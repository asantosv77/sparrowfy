<?php
require __DIR__.'/lib/processing_queue.php';lab_auth_require_album_manager();header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit('{}');}
$stored=null;
try{
 $aid=(string)($_POST['album_id']??'');$tid=(string)($_POST['track_id']??'');foreach([$aid,$tid] as $id)if(!preg_match('/^[a-f0-9]{16}$/D',$id))throw new RuntimeException('Identificador inválido');
 $a=lab_load_album($aid);$track=null;foreach($a['tracks']??[] as $t)if($t['id']===$tid)$track=$t;if(!$track)throw new RuntimeException('La pista original no se encuentra en esa colección');if(($a['album_type']??'')==='online_links')throw new RuntimeException('Esta opción reemplaza audio almacenado');
 $payload=['replace_track_id'=>$tid,'expected_hls'=>$track['hls']];$cfg=lab_load_config();$operation='upload';
 if(!empty($_FILES['audio']['name'])){
  $f=$_FILES['audio'];if(($f['error']??1)!==UPLOAD_ERR_OK||!is_uploaded_file($f['tmp_name']))throw new RuntimeException('No se recibió correctamente el archivo');if($f['size']>(int)$cfg['max_upload_mib']*1048576)throw new RuntimeException('Archivo demasiado grande');
  $ext=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));if(!in_array($ext,lab_allowed_audio_extensions($cfg),true))throw new RuntimeException('Formato no permitido');
  $stored=sfq_dir().'/'.bin2hex(random_bytes(16)).'.upload';if(!move_uploaded_file($f['tmp_name'],$stored))throw new RuntimeException('No se pudo guardar la fuente');chmod($stored,0600);$payload+=['file'=>$stored,'filename'=>basename($f['name'])];
 }else{
  define('SFQ_YOUTUBE_FUNCTIONS_ONLY',true);require_once __DIR__.'/api_youtube.php';$operation='youtube';$url=yt_validate_url((string)($_POST['url']??''));$payload+=['url'=>$url,'filename'=>$track['name'],'format_id'=>''];
 }
 $u=lab_auth_current_account();if(!$u)throw new RuntimeException('Sesión caducada');$job=sfq_add(sfq_db(),(string)$u['id'],'conversion',$operation,$aid,'Reemplazar: '.$track['name'],$payload);$stored=null;http_response_code(202);echo json_encode(['job_id'=>$job,'queued'=>true]);
}catch(Throwable $e){if($stored)@unlink($stored);http_response_code(400);echo json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
