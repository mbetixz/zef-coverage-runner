<?php
declare(strict_types=1);
require_once __DIR__ . '/../../zef_framework_v2.5.0-beta1.php';

use Zef\Framework\Config\ModuleDefinition;
use Zef\Framework\Container\ServiceDefinition;
use Zef\Framework\Router\RouteDefinition;

$assert=function(bool $ok,string $msg):void{if(!$ok)throw new RuntimeException($msg);};
$f=static fn()=>new stdClass();
$m=ModuleDefinition::fromArray('core',[
    'services'=>['core.svc'=>['factory'=>$f,'deps'=>['logger'],'lifetime'=>'singleton','tags'=>['core']]],
    'aliases'=>['core.alias'=>'core.svc'],
    'routes'=>[['method'=>'GET','path'=>'/','handler'=>'core.svc','priority'=>10]],
]);
$svc=$m->services['core.svc'] ?? null; $assert($svc instanceof ServiceDefinition ? $svc->dependencies===['logger'] : false,'service is not typed');
$assert($m->services['core.svc']->module==='core','service module attached');
$assert($m->aliases['core.alias']==='core.svc','alias drift');
$route=$m->routes[0] ?? null; $assert($route instanceof RouteDefinition ? $route->priority===10 : false,'route is not typed');
$legacy=$m->toArray();
$roundtrip=ModuleDefinition::fromArray('core',$legacy);
$assert($roundtrip->services['core.svc']->dependencies===$m->services['core.svc']->dependencies,'roundtrip service drift');
$assert($roundtrip->aliases===$m->aliases,'roundtrip alias drift');
$assert($roundtrip->routes[0]->path===$m->routes[0]->path,'roundtrip route drift');
echo "PASS: ModuleDefinition invariants\n";
