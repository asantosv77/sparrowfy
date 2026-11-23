<?php
declare(strict_types=1);
/** Deletes only verified retained source files; never recursively deletes a directory. */
function sfsc_within(string $path,string $root):bool{return str_starts_with($path,$root.'/');}
function sfsc_dir(string $path,string $root):?string {
 $dir=realpath($path);if(!$dir||!sfsc_within($dir,$root))return null;
 $rel=substr($path,strlen($root)+1);$walk=$root;
 foreach(explode('/',$rel) as $part){if($part===''||$part==='.'||$part==='..')return null;$walk.='/'.$part;if(is_link($walk))return null;}
 return $dir;
}
function sfsc_playlist(string $path,string $dir,int $depth=0):bool {
 if($depth>2||is_link($path)||!is_file($path)||filesize($path)>2097152)return false;
 $real=realpath($path);if(!$real||!sfsc_within($real,$dir))return false;
 $text=(string)file_get_contents($path);if(!str_starts_with(ltrim($text),'#EXTM3U'))return false;
 $count=0;$variant=false;$end=str_contains($text,'#EXT-X-ENDLIST');
 foreach(preg_split('/\r?\n/',$text) as $line){$line=trim($line);if($line===''||$line[0]==='#')continue;
  if(++$count>20000||preg_match('~[\\\\:?\x00-\x20]|(^|/)\.\.(/|$)|^/~',$line))return false;
  $item=dirname($path).'/'.$line;$actual=realpath($item);if(!$actual||!sfsc_within($actual,$dir)||is_link($item)||!is_file($item)||filesize($item)<1)return false;
  if(str_ends_with($line,'.m3u8')){$variant=true;if(!sfsc_playlist($item,$dir,$depth+1))return false;}
 }
 return $count>0&&($variant||$end);
}
function sfsc_table(PDO $db,string $name):bool {$q=$db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$name]);$yes=(bool)$q->fetchColumn();$q->closeCursor();return $yes;}
/** Snapshot while the caller holds the database writer for execution. */
function sfsc_busy(PDO $db):array {
 $ids=[];$paths=[];$jobs=[];
 if(sfsc_table($db,'sf_processing_jobs'))foreach($db->query("SELECT id,payload FROM sf_processing_jobs WHERE state IN ('queued','running')")->fetchAll(PDO::FETCH_ASSOC) as $j){$jobs[$j['id']]=true;$data=json_decode($j['payload'],true)?:[];foreach(['track_id','replace_track_id'] as $key)if(!empty($data[$key]))$ids[$data[$key]]=true;foreach(['expected_hls','hls','file','source'] as $key)if(is_string($data[$key]??null))$paths[]=$data[$key];}
 if(sfsc_table($db,'audio_edit_jobs'))foreach($db->query("SELECT id,track_id,parameters FROM audio_edit_jobs WHERE state IN ('queued','running')")->fetchAll(PDO::FETCH_ASSOC) as $j){$jobs[$j['id']]=true;$ids[$j['track_id']]=true;$data=json_decode($j['parameters'],true)?:[];if(is_string($data['_expected_hls']??null))$paths[]=$data['_expected_hls'];}
 if($ids&&sfsc_table($db,'tracks')){$q=$db->prepare('SELECT hls_path FROM tracks WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).')');$q->execute(array_keys($ids));foreach($q->fetchAll(PDO::FETCH_COLUMN) as $hls)$paths[]=$hls;$q->closeCursor();}
 return ['jobs'=>$jobs,'paths'=>$paths];
}
function sfsc_protected(string $dir,array $busy):bool {
 $marker=json_decode((string)@file_get_contents($dir.'/.sf-output.json'),true)?:[];
 if(!empty($busy['jobs'][$marker['task_id']??'']))return true;
 foreach($busy['paths'] as $path){$real=realpath(str_starts_with($path,'/')?$path:LAB_ROOT.'/'.$path);if($real&&($real===$dir||sfsc_within($real,$dir)))return true;}
 return false;
}
function sfsc_sources(string $dir):array {
 $found=[];foreach(scandir($dir)?:[] as $name){if(!preg_match('/^source(?:\.[A-Za-z0-9_-]+)?$/D',$name))continue;$path=$dir.'/'.$name;if(is_link($path)||!is_file($path))continue;$real=realpath($path);if(!$real||dirname($real)!==$dir)continue;$st=stat($path);$found[]=['name'=>$name,'bytes'=>(int)$st['size'],'mtime'=>(int)$st['mtime'],'inode'=>(int)$st['ino']];}return $found;
}
function sfsc_scan(PDO $db):array {
 $root=realpath(LAB_HLS);if(!$root)return ['files'=>[],'count'=>0,'bytes'=>0,'protected'=>0,'incomplete'=>0];
 $busy=sfsc_busy($db);$plan=[];$bytes=0;$protected=0;$incomplete=0;$seen=[];
 foreach(array_merge(glob($root.'/*/info.json')?:[],glob($root.'/*/*/info.json')?:[]) as $info){
  $dir=sfsc_dir(dirname($info),$root);if(!$dir||isset($seen[$dir])||is_link($info))continue;$seen[$dir]=true;$sources=sfsc_sources($dir);if(!$sources)continue;
  if(sfsc_protected($dir,$busy)){$protected+=count($sources);continue;}
  $meta=json_decode((string)file_get_contents($info),true);
  if(!is_array($meta)||!sfsc_playlist($dir.'/master.m3u8',$dir)){$incomplete+=count($sources);continue;}
  foreach($sources as $source){$plan[]=['dir'=>substr($dir,strlen($root)+1)]+$source;$bytes+=$source['bytes'];}
 }
 return ['files'=>$plan,'count'=>count($plan),'bytes'=>$bytes,'protected'=>$protected,'incomplete'=>$incomplete];
}
function sfsc_execute(PDO $db,array $files):array {
 $root=realpath(LAB_HLS);if(!$root)throw new RuntimeException('Almacenamiento no disponible');$groups=[];
 foreach($files as $file){if(!is_array($file)||!is_string($file['dir']??null)||!preg_match('/^source(?:\.[A-Za-z0-9_-]+)?$/D',(string)($file['name']??'')))throw new RuntimeException('Plan de limpieza inválido');$groups[$file['dir']][]=$file;}
 $result=['deleted'=>0,'bytes'=>0,'skipped'=>0,'errors'=>0];
 foreach($groups as $relative=>$group){
  $db->exec('BEGIN IMMEDIATE');try{
   $dir=sfsc_dir($root.'/'.$relative,$root);if(!$dir||sfsc_protected($dir,sfsc_busy($db))||!sfsc_playlist($dir.'/master.m3u8',$dir)){$result['skipped']+=count($group);$db->exec('COMMIT');continue;}
   $info=$dir.'/info.json';$meta=!is_link($info)?json_decode((string)@file_get_contents($info),true):null;if(!is_array($meta)){$result['skipped']+=count($group);$db->exec('COMMIT');continue;}
   foreach($group as $file){$path=$dir.'/'.$file['name'];clearstatcache(true,$path);$st=!is_link($path)&&is_file($path)?stat($path):false;
    if(!$st||(int)$st['size']!==(int)$file['bytes']||(int)$st['mtime']!==(int)$file['mtime']||(int)$st['ino']!==(int)$file['inode']||realpath($path)!==$path){$result['skipped']++;continue;}
    if(@unlink($path)){$result['deleted']++;$result['bytes']+=(int)$st['size'];}else $result['errors']++;
   }
   if(!sfsc_sources($dir)){$meta['source_file']=null;$meta['source_deleted']=true;$meta['source_cleanup_at']=gmdate('c');$tmp=$info.'.'.bin2hex(random_bytes(5)).'.tmp';$written=file_put_contents($tmp,json_encode($meta,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE),LOCK_EX);if($written!==false)chmod($tmp,fileperms($info)&0777);if($written===false||!@rename($tmp,$info)){$result['errors']++;@unlink($tmp);}}
   $db->exec('COMMIT');
  }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
 }
 return $result;
}
