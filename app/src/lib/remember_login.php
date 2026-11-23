<?php
declare(strict_types=1);
require_once __DIR__.'/control_log.php';
function sf_remember_days(): int {
    $cfg=json_decode((string)@file_get_contents(dirname(__DIR__).'/data/config.json'),true);
    return max(1,min(365,(int)($cfg['remember_days']??30)));
}
function sf_remember_db(): PDO {
    $db=lab_auth_pdo();
    $db->exec('CREATE TABLE IF NOT EXISTS sf_remember_tokens (selector TEXT PRIMARY KEY,user_id TEXT NOT NULL,validator_hash TEXT NOT NULL,previous_hash TEXT,previous_until INTEGER NOT NULL DEFAULT 0,credential_stamp TEXT NOT NULL,created_at INTEGER NOT NULL,expires_at INTEGER NOT NULL)');
    // One CSRF value per remembered browser lineage, including parallel restores.
    static $csrfSchema=false;
    if(!$csrfSchema){
        $columns=$db->query('PRAGMA table_info(sf_remember_tokens)')->fetchAll(PDO::FETCH_ASSOC);
        if(!in_array('csrf_seed',array_column($columns,'name'),true)){
            try{$db->exec('ALTER TABLE sf_remember_tokens ADD COLUMN csrf_seed TEXT');}
            catch(Throwable $e){$again=$db->query('PRAGMA table_info(sf_remember_tokens)')->fetchAll(PDO::FETCH_ASSOC);if(!in_array('csrf_seed',array_column($again,'name'),true))throw $e;}
        }
        $csrfSchema=true;
    }
    sf_devices_schema($db);
    return $db;
}
function sf_remember_cookie(string $value,int $expires): void {
    $p=session_get_cookie_params();
    if(!setcookie('SFREMEMBER',$value,['expires'=>$expires,'path'=>$p['path'],'secure'=>lab_auth_is_https(),'httponly'=>true,'samesite'=>'Lax']))throw new RuntimeException('Remember cookie could not be sent');
}
function sf_remember_parts(): ?array {
    $value=$_COOKIE['SFREMEMBER']??'';
    if(!is_string($value)||!preg_match('/^([a-f0-9]{32}):([a-f0-9]{64})$/D',$value,$m))return null;
    return [$m[1],$m[2]];
}
function sf_remember_forget(): void {
    $parts=sf_remember_parts();
    if($parts){$db=sf_remember_db();$q=$db->prepare('DELETE FROM sf_remember_tokens WHERE selector=?');$q->execute([$parts[0]]);}
    sf_remember_cookie('',time()-3600);
    if($parts)sf_control_event('remember.forgotten');
}
function sf_remember_issue(string $uid,string $stamp): void {
    if(headers_sent())throw new RuntimeException('Headers already sent before remember cookie');
    $db=sf_remember_db();$now=time();$old=sf_remember_parts();
    $selector=bin2hex(random_bytes(16));$validator=bin2hex(random_bytes(32));$expires=$now+sf_remember_days()*86400;
    $db->beginTransaction();
    try{
      if($old)$db->prepare('DELETE FROM sf_remember_tokens WHERE selector=?')->execute([$old[0]]);
      $db->prepare('DELETE FROM sf_remember_tokens WHERE expires_at<?')->execute([$now]);
      $db->exec('DELETE FROM sf_device_remember WHERE selector NOT IN (SELECT selector FROM sf_remember_tokens)');
      $db->prepare('INSERT INTO sf_remember_tokens(selector,user_id,validator_hash,credential_stamp,created_at,expires_at,csrf_seed) VALUES(?,?,?,?,?,?,?)')->execute([$selector,$uid,hash('sha256',$validator),$stamp,$now,$expires,lab_auth_csrf_token()]);
      if(empty($_SESSION['device_id']))throw new RuntimeException('Remember device missing');
      sf_device_bind($db,$selector,(string)$_SESSION['device_id']);
      $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();sf_control_error('remember.issue_failed',$e,['user_id'=>$uid]);throw $e;}
    sf_remember_cookie($selector.':'.$validator,$expires);
    sf_control_event('remember.issued',['user_id'=>$uid,'duration_ms'=>sf_remember_days()*86400000]);
}
// Pure validation is also used by the isolated security tests.
function sf_remember_valid(array $r,string $validator,int $now,int $days): bool {
    if((int)$r['is_active']!==1 || $now>=min((int)$r['expires_at'],(int)$r['created_at']+$days*86400))return false;
    if(!hash_equals((string)$r['credential_stamp'],hash('sha256',(string)$r['password_hash'])))return false;
    $hash=hash('sha256',$validator);
    return hash_equals((string)$r['validator_hash'],$hash) ||
        ((int)$r['previous_until']>$now && is_string($r['previous_hash']) && hash_equals($r['previous_hash'],$hash));
}
function sf_remember_restore(): void {
    $parts=sf_remember_parts();if(!$parts){if(isset($_COOKIE['SFREMEMBER']))sf_control_event('remember.rejected',['reason'=>'malformed_cookie']);return;}
    $db=sf_remember_db();$db->exec('BEGIN IMMEDIATE');
    try {
        $q=$db->prepare('SELECT t.*,u.username,u.role,u.is_active,u.password_hash FROM sf_remember_tokens t JOIN users u ON u.id=t.user_id WHERE t.selector=?');$q->execute([$parts[0]]);$r=$q->fetch();$q->closeCursor();$now=time();
        if(!$r || !sf_remember_valid($r,$parts[1],$now,sf_remember_days())){
 $reason=!$r?'token_missing':((int)$r['is_active']!==1?'account_inactive':($now>=min((int)$r['expires_at'],(int)$r['created_at']+sf_remember_days()*86400)?'expired':(!hash_equals($r['credential_stamp'],hash('sha256',$r['password_hash']))?'credentials_changed':'validator_mismatch')));
 $db->exec('ROLLBACK');sf_control_event('remember.rejected',['reason'=>$reason]);return;}
        $device=sf_device_remember($db,$parts[0],(string)$r['user_id'],$r['credential_stamp']);
        if(!$device){$db->exec('ROLLBACK');sf_control_event('remember.rejected',['reason'=>'device_revoked_or_unbound']);return;}
        $csrf=(string)($r['csrf_seed']??'');
        if(!preg_match('/^[a-f0-9]{64}$/D',$csrf)){
            $csrf=bin2hex(random_bytes(32));
            $db->prepare('UPDATE sf_remember_tokens SET csrf_seed=? WHERE selector=?')->execute([$csrf,$parts[0]]);
        }
        $next=null;
        if(hash_equals($r['validator_hash'],hash('sha256',$parts[1])) && (int)($r['previous_until']??0)<=$now){
            $next=bin2hex(random_bytes(32));
            $db->prepare('UPDATE sf_remember_tokens SET previous_hash=validator_hash,previous_until=?,validator_hash=? WHERE selector=?')->execute([$now+30,hash('sha256',$next),$parts[0]]);
        }
        $db->exec('COMMIT');
        session_regenerate_id(true);
        $_SESSION=['user_id'=>(string)$r['user_id'],'username'=>$r['username'],'role'=>$r['role'],'auth_at'=>$now,'credential_stamp'=>$r['credential_stamp'],'csrf_token'=>$csrf,'device_id'=>$device['id'],'device_epoch'=>(int)$device['epoch'],'device_touch_at'=>0];
        sf_control_event('remember.restored',['user_id'=>(string)$r['user_id']]);
        if($next!==null)sf_remember_cookie($parts[0].':'.$next,min((int)$r['expires_at'],(int)$r['created_at']+sf_remember_days()*86400));
    }catch(Throwable $e){try{$db->exec('ROLLBACK');}catch(Throwable $ignored){}sf_control_error('remember.restore_failed',$e);throw $e;}
}
