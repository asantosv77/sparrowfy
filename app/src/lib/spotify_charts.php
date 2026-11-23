<?php
declare(strict_types=1);
/**
 * Aislated Spotify Charts adapter: bridge transport, validation and normalization.
 * No credentials, cookies or headers are stored here. The session lives only in the
 * dedicated container volume.
 */

function sfc_spotify_markets():array {return ['mx'=>'México','us'=>'Estados Unidos','global'=>'Global'];}
function sfc_spotify_alias(string $market):string {
 $map=['mx'=>'regional-mx-weekly','us'=>'regional-us-weekly','global'=>'regional-global-weekly'];
 if(!isset($map[$market]))throw new InvalidArgumentException('Ranking de Spotify no compatible');
 return $map[$market];
}
function sfc_spotify_bridge():string {
 $base=(string)(getenv('SPOTIFY_CHARTS_BRIDGE')?:'http://spotify-charts:8099');
 if(!preg_match('~^http://[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?:8099$~D',$base))throw new RuntimeException('La dirección interna del puente de Spotify Charts no es válida');
 return rtrim($base,'/');
}
/** Bounded JSON request to the local bridge. Never logs bodies, cookies or headers. */
function sfc_spotify_request(string $path,?array $payload=null,int $timeout=75):array {
 $url=sfc_spotify_bridge().$path;$body='';
 $headers=['Accept: application/json'];if($payload!==null)$headers[]='Content-Type: application/json';
 $c=curl_init($url);$options=[CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>$timeout,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP,CURLOPT_PROXY=>'',CURLOPT_HTTPHEADER=>$headers,CURLOPT_WRITEFUNCTION=>static function($c,$part)use(&$body){if(strlen($body)+strlen($part)>8388608)return 0;$body.=$part;return strlen($part);}];
 if($payload!==null){$options[CURLOPT_POST]=true;$options[CURLOPT_POSTFIELDS]=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
 curl_setopt_array($c,$options);$ok=curl_exec($c);$code=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);$errno=curl_errno($c);curl_close($c);
 if($ok===false||$errno)throw new RuntimeException('El servicio de Spotify Charts no está disponible. Comprueba que el contenedor «spotify-charts» esté instalado y activo.');
 $data=json_decode($body,true);if(!is_array($data))throw new RuntimeException('El servicio de Spotify Charts devolvió una respuesta ilegible.');
 if($code<200||$code>299||($data['ok']??false)!==true){
  $message=trim((string)($data['error']??''));
  if($message==='')$message=$code===401?'La sesión de Spotify Charts caducó. Usa «Conectar / renovar sesión».':'El servicio de Spotify Charts no pudo completar la consulta.';
  throw new RuntimeException($message);
 }
 return $data;
}
function sfc_spotify_status():array {
 try{$state=sfc_spotify_request('/status',null,8);$state['reachable']=true;return $state;}
 catch(Throwable $e){return ['reachable'=>false,'connected'=>false,'login_open'=>false,'last_success'=>null,'last_error'=>$e->getMessage(),'last_error_at'=>null,'last_chart_date'=>null];}
}
function sfc_spotify_login_start():array {return sfc_spotify_request('/login/start',[],60);}
function sfc_spotify_test():array {return sfc_spotify_request('/test',[],90);}
function sfc_spotify_disconnect():array {return sfc_spotify_request('/disconnect',[],20);}

/** Fetch one weekly chart through the authenticated bridge and normalize it. */
function sfc_spotify_snapshot(string $market,int $limit=100):array {
 $alias=sfc_spotify_alias($market);
 $result=sfc_spotify_request('/fetch',['alias'=>$alias,'date'=>'latest'],90);
 if(empty($result['data'])||!is_array($result['data']))throw new RuntimeException('Spotify Charts no devolvió datos del ranking; se conserva el ranking anterior.');
 return sfc_spotify_normalize($market,$result['data'],$limit);
}

