<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require $root . '/vendor/autoload.php';

use Zef\Framework\Container\Container;
use Zef\Framework\Container\ServiceLifetime;

$pass = 0;
$fail = 0;
$check = static function (bool $condition, string $message) use (&$pass, &$fail): void {
    if ($condition) {
        ++$pass;
        echo "PASS: {$message}\n";
        return;
    }
    ++$fail;
    echo "FAIL: {$message}\n";
};

$container = new Container();
$created = 0;
$container->register(
    'rf06.request-scoped-token',
    static function () use (&$created): object {
        ++$created;
        return (object) ['sequence' => $created];
    },
    [],
    'rf06',
    ServiceLifetime::REQUEST,
);
$container->validateAndFreeze();

$scopeA = $container->createRequestScope();
/** @var object{sequence:int} $tokenA1 */
$tokenA1 = $scopeA->get('rf06.request-scoped-token');
$tokenA2 = $scopeA->get('rf06.request-scoped-token');
$check($tokenA1 === $tokenA2, 'same request reuses one request-scoped instance');
$check($tokenA1->sequence === 1, 'first request scope creates sequence 1');
$check($scopeA->has('rf06.request-scoped-token'), 'active request scope reports its service');

$scopeB = $container->createRequestScope();
/** @var object{sequence:int} $tokenB */
$tokenB = $scopeB->get('rf06.request-scoped-token');
$check($tokenB !== $tokenA1, 'second request receives a different request-scoped instance');
$check($tokenB->sequence === 2, 'second request creates sequence 2');
$check($created === 2, 'two request scopes create exactly two instances');

$scopeA->close();
$check($scopeA->isClosed(), 'closed scope reports closed state');
$check(!$scopeA->has('rf06.request-scoped-token'), 'closed scope cannot report request-scoped service');
$closedGetThrows = false;
try {
    $scopeA->get('rf06.request-scoped-token');
} catch (LogicException $exception) {
    $closedGetThrows = $exception->getMessage() === 'Request scope is closed.';
}
$check($closedGetThrows, 'closed scope rejects service resolution');
$scopeA->close();
$check($scopeA->isClosed(), 'closing a scope twice remains idempotent');

$scopeB->close();
$check($scopeB->isClosed(), 'second request scope closes independently');

printf("Request-scope isolation characterization: %d pass, %d fail\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
