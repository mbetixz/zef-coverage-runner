<?php

declare(strict_types=1);

namespace Zef\Framework\Job {
    // Compatibility aggregate retained for explicit legacy requires.
    // Public declarations moved to per-type files (D2 structural decomposition):
    // interface JobInterface, interface JobQueueInterface,
    // interface JobHandlerInterface, interface JobMiddlewareInterface,
    // interface JobIdempotencyStoreInterface, class JobContext, class RetryPolicy,
    // class JobEnvelope, class JobResult, class JobExecutionException,
    // class JobCancelledException, class JobTimeoutException,
    // class InMemoryJobQueue, class InMemoryJobIdempotencyStore,
    // class InProcessJobWorker.
}

namespace {
    require_once __DIR__ . '/JobContext.php';
    require_once __DIR__ . '/RetryPolicy.php';
    require_once __DIR__ . '/JobEnvelope.php';
    require_once __DIR__ . '/JobResult.php';
    require_once __DIR__ . '/JobInterface.php';
    require_once __DIR__ . '/JobQueueInterface.php';
    require_once __DIR__ . '/JobHandlerInterface.php';
    require_once __DIR__ . '/JobMiddlewareInterface.php';
    require_once __DIR__ . '/JobIdempotencyStoreInterface.php';
    require_once __DIR__ . '/JobExecutionException.php';
    require_once __DIR__ . '/JobCancelledException.php';
    require_once __DIR__ . '/JobTimeoutException.php';
    require_once __DIR__ . '/InMemoryJobQueue.php';
    require_once __DIR__ . '/InMemoryJobIdempotencyStore.php';
    require_once __DIR__ . '/InProcessJobWorker.php';
}
