<?php
declare(strict_types=1);
require __DIR__.'/lib.php';require_once __DIR__.'/lib/personal_library.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');
try{
 $u=lab_auth_current_account();if(!$u){http_response_code(401);throw new RuntimeException('Sesion caducada');}$uid=(string)$u['id'];$p=lab_auth_pdo();
 if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
 $kind=(string)($_GET['kind']??'state');
 if($kind==='state'){$favorites=sf_personal_rows($p,'SELECT track_id FROM favorites WHERE user_id=?',[$uid]);$lists=sf_personal_rows($p,'SELECT p.id,p.name,(SELECT count(*) FROM playlist_tracks pt WHERE pt.playlist_id=p.id) AS track_count FROM playlists p WHERE p.owner_id=? ORDER BY p.updated_at DESC',[$uid]);$out=['favorites'=>array_column($favorites,'track_id'),'favorite_albums'=>sf_fav_albums_ids($p,$uid),'playlists'=>$lists];}
 elseif($kind==='memberships'){$tid=(string)($_GET['track_id']??'');$out=['items'=>sf_personal_rows($p,'SELECT p.id,p.name,EXISTS(SELECT 1 FROM playlist_tracks pt WHERE pt.playlist_id=p.id AND pt.track_id=?) AS selected FROM playlists p WHERE p.owner_id=? ORDER BY p.name COLLATE NOCASE',[$tid,$uid])];}
 elseif($kind==='share')$out=sf_personal_shared($p,(string)($_GET['token']??''));
 elseif($kind==='album'){
  $aid=(string)($_GET['album_id']??'');
  if(!preg_match('/^[a-f0-9]{16}$/D',$aid))throw new RuntimeException('Album invalido');
  $a=lab_load_album($aid);if(!$a)throw new RuntimeException('Album no encontrado');
  $out=['album'=>['id'=>(string)($a['id']??$aid),'title'=>(string)($a['title']??''),'artist'=>(string)($a['artist']??'')],'tracks'=>array_values(array_map(static function(array $t)use($a):array{
   return ['id'=>(string)($t['id']??''),'name'=>(string)($t['name']??$t['title']??'Pista'),'hls'=>(string)($t['hls']??''),'duration'=>(float)($t['duration']??0),'artist'=>(string)($t['artist']??$a['artist']??''),'album'=>(string)($a['title']??''),'source_type'=>(string)($t['source_type']??'')];
  },$a['tracks']??[]))];
 }
 elseif($kind==='favorite_albums'){
  $out=['items'=>array_values(array_map(static function(array $r):array{
   return ['album_id'=>(string)$r['album_id'],'created_at'=>(string)$r['created_at']];
  },sf_fav_albums_rows($p,$uid)))];
 }
 elseif(in_array($kind,['favorites','recent','playlist'],true))$out=['tracks'=>sf_personal_tracks($p,$uid,$kind,(string)($_GET['playlist_id']??''))];
 else throw new RuntimeException('Consulta invalida');
 }else{
 if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);throw new RuntimeException('POST requerido');}
 lab_require_same_origin_post();if(!lab_auth_verify_csrf((string)($_POST['csrf_token']??''))){http_response_code(403);throw new RuntimeException('Recarga la pagina para renovar la sesion');}
 $out=sf_personal_action($p,$uid,(string)($_POST['action']??''),$_POST);
 }
 echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(Throwable $e){if(http_response_code()<400)http_response_code(400);echo json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}

