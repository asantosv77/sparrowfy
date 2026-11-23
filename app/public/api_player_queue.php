<?php declare(strict_types=1);
require __DIR__.'/lib.php';require_once __DIR__.'/lib/loudness.php';require_once __DIR__.'/lib/cover_url.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
try{
 $p=lab_auth_pdo();$uid=(string)(lab_auth_current_account()['id']??'');
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(($_POST['action']??'')!=='save')throw new InvalidArgumentException('Acción inválida');
  $name=trim((string)($_POST['name']??''));if($name===''||mb_strlen($name)>120||preg_match('/[\x00-\x1F\x7F]/u',$name))throw new InvalidArgumentException('Indica un nombre de hasta 120 caracteres');
  $ids=json_decode((string)($_POST['tracks']??''),true);if(!is_array($ids)||count($ids)>500||!$ids)throw new InvalidArgumentException('Cola inválida o vacía');foreach($ids as $candidate)if(!is_string($candidate)||!preg_match('/^[a-f0-9]{16}$/D',$candidate))throw new InvalidArgumentException('Pista inválida');$ids=array_values(array_unique($ids));
  $check=$p->prepare('SELECT id FROM tracks WHERE id=?');foreach($ids as $id){if(!is_string($id)||!preg_match('/^[a-f0-9]{16}$/D',$id))throw new InvalidArgumentException('Pista inválida');$check->execute([$id]);if(!$check->fetchColumn())throw new RuntimeException('Una pista ya no está disponible');$check->closeCursor();}
  $pid=bin2hex(random_bytes(16));$now=gmdate('c');$p->beginTransaction();
  try{$p->prepare("INSERT INTO playlists(id,owner_id,name,description,is_shared,created_at,updated_at) VALUES(?,?,?,'',0,?,?)")->execute([$pid,$uid,$name,$now,$now]);$q=$p->prepare('INSERT INTO playlist_tracks(playlist_id,track_id,position,added_at) VALUES(?,?,?,?)');foreach($ids as $i=>$id)$q->execute([$pid,$id,$i,$now]);$p->commit();}catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
  echo json_encode(['ok'=>true,'playlist_id'=>$pid]);exit;
 }
 if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);exit('{}');}
 $term=mb_substr(trim((string)($_GET['q']??'')),0,100);$id=(string)($_GET['id']??'');
 if($id!==''&&!preg_match('/^[a-f0-9]{16}$/D',$id))throw new InvalidArgumentException('Pista inválida');
 if($id===''){if(mb_strlen($term)<2){echo '{"tracks":[]}';exit;}$escaped=str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$term);$q=$p->prepare("SELECT id,album_id,name FROM tracks WHERE name LIKE ? ESCAPE '\\' ORDER BY name COLLATE NOCASE LIMIT 20");$q->execute(['%'.$escaped.'%']);}else{$q=$p->prepare('SELECT id,album_id,name FROM tracks WHERE id=?');$q->execute([$id]);}
 $rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();$albums=[];$result=[];
 foreach($rows as $r){$aid=(string)$r['album_id'];if(!array_key_exists($aid,$albums))$albums[$aid]=lab_load_album($aid);foreach($albums[$aid]['tracks']??[] as $t)if(($t['id']??'')===$r['id']){$t['collection_title']=$albums[$aid]['title']??'';$t['collection_artist']=$albums[$aid]['artist']??'';$t['collection_cover']=sf_cover_url($albums[$aid]);$t['origin_album']=$aid;$result[]=$t;break;}}
 echo json_encode(['tracks'=>sf_loudness_attach($result)],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(Throwable $e){if(http_response_code()<400)http_response_code(400);echo json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
