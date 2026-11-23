<?php
declare(strict_types=1);
require_once __DIR__.'/track_reuse.php';require_once __DIR__.'/music_charts.php';
function sfcc_schema(PDO $db):void {$db->exec('CREATE TABLE IF NOT EXISTS sf_chart_copies(user_id TEXT NOT NULL,request_key TEXT NOT NULL,album_id TEXT NOT NULL,chart_key TEXT NOT NULL,created_at INTEGER NOT NULL,PRIMARY KEY(user_id,request_key))');}
function sfcc_selection(PDO $db,string $key):array {
 $q=$db->prepare('SELECT * FROM sf_music_charts WHERE chart_key=? AND enabled=1');$q->execute([$key]);$chart=$q->fetch(PDO::FETCH_ASSOC);if(!$chart||empty($chart['album_id']))throw new InvalidArgumentException('El TOP ya no está disponible.');
 $rows=sfc_entry_rows($db,$key);$tracks=[];
 foreach($rows as $row)if($row['state']==='ready'&&$row['track_id']){$live=sfc_live_track($db,$row['track_id']);if($live)$tracks[]=$live;}
 $ids=array_column(array_column($tracks,'track'),'id');$date=(new DateTimeImmutable('now',new DateTimeZone('America/Mexico_City')))->format('Y-m-d');
 return ['chart'=>$chart,'tracks'=>$tracks,'ids'=>$ids,'total'=>count($rows),'name'=>mb_substr((string)$chart['title'],0,160).' · '.$date,'signature'=>hash('sha256',json_encode([$chart['last_week'],$ids]))];
}
/** Freeze the published selection as references; audio ownership changes only during retirement. */
function sfcc_copy(PDO $db,array $account,string $key,string $request,string $expected):array {
 if(($account['role']??'')!=='admin'&&empty($account['can_manage_albums']))throw new DomainException('Solo los gestores pueden copiar un TOP.');
 if(!preg_match('/^[a-f0-9]{32}$/D',$request)||!preg_match('/^[a-f0-9]{64}$/D',$expected))throw new InvalidArgumentException('Solicitud inválida.');
 sfcc_schema($db);$guard=fopen(sfc_root().'/.worker.lock','c');if(!$guard||!flock($guard,LOCK_EX|LOCK_NB))throw new RuntimeException('Se está actualizando el ranking. Vuelve a intentarlo en unos segundos.');$lock=null;$file=null;$transaction=false;$new=false;$committed=false;
 try{
  $lock=sf_reuse_lock();$q=$db->prepare('SELECT album_id FROM sf_chart_copies WHERE user_id=? AND request_key=?');$q->execute([(string)$account['id'],$request]);$prior=$q->fetchColumn();if($prior){if(!lab_load_album($prior))throw new RuntimeException('La copia anterior ya no está disponible.');return ['album_id'=>$prior,'reused'=>true];}
  $selection=sfcc_selection($db,$key);if(!hash_equals($selection['signature'],$expected))throw new RuntimeException('El TOP cambió. Vuelve a abrir la copia para revisar la selección.');if(!$selection['tracks'])throw new InvalidArgumentException('Todavía no hay pistas disponibles para copiar.');
  $aid=substr(hash('sha256','chart-copy|'.$account['id'].'|'.$request),0,16);$dir=LAB_ALBUMS.'/'.$aid;$file=$dir.'/album.json';
  if(is_file($file)){$orphan=json_decode((string)file_get_contents($file),true);$q=$db->prepare('SELECT 1 FROM albums WHERE id=?');$q->execute([$aid]);if($q->fetchColumn()||($orphan['chart_copy']['request_key']??'')!==$request||($orphan['chart_copy']['copied_by']??'')!==(string)$account['id'])throw new RuntimeException('Este identificador ya corresponde a otra colección.');}
  if(!is_dir($dir)&&!mkdir($dir,02770,true))throw new RuntimeException('No se pudo preparar la colección.');$new=true;
  $album=['id'=>$aid,'title'=>$selection['name'],'artist'=>(string)$selection['chart']['title'],'created_at'=>gmdate('c'),'tracks'=>[],'track_order'=>$selection['ids'],'chart_copy'=>['chart_key'=>$key,'source_album'=>$selection['chart']['album_id'],'edition'=>$selection['chart']['last_week'],'copied_at'=>gmdate('c'),'request_key'=>$request,'copied_by'=>(string)$account['id'],'available'=>count($selection['ids']),'ranking_total'=>$selection['total']]];
  require_once __DIR__.'/album_delete_transaction.php';require_once __DIR__.'/sqlite_catalog_sync.php';
  $db->beginTransaction();$transaction=true;
  $db->prepare('INSERT INTO sf_chart_copies VALUES(?,?,?,?,?)')->execute([(string)$account['id'],$request,$aid,$key,time()]);
  lab_sqlite_sync_album($album,$db);
  $q=$db->prepare('INSERT INTO sf_album_track_refs(album_id,source_album_id,track_id,created_at) VALUES(?,?,?,?)');foreach($selection['tracks'] as $live)$q->execute([$aid,$live['album_id'],$live['track']['id'],gmdate('c')]);
  $temp=$dir.'/.copy-'.bin2hex(random_bytes(8)).'.tmp';$json=json_encode($album,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);if(file_put_contents($temp,$json,LOCK_EX)!==strlen($json))throw new RuntimeException('No se pudo preparar la colección.');chmod($temp,0660);if(!rename($temp,$file))throw new RuntimeException('No se pudo publicar la colección.');$db->commit();$transaction=false;$committed=true;
  return ['album_id'=>$aid,'title'=>$album['title'],'copied'=>count($selection['ids']),'ranking_total'=>$selection['total'],'reused'=>false];
 }catch(Throwable $e){if($transaction)$db->rollBack();if($new&&!$committed&&$file&&is_file($file))unlink($file);if(isset($temp)&&is_file($temp))unlink($temp);throw $e;}
 finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}flock($guard,LOCK_UN);fclose($guard);}
}
/** Prefer permanent non-TOP owners. Playlist-only sources live in the loose collection. */
function sfcc_retirement_target(PDO $db,string $source,string $tid,array $albums,bool $playlist,bool $favorite):?string {
 $q=$db->prepare('SELECT album_id FROM sf_album_track_refs WHERE source_album_id=? AND track_id=? ORDER BY created_at,album_id');$q->execute([$source,$tid]);$ordinary=[];$copies=[];$tops=[];
 foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){$a=$albums[$id]??lab_load_album($id);if(!$a)continue;if(!empty($a['music_chart']))$tops[]=$id;elseif(!empty($a['chart_copy']))$copies[]=$id;else$ordinary[]=$id;}
 if($ordinary)return $ordinary[0];if($playlist)return 'fffffffffffffffe';if($copies)return $copies[0];if($tops)return $tops[0];return $favorite?'fffffffffffffffe':null;
}
