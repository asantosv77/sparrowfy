<?php
declare(strict_types=1);
/** External integrations entry point. Read scripts/EXTERNAL_SERVICES.md FIRST.
 * Loading this file only defines functions: no network, session or HTML output.
 * Provider failures must remain optional; never block local playback/navigation.
 */
require_once __DIR__.'/external_youtube.php';
require_once __DIR__.'/external_cover.php';
require_once __DIR__.'/external_album.php';
require_once __DIR__.'/external_mornye.php';
require_once __DIR__.'/external_charts.php';
require_once __DIR__.'/external_charts_audio.php';
require_once __DIR__.'/external_lyrics.php';
require_once __DIR__.'/acoustid_health.php';
const SF_YOUTUBE_SEARCH_TIMEOUT = 28;
const SF_YOUTUBE_SEARCH_COOLDOWN = 120;
function sf_external_service_state_path(string $service): string {
 if(!in_array($service,['youtube_search'],true))throw new InvalidArgumentException('Unknown service');
 return dirname(__DIR__).'/data/external-'.$service.'.json';
}
function sf_external_service_available(string $service): bool {
 $file=sf_external_service_state_path($service);
 $state=is_file($file)?json_decode((string)@file_get_contents($file),true):null;
 return !is_array($state)||(int)($state['retry_after']??0)<=time();
}
function sf_external_service_result(string $service,bool $ok,string $reason=''):void {
 $file=sf_external_service_state_path($service);
 $reason=in_array($reason,['provider_failure','invalid_response','timeout_or_runtime'],true)?$reason:'';
 $state=['state'=>$ok?'ok':'unavailable','checked_at'=>time(),'retry_after'=>$ok?0:time()+SF_YOUTUBE_SEARCH_COOLDOWN,'reason'=>$reason];
 $temp=$file.'.'.bin2hex(random_bytes(4)).'.tmp';
 if(@file_put_contents($temp,json_encode($state),LOCK_EX)!==false){@chmod($temp,0660);if(!@rename($temp,$file))@unlink($temp);}
 if(!$ok)error_log('SparrowFy external service '.$service.': '.$reason);
}
/** Stable internal contract. A provider schema change is contained HERE.
 * Optional runner is for isolated tests; production uses bounded yt_run().
 * No user-supplied URLs, shell interpolation, cookies or keys are needed for search.
 */
function sf_external_youtube_search(string $query,?callable $runner=null,int $limit=2):array {
 $limit=max(1,min(3,$limit));
 $run=$runner??static fn(array $args,int $seconds,int $bytes):array=>yt_run($args,$seconds,$bytes);
 $binary=$runner?'yt-dlp-test':yt_bin();
 $r=$run([$binary,'--ignore-config','--flat-playlist','--dump-single-json','--skip-download','--no-warnings','--socket-timeout','8','--retries','0','--extractor-retries','0','--','ytsearch'.$limit.':'.$query],SF_YOUTUBE_SEARCH_TIMEOUT,1048576);
 if(($r['code']??-1)!==0)throw new RuntimeException('provider_failure');
 try{$data=json_decode((string)($r['stdout']??''),true,64,JSON_THROW_ON_ERROR);}catch(Throwable $e){throw new RuntimeException('invalid_response',0,$e);}
 if(!is_array($data)||!is_array($data['entries']??null))throw new RuntimeException('invalid_response');
 $items=[];$seen=[];
 foreach($data['entries'] as $entry){
  if(!is_array($entry))continue;
  $id=$entry['id']??null;$title=$entry['title']??null;
  if(!is_string($id)||!is_string($title)||!preg_match('/^[A-Za-z0-9_-]{11}$/D',$id)||trim($title)===''||isset($seen[$id]))continue;
  $seen[$id]=true;$channel=$entry['channel']??$entry['uploader']??'';
  $items[]=['id'=>$id,'title'=>function_exists('mb_substr')?mb_substr(trim($title),0,300):substr(trim($title),0,300),'channel'=>is_string($channel)?substr($channel,0,300):'','duration'=>is_numeric($entry['duration']??null)?max(0,(int)$entry['duration']):null,'url'=>'https://www.youtube.com/watch?v='.$id];
  if(count($items)===$limit)break;
 }
 if($data['entries']&&!$items)throw new RuntimeException('invalid_response');
 return $items;
}
