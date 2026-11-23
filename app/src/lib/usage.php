<?php
declare(strict_types=1);
function sf_usage_schema(PDO $p):void{
 $p->exec('CREATE TABLE IF NOT EXISTS sf_usage_daily(user_id TEXT NOT NULL,day TEXT NOT NULL,downloaded INTEGER NOT NULL DEFAULT 0,uploaded INTEGER NOT NULL DEFAULT 0,audio INTEGER NOT NULL DEFAULT 0,requests INTEGER NOT NULL DEFAULT 0,errors INTEGER NOT NULL DEFAULT 0,peak_out INTEGER NOT NULL DEFAULT 0,peak_in INTEGER NOT NULL DEFAULT 0,peak_at INTEGER,PRIMARY KEY(user_id,day))');
 $p->exec('CREATE TABLE IF NOT EXISTS sf_usage_minute(user_id TEXT NOT NULL,minute INTEGER NOT NULL,downloaded INTEGER NOT NULL DEFAULT 0,uploaded INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(user_id,minute))');
 $p->exec('CREATE TABLE IF NOT EXISTS sf_usage_global(minute INTEGER PRIMARY KEY,downloaded INTEGER NOT NULL DEFAULT 0,uploaded INTEGER NOT NULL DEFAULT 0)');
 $p->exec('CREATE TABLE IF NOT EXISTS sf_usage_batches(id TEXT PRIMARY KEY,processed_at INTEGER NOT NULL)');
 $p->exec('CREATE TABLE IF NOT EXISTS sf_usage_meta(key TEXT PRIMARY KEY,value TEXT NOT NULL)');
 $p->exec('CREATE TABLE IF NOT EXISTS sf_usage_presence(user_id TEXT NOT NULL,device_id TEXT NOT NULL,last_seen INTEGER NOT NULL,playing_until INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(user_id,device_id))');
 $p->exec('CREATE INDEX IF NOT EXISTS sf_usage_minute_time ON sf_usage_minute(minute)');
 $p->exec('CREATE INDEX IF NOT EXISTS sf_usage_daily_day ON sf_usage_daily(day)');
 $p->exec('CREATE INDEX IF NOT EXISTS sf_usage_global_peak ON sf_usage_global(downloaded DESC)');
 $p->prepare("INSERT OR IGNORE INTO sf_usage_meta(key,value) VALUES('since',?)")->execute([(string)time()]);
}
function sf_usage_record(string $uid,int $out,int $in=0,string $kind='web',int $status=200):void{
 if($uid===''||PHP_SAPI==='cli')return;
 try{
  $dir=dirname(__DIR__).'/data/usage-spool';if(!is_dir($dir))mkdir($dir,02770,true);
  $lock=fopen($dir.'/.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('usage lock');
  try{$now=time();$line=json_encode([$now,$uid,max(0,$out),max(0,$in),$kind,$status])."\n";
   $f=fopen($dir.'/'.((int)floor($now/60)*60).'.ndjson','ab');if(!$f)throw new RuntimeException('usage spool');fwrite($f,$line);fclose($f);
  }finally{flock($lock,LOCK_UN);fclose($lock);}
 }catch(Throwable $e){error_log('Usage accounting unavailable: '.get_class($e));}
}
function sf_usage_hook():void{
 if(PHP_SAPI==='cli')return;
 $uid=(string)($_SESSION['user_id']??'');if($uid==='')return;
 $total=0;$input=max(0,(int)($_SERVER['CONTENT_LENGTH']??0));$head=($_SERVER['REQUEST_METHOD']??'')==='HEAD';
 ob_start(static function($buffer,$phase)use(&$total,$uid,$input,$head){$total+=strlen($buffer);if($phase&PHP_OUTPUT_HANDLER_FINAL)sf_usage_record($uid,$head?0:$total,$input,'web',(int)(http_response_code()?:200));return $buffer;},8192);
}
function sf_usage_heartbeat(PDO $p,string $uid,string $device,string $kind):void{
 $now=time();$until=$kind==='playing'?$now+60:0;
 $sql='INSERT INTO sf_usage_presence(user_id,device_id,last_seen,playing_until) VALUES(?,?,?,?) ON CONFLICT(user_id,device_id) DO UPDATE SET last_seen=excluded.last_seen,playing_until=';
 $sql.=$kind==='web'?'sf_usage_presence.playing_until':'excluded.playing_until';
 $p->prepare($sql)->execute([$uid,$device,$now,$until]);
}
