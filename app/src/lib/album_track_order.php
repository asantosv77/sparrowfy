<?php
declare(strict_types=1);
/** A collection orders its references without modifying the source collection. */
function sf_album_order_view(array $album):array {
 $order=$album['track_order']??[];if(!is_array($order)||!$order)return $album;
 $tracks=[];foreach($album['tracks']??[] as $t)$tracks[$t['id']]=$t;$sorted=[];
 foreach($order as $id)if(is_string($id)&&isset($tracks[$id])){$sorted[]=$tracks[$id];unset($tracks[$id]);}
 $album['tracks']=array_merge($sorted,array_values($tracks));return $album;
}
function sf_album_order_validate(array $visible,array $order,array $expected):void {
 foreach([$order,$expected] as $ids){if(!array_is_list($ids)||count($ids)>10000)throw new InvalidArgumentException('Lista de pistas inválida');foreach($ids as $id)if(!is_string($id)||!preg_match('/^[a-f0-9]{16}$/D',$id))throw new InvalidArgumentException('Identificador de pista inválido');if(count(array_unique($ids))!==count($ids))throw new InvalidArgumentException('No repitas pistas');}
 if($expected!==$visible)throw new RuntimeException('La lista cambió. Recarga el álbum antes de reordenar.');
 $a=$visible;$b=$order;sort($a);sort($b);if($a!==$b)throw new InvalidArgumentException('El orden debe contener todas las pistas del álbum, sin añadir ni quitar ninguna');
}
function sf_album_order_save(string $aid,array $order,array $expected):array {
 if(!preg_match('/^[a-f0-9]{16}$/D',$aid))throw new InvalidArgumentException('Álbum inválido');
 require_once __DIR__.'/track_reuse.php';
 $reuse=sf_reuse_lock();$lock=null;$db=null;$transaction=false;$published=false;$temp=null;$before=null;
 try{
  $dir=LAB_ALBUMS.'/'.$aid;if(!is_dir($dir))throw new RuntimeException('Álbum no encontrado');
  $lock=fopen($dir.'/.processing-append.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Álbum ocupado');
  $album=lab_load_album($aid);if(!$album)throw new RuntimeException('Álbum no encontrado');
  $view=sf_reuse_view($album);sf_album_order_validate(array_column($view['tracks']??[],'id'),$order,$expected);
  $db=lab_auth_pdo();$db->exec('BEGIN IMMEDIATE');$transaction=true;
  $q=$db->prepare('SELECT metadata_json FROM albums WHERE id=?');$q->execute([$aid]);$raw=$q->fetchColumn();if($raw===false)throw new RuntimeException('Álbum no encontrado');$q->closeCursor();
  $extra=$raw?json_decode((string)$raw,true,512,JSON_THROW_ON_ERROR):[];$extra['track_order']=$order;
  $album['track_order']=$order;$album=sf_album_order_view($album);
  if(($album['album_type']??'')==='online_links')$extra['tracks']=$album['tracks'];
  $file=$dir.'/album.json';$before=file_get_contents($file);if($before===false)throw new RuntimeException('No se pudo leer el álbum');
  $json=json_encode($album,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);$temp=$file.'.order-'.bin2hex(random_bytes(8)).'.tmp';if(file_put_contents($temp,$json,LOCK_EX)!==strlen($json))throw new RuntimeException('No se pudo preparar el orden');chmod($temp,fileperms($file)&0777);
  $update=$db->prepare('UPDATE tracks SET position=? WHERE album_id=? AND id=?');foreach($album['tracks']??[] as $i=>$track)$update->execute([$i,$aid,$track['id']]);
  $db->prepare('UPDATE albums SET metadata_json=?,updated_at=? WHERE id=?')->execute([json_encode($extra,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),gmdate('c'),$aid]);
  if(!rename($temp,$file))throw new RuntimeException('No se pudo guardar el orden');$published=true;
  $db->exec('COMMIT');$transaction=false;return $order;
 }catch(Throwable $e){
  if($transaction){$db->exec('ROLLBACK');$transaction=false;}
  if($published&&is_string($before)){if(file_put_contents($temp,$before,LOCK_EX)!==strlen($before)||!rename($temp,$file))throw new RuntimeException('No se pudo restaurar el respaldo de orden; solicita revisión al administrador',0,$e);}
  throw $e;
 }finally{if($temp&&is_file($temp))unlink($temp);if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}flock($reuse,LOCK_UN);fclose($reuse);}
}
