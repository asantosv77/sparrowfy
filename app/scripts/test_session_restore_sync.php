<?php
$dir=dirname(__DIR__).'/data/test-session-sync-'.bin2hex(random_bytes(6));mkdir($dir);ini_set('session.save_path',$dir);session_start();
$GLOBALS['p']=new PDO('sqlite::memory:');$p=$GLOBALS['p'];$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
function lab_auth_pdo(){return $GLOBALS['p'];}function lab_auth_is_https(){return false;}
function sf_devices_schema($db){$db->exec('CREATE TABLE IF NOT EXISTS sf_device_remember(selector TEXT)');}
function sf_device_remember($db,$selector,$user,$stamp){return ['id'=>'device','epoch'=>1];}function sf_device_bind(...$args){}
function lab_auth_csrf_token(){return $_SESSION['csrf_token']??=bin2hex(random_bytes(32));}
function sf_control_event(...$args){}function sf_control_error(...$args){}
function check($v,$message){if(!$v)throw new RuntimeException($message);}
try{
 $p->exec('CREATE TABLE users(id TEXT PRIMARY KEY,username TEXT,role TEXT,is_active INTEGER,password_hash TEXT)');$p->exec("INSERT INTO users VALUES('u','fixture','user',1,'hash')");
 $source=file_get_contents(dirname(__DIR__).'/lib/remember_login.php');$source=preg_replace('/^require_once .*;$/m','',$source);eval(substr($source,5));sf_remember_db();
 $selector=str_repeat('a',32);$validator=str_repeat('b',64);$stamp=hash('sha256','hash');$now=time();
 $p->prepare('INSERT INTO sf_remember_tokens(selector,user_id,validator_hash,credential_stamp,created_at,expires_at) VALUES(?,?,?,?,?,?)')->execute([$selector,'u',hash('sha256',$validator),$stamp,$now,$now+86400]);
 $_COOKIE['SFREMEMBER']=$selector.':'.$validator;$token=null;$rotated=null;
 for($i=0;$i<3;$i++){
  $_SESSION=[];sf_remember_restore();check(($_SESSION['user_id']??'')==='u','Parallel restore lost authentication');$token??=$_SESSION['csrf_token'];check($token===$_SESSION['csrf_token'],'Parallel restore changed CSRF');
  $row=$p->query('SELECT * FROM sf_remember_tokens')->fetch(PDO::FETCH_ASSOC);$rotated??=$row['validator_hash'];check($rotated===$row['validator_hash'],'Parallel restore invalidated grace validator');
 }
 check(preg_match('/^[a-f0-9]{64}$/D',$token)===1,'Invalid CSRF seed');
 // Even a request carrying the freshly rotated validator must not invalidate older parallel requests.
 $p->prepare('UPDATE sf_remember_tokens SET validator_hash=?')->execute([hash('sha256',$validator)]);$_SESSION=[];sf_remember_restore();check($p->query('SELECT validator_hash FROM sf_remember_tokens')->fetchColumn()===hash('sha256',$validator),'Rotation repeated during grace');
 $p->exec("UPDATE users SET is_active=0");$_SESSION=[];sf_remember_restore();check(empty($_SESSION['user_id']),'Inactive account restored');$p->exec("UPDATE users SET is_active=1");
 $p->exec('UPDATE sf_remember_tokens SET expires_at=1');$_SESSION=[];sf_remember_restore();check(empty($_SESSION['user_id']),'Expired token restored');
 $_SESSION=['device_id'=>'device','csrf_token'=>str_repeat('c',64)];unset($_COOKIE['SFREMEMBER']);sf_remember_issue('u',$stamp);$seed=$p->query("SELECT csrf_seed FROM sf_remember_tokens WHERE expires_at>1")->fetchColumn();check($seed===str_repeat('c',64),'Initial login token not preserved');
 $ok=true;
}finally{session_write_close();foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);}
if(!empty($ok))echo "PASS: concurrent remembered restores share CSRF, rotation grace survives, initial login token preserved, disabled/expired accounts rejected\n";
