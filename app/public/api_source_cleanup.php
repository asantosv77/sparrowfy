<?php
declare(strict_types=1);
require __DIR__.'/lib/processing_queue.php';lab_auth_require_admin();require_once __DIR__.'/lib/source_cleanup.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, private');
try{
 if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new RuntimeException('Método no permitido');lab_require_same_origin_post();
 if(!lab_auth_verify_csrf((string)($_POST['csrf_token']??'')))throw new RuntimeException('Sesión caducada. Recarga antes de continuar.');
 $user=lab_auth_current_account();session_write_close();set_time_limit(120);
 $dir=LAB_DATA.'/source-cleanup';if(!is_dir($dir)&&!mkdir($dir,02770,true)&&!is_dir($dir))throw new RuntimeException('No se pudo preparar la limpieza');
 if(!is_file($dir.'/.htaccess'))file_put_contents($dir.'/.htaccess',"Require all denied\n");
 $lock=fopen($dir.'/.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Hay otra revisión o limpieza en curso. Intenta de nuevo al terminar.');
 try{
  $db=sfq_db();$action=(string)($_POST['action']??'preview');
  if($action==='preview'){
   foreach(glob($dir.'/*.json')?:[] as $old)if(!is_link($old)&&filemtime($old)<time()-86400)@unlink($old);
   $scan=sfsc_scan($db);$token=bin2hex(random_bytes(16));$plan=['user'=>$user['id'],'expires'=>time()+600,'files'=>$scan['files']];
   $json=json_encode($plan,JSON_THROW_ON_ERROR);if(file_put_contents($dir.'/'.$token.'.json',$json,LOCK_EX)!==strlen($json))throw new RuntimeException('No se pudo conservar la revisión');chmod($dir.'/'.$token.'.json',0660);unset($scan['files']);$result=['success'=>true,'token'=>$token]+$scan;
  }elseif($action==='delete'){
   $token=(string)($_POST['token']??'');if(!preg_match('/^[a-f0-9]{32}$/D',$token)||($_POST['confirm']??'')!=='delete_sources')throw new RuntimeException('Confirma la eliminación desde la revisión');
   $path=$dir.'/'.$token.'.json';$plan=!is_link($path)?json_decode((string)@file_get_contents($path),true):null;
   if(!is_array($plan)||($plan['user']??'')!==$user['id']||($plan['expires']??0)<time())throw new RuntimeException('La revisión ha caducado. Revisa de nuevo los originales antes de eliminar.');
   if(isset($plan['result']))$result=$plan['result'];else{
    $result=['success'=>true]+sfsc_execute($db,$plan['files']);$plan['result']=$result;$plan['files']=[];
    $tmp=$path.'.tmp';if(file_put_contents($tmp,json_encode($plan,JSON_THROW_ON_ERROR),LOCK_EX)===false||!rename($tmp,$path))throw new RuntimeException('Limpieza aplicada; no se pudo guardar el resumen. Revisa de nuevo antes de repetir.');
    if(function_exists('sf_media_event'))sf_media_event('source.cleanup.completed',['files'=>$result['deleted'],'bytes'=>$result['bytes']]);
   }
  }else throw new RuntimeException('Acción no permitida');
 }finally{flock($lock,LOCK_UN);fclose($lock);}
 echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(Throwable $e){http_response_code(400);echo json_encode(['success'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);}
