<?php
declare(strict_types=1);
/** Editable external metadata contracts only. No storage, auth, queue or playback. */
function sf_external_chart_url(string $provider,string $market):string {
 if($provider==='apple'&&in_array($market,['mx','us'],true))return 'https://rss.marketingtools.apple.com/api/v2/'.$market.'/music/most-played/100/songs.json';
 if($provider==='itunes_christian'&&$market==='mx')return 'https://itunes.apple.com/mx/rss/topsongs/limit=100/genre=22/json';
 if($provider==='billboard_christian'&&$market==='us')return 'https://www.billboard.com/charts/christian-songs/';
 if($provider==='billboard_global'&&$market==='global')return 'https://www.billboard.com/charts/billboard-global-200/';
 if($provider==='billboard_hot100'&&$market==='us')return 'https://www.billboard.com/charts/hot-100/';
 if($provider==='billboard_latin_pop'&&$market==='us')return 'https://www.billboard.com/charts/hot-latin-pop-songs/';
 if($provider==='billboard_rock'&&$market==='us')return 'https://www.billboard.com/charts/rock-songs/';
 if($provider==='deezer'){ $ids=['global'=>0,'pop'=>132,'rock'=>152,'electronic'=>106,'dance'=>113,'alternative'=>85,'jazz'=>129,'classical'=>98];if(isset($ids[$market]))return 'https://api.deezer.com/chart/'.$ids[$market].'/tracks?limit=100'; }
 if($provider==='spotify'&&$market==='global')return 'https://charts.spotify.com/charts/view/regional-global-weekly/latest';
 throw new InvalidArgumentException('Ranking no compatible');
}
function sf_external_chart_normalize(string $provider,string $market,array $data,int $limit=100):array {
 if($provider==='deezer')return sf_external_chart_deezer($market,$data,$limit);
 if(in_array($provider,['billboard_hot100','billboard_latin_pop','billboard_rock'],true)&&$market==='us'){$chart=sf_external_chart_billboard((string)($data['html']??''),$limit);$chart['provider']=$provider;$chart['market']=$market;$chart['source_url']=sf_external_chart_url($provider,$market);$genres=['billboard_hot100'=>[],'billboard_latin_pop'=>['Latin Pop'],'billboard_rock'=>['Rock','Alternative']];foreach($chart['items'] as &$item){$item['source_url']=$chart['source_url'];$item['genres']=$genres[$provider];}unset($item);return $chart;}
 if($provider==='billboard_global'&&$market==='global'){$chart=sf_external_chart_billboard((string)($data['html']??''),$limit);$chart['provider']=$provider;$chart['market']=$market;$chart['source_url']=sf_external_chart_url($provider,$market);foreach($chart['items'] as &$item){$item['source_url']=$chart['source_url'];$item['genres']=[];}unset($item);return $chart;}
 if($provider==='itunes_christian'&&$market==='mx')return sf_external_chart_christian_itunes($data,$limit);
 if($provider==='billboard_christian'&&$market==='us')return sf_external_chart_billboard((string)($data['html']??''),$limit);
 if($provider!=='apple'||!in_array($market,['mx','us'],true))throw new RuntimeException('No hay un adaptador público validado para este ranking');
 $feed=$data['feed']??null;if(!is_array($feed)||($feed['country']??'')!==$market||!is_array($feed['results']??null)||!is_string($feed['updated']??null))throw new RuntimeException('El formato o país del ranking de Apple cambió');
 $items=[];$seen=[];$rank=0;
 foreach(array_slice($feed['results'],0,100) as $item){$rank++;
  if(!is_array($item)||!preg_match('/^[0-9]{1,20}$/D',(string)($item['id']??''))||!is_string($item['name']??null)||!is_string($item['artistName']??null)||trim($item['name'])===''||trim($item['artistName'])===''||isset($seen[$item['id']]))throw new RuntimeException('Ranking incompleto o con identidades incompatibles');
  $seen[$item['id']]=true;$song=(string)($item['url']??'');if(!str_starts_with($song,'https://music.apple.com/'.$market.'/'))throw new RuntimeException('URL de canción incompatible');
  $art=(string)($item['artworkUrl100']??'');if(!preg_match('~^https://(?:is[0-9]+-ssl|is[0-9]+|a[0-9]+)\.mzstatic\.com/~D',$art))$art='';
  $genres=[];foreach((array)($item['genres']??[]) as $genre)if(is_array($genre)&&($genre['genreId']??'')!=='34'&&is_string($genre['name']??null))$genres[]=mb_substr($genre['name'],0,80);
  $isrc=strtoupper((string)($item['isrc']??''));if(!preg_match('/^[A-Z]{2}[A-Z0-9]{3}[0-9]{7}$/D',$isrc))$isrc='';
  $items[]=['provider_id'=>(string)$item['id'],'rank'=>$rank,'title'=>mb_substr(trim($item['name']),0,200),'artist'=>mb_substr(trim($item['artistName']),0,200),'source_url'=>$song,'artwork'=>$art,'genres'=>array_values(array_unique($genres)),'isrc'=>$isrc,'duration'=>0];
  if(count($items)>=max(1,min(100,$limit)))break;
 }
 if(!$items)throw new RuntimeException('El proveedor no devolvió canciones; se conserva el ranking anterior');
 return ['provider'=>$provider,'market'=>$market,'source_url'=>sf_external_chart_url($provider,$market),'source_updated'=>$feed['updated'],'period_kind'=>'current_snapshot','period_start'=>null,'period_end'=>null,'items'=>$items];
}
function sf_external_chart_christian_itunes(array $data,int $limit=100):array {
 $feed=$data['feed']??[];if(!is_array($feed['entry']??null)||!is_string($feed['updated']['label']??null))throw new RuntimeException('El ranking cristiano de iTunes no tiene el formato esperado');
 $items=[];$seen=[];
 foreach(array_slice($feed['entry'],0,100) as $entry){
  $id=(string)($entry['id']['attributes']['im:id']??'');$title=trim((string)($entry['im:name']['label']??''));$artist=trim((string)($entry['im:artist']['label']??''));$url=(string)($entry['id']['label']??'');
  if(!preg_match('/^[0-9]{1,20}$/D',$id)||isset($seen[$id])||!$title||!$artist||!str_starts_with($url,'https://music.apple.com/mx/'))throw new RuntimeException('Ranking cristiano incompleto o de otro país');
  $seen[$id]=true;$art='';foreach($entry['im:image']??[] as $img)if(preg_match('~^https://(?:is[0-9]+-ssl|is[0-9]+|a[0-9]+)\.mzstatic\.com/~D',(string)($img['label']??'')))$art=$img['label'];
  // im:duration belongs to the preview. It is never the full song duration.
  $items[]=['provider_id'=>$id,'rank'=>count($items)+1,'title'=>mb_substr($title,0,200),'artist'=>mb_substr($artist,0,200),'source_url'=>$url,'artwork'=>$art,'genres'=>['Christian & Gospel'],'isrc'=>'','duration'=>0];
  if(count($items)>=max(1,min(100,$limit)))break;
 }
 if(!$items)throw new RuntimeException('iTunes no devolvió canciones cristianas; se conserva la selección anterior');
 return ['provider'=>'itunes_christian','market'=>'mx','source_url'=>sf_external_chart_url('itunes_christian','mx'),'source_updated'=>$feed['updated']['label'],'period_kind'=>'current_sales_snapshot','period_start'=>null,'period_end'=>null,'items'=>$items];
}
function sf_external_chart_billboard(string $html,int $limit=100):array {
 if(!preg_match('/Week of\s+([A-Za-z]+\s+\d{1,2},\s+\d{4})/',$html,$date)||!($stamp=strtotime($date[1])))throw new RuntimeException('Billboard no publica una edición verificable en esta respuesta');
 $issue=gmdate('Y-m-d',$stamp);$chunks=preg_split('~<div\b[^>]*class="[^"]*\bo-chart-results-list-row-container\b[^"]*"[^>]*>~',$html);$items=[];$seen=[];
 foreach(array_slice($chunks?:[],1) as $row){
  if(!preg_match('/data-detail-target="([0-9]+)"/',$row,$rank)||!preg_match('~<h3\b[^>]*\bid="title-of-a-story"[^>]*>(.*?)</h3>~s',$row,$title,PREG_OFFSET_CAPTURE))throw new RuntimeException('Cambió el formato de filas de Billboard');
  $position=(int)$rank[1];if($position!==count($items)+1)throw new RuntimeException('Ranking Billboard con posiciones incompletas');
  $after=substr($row,$title[0][1]+strlen($title[0][0]));if(!preg_match('~^\s*<span\b[^>]*>(.*?)</span>~s',$after,$artist))throw new RuntimeException('Falta el artista del ranking Billboard');
  $clean=static fn(string $s):string=>trim(preg_replace('/\s+/u',' ',html_entity_decode(strip_tags($s),ENT_QUOTES|ENT_HTML5,'UTF-8'))??'');$name=$clean($title[1][0]);$who=$clean($artist[1]);if(!$name||!$who)throw new RuntimeException('Billboard devolvió una canción sin identificar');
  $previous=null;if(preg_match('~>\s*LW\s*</span>\s*<ul\b.*?<span\b[^>]*>\s*(\d+|-)\s*</span>~s',$row,$prior)&&$prior[1]!=='-')$previous=(int)$prior[1];
  $art='';if(preg_match('~(?:src|data-lazy-src)="(https://charts-static\.billboard\.com/[^"<>]+)"~',$row,$image))$art=html_entity_decode($image[1],ENT_QUOTES|ENT_HTML5,'UTF-8');
  $id=hash('sha256',$who.'|'.$name);if(isset($seen[$id]))throw new RuntimeException('Billboard repitió una identidad');$seen[$id]=true;
  $items[]=['provider_id'=>'billboard:'.$id,'rank'=>$position,'title'=>mb_substr($name,0,200),'artist'=>mb_substr($who,0,200),'source_url'=>sf_external_chart_url('billboard_christian','us'),'artwork'=>$art,'genres'=>['Christian'],'isrc'=>'','duration'=>0,'official_previous_rank'=>$previous];
  if(count($items)>=max(1,min(100,$limit)))break;
 }
 if(!$items)throw new RuntimeException('Billboard no devolvió canciones; se conserva la selección anterior');
 return ['provider'=>'billboard_christian','market'=>'us','source_url'=>sf_external_chart_url('billboard_christian','us'),'source_updated'=>$issue,'issue_date'=>$issue,'period_kind'=>'weekly','period_start'=>null,'period_end'=>null,'items'=>$items];
}
function sf_external_chart_fetch(string $provider,string $market,int $limit=100,?callable $runner=null):array {
 if($provider==='spotify')throw new RuntimeException('Spotify Charts requiere acceso a sus rankings completos; no hay una fuente automática validada. Se conservan los demás charts.');
 $url=sf_external_chart_url($provider,$market);return sf_external_chart_normalize($provider,$market,($runner??'sfc_http')($url),$limit);
}
function sf_external_chart_detail(array $track,string $market,?callable $runner=null):array {
 if(!in_array($market,['mx','us'],true)||!preg_match('/^[0-9]{1,20}$/D',(string)($track['provider_id']??'')))return $track;
 $data=($runner??'sfc_http')('https://itunes.apple.com/lookup?'.http_build_query(['id'=>$track['provider_id'],'entity'=>'song','country'=>$market]));
 foreach($data['results']??[] as $item)if((string)($item['trackId']??'')===$track['provider_id']){$track['duration']=max(0,(int)round((float)($item['trackTimeMillis']??0)/1000));$isrc=strtoupper((string)($item['isrc']??''));if(preg_match('/^[A-Z]{2}[A-Z0-9]{3}[0-9]{7}$/D',$isrc))$track['isrc']=$isrc;break;}return $track;
}

