<?php
declare(strict_types=1);
require_once __DIR__.'/client_processing.php';
/** Persist only this authenticated job's downloaded source for desktop WebCodecs. */
function sfmn_stage_client_source(PDO $db,array $job,array $payload,string $source,string $filename,array $cfg):void {
 $sid=(string)$payload['mornye_client_source'];if(!preg_match('/^[a-f0-9]{32}$/D',$sid))throw new RuntimeException('Sesión local inválida');
 $dir=sfwc_root().'/'.$sid;$m=json_decode((string)file_get_contents($dir.'/state.json'),true);
 if(($m['user']??'')!==$job['user_id']||($m['album_id']??'')!==$job['album_id'])throw new RuntimeException('Propietario de fuente inválido');
 $ext=strtolower(pathinfo($source,PATHINFO_EXTENSION));if(!in_array($ext,['m4a','webm','opus','mp3','ogg','aac','mp4','flac','wav','aiff','alac'],true))throw new RuntimeException('Formato Mornye no compatible');
 if(!is_file($source)||is_link($source)||filesize($source)>500*1048576)throw new RuntimeException('Fuente Mornye no válida');
 [, $ffprobe]=lab_ffmpeg();$probe=lab_probe_audio($source,$ffprobe);if(empty($probe['ok'])||$probe['duration']<=0)throw new RuntimeException('No se pudo examinar el audio Mornye');
 $dest=$dir.'/source.'.$ext;if(!copy($source,$dest))throw new RuntimeException('No se pudo conservar la fuente local');chmod($dest,0600);
 $m['source_file']=basename($dest);$m['filename']=$filename;$m['duration']=$probe['duration'];$m['source_kbps']=$probe['kbps'];$m['source_codec']=$probe['codec'];$m['mornye_parent']=$job['id'];$m['mornye_url']=$payload['url'];$m['source_url']=$payload['url'];$m['source_service']='mornye';$m['state']='source_ready';$m['updated']=time();sfwc_save($dir,$m);
 sfq_finish($db,$job['id'],['source_id'=>$sid,'filename'=>$filename,'duration'=>$m['duration'],'album_id'=>$job['album_id']]);
}
