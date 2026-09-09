<?php
declare(strict_types=1);
require __DIR__.'/_assert.php';
require dirname(__DIR__,2).'/zef_framework_v2.5.0-beta1.php';
use Zef\Framework\Container\ServiceDefinition;
use Zef\Framework\Container\ServiceLifetime;
$factory=static fn()=>42;
$legacy=[
 'factory'=>$factory,'deps'=>['logger'],'lifetime'=>ServiceLifetime::TRANSIENT,
 'tags'=>['core','test'],'lazy'=>true,
];
$d=ServiceDefinition::fromArray('demo.service',$legacy);
$r=ServiceDefinition::fromArray('demo.service',$legacy);
architecture_check($d->id==='demo.service','service id preserved');
architecture_check($d->factory===$factory,'factory identity preserved');
architecture_check($d->dependencies===['logger'],'dependencies preserved');
architecture_check($d->lifetime===ServiceLifetime::TRANSIENT,'lifetime preserved');
architecture_check($d->shared===false,'transient shared semantics preserved');
architecture_check($d->tags===['core','test'],'tags preserve order');
architecture_check($d->lazy===true,'lazy metadata preserved');
architecture_check($r->id===$d->id && $r->factory===$d->factory && $r->dependencies===$d->dependencies && $r->lifetime===$d->lifetime && $r->shared===$d->shared && $r->lazy===$d->lazy && $r->tags===$d->tags,'legacy normalization deterministic');
