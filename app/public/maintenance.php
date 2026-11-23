<?php
require __DIR__.'/lib.php';require_once __DIR__.'/lib/user_preferences.php';lab_auth_require_admin();
$sfTheme=lab_user_theme((string)(lab_auth_current_account()['id']??''));
header('Cache-Control: no-store');
if(isset($_GET['hls_low_status'])){
 header('Content-Type: application/json');
 $v=json_decode((string)@file_get_contents(__DIR__.'/data/processing_jobs/hls-low-state.json'),true)?:['state'=>'idle','repaired'=>0,'failed'=>0,'found'=>0];
 $handle=fopen(__DIR__.'/data/repair-local-low.lock','c');$active=$handle&&!flock($handle,LOCK_EX|LOCK_NB);
 if($handle){if(!$active)flock($handle,LOCK_UN);fclose($handle);}
 if(($v['state']??'')==='running'&&!$active)$v['state']='interrupted';
 echo json_encode($v,JSON_UNESCAPED_UNICODE);exit;
}

$embed=!empty($_GET['embed'])||!empty($_POST['embed']);
$settingsFile=__DIR__.'/data/maintenance-settings.json';
$settings=json_decode((string)@file_get_contents($settingsFile),true)?:['primary'=>__DIR__.'/backups','secondary'=>''];
$notice=(string)($_SESSION['maintenance_notice']??'');$queuedAt=(float)($_SESSION['maintenance_queued_at']??0);unset($_SESSION['maintenance_notice'],$_SESSION['maintenance_queued_at']);$formError='';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
 try{
  $action=(string)($_POST['action']??'');
  if($action==='hls_low'){
   $lock=fopen(__DIR__.'/data/repair-local-low.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Ya se están generando las calidades bajas');flock($lock,LOCK_UN);fclose($lock);
   $cmd='nohup nice -n 10 '.escapeshellarg(PHP_BINDIR.'/php').' '.escapeshellarg(__DIR__.'/scripts/repair_local_hls_low.php').' --apply > '.escapeshellarg(__DIR__.'/data/processing_jobs/hls-low.log').' 2>&1 < /dev/null &';
   exec($cmd,$output,$exit);if($exit!==0)throw new RuntimeException('No se pudo iniciar');
   $notice='Búsqueda y generación de calidades bajas iniciada. Puedes cerrar este panel; continuará en el servidor.';
  }elseif($action==='configure'){
   $args=['/usr/bin/python3',__DIR__.'/scripts/maintenance.py','configure','--primary',(string)($_POST['primary']??''),'--secondary',(string)($_POST['secondary']??'')];
   $proc=proc_open($args,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__);
   if(!is_resource($proc))throw new RuntimeException('No se pudo guardar');
   $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($proc);
   if($code!==0){$lines=explode("\n",trim($err));throw new RuntimeException((string)end($lines));}
   $notice='Destinos guardados. Los respaldos anteriores permanecen en su ubicación original.';
   $settings=json_decode((string)file_get_contents($settingsFile),true);
  }elseif(in_array($action,['backup','audit','verify','restore','quarantine','quarantine_restore','quarantine_delete'],true)){
   $lock=fopen(__DIR__.'/data/.maintenance-global.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Ya hay una tarea de mantenimiento en curso');flock($lock,LOCK_UN);fclose($lock);
   $args=['/usr/bin/python3',__DIR__.'/scripts/maintenance.py',$action];
   if(in_array($action,['verify','restore'],true)){
    $snapshot=(string)($_POST['snapshot']??'');
    if(!preg_match('/^[0-9]{8}T[0-9]{12}Z$/D',$snapshot))throw new RuntimeException('Selecciona un respaldo');
    $args[]='--snapshot';$args[]=$snapshot;
   }
   if(in_array($action,['quarantine','quarantine_restore','quarantine_delete'],true)){
    $item=(string)($_POST['item']??'');if($item===''||strlen($item)>1500)throw new RuntimeException('Elemento inválido');
    if($action!=='quarantine'&&!preg_match('/^[a-f0-9]{32}$/D',$item))throw new RuntimeException('Identificador inválido');
    $args[]='--item';$args[]=$item;
    if($action==='quarantine_delete'){
     if(($_POST['tested']??'')!=='1'||($_POST['confirmation']??'')!=='ELIMINAR')throw new RuntimeException('Confirma que probaste las pistas y escribe ELIMINAR');
     $args[]='--confirmed';
    }
   }
   $command='nohup '.implode(' ',array_map('escapeshellarg',$args)).' > '.escapeshellarg(__DIR__.'/data/maintenance-job.log').' 2>&1 < /dev/null &';
   exec($command,$output,$exit);if($exit!==0)throw new RuntimeException('No se pudo iniciar la tarea');
   $notice='Tarea enviada. El estado se actualizará al comenzar y terminar.';
  }else throw new RuntimeException('Acción inválida');
  if($notice!==''){
   lab_auth_session_start();$_SESSION['maintenance_notice']=$notice;
   if(!in_array($action,['configure','hls_low'],true))$_SESSION['maintenance_queued_at']=microtime(true);
   header('Location: maintenance.php'.($embed?'?embed=1':''),true,303);exit;
  }
 }catch(Throwable $e){$formError=$e->getMessage();}
}
$backupRoot=(string)$settings['primary'];
function sf_read_report($name){global $backupRoot;$v=json_decode((string)@file_get_contents($backupRoot.'/'.$name),true);return is_array($v)?$v:[];}
$state=json_decode((string)@file_get_contents(__DIR__.'/data/maintenance-state.json'),true)?:[];
if(($_GET['status']??'')==='1'){header('Content-Type: application/json');echo json_encode($state);exit;}
$success=sf_read_report('last-success.json');$error=sf_read_report('last-error.json');$audit=sf_read_report('latest-audit.json');$secondary=sf_read_report('secondary-status.json');
$snapshots=[];foreach(glob($backupRoot.'/snapshots/*/manifest.json')?:[] as $f){$id=basename(dirname($f));if(preg_match('/^[0-9]{8}T[0-9]{12}Z$/D',$id))$snapshots[]=$id;}rsort($snapshots);
$quarantine=json_decode((string)@file_get_contents($backupRoot.'/quarantine/index.json'),true)?:[];
$csrf=lab_h(lab_auth_csrf_token());
$labels=['possible_orphan_media'=>'Posible audio sin referencia','missing_manifest'=>'Manifiesto faltante','missing_segment'=>'Segmento faltante','broken_collection_reference'=>'Referencia de colección rota','broken_track_references'=>'Referencias a pistas inexistentes','sqlite_integrity'=>'Integridad de SQLite','foreign_key'=>'Relación de base de datos inválida'];
?><!doctype html><html lang="es" data-theme="<?=lab_h($sfTheme)?>" data-theme-source="server"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Respaldos e integridad · Sparrow-Fy</title><link rel="stylesheet" href="assets/lab.css?v=theme-sweep1"><style>.maintenance-header{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:20px}.maintenance-header h1{margin:0}.maintenance-header .btn{white-space:nowrap}@media(max-width:600px){.maintenance-header{align-items:flex-start}.maintenance-header h1{font-size:1.35rem}}</style><meta name="sf-session-account" content="<?= htmlspecialchars((string)(lab_auth_current_account()['id']??''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><script src="assets/session-sync.js?v=auth-poll3" defer></script>
<meta name="sf-save-result" content="<?= $formError!==''?'error':(str_starts_with($notice,'Destinos guardados.')?'success':'') ?>"><link rel="stylesheet" href="assets/save-feedback.css?v=2"><script src="assets/save-feedback.js?v=2"></script><script defer src=assets/sf-language.js></script></head><body><main class="lab-main" style="max-width:1000px;margin:auto"><section class="card"><header class="maintenance-header"><h1>Respaldos e integridad</h1><?php if(!$embed): ?><a class="btn" href="config.php<?= $embed ? '?embed=1' : '' ?>">← Volver atrás</a><?php endif; ?></header>
<?php if($notice):?><p role="status"><?=lab_h($notice)?></p><?php endif;?><?php if($formError):?><p role="alert"><?=lab_h($formError)?></p><?php endif;?>
<p id="maintenance-state" role="status"><?=lab_h((string)($state['message']??(($state['state']??'')==='running'?'Tarea en curso…':'')))?></p>
<form method="post" style="display:grid;gap:12px"><input type="hidden" name="csrf_token" value="<?=$csrf?>"><input type="hidden" name="action" value="configure">
<label>Carpeta principal<input style="width:100%;box-sizing:border-box" name="primary" value="<?=lab_h($settings['primary'])?>" required></label>
<label>Segunda copia (opcional)<input style="width:100%;box-sizing:border-box" name="secondary" value="<?=lab_h($settings['secondary'])?>" placeholder="Ruta de otra unidad montada"></label>
<small>Las carpetas deben existir dentro del contenedor www, permitir escritura a www-data y tener grupo www-data y permisos 2770. Para una segunda copia independiente, utiliza otro disco montado. La segunda copia conserva sus versiones anteriores; revisa su espacio disponible. Cambiar la ruta no mueve los respaldos anteriores.</small><button class="btn" type="submit">Guardar destinos</button></form>
<hr><form method="post" style="display:flex;gap:10px;flex-wrap:wrap"><input type="hidden" name="csrf_token" value="<?=$csrf?>"><button class="btn primary" name="action" value="backup">Generar respaldo ahora</button><button class="btn" name="action" value="audit">Ejecutar auditoría</button></form>
<h3>Restauración</h3><p>Comprueba una copia o reconstruye sus archivos en una carpeta aislada. La biblioteca activa permanece en servicio.</p>
<form method="post" style="display:flex;gap:10px;flex-wrap:wrap"><input type="hidden" name="csrf_token" value="<?=$csrf?>"><select name="snapshot" required aria-label="Respaldo a restaurar"><?php foreach($snapshots as $id):?><option value="<?=lab_h($id)?>"><?=lab_h($id)?></option><?php endforeach;?></select><button class="btn" name="action" value="verify" <?=!$snapshots?'disabled':''?>>Verificar restauración</button><button class="btn" name="action" value="restore" <?=!$snapshots?'disabled':''?>>Restaurar en carpeta aislada</button></form>
<?php if(!empty($settings['secondary'])):?><p>Segunda copia: <?=lab_h((string)($secondary['state']??'Pendiente'))?> <?=lab_h((string)($secondary['error']??''))?></p><?php endif;?>
<p>Respaldo diario a las 04:17, hora de Ciudad de México. Se conservan siete versiones.</p>
<?php if($success): ?><p>Último respaldo verificado: <strong><?=lab_h((new DateTimeImmutable('@'.(int)$success['time']))->setTimezone(new DateTimeZone('America/Mexico_City'))->format('d/m/Y H:i'))?></strong></p><p>Restauración aislada y comprobación de archivos: completadas.</p><?php else: ?><p>El primer respaldo todavía no tiene una restauración verificada.</p><?php endif; ?>
<?php if($error && empty($error['resolved_at']) && in_array($error['action']??($state['action']??''),['backup','auto','verify','restore'],true) && (float)($error['time']??0)>(float)($success['time']??0)): ?><p role="alert">El último intento de respaldo o verificación requiere revisión: <?=lab_h((string)$error['error'])?></p><?php endif; ?>
<p>Estas copias están en el mismo servidor. Para protegerte ante una falla del disco, conserva también una copia completa en otro dispositivo.</p></section><section class="card"><h2>Trenes de calidad HLS</h2><p>Completa el tren de calidades de todas las melodías, asignadas a un álbum o sueltas. Añade las calidades de la escalera configurada que falten por debajo de la mejor calidad existente, incluida 64 kbps, conserva las calidades originales y procesa una pista a la vez.</p><form method="post"><input type="hidden" name="csrf_token" value="<?=$csrf?>"><button class="btn" name="action" value="hls_low">Completar trenes de calidad</button></form><p id="hlsLowStatus" role="status" aria-live="polite">Consultando estado…</p><script src="assets/hls-low-panel.js?v=3" defer></script></section><section class="card"><h2>Auditoría de integridad</h2><p>Pistas revisadas: <?=(int)($audit['tracks']??0)?> · Hallazgos: <?=count($audit['issues']??[])?></p><p>La auditoría informa; no elimina audio ni modifica referencias.</p>
<?php foreach($audit['issues']??[] as $issue): ?><article style="padding:12px 0;border-bottom:1px solid var(--border)"><strong><?=lab_h($labels[$issue['kind']]??$issue['kind'])?></strong><p style="overflow-wrap:anywhere"><?=lab_h((string)($issue['path']??$issue['track']??$issue['table']??''))?></p>
<?php if($issue['kind']==='possible_orphan_media'):?><form method="post"><input type="hidden" name="csrf_token" value="<?=$csrf?>"><input type="hidden" name="action" value="quarantine"><input type="hidden" name="item" value="<?=lab_h($issue['path'])?>"><button class="btn" type="submit">Enviar a cuarentena</button></form><?php endif;?></article><?php endforeach; ?>
<?php if(!empty($audit['retained_media'])): ?><details><summary>Versiones anteriores conservadas: <?=count($audit['retained_media'])?></summary><p>Fuentes anteriores de reemplazos con una pista vigente válida. No son fallos de integridad; puedes apartarlas para probar la versión actual.</p><?php foreach($audit['retained_media'] as $previous): ?><article style="padding:10px 0;border-bottom:1px solid var(--border)"><p style="overflow-wrap:anywhere"><?=lab_h($previous['path'])?></p><form method="post"><input type="hidden" name="csrf_token" value="<?=$csrf?>"><input type="hidden" name="action" value="quarantine"><input type="hidden" name="item" value="<?=lab_h($previous['path'])?>"><button class="btn" type="submit">Enviar a cuarentena</button></form></article><?php endforeach; ?></details><?php endif; ?>
</section><section class="card"><h2>Audio en cuarentena</h2><p>Prueba las canciones vigentes del catálogo antes de eliminar las versiones apartadas. La webapp no puede comprobar por sí sola lo que escuchaste.</p>
<?php $visible=0;foreach($quarantine as $item):if(!in_array($item['state'],['quarantined','copied'],true))continue;$visible++;?>
<article style="padding:16px 0;border-bottom:1px solid var(--border)"><strong style="overflow-wrap:anywhere"><?=lab_h($item['original'])?></strong><p><?=count($item['files'])?> archivos · <?=lab_h($item['state']==='copied'?'Copia conservada; traslado incompleto, requiere revisión':'Apartado del catálogo')?></p>
<?php if($item['state']==='quarantined'):?>
<form method="post"><input type="hidden" name="csrf_token" value="<?=$csrf?>"><input type="hidden" name="item" value="<?=lab_h($item['id'])?>"><button class="btn" name="action" value="quarantine_restore">Devolver a su ubicación</button></form>
<details style="margin-top:12px"><summary>Eliminar definitivamente…</summary><form method="post" style="display:grid;gap:10px;margin-top:12px"><input type="hidden" name="csrf_token" value="<?=$csrf?>"><input type="hidden" name="item" value="<?=lab_h($item['id'])?>"><input type="hidden" name="action" value="quarantine_delete"><label><input type="checkbox" name="tested" value="1" required> Probé las pistas vigentes y se reproducen correctamente.</label><label>Escribe ELIMINAR para confirmar <input name="confirmation" required pattern="ELIMINAR" autocomplete="off"></label><small>Elimina esta copia de cuarentena. Los respaldos anteriores conservan su propia retención.</small><button class="btn" type="submit">Eliminar copia definitivamente</button></form></details>
<?php endif;?></article><?php endforeach;if(!$visible):?><p>No hay elementos pendientes en cuarentena.</p><?php endif;?><p>Devolver una carpeta restaura sus archivos; no crea una pista en el catálogo.</p></section></main><script src="assets/maintenance-panel.js?v=2" data-submitted-at="<?=lab_h((string)$queuedAt)?>" data-state-version="<?=lab_h(json_encode($state['finished']??$state['started']??0,JSON_PRESERVE_ZERO_FRACTION))?>"></script></body></html>
