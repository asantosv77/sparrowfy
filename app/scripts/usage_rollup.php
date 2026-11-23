<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
umask(0007);require dirname(__DIR__).'/auth.php';require dirname(__DIR__).'/lib/usage.php';
$dir=getenv('SF_USAGE_TEST_SPOOL')?:dirname(__DIR__).'/data/usage-spool';
if(getenv('SF_USAGE_TEST_SPOOL') && !str_starts_with(realpath($dir)?:'',realpath(dirname(__DIR__).'/backups').DIRECTORY_SEPARATOR))throw new RuntimeException('Invalid test spool');if(!is_dir($dir))mkdir($dir,02770,true);
$roll=fopen($dir.'/.rollup.lock','c');if(!flock($roll,LOCK_EX|LOCK_NB))exit;
$pending=$dir.'/pending';if(!is_dir($pending))mkdir($pending,02770);
$lock=fopen($dir.'/.lock','c');flock($lock,LOCK_EX);
foreach(glob($dir.'/*.ndjson') as $f)rename($f,$pending.'/'.basename($f,'.ndjson').'-'.bin2hex(random_bytes(8)).'.ndjson');
flock($lock,LOCK_UN);fclose($lock);
$p=lab_auth_pdo();sf_usage_schema($p);$tz=new DateTimeZone('America/Mexico_City');$processed=0;
foreach(glob($pending.'/*.ndjson') as $file){
 $id=basename($file);$groups=[];$bad=0;$handle=fopen($file,'r');
 while(($line=fgets($handle))!==false){
  $r=json_decode($line,true);
  if(!is_array($r)||count($r)!==6||!is_numeric($r[0])||!is_string($r[1])||strlen($r[1])>128){$bad++;continue;}
  [$ts,$uid,$out,$in,$kind,$status]=$r;$minute=(int)floor((int)$ts/60)*60;$key=$uid.':'.$minute;
  if(!isset($groups[$key]))$groups[$key]=['uid'=>$uid,'minute'=>$minute,'day'=>(new DateTimeImmutable('@'.(int)$ts))->setTimezone($tz)->format('Y-m-d'),'out'=>0,'in'=>0,'audio'=>0,'requests'=>0,'errors'=>0];
  $g=&$groups[$key];$g['out']+=max(0,(int)$out);$g['in']+=max(0,(int)$in);$g['audio']+=$kind==='hls'?max(0,(int)$out):0;$g['requests']++;$g['errors']+=(int)$status>=400?1:0;unset($g);
 }
 fclose($handle);$p->beginTransaction();
 try{
  $q=$p->prepare('SELECT 1 FROM sf_usage_batches WHERE id=?');$q->execute([$id]);
  if(!$q->fetchColumn()){
   $day=$p->prepare('INSERT INTO sf_usage_daily(user_id,day,downloaded,uploaded,audio,requests,errors) VALUES(?,?,?,?,?,?,?) ON CONFLICT(user_id,day) DO UPDATE SET downloaded=downloaded+excluded.downloaded,uploaded=uploaded+excluded.uploaded,audio=audio+excluded.audio,requests=requests+excluded.requests,errors=errors+excluded.errors');
   $minuteStmt=$p->prepare('INSERT INTO sf_usage_minute(user_id,minute,downloaded,uploaded) VALUES(?,?,?,?) ON CONFLICT(user_id,minute) DO UPDATE SET downloaded=downloaded+excluded.downloaded,uploaded=uploaded+excluded.uploaded');
   $global=$p->prepare('INSERT INTO sf_usage_global(minute,downloaded,uploaded) VALUES(?,?,?) ON CONFLICT(minute) DO UPDATE SET downloaded=downloaded+excluded.downloaded,uploaded=uploaded+excluded.uploaded');
   $get=$p->prepare('SELECT downloaded,uploaded FROM sf_usage_minute WHERE user_id=? AND minute=?');
   $peak=$p->prepare('UPDATE sf_usage_daily SET peak_at=CASE WHEN ?>peak_out THEN ? ELSE peak_at END,peak_out=MAX(peak_out,?),peak_in=MAX(peak_in,?) WHERE user_id=? AND day=?');
   foreach($groups as $g){
    $day->execute([$g['uid'],$g['day'],$g['out'],$g['in'],$g['audio'],$g['requests'],$g['errors']]);
    $minuteStmt->execute([$g['uid'],$g['minute'],$g['out'],$g['in']]);$global->execute([$g['minute'],$g['out'],$g['in']]);
    $get->execute([$g['uid'],$g['minute']]);$tot=$get->fetch();$peak->execute([$tot['downloaded'],$g['minute'],$tot['downloaded'],$tot['uploaded'],$g['uid'],$g['day']]);
   }
   $p->prepare('INSERT INTO sf_usage_batches VALUES(?,?)')->execute([$id,time()]);$processed+=count($groups);
  }
  $p->commit();unlink($file);
 }catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
 if($bad)error_log('Usage rejected records: '.$bad);
}
$p->prepare('DELETE FROM sf_usage_minute WHERE minute<?')->execute([time()-3*86400]);
$candidate=$p->query('SELECT downloaded AS peak_out,minute AS peak_at FROM sf_usage_global ORDER BY downloaded DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$old=json_decode((string)$p->query("SELECT value FROM sf_usage_meta WHERE key='global_peak_out'")->fetchColumn(),true);
if($candidate && (int)$candidate['peak_out']>(int)($old['peak_out']??0))$p->prepare("INSERT INTO sf_usage_meta VALUES('global_peak_out',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value")->execute([json_encode($candidate)]);
$p->prepare('DELETE FROM sf_usage_global WHERE minute<?')->execute([time()-366*86400]);
$p->prepare('DELETE FROM sf_usage_presence WHERE last_seen<?')->execute([time()-7*86400]);
$p->prepare("INSERT INTO sf_usage_meta(key,value) VALUES('last_rollup',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value")->execute([(string)time()]);
echo 'Usage rollup completed: '.$processed.' user/minute groups'.PHP_EOL;
