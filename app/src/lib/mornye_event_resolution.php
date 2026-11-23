<?php
declare(strict_types=1);
/** Provider attempts are history; alert only after the import routine stops retrying. */
function sfmn_resolved_events(array $events,PDO $db,string $receiptDir):array {
    $rows=$db->query("SELECT id,user_id,operation,album_id,payload,state,result_json,created_at,finished_at FROM sf_processing_jobs WHERE operation IN ('mornye','client_hls') OR (operation='upload' AND json_extract(payload,'$.mornye_parent') IS NOT NULL)")->fetchAll(PDO::FETCH_ASSOC);
    $jobs=[];$roots=[];$completed=[];$groups=[];$recovering=[];$links=[];$owners=[];$receipts=[];
    foreach($rows as $row){$row['decoded']=json_decode((string)$row['payload'],true)?:[];$row['result']=json_decode((string)($row['result_json']??''),true)?:[];$jobs[$row['id']]=$row;if($row['operation']==='mornye')$roots[$row['id']]=$row;}
    $key=static function(array $row):string{$p=$row['decoded'];return empty($p['url'])?'':json_encode([$row['user_id'],$row['album_id'],$p['url'],$p['replace_track_id']??'',$p['expected_hls']??'']);};
    foreach($jobs as $row){
        if($row['state']!=='done'||empty($row['result']['track']['id'])||empty($row['result']['track']['hls']))continue;
        $source=$row['operation']==='mornye'?$row['id']:($row['decoded']['mornye_parent']??'');
        if(!isset($roots[$source]))continue;
        $success=['job_id'=>$source,'finished_at'=>$row['finished_at']];
        if(!isset($completed[$source])||strtotime((string)$success['finished_at'])>strtotime((string)$completed[$source]['finished_at']))$completed[$source]=$success;
    }
    foreach($completed as $id=>$success){$group=$key($roots[$id]);if($group!==''&&(!isset($groups[$group])||strtotime((string)$groups[$group]['finished_at'])<strtotime((string)$success['finished_at'])))$groups[$group]=$success;}
    $chartCompleted=[];$chartRecovering=[];$chartRoots=[];
    foreach($roots as $id=>$row)if(!empty($row['decoded']['chart_identity']))$chartRoots[$id]=true;
    // An optional Charts import can still be trying YouTube after Mornye stopped.
    $columns=$db->query('PRAGMA table_info(sf_chart_audio)')->fetchAll(PDO::FETCH_COLUMN,1);
    if(in_array('mornye_job_id',$columns,true))foreach($db->query('SELECT mornye_job_id,state,job_id,updated_at FROM sf_chart_audio WHERE mornye_job_id IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC) as $chart){
        $root=(string)$chart['mornye_job_id'];if(!isset($roots[$root]))continue;$chartRoots[$root]=true;
        if($chart['state']==='ready')$chartCompleted[$root]=['job_id'=>$chart['job_id'],'finished_at'=>$chart['updated_at'],'reason'=>'charts_recovered'];
        elseif(in_array($chart['state'],['new','queued'],true))$chartRecovering[$root]=true;
    }
    foreach($roots as $id=>$row){
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))continue;
        $group=$key($row);
        if(in_array($row['state'],['queued','running','done'],true)&&!isset($completed[$id])&&$group!=='')$recovering[$group]=max($recovering[$group]??0,strtotime($row['created_at']));
        $receipt=json_decode((string)@file_get_contents($receiptDir.'/mornye-'.$id.'.json'),true)?:[];$receipts[$id]=$receipt;
        $aliases=[$id,$receipt['remote_id']??'',$receipt['previous_remote_id']??''];foreach($receipt['history']??[] as $attempt)$aliases[]=$attempt['id']??'';
        $success=$completed[$id]??$groups[$group]??$chartCompleted[$id]??null;
        if($success&&strtotime($row['created_at'])>strtotime((string)$success['finished_at']))$success=null;
        foreach($aliases as $alias){if(!is_string($alias)||!preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$alias))continue;$owners[$alias]=$id;if($success)$links[$alias]=$success;}
    }
    foreach($events as &$event){
        if(!empty($event['archived'])){$event['deferred']=true;$event['read']=true;continue;}
        $event['resolved']=!empty($event['resolved']);$event['deferred']=false;unset($event['defer_reason'],$event['final_failure'],$event['notice_key']);
        $code=(string)($event['code']??'');
        if(str_starts_with($code,'charts.audio.failed.')){
            $batch=substr($code,20);
            if($db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='sf_chart_outcomes'")->fetchColumn()){
                $q=$db->prepare('SELECT o.state,c.enabled,c.last_week,o.edition FROM sf_chart_outcomes o LEFT JOIN sf_music_charts c ON c.chart_key=o.chart_key WHERE o.batch_id=?');$q->execute([$batch]);$outcome=$q->fetch(PDO::FETCH_ASSOC);
                if(!$outcome||!$outcome['enabled']||$outcome['last_week']!==$outcome['edition']){$event['archived']=true;$event['read']=true;$event['deferred']=true;}
                elseif($outcome['state']!=='failed'){$event['resolved']=true;$event['read']=true;$event['resolution']=['reason'=>'charts_recovered'];}
            }
            continue;
        }
        if(!preg_match('/^(import\.(?:failed|retry|paused)|download\.failed|inbox\.cleaned)\.([A-Za-z0-9_-]+)$/D',$code,$match))continue;
        $eventRoot=$owners[$match[2]]??null;
        if($eventRoot!==null&&isset($chartRoots[$eventRoot])){$event['archived']=true;$event['read']=true;$event['deferred']=true;$event['defer_reason']='charts_best_effort';continue;}
        if(isset($links[$match[2]])){$event['resolved']=true;$event['resolution']=$links[$match[2]];}
        if($event['resolved']){$event['read']=true;continue;}
        $root=$owners[$match[2]]??null;
        if($root!==null){
            $row=$roots[$root];$group=$key($row);
            if(isset($chartRoots[$root])){$event['archived']=true;$event['read']=true;$event['deferred']=true;$event['defer_reason']='charts_best_effort';unset($event['final_failure']);continue;}$event['notice_key']='mornye-import:'.$root.':'.(string)($row['finished_at']??'');
            $retrying=isset($chartRecovering[$root])||$row['state']!=='failed'||($group!==''&&($recovering[$group]??0)>=strtotime($row['created_at']));
            $previous=$match[1]==='import.retry'||($match[1]==='download.failed'&&!empty($receipts[$root]['remote_id'])&&$receipts[$root]['remote_id']!==$match[2]);
            if($retrying||$previous){$event['deferred']=true;$event['defer_reason']=$retrying?'retrying':'previous_attempt';}
            else $event['final_failure']=true;
        }elseif($match[1]==='download.failed'&&time()-(int)($event['first']??0)<15){
            // Let the local receipt arrive before treating an unlinked remote failure as final.
            $event['deferred']=true;$event['defer_reason']='identifying';
        }
    }unset($event);
    return $events;
}

