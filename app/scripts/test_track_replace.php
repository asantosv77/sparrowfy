<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
$code=dirname(__DIR__);$fixture=$code.'/backups/replacement-test-'.bin2hex(random_bytes(6));mkdir($fixture,02770,true);
define('LAB_ROOT',$fixture);define('LAB_DATA',$fixture.'/data');define('LAB_ALBUMS',LAB_DATA.'/albums');define('LAB_HLS',$fixture.'/hls_out');mkdir(LAB_ALBUMS,0770,true);mkdir(LAB_HLS,0770,true);
$dbFile=$fixture.'/fixture.sqlite';putenv('HLS_MUSIC_LAB_SQLITE_DB='.$dbFile);
function lab_auth_pdo():PDO{global $dbFile;$p=new PDO('sqlite:'.$dbFile,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$p->exec('PRAGMA foreign_keys=ON');return $p;}
function lab_load_album(string $id):?array{return lab_sqlite_load_album($id);}
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
require $code.'/lib/sqlite_catalog_sync.php';require $code.'/lib/sqlite_catalog_read.php';require $code.'/lib/track_replace.php';
$db=lab_auth_pdo();$db->exec(<<<'SCHEMA'
CREATE TABLE users(id TEXT PRIMARY KEY);CREATE TABLE playlists(id TEXT PRIMARY KEY);
CREATE TABLE albums (
    id TEXT PRIMARY KEY CHECK(length(id) = 16),
    title TEXT NOT NULL,
    artist TEXT NOT NULL DEFAULT '',
    cover TEXT,
    cover_layout_json TEXT,
    created_at TEXT,
    matched_album TEXT,
    matched_artist TEXT,
    metadata_json TEXT,
    imported_at TEXT NOT NULL,
    updated_at TEXT
);
CREATE TABLE tracks (
    id TEXT PRIMARY KEY,
    album_id TEXT NOT NULL REFERENCES albums(id) ON DELETE RESTRICT,
    name TEXT NOT NULL,
    original_name TEXT,
    job_id TEXT,
    hls_path TEXT NOT NULL,
    duration REAL NOT NULL DEFAULT 0 CHECK(duration >= 0),
    source_kbps INTEGER CHECK(source_kbps IS NULL OR source_kbps >= 0),
    position INTEGER NOT NULL DEFAULT 0 CHECK(position >= 0),
    metadata_json TEXT,
    imported_at TEXT NOT NULL,
    updated_at TEXT
);
CREATE TABLE track_renditions (
    track_id TEXT NOT NULL REFERENCES tracks(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    bitrate_kbps INTEGER CHECK(bitrate_kbps IS NULL OR bitrate_kbps >= 0),
    PRIMARY KEY (track_id, name)
);
CREATE TABLE sf_album_track_refs(album_id TEXT NOT NULL,source_album_id TEXT NOT NULL,track_id TEXT NOT NULL,created_at TEXT NOT NULL,PRIMARY KEY(album_id,track_id));
CREATE TABLE favorites(user_id TEXT NOT NULL,track_id TEXT NOT NULL,created_at TEXT NOT NULL,PRIMARY KEY(user_id,track_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(track_id) REFERENCES tracks(id) ON DELETE CASCADE);
CREATE TABLE playlist_tracks(playlist_id TEXT NOT NULL,track_id TEXT NOT NULL,position INTEGER NOT NULL,added_at TEXT NOT NULL,PRIMARY KEY(playlist_id,track_id),FOREIGN KEY(playlist_id) REFERENCES playlists(id) ON DELETE CASCADE,FOREIGN KEY(track_id) REFERENCES tracks(id) ON DELETE CASCADE);
CREATE TABLE sf_processing_jobs (id TEXT PRIMARY KEY, user_id TEXT NOT NULL, category TEXT NOT NULL, operation TEXT NOT NULL, album_id TEXT NOT NULL, label TEXT NOT NULL, payload TEXT NOT NULL, state TEXT NOT NULL, stage TEXT NOT NULL, progress INTEGER, error TEXT, result_json TEXT, created_at TEXT NOT NULL, started_at TEXT, finished_at TEXT, updated_at TEXT NOT NULL);
CREATE TABLE audio_edit_jobs (id TEXT PRIMARY KEY, user_id TEXT NOT NULL, album_id TEXT NOT NULL, track_id TEXT NOT NULL, track_name TEXT NOT NULL, parameters TEXT NOT NULL, state TEXT NOT NULL, stage TEXT NOT NULL, progress INTEGER, source_kind TEXT, source_quality TEXT, error TEXT, created_at TEXT NOT NULL, started_at TEXT, finished_at TEXT, notified_at TEXT, updated_at TEXT NOT NULL);

SCHEMA
);
$aid='0123456789abcdef';$tid='fedcba9876543210';mkdir(LAB_ALBUMS.'/'.$aid);mkdir(LAB_HLS.'/'.$aid.'_Fixture');
function output(string $job):array{global $aid;$dir=LAB_HLS.'/'.$aid.'_Fixture/'.$job;mkdir($dir);file_put_contents($dir.'/master.m3u8',"#EXTM3U\n");file_put_contents($dir.'/seg.ts','fixture audio');return ['album_id'=>$aid,'job_id'=>$job,'master_rel'=>'hls_out/'.$aid.'_Fixture/'.$job.'/master.m3u8','hls'=>'hls_out/'.$aid.'_Fixture/'.$job.'/master.m3u8','duration'=>10,'source_kbps'=>192,'renditions'=>['96k','192k']];}
$a=output('original');$old=['id'=>$tid,'name'=>'Chosen name','title'=>'Chosen title','artist'=>'Chosen artist','genre'=>'','artwork'=>'chosen.jpg','hls'=>$a['hls'],'job_id'=>$a['job_id'],'duration'=>10,'renditions'=>['96k'],'original'=>'original.mp3'];$album=['id'=>$aid,'title'=>'Fixture','tracks'=>[$old]];lab_sqlite_sync_album($album);$file=LAB_ALBUMS.'/'.$aid.'/album.json';file_put_contents($file,json_encode($album));
$db->exec("INSERT INTO users VALUES('fixture');INSERT INTO favorites(user_id,track_id,created_at) VALUES('fixture','$tid','now');INSERT INTO sf_album_track_refs VALUES('2222222222222222','$aid','$tid','now');");
$raw=file_get_contents($file);$f=output('failed_sync');$db->exec("CREATE TRIGGER fail_sync BEFORE INSERT ON track_renditions BEGIN SELECT RAISE(ABORT,'injected sync failure');END;");
try{sf_replace_publish($aid,$tid,$a['hls'],$f,[],'syncfail');throw new LogicException('Accepted sync failure');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'injected sync failure'),'Unexpected failure');}
check(file_get_contents($file)===$raw&&lab_load_album($aid)['tracks'][0]['hls']===$a['hls'],'Sync rollback failed');$db->exec('DROP TRIGGER fail_sync');sf_replace_remove_unpublished($f);check(!is_dir(dirname(LAB_ROOT.'/'.$f['hls'])),'Failed output left behind');
$f=output('failed_commit');$db->exec("CREATE TABLE parent(id INTEGER PRIMARY KEY);CREATE TABLE child(pid INTEGER REFERENCES parent(id) DEFERRABLE INITIALLY DEFERRED);CREATE TRIGGER fail_commit AFTER UPDATE ON tracks BEGIN INSERT INTO child VALUES(99);END;");
try{sf_replace_publish($aid,$tid,$a['hls'],$f,[],'commitfail');throw new LogicException('Accepted commit failure');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'FOREIGN KEY'),'Unexpected commit failure');}
check(file_get_contents($file)===$raw&&lab_load_album($aid)['tracks'][0]['hls']===$a['hls'],'Commit JSON/DB rollback failed');$db->exec('DROP TRIGGER fail_commit');sf_replace_remove_unpublished($f);
$b=output('second')+['source_url'=>'https://open.spotify.com/track/'.str_repeat('A',22),'source_service'=>'mornye','source_mornye_url'=>'https://open.spotify.com/track/'.str_repeat('A',22),'source_mornye_job'=>'origin-job'];$merged=sf_replace_publish($aid,$tid,$a['hls'],$b,['title'=>'Other title','genre'=>'Rock'],'first');check($merged['id']===$tid&&$merged['title']==='Chosen title'&&$merged['genre']==='Rock'&&$merged['artwork']==='chosen.jpg','Metadata or ID changed');
$persisted=lab_load_album($aid)['tracks'][0];check($persisted['source_url']===$b['source_url']&&$persisted['source_mornye_job']==='origin-job','SQLite lost source provenance');$savedJson=json_decode(file_get_contents($file),true);check($savedJson['tracks'][0]['source_service']==='mornye','JSON lost source service');
$c=output('third')+array_intersect_key($persisted,array_flip(['source_url','source_service','source_mornye_url','source_mornye_job']));$current=sf_replace_publish($aid,$tid,$b['hls'],$c,[],'second','audio_edit');check($current['hls']===$c['hls'],'Chained publication failed');check($current['source_url']===$b['source_url'],'Audio edit lost source URL');
$again=sf_replace_publish($aid,$tid,$a['hls'],$b,[],'second');check($again['hls']===$c['hls'],'Retry changed current source');sf_replace_remove_unpublished($c);check(is_file(LAB_ROOT.'/'.$c['hls']),'Cleanup deleted current audio');
check((int)$db->query('SELECT COUNT(*) FROM favorites')->fetchColumn()===1&&(int)$db->query('SELECT COUNT(*) FROM sf_album_track_refs')->fetchColumn()===1,'Memberships changed');
foreach(['first'=>$a['hls'],'second'=>$b['hls']] as $job=>$expected){$receipt=json_decode(file_get_contents(LAB_ROOT.'/backups/replacements/'.$job.'.json'),true);check($receipt['state']==='published'&&$receipt['track']['hls']===$expected&&$receipt['retire_after']>=time()+3595,'Chained receipt missing');}
$receipt=json_decode(file_get_contents(LAB_ROOT.'/backups/replacements/syncfail.json'),true);check($receipt['state']==='prepared'&&!isset($receipt['retire_after']),'Failure authorized retirement');
check(is_file(LAB_ROOT.'/'.$a['hls'])&&is_file(LAB_ROOT.'/'.$b['hls']),'Old audio removed prematurely');
$pending=output('album_removed');$albumDir=LAB_ALBUMS.'/'.$aid;rename($albumDir,$albumDir.'.fixture-hidden');sf_replace_remove_unpublished($pending);check(!is_dir(dirname(LAB_ROOT.'/'.$pending['hls'])),'Removed album prevented cleanup');rename($albumDir.'.fixture-hidden',$albumDir);
echo "PASS: sync/commit rollback, cleanup protection, stable ID/metadata/memberships, chained receipts and idempotent retry\n";
