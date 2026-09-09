<?php
declare(strict_types=1);
require __DIR__.'/../../zef_framework_v2.5.0-beta1.php';
use Zef\Framework\MiddlewareDefinition;
$fail=0;
$assert=static function(bool $ok,string $msg)use(&$fail):void{echo ($ok?'PASS: ':'FAIL: ').$msg."\n";if(!$ok)++$fail;};
$factory=static fn()=>null;
// string and array legacy normalization must converge on equivalent service identity.
$a=MiddlewareDefinition::fromLegacy('middleware.timing');
$b=MiddlewareDefinition::fromArray(['service'=>'middleware.timing']);
$assert($a->serviceId===$b->serviceId,'legacy string and array converge on service id');
$assert($a->priority===$b->priority,'legacy string and array converge on priority');
$assert($a->group===$b->group,'legacy string and array converge on group');
$assert($a->tags===$b->tags,'legacy string and array converge on tags');
// explicit metadata remains deterministic.
$c=MiddlewareDefinition::fromArray(['service'=>'middleware.auth','priority'=>'50','group'=>'web','tags'=>['auth']]);
$assert($c->priority===50,'numeric string priority normalizes deterministically');
$assert($c->tags===['auth'],'tags preserve order');
exit($fail===0?0:1);
