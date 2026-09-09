<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\CQRS\CommandBus;
use Zef\Framework\CQRS\CqrsContext;
use Zef\Framework\CQRS\CqrsEventResult;
use Zef\Framework\CQRS\CqrsHandlerConflictException;
use Zef\Framework\CQRS\CqrsHandlerNotFoundException;
use Zef\Framework\CQRS\CqrsMiddlewareInterface;
use Zef\Framework\CQRS\InMemoryIdempotencyStore;
use Zef\Framework\CQRS\QueryBus;
use Zef\Framework\CQRS\QueryHandlerInterface;
use Zef\Framework\CQRS\CommandHandlerInterface;

final class B6FindUser
{
    public function __construct(public readonly int $userId)
    {
    }
}

final class B6UserFound
{
    public function __construct(public readonly int $userId, public readonly string $name)
    {
    }
}

final class B6CreateUser
{
    public function __construct(public readonly string $email)
    {
    }
}

interface B6RoleQuery
{
}

final class B6AdminQuery implements B6RoleQuery
{
}

final class B6UserQuery implements B6RoleQuery
{
}

/**
 * Batch 6 coverage: CQRS\QueryBus + CommandBus remaining paths.
 *
 * Exercises handler-object registration, per-interface resolution, middleware
 * ordering/short-circuit, event emission via CqrsEventResult, strict
 * CqrsContext validation and CqrsEventResult construction. Deterministic:
 * fixed correlation ids, no wall-clock dependence in assertions.
 */
final class Batch6QueryBusCqrsTest extends TestCase
{
    public function testQueryBusRegistersInvokableHandlerObject(): void
    {
        $bus = new QueryBus();
        $handler = new class implements QueryHandlerInterface {
            /** @param B6FindUser $query */
            #[Override]
            public function __invoke(object $query, CqrsContext $context): mixed
            {
                return ['id' => $query->userId, 'ctx' => $context->correlationId];
            }
        };
        $bus->register(B6FindUser::class, $handler);
        $bus->freeze();
        $result = $bus->ask(new B6FindUser(7), new CqrsContext('corr-b6-query-001'));
        $this->assertSame(['id' => 7, 'ctx' => 'corr-b6-query-001'], $result);
    }

    public function testQueryBusCreatesDefaultContextWhenOmitted(): void
    {
        $bus = new QueryBus();
        $bus->register(B6FindUser::class, static fn (B6FindUser $query): int => $query->userId * 2);
        $result = $bus->ask(new B6FindUser(21));
        $this->assertSame(42, $result, 'context defaults to CqrsContext::create()');
    }

    public function testQueryBusResolvesViaInterfaceAndPrefersExactClass(): void
    {
        $bus = new QueryBus();
        $bus->register(B6RoleQuery::class, static fn (): string => 'via-interface');
        $bus->register(B6AdminQuery::class, static fn (): string => 'via-exact');
        $this->assertSame('via-interface', $bus->ask(new B6UserQuery()), 'falls back to implemented interface');
        $this->assertSame('via-exact', $bus->ask(new B6AdminQuery()), 'exact class registration wins');
    }

    public function testQueryBusDuplicateRegistrationThrows(): void
    {
        $bus = new QueryBus();
        $bus->register(B6FindUser::class, static fn (): mixed => null);
        $this->expectException(CqrsHandlerConflictException::class);
        $bus->register(B6FindUser::class, static fn (): mixed => null);
    }

    public function testQueryBusMiddlewareRunsInRegistrationOrderAroundHandler(): void
    {
        /** @var ArrayObject<int, string> $log */
        $log = new ArrayObject();
        $recorder = static function (string $tag, ArrayObject $log): CqrsMiddlewareInterface {
            return new class ($tag, $log) implements CqrsMiddlewareInterface {
                /** @param ArrayObject<int, string> $log */
                public function __construct(private string $tag, private ArrayObject $log)
                {
                }

                #[Override]
                public function process(object $message, CqrsContext $context, Closure $next): mixed
                {
                    $this->log[] = $this->tag . ':before';
                    $result = $next($message, $context);
                    $this->log[] = $this->tag . ':after';
                    return $result;
                }
            };
        };
        $bus = new QueryBus();
        $bus->use($recorder('m1', $log));
        $bus->use($recorder('m2', $log));
        $bus->register(B6FindUser::class, static function () use ($log): string {
            $log[] = 'handler';
            return 'ok';
        });
        $this->assertSame('ok', $bus->ask(new B6FindUser(1)));
        $this->assertSame(['m1:before', 'm2:before', 'handler', 'm2:after', 'm1:after'], $log->getArrayCopy());
    }

