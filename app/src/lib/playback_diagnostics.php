<?php declare(strict_types=1);
function sf_playback_debug_settings(array $cfg): array {return ['enabled'=>!empty($cfg['playback_diagnostics_enabled']),'record'=>!empty($cfg['playback_diagnostics_record']),'console'=>!empty($cfg['playback_diagnostics_console']),'retention'=>max(1,min(30,(int)($cfg['playback_diagnostics_days']??7)))];}
function sf_playback_clean_event(array $e): ?array {
 $allowed=['track_load','play_request','started','buffer_start','buffer_end','pause','ended','media_error','hls_error','retry','rebuild','offline','online','autoplay_blocked','recovered','seek','prefetch'];
 if(!in_array($e['event']??'', $allowed,true))return null;
 $out=['event'=>$e['event']];
 foreach(['track','run','engine','client','reason','profile'] as $k)if(isset($e[$k])&&is_string($e[$k])&&preg_match('/^[a-zA-Z0-9_.-]{1,80}$/D',$e[$k]))$out[$k]=$e[$k];
 foreach(['position','duration','startup_ms','buffer_ms','buffer_ahead','ready','network','status','fatal','attempt'] as $k)if(isset($e[$k])&&is_numeric($e[$k])&&is_finite((float)$e[$k]))$out[$k]=max(0,min(86400000,(float)$e[$k]));
 return $out;
}
function sf_playback_write_events(string $dir,string $uid,array $events,int $days): int {
 if(!is_dir($dir)&&!@mkdir($dir,0770,true))throw new RuntimeException('Registro no disponible');
 $lock=fopen($dir.'/.write.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Registro ocupado');
 try{
  $now=new DateTimeImmutable('now',new DateTimeZone('America/Mexico_City'));$day=$now->format('Y-m-d');$file=$dir.'/playback-'.$day.'.jsonl';
  foreach(glob($dir.'/playback-*.jsonl')?:[] as $old)if(filemtime($old)<time()-$days*86400)@unlink($old);
  if(is_file($file)&&filesize($file)>5*1024*1024)return 0;
  $lines='';$count=0;foreach(array_slice($events,0,30) as $e){if(!is_array($e)||!($safe=sf_playback_clean_event($e)))continue;$safe['time']=$now->format(DATE_ATOM);$safe['user']=$uid;$lines.=json_encode($safe,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)."\n";$count++;}
  if($lines!==''&&file_put_contents($file,$lines,FILE_APPEND)===false)throw new RuntimeException('No se pudo escribir el registro');
  if(is_file($file))@chmod($file,0660);return $count;
 }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function sf_playback_read_events(string $dir,int $limit=400): array {
 $files=glob($dir.'/playback-*.jsonl')?:[];rsort($files);$result=[];
 foreach($files as $file){$f=fopen($file,'r');if(!$f)continue;$tail=[];while(($line=fgets($f,4096))!==false){$row=json_decode($line,true);if(is_array($row)){$tail[]=$row;if(count($tail)>$limit)array_shift($tail);}}fclose($f);$result=array_merge(array_reverse($tail),$result);if(count($result)>=$limit)break;}
 usort($result,static fn($a,$b)=>(strtotime($b['time']??'')?:0)<=>(strtotime($a['time']??'')?:0));return array_slice($result,0,$limit);
}
