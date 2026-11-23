<?php

declare(strict_types=1);

require_once __DIR__.'/charts_genres.php';
/** Charts persistence and configuration. External parsers do not own this module. */

function sfc_root():string {

 $dir=LAB_DATA.'/music-charts';if(!is_dir($dir)&&!mkdir($dir,02770,true)&&!is_dir($dir))throw new RuntimeException('No se pudo preparar Music Charts');

 if(!is_file($dir.'/.htaccess'))file_put_contents($dir.'/.htaccess',"Require all denied\n");return $dir;

}

function sfc_json(string $path,array $data):void {$temp=$path.'.'.bin2hex(random_bytes(5)).'.tmp';$text=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);if(file_put_contents($temp,$text,LOCK_EX)!==strlen($text)||!rename($temp,$path))throw new RuntimeException('No se pudo guardar Music Charts');chmod($path,0660);}

function sfc_defaults():array {return ['enabled'=>true,'auto_download'=>true,'daily_capture'=>true,'markets'=>['mx','us'],'global_enabled'=>false,'christian_mx'=>true,'christian_us'=>true,'spotify_mx'=>false,'spotify_us'=>false,'spotify_global'=>false,'tracks_per_chart'=>20,'max_charts'=>4,'weekday'=>1,'time'=>'18:00','timezone'=>'America/Mexico_City','extra_charts'=>[],'genre_filter_enabled'=>false,'allowed_genres'=>array_keys(sfc_genres()),'owner_id'=>LAB_PROTECTED_ADMIN_ID];}

function sfc_settings():array {$path=sfc_root().'/settings.json';$saved=is_file($path)?json_decode((string)file_get_contents($path),true):[];return array_replace(sfc_defaults(),is_array($saved)?$saved:[]);}

function sfc_save_settings(array $input):array {

 $settings=sfc_settings();foreach(['enabled','auto_download','daily_capture','global_enabled','christian_mx','christian_us','spotify_mx','spotify_us','spotify_global'] as $key)$settings[$key]=!empty($input[$key]);

 if(($input['genre_options_version']??'')==='1'){
 $settings['genre_filter_enabled']=!empty($input['genre_filter_enabled']);
 $extra=array_keys(sfc_extra_definitions());$settings['extra_charts']=array_values(array_intersect($extra,(array)($input['extra_charts']??[])));
 $settings['allowed_genres']=array_values(array_intersect(array_keys(sfc_genres()),(array)($input['allowed_genres']??[])));if($settings['genre_filter_enabled']&&!$settings['allowed_genres'])throw new InvalidArgumentException('Selecciona al menos un género');
 }
 $settings['markets']=array_values(array_intersect(['mx','us'],(array)($input['markets']??[])));

 foreach(['tracks_per_chart'=>[1,100],'max_charts'=>[1,20],'weekday'=>[1,7]] as $key=>$range){$value=filter_var($input[$key]??null,FILTER_VALIDATE_INT);if($value===false||$value<$range[0]||$value>$range[1])throw new InvalidArgumentException('Revisa los límites y el día de actualización');$settings[$key]=$value;}

 $time=(string)($input['time']??'');if(!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$time))throw new InvalidArgumentException('Hora de actualización inválida');$settings['time']=$time;

 if(count($settings['markets'])+(int)$settings['global_enabled']+(int)$settings['christian_mx']+(int)$settings['christian_us']+(int)$settings['spotify_mx']+(int)$settings['spotify_us']+(int)$settings['spotify_global']+count($settings['extra_charts'])>$settings['max_charts'])throw new InvalidArgumentException('Aumenta el máximo de rankings para incluir todas las listas seleccionadas');

 if(!$settings['markets']&&!$settings['global_enabled']&&!$settings['christian_mx']&&!$settings['christian_us']&&!$settings['spotify_mx']&&!$settings['spotify_us']&&!$settings['spotify_global']&&!$settings['extra_charts']&&$settings['enabled'])throw new InvalidArgumentException('Activa al menos un ranking');

 $settings['owner_id']=LAB_PROTECTED_ADMIN_ID;sfc_json(sfc_root().'/settings.json',$settings);return $settings;

}

