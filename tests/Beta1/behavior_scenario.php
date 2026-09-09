<?php
declare(strict_types=1);
$mode=$argv[1]??'';
$root=dirname(__DIR__,2);
if($mode==='baseline') require $root.'/zef_framework_v2.5.0-beta1.php';
elseif($mode==='modular') require $root.'/vendor/autoload.php';
elseif($mode==='monolith') require $root.'/zef_framework_v2.5.0-beta1.php';
else {fwrite(STDERR,"mode required\n");exit(2);}
use Zef\App\Bootstrap;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Uri;
$cases=[['GET','/'],['GET','/about'],['GET','/missing'],['GET','/toko/produk/2'],['GET','/toko/produk/abc'],['HEAD','/about']];
$app=Bootstrap::createApp(false);$app->boot();$out=[];
foreach($cases as [$method,$path]){
 $req=new ServerRequest($method,new Uri('http://localhost'.$path,['localhost']));
 $res=$app->handle($req);
 $headers=$res->getHeaders(); ksort($headers);
 $body=(string)$res->getBody();
 $out[]=['method'=>$method,'path'=>$path,'status'=>$res->getStatusCode(),'headers'=>$headers,'body'=>$body];
}
$version=\Zef\Framework\Foundation\ZefVersion::VERSION;
echo json_encode(['version'=>$version,'cases'=>$out],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),"\n";
