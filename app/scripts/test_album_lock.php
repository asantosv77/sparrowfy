<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
$root=dirname(__DIR__);$dir=$root.'/backups/album-lock-test-'.bin2hex(random_bytes(4));mkdir($dir,02770);define('LAB_ALBUMS',$dir.'/albums');mkdir(LAB_ALBUMS,02770);
$db=$dir.'/fixture.sqlite';putenv('HLS_MUSIC_LAB_SQLITE_DB='.$db);
function lab_auth_pdo():PDO{$p=new PDO('sqlite:'.getenv('HLS_MUSIC_LAB_SQLITE_DB'));$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$p->exec('PRAGMA busy_timeout=15000');return $p;}
function lab_ensure_data_dirs():void{}
$p=lab_auth_pdo();$p->exec('PRAGMA journal_mode=WAL;CREATE TABLE lock_probe(v INTEGER);INSERT INTO lock_probe VALUES(0)');
// Use only the real save transaction, with a controlled WAL conflict.
$s=file_get_contents($root.'/lib.php');$start=strpos($s,'function lab_save_album(');$end=strpos($s,"\nfunction lab_album_path",$start);$code=substr($s,$start,$end-$start);$code=str_replace("require_once __DIR__.'/lib/sqlite_catalog_sync.php';",'', $code);eval($code);
$GLOBALS['attempts']=0;
function lab_sqlite_sync_album(array $album,?PDO $sharedPdo=null):void{
 $GLOBALS['attempts']++;
 if($GLOBALS['attempts']===1){$q=$sharedPdo->query('SELECT v FROM lock_probe');$q->fetchColumn();$q->closeCursor();$other=lab_auth_pdo();$other->exec('UPDATE lock_probe SET v=v+1');}
 $sharedPdo->exec('UPDATE lock_probe SET v=v+1');
}
lab_save_album(['id'=>'0123456789abcdef','title'=>'fixture','tracks'=>[]]);
if($GLOBALS['attempts']!==2 || (int)$p->query('SELECT v FROM lock_probe')->fetchColumn()!==2)throw new RuntimeException('Retry did not recover');
if(!is_file(LAB_ALBUMS.'/0123456789abcdef/album.json'))throw new RuntimeException('Mirror missing');
echo "PASS: real WAL snapshot conflict recovered by whole-transaction retry; isolated fixture only\n";
