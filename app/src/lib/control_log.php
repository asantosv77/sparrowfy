<?php
declare(strict_types=1);
function sf_control_request_id():string{static $id=null;return $id??=bin2hex(random_bytes(8));}
/** Operational metadata only: never persist cookies, URLs, payloads or credentials. */
function sf_control_event(string $event,array $data=[]):void{
 try{
  $request=sf_control_request_id();
  $allowed=['album_id','job_id','user_id','operation','stage','progress','bytes','files','status','duration_ms','error_class','error_code','sql_code','error_file','error_line','error_fingerprint','imported','failed','success','reason','account_fingerprint','session_cookie_present'];
  $safe=array_intersect_key($data,array_flip($allowed));
  foreach($safe as $k=>$v){if(!is_scalar($v)&&$v!==null)unset($safe[$k]);elseif(is_string($v))$safe[$k]=substr($v,0,240);}
  $record=['time'=>gmdate('c'),'event'=>substr($event,0,80),'request_id'=>$request,'pid'=>getmypid(),'user_id'=>(string)($_SESSION['user_id']??'')]+$safe;
  if(isset($safe['user_id']))$record['user_id']=$safe['user_id'];
  $dir=dirname(__DIR__).'/data/control-log';if(!is_dir($dir)&&!@mkdir($dir,02770,true)&&!is_dir($dir))throw new RuntimeException();
  $lock=fopen($dir.'/.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException();
  try{
   $path=$dir.'/events.jsonl';clearstatcache(true,$path);
   if(is_file($path)&&filesize($path)>=2097152){for($i=4;$i>=1;$i--){$old=$path.'.'.$i;if(is_file($old))rename($old,$path.'.'.($i+1));}rename($path,$path.'.1');}
   $line=json_encode($record,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR)."\n";
   if(file_put_contents($path,$line,FILE_APPEND)===false)throw new RuntimeException();@chmod($path,0660);
  }finally{flock($lock,LOCK_UN);fclose($lock);}
 }catch(Throwable $ignored){error_log('Sparrow-Fy control log unavailable');}
}
function sf_control_error(string $event,Throwable $e,array $data=[]):void{
 sf_control_event($event,$data+['error_class'=>get_class($e),'error_code'=>(string)$e->getCode(),'sql_code'=>$e instanceof PDOException?(int)($e->errorInfo[1]??0):null,'error_file'=>basename($e->getFile()),'error_line'=>$e->getLine(),'error_fingerprint'=>hash('sha256',$e->getMessage())]);
}
function sf_control_request(string $operation):void{
 $start=microtime(true);$base=['operation'=>$operation,'album_id'=>substr((string)($_POST['album_id']??''),0,32),'bytes'=>max(0,(int)($_SERVER['CONTENT_LENGTH']??0)),'files'=>count($_FILES)];
 sf_control_event('request.received',$base);
 register_shutdown_function(static function()use($start,$base){$last=error_get_last();$fatal=$last&&in_array($last['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true);sf_control_event($fatal?'request.fatal':'request.finished',$base+['status'=>http_response_code()?:200,'duration_ms'=>(int)((microtime(true)-$start)*1000)]+($fatal?['error_file'=>basename($last['file']),'error_line'=>$last['line']]:[]));});
}
