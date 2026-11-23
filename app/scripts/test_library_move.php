<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
$base=dirname(__DIR__);$tag=bin2hex(random_bytes(5));$fixture=$base.'/data/qa-library-'.$tag;mkdir($fixture,0700);mkdir($fixture.'/albums');
define('LAB_DATA',$fixture);define('LAB_ALBUMS',$fixture.'/albums');define('LAB_HLS',$base.'/hls_out');
$source=bin2hex(random_bytes(8));$target=bin2hex(random_bytes(8));$tid=bin2hex(random_bytes(8));$job=bin2hex(random_bytes(8));
$dbPath=$fixture.'/test.sqlite';putenv('HLS_MUSIC_LAB_SQLITE_DB='.$dbPath);
$db=new PDO('sqlite:'.$dbPath);$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$live=new PDO('sqlite:'.(getenv('HLS_MUSIC_LAB_SQLITE')?:'/sparrowfy/database/library.sqlite'));
foreach(['albums','tracks','track_renditions'] as $table){$q=$live->prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$table]);$db->exec($q->fetchColumn());}
function lab_auth_pdo():PDO{global $db;return $db;}
function lab_load_album(string $id):?array{return json_decode(file_get_contents(LAB_ALBUMS.'/'.$id.'/album.json'),true);}
function lab_album_path(string $id):string{return LAB_ALBUMS.'/'.$id.'/album.json';}
function lab_album_hls_dir(string $id,string $title=''):string{$path=LAB_HLS.'/'.$id.'_qa';if(!is_dir($path))mkdir($path,0770);return $path;}
function check($value,string $message){if(!$value)throw new RuntimeException($message);}
function cleanup(string $path){if(is_dir($path)&&!is_link($path)){foreach(scandir($path) as $n)if($n!=='.'&&$n!=='..')cleanup($path.'/'.$n);rmdir($path);}elseif(file_exists($path))unlink($path);}
require $base.'/lib/library_move.php';
try{
 foreach([$source,$target] as $id){mkdir(LAB_ALBUMS.'/'.$id);file_put_contents(LAB_ALBUMS.'/'.$id.'/album.json','{}');}
 $dir=lab_album_hls_dir($source).'/'.$job;mkdir($dir);file_put_contents($dir.'/master.m3u8',"#EXTM3U\n");file_put_contents($dir.'/segment.ts','QA');
 $track=['id'=>$tid,'name'=>'Prueba ñ','job_id'=>$job,'hls'=>'hls_out/'.$source.'_qa/'.$job.'/master.m3u8','duration'=>1,'renditions'=>['128k']];
 $a=['id'=>$source,'title'=>'Origen','tracks'=>[$track]];$b=['id'=>$target,'title'=>'Destino','tracks'=>[]];
 hml_delete_atomic_save_album($a);hml_delete_atomic_save_album($b);lab_sqlite_sync_album($a);lab_sqlite_sync_album($b);
 sf_move_track($source,$target,$tid);
 check(count(lab_load_album($source)['tracks'])===0,'Origen conserva pista');check(count(lab_load_album($target)['tracks'])===1,'Destino sin pista');
 check(is_file(lab_album_hls_dir($target).'/'.$job.'/segment.ts'),'Audio no movido');check(!is_dir($dir),'Audio duplicado');
 check($db->query("SELECT album_id FROM tracks WHERE id='$tid'")->fetchColumn()===$target,'SQLite desincronizado');
 sf_move_track($target,$source,$tid);check(is_file($dir.'/segment.ts'),'No se puede devolver pista');
 foreach(['playlist_tracks','favorites','recent_tracks'] as $table)$db->exec("CREATE TABLE $table(track_id TEXT)");
 $db->prepare('INSERT INTO playlist_tracks VALUES(?)')->execute([$tid]);
 sf_reuse_attach($source,$tid,$target);
 $lock=sf_reuse_lock();try{sf_reuse_preserve_before_delete($source,$tid);}finally{flock($lock,LOCK_UN);fclose($lock);}
 check(count(lab_load_album($source)['tracks'])===0,'Original no se puede quitar');
 check(count(sf_reuse_view(lab_load_album($target))['tracks'])===1,'Destino perdió pista al quitar original');
 check($db->query('SELECT COUNT(*) FROM playlist_tracks')->fetchColumn()==1,'Lista perdió identidad');
 check($db->query('SELECT COUNT(*) FROM sf_album_track_refs')->fetchColumn()==0,'Referencia redundante después de promover');
 check(is_file(lab_album_hls_dir($target).'/'.$job.'/segment.ts'),'Promoción perdió audio');
 sf_move_track($target,$source,$tid);
 $root=['id'=>'fffffffffffffffe','title'=>'Pistas sueltas','library_root'=>true,'tracks'=>[]];mkdir(LAB_ALBUMS.'/'.$root['id']);file_put_contents(lab_album_path($root['id']),'{}');hml_delete_atomic_save_album($root);lab_sqlite_sync_album($root);
 $lock=sf_reuse_lock();try{sf_reuse_remove_locked($source,$tid);}finally{flock($lock,LOCK_UN);fclose($lock);}
 check(count(lab_load_album($root['id'])['tracks'])===1,'Quitar ultima colección no conserva en biblioteca');
 check($db->query('SELECT COUNT(*) FROM playlist_tracks')->fetchColumn()==1,'Lista perdida en biblioteca');
 sf_move_track($root['id'],$source,$tid);
 $db->exec("CREATE TRIGGER qa_fail BEFORE UPDATE ON tracks BEGIN SELECT RAISE(ABORT,'qa rollback'); END");
 $failed=false;try{sf_move_track($source,$target,$tid);}catch(Throwable){$failed=true;}
 check($failed,'No se probo rollback');check(is_file($dir.'/segment.ts'),'Rollback perdio audio');check(count(lab_load_album($source)['tracks'])===1,'Rollback JSON incorrecto');check($db->query("SELECT album_id FROM tracks WHERE id='$tid'")->fetchColumn()===$source,'Rollback SQLite incorrecto');
 echo "PASS: mover y devolver sin duplicar, rutas y SQLite correctos, recuperacion ante fallo SQL; quitar original conserva audio, lista y destino\n";
}finally{cleanup($fixture);foreach([$source,$target,'fffffffffffffffe'] as $id){$path=LAB_HLS.'/'.$id.'_qa';if(is_dir($path))cleanup($path);}}





