<?php

declare(strict_types=1);

namespace Zef\Framework\Runtime {
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;

    final class InMemoryWorker implements WorkerInterface
    {
        /** @var list<ResponseInterface> */ private array $responses = [];
        private bool $running = true;
        /** @param list<ServerRequestInterface> $requests */
        public function __construct(private array $requests)
        {
        }
        #[\Override]
        public function waitRequest(): ?ServerRequestInterface
        {
            return array_shift($this->requests) ?? null;
        }
        #[\Override]
        public function respond(ResponseInterface $response): void
        {
            $this->responses[] = $response;
        }
        #[\Override]
        public function error(string $message): void
        {
        }
        #[\Override]
        public function stop(): void
        {
            $this->running = false;
        }
        #[\Override]
        public function isRunning(): bool
        {
            return $this->running;
        }
        /** @return list<ResponseInterface> Responses emitted by the worker. */ public function responses(): array
        {
            return $this->responses;
        }
    }
}
