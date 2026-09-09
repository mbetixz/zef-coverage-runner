<?php
declare(strict_types=1);
require __DIR__.'/_assert.php';
$root=dirname(__DIR__,2);
require $root.'/zef_framework_v2.5.0-beta1.php';
$classes=[\Zef\Framework\Container\ServiceDefinition::class,\Zef\Framework\MiddlewareDefinition::class,\Zef\Framework\Router\RouteDefinition::class,\Zef\Framework\Config\ModuleDefinition::class,\Zef\Framework\Policy\ArchitecturePolicy::class];
foreach($classes as $class){ architecture_check(class_exists($class), 'semantic public class exists: '.$class); $r=new ReflectionClass($class); architecture_check($r->isFinal(), 'semantic public class final: '.$class); }
$r=new ReflectionMethod(\Zef\Framework\Container\ServiceDefinition::class,'fromArray'); architecture_check($r->isPublic(),'ServiceDefinition::fromArray public contract'); architecture_check((string)$r->getReturnType()==='self','ServiceDefinition::fromArray return type');
