<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/zef_framework_v2.5.0-beta1.php';
$v=\Zef\Framework\Foundation\ZefVersion::VERSION; /** @var string $v */ $expected='2.5.0-beta1'; if($v!==$expected) exit(1); $app=Zef\App\Bootstrap::createApp(debug:false); $app->boot(); $res=$app->handle(new Zef\Framework\Http\ServerRequest('GET',new Zef\Framework\Http\Uri('http://localhost/about'))); /** @var Zef\Framework\Http\Response $res */ $data=json_decode($res->bodyString(),true,512,JSON_THROW_ON_ERROR); if(!is_array($data)) exit(3); if(($data['version']??null)!==$v) exit(2); echo "VersionConsistencyTest: PASS\n";
