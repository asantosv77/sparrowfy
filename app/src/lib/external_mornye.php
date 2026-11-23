<?php
declare(strict_types=1);
/** Mornye API mapping only. Transport/queue/auth remain outside AI scope. */
function sf_external_mornye_url(string $url):string{
 if(!preg_match('~^https://open\.spotify\.com/track/([A-Za-z0-9]{22})(?:\?[^\s]*)?$~D',$url,$m))throw new RuntimeException('Selecciona una canción de los resultados de Mornye.');
 return 'https://open.spotify.com/track/'.$m[1];
}
function sf_external_mornye_search(string $query,?callable $runner=null):array{
 $query=trim($query);if($query===''||mb_strlen($query)>240)throw new RuntimeException('Escribe una búsqueda de hasta 240 caracteres.');
 $data=($runner??'sfmn_api')('search',['query'=>$query]);
 if(!is_array($data['tracks']??null))throw new RuntimeException('La respuesta de búsqueda de Mornye cambió.');
 $items=[];$seen=[];foreach($data['tracks'] as $t){
  if(!is_array($t)||!is_string($t['id']??null)||!preg_match('/^[A-Za-z0-9]{22}$/D',$t['id'])||!is_string($t['title']??null)||trim($t['title'])===''||isset($seen[$t['id']]))continue;
  $seen[$t['id']]=true;$artist=is_string($t['artists']??null)?$t['artists']:'';
  $items[]=['id'=>$t['id'],'title'=>mb_substr(trim($t['title']),0,200),'artist'=>mb_substr($artist,0,200),'album'=>mb_substr((string)($t['album']??''),0,200),'duration'=>max(0,(int)round((float)($t['duration_ms']??0)/1000)),'url'=>'https://open.spotify.com/track/'.$t['id']];
  if(count($items)>=5)break;
 }
 return $items;
}
function sf_external_mornye_start(string $url,string $directory,?callable $runner=null,?array $services=null):array{
 $url=sf_external_mornye_url($url);if(!preg_match('~^/inbox/sf-[a-f0-9]{32}(?:/attempt-[2-3])?$~D',$directory))throw new RuntimeException('Directorio de descarga inválido');
 $data=['url'=>$url,'output_dir'=>$directory];if($services!==null)$data['_services']=$services;
 $x=($runner??'sfmn_api')('start',$data);
 if(!is_string($x['id']??null)||!preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$x['id']))throw new RuntimeException('Mornye no devolvió un identificador de tarea válido');return ['id'=>$x['id']];
}
function sf_external_mornye_status(string $id,?callable $runner=null):array{
 if(!preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$id))throw new RuntimeException('Tarea Mornye inválida');
 $x=($runner??'sfmn_api')('status',['id'=>$id]);$status=$x['status']??'';
 if(!in_array($status,['queued','running','paused','retrying','done','failed','cancelled'],true))throw new RuntimeException('Estado de Mornye incompatible');
 return ['status'=>$status,'error'=>is_string($x['error']??null)?mb_substr($x['error'],0,500):''];
}
