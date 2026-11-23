<?php
$GLOBALS['db']=new PDO('sqlite::memory:');function lab_auth_pdo(){return $GLOBALS['db'];}function lab_auth_is_protected_admin_id($id){return $id==='a';}function sf_remember_db(){return lab_auth_pdo();}
$src=file_get_contents(dirname(__DIR__).'/lib/audio_download.php');eval(substr(preg_replace('/^require_once .*;$/m','',$src),5));
$pdo=lab_auth_pdo();$pdo->exec("CREATE TABLE users(id TEXT PRIMARY KEY,username TEXT,role TEXT,is_active INTEGER,can_manage_albums INTEGER,updated_at INTEGER);INSERT INTO users VALUES('a','admin','admin',1,1,0),('b','normal','user',1,0,0)");sfd_db();$_SESSION=['user_id'=>'a'];
$admin=file_get_contents(dirname(__DIR__).'/admin_users.php');$a=strpos($admin,'function au_checked_permissions(');$b=strpos($admin,"\n}",$a);eval(substr($admin,$a,$b-$a+2));
$marker="} elseif (\$action === 'update_bulk') {";$a=strpos($admin,$marker)+strlen($marker);$b=strpos($admin,"} elseif (\$action === 'reset_password') {",$a);$bulk=substr($admin,$a,$b-$a);
foreach([1,0] as $on){$_POST=['active'=>['b'=>1],'manage'=>['b'=>0],'download'=>['a'=>$on,'b'=>$on]];eval($bulk);if(sfd_allowed('a')!==(bool)$on||sfd_allowed('b')!==(bool)$on)throw new RuntimeException('Permission not saved');$a=$pdo->query("SELECT * FROM users WHERE id='a'")->fetch(PDO::FETCH_ASSOC);if((int)$a['is_active']!==1||(int)$a['can_manage_albums']!==1)throw new RuntimeException('Protected account changed');}
$_POST=['active'=>['a'=>0],'download'=>['a'=>1]];try{eval($bulk);throw new LogicException('Protected account deactivated');}catch(RuntimeException $e){}
echo "PASS: actual bulk handler enables/revokes downloads, preserves admin protection, prevents self-deactivation\n";
