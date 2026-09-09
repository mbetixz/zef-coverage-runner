<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zef\Framework\ModuleBootstrapper;
use Zef\Framework\Router\RouteDefinition;
use Zef\Framework\Router\Router;
use Zef\Framework\Container\Container;

final class RouteDefinitionIntegrationTest extends TestCase
{
    public function testLegacyBehavior(): void
    {
        $pass=0;$fail=0;
        $check=static function(bool $ok,string $message)use(&$pass,&$fail):void{if($ok){++$pass;echo "PASS: {$message}\n";}else{++$fail;echo "FAIL: {$message}\n";}};

        $container=new Container(debug:false);
        $router=new Router();
        $bootstrapper=new ModuleBootstrapper($container,$router);

        $bootstrapper->registerModule('test', [
            'services'=>[
                'test.handler'=>[
                    'factory'=>static fn()=>new stdClass(),
                    'deps'=>[],
                ],
            ],
            'routes'=>[
                new RouteDefinition('GET','/typed','test.handler',25),
                ['method'=>'GET','path'=>'/legacy','handler'=>'test.handler','priority'=>10],
            ],
        ]);

        $routes=$router->getRoutes();
        $check(count($routes)===2,'typed and legacy route definitions are both registered');
        $byPath=[];
        foreach($routes as $route){$byPath[$route['pattern']]=$route;}
        $check(($byPath['/typed']['priority']??null)===25,'typed route priority reaches router unchanged');
        $check(($byPath['/legacy']['priority']??null)===10,'legacy route priority remains compatible');
        $check(($byPath['/typed']['handler']??null)==='test.handler','typed route handler reaches router unchanged');
        $check(($byPath['/legacy']['handler']??null)==='test.handler','legacy route handler remains compatible');

        $this->assertSame(0, $fail, 'legacy route-definition integration checks failed');
    }
}
