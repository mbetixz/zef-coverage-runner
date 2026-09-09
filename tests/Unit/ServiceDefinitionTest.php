<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Container\Container;
use Zef\Framework\Container\ServiceDefinition;
use Zef\Framework\Container\ServiceLifetime;

final class ServiceDefinitionTest extends TestCase
{
    public function testLegacyBehavior(): void
    {
        $pass = 0; $fail = 0;
        $check = static function (bool $condition, string $message) use (&$pass,&$fail): void {
            if ($condition) { ++$pass; echo "PASS: $message\n"; }
            else { ++$fail; echo "FAIL: $message\n"; }
        };

        $def = new ServiceDefinition('demo.answer', static fn() => 42);
        $check($def->id === 'demo.answer', 'typed definition preserves id');
        $check($def->dependencies === [], 'typed definition defaults dependencies');
        $check($def->lifetime === ServiceLifetime::SINGLETON, 'typed definition defaults singleton');
        $check($def->shared === true, 'singleton definition defaults shared');

        $legacy = ServiceDefinition::fromArray('demo.legacy', [
            'factory' => static fn() => 'legacy',
            'deps' => [],
            'lifetime' => ServiceLifetime::TRANSIENT,
        ]);
        $check($legacy->lifetime === ServiceLifetime::TRANSIENT, 'legacy config normalizes lifetime');
        $check($legacy->shared === false, 'transient legacy config is not shared');
        $factory=$legacy->factory; /** @var callable $factory */ $check($factory() === 'legacy', 'legacy config preserves factory');

        $container = new Container();
        $container->registerDefinition($def);
        $container->register('demo.legacy2', static fn() => 'legacy2', [], null, ServiceLifetime::TRANSIENT);
        $container->validateAndFreeze();
        $check($container->get('demo.answer') === 42, 'typed definition resolves through container');
        $check($container->get('demo.legacy2') === 'legacy2', 'legacy register path remains compatible');
        $registry=$container->getRegistry()->definitions(); $check(isset($registry['demo.answer']) && $registry['demo.answer']->id==='demo.answer', 'registry canonical storage is ServiceDefinition');

        $invalidCases = [
            fn() => new ServiceDefinition('', static fn() => null),
            fn() => new ServiceDefinition('bad.dep', static fn() => null, [123]),
            fn() => new ServiceDefinition('bad.life', static fn() => null, [], null, 'bogus'),
            fn() => new ServiceDefinition('bad.shared', static fn() => null, [], null, ServiceLifetime::TRANSIENT, true),
            fn() => ServiceDefinition::fromArray('bad.tags', ['factory' => static fn() => null, 'tags' => 'bad']),
        ];
        foreach ($invalidCases as $invalid) {
            try { $invalid(); $check(false, 'invalid definition rejected'); }
            catch (Throwable) { $check(true, 'invalid definition rejected'); }
        }
        $this->assertSame(0, $fail, 'legacy service definition checks failed');
    }
}
