<?php
/** Optional display lookup: uses the same strict matcher as loose tracks.
 * Does not change track/album metadata; an ambiguous result stays empty.
 * External transport lives in lib/external_cover.php (cov_get).
 */
require __DIR__.'/lib.php';lab_auth_require_album_manager();
require_once __DIR__.'/lib/text_artwork.php';
require_once __DIR__.'/lib/external_cover.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit('{}');}
try{
 $id=(string)($_POST['track_id']??'');
 if(!preg_match('/^[a-f0-9]{16}$/D',$id))throw new InvalidArgumentException('Pista inválida');
 $q=lab_auth_pdo()->prepare('SELECT album_id FROM tracks WHERE id=?');$q->execute([$id]);$aid=$q->fetchColumn();$q->closeCursor();
 $album=$aid?lab_load_album((string)$aid):null;$track=null;
 foreach($album['tracks']??[] as $t)if(($t['id']??'')===$id){$track=$t;break;}
 if(!$track){http_response_code(404);exit('{}');}
 if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
 $pairs=sf_art_pairs($track);$cover=null;
 if($pairs){
  $key=hash('sha256',json_encode([$id,$track['name']??'',$track['title']??'',$track['artist']??'',$track['album']??'',$track['duration']??0]));
  $dir=LAB_DATA.'/artwork-matches';if(!is_dir($dir))@mkdir($dir,0770,true);$file=$dir.'/'.$key.'.json';
  $cached=is_file($file)?json_decode((string)@file_get_contents($file),true):null;
  if(is_array($cached)&&($cached['expires']??0)>time())$cover=$cached['cover']??null;
  else{
   $query=http_build_query(['term'=>implode(' ',$pairs[0]),'entity'=>'song','country'=>'US','limit'=>25]);
   $response=cov_get('https://itunes.apple.com/search?'.$query);
   $data=json_decode($response['body'],true);
   if($response['code']!==200||!is_array($data)){echo json_encode(['cover'=>null,'unavailable'=>true]);exit;}
   $cover=sf_art_choose($track,$pairs,$data['results']??[]);
   @file_put_contents($file,json_encode(['expires'=>time()+86400,'cover'=>$cover]),LOCK_EX);
  }
 }
 echo json_encode(['cover'=>$cover],JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){error_log('Album track artwork: '.$e->getMessage());http_response_code(400);echo json_encode(['cover'=>null,'unavailable'=>true]);}
