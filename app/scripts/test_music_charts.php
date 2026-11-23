<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
$temporary=sys_get_temp_dir().'/sf-charts-'.bin2hex(random_bytes(5));mkdir($temporary,0700);
define('LAB_DATA',$temporary);define('LAB_PROTECTED_ADMIN_ID','fixture-admin');
require dirname(__DIR__).'/lib/external_charts.php';require dirname(__DIR__).'/lib/external_charts_audio.php';require dirname(__DIR__).'/lib/music_charts.php';
function check(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
try {
 $settings=sfc_defaults();check(sfc_active($settings)===['apple_mx','apple_us','christian_mx','christian_us'],'Default charts and Global off');
 check(sfc_week(new DateTimeImmutable('2026-11-16 17:59:00',new DateTimeZone('America/Mexico_City')),$settings)==='2026-11-09','Before weekly deadline');
 check(sfc_week(new DateTimeImmutable('2026-11-16 18:00:00',new DateTimeZone('America/Mexico_City')),$settings)==='2026-11-16','At weekly deadline');
 $settings['max_charts']=3;$failed=false;try{sfc_save_settings($settings);}catch(InvalidArgumentException $e){$failed=true;}check($failed,'Chart cap cannot silently omit selections');
 $itunes=sf_external_chart_normalize('itunes_christian','mx',json_decode(file_get_contents(dirname(__DIR__).'/tests/itunes-christian.json'),true),20);
 check(count($itunes['items'])===20&&$itunes['items'][0]['duration']===0,'iTunes preview is not track duration');
 $global=sf_external_chart_normalize('billboard_global','global',['html'=>file_get_contents(dirname(__DIR__).'/tests/billboard-christian.html')],20);check($global['market']==='global'&&$global['items'][0]['genres']===[]&&str_contains($global['source_url'],'billboard-global-200'),'Global keeps its own source and does not invent genres');
 check($itunes['period_kind']==='current_sales_snapshot','Sales snapshot provenance');
 $billboard=sf_external_chart_normalize('billboard_christian','us',['html'=>file_get_contents(dirname(__DIR__).'/tests/billboard-christian.html')],100);
 check(count($billboard['items'])===50&&$billboard['issue_date']==='2026-11-14','Billboard full weekly chart and edition');
 check($billboard['items'][0]['title']==="God I'm Just Grateful"&&$billboard['items'][0]['official_previous_rank']===1,'Billboard song and official prior rank');
 check(str_contains($billboard['items'][0]['artist'],'Chandler Moore'),'Billboard linked artist plus collaboration');
 foreach([['html'=>'<html>Sign in</html>'],['feed'=>[]]] as $bad){$failed=false;try{sf_external_chart_normalize('billboard_christian','us',$bad);}catch(Throwable $e){$failed=true;}check($failed,'Do not invent unavailable rows');}
 $expected=['title'=>'Dancing Queen','artist'=>'ABBA','duration'=>230];
 check(sf_chart_candidate($expected,['title'=>'ABBA - Dancing Queen (Official Music Video)','channel'=>'ABBA','duration'=>230],'youtube')['confident'],'Official YouTube title');
 foreach(['Dancing Queen (Live)','Dancing Queen karaoke','Dancing Queen remix','Dancing Queen cover'] as $title)check(!sf_chart_candidate($expected,['title'=>$title,'artist'=>'ABBA','duration'=>230],'mornye')['confident'],'Reject other versions');
 check(!sf_chart_candidate($expected,['title'=>'Dancing Queen','artist'=>'ABBA','duration'=>180],'mornye')['confident'],'Reject incompatible duration');
 check(!sf_chart_candidate($expected,['title'=>'Dancing Queen','artist'=>'Other'],'mornye')['confident'],'Reject other artist');
 check(sf_chart_identity(['title'=>'Song','artist'=>'A & B'])===sf_chart_identity(['title'=>'Song','artist'=>'A feat. B']),'Cross-provider collaboration identity');
 check(sf_chart_pick(['title'=>'Song','artist'=>'A'],[['title'=>'Song','artist'=>'A','album'=>'One','duration'=>200],['title'=>'Song','artist'=>'A','album'=>'Two','duration'=>240]],'mornye')===null,'Ambiguous version needs review');
 $db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);sfc_schema($db);
 $db->prepare('INSERT INTO sf_music_charts(chart_key,provider,market,title,source_url,enabled) VALUES(?,?,?,?,?,1)')->execute(['christian_us','billboard_christian','us','Christian',$billboard['source_url']]);
 $db->prepare('INSERT INTO sf_chart_snapshots VALUES(?,?,?,?)')->execute(['christian_us','2026-11-15','2026-11-15T12:00:00Z',json_encode($billboard)]);
 $report=sfc_collect_report($db,'2026-11-09');check($report['sections'][0]['period_kind']==='weekly'&&$report['sections'][0]['tracks'][0]['change']===0,'Use official prior rank without invented capture');
 check($report['sections'][0]['period_start']===null,'Do not invent measurement window');
 echo "MUSIC_CHARTS_OK: feeds, edition, preview, matching, calendar, defaults and report\n";
}finally{foreach(glob($temporary.'/music-charts/*')?:[] as $file)unlink($file);if(is_file($temporary.'/music-charts/.htaccess'))unlink($temporary.'/music-charts/.htaccess');if(is_dir($temporary.'/music-charts'))rmdir($temporary.'/music-charts');rmdir($temporary);}
