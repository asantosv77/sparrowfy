<?php
declare(strict_types=1);
require __DIR__.'/lib.php';lab_auth_require_album_manager();
require_once __DIR__.'/lib/external_album.php';require_once __DIR__.'/lib/external_cover.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
try{
 if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);throw new InvalidArgumentException('POST requerido');}
 lab_require_same_origin_post();$action=(string)($_POST['action']??'');$provider=(string)($_POST['provider']??'musicbrainz');
 if(!in_array($provider,['musicbrainz','itunes'],true))throw new InvalidArgumentException('Fuente inválida');
 $bucket=(int)floor(time()/60);$rate=$_SESSION['album_discovery_rate']??['bucket'=>$bucket,'count'=>0];if(($rate['bucket']??0)!==$bucket)$rate=['bucket'=>$bucket,'count'=>0];if(($rate['count']??0)>=90){http_response_code(429);throw new RuntimeException('Demasiadas consultas; espera un minuto.');}$rate['count']++;$_SESSION['album_discovery_rate']=$rate;session_write_close();
 if($action==='search'){
  $artist=trim((string)($_POST['artist']??''));$title=trim((string)($_POST['title']??''));$year=trim((string)($_POST['year']??''));
  if($artist===''||$title===''||mb_strlen($artist)>160||mb_strlen($title)>160||preg_match('/[\x00-\x1f]/',$artist.$title)||($year!==''&&!preg_match('/^[12][0-9]{3}$/D',$year)))throw new InvalidArgumentException('Indica artista y álbum válidos; el año es opcional.');
  $result=['items'=>sfa_search($provider,$artist,$title,$year)];
 }elseif($action==='release')$result=['release'=>sfa_release($provider,(string)($_POST['id']??''))];
 elseif($action==='rank'){$track=json_decode((string)($_POST['track']??''),true);$items=json_decode((string)($_POST['items']??''),true);if(!is_array($track)||!is_array($items)||count($items)>3||!is_string($track['title']??null)||!is_string($track['artist']??null)||strlen((string)($_POST['track']??''))>4096||strlen((string)($_POST['items']??''))>12000)throw new InvalidArgumentException('Candidatos inválidos');$preferLyrics=in_array(strtolower((string)($_POST['prefer_lyrics']??'')),['1','true','on','yes'],true);$result=['items'=>sfa_rank($track,$items,$preferLyrics)];}
 else throw new InvalidArgumentException('Acción inválida');
 echo json_encode(['success'=>true]+$result,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(Throwable $e){if(http_response_code()<400)http_response_code($e instanceof InvalidArgumentException?400:502);echo json_encode(['success'=>false,'error'=>$e instanceof JsonException?'El proveedor devolvió una respuesta incompatible.':($e instanceof RuntimeException||$e instanceof InvalidArgumentException?$e->getMessage():'No se pudo consultar el catálogo; puedes usar los enlaces manuales.')],JSON_UNESCAPED_UNICODE);}
