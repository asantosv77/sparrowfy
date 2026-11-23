<?php
declare(strict_types=1);
/** Durable ownership marker for the cleanup of interrupted, unpublished outputs. */
function sf_output_checkpoint(string $dir,string $state,array $extra=[]):void {
 $file=$dir.'/.sf-output.json';$old=is_file($file)?json_decode((string)file_get_contents($file),true):[];$ctx=function_exists('sf_media_context')?sf_media_context():[];
 $start='';$stat=@file_get_contents('/proc/self/stat');if(is_string($stat)&&preg_match('/^\d+ \(.*\) (.*)$/s',$stat,$m))$start=(string)(preg_split('/\s+/',trim($m[1]))[19]??'');
 $data=array_replace(['version'=>1,'kind'=>'hls_output','created_at'=>time(),'task_id'=>(string)($ctx['task_id']??''),'album_id'=>(string)($ctx['album_id']??''),'operation'=>(string)($ctx['operation']??''),'pid'=>getmypid(),'process_start'=>$start],is_array($old)?$old:[],$extra,['state'=>$state,'updated_at'=>time()]);
 $tmp=$file.'.'.bin2hex(random_bytes(6)).'.tmp';
 try{$json=json_encode($data,JSON_THROW_ON_ERROR);if(file_put_contents($tmp,$json,LOCK_EX)!==strlen($json)||!@rename($tmp,$file))throw new RuntimeException('No se pudo registrar la salida de audio');chmod($file,0660);}finally{if(is_file($tmp))@unlink($tmp);}
}
function sf_output_mark_published(array $track,string $aid):void {
 try{$rel=(string)($track['hls']??'');$dir=realpath(dirname(LAB_ROOT.'/'.$rel));$root=realpath(LAB_HLS);if(!$dir||!$root||!str_starts_with($dir,$root.'/'))return;
  sf_output_checkpoint($dir,'published',['album_id'=>$aid,'track_id'=>(string)($track['id']??'')]);
 }catch(Throwable $e){sf_media_event('output.marker_deferred',['album_id'=>$aid,'track_id'=>$track['id']??''],$e);}
}
function sf_edit_temp_begin(string $task,string $aid):array {
 if(!preg_match('/^[a-f0-9]{32}$/D',$task))throw new RuntimeException('Tarea temporal inválida');
 $root=LAB_DATA.'/audio-edit-temp';if(!is_dir($root)&&!mkdir($root,02770,true))throw new RuntimeException('No se pudo preparar el temporal');
 if(!is_file($root.'/.htaccess')&&file_put_contents($root.'/.htaccess',"Require all denied\n")===false)throw new RuntimeException('No se pudo proteger el temporal');
 $dir=$root.'/'.$task.'-'.bin2hex(random_bytes(6));if(!mkdir($dir,02770))throw new RuntimeException('No se pudo crear el intento temporal');
 $lock=fopen($dir.'/.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('No se pudo bloquear el temporal');
 try{sf_output_checkpoint($dir,'building',['kind'=>'edit_temp','task_id'=>$task,'album_id'=>$aid]);return ['dir'=>$dir,'file'=>$dir.'/audio.m4a','lock'=>$lock];}
 catch(Throwable $e){flock($lock,LOCK_UN);fclose($lock);throw $e;}
}
function sf_edit_temp_close(?array $temp):void {
 if(!$temp)return;
 try{$dir=$temp['dir'];foreach(['audio.m4a','reconstructed.m4a'] as $name)if(is_file($dir.'/'.$name))@unlink($dir.'/'.$name);$left=array_diff(scandir($dir)?:[],['.','..','.sf-output.json','.lock']);if(!$left){@unlink($dir.'/.sf-output.json');@unlink($dir.'/.lock');@rmdir($dir);}else sf_media_event('audio_edit.temp_cleanup_deferred',['path'=>$dir]);}
 finally{if(is_resource($temp['lock'])){flock($temp['lock'],LOCK_UN);fclose($temp['lock']);}}
}