function sfc_schema(PDO $db):void {

 $db->exec('CREATE TABLE IF NOT EXISTS sf_music_charts(chart_key TEXT PRIMARY KEY,provider TEXT NOT NULL,market TEXT NOT NULL,title TEXT NOT NULL,album_id TEXT,source_url TEXT,source_updated TEXT,last_fetch TEXT,last_week TEXT,error TEXT,enabled INTEGER NOT NULL DEFAULT 1)');

 $db->exec('CREATE TABLE IF NOT EXISTS sf_chart_snapshots(chart_key TEXT NOT NULL,day TEXT NOT NULL,captured_at TEXT NOT NULL,data TEXT NOT NULL,PRIMARY KEY(chart_key,day))');

 $db->exec('CREATE TABLE IF NOT EXISTS sf_chart_entries(chart_key TEXT NOT NULL,rank INTEGER NOT NULL,identity TEXT NOT NULL,data TEXT NOT NULL,PRIMARY KEY(chart_key,rank))');

 $db->exec('CREATE INDEX IF NOT EXISTS sf_chart_entry_identity ON sf_chart_entries(identity)');

 $db->exec('CREATE TABLE IF NOT EXISTS sf_chart_audio(identity TEXT PRIMARY KEY,data TEXT NOT NULL,state TEXT NOT NULL DEFAULT "new",track_id TEXT,source_album TEXT,job_id TEXT,service TEXT,url TEXT,attempts TEXT NOT NULL DEFAULT "[]",error TEXT,updated_at TEXT NOT NULL)');

 $columns=$db->query('PRAGMA table_info(sf_chart_audio)')->fetchAll(PDO::FETCH_COLUMN,1);if(!in_array('mornye_job_id',$columns,true))$db->exec('ALTER TABLE sf_chart_audio ADD COLUMN mornye_job_id TEXT');
 $db->exec("UPDATE sf_chart_audio SET mornye_job_id=job_id WHERE service='mornye' AND mornye_job_id IS NULL AND job_id IS NOT NULL");
 $db->exec('CREATE TABLE IF NOT EXISTS sf_chart_reports(week TEXT PRIMARY KEY,created_at TEXT NOT NULL,data TEXT NOT NULL)');

}

function sfc_definitions():array {return sfc_extra_definitions()+['apple_mx'=>['provider'=>'apple','market'=>'mx','title'=>'Top México','label'=>'Apple Music · México'],'apple_us'=>['provider'=>'apple','market'=>'us','title'=>'Top Estados Unidos','label'=>'Apple Music · Estados Unidos'],'christian_mx'=>['provider'=>'itunes_christian','market'=>'mx','title'=>'Cristiana · México','label'=>'iTunes · Christian & Gospel · México'],'christian_us'=>['provider'=>'billboard_christian','market'=>'us','title'=>'Cristiana · Estados Unidos','label'=>'Billboard · Hot Christian Songs · EE. UU.'],'billboard_global'=>['provider'=>'billboard_global','market'=>'global','title'=>'Top Global','label'=>'Billboard · Global 200'],'spotify_mx'=>['provider'=>'spotify','market'=>'mx','title'=>'Spotify · Top semanal · México','label'=>'Spotify · México · Top semanal'],'spotify_us'=>['provider'=>'spotify','market'=>'us','title'=>'Spotify · Top semanal · Estados Unidos','label'=>'Spotify · Estados Unidos · Top semanal'],'spotify_global'=>['provider'=>'spotify','market'=>'global','title'=>'Spotify · Top semanal · Global','label'=>'Spotify · Global · Top semanal']];}

function sfc_source_url(string $provider,string $market):string {
 if($provider==='spotify'){$aliases=['mx'=>'regional-mx-weekly','us'=>'regional-us-weekly','global'=>'regional-global-weekly'];if(!isset($aliases[$market]))throw new InvalidArgumentException('Ranking de Spotify no compatible');return 'https://charts.spotify.com/charts/view/'.$aliases[$market].'/latest';}
 return sf_external_chart_url($provider,$market);
}

function sfc_active(array $settings):array {

 $keys=[];foreach($settings['markets'] as $market)$keys[]='apple_'.$market;if($settings['christian_mx'])$keys[]='christian_mx';if($settings['christian_us'])$keys[]='christian_us';if($settings['global_enabled'])$keys[]='billboard_global';foreach(['mx','us','global'] as $market)if(!empty($settings['spotify_'.$market]))$keys[]='spotify_'.$market;return array_slice(array_merge($keys,$settings['extra_charts']??[]),0,$settings['max_charts']);

}

function sfc_week(DateTimeImmutable $now,array $settings):string {

 $anchor=$now->setTime((int)substr($settings['time'],0,2),(int)substr($settings['time'],3,2));$days=((int)$anchor->format('N')-$settings['weekday']+7)%7;$anchor=$anchor->modify('-'.$days.' days');if($anchor>$now)$anchor=$anchor->modify('-7 days');return $anchor->format('Y-m-d');

}

function sfc_album(PDO $db,string $key,array $definition):string {

 $q=$db->prepare('SELECT album_id FROM sf_music_charts WHERE chart_key=?');$q->execute([$key]);$id=$q->fetchColumn();$q->closeCursor();if($id&&lab_load_album($id))return $id;

 $id=lab_id();lab_save_album(['id'=>$id,'title'=>$definition['title'],'artist'=>$definition['label'],'created_at'=>gmdate('c'),'music_chart'=>$key,'tracks'=>[]]);

 $db->prepare('UPDATE sf_music_charts SET album_id=? WHERE chart_key=?')->execute([$id,$key]);return $id;

}

function sfc_live_track(PDO $db,string $id):?array {

 $q=$db->prepare('SELECT album_id FROM tracks WHERE id=?');$q->execute([$id]);$aid=$q->fetchColumn();$q->closeCursor();if(!$aid)return null;$album=lab_load_album($aid);

 foreach($album['tracks']??[] as $track)if(($track['id']??'')===$id&&is_file(LAB_ROOT.'/'.($track['hls']??'')))return ['album_id'=>$aid,'track'=>$track];return null;

}

