<?php
declare(strict_types=1);

require_once __DIR__.'/../../vendor/autoload.php';

use Zef\Framework\Container\Container;
use Zef\Framework\Container\ServiceDefinition;
use Zef\Framework\Container\ServiceLifetime;

final class CompilerProbe {}
final class CompilerDependency { public function __construct(public readonly CompilerProbe $probe) {} }

$c = new Container();
$probe = new CompilerProbe();
$c->registerDefinition(new ServiceDefinition(
    'probe',
    static fn() => $probe,
    [],
    'compiler-test',
    ServiceLifetime::SINGLETON,
));
$c->registerDefinition(new ServiceDefinition(
    'dependency',
    static fn(\Zef\Framework\Container\ResolutionContext $ctx, CompilerProbe $probe): CompilerDependency => new CompilerDependency($probe),
    ['probe'],
    'compiler-test',
    ServiceLifetime::TRANSIENT,
    false,
));
$c->alias('probe.alias', 'probe', 'compiler-test');
$c->validateAndFreeze();

if ($c->get('probe.alias') !== $c->get('probe')) {
    throw new RuntimeException('compiled alias resolution failed');
}

$one = $c->get('dependency');
$two = $c->get('dependency');
if (!$one instanceof CompilerDependency || !$two instanceof CompilerDependency || $one === $two) {
    throw new RuntimeException('compiled transient resolution failed');
}
if ($one->probe !== $probe || $two->probe !== $probe) {
    throw new RuntimeException('compiled dependency resolution failed');
}

$scope = $c->createRequestScope();
$scoped = new Container();
$scoped->register('probe', static fn() => new CompilerProbe(), [], 'compiler-test', ServiceLifetime::REQUEST);
$scoped->validateAndFreeze();
$scope2 = $scoped->createRequestScope();
if ($scope2->get('probe') !== $scope2->get('probe')) {
    throw new RuntimeException('compiled request scope caching failed');
}
$scope->close();
$scope2->close();

echo "ContainerCompilerTest: PASS\n";
