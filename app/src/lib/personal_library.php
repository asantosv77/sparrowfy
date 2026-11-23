<?php
declare(strict_types=1);
require_once __DIR__.'/favorite_albums.php';
function sf_personal_schema(PDO $p):void{
 $p->exec("CREATE TABLE IF NOT EXISTS sf_playlist_links(playlist_id TEXT PRIMARY KEY,token_hash TEXT NOT NULL UNIQUE,created_at TEXT NOT NULL,FOREIGN KEY(playlist_id) REFERENCES playlists(id) ON DELETE CASCADE)");
 $p->exec("CREATE TABLE IF NOT EXISTS sf_playlist_imports(user_id TEXT NOT NULL,source_id TEXT NOT NULL,copy_id TEXT NOT NULL,PRIMARY KEY(user_id,source_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(copy_id) REFERENCES playlists(id) ON DELETE CASCADE)");
}
function sf_personal_owner(PDO $p,string $uid,string $pid):array{
 $q=$p->prepare('SELECT * FROM playlists WHERE id=? AND owner_id=?');$q->execute([$pid,$uid]);$pl=$q->fetch(PDO::FETCH_ASSOC);if(!$pl)throw new RuntimeException('Lista no encontrada o sin permiso');return $pl;
}
function sf_personal_track(PDO $p,string $tid):void{
 $q=$p->prepare('SELECT id FROM tracks WHERE id=?');$q->execute([$tid]);if(!$q->fetchColumn())throw new RuntimeException('Pista no encontrada en el catalogo');
}
function sf_personal_rows(PDO $p,string $sql,array $args):array{$q=$p->prepare($sql);$q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC);}
/**
 * Colecciones favoritas listas para pintar: solo las que siguen existiendo,
 * con las pistas reutilizadas incluidas y ocultando las que están pendientes
 * de eliminación para quien no es administrador.
 */
