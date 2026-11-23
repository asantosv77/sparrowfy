<?php
declare(strict_types=1);
require_once __DIR__.'/mornye_requests.php';
/** Quarantine only expired, terminal app-owned downloads, never active/unknown remote work. */
function sfmn_quarantine_expired(PDO $db):void {
 $root=realpath('/sparrowfy/inbox');if(!$root)return;
 sfmn_quarantine_scan($db,$root,sfq_dir(),'sf_external_mornye_status','sfmn_event',sfmn_dir().'/quarantine-cursor.json');
}
/** Injectable filesystem/transport boundaries for isolated CLI regression tests. */
function sfmn_quarantine_scan(PDO $db,string $root,string $receipts,callable $statusReader,callable $notify,?string $cursorFile=null):void {
 $checked=0;$remoteChecks=0;$last='';$cursor=$cursorFile?json_decode((string)@file_get_contents($cursorFile),true):[];$after=(string)($cursor['after']??'');
 $dirs=glob($root.'/sf-*',GLOB_ONLYDIR)?:[];sort($dirs,SORT_STRING);if($after!=='')$dirs=array_merge(array_values(array_filter($dirs,static fn($d)=>basename($d)>$after)),array_values(array_filter($dirs,static fn($d)=>basename($d)<=$after)));
 foreach($dirs as $dir){if(++$checked>200||$remoteChecks>=3)break;$last=basename($dir);$id=substr(basename($dir),3);if(!preg_match('/^[a-f0-9]{32}$/D',$id)||is_link($dir)||dirname(realpath($dir)?:'')!==$root)continue;
 $q=$db->prepare('SELECT state,updated_at FROM sf_processing_jobs WHERE id=?');$q->execute([$id]);$j=$q->fetch(PDO::FETCH_ASSOC);if(!$j||!in_array($j['state'],['done','failed','cancelled'],true)||strtotime($j['updated_at'])>time()-86400)continue;
 $q=$db->prepare("SELECT COUNT(*) FROM sf_processing_jobs WHERE json_extract(payload,'$.mornye_parent')=? AND state IN ('queued','running')");$q->execute([$id]);if($q->fetchColumn())continue;
 $receipt=$receipts.'/mornye-'.$id.'.json';$r=json_decode((string)@file_get_contents($receipt),true);if(empty($r['remote_id'])||!empty($r['submitting']))continue;
 ++$remoteChecks;try{$remote=$statusReader($r['remote_id']);if(!in_array($remote['status'],['done','failed','cancelled'],true))continue;}catch(Throwable $e){continue;}
 $target=$root.'/.quarantine';if(is_link($target))continue;if(!is_dir($target)&&!mkdir($target,02770))continue;if(realpath($target)!==$root.'/.quarantine')continue;
 $destination=$target.'/sf-'.$id.'-'.gmdate('YmdHis');$moved=false;$db->exec('BEGIN IMMEDIATE');
 try{
  $q=$db->prepare('SELECT state,updated_at FROM sf_processing_jobs WHERE id=?');$q->execute([$id]);$fresh=$q->fetch(PDO::FETCH_ASSOC);
  $q=$db->prepare("SELECT COUNT(*) FROM sf_processing_jobs WHERE json_extract(payload,'$.mornye_parent')=? AND state IN ('queued','running')");$q->execute([$id]);$active=(int)$q->fetchColumn();
  $latest=json_decode((string)@file_get_contents($receipt),true);
  if($fresh===$j&&!$active&&empty($latest['submitting'])&&($latest['remote_id']??'')===$r['remote_id']&&is_dir($dir)&&!is_link($dir))$moved=rename($dir,$destination);
  $db->exec('COMMIT');
 }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
 if($moved){sfmn_write($destination.'/sparrowfy-quarantine.json',['job_id'=>$id,'reason'=>'Temporal inactivo por más de 24 horas; tarea externa terminal','quarantined_at'=>gmdate('c')]);$notify('inbox.quarantined.'.$id,'Temporal Mornye abandonado enviado a cuarentena; no se eliminó definitivamente.','warning');}
 }
 if($cursorFile&&$last!=='')sfmn_write($cursorFile,['after'=>$last,'checked_at'=>time()]);
}
