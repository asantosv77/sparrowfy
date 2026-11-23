<?php
declare(strict_types=1);if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/lib/mornye_retry.php';require dirname(__DIR__).'/lib/external_mornye.php';
function check($value,$label){if(!$value)throw new RuntimeException($label);echo 'PASS '.$label.PHP_EOL;}
$s=['services'=>['amazon','qobuz','tidal'],'allow_fallback'=>true];$a=$s['services'];
$p=sfmn_retry_plan([],'failed','All providers failed — ext:amazon: HTTP 404',$s,$a);
check($p['services']===['qobuz','tidal'],'404 moves to remaining providers in configured order');
check(sfmn_retry_plan(['retry_count'=>2],'failed','ext:amazon: HTTP 404',$s,$a)===null,'two-retry ceiling');
foreach(['running','queued','paused','cancelled'] as $state)check(sfmn_retry_plan([],$state,'HTTP 502',$s,$a)===null,'no resubmit when '.$state);
check(sfmn_retry_plan(['submitting'=>true],'failed','HTTP 502',$s,$a)===null,'uncertain submission never repeated');
check(sfmn_retry_plan([],'failed','HTTP 502',$s,$a)!==null,'temporary failure can retry once');
check(sfmn_retry_plan(['retry_count'=>1],'failed','HTTP 502',$s,$a)===null,'temporary retry remains bounded');
check(sfmn_retry_plan([],'failed','verification required HTTP 403',$s,$a)===null,'verification requires human action');
check(sfmn_retry_plan([],'failed','HTTP 429',$s,$a)===null,'rate limit not hammered');
$alias=sfmn_retry_plan([],'failed','ext:qobuz-web: HTTP 404',$s,$a);check(!in_array('qobuz',$alias['services'],true),'provider extension alias is not retried as a new provider');
$s['allow_fallback']=false;check(sfmn_retry_plan([],'failed','ext:amazon: HTTP 404',$s,$a)===null,'disabled fallback respected');
$request=null;$url='https://open.spotify.com/track/1234567890123456789012';
$r=sf_external_mornye_start($url,'/inbox/sf-'.str_repeat('a',32).'/attempt-2',function($op,$data)use(&$request){$request=$data;return ['id'=>'job-fixture'];},['qobuz','tidal']);
check($request['url']===$url&&$request['_services']===['qobuz','tidal'],'retry preserves exact song and ordered provider subset');
$bad=false;try{sf_external_mornye_start($url,'/inbox/sf-'.str_repeat('a',32).'/../outside',fn()=>[]);}catch(Throwable $e){$bad=true;}check($bad,'retry directory boundary');
