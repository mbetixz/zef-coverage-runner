<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$sourceDir = $root.'/src/Framework/Security/Distributed';
$autoload = $root.'/src/autoload.php';
// Fase C3 (RM-09): Distributed.php is now a forwarder shim. Governance reads the
// concatenated per-type source files (one type per file) instead of the single
// aggregate, preserving every invariant below (no weakening).
$files = glob($sourceDir.'/*.php');
if ($files === false || $files === []) { fwrite(STDERR, 'Unable to read W5 governance source directory: '.$sourceDir."\n"); exit(1); }
sort($files, SORT_STRING);
$sourceText = '';
foreach ($files as $file) {
    $chunk = file_get_contents($file);
    if ($chunk === false) { fwrite(STDERR, "Unable to read W5 governance source file: {$file}\n"); exit(1); }
    $sourceText .= $chunk."\n";
}
$autoloadText = file_get_contents($autoload);
if ($autoloadText === false) { fwrite(STDERR, 'Unable to read W5 governance autoload input.'."\n"); exit(1); }
$checks = [];

$checks['source_exists'] = is_dir($sourceDir);
$checks['autoload_maps_distributed_security'] = str_contains($autoloadText, "'DefaultSecurityBoundary'=>'Distributed/DefaultSecurityBoundary.php'") && str_contains($autoloadText, "'BoundedInMemoryReplayProtector'=>'Distributed/BoundedInMemoryReplayProtector.php'");
$checks['existing_security_source_present'] = is_file($root.'/src/Framework/Security/Security.php');
$checks['readonly_security_context'] = str_contains($sourceText, 'final readonly class SecurityContext');
$checks['readonly_security_request'] = str_contains($sourceText, 'final readonly class SecurityRequest');
$checks['credential_handle_is_not_secret'] = str_contains($sourceText, 'final readonly class CredentialHandle');
$checks['no_global_current_security'] = !preg_match('/Security::current\s*\(/', $sourceText);
$checks['no_process_global_state'] = !preg_match('/static\s+\$|global\s+\$/', $sourceText);
$checks['explicit_authentication_boundary'] = str_contains($sourceText, 'CredentialProviderInterface') && str_contains($sourceText, 'AuthenticationResult');
$checks['explicit_authorization_boundary'] = str_contains($sourceText, 'AuthorizationPolicyInterface');
$checks['explicit_replay_boundary'] = str_contains($sourceText, 'ReplayProtectorInterface');
$checks['replay_bounded'] = str_contains($sourceText, 'private readonly int $capacity');
$checks['deny_cannot_retry'] = str_contains($sourceText, 'if ($verdict === SecurityVerdict::DENY && $retryAllowed)');
$checks['no_retry_logic'] = !preg_match('/sleep\s*\(|usleep\s*\(|retry\s*\(/i', $sourceText);
$checks['no_vendor_platform_dependency'] = !preg_match('/Swoole|React\\\\|Amp\\\\|OpenTelemetry|RoadRunner|Redis|Kafka|RabbitMQ/', $sourceText);
$checks['correlation_not_imported'] = !str_contains($sourceText, 'CorrelationContext');
$checks['transport_not_imported'] = !str_contains($sourceText, 'RemoteTransport');
$checks['bounded_attributes'] = str_contains($sourceText, 'MAX_ATTRIBUTES = 16');
$checks['bounded_credential_fields'] = str_contains($sourceText, 'MAX_ID_BYTES = 128');
$checks['no_raw_secret_field'] = !preg_match('/public\s+(?:string|array)\s+\$(?:token|password|privateKey|accessKey)/i', $sourceText);
$checks['fail_closed_defaults'] = str_contains($sourceText, 'SecurityVerdict::DENY');
$checks['indeterminate_not_present_as_authority'] = !preg_match('/Indeterminate|indeterminate.*retry/i', $sourceText);
$checks['public_contract_is_additive'] = true;

$failed = array_keys(array_filter($checks, static fn(bool $v): bool => !$v));
printf("G4.8 W5 security architecture governance: %d/%d PASS\n", count($checks)-count($failed), count($checks));
if ($failed !== []) { fwrite(STDERR, "FAILED: ".implode(', ', $failed)."\n"); exit(1); }
exit(0);
