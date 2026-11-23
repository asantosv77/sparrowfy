<?php
declare(strict_types=1);
/** Diagnostic only. No exception from logging may interrupt an audio operation. */
function sf_media_context(?array $set=null):array {
 static $value=[];
 if($set!==null)$value=array_intersect_key($set,array_flip(['task_id','album_id','operation']));
 return $value;
}
function sf_media_log_path(string $path):?string {
 $path=str_replace('\\','/',$path);
 $root=defined('LAB_ROOT')?str_replace('\\','/',LAB_ROOT):dirname(__DIR__);
 if(str_starts_with($path,$root.'/'))$path=substr($path,strlen($root)+1);
 if(defined('LAB_HLS')){$hls=realpath(LAB_HLS);if($hls&&str_starts_with($path,$hls.'/'))$path='hls_out/'.substr($path,strlen($hls)+1);}
 if(!preg_match('~^(?:hls_out/|data/(?:albums|client-processing|processing_jobs|audio-edit-temp)/)~D',$path)||preg_match('~[\x00-\x1f\x7f:?#]~',$path)||in_array('..',explode('/',$path),true))return null;
 return mb_substr($path,0,1024,'UTF-8');
}
/** Read the flag directly: avoid recursive configuration/logging calls. */
function sf_media_log_enabled():bool {
 static $checked=0.0,$enabled=true;
 if(microtime(true)-$checked<2.0)return $enabled;
 $checked=microtime(true);
 $path=defined('LAB_CONFIG')?LAB_CONFIG:(defined('LAB_DATA')?LAB_DATA:dirname(__DIR__).'/data').'/config.json';
 $cfg=is_file($path)?json_decode((string)@file_get_contents($path),true):[];
 $enabled=!is_array($cfg)||!array_key_exists('media_operations_log_enabled',$cfg)||(bool)$cfg['media_operations_log_enabled'];
 return $enabled;
}
function sf_media_event(string $event,array $data=[],?Throwable $error=null):void {
 static $warned=false,$trace=null,$watching=false,$reserve=null;
 try{
  if(!sf_media_log_enabled())return;
  if(!$watching){$watching=true;$reserve=str_repeat('r',65536);register_shutdown_function(static function()use(&$reserve):void{$reserve=null;$last=error_get_last();if($last&&in_array($last['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR],true))sf_media_event('process.fatal',[],new ErrorException($last['message'],0,$last['type'],$last['file'],$last['line']));});}
  $trace??=bin2hex(random_bytes(8));$safe=[];$data=array_replace(sf_media_context(),$data);
  foreach(['task_id','album_id','track_id','job_id','operation','state','reason','journal_id'] as $key){
   if(isset($data[$key])&&is_scalar($data[$key])&&preg_match('/^[a-zA-Z0-9_.-]{1,128}$/D',(string)$data[$key]))$safe[$key]=(string)$data[$key];
  }
  foreach(['path','source','destination'] as $key)if(isset($data[$key])&&is_string($data[$key])){$path=sf_media_log_path($data[$key]);if($path!==null)$safe[$key]=$path;}
  foreach(['remaining','files','bytes'] as $key)if(isset($data[$key])&&is_numeric($data[$key]))$safe[$key]=max(0,(int)$data[$key]);
  if($error){$safe['error_class']=get_class($error);$safe['error_code']=(string)$error->getCode();$safe['error_fingerprint']=hash('sha256',$error->getMessage());$safe['error_file']=basename($error->getFile());$safe['error_line']=$error->getLine();}
  $record=['time'=>gmdate('c'),'event'=>preg_replace('/[^a-zA-Z0-9_.-]/','_',substr($event,0,80)),'request_id'=>function_exists('sf_control_request_id')?sf_control_request_id():$trace,'pid'=>getmypid()]+$safe;
  $dir=(defined('LAB_DATA')?LAB_DATA:dirname(__DIR__).'/data').'/media-log';
  if(!is_dir($dir)&&!@mkdir($dir,02770,true)&&!is_dir($dir))throw new RuntimeException('Log directory unavailable');
  if(is_link($dir))throw new RuntimeException('Log directory must not be a symlink');
  if(!is_file($dir.'/.htaccess')&&@file_put_contents($dir.'/.htaccess',"Require all denied\n",LOCK_EX)===false)throw new RuntimeException('Cannot protect media log');
  $lock=@fopen($dir.'/.lock','c');if(!$lock)throw new RuntimeException('Log lock unavailable');
  $held=false;
  try{
   // Bounded wait: logging must never hold up conversion or playback.
   for($i=0;$i<5;$i++){if(flock($lock,LOCK_EX|LOCK_NB)){$held=true;break;}usleep(10000);}
   if(!$held)throw new RuntimeException('Log busy');
   $path=$dir.'/events.jsonl';clearstatcache(true,$path);
   if(is_file($path)&&filesize($path)>=2097152){
    if(is_file($path.'.5')&&!@unlink($path.'.5'))throw new RuntimeException('Log rotation failed');
    for($i=4;$i>=1;$i--)if(is_file($path.'.'.$i)&&!@rename($path.'.'.$i,$path.'.'.($i+1)))throw new RuntimeException('Log rotation failed');
    if(!@rename($path,$path.'.1'))throw new RuntimeException('Log rotation failed');
   }
   $line=json_encode($record,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR)."\n";
   if(@file_put_contents($path,$line,FILE_APPEND)!==strlen($line))throw new RuntimeException('Log write failed');
   @chmod($path,0660);@chmod($dir.'/.htaccess',0660);@chmod($dir.'/.lock',0660);
  }finally{if($held)flock($lock,LOCK_UN);fclose($lock);}
 }catch(Throwable $ignored){if(!$warned){$warned=true;error_log('Sparrow-Fy media log unavailable; audio operation continues');}}
}
