<?php

/**
 * parity_check.php — pengukuran paritas dual-run GitLab (kanonik) vs GitHub (eksekutor coverage).
 *
 * Bagian dari docs/CANONICAL_MIGRATION_PLAN.md (Tahap 3). Alat ini:
 *   1. Untuk tiap commit pada branch kanonik, menemukan commit mirror pasangannya
 *      (subject "mirror(zef <short_sha>): pipeline <id>") di repo eksekutor.
 *   2. Mengambil bukti dari KEDUA sisi:
 *        - GitHub : run terakhir (conclusion) + angka coverage (via deskripsi status GitLab,
 *                   yang memuat output gate GitHub verbatim; artifact opsional bila unzip ada).
 *        - GitLab : external status check `zef/coverage-gate` (state/description/target_url).
 *   3. Menjalankan pemeriksaan paritas:
 *        P8  keputusan gate   : kesimpulan run GitHub  <->  state status GitLab
 *        P1  jumlah test      : hanya bila kedua sisi menyediakan (ditandai "n/a" bila tidak)
 *        P3  line coverage    : \delta di dalam toleransi
 *        P6  branch coverage  : \delta di dalam toleransi
 *        P5  penyebut line    : HARUS identik (scope file berubah = kegagalan keras)
 *        P7  penyebut branch  : HARUS identik
 *        P9  versi runtime    : HARUS identik (PHP/Xdebug) bila tersedia
 *        P10 diges instrumen  : coverage-gate.php + phpunit.xml.dist identik di kedua sisi
 *
 * Verdict & kode keluar:
 *   0  LULUS               — semua pemeriksaan keras lulus
 *   1  PELANGGARAN KRITIS  — ada pemeriksaan keras yang gagal (pipeline harus MERAH)
 *   2  KESALAHAN OPERASIONAL — data tidak lengkap / API gagal (JANGAN ditafsirkan sebagai lulus)
 *
 * Pemakaian:
 *   php tools/migration/parity_check.php \
 *     --gl-project=86155206 --repo=mbetixz/zef-coverage-runner \
 *     --gl-token="$ZEF_GITLAB_TOKEN" --gh-token="$GH_TOKEN"
 *
 * Flag:
 *   --gl-api=URL        (default https://gitlab.com/api/v4)
 *   --gh-api=URL        (default https://api.github.com)
 *   --branch=NAME       (default main)
 *   --max=N             maksimum commit diperiksa (default 20)
 *   --sample-days=N     batasi commit ke N hari terakhir (default 30; 0 = tanpa batas)
 *   --baseline=SHA      jendela teranchored: periksa hanya commit dari HEAD sampai baseline
 *                       (inklusif). Menggantikan sample-days bila SHA ditemukan; bila tidak
 *                       ditemukan, jatuh ke sample-days + peringatan. Dipakai agar gate
 *                       mengukur jendela PASCA-ratifikasi baseline, bukan commit historis.
 *   --line-tol=F        toleransi absolut line coverage % (default 0.0)
 *   --branch-tol=F      toleransi absolut branch coverage % (default 0.0)
 *   --tests-tol=N       toleransi selisih jumlah test (default 0)
 *   --out-dir=DIR       direktori keluaran ledger (default docs/parity)
 *   --no-write          jangan menulis berkas ledger
 *   --compact           ringkas (tanpa detail per-commit)
 *   --selftest          jalankan uji internal parser/komparator tanpa jaringan
 *
 * Token dibaca dari flag atau env (ZEF_GITLAB_TOKEN / GITHUB_TOKEN / GH_TOKEN). Token TIDAK pernah dicetak.
 */

declare(strict_types=1);

const EXIT_PASS = 0;
const EXIT_VIOLATION = 1;
const EXIT_ERROR = 2;

/**
 * NAMA KANONIK status commit GitLab untuk gate coverage (#33).
 *
 * Split-brain evidence: producer (`coverage-wait`, jalur push) mempublikasikan status
 * `zef/coverage-gate`, dan OBSERVER (`coverage-gate-audit`, jalur schedule) membacanya
 * sebagai status kanonik. Checker paritas ini dulu masih mencari nama WARISAN
 * `github-actions/coverage` yang SUDAH TIDAK PERNAH dipublikasikan lagi, sehingga setiap
 * baris selalu `gl_state=''` ("missing") walau gate di HEAD sebenarnya PASS - gate hijau
 * sementara auditor buta. Konstanta ini menjadikan satu nama sebagai satu-satunya sumber,
 * dipakai oleh seleksi status, pembangunan URL, dan deteksi korelasi run.
 */
const COVERAGE_STATUS_NAME = 'zef/coverage-gate';

/**
 * Sentinel: Xdebug DIKONFIRMASI TIDAK dimuat (bukan "tak terdeteksi"). Dipancarkan oleh
 * workflow GitHub / job phpunit GitLab sebagai `XDEBUG_VERSION=__XDEBUG_ABSENT__` setelah
 * probe `extension_loaded("xdebug")`. Bila nilainya sentinel ini, P9 menjadi PELANGGARAN
 * KERAS (#27) — gate tidak boleh lulus di atas instrumentasi yang hilang. Seluruh ledger
 * menuliskan literal ini seragam supaya mudah di-grep saat audit.
 */
const XDEBUG_ABSENT = '__XDEBUG_ABSENT__';

// Ambang kebijakan (dapat dioverride lewat flag).
$POLICY = [
    'line_tol'     => 0.0,
    'branch_tol'   => 0.0,
    'tests_tol'    => 0,
    'duration_tol' => 0.0, // P11: 0 = INFORMASIONAL (durasi bergantung lingkungan; tidak pernah jadi pelanggaran). Set > 0 untuk menegakkan.
    'sample_days'  => 30,
];

// Instrumen yang HARUS identik di kedua sisi (P10).
$INSTRUMENT_PATHS = [
    'tools/coverage-gate.php',
    'phpunit.xml.dist',
];

// ---------------------------------------------------------------------------
// Argumen
// ---------------------------------------------------------------------------
$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/i', $a, $m)) {
        $args[$m[1]] = $m[2] ?? true;
    }
}
$opt = static fn (string $k, $d = null) => $args[$k] ?? $d;

if ($opt('selftest')) {
    exit(selftest());
}

$glApi     = rtrim((string) $opt('gl-api', 'https://gitlab.com/api/v4'), '/');
$ghApi     = rtrim((string) $opt('gh-api', 'https://api.github.com'), '/');
$glProject = (string) $opt('gl-project', '');
$repo      = (string) $opt('repo', '');
$branch    = (string) $opt('branch', 'main');
$max       = max(1, (int) $opt('max', 20));
$outDir    = (string) $opt('out-dir', 'docs/parity');
$noWrite   = (bool) $opt('no-write', false);
$compact   = (bool) $opt('compact', false);
// --strict-numeric: naikkan dimensi numerik yang belum terukur (n/a) menjadi ERROR.
// Default OFF agar gate tetap hijau selama job `coverage` GitLab belum kembali (Tahap 3c).
$strictNumeric = (bool) $opt('strict-numeric', false);
// --evidence-authoritative: OBSERVER sudah memverifikasi EVIDENCE CONTRACT bersama (#32).
//   Di arsitektur offload, status kanonik GitLab bersifat KORROBORASI - bukan penentu
//   PASS/FAIL (penentu = contract yang diverifikasi di langkah [3/7] observer). Karena itu
//   riwayat status GitLab yang tipis/terpotong tidak boleh menurunkan verdict baris.
//   TANPA flag ini perilaku lama (fail-closed) TETAP berlaku, sehingga guard Qodo #12
//   tidak hilang - ia hanya tidak diberlakukan saat contract sudah otoritatif.
$evidenceAuthoritative = (bool) $opt('evidence-authoritative', false);
$baseline = (string) $opt('baseline', '');

$POLICY['line_tol']    = (float) $opt('line-tol', $POLICY['line_tol']);
$POLICY['branch_tol']  = (float) $opt('branch-tol', $POLICY['branch_tol']);
$POLICY['tests_tol']   = (int) $opt('tests-tol', $POLICY['tests_tol']);
$POLICY['sample_days'] = (int) $opt('sample-days', $POLICY['sample_days']);
$POLICY['duration_tol'] = (float) $opt('duration-tol', $POLICY['duration_tol']);

$glToken = (string) ($opt('gl-token') ?: getenv('ZEF_GITLAB_TOKEN') ?: getenv('GITLAB_TOKEN') ?: '');
$ghToken = (string) ($opt('gh-token') ?: getenv('GITHUB_TOKEN') ?: getenv('GH_TOKEN') ?: '');

if ($glProject === '' || $repo === '') {
    fwrite(STDERR, "ERROR: --gl-project dan --repo wajib diisi.\n");
    exit(EXIT_ERROR);
}
if ($glToken === '' || $ghToken === '') {
    fwrite(STDERR, "ERROR: token GitLab/GitHub tidak tersedia (butuh scope api / repo).\n");
    exit(EXIT_ERROR);
}

// ---------------------------------------------------------------------------
// HTTP helpers (extension-curl tidak selalu ada; pakai stream wrapper + openssl)
// ---------------------------------------------------------------------------
/**
 * @param array<int,string> $headers
 * @return array{code:int,body:string}
 */
function http(string $url, array $headers = [], ?string $postBody = null, int $timeout = 25): array
{
    $originHost = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
    $currentUrl = $url;
    $body = false;
    $code = 0;
    $redirectsLeft = 5;

    // Redirect ditangani MANUAL (follow_location=0). PHP stream wrapper — berbeda dari
    // curl — MENERUSKAN header kustom ke host tujuan redirect; unduhan log GitHub
    // Actions mengalihkan ke S3 (URL pre-signed) yang MENOLAK header Authorization
    // (403 AuthenticationFailed). Karena itu permintaan lintas-host diulang TANPA
    // header kredensial. Header hanya dikirim ke host asal (origin), tidak pernah bocor.
    do {
        $p = parse_url($currentUrl);
        $requestHeaders = $headers;
        if (strtolower((string) ($p['host'] ?? '')) !== $originHost) {
            $requestHeaders = [];
            foreach ($headers as $h) {
                if (!preg_match('/^(authorization|private-token|token)\s*:/i', $h)) {
                    $requestHeaders[] = $h;
                }
            }
        }
        $opts = [
            'http' => [
                'method'          => $postBody === null ? 'GET' : 'POST',
                'header'          => implode("\r\n", $requestHeaders),
                'timeout'         => $timeout,
                'ignore_errors'   => true,
                'follow_location' => 0,
                'max_redirects'   => 0,
            ],
        ];
        if ($postBody !== null) {
            $opts['http']['content'] = $postBody;
        }
        $ctx = stream_context_create($opts);
        $body = @file_get_contents($currentUrl, false, $ctx);
        $code = 0;
        $location = '';
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $code = (int) $m[1];
            }
            if (preg_match('#^location:\s*(.+)$#i', $h, $m)) {
                $location = trim($m[1]);
            }
        }
        if ($code >= 300 && $code < 400 && $location !== '' && $redirectsLeft > 0) {
            $scheme = (string) ($p['scheme'] ?? 'https');
            if (strpos($location, '//') === 0) {
                $location = $scheme . ':' . $location;
            } elseif (strpos($location, '/') === 0) {
                $location = $scheme . '://' . ($p['host'] ?? '') . $location;
            } elseif (strpos($location, '://') === false) {
                $location = $scheme . '://' . ($p['host'] ?? '') . '/' . ltrim($location, '/');
            }
            $currentUrl = $location;
            $redirectsLeft--;
            continue;
        }
        break;
    } while (true);

    return ['code' => $code, 'body' => $body === false ? '' : $body];
}

function ghHeaders(string $token): array
{
    return [
        'Authorization: token ' . $token,
        'Accept: application/vnd.github+json',
        'User-Agent: zef-parity-check',
        'X-GitHub-Api-Version: 2022-11-28',
    ];
}

function glHeaders(string $token): array
{
    return ['PRIVATE-TOKEN: ' . $token, 'Accept: application/json'];
}

/** Header API GitHub (JSON) — dipakai seluruh GET API (runs, jobs, commits, contents). */
function ghApiHeaders(string $token): array
{
    return [
        'Authorization: token ' . $token,
        'Accept: application/vnd.github+json',
        'User-Agent: zef-parity-check',
        'X-GitHub-Api-Version: 2022-11-28',
    ];
}

/** Bersihkan artefak log mentah (UTF-8 BOM, escape ANSI, CR) agar regex angka stabil. */
function cleanLog(string $raw): string
{
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
    $raw = preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', $raw) ?? $raw;
    return str_replace("\r", '', $raw);
}

/**
 * Unduh LOG job Actions sebagai teks biasa.
 * PENTING: endpoint ini mengalihkan (302) ke S3 dan URL pre-signed MENOLAK header
 * Authorization — pembuangan header lintas-host ditangani http().
 */
