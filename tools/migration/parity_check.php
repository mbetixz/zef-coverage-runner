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
 *        - GitLab : external status check `github-actions/coverage` (state/description/target_url).
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
 *     --gl-token="$GL_TOKEN" --gh-token="$GH_TOKEN"
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
 * Token dibaca dari flag atau env (GL_TOKEN / GITHUB_TOKEN / GH_TOKEN). Token TIDAK pernah dicetak.
 */

declare(strict_types=1);

const EXIT_PASS = 0;
const EXIT_VIOLATION = 1;
const EXIT_ERROR = 2;

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
$baseline = (string) $opt('baseline', '');

$POLICY['line_tol']    = (float) $opt('line-tol', $POLICY['line_tol']);
$POLICY['branch_tol']  = (float) $opt('branch-tol', $POLICY['branch_tol']);
$POLICY['tests_tol']   = (int) $opt('tests-tol', $POLICY['tests_tol']);
$POLICY['sample_days'] = (int) $opt('sample-days', $POLICY['sample_days']);
$POLICY['duration_tol'] = (float) $opt('duration-tol', $POLICY['duration_tol']);

$glToken = (string) ($opt('gl-token') ?: getenv('GL_TOKEN') ?: getenv('GITLAB_TOKEN') ?: '');
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
    if ($u['xdebug'] === null && preg_match('/Xdebug\s+v?([0-9][0-9A-Za-z.\-]*)/', $txt, $m)) {
        $u['xdebug'] = $m[1];
    }
    return ['tests' => $u['tests'], 'skipped' => $u['skipped'], 'php' => $u['php'], 'xdebug' => $u['xdebug']];
}

/**
 * Metrik sisi GitHub: ambil job pertama pada run, durasi, lalu log job (plain).
 * @return array<string,mixed>
 */
