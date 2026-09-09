<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require $root.'/zef_framework_v2.5.0-beta1.php';
foreach ([
    'Zef\\Framework\\Job\\JobInterface',
    'Zef\\Framework\\Job\\JobQueueInterface',
    'Zef\\Framework\\Job\\JobHandlerInterface',
    'Zef\\Framework\\Job\\JobMiddlewareInterface',
    'Zef\\Framework\\Job\\JobIdempotencyStoreInterface',
    'Zef\\Framework\\Job\\InProcessJobWorker',
] as $class) {
    if (!interface_exists($class) && !class_exists($class)) { fwrite(STDERR,"missing {$class}\n"); exit(1); }
}
echo "JobSystemMonolithTest: PASS\n";
