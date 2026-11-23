<?php
require __DIR__.'/lib.php';lab_auth_require_album_manager();require __DIR__.'/lib/track_reuse.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
try{
 if(($_SERVER['REQUEST_METHOD']??'')==='GET'){
 if(($_GET['action']??'')==='memberships'){
 $tid=(string)($_GET['track_id']??'');$source=null;$albums=lab_list_albums();foreach($albums as $a)foreach($a['tracks']??[] as $t)if(($t['id']??'')===$tid){$source=$a;break 2;}
 if(!$source)throw new RuntimeException('Pista no encontrada. Actualiza la página');
 $q=sf_reuse_db()->prepare('SELECT album_id FROM sf_album_track_refs WHERE track_id=? AND source_album_id=?');$q->execute([$tid,$source['id']]);$selected=$q->fetchAll(PDO::FETCH_COLUMN);$selected[]=$source['id'];$items=[];
 foreach($albums as $a)if($a['id']!=='fffffffffffffffe' && (($a['album_type']??'')==='online_links')===(($source['album_type']??'')==='online_links'))$items[]=['id'=>$a['id'],'name'=>$a['title'],'selected'=>in_array($a['id'],$selected,true),'source'=>$source['id']];
 echo json_encode(['items'=>$items],JSON_UNESCAPED_UNICODE);exit;
 }
 if(($_GET['action']??'')==='albums'){$items=[];foreach(lab_list_albums() as $a)if($a['id']!=='fffffffffffffffe')$items[]=['id'=>$a['id'],'title'=>$a['title']];echo json_encode(['items'=>$items]);}
 else echo json_encode(['items'=>sf_reuse_search((string)($_GET['q']??''),(float)($_GET['duration']??0))],JSON_UNESCAPED_UNICODE);exit;
 }
 if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);throw new RuntimeException('POST requerido');}
 lab_require_same_origin_post();if(!lab_auth_verify_csrf((string)($_POST['csrf_token']??''))){http_response_code(403);throw new RuntimeException('Sesión caducada. Recarga la página');}
 $action=$_POST['action']??'';$target=(string)($_POST['target']??'');$tid=(string)($_POST['track_id']??'');
 if($action==='attach'){$added=sf_reuse_attach((string)($_POST['source']??''),$tid,$target);echo json_encode(['success'=>true,'already_exists'=>!$added,'message'=>$added?'Canción añadida a la colección.':'Esta melodía ya existe en este álbum o colección. No se agregó de nuevo.'],JSON_UNESCAPED_UNICODE);exit;}
 elseif($action==='detach'){$lock=sf_reuse_lock();try{sf_reuse_remove_locked($target,$tid);}finally{flock($lock,LOCK_UN);fclose($lock);}}
 else throw new RuntimeException('Acción no disponible');
 echo json_encode(['success'=>true]);
}catch(Throwable $e){if(http_response_code()<400)http_response_code(400);echo json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}