    public function testQueryBusMiddlewareMayShortCircuit(): void
    {
        $shortCircuit = new class implements CqrsMiddlewareInterface {
            #[Override]
            public function process(object $message, CqrsContext $context, Closure $next): mixed
            {
                return 'short-circuited';
            }
        };
        $handlerCalled = false;
        $bus = new QueryBus();
        $bus->use($shortCircuit);
        $bus->register(B6FindUser::class, static function () use (&$handlerCalled): string {
            $handlerCalled = true;
            return 'handler';
        });
        $this->assertSame('short-circuited', $bus->ask(new B6FindUser(1)));
        $this->assertFalse($handlerCalled);
    }

    public function testQueryBusNoHandlerThrows(): void
    {
        $bus = new QueryBus();
        $this->expectException(CqrsHandlerNotFoundException::class);
        $bus->ask(new B6FindUser(1));
    }

    public function testCommandBusWithHandlerObjectAndIdempotencyDedupe(): void
    {
        $executions = 0;
        $handler = new class ($executions) implements CommandHandlerInterface {
            public function __construct(private int &$executions)
            {
            }

            /** @param B6CreateUser $command */
            #[Override]
            public function __invoke(object $command, CqrsContext $context): mixed
            {
                ++$this->executions;
                return ['email' => $command->email, 'key' => $context->idempotencyKey];
            }
        };
        $store = new InMemoryIdempotencyStore();
        $bus = new CommandBus($store, 3600);
        $bus->register(B6CreateUser::class, $handler);
        $ctx = new CqrsContext('corr-b6-cmd-001', null, 'idem-b6-001');
        $first = $bus->dispatch(new B6CreateUser('a@b.c'), $ctx);
        $second = $bus->dispatch(new B6CreateUser('a@b.c'), $ctx);
        $this->assertSame($first, $second);
        $this->assertSame(1, $executions, 'idempotency key dedupes the second dispatch');
        $this->assertIsArray($first);
        $this->assertSame('idem-b6-001', $first['key']);
    }

    public function testCommandBusUseAfterFreezeThrows(): void
    {
        $bus = new CommandBus();
        $bus->freeze();
        $this->expectException(LogicException::class);
        $bus->use(new class implements CqrsMiddlewareInterface {
            #[Override]
            public function process(object $message, CqrsContext $context, Closure $next): mixed
            {
                return $next($message, $context);
            }
        });
    }

    public function testCqrsEventResultHoldsResultAndEvents(): void
    {
        $event = new B6UserFound(1, 'ada');
        $outcome = new CqrsEventResult(['created' => true], [$event]);
        $this->assertSame(['created' => true], $outcome->result);
        $this->assertSame([$event], $outcome->events);

        $empty = new CqrsEventResult(null);
        $this->assertNull($empty->result);
        $this->assertSame([], $empty->events);
    }

    public function testCqrsContextRejectsInvalidTraceParentVariants(): void
    {
        foreach ([
            '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7',      // missing flags
            '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-zz',  // non-hex flags
        ] as $bad) {
            try {
                new CqrsContext('corr-b6-ctx-001', $bad);
                $this->fail("traceparent must throw");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        // A valid traceparent (even with an all-zero trace id, which only the
        // observability layer rejects) is accepted by the CQRS context regex.
        $ok = new CqrsContext('corr-b6-ctx-002', '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01-vendor=value');
        $this->assertSame('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01-vendor=value', $ok->traceParent);
        $zero = new CqrsContext('corr-b6-ctx-003', '00-' . str_repeat('0', 32) . '-00f067aa0ba902b7-01');
        $this->assertNotNull($zero->traceParent);
    }
}
