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
use Zef\Framework\Event\EventDispatcher;

final class H1CreateOrder {}
final class H1CreateInvoice {}
final class H1GetOrder { public function __construct(public readonly int $id) {} }
final class H1OrderCreated { public function __construct(public readonly int $id) {} }
interface H1MarkerA {}
interface H1MarkerB {}
final class H1Marked implements H1MarkerA, H1MarkerB {}

final class CqrsTest extends TestCase
{
    public function testLegacyBehavior(): void
    {
        $assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
        $store = new InMemoryIdempotencyStore(8);
        $events = new EventDispatcher();
        $seen = [];
        $events->listen(H1OrderCreated::class, static function (H1OrderCreated $event, \Zef\Framework\Event\EventContext $ctx) use (&$seen): void { $seen[] = $event->id.':'.$ctx->correlationId; });
        $events->freeze();

        $commands = new CommandBus($store, 3600, $events);
        $executions = 0;
        $commands->register(H1CreateOrder::class, static function (H1CreateOrder $command, CqrsContext $context) use (&$executions): CqrsEventResult {
            ++$executions;
            return new CqrsEventResult(['created'=>true,'correlation'=>$context->correlationId], [new H1OrderCreated(7)]);
        });
        $commands->use(new class implements CqrsMiddlewareInterface {
            #[\Override] public function process(object $message, CqrsContext $context, Closure $next): mixed { return $next($message, $context); }
        });
        $commands->freeze();
        $context = new CqrsContext('corr-h1-001', null, 'idem-h1-001');
        $first = $commands->dispatch(new H1CreateOrder(), $context);
        $second = $commands->dispatch(new H1CreateOrder(), $context);
        $assert($executions === 1, 'idempotency must execute command once');
        $assert(is_array($first) && $first['created'] === true, 'command result expected');
        $assert($first === $second, 'idempotent result must be stable');
        $commands2 = new CommandBus($store, 3600, $events);
        $commands2->register(H1CreateInvoice::class, static fn(): array => ['invoice'=>true]);
        $commands2->freeze();
        $assert($commands2->dispatch(new H1CreateInvoice(), new CqrsContext('corr-h1-002', null, 'idem-h1-001')) === ['invoice'=>true], 'idempotency key must be command-scoped');
        $assert($seen === ['7:corr-h1-001'], 'command event must preserve correlation context');

        $queries = new QueryBus();
        $queries->register(H1GetOrder::class, static fn(H1GetOrder $query, CqrsContext $context): array => ['id'=>$query->id,'correlation'=>$context->correlationId]);
        $queries->freeze();
        $queryResult = $queries->ask(new H1GetOrder(9), new CqrsContext('corr-h1-q01'));
        $assert($queryResult === ['id'=>9,'correlation'=>'corr-h1-q01'], 'query result mismatch');

        $notFound = false;
        try { $queries->ask(new stdClass(), new CqrsContext('corr-h1-nf')); } catch (CqrsHandlerNotFoundException) { $notFound = true; }
        $assert($notFound, 'missing query handler must fail closed');

        $conflict = new CommandBus();
        $conflict->register(H1MarkerA::class, static fn(): string => 'a');
        $conflict->register(H1MarkerB::class, static fn(): string => 'b');
        $conflict->freeze();
        $ambiguous = false;
        try { $conflict->dispatch(new H1Marked()); } catch (CqrsHandlerConflictException) { $ambiguous = true; }
        $assert($ambiguous, 'ambiguous command handlers must fail closed');

        $late = false;
        try { $commands->register(H1CreateOrder::class, static fn(): mixed => null); } catch (LogicException) { $late = true; }
        $assert($late, 'command registration after freeze must fail');

        $invalid = false;
        try { new CqrsContext('bad', null); } catch (InvalidArgumentException) { $invalid = true; }
        $assert($invalid, 'invalid CQRS context must fail');
        $this->addToAssertionCount(1);
    }
}
