<?php
declare(strict_types=1);
require __DIR__.'/lib/processing_queue.php';
lab_auth_require_album_manager();
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
try{
 lab_require_same_origin_post();
 if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||!lab_auth_verify_csrf((string)($_POST['csrf_token']??'')))throw new RuntimeException('Solicitud no válida. Recarga la página.');
 require_once __DIR__.'/lib/library_root.php';
 define('SFQ_YOUTUBE_FUNCTIONS_ONLY',true);require_once __DIR__.'/api_youtube.php';
 $url=yt_validate_url((string)($_POST['url']??''));$aid=(string)($_POST['album_id']??'');$a=preg_match('/^[a-f0-9]{16}$/D',$aid)?lab_load_album($aid):sf_ensure_loose();if(!$a)throw new RuntimeException('Álbum no encontrado');$u=lab_auth_current_account();
 $name=trim((string)($_POST['title']??''));if($name==='')$name='Pista de YouTube';$name=mb_substr($name,0,200);
 if(($_POST['local']??'')==='1'){if(empty(lab_load_config()['webcodecs_enabled']))throw new RuntimeException('WebCodecs está deshabilitado. Recarga y usa procesamiento en servidor.');if(preg_match('/Android|iPhone|iPad|iPod|Mobile/i',(string)($_SERVER['HTTP_USER_AGENT']??'')))throw new RuntimeException('La conversión local solo está disponible en escritorio');require_once __DIR__.'/lib/client_processing.php';$sid=bin2hex(random_bytes(16));$dir=sfwc_root().'/'.$sid;mkdir($dir,02770);$m=['id'=>$sid,'user'=>(string)$u['id'],'album_id'=>$a['id'],'filename'=>$name,'state'=>'source_queued','created'=>time(),'updated'=>time()];sfwc_save($dir,$m);$payload=['url'=>$url,'filename'=>$name,'format_id'=>(string)($_POST['format_id']??''),'client_source'=>$sid];$cookies=yt_cookie_temp();if($cookies){$keep=sfq_dir().'/'.bin2hex(random_bytes(16)).'.cookies';if(!@copy($cookies,$keep)){@unlink($cookies);throw new RuntimeException('No se pudieron guardar las cookies para la conversión en cola.');}@chmod($keep,0600);@unlink($cookies);$payload['cookies']=$keep;}$job=sfq_add(sfq_db(),(string)$u['id'],'conversion','youtube',$a['id'],$name,$payload);$m['job_id']=$job;sfwc_save($dir,$m);http_response_code(202);echo json_encode(['id'=>$sid,'job_id'=>$job,'album_id'=>$a['id'],'client_source'=>true]);exit;}
 $id=sfq_add(sfq_db(),(string)$u['id'],'conversion','youtube',$a['id'],$name,['url'=>$url,'format_id'=>'']+($name==='Pista de YouTube'?[]:['filename'=>$name]));
 http_response_code(202);echo json_encode(['job_id'=>$id,'queued'=>true,'album_id'=>$a['id']]);
}catch(Throwable $e){http_response_code(400);echo json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
