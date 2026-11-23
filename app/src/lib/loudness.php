<?php
declare(strict_types=1);
function sf_loudness_db(): PDO {
 static $ready=false;$p=lab_auth_pdo();if($ready)return $p;$p->exec('CREATE TABLE IF NOT EXISTS sf_track_loudness(track_id TEXT PRIMARY KEY,lufs REAL,true_peak REAL,lra REAL,status TEXT NOT NULL,updated_at INTEGER NOT NULL)');
 $p->exec('CREATE TABLE IF NOT EXISTS sf_volume_preferences(user_id TEXT PRIMARY KEY,enabled INTEGER NOT NULL)');$columns=array_column($p->query('PRAGMA table_info(sf_track_loudness)')->fetchAll(PDO::FETCH_ASSOC),'name');if(!in_array('source_fingerprint',$columns,true)){try{$p->exec('ALTER TABLE sf_track_loudness ADD COLUMN source_fingerprint TEXT');}catch(Throwable $e){$again=array_column($p->query('PRAGMA table_info(sf_track_loudness)')->fetchAll(PDO::FETCH_ASSOC),'name');if(!in_array('source_fingerprint',$again,true))throw $e;}}$ready=true;return $p;
}
function sf_loudness_enabled(string $uid): bool {$q=sf_loudness_db()->prepare('SELECT enabled FROM sf_volume_preferences WHERE user_id=?');$q->execute([$uid]);$v=$q->fetchColumn();return $v===false||((int)$v===1);}
// Allow long compilations to finish while keeping CPU/thread limits unchanged.
function sf_loudness_timeout_seconds(float $duration,array $settings=[]): int {
 if(($settings['loudness_timeout_mode']??'auto')==='fixed')return max(3,min(120,(int)($settings['loudness_timeout_minutes']??30)))*60;
 if(!is_finite($duration)||$duration<=0)return 180;
 return (int)max(180,min(1800,ceil($duration/4)+60));
}
function sf_loudness_analyze(array $track): void {
 $id=(string)($track['id']??'');if(!preg_match('/^[a-f0-9]{16}$/D',$id))return;
 $p=sf_loudness_db();$fingerprint=sf_loudness_fingerprint($track);$sourceCheck=$p->prepare('SELECT job_id,hls_path,duration FROM tracks WHERE id=?');$sourceCheck->execute([$id]);$currentSource=$sourceCheck->fetch(PDO::FETCH_ASSOC);$sourceCheck->closeCursor();if($currentSource&&sf_loudness_fingerprint(['job_id'=>$currentSource['job_id'],'hls'=>$currentSource['hls_path'],'duration'=>$currentSource['duration']])!==$fingerprint)return;$q=$p->prepare('SELECT status,source_fingerprint FROM sf_track_loudness WHERE track_id=?');$q->execute([$id]);$existing=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();if($existing&&$existing['status']==='ready'&&($existing['source_fingerprint']??'')===$fingerprint)return;
 $hls=(string)($track['hls']??'');if(!str_starts_with($hls,'hls_out/'))return;
 $root=realpath(dirname(__DIR__).'/hls_out');$path=realpath(dirname(__DIR__).'/'.$hls);
 if(!$root||!$path||!str_starts_with($path,$root.'/'))return;
 $p->prepare("INSERT INTO sf_track_loudness(track_id,status,updated_at) VALUES(?,'analyzing',?) ON CONFLICT(track_id) DO UPDATE SET status='analyzing',updated_at=excluded.updated_at")->execute([$id,time()]);
 // Read-only analysis of delivered audio; never rewrites the original or HLS.
 $cmd=['ffmpeg','-nostdin','-hide_banner','-loglevel','info','-threads','1','-filter_threads','1','-protocol_whitelist','file,crypto,data','-i',$path,'-vn','-af','loudnorm=I=-14:TP=-1:LRA=11:print_format=json','-f','null','-'];
 $proc=proc_open($cmd,[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
 $out='';$exit=-1;$timedOut=false;$timeoutSeconds=sf_loudness_timeout_seconds((float)($track['duration']??0),lab_load_config());
 if(is_resource($proc)){stream_set_blocking($pipes[2],false);$deadline=microtime(true)+$timeoutSeconds;while(true){$out.=stream_get_contents($pipes[2]);if(strlen($out)>65536)$out=substr($out,-65536);$status=proc_get_status($proc);if(!$status['running']){$exit=(int)$status['exitcode'];break;}if(microtime(true)>$deadline){$timedOut=true;proc_terminate($proc,9);break;}usleep(100000);}$out.=stream_get_contents($pipes[2]);fclose($pipes[2]);$closed=proc_close($proc);$code=$timedOut?-1:($exit>=0?$exit:$closed);}else $code=-1;
 preg_match('/\{[^{}]*"input_i"[^{}]*\}/s',$out,$m);$a=json_decode($m[0]??'',true);
 $ok=$code===0&&is_array($a)&&is_numeric($a['input_i']??null)&&is_numeric($a['input_tp']??null)&&is_numeric($a['input_lra']??null);
 $source=$p->prepare('SELECT job_id,hls_path,duration FROM tracks WHERE id=?');$source->execute([$id]);$current=$source->fetch(PDO::FETCH_ASSOC);$source->closeCursor();if($current&&sf_loudness_fingerprint(['job_id'=>$current['job_id'],'hls'=>$current['hls_path'],'duration'=>$current['duration']])!==$fingerprint)return;
 $p->prepare('UPDATE sf_track_loudness SET lufs=?,true_peak=?,lra=?,status=?,updated_at=?,source_fingerprint=? WHERE track_id=?')->execute([$ok?(float)$a['input_i']:null,$ok?(float)$a['input_tp']:null,$ok?(float)$a['input_lra']:null,$ok?'ready':'failed',time(),$fingerprint,$id]);
 if(!$ok)throw new RuntimeException($timedOut?'El análisis de sonoridad superó su límite de '.$timeoutSeconds.' segundos.':'FFmpeg no devolvió una medición válida de sonoridad (código '.$code.').');
}
function sf_loudness_attach(array $tracks): array {
 $p=sf_loudness_db();$map=[];foreach($p->query("SELECT track_id,lufs,true_peak,source_fingerprint FROM sf_track_loudness WHERE status='ready'") as $r)$map[$r['track_id']]=$r;
 foreach($tracks as &$t)if(isset($map[$t['id']??''])&&(empty($map[$t['id']]['source_fingerprint'])||$map[$t['id']]['source_fingerprint']===sf_loudness_fingerprint($t)))$t['loudness']=$map[$t['id']];unset($t);return $tracks;
}

/** Only audio-source changes invalidate analysis; editing a title does not. */
function sf_loudness_fingerprint(array $track): string {return hash('sha256',json_encode([(string)($track['job_id']??''),(string)($track['hls']??''),(float)($track['duration']??0)]));}
function sf_loudness_audit(): array {
 $p=sf_loudness_db();$rows=[];foreach($p->query('SELECT track_id,status,source_fingerprint FROM sf_track_loudness') as $r)$rows[$r['track_id']]=$r;
 $out=['total'=>0,'ready'=>0,'pending'=>0,'failed'=>0,'tracks'=>[]];
 foreach(lab_list_albums() as $album)foreach($album['tracks']??[] as $t){if(empty($t['hls'])||empty($t['id'])||isset($out['tracks'][$t['id']]))continue;$out['total']++;$r=$rows[$t['id']]??null;$valid=$r&&$r['status']==='ready'&&($r['source_fingerprint']??'')===sf_loudness_fingerprint($t);if($valid)$out['ready']++;else{$out['pending']++;if(($r['status']??'')==='failed')$out['failed']++;}$out['tracks'][$t['id']]=['album_id'=>$album['id'],'name'=>$t['name']??'Pista','pending'=>!$valid];}
 return $out;
}
function sf_loudness_enqueue_pending(string $uid): int {
 require_once __DIR__.'/processing_queue.php';$audit=sf_loudness_audit();$p=sfq_db();$added=0;$p->exec('BEGIN IMMEDIATE');
 try{$check=$p->prepare("SELECT 1 FROM sf_processing_jobs WHERE operation='loudness' AND state IN ('queued','running') AND json_extract(payload,'$.track_id')=? LIMIT 1");foreach($audit['tracks'] as $tid=>$t){if(!$t['pending'])continue;$check->execute([$tid]);$exists=$check->fetchColumn();$check->closeCursor();if($exists)continue;sfq_add($p,$uid,'conversion','loudness',$t['album_id'],'Sonoridad: '.$t['name'],['track_id'=>$tid]);$added++;}$p->exec('COMMIT');}catch(Throwable $e){try{$p->exec('ROLLBACK');}catch(Throwable $ignored){}throw $e;}
 return $added;
}
