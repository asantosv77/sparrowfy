<?php
declare(strict_types=1);
require_once __DIR__.'/mornye_control.php';
require_once __DIR__.'/external_mornye.php';
require_once __DIR__.'/mornye_retry.php';
/** Fixed internal service. No provider-supplied redirects or arbitrary endpoints. */
function sfmn_api(string $operation,array $data):array{
 $post=false;switch($operation){case 'search':$path='/api/v1/search?q='.rawurlencode((string)$data['query']);break;case 'start':$path='/api/v1/downloads';$post=true;break;case 'status':if(!preg_match('/^[A-Za-z0-9_-]{1,80}$/D',(string)($data['id']??'')))throw new RuntimeException('ID inválido');$path='/api/v1/downloads/'.$data['id'];break;default:throw new RuntimeException('Operación no permitida');}
 if($operation==='start'){
  $preferences=sfmn_request('settings');$providers=(array)($preferences['services']??[]);$available=array_column(sfmn_request('providers')['services']??[],'id');
  $providers=array_values(array_filter($providers,static fn($id)=>is_string($id)&&in_array($id,$available,true)));if(!$providers)throw new RuntimeException('Configura proveedores instalados en el panel Mornye.');
  $data['services']=!empty($preferences['allow_fallback'])?$providers:array_slice($providers,0,1);
  if(isset($data['_services'])){$requested=$data['_services'];if(!is_array($requested)||!$requested||array_diff($requested,$data['services']))throw new RuntimeException('Proveedores de reintento no permitidos');$data['services']=array_values(array_intersect($data['services'],$requested));unset($data['_services']);}
  $quality=(string)($preferences['quality']??'LOSSLESS');$data['quality']=preg_match('/^[A-Z0-9_]{1,40}$/D',$quality)?$quality:'LOSSLESS';
 }
 $ch=curl_init(rtrim((string)(getenv('MORNYE_API_URL') ?: 'http://mornye-api:8000'), '/').$path);$body='';curl_setopt_array($ch,[CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>25,CURLOPT_PROXY=>'',CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_WRITEFUNCTION=>static function($ch,$part)use(&$body){if(strlen($body)+strlen($part)>2097152)return 0;$body.=$part;return strlen($part);}]);
 if($post)curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($data,JSON_THROW_ON_ERROR)]);
 $ok=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
 if($ok===false||$code<200||$code>=300)throw new RuntimeException('Mornye no pudo atender la solicitud (HTTP '.$code.'). Revisa su panel y la sesión del proveedor.');
 $x=json_decode($body,true,64,JSON_THROW_ON_ERROR);if(!is_array($x)||(isset($x['error'])&&$operation!=='status'))throw new RuntimeException('Mornye devolvió un error o un formato incompatible.');return $x;
}
function sfmn_fetch_source(PDO $db,array $job,array $payload):array{
 $id=(string)$job['id'];if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new RuntimeException('Trabajo inválido');
 $url=sf_external_mornye_url((string)($payload['url']??''));$root=realpath('/sparrowfy/inbox');if(!$root||!is_writable($root))throw new RuntimeException('El inbox de Mornye no está disponible');
 $dir=$root.'/sf-'.$id;if(is_link($dir))throw new RuntimeException('Temporal inválido');if(!is_dir($dir)&&!mkdir($dir,02770))throw new RuntimeException('No se pudo preparar el inbox');chmod($dir,02770);
 $receipt=sfq_dir().'/mornye-'.$id.'.json';$state=is_file($receipt)?json_decode((string)file_get_contents($receipt),true):[];
 if(!empty($state['submitting']))throw new RuntimeException('Solicitud Mornye incierta; revisa su cola antes de reenviar.');
 if(empty($payload['mornye_download_ready'])&&empty($state['remote_id'])){
  if(!empty($state['submitting']))throw new RuntimeException('Respuesta anterior de Mornye incierta. Revisa su cola antes de reintentar para evitar descargas duplicadas.');
  sfmn_write($receipt,['submitting'=>true,'created'=>time()]);sfq_update($db,$id,'Mornye: solicitando descarga',8);
  $remote=sf_external_mornye_start($url,'/inbox/sf-'.$id);$state=['remote_id'=>$remote['id'],'created'=>time()];sfmn_write($receipt,$state);
 }
 $until=microtime(true)+1800;$networkFailures=0;
 do{
  try{$r=!empty($payload['mornye_download_ready'])?['status'=>'done','error'=>'']:sf_external_mornye_status($state['remote_id']);$status=$r['status'];$networkFailures=0;}catch(Throwable $e){if(++$networkFailures>5)throw new RuntimeException('Conexión con Mornye interrumpida. La tarea conserva su identificador; reanuda el lote sin reenviar la descarga.');sfq_update($db,$id,'Mornye: conexión interrumpida; reintentando',15);sleep(min(30,3*(2**$networkFailures)));continue;}
  if($status==='failed'){
   try{$settings=sfmn_request('settings');$available=array_column(sfmn_request('providers')['services']??[],'id');$plan=sfmn_retry_plan($state,$status,$r['error'],$settings,$available);}catch(Throwable $policyError){$plan=null;}
   if($plan){
    $state['retry_count']=(int)($state['retry_count']??0)+1;$state['failed_providers']=$plan['failed_providers'];
    $state['history'][]=['id'=>$state['remote_id'],'error'=>mb_substr($r['error'],0,500),'finished'=>time()];
    $state['previous_remote_id']=$state['remote_id'];unset($state['remote_id']);$state['submitting']=true;sfmn_write($receipt,$state);
    $attempt=(int)$state['retry_count']+1;$attemptDir=$dir.'/attempt-'.$attempt;
    if(is_link($attemptDir)||(!is_dir($attemptDir)&&!mkdir($attemptDir,02770)))throw new RuntimeException('No se pudo preparar el reintento Mornye');
    sfq_update($db,$id,'Mornye: '.$plan['reason'].' (reintento '.$state['retry_count'].'/2)',15);
    $remote=sf_external_mornye_start($url,'/inbox/sf-'.$id.'/attempt-'.$attempt,null,$plan['services']);
    $state['remote_id']=$remote['id'];unset($state['submitting']);sfmn_write($receipt,$state);
    sfmn_event('import.retry.'.$id,'Reintento '.$state['retry_count'].'/2: '.$plan['reason'].'. Orden: '.implode(', ',$plan['services']).'.','warning');
    continue;
   }
  }
  if(in_array($status,['failed','cancelled'],true)){sfmn_event('import.failed.'.$id,$r['error']?:'Mornye no completó la descarga. Revisa el proveedor y su verificación.');throw new RuntimeException('Descarga Mornye fallida. Consulta Configuración → Mornye API para ver el detalle.');}
  if($status==='paused'){sfmn_event('import.paused.'.$id,'Descarga pausada en Mornye. Comprueba si requiere intervención o verificación del proveedor.');throw new RuntimeException('Mornye dejó la tarea en pausa. Revisa su interfaz antes de reintentar.');}
  if($status==='done')break;
  sfq_update($db,$id,$status==='queued'?'Mornye: esperando turno':'Mornye: descargando fuente (sin porcentaje disponible)',15);sleep(3);
 }while(microtime(true)<$until);
 if($status!=='done')throw new RuntimeException('Mornye superó 30 minutos. La tarea externa puede continuar; revisa su cola antes de volver a enviar.');
 $activeDir=!empty($state['retry_count'])?$dir.'/attempt-'.((int)$state['retry_count']+1):$dir;
 $files=[];$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($activeDir,FilesystemIterator::SKIP_DOTS));
 foreach($it as $f){if($f->isLink())throw new RuntimeException('Mornye creó un enlace no permitido');if(!$f->isFile())continue;$ext=strtolower($f->getExtension());if(in_array($ext,['mp3','flac','m4a','aac','ogg','opus','wav','webm','mp4'],true)&&$f->getSize()>0)$files[]=$f->getPathname();}
 if(count($files)!==1)throw new RuntimeException('Mornye terminó sin entregar un único archivo de audio. Revisa la tarea en su interfaz.');
 $source=$files[0];if(filesize($source)>524288000)throw new RuntimeException('La fuente Mornye supera 500 MiB');
 if(!empty($payload['mornye_download_ready']))return ['source'=>$source,'directory'=>null,'filename'=>(string)$payload['filename'].'.'.strtolower(pathinfo($source,PATHINFO_EXTENSION))];
 $tmp=sys_get_temp_dir().'/sfq-mornye-'.bin2hex(random_bytes(12));if(!mkdir($tmp,0700))throw new RuntimeException('No se pudo crear temporal');$ext=strtolower(pathinfo($source,PATHINFO_EXTENSION));$dest=$tmp.'/source.'.$ext;
 if(!copy($source,$dest)){rmdir($tmp);throw new RuntimeException('No se pudo copiar la fuente Mornye');}
 $name=str_replace(['/','\\'], '_',mb_substr((string)($payload['filename']??'Pista Mornye'),0,200));$name=preg_replace('/[\x00-\x1f\x7f]/','_',$name);
 return ['source'=>$dest,'directory'=>$tmp,'filename'=>$name.'.'.$ext];
}

