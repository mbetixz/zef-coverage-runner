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
 *   --sample-days=N     batasi commit ke N hari terakhir (default 14; 0 = tanpa batas)
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
    'line_tol'    => 0.0,
    'branch_tol'  => 0.0,
    'tests_tol'   => 0,
    'sample_days' => 14,
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

$POLICY['line_tol']    = (float) $opt('line-tol', $POLICY['line_tol']);
$POLICY['branch_tol']  = (float) $opt('branch-tol', $POLICY['branch_tol']);
$POLICY['tests_tol']   = (int) $opt('tests-tol', $POLICY['tests_tol']);
$POLICY['sample_days'] = (int) $opt('sample-days', $POLICY['sample_days']);

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
    $opts = [
        'http' => [
            'method'        => $postBody === null ? 'GET' : 'POST',
            'header'        => implode("\r\n", $headers),
            'timeout'       => $timeout,
            'ignore_errors' => true,
            'follow_location' => 1,
            'max_redirects' => 5,
        ],
    ];
    if ($postBody !== null) {
        $opts['http']['content'] = $postBody;
    }
    $ctx = stream_context_create($opts);
    $body = @file_get_contents($url, false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $code = (int) $m[1];
        }
    }
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

$rows = [];
$violations = [];
$operationalErrors = 0;
$checked = 0;

foreach ($commits as $c) {
    $glSha = (string) ($c['id'] ?? '');
    if ($glSha === '') {
        continue;
    }
    $short = substr($glSha, 0, 8);
    $createdAt = (string) ($c['created_at'] ?? '');
    $ts = $createdAt !== '' ? (int) strtotime($createdAt) : 0;
    if ($cutoff > 0 && $ts > 0 && $ts < $cutoff) {
        continue; // di luar jendela sampel
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

    // --- Hasil baris
    $row['result'] = 'PASS';
    if ($row['decision_parity'] === 'MISMATCH' || $row['instr_gate'] === 'MISMATCH') {
        $row['result'] = 'VIOLATION';
    } elseif ($conclusion === '' || $glState === '') {
        $row['result'] = 'INCOMPLETE';
    }

    $rows[] = $row;
}

// ---------------------------------------------------------------------------
// Keluaran
// ---------------------------------------------------------------------------
$verdict = $violations === [] ? 'PASS' : 'VIOLATION';
if ($rows === []) {
    $verdict = 'ERROR';
    $operationalErrors++;
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
    echo "\n";
}

$summary = [
    'generated_at'  => gmdate('c'),
    'branch'        => $branch,
    'commits_checked' => $checked,
    'rows'          => count($rows),
    'violations'    => count($violations),
    'operational_errors' => $operationalErrors,
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

    // Komparator keras: penyebut line berbeda -> pelanggaran.
    $denyDiff = ($g2['lines_total'] !== $g['lines_total']);
    $ok('deteksi penyebut line berbeda', $denyDiff === false);
    $branchDiff = ($g2['branches_total'] !== $g['branches_total']);
    $ok('deteksi penyebut branch berbeda', $branchDiff === true);

    echo $fail === 0 ? "== selftest: LULUS ==\n" : "== selftest: {$fail} GAGAL ==\n";
    return $fail === 0 ? EXIT_PASS : EXIT_VIOLATION;
}
