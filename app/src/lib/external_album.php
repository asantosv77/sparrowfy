<?php
declare(strict_types=1);
/** Album discovery: bounded metadata adapters. Read scripts/EXTERNAL_SERVICES.md. */
function sfa_http(string $url):array {
 $u=parse_url($url);$host=$u['host']??'';
 if(($u['scheme']??'')!=='https'||!in_array($host,['musicbrainz.org','itunes.apple.com','coverartarchive.org'],true)||isset($u['user'])||isset($u['pass'])||isset($u['port']))throw new RuntimeException('Proveedor no permitido');
 $ip=gethostbyname($host);if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_GLOBAL_RANGE|FILTER_FLAG_NO_RES_RANGE))throw new RuntimeException('Proveedor no disponible');
 $ch=curl_init($url);$body='';curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>12,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_PROXY=>'',CURLOPT_RESOLVE=>[$host.':443:'.$ip],CURLOPT_USERAGENT=>'SparrowFy/1.0 (album metadata; https://sparrowfy.ddns.info)',CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_WRITEFUNCTION=>static function($ch,$chunk)use(&$body){if(strlen($body)+strlen($chunk)>2097152)return 0;$body.=$chunk;return strlen($chunk);}]);
 $ok=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);if($ok===false||$code!==200)throw new RuntimeException('El catálogo no respondió. Prueba otra fuente o los enlaces manuales.');
 $data=json_decode($body,true,64,JSON_THROW_ON_ERROR);if(!is_array($data))throw new RuntimeException('Respuesta de catálogo incompatible');return $data;
}
function sfa_cached(string $url):array {
 $root=dirname(__DIR__).'/data/album-discovery';if(!is_dir($root))mkdir($root,02770,true);$file=$root.'/'.hash('sha256',$url).'.json';
 if(is_file($file)&&filemtime($file)>time()-3600){$v=json_decode((string)file_get_contents($file),true);if(is_array($v))return $v;}
 $lock=fopen($root.'/.request.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Hay otra consulta de catálogo en curso. Intenta de nuevo en unos segundos.');
 try{if(str_starts_with($url,'https://musicbrainz.org/')){$last=(float)@file_get_contents($root.'/.musicbrainz-at');$wait=1.1-(microtime(true)-$last);if($wait>0)usleep((int)($wait*1000000));file_put_contents($root.'/.musicbrainz-at',(string)microtime(true));}$data=sfa_http($url);file_put_contents($file.'.tmp',json_encode($data));rename($file.'.tmp',$file);return $data;}finally{flock($lock,LOCK_UN);fclose($lock);}
}
function sfa_artist(array $credits):string {$names=[];foreach($credits as $c)if(is_array($c))$names[]=($c['name']??$c['artist']['name']??'').($c['joinphrase']??'');return trim(implode('',$names));}
function sfa_search(string $provider,string $artist,string $title,string $year='',?callable $runner=null):array {
 $fetch=$runner??'sfa_cached';
 $out=[];
 if($provider==='musicbrainz'){
  $escape=static fn($v)=>str_replace(['\\','"'],['\\\\','\\"'],$v);
  $q='release:"'.$escape($title).'" AND artist:"'.$escape($artist).'"';if($year!=='')$q.=' AND date:'.$year;
  $d=$fetch('https://musicbrainz.org/ws/2/release/?'.http_build_query(['query'=>$q,'fmt'=>'json','limit'=>8]));
  foreach($d['releases']??[] as $r){if(!preg_match('/^[a-f0-9-]{36}$/D',(string)($r['id']??'')))continue;$out[]=['id'=>$r['id'],'provider'=>$provider,'title'=>$r['title']??'','artist'=>sfa_artist($r['artist-credit']??[]),'date'=>$r['date']??'','country'=>$r['country']??'','tracks'=>(int)($r['track-count']??0),'cover'=>null];}
 }elseif($provider==='itunes'){
  $d=$fetch('https://itunes.apple.com/search?'.http_build_query(['term'=>$artist.' '.$title,'entity'=>'album','media'=>'music','limit'=>8,'country'=>'mx']));
  foreach($d['results']??[] as $r)if(isset($r['collectionId'])){$date=substr($r['releaseDate']??'',0,10);if($year!==''&&!str_starts_with($date,$year))continue;$out[]=['id'=>(string)$r['collectionId'],'provider'=>$provider,'title'=>$r['collectionName']??'','artist'=>$r['artistName']??'','date'=>$date,'country'=>'','tracks'=>(int)($r['trackCount']??0),'cover'=>str_replace('100x100bb','600x600bb',$r['artworkUrl100']??'')];}
 }else throw new InvalidArgumentException('Fuente inválida');
 return array_slice($out,0,8);
}
function sfa_release(string $provider,string $id,?callable $runner=null):array {
 $fetch=$runner??'sfa_cached';
 $tracks=[];$cover=null;$artist='';$title='';$date='';
 if($provider==='musicbrainz'){
  if(!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D',$id))throw new InvalidArgumentException('Edición inválida');
  $d=$fetch('https://musicbrainz.org/ws/2/release/'.$id.'?inc=recordings+artist-credits&fmt=json');$artist=sfa_artist($d['artist-credit']??[]);$title=$d['title']??'';$date=$d['date']??'';
  foreach($d['media']??[] as $disc)foreach($disc['tracks']??[] as $t)$tracks[]=['title'=>$t['title']??$t['recording']['title']??'','artist'=>sfa_artist($t['artist-credit']??$t['recording']['artist-credit']??[])?:$artist,'duration'=>(float)($t['length']??$t['recording']['length']??0)/1000,'disc'=>(int)($disc['position']??1),'position'=>(int)($t['position']??0)];
  try{$art=$fetch('https://coverartarchive.org/release/'.$id);foreach($art['images']??[] as $image)if(!empty($image['front'])){$cover=$image['thumbnails']['large']??$image['image']??null;break;}}catch(Throwable $e){}
 }elseif($provider==='itunes'){
  if(!preg_match('/^[0-9]{1,16}$/D',$id))throw new InvalidArgumentException('Edición inválida');
  $d=$fetch('https://itunes.apple.com/lookup?'.http_build_query(['id'=>$id,'entity'=>'song','country'=>'mx','limit'=>200]));
  foreach($d['results']??[] as $r){if(($r['wrapperType']??'')==='collection'){$artist=$r['artistName']??'';$title=$r['collectionName']??'';$date=substr($r['releaseDate']??'',0,10);$cover=str_replace('100x100bb','600x600bb',$r['artworkUrl100']??'');}elseif(($r['kind']??'')==='song')$tracks[]=['title'=>$r['trackName']??'','artist'=>$r['artistName']??$artist,'duration'=>(float)($r['trackTimeMillis']??0)/1000,'disc'=>(int)($r['discNumber']??1),'position'=>(int)($r['trackNumber']??0)];}
 }else throw new InvalidArgumentException('Fuente inválida');
 if(!$title||!$tracks||count($tracks)>100)throw new RuntimeException('La edición no contiene pistas o supera 100 canciones. Elige otra edición o usa los enlaces manuales.');
 usort($tracks,static fn($a,$b)=>[$a['disc'],$a['position']]<=>[$b['disc'],$b['position']]);
 if(!is_string($cover)||!str_starts_with($cover,'https://'))$cover=null;
 return ['id'=>$id,'provider'=>$provider,'title'=>$title,'artist'=>$artist,'date'=>$date,'cover'=>$cover,'tracks'=>$tracks];
}
function sfa_words(string $text):array{
 $text=mb_strtolower($text,'UTF-8');
 if(class_exists('Normalizer'))$text=Normalizer::normalize($text,Normalizer::FORM_D)?:$text;
 $text=preg_replace('/\p{Mn}+/u','',$text)??$text;
 $parts=preg_split('/[^a-z0-9]+/u',$text,-1,PREG_SPLIT_NO_EMPTY)?:[];
 static $stop=null;$stop??=array_fill_keys(['en','de','del','la','el','los','las','y','o','u','al','con','the','and','a','of','to','in','on','for','official','oficial','video','videoclip','audio','lyrics','lyric','letra','hd','hq','mv','feat','ft'],true);
 return array_values(array_unique(array_filter($parts,static fn($w)=>mb_strlen($w)>1&&!ctype_digit($w)&&!isset($stop[$w]))));
}
function sfa_title_words(string $title):array{
 if(preg_match('/\s+[-–—]\s+/u',$title,$m,PREG_OFFSET_CAPTURE)===1){$tail=substr($title,(int)$m[0][1]+strlen($m[0][0]));$words=sfa_words($tail);if($words)return $words;}
 return sfa_words($title);
}
function sfa_rank(array $track,array $items,bool $preferLyrics=false):array {
 $titleWords=sfa_words((string)($track['title']??''));$artistWords=sfa_words((string)($track['artist']??''));$out=[];
 foreach(array_slice($items,0,3) as $item){
  $rawTitle=(string)($item['title']??'');$candWords=sfa_words($rawTitle);$candTitle=sfa_title_words($rawTitle);$channel=sfa_words((string)($item['channel']??''));
  $score=0;
  $hits=$titleWords?count(array_intersect($titleWords,$candWords)):0;
  $coverage=$titleWords?$hits/count($titleWords):0.0;
  if($coverage>=0.999)$score+=60;elseif($coverage>=0.66)$score+=45;elseif($hits>0)$score+=20;
  $artistHits=$artistWords?count(array_intersect($artistWords,array_merge($candWords,$channel))):0;
  if($artistHits>0)$score+=20;
  $expected=(float)($track['duration']??0);$actual=(float)($item['duration']??0);$delta=$expected>0&&$actual>0?abs($expected-$actual):null;
  if($delta!==null){$score+=$delta<=5?15:($delta<=12?8:($delta<=30?0:-35));}
  $hasLyrics=preg_match('/\b(lyrics?|letra)\b/iu',$rawTitle)===1;
  if($preferLyrics&&$hasLyrics)$score+=12;
  elseif(!$preferLyrics&&$hasLyrics)$score-=18;
  if(preg_match('/\b(official|oficial|topic)\b/iu',$rawTitle.' '.($item['channel']??'')))$score+=5;
  foreach(['cover','karaoke','instrumental','nightcore','slowed','remix','envivo','live','spedup'] as $bad){
   $needles=$bad==='envivo'?['vivo']:($bad==='spedup'?['sped','up']:[$bad]);
   if(array_intersect($needles,$candWords)&&!array_intersect($needles,$titleWords))$score-=40;
  }
  $item['score']=max(0,min(100,$score));$item['confident']=$item['score']>=70;
  $item['match_reason']=implode(' · ',array_filter([$coverage>=0.999?'título idéntico':($coverage>=0.66?'título similar':'título parcial'),$artistHits?'artista coincide':null,$delta!==null?($delta<=5?'duración idéntica':'duración ±'.(int)round($delta).' s'):null,$hasLyrics?($preferLyrics?'con letra (preferida)':'con letra'):null]));
  $out[]=$item;
 }
 usort($out,static fn($a,$b)=>$b['score']<=>$a['score']);return $out;
}
