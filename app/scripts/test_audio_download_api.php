<?php
if(isset($argv[1])){
 $case=$argv[1];$GLOBALS['p']=new PDO('sqlite::memory:');$p=$GLOBALS['p'];$p->exec("CREATE TABLE users(id TEXT PRIMARY KEY,is_active INTEGER,role TEXT);INSERT INTO users VALUES('a',1,'admin'),('b',1,'user');CREATE TABLE sf_processing_jobs(id TEXT,user_id TEXT,operation TEXT,state TEXT,stage TEXT,progress INTEGER,error TEXT,result_json TEXT)");
 function lab_auth_pdo(){return $GLOBALS['p'];}function sfq_db(){return lab_auth_pdo();}function lab_auth_require_login(){}function lab_auth_current_account(){return ['id'=>'b'];}function lab_require_same_origin_post(){}function lab_auth_verify_csrf($v){return $v==='valid';}
 $helper=file_get_contents(dirname(__DIR__).'/lib/audio_download.php');$helper=preg_replace('/^require_once .*;$/m','',$helper);eval(substr($helper,5));sfd_db();
 if(in_array($case,['other','own','csrf'],true))$p->exec("INSERT INTO sf_audio_download_permissions VALUES('b',1)");
 $_SERVER['REQUEST_METHOD']=$case==='csrf'?'POST':'GET';$_GET=['id'=>str_repeat('a',32)];$_POST=['csrf_token'=>'invalid'];
 if($case==='permission')$_GET=['action'=>'permission'];
 $p->prepare('INSERT INTO sf_processing_jobs VALUES(?,?,?,?,?,?,?,?)')->execute([str_repeat('a',32),$case==='own'?'b':'a','download','queued','En cola',0,null,null]);
 ob_start();register_shutdown_function(function(){echo json_encode(['code'=>http_response_code()?:200,'body'=>json_decode(ob_get_clean(),true)]);});
 $api=file_get_contents(dirname(__DIR__).'/api_audio_download.php');$api=str_replace("require __DIR__.'/lib.php';",'',$api);$api=str_replace("require_once __DIR__.'/lib/audio_download.php';",'',$api);$api=str_replace("require_once __DIR__.'/lib/album_deletion.php';",'',$api);eval(substr($api,5));exit;
}
foreach(['disabled'=>403,'other'=>404,'own'=>200,'csrf'=>403,'permission'=>200] as $case=>$expected){$p=proc_open([PHP_BINARY,__FILE__,$case],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);foreach($pipes as $f)fclose($f);$exit=proc_close($p);$r=json_decode($out,true);if($exit||($r['code']??0)!==$expected)throw new RuntimeException($case.': '.$out.' '.$err);if($case==='permission'&&($r['body']['allowed']??true)!==false)throw new RuntimeException('Disabled by default');}
echo "PASS: download API denies disabled users, other owners and invalid CSRF; permission endpoint defaults off\n";