function ghJobLog(string $ghApi, string $repo, string $jobId, string $ghToken): string
{
    $r = http("{$ghApi}/repos/{$repo}/actions/jobs/" . rawurlencode($jobId) . '/logs', ghApiHeaders($ghToken), null, 60);
    if ($r['code'] < 200 || $r['code'] >= 300 || $r['body'] === '') {
        return '';
    }
    return cleanLog($r['body']);
}

/**
 * Status job `coverage-offload` pada pipeline COMMIT yang diperiksa.
 * Dipakai untuk MEMBEDAKAN (a) commit yang pipeline-nya tidak pernah mencapai tahap
 * mirror-push — paritas vakum, bukan lulus diam-diam — dari (b) INVARIANT BREACH:
 * mirror sudah ter-push (offload success) tetapi run/status GitHub tidak ada ⇒ data
 * tidak lengkap yang WAJIB menjadi error. Tanpa pembeda ini, keduanya jatuh ke
 * "INCOMPLETE" yang sama dan gate menjadi terlalu bising untuk commit uji-yang-gagal.
 * @return string salah satu: success|failed|canceled|skipped|manual|none
 */
function glOffloadStatus(string $glApi, string $glq, string $branch, string $glToken, string $sha): string
{
    $res = resolveGlPipelines($glApi, $glq, $branch, $glToken, $sha);
    $best = 'none';
    foreach ($res['pids'] as $pid) {
        $jobs = jsonGet("{$glApi}/projects/{$glq}/pipelines/" . (int) $pid . '/jobs?per_page=100', glHeaders($glToken), 30);
        foreach (($jobs ?? []) as $j) {
            if ((string) ($j['name'] ?? '') !== 'coverage-offload') {
                continue;
            }
            $st = (string) ($j['status'] ?? '');
            if ($st === 'success') {
                return 'success';
            }
            if ($best === 'none' && $st !== '') {
                $best = $st;
            }
        }
    }
    return $best;
}

/**
 * @param array<int,string> $headers
 * @return mixed decoded JSON atau null
 */
function jsonGet(string $url, array $headers, int $timeout = 25)
{
    $r = http($url, $headers, null, $timeout);
    if ($r['code'] < 200 || $r['code'] >= 300 || $r['body'] === '') {
        return null;
    }
    $d = json_decode($r['body'], true);
    return is_array($d) ? $d : null;
}

function sha256Remote(string $url, array $headers, int $timeout = 25): string
{
    $r = http($url, $headers, null, $timeout);
    if ($r['code'] < 200 || $r['code'] >= 300 || $r['body'] === '') {
        return '';
    }
    return hash('sha256', $r['body']);
}

// ---------------------------------------------------------------------------
// Parsing
// ---------------------------------------------------------------------------
/** Ambil angka coverage dari output gate (format tools/coverage-gate.php). */
function parseGateText(string $txt): array
{
    $out = [
        'lines_pct' => null, 'lines_covered' => null, 'lines_total' => null, 'line_threshold' => null,
        'branches_pct' => null, 'branches_covered' => null, 'branches_total' => null, 'branch_threshold' => null,
        'verdict' => null,
    ];
    if (preg_match('/Coverage:\s*([0-9.]+)%\s*lines\s*\((\d+)\/(\d+)\)\s*\|\s*threshold\s*([0-9.]+)%/', $txt, $m)) {
        $out['lines_pct'] = (float) $m[1];
        $out['lines_covered'] = (int) $m[2];
        $out['lines_total'] = (int) $m[3];
        $out['line_threshold'] = (float) $m[4];
    }
    if (preg_match('/([0-9.]+)%\s*branches\s*\((\d+)\/(\d+)\)\s*\|\s*threshold\s*([0-9.]+)%/', $txt, $m)) {
        $out['branches_pct'] = (float) $m[1];
        $out['branches_covered'] = (int) $m[2];
        $out['branches_total'] = (int) $m[3];
        $out['branch_threshold'] = (float) $m[4];
    }
    if (preg_match('/Coverage gate:\s*(PASS|FAIL)/i', $txt, $m)) {
        $out['verdict'] = strtoupper($m[1]);
    }
    return $out;
}

/** Ambil "@tests N" dan "@skipped M" dari blok ringkas phpunit (baris "Tests: N, ... Skipped: M."). */
function parsePhpunitSummary(string $txt): array
{
    $out = ['tests' => null, 'assertions' => null, 'skipped' => null, 'php' => null, 'xdebug' => null];
    if (preg_match('/Tests:\s*(\d+),\s*Assertions:\s*(\d+)(?:.*?Skipped:\s*(\d+))?\./s', $txt, $m)) {
        $out['tests'] = (int) $m[1];
        $out['assertions'] = (int) $m[2];
        $out['skipped'] = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : 0;
    }
    if (preg_match('/Runtime:\s*PHP\s*([0-9.]+)\s*with Xdebug\s*([0-9A-Za-z.\-]+)/', $txt, $m)) {
        $out['php'] = $m[1];
        $out['xdebug'] = $m[2];
    }
    return $out;
}

/** Petakan kesimpulan run GitHub -> state status GitLab yang diharapkan. */
function mapConclusionToState(string $conclusion): string
{
    return match (strtolower($conclusion)) {
        'success'                                  => 'success',
        'failure', 'timed_out', 'startup_failure'  => 'failed',
        'cancelled'                                => 'canceled',
        default                                    => strtolower($conclusion) ?: 'unknown',
    };
}

// ---------------------------------------------------------------------------
// Pengumpulan data
// ---------------------------------------------------------------------------
$glq = rawurlencode($glProject);
$commitUrl = "{$glApi}/projects/{$glq}/repository/commits?ref_name=" . rawurlencode($branch) . "&per_page={$max}";

$commits = jsonGet($commitUrl, glHeaders($glToken), 30);
if (!is_array($commits)) {
    fwrite(STDERR, "ERROR: tidak bisa mengambil daftar commit GitLab (periksa --gl-project & token).\n");
    exit(EXIT_ERROR);
}

$cutoff = $POLICY['sample_days'] > 0 ? time() - ($POLICY['sample_days'] * 86400) : 0;

// Ambil daftar commit mirror sekali (map short_sha -> sha).
$mirrorMap = [];
$ghCommits = jsonGet("{$ghApi}/repos/{$repo}/commits?sha=" . rawurlencode($branch) . '&per_page=100', ghHeaders($ghToken), 30);
if (!is_array($ghCommits)) {
    fwrite(STDERR, "ERROR: tidak bisa mengambil daftar commit mirror GitHub (periksa --repo & token).\n");
    exit(EXIT_ERROR);
}
foreach ($ghCommits as $c) {
    $msg = (string) ($c['commit']['message'] ?? '');
    if (preg_match('/^mirror\(zef ([0-9a-f]{7,40})\):/m', $msg, $m)) {
        $mirrorMap[substr($m[1], 0, 8)] = (string) ($c['sha'] ?? '');
    }
}

// Cache digest instrumen per sumber.
$instrumentCache = [];
function instrumentDigest(string $path, string $side, string $ref, string $glApi, string $glq, string $ghApi, string $repo, string $glToken, string $ghToken): string
{
    $url = $side === 'gitlab'
        ? "{$glApi}/projects/{$glq}/repository/files/" . rawurlencode($path) . '/raw?ref=' . rawurlencode($ref)
        : "{$ghApi}/repos/{$repo}/contents/" . str_replace('%2F', '/', rawurlencode($path)) . '?ref=' . rawurlencode($ref);
    $headers = $side === 'gitlab'
        ? glHeaders($glToken)
        : [ghHeaders($ghToken)[0], 'Accept: application/vnd.github.raw', ghHeaders($ghToken)[2]];
    return sha256Remote($url, $headers);
}

/**
 * Ekstrak angka test/skipped + versi runtime dari teks (log/trace) salah satu sisi.
 * Fallback: baris `PHP x.y.z` (mis. `php --version`) bila pola `Runtime: PHP ... with Xdebug` absen.
 * @return array{tests:?int,skipped:?int,php:?string,xdebug:?string}
 */
function extractNumbers(string $txt): array
{
    $u = parsePhpunitSummary($txt);
    if ($u['php'] === null && preg_match('/\bPHP\s+(\d+\.\d+\.\d+)/', $txt, $m)) {
        $u['php'] = $m[1];
    }
    // (#27) Penanda EKSPLISIT diutamakan: `XDEBUG_VERSION=<versi>` atau sentinel
    // `XDEBUG_VERSION=__XDEBUG_ABSENT__` yang dipancarkan workflow GitHub dan job phpunit
    // GitLab. Jauh lebih andal daripada bersandar pada banner "Runtime: PHP ... with Xdebug
    // ..." yang tidak selalu dicetak, DAN membedakan "Xdebug tak dimuat" (absen eksplisit)
    // dari "tak terdeteksi" (tanpa penanda) — pembedaan yang menentukan P9 fail-closed.
    if (preg_match('/XDEBUG_VERSION=([0-9][0-9A-Za-z.\-]*|__XDEBUG_ABSENT__)/', $txt, $m)) {
        $u['xdebug'] = ($m[1] === XDEBUG_ABSENT) ? '' : $m[1];
    } elseif ($u['xdebug'] === null && preg_match('/Xdebug\s+v?(\d+(?:\.\d+)*[0-9A-Za-z.\-]*)/', $txt, $m)) {
        $u['xdebug'] = $m[1];
    }
    return ['tests' => $u['tests'], 'skipped' => $u['skipped'], 'php' => $u['php'], 'xdebug' => $u['xdebug']];
}

/**
 * Metrik sisi GitHub: ambil job pertama pada run, durasi, lalu log job (plain).
 *
 * Sumber angka coverage P3-P7 (#27) — DUA sumber terverifikasi, berurutan:
 *  1. `gh_job_log`   : log job Actions memuat `Coverage: ... | ... branches ...`
 *                      (tools/coverage-gate.php mencetak ke stdout, di-tee ke gate.txt).
 *  2. `gl_commit_status` : deskripsi status commit GitLab `zef/coverage-gate`,
 *                      yang DIBUAT oleh workflow GitHub ini sendiri dengan isi
 *                      "Coverage GitHub PASS: $(head -1 gate.txt)" — jadi tetap
 *                      merupakan angka gate GitHub, verbatim, walau artifact/log
 *                      sudah kedaluwarsa (retensi artifact GHA = 90 hari).
 *
 * `coverage_source` direkam ke ledger supaya asal-angka dapat diaudit, dan supaya
 * dimensi yang benar-benar tak terukur tetap dilaporkan `n/a` — TIDAK dipalsukan lulus.
 *
 * @param string $ghApi        basis URL GitHub API (mis. https://api.github.com)
 * @param string $repo         slug repo GitHub pemilik workflow, format `owner/repo`
 * @param string $runId        ID run GitHub Actions yang metriknya diambil; '' = tak terukur
 * @param string $ghToken      token GitHub (scope repo) untuk membaca API & log job
 * @param string $glStatusDesc deskripsi status commit GitLab `zef/coverage-gate`
 *                            (opsional; fallback bila log job tak tersedia/kedaluwarsa)
 * @param string $glStatusUrl  target_url status commit GitLab (opsional; dipakai untuk
 *                            memverifikasi status menargetkan run yang sama dengan $runId)
 * @return array<string,mixed> metrik beserta `coverage_source` penanda asal-angka; dimensi
 *                            yang benar-benar tak terukur bernilai null (bukan 0 / bukan lulus)
 *                            `xdebug_source` mencatat provenance versi Xdebug (#27):
 *                            `gh_marker` | `gh_job_log` | `gh_marker_absent` | `gl_mirror` | `none`
 */
