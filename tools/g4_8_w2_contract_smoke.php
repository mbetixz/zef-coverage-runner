<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Zef\Framework\Transport\CancellationTokenInterface;
use Zef\Framework\Transport\RemoteRequest;
use Zef\Framework\Transport\RemoteTransportInterface;
use Zef\Framework\Transport\RemoteTransportResult;
use Zef\Framework\Transport\TransportContext;
use Zef\Framework\Transport\TransportOutcome;
$assert=static function(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);};
$r=new RemoteRequest('health.check','', ['traceparent'=>'00-abc-def-01']);
$c=new TransportContext(1000,1000);
$assert(interface_exists(RemoteTransportInterface::class),'interface');
$assert($r->operation==='health.check','operation');
$assert($c->remainingMs(250)===750,'remaining');
$assert(!$c->hasExpired(999) && $c->hasExpired(1000),'deadline');
$assert((new RemoteTransportResult(TransportOutcome::SUCCESS))->isDefinitive(),'success definitive');
$assert(!(new RemoteTransportResult(TransportOutcome::INDETERMINATE))->isDefinitive(),'indeterminate non-definitive');
$cancel=new class implements CancellationTokenInterface{#[\Override]public function isCancellationRequested():bool{return true;}};
$assert((new TransportContext(10,10,$cancel))->isCancelled(),'cancellation');
foreach([static fn()=>new RemoteRequest('', ''),static fn()=>new RemoteRequest('x',str_repeat('x',1048577)),static fn()=>new RemoteRequest('x','',array_fill(0,33,'x'))] as $case){$ok=false;try{$case();}catch(InvalidArgumentException){$ok=true;}$assert($ok,'bounded invalid input must reject');}
echo "G4.8 W2 native contract smoke PASS\n";
