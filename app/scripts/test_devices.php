<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require dirname(__DIR__).'/lib/devices.php';require dirname(__DIR__).'/lib/remember_login.php';
function lab_auth_pdo(){static $p;return $p??=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}
function lab_auth_is_https(){return true;}
function ck($ok,$name){if(!$ok)throw new RuntimeException($name);}
$dir=dirname(__DIR__).'/backups/device-test-sessions';if(!is_dir($dir))mkdir($dir,02770);session_save_path($dir);session_start();
$p=sf_remember_db();$p->exec('CREATE TABLE users(id TEXT PRIMARY KEY,username TEXT,password_hash TEXT,role TEXT,is_active INTEGER)');
$hash=password_hash('fixture-password-only',PASSWORD_DEFAULT);$stamp=hash('sha256',$hash);
foreach(['a','b'] as $uid)$p->prepare("INSERT INTO users VALUES(?,?,?,'user',1)")->execute([$uid,$uid,$hash]);
$one=sf_device_create($p,'a');$two=sf_device_create($p,'a');$other=sf_device_create($p,'b');
$_SESSION=['device_id'=>$one['id'],'device_epoch'=>$one['epoch']];ck(sf_device_check($p,['id'=>'a','password_hash'=>$hash]),'current session');
$selector=str_repeat('d',32);$validator=str_repeat('e',64);
$p->prepare('INSERT INTO sf_remember_tokens(selector,user_id,validator_hash,credential_stamp,created_at,expires_at) VALUES(?,?,?,?,?,?)')->execute([$selector,'a',hash('sha256',$validator),$stamp,time(),time()+86400]);sf_device_bind($p,$selector,$one['id']);
$_COOKIE['SFREMEMBER']=$selector.':'.$validator;sf_remember_restore();ck($_SESSION['device_id']===$one['id'],'remembered restoration keeps device identity');
try{sf_device_revoke($p,'a',false,'b',$other['id']);throw new Exception('Cross-account access accepted');}catch(RuntimeException $e){ck($e->getMessage()==='Sin permiso para esta cuenta','permission error');}
sf_device_revoke($p,'a',false,'a',$one['id']);ck(!sf_device_check($p,['id'=>'a','password_hash'=>$hash]),'revoked session');
ck((int)$p->query('SELECT COUNT(*) FROM sf_remember_tokens')->fetchColumn()===0,'remember token revoked');
ck(sf_device_remember($p,$selector,'a',$stamp)===null,'revoked device cannot restore');
$_SESSION=['device_id'=>$two['id'],'device_epoch'=>$two['epoch']];ck(sf_device_check($p,['id'=>'a','password_hash'=>$hash]),'other access stays valid');
sf_device_revoke($p,'admin',true,'b',$other['id']);
ck($p->query("SELECT revoked_at FROM sf_devices WHERE user_id='b'")->fetchColumn()!==false,'admin revocation');
sf_device_revoke($p,'a',false,'a');ck(!sf_device_check($p,['id'=>'a','password_hash'=>$hash]),'close all');
$_SESSION=[];ck(!sf_device_check($p,['id'=>'a','password_hash'=>$hash]),'legacy unregistered session invalidated by epoch');
$fresh=sf_device_create($p,'a');$_SESSION=['device_id'=>$fresh['id'],'device_epoch'=>$fresh['epoch']];ck(sf_device_check($p,['id'=>'a','password_hash'=>$hash]),'new password login allowed');
ck(!sf_device_check($p,['id'=>'a','password_hash'=>'changed']),'password change invalidates access');
session_destroy();foreach(glob($dir.'/sess_*') as $f)unlink($f);rmdir($dir);
echo "PASS ownership, admin revocation, remembered identity, token revocation, per-device isolation, revoke-all epochs and password invalidation\n";
