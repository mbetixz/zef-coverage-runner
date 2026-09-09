<?php

declare(strict_types=1);

require_once __DIR__.'/../../vendor/autoload.php';

use Zef\Framework\Exception\MethodNotAllowedException;
use Zef\Framework\Exception\RouteConstraintException;
use Zef\Framework\Exception\RouteNotFoundException;
use Zef\Framework\Router\Router;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: '.$message);
    }
    echo 'PASS: '.$message.PHP_EOL;
};

$router = new Router();
$router->add('GET', '/about', 'about.handler', priority: 10);
$router->add('GET', '/users/{id:int}', 'user.handler');
$router->add('POST', '/users/{id:int}', 'user.create');
$router->addConstraint('slug', '/^[a-z]+$/');
$router->add('GET', '/posts/{slug:slug}', 'post.handler');

$about = $router->match('GET', '/about');
$assert($about['handler'] === 'about.handler', 'static route selects handler');
$assert($router->match('HEAD', '/about')['handler'] === 'about.handler', 'HEAD falls back to GET');
$userMatch = $router->match('GET', '/users/42');
$assert(($userMatch['params']['id'] ?? null) === '42', 'integer route captures parameter');
$postMatch = $router->match('GET', '/posts/hello');
$assert(($postMatch['params']['slug'] ?? null) === 'hello', 'custom constraint captures valid slug');

try {
    $router->match('GET', '/users/nope');
    throw new RuntimeException('expected RouteConstraintException');
} catch (RouteConstraintException) {
    $assert(true, 'invalid constraint produces RouteConstraintException');
}

try {
    $router->match('PUT', '/users/42');
    throw new RuntimeException('expected MethodNotAllowedException');
} catch (MethodNotAllowedException) {
    $assert(true, 'method mismatch produces MethodNotAllowedException');
}

try {
    $router->match('GET', '/missing');
    throw new RuntimeException('expected RouteNotFoundException');
} catch (RouteNotFoundException) {
    $assert(true, 'missing route produces RouteNotFoundException');
}

$router->freeze();
$assert(count($router->getRoutes()) === 4, 'freeze preserves registered route count');
try {
    $router->add('GET', '/late', 'late.handler');
    throw new RuntimeException('expected frozen router LogicException');
} catch (LogicException) {
    $assert(true, 'frozen router rejects mutation');
}

try {
    $router->addConstraint('late', '[a-z]+');
    throw new RuntimeException('expected frozen router LogicException');
} catch (LogicException) {
    $assert(true, 'frozen router rejects constraint mutation');
}

echo "Router characterization: PASS\n";