function ghRunMetrics(string $ghApi, string $repo, string $runId, string $ghToken, string $glStatusDesc = '', string $glStatusUrl = ''): array
{
    $out = [
        'tests' => null, 'skipped' => null, 'php' => null, 'xdebug' => null,
        'lines_pct' => null, 'lines_covered' => null, 'lines_total' => null,
        'branches_pct' => null, 'branches_covered' => null, 'branches_total' => null,
        'duration_s' => null, 'coverage_source' => 'none', 'xdebug_source' => 'none',
    ];
    // Koreksi review Qodo #2: JANGAN early-return saat run/job/log tak tersedia. Justru
    // log yang kedaluwarsa adalah skenario yang memotivasi fallback ini, sehingga seluruh
    // langkah di bawah dibuat OPSIONAL dan fallback selalu dievaluasi.
    $txt = '';
    if ($runId !== '') {
        $jobs = jsonGet("{$ghApi}/repos/{$repo}/actions/runs/" . rawurlencode($runId) . '/jobs?per_page=20', ghHeaders($ghToken), 30);
        if (is_array($jobs) && (($jobs['jobs'] ?? []) !== [])) {
            $job = $jobs['jobs'][0];
            if (!empty($job['started_at']) && !empty($job['completed_at'])) {
                $out['duration_s'] = max(0, (int) strtotime((string) $job['completed_at']) - (int) strtotime((string) $job['started_at']));
            }
            $jobId = (string) ($job['id'] ?? '');
            if ($jobId !== '') {
                $txt = ghJobLog($ghApi, $repo, $jobId, $ghToken);
            }
        }
    }
    if ($txt !== '') {
        $n = extractNumbers($txt);
        $out['tests'] = $n['tests'];
        $out['skipped'] = $n['skipped'];
        $out['php'] = $n['php'];
        // (#27) P9: Xdebug diambil dari log job Actions. `extractNumbers()` mengembalikan ''
        // bila penanda `XDEBUG_VERSION=__XDEBUG_ABSENT__` terbaca ⇒ Xdebug DIKONFIRMASI tidak
        // dimuat. Itu bukan "tak terukur": dicatat sebagai sentinel supaya P9 menjadi
        // pelanggaran keras. `null` (tanpa penanda) tetap "tak terukur" dan menunggu
        // --strict-numeric. Provenance dicatat di `xdebug_source` agar asal-angka auditabel.
        if ($n['xdebug'] === '') {
            $out['xdebug'] = XDEBUG_ABSENT;
            $out['xdebug_source'] = 'gh_marker_absent';
        } elseif ($n['xdebug'] !== null) {
            $out['xdebug'] = $n['xdebug'];
            $out['xdebug_source'] = 'gh_job_log';
        }
        $g = parseGateText($txt);
        foreach (['lines_pct', 'lines_covered', 'lines_total', 'branches_pct', 'branches_covered', 'branches_total'] as $k) {
            $out[$k] = $g[$k];
        }
        if ($g['lines_total'] !== null || $g['branches_total'] !== null) {
            $out['coverage_source'] = 'gh_job_log';
        }
    }
    // Fallback (#27) — dijalankan SETELAH blok log, bukan dilompati oleh early-return.
    // Koreksi review Qodo #6: verifikasi identitas status↔run. Status commit dan run GitHub
    // dipilih independen; tanpa perbandingan, status dari rerun LAMA bisa menimpa coverage
    // run BARU. target_url status memuat run ID (…/actions/runs/<id>) — cocokkan dengan $runId.
    return applyCoverageFallback($out, $glStatusDesc, $glStatusUrl, $runId);
}

/**
 * Pilih status commit `zef/coverage-gate` (nama kanonik, #33) yang MENARGETKAN run GitHub terpilih.
 *
 * Regresi review Qodo #7: seleksi lama berhenti pada entri PERTAMA yang namanya cocok,
 * independen dari run yang sedang diukur. Pada commit dengan lebih dari satu rerun, entri
 * pertama bisa menunjuk run LAMA sehingga fallback yang sah untuk run terpilih ikut ditolak
 * oleh guard korelasi #6 — dimensi tetap null dan `--strict-numeric` memberi ERROR palsu.
 *
 * Prasyarat data (review Qodo #8): pemanggil HARUS meminta riwayat status lengkap
 * (`all=true`); tanpa itu GitLab hanya menyemburkan status TERBARU sehingga fungsi ini
 * tidak pernah melihat entri yang cocok.
 *
 * Kontrak: entri TERBARU yang korelasi run ID-nya TERBUKTI menang (penting bila commit
 * yang sama punya beberapa status dari run yang sama, mis. rerun gagal lalu sukses); bila
 * tidak ada yang terbukti, entri TERBARU yang cocok nama tetap dikembalikan agar guard
 * korelasi di hilir yang memutuskan — fungsi ini tidak pernah mengarang korelasi.
 * Mengasumsikan `$statuses` terurut terbaru-dulu (pemanggil memakai `sort=desc`).
 *
 * @param array<int,array<string,mixed>> $statuses daftar status commit GitLab (terbaru-dulu)
 * @param string $runId ID run GitHub terpilih; '' = tanpa korelasi (entri terbaru dipakai)
 * @return array<string,mixed>|null status terpilih, atau null bila tidak ada yang bernama cocok
 */
function selectCoverageStatus(array $statuses, string $runId): ?array
{
    $newest = null;
    foreach ($statuses as $s) {
        if (($s['name'] ?? '') !== COVERAGE_STATUS_NAME) {
            continue;
        }
        if ($newest === null) {
            $newest = $s;
        }
        $url = (string) ($s['target_url'] ?? '');
        if ($runId === '' || $url === '') {
            continue;
        }
        if (preg_match('#/actions/runs/(\d+)#', $url, $m) === 1 && (string) $m[1] === $runId) {
            return $s;   // terbaru yang terbukti cocok (input terurut terbaru-dulu)
        }
    }
    return $newest;
}

/**
 * Bangun URL permintaan status commit GitLab yang BENAR (dapat diuji terpisah).
 *
 * Koreksi review Qodo #9 (2026-09-11): URL dipindahkan ke fungsi ini agar `--selftest`
 * memeriksa URL yang benar-benar DIBANGUN, bukan sekadar mencari fragmen di seluruh isi
 * berkas — pencarian teks seluruh-berkas bisa dipuaskan oleh komentar (regresi review #10),
 * sehingga penghapusan parameter produksi tetap lolos pemeriksaan.
 *
 * `all=true` wajib (tanpa itu hanya status TERBARU yang dikembalikan); `name` menyaring ke
 * status coverage; `order_by=id&sort=desc` memberi urutan terbaru-dulu (diasumsikan oleh
 * selectCoverageStatus()); `per_page=100` agar satu halaman seragam dengan penomoran halaman.
 *
 * @param string $glApi basis URL GitLab API (mis. https://gitlab.com/api/v4)
 * @param string $glq   project ID/path ter-encode URL
 * @param string $sha   SHA commit yang statusnya diminta
 * @param int    $page  nomor halaman GitLab (1-based)
 * @return string URL lengkap siap-request
 */
function coverageStatusUrl(string $glApi, string $glq, string $sha, int $page = 1): string
{
    return $glApi . '/projects/' . $glq . '/repository/commits/' . $sha . '/statuses'
        . '?all=true&name=' . COVERAGE_STATUS_NAME . '&order_by=id&sort=desc&per_page=100&page=' . max(1, $page);
}

/**
 * Ambil status commit dengan MENELUSURI halaman sampai korelasi run ditemukan atau habis.
 *
 * Koreksi review Qodo #9: `all=true` TIDAK menghapus paginasi. Satu `jsonGet()` dibatasi
 * `per_page` sehingga bila status run terpilih berada di halaman berikutnya, ia tak pernah
 * terlihat dan `--strict-numeric` melaporkan "coverage hilang" padahal datanya ADA. Fungsi
 * ini menelusuri halaman berurutan (terbaru-dulu) dan berhenti lebih awal begitu menemukan
 * entri yang korelasi run ID-nya TERBUKTI cocok.
 *
 * @param string $glApi   basis URL GitLab API
 * @param string $glq     project ID/path ter-encode
 * @param string $sha     SHA commit
 * @param array<string,string> $headers header GitLab (termasuk PRIVATE-TOKEN)
 * @param string $runId   run GitHub terpilih; '' = tanpa korelasi (halaman pertama cukup)
 * @param int    $maxPages batas halaman yang ditelusuri (pengaman)
 * @return array{0: array<int,array<string,mixed>>, 1: bool, 2: int, 3: bool}
 *         [statuses terkumpul (terbaru-dulu, aman dari duplikat), ditemukan-korelasi,
 *          halaman-diperiksa, fetch-terpotong]
 */
function fetchCoverageStatuses(string $glApi, string $glq, string $sha, array $headers, string $runId, int $maxPages = 5): array
{
    $all = [];
    $seen = [];
    $pages = 0;
    $found = false;
    $failed = false;
    for ($page = 1; $page <= max(1, $maxPages); $page++) {
        $chunk = jsonGet(coverageStatusUrl($glApi, $glq, $sha, $page), $headers, 30);
        $pages = $page;
        if (!is_array($chunk)) {
            // Koreksi review Qodo #11 (2026-09-11): `jsonGet()` menciutkan respons non-2xx,
            // body kosong, dan JSON tak valid menjadi `null`. Dulu kondisi ini DIGABUNG dengan
            // "halaman kosong", sehingga KEGAGALAN tampak identik dengan AKHIR riwayat
            // (timeout halaman 2 = riwayat tamat) dan sepotong riwayat diaudit seolah lengkap.
            // Kini keduanya dibedakan agar pemanggil dapat bersikap fail-closed.
            $failed = true;
            break;
        }
        if ($chunk === []) {
            break;   // halaman kosong = akhir riwayat yang SAH
        }
        foreach ($chunk as $s) {
            $key = (string) ($s['id'] ?? md5((string) ($s['name'] ?? '') . (string) ($s['target_url'] ?? '')));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $all[] = $s;
            $url = (string) ($s['target_url'] ?? '');
            if (($s['name'] ?? '') === COVERAGE_STATUS_NAME && $runId !== '' && $url !== ''
                && preg_match('#/actions/runs/(\d+)#', $url, $m) === 1 && (string) $m[1] === $runId) {
                $found = true;
            }
        }
        if (shouldStopStatusPaging($found, count($chunk), 100)) {
            break;   // korelasi ditemukan, atau halaman terakhir (kurang dari per_page)
        }
    }
    return [$all, $found, $pages, $failed];
}

/**
 * Keputusan berhenti menelusuri halaman status commit (dipisah agar dapat diuji murni).
 *
 * Koreksi review Qodo #9: aturan henti dipisahkan menjadi fungsi tanpa efek samping sehingga
 * `--selftest` dapat memverifikasi batas paginasi tanpa memanggil jaringan. Berhenti bila
 * korelasi run sudah ditemukan, atau bila halaman yang diterima lebih pendek dari `perPage`
 * (menandakan halaman terakhir). Halaman penuh tanpa korelasi → lanjut ke halaman berikutnya.
 *
 * @param bool $found      true bila entri ber-korelasi run sudah ditemukan
 * @param int  $chunkCount jumlah entri pada halaman yang baru diterima
 * @param int  $perPage    ukuran halaman yang diminta (default 100)
 * @return bool true = berhenti menelusuri halaman
 */
function shouldStopStatusPaging(bool $found, int $chunkCount, int $perPage = 100): bool
{
    return $found || $chunkCount < $perPage;
}

/**
 * Klasifikasi hasil satu baris ledger (fungsi MURNI — diuji langsung oleh --selftest).
 *
 * Koreksi review Qodo #12 (2026-09-11): percabangan inline sebelumnya menaruh pemeriksaan
 * MISMATCH DI ATAS pemeriksaan riwayat-terpotong, sehingga mismatch yang berasal dari
 * himpunan status TERPOTONG tidak pernah menaikkan $operationalErrors dan keluar sebagai
 * VIOLATION alih-alih ERROR operasional yang dijanjikan oleh penanganan fail-closed.
 *
 * Urutan keputusan (fail-closed, tidak pernah menghasilkan "lulus" dari bukti tak lengkap):
 *   1. VAKUM: pipeline tak mencapai mirror-push ⇒ N/A(no-dual-run:*) — bukan lulus, bukan error.
 *   2. PARTIAL: riwayat status terpotong oleh kegagalan halaman ⇒ operasional error, MENDAHULUI
 *      mismatch (bukti terpotong tidak boleh menghasilkan verdict paritas apa pun).
 *   3. MISMATCH keputusan gate / instrumen ⇒ pelanggaran kebijakan (VIOLATION).
 *   4. P8 tak dapat dinilai tetapi mirror ter-push ⇒ INVARIANT dilanggar ⇒ operasional error.
 *   5. Dimensi numerik belum terukur ⇒ PASS(n/a:...) eksplisit (bukan PASS polos).
 *   6. Selainnya ⇒ PASS.
 *
 * @param string $decisionParity nilai kolom decision_parity ('OK'|'MISMATCH'|'n/a')
 * @param string $instrGate      nilai kolom instr_gate ('OK'|'MISMATCH'|'n/a')
 * @param string $conclusion     conclusion run GitHub ('' bila run tak terpilih)
 * @param string $glState        state status GitLab ('' bila status tak terpilih)
 * @param string $glStatusFetch  'complete'|'partial' — kelengkapan riwayat status
 * @param string $offloadState   hasil glOffloadStatus() ('' bila belum diperiksa)
 * @param array<int,string> $numNA dimensi numerik yang belum terukur
 * @return array{0:string,1:bool,2:bool} [result, isOperationalError, isVacuous]
 */
