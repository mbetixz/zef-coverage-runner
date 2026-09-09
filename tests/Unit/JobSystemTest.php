<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Job\InMemoryJobIdempotencyStore;
use Zef\Framework\Job\InMemoryJobQueue;
use Zef\Framework\Job\InProcessJobWorker;
use Zef\Framework\Job\JobEnvelope;
use Zef\Framework\Job\JobInterface;
use Zef\Framework\Job\JobMiddlewareInterface;
use Zef\Framework\Job\RetryPolicy;

final class JobTrace
{
    /** @var list<string> */
    public array $events = [];
}

final class JobSystemTest extends TestCase
{
    public function testLegacyBehavior(): void
    {
        $check = static function (bool $cond, string $msg): void { if (!$cond) throw new RuntimeException($msg); };

        $qPriority = new InMemoryJobQueue(3);
        $qPriority->enqueue(new JobEnvelope('job00001','low',[],0,1));
        $qPriority->enqueue(new JobEnvelope('job00002','high',[],0,10));
        $qPriority->enqueue(new JobEnvelope('job00003','high',[],0,10));
        $first=$qPriority->dequeue(); $second=$qPriority->dequeue(); $third=$qPriority->dequeue();
        $check($first!==null&&$second!==null&&$third!==null&&$first->jobId==='job00002'&&$second->jobId==='job00003'&&$third->jobId==='job00001', 'priority ordering failed');
        $capRejected=false; try { $qPriority->enqueue(new JobEnvelope('job00004','x',[],0)); $qPriority->enqueue(new JobEnvelope('job00005','x',[],0)); $qPriority->enqueue(new JobEnvelope('job00006','x',[],0)); $qPriority->enqueue(new JobEnvelope('job00007','x',[],0)); } catch(OverflowException){ $capRejected=true; }
        $check($capRejected, 'queue capacity failed');

        $queue=new InMemoryJobQueue(10); $trace=new JobTrace();
        $worker=new InProcessJobWorker($queue,new RetryPolicy(3,0,0),new InMemoryJobIdempotencyStore(),null,0);
        $worker->use(new class($trace) implements JobMiddlewareInterface {
            public function __construct(private JobTrace $trace){}
            #[\Override]
            public function process(JobEnvelope $job, \Zef\Framework\Job\JobContext $context, \Closure $next): mixed { $this->trace->events[]='before'; $r=$next($job,$context); $this->trace->events[]='after'; return $r; }
        });
        $worker->register('demo', static fn(JobEnvelope $job): string => 'ok');
        $queue->enqueue(new JobEnvelope('job00008','demo',[],0,10));
        $result=$worker->processOne();
        $check($result!==null&&$result->completed&&$result->result==='ok'&&$trace->events===['before','after'], 'job execution failed');
        $freezeRejected=false; try{$worker->register('late',static fn():null=>null);}catch(LogicException){$freezeRejected=true;}
        $check($freezeRejected, 'freeze contract failed');

        $count=0;$store=new InMemoryJobIdempotencyStore();
        $a=$store->remember('idem0001',static function()use(&$count){++$count;return 42;});
        $b=$store->remember('idem0001',static fn()=>99);
        $check($a===42&&$b===42&&$count===1, 'idempotency failed');
        $dupQueue=new InMemoryJobQueue(5);$dupWorker=new InProcessJobWorker($dupQueue,new RetryPolicy(1,0,0),new InMemoryJobIdempotencyStore(),null,0);$dupCalls=0;
        $dupWorker->register('dup',static function()use(&$dupCalls):string{++$dupCalls;return 'once';});
        $dupQueue->enqueue(new JobEnvelope('job00016','dup',[],0));$dupQueue->enqueue(new JobEnvelope('job00016','dup',[],0));
        $dupA=$dupWorker->processOne();$dupB=$dupWorker->processOne();
        $check($dupA!==null&&$dupB!==null&&$dupA->completed&&$dupB->completed&&$dupA->result==='once'&&$dupB->result==='once'&&$dupCalls===1, 'worker idempotency failed');

        $jobObject=new class implements JobInterface { #[\Override] public function handle(\Zef\Framework\Job\JobContext $context):string{return 'job-object';} };
        $qObject=new InMemoryJobQueue(2);$wObject=new InProcessJobWorker($qObject,new RetryPolicy(1,0,0),null,null,0);$wObject->register('object',$jobObject);$qObject->enqueue(new JobEnvelope('job00009','object',[],0));
        $objectResult=$wObject->processOne();
        $check($objectResult!==null&&$objectResult->result==='job-object', 'JobInterface adapter failed');

        $retryQueue=new InMemoryJobQueue(10);$retryWorker=new InProcessJobWorker($retryQueue,new RetryPolicy(2,0,0),null,null,0);$attempts=0;
        $retryWorker->register('retry',static function()use(&$attempts):string{++$attempts;if($attempts===1)throw new RuntimeException('fail');return 'done';});
        $retryQueue->enqueue(new JobEnvelope('job00010','retry',[],0));$r1=$retryWorker->processOne();$r2=$retryWorker->processOne();
        $check($r1!==null&&$r1->completed===false&&$r1->deadLettered===false&&$r2!==null&&$r2->completed&&$attempts===2, 'retry failed');

        $dlq=new InMemoryJobQueue(10);$failQueue=new InMemoryJobQueue(10);$failWorker=new InProcessJobWorker($failQueue,new RetryPolicy(1,0,0),null,$dlq,0);
        $failWorker->register('dead',static function():never{throw new RuntimeException('permanent');});$failQueue->enqueue(new JobEnvelope('job00011','dead',[],0));$deadResult=$failWorker->processOne();
        $deadJobInDlq=$dlq->dequeue();
        $check($deadResult!==null&&$deadResult->deadLettered&&$dlq->size()===0&&$deadJobInDlq!==null&&$deadJobInDlq->jobId==='job00011', 'dead letter failed');

        $ctx=(new \Zef\Framework\Job\JobContext('job00012',1))->withDeadlineMs(1_000);
        $check(!$ctx->isTimedOut(), 'future deadline failed');
        $check($ctx->cancel()->isCancelled(), 'cancel context failed');
        $cancelGuarded=false; try{$ctx->cancel()->throwIfCancelled();}catch(\Zef\Framework\Job\JobExecutionException){$cancelGuarded=true;}
        $check($cancelGuarded, 'cancel guard failed');

        $qRun=new InMemoryJobQueue(10);$runWorker=new InProcessJobWorker($qRun,new RetryPolicy(1,0,0),null,null,0);$runCount=0;
        $runWorker->register('run',static function()use(&$runCount):string{++$runCount;return 'ok';});
        $qRun->enqueue(new JobEnvelope('job00013','run',[],0));$qRun->enqueue(new JobEnvelope('job00014','run',[],0));$qRun->enqueue(new JobEnvelope('job00015','run',[],0));
        $check($runWorker->run(2)===2&&$runCount===2&&$qRun->size()===1, 'maxJobs failed');
        $qStop=new InMemoryJobQueue(2);$stopWorker=new InProcessJobWorker($qStop,new RetryPolicy(1,0,0),null,null,0);$stops=0;
        $check($stopWorker->run(0,static function()use(&$stops):bool{++$stops;return true;})===0&&$stopWorker->isRunning()===false, 'stop callback failed');
        $this->addToAssertionCount(1);
    }
}
