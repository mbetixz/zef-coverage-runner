<?php

declare(strict_types=1);

namespace Zef\Framework\Runtime;

/** Contract for a single-use persistent worker runtime. */
interface RuntimeInterface
{
    /** Run the runtime until the worker stops or a terminal condition occurs. */
    public function run(): int;
    /** Request a graceful runtime stop. */
    public function stop(): void;
    /** Report whether the runtime is currently processing its loop. */
    public function isRunning(): bool;
}
