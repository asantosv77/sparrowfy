<?php
declare(strict_types=1);
require __DIR__.'/lib/processing_queue.php';lab_auth_require_album_manager();require_once __DIR__.'/lib/mornye_import.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
try{
 lab_require_same_origin_post();if($_SERVER['REQUEST_METHOD']!=='POST'||!lab_auth_verify_csrf((string)($_POST['csrf_token']??'')))throw new RuntimeException('Solicitud inválida. Recarga la página.');
 $user=lab_auth_current_account();$action=(string)($_POST['action']??'search');if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
 if($action==='search'){
  $lock=fopen(sfmn_dir().'/.search.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Hay una búsqueda Mornye en curso. Intenta de nuevo en unos segundos.');
  try{$items=sf_external_mornye_search((string)($_POST['query']??''));}finally{flock($lock,LOCK_UN);fclose($lock);}
  echo json_encode(['success'=>true,'items'=>$items],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;
 }
 if(!in_array($action,['replace','add'],true))throw new RuntimeException('Acción no permitida');
 $url=sf_external_mornye_url(trim((string)($_POST['url']??'')));$aid=(string)($_POST['album_id']??'');$payload=['url'=>$url];
 if($action==='replace'){
  $tid=(string)($_POST['track_id']??'');if(!preg_match('/^[a-f0-9]{16}$/D',$aid)||!preg_match('/^[a-f0-9]{16}$/D',$tid))throw new RuntimeException('Pista inválida');
  $a=lab_load_album($aid);$track=null;foreach($a['tracks']??[] as $t)if($t['id']===$tid)$track=$t;if(!$track||($a['album_type']??'')==='online_links')throw new RuntimeException('La pista no tiene audio reemplazable');
  $title=(string)$track['name'];$payload+=['replace_track_id'=>$tid,'expected_hls'=>$track['hls'],'filename'=>$title];
 }else{
  if($aid!==''){if(!preg_match('/^[a-f0-9]{16}$/D',$aid))throw new RuntimeException('Álbum inválido');$a=lab_load_album($aid);if(!$a||($a['album_type']??'')==='online_links')throw new RuntimeException('Álbum no disponible');}else{require_once __DIR__.'/lib/library_root.php';$a=sf_ensure_loose();$aid=$a['id'];}$title=trim((string)($_POST['title']??''));if($title===''||mb_strlen($title)>200||preg_match('/[\x00-\x1f\x7f]/',$title))throw new RuntimeException('Título inválido');$payload['filename']=$title;
 }
 if($action==='add'&&($_POST['duplicate_choice']??'')!=='create'){require_once __DIR__.'/lib/track_reuse.php';$matches=sf_reuse_search($title);if($matches){http_response_code(409);echo json_encode(['success'=>false,'needs_confirmation'=>true,'error'=>'Hay melodías similares. Revisa las coincidencias antes de añadir.','items'=>$matches],JSON_UNESCAPED_UNICODE);exit;}}
 require_once __DIR__.'/lib/mornye_requests.php';$db=sfq_db();sfmn_schema($db);$lock=sfmn_submission_lock();try{$local=($_POST['local']??'')==='1';if($local&&empty(lab_load_config()['webcodecs_enabled']))throw new RuntimeException('WebCodecs está deshabilitado. Recarga y usa procesamiento en servidor.');if($local&&preg_match('/Android|iPhone|iPad|iPod|Mobile/i',$_SERVER['HTTP_USER_AGENT']??''))throw new RuntimeException('Conversión local solo en escritorio');$result=sfmn_enqueue($db,$user,$aid,$url,$title,$local,$action==='replace'?$tid:'');}finally{flock($lock,LOCK_UN);fclose($lock);}http_response_code(202);echo json_encode(['success'=>true,'queued'=>true]+$result);
}catch(Throwable $e){if(isset($action)&&$action==='search')sfmn_event('search.failed',$e->getMessage());http_response_code(400);echo json_encode(['success'=>false,'error'=>$e->getMessage()],JSON_INVALID_UTF8_SUBSTITUTE);}
