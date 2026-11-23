<?php
require __DIR__.'/lib.php';lab_auth_require_album_manager();require_once __DIR__.'/lib/text_artwork.php';header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit('{}');}
try{
 $cursor=max(0,(int)($_POST['cursor']??0));$aid='fffffffffffffffe';$album=lab_load_album($aid);$tracks=$album['tracks']??[];$batch=array_slice($tracks,$cursor,3);$total=count($tracks);$matches=[];$skipped=0;$errors=0;$had=0;
 $global=fopen(LAB_DATA.'/.text-artwork.lock','c');if(!$global||!flock($global,LOCK_EX|LOCK_NB))throw new RuntimeException('Ya hay una búsqueda de portadas en curso.');
 session_write_close();set_time_limit(60);
 try{
 foreach($batch as $t){
  if(!empty($t['artwork'])){$had++;continue;}$pairs=sf_art_pairs($t);if(!$pairs){$skipped++;continue;}
  $query=http_build_query(['term'=>sf_art_clean((string)$t['name']).' '.(string)($t['artist']??''),'entity'=>'song','country'=>'US','limit'=>25]);
  $ch=curl_init('https://itunes.apple.com/search?'.$query);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>8,CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_USERAGENT=>'SparrowFy/1.0']);$raw=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
  $data=is_string($raw)?json_decode($raw,true):null;
  if($status!==200||!is_array($data)){$errors++;continue;}
  $cover=sf_art_choose($t,$pairs,$data['results']??[]);if($cover)$matches[$t['id']]=['artwork'=>$cover,'name'=>$t['name']];else $skipped++;usleep(350000);
 }
 $added=0;
 if($matches){$lock=fopen(LAB_ALBUMS.'/'.$aid.'/.processing-append.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Colección ocupada');try{$latest=lab_load_album($aid);foreach($latest['tracks'] as &$t){$m=$matches[$t['id']]??null;if($m&&empty($t['artwork'])&&$t['name']===$m['name']){$t['artwork']=$m['artwork'];$added++;}}unset($t);if($added)lab_save_album($latest);}finally{flock($lock,LOCK_UN);fclose($lock);}}
 $next=$cursor+count($batch);echo json_encode(['added'=>$added,'skipped'=>$skipped,'existing'=>$had,'errors'=>$errors,'next'=>$next,'total'=>$total,'done'=>$next>=$total]);
 }finally{flock($global,LOCK_UN);fclose($global);}
}catch(Throwable $e){http_response_code(400);echo json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
