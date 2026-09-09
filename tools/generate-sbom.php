<?php
declare(strict_types=1);
$root=dirname(__DIR__);
/** @return array<string,mixed> */
function readJson(string $path): array {
    $decoded=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    if(!is_array($decoded)) throw new RuntimeException('Expected JSON object: '.$path);
    /** @var array<string,mixed> $typed */ $typed=$decoded; return $typed;
}
/** @param array<string,mixed> $record */
function stringField(array $record,string $key,string $fallback=''): string { $value=$record[$key]??$fallback; return is_string($value)?$value:$fallback; }
/** @return list<string> */
function licensesOf(mixed $raw): array { if(is_string($raw)) return [$raw]; if(!is_array($raw)) return []; $out=[]; foreach($raw as $v) if(is_string($v)) $out[]=$v; return $out; }
$lock=readJson($root.'/composer.lock'); $components=[];
foreach(['packages'=>'required','packages-dev'=>'optional'] as $section=>$scope){ $packages=$lock[$section]??[]; if(!is_array($packages)) throw new RuntimeException('Invalid lock section: '.$section); foreach($packages as $p){ if(!is_array($p)) throw new RuntimeException('Invalid package record.'); /** @var array<string,mixed> $p */ $name=stringField($p,'name'); $version=stringField($p,'version'); if($name===''||$version==='') throw new RuntimeException('Package name/version missing.'); $parts=explode('/',$name,2); $c=['type'=>'library','bom-ref'=>'pkg:composer/'.$name.'@'.rawurlencode($version),'name'=>$parts[1]??$name,'version'=>$version,'scope'=>$scope,'group'=>$parts[0]]; $dist=$p['dist']??[]; if(is_array($dist)){ /** @var array<string,mixed> $dist */ $shasum=stringField($dist,'shasum'); if($shasum!=='')$c['hashes']=[['alg'=>'SHA-1','content'=>$shasum]]; } $ls=licensesOf($p['license']??null); if($ls!==[])$c['licenses']=array_map(static fn(string $l):array=>['license'=>['id'=>$l]],$ls); $components[]=$c; }}
$lockHash=hash_file('sha256',$root.'/composer.lock'); if($lockHash===false) throw new RuntimeException('Unable to hash composer.lock.'); $bom=['bomFormat'=>'CycloneDX','specVersion'=>'1.7','serialNumber'=>'urn:uuid:'.substr(hash('sha256','zef-sbom:'.$lockHash),0,32),'version'=>1,'metadata'=>['timestamp'=>'1970-01-01T00:00:00Z','component'=>['type'=>'framework','bom-ref'=>'pkg:composer/zef/framework@2.5.0-beta1','group'=>'zef','name'=>'framework','version'=>'2.5.0-beta1'],'tools'=>[['vendor'=>'Zef Framework','name'=>'zef-sbom-generator','version'=>'1.1.2']]],'components'=>$components];
file_put_contents($root.'/supply-chain/bom.cdx.json',json_encode($bom,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n"); echo "SBOM generated\n";
