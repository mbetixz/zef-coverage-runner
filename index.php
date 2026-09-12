<?php
declare(strict_types=1);
require_once __DIR__.'/src/autoload.php';
$version=\Zef\Framework\Foundation\ZefVersion::VERSION;
if(version_compare(PHP_VERSION,'8.4.0','<')){fwrite(STDERR,"ZEF Framework v{$version} requires PHP >= 8.4\n");exit(1);}
$debug=filter_var(getenv('ZEF_DEBUG')?:'0',FILTER_VALIDATE_BOOL);
if(PHP_SAPI==='cli'){
    $args=[];
    if (is_array($_SERVER['argv'] ?? null)) {
        foreach ($_SERVER['argv'] as $arg) {
            if (is_string($arg)) $args[]=$arg;
        }
    }
    if(in_array('--self-test',$args,true)) exit((new \Zef\Test\CliRunner())->run(false));
    fwrite(STDOUT,"ZEF Framework v{$version}\nRun with --self-test for diagnostics.\n");
    exit(0);
}
try{
    $app=\Zef\App\Bootstrap::createApp($debug);
    $response=$app->handleGlobals();
    $app->emit($response);
}catch(\Throwable $e){
    if(!headers_sent())http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $debug?get_class($e).': '.$e->getMessage():'Internal Server Error';
}
