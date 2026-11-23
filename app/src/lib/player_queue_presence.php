<?php
declare(strict_types=1);
/** Authenticated, per-player queue leases. A disconnected client gets 24h grace. */
function sfqp_schema(PDO $db):void {
 $db->exec("CREATE TABLE IF NOT EXISTS sf_player_queue_leases(user_id TEXT NOT NULL,client_id TEXT NOT NULL,source_album_id TEXT NOT NULL DEFAULT '',queue_hash TEXT NOT NULL,seq INTEGER NOT NULL,updated_at INTEGER NOT NULL,expires_at INTEGER NOT NULL,PRIMARY KEY(user_id,client_id));
 CREATE TABLE IF NOT EXISTS sf_player_queue_items(user_id TEXT NOT NULL,client_id TEXT NOT NULL,track_id TEXT NOT NULL,PRIMARY KEY(user_id,client_id,track_id));
 CREATE INDEX IF NOT EXISTS sf_queue_track ON sf_player_queue_items(track_id,user_id,client_id);
 CREATE TABLE IF NOT EXISTS sf_chart_retire_pending(album_id TEXT NOT NULL,track_id TEXT NOT NULL,created_at INTEGER NOT NULL,not_before INTEGER NOT NULL,PRIMARY KEY(album_id,track_id));");
}
function sfqp_prune(PDO $db):void {
 $db->prepare('DELETE FROM sf_player_queue_items WHERE NOT EXISTS(SELECT 1 FROM sf_player_queue_leases l WHERE l.user_id=sf_player_queue_items.user_id AND l.client_id=sf_player_queue_items.client_id AND l.expires_at>?)')->execute([time()]);
 // Keep closed-client tombstones for one day to reject late, out-of-order requests.
 $db->prepare('DELETE FROM sf_player_queue_leases WHERE expires_at<=? AND updated_at<?')->execute([time(),time()-86400]);
}
function sfqp_update(PDO $db,string $uid,array $input):array {
 $client=$input['client_id']??'';$seq=filter_var($input['seq']??null,FILTER_VALIDATE_INT);$action=$input['action']??'';
 if(!is_string($client)||!preg_match('/^[a-f0-9]{32}$/D',$client)||$seq===false||$seq<1||$seq>2147483647||!in_array($action,['heartbeat','release'],true))throw new InvalidArgumentException('Cola inválida.');
 $ids=[];$album=(string)($input['album_id']??'');if(!preg_match('/^[a-f0-9]{16}$/D',$album))$album='';
 if($action==='heartbeat'){$ids=json_decode((string)($input['tracks']??''),true,8,JSON_THROW_ON_ERROR);if(!is_array($ids)||!array_is_list($ids)||count($ids)>500)throw new InvalidArgumentException('Cola demasiado grande.');foreach($ids as $id)if(!is_string($id)||!preg_match('/^[a-f0-9]{16}$/D',$id))throw new InvalidArgumentException('Pista inválida.');$ids=array_values(array_unique($ids));sort($ids);}
 $db->exec('BEGIN IMMEDIATE');try{
  $q=$db->prepare('SELECT * FROM sf_player_queue_leases WHERE user_id=? AND client_id=?');$q->execute([$uid,$client]);$old=$q->fetch(PDO::FETCH_ASSOC);
  if($old&&(int)$old['seq']>=$seq){$db->exec('COMMIT');return ['ok'=>true,'stale'=>true];}
  if($ids){$q=$db->prepare('SELECT id FROM tracks WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).')');$q->execute($ids);$ids=$q->fetchAll(PDO::FETCH_COLUMN);sort($ids);}
  if(!$old&&$ids){$q=$db->prepare('SELECT COUNT(*) FROM sf_player_queue_leases WHERE user_id=? AND expires_at>?');$q->execute([$uid,time()]);if((int)$q->fetchColumn()>=10)throw new InvalidArgumentException('Hay demasiados reproductores abiertos en tu cuenta.');}
  $hash=hash('sha256',json_encode($ids));$now=time();$expires=$ids?$now+86400:0;
  $db->prepare('INSERT INTO sf_player_queue_leases VALUES(?,?,?,?,?,?,?) ON CONFLICT(user_id,client_id) DO UPDATE SET source_album_id=excluded.source_album_id,queue_hash=excluded.queue_hash,seq=excluded.seq,updated_at=excluded.updated_at,expires_at=excluded.expires_at')->execute([$uid,$client,$album,$hash,$seq,$now,$expires]);
  if(!$old||$old['queue_hash']!==$hash||(int)$old['expires_at']<=time()){$db->prepare('DELETE FROM sf_player_queue_items WHERE user_id=? AND client_id=?')->execute([$uid,$client]);$q=$db->prepare('INSERT INTO sf_player_queue_items VALUES(?,?,?)');foreach($ids as $id)$q->execute([$uid,$client,$id]);}
  $db->exec('COMMIT');return ['ok'=>true,'protected'=>count($ids),'expires_at'=>$expires];
 }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function sfqp_users(PDO $db,string $tid):array {
 $q=$db->prepare('SELECT DISTINCT l.user_id FROM sf_player_queue_items i JOIN sf_player_queue_leases l ON l.user_id=i.user_id AND l.client_id=i.client_id JOIN users u ON u.id=l.user_id WHERE i.track_id=? AND l.expires_at>? AND u.is_active=1');$q->execute([$tid,time()]);return $q->fetchAll(PDO::FETCH_COLUMN);
}
function sfqp_warn(PDO $db,array $album,array $users):void {
 if(!$users)return;require_once __DIR__.'/communication.php';scom_schema($db);
 $q=$db->prepare('SELECT last_week FROM sf_music_charts WHERE album_id=?');$q->execute([$album['id']]);$edition=(string)$q->fetchColumn();
 $key=hash('sha256','chart-retirement|'.$album['id'].'|'.$edition);$title='Un TOP de tu cola se va a renovar';
 $body='La selección «'.mb_substr((string)$album['title'],0,180).'» se va a renovar o retirar. Conservaremos sus pistas mientras estén en una cola activa. Si te gusta alguna, añádela a tus favoritos antes de quitarla de la cola o cerrar el reproductor; así se conservará en la biblioteca. Las pistas guardadas en otras colecciones o listas también se conservan.';
 $db->exec('BEGIN IMMEDIATE');try{
  $q=$db->prepare('SELECT id FROM sf_com_notices WHERE author=? AND request_key=?');$q->execute([LAB_PROTECTED_ADMIN_ID,$key]);$id=$q->fetchColumn();
  if(!$id){$id=bin2hex(random_bytes(16));$db->prepare('INSERT INTO sf_com_notices VALUES(?,?,?,?,?,NULL,?)')->execute([$id,LAB_PROTECTED_ADMIN_ID,$title,$body,time(),$key]);}
  $q=$db->prepare('INSERT OR IGNORE INTO sf_com_recipients(notice_id,user_id) VALUES(?,?)');foreach($users as $user)$q->execute([$id,(string)$user]);$db->exec('COMMIT');
 }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
/** Called while holding the catalogue lock, before touching any file or reference. */
function sfqp_defer_retirement(PDO $db,array $album,string $tid):bool {
 $users=sfqp_users($db,$tid);
 if($users){$db->prepare('INSERT OR IGNORE INTO sf_chart_retire_pending VALUES(?,?,?,?)')->execute([$album['id'],$tid,time(),time()+120]);sfqp_warn($db,$album,$users);return true;}
 $q=$db->prepare('SELECT not_before FROM sf_chart_retire_pending WHERE album_id=? AND track_id=?');$q->execute([$album['id'],$tid]);$notBefore=$q->fetchColumn();return $notBefore!==false&&(int)$notBefore>time();
}