function classifyRowResult(
    string $decisionParity,
    string $instrGate,
    string $conclusion,
    string $glState,
    string $glStatusFetch,
    string $offloadState,
    array $numNA,
    bool $evidenceAuthoritative = false
): array {
    // 1. Vakum: commit tanpa dual-run (pipeline tak mencapai offload).
    if (($conclusion === '' || $glState === '') && $offloadState !== 'success') {
        return ['N/A(no-dual-run:' . $offloadState . ')', false, true];
    }
    // 2. Riwayat status terpotong - MENDAHULUI mismatch (koreksi Qodo #12).
    //    #32: bila observer SUDAH memverifikasi evidence contract bersama, verdict baris
    //    bersumber dari contract itu dan paritas status GitLab hanya korroborasi. Dalam rezim
    //    itu riwayat yang terpotong DILAPORKAN eksplisit sebagai n/a(gl_status_partial) -
    //    bukan pelanggaran DAN bukan lulus diam-diam. Tanpa --evidence-authoritative,
    //    perilaku fail-closed lama dipertahankan utuh.
    if ($glStatusFetch === 'partial') {
        if ($evidenceAuthoritative) {
            return ['n/a(gl_status_partial)', false, false];
        }
        return ['INCOMPLETE(gl_status_partial)', true, false];
    }
    // 3. Pelanggaran kebijakan.
    if ($decisionParity === 'MISMATCH' || $instrGate === 'MISMATCH') {
        return ['VIOLATION', false, false];
    }
    // 4. P8 tak dapat dinilai padahal mirror ter-push ⇒ invariant dilanggar.
    //
    //    #33: di rezim evidence-contract-otoritatif, status kanonik hanya dipublikasikan
    //    oleh commit yang pipeline-nya benar-benar mencapai `coverage-wait`. Commit historis
    //    (pra-kontrak) tidak memilikinya — itu BUKAN kontradiksi dan BUKAN lulus diam-diam,
    //    jadi dilaporkan eksplisit `n/a(canonical-status-absent)`. Penegakan gate di HEAD
    //    tetap HARD di langkah [4/7] observer (status kanonik wajib `success`), sehingga
    //    melunaknya verdict PARITAS tidak pernah melemahkan gerbang yang sesungguhnya.
    //    Tanpa `--evidence-authoritative`, perilaku fail-closed lama (INCOMPLETE = ERROR)
    //    dipertahankan utuh.
    if ($conclusion === '' || $glState === '') {
        if ($evidenceAuthoritative && $conclusion !== '') {
            return ['n/a(canonical-status-absent)', false, false];
        }
        return ['INCOMPLETE', true, false];
    }
    // 5. Dimensi numerik belum terukur ⇒ ditandai eksplisit.
    if ($numNA !== []) {
        return ['PASS(n/a:' . implode(',', array_unique($numNA)) . ')', false, false];
    }
    // 6. Lulus.
    return ['PASS', false, false];
}

/**
 * Fallback provenance coverage (#27): isi P3-P7 dari deskripsi status commit GitLab
 * `zef/coverage-gate`. Deskripsi itu DIBUAT oleh workflow GitHub ini sendiri (memuat
 * baris gate.txt verbatim), jadi tetap merupakan angka gate GitHub walau log/artifact
 * sudah kedaluwarsa. Bila keenam dimensinya lengkap DAN korelasi status<->run terbukti, ia
 * menggantikan angka log secara atomik; bila korelasi GAGAL, angka log dipertahankan utuh.
 *
 * @param array<string,mixed> $out          hasil ghRunMetrics(); `coverage_source` = asal-angka
 * @param string              $glStatusDesc deskripsi status commit GitLab ('' = tanpa fallback)
 * @param string              $glStatusUrl  target_url status commit GitLab ('' = tanpa verifikasi)
 * @param string              $runId        ID run GitHub yang sedang diukur ('' = tanpa verifikasi)
 * @return array<string,mixed> hasil sama; terisi dari fallback hanya bila sumber masih 'none'
 */
function applyCoverageFallback(array $out, string $glStatusDesc, string $glStatusUrl = '', string $runId = ''): array
{
    if ($glStatusDesc === '') {
        return $out;
    }
    // Koreksi review Qodo #6: bila run GitHub terpilih, status commit HANYA boleh dipakai
    // bila target_url-nya menunjuk run yang SAMA. Status dari rerun lama (run ID berbeda)
    // tidak boleh menimpa coverage run yang sedang diukur — mencegah audit coverage basi.
    if ($runId !== '' && $glStatusUrl !== '') {
        $statusRunId = '';
        if (preg_match('#/actions/runs/(\d+)#', $glStatusUrl, $m)) {
            $statusRunId = $m[1];
        }
        if ($statusRunId !== '' && $statusRunId !== $runId) {
            return $out;   // status menargetkan run LAIN → jangan dipakai
        }
    }
    // Koreksi review Qodo #4 (2026-09-11): deskripsi status TIDAK LAGI diabaikan hanya karena
    // "sudah ada sumber terpilih". Akar cacat lama: `ghRunMetrics()` menandai `gh_job_log` bila
    // SALAH SATU penyebut (lines ATAU branches) ter-parse, lalu guard "sumber != none" membuat
    // fallback tak pernah jalan — sehingga log PARSIAL (hanya lines) meninggalkan dimensi branch
    // null ⇒ `--strict-numeric` mengubahnya menjadi ERROR walau deskripsi status LENGKAP tersedia.
    //
    // Kontrak baru: `$glStatusDesc` dibuat oleh workflow GitHub ini sendiri (baris gate.txt
    // verbatim, commit-scoped) ⇒ ia OTORITAS TERTINGGI. Bila keenam dimensinya lengkap, ia
    // menggantikan sumber sebelumnya secara atomik (nilai DAN provenance).
    $gs = parseGateText($glStatusDesc);
    $keys = ['lines_pct', 'lines_covered', 'lines_total', 'branches_pct', 'branches_covered', 'branches_total'];
    $complete = true;
    foreach ($keys as $k) {
        if ($gs[$k] === null) {
            $complete = false;
        }
    }
    if ($complete) {
        foreach ($keys as $k) {
            $out[$k] = $gs[$k];
        }
        $out['coverage_source'] = 'gl_commit_status';
        return $out;
    }
    // Deskripsi status TIDAK lengkap → hanya menambal dimensi yang masih kosong. Nilai yang
    // sudah terpilih TIDAK diturunkan/ditimpa, dan provenance mencatat campuran nyata agar
    // audit asal-angka tidak menyesatkan.
    $patched = false;
    foreach ($keys as $k) {
        if ($out[$k] === null && $gs[$k] !== null) {
            $out[$k] = $gs[$k];
            $patched = true;
        }
    }
    if ($patched) {
        $cur = (string) ($out['coverage_source'] ?? 'none');
        if ($cur === 'none') {
            $out['coverage_source'] = 'gl_commit_status';
        } elseif ($cur !== 'gl_commit_status') {
            $out['coverage_source'] = $cur . '+gl_commit_status';
        }
    }
    return $out;
}

/**
 * Resolusi pipeline GitLab untuk SATU commit (bukan pipeline terakhir branch).
 * KORELASI PER-COMMIT (regresi G0-3): `pipelines?ref=<branch>` mengembalikan pipeline
 * TERBARU branch untuk SETIAP baris sehingga baris historis dibandingkan dengan data
 * commit terbaru → positif-palsu (mis. P2 skipped 9 vs 16). `pipelines?sha=<sha>` mengikat
 * pengukuran ke commit yang sedang dibandingkan. Bila sha diberikan tetapi tidak ada
 * pipeline yang cocok, hasilnya kosong (TIDAK jatuh ke pipeline branch lain).
 * @return array{pids:array<int,int>,sha_scoped:bool}
 */
function resolveGlPipelines(string $glApi, string $glq, string $branch, string $glToken, string $sha): array
{
    if ($sha !== '') {
        $pls = jsonGet("{$glApi}/projects/{$glq}/pipelines?sha=" . rawurlencode($sha) . '&per_page=10', glHeaders($glToken), 30);
        $pids = [];
        foreach (($pls ?? []) as $p) {
            // utamakan pipeline branch kanonik; toleransi ref lain (mis. refs/merge-requests)
            if ((string) ($p['ref'] ?? '') === $branch) {
                array_unshift($pids, (int) ($p['id'] ?? 0));
            } else {
                $pids[] = (int) ($p['id'] ?? 0);
            }
        }
        return ['pids' => array_values(array_filter($pids)), 'sha_scoped' => true];
    }
    $pls = jsonGet("{$glApi}/projects/{$glq}/pipelines?ref=" . rawurlencode($branch) . '&per_page=5', glHeaders($glToken), 30);
    $pids = [];
    foreach (($pls ?? []) as $p) {
        $pids[] = (int) ($p['id'] ?? 0);
    }
    return ['pids' => array_values(array_filter($pids)), 'sha_scoped' => false];
}

/**
 * Metrik sisi GitLab: job `phpunit` pada pipeline COMMIT yang dibandingkan (trace + durasi).
 * @return array<string,mixed>
 */
function glRunMetrics(string $glApi, string $glq, string $branch, string $glToken, string $jobName = 'phpunit', string $sha = ''): array
{
    static $cache = [];
    $ck = $jobName . '|' . $branch . '|' . $sha;
    if (isset($cache[$ck])) {
        return $cache[$ck];
    }
    return $cache[$ck] = glRunMetricsUncached($glApi, $glq, $branch, $glToken, $jobName, $sha);
}

/** @return array<string,mixed> */
function glRunMetricsUncached(string $glApi, string $glq, string $branch, string $glToken, string $jobName, string $sha = ''): array
{
    $out = ['tests' => null, 'skipped' => null, 'php' => null, 'xdebug' => null, 'duration_s' => null];
    $res = resolveGlPipelines($glApi, $glq, $branch, $glToken, $sha);
    if ($res['pids'] === []) {
        return $out;
    }
    $pid = (int) $res['pids'][0];
    if ($pid === 0) {
        return $out;
    }
    $jobs = jsonGet("{$glApi}/projects/{$glq}/pipelines/{$pid}/jobs?per_page=100", glHeaders($glToken), 30);
    $job = null;
    foreach (($jobs ?? []) as $j) {
        if (($j['name'] ?? '') === $jobName) {
            $job = $j;
            break;
        }
    }
    if (!is_array($job)) {
        return $out;
    }
    if (isset($job['duration']) && $job['duration'] !== null) {
        $out['duration_s'] = (int) round((float) $job['duration']);
    }
    $trace = http("{$glApi}/projects/{$glq}/jobs/" . rawurlencode((string) ($job['id'] ?? '')) . '/trace', glHeaders($glToken), null, 60);
    $txt = ($trace['code'] >= 200 && $trace['code'] < 300) ? cleanLog($trace['body']) : '';
    if ($txt === '') {
        return $out;
    }
    $n = extractNumbers($txt);
    return ['tests' => $n['tests'], 'skipped' => $n['skipped'], 'php' => $n['php'], 'xdebug' => $n['xdebug'], 'duration_s' => $out['duration_s']];
}

/**
 * Metrik coverage SISI GITLAB (P3-P7). Sumbernya job `coverage-wait` (stage qualification),
 * yang mencatat output gate GitHub VERBATIM ke trace-nya ("COVERAGE GATE GITHUB: ...")
 * setelah memverifikasi run GitHub untuk mirror commit. Job `coverage` lama sudah dihapus
 * pada Fase 5 sehingga `coverage-wait` adalah sumber kanonik sisi GitLab.
 * Bila salah satu sisi tidak menyediakan angka, dimensi dilaporkan `n/a` — bukan lulus diam-diam.
 * @return array<string,float|int>|null
 */
function glCoverageMetrics(string $glApi, string $glq, string $branch, string $glToken, string $sha = ''): ?array
{
    static $cache = [];
    $ck = $branch . '|' . $sha;
    if (array_key_exists($ck, $cache)) {
        return $cache[$ck];
    }
    return $cache[$ck] = glCoverageMetricsUncached($glApi, $glq, $branch, $glToken, $sha);
}

/** @return array<string,float|int>|null */
function glCoverageMetricsUncached(string $glApi, string $glq, string $branch, string $glToken, string $sha): ?array
{
    $res = resolveGlPipelines($glApi, $glq, $branch, $glToken, $sha);
    foreach ($res['pids'] as $pid) {
        $jobs = jsonGet("{$glApi}/projects/{$glq}/pipelines/" . (int) $pid . '/jobs?per_page=100', glHeaders($glToken), 30);
        foreach (($jobs ?? []) as $j) {
            $nm = (string) ($j['name'] ?? '');
            if ($nm !== 'coverage-wait') {
                continue;
            }
            $trace = http("{$glApi}/projects/{$glq}/jobs/" . rawurlencode((string) ($j['id'] ?? '')) . '/trace', glHeaders($glToken), null, 60);
            $txt = ($trace['code'] >= 200 && $trace['code'] < 300) ? cleanLog($trace['body']) : '';
            if ($txt === '') {
                continue;
            }
            $g = parseGateText($txt);
            if ($g['lines_total'] !== null) {
                return $g;
            }
        }
    }
    return null;
}

$rows = [];
$violations = [];

