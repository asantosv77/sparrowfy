<?php
declare(strict_types=1);
function sf_devices_schema(PDO $p):void{
 static $ready=false;if($ready)return;
 $p->exec('CREATE TABLE IF NOT EXISTS sf_device_epochs(user_id TEXT PRIMARY KEY,epoch INTEGER NOT NULL DEFAULT 0)');
 $p->exec('CREATE TABLE IF NOT EXISTS sf_devices(id TEXT PRIMARY KEY,user_id TEXT NOT NULL,epoch INTEGER NOT NULL,credential_stamp TEXT NOT NULL,created_at INTEGER NOT NULL,last_seen INTEGER NOT NULL,first_ip TEXT NOT NULL,last_ip TEXT NOT NULL,user_agent TEXT NOT NULL,revoked_at INTEGER)');
 $p->exec('CREATE INDEX IF NOT EXISTS sf_devices_user ON sf_devices(user_id,last_seen)');
 $p->exec('CREATE TABLE IF NOT EXISTS sf_device_remember(selector TEXT PRIMARY KEY,device_id TEXT NOT NULL)');$ready=true;
}
function sf_device_epoch(PDO $p,string $uid):int{
 $q=$p->prepare('SELECT epoch FROM sf_device_epochs WHERE user_id=?');$q->execute([$uid]);return (int)$q->fetchColumn();
}
function sf_device_client():array{
 // Only the peer verified by the web server; never trust arbitrary forwarding headers.
 $ip=(string)($_SERVER['REMOTE_ADDR']??'');if(!filter_var($ip,FILTER_VALIDATE_IP))$ip='';
 return [$ip,mb_substr((string)($_SERVER['HTTP_USER_AGENT']??'Cliente no identificado'),0,400)];
}
function sf_device_create(PDO $p,string $uid):array{
 sf_devices_schema($p);$q=$p->prepare('SELECT password_hash FROM users WHERE id=? AND is_active=1');$q->execute([$uid]);$hash=$q->fetchColumn();$q->closeCursor();if(!is_string($hash))throw new RuntimeException('Cuenta no disponible');
 [$ip,$ua]=sf_device_client();$r=['id'=>bin2hex(random_bytes(16)),'epoch'=>sf_device_epoch($p,$uid)];$now=time();
 $p->prepare('INSERT INTO sf_devices(id,user_id,epoch,credential_stamp,created_at,last_seen,first_ip,last_ip,user_agent) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$r['id'],$uid,$r['epoch'],hash('sha256',$hash),$now,$now,$ip,$ip,$ua]);return $r;
}
function sf_device_login(string $uid):void{
 $p=lab_auth_pdo();$r=sf_device_create($p,$uid);$_SESSION['device_id']=$r['id'];$_SESSION['device_epoch']=$r['epoch'];$_SESSION['device_touch_at']=time();
}
function sf_device_check(PDO $p,array $account):bool{
 sf_devices_schema($p);$uid=(string)$account['id'];$epoch=sf_device_epoch($p,$uid);
 if((int)($_SESSION['device_epoch']??0)!==$epoch)return false;
 if(empty($_SESSION['device_id'])){
  $r=sf_device_create($p,$uid);$_SESSION['device_id']=$r['id'];$_SESSION['device_epoch']=$r['epoch'];
 }
 $q=$p->prepare('SELECT revoked_at,epoch,credential_stamp FROM sf_devices WHERE id=? AND user_id=?');$q->execute([(string)$_SESSION['device_id'],$uid]);$r=$q->fetch();$q->closeCursor();
 if(!$r||$r['revoked_at']!==null||(int)$r['epoch']!==$epoch||!hash_equals((string)$r['credential_stamp'],hash('sha256',(string)$account['password_hash'])))return false;
 if(time()-(int)($_SESSION['device_touch_at']??0)>=60){
  [$ip,$ua]=sf_device_client();$p->prepare('UPDATE sf_devices SET last_seen=?,last_ip=?,user_agent=? WHERE id=? AND revoked_at IS NULL')->execute([time(),$ip,$ua,$_SESSION['device_id']]);$_SESSION['device_touch_at']=time();
 }
 return true;
}
function sf_device_bind(PDO $p,string $selector,string $device):void{
 $p->prepare('INSERT INTO sf_device_remember(selector,device_id) VALUES(?,?) ON CONFLICT(selector) DO UPDATE SET device_id=excluded.device_id')->execute([$selector,$device]);
}
function sf_device_remember(PDO $p,string $selector,string $uid,string $stamp):?array{
 $q=$p->prepare('SELECT d.* FROM sf_device_remember r JOIN sf_devices d ON d.id=r.device_id WHERE r.selector=? AND d.user_id=?');$q->execute([$selector,$uid]);$r=$q->fetch();$q->closeCursor();
 if(!$r){$r=sf_device_create($p,$uid);sf_device_bind($p,$selector,$r['id']);return $r;}
 if($r['revoked_at']!==null||(int)$r['epoch']!==sf_device_epoch($p,$uid)||!hash_equals($stamp,$r['credential_stamp']))return null;
 return $r;
}
function sf_device_revoke(PDO $p,string $actorUid,bool $admin,string $uid,?string $device=null):void{
 if(!$admin&&!hash_equals($actorUid,$uid))throw new RuntimeException('Sin permiso para esta cuenta');
 sf_devices_schema($p);$p->beginTransaction();try{
  if($device!==null){
   $q=$p->prepare('SELECT id FROM sf_devices WHERE id=? AND user_id=?');$q->execute([$device,$uid]);if(!$q->fetchColumn())throw new RuntimeException('Acceso no encontrado');
   $p->prepare('UPDATE sf_devices SET revoked_at=? WHERE id=? AND user_id=?')->execute([time(),$device,$uid]);
   $p->prepare('DELETE FROM sf_remember_tokens WHERE user_id=? AND selector IN (SELECT selector FROM sf_device_remember WHERE device_id=?)')->execute([$uid,$device]);
  }else{
   $p->prepare('INSERT INTO sf_device_epochs(user_id,epoch) VALUES(?,1) ON CONFLICT(user_id) DO UPDATE SET epoch=epoch+1')->execute([$uid]);
   $p->prepare('UPDATE sf_devices SET revoked_at=? WHERE user_id=? AND revoked_at IS NULL')->execute([time(),$uid]);
   $p->prepare('DELETE FROM sf_remember_tokens WHERE user_id=?')->execute([$uid]);
  }
  $p->commit();
 }catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
}
