<?php
declare(strict_types=1);
define('SFQ_YOUTUBE_FUNCTIONS_ONLY',true);
require __DIR__.'/api_youtube.php';
require_once __DIR__.'/lib/youtube_search_limits.php';
lab_auth_require_album_manager();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function sf_search_reply(array $data,int $status=200):never{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')sf_search_reply(['success'=>false,'message'=>'POST requerido.'],405);
if(!lab_auth_verify_csrf(isset($_POST['csrf_token'])?(string)$_POST['csrf_token']:null))sf_search_reply(['success'=>false,'message'=>'Recarga la página e inténtalo de nuevo.'],403);
$query=trim((string)($_POST['query']??''));
if($query===''||strlen($query)>960||preg_match('/[\x00-\x1F\x7F]/',$query))sf_search_reply(['success'=>false,'message'=>'Escribe un título de búsqueda válido.'],400);
$retry=sf_youtube_search_admit($_SESSION,($_POST['purpose']??'')==='album_sources');
if($retry){header('Retry-After: '.$retry);sf_search_reply(['success'=>false,'message'=>'Espera unos segundos antes de volver a buscar.'],429);}
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();

if(!sf_external_service_available('youtube_search'))sf_search_reply(['success'=>false,'items'=>[],'message'=>'Las sugerencias de YouTube están temporalmente en pausa tras un fallo. Puedes pegar un enlace manualmente y volver a buscar en unos minutos.']);
$lock=sf_youtube_search_slot(__DIR__.'/data');
if(!$lock){header('Retry-After: 2');sf_search_reply(['success'=>false,'items'=>[],'message'=>'Las búsquedas están ocupadas. Puedes reintentar o pegar un enlace manualmente.'],503);}
$result=[];
try{
 @set_time_limit(40);
 $items=sf_external_youtube_search($query,null,max(1,min(3,(int)($_POST['limit']??2))));
 sf_external_service_result('youtube_search',true);
 $result=['success'=>true,'items'=>$items,'message'=>$items?'':'No se encontraron resultados. Puedes modificar la búsqueda o pegar un enlace manualmente.'];
}catch(Throwable $e){
 $reason=in_array($e->getMessage(),['provider_failure','invalid_response'],true)?$e->getMessage():'timeout_or_runtime';
 sf_external_service_result('youtube_search',false,$reason);
 $result=['success'=>false,'items'=>[],'message'=>'No se pudieron obtener las sugerencias de YouTube. El servicio puede estar temporalmente indisponible o haber cambiado. Puedes seguir pegando un enlace manualmente.'];
}finally{flock($lock,LOCK_UN);fclose($lock);}
sf_search_reply($result);
