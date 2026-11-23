<?php
// CLI only. Completa el tren de calidades HLS de cada melodía referenciada
// (asignada a un álbum o suelta). Añade las calidades de la escalera
// configurada que falten por debajo de la mejor calidad existente, incluida
// 64 kbps. Dry run por defecto; --apply --limit=N. Antes de publicar guarda
// una copia de seguridad de los manifiestos, info.json e IDs de las pistas.
declare(strict_types=1);
require_once dirname(__DIR__).'/lib/media_log.php';

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/lib/client_processing.php';
require_once dirname(__DIR__).'/lib/sqlite_catalog_read.php';
$apply=in_array('--apply',$argv,true);$limit=PHP_INT_MAX;
foreach($argv as $arg)if(preg_match('/^--limit=(\d+)$/',$arg,$m))$limit=(int)$m[1];
$guard=fopen(LAB_DATA.'/repair-local-low.lock','c');if(!flock($guard,LOCK_EX|LOCK_NB))throw new RuntimeException('Repair already running');
$db=new PDO('sqlite:'.lab_sqlite_catalog_db_path(),null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$db->exec('PRAGMA busy_timeout=15000');$db->exec('PRAGMA foreign_keys=ON');$root=realpath(LAB_HLS);$done=0;$failed=0;$needed=0;
$backup=LAB_DATA.'/backups/local-low-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
$stateFile=LAB_DATA.'/processing_jobs/hls-low-state.json';
$cfg=lab_load_config();
$ladder=array_map('intval',(array)($cfg['quality_ladder_kbps']??[64,96,128,192]));
$ladder[]=(int)($cfg['always_include_low_kbps']??64);
$ladder=array_values(array_unique(array_filter($ladder,static fn(int $k):bool=>$k>=32&&$k<=320)));
sort($ladder);
if(!$ladder)$ladder=[64];
$writeState=function(string $status,string $track='')use($stateFile,$apply,&$done,&$failed,&$needed,$backup,$ladder){
 if(!$apply)return;$v=['state'=>$status,'repaired'=>$done,'failed'=>$failed,'found'=>$needed,'track'=>$track,'updated'=>time(),'backup'=>basename($backup),'ladder'=>$ladder];
 file_put_contents($stateFile.'.tmp',json_encode($v,JSON_UNESCAPED_UNICODE));rename($stateFile.'.tmp',$stateFile);
};
$writeState('running');
$paths=$db->query("SELECT DISTINCT hls_path FROM tracks WHERE hls_path IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
foreach($paths as $rel){
 $master=realpath(LAB_ROOT.'/'.$rel);
 if(!$master||!str_starts_with($master,$root.'/')||basename($master)!=='master.m3u8')continue;
 $base=dirname($master);$infoPath=$base.'/info.json';$info=json_decode((string)@file_get_contents($infoPath),true);
 if(!is_array($info))continue;
 $body=(string)file_get_contents($master);
 preg_match_all('/^(\d+)k\/prog\.m3u8\s*$/m',$body,$variants);$have=array_values(array_unique(array_map('intval',$variants[1])));sort($have);
 if(!$have)continue;
 $top=max($have);
 // La escalera nunca supera la mejor calidad ya disponible en la pista.
 $desired=array_values(array_filter($ladder,static fn(int $k):bool=>$k<=$top));
 if(!in_array($top,$desired,true))$desired[]=$top;
 sort($desired);$desired=array_values(array_unique($desired));
 $missing=array_values(array_diff($desired,$have));
 $q=$db->prepare('SELECT 1 FROM tracks t WHERE hls_path=? AND NOT EXISTS(SELECT 1 FROM track_renditions r WHERE r.track_id=t.id AND r.name=?) LIMIT 1');
 $needsSync=(bool)array_diff(array_map(static fn($k)=>$k.'k',$have),$info['renditions']??[]);
 foreach($have as $k){$q->execute([$rel,$k.'k']);$needsSync=$needsSync||(bool)$q->fetchColumn();}
 if(!$missing&&!$needsSync)continue;
 $needed++;$writeState('running',basename($base));
 if(!$apply){echo 'Missing '.implode(', ',array_map(static fn(int $k):string=>$k.'k',$missing)).': '.$rel."\n";continue;}
 if($done+$failed >= $limit)continue;
 sf_media_event('ladder.started',['path'=>$rel]);
 $tmps=[];$publicationLock=null;$originalPublication=null;
 try{
  $input=realpath($base.'/'.$top.'k/prog.m3u8');
  if(!$input||!str_starts_with($input,$base.'/'))throw new RuntimeException('Unsafe source');
  if(!is_dir($backup)){mkdir($backup,02770,true);file_put_contents($backup.'/.htaccess',"Require all denied\n");}
  $item=$backup.'/'.hash('sha256',$rel);if(!is_dir($item))mkdir($item,02770);
  copy($master,$item.'/master.m3u8');copy($infoPath,$item.'/info.json');file_put_contents($item.'/path.txt',$rel);
  $q=$db->prepare('SELECT id FROM tracks WHERE hls_path=?');$q->execute([$rel]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);
  file_put_contents($item.'/track_ids.json',json_encode($ids));
  $probe=lab_probe_audio($input,(string)($cfg['ffprobe']??'ffprobe'));
  $sampleRate=(int)($probe['sample_rate']??0);
  if(!in_array($sampleRate,[44100,48000],true))throw new RuntimeException('Unsupported source sample rate');
  $seg=max(2,min(12,(int)($cfg['segment_seconds']??4)));
  $created=[];
  foreach($missing as $target){
   $dest=$base.'/'.$target.'k';
   if(!is_dir($dest)){
    $tmp=$base.'/.ladder-'.bin2hex(random_bytes(6));if(!@mkdir($tmp,0750))throw new RuntimeException('Cannot create rendition directory (permissions) at '.$target.'k');$tmps[$target]=$tmp;
    echo 'Encoding '.$target.'k: '.basename($base)."\n";flush();
    [$code,$out]=lab_run('timeout 240 ffmpeg -nostdin -v error -threads 1 -y -i '.escapeshellarg($input).' -map 0:a:0 -vn -c:a aac -b:a '.$target.'k -ar '.$sampleRate.' -ac 2 -threads 1 -f hls -hls_time '.$seg.' -hls_playlist_type vod -hls_flags independent_segments -hls_segment_filename '.escapeshellarg($tmp.'/seg_%03d.ts').' '.escapeshellarg($tmp.'/prog.m3u8'));
    if($code!==0)throw new RuntimeException('FFmpeg failed at '.$target.'k');
    $playlist=(string)file_get_contents($tmp.'/prog.m3u8');
    if(!str_contains($playlist,'#EXT-X-ENDLIST'))throw new RuntimeException('Incomplete output at '.$target.'k');
    preg_match_all('/#EXTINF:([\d.]+)/',$playlist,$new);preg_match_all('/#EXTINF:([\d.]+)/',(string)file_get_contents($input),$old);
    if(!count($new[1])||abs(array_sum($new[1])-array_sum($old[1]))>1)throw new RuntimeException('Duration mismatch at '.$target.'k');
   }elseif(is_link($dest)||!str_contains((string)file_get_contents($dest.'/prog.m3u8'),'#EXT-X-ENDLIST'))throw new RuntimeException('Invalid existing '.$target.'k rendition');
   $created[]=$target;
  }
  // Publica primero los directorios y luego el manifiesto y el catálogo.
  foreach($tmps as $target=>$tmp){if(!rename($tmp,$base.'/'.$target.'k'))throw new RuntimeException('Cannot publish '.$target.'k rendition');unset($tmps[$target]);}
  require_once dirname(__DIR__).'/lib/track_reuse.php';
  $publicationLock=sf_reuse_lock();
  $q=$db->prepare('SELECT 1 FROM tracks WHERE hls_path=? LIMIT 1');$q->execute([$rel]);
  if(!$q->fetchColumn()||!is_file($master)||!is_file($infoPath))throw new RuntimeException('Track changed before publication');
  $originalPublication=[$master=>(string)file_get_contents($master),$infoPath=>(string)file_get_contents($infoPath)];
  $created=array_values(array_unique(array_merge($have,$created)));
  $attempt=0;
  while(true){
  try{
   // BEGIN IMMEDIATE evita SQLITE_BUSY_SNAPSHOT: la escritura toma el bloqueo
   // al inicio y espera con busy_timeout en lugar de fallar al actualizar.
   $db->exec('BEGIN IMMEDIATE');
   $q=$db->prepare('SELECT id FROM tracks WHERE hls_path=?');$q->execute([$rel]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);
   if(!$ids)throw new RuntimeException('Track changed during conversion');
   $current=(string)file_get_contents($master);
   $entries=[];
   if(preg_match_all('/#EXT-X-STREAM-INF:([^\n]*)\r?\n(\d+)k\/prog\.m3u8/',$current,$mm,PREG_SET_ORDER))foreach($mm as $entry)$entries[(int)$entry[2]]=[$entry[1],(int)$entry[2]];
   foreach($created as $target)if(!isset($entries[$target]))$entries[$target]=['BANDWIDTH='.($target*1150).',AVERAGE-BANDWIDTH='.($target*1000).',CODECS="mp4a.40.2",NAME="'.$target.'k"',$target];
   ksort($entries);
   $current="#EXTM3U\n#EXT-X-VERSION:3\n\n";
   foreach($entries as $target=>$entry)$current.='#EXT-X-STREAM-INF:'.$entry[0]."\n".$target."k/prog.m3u8\n\n";
   if(file_put_contents($master.'.ladder.tmp',$current,LOCK_EX)===false||!rename($master.'.ladder.tmp',$master))throw new RuntimeException('Cannot publish master');
   $info=json_decode((string)file_get_contents($infoPath),true);
   $names=array_map(static fn(int $k):string=>$k.'k',$created);
   $info['renditions']=array_values(array_unique(array_merge($names,$info['renditions']??[])));
   usort($info['renditions'],static fn(string $a,string $b):int=>((int)$a<=>(int)$b));
   if(file_put_contents($infoPath.'.ladder.tmp',json_encode($info,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX)===false||!rename($infoPath.'.ladder.tmp',$infoPath))throw new RuntimeException('Cannot publish info');
   $insert=$db->prepare('INSERT INTO track_renditions(track_id,name,bitrate_kbps) VALUES (?,?,?) ON CONFLICT(track_id,name) DO UPDATE SET bitrate_kbps=excluded.bitrate_kbps');
   foreach($ids as $id)foreach($created as $target)$insert->execute([$id,$target.'k',$target]);
   $db->exec('COMMIT');$originalPublication=null;
   sf_media_event('ladder.published',['path'=>$rel]);
   break;
  }catch(Throwable $e){try{$db->exec('ROLLBACK');}catch(Throwable $_){}if(stripos($e->getMessage(),'locked')===false||++$attempt>=4)throw $e;echo '  reintento '.$attempt.' (database is locked)'."\n";flush();usleep(1000000*$attempt);}
  }
  $done++;$writeState('running');echo 'OK '.$done.': '.$rel.' (+'.implode(',',array_map(static fn(int $k):string=>$k.'k',$created)).")\n";flush();
 }catch(Throwable $e){
  if($originalPublication!==null)foreach($originalPublication as $file=>$raw){if(file_put_contents($file.'.ladder.restore',$raw,LOCK_EX)===false||!@rename($file.'.ladder.restore',$file))echo 'RESTORE FAILED: '.$file."\n";}
  sf_media_event('ladder.failed',['path'=>$rel],$e);
  $failed++;$writeState('running');echo 'FAILED: '.$rel.' '.$e->getMessage()."\n";flush();}
 finally{if(is_resource($publicationLock)){flock($publicationLock,LOCK_UN);fclose($publicationLock);}foreach($tmps as $tmp)if(is_dir($tmp)){foreach(glob($tmp.'/*')?:[] as $f)if(is_file($f))unlink($f);rmdir($tmp);}}
}
$writeState($failed?'completed_with_errors':'done');
echo json_encode(['apply'=>$apply,'missing_at_start'=>$needed,'repaired'=>$done,'failed'=>$failed,'ladder'=>$ladder,'backup'=>$apply?$backup:null],JSON_UNESCAPED_SLASHES)."\n";
exit($failed?1:0);
