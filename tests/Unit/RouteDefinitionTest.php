<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\Router\RouteDefinition;

final class RouteDefinitionTest extends TestCase
{
    public function testLegacyBehavior(): void
    {
        $pass=0;$fail=0;
        $check=static function(bool $condition,string $message)use(&$pass,&$fail):void{if($condition){++$pass;echo "PASS: $message\n";}else{++$fail;echo "FAIL: $message\n";}};

        $d=new RouteDefinition(' get ','/users','users.index',10);
        $check($d->method==='GET','method is normalized to uppercase and trimmed');
        $check($d->path==='/users','path is preserved');
        $check($d->handler==='users.index','handler is preserved');
        $check($d->priority===10,'priority is preserved');

        $l=RouteDefinition::fromArray(['method'=>'post','path'=>'/users/{id:int}','handler'=>'users.detail','priority'=>'20']);
        $check($l->method==='POST','array normalization preserves normalized method');
        $check($l->path==='/users/{id:int}','array normalization preserves path');
        $check($l->handler==='users.detail','array normalization preserves handler');
        $check($l->priority===20,'numeric priority is normalized');

        $defaults=RouteDefinition::fromArray(['handler'=>'home']);
        $check($defaults->method==='GET','default method is GET');
        $check($defaults->path==='/','default path is root');
        $check($defaults->priority===0,'default priority is zero');

        foreach([
            fn()=>new RouteDefinition('','/x','x'),
            fn()=>new RouteDefinition('GET','x','x'),
            fn()=>new RouteDefinition('GET','/x',''),
            fn()=>RouteDefinition::fromArray(['method'=>123,'path'=>'/x','handler'=>'x']),
            fn()=>RouteDefinition::fromArray(['method'=>'GET','path'=>'/x','handler'=>'x','priority'=>[]]),
        ] as $invalid){try{$invalid();$check(false,'invalid route definition rejected');}catch(Throwable){$check(true,'invalid route definition rejected');}}

        try { RouteDefinition::fromArray(['method'=>'GET','path'=>'/x','handler'=>'x','priority'=>'not-numeric']); $check(false,'non-numeric priority rejected'); }
        catch(Throwable){ $check(true,'non-numeric priority rejected'); }

        $this->assertSame(0, $fail, 'legacy route definition checks failed');
    }
}
