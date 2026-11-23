<?php
declare(strict_types=1);
/** Per-session sliding window; authentication/CSRF remain in the endpoint. */
function sf_youtube_search_admit(array &$session,bool $albumBatch=false,?float $now=null):int {
 $now??=microtime(true);$window=3.0;$capacity=$albumBatch?3:1;
 $hits=array_values(array_filter((array)($session['youtube_search_hits']??[]),static fn($t)=>is_numeric($t)&&(float)$t>$now-$window));
 if(count($hits)>=$capacity){$session['youtube_search_hits']=$hits;return max(1,(int)ceil((float)$hits[0]+$window-$now));}
 $hits[]=$now;$session['youtube_search_hits']=$hits;return 0;
}
/** Three shared slots across users; a process exit releases its slot automatically. */
function sf_youtube_search_slot(string $dataDir) {
 for($slot=0;$slot<3;$slot++){
  $path=$dataDir.'/.youtube-search.slot'.$slot.'.lock';$lock=@fopen($path,'c');
  if(!$lock)continue;
  if(flock($lock,LOCK_EX|LOCK_NB)){@chmod($path,0660);return $lock;}
  fclose($lock);
 }
 return null;
}