function sf_fav_albums_load(PDO $p,string $uid):array{
 require_once __DIR__.'/album_deletion.php';
 require_once __DIR__.'/track_reuse.php';
 $out=[];
 foreach(sf_fav_albums_rows($p,$uid) as $row){
  $aid=strtolower((string)($row['album_id']??''));
  if(!preg_match('/^[a-f0-9]{16}$/D',$aid)||$aid==='fffffffffffffffe')continue;
  $album=lab_load_album($aid);
  if(!$album)continue;
  $album=sf_reuse_view($album);
  if(empty($album['tracks']))continue;
  $album['favorited_at']=(string)($row['created_at']??'');
  $out[]=$album;
 }
 return sf_delreq_filter_visible($out);
}
function sf_personal_tracks(PDO $p,string $uid,string $kind,string $pid=''):array{
 $select='SELECT t.id,t.album_id,t.name,t.position,t.duration,t.hls_path AS hls,a.title AS album_title,a.artist FROM tracks t JOIN albums a ON a.id=t.album_id ';
 if($kind==='favorites')return sf_personal_rows($p,$select.'JOIN favorites f ON f.track_id=t.id WHERE f.user_id=? ORDER BY f.created_at DESC,t.name',[$uid]);
 if($kind==='recent')return sf_personal_rows($p,$select.'JOIN recent_tracks r ON r.track_id=t.id WHERE r.user_id=? ORDER BY r.played_at DESC LIMIT 100',[$uid]);
 sf_personal_access($p,$uid,$pid);return sf_personal_playlist_rows($p,$pid);
}
function sf_personal_playlist_rows(PDO $p,string $pid):array{return sf_personal_rows($p,'SELECT t.id,t.album_id,t.name,t.position,t.duration,t.hls_path AS hls,a.title AS album_title,a.artist FROM playlist_tracks pt JOIN tracks t ON t.id=pt.track_id JOIN albums a ON a.id=t.album_id WHERE pt.playlist_id=? ORDER BY pt.position,pt.track_id',[$pid]);}
function sf_personal_shared(PDO $p,string $token):array{
 if(!preg_match('/^[a-f0-9]{64}$/D',$token))throw new RuntimeException('Enlace no valido');
 $q=$p->prepare('SELECT p.id,p.name,p.description,u.username AS owner_username FROM sf_playlist_links l JOIN playlists p ON p.id=l.playlist_id JOIN users u ON u.id=p.owner_id WHERE l.token_hash=?');$q->execute([hash('sha256',$token)]);$pl=$q->fetch(PDO::FETCH_ASSOC);if(!$pl)throw new RuntimeException('El enlace no existe o fue revocado');$pl['tracks']=sf_personal_playlist_rows($p,$pl['id']);return $pl;
}
function sf_personal_action(PDO $p,string $uid,string $action,array $d):array{
 $now=gmdate('c');$tid=(string)($d['track_id']??'');$pid=(string)($d['playlist_id']??'');
 if($action==='favorite'){
 sf_personal_track($p,$tid);$enabled=($d['enabled']??'')==='1';
 if($enabled)$p->prepare('INSERT OR IGNORE INTO favorites(user_id,track_id,created_at) VALUES(?,?,?)')->execute([$uid,$tid,$now]);
 else $p->prepare('DELETE FROM favorites WHERE user_id=? AND track_id=?')->execute([$uid,$tid]);return ['favorite'=>$enabled];
 }
 if($action==='recent'){
 sf_personal_track($p,$tid);$p->prepare('INSERT INTO recent_tracks(user_id,track_id,played_at,play_count) VALUES(?,?,?,1) ON CONFLICT(user_id,track_id) DO UPDATE SET played_at=excluded.played_at,play_count=play_count+1')->execute([$uid,$tid,$now]);return ['ok'=>true];
 }
 if($action==='favorite_album'){
 sf_fav_albums_schema($p);$aid=strtolower(trim((string)($d['album_id']??'')));
 if(!preg_match('/^[a-f0-9]{16}$/D',$aid)||$aid==='fffffffffffffffe')throw new RuntimeException('Colección no válida');
 $enabled=($d['enabled']??'')==='1';
 if($enabled){$album=lab_load_album($aid);if(!$album)throw new RuntimeException('La colección ya no existe');if(!sf_fav_albums_has_tracks($p,$aid))throw new RuntimeException('La colección no tiene pistas');$p->prepare('INSERT OR IGNORE INTO favorite_albums(user_id,album_id,created_at) VALUES(?,?,?)')->execute([$uid,$aid,$now]);}
 else $p->prepare('DELETE FROM favorite_albums WHERE user_id=? AND album_id=?')->execute([$uid,$aid]);
 return ['favorite_album'=>$enabled,'album_id'=>$aid];
 }
 if($action==='create_playlist'){
 $name=trim((string)($d['name']??''));if($name===''||strlen($name)>480||preg_match('//u',$name)!==1||preg_match('/[\x00-\x1F\x7F]/',$name))throw new RuntimeException('Introduce un nombre valido de hasta 120 caracteres');if(function_exists('mb_strlen')&&mb_strlen($name)>120)throw new RuntimeException('Nombre demasiado largo');
 $pid=bin2hex(random_bytes(16));$p->prepare("INSERT INTO playlists(id,owner_id,name,description,is_shared,created_at,updated_at) VALUES(?,?,?,'',0,?,?)")->execute([$pid,$uid,$name,$now,$now]);return ['playlist_id'=>$pid];
 }
 if($action==='share_user')return sf_personal_share_user($p,$uid,$pid,(string)($d['username']??''),($d['shared']??'')==='1');
 if($action==='import_playlist')return sf_personal_import($p,$uid,(string)($d['token']??''));
 sf_personal_owner($p,$uid,$pid);
 if($action==='rename_playlist'){
 $name=trim((string)($d['name']??''));if($name===''||strlen($name)>480||preg_match('//u',$name)!==1||preg_match('/[\x00-\x1F\x7F]/',$name))throw new RuntimeException('Introduce un nombre valido de hasta 120 caracteres');if(function_exists('mb_strlen')&&mb_strlen($name)>120)throw new RuntimeException('Nombre demasiado largo');
 $p->prepare('UPDATE playlists SET name=?,updated_at=? WHERE id=? AND owner_id=?')->execute([$name,$now,$pid,$uid]);return ['playlist_id'=>$pid,'name'=>$name];
 }
 if($action==='share_playlist'){$token=bin2hex(random_bytes(32));$p->prepare('INSERT INTO sf_playlist_links(playlist_id,token_hash,created_at) VALUES(?,?,?) ON CONFLICT(playlist_id) DO UPDATE SET token_hash=excluded.token_hash,created_at=excluded.created_at')->execute([$pid,hash('sha256',$token),$now]);return ['token'=>$token];}
 if($action==='revoke_link'){$p->prepare('DELETE FROM sf_playlist_links WHERE playlist_id=?')->execute([$pid]);return ['ok'=>true];}
 if($action==='playlist_add'){
 sf_personal_track($p,$tid);$p->beginTransaction();try{$q=$p->prepare('SELECT COALESCE(MAX(position),-1)+1 FROM playlist_tracks WHERE playlist_id=?');$q->execute([$pid]);$position=(int)$q->fetchColumn();$p->prepare('INSERT OR IGNORE INTO playlist_tracks(playlist_id,track_id,position,added_at) VALUES(?,?,?,?)')->execute([$pid,$tid,$position,$now]);$p->prepare('UPDATE playlists SET updated_at=? WHERE id=?')->execute([$now,$pid]);$p->commit();}catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}return ['ok'=>true];
 }
 if($action==='playlist_remove'){$p->prepare('DELETE FROM playlist_tracks WHERE playlist_id=? AND track_id=?')->execute([$pid,$tid]);$p->prepare('UPDATE playlists SET updated_at=? WHERE id=?')->execute([$now,$pid]);return ['ok'=>true];}
 throw new RuntimeException('Accion no disponible');
}
function sf_personal_import(PDO $p,string $uid,string $token):array{
 $shared=sf_personal_shared($p,$token);$source=$shared['id'];
 $q=$p->prepare('SELECT id FROM playlists WHERE id=? AND owner_id=?');$q->execute([$source,$uid]);if($q->fetchColumn())return ['playlist_id'=>$source,'already_added'=>true];
 $p->beginTransaction();try{
 $q=$p->prepare('SELECT copy_id FROM sf_playlist_imports WHERE user_id=? AND source_id=?');$q->execute([$uid,$source]);if($copy=$q->fetchColumn()){$p->commit();return ['playlist_id'=>$copy,'already_added'=>true];}
 $pid=bin2hex(random_bytes(16));$now=gmdate('c');$p->prepare('INSERT INTO playlists(id,owner_id,name,description,is_shared,created_at,updated_at) VALUES(?,?,?,?,0,?,?)')->execute([$pid,$uid,$shared['name'],'Copia de una lista compartida',$now,$now]);
 $p->prepare('INSERT INTO playlist_tracks(playlist_id,track_id,position,added_at) SELECT ?,track_id,position,? FROM playlist_tracks WHERE playlist_id=?')->execute([$pid,$now,$source]);
 $p->prepare('INSERT INTO sf_playlist_imports(user_id,source_id,copy_id) VALUES(?,?,?)')->execute([$uid,$source,$pid]);$p->commit();return ['playlist_id'=>$pid,'already_added'=>false];
 }catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
}
function sf_personal_access(PDO $p,string $uid,string $pid):array{
 $q=$p->prepare('SELECT p.*,u.username AS owner_username FROM playlists p JOIN users u ON u.id=p.owner_id WHERE p.id=? AND (p.owner_id=? OR p.is_shared=1 OR EXISTS(SELECT 1 FROM playlist_shares ps WHERE ps.playlist_id=p.id AND ps.shared_with_user_id=?))');$q->execute([$pid,$uid,$uid]);$pl=$q->fetch(PDO::FETCH_ASSOC);if(!$pl)throw new RuntimeException('Lista no encontrada o sin permiso');return $pl;
}
function sf_personal_share_user(PDO $p,string $uid,string $pid,string $username,bool $shared):array{
 sf_personal_owner($p,$uid,$pid);$username=trim($username);if($username===''||strlen($username)>160)throw new RuntimeException('Introduce un nombre de usuario de Sparrow-Fy');
 $q=$p->prepare('SELECT id,username FROM users WHERE username=? COLLATE NOCASE AND is_active=1');$q->execute([$username]);$target=$q->fetch(PDO::FETCH_ASSOC);if(!$target)throw new RuntimeException('Usuario no encontrado o inactivo');if((string)$target['id']===$uid)throw new RuntimeException('La lista ya pertenece a tu cuenta');
 if($shared)$p->prepare('INSERT OR IGNORE INTO playlist_shares(playlist_id,shared_with_user_id,created_at) VALUES(?,?,?)')->execute([$pid,$target['id'],gmdate('c')]);
 else $p->prepare('DELETE FROM playlist_shares WHERE playlist_id=? AND shared_with_user_id=?')->execute([$pid,$target['id']]);
 return ['playlist_id'=>$pid,'shared_with'=>$target['username'],'shared'=>$shared];
}