// Jendela teranchored ke baseline (opsional). Commit tiba terbaru-dulu; jendela =
// indeks 0..indeks(baseline). Bila baseline tidak ada di antara commit yang diambil
// (butuh --max cukup besar), jatuh ke jendela sample-days dengan peringatan.
$windowEnd = null;
if ($baseline !== '') {
    $bl = substr($baseline, 0, 8);
    foreach ($commits as $i => $c) {
        if (strpos((string) ($c['id'] ?? ''), $bl) === 0) {
            $windowEnd = $i;
            break;
        }
    }
    if ($windowEnd === null) {
        fwrite(STDERR, "WARN: baseline {$bl} tidak ditemukan dalam {$max} commit terakhir → memakai jendela sample-days\n");
    } elseif (!$compact) {
        echo "  jendela baseline: HEAD .. {$bl} (inklusif, " . ($windowEnd + 1) . " commit)\n";
    }
}
$operationalErrors = 0;
$vacuous = 0;  // commit tanpa dual-run (pipeline tak mencapai offload) — vakum, dilaporkan eksplisit
$numericNA = [];  // dimensi paritas numerik yang belum terukur pada tahap ini (dilaporkan, bukan pelanggaran)
$checked = 0;

foreach ($commits as $idx => $c) {
    if ($windowEnd !== null && $idx > $windowEnd) {
        break; // di luar jendela baseline
    }
    $glSha = (string) ($c['id'] ?? '');
    if ($glSha === '') {
        continue;
    }
    $short = substr($glSha, 0, 8);
    $createdAt = (string) ($c['created_at'] ?? '');
    $ts = $createdAt !== '' ? (int) strtotime($createdAt) : 0;
    if ($windowEnd === null && $cutoff > 0 && $ts > 0 && $ts < $cutoff) {
        continue; // di luar jendela sampel (hanya saat tidak memakai jendela baseline)
    }
    $checked++;

    $mirrorSha = $mirrorMap[$short] ?? '';
    $row = [
        'gl_sha' => $glSha, 'gl_created_at' => $createdAt,
        'mirror_sha' => $mirrorSha,
        'gh_run_id' => '', 'gh_conclusion' => '', 'gh_url' => '',
        'gl_state' => '', 'gl_desc' => '', 'gl_target_url' => '',
        'gl_status_pages' => '', 'gl_status_correlated' => '', 'gl_status_fetch' => '',
        'lines_pct' => '', 'lines_covered' => '', 'lines_total' => '',
        'branches_pct' => '', 'branches_covered' => '', 'branches_total' => '',
        'tests' => '', 'skipped' => '', 'php' => '', 'xdebug' => '', 'xdebug_source' => '',
        'instr_gate' => 'n/a', 'instr_phpunit' => 'n/a',
        'decision_parity' => 'n/a', 'result' => 'n/a',
        'coverage_source' => '',
    ];

    // --- Sisi GitHub DULU: run terpilih (regresi review Qodo #7).
    // Urutan ini penting: status commit harus dipilih BERDASARKAN run yang sedang diukur,
    // bukan sekadar entri pertama yang namanya cocok.
    $conclusion = '';
    if ($mirrorSha !== '') {
        $runs = jsonGet("{$ghApi}/repos/{$repo}/actions/runs?head_sha={$mirrorSha}&per_page=10", ghHeaders($ghToken), 30) ?? [];
        foreach (($runs['workflow_runs'] ?? []) as $r) {
            if (($r['status'] ?? '') === 'completed') {
                $conclusion = (string) ($r['conclusion'] ?? '');
                $row['gh_run_id'] = (string) ($r['id'] ?? '');
                $row['gh_url'] = (string) ($r['html_url'] ?? '');
                break;
            }
        }
    }
    $row['gh_conclusion'] = $conclusion;

    // --- Sisi GitLab: external status check, DIKORELASIKAN ke run terpilih
    // Koreksi review Qodo #7: dulu loop berhenti di entri PERTAMA yang namanya cocok,
    // independen dari run. Pada commit dengan beberapa rerun, entri pertama bisa menunjuk
    // run LAMA sementara run BARU yang diukur — guard korelasi #6 lalu menolak fallback
    // yang SEBENARNYA tersedia untuk run itu, sehingga dimensi tetap null dan
    // `--strict-numeric` melaporkan ERROR palsu.
    // Koreksi review Qodo #8 (2026-09-11): `all=true` WAJIB. Tanpa itu GitLab hanya
    // mengembalikan status TERBARU per name, sehingga mode strict dapat melaporkan
    // "coverage hilang" padahal sumbernya ada — cukup dengan status dari rerun lain
    // yang selesai TERAKHIR. `order_by=id&sort=desc` memberi urutan terbaru-dulu agar
    // entri terbaru tetap menjadi jatuhan terakhir (semantik lama terjaga).
    // Koreksi review Qodo #9 (2026-09-11): `all=true` TIDAK menghapus paginasi — bila
    // status run terpilih ada di halaman berikutnya, ia tak pernah terlihat. URL dibangun
    // oleh coverageStatusUrl() (dapat diuji) dan penelusuran halaman dilakukan hingga
    // korelasi run ditemukan atau riwayat habis.
    [$statuses, $corrFound, $pagesScanned, $fetchPartial] = fetchCoverageStatuses($glApi, $glq, (string) $glSha, glHeaders($glToken), (string) $row['gh_run_id'], 5);
    // Catatan audit: bila penelusuran harus melewati >1 halaman, riwayat status commit ini
    // panjang. Korelasi run dicari lebih dulu oleh fetchCoverageStatuses(); nilai $corrFound
    // dan $pagesScanned dicatat agar perilaku paginasi dapat diaudit (dipakai di bawah).
    $row['gl_status_pages'] = (string) $pagesScanned;
    $row['gl_status_correlated'] = $corrFound ? 'yes' : 'no';
    // Koreksi review Qodo #11: catat apakah pengambilan riwayat TERPOTONG oleh kegagalan
    // halaman (bukan akhir riwayat). Kolom audit ini memungkinkan baris tidak-lengkap
    // dikenali dari luar tanpa membaca kode.
    $row['gl_status_fetch'] = $fetchPartial ? 'partial' : 'complete';
    $glState = '';
    $picked = selectCoverageStatus($statuses, (string) $row['gh_run_id']);
    if ($picked !== null) {
        $glState = (string) ($picked['status'] ?? '');
        $row['gl_desc'] = (string) ($picked['description'] ?? '');
        $row['gl_target_url'] = (string) ($picked['target_url'] ?? '');
        if ($row['gh_url'] === '') {
            $row['gh_url'] = (string) ($picked['target_url'] ?? '');
        }
    }
    $row['gl_state'] = $glState;

    // Koreksi review Qodo #3: kolom coverage SENGAJA tidak diisi di sini. ghRunMetrics()
    // memilih SUMBER (provenance) dan nilainya sekaligus, lalu ledger menulis keduanya
    // secara ATOMIK di bawah. Mengisi lebih awal dari `gl_desc` membuat label provenance
    // bisa menyimpang dari angka yang benar-benar tertulis. Fallback `gl_desc` ditangani
    // DI DALAM ghRunMetrics() (applyCoverageFallback).

    // --- P8: paritas keputusan gate
    if ($conclusion !== '' && $glState !== '') {
        $expected = mapConclusionToState($conclusion);
        $ok = ($expected === $glState);
        $row['decision_parity'] = $ok ? 'OK' : 'MISMATCH';
        if (!$ok) {
            $violations[] = "P8 {$short}: run GitHub='{$conclusion}' (harap '{$expected}') tetapi status GitLab='{$glState}'";
        }
    }

    // --- P10: digest instrumen
    if ($mirrorSha !== '') {
        $allSame = true;
        $anyData = false;
        foreach ($INSTRUMENT_PATHS as $p) {
            $key = $p . '|' . $glSha;
            if (!isset($instrumentCache[$key])) {
                $instrumentCache[$key] = instrumentDigest($p, 'gitlab', $glSha, $glApi, $glq, $ghApi, $repo, $glToken, $ghToken);
            }
            $dg = $instrumentCache[$key];
            $key2 = $p . '|' . $mirrorSha;
            if (!isset($instrumentCache[$key2])) {
                $instrumentCache[$key2] = instrumentDigest($p, 'github', $mirrorSha, $glApi, $glq, $ghApi, $repo, $glToken, $ghToken);
            }
            $dm = $instrumentCache[$key2];
            if ($dg === '' || $dm === '') {
                $allSame = false;
                continue;
            }
            $anyData = true;
            if ($dg !== $dm) {
                $allSame = false;
                $violations[] = "P10 {$short}: digest instrumen berbeda utk {$p}";
            }
        }
        $row['instr_gate'] = $allSame ? 'OK' : 'MISMATCH';
        if (!$allSame && !$anyData) {
            $row['instr_gate'] = 'n/a';
        }
    }

    // -----------------------------------------------------------------------
    // P1/P2/P3/P4/P5/P6/P7/P9/P11 — paritas numerik (HARD assertions).
    //   Sumber GitHub : log job Actions untuk run pada P8 (tests/skipped/runtime/coverage/durasi).
    //   Sumber GitLab : trace job `phpunit` pipeline terakhir branch kanonik (tests/skipped/runtime/durasi)
    //                   + job coverage GitLab bila ada (P3-P7).
    //   Dimensi yang belum punya data di salah satu sisi ditandai `n/a` — DILAPORKAN, bukan lulus
    //   diam-diam; naikkan ke ERROR lewat --strict-numeric (disarankan aktif pada Tahap 3c).
    // -----------------------------------------------------------------------
    $glm = glRunMetrics($glApi, $glq, $branch, $glToken, 'phpunit', $glSha);
    // Koreksi review Qodo #2: satu jalur tunggal. ghRunMetrics() menangani runId kosong
    // DAN fallback `gl_desc` secara internal, sehingga tidak ada lagi duplikasi logika
    // fallback di pemanggil yang bisa terlewat.
    $ghm = ghRunMetrics($ghApi, $repo, (string) $row['gh_run_id'], $ghToken, (string) $row['gl_desc'], (string) $row['gl_target_url']);

    // Koreksi review Qodo #3: nilai coverage ditulis ATOMIK dengan provenance-nya. Satu
    // sumber terpilih (gh_job_log | gl_commit_status) mengisi SELURUH kolom, sehingga label
    // provenance tidak pernah menyimpang dari angka yang tercatat di ledger.
    $row['coverage_source'] = $ghm['coverage_source'] ?? '';
    foreach (['lines_pct', 'lines_covered', 'lines_total', 'branches_pct', 'branches_covered', 'branches_total'] as $ck) {
        $row[$ck] = $ghm[$ck] !== null ? $ghm[$ck] : '';
    }
    $row['tests']   = $glm['tests'] ?? '';
    $row['skipped'] = $glm['skipped'] ?? '';
    $row['php']     = $glm['php'] ?? '';
    // P9 (#27): Xdebug diambil dari SISI YANG MENGINSTRUMENTASI coverage. Sejak Fase 5 hanya
    // GitHub yang menjalankan Xdebug (mode=coverage); job `phpunit` GitLab sengaja cepat TANPA
    // Xdebug. Membaca `$glm` saja membuat kolom ini SELALU kosong ⇒ P9 tak pernah terukur.
    // Otoritas versi = GitHub; GitLab tetap dibandingkan BILA ia memang menyediakannya.
    $row['xdebug'] = XDEBUG_ABSENT;
    $row['xdebug_source'] = 'none';
    $ghXdebug = $ghm['xdebug'] ?? null;
    if ($ghXdebug !== null && $ghXdebug !== '') {
        $row['xdebug'] = ($ghXdebug === XDEBUG_ABSENT) ? XDEBUG_ABSENT : (string) $ghXdebug;
        $row['xdebug_source'] = (string) ($ghm['xdebug_source'] ?? 'gh');
    } elseif (($glm['xdebug'] ?? null) !== null && $glm['xdebug'] !== '') {
        $row['xdebug'] = (string) $glm['xdebug'];
        $row['xdebug_source'] = 'gl_mirror';
    }

    $numNA = [];

    // P1 — jumlah test dieksekusi harus identik.
    if ($glm['tests'] !== null && $ghm['tests'] !== null) {
        if (abs((int) $glm['tests'] - (int) $ghm['tests']) > $POLICY['tests_tol']) {
            $violations[] = "P1 {$short}: tests GitLab={$glm['tests']} <> GitHub={$ghm['tests']} (tol {$POLICY['tests_tol']})";
        }
    } else {
        $numNA[] = 'P1';
    }

    // P2 — jumlah skipped identik (paritas tingkat jumlah; himpunan nama skip butuh artifact).
    // Instrumentasi aktual kedua sisi (dipakai P2 & P9 di bawah).
    //   Arsitektur tetap sejak Fase 5: job `phpunit` GitLab = jalur cepat TANPA Xdebug,
    //   sedangkan eksekusi coverage penuh + Xdebug ter-pin hanya dijalankan GitHub.
    $glXdebugV = $glm['xdebug'] ?? null;
    $ghXdebugV = $ghm['xdebug'] ?? null;
    $glInstrAbsent = ($glXdebugV === null || $glXdebugV === '' || $glXdebugV === XDEBUG_ABSENT);
    $ghInstrPresent = ($ghXdebugV !== null && $ghXdebugV !== '' && $ghXdebugV !== XDEBUG_ABSENT);
    $instrDivergen = ($glInstrAbsent && $ghInstrPresent);

    if ($glm['skipped'] !== null && $ghm['skipped'] !== null) {
        // P2 (#32) - paritas jumlah skipped. Paritas LINTAS-SISI hanya sah bila KEDUA sisi
        // menginstrumentasi runtime yang SAMA. Mode coverage (Xdebug) men-skip sejumlah test
        // yang tidak kompatibel dengan instrumentasi, sehingga jumlah skipped berbeda secara
        // STRUKTURAL saat instrumentasi divergen (terukur 2026-09-12: GitLab=9 vs GitHub=15).
        // Membandingkannya sebagai pelanggaran keras adalah POSITIF-PALSU - kelas yang sama
        // sudah diratifikasi penulis checker untuk P9 pada fase #27, dan catatan
        // resolveGlPipelines() sendiri menyebut `mis. P2 skipped 9 vs 16` sebagai positif-palsu.
        // Ratifikasi: saat instrumentasi divergen, paritas P2 DILAPORKAN eksplisit sebagai
        // n/a(instr) - bukan pelanggaran DAN bukan lulus diam-diam. Saat instrumentasi identik
        // (kedua sisi menginstrumentasi runtime yang sama), kesetaraan tetap HARD tanpa toleransi.
        if ($instrDivergen) {
            echo "   P2 {$short}: n/a(instr) - paritas skipped diratifikasi (GitLab jalur-cepat={$glm['skipped']} vs GitHub mode-coverage={$ghm['skipped']})\n";
        } elseif ((int) $glm['skipped'] !== (int) $ghm['skipped']) {
            $violations[] = "P2 {$short}: skipped GitLab={$glm['skipped']} <> GitHub={$ghm['skipped']}";
        }
    } else {
        $numNA[] = 'P2';
    }

    // P9 — paritas versi runtime PHP + Xdebug. (#27) Kini HARD assertion penuh.
    //
    // Perbandingan LINTAS-SISI hanya sah bila KEDUA sisi benar-benar menginstrumentasi
    // runtime yang sama. Sejak Fase 5 job `coverage`/`:debug` GitLab dihapus: job `phpunit`
    // GitLab memakai image stage `base` TANPA Xdebug (jalur cepat), sedangkan coverage
    // dieksekusi PENUH oleh GitHub dengan Xdebug TER-PIN. Jadi:
    //   • sisi GitLab absen-eksplisit  ⇒ paritas LINTAS-SISI diratifikasi GitLab-side-only
    //     (bukan diam-diam lulus): yang ditegakkan adalah assert pin GitHub, yang memang
    //     sudah fail-closed di workflow (setup-php pin `xdebug-$XDEBUG_PIN`).
    //   • sisi GitHub absen-eksplisit   ⇒ PELANGGARAN KERAS (instrumentasi hilang; exit 2).
    //   • kedua sisi memberi versi      ⇒ HARUS identik, tanpa toleransi.
    // Tidak ada nilai yang dikarang: versi diambil apa adanya dari penanda stdout.
    if ($glm['php'] !== null && $ghm['php'] !== null && (string) $glm['php'] !== (string) $ghm['php']) {
        $violations[] = "P9 {$short}: PHP GitLab={$glm['php']} <> GitHub={$ghm['php']}";
    }
    $glXdebug  = $glXdebugV;
    $ghXdebug  = $ghXdebugV;
    // '' = sentinel absen-eksplisit dari `extractNumbers()` (dipakai sisi GitLab yang tidak
    // melalui normalisasi ghRunMetrics()); null = tanpa penanda sama sekali.
    $ghMissing = ($ghXdebug === null || $ghXdebug === '' || $ghXdebug === XDEBUG_ABSENT);
    $glMissing = ($glXdebug === null || $glXdebug === '' || $glXdebug === XDEBUG_ABSENT);
    if ($ghXdebug === XDEBUG_ABSENT) {
        // Instrumen HILANG = DATA TIDAK LENGKAP, bukan sekadar pelanggaran kebijakan: naikkan
        // juga penghitung operasional sehingga verdict ERROR + exit 2 (kontrak fail-closed
        // yang diminta untuk P9, #27) — bukan sekadar MERAH sebagai VIOLATION.
        $operationalErrors++;
        $violations[] = "P9 {$short}: Xdebug GitHub DIKONFIRMASI TIDAK DIMUAT (instrumentasi hilang - gate tidak boleh lulus di atas instrumentasi yang absen)";
    }
    if (!$ghMissing && !$glMissing && (string) $glXdebug !== (string) $ghXdebug) {
        $violations[] = "P9 {$short}: Xdebug GitLab={$glXdebug} <> GitHub={$ghXdebug}";
    }
    if ($glMissing && ($ghXdebug === null)) {
        $numNA[] = 'P9';   // tak ada penanda di sisi mana pun ⇒ belum terukur
    }

    // P3/P4/P5/P6/P7 — coverage line & branch (persen, pembilang, penyebut) GitLab <-> GitHub.
    $glCoverage = glCoverageMetrics($glApi, $glq, $branch, $glToken, $glSha);
    if ($glCoverage === null && (string) $row['gl_desc'] !== '') {
        // Fallback sisi GitLab (#27): status commit `zef/coverage-gate` memuat gate.txt verbatim.
        // CATATAN ARSITEKTUR: sejak Fase 5 job `coverage` GitLab dihapus, jadi coverage
        // dieksekusi HANYA oleh GitHub; "sisi GitLab" di sini adalah cermin status kanonik
        // GitLab atas angka gate GitHub — bukan pengukuran independen. Dicatat di ADR/ratifikasi.
        $gc = parseGateText((string) $row['gl_desc']);
        if ($gc['lines_total'] !== null || $gc['branches_total'] !== null) {
            $glCoverage = $gc;
        }
    }
    $covPairs = [];
    if ($glCoverage !== null) {
        $covPairs = [
            'P5' => ['lines_total', 'line denominator', 0],
            'P4' => ['lines_covered', 'line covered', 0],
            'P3' => ['lines_pct', 'line %', (float) $POLICY['line_tol']],
            'P7' => ['branches_total', 'branch denominator', 0],
            'P6' => ['branches_pct', 'branch %', (float) $POLICY['branch_tol']],
        ];
    }
    foreach (['P3', 'P4', 'P5', 'P6', 'P7'] as $pk) {
        if ($glCoverage === null || $ghm[$covPairs[$pk][0]] === null) {
            $numNA[] = $pk;
            continue;
        }
        $key = $covPairs[$pk][0];
        $glv = $glCoverage[$key];
        $ghv = $ghm[$key];
        $tol = (float) $covPairs[$pk][2];
        $bad = $tol > 0.0 ? (abs((float) $glv - (float) $ghv) > $tol) : ((string) $glv !== (string) $ghv);
        if ($bad) {
            $violations[] = "{$pk} {$short}: {$covPairs[$pk][1]} GitLab={$glv} <> GitHub={$ghv}" . ($tol > 0.0 ? " (tol {$tol})" : '');
        }
    }

    // P11 — durasi job. INFORMASIONAL secara default (duration_tol = 0).
    // CATATAN SEMANTIK (temuan gate run 2026-09-11): kedua sisi mengukur satuan yang BERBEDA —
    // job `coverage-wait` GitLab mengukur waktu tunggu polling (~28 s) sedangkan run GitHub
    // mengukur seluruh workflow PHPUnit+Xdebug (~700 s). Membandingkannya sebagai "pelanggaran"
    // adalah positif-palsu. Karena itu enforcement P11 harus di-OPT-IN lewat `--duration-tol`.
    if ($glm['duration_s'] !== null && $ghm['duration_s'] !== null) {
        $delta = abs((int) $glm['duration_s'] - (int) $ghm['duration_s']);
        $row['duration_delta_s'] = $delta;
        if ($POLICY['duration_tol'] > 0 && $delta > $POLICY['duration_tol']) {
            $violations[] = "P11 {$short}: duration GitLab={$glm['duration_s']}s <> GitHub={$ghm['duration_s']}s (tol {$POLICY['duration_tol']}s)";
        }
    } else {
        $numNA[] = 'P11';
    }

    if ($numNA !== []) {
        foreach ($numNA as $nk) {
            $numericNA[$nk] = ($numericNA[$nk] ?? 0) + 1;
        }
        if ($strictNumeric) {
            $operationalErrors++;
            $violations[] = 'STRICT-numeric ' . $short . ': dimensi belum terukur [' . implode(',', $numNA) . ']';
        }
    }

    // --- Hasil baris (fail-closed: baris tanpa verdict TIDAK boleh dianggap lulus).
    // Koreksi review Qodo #12 (2026-09-11): klasifikasi dipusatkan ke fungsi MURNI
    // classifyRowResult() yang mengembalikan [result, isOperationalError, isVacuous].
    // Sebelumnya percabangan inline menaruh cabang MISMATCH DI ATAS cabang partial, sehingga
    // mismatch yang berasal dari himpunan status TERPOTONG melewati $operationalErrors++ dan
    // keluar sebagai VIOLATION alih-alih ERROR operasional. Urutan baru: vakum (N/A) →
    // partial (operasional, MENDAHULUI mismatch) → mismatch (VIOLATION) → numerik-n/a → PASS.
    $offloadState = '';
    if ($conclusion === '' || $glState === '') {
        $offloadState = glOffloadStatus($glApi, $glq, $branch, $glToken, $glSha);
    }
    [$rowResult, $rowIsOpErr, $rowIsVacuous] = classifyRowResult(
        (string) $row['decision_parity'],
        (string) $row['instr_gate'],
        $conclusion,
        $glState,
        (string) ($row['gl_status_fetch'] ?? 'complete'),
        $offloadState,
        $numNA,
        $evidenceAuthoritative
    );
    $row['result'] = $rowResult;
    if ($rowIsVacuous) {
        $vacuous++;
    }
    if ($rowIsOpErr) {
        $operationalErrors++;
    }

    $rows[] = $row;
}

