<?php
declare(strict_types=1);
require_once __DIR__.'/processing_queue.php';
require_once __DIR__.'/track_lookup.php';
function sfd_db():PDO {
 static $ready=false;$p=lab_auth_pdo();if(!$ready){$p->exec('CREATE TABLE IF NOT EXISTS sf_audio_download_permissions(user_id TEXT PRIMARY KEY,enabled INTEGER NOT NULL DEFAULT 0)');$ready=true;}return $p;
}
function sfd_allowed(string $uid):bool {
 $q=sfd_db()->prepare('SELECT d.enabled FROM sf_audio_download_permissions d JOIN users u ON u.id=d.user_id WHERE d.user_id=? AND u.is_active=1');$q->execute([$uid]);return (int)$q->fetchColumn()===1;
}
function sfd_root():string {
 $d=LAB_DATA.'/audio-downloads';if(!is_dir($d)&&!mkdir($d,02770,true))throw new RuntimeException('No se pudo preparar el almacenamiento temporal');file_put_contents($d.'/.htaccess',"Require all denied\n");return $d;
}
function sfd_cleanup():void {
 foreach(glob(sfd_root().'/*')?:[] as $f)if(preg_match('/^[a-f0-9]{32}\.(m4a|mp3|m3u8|part)$/D',basename($f))&&is_file($f)&&!is_link($f)&&filemtime($f)<time()-86400)unlink($f);
}
function sfd_local(string $base,string $uri,string $root):string {
 if($uri===''||preg_match('~[\x00-\x1f?#]|^[a-z][a-z0-9+.-]*:|^[/\\\\]~i',$uri))throw new RuntimeException('Referencia de audio no válida');
 $p=realpath($base.'/'.$uri);if(!$p||!str_starts_with($p,$root.'/')||!is_file($p))throw new RuntimeException('Falta un segmento de audio');return $p;
}
/** Choose the highest BANDWIDTH from the stored master, never a remote URL. */
function sfd_manifest(array $track):array {
 $hls=(string)($track['hls']??'');$root=realpath(dirname(__DIR__).'/hls_out');$master=realpath(dirname(__DIR__).'/'.$hls);
 if(!str_starts_with($hls,'hls_out/')||!$root||!$master||!str_starts_with($master,$root.'/')||!is_file($master))throw new RuntimeException('Esta pista no tiene audio local disponible');
 return sfd_read_manifest($master);
}
function sfd_read_manifest(string $master):array {
 $trackRoot=dirname($master);$lines=file($master,FILE_IGNORE_NEW_LINES);$variants=[];$bw=null;
 foreach($lines as $line){$line=trim($line);if(str_starts_with($line,'#EXT-X-STREAM-INF:')){$bw=preg_match('/(?:^|[:,])BANDWIDTH=(\d+)/',$line,$m)?(int)$m[1]:0;}elseif($line!==''&&$line[0]!=='#'&&$bw!==null){$variants[$line]=$bw;$bw=null;}}
 if($variants){arsort($variants,SORT_NUMERIC);$playlist=sfd_local($trackRoot,(string)array_key_first($variants),$trackRoot);}else $playlist=$master;
 $out=[];$segments=0;
 foreach(file($playlist,FILE_IGNORE_NEW_LINES) as $line){$line=trim($line);
  if(str_starts_with($line,'#EXT-X-KEY:'))throw new RuntimeException('No se permite exportar audio cifrado');
  if(str_starts_with($line,'#EXT-X-MAP:'))$line=preg_replace_callback('/URI="([^"]+)"/',fn($m)=>'URI="'.sfd_local(dirname($playlist),$m[1],$trackRoot).'"',$line);
  elseif($line!==''&&$line[0]!=='#'){$line=sfd_local(dirname($playlist),$line,$trackRoot);$segments++;}
  elseif(preg_match('/URI\s*=/', $line))throw new RuntimeException('Manifiesto de audio no compatible');
  $out[]=$line;
 }
 if(!$segments||!in_array('#EXT-X-ENDLIST',$out,true))throw new RuntimeException('La pista todavía no está completa');
 return ['body'=>implode("\n",$out)."\n",'bandwidth'=>$variants?(int)reset($variants):0];
}
function sfd_build(array $track,string $mode,string $id,?callable $progress=null):array {
 if(!in_array($mode,['m4a','mp3'],true)||!preg_match('/^[a-f0-9]{32}$/D',$id))throw new RuntimeException('Formato no válido');
 return sfd_encode($track,sfd_manifest($track),$mode,$id,$progress);
}
/** Internal encoder accepts only a manifest already validated by sfd_read_manifest. */
function sfd_encode(array $track,array $manifest,string $mode,string $id,?callable $progress=null):array {
 if(!in_array($mode,['m4a','mp3'],true)||!preg_match('/^[a-f0-9]{32}$/D',$id))throw new RuntimeException('Formato no válido');
 $root=sfd_root();$input=$root.'/'.$id.'.m3u8';$tmp=$root.'/'.$id.'.part';$dest=$root.'/'.$id.'.'.$mode;
 file_put_contents($input,$manifest['body']);chmod($input,0600);$proc=null;$pipes=[];$success=false;
 try{
  $cmd=['ffmpeg','-nostdin','-hide_banner','-loglevel','error','-y','-threads','1','-protocol_whitelist','file','-allowed_extensions','ALL','-i',$input,'-map','0:a:0','-vn'];
  $cmd=array_merge($cmd,$mode==='m4a'?['-c:a','copy','-movflags','+faststart','-f','ipod']:['-c:a','libmp3lame','-q:a','2','-threads','1','-f','mp3']);
  $cmd=array_merge($cmd,['-metadata','title='.(string)($track['title']??$track['name']??'Pista'),'-metadata','artist='.(string)($track['artist']??''),'-progress','pipe:1',$tmp]);
  $proc=proc_open($cmd,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc))throw new RuntimeException('No se pudo iniciar la preparación');
  stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$deadline=microtime(true)+3600;$last=0;$exit=-1;$buffer='';
  while(true){$buffer.=stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);if(strlen($buffer)>65536)$buffer=substr($buffer,-65536);
   if($progress&&microtime(true)-$last>1){preg_match_all('/out_time_us=(\d+)/',$buffer,$m);$us=(float)($m[1]?end($m[1]):0);$pct=($track['duration']??0)>0?min(95,(int)($us/1000000/(float)$track['duration']*95)):10;$progress($pct);$last=microtime(true);}
   $s=proc_get_status($proc);if(!$s['running']){$exit=(int)$s['exitcode'];break;}if(microtime(true)>$deadline)throw new RuntimeException('Se agotó el tiempo de preparación; inténtalo más tarde');usleep(100000);
  }
  foreach($pipes as $p)fclose($p);$pipes=[];$closed=proc_close($proc);$proc=null;if(($exit>=0?$exit:$closed)!==0||!is_file($tmp)||filesize($tmp)<32)throw new RuntimeException('No se pudo reconstruir el audio; revisa la integridad de la pista');
  chmod($tmp,0600);if(!rename($tmp,$dest))throw new RuntimeException('No se pudo publicar la descarga');$success=true;
  return ['format'=>$mode,'track_id'=>$track['id'],'name'=>(string)($track['name']??'Pista'),'expires'=>time()+3600,'bandwidth'=>$manifest['bandwidth']];
 }finally{if(is_resource($proc)){proc_terminate($proc,9);foreach($pipes as $p)fclose($p);proc_close($proc);}foreach([$input,$tmp] as $f)if(is_file($f))unlink($f);if(!$success&&is_file($dest))unlink($dest);}
}
function sfd_process(PDO $db,array $job,array $payload):void {
 if(!sfd_allowed((string)$job['user_id']))throw new RuntimeException('El permiso de descarga fue desactivado');
 $found=sf_track_lookup((string)($payload['track_id']??''));if(!$found)throw new RuntimeException('La pista ya no está disponible');
 $result=sfd_build($found['track'],(string)($payload['format']??''),(string)$job['id'],fn($pct)=>sfq_update($db,$job['id'],'Preparando archivo de descarga',$pct));
 if(!sfd_allowed((string)$job['user_id'])){unlink(sfd_root().'/'.$job['id'].'.'.$result['format']);throw new RuntimeException('El permiso de descarga fue desactivado');}
 sfq_finish($db,$job['id'],$result);sfd_cleanup();
}
