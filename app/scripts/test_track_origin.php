<?php
if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/lib/track_origin.php';require dirname(__DIR__).'/lib/track_replace.php';
function ck($v,$m){if(!$v)throw new RuntimeException($m);echo "PASS $m\n";}
$yt=sf_track_origin_fields('youtube',['url'=>'https://youtu.be/abcdefghijk?si=tracking']);ck($yt['source_service']==='youtube'&&str_contains($yt['source_url'],'abcdefghijk')&&!str_contains($yt['source_url'],'tracking'),'canonical durable YouTube URL');
$mn=sf_track_origin_fields('mornye',['url'=>'https://open.spotify.com/track/'.str_repeat('A',22).'?si=tracking']);ck($mn['source_url']==='https://open.spotify.com/track/'.str_repeat('A',22),'canonical durable Mornye URL');
foreach(['client_hls','upload'] as $op){ck(sf_track_origin_fields($op,$yt)===$yt,'YouTube provenance survives '.$op);ck(sf_track_origin_fields($op,$mn+['mornye_parent'=>'job','mornye_url'=>$mn['source_url']])===$mn,'Mornye provenance survives '.$op);}
$old=['id'=>str_repeat('1',16),'name'=>'chosen']+$mn+['source_mornye_job'=>'old','source_mornye_url'=>$mn['source_url']];$new=sf_replace_merge($old,$yt,[],'job');ck($new['source_url']===$yt['source_url']&&$new['source_service']==='youtube'&&!isset($new['source_mornye_job'],$new['source_mornye_url']),'cross-provider replacement clears obsolete provenance');$new=sf_replace_merge($old,[],[],'local');ck(!isset($new['source_url'],$new['source_service'],$new['source_mornye_url'],$new['source_mornye_job']),'local replacement does not retain a false external origin');
$rejected=false;try{sf_track_origin_fields('upload',['source_service'=>'youtube','source_url'=>'https://cdn.invalid/signed-audio?token=secret']);}catch(Throwable $e){$rejected=true;}ck($rejected,'temporary download host cannot become permanent source URL');
