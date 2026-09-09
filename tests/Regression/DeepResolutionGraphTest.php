<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/zef_framework_v2.5.0-beta1.php';
use Zef\Framework\Container\Container;
final class DeepLeaf249 {} final class DeepNode249 { public function __construct(public readonly object $next) {} }
$c=new Container(); $depth=250; $prev='deep.0'; $c->register($prev, static fn()=>new DeepLeaf249(), [], 'deep'); for($i=1;$i<$depth;$i++){ $id='deep.'.$i; $dep=$prev; $c->register($id, static fn($ctx, object $next)=>new DeepNode249($next),[$dep],'deep'); $prev=$id; } $c->validateAndFreeze(); $root=$c->get($prev); if(!$root instanceof DeepNode249) throw new RuntimeException('deep graph resolution failed'); echo "DeepResolutionGraphTest: PASS depth={$depth}\n";
