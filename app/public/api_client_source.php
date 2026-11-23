<?php
declare(strict_types=1);require __DIR__.'/lib/client_processing.php';lab_auth_require_album_manager();header('Cache-Control: no-store, private');
$lock=null;
try{
 if(!in_array($_SERVER['REQUEST_METHOD']??'',['GET','POST'],true))throw new RuntimeException('Método no permitido');$post=($_SERVER['REQUEST_METHOD']??'')==='POST';if($post){lab_require_same_origin_post();if(!lab_auth_verify_csrf((string)($_POST['csrf_token']??'')))throw new RuntimeException('Sesión caducada');}$id=(string)(($post?$_POST:$_GET)['id']??'');if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new RuntimeException('Identificador inválido');$dir=sfwc_root().'/'.$id;if(!is_file($dir.'/state.json'))throw new RuntimeException('La fuente expiró');
 $lock=fopen($dir.'/.lock','c');if(!$lock||!flock($lock,LOCK_SH))throw new RuntimeException('Fuente ocupada');$m=json_decode((string)file_get_contents($dir.'/state.json'),true);
 if(($m['user']??'')!==(string)lab_auth_current_account()['id']){http_response_code(403);throw new RuntimeException('Acceso denegado');}
 $name=(string)($m['source_file']??'');if(($m['state']??'')!=='source_ready'||!preg_match('/^source\.(m4a|webm|opus|mp3|ogg|aac|mp4|flac|wav|aiff|alac)$/D',$name))throw new RuntimeException('La descarga todavía no está lista');$path=$dir.'/'.$name;if(!is_file($path)||is_link($path))throw new RuntimeException('Fuente no disponible');
 if($post&&($_POST['action']??'')==='preflight'){
  $caps=json_decode((string)($_POST['capabilities']??'[]'),true);
  if(!is_array($caps)||count($caps)>16)throw new RuntimeException('Capacidades inválidas');
  $cfg=lab_load_config();$supported=true;$reason='';$rates=[];
  if(empty($cfg['webcodecs_enabled'])){header('Content-Type: application/json');echo json_encode(['local_supported'=>false,'rates'=>[],'reason'=>'WebCodecs está deshabilitado por el administrador.'],JSON_UNESCAPED_UNICODE);exit;}
  try{$rates=sfwc_pick_rates((int)($m['source_kbps']??0),$cfg,$caps,(string)($m['source_codec']??''));}
  catch(RuntimeException $e){$supported=false;$reason=$e->getMessage();}
  header('Content-Type: application/json');
  echo json_encode(['local_supported'=>$supported,'rates'=>$rates,'reason'=>$reason],JSON_UNESCAPED_UNICODE);exit;
 }
 if($post){
 flock($lock,LOCK_UN);if(!flock($lock,LOCK_EX))throw new RuntimeException('Fuente ocupada');$m=json_decode((string)file_get_contents($dir.'/state.json'),true);
 if(($_POST['recovery_reason']??'')==='client_transfer_failed'&&!empty($m['mornye_parent'])){require_once __DIR__.'/lib/mornye_control.php';sfmn_event('client.transfer.recovered.'.$m['mornye_parent'],'La fuente Mornye se descargó correctamente, pero falló su transferencia a la PC tras dos intentos. Se continúa la conversión en el servidor sin volver a descargar.');}
 if(empty($m['import_job_id'])){
 // The reviewed name is authoritative; the source path/extension stay server-owned.
 $requested=trim((string)($_POST['filename']??''));
 if($requested!==''){require_once __DIR__.'/lib/external_youtube.php';$m['filename']=yt_safe_filename($requested,pathinfo($name,PATHINFO_EXTENSION));}
 $payload=['filename'=>$m['filename']];foreach(['source_url','source_service'] as $field)if(!empty($m[$field]))$payload[$field]=$m[$field];if(!empty($m['mornye_parent']))$payload['mornye_parent']=$m['mornye_parent'];if(!empty($m['mornye_url']))$payload['mornye_url']=$m['mornye_url'];$tid=(string)($_POST['replace_track_id']??'');if($tid!==''){$target=null;foreach(lab_load_album($m['album_id'])['tracks']??[] as $t)if(($t['id']??'')===$tid)$target=$t;if(!$target)throw new RuntimeException('Pista a reemplazar no encontrada');$payload+=['replace_track_id'=>$tid,'expected_hls'=>$target['hls']];}$stored=sfq_dir().'/'.bin2hex(random_bytes(16)).'.upload';if(!copy($path,$stored))throw new RuntimeException('No se pudo preparar el archivo');chmod($stored,0600);$payload['file']=$stored;try{$m['import_job_id']=sfq_add(sfq_db(),$m['user'],'conversion','upload',$m['album_id'],$m['filename'],$payload);sfwc_save($dir,$m);}catch(Throwable $e){unlink($stored);throw $e;}}
 header('Content-Type: application/json');echo json_encode(['queued'=>true,'job_id'=>$m['import_job_id'],'album_id'=>$m['album_id']]);exit;
 }
 session_write_close();header('Content-Type: application/octet-stream');header('X-Content-Type-Options: nosniff');header('Content-Disposition: attachment; filename="'.$name.'"');header('Content-Length: '.filesize($path));$f=fopen($path,'rb');while(!feof($f)){echo fread($f,1048576);if(connection_aborted())break;}fclose($f);
}catch(Throwable $e){if(!headers_sent()){if(http_response_code()<400)http_response_code(400);header('Content-Type: application/json');echo json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}}
finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
