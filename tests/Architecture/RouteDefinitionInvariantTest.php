<?php
declare(strict_types=1);
require __DIR__ . '/_assert.php';
require dirname(__DIR__,2) . '/zef_framework_v2.5.0-beta1.php';
use Zef\Framework\Router\RouteDefinition;
$legacy=['method'=>'GET','path'=>'/x/{id:int}','handler'=>'x.handler','priority'=>'5'];
$d=RouteDefinition::fromArray($legacy);
architecture_check($d->method==='GET','legacy method preserved');
architecture_check($d->path===$legacy['path'],'legacy path preserved');
architecture_check($d->handler===$legacy['handler'],'legacy handler preserved');
architecture_check($d->priority===5,'legacy priority normalized deterministically');
architecture_check(RouteDefinition::fromArray(['handler'=>'root'])->path==='/' ,'default path remains root');
