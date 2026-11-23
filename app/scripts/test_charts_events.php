<?php
declare(strict_types=1);if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/lib/mornye_event_resolution.php';
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE sf_processing_jobs(id TEXT,user_id TEXT,operation TEXT,album_id TEXT,payload TEXT,state TEXT,result_json TEXT,created_at TEXT,finished_at TEXT);CREATE TABLE sf_chart_audio(mornye_job_id TEXT,state TEXT,job_id TEXT,updated_at TEXT)');
$root=str_repeat('a',32);$dir=sys_get_temp_dir().'/sf-chart-events-'.bin2hex(random_bytes(5));mkdir($dir,0700);
try{
 $db->prepare('INSERT INTO sf_processing_jobs VALUES(?,?,?,?,?,?,?,?,?)')->execute([$root,'admin','mornye','album',json_encode(['url'=>'https://open.spotify.com/track/1234567890123456789012']),'failed',null,'2026-11-15T01:00:00Z','2026-11-15T01:01:00Z']);
 file_put_contents($dir.'/mornye-'.$root.'.json',json_encode(['remote_id'=>'remote-a']));
 $events=[['code'=>'download.failed.remote-a','first'=>0,'read'=>false]];
 $db->prepare('INSERT INTO sf_chart_audio VALUES(?,?,?,?)')->execute([$root,'queued','youtube-job','2026-11-15T01:02:00Z']);
 $r=sfmn_resolved_events($events,$db,$dir);if(empty($r[0]['deferred'])||!empty($r[0]['final_failure']))throw new RuntimeException('Charts fallback should defer Mornye alert');
 $db->exec("UPDATE sf_chart_audio SET state='ready'");$r=sfmn_resolved_events($events,$db,$dir);if(empty($r[0]['resolved'])||empty($r[0]['read'])||$r[0]['resolution']['reason']!=='charts_recovered')throw new RuntimeException('Successful fallback must confirm related error');
 $db->exec("UPDATE sf_chart_audio SET state='review'");$r=sfmn_resolved_events($events,$db,$dir);if(empty($r[0]['final_failure'])||!empty($r[0]['resolved']))throw new RuntimeException('Exhausted fallback should remain visible');
 echo "CHARTS_EVENTS_OK: wait for fallback, strike recovered errors, retain final failure\n";
}finally{unlink($dir.'/mornye-'.$root.'.json');rmdir($dir);}
