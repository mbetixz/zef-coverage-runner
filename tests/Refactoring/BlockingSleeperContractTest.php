<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Zef\Framework\Runtime\BlockingSleeper;

$pass = 0; $fail = 0;
$check = static function (bool $condition, string $message) use (&$pass, &$fail): void {
    if ($condition) { ++$pass; echo "PASS: {$message}\n"; return; }
    ++$fail; echo "FAIL: {$message}\n";
};
$throws = static function (string $class, callable $operation): bool {
    try { $operation(); } catch (Throwable $exception) { return $exception instanceof $class; }
    return false;
};

$check($throws(InvalidArgumentException::class, static fn() => BlockingSleeper::sleepMilliseconds(-1)), 'negative delay is rejected');
$started = hrtime(true);
BlockingSleeper::sleepMilliseconds(0);
$check((hrtime(true) - $started) < 50_000_000, 'zero delay returns immediately');
$started = hrtime(true);
BlockingSleeper::sleepMilliseconds(5);
$elapsedMs = (hrtime(true) - $started) / 1_000_000;
$check($elapsedMs >= 3, 'positive delay blocks for the requested duration');

printf("BlockingSleeper contract: %d pass, %d fail\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
