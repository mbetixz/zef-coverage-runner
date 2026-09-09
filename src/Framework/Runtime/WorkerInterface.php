<?php

declare(strict_types=1);

namespace Zef\Framework\Runtime;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Transport boundary used by a persistent worker runtime. */
interface WorkerInterface
{
    /** Wait for the next request, or return null when the worker is exhausted. */
    public function waitRequest(): ?ServerRequestInterface;
    /** Send a response through the worker transport. */
    public function respond(ResponseInterface $response): void;
    /** Report a non-recoverable worker error to the supervisor. */
    public function error(string $message): void;
    /** Stop accepting work and begin transport shutdown. */
    public function stop(): void;
    /** Report whether the transport is still available. */
    public function isRunning(): bool;
}
