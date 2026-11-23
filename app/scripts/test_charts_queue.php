<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
// Load only the trusted orchestration functions, with isolated fake service boundaries.
$code=file_get_contents(dirname(__DIR__).'/lib/charts_import.php');$code=preg_replace("~^require_once __DIR__\.'[^']+';\s*$~m",'',$code);
$file=tempnam(sys_get_temp_dir(),'sf-chart-functions-');file_put_contents($file,$code);require $file;unlink($file);
require dirname(__DIR__).'/lib/external_charts_audio.php';
$mode='ok';$youtubeCalls=0;
function sf_reuse_search(...$args):array{return [];}
function sf_external_mornye_search(string $query):array{global $mode;if($mode==='no-match')return [];return [['title'=>'Song','artist'=>'Artist','url'=>'https://open.spotify.com/track/1234567890123456789012']];}
function sf_external_youtube_search(...$args):array{global $youtubeCalls;$youtubeCalls++;return [['title'=>'Artist - Song (Official Audio)','channel'=>'Artist','url'=>'https://www.youtube.com/watch?v=abcdefghijk']];}
function sfmn_submission_lock(){return tmpfile();}
function sfmn_enqueue(PDO $db,...$args):array{$db->exec("INSERT INTO sf_processing_jobs VALUES('mornye-job','queued',NULL,NULL)");return ['job_id'=>'mornye-job'];}
function sfq_add(PDO $db,...$args):string{$db->exec("INSERT INTO sf_processing_jobs VALUES('youtube-job','queued',NULL,NULL)");return 'youtube-job';}
function sfc_live_track(PDO $db,string $id):?array{return $id==='published'?['album_id'=>'album','track'=>['id'=>$id]]:null;}
function expectq(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function fixture():PDO{$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->exec('CREATE TABLE sf_chart_audio(identity TEXT PRIMARY KEY,data TEXT,state TEXT,track_id TEXT,source_album TEXT,job_id TEXT,mornye_job_id TEXT,service TEXT,url TEXT,attempts TEXT,error TEXT,updated_at TEXT); CREATE TABLE sf_processing_jobs(id TEXT,state TEXT,result_json TEXT,error TEXT)');$db->prepare('INSERT INTO sf_chart_audio(identity,data,state,attempts) VALUES(?,?,?,?)')->execute(['identity',json_encode(['artist'=>'Artist','title'=>'Song','detail_checked'=>true]),'new','[]']);return $db;}
function runq(PDO $db):void{$row=$db->query('SELECT * FROM sf_chart_audio')->fetch(PDO::FETCH_ASSOC);sfc_try_source($db,$row,['owner_id'=>'admin'],'album');}
$db=fixture();runq($db);$row=$db->query('SELECT * FROM sf_chart_audio')->fetch(PDO::FETCH_ASSOC);expectq($row['state']==='queued'&&$row['job_id']==='mornye-job','Mornye commit includes queue and ledger');
$db->exec("UPDATE sf_processing_jobs SET state='failed',error='Provider failed'");sfc_reconcile_audio($db);runq($db);expectq($db->query('SELECT job_id FROM sf_chart_audio')->fetchColumn()==='youtube-job','Final Mornye failure uses YouTube fallback');
$db->exec("UPDATE sf_processing_jobs SET state='done',result_json='{".'"track":{"id":"published"}'."}' WHERE id='youtube-job'");sfc_reconcile_audio($db);expectq($db->query('SELECT state FROM sf_chart_audio')->fetchColumn()==='ready','Completion requires published track');
$db=fixture();$db->exec("CREATE TRIGGER fail_link BEFORE UPDATE OF state ON sf_chart_audio WHEN NEW.state='queued' BEGIN SELECT RAISE(ABORT,'link failed'); END");$before=$youtubeCalls;runq($db);expectq((int)$db->query('SELECT count(*) FROM sf_processing_jobs')->fetchColumn()===0,'Failed ledger linkage rolls back queued task');expectq($youtubeCalls===$before&&$db->query('SELECT state FROM sf_chart_audio')->fetchColumn()==='review','Uncertain enqueue cannot fall through to another download');
$mode='no-match';$db=fixture();runq($db);expectq($db->query('SELECT job_id FROM sf_chart_audio')->fetchColumn()==='youtube-job','No exact Mornye match uses protected YouTube queue');
echo "CHARTS_QUEUE_OK: atomic submission, fallback and publication reconciliation\n";