/** Only retire this task's isolated inbox after confirmed catalog publication. */
function sfmn_cleanup_published(array $job,?string $publicationId=null):void{
 $id=(string)$job['id'];if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new RuntimeException('Trabajo inválido');
 $publicationId=$publicationId??$id;if(!preg_match('/^[a-f0-9]{32}$/D',$publicationId))throw new RuntimeException('Publicación inválida');
 $q=sfq_db()->prepare('SELECT state,result_json FROM sf_processing_jobs WHERE id=?');$q->execute([$publicationId]);$row=$q->fetch(PDO::FETCH_ASSOC);$result=json_decode((string)($row['result_json']??''),true);
 if(($row['state']??'')!=='done'||empty($result['track']['id'])||empty($result['track']['hls']))throw new RuntimeException('Publicación no confirmada');
 $album=lab_load_album((string)$job['album_id']);$found=false;foreach($album['tracks']??[] as $t)if(($t['id']??'')===$result['track']['id']&&($t['hls']??'')===$result['track']['hls'])$found=true;
 if(!$found||!is_file(LAB_ROOT.'/'.$result['track']['hls']))throw new RuntimeException('Audio publicado no verificable');
 $root=realpath('/sparrowfy/inbox');$path='/sparrowfy/inbox/sf-'.$id;if(!file_exists($path))return;
 if(!$root||is_link($path)||realpath($path)!==$root.'/sf-'.$id)throw new RuntimeException('Carpeta inbox fuera del límite permitido');
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);$entries=[];
 foreach($it as $f){if($f->isLink()||!str_starts_with($f->getRealPath(),$root.'/sf-'.$id.'/'))throw new RuntimeException('Inbox contiene enlaces; limpieza aplazada');$entries[]=[$f->getPathname(),$f->isDir()];}
 foreach($entries as [$file,$dir])if(!($dir?rmdir($file):unlink($file)))throw new RuntimeException('No se pudo completar limpieza inbox');
 if(!rmdir($path))throw new RuntimeException('No se pudo retirar carpeta inbox');
 sfmn_event('inbox.cleaned.'.$id,'Fuente temporal retirada tras verificar la publicación en Sparrow-Fy.','info');
}
