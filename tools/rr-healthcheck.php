<?php
declare(strict_types=1);

$url = getenv('ZEF_HEALTH_URL') ?: 'http://127.0.0.1:18080/health/live';
$expected = getenv('ZEF_HEALTH_EXPECTED_STATUS') ?: '200';
$timeout = getenv('ZEF_HEALTH_TIMEOUT_SECONDS');
$timeoutSeconds = ($timeout !== false && ctype_digit($timeout)) ? (int)$timeout : 2;
if ($timeoutSeconds < 1 || $timeoutSeconds > 30) {
    fwrite(STDERR, "ZEF_HEALTH_TIMEOUT_SECONDS must be between 1 and 30.\n");
    exit(78);
}
$parts = parse_url($url);
if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || !in_array($parts['scheme'], ['http','https'], true)) {
    fwrite(STDERR, "Invalid health URL.\n");
    exit(78);
}
$context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => $timeoutSeconds, 'ignore_errors' => true]]);
$body = @file_get_contents($url, false, $context);
$status = null;
foreach ($http_response_header ?? [] as $header) {
    if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $header, $m)) { $status = $m[1]; break; }
}
if ($status === $expected) {
    echo "Health check PASS: {$status}\n";
    exit(0);
}
fwrite(STDERR, "Health check FAIL: expected {$expected}, got ".($status ?? 'no-response')."\n");
if ($body !== false && $body !== '') fwrite(STDERR, trim($body)."\n");
exit(1);