function sfc_entry_rows(PDO $db,string $key):array {

 $q=$db->prepare('SELECT e.rank,e.data,a.state,a.track_id,a.source_album,a.job_id,a.service,a.error,a.attempts,a.mornye_job_id FROM sf_chart_entries e LEFT JOIN sf_chart_audio a ON a.identity=e.identity WHERE e.chart_key=? ORDER BY e.rank');$q->execute([$key]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();foreach($rows as &$row){$row['song']=json_decode($row['data'],true)?:[];if(!empty($row['song']['genre_review']))$row['state']='genre_review';}unset($row);return $rows;

}

function sfc_collect_report(PDO $db,string $week):array {

 $sections=[];$defs=sfc_definitions();

 foreach($db->query('SELECT * FROM sf_music_charts WHERE enabled=1 ORDER BY chart_key')->fetchAll(PDO::FETCH_ASSOC) as $chart){

  $q=$db->prepare('SELECT data,captured_at FROM sf_chart_snapshots WHERE chart_key=? ORDER BY day DESC LIMIT 1');$q->execute([$chart['chart_key']]);$latest=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();$snapshot=$latest?json_decode($latest['data'],true):null;

  $q=$db->prepare('SELECT data,captured_at FROM sf_chart_snapshots WHERE chart_key=? AND day<? ORDER BY day DESC LIMIT 1');$q->execute([$chart['chart_key'],$week]);$old=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();$before=$old?json_decode($old['data'],true):null;$oldRanks=[];foreach($before['items']??[] as $t)$oldRanks[sf_chart_identity($t)]=$t['rank'];

  $rows=[];$artists=[];$genres=[];foreach(sfc_select_items($snapshot??[],sfc_settings()) as $t){$identity=sf_chart_identity($t);$official=($snapshot['period_kind']??'')==='weekly';$previous=$official?($t['official_previous_rank']??null):($oldRanks[$identity]??null);$rows[]=$t+['previous_rank'=>$previous,'change'=>$previous!==null?$previous-$t['rank']:null,'new_entry'=>!$official&&$before!==null&&$previous===null];$artists[$t['artist']]=($artists[$t['artist']]??0)+1;foreach($t['genres'] as $g)$genres[$g]=($genres[$g]??0)+1;}

  arsort($artists);arsort($genres);$sections[]=['key'=>$chart['chart_key'],'title'=>$defs[$chart['chart_key']]['label'],'source_url'=>$chart['source_url'],'source_updated'=>$snapshot['source_updated']??null,'captured_at'=>$latest['captured_at']??null,'compared_at'=>$old['captured_at']??null,'period_kind'=>$snapshot['period_kind']??null,'issue_date'=>$snapshot['issue_date']??null,'period_start'=>$snapshot['period_start']??null,'period_end'=>$snapshot['period_end']??null,'error'=>$chart['error'],'tracks'=>$rows,'artist_presence'=>array_slice($artists,0,5,true),'genres'=>array_slice($genres,0,5,true)];

 }

 $report=['week'=>$week,'created_at'=>gmdate('c'),'sections'=>$sections,'notes'=>['Cuando se aplican filtros, la selección puede tener huecos: conserva la posición oficial de cada canción. Las pistas sin género verificable quedan pendientes y no se descargan automáticamente.','Las posiciones proceden del proveedor. Las variaciones comparan capturas de Sparrow-Fy y no son estadísticas semanales oficiales.','Sin captura anterior no se inventan variaciones ni nuevas entradas.','La presencia de artistas y géneros se calcula en las canciones seleccionadas; no es un ranking oficial de artistas.','Las fuentes no se sustituyen con datos inventados si no hay acceso validado. Global permanece apagado salvo activación del administrador.']];

 $db->prepare('INSERT INTO sf_chart_reports VALUES(?,?,?) ON CONFLICT(week) DO UPDATE SET created_at=excluded.created_at,data=excluded.data')->execute([$week,$report['created_at'],json_encode($report,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);return $report;

}


function sfc_extra_definitions():array {
 $out=['billboard_hot100'=>['provider'=>'billboard_hot100','market'=>'us','title'=>'Billboard Hot 100','label'=>'Billboard · Hot 100 · EE. UU.'],'billboard_latin_pop'=>['provider'=>'billboard_latin_pop','market'=>'us','title'=>'Pop latino · Billboard','label'=>'Billboard · Hot Latin Pop Songs · EE. UU.'],'billboard_rock'=>['provider'=>'billboard_rock','market'=>'us','title'=>'Rock y alternativa · Billboard','label'=>'Billboard · Hot Rock & Alternative Songs · EE. UU.']];
 foreach(['global'=>'General','pop'=>'Pop','rock'=>'Rock','electronic'=>'Electrónica','dance'=>'Dance','alternative'=>'Alternativa','jazz'=>'Jazz','classical'=>'Clásica'] as $key=>$label)$out['deezer_'.$key]=['provider'=>'deezer','market'=>$key,'title'=>'Deezer · '.$label,'label'=>'Deezer · '.$label.' · ámbito no especificado por la API'];return $out;
}
