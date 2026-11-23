<?php
require_once dirname(__DIR__).'/lib/external_youtube.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function sfwc_root(){return $GLOBALS['root'];}
function sfwc_save($dir,$m){$GLOBALS['saved']=$m;}
function sfq_finish($db,$id,$result){$GLOBALS['result']=$result;}
$worker=file_get_contents(dirname(__DIR__).'/scripts/processing_worker.php');
$start=strpos($worker,"if(!empty(\$payload['client_source'])){");
$end=strpos($worker,' $replacementTags=[];',$start);
$block=substr($worker,$start,$end-$start);
$block=str_replace("require_once dirname(__DIR__).'/lib/client_processing.php';",'',$block);
$root=dirname(__DIR__).'/data/test-import-name-'.bin2hex(random_bytes(6));mkdir($root);
$sid=str_repeat('a',32);$dir=$root.'/'.$sid;mkdir($dir);$source=$root.'/fixture.webm';file_put_contents($source,'test');file_put_contents($dir.'/state.json',json_encode(['user'=>'fixture']));
try{
 $url='https://www.youtube.com/watch?v=abcdefghijk';$filename='Meghan Trainor - Last Christmas.webm';$payload=['client_source'=>$sid];$meta=['title'=>'Meghan Trainor - Last Christmas (Official Christmas Stroll Video)','duration'=>190];$job=['user_id'=>'fixture','album_id'=>'album'];$db=null;$id='job';
 (static function()use($block,$filename,$payload,$meta,$job,$db,$id,$source,$url){eval($block);})();
 check($saved['source_url']===$url&&$saved['source_service']==='youtube','Source handoff lost origin URL');check($saved['filename']===$filename,'Source handoff replaced reviewed name');check($result['filename']===$filename,'Job result replaced reviewed name');check($saved['source_file']==='source.webm','Physical path changed');
 $api=file_get_contents(dirname(__DIR__).'/api_client_source.php');$start=strpos($api," \$requested=trim(");$end=strpos($api," \$payload=['filename'",$start);$naming=substr($api,$start,$end-$start);$naming=str_replace("require_once __DIR__.'/lib/external_youtube.php';",'',$naming);
 $_POST=['filename'=>'Meghan Trainor - Last Christmas.audio'];$m=['filename'=>'Provider title.webm'];$name='source.webm';eval($naming);check($m['filename']===$filename,'Server fallback lost reviewed name');
 $_POST=['filename'=>'../../untrusted.mp3'];eval($naming);check(!str_contains($m['filename'],'/'),'Unsafe display filename');check(str_ends_with($m['filename'],'.webm'),'Client changed source extension');
 echo "PASS: worker source handoff and server fallback preserve reviewed name and source extension\n";
}finally{foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);unlink($source);rmdir($root);}
