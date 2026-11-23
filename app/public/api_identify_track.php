<?php
require __DIR__.'/lib.php';$automatic=($_POST['automatic']??'')==='1';if(!$automatic)lab_auth_require_album_manager();header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit('{}');}
$csrf=(string)($_SERVER['HTTP_X_CSRF_TOKEN']??($_POST['csrf_token']??''));
if(!lab_auth_verify_csrf($csrf)){http_response_code(403);echo json_encode(['error'=>'Solicitud no válida. Recarga la página e inténtalo de nuevo.'],JSON_UNESCAPED_UNICODE);exit;}
require_once __DIR__.'/lib/acoustid_health.php';
try{
 $key=trim((string)(lab_load_config()['acoustid_client_key']??''));if($key==='')throw new RuntimeException('Configura la clave de aplicación de AcoustID.');
 $health=sf_acoustid_health();
 if(($health['state']??'')==='unavailable' && ($health['retry_after']??0)>time() && hash_equals((string)($health['key_stamp']??''),hash('sha256',$key))){echo json_encode(['skipped'=>true,'candidates'=>[],'warning'=>sf_acoustid_skip_message()],JSON_UNESCAPED_UNICODE);exit;}
 $tid=(string)($_POST['track_id']??'');if(!preg_match('/^[a-f0-9]{16}$/D',$tid))throw new RuntimeException('Pista inválida');
 $albumId='fffffffffffffffe';
 {$q=lab_auth_pdo()->prepare('SELECT album_id FROM tracks WHERE id=?');$q->execute([$tid]);$albumId=(string)$q->fetchColumn();$q->closeCursor();}
 $a=lab_load_album($albumId);$track=null;foreach($a['tracks']??[] as $t)if($t['id']===$tid)$track=$t;if(!$track)throw new RuntimeException('No se encontró la pista en el catálogo');
 $file=realpath(__DIR__.'/'.($track['hls']??''));$root=realpath(LAB_HLS);if(!$file||!$root||!str_starts_with($file,$root.DIRECTORY_SEPARATOR))throw new RuntimeException('Audio no disponible');
 if((float)($track['duration']??0)<=0)throw new RuntimeException('La duración del audio no está disponible');
 $cacheDir=LAB_DATA.'/acoustid-cache';if(!is_dir($cacheDir))@mkdir($cacheDir,02770,true);
 $cacheFile=$cacheDir.'/'.$tid.'.json';$stamp=hash('sha256',$file.':'.filemtime($file));
 $cached=json_decode((string)@file_get_contents($cacheFile),true);
 if($automatic && is_array($cached) && ($cached['stamp']??'')===$stamp && ($cached['expires']??0)>time()){echo json_encode($cached['data'],JSON_UNESCAPED_UNICODE);exit;}
 // Automatic (background) identification is a convenience for any signed-in listener,
 // so keep it cheap and bounded: short cooldown plus an hourly per-account cap.
 if($automatic){
  $account=lab_auth_current_account();if(!is_array($account))throw new RuntimeException('Sesión caducada');
  $throttleFile=$cacheDir.'/.auto-'.hash('sha256',(string)$account['id']).'.json';
  $throttle=json_decode((string)@file_get_contents($throttleFile),true);
  $throttleNow=time();$throttleLast=is_array($throttle)?(int)($throttle['last']??0):0;
  $throttleCount=(is_array($throttle)&&($throttleNow-(int)($throttle['since']??0)<3600))?(int)($throttle['count']??0):0;
  if($throttleNow-$throttleLast<15)throw new RuntimeException('La identificación automática está limitada; espera unos segundos.');
  if($throttleCount>=120)throw new RuntimeException('Límite temporal de identificaciones automáticas alcanzado.');
  @file_put_contents($throttleFile,json_encode(['since'=>$throttleCount===0?$throttleNow:(int)($throttle['since']??0),'count'=>$throttleCount+1,'last'=>$throttleNow]),LOCK_EX);@chmod($throttleFile,0660);
 }
 $lock=fopen(LAB_DATA.'/.acoustid.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Hay otra identificación en curso. Intenta al terminar.');
 session_write_close();
 set_time_limit(65);
 try{
  $proc=proc_open(['python3',__DIR__.'/scripts/acoustid_lookup.py'],[0=>['pipe','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes,__DIR__);
  if(!is_resource($proc))throw new RuntimeException('No se pudo iniciar el análisis');
  fwrite($pipes[0],json_encode(['key'=>$key,'file'=>$file,'duration'=>(float)$track['duration']]));fclose($pipes[0]);$output=stream_get_contents($pipes[1]);fclose($pipes[1]);$code=proc_close($proc);$data=json_decode($output,true);
  if($code!==0||!is_array($data)){
   if(($data['component']??'local')==='service')sf_acoustid_health_set(false,$key);
   require_once __DIR__.'/lib/control_log.php';sf_control_event('acoustid.failed',['track_id'=>$tid,'component'=>$data['component']??'local','exit_code'=>$code]);
    echo json_encode(['skipped'=>true,'candidates'=>[],'warning'=>($data['component']??'local')==='service'?sf_acoustid_skip_message():'No se pudo generar la huella acústica local. El audio se conserva; puedes reintentar o editar sus metadatos manualmente.'],JSON_UNESCAPED_UNICODE);return;
  }
  sf_acoustid_health_set(true,$key);
  $temp=$cacheFile.'.tmp';if(@file_put_contents($temp,json_encode(['stamp'=>$stamp,'expires'=>time()+(empty($data['candidates'])?86400:2592000),'data'=>$data]),LOCK_EX)!==false){@chmod($temp,0660);@rename($temp,$cacheFile);}
  echo json_encode($data,JSON_UNESCAPED_UNICODE);
 }finally{flock($lock,LOCK_UN);fclose($lock);}
}catch(Throwable $e){http_response_code(400);echo json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
