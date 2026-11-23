<?php
declare(strict_types=1);
require_once __DIR__.'/worker_access.php';

function sfda_schema(PDO $db): void {
    sf_worker_schema($db);
    $db->exec("CREATE TABLE IF NOT EXISTS sf_desktop_agents(request_id TEXT PRIMARY KEY,
        token_hash TEXT,credential_stamp TEXT,pair_hash TEXT,pair_expires INTEGER,last_seen INTEGER,
        version TEXT,capabilities TEXT)");
    $db->exec("CREATE TABLE IF NOT EXISTS sf_desktop_tasks(id TEXT PRIMARY KEY,request_id TEXT NOT NULL,
        kind TEXT NOT NULL,parent_id TEXT,source TEXT NOT NULL,source_hash TEXT NOT NULL,source_bytes INTEGER NOT NULL,
        rates TEXT NOT NULL,duration REAL NOT NULL,sample_rate INTEGER NOT NULL,segment_seconds INTEGER NOT NULL,
        state TEXT NOT NULL,lease_hash TEXT,lease_until INTEGER,created_at INTEGER NOT NULL,
        updated_at INTEGER NOT NULL,manifest TEXT,error TEXT)");
    $db->exec('CREATE INDEX IF NOT EXISTS sfda_tasks_pending ON sf_desktop_tasks(request_id,state,created_at)');
    $db->exec('CREATE TABLE IF NOT EXISTS sf_worker_log_options(id INTEGER PRIMARY KEY,enabled INTEGER NOT NULL)');
    $db->exec('INSERT OR IGNORE INTO sf_worker_log_options(id,enabled) VALUES(1,1)');
    $db->exec('CREATE TABLE IF NOT EXISTS sf_worker_task_events(id INTEGER PRIMARY KEY AUTOINCREMENT,task_id TEXT NOT NULL,state TEXT NOT NULL,created_at INTEGER NOT NULL)');
    $db->exec("CREATE TRIGGER IF NOT EXISTS sf_worker_log_insert AFTER INSERT ON sf_desktop_tasks WHEN (SELECT enabled FROM sf_worker_log_options WHERE id=1)=1 BEGIN INSERT INTO sf_worker_task_events(task_id,state,created_at) VALUES(NEW.id,NEW.state,NEW.updated_at); DELETE FROM sf_worker_task_events WHERE id NOT IN (SELECT id FROM sf_worker_task_events ORDER BY id DESC LIMIT 1000); END");
    $db->exec("CREATE TRIGGER IF NOT EXISTS sf_worker_log_update AFTER UPDATE OF state ON sf_desktop_tasks WHEN OLD.state!=NEW.state AND (SELECT enabled FROM sf_worker_log_options WHERE id=1)=1 BEGIN INSERT INTO sf_worker_task_events(task_id,state,created_at) VALUES(NEW.id,NEW.state,NEW.updated_at); DELETE FROM sf_worker_task_events WHERE id NOT IN (SELECT id FROM sf_worker_task_events ORDER BY id DESC LIMIT 1000); END");

}

function sfda_root(): string {
    $root=dirname(__DIR__).'/data/desktop-worker-jobs';
    if(!is_dir($root)&&!mkdir($root,02770,true)&&!is_dir($root))throw new RuntimeException('No se pudo crear el área privada del worker.');
    if(!is_file($root.'/.htaccess'))file_put_contents($root.'/.htaccess',"Require all denied\n");
    return $root;
}

function sfda_enabled(): bool {
    $cfg=json_decode((string)file_get_contents(dirname(__DIR__).'/data/config.json'),true);
    return !empty($cfg['desktop_workers_enabled']);
}

function sfda_active_request(PDO $db,string $id): array {
    $q=$db->prepare("SELECT r.*,u.role,u.is_active,u.can_manage_albums,u.password_hash FROM sf_worker_requests r JOIN users u ON u.id=r.user_id WHERE r.id=? AND r.status='approved'");
    $q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
    if(!$row||!sf_worker_is_manager($row))throw new RuntimeException('Equipo retirado o cuenta sin permisos de gestión.',403);
    return $row;
}

