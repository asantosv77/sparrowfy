<?php
declare(strict_types=1);
function sf_usage_scope(array $account,string $requested):?string{
 $uid=(string)$account['id'];if(($account['role']??'')==='admin')return $requested==='all'?null:($requested===''?$uid:$requested);
 if($requested!==''&&$requested!==$uid)throw new RuntimeException('Sin permiso para consultar esa cuenta');return $uid;
}
function sf_usage_report(PDO $p,?string $uid,int $now=0):array{
 $now=$now?:time();$tz=new DateTimeZone('America/Mexico_City');$local=(new DateTimeImmutable('@'.$now))->setTimezone($tz);$today=$local->setTime(0,0);
 $month=$today->modify('first day of this month');$monthEnd=$month->modify('+1 month');$week=$today->modify('monday this week');$weekEnd=$week->modify('+7 days');
 $start=min($month->format('Y-m-d'),$week->format('Y-m-d'));$end=max($monthEnd->format('Y-m-d'),$weekEnd->format('Y-m-d'));
 $where=$uid!==null?' AND user_id=?':'';$args=[$start,$end];if($uid!==null)$args[]=$uid;
 $q=$p->prepare('SELECT day,SUM(downloaded) downloaded,SUM(uploaded) uploaded,SUM(audio) audio,SUM(requests) requests,SUM(errors) errors FROM sf_usage_daily WHERE day>=? AND day<?'.$where.' GROUP BY day');$q->execute($args);$daily=[];
 foreach($q->fetchAll() as $r){foreach(['downloaded','uploaded','audio','requests','errors'] as $k)$r[$k]=(int)$r[$k];$daily[$r['day']]=$r;}
 $series=static function($from,$to)use($daily){$items=[];for($day=$from;$day<$to;$day=$day->modify('+1 day')){$date=$day->format('Y-m-d');$items[]=$daily[$date]??['day'=>$date,'downloaded'=>0,'uploaded'=>0,'audio'=>0,'requests'=>0,'errors'=>0];}return $items;};
 $weekly=$series($week,$weekEnd);$monthly=$series($month,$monthEnd);$totals=['downloaded'=>0,'uploaded'=>0,'audio'=>0,'requests'=>0,'errors'=>0];foreach($monthly as $r)foreach($totals as $k=>$_)$totals[$k]+=$r[$k];
 $minute=(int)floor($now/60)*60;$table=$uid===null?'sf_usage_global':'sf_usage_minute';$filter=$uid===null?'':' AND user_id=?';$args=[$minute-3600,$minute];if($uid!==null)$args[]=$uid;
 $q=$p->prepare('SELECT minute,downloaded,uploaded FROM '.$table.' WHERE minute>=? AND minute<?'.$filter.' ORDER BY minute');$q->execute($args);$minutes=[];foreach($q->fetchAll() as $r)$minutes[(int)$r['minute']]=$r;
 $bandwidth=[];for($t=$minute-3600;$t<$minute;$t+=60){$r=$minutes[$t]??[];$bandwidth[]=['time'=>$t,'out_mbps'=>(int)($r['downloaded']??0)*8/60000000,'in_mbps'=>(int)($r['uploaded']??0)*8/60000000];}
 if($uid===null){
  $q=$p->prepare('SELECT minute AS peak_at,downloaded AS peak_out FROM sf_usage_global WHERE minute>=? AND minute<? ORDER BY downloaded DESC,minute LIMIT 1');$q->execute([$month->getTimestamp(),$minute]);$peak=$q->fetch()?:null;
  $raw=$p->query("SELECT value FROM sf_usage_meta WHERE key='global_peak_out'")->fetchColumn();$allPeak=$raw?json_decode($raw,true):null;
 }else{
  $q=$p->prepare('SELECT peak_at,peak_out FROM sf_usage_daily WHERE user_id=? AND day>=? AND day<? ORDER BY peak_out DESC LIMIT 1');$q->execute([$uid,$month->format('Y-m-d'),$monthEnd->format('Y-m-d')]);$peak=$q->fetch()?:null;
  $q=$p->prepare('SELECT peak_at,peak_out FROM sf_usage_daily WHERE user_id=? ORDER BY peak_out DESC LIMIT 1');$q->execute([$uid]);$allPeak=$q->fetch()?:null;
 }
 $p->sqliteCreateFunction('sf_usage_stamp',static fn($s)=>hash('sha256',(string)$s),1);
 $presence=" FROM sf_usage_presence s JOIN users u ON u.id=s.user_id JOIN sf_devices d ON d.id=s.device_id AND d.user_id=s.user_id LEFT JOIN sf_device_epochs e ON e.user_id=u.id WHERE s.last_seen>=? AND u.is_active=1 AND d.revoked_at IS NULL AND d.epoch=COALESCE(e.epoch,0) AND d.credential_stamp=sf_usage_stamp(u.password_hash)";
 $args=[$now,$now-120];if($uid!==null){$presence.=' AND s.user_id=?';$args[]=$uid;}
 $q=$p->prepare('SELECT COUNT(DISTINCT s.user_id) users,COUNT(*) sessions,COUNT(DISTINCT CASE WHEN s.playing_until>? THEN s.user_id END) playing'.$presence);$q->execute($args);$activity=$q->fetch();
 $meta=[];foreach($p->query('SELECT key,value FROM sf_usage_meta') as $r)$meta[$r['key']]=$r['value'];
 return ['timezone'=>'America/Mexico_City','now'=>$now,'since'=>(int)($meta['since']??$now),'last_rollup'=>(int)($meta['last_rollup']??0),'today'=>$daily[$today->format('Y-m-d')]??['downloaded'=>0,'uploaded'=>0],'totals'=>$totals,'week'=>$weekly,'month'=>$monthly,'bandwidth'=>$bandwidth,'peak_month'=>$peak,'peak_all'=>$allPeak,'activity'=>$activity,'scope'=>$uid??'all'];
}
