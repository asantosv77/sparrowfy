<?php
namespace TransferFixture;
use PDO;use RuntimeException;use Throwable;use RecursiveIteratorIterator;use RecursiveDirectoryIterator;use FilesystemIterator;
if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/lib/mornye_retry.php';
$base=dirname(__DIR__).'/data/fixture-transfer-'.bin2hex(random_bytes(6));mkdir($base,0700);mkdir($base.'/receipts',0700);
function realpath($p){global $base;return $p==='/sparrowfy/inbox'?$base:\realpath($p);}
function sfq_dir(){global $base;return $base.'/receipts';}
function sfmn_write($p,$v){file_put_contents($p,json_encode($v));}
function sf_external_mornye_url($u){return $u;}
function sfq_update(...$v){}
function sfmn_event(...$v){}
function sfmn_request($op){return $op==='settings'?['services'=>['amazon','qobuz'],'allow_fallback'=>true]:['services'=>[['id'=>'amazon'],['id'=>'qobuz']]];}
function sf_external_mornye_status($id){return $id==='original'?['status'=>'failed','error'=>'ext:amazon: HTTP 404']:['status'=>'done','error'=>''];}
$requests=[];$uncertain=false;
function sf_external_mornye_start($url,$dir,$runner=null,$providers=null){global $requests,$base,$uncertain;$requests[]=[$url,$dir,$providers];if($uncertain)throw new RuntimeException('connection lost after submission');file_put_contents($base.substr($dir,6).'/source.flac','new source');return ['id'=>'second'];}
$source=file_get_contents(dirname(__DIR__).'/lib/mornye_import.php');$start=strpos($source,'function sfmn_fetch_source(');$end=strpos($source,'/** Only retire',$start);eval('namespace TransferFixture; use PDO; use RuntimeException; use Throwable; use RecursiveIteratorIterator; use RecursiveDirectoryIterator; use FilesystemIterator; '.substr($source,$start,$end-$start));
function check($v,$label){if(!$v)throw new RuntimeException($label);echo 'PASS '.$label.PHP_EOL;}
$db=new PDO('sqlite::memory:');$id=str_repeat('a',32);mkdir($base.'/sf-'.$id,0700);file_put_contents($base.'/sf-'.$id.'/partial.flac','old incomplete bytes');$receipt=sfq_dir().'/mornye-'.$id.'.json';sfmn_write($receipt,['remote_id'=>'original','created'=>time()]);$result=null;
try{
 $payload=['url'=>'https://open.spotify.com/track/1234567890123456789012','filename'=>'Custom title'];
 $result=sfmn_fetch_source($db,['id'=>$id],$payload);
 check(count($requests)===1&&$requests[0][2]===['qobuz'],'confirmed failed provider switches once in order');
 check(file_get_contents($result['source'])==='new source','partial previous audio excluded from source');
 check($result['filename']==='Custom title.flac','custom title survives provider retry');
 $state=json_decode(file_get_contents($receipt),true);check($state['remote_id']==='second'&&$state['history'][0]['id']==='original','attempt identity survives process restart');
 $result2=sfmn_fetch_source($db,['id'=>$id],$payload+['mornye_download_ready'=>true]);
 check(count($requests)===1&&file_get_contents($result2['source'])==='new source','conversion resumes downloaded source without another download');
 $uncertain=true;sfmn_write($receipt,['remote_id'=>'original','created'=>time()]);
 try{sfmn_fetch_source($db,['id'=>$id],$payload);}catch(RuntimeException $e){}
 $count=count($requests);$blocked=false;try{sfmn_fetch_source($db,['id'=>$id],$payload);}catch(RuntimeException $e){$blocked=true;}
 check($blocked&&count($requests)===$count,'lost submission response prevents duplicate resend');
}finally{
 if($result&&is_dir($result['directory'])){unlink($result['source']);rmdir($result['directory']);}
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($base);
}
