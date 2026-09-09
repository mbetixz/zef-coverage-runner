<?php
declare(strict_types=1);
require __DIR__.'/_assert.php';

$source = (string) file_get_contents(dirname(__DIR__, 2).'/src/Framework/Delivery/Delivery.php');
architecture_check(str_contains($source, 'enum DeliveryMode'), 'W3 delivery mode exists');
architecture_check(str_contains($source, 'enum ExecutionCertainty'), 'W3 execution certainty exists');
architecture_check(str_contains($source, 'IdempotencyGuaranteeInterface'), 'W3 explicit idempotency guarantee exists');
architecture_check(str_contains($source, 'IdempotencyStoreInterface'), 'W3 duplicate detection store exists');
architecture_check(str_contains($source, 'maxAttempts'), 'W3 attempt bound exists');
architecture_check(str_contains($source, 'maxCumulativeDelayMs'), 'W3 cumulative retry budget exists');
architecture_check(str_contains($source, 'deadlineMs'), 'W3 deadline bound exists');
architecture_check(str_contains($source, 'DEFINITIVELY_EXECUTED'), 'W3 definitive execution guard exists');
architecture_check(str_contains($source, 'IDEMPOTENCY_GUARANTEE_REQUIRED'), 'W3 fail-closed idempotency guard exists');
architecture_check(str_contains($source, 'SECURITY_DENIED'), 'W3 security gate exists');
architecture_check(str_contains($source, 'RESOURCE_DENIED'), 'W3 resource gate exists');
architecture_check(!str_contains($source, '$GLOBALS'), 'W3 has no global mutable state');
architecture_check(!str_contains($source, 'Promise'), 'W3 does not introduce async Promise contract');
architecture_check(!str_contains($source, 'Future'), 'W3 does not introduce async Future contract');
architecture_check(!str_contains($source, 'Swoole'), 'W3 remains vendor-neutral: Swoole');
architecture_check(!str_contains($source, 'React\\Promise'), 'W3 remains vendor-neutral: React');
architecture_check(!str_contains($source, 'Amp\\'), 'W3 remains vendor-neutral: Amp');
architecture_check(!str_contains($source, 'sleep('), 'W3 core does not sleep or schedule retry');
architecture_check(!str_contains($source, 'usleep('), 'W3 core does not sleep or schedule retry');
echo "G4.8 W3 architecture governance PASS".PHP_EOL;
