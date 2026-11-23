<?php
declare(strict_types=1);
require __DIR__.'/lib.php';lab_auth_require_login();require_once __DIR__.'/lib/audio_download.php';require_once __DIR__.'/lib/album_deletion.php';
header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');
try{
 $uid=(string)lab_auth_current_account()['id'];$method=$_SERVER['REQUEST_METHOD']??'GET';if(!in_array($method,['GET','POST'],true)){http_response_code(405);throw new RuntimeException('Método no permitido');}
 $allowed=sfd_allowed($uid);if($method==='GET'&&($_GET['action']??'')==='permission'){header('Content-Type: application/json');echo json_encode(['allowed'=>$allowed]);exit;}
 if(!$allowed){http_response_code(403);throw new RuntimeException('El administrador no ha autorizado descargas para esta cuenta');}
 $db=sfq_db();
 if($method==='POST'){
  lab_require_same_origin_post();if(!lab_auth_verify_csrf((string)($_POST['csrf_token']??''))){http_response_code(403);throw new RuntimeException('Sesión caducada');}
  $tid=(string)($_POST['track_id']??'');$mode=(string)($_POST['format']??'');if(!in_array($mode,['m4a','mp3'],true))throw new RuntimeException('Formato no válido');
  $found=sf_track_lookup($tid);if(!$found){http_response_code(404);throw new RuntimeException('Pista no encontrada');}if(sf_delreq_is_hidden_for_viewer((string)$found['album']['id'])){http_response_code(404);throw new RuntimeException('Pista no disponible');}sfd_manifest($found['track']);
  sfd_cleanup();$db->exec('BEGIN IMMEDIATE');try{
   $q=$db->prepare("SELECT id,payload FROM sf_processing_jobs WHERE user_id=? AND operation='download' AND state IN ('queued','running')");$q->execute([$uid]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$id=null;
   foreach($rows as $row){$p=json_decode($row['payload'],true);if(($p['track_id']??'')===$tid&&($p['format']??'')===$mode)$id=$row['id'];}
   if(!$id){if(count($rows)>=2)throw new RuntimeException('Ya tienes dos descargas en preparación. Espera a que terminen');$id=sfq_add($db,$uid,'conversion','download',(string)$found['album']['id'],(string)($found['track']['name']??'Pista'),['track_id'=>$tid,'format'=>$mode]);}
   $db->exec('COMMIT');
  }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
  header('Content-Type: application/json');echo json_encode(['job_id'=>$id]);exit;
 }
 $id=(string)($_GET['id']??'');if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new RuntimeException('Identificador no válido');
 $q=$db->prepare("SELECT state,stage,progress,error,result_json FROM sf_processing_jobs WHERE id=? AND user_id=? AND operation='download'");$q->execute([$id,$uid]);$job=$q->fetch(PDO::FETCH_ASSOC);if(!$job){http_response_code(404);throw new RuntimeException('Preparación no encontrada');}
 $result=json_decode((string)($job['result_json']??''),true)?:[];$ready=$job['state']==='done'&&($result['expires']??0)>time()&&in_array($result['format']??'',['m4a','mp3'],true);$file=$ready?sfd_root().'/'.$id.'.'.$result['format']:'';$ready=$ready&&is_file($file)&&!is_link($file);
 if(($_GET['action']??'')==='file'){
  if(!$ready){http_response_code(410);throw new RuntimeException('El archivo no está listo o ha caducado; vuelve a prepararlo');}
  $found=sf_track_lookup((string)($result['track_id']??''));if(!$found||sf_delreq_is_hidden_for_viewer((string)$found['album']['id'])){http_response_code(404);throw new RuntimeException('La pista ya no está disponible');}
  $name=preg_replace('~[\\\\/\x00-\x1f:*?"<>|]+~u','_', (string)($result['name']??'Pista'))?:'Pista';$name=mb_substr($name,0,160).'.'.$result['format'];
  session_write_close();header('Content-Type: '.($result['format']==='mp3'?'audio/mpeg':'audio/mp4'));header('Content-Disposition: attachment; filename="SparrowFy.'.$result['format'].'"; filename*=UTF-8\'\''.rawurlencode($name));header('Content-Length: '.filesize($file));
  require_once __DIR__.'/lib/usage.php';$sent=readfile($file);sf_usage_record($uid,$sent===false?0:$sent,0,'download');exit;
 }
 header('Content-Type: application/json');echo json_encode(['state'=>$job['state'],'stage'=>$job['stage'],'progress'=>(int)$job['progress'],'error'=>$job['state']==='failed'?'No se pudo preparar la descarga. Revisa la integridad de la pista o inténtalo más tarde.':null,'ready'=>$ready,'expired'=>$job['state']==='done'&&!$ready,'name'=>$result['name']??null]);
}catch(Throwable $e){if(http_response_code()<400)http_response_code(400);header('Content-Type: application/json');echo json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
