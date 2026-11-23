<?php
declare(strict_types=1);
require __DIR__.'/lib.php';
lab_auth_require_album_manager();
if (($_GET['download'] ?? '') === 'windows') {
    $package = __DIR__ . '/scripts/SparrowFyWorker-Instalador.zip';
    if (!is_readable($package)) { http_response_code(404); exit; }
    session_write_close();
    header('Cache-Control: private, no-store');
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="SparrowFyWorker-Instalador.zip"');
    header('Content-Length: ' . filesize($package));
    readfile($package);
    exit;
}
require_once __DIR__.'/lib/user_preferences.php';
require_once __DIR__.'/lib/desktop_agent.php';
header('Cache-Control: no-store, private');header('Content-Type: text/html; charset=utf-8');
$account = lab_auth_current_account();
$admin = lab_auth_is_admin() && ($_GET['view'] ?? '') !== 'mine';
$embed = !empty($_GET['embed']);
$cfg = lab_load_config();
$db = lab_auth_pdo(); sfda_schema($db);
$message = ''; $error = ''; $pairCode = ''; $pairRequest = ''; 
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    lab_require_same_origin_post();
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'pair_code') {
            $pairRequest = (string)($_POST['request_id'] ?? '');
            $pairCode = sfda_pair_code($db, $account, $pairRequest);
            $message = 'Código generado. Caduca en cinco minutos y solo se puede utilizar una vez.';
        } elseif ($action === 'diagnostic') {
            $rid = (string)($_POST['request_id'] ?? '');
            $request = sfda_active_request($db, $rid);
            if (!$admin && $request['user_id'] !== $account['id']) throw new RuntimeException('Equipo ajeno.');
            if (!sfda_enabled()) throw new RuntimeException('Habilita primero los workers.');
            $q=$db->prepare('SELECT last_seen FROM sf_desktop_agents WHERE request_id=?');$q->execute([$rid]);$seen=(int)$q->fetchColumn();$q->closeCursor();
            if ($seen < time()-30) throw new RuntimeException('Inicia el agente en la PC antes de enviar la prueba.');
            $q=$db->prepare("SELECT count(*) FROM sf_desktop_tasks WHERE request_id=? AND state IN ('queued','leased','uploading','validating')");$q->execute([$rid]);$busy=(int)$q->fetchColumn();$q->closeCursor();if($busy)throw new RuntimeException('El equipo ya tiene una tarea pendiente.');
            $tmp=tempnam(sfda_root(),'diagnostic-');
            try { [$ffmpeg]=lab_ffmpeg(); [$code,$out]=lab_run(escapeshellarg($ffmpeg).' -nostdin -v error -y -f lavfi -i sine=frequency=440:sample_rate=48000:duration=12 -ac 2 -c:a flac -f flac '.escapeshellarg($tmp)); if($code!==0)throw new RuntimeException('No se pudo preparar el audio de prueba.');
                $task=sfda_enqueue($db,$rid,$tmp,[64,192,256],12,48000,4,'diagnostic');
                $message='Prueba enviada: '.$task.'. Actualiza la lista para ver el resultado. No se añadirá música a la biblioteca.';
            } finally { @unlink($tmp); }
        } elseif ($action === 'request') {
            if (empty($_POST['request_worker'])) throw new RuntimeException('Marca la opción para registrar este equipo como worker.');
            $pairRequest=sf_worker_request($db, $account, (string)($_POST['device_name'] ?? ''));
            if(sfda_enabled()) {
                $pairCode=sfda_pair_code($db,$account,$pairRequest);
                $message='Equipo registrado. Usa este código en tu PC para vincular el agente; no necesitas aprobación del administrador.';
            } else $message='Equipo registrado. El servicio de workers está desactivado globalmente; podrás vincularlo cuando vuelva a estar disponible.';
        } else {
            sf_worker_review($db, $account, (string)($_POST['request_id'] ?? ''), $action, !empty($cfg['desktop_workers_enabled']));
            $message = 'Equipo retirado. Su credencial quedó revocada.';
        }
        if ($pairCode === '') { $_SESSION['worker_access_notice'] = $message;
        header('Location: worker_access.php?view='.($admin?'admin':'mine').($embed?'&embed=1':''), true, 303); exit; }
    } catch (RuntimeException $e) { $error = $e->getMessage(); }
}
$message = (string)($_SESSION['worker_access_notice'] ?? $message); unset($_SESSION['worker_access_notice']);
$rows = sf_worker_list($db, $admin ? null : (string)$account['id']);
$theme = lab_user_theme((string)$account['id']);
$csrf = lab_auth_csrf_token();
?><!doctype html>
<html lang="es" data-theme="<?=lab_h($theme)?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Workers de escritorio · Sparrow-Fy</title><link rel="stylesheet" href="assets/lab.css?v=theme-sweep1"><style>.worker-wrap{max-width:1000px;margin:0 auto}.worker-row{padding:16px 0;border-bottom:1px solid var(--border);display:flex;gap:16px;align-items:center;justify-content:space-between;flex-wrap:wrap}.worker-actions{display:flex;gap:8px;flex-wrap:wrap}.worker-row p{margin:4px 0}.worker-table-wrap{overflow:auto;max-width:100%}.worker-table{width:100%;border-collapse:collapse;font-size:.85rem}.worker-table th,.worker-table td{padding:10px 8px;border-bottom:1px solid var(--border);text-align:left;vertical-align:top}.worker-table time,.worker-table code{white-space:nowrap}.worker-code{display:block;overflow:auto;white-space:pre;padding:12px;background:var(--bg);border-radius:8px}<?php if($embed):?>body>.lab-header{display:none}<?php endif;?></style><script defer src=assets/sf-language.js></script></head><body>
<header class="lab-header"><h1>Workers de escritorio</h1><a class="btn" href="<?=$admin?'config.php':'change_password.php'?><?=$embed?'?embed=1':''?>">Volver</a></header>
<main class="lab-main worker-wrap"><section class="card"><h2><?=$admin?'Equipos registrados':'Mi PC como worker'?></h2><p>Tu PC puede procesar tareas de audio para Sparrow-Fy con Python y FFmpeg. Extrae el ZIP, ejecuta Instalar-worker.cmd y vincula este equipo con su código temporal.</p><form method="get" action="worker_access.php"><input type="hidden" name="download" value="windows"><p>El instalador puede instalar Python y FFmpeg y registrar una tarea programada en Windows. Puedes revisar los archivos antes de ejecutarlos.</p><label><input type="checkbox" required> He leído la explicación y decido descargar este paquete para mi PC.</label> <button class="btn" type="submit">Descargar instalador para Windows</button></form><p>Para vincular el PC a otro servidor, abre PowerShell en la carpeta extraída y especifica la URL de esa instalación:</p><code>.\Instalar-worker.ps1 -ServerUrl https://TU-SERVIDOR/</code>
<?php if($message):?><p role="status"><?=lab_h($message)?></p><?php endif;?>
<?php if($error):?><p role="alert"><?=lab_h($error)?></p><?php endif;?>
<p>Servicio de workers: <strong><?=!empty($cfg['desktop_workers_enabled'])?'habilitado':'desactivado por el administrador'?></strong>.</p>
<p class="muted">El worker usa Python y FFmpeg para crear la escalera completa sin depender de las capacidades del navegador. Cada gestor puede registrar y vincular sus propias PC sin aprobación del administrador.</p>
<p role="note">Registra tu equipo y genera un código temporal desde tu propia cuenta gestora. Vincula el agente instalado en esa PC y mantenlo ejecutándose. Solo recibe tareas del gestor propietario. El servidor valida las calidades, integridad y sincronización antes de publicar; si el equipo falla, continúa en el servidor.</p>
<?php if($pairCode):?><section aria-label="Código temporal de vinculación"><h3>Vincular este equipo</h3><p>Guarda el código en un archivo local; no lo compartas. Caduca en cinco minutos.</p><code><?=lab_h($pairCode)?></code><pre class="worker-code">.\.venv\Scripts\python.exe .\agent.py pair --request <?=lab_h($pairRequest)?> --code-file .\private\pair-code.txt
.\.venv\Scripts\python.exe .\agent.py run</pre></section><?php endif;?>
<p><a class="btn" href="worker_access.php?view=<?=$admin?'admin':'mine'?><?=$embed?'&amp;embed=1':''?>">Actualizar estado</a></p>
<?php if($admin):?><p><a class="btn" href="worker_access.php?view=mine<?=$embed?'&amp;embed=1':''?>">Registrar mi propio equipo</a> <a class="btn" href="config.php<?=$embed?'?embed=1':''?>">Opciones globales</a></p><?php else:?>
<form method="post"><input type="hidden" name="csrf_token" value="<?=lab_h($csrf)?>"><input type="hidden" name="action" value="request">
<label>Nombre del equipo <input type="text" name="device_name" required maxlength="80" placeholder="Ej. PC de escritorio de casa" autocomplete="off"></label>
<p><label title="Identifica esta PC y vincúlala a tu cuenta gestora, sin aprobación del administrador. No instala programas ni abre puertos. La conversión requiere el agente instalado, vinculado y en ejecución."><input type="checkbox" name="request_worker" value="1" required> Registrar este PC como worker de Sparrow-Fy</label></p>
<button class="btn primary" type="submit" title="Tu permiso de gestor permite registrar y vincular esta PC directamente. El código identifica el equipo; no es una solicitud de aprobación.">Registrar y vincular equipo</button>
</form><?php endif;?>
<?php if(!$rows):?><p class="muted">No hay equipos registrados.</p><?php endif;?>
<?php foreach($rows as $row): $q=$db->prepare('SELECT last_seen,token_hash FROM sf_desktop_agents WHERE request_id=?');$q->execute([$row['id']]);$agent=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor(); ?><article class="worker-row"><div><strong><?=lab_h($row['device_name'])?></strong><p>Gestor: <?=lab_h($row['username'])?></p><p><?=lab_h($row['status']==='approved'&&!empty($agent['token_hash'])?(sf_worker_is_manager($row)?'Vinculado':'Cuenta sin permiso'):sf_worker_status_label($row))?><?php if(!empty($agent['last_seen'])):?> · <?=time()-(int)$agent['last_seen']<30?'Conectado':'Sin conexión'?> · última conexión <?=lab_h(gmdate('Y-m-d H:i:s',(int)$agent['last_seen']))?> UTC<?php endif;?></p><small>Registrado: <?=lab_h(gmdate('Y-m-d H:i', (int)$row['requested_at']))?> UTC</small></div><div class="worker-actions">
<?php $actions = [];
if(in_array($row['status'],['pending','approved'],true))$actions['revoke']='Retirar equipo';
if($row['status']==='approved'&&sf_worker_is_manager($row)){if($row['user_id']===$account['id'])$actions['pair_code']='Generar código de vinculación';if(!empty($agent['token_hash']))$actions['diagnostic']='Probar conversión';}
foreach($actions as $action=>$label): $help=['revoke'=>'Retira este equipo y revoca su credencial. Las tareas pendientes se recuperan en el servidor.','pair_code'=>'Genera un código de un solo uso válido durante cinco minutos. Al vincular de nuevo, la credencial anterior deja de servir.','diagnostic'=>'Envía audio sintético para comprobar transferencia, conversión y validación. No añade ni reemplaza pistas de la biblioteca.'][$action]; ?><form method="post"><input type="hidden" name="csrf_token" value="<?=lab_h($csrf)?>"><input type="hidden" name="request_id" value="<?=lab_h($row['id'])?>"><button class="btn" name="action" value="<?=lab_h($action)?>" <?=(in_array($action,['pair_code','diagnostic'],true)&&(empty($cfg['desktop_workers_enabled'])||!sf_worker_is_manager($row)))?'disabled':''?> title="<?=lab_h($help)?>"><?=lab_h($label)?></button></form><?php endforeach;?></div></article><?php endforeach;?>
<?php $ids=array_column($rows,'id');if($ids):$q=$db->prepare('SELECT e.id AS event_id,e.state AS event_state,e.created_at AS event_at,t.*,r.device_name,j.label FROM sf_worker_task_events e JOIN sf_desktop_tasks t ON t.id=e.task_id JOIN sf_worker_requests r ON r.id=t.request_id LEFT JOIN sf_processing_jobs j ON j.id=t.parent_id WHERE t.request_id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY e.created_at DESC,e.id DESC LIMIT 100');$q->execute($ids);$tasks=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();?><h3>Historial de los workers</h3><p class="muted">Más recientes primero. Hasta 100 eventos visibles y 1000 conservados. Los registros se controlan en Configuración → Diagnósticos.</p><div class="worker-table-wrap"><table class="worker-table"><thead><tr><th>Fecha y hora</th><th>Evento</th><th>Equipo</th><th>Pista</th><th>Estado</th><th>ID de tarea</th></tr></thead><tbody><?php foreach($tasks as $t):?><tr><td><time datetime="<?=gmdate('c',(int)$t['event_at'])?>"><?=lab_h(gmdate('Y-m-d H:i:s',(int)$t['event_at']))?> UTC</time></td><td><?=lab_h($t['kind']==='diagnostic'?'Prueba sin publicar':'Conversión de biblioteca')?></td><td><?=lab_h($t['device_name'])?></td><td><?=lab_h($t['label']?:'Prueba de conversión')?></td><td><?=lab_h(['queued'=>'Esperando PC','leased'=>'Convirtiendo en PC','uploading'=>'Recibiendo resultado','validating'=>'Validando en servidor','ready'=>'Resultado validado','consumed'=>'Incorporado a la conversión','failed'=>'Recuperado o fallido'][$t['event_state']]??$t['event_state'])?><?php if($t['event_state']==='failed'&&$t['error']):?><details><summary>Detalle</summary><?=lab_h($t['error'])?></details><?php endif;?></td><td><code><?=lab_h($t['id'])?></code></td></tr><?php endforeach;?><?php if(!$tasks):?><tr><td colspan="6">No hay eventos registrados.</td></tr><?php endif;?></tbody></table></div><?php endif;?>
</section><section class="card"><h2>Preparar una PC con Windows</h2><p>Ejecuta estos comandos en una terminal de Windows. El navegador no puede instalar paquetes ni iniciar un agente residente por sí solo.</p>
<pre class="worker-code">winget install --id Python.Python.3.14 --exact --source winget
winget install --id Gyan.FFmpeg --exact --source winget --scope user</pre>
<p>Si Python ya está instalado, puedes reutilizarlo. Cierra y vuelve a abrir la terminal después de instalar y comprueba:</p>
<pre class="worker-code">py -V
ffmpeg -version
ffprobe -version</pre>
<p>El agente deberá trabajar con una tarea simultánea y prioridad baja. La PC debe estar encendida y conectada para convertir; si pierde la conexión o caduca su reserva, el servidor conserva la fuente y continúa la conversión. Los resultados de reservas vencidas se rechazan.</p>
<p class="muted">En la PC ya preparada, desde la carpeta SparrowFyWorker:</p><pre class="worker-code">.\.venv\Scripts\python.exe .\worker.py doctor
.\.venv\Scripts\python.exe .\worker.py self-test</pre>
<p>La prueba genera audio sintético y verifica 64/192/256 kbps. No modifica la biblioteca. Esta PC ya tiene el agente. Para otras PC es necesario copiar también el paquete SparrowFyWorker; instalar Python y FFmpeg no instala el agente.</p>
</section></main><script src="assets/navigation.js?v=album-return1"></script><script>for(const el of document.querySelectorAll('time[datetime]'))el.textContent=new Date(el.dateTime).toLocaleString('es-MX');</script></body></html>