function ghRunMetrics(string $ghApi, string $repo, string $runId, string $ghToken): array
{
    $out = [
        'tests' => null, 'skipped' => null, 'php' => null, 'xdebug' => null,
        'lines_pct' => null, 'lines_covered' => null, 'lines_total' => null,
        'branches_pct' => null, 'branches_covered' => null, 'branches_total' => null,
        'duration_s' => null,
    ];
    if ($runId === '') {
        return $out;
    }
    $jobs = jsonGet("{$ghApi}/repos/{$repo}/actions/runs/" . rawurlencode($runId) . '/jobs?per_page=20', ghHeaders($ghToken), 30);
    if (!is_array($jobs) || (($jobs['jobs'] ?? []) === [])) {
        return $out;
    }
    $job = $jobs['jobs'][0];
    if (!empty($job['started_at']) && !empty($job['completed_at'])) {
        $out['duration_s'] = max(0, (int) strtotime((string) $job['completed_at']) - (int) strtotime((string) $job['started_at']));
    }
    $jobId = (string) ($job['id'] ?? '');
    if ($jobId === '') {
        return $out;
    }
    $txt = ghJobLog($ghApi, $repo, $jobId, $ghToken);
    if ($txt === '') {
        return $out;
    }
    $n = extractNumbers($txt);
    $out['tests'] = $n['tests'];
    $out['skipped'] = $n['skipped'];
    $out['php'] = $n['php'];
    $out['xdebug'] = $n['xdebug'];
    $g = parseGateText($txt);
    foreach (['lines_pct', 'lines_covered', 'lines_total', 'branches_pct', 'branches_covered', 'branches_total'] as $k) {
        $out[$k] = $g[$k];
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
        'gl_state' => '', 'gl_desc' => '',
        'lines_pct' => '', 'lines_covered' => '', 'lines_total' => '',
        'branches_pct' => '', 'branches_covered' => '', 'branches_total' => '',
        'tests' => '', 'skipped' => '', 'php' => '', 'xdebug' => '',
        'instr_gate' => 'n/a', 'instr_phpunit' => 'n/a',
        'decision_parity' => 'n/a', 'result' => 'n/a',
    ];

    // --- Sisi GitLab: external status check
    $statuses = jsonGet("{$glApi}/projects/{$glq}/repository/commits/{$glSha}/statuses?per_page=100", glHeaders($glToken), 30) ?? [];
    $glState = '';
    foreach ($statuses as $s) {
        if (($s['name'] ?? '') === 'github-actions/coverage') {
            $glState = (string) ($s['status'] ?? '');
            $row['gl_desc'] = (string) ($s['description'] ?? '');
            $row['gh_url'] = (string) ($s['target_url'] ?? '');
            break;
        }
    }
    $row['gl_state'] = $glState;

    // --- Sisi GitHub: run terakhir utk mirror sha
    $conclusion = '';
    if ($mirrorSha !== '') {
        $runs = jsonGet("{$ghApi}/repos/{$repo}/actions/runs?head_sha={$mirrorSha}&per_page=10", ghHeaders($ghToken), 30) ?? [];
        foreach (($runs['workflow_runs'] ?? []) as $r) {
            if (($r['status'] ?? '') === 'completed') {
                $conclusion = (string) ($r['conclusion'] ?? '');
                $row['gh_run_id'] = (string) ($r['id'] ?? '');
                if ($row['gh_url'] === '') {
                    $row['gh_url'] = (string) ($r['html_url'] ?? '');
                }
                break;
            }
        }
    }
    $row['gh_conclusion'] = $conclusion;

    // Angka coverage: dari deskripsi status GitLab (memuat output gate GitHub verbatim).
    if ($row['gl_desc'] !== '') {
        $g = parseGateText($row['gl_desc']);
        $row['lines_pct'] = $g['lines_pct'] ?? '';
        $row['lines_covered'] = $g['lines_covered'] ?? '';
        $row['lines_total'] = $g['lines_total'] ?? '';
        $row['branches_pct'] = $g['branches_pct'] ?? '';
        $row['branches_covered'] = $g['branches_covered'] ?? '';
        $row['branches_total'] = $g['branches_total'] ?? '';
    }

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
    $ghm = [
        'tests' => null, 'skipped' => null, 'php' => null, 'xdebug' => null,
        'lines_pct' => null, 'lines_covered' => null, 'lines_total' => null,
        'branches_pct' => null, 'branches_covered' => null, 'branches_total' => null,
        'duration_s' => null,
    ];
    if ($row['gh_run_id'] !== '') {
        $ghm = ghRunMetrics($ghApi, $repo, $row['gh_run_id'], $ghToken);
    }
    $row['tests']   = $glm['tests'] ?? '';
    $row['skipped'] = $glm['skipped'] ?? '';
    $row['php']     = $glm['php'] ?? '';
    $row['xdebug']  = $glm['xdebug'] ?? '';

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
    if ($glm['skipped'] !== null && $ghm['skipped'] !== null) {
        if ((int) $glm['skipped'] !== (int) $ghm['skipped']) {
            $violations[] = "P2 {$short}: skipped GitLab={$glm['skipped']} <> GitHub={$ghm['skipped']}";
        }
    } else {
        $numNA[] = 'P2';
    }

    // P9 — versi runtime PHP/Xdebug identik (Xdebug dibandingkan hanya bila kedua sisi menyediakannya).
    if ($glm['php'] !== null && $ghm['php'] !== null) {
        if ((string) $glm['php'] !== (string) $ghm['php']) {
            $violations[] = "P9 {$short}: PHP GitLab={$glm['php']} <> GitHub={$ghm['php']}";
        }
        if ($glm['xdebug'] !== null && $ghm['xdebug'] !== null && (string) $glm['xdebug'] !== (string) $ghm['xdebug']) {
            $violations[] = "P9 {$short}: Xdebug GitLab={$glm['xdebug']} <> GitHub={$ghm['xdebug']}";
        }
    } else {
        $numNA[] = 'P9';
    }

    // P3/P4/P5/P6/P7 — coverage line & branch (persen, pembilang, penyebut) GitLab <-> GitHub.
    $glCoverage = glCoverageMetrics($glApi, $glq, $branch, $glToken, $glSha);
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
    $row['result'] = 'PASS';
    if ($row['decision_parity'] === 'MISMATCH' || $row['instr_gate'] === 'MISMATCH') {
        $row['result'] = 'VIOLATION';
    } elseif ($conclusion === '' || $glState === '') {
        // P8 tidak dapat dinilai. BEDAKAN dua sebab (fail-closed tetap berlaku):
        //   (a) pipeline commit ini TIDAK PERNAH mencapai tahap mirror-push
        //       (coverage-offload != success) ⇒ paritas VAKUM — bukan lulus,
        //       dilaporkan eksplisit sebagai N/A dan tidak menghitung error;
        //   (b) mirror sudah ter-push tetapi run/status GitHub tidak ada ⇒
        //       PELANGGARAN INVARIANT ⇒ KESALAHAN OPERASIONAL (exit 2).
        $offload = glOffloadStatus($glApi, $glq, $branch, $glToken, $glSha);
        if ($offload !== 'success') {
            $row['result'] = 'N/A(no-dual-run:' . $offload . ')';
            $vacuous++;
        } else {
            $row['result'] = 'INCOMPLETE';
            $operationalErrors++;
        }
    } elseif ($numNA !== []) {
        // Numerik belum lengkap pada tahap ini ⇒ ditandai eksplisit (bukan PASS diam-diam).
        $row['result'] = 'PASS(n/a:' . implode(',', array_unique($numNA)) . ')';
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

// ---------------------------------------------------------------------------
// Selftest — membuktikan parser & komparator bekerja (tanpa jaringan)
// ---------------------------------------------------------------------------
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
    $lg = parseGateText($cl);
    $ok('parse dari log GH: coverage', $lg['lines_pct'] === 86.99 && $lg['lines_total'] === 4813 && $lg['branches_total'] === 4929);

    // Komparator keras: penyebut line berbeda -> pelanggaran.
    $denyDiff = ($g2['lines_total'] !== $g['lines_total']);
    $ok('deteksi penyebut line berbeda', $denyDiff === false);
    $branchDiff = ($g2['branches_total'] !== $g['branches_total']);
    $ok('deteksi penyebut branch berbeda', $branchDiff === true);

    echo $fail === 0 ? "== selftest: LULUS ==\n" : "== selftest: {$fail} GAGAL ==\n";
    return $fail === 0 ? EXIT_PASS : EXIT_VIOLATION;
}
