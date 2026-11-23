<?php
declare(strict_types=1);
// Dedicated Bearer endpoint: no browser session, library browsing, or arbitrary file access.
require __DIR__.'/auth.php';
require __DIR__.'/lib/desktop_agent.php';
header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');
$db=lab_auth_pdo();sfda_schema($db);
try {
    if(!lab_auth_is_https())throw new RuntimeException('El agente requiere HTTPS.',403);
    if(isset($_SERVER['HTTP_ORIGIN']))throw new RuntimeException('Usa el agente de escritorio, no peticiones desde otra página.',403);
    $action=(string)($_GET['action']??'');$get=($_SERVER['REQUEST_METHOD']??'')==='GET';
    if($get&&$action!=='source'||!$get&&($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new RuntimeException('Método no permitido.',405);
    $input=[];
    if(!$get&&$action!=='file'){
        $raw=file_get_contents('php://input',false,null,0,2097153);
        if(strlen($raw)>2097152)throw new RuntimeException('Solicitud demasiado grande.',413);
        $input=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($input))throw new RuntimeException('JSON inválido.');
    }
    if($action==='pair'){
        $result=sfda_pair($db,(string)($input['request_id']??''),(string)($input['code']??''));
    }else{
        $request=(string)($_SERVER['HTTP_X_SF_WORKER']??'');$authorization=(string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');
        if($authorization===''&&function_exists('getallheaders'))foreach(getallheaders() as $name=>$value)if(strtolower($name)==='authorization')$authorization=(string)$value;
        if(!preg_match('/^Bearer ([a-f0-9]{64})$/D',$authorization,$m))throw new RuntimeException('Credencial requerida.',401);
        sfda_auth($db,$request,$m[1]);
        if(in_array($action,['claim','heartbeat'],true))sfda_save_metrics($request,(array)($input['resources']??[]));
        if($action==='claim')$result=sfda_claim($db,$request,(array)($input['capabilities']??[]),(string)($input['version']??''));
        else{
            $id=(string)($_GET['task']??$input['task_id']??'');$lease=(string)($_SERVER['HTTP_X_SF_LEASE']??$input['lease']??'');
            if($action==='heartbeat'){
                sfda_task($db,$request,$id,$lease);
                $db->prepare("UPDATE sf_desktop_tasks SET lease_until=?,updated_at=? WHERE id=? AND request_id=? AND lease_hash=? AND state IN ('leased','uploading','validating')")->execute([time()+90,time(),$id,$request,hash('sha256',$lease)]);
                $db->prepare('UPDATE sf_desktop_agents SET last_seen=? WHERE request_id=?')->execute([time(),$request]);$result=['ok'=>true];
            }elseif($action==='source'){
                $task=sfda_task($db,$request,$id,$lease);$path=sfda_root().'/'.$id.'/source.audio';
                if($path!==$task['source']||!is_file($path)||is_link($path))throw new RuntimeException('Fuente no disponible.');
                header('Content-Type: application/octet-stream');header('Content-Length: '.filesize($path));$stream=fopen($path,'rb');fpassthru($stream);fclose($stream);exit;
            }elseif($action==='manifest'){
                $db->exec('BEGIN IMMEDIATE');
                try{$task=sfda_task($db,$request,$id,$lease);if($task['state']==='validating')throw new RuntimeException('El resultado se está validando.',409);
                    $files=(array)($input['files']??[]);unset($files['master.m3u8']);sfda_manifest($task,$files);
                    if($task['manifest']&&json_decode($task['manifest'],true)!=$files)throw new RuntimeException('El resultado cambió durante la transferencia.',409);
                    $output=sfda_root().'/'.$id.'/output';if(!is_dir($output))mkdir($output,02770);
                    foreach(json_decode($task['rates'],true) as $rate)if(!is_dir($output.'/'.$rate.'k'))mkdir($output.'/'.$rate.'k',02770);
                    $db->prepare("UPDATE sf_desktop_tasks SET manifest=?,state='uploading',updated_at=? WHERE id=?")->execute([json_encode($files),time(),$id]);$db->exec('COMMIT');$result=['ok'=>true];
                }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
            }elseif($action==='file'){
                $data=file_get_contents('php://input',false,null,0,2097153);if(strlen($data)>2097152)throw new RuntimeException('Segmento demasiado grande.',413);
                $db->exec('BEGIN IMMEDIATE');
                try{$task=sfda_task($db,$request,$id,$lease);if($task['state']!=='uploading')throw new RuntimeException('Falta el manifiesto de transferencia.',409);
                    $name=(string)($_GET['name']??'');$files=json_decode($task['manifest'],true);$info=$files[$name]??null;
                    if(!$info||strlen($data)!==$info['bytes']||!hash_equals($info['sha256'],hash('sha256',$data)))throw new RuntimeException('Integridad del archivo incorrecta.');
                    $path=sfda_root().'/'.$id.'/output/'.$name;
                    if(file_put_contents($path.'.part',$data,LOCK_EX)!==strlen($data)||!rename($path.'.part',$path))throw new RuntimeException('No se pudo guardar el segmento.');
                    $db->prepare('UPDATE sf_desktop_tasks SET lease_until=?,updated_at=? WHERE id=?')->execute([time()+90,time(),$id]);$db->exec('COMMIT');$result=['ok'=>true];
                }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
            }elseif($action==='complete'){
                $task=sfda_task($db,$request,$id,$lease);
                $q=$db->prepare("UPDATE sf_desktop_tasks SET state='validating',lease_until=?,updated_at=? WHERE id=? AND state='uploading' AND lease_hash=?");$q->execute([time()+90,time(),$id,hash('sha256',$lease)]);if($q->rowCount()!==1)throw new RuntimeException('El resultado ya está siendo validado.',409);
                try{$result=sfda_validate($task);
                    $q=$db->prepare("UPDATE sf_desktop_tasks SET state='ready',updated_at=? WHERE id=? AND state='validating' AND lease_hash=?");$q->execute([time(),$id,hash('sha256',$lease)]);if($q->rowCount()!==1)throw new RuntimeException('El servidor ya recuperó esta tarea.',409);
                }catch(Throwable $e){$db->prepare("UPDATE sf_desktop_tasks SET state='failed',error=?,updated_at=? WHERE id=? AND state='validating'")->execute([substr($e->getMessage(),0,300),time(),$id]);throw $e;}
            }elseif($action==='failed'){
                sfda_task($db,$request,$id,$lease);$db->prepare("UPDATE sf_desktop_tasks SET state='failed',error=?,updated_at=? WHERE id=? AND lease_hash=? AND state IN ('leased','uploading')")->execute([substr((string)($input['error']??'Falló el agente'),0,300),time(),$id,hash('sha256',$lease)]);$result=['ok'=>true];
            }else throw new RuntimeException('Acción no permitida.');
        }
    }
    header('Content-Type: application/json; charset=utf-8');echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(Throwable $e){$code=(int)$e->getCode();http_response_code(in_array($code,[401,403,405,409,413],true)?$code:400);header('Content-Type: application/json; charset=utf-8');echo json_encode(['error'=>$e instanceof JsonException?'JSON inválido.':$e->getMessage()],JSON_UNESCAPED_UNICODE);}