function sfda_pair_code(PDO $db,array $actor,string $id): string {
    if(!sf_worker_is_manager($actor)||!sfda_enabled())throw new RuntimeException('Necesitas una cuenta gestora activa y el servicio de workers habilitado.');
    $row=sfda_active_request($db,$id);
    if($row['user_id']!==$actor['id'])throw new RuntimeException('Solo el gestor propietario puede vincular este equipo.',403);
    $code=bin2hex(random_bytes(16));
    $db->prepare('INSERT INTO sf_desktop_agents(request_id,pair_hash,pair_expires) VALUES(?,?,?) ON CONFLICT(request_id) DO UPDATE SET pair_hash=excluded.pair_hash,pair_expires=excluded.pair_expires')->execute([$id,hash('sha256',$code),time()+300]);
    sf_worker_event($db,$id,(string)$actor['id'],'pairing_requested');
    return $code;
}

function sfda_pair(PDO $db,string $id,string $code): array {
    if(!sfda_enabled())throw new RuntimeException('Los workers están deshabilitados.',403);
    if(!preg_match('/^[a-f0-9]{32}$/D',$id)||!preg_match('/^[a-f0-9]{32}$/D',$code))throw new RuntimeException('Código inválido o caducado.',403);
    $db->exec('BEGIN IMMEDIATE');
    try {
        $row=sfda_active_request($db,$id);
        $q=$db->prepare('SELECT * FROM sf_desktop_agents WHERE request_id=?');$q->execute([$id]);$a=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if(!$a||($a['pair_expires']??0)<time()||!hash_equals((string)$a['pair_hash'],hash('sha256',$code)))throw new RuntimeException('Código inválido o caducado.',403);
        $token=bin2hex(random_bytes(32));
        $db->prepare('UPDATE sf_desktop_agents SET token_hash=?,credential_stamp=?,pair_hash=NULL,pair_expires=NULL,last_seen=NULL WHERE request_id=?')->execute([hash('sha256',$token),hash('sha256',$row['password_hash']),$id]);
        sf_worker_event($db,$id,$row['user_id'],'paired');$db->exec('COMMIT');
        return ['request_id'=>$id,'token'=>$token,'device_name'=>$row['device_name']];
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}

function sfda_auth(PDO $db,string $id,string $token): array {
    if(!sfda_enabled())throw new RuntimeException('Los workers están deshabilitados.',403);
    if(!preg_match('/^[a-f0-9]{32}$/D',$id)||!preg_match('/^[a-f0-9]{64}$/D',$token))throw new RuntimeException('Credencial no válida.',401);
    $row=sfda_active_request($db,$id);
    $q=$db->prepare('SELECT * FROM sf_desktop_agents WHERE request_id=?');$q->execute([$id]);$agent=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
    if(!$agent||!hash_equals((string)$agent['token_hash'],hash('sha256',$token))||!hash_equals((string)$agent['credential_stamp'],hash('sha256',$row['password_hash'])))throw new RuntimeException('Credencial revocada. Vincula de nuevo el equipo.',401);
    return $row;
}

function sfda_task(PDO $db,string $request,string $id,string $lease): array {
    if(!preg_match('/^[a-f0-9]{32}$/D',$id)||!preg_match('/^[a-f0-9]{64}$/D',$lease))throw new RuntimeException('Reserva inválida.',409);
    $q=$db->prepare('SELECT * FROM sf_desktop_tasks WHERE id=? AND request_id=?');$q->execute([$id,$request]);$task=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
    if(!$task||!in_array($task['state'],['leased','uploading','validating'],true)||($task['lease_until']??0)<time()||!hash_equals((string)$task['lease_hash'],hash('sha256',$lease)))throw new RuntimeException('La reserva terminó; no publiques este resultado.',409);
    return $task;
}

function sfda_claim(PDO $db,string $request,array $caps,string $version): array {
    $rates=array_map('intval',(array)($caps['rates']??[]));
    if(!in_array(64,$rates,true)||!in_array(256,$rates,true)||($caps['codec']??'')!=='aac-lc')throw new RuntimeException('El agente debe admitir AAC-LC, incluidos 64 y 256 kbps.');
    sfda_cleanup($db);
    $db->exec('BEGIN IMMEDIATE');
    try {
        $now=time();
        $db->prepare("UPDATE sf_desktop_agents SET last_seen=?,version=?,capabilities=? WHERE request_id=?")->execute([$now,substr($version,0,40),json_encode($caps),$request]);
        $db->prepare("UPDATE sf_desktop_tasks SET state='failed',error='Se interrumpió el agente o venció su reserva',updated_at=? WHERE request_id=? AND state IN ('leased','uploading','validating') AND lease_until<?")->execute([$now,$request,$now]);
        $q=$db->prepare("SELECT id FROM sf_desktop_tasks WHERE request_id=? AND state IN ('leased','uploading','validating') LIMIT 1");$q->execute([$request]);$busy=$q->fetchColumn();$q->closeCursor();
        if($busy){$db->exec('COMMIT');return ['task'=>null,'busy'=>true];}
        $q=$db->prepare("SELECT * FROM sf_desktop_tasks WHERE request_id=? AND state='queued' AND created_at>=? ORDER BY created_at LIMIT 1");$q->execute([$request,$now-120]);$t=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if(!$t){$db->exec('COMMIT');return ['task'=>null];}
        if(array_diff(json_decode($t['rates'],true),$rates))throw new RuntimeException('El equipo no admite toda la escalera solicitada.');
        $lease=bin2hex(random_bytes(32));
        $db->prepare("UPDATE sf_desktop_tasks SET state='leased',lease_hash=?,lease_until=?,updated_at=? WHERE id=? AND state='queued'")->execute([hash('sha256',$lease),$now+90,$now,$t['id']]);$db->exec('COMMIT');
        return ['task'=>['id'=>$t['id'],'kind'=>$t['kind'],'lease'=>$lease,'rates'=>json_decode($t['rates'],true),'duration'=>$t['duration'],'sample_rate'=>$t['sample_rate'],'segment_seconds'=>$t['segment_seconds'],'source_bytes'=>$t['source_bytes'],'source_sha256'=>$t['source_hash']]];
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}

function sfda_enqueue(PDO $db,string $request,string $source,array $rates,float $duration,int $sampleRate,int $segment,string $kind='conversion',string $parent=''): string {
    sfda_active_request($db,$request);
    if($duration<=0||$duration>1800||!in_array($sampleRate,[44100,48000],true)||$segment<2||$segment>12||!in_array(64,$rates,true)||array_diff($rates,[32,48,64,96,128,160,192,256]))throw new RuntimeException('La fuente o escalera está fuera de los límites del agente.');
    $id=bin2hex(random_bytes(16));$dir=sfda_root().'/'.$id;mkdir($dir,02770);
    $size=filesize($source);if($size<1||$size>500*1048576)throw new RuntimeException('La fuente supera el límite del worker.');
    $file=$dir.'/source.audio';if(!copy($source,$file))throw new RuntimeException('No se pudo conservar la fuente del worker.');
    $db->prepare("INSERT INTO sf_desktop_tasks(id,request_id,kind,parent_id,source,source_hash,source_bytes,rates,duration,sample_rate,segment_seconds,state,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,'queued',?,?)")->execute([$id,$request,$kind,$parent,$file,hash_file('sha256',$file),$size,json_encode($rates),$duration,$sampleRate,$segment,time(),time()]);
    return $id;
}

function sfda_manifest(array $task,array $files): void {
    $rates=json_decode($task['rates'],true);$bytes=0;$expected=[];$segments=0;
    if(count($files)<2||count($files)>10000)throw new RuntimeException('Cantidad de archivos fuera de límites.');
    foreach($files as $name=>$info){
        if($name==='master.m3u8')continue;
        if(!preg_match('~^(\d+)k/(prog\.m3u8|seg_\d{3,6}\.ts)$~D',$name,$m)||!in_array((int)$m[1],$rates,true))throw new RuntimeException('Ruta de resultado inválida.');
        if(!is_array($info)||!preg_match('/^[a-f0-9]{64}$/D',(string)($info['sha256']??''))||!is_int($info['bytes']??null)||$info['bytes']<1||$info['bytes']>2*1048576)throw new RuntimeException('Archivo fuera de límites.');
        $bytes+=$info['bytes'];$expected[$name]=true;if(str_ends_with($name,'.ts'))$segments++;
    }
    foreach($rates as $rate)if(!isset($expected[$rate.'k/prog.m3u8']))throw new RuntimeException('Falta una calidad.');
    if(!$segments||$bytes>512*1048576)throw new RuntimeException('Resultado fuera de límites.');
}

function sfda_probe(string $path,bool $packets=false): array {
    $cmd=['timeout','60','ffprobe','-v','error','-show_streams','-show_format'];
    if($packets)$cmd=array_merge($cmd,['-show_packets','-show_entries','stream=codec_name,profile,sample_rate,channels,codec_type:format=duration:packet=pts_time,duration_time']);
    $cmd=array_merge($cmd,['-of','json',$path]);$tmp=tempnam(sys_get_temp_dir(),'sfda-');
    try{$p=proc_open($cmd,[0=>['file','/dev/null','r'],1=>['file',$tmp,'w'],2=>['file','/dev/null','w']],$pipes);if(!is_resource($p)||proc_close($p)!==0)throw new RuntimeException('El paquete recibido no pasó ffprobe.');$result=json_decode((string)file_get_contents($tmp),true,512,JSON_THROW_ON_ERROR);return $result;}finally{@unlink($tmp);}
}

/** Count actual AAC frames against PES clocks, avoiding demuxer-estimated PTS. */
function sfda_ts_clock(array $paths,int $sampleRate): array {
    $frames=0;$first=null;$anchors=0;$segmentFrames=[];
    $consume=static function(string $pes)use(&$frames,&$first,&$anchors,$sampleRate):void{
        if(strlen($pes)<14||substr($pes,0,3)!=="\0\0\1"||!(ord($pes[7])&128))throw new RuntimeException('Cabecera PES incompleta.');
        $length=(ord($pes[4])<<8)|ord($pes[5]);
        if(!$length||strlen($pes)<$length+6)throw new RuntimeException('PES truncado.');
        $pes=substr($pes,0,$length+6);$start=9+ord($pes[8]);
        if($start<14||$start>strlen($pes))throw new RuntimeException('Cabecera PES inválida.');
        $pts=(((ord($pes[9])>>1)&7)<<30)|(ord($pes[10])<<22)|((ord($pes[11])>>1)<<15)|(ord($pes[12])<<7)|(ord($pes[13])>>1);
        $clock=$pts/90000;
        if($first===null){if($clock<0||$clock>10)throw new RuntimeException('Reloj de inicio inválido.');$first=$clock;}
        if(abs($clock-($first+$frames*1024/$sampleRate))>2/90000)throw new RuntimeException('Discontinuidad real del reloj de audio.');
        $anchors++;$end=strlen($pes);
        for($p=$start;$p<$end;){
            if($p+7>$end||ord($pes[$p])!==255||(ord($pes[$p+1])&0xf6)!==0xf0)throw new RuntimeException('Trama AAC inválida.');
            $b2=ord($pes[$p+2]);$b3=ord($pes[$p+3]);
            if(($b2>>6)!==1||(($b2>>2)&15)!==($sampleRate===48000?3:4)||((($b2&1)<<2)|($b3>>6))!==2||(ord($pes[$p+6])&3)!==0)throw new RuntimeException('La trama no es AAC-LC estéreo con la frecuencia solicitada.');
            $size=(($b3&3)<<11)|(ord($pes[$p+4])<<3)|(ord($pes[$p+5])>>5);
            if($size<7||$p+$size>$end)throw new RuntimeException('Trama AAC truncada.');
            $p+=$size;$frames++;
        }
    };
    foreach($paths as $path){
        $data=(string)file_get_contents($path);if(strlen($data)%188)throw new RuntimeException('Segmento MPEG-TS incompleto.');
        $pending=[];$before=$frames;
        for($p=0,$end=strlen($data);$p<$end;$p+=188){
            $ts=substr($data,$p,188);if(ord($ts[0])!==0x47)throw new RuntimeException('Sincronización MPEG-TS inválida.');
            $pid=((ord($ts[1])&31)<<8)|ord($ts[2]);$af=(ord($ts[3])>>4)&3;$pos=4;
            if(!in_array($af,[1,3],true))continue;if($af===3)$pos+=1+ord($ts[4]);if($pos>188)throw new RuntimeException('Adaptación MPEG-TS inválida.');
            $payload=substr($ts,$pos);
            if(ord($ts[1])&64){
                if(isset($pending[$pid])){$consume($pending[$pid]);unset($pending[$pid]);}
                if(strlen($payload)>=4&&substr($payload,0,3)==="\0\0\1"&&ord($payload[3])>=0xc0&&ord($payload[3])<=0xdf)$pending[$pid]=$payload;
            }elseif(isset($pending[$pid])){$pending[$pid].=$payload;if(strlen($pending[$pid])>66000)throw new RuntimeException('PES demasiado grande.');}
        }
        foreach($pending as $pes)$consume($pes);
        $segmentFrames[]=$frames-$before;
    }
    if(!$frames||$first===null)throw new RuntimeException('No hay tramas AAC verificables.');
    return ['frames'=>$frames,'first_pts'=>$first,'anchors'=>$anchors,'segment_frames'=>$segmentFrames];
}

function sfda_validate(array $task): array {
    $dir=sfda_root().'/'.$task['id'].'/output';$files=json_decode($task['manifest'],true);sfda_manifest($task,$files);$reference=null;$results=[];
    foreach($files as $name=>$meta){if($name==='master.m3u8')continue;$p=$dir.'/'.$name;if(!is_file($p)||is_link($p)||filesize($p)!==$meta['bytes']||!hash_equals($meta['sha256'],hash_file('sha256',$p)))throw new RuntimeException('Falta un archivo o no coincide su integridad.');}
    foreach(json_decode($task['rates'],true) as $rate){
        $folder=$dir.'/'.$rate.'k';$playlist=$folder.'/prog.m3u8';$text=(string)file_get_contents($playlist);$lines=preg_split('/\r?\n/',$text);$durations=[];$names=[];
        foreach($lines as $line){if($line==='')continue;if(str_starts_with($line,'#EXTINF:')){if(!preg_match('/^#EXTINF:([0-9.]+),$/D',$line,$m))throw new RuntimeException('Duración de segmento inválida.');$d=(float)$m[1];if(!is_finite($d)||$d<=0||$d>$task['segment_seconds']+1)throw new RuntimeException('Segmento fuera de límites.');$durations[]=$d;}
            elseif($line[0]!=='#'){if(!preg_match('/^seg_\d{3,6}\.ts$/D',$line)||!isset($files[$rate.'k/'.$line]))throw new RuntimeException('Referencia externa o segmento ausente.');$names[]=$line;}
            elseif(!preg_match('/^#(?:EXTM3U|EXT-X-(?:VERSION:\d+|TARGETDURATION:\d+|MEDIA-SEQUENCE:0|PLAYLIST-TYPE:VOD|INDEPENDENT-SEGMENTS|ENDLIST))$/D',$line))throw new RuntimeException('Etiqueta HLS no permitida.');}
        if(!str_contains($text,'#EXT-X-ENDLIST')||count($durations)!==count($names)||count($names)!==count(array_unique($names))||!count($names)||abs(array_sum($durations)-(float)$task['duration'])>0.35)throw new RuntimeException('Paquete HLS incompleto o duración distinta del origen.');
        $actual=[];foreach(array_keys($files) as $f)if(str_starts_with($f,$rate.'k/')&&str_ends_with($f,'.ts'))$actual[]=basename($f);if(array_diff($actual,$names))throw new RuntimeException('Hay segmentos sin referenciar.');
        $probe=sfda_probe($playlist);$streams=$probe['streams']??[];$s=$streams[0]??[];
        if(count($streams)!==1||($s['codec_name']??'')!=='aac'||($s['profile']??'')!=='LC'||(int)($s['sample_rate']??0)!==(int)$task['sample_rate']||(int)($s['channels']??0)!==2)throw new RuntimeException('Códec, canales o frecuencia distintos de los solicitados.');
        $clock=sfda_ts_clock(array_map(static fn($n)=>$folder.'/'.$n,$names),(int)$task['sample_rate']);
        $frame=1024/(int)$task['sample_rate'];if(abs($clock['frames']*$frame-(float)$task['duration'])>0.35)throw new RuntimeException('Duración real distinta del origen.');
        // The final AAC packet may contain padding beyond its declared duration.
        foreach($durations as $i=>$d)if(abs($clock['segment_frames'][$i]*$frame-$d)>(($i===count($durations)-1?$frame:0)+2/(int)$task['sample_rate']))throw new RuntimeException('La duración declarada del segmento no coincide con su audio.');
        if($reference!==null){if($clock['frames']!==$reference['frames']||$clock['segment_frames']!==$reference['segment_frames']||abs($clock['first_pts']-$reference['first_pts'])>1/(int)$task['sample_rate'])throw new RuntimeException('Desfase o segmentación distinta entre calidades.');foreach($durations as $i=>$d)if(abs($d-$reference['durations'][$i])>2/(int)$task['sample_rate'])throw new RuntimeException('Manifiestos desalineados entre calidades.');}
        else $reference=$clock+['durations'=>$durations];
        $results[]=['kbps'=>$rate,'codec'=>'aac-lc','frames'=>$clock['frames'],'anchors'=>$clock['anchors'],'segments'=>count($names),'first_pts'=>$clock['first_pts']];
        $decoder=proc_open(['timeout','120','ffmpeg','-nostdin','-v','error','-xerror','-i',$playlist,'-f','null','-'],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
        if(!is_resource($decoder)||proc_close($decoder)!==0)throw new RuntimeException('El audio recibido no se decodifica correctamente.');
    }
    return ['validated'=>true,'qualities'=>$results];
}

/** Optional hand-off inside the existing conversion pipeline; never publishes a track. */
function sfda_try_convert(string $source,string $base,array $renditions,array $probe,array $cfg,array $ctx): bool {
    if(empty($cfg['desktop_workers_enabled'])||empty($ctx['worker_user_id'])||empty($ctx['worker_parent_id'])||(float)$probe['duration']>1800||filesize($source)>500*1048576)return false;
    $db=lab_auth_pdo();sfda_schema($db);
    $q=$db->prepare("SELECT r.id FROM sf_worker_requests r JOIN sf_desktop_agents a ON a.request_id=r.id JOIN users u ON u.id=r.user_id WHERE r.user_id=? AND r.status='approved' AND u.is_active=1 AND (u.role='admin' OR u.can_manage_albums=1) AND a.last_seen>? AND a.token_hash IS NOT NULL AND NOT EXISTS(SELECT 1 FROM sf_desktop_tasks t WHERE t.request_id=r.id AND t.state IN ('queued','leased','uploading','validating')) ORDER BY a.last_seen DESC LIMIT 1");
    $q->execute([$ctx['worker_user_id'],time()-30]);$request=$q->fetchColumn();$q->closeCursor();if(!$request)return false;
    try{$id=sfda_enqueue($db,$request,$source,array_column($renditions,'kbps'),(float)$probe['duration'],in_array((int)$probe['sample_rate'],[44100,48000],true)?(int)$probe['sample_rate']:44100,(int)$cfg['segment_seconds'],'conversion',(string)$ctx['worker_parent_id']);}catch(Throwable $e){error_log('SparrowFy worker preparation fallback: '.$e->getMessage());return false;}
    $start=time();
    try{
        for(;;){
            $q=$db->prepare('SELECT * FROM sf_desktop_tasks WHERE id=?');$q->execute([$id]);$t=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
            if($t['state']==='ready'){
                sfda_active_request($db,$request);
                if(!sfda_enabled())throw new RuntimeException('Workers desactivados antes de publicar.');
                foreach(json_decode($t['manifest'],true) as $name=>$meta){if($name==='master.m3u8')continue;$from=sfda_root().'/'.$id.'/output/'.$name;if(!hash_equals($meta['sha256'],hash_file('sha256',$from)))throw new RuntimeException('El resultado cambió antes de publicarse.');if(!copy($from,$base.'/'.$name))throw new RuntimeException('No se pudo incorporar el resultado del worker.');}
                $db->prepare("UPDATE sf_desktop_tasks SET state='consumed',updated_at=? WHERE id=? AND state='ready'")->execute([time(),$id]);
                sfda_remove_files($id);
                return true;
            }
            if($t['state']==='failed'||!sfda_enabled()||time()-$start>1800||($t['state']==='queued'&&time()-$start>30)||($t['lease_until']&&$t['lease_until']<time()))throw new RuntimeException($t['error']?:'La PC no respondió dentro del plazo.');
            $db->prepare("UPDATE sf_processing_jobs SET stage=?,updated_at=? WHERE id=? AND state='running'")->execute([(['queued'=>'Esperando respuesta de la PC','leased'=>'Convirtiendo en la PC','uploading'=>'Recibiendo los paquetes de la PC','validating'=>'Validando el audio recibido de la PC'][$t['state']]??'Procesando con la PC'),gmdate('c'),$ctx['worker_parent_id']]);
            sleep(2);
        }
    }catch(Throwable $e){
        $db->prepare("UPDATE sf_desktop_tasks SET state='failed',error=?,updated_at=? WHERE id=? AND state!='consumed'")->execute([substr($e->getMessage(),0,300),time(),$id]);
        foreach($renditions as $r)foreach(glob($base.'/'.$r['name'].'/*')?:[] as $f)if(is_file($f))unlink($f);
        error_log('SparrowFy desktop worker fallback: '.$e->getMessage());
        $db->prepare("UPDATE sf_processing_jobs SET stage=?,updated_at=? WHERE id=? AND state='running'")->execute(['PC interrumpida; convirtiendo en servidor',gmdate('c'),$ctx['worker_parent_id']]);
        return false;
    }
}

function sfda_remove_files(string $id): void {
    if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new RuntimeException('Temporal inválido.');
    $root=realpath(sfda_root());$dir=$root.'/'.$id;
    if(is_link($dir)||!is_dir($dir)||dirname(realpath($dir))!==$root)return;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $item){if($item->isLink()||$item->isFile())@unlink($item->getPathname());elseif($item->isDir())@rmdir($item->getPathname());}
    @rmdir($dir);
}

function sfda_cleanup(PDO $db): void {
    if(random_int(1,20)!==1)return;
    $db->prepare("UPDATE sf_desktop_tasks SET state='failed',error='No se reclamó la tarea a tiempo',updated_at=? WHERE state='queued' AND created_at<?")->execute([time(),time()-120]);
    $q=$db->prepare("SELECT id FROM sf_desktop_tasks WHERE state IN ('failed','ready','consumed') AND updated_at<? LIMIT 20");$q->execute([time()-86400]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);$q->closeCursor();foreach($ids as $id)sfda_remove_files($id);
}

/** Only bounded host load data; no paths, user agents or credentials. */
function sfda_save_metrics(string $request,array $input):void {
 if(!preg_match('/^[a-f0-9]{32}$/D',$request)||!$input)return;
 $safe=['updated_at'=>time()];
 foreach(['cpu_percent'=>100,'ram_total_mib'=>1048576,'ram_used_mib'=>1048576] as $key=>$limit){$v=$input[$key]??null;if($v===null&&$key==='cpu_percent'){$safe[$key]=null;continue;}if(!is_numeric($v)||!is_finite((float)$v)||(float)$v<0||(float)$v>$limit)return;$safe[$key]=round((float)$v,2);}
 if($safe['ram_used_mib']>$safe['ram_total_mib'])return;
 $path=sfda_root().'/node-'.$request.'.json';$tmp=$path.'.'.bin2hex(random_bytes(4)).'.tmp';
 if(file_put_contents($tmp,json_encode($safe),LOCK_EX)!==false){chmod($tmp,0660);if(!rename($tmp,$path))@unlink($tmp);}
}
