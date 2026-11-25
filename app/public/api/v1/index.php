<?php
declare(strict_types=1);
/** HLS Music Lab Mobile API v1. All responses JSON; auth uses Bearer tokens. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
require_once dirname(__DIR__, 2) . '/auth.php';
require_once dirname(__DIR__, 2) . '/lib/mobile_sessions.php';
require_once dirname(__DIR__, 2) . '/lib/control_log.php';

const SF_MOBILE_LOOSE_ID = 'fffffffffffffffe';

function out(array $data, int $status=200): never { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function body(): array { $raw=file_get_contents('php://input'); if (!$raw) return []; $v=json_decode($raw,true); if (!is_array($v)) out(['error'=>'JSON inválido'],400); return $v; }
function db(): PDO { $p=lab_auth_pdo(); $p->exec('PRAGMA foreign_keys=ON'); sf_mobile_sessions_schema($p); return $p; }
function coverUrl(?array $a): string {
    if (!$a || empty($a['id']) || empty($a['cover'])) return '';
    $id = (string)$a['id']; $cover = (string)$a['cover'];
    $base = rtrim(str_replace('\\','/',dirname(__DIR__, 2).'/data/albums'), '/');
    $ver = is_file($base.'/'.$id.'/'.$cover) ? (string)filemtime($base.'/'.$id.'/'.$cover) : '';
    $root = rtrim(str_replace('\\','/',(string)(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/api/v1/index.php'), 3) ?: '/')), '/');
    return $root.'/data/albums/'.rawurlencode($id).'/'.rawurlencode($cover).($ver !== '' ? '?v='.$ver : '');
}
function artworkUrl(string $value): string {
    $value = trim($value);
    if ($value === '') return '';
    if (preg_match('~^https://~i', $value)) return $value;
    if (preg_match('/^artwork_image\.php\?key=[a-f0-9]{64}$/D', $value)) {
        $root = rtrim(str_replace('\\','/',(string)(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/api/v1/index.php'), 3) ?: '/')), '/');
        return $root.'/'.$value;
    }
    return '';
}
function trackCover(array $r): string {
    $meta = json_decode((string)($r['metadata_json'] ?? ''), true);
    $artwork = artworkUrl((string)(is_array($meta) ? ($meta['artwork'] ?? '') : ''));
    if ($artwork !== '') return $artwork;
    return coverUrl(['id' => (string)($r['album_id'] ?? ''), 'cover' => (string)($r['cover'] ?? '')]);
}
function trackCoverTerms(array $r): array {
    $meta = json_decode((string)($r['metadata_json'] ?? ''), true);
    $meta = is_array($meta) ? $meta : [];
    $name = (string)($r['name'] ?? '');
    $artist = trim((string)($meta['artist'] ?? ($r['album_artist'] ?? '')));
    if ($artist === '' && preg_match('/^\s*(.+?)\s+-\s+(.+)$/u', $name, $m)) $artist = trim($m[1]);
    $title = trim((string)($meta['title'] ?? ($meta['name'] ?? $name)));
    if (preg_match('/^\s*(.+?)\s+-\s+(.+)$/u', $title, $m)) $title = trim($m[2]);
    $album = trim((string)($meta['album'] ?? ($r['album_title'] ?? '')));
    return [$artist, $title, $album];
}
function externalCover(array $terms): string {
    require_once dirname(__DIR__, 2).'/lib/cover_lookup.php';
    return sf_cover_first_https(sf_cover_lookup_candidates($terms[0] ?? '', $terms[1] ?? '', $terms[2] ?? ''));
}
function mobileLyrics(string $artist, string $title, string $album, int $duration): array {
    require_once dirname(__DIR__, 2).'/lib/external_lyrics.php';
    $title = lyrics_clean_title($title);
    $result = lyrics_from_lrclib($artist, $title, $duration);
    if ((!$result || (empty($result['synced']) && empty($result['plain']))) && $artist !== '' && $title !== '') {
        $fallback = lyrics_from_ovh($artist, $title);
        if ($fallback) $result = $fallback;
    }
    return [
        'title' => $title,
        'artist' => $artist,
        'album' => $album,
        'synced' => $result['synced'] ?? null,
        'plain' => $result['plain'] ?? null,
        'source' => $result['source'] ?? null,
    ];
}
function mobileCachePath(string $kind, string $key): string {
    $dir = dirname(__DIR__, 2).'/data/mobile-cache/'.$kind;
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir.'/'.$key.'.json';
}
function mobileCacheGet(string $kind, string $key, int $ttl): ?array {
    $file = mobileCachePath($kind, $key);
    if (!is_file($file) || time() - (int)filemtime($file) > $ttl) return null;
    $data = json_decode((string)@file_get_contents($file), true);
    return is_array($data) ? $data : null;
}
function mobileCacheSet(string $kind, string $key, array $data): void {
    $file = mobileCachePath($kind, $key);
    @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), LOCK_EX);
}
function bearer(): string { $h=(string)($_SERVER['HTTP_AUTHORIZATION']??''); if (!$h && function_exists('getallheaders')) { foreach(getallheaders() as $k=>$v) if(strtolower($k)==='authorization') $h=(string)$v; } return preg_match('/^Bearer\s+([A-Fa-f0-9]{64})$/',$h,$m)?strtolower($m[1]):''; }
function account(PDO $p, bool $required=true): ?array {
    $token=bearer();
    if(!$token){ if($required) out(['error'=>'Token requerido'],401); return null; }
    // Las sesiones móviles son persistentes: solo dejan de valer al revocarse, cerrar sesión o desactivar la cuenta.
    $q=$p->prepare('SELECT u.id,u.username,u.role,u.is_active,s.id AS session_id,s.last_used_at FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.refresh_token_hash=? AND s.revoked_at IS NULL AND u.is_active=1 LIMIT 1');
    $q->execute([hash('sha256',$token)]);
    $r=$q->fetch();
    if(!$r){if($required)out(['error'=>'Token inválido o expirado'],401);return null;}
    // La app abre varias peticiones en paralelo; escribir last_used_at en cada una bloquea SQLite.
    $stale = empty($r['last_used_at']) || strtotime((string)$r['last_used_at']) < time() - 60;
    if ($stale) $p->prepare('UPDATE sessions SET last_used_at=? WHERE id=?')->execute([gmdate('c'),$r['session_id']]);
    return $r;
}
function id(): string { return bin2hex(random_bytes(16)); }
function jsonId(mixed $v): string { $s=trim((string)$v); if($s===''||strlen($s)>160) out(['error'=>'Identificador inválido'],400); return $s; }
function likeArg(string $term): string { return '%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$term).'%'; }
function pageArgs(): array { $limit=max(1,min(100,(int)($_GET['limit']??30))); $offset=max(0,(int)($_GET['offset']??0)); return [$limit,$offset]; }
function publicTrack(array $r, bool $withCover=true): array {
    $t=['id'=>$r['id'],'album_id'=>$r['album_id'],'name'=>$r['name'],'duration'=>(float)$r['duration'],'position'=>(int)$r['position'],'source_kbps'=>$r['source_kbps']===null?null:(int)$r['source_kbps'],'renditions'=>isset($r['renditions'])?$r['renditions']:[],'stream_url'=>rtrim(str_replace('\\','/',dirname((string)($_SERVER['SCRIPT_NAME']??'/api/v1/index.php'))),'/').'/stream.php?track_id='.rawurlencode((string)$r['id'])];
    if($withCover)$t['cover_url']=trackCover($r);
    return $t;
}
try {
$p=db(); $method=strtoupper($_SERVER['REQUEST_METHOD']??'GET'); $path=trim((string)($_GET['r']??''),'/'); if($path==='') { $uri=parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH)?:''; $path=preg_replace('~^.*/api/v1/?~','',$uri)??''; }
$parts=array_values(array_filter(explode('/',$path),'strlen')); $route=implode('/',$parts);
if($route==='health' && $method==='GET') out(['api'=>'HLS Music Lab','version'=>'1.0.0','status'=>'ok']);
if($route==='auth/login' && $method==='POST') { $b=body(); $username=trim((string)($b['username']??'')); $password=(string)($b['password']??''); if($username===''||$password==='')out(['error'=>'username y password son obligatorios'],400); $key=lab_auth_attempt_key($username); if(lab_auth_login_blocked($p,$key)){sf_control_event('mobile.login.rejected',['reason'=>'rate_limit']);out(['error'=>'Demasiados intentos. Espera 15 minutos antes de volver a probar.'],429);} $q=$p->prepare('SELECT id,username,password_hash,role,is_active FROM users WHERE username=? COLLATE NOCASE LIMIT 1');$q->execute([$username]);$u=$q->fetch(); $ok=is_array($u)&&(int)$u['is_active']===1&&password_verify($password,(string)$u['password_hash']); lab_auth_record_attempt($p,$key,$ok); if(!$ok){sf_control_event('mobile.login.rejected',['reason'=>'invalid_credentials']);out(['error'=>'Credenciales incorrectas'],401);} sf_control_event('mobile.login.success',['user_id'=>(string)$u['id']]); $token=bin2hex(random_bytes(32)); $sid=id(); $now=gmdate('c'); $expires=gmdate('c',time()+60*60*24*365*20); $p->prepare('INSERT INTO sessions(id,user_id,refresh_token_hash,created_at,expires_at,device_label,last_used_at) VALUES(?,?,?,?,?,?,?)')->execute([$sid,$u['id'],hash('sha256',$token),$now,$expires,substr(trim((string)($b['device_label']??'mobile')),0,100),$now]); $p->prepare('UPDATE users SET last_login_at=? WHERE id=?')->execute([$now,$u['id']]); out(['access_token'=>$token,'token_type'=>'Bearer','expires_at'=>$expires,'user'=>['id'=>$u['id'],'username'=>$u['username'],'role'=>$u['role']]]); }
if($route==='auth/logout' && $method==='POST') { $u=account($p); $p->prepare('UPDATE sessions SET revoked_at=? WHERE id=?')->execute([gmdate('c'),$u['session_id']]); out(['success'=>true]); }
$u=account($p);
require_once dirname(__DIR__,2).'/lib/track_reuse.php';sf_reuse_db();
if($route==='auth/me' && $method==='GET') {
    // Sesiones antiguas: se prolongan en la primera validación online, sin renovar el token.
    $longExpiry=gmdate('c',time()+60*60*24*365*20);
    $p->prepare('UPDATE sessions SET expires_at=? WHERE id=? AND expires_at<?')->execute([$longExpiry,$u['session_id'],$longExpiry]);
    out(['user'=>['id'=>$u['id'],'username'=>$u['username'],'role'=>$u['role']]]);
}
if(count($parts)===3 && $parts[0]==='tracks' && $parts[2]==='cover' && $method==='GET') {
    $tid=jsonId($parts[1]);
    $q=$p->prepare('SELECT t.*,a.title AS album_title,a.artist AS album_artist,a.cover AS cover FROM tracks t JOIN albums a ON a.id=t.album_id WHERE t.id=? LIMIT 1');
    $q->execute([$tid]);$r=$q->fetch();if(!$r)out(['error'=>'Pista no encontrada'],404);
    $local=trackCover($r);$terms=trackCoverTerms($r);
    $cacheKey=hash('sha256',$tid.'|'.$local.'|'.implode('|',$terms));
    $cached=mobileCacheGet('covers',$cacheKey,86400);
    if($cached!==null)out($cached+['track_id'=>$tid]);
    if($local!==''){
        $payload=['track_id'=>$tid,'cover_url'=>$local,'source'=>'local'];
    } else {
        $cover=externalCover($terms);
        $payload=['track_id'=>$tid,'cover_url'=>$cover,'source'=>$cover!==''?'external':null];
    }
    mobileCacheSet('covers',$cacheKey,$payload);
    out($payload);
}
if(count($parts)===3 && $parts[0]==='tracks' && $parts[2]==='lyrics' && $method==='GET') {
    $tid=jsonId($parts[1]);
    $q=$p->prepare('SELECT t.*,a.title AS album_title,a.artist AS album_artist,a.cover AS cover FROM tracks t JOIN albums a ON a.id=t.album_id WHERE t.id=? LIMIT 1');
    $q->execute([$tid]);$r=$q->fetch();if(!$r)out(['error'=>'Pista no encontrada'],404);
    [$artist,$title,$album]=trackCoverTerms($r);$duration=(int)round((float)($r['duration']??0));
    $cacheKey=hash('sha256',$artist.'|'.$title.'|'.$album.'|'.$duration);
    $cached=mobileCacheGet('lyrics',$cacheKey,604800);
    if($cached!==null)out($cached+['track_id'=>$tid]);
    $payload=['track_id'=>$tid]+mobileLyrics($artist,$title,$album,$duration);
    mobileCacheSet('lyrics',$cacheKey,$payload);
    out($payload);
}
if($route==='me/preferences' && $method==='GET') {
    $p->prepare("INSERT OR IGNORE INTO user_preferences(user_id,hls_profile,updated_at) VALUES(?,'balanced_mobile',?)")->execute([$u['id'],gmdate('c')]);
    $q=$p->prepare('SELECT hls_profile FROM user_preferences WHERE user_id=? LIMIT 1');
    $q->execute([$u['id']]);
    $profile=(string)($q->fetchColumn() ?: 'balanced_mobile');
    if(!in_array($profile,['continuity','balanced_mobile','audio_quality'],true)) $profile='balanced_mobile';
    out(['hls_profile'=>$profile,'available_profiles'=>['continuity','balanced_mobile','audio_quality'],'scope'=>'account']);
}
if($route==='me/preferences/hls' && $method==='PATCH') {
    $b=body();
    $profile=(string)($b['hls_profile']??'');
    if(!in_array($profile,['continuity','balanced_mobile','audio_quality'],true)) out(['error'=>'Perfil HLS inválido','allowed'=>['continuity','balanced_mobile','audio_quality']],400);
    $p->prepare("INSERT INTO user_preferences(user_id,hls_profile,updated_at) VALUES(?,?,?) ON CONFLICT(user_id) DO UPDATE SET hls_profile=excluded.hls_profile,updated_at=excluded.updated_at")->execute([$u['id'],$profile,gmdate('c')]);
    out(['hls_profile'=>$profile,'updated'=>true]);
}
if($route==='library/albums' && $method==='GET') { [$limit,$offset]=pageArgs(); $term=mb_substr(trim((string)($_GET['q']??'')),0,120); $sql='SELECT id,title,artist,cover,created_at,(SELECT COUNT(*) FROM tracks t WHERE t.album_id=albums.id OR EXISTS(SELECT 1 FROM sf_album_track_refs r WHERE r.track_id=t.id AND r.source_album_id=t.album_id AND r.album_id=albums.id)) AS track_count FROM albums WHERE id<>?'; $args=[SF_MOBILE_LOOSE_ID]; if($term!==''){ $sql.=" AND (title LIKE ? ESCAPE '\\' OR artist LIKE ? ESCAPE '\\')";$args[]=likeArg($term);$args[]=likeArg($term); } $order=((string)($_GET['order']??'')==='recent')?'ORDER BY created_at DESC, title COLLATE NOCASE':'ORDER BY title COLLATE NOCASE';$sql.=' '.$order.' LIMIT ? OFFSET ?';$args[]=$limit;$args[]=$offset;$q=$p->prepare($sql);$q->execute($args);$items=[];foreach($q->fetchAll() as $a){$a['cover_url']=coverUrl($a);$a['track_count']=(int)$a['track_count'];$items[]=$a;}out(['items'=>$items,'limit'=>$limit,'offset'=>$offset]); }
if($route==='library/tracks' && $method==='GET') { [$limit,$offset]=pageArgs();$term=mb_substr(trim((string)($_GET['q']??'')),0,120);$album=trim((string)($_GET['album_id']??''));$where=[];$args=[];if($term!==''){$where[]="(t.name LIKE ? ESCAPE '\\' OR t.original_name LIKE ? ESCAPE '\\')";array_push($args,likeArg($term),likeArg($term));}if($album!==''){$where[]='(t.album_id=? OR EXISTS(SELECT 1 FROM sf_album_track_refs r WHERE r.track_id=t.id AND r.source_album_id=t.album_id AND r.album_id=?))';$args[]=$album;$args[]=$album;} $sql="SELECT t.*,COALESCE(a.title,'Pistas sin asignar a colección') AS album_title,COALESCE(a.artist,'') AS album_artist,COALESCE(a.cover,'') AS cover FROM tracks t LEFT JOIN albums a ON a.id=t.album_id";if($where)$sql.=' WHERE '.implode(' AND ',$where);$sql.=' ORDER BY album_title COLLATE NOCASE,t.position LIMIT ? OFFSET ?';$args[]=$limit;$args[]=$offset;$q=$p->prepare($sql);$q->execute($args);$items=[];foreach($q->fetchAll() as $r){$rr=$p->prepare('SELECT name FROM track_renditions WHERE track_id=? ORDER BY bitrate_kbps');$rr->execute([$r['id']]);$r['renditions']=array_column($rr->fetchAll(),'name');$items[]=publicTrack($r);}out(['items'=>$items,'limit'=>$limit,'offset'=>$offset]); }
if($route==='search' && $method==='GET') { $term=trim((string)($_GET['q']??'')); if($term===''||mb_strlen($term)>120)out(['error'=>'El parámetro q es obligatorio y admite hasta 120 caracteres'],400); [$limit,$offset]=pageArgs(); $like=likeArg($term); $aq=$p->prepare("SELECT id,title,artist,cover,(SELECT COUNT(*) FROM tracks t WHERE t.album_id=albums.id OR EXISTS(SELECT 1 FROM sf_album_track_refs r WHERE r.track_id=t.id AND r.source_album_id=t.album_id AND r.album_id=albums.id)) AS track_count FROM albums WHERE title LIKE ? ESCAPE '\\' OR artist LIKE ? ESCAPE '\\' ORDER BY title COLLATE NOCASE LIMIT ? OFFSET ?"); $aq->execute([$like,$like,$limit,$offset]); $albums=[];foreach($aq->fetchAll() as $a){$a['cover_url']=coverUrl($a);$a['track_count']=(int)$a['track_count'];$albums[]=$a;} $tq=$p->prepare("SELECT t.*,a.title AS album_title,a.artist AS album_artist,a.cover AS cover FROM tracks t JOIN albums a ON a.id=t.album_id WHERE t.name LIKE ? ESCAPE '\\' OR t.original_name LIKE ? ESCAPE '\\' OR a.title LIKE ? ESCAPE '\\' OR a.artist LIKE ? ESCAPE '\\' ORDER BY a.title COLLATE NOCASE,t.position LIMIT ? OFFSET ?"); $tq->execute([$like,$like,$like,$like,$limit,$offset]); $tracks=[]; foreach($tq->fetchAll() as $r){$rr=$p->prepare('SELECT name FROM track_renditions WHERE track_id=? ORDER BY bitrate_kbps');$rr->execute([$r['id']]);$r['renditions']=array_column($rr->fetchAll(),'name');$item=publicTrack($r);$item['album_title']=$r['album_title'];$item['album_artist']=$r['album_artist'];$tracks[]=$item;} out(['query'=>$term,'albums'=>$albums,'tracks'=>$tracks,'limit'=>$limit,'offset'=>$offset]); }
if(count($parts)>=3 && $parts[0]==='library' && $parts[1]==='albums' && $method==='GET') { $aid=$parts[2];$q=$p->prepare('SELECT * FROM albums WHERE id=?');$q->execute([$aid]);$a=$q->fetch();if(!$a)out(['error'=>'Álbum no encontrado'],404);$q=$p->prepare('SELECT t.* FROM tracks t WHERE t.album_id=? OR EXISTS(SELECT 1 FROM sf_album_track_refs r WHERE r.track_id=t.id AND r.source_album_id=t.album_id AND r.album_id=?) ORDER BY t.position');$q->execute([$aid,$aid]);$items=[];foreach($q->fetchAll() as $r){$rr=$p->prepare('SELECT name FROM track_renditions WHERE track_id=? ORDER BY bitrate_kbps');$rr->execute([$r['id']]);$r['renditions']=array_column($rr->fetchAll(),'name');$r['cover']=$a['cover'] ?? '';$items[]=publicTrack($r);}$a['cover_url']=coverUrl($a);$a['track_count']=count($items);out(['album'=>$a,'tracks'=>$items]); }
if($route==='me/favorites' && $method==='GET') { $q=$p->prepare('SELECT t.*,a.title AS album_title,a.artist AS album_artist,a.cover AS cover,f.created_at AS favorited_at FROM favorites f JOIN tracks t ON t.id=f.track_id JOIN albums a ON a.id=t.album_id WHERE f.user_id=? ORDER BY f.created_at DESC');$q->execute([$u['id']]);$items=[];foreach($q->fetchAll() as $r){$item=publicTrack($r);$item['favorited_at']=$r['favorited_at'];$items[]=$item;}out(['items'=>$items]); }
if($route==='me/favorites' && $method==='POST') { $b=body();$tid=jsonId($b['track_id']??'');$q=$p->prepare('SELECT 1 FROM tracks WHERE id=?');$q->execute([$tid]);if(!$q->fetchColumn())out(['error'=>'Pista no encontrada'],404);$p->prepare('INSERT OR IGNORE INTO favorites(user_id,track_id,created_at) VALUES(?,?,?)')->execute([$u['id'],$tid,gmdate('c')]);out(['success'=>true,'track_id'=>$tid],201); }
if(count($parts)===3 && $parts[0]==='me' && $parts[1]==='favorites' && $method==='DELETE') { $tid=jsonId($parts[2]);$p->prepare('DELETE FROM favorites WHERE user_id=? AND track_id=?')->execute([$u['id'],$tid]);out(['success'=>true,'track_id'=>$tid]); }
if($route==='me/favorite-albums' && $method==='GET') { [$limit,$offset]=pageArgs(); $q=$p->prepare('SELECT a.*,f.created_at AS favorited_at,(SELECT COUNT(*) FROM tracks t WHERE t.album_id=a.id OR EXISTS(SELECT 1 FROM sf_album_track_refs r WHERE r.track_id=t.id AND r.source_album_id=t.album_id AND r.album_id=a.id)) AS track_count FROM favorite_albums f JOIN albums a ON a.id=f.album_id WHERE f.user_id=? ORDER BY f.created_at DESC LIMIT ? OFFSET ?');$q->execute([$u['id'],$limit,$offset]);$items=[];foreach($q->fetchAll() as $a){$a['cover_url']=coverUrl($a);$a['track_count']=(int)$a['track_count'];$a['favorited_at']=$a['favorited_at'];$items[]=$a;}out(['items'=>$items,'limit'=>$limit,'offset'=>$offset]); }
if($route==='me/favorite-albums' && $method==='POST') { $b=body();$aid=jsonId($b['album_id']??'');$q=$p->prepare('SELECT 1 FROM albums WHERE id=?');$q->execute([$aid]);if(!$q->fetchColumn())out(['error'=>'Colección no encontrada'],404);$p->prepare('INSERT OR IGNORE INTO favorite_albums(user_id,album_id,created_at) VALUES(?,?,?)')->execute([$u['id'],$aid,gmdate('c')]);out(['success'=>true,'album_id'=>$aid],201); }
if(count($parts)===3 && $parts[0]==='me' && $parts[1]==='favorite-albums' && $method==='DELETE') { $aid=jsonId($parts[2]);$p->prepare('DELETE FROM favorite_albums WHERE user_id=? AND album_id=?')->execute([$u['id'],$aid]);out(['success'=>true,'album_id'=>$aid]); }
if($route==='me/recent' && $method==='GET') { [$limit,$offset]=pageArgs();$q=$p->prepare("SELECT t.*,COALESCE(a.title,'Pistas sin asignar a colección') AS album_title,COALESCE(a.artist,'') AS album_artist,COALESCE(a.cover,'') AS cover,r.played_at FROM recent_tracks r JOIN tracks t ON t.id=r.track_id LEFT JOIN albums a ON a.id=t.album_id WHERE r.user_id=? ORDER BY r.played_at DESC LIMIT ? OFFSET ?");$q->execute([$u['id'],$limit,$offset]);$items=[];foreach($q->fetchAll() as $r){$item=publicTrack($r);$item['played_at']=$r['played_at'];$items[]=$item;}out(['items'=>$items,'limit'=>$limit,'offset'=>$offset]); }
if($route==='charts' && $method==='GET') { [$limit,$offset]=pageArgs();$q=$p->prepare("SELECT t.*,COALESCE(a.title,'Pistas sin asignar a colección') AS album_title,COALESCE(a.artist,'') AS album_artist,COALESCE(a.cover,'') AS cover,SUM(r.play_count) AS plays,MAX(r.played_at) AS last_played FROM recent_tracks r JOIN tracks t ON t.id=r.track_id LEFT JOIN albums a ON a.id=t.album_id WHERE r.user_id=? GROUP BY t.id ORDER BY plays DESC,last_played DESC LIMIT ? OFFSET ?");$q->execute([$u['id'],$limit,$offset]);$items=[];foreach($q->fetchAll() as $r){$item=publicTrack($r);$item['plays']=(int)$r['plays'];$item['last_played']=$r['last_played'];$items[]=$item;}out(['items'=>$items,'limit'=>$limit,'offset'=>$offset]); }
if($route==='me/recent' && $method==='POST') { $b=body();$tid=jsonId($b['track_id']??'');$q=$p->prepare('SELECT 1 FROM tracks WHERE id=?');$q->execute([$tid]);if(!$q->fetchColumn())out(['error'=>'Pista no encontrada'],404);$now=gmdate('c');$p->prepare('INSERT INTO recent_tracks(user_id,track_id,played_at,play_count) VALUES(?,?,?,1) ON CONFLICT(user_id,track_id) DO UPDATE SET played_at=excluded.played_at,play_count=play_count+1')->execute([$u['id'],$tid,$now]);out(['success'=>true]); }
if($route==='me/playlists/shared' && $method==='GET') { $q=$p->prepare('SELECT DISTINCT p.id,p.name,p.description,p.owner_id,u.username AS owner_username,p.updated_at FROM playlists p JOIN users u ON u.id=p.owner_id LEFT JOIN playlist_shares ps ON ps.playlist_id=p.id WHERE p.owner_id<>? AND (p.is_shared=1 OR ps.shared_with_user_id=?) ORDER BY p.updated_at DESC');$q->execute([$u['id'],$u['id']]);out(['items'=>$q->fetchAll()]); }
if($route==='me/playlists' && $method==='GET') { $q=$p->prepare('SELECT p.*, (SELECT COUNT(*) FROM playlist_tracks pt WHERE pt.playlist_id=p.id) AS track_count FROM playlists p WHERE p.owner_id=? OR p.is_shared=1 OR EXISTS(SELECT 1 FROM playlist_shares ps WHERE ps.playlist_id=p.id AND ps.shared_with_user_id=? ) ORDER BY p.updated_at DESC');$q->execute([$u['id'],$u['id']]);out(['items'=>$q->fetchAll()]); }
if($route==='me/playlists' && $method==='POST') { $b=body();$name=trim((string)($b['name']??''));if($name===''||mb_strlen($name)>120)out(['error'=>'El nombre debe tener entre 1 y 120 caracteres'],400);$pid=id();$now=gmdate('c');$p->prepare('INSERT INTO playlists(id,owner_id,name,description,is_shared,created_at,updated_at) VALUES(?,?,?,?,0,?,?)')->execute([$pid,$u['id'],$name,substr(trim((string)($b['description']??'')),0,1000),$now,$now]);out(['id'=>$pid,'name'=>$name,'is_shared'=>false],201); }
if(count($parts)>=3 && $parts[0]==='me' && $parts[1]==='playlists') { $pid=$parts[2];$q=$p->prepare('SELECT p.*,u.username AS owner_username FROM playlists p JOIN users u ON u.id=p.owner_id WHERE p.id=? AND (p.owner_id=? OR p.is_shared=1 OR EXISTS(SELECT 1 FROM playlist_shares ps WHERE ps.playlist_id=p.id AND ps.shared_with_user_id=?))');$q->execute([$pid,$u['id'],$u['id']]);$pl=$q->fetch();if(!$pl)out(['error'=>'Lista no encontrada o sin permisos'],404);$isOwner=(string)$pl['owner_id']===(string)$u['id'];if(count($parts)===3 && $method==='GET'){$q=$p->prepare('SELECT t.*,a.title AS album_title,a.artist AS album_artist,a.cover AS cover,pt.position FROM playlist_tracks pt JOIN tracks t ON t.id=pt.track_id JOIN albums a ON a.id=t.album_id WHERE pt.playlist_id=? ORDER BY pt.position');$q->execute([$pid]);$items=[];foreach($q->fetchAll() as $r){$item=publicTrack($r);$item['position']=(int)$r['position'];$items[]=$item;}out(['playlist'=>$pl,'tracks'=>$items]);}if(count($parts)===4 && $parts[3]==='tracks' && $method==='POST'){if(!$isOwner)out(['error'=>'Solo el propietario puede editar esta lista'],403);$b=body();$tid=jsonId($b['track_id']??'');$q=$p->prepare('SELECT 1 FROM tracks WHERE id=?');$q->execute([$tid]);if(!$q->fetchColumn())out(['error'=>'Pista no encontrada'],404);$max=$p->prepare('SELECT COALESCE(MAX(position),0)+1 FROM playlist_tracks WHERE playlist_id=?');$max->execute([$pid]);$p->prepare('INSERT OR IGNORE INTO playlist_tracks(playlist_id,track_id,position,added_at) VALUES(?,?,?,?)')->execute([$pid,$tid,(int)$max->fetchColumn(),gmdate('c')]);$p->prepare('UPDATE playlists SET updated_at=? WHERE id=?')->execute([gmdate('c'),$pid]);out(['success'=>true],201);}if(count($parts)===5 && $parts[3]==='tracks' && $method==='DELETE'){if(!$isOwner)out(['error'=>'Solo el propietario puede editar esta lista'],403);$p->prepare('DELETE FROM playlist_tracks WHERE playlist_id=? AND track_id=?')->execute([$pid,$parts[4]]);out(['success'=>true]);}if(count($parts)===4 && $parts[3]==='share' && $method==='POST'){if(!$isOwner)out(['error'=>'Solo el propietario puede compartir esta lista'],403);$b=body();$share=(bool)($b['shared']??true);$username=trim((string)($b['username']??''));if($username!==''){$uq=$p->prepare('SELECT id FROM users WHERE username=? COLLATE NOCASE AND is_active=1');$uq->execute([$username]);$target=$uq->fetchColumn();if(!$target)out(['error'=>'Usuario destino no encontrado o inactivo'],404);if((string)$target===(string)$u['id'])out(['error'=>'No puedes compartir la lista contigo mismo'],400);if($share)$p->prepare('INSERT OR IGNORE INTO playlist_shares(playlist_id,shared_with_user_id,created_at) VALUES(?,?,?)')->execute([$pid,$target,gmdate('c')]);else $p->prepare('DELETE FROM playlist_shares WHERE playlist_id=? AND shared_with_user_id=?')->execute([$pid,$target]);out(['success'=>true,'shared_with'=>$username,'shared'=>$share]);}$p->prepare('UPDATE playlists SET is_shared=?,updated_at=? WHERE id=?')->execute([$share?1:0,gmdate('c'),$pid]);out(['success'=>true,'is_shared'=>$share]);} }
if($route==='admin/suggestions' && $method==='GET') { if((string)$u['role']!=='admin')out(['error'=>'Acceso reservado al administrador'],403);$q=$p->query('SELECT s.*,u.username FROM user_suggestions s JOIN users u ON u.id=s.user_id ORDER BY s.created_at DESC');out(['items'=>$q->fetchAll()]); }
if($route==='admin/suggestions' && $method==='PATCH') { if((string)$u['role']!=='admin')out(['error'=>'Acceso reservado al administrador'],403);$b=body();$sid=jsonId($b['id']??'');$status=trim((string)($b['status']??'reviewed'));if(!in_array($status,['new','reviewed','resolved','rejected'],true))out(['error'=>'Estado inválido'],400);$r=$p->prepare('UPDATE user_suggestions SET status=?,admin_response=?,responded_at=? WHERE id=?');$r->execute([$status,substr(trim((string)($b['admin_response']??'')),0,5000),gmdate('c'),$sid]);if($r->rowCount()===0){$c=$p->prepare('SELECT 1 FROM user_suggestions WHERE id=?');$c->execute([$sid]);if(!$c->fetchColumn())out(['error'=>'Sugerencia no encontrada'],404);}out(['success'=>true]);}
if($route==='me/suggestions' && $method==='POST') { $b=body();$subject=trim((string)($b['subject']??''));$message=trim((string)($b['message']??''));if($subject===''||$message===''||mb_strlen($subject)>160||mb_strlen($message)>5000)out(['error'=>'Asunto y mensaje son obligatorios; límites: 160 y 5000 caracteres'],400);$sid=id();$p->prepare('INSERT INTO user_suggestions(id,user_id,subject,message,status,created_at) VALUES(?,?,?,?,?,?)')->execute([$sid,$u['id'],$subject,$message,'new',gmdate('c')]);out(['success'=>true,'id'=>$sid,'status'=>'new'],201); }
out(['error'=>'Endpoint no encontrado','path'=>$route],404);
} catch(Throwable $e) { error_log('HLSML mobile API: '.get_class($e).': '.$e->getMessage()); out(['error'=>'Error interno de API'],500); }