/** Strict normalization of the authenticated JSON payload. */
function sfc_spotify_normalize(string $market,array $payload,int $limit=100):array {
 $alias=sfc_spotify_alias($market);
 $display=$payload['displayChart']??null;$entries=$payload['entries']??null;
 if(!is_array($display)||!is_array($entries))throw new RuntimeException('Spotify Charts cambió el formato del ranking; se conserva el ranking anterior.');
 $meta=$display['chartMetadata']??null;if(!is_array($meta)||!is_array($meta['dimensions']??null))throw new RuntimeException('Spotify Charts no publicó los metadatos del ranking.');
 $dims=$meta['dimensions'];
 if(($dims['recurrence']??'')!=='WEEKLY')throw new RuntimeException('Spotify devolvió una edición que no es semanal; se conserva el ranking anterior.');
 if(isset($meta['alias'])){$metaAlias=strtoupper(str_replace('-','_',(string)$meta['alias']));$expectedAlias=strtoupper(str_replace('-','_',$alias));if($metaAlias!==$expectedAlias)throw new RuntimeException('Spotify devolvió un ranking de otro país o edición; se conserva el ranking anterior.');}
 if(isset($meta['entityType'])&&(string)$meta['entityType']!=='TRACK')throw new RuntimeException('Spotify devolvió un ranking que no es de canciones.');
 $date=(string)($display['date']??'');
 if(!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$date,$m)||!checkdate((int)$m[2],(int)$m[3],(int)$m[1]))throw new RuntimeException('Spotify no publicó una fecha de edición verificable; se conserva el ranking anterior.');
 $periodStart=(new DateTimeImmutable($date))->modify('-6 days')->format('Y-m-d');
 $items=[];$seen=[];
 foreach($entries as $entry){
  if(!is_array($entry)||!is_array($entry['chartEntryData']??null)||!is_array($entry['trackMetadata']??null))throw new RuntimeException('Spotify devolvió una fila incompleta; se conserva el ranking anterior.');
  $data=$entry['chartEntryData'];$track=$entry['trackMetadata'];
  $rank=$data['currentRank']??null;if(!is_int($rank)||$rank<1||$rank>1000||isset($seen['r'.$rank]))throw new RuntimeException('Spotify devolvió posiciones inválidas o duplicadas; se conserva el ranking anterior.');
  $title=trim((string)($track['trackName']??''));$names=[];
  foreach((array)($track['artists']??[]) as $artist)if(is_array($artist)&&trim((string)($artist['name']??''))!=='')$names[]=trim((string)$artist['name']);
  $artist=trim(implode(', ',array_unique($names)));
  if($title===''||$artist==='')throw new RuntimeException('Spotify devolvió una canción sin título o artista; se conserva el ranking anterior.');
  $uri=(string)($track['trackUri']??'');if(!preg_match('/^spotify:track:([0-9A-Za-z]{22})$/D',$uri,$id))throw new RuntimeException('Spotify devolvió un enlace de pista incompatible; se conserva el ranking anterior.');
  $trackId=$id[1];if(isset($seen['t'.$trackId]))throw new RuntimeException('Spotify repitió una canción en el ranking; se conserva el ranking anterior.');
  $seen['r'.$rank]=true;$seen['t'.$trackId]=true;
  $previous=$data['previousRank']??null;if($previous!==null&&(!is_int($previous)||$previous<1||$previous>1000))$previous=null;
  $art='';$image=(string)($track['displayImageUri']??'');if(preg_match('~^https://[a-z0-9.-]+\.(?:scdn\.co|spotifycdn\.com)/~D',$image))$art=$image;
  $items[]=['provider_id'=>'spotify:'.$trackId,'rank'=>$rank,'title'=>mb_substr($title,0,200),'artist'=>mb_substr($artist,0,200),'source_url'=>'https://open.spotify.com/track/'.$trackId,'artwork'=>$art,'genres'=>[],'isrc'=>'','duration'=>0,'official_previous_rank'=>$previous];
  if(count($items)>=max(1,min(200,$limit)))break;
 }
 if(!$items)throw new RuntimeException('Spotify no devolvió canciones; se conserva el ranking anterior.');
 usort($items,static fn($a,$b)=>$a['rank']<=>$b['rank']);
 return ['provider'=>'spotify','market'=>$market,'source_url'=>'https://charts.spotify.com/charts/view/'.$alias.'/'.$date,'source_updated'=>$date,'issue_date'=>$date,'period_kind'=>'weekly','period_start'=>$periodStart,'period_end'=>$date,'items'=>$items];
}
