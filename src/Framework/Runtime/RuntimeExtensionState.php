<?php

declare(strict_types=1);

namespace Zef\Framework\Runtime {
    /** Runtime extension lifecycle state. */
    enum RuntimeExtensionState: string
    {
        case STARTING = 'starting';
        case READY = 'ready';
        case DRAINING = 'draining';
        case STOPPED = 'stopped';
    }

}
