<?php
declare(strict_types=1);
require __DIR__.'/_assert.php';
require dirname(__DIR__,2).'/vendor/autoload.php';

use Zef\Framework\CQRS\CommandBusInterface;
use Zef\Framework\CQRS\CommandHandlerInterface;
use Zef\Framework\CQRS\QueryBusInterface;
use Zef\Framework\CQRS\QueryHandlerInterface;
use Zef\Framework\CQRS\InMemoryIdempotencyStore;

final class H1GovernanceCommand {}
final class H1GovernanceQuery {}
final class H1GovernanceCommandHandler implements CommandHandlerInterface { #[\Override] public function __invoke(object $command, \Zef\Framework\CQRS\CqrsContext $context): mixed { return true; } }
final class H1GovernanceQueryHandler implements QueryHandlerInterface { #[\Override] public function __invoke(object $query, \Zef\Framework\CQRS\CqrsContext $context): mixed { return true; } }

architecture_check(interface_exists(\Zef\Framework\CQRS\IdempotencyStoreInterface::class), 'idempotency contract exists');
architecture_check(interface_exists(CommandHandlerInterface::class), 'command handler contract exists');
architecture_check(interface_exists(QueryHandlerInterface::class), 'query handler contract exists');
architecture_check(interface_exists(CommandBusInterface::class), 'command bus contract exists');
architecture_check(interface_exists(QueryBusInterface::class), 'query bus contract exists');
architecture_check(interface_exists(\Zef\Framework\CQRS\CqrsMiddlewareInterface::class), 'middleware contract exists');
architecture_check(class_exists(\Zef\Framework\CQRS\CqrsEventResult::class), 'explicit command event result contract');
echo "CqrsGovernanceTest: PASS\n";
