<?php
declare(strict_types=1);
/** Durable public song URL, never a signed download/CDN URL or credentials. */
function sf_track_origin_fields(string $operation,array $payload,array $metadata=[]):array {
 $service=(string)($payload['source_service']??'');$url=(string)($payload['source_url']??'');
 if($operation==='youtube'){$service='youtube';$url=(string)($payload['url']??$metadata['webpage_url']??'');}
 elseif($operation==='mornye'||!empty($payload['mornye_parent'])){$service='mornye';$url=(string)($payload['mornye_url']??$payload['url']??$url);}
 if($url==='')return [];
 if($service==='youtube'){require_once __DIR__.'/external_youtube.php';$url=yt_validate_url($url);}
 elseif($service==='mornye'){require_once __DIR__.'/external_mornye.php';$url=sf_external_mornye_url($url);}
 else throw new RuntimeException('Servicio de origen no reconocido');
 return ['source_url'=>$url,'source_service'=>$service];
}
