<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/zef_framework_v2.5.0-beta1.php';
use Zef\Framework\Http\Psr17Factory;
use Zef\Framework\Router\Router;

function zef_assert(bool $condition, string $label): void { if (!$condition) throw new RuntimeException($label); echo "PASS: {$label}\n"; }
$f = new Psr17Factory();
$r = fopen('php://temp','w+b'); if($r===false){ throw new RuntimeException('fopen php://temp failed'); } fwrite($r,'abc'); rewind($r); $s=$f->createStreamFromResource($r); $chunk1=$s->read(3); $chunk2=$s->read(3); zef_assert($chunk1==='abc' && $chunk2==='', 'stream cursor exhaustion is deterministic');
$req=$f->createServerRequest('POST','/'); zef_assert($req->withParsedBody(null)->getParsedBody()===null,'parsed body accepts null'); $bad=false; try{$req->withParsedBody(1); /** @phpstan-ignore argument.type */}catch(InvalidArgumentException){$bad=true;} zef_assert($bad,'parsed body rejects scalar');
$router=new Router(); $router->setMaxRoutesBudget(2); $router->add('GET','/a','h.a'); $router->add('GET','/b','h.b'); $bad=false; try{$router->add('GET','/c','h.c');}catch(Throwable){$bad=true;} zef_assert($bad,'route budget boundary is enforced');
echo "BehavioralNuancesTest: PASS\n";