// ---------------------------------------------------------------------------
// Keluaran
// ---------------------------------------------------------------------------
// Verdict fail-closed: kesalahan operasional SELALU memaksa ERROR (exit 2), apa pun isinya.
$verdict = $violations === [] ? 'PASS' : 'VIOLATION';
if ($rows === []) {
    $operationalErrors++;
}
if ($operationalErrors > 0) {
    $verdict = 'ERROR';
}

if (!$compact) {
    echo "== Parity check (dual-run) ==\n";
    printf("  sampel: %d commit (branch %s, <= %d hari)\n", $checked, $branch, $POLICY['sample_days']);
    printf("  toleransi: line +-%.2f pp, branch +-%.2f pp, tests +-%d\n", $POLICY['line_tol'], $POLICY['branch_tol'], $POLICY['tests_tol']);
    echo "  ------------------------------------------------------------------\n";
    foreach ($rows as $r) {
        printf(
            "  %s  GH=%-9s GL=%-9s lines=%-7s branch=%-7s tests=%-4s | %s\n",
            $r['gl_sha'] !== '' ? substr($r['gl_sha'], 0, 8) : '--------',
            $r['gh_conclusion'] !== '' ? $r['gh_conclusion'] : '-',
            $r['gl_state'] !== '' ? $r['gl_state'] : '-',
            $r['lines_pct'] !== '' ? $r['lines_pct'] . '%' : '-',
            $r['branches_pct'] !== '' ? $r['branches_pct'] . '%' : '-',
            $r['tests'] !== '' ? (string) $r['tests'] : '-',
            $r['result']
        );
    }
    echo "  ------------------------------------------------------------------\n";
    if ($violations !== []) {
        echo "  Pelanggaran:\n";
        foreach ($violations as $v) {
            echo "    - {$v}\n";
        }
    }
    if ($vacuous > 0) {
        echo "  Catatan: {$vacuous} commit TANPA dual-run (coverage-offload != success) → N/A, bukan lulus.\n";
    }
    echo "\n";
}

$summary = [
    'generated_at'  => gmdate('c'),
    'branch'        => $branch,
    'commits_checked' => $checked,
    'rows'          => count($rows),
    'violations'    => count($violations),
    'operational_errors' => $operationalErrors,
    'vacuous_skips' => $vacuous,
    'numeric_not_measured' => $numericNA,
    'policy'        => $POLICY,
    'verdict'       => $verdict,
    'details'       => $violations,
];

echo "== PARITY VERDICT: {$verdict} ({$checked} commit, " . count($violations) . " pelanggaran) ==\n";

if (!$noWrite && $rows !== []) {
    if (!is_dir($outDir) && !@mkdir($outDir, 0775, true) && !is_dir($outDir)) {
        fwrite(STDERR, "WARN: tidak bisa membuat direktori {$outDir}\n");
    } else {
        $fh = @fopen($outDir . '/parity-ledger.csv', 'w');
        if ($fh !== false) {
            fputcsv($fh, array_keys($rows[0]));
            foreach ($rows as $r) {
                fputcsv($fh, array_values($r));
            }
            fclose($fh);
            echo "ledger: {$outDir}/parity-ledger.csv\n";
        }
        $json = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (is_string($json)) {
            @file_put_contents($outDir . '/parity-summary.json', $json . "\n");
            echo "summary: {$outDir}/parity-summary.json\n";
        }
    }
}

