<?php
declare(strict_types=1);
require_once __DIR__.'/media_log.php';

require_once __DIR__.'/processing_queue.php';
function sfwc_root():string{$p=LAB_DATA.'/client-processing';if(!is_dir($p)&&!mkdir($p,02770,true))throw new RuntimeException('No se puede preparar la subida');if(!is_file($p.'/.htaccess'))file_put_contents($p.'/.htaccess',"Require all denied\n");return $p;}
function sfwc_save(string $dir,array $m):void{$tmp=$dir.'/state.tmp';if(file_put_contents($tmp,json_encode($m,JSON_THROW_ON_ERROR),LOCK_EX)===false||!rename($tmp,$dir.'/state.json'))throw new RuntimeException('No se pudo registrar el estado');chmod($dir.'/state.json',0660);}
function sfwc_remove(string $dir):void{$root=realpath(sfwc_root());$real=realpath($dir);if(!$real||dirname($real)!==$root||!preg_match('/^[a-f0-9]{32}$/D',basename($real)))throw new RuntimeException('Ruta inválida');foreach(scandir($real)?:[] as $n){$p=$real.'/'.$n;if(is_file($p)||is_link($p))unlink($p);}rmdir($real);}
function sfwc_cleanup():void{foreach(glob(sfwc_root().'/*/state.json')?:[] as $file){$dir=dirname($file);$lock=fopen($dir.'/.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if($lock)fclose($lock);continue;}try{$m=json_decode((string)file_get_contents($file),true);if(!is_array($m)||($m['updated']??time())>time()-86400)continue;if(!empty($m['job_id'])){$q=sfq_db()->prepare('SELECT state FROM sf_processing_jobs WHERE id=?');$q->execute([$m['job_id']]);if(in_array($q->fetchColumn(),['queued','running'],true))continue;}sfwc_remove($dir);}finally{flock($lock,LOCK_UN);fclose($lock);}}}
function sfwc_convert(array $payload,array $album,array $cfg):array{
 $sid=(string)($payload['client_session']??'');if(!preg_match('/^[a-f0-9]{32}$/D',$sid))throw new RuntimeException('Sesión local inválida');
 $staging=sfwc_root().'/'.$sid;$m=json_decode((string)file_get_contents($staging.'/state.json'),true);if(!$m||$m['album_id']!==$album['id'])throw new RuntimeException('Estado inválido');
 $sourceCodec=(string)($m['source_codec']??'');$expected=array_map(static fn($r)=>(int)($r['kbps']??round(($r['bandwidth']??0)/1000)),lab_pick_renditions((int)$m['source_kbps'],$cfg,$sourceCodec));$actual=array_map('intval',$m['rates']);sort($actual);sort($expected);if($actual!==$expected)throw new RuntimeException('La conversión local no contiene la escalera vigente completa; reintenta con procesamiento en servidor');
 $jobId='webcode_'.date('Ymd_His').'_'.bin2hex(random_bytes(6));$parent=lab_album_hls_dir($album['id'],$album['title']);$base=$parent.'/'.$jobId;mkdir($base,0755);
 sf_media_event('client_hls.started',['album_id'=>$album['id'],'job_id'=>$jobId,'path'=>$base.'/master.m3u8']);
 try{sf_output_checkpoint($base,'building',['album_id'=>$album['id']]);$master=['#EXTM3U','#EXT-X-VERSION:3',''];$duration=0;$names=[];
 foreach($m['rates'] as $rate){$name=$rate.'k';$names[]=$name;$source=$staging.'/'.$name.'.aac';$out=fopen($source,'wb');
 foreach($m['chunks'][(string)$rate] as $index=>$hash){$f=$staging.'/'.$rate.'_'.$index.'.chunk';if(!hash_equals($hash,hash_file('sha256',$f)))throw new RuntimeException('Bloque alterado');$in=fopen($f,'rb');stream_copy_to_stream($in,$out);fclose($in);}fclose($out);
 [$code,$json]=lab_run('timeout 20 ffprobe -v error -f aac -show_streams -show_format -of json '.escapeshellarg($source));$probe=json_decode($json,true);$audio=$probe['streams'][0]??[];$fh=fopen($source,'rb');$bytes=filesize($source);$frames=0;try{while(ftell($fh)<$bytes){$position=ftell($fh);$header=fread($fh,7);if(strlen($header)!==7)throw new RuntimeException('Trama AAC incompleta');$h=array_values(unpack('C*',$header));$length=(($h[3]&3)<<11)|($h[4]<<3)|($h[5]>>5);if($h[0]!==255||$h[1]!==241||($h[2]>>6)!==1||(($h[2]>>2)&15)!==3||$length<8||$position+$length>$bytes)throw new RuntimeException('Trama AAC inválida');fseek($fh,$length-7,SEEK_CUR);$frames++;}}finally{fclose($fh);}$d=$frames*1024/48000;
 if($code!==0||($audio['codec_name']??'')!=='aac'||$d<=0||$d>1900||abs($d-$m['duration'])>max(3,$m['duration']*.04)||(int)($audio['channels']??0)!==2||(int)($audio['sample_rate']??0)!==48000)throw new RuntimeException('El audio procesado no pasó la validación');
 $duration=$d;$dir=$base.'/'.$name;mkdir($dir,0755);$seg=max(2,min(12,(int)($cfg['segment_seconds']??4)));
 [$code,$out]=lab_run('timeout 90 ffmpeg -nostdin -v error -threads 1 -y -f aac -i '.escapeshellarg($source).' -map 0:a:0 -c:a copy -f hls -hls_time '.$seg.' -hls_playlist_type vod -hls_flags independent_segments -hls_segment_filename '.escapeshellarg($dir.'/seg_%03d.ts').' '.escapeshellarg($dir.'/prog.m3u8'));
 if($code!==0||!is_file($dir.'/prog.m3u8'))throw new RuntimeException('No se pudo empaquetar el audio local');
 $master[]='#EXT-X-STREAM-INF:BANDWIDTH='.(int)($rate*1150).',AVERAGE-BANDWIDTH='.($rate*1000).',CODECS="mp4a.40.2",NAME="'.$name.'"';$master[]=$name.'/prog.m3u8';}
 file_put_contents($base.'/master.m3u8',implode("\n",$master)."\n");
 $info=['job_id'=>$jobId,'album_id'=>$album['id'],'album_folder'=>basename($parent),'source_name'=>$m['filename'],'source_kbps'=>$m['source_kbps'],'source_codec'=>$sourceCodec?:'aac','duration_sec'=>$duration,'renditions'=>$names,'highest'=>end($names),'master'=>'master.m3u8','master_rel'=>'hls_out/'.basename($parent).'/'.$jobId.'/master.m3u8','source_file'=>null,'source_deleted'=>true,'created_at'=>date('c'),'processing'=>'webcodecs'];
 file_put_contents($base.'/info.json',json_encode($info));sf_output_checkpoint($base,'ready');sf_media_event('client_hls.ready',['album_id'=>$album['id'],'job_id'=>$jobId,'path'=>$info['master_rel']]);return $info;
 }catch(Throwable $e){sf_media_event('client_hls.failed',['album_id'=>$album['id'],'job_id'=>$jobId,'path'=>$base.'/master.m3u8'],$e);$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $p){$p->isDir()?rmdir($p->getPathname()):unlink($p->getPathname());}rmdir($base);sf_media_event('client_hls.cleanup',['path'=>$base.'/master.m3u8','remaining'=>is_dir($base)?1:0]);throw $e;}
}
function sfwc_remove_unpublished(array $info):void{
 if(!str_starts_with((string)($info['job_id']??''),'webcode_'))return;
 try{$a=lab_load_album((string)$info['album_id']);foreach($a['tracks']??[] as $t)if(($t['hls']??'')===$info['master_rel']){sf_media_event('client_hls.cleanup_skipped',['path'=>$info['master_rel'],'reason'=>'referenced']);return;}}catch(Throwable $e){sf_media_event('client_hls.cleanup_skipped',['path'=>$info['master_rel'],'reason'=>'catalog_unavailable'],$e);return;}
 $base=realpath(dirname(dirname(__DIR__).'/'.$info['master_rel']));$root=realpath(LAB_HLS);
 if(!$base||!$root||!str_starts_with($base,$root.'/')||basename($base)!==$info['job_id'])return;
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $p){$p->isDir()?rmdir($p->getPathname()):unlink($p->getPathname());}rmdir($base);sf_media_event('client_hls.unpublished_removed',['path'=>$info['master_rel'],'remaining'=>is_dir($base)?1:0]);
}

/** Publish only the complete configured ladder; unsupported encoders fall back before encoding. */
function sfwc_pick_rates(int $source,array $cfg,array $capabilities,string $sourceCodec=''):array {
 $expected=array_map(static fn($r)=>(int)($r['kbps']??round(($r['bandwidth']??0)/1000)),lab_pick_renditions($source,$cfg,$sourceCodec));
 $caps=array_values(array_filter($capabilities,'is_int'));$missing=array_values(array_diff($expected,$caps));
 if($missing)throw new RuntimeException('Este navegador no admite la escalera completa (faltan '.implode(', ',$missing).' kbps); la fuente se convertirá en el servidor');
 return $expected;
}
