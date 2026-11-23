<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require dirname(__DIR__).'/lib/personal_library.php';
$p=new PDO('sqlite::memory:');$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$p->exec('PRAGMA foreign_keys=ON');
$p->exec('CREATE TABLE users(id TEXT PRIMARY KEY,username TEXT,is_active INTEGER DEFAULT 1);CREATE TABLE albums(id TEXT PRIMARY KEY,title TEXT,artist TEXT);CREATE TABLE tracks(id TEXT PRIMARY KEY,album_id TEXT,name TEXT,position INTEGER,duration REAL,hls_path TEXT)');
$live=new PDO('sqlite:'.(getenv('HLS_MUSIC_LAB_SQLITE')?:'/sparrowfy/database/library.sqlite'));
foreach(['favorites','recent_tracks','playlists','playlist_tracks','playlist_shares'] as $table){$q=$live->prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$table]);$p->exec($q->fetchColumn());}sf_personal_schema($p);
function ck($v,$m){if(!$v)throw new RuntimeException($m);}
function denied($fn){try{$fn();}catch(RuntimeException){return;}throw new RuntimeException('Falto rechazo de acceso');}
$p->exec("INSERT INTO users(id,username) VALUES('u1','Uno'),('u2','Dos');INSERT INTO albums VALUES('a','Album','Artista');INSERT INTO tracks(id,album_id,name,position,duration) VALUES('t1','a','Uno',0,1),('t2','a','Dos',1,1)");
sf_personal_action($p,'u1','favorite',['track_id'=>'t1','enabled'=>'1']);
ck(count(sf_personal_tracks($p,'u1','favorites'))===1,'Favorito no guardado');ck(count(sf_personal_tracks($p,'u2','favorites'))===0,'Favoritos expuestos');
sf_personal_action($p,'u1','recent',['track_id'=>'t1']);sf_personal_action($p,'u1','recent',['track_id'=>'t1']);ck((int)$p->query('SELECT play_count FROM recent_tracks')->fetchColumn()===2,'Contador incorrecto');ck(count(sf_personal_tracks($p,'u2','recent'))===0,'Historial expuesto');
$id=sf_personal_action($p,'u1','create_playlist',['name'=>'Lista privada'])['playlist_id'];
sf_personal_action($p,'u1','playlist_add',['playlist_id'=>$id,'track_id'=>'t1']);sf_personal_action($p,'u1','playlist_add',['playlist_id'=>$id,'track_id'=>'t2']);sf_personal_action($p,'u1','playlist_add',['playlist_id'=>$id,'track_id'=>'t1']);ck(count(sf_personal_tracks($p,'u1','playlist',$id))===2,'Duplicacion');
denied(fn()=>sf_personal_tracks($p,'u2','playlist',$id));denied(fn()=>sf_personal_action($p,'u2','playlist_add',['playlist_id'=>$id,'track_id'=>'t1']));denied(fn()=>sf_personal_action($p,'u2','share_playlist',['playlist_id'=>$id]));
sf_personal_action($p,'u1','share_user',['playlist_id'=>$id,'username'=>'dos','shared'=>'1']);
sf_personal_action($p,'u1','share_user',['playlist_id'=>$id,'username'=>'DOS','shared'=>'1']);
ck(count(sf_personal_tracks($p,'u2','playlist',$id))===2,'Usuario no recibe lectura');ck((int)$p->query('SELECT count(*) FROM playlist_shares')->fetchColumn()===1,'Permiso duplicado');
denied(fn()=>sf_personal_action($p,'u2','playlist_remove',['playlist_id'=>$id,'track_id'=>'t1']));
denied(fn()=>sf_personal_action($p,'u2','share_user',['playlist_id'=>$id,'username'=>'Uno','shared'=>'1']));
sf_personal_action($p,'u1','playlist_remove',['playlist_id'=>$id,'track_id'=>'t2']);ck(count(sf_personal_tracks($p,'u2','playlist',$id))===1,'Lectura no refleja cambios');sf_personal_action($p,'u1','playlist_add',['playlist_id'=>$id,'track_id'=>'t2']);
sf_personal_action($p,'u1','share_user',['playlist_id'=>$id,'username'=>'Dos','shared'=>'0']);denied(fn()=>sf_personal_tracks($p,'u2','playlist',$id));
denied(fn()=>sf_personal_action($p,'u1','share_user',['playlist_id'=>$id,'username'=>'Uno','shared'=>'1']));
denied(fn()=>sf_personal_action($p,'u1','share_user',['playlist_id'=>$id,'username'=>'NoExiste','shared'=>'1']));
$token=sf_personal_action($p,'u1','share_playlist',['playlist_id'=>$id])['token'];ck(count(sf_personal_shared($p,$token)['tracks'])===2,'Vista compartida');
$copy=sf_personal_action($p,'u2','import_playlist',['token'=>$token])['playlist_id'];ck($copy!==$id,'No se creo copia propia');ck(count(sf_personal_tracks($p,'u2','playlist',$copy))===2,'Copia incompleta');ck(sf_personal_action($p,'u2','import_playlist',['token'=>$token])['playlist_id']===$copy,'Importacion duplicada');
sf_personal_action($p,'u2','playlist_remove',['playlist_id'=>$copy,'track_id'=>'t1']);ck(count(sf_personal_tracks($p,'u1','playlist',$id))===2,'Se modifico original');
sf_personal_action($p,'u1','revoke_link',['playlist_id'=>$id]);denied(fn()=>sf_personal_shared($p,$token));ck(count(sf_personal_tracks($p,'u2','playlist',$copy))===1,'Revocacion borro copia');
echo "PASS: compartir por usuario, lectura en vivo, permisos y revocacion; favoritos e historial privados, listas propias, acceso denegado a terceros, copia ordenada sin duplicados, revocacion e independencia de copias\n";
