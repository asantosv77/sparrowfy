<?php
declare(strict_types=1);
require_once __DIR__.'/charts_http.php';
require_once __DIR__.'/charts_import.php';
require_once __DIR__.'/spotify_charts.php';
function sfc_refresh(PDO $db,array $settings,bool $force=false):void {
 $now=new DateTimeImmutable('now',new DateTimeZone($settings['timezone']));$day=$now->format('Y-m-d');$week=sfc_week($now,$settings);$active=sfc_active($settings);$defs=sfc_definitions();
 $q=$db->prepare('UPDATE sf_music_charts SET enabled=0 WHERE chart_key NOT IN ('.implode(',',array_fill(0,count($active),'?')).')');$q->execute($active);
 foreach($active as $key){$def=$defs[$key];$db->prepare('INSERT INTO sf_music_charts(chart_key,provider,market,title,source_url,enabled) VALUES(?,?,?,?,?,1) ON CONFLICT(chart_key) DO UPDATE SET enabled=1')->execute([$key,$def['provider'],$def['market'],$def['title'],sfc_source_url($def['provider'],$def['market'])]);
  $q=$db->prepare('SELECT * FROM sf_music_charts WHERE chart_key=?');$q->execute([$key]);$chart=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
  $q=$db->prepare('SELECT data FROM sf_chart_snapshots WHERE chart_key=? AND day=?');$q->execute([$key,$day]);$raw=$q->fetchColumn();$q->closeCursor();$snapshot=$raw?json_decode($raw,true):null;
  $due=$force||!$chart['last_week']||$chart['last_week']!==$week;$capture=$force||(!$snapshot&&($settings['daily_capture']||$due));$recentError=$chart['error']&&strtotime((string)$chart['last_fetch'])>time()-3600;
  if($capture&&(!$recentError||$force)){try{
    $snapshot=$def['provider']==='spotify'?sfc_spotify_snapshot((string)$def['market']):sf_external_chart_fetch($def['provider'],$def['market']);$stamp=strtotime((string)$snapshot['source_updated']);if(($def['provider']!=='deezer'&&!$stamp)||($stamp&&$stamp<time()-(($snapshot['period_kind']??'')==='weekly'?10:3)*86400)||($stamp&&$stamp>time()+86400))throw new RuntimeException('El proveedor no publica datos actuales verificables; se conserva el ranking anterior');
    $db->prepare('INSERT INTO sf_chart_snapshots VALUES(?,?,?,?) ON CONFLICT(chart_key,day) DO UPDATE SET captured_at=excluded.captured_at,data=excluded.data')->execute([$key,$day,gmdate('c'),json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    $db->prepare('UPDATE sf_music_charts SET source_updated=?,last_fetch=?,error=NULL WHERE chart_key=?')->execute([$snapshot['source_updated'],gmdate('c'),$key]);
   }catch(Throwable $e){$db->prepare('UPDATE sf_music_charts SET last_fetch=?,error=? WHERE chart_key=?')->execute([gmdate('c'),mb_substr($e->getMessage(),0,700),$key]);continue;}
  }
  if($due&&$snapshot){$album=sfc_album($db,$key,$def);$items=sfc_select_items($snapshot,$settings);
   $db->beginTransaction();try{
    $db->prepare('DELETE FROM sf_chart_entries WHERE chart_key=?')->execute([$key]);
    foreach($items as $item){$identity=sf_chart_identity($item);$item['market']=$def['market'];$json=json_encode($item,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$db->prepare('INSERT INTO sf_chart_entries VALUES(?,?,?,?)')->execute([$key,$item['rank'],$identity,$json]);$db->prepare("INSERT INTO sf_chart_audio(identity,data,updated_at) VALUES(?,?,?) ON CONFLICT(identity) DO UPDATE SET data=excluded.data,state='new',updated_at=excluded.updated_at WHERE sf_chart_audio.state='retired'")->execute([$identity,$json,gmdate('c')]);}
    // Each new weekly batch may evaluate failed candidates again, once. Active and published jobs are retained.
    $db->prepare("UPDATE sf_chart_audio SET state='new',attempts='[]',error=NULL WHERE state='review' AND identity IN (SELECT identity FROM sf_chart_entries WHERE chart_key=?)")->execute([$key]);
    $db->prepare('UPDATE sf_music_charts SET last_week=? WHERE chart_key=?')->execute([$week,$key]);$db->commit();
   }catch(Throwable $e){$db->rollBack();throw $e;}
  }
 }
 // Keep a bounded year of metadata snapshots; audio is never pruned here.
 $db->prepare('DELETE FROM sf_chart_snapshots WHERE day<?')->execute([$now->modify('-370 days')->format('Y-m-d')]);
 sfc_collect_report($db,$week);
}
function sfc_tick(bool $force=false):array {
 $root=sfc_root();$lock=fopen($root.'/.worker.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))return ['state'=>'busy'];
 try{
  $settings=sfc_settings();$db=sfq_db();sfc_schema($db);sfmn_schema($db);$q=$db->prepare('SELECT id,role,is_active,can_manage_albums FROM users WHERE id=?');$q->execute([$settings['owner_id']]);$owner=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
  if(!$owner||empty($owner['is_active'])||($owner['role']!=='admin'&&empty($owner['can_manage_albums'])))throw new RuntimeException('La cuenta de procesamiento de Charts no está autorizada');
  $status=['state'=>'running','stage'=>'Consultando rankings','updated_at'=>gmdate('c')];sfc_json($root.'/status.json',$status);
  sfqp_schema($db);sfqp_prune($db);
  sfc_retire_disabled($db,$settings);
  if(!$settings['enabled']){$status['state']='disabled';$status['stage']='Music Charts desactivado';sfc_json($root.'/status.json',$status);return $status;}
  $request=$root.'/refresh.json';$requested=is_file($request);if($requested)@unlink($request);sfc_refresh($db,$settings,$force||$requested);sfc_cancel_unselected($db);sfc_reconcile_audio($db);
  $deadline=microtime(true)+50;$attempted=0;
  if($settings['auto_download']){
   $q=$db->query("SELECT DISTINCT a.* FROM sf_chart_audio a JOIN sf_chart_entries e ON e.identity=a.identity JOIN sf_music_charts c ON c.chart_key=e.chart_key WHERE c.enabled=1 AND COALESCE(json_extract(e.data,'$.genre_review'),0)=0 AND a.state='new' ORDER BY e.rank,a.identity LIMIT 3");$pending=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
   foreach($pending as $row){if(microtime(true)>$deadline)break;$q=$db->prepare('SELECT c.album_id FROM sf_music_charts c JOIN sf_chart_entries e ON e.chart_key=c.chart_key WHERE e.identity=? AND c.enabled=1 AND COALESCE(json_extract(e.data,\'$.genre_review\'),0)=0 ORDER BY c.chart_key LIMIT 1');$q->execute([$row['identity']]);$album=$q->fetchColumn();$q->closeCursor();if(!$album)continue;
    $song=json_decode($row['data'],true);$status['stage']='Buscando fuente: '.$song['artist'].' - '.$song['title'];$status['updated_at']=gmdate('c');sfc_json($root.'/status.json',$status);sfc_try_source($db,$row,$settings,$album);$attempted++;
   }
  }
  foreach($db->query('SELECT * FROM sf_music_charts WHERE enabled=1')->fetchAll(PDO::FETCH_ASSOC) as $chart){try{sfc_sync_album($db,$chart);$db->prepare("UPDATE sf_music_charts SET error=NULL WHERE chart_key=? AND error LIKE 'Organización pendiente:%'")->execute([$chart['chart_key']]);}catch(Throwable $e){$db->prepare('UPDATE sf_music_charts SET error=? WHERE chart_key=?')->execute(['Organización pendiente: '.mb_substr($e->getMessage(),0,500),$chart['chart_key']]);}}
  sfc_best_effort_report($db,$settings);
  require_once __DIR__.'/mornye_event_resolution.php';sfmn_reconcile_events($db,sfq_dir(),LAB_DATA.'/mornye-control/events.json');
  $counts=$db->query('SELECT a.state,count(*) total FROM sf_chart_audio a WHERE EXISTS(SELECT 1 FROM sf_chart_entries e JOIN sf_music_charts c ON c.chart_key=e.chart_key WHERE e.identity=a.identity AND c.enabled=1) GROUP BY a.state')->fetchAll(PDO::FETCH_KEY_PAIR);
  $status=['state'=>'idle','stage'=>'Seguimiento al día; la cola continúa las descargas y conversiones','updated_at'=>gmdate('c'),'counts'=>$counts,'searched_this_run'=>$attempted];sfc_json($root.'/status.json',$status);return $status;
 }catch(Throwable $e){$status=['state'=>'error','stage'=>mb_substr($e->getMessage(),0,700),'updated_at'=>gmdate('c')];sfc_json($root.'/status.json',$status);throw $e;}
 finally{flock($lock,LOCK_UN);fclose($lock);}
}