function sf_external_chart_deezer(string $market,array $data,int $limit=100):array {
 $genres=['global'=>[],'pop'=>['Pop'],'rock'=>['Rock'],'electronic'=>['Electronic'],'dance'=>['Dance'],'alternative'=>['Alternative'],'jazz'=>['Jazz'],'classical'=>['Classical']];
 if(!isset($genres[$market])||!is_array($data['data']??null)||isset($data['error']))throw new RuntimeException('Deezer no devolvió un ranking compatible');
 $items=[];$seen=[];
 foreach(array_slice($data['data'],0,100) as $entry){$id=(string)($entry['id']??'');$title=trim((string)($entry['title']??''));$artist=trim((string)($entry['artist']['name']??''));$rank=(int)($entry['position']??0);
  if(!preg_match('/^[0-9]{1,20}$/D',$id)||isset($seen[$id])||!$title||!$artist||$rank!==count($items)+1)throw new RuntimeException('Deezer devolvió posiciones o identidades incompletas');$seen[$id]=true;
  $url=(string)($entry['link']??'');if($url!=='https://www.deezer.com/track/'.$id)throw new RuntimeException('URL de pista Deezer incompatible');$art=(string)($entry['album']['cover_medium']??'');if(!preg_match('~^https://(?:cdn-images|e-cdns-images)\.dzcdn\.net/images/~D',$art))$art='';
  $items[]=['provider_id'=>'deezer:'.$id,'rank'=>$rank,'title'=>mb_substr($title,0,200),'artist'=>mb_substr($artist,0,200),'source_url'=>$url,'artwork'=>$art,'genres'=>$genres[$market],'isrc'=>'','duration'=>max(0,(int)($entry['duration']??0))];if(count($items)>=max(1,min(100,$limit)))break;
 }
 if(!$items)throw new RuntimeException('Deezer no publicó canciones para este ranking');
 return ['provider'=>'deezer','market'=>$market,'source_url'=>sf_external_chart_url('deezer',$market),'source_updated'=>null,'period_kind'=>'current_snapshot','period_start'=>null,'period_end'=>null,'items'=>$items];
}
