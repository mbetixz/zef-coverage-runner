<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Config\ModuleDefinition;
use Zef\Framework\Container\ServiceDefinition;
use Zef\Framework\Container\ServiceLifetime;
use Zef\Framework\Router\RouteDefinition;

final class ModuleDefinitionTest extends TestCase
{
    public function testLegacyBehavior(): void
    {
        $assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
        $f=static fn()=>new stdClass();

        $tests=[
         'constructs typed module'=>function()use($assert,$f){$m=new ModuleDefinition('core',['core.svc'=>new ServiceDefinition('core.svc',$f)],[],[new RouteDefinition('GET','/','core.svc')]);$assert($m->name==='core','module name');$assert(isset($m->services['core.svc']),'service typed');$assert(isset($m->routes[0]) && $m->routes[0]->method==='GET','route typed');},
         'normalizes legacy services'=>function()use($assert,$f){$m=ModuleDefinition::fromArray('core',['services'=>['core.svc'=>['factory'=>$f,'deps'=>[]]]]);$assert(isset($m->services['core.svc']) && $m->services['core.svc']->id==='core.svc','legacy service normalized');$assert($m->services['core.svc']->module==='core','module attached');},
         'normalizes legacy routes'=>function()use($assert){$m=ModuleDefinition::fromArray('core',['routes'=>[['method'=>'GET','path'=>'/','handler'=>'core.svc']]]);$assert(isset($m->routes[0]) && $m->routes[0]->handler==='core.svc','legacy route normalized');},
         'normalizes aliases'=>function()use($assert){$m=ModuleDefinition::fromArray('core',['aliases'=>['foo'=>'bar']]);$assert($m->aliases===['foo'=>'bar'],'aliases preserved');},
         'preserves extension config'=>function()use($assert){$m=ModuleDefinition::fromArray('middleware',['stack'=>['a'],'custom'=>['x'=>1]]);$assert($m->extensions['stack']===['a'],'stack preserved');$assert(is_array($m->extensions['custom']??null) && ($m->extensions['custom']['x']??null)===1,'extension preserved');},
         'rejects invalid name'=>function()use($assert){$ok=false;try{new ModuleDefinition('bad name');}catch(InvalidArgumentException){$ok=true;}$assert($ok,'invalid module name accepted');},
         'rejects service id mismatch'=>function()use($assert,$f){$ok=false;try{new ModuleDefinition('core',['key'=>new ServiceDefinition('other',$f)]);}catch(InvalidArgumentException){$ok=true;}$assert($ok,'service id mismatch accepted');},
         'rejects invalid aliases'=>function()use($assert){$ok=false;try{new ModuleDefinition('core',[],[''=>'x']);}catch(InvalidArgumentException){$ok=true;}$assert($ok,'invalid alias accepted');},
         'rejects invalid routes'=>function()use($assert){$ok=false;try{new ModuleDefinition('core',[],[],[['bad']]);}catch(InvalidArgumentException){$ok=true;}$assert($ok,'invalid route accepted');},
         'preserves lifetime semantics'=>function()use($assert,$f){$m=ModuleDefinition::fromArray('core',['services'=>['core.svc'=>['factory'=>$f,'lifetime'=>ServiceLifetime::TRANSIENT,'shared'=>false]]]);$assert($m->services['core.svc']->lifetime===ServiceLifetime::TRANSIENT,'lifetime drift');$assert($m->services['core.svc']->shared===false,'shared drift');},
         'idempotent typed input'=>function()use($assert,$f){$sd=new ServiceDefinition('core.svc',$f,module:'core');$rd=new RouteDefinition('GET','/','core.svc');$m=ModuleDefinition::fromArray('core',['services'=>['core.svc'=>$sd],'routes'=>[$rd]]);$assert($m->services['core.svc'] === $sd,'service identity changed');$assert($m->routes[0] === $rd,'route identity changed');},
         'normalizes module case without changing identity'=>function()use($assert,$f){$m=ModuleDefinition::fromArray('Core',['services'=>['core.svc'=>['factory'=>$f]]]);$assert($m->name==='Core','module name case lost');$assert($m->services['core.svc']->module==='Core','module case lost on service');},
         'keeps empty sections valid'=>function()use($assert){$m=new ModuleDefinition('core');$assert($m->services===[]&&$m->aliases===[]&&$m->routes===[],'empty sections invalid');},
         'toArray roundtrip metadata'=>function()use($assert,$f){$m=ModuleDefinition::fromArray('core',['services'=>['core.svc'=>['factory'=>$f]],'aliases'=>['a'=>'b'],'routes'=>[['method'=>'GET','path'=>'/','handler'=>'core.svc']],'stack'=>['x']]);/** @var array{services?:array<string,mixed>,aliases?:array<string,mixed>,routes?:list<mixed>,stack?:mixed} $a */$a=$m->toArray();$assert(isset($a['services']['core.svc'],$a['aliases']['a'],$a['routes'][0],$a['stack']),'roundtrip metadata lost');},
         'fromArray rejects malformed service config'=>function()use($assert){$ok=false;try{ModuleDefinition::fromArray('core',['services'=>['x'=>['factory'=>'not callable']]]);}catch(Throwable){$ok=true;}$assert($ok,'malformed service config accepted');},
        ];
        foreach($tests as $name=>$test){$test();}
        $this->addToAssertionCount(1);
    }
}
