<?php
declare(strict_types=1);require __DIR__.'/lib/client_processing.php';lab_auth_require_album_manager();header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
$lock=null;
try{
 if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new RuntimeException('POST requerido');lab_require_same_origin_post();if(!lab_auth_verify_csrf((string)($_POST['csrf_token']??'')))throw new RuntimeException('Sesión caducada. Recarga la página.');
 $user=(string)lab_auth_current_account()['id'];$action=(string)($_POST['action']??'');$root=sfwc_root();file_put_contents($root.'/.htaccess',"Require all denied\n");$cfg=lab_load_config();
 if($action==='init'){if(empty($cfg['webcodecs_enabled']))throw new RuntimeException('WebCodecs está deshabilitado por el administrador. Usa procesamiento en servidor.');if(preg_match('/Android|iPhone|iPad|iPod|Mobile/i',(string)($_SERVER['HTTP_USER_AGENT']??'')))throw new RuntimeException('El procesamiento local solo está disponible en escritorio');
  sfwc_cleanup();$album=(string)($_POST['album_id']??'');if(!preg_match('/^[a-f0-9]{16}$/D',$album)||!lab_load_album($album))throw new RuntimeException('Álbum inválido');
  $sourceId=(string)($_POST['source_id']??'');$youtubeSource=false;if(preg_match('/^[a-f0-9]{32}$/D',$sourceId)){$origin=json_decode((string)@file_get_contents($root.'/'.$sourceId.'/state.json'),true);$youtubeSource=($origin['user']??'')===$user&&($origin['state']??'')==='source_ready';}
  $filename=basename((string)($_POST['filename']??''));$ext=strtolower(pathinfo($filename,PATHINFO_EXTENSION));if(!($youtubeSource&&in_array($ext,['m4a','webm','opus','mp3','ogg','aac','mp4'],true))&&!in_array($ext,lab_allowed_audio_extensions($cfg),true))throw new RuntimeException('Formato no permitido');
  // La ruta local sube AAC en bloques de 1 MiB: el tamano declarado puede
  // superar max_upload_mib (limite de subida completa) sin afectar al servidor.
  $duration=(float)($_POST['duration']??0);$size=(int)($_POST['size']??0);if(!is_finite($duration)||$duration<=0||$duration>1800||$size<=0||(($youtubeSource||$ext!=='mp3')&&$size>336*1048576))throw new RuntimeException('Usa el servidor para este tamaño o duración');
  $replacement=[];if($youtubeSource){foreach(['source_url','source_service'] as $field)if(!empty($origin[$field]))$replacement[$field]=$origin[$field];}if($youtubeSource&&!empty($origin['mornye_parent']))$replacement['mornye_parent']=$origin['mornye_parent'];if($youtubeSource&&!empty($origin['mornye_url']))$replacement['mornye_url']=$origin['mornye_url'];$tid=(string)($_POST['replace_track_id']??'');if($tid!==''){$target=null;foreach(lab_load_album($album)['tracks']??[] as $t)if(($t['id']??'')===$tid)$target=$t;if(!$target)throw new RuntimeException('Pista a reemplazar no encontrada');$replacement+=['replace_track_id'=>$tid,'expected_hls'=>$target['hls']];}
  $active=0;foreach(glob($root.'/*/state.json')?:[] as $f){$v=json_decode((string)file_get_contents($f),true);if(($v['user']??'')===$user&&empty($v['job_id']))$active++;}if($active>=8)throw new RuntimeException('Hay ocho subidas locales pendientes. Reanuda o descarta alguna.');
  $source=max(32,min(320,(int)($size*8/$duration/1000)));$lossless=in_array($ext,['flac','wav','aiff','alac'],true);if($lossless)$source=320;
  $sourceCodec=$lossless?'flac':'';if($youtubeSource){$src=$root.'/'.$sourceId.'/'.basename($origin['source_file']??'missing');if(!is_file($src)||is_link($src))throw new RuntimeException('Fuente original no disponible');[, $ffprobe]=lab_ffmpeg();$verified=lab_probe_audio($src,$ffprobe);if(empty($verified['ok']))throw new RuntimeException('No se pudo verificar la fuente original');$source=(int)$verified['kbps'];$sourceCodec=(string)$verified['codec'];}
  $capabilities=json_decode((string)($_POST['capabilities']??'[]'),true);if(!is_array($capabilities))throw new RuntimeException('Capacidades inválidas');$rates=sfwc_pick_rates($source,$cfg,$capabilities,$sourceCodec);
  if(!$rates)throw new RuntimeException('No hay calidades compatibles');
  $id=bin2hex(random_bytes(16));$dir=$root.'/'.$id;mkdir($dir,02770);$m=['id'=>$id,'user'=>$user,'album_id'=>$album,'filename'=>mb_substr($filename,0,220),'duration'=>$duration,'source_kbps'=>$source,'source_codec'=>$sourceCodec,'source_id'=>$sourceId,'rates'=>$rates,'chunks'=>[],'bytes'=>0,'state'=>'encoding','created'=>time(),'updated'=>time(),'replacement'=>$replacement];sfwc_save($dir,$m);sf_control_event('client.started',['session_id'=>$id,'user_id'=>$user,'album_id'=>$album]);
 }else{
  $id=(string)($_POST['id']??'');if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new RuntimeException('Sesión inválida');$dir=$root.'/'.$id;if(!is_file($dir.'/state.json'))throw new RuntimeException('La subida expiró o fue descartada');
  $lock=fopen($dir.'/.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Subida ocupada');$m=json_decode((string)file_get_contents($dir.'/state.json'),true);if(($m['user']??'')!==$user)throw new RuntimeException('Acceso denegado');
  if($action==='chunk'){
   if(!empty($m['job_id']))throw new RuntimeException('La subida ya fue publicada en cola');$rate=(int)($_POST['rate']??0);$index=(int)($_POST['index']??-1);$f=$_FILES['chunk']??[];
   if(!in_array($rate,$m['rates'],true)||$index<0||$index>511||($f['error']??1)!==0||($f['size']??0)<1||$f['size']>1048576||!is_uploaded_file($f['tmp_name']))throw new RuntimeException('Bloque inválido');
   $hash=hash_file('sha256',$f['tmp_name']);$parts=$m['chunks'][(string)$rate]??[];
   if(isset($parts[$index])){if(!hash_equals($parts[$index],$hash))throw new RuntimeException('El archivo cambió; descarta esta sesión');}
   else{if($index!==count($parts)||$m['bytes']+$f['size']>268435456)throw new RuntimeException('Orden o tamaño de subida inválido');if(!move_uploaded_file($f['tmp_name'],$dir.'/'.$rate.'_'.$index.'.chunk'))throw new RuntimeException('No se pudo guardar el bloque');$m['chunks'][(string)$rate][$index]=$hash;$m['bytes']+=$f['size'];$m['state']='uploading';$m['updated']=time();sfwc_save($dir,$m);}
  }elseif($action==='finish'){
   $expected=json_decode((string)($_POST['counts']??'{}'),true);foreach($m['rates'] as $rate)if(empty($m['chunks'][(string)$rate])||count($m['chunks'][(string)$rate])!==($expected[(string)$rate]??0))throw new RuntimeException('Faltan bloques o calidades');
   if(empty($m['job_id'])){
    $db=sfq_db();$q=$db->prepare("SELECT id FROM sf_processing_jobs WHERE operation='client_hls' AND json_extract(payload,'$.client_session')=? LIMIT 1");$q->execute([$id]);$job=$q->fetchColumn();
    $m['job_id']=$job?:sfq_add($db,$user,'conversion','client_hls',$m['album_id'],$m['filename'],['client_session'=>$id,'filename'=>$m['filename']]+($m['replacement']??[]));$m['state']='queued';$m['updated']=time();sfwc_save($dir,$m);
   }
  }elseif($action==='interrupted'){if(empty($m['job_id'])){$m['state']='interrupted';$m['updated']=time();sfwc_save($dir,$m);sf_control_event('client.interrupted',['session_id'=>$id,'user_id'=>$user]);}}elseif($action==='discard'){if(!empty($m['job_id'])){$q=sfq_db()->prepare('SELECT state FROM sf_processing_jobs WHERE id=?');$q->execute([$m['job_id']]);if(in_array($q->fetchColumn(),['queued','running'],true))throw new RuntimeException('La tarea está activa; espera antes de descartar la fuente');}sfwc_remove($dir);echo json_encode(['discarded'=>true]);exit;}
  elseif($action!=='status')throw new RuntimeException('Acción inválida');
 }
 echo json_encode(['success'=>true,'id'=>$m['id'],'rates'=>$m['rates'],'chunks'=>$m['chunks'],'state'=>$m['state'],'job_id'=>$m['job_id']??null,'queued'=>!empty($m['job_id'])]);
}catch(Throwable $e){sf_control_error('client.processing.error',$e);http_response_code(400);echo json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
