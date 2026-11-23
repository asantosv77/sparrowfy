<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require dirname(__DIR__).'/lib.php';require dirname(__DIR__).'/lib/loudness.php';
$lock=fopen(LAB_DATA.'/.loudness-analysis.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit;
foreach(lab_list_albums() as $album)foreach($album['tracks']??[] as $track){try{sf_loudness_analyze($track);}catch(Throwable $e){error_log('Loudness backfill: '.get_class($e));}usleep(300000);}
echo "Loudness analysis completed\n";
