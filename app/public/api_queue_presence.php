<?php
declare(strict_types=1);
require __DIR__.'/lib.php';require_once __DIR__.'/lib/track_reuse.php';require_once __DIR__.'/lib/player_queue_presence.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, private');
$lock=null;
try{
 if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);exit('{}');}lab_require_same_origin_post();
 $account=lab_auth_current_account();$uid=(string)$account['id'];if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
 $db=lab_auth_pdo();sfqp_schema($db);$lock=sf_reuse_lock();$result=sfqp_update($db,$uid,$_POST);
 $q=$db->prepare('SELECT DISTINCT p.album_id FROM sf_chart_retire_pending p JOIN sf_player_queue_items i ON i.track_id=p.track_id WHERE i.user_id=? AND i.client_id=? LIMIT 10');$q->execute([$uid,(string)($_POST['client_id']??'')]);$result['retiring_albums']=$q->fetchAll(PDO::FETCH_COLUMN);
 echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(InvalidArgumentException|JsonException $e){http_response_code(400);echo json_encode(['error'=>'Cola inválida.']);}
catch(Throwable $e){http_response_code(503);echo json_encode(['error'=>'No se pudo confirmar la protección de la cola.']);}
finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