/** Persist successful resolutions as confirmed history, retaining original messages/counts. */
function sfmn_reconcile_events(PDO $db,string $receiptDir,string $eventsPath):array {
    require_once __DIR__.'/mornye_control.php';
    if(!is_file($eventsPath))return [];
    $lock=fopen(dirname($eventsPath).'/.lock','c');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('No se pudo actualizar el estado de los avisos Mornye.');
    try{
        $rows=json_decode((string)file_get_contents($eventsPath),true)?:[];
        $decorated=sfmn_resolved_events($rows,$db,$receiptDir);$changed=false;
        foreach($decorated as $i=>$event){
            if(!empty($event['archived'])){$confirmed=$rows[$i];$confirmed['archived']=true;$confirmed['read']=true;$confirmed['archive_reason']='charts_best_effort';if($confirmed!==$rows[$i]){$rows[$i]=$confirmed;$changed=true;}continue;}
            if(empty($event['resolved']))continue;
            $confirmed=$rows[$i];$confirmed['read']=true;$confirmed['resolved']=true;$confirmed['auto_confirmed']=true;
            $confirmed['confirmed_at']=$confirmed['confirmed_at']??time();
            if(isset($event['resolution']))$confirmed['resolution']=$event['resolution'];
            if($confirmed!==$rows[$i]){$rows[$i]=$confirmed;$changed=true;}
        }
        if($changed)sfmn_write($eventsPath,$rows);
        return $decorated;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
