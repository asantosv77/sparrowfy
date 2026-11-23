<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require dirname(__DIR__).'/lib.php';require_once dirname(__DIR__).'/lib/sqlite_catalog_sync.php';require_once dirname(__DIR__).'/lib/sqlite_catalog_read.php';
$p=lab_auth_pdo();$backup=LAB_DATA.'/backups/sqlite-search/catalog-before.sqlite';
if(!is_file($backup))$p->exec('VACUUM INTO '.$p->quote($backup));
$source=[];foreach(glob(LAB_ALBUMS.'/*/album.json') as $file){$a=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);$source[$a['id']]=$a;}
$p->beginTransaction();try{foreach($source as $a)lab_sqlite_sync_album($a,$p);$p->commit();}catch(Throwable $e){$p->rollBack();throw $e;}
$actual=[];foreach(lab_sqlite_list_albums() as $a)$actual[$a['id']]=$a;
function equivalent($a,$b,$path=''){
 foreach($a as $k=>$v){if(!array_key_exists($k,$b))throw new Exception('Missing field '.$path.'.'.$k);if(is_array($v)){if(!is_array($b[$k]))throw new Exception('Type mismatch');equivalent($v,$b[$k],$path.'.'.$k);}elseif($v!=$b[$k])throw new Exception('Value mismatch '.$path.'.'.$k);}
}
if(count($source)!==count($actual))throw new Exception('Album coverage mismatch');
foreach($source as $id=>$a)equivalent($a,$actual[$id],$id);
file_put_contents(LAB_DATA.'/sqlite-catalog.enabled','SQLite primary catalog; JSON compatibility mirrors retained.');
echo 'PASS migrated and verified '.count($actual).' albums; SQLite reads enabled'.PHP_EOL;