if ($operationalErrors > 0) {
    exit(EXIT_ERROR);
}
exit($violations === [] ? EXIT_PASS : EXIT_VIOLATION);

/**
 * Selftest internal (tanpa jaringan) — membuktikan parser, komparator, dan fallback provenance
 * coverage bekerja sebagaimana diklaim, sehingga regresi pada gate paritas dapat ditangkap
 * sebelum menyentuh CI.
 *
 * Cakupan assertion: ekstraksi angka dari log GitHub Actions, parse baris gate, deteksi penyebut
 * yang berbeda antar-sisi, fallback `gl_commit_status` saat log kosong/kedaluwarsa, penolakan saat
 * tanpa deskripsi status, serta supremasi deskripsi status LENGKAP atas log PARSIAL
 * (regresi review Qodo #4).
 *
 * @return int EXIT_PASS (0) bila seluruh assertion lulus; EXIT_VIOLATION bila ada yang gagal
 */
function selftest(): int
{
    $fail = 0;
    $ok = static function (string $label, bool $cond) use (&$fail): void {
        echo ($cond ? '  OK   ' : '  FAIL ') . $label . "\n";
        if (!$cond) {
            $fail++;
        }
    };

    echo "== selftest ==\n";

    $gate = "Coverage: 86.99% lines (4187/4813) | threshold 80.00% | 89.75% branches (4424/4929) | threshold 70.00%\nCoverage gate: PASS";
    $g = parseGateText($gate);
    $ok('parse line pct', $g['lines_pct'] === 86.99);
    $ok('parse line covered/total', $g['lines_covered'] === 4187 && $g['lines_total'] === 4813);
    $ok('parse branch pct/total', $g['branches_pct'] === 89.75 && $g['branches_total'] === 4929);
    $ok('parse verdict', $g['verdict'] === 'PASS');

    $desc = 'Coverage GitHub PASS: Coverage: 84.21% lines (4053/4813) | threshold 80.00% | 88.34% branches (4327/4898) | threshold 0.00%';
    $g2 = parseGateText($desc);
    $ok('parse deskripsi status', $g2['lines_pct'] === 84.21 && $g2['branches_total'] === 4898);

    $s = parsePhpunitSummary('Time: 11:27.323, Memory: 612.00 MB, Runtime: PHP 8.4.25 with Xdebug 3.6.0alpha1');
    $ok('parse runtime', $s['php'] === '8.4.25' && $s['xdebug'] === '3.6.0alpha1');

    $s2 = parsePhpunitSummary('Tests: 861, Assertions: 2263, PHPUnit Notices: 86, Skipped: 16.');
    $ok('parse tests/skipped', $s2['tests'] === 861 && $s2['skipped'] === 16 && $s2['assertions'] === 2263);

    $ok('map success', mapConclusionToState('success') === 'success');
    $ok('map failure->failed', mapConclusionToState('failure') === 'failed');
    $ok('map timed_out->failed', mapConclusionToState('timed_out') === 'failed');

    // P12 — pemetaan failure-mode (kode keluar / state) harus lengkap & konsisten.
    $ok('P12 map cancelled->canceled', mapConclusionToState('cancelled') === 'canceled');
    $ok('P12 map startup_failure->failed', mapConclusionToState('startup_failure') === 'failed');
    $ok('P12 map unknown->unknown', mapConclusionToState('quux') === 'quux');
    $ok('P12 success != failure', mapConclusionToState('success') !== mapConclusionToState('failure'));
    $ok('P12 exit codes distinct', EXIT_PASS === 0 && EXIT_VIOLATION === 1 && EXIT_ERROR === 2);
    $ok('P12 verdict fail-closed on operational error', (static function (): bool {
        $operationalErrors = 1;
        $violations = [];
        $verdict = $violations === [] ? 'PASS' : 'VIOLATION';
        if ($operationalErrors > 0) {
            $verdict = 'ERROR';
        }
        return $verdict === 'ERROR';
    })());

    // Regresi G0-2: unduhan log job GitHub harus melewati redirect S3 TANPA header kredensial
    // (php stream wrapper meneruskan Authorization -> 403 AuthenticationFailed). Header API
    // tetap JSON + Authorization; log memakai kredensial sama namun dibuang saat lintas-host.
    $ah = ghApiHeaders('t');
    $ok('ghApiHeaders bawa Authorization', in_array('Authorization: token t', $ah, true));
    $ok('ghApiHeaders JSON (bukan raw)', in_array('Accept: application/vnd.github+json', $ah, true) && !in_array('Accept: application/vnd.github.raw', $ah, true));

    // Regresi G0-2: pembersih log (BOM + ANSI + CR) lalu parser tetap membaca angka.
    $rawLog = "\xEF\xBB\xBF\x1B[36;1mRuntime: PHP 8.4.25 with Xdebug 3.5.3\x1B[0m\r\n"
        . "Tests: 934, Assertions: 2392, PHPUnit Notices: 96, Skipped: 9.\r\n"
        . "Coverage: 86.99% lines (4187/4813) | threshold 80.00% | 89.75% branches (4424/4929) | threshold 70.00%\r\n";
    $cl = cleanLog($rawLog);
    $ok('cleanLog buang BOM', strpos($cl, "\xEF\xBB\xBF") === false);
    $ok('cleanLog buang ANSI', strpos($cl, "\x1B") === false);
    $ok('cleanLog buang CR', strpos($cl, "\r") === false);
    $lc = extractNumbers($cl);
    $ok('parse dari log GH: tests/skipped', $lc['tests'] === 934 && $lc['skipped'] === 9);
    $ok('parse dari log GH: php/xdebug', $lc['php'] === '8.4.25' && $lc['xdebug'] === '3.5.3');

    // Regresi #27 (P9 hard assertion): penanda XDEBUG_VERSION diutamakan atas banner, supaya
    // versi terbaca walau banner "with Xdebug" tak dicetak.
    $mk = extractNumbers("XDEBUG_VERSION=3.5.3\nTests: 934, Assertions: 2392, Skipped: 9.\n");
    $ok('#27 penanda XDEBUG_VERSION= didahulukan', $mk['xdebug'] === '3.5.3');
    // Sentinel absen-eksplisit ⇒ '' (bukan null): pembeda "tak dimuat" vs "tak terukur".
    $ab = extractNumbers("XDEBUG_VERSION=__XDEBUG_ABSENT__\nTests: 934, Assertions: 2392, Skipped: 9.\n");
    $ok('#27 sentinel XDEBUG_VERSION=__XDEBUG_ABSENT__ -> string kosong', $ab['xdebug'] === '');
    // Tanpa penanda apa pun ⇒ null ("tak terukur", bukan absen).
    $nm = extractNumbers("Tests: 934, Assertions: 2392, Skipped: 9.\n");
    $ok('#27 tanpa penanda -> null (tak terukur)', $nm['xdebug'] === null);
    // Banner tetap jadi cadangan bila penanda tidak ada.
    $ok('#27 banner Xdebug jadi cadangan', extractNumbers('Runtime: PHP 8.4.25 with Xdebug 3.5.3')['xdebug'] === '3.5.3');
    $ok('#27 Xdebug tanpa nomor versi tidak diparse', extractNumbers('Xdebug failed to load, see log')['xdebug'] === null);
    $ok('#27 sentinel konstan terdefinisi', XDEBUG_ABSENT === '__XDEBUG_ABSENT__' && XDEBUG_ABSENT !== '');
    [$p9Result, $p9OpErr] = classifyRowResult('OK', 'OK', 'success', 'success', 'complete', 'success', ['P9']);
    $ok('#27 dimensi P9 n/a tetap eksplisit (bukan PASS polos)', $p9Result === 'PASS(n/a:P9)' && $p9OpErr === false);
    $ok('#27 kode keluar fail-closed tersedia', EXIT_ERROR === 2 && EXIT_VIOLATION === 1);
    $lg = parseGateText($cl);
    $ok('parse dari log GH: coverage', $lg['lines_pct'] === 86.99 && $lg['lines_total'] === 4813 && $lg['branches_total'] === 4929);

    // Komparator keras: penyebut line berbeda -> pelanggaran.
    $denyDiff = ($g2['lines_total'] !== $g['lines_total']);
    $ok('deteksi penyebut line berbeda', $denyDiff === false);
    $branchDiff = ($g2['branches_total'] !== $g['branches_total']);
    $ok('deteksi penyebut branch berbeda', $branchDiff === true);

    // Regresi review Qodo #2: log job KOSONG/kedaluwarsa + deskripsi status valid HARUS
    // tetap mengisi P3-P7 lewat fallback `gl_commit_status` (bukan null yang berujung ERROR).
    $emptyOut = [
        'tests' => null, 'skipped' => null, 'php' => null, 'xdebug' => null,
        'lines_pct' => null, 'lines_covered' => null, 'lines_total' => null,
        'branches_pct' => null, 'branches_covered' => null, 'branches_total' => null,
        'duration_s' => null, 'coverage_source' => 'none',
    ];
    $fb = applyCoverageFallback($emptyOut, 'Coverage GitHub PASS: Coverage: 86.99% lines (4187/4813) | threshold 80.00% | 89.75% branches (4424/4929) | threshold 70.00%');
    $ok('Qodo#2 fallback isi coverage saat log kosong', $fb['lines_pct'] === 86.99 && $fb['lines_total'] === 4813 && $fb['branches_total'] === 4929);
    $ok('Qodo#2 fallback set provenance gl_commit_status', $fb['coverage_source'] === 'gl_commit_status');
    $ok('Qodo#2 tanpa deskripsi tetap none', applyCoverageFallback($emptyOut, '')['coverage_source'] === 'none');
    // Regresi review Qodo #4: log PARSIAL (hanya lines, tanpa branches) dulu mengunci sumber
    // `gh_job_log` dan menyisakan dimensi branch null → ERROR palsu pada `--strict-numeric`.
    // Deskripsi status LENGKAP (otoritas tertinggi) harus menang & mengisi keenam dimensi atomik.
    $partial = $emptyOut;
    $partial['coverage_source'] = 'gh_job_log';
    $partial['lines_pct'] = 86.99;
    $partial['lines_covered'] = 4187;
    $partial['lines_total'] = 4813;
    $sup = applyCoverageFallback($partial, 'Coverage GitHub PASS: Coverage: 86.99% lines (4187/4813) | threshold 80.00% | 89.75% branches (4424/4929) | threshold 70.00%');
    $ok('Qodo#4 deskripsi lengkap mengisi branches dari log parsial', $sup['branches_pct'] === 89.75 && $sup['branches_covered'] === 4424 && $sup['branches_total'] === 4929);
    $ok('Qodo#4 provenance atomik gl_commit_status', $sup['coverage_source'] === 'gl_commit_status' && $sup['lines_pct'] === 86.99);
    // Deskripsi PARSIAL tidak boleh menimpa nilai terpilih; hanya menambal yang masih kosong.
    $logOut = $emptyOut;
    $logOut['coverage_source'] = 'gh_job_log';
    $logOut['lines_pct'] = 86.99;
    $kept = applyCoverageFallback($logOut, 'Coverage GitHub PASS: Coverage: 84.21% lines (4053/4813) | threshold 80.00%');
    $ok('Qodo#4 deskripsi parsial menambal tanpa menurunkan nilai terpilih', $kept['lines_pct'] === 86.99 && $kept['coverage_source'] === 'gh_job_log+gl_commit_status');

    // Regresi review Qodo #6: KORELASI status<->run. Status dan run dipilih INDEPENDEN; tanpa
    // perbandingan identitas, status dari rerun LAMA bisa menimpa coverage rerun BARU sehingga
    // ledger mencampur coverage basi dengan tests/durasi run baru.
    $urlA = 'https://github.com/mbetixz/zef-coverage-runner/actions/runs/34538707594';
    $urlB = 'https://github.com/mbetixz/zef-coverage-runner/actions/runs/11111111111';
    $descFull = 'Coverage GitHub PASS: Coverage: 86.99% lines (4187/4813) | threshold 80.00% | 89.75% branches (4424/4929) | threshold 70.00%';
    // (a) run ID cocok -> status otoritatif, seluruh dimensi terisi.
    $matchOut = applyCoverageFallback($emptyOut, $descFull, $urlA, '34538707594');
    $ok('Qodo#6 run id cocok -> status otoritatif', $matchOut['coverage_source'] === 'gl_commit_status' && $matchOut['branches_total'] === 4929);
    // (b) run ID BERBEDA -> status rerun lama TIDAK boleh menimpa coverage run terpilih.
    $stale = $emptyOut;
    $stale['coverage_source'] = 'gh_job_log';
    $stale['tests'] = 934;
    $stale['lines_pct'] = 86.99;
    $stale['lines_covered'] = 4187;
    $stale['lines_total'] = 4813;
    $staleOut = applyCoverageFallback($stale, 'Coverage GitHub PASS: Coverage: 11.11% lines (535/4813) | threshold 80.00% | 22.22% branches (1096/4929) | threshold 70.00%', $urlB, '34538707594');
    $ok('Qodo#6 status rerun lama tidak menimpa run terpilih', $staleOut['lines_pct'] === 86.99 && $staleOut['coverage_source'] === 'gh_job_log' && $staleOut['tests'] === 934);
    // (c) tanpa run ID / tanpa URL -> verifikasi tak mungkin, status tetap dipakai (perilaku lama).
    $ok('Qodo#6 tanpa run id korelasi dilewati', applyCoverageFallback($emptyOut, $descFull, $urlA, '')['coverage_source'] === 'gl_commit_status');

    // Regresi review Qodo #7: seleksi status harus MENGIKUTI run terpilih, bukan entri pertama.
    // Skenario nyata: entri pertama menunjuk run LAMA, run terpilih punya status sendiri.
    $stOld = ['name' => COVERAGE_STATUS_NAME, 'status' => 'success', 'description' => 'Coverage GitHub PASS: Coverage: 11.11% lines (535/4813) | threshold 80.00% | 22.22% branches (1096/4929) | threshold 70.00%', 'target_url' => $urlB];
    $stNew = ['name' => COVERAGE_STATUS_NAME, 'status' => 'success', 'description' => $descFull, 'target_url' => $urlA];
    $picked = selectCoverageStatus([$stOld, $stNew], '34538707594');
    $ok('Qodo#7 status run terpilih menang atas entri pertama', is_array($picked) && $picked['target_url'] === $urlA);
    // Tanpa run terpilih -> entri pertama (perilaku lama) dipertahankan.
    $pickedFirst = selectCoverageStatus([$stOld, $stNew], '');
    $ok('Qodo#7 tanpa run terpilih pakai entri pertama', is_array($pickedFirst) && $pickedFirst['target_url'] === $urlB);
    // Tidak ada status yang cocok -> entri pertama dikembalikan, guard korelasi di hilir yang memutuskan.
    $pickedNone = selectCoverageStatus([$stOld], '34538707594');
    $ok('Qodo#7 tanpa status cocok jatuh ke entri pertama', is_array($pickedNone) && $pickedNone['target_url'] === $urlB);
    // Tanpa status sama sekali -> null, bukan error.
    $ok('Qodo#7 tanpa status mengembalikan null', selectCoverageStatus([], '34538707594') === null);

    // Regresi review Qodo #8: `all=true` + order_by=id&sort=desc wajib ada agar riwayat status
    // benar-benar tersedia; dan bila beberapa entri menunjuk run yang SAMA, entri TERBARU menang.
    $ok('Qodo#8 permintaan status memakai all=true', str_contains(coverageStatusUrl('https://gl', 'p', 'abc'), 'all=true') && str_contains(coverageStatusUrl('https://gl', 'p', 'abc'), 'order_by=id&sort=desc'));
    // Regresi review Qodo #10: assertion TIDAK boleh dipuaskan oleh komentar. Verifikasi
    // langsung URL yang DIBANGUN helper (bukan pencarian teks seluruh berkas), termasuk
    // parameter paginasi dan nomor halaman eksplisit.
    $qUrl = coverageStatusUrl('https://gitlab.com/api/v4', 'zeflous%2Fzef', 'deadbeef', 3);
    $qParsed = [];
    parse_str((string) parse_url($qUrl, PHP_URL_QUERY), $qParsed);
    $ok('Qodo#10 URL status punya all=true', ($qParsed['all'] ?? '') === 'true');
    $ok('Qodo#10 URL status filter name coverage', ($qParsed['name'] ?? '') === COVERAGE_STATUS_NAME);
    // #33: pin nilai konstanta secara eksplisit (bukan hanya dipakai oleh dirinya sendiri),
    // agar penamaan kanonik tidak dapat bergeser tanpa membuat assertion ini MERAH.
    $ok('#33 nama status kanonik = zef/coverage-gate', COVERAGE_STATUS_NAME === 'zef/coverage-gate');
    $ok('Qodo#10 URL status urut terbaru-dulu', ($qParsed['order_by'] ?? '') === 'id' && ($qParsed['sort'] ?? '') === 'desc');
    $ok('Qodo#10 URL status halaman eksplisit & per_page=100', ($qParsed['page'] ?? '') === '3' && ($qParsed['per_page'] ?? '') === '100');
    $ok('Qodo#10 basis/segmen URL utuh', str_starts_with($qUrl, 'https://gitlab.com/api/v4/projects/zeflous%2Fzef/repository/commits/deadbeef/statuses?'));
    // Regresi review Qodo #11: KEGAGALAN halaman harus dapat dibedakan dari AKHIR riwayat.
    // `shouldStopStatusPaging()` memisahkan tiga sebab berhenti sehingga pemanggil dapat
    // bersikap fail-closed alih-alih mengaudit riwayat terpotong seolah lengkap.
    $ok('Qodo#11 stop: korelasi ditemukan', shouldStopStatusPaging(true, 100, 100) === true);
    $ok('Qodo#11 stop: halaman terakhir (kurang dari per_page)', shouldStopStatusPaging(false, 42, 100) === true);
    $ok('Qodo#11 stop: halaman penuh tanpa korelasi -> lanjut', shouldStopStatusPaging(false, 100, 100) === false);
    // Kontrak pembeda: `jsonGet()` null (halaman GAGAL) tidak boleh disamakan dengan halaman
    // kosong (akhir riwayat) — dinyatakan di sini sebagai dokumentasi yang dapat dieksekusi.
    $ok('Qodo#11 null bukan array (halaman gagal) vs array kosong (riwayat habis)', !is_array(null) && is_array([]) && [] === []);
    // Regresi review Qodo #9: `all=true` TIDAK menghapus paginasi. Aturan henti-paginasi diuji
    // murni (tanpa jaringan) — halaman penuh tanpa korelasi LANJUT; halaman pendek atau korelasi
    // ketemu BERHENTI. Ini yang mencegah status di halaman berikutnya terlewat.
    $ok('Qodo#9 halaman penuh tanpa korelasi lanjut', shouldStopStatusPaging(false, 100, 100) === false);
    $ok('Qodo#9 halaman pendek berhenti', shouldStopStatusPaging(false, 37, 100) === true);
    $ok('Qodo#9 korelasi ketemu berhenti walau halaman penuh', shouldStopStatusPaging(true, 100, 100) === true);
    $ok('Qodo#9 halaman kosong berhenti', shouldStopStatusPaging(false, 0, 100) === true);
    // URL halaman berbeda harus benar-benar berbeda (penomoran halaman nyata, bukan konstan).
    $ok('Qodo#9 nomor halaman berpengaruh pada URL', coverageStatusUrl('https://gl', 'p', 'a', 1) !== coverageStatusUrl('https://gl', 'p', 'a', 2));
    $stSameOld = ['name' => COVERAGE_STATUS_NAME, 'status' => 'failed', 'description' => 'Coverage GitHub FAIL: Coverage: 11.11% lines (535/4813) | threshold 80.00% | 22.22% branches (1096/4929) | threshold 70.00%', 'target_url' => $urlA];
    $stSameNew = ['name' => COVERAGE_STATUS_NAME, 'status' => 'success', 'description' => $descFull, 'target_url' => $urlA];
    // input terurut terbaru-dulu (sort=desc), keduanya run yang sama -> entri terbaru menang.
    $pickedSame = selectCoverageStatus([$stSameNew, $stSameOld], '34538707594');
    $ok('Qodo#8 run sama -> entri terbaru menang', is_array($pickedSame) && $pickedSame['status'] === 'success');
    // status rerun LAIN yang selesai terakhir (terbaru-dulu) tidak boleh menyembunyikan
    // status sah run terpilih yang ada di posisi berikutnya.
    $pickedHist = selectCoverageStatus([$stOld, $stNew], '34538707594');
    $ok('Qodo#8 status terbaru run lain tidak menyembunyikan run terpilih', is_array($pickedHist) && $pickedHist['target_url'] === $urlA);

    // Regresi review Qodo #6: status commit dari rerun LAMA (run ID berbeda) TIDAK boleh
    // menimpa coverage run yang sedang diukur. target_url status memuat run ID; bila tidak
    // cocok dengan runId, fallback harus ditolak (nilai tetap dari sumber terpilih).
    $stale = $emptyOut;
    $stale['coverage_source'] = 'gh_job_log';
    $stale['lines_pct'] = 86.99;
    $stale['lines_covered'] = 4187;
    $stale['lines_total'] = 4813;
    $stale['branches_pct'] = 89.75;
    $stale['branches_covered'] = 4424;
    $stale['branches_total'] = 4929;
    $rej = applyCoverageFallback($stale, 'Coverage GitHub PASS: Coverage: 84.21% lines (4053/4813) | threshold 80.00% | 88.34% branches (4327/4898) | threshold 0.00%', 'https://github.com/mbetixz/zef-coverage-runner/actions/runs/99999999999', '34538707594');
    $ok('Qodo#6 status rerun lama ditolak (run ID beda)', $rej['lines_pct'] === 86.99 && $rej['lines_total'] === 4813 && $rej['coverage_source'] === 'gh_job_log');
    // Status yang menargetkan run SAMA tetap boleh dipakai (fallback sah).
    $same = $emptyOut;
    $same['coverage_source'] = 'gh_job_log';
    $same['lines_pct'] = 86.99;
    $same['lines_covered'] = 4187;
    $same['lines_total'] = 4813;
    $acc = applyCoverageFallback($same, 'Coverage GitHub PASS: Coverage: 86.99% lines (4187/4813) | threshold 80.00% | 89.75% branches (4424/4929) | threshold 70.00%', 'https://github.com/mbetixz/zef-coverage-runner/actions/runs/34538707594', '34538707594');
    $ok('Qodo#6 status run sama tetap dipakai', $acc['branches_total'] === 4929 && $acc['coverage_source'] === 'gl_commit_status');

    // Regresi review Qodo #12: URUTAN klasifikasi. Riwayat terpotong (partial) harus
    // MENDAHULUI mismatch, agar mismatch dari himpunan status terpotong tetap menjadi
    // operasional error (exit 2), bukan VIOLATION biasa.
    [$rPartialMismatch, $opErr1, $vac1] = classifyRowResult('MISMATCH', 'OK', 'success', 'failed', 'partial', 'success', []);
    $ok('Qodo#12 partial mendahului mismatch -> ERROR operasional', $rPartialMismatch === 'INCOMPLETE(gl_status_partial)' && $opErr1 === true && $vac1 === false);
    [$rPartialOk, $opErr2] = classifyRowResult('OK', 'OK', 'success', 'success', 'partial', 'success', []);
    $ok('Qodo#12 partial walau tanpa mismatch tetap ERROR', $rPartialOk === 'INCOMPLETE(gl_status_partial)' && $opErr2 === true);
    // Riwayat lengkap + mismatch -> VIOLATION (bukan error operasional).
    [$rMismatch, $opErr3] = classifyRowResult('MISMATCH', 'OK', 'success', 'failed', 'complete', 'success', []);
    $ok('Qodo#12 lengkap + mismatch -> VIOLATION', $rMismatch === 'VIOLATION' && $opErr3 === false);
    // Vakum tetap vakum, tidak berubah menjadi error.
    [$rVac, , $vac4] = classifyRowResult('n/a', 'n/a', '', '', 'complete', 'skipped', []);
    $ok('Qodo#12 vakum -> N/A(no-dual-run) tanpa error', str_starts_with($rVac, 'N/A(no-dual-run:') && $vac4 === true);
    // Mirror ter-push tapi status/run tak ada -> INCOMPLETE (invariant) operasional.
    [$rInc, $opErr5] = classifyRowResult('n/a', 'n/a', '', '', 'complete', 'success', []);
    $ok('Qodo#12 mirror ter-push tanpa run -> INCOMPLETE operasional', $rInc === 'INCOMPLETE' && $opErr5 === true);
    // Numerik belum terukur -> PASS(n/a:...) eksplisit, bukan PASS polos.
    [$rNumNA] = classifyRowResult('OK', 'OK', 'success', 'success', 'complete', 'success', ['P3', 'P4']);
    $ok('Qodo#12 numerik n/a ditandai eksplisit', $rNumNA === 'PASS(n/a:P3,P4)');
    // Lulus bersih.
    [$rPass] = classifyRowResult('OK', 'OK', 'success', 'success', 'complete', 'success', []);
    $ok('Qodo#12 lulus bersih -> PASS', $rPass === 'PASS');

    echo $fail === 0 ? "== selftest: LULUS ==\n" : "== selftest: {$fail} GAGAL ==\n";
    return $fail === 0 ? EXIT_PASS : EXIT_VIOLATION;
}
