<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\MiddlewareDefinition;

final class MiddlewareDefinitionTest extends TestCase
{
    public function testLegacyBehavior(): void
    {
        $pass=0;$fail=0;
        $check=static function(bool $condition,string $message)use(&$pass,&$fail):void{if($condition){++$pass;echo "PASS: $message\n";}else{++$fail;echo "FAIL: $message\n";}};

        $def=new MiddlewareDefinition('middleware.auth');
        $check($def->serviceId==='middleware.auth','typed definition preserves service id');
        $check($def->priority===0,'default priority is zero');
        $check($def->group===null,'default group is null');
        $check($def->tags===[],'default tags are empty');

        $legacy=MiddlewareDefinition::fromArray([
          'service'=>'middleware.security',
          'priority'=>100,
          'group'=>'web',
          'tags'=>['security'],
        ]);
        $check($legacy->serviceId==='middleware.security','array normalization preserves service id');
        $check($legacy->priority===100,'array normalization preserves priority');
        $check($legacy->group==='web','array normalization preserves group');
        $check($legacy->tags===['security'],'array normalization preserves tags');

        $string=MiddlewareDefinition::fromLegacy('middleware.timing');
        $check($string->serviceId==='middleware.timing','legacy string remains compatible');

        foreach([
          fn()=>new MiddlewareDefinition(''),
          fn()=>new MiddlewareDefinition('x',0,''),
          fn()=>new MiddlewareDefinition('x',0,null,[123]), // @phpstan-ignore argument.type
          fn()=>MiddlewareDefinition::fromArray([]),
          fn()=>MiddlewareDefinition::fromArray(['service'=>'x','tags'=>'bad']),
          fn()=>MiddlewareDefinition::fromArray(['service'=>'x','group'=>123]),
        ] as $invalid){try{$invalid();$check(false,'invalid middleware definition rejected');}catch(Throwable){$check(true,'invalid middleware definition rejected');}}

        $this->assertSame(0, $fail, 'legacy middleware definition checks failed');
    }
}
