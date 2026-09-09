<?php

declare(strict_types=1);

namespace Zef\Framework\Runtime {
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;

    final class RoadRunnerWorkerAdapter implements WorkerInterface
    {
        public function __construct(private readonly object $worker)
        {
        }

        #[\Override]
        public function waitRequest(): ?ServerRequestInterface
        {
            if (!method_exists($this->worker, 'waitRequest')) {
                throw new \LogicException('RoadRunner HTTP worker must expose waitRequest().');
            }
            $request = $this->worker->waitRequest();
            return $request instanceof ServerRequestInterface ? $request : null;
        }
        #[\Override]
        public function respond(ResponseInterface $response): void
        {
            if (!method_exists($this->worker, 'respond')) {
                throw new \LogicException('RoadRunner HTTP worker must expose respond().');
            }
            $this->worker->respond($response);
        }
        #[\Override]
        public function error(string $message): void
        {
            if (method_exists($this->worker, 'error')) {
                $this->worker->error($message);
                return;
            }
            @error_log($message);
        }
        #[\Override]
        public function stop(): void
        {
            if (method_exists($this->worker, 'stop')) {
                $this->worker->stop();
                return;
            }
            if (method_exists($this->worker, 'getWorker')) {
                $inner = $this->worker->getWorker();
                if (is_object($inner) && method_exists($inner, 'stop')) {
                    $inner->stop();
                }
            }
        }
        #[\Override]
        public function isRunning(): bool
        {
            if (method_exists($this->worker, 'isStopped')) {
                return !$this->worker->isStopped();
            }
            return true;
        }
    }

}
