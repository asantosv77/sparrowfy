<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
require_once __DIR__ . '/lib/user_preferences.php';
lab_auth_require_admin();
$cfg = lab_load_config();
$sfTheme = lab_user_theme((string)(lab_auth_current_account()['id'] ?? ''));
$msg = '';
$embed = !empty($_GET['embed']) || !empty($_POST['embed']);
require_once __DIR__.'/lib/album_deletion.php';
$sfPendingDeletes=sf_delreq_pending();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    lab_require_same_origin_post();
    try {
    $cfg['csrf_protection_enabled'] = true;
    $cfg['worker_diagnostics_enabled'] = !empty($_POST['worker_diagnostics_enabled']);
    $cfg['media_operations_log_enabled'] = !empty($_POST['media_operations_log_enabled']);
    foreach(['enabled','record','console'] as $debugFlag)$cfg['playback_diagnostics_'.$debugFlag]=!empty($_POST['playback_diagnostics_'.$debugFlag]);
    $cfg['playback_diagnostics_days']=max(1,min(30,(int)($_POST['playback_diagnostics_days']??7)));
    $cfg['remember_days'] = max(1,min(365,(int)($_POST['remember_days']??30)));
    $cfg['delete_source_after_convert'] = !empty($_POST['delete_source_after_convert']);
    $cfg['lrclib_enabled'] = !empty($_POST['lrclib_enabled']);
    $cfg['webcodecs_enabled'] = !empty($_POST['webcodecs_enabled']);
    $cfg['desktop_workers_enabled'] = !empty($_POST['desktop_workers_enabled']);
    $cfg['sf_jobs_global_limit'] = max(1, min(4, (int) ($_POST['sf_jobs_global_limit'] ?? 1)));
    $cfg['sf_jobs_conversion_limit'] = max(1, min(4, (int) ($_POST['sf_jobs_conversion_limit'] ?? 1)));
    $cfg['sf_jobs_edit_limit'] = max(1, min(4, (int) ($_POST['sf_jobs_edit_limit'] ?? 1)));
    $cfg['sf_jobs_ffmpeg_threads'] = max(1, min(4, (int) ($_POST['sf_jobs_ffmpeg_threads'] ?? 2)));
    $cfg['sf_jobs_protect_resources'] = !empty($_POST['sf_jobs_protect_resources']);
    $cfg['segment_seconds'] = max(2, min(12, (int) ($_POST['segment_seconds'] ?? 4)));
    // El perfil HLS ya no es global: cada cuenta lo guarda en user_preferences.
    unset($cfg['hls_profile']);
    require_once __DIR__.'/lib/quality_profiles.php';
    $cfg['quality_ladder_mode']=($_POST['quality_ladder_mode']??'profiles')==='legacy'?'legacy':'profiles';
    $cfg['quality_source_profiles']=sf_quality_profiles_normalize((array)($_POST['quality_source_profiles']??[]));
    $cfg['max_upload_mib'] = max(10, min(250, (int) ($_POST['max_upload_mib'] ?? 120)));
    $mem = (int) ($_POST['client_memory_mib'] ?? 0);
    $cfg['client_memory_mib'] = in_array($mem, [0, 128, 192, 256, 384, 512, 640], true) ? $mem : 0;
    $newAcoustid=trim((string)($_POST['acoustid_client_key']??''));
    if($newAcoustid!=='' && strlen($newAcoustid)<=256 && !preg_match('/[\x00-\x20\x7f]/',$newAcoustid))$cfg['acoustid_client_key']=$newAcoustid;
    $newMx = trim((string) ($_POST['musixmatch_api_key'] ?? ''));
    $newGenius = trim((string) ($_POST['genius_access_token'] ?? ''));
    if ($newMx !== '') $cfg['musixmatch_api_key'] = $newMx;
    if ($newGenius !== '') $cfg['genius_access_token'] = $newGenius;
    $ext = preg_split('/[\s,;]+/', (string) ($_POST['allowed_extensions'] ?? 'mp3,flac,ogg,wma,m4a,wav')) ?: [];
    $cfg['allowed_extensions'] = array_values(array_intersect(lab_safe_audio_extensions(), array_unique(array_map('strtolower', $ext))));
    if (!$cfg['allowed_extensions']) $cfg['allowed_extensions'] = lab_default_config()['allowed_extensions'];
    require_once __DIR__.'/lib/desktop_agent.php';$workerDb=lab_auth_pdo();sfda_schema($workerDb);$workerDb->prepare('UPDATE sf_worker_log_options SET enabled=? WHERE id=1')->execute([!empty($cfg['worker_diagnostics_enabled'])?1:0]);
    lab_save_config($cfg);
    $cfg = lab_load_config();
    $msg = 'Configuración guardada.';
    }catch(InvalidArgumentException $e){$cfg=lab_load_config();$msg=$e->getMessage();}
}
?><!DOCTYPE html>
<html lang="es" data-theme="<?= lab_h($sfTheme) ?>" data-theme-source="server">
<head>
  <meta charset="utf-8">
  <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
  <meta name="application-name" content="HLS Music Lab">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Configuración · HLS Music Lab</title>
  <script>try{document.documentElement.dataset.theme=<?= json_encode($sfTheme) ?>}catch(e){document.documentElement.dataset.theme=<?= json_encode($sfTheme) ?>}</script>
<link rel="stylesheet" href="assets/lab.css?v=theme-sweep1">
<link rel="stylesheet" href="assets/admin-settings.css?v=aligned2">
<meta name="sf-player-account" content="<?= htmlspecialchars((string)(lab_auth_current_account()['id']??''), ENT_QUOTES, 'UTF-8') ?>"><meta name="sf-session-account" content="<?= htmlspecialchars((string)(lab_auth_current_account()['id']??''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><script src="assets/session-sync.js?v=auth-poll3" defer></script>
<meta name="sf-save-result" content="<?= $msg==='Configuración guardada.'?'success':($msg!==''?'error':'') ?>"><link rel="stylesheet" href="assets/save-feedback.css?v=2"><script src="assets/save-feedback.js?v=2"></script><script defer src=assets/sf-language.js></script></head>
<body class="<?= !empty($embed) ? 'config-embed' : '' ?>">
  <?php if (empty($embed)): ?>
  <header class="lab-header">
    <div>
      <h1>Configuración</h1>
      <p class="muted">Preferencias en <code>data/config.json</code> (sin base de datos)</p>
    </div>
    <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
      <a class="btn" href="index.php?view=library">Inicio</a>
      <form method="post" action="logout.php" style="display:inline;margin:0"><input type="hidden" name="csrf_token" value="<?= lab_h(lab_auth_csrf_token()) ?>"><button class="btn" type="submit">Cerrar sesión</button></form>
    </div>
  </header>
  <?php endif; ?>
  <main class="lab-main">
    <?php if (empty($embed)): ?>
    <section class="card account-management-entry">
      <h2>Usuarios y permisos</h2>
      <p class="muted">Crea cuentas, activa o desactiva usuarios y decide quién puede únicamente escuchar música y quién puede gestionar la biblioteca.</p>
      <a class="btn primary" href="admin_users.php">Administrar cuentas y permisos</a>
    </section>

    <?php endif; ?>
    <?php if ($msg): ?><p class="ok"><?= lab_h($msg) ?></p><?php endif; ?>
    <form class="sf-settings-form" method="post" action="config.php<?= !empty($embed) ? '?embed=1' : '' ?>"><input type="hidden" name="csrf_token" value="<?= lab_h(lab_auth_csrf_token()) ?>">
      <?php if (!empty($embed)): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
<div class="sf-settings-layout"><section class="sf-settings-section"><h2>Reproducción y calidad HLS</h2><div class="sf-settings-card">      <h3>Calidades HLS</h3>
<?php require_once __DIR__.'/lib/quality_profiles.php';$profiles=sf_quality_profiles_normalize((array)($cfg['quality_source_profiles']??[])); ?>
<label>Modo de selección <select name="quality_ladder_mode"><option value="profiles" <?=($cfg['quality_ladder_mode']??'profiles')==='profiles'?'selected':''?>>Perfiles según archivo de origen</option><option value="legacy" <?=($cfg['quality_ladder_mode']??'')==='legacy'?'selected':''?>>Escalera automática anterior</option></select></label>
<p class="muted">Todos los trenes son AAC y se generan juntos y alineados. Fuentes con pérdida: nunca superar el bitrate medido. FLAC, ALAC y PCM se reconocen por códec. Valores intermedios: perfil inferior.</p>
<div class="sf-quality-profiles-wrap"><table class="sf-quality-profiles"><thead><tr><th scope="col">Origen</th><th scope="col">Tren 1</th><th scope="col">Tren 2</th><th scope="col">Tren 3</th></tr></thead><tbody>
<?php foreach($profiles as $origin=>$rates):$max=$origin==='lossless'?256:min(256,(int)$origin); ?><tr><th scope="row"><?= $origin==='lossless'?'Sin pérdida (FLAC / ALAC / PCM)':lab_h((string)$origin).' kbps'.($origin==320?' o superior':'') ?></th><?php for($i=0;$i<3;$i++): ?><td><select aria-label="<?=lab_h((string)$origin)?> tren <?=$i+1?>" name="quality_source_profiles[<?=lab_h((string)$origin)?>][]"><?php foreach([0,32,48,64,96,128,160,192,256] as $k):if($k>$max)continue; ?><option value="<?=$k?>" <?=($rates[$i]??0)===$k?'selected':''?>><?=$k?$k.' kbps':'No generar'?></option><?php endforeach; ?></select></td><?php endfor; ?></tr><?php endforeach; ?></tbody></table></div>
<p class="muted">64 kbps es obligatorio; sin duplicados. Aplica a nuevas conversiones, recortes y reemplazos. Guardar no modifica pistas existentes. El modo anterior conserva sus parámetros previos.</p>
</div></section><section class="sf-settings-section"><h2>Cola general de procesamiento <small>Todos los usuarios</small></h2><div class="sf-settings-card">
      <p class="muted">Los archivos subidos, las importaciones de YouTube y las ediciones comparten una cola persistente. El límite global siempre prevalece sobre los límites por tipo. Predeterminado para Raspberry Pi 4B: 1 trabajo global, 1 conversión y 1 edición.</p>
<div class="sf-settings-fields four sf-processing-limits">      <div class="sf-setting"><label for="sf_jobs_global_limit">Máximo global de trabajos intensivos simultáneos</label>
      <select id="sf_jobs_global_limit" name="sf_jobs_global_limit"><?php for($n=1;$n<=4;$n++): ?><option value="<?= $n ?>" <?= (int)($cfg['sf_jobs_global_limit']??1)===$n?'selected':'' ?>><?= $n ?></option><?php endfor; ?></select></div>
      <div class="sf-setting"><label for="sf_jobs_conversion_limit">Máximo de extracciones/conversiones HLS simultáneas</label>
      <select id="sf_jobs_conversion_limit" name="sf_jobs_conversion_limit"><?php for($n=1;$n<=4;$n++): ?><option value="<?= $n ?>" <?= (int)($cfg['sf_jobs_conversion_limit']??1)===$n?'selected':'' ?>><?= $n ?></option><?php endfor; ?></select></div>
      <div class="sf-setting"><label for="sf_jobs_edit_limit">Máximo de ediciones (recorte/fade) simultáneas</label>
      <select id="sf_jobs_edit_limit" name="sf_jobs_edit_limit"><?php for($n=1;$n<=4;$n++): ?><option value="<?= $n ?>" <?= (int)($cfg['sf_jobs_edit_limit']??1)===$n?'selected':'' ?>><?= $n ?></option><?php endfor; ?></select></div>
      <div class="sf-setting"><label for="sf_jobs_ffmpeg_threads">Hilos FFmpeg por proceso (orientativo)</label>
      <select id="sf_jobs_ffmpeg_threads" name="sf_jobs_ffmpeg_threads"><?php for($n=1;$n<=4;$n++): ?><option value="<?= $n ?>" <?= (int)($cfg['sf_jobs_ffmpeg_threads']??2)===$n?'selected':'' ?>><?= $n ?></option><?php endfor; ?></select></div>
</div>      <label class="check"><input type="checkbox" name="sf_jobs_protect_resources" value="1" <?= !array_key_exists('sf_jobs_protect_resources',$cfg)||!empty($cfg['sf_jobs_protect_resources'])?'checked':'' ?>> Proteger recursos del servidor antes de iniciar nuevos trabajos (temperatura, carga, memoria)</label>
      <p class="muted">Los cambios se aplican en el siguiente ciclo del worker; no se interrumpen tareas activas. Requiere cron de un minuto o servicio supervisor. El límite de hilos se aplica al comando FFmpeg de recorte; el convertidor HLS puede tener varios procesos internos.</p>

</div></section><section class="sf-settings-section"><h2>Procesamiento local y metadatos</h2><div class="sf-settings-columns"><div class="sf-settings-card">      <h3>Procesamiento local (WebCodecs)</h3>
      <label class="check" title="Convierte audio en el navegador de escritorio sin instalar programas. Exige compatibilidad con toda la escalera, incluidos 64 kbps, memoria suficiente y mantener abierta la pestaña. Si falta una calidad, convierte el servidor."><input type="checkbox" name="webcodecs_enabled" value="1" <?= !empty($cfg['webcodecs_enabled'])?'checked':'' ?>> Habilitar conversión con WebCodecs <span aria-hidden="true">ⓘ</span></label>
      <p class="muted">Desactivado por defecto. Edge en la PC comprobada no admite 64 y 256 kbps, por lo que no puede generar nuestras escaleras completas. Esta opción no cambia las reglas de calidad. Al desactivarla no se interrumpen conversiones ya iniciadas.</p>
      <label class="check" title="Permite a cada gestor registrar y vincular sus propias PC sin aprobación del administrador. Requiere instalar Python, FFmpeg y ejecutar el agente. El agente se vincula con un código temporal y una credencial revocable. La PC debe estar encendida y conectada; si falla, continúa el servidor."><input type="checkbox" name="desktop_workers_enabled" value="1" <?= !empty($cfg['desktop_workers_enabled'])?'checked':'' ?>> Permitir workers de escritorio <span aria-hidden="true">ⓘ</span></label>
      <p><a class="btn" href="worker_access.php<?= $embed?'?embed=1':'' ?>" title="Consulta a qué gestor pertenece cada PC, su conexión y tareas. Cada gestor vincula sus equipos; el administrador puede retirarlos y desactivar el servicio globalmente.">Equipos y conexión de workers</a></p>
      <p class="muted">Cada gestor registra y vincula sus equipos sin aprobación previa. Una PC conectada puede convertir tareas de su gestor, hasta 30 minutos y 500 MiB por fuente. Si no está disponible, convierte el servidor. WebCodecs es independiente.</p>
      <label for="clientMemoryMib">Uso de memoria local con WebCodecs</label>
      <select id="clientMemoryMib" name="client_memory_mib">
        <?php foreach ([0 => 'Automático (según la memoria del equipo)', 128 => '128 MiB · ~5 min', 192 => '192 MiB · ~8 min', 256 => '256 MiB · ~11 min', 384 => '384 MiB · ~17 min', 512 => '512 MiB · ~23 min', 640 => '640 MiB · ~29 min'] as $memMib => $memLabel): ?>
          <option value="<?= (int) $memMib ?>" <?= (int) ($cfg['client_memory_mib'] ?? 0) === $memMib ? 'selected' : '' ?>><?= lab_h($memLabel) ?></option>
        <?php endforeach; ?>
      </select>
      <label>Extensiones permitidas</label>
      <input type="text" name="allowed_extensions" value="<?= lab_h(implode(',', $cfg['allowed_extensions'])) ?>">
      <p class="muted">Soportados vía FFmpeg: mp3, flac, ogg, wma, m4a, wav, aac…</p>
      <label class="check">
        <input type="checkbox" name="delete_source_after_convert" value="1" <?= !empty($cfg['delete_source_after_convert']) ? 'checked' : '' ?>>
        Eliminar archivo fuente después de convertir (ahorra espacio)
      </label>
      <p style="margin:6px 0"><button class="btn" type="button" id="cleanExistingSources">Limpiar archivos origen existentes</button></p>
      <p class="muted">El checkbox se aplica a conversiones futuras. El botón permite revisar y eliminar los originales ya conservados, con confirmación. Se mantienen los paquetes HLS; una reconstrucción no recupera exactamente el original eliminado. No se limpia inbox ni las fuentes de tareas pendientes.</p>

<details class="sf-settings-details"><summary>Detalles de asignación de RAM por navegador</summary>      <p class="muted">Valor por defecto para las cuentas gestoras y para quien no haya elegido otro. Cada gestor puede ajustarlo en <strong>Mi cuenta → Procesamiento local (WebCodecs)</strong>. <strong>Automático</strong> usa 512 MiB en equipos de 8 GiB o más, 384 MiB en 4 GiB y 256 MiB cuando el navegador no declara memoria (Safari/Firefox). Los valores altos permiten pistas más largas pero consumen más RAM: 640 MiB puede cerrar la pestaña en equipos justos. El tope de archivo local se ajusta a la mitad del valor (mínimo 128 MiB).</p>

</details>      <h3>Conversión y subidas</h3>
      <label>Segundos por segmento HLS</label>
      <input type="number" name="segment_seconds" min="2" max="12" value="<?= (int) $cfg['segment_seconds'] ?>">
      <label>Tamaño máximo de subida (MiB)</label>
      <input type="number" name="max_upload_mib" min="10" max="500" value="<?= (int) $cfg['max_upload_mib'] ?>">
</div><div class="sf-settings-card">      <h3>Identificación por audio y letras</h3>
      <label for="acoustidClientKey">AcoustID · Clave de aplicación (client API key)</label>
      <input type="password" id="acoustidClientKey" name="acoustid_client_key" value="" maxlength="256" autocomplete="new-password" spellcheck="false" placeholder="<?= !empty($cfg['acoustid_client_key']) ? 'Clave guardada · deja vacío para conservarla' : 'Introduce la clave de aplicación de AcoustID' ?>">
      <p class="muted"><?= !empty($cfg['acoustid_client_key']) ? 'Hay una clave configurada.' : 'Todavía no hay una clave configurada.' ?> Se requiere la clave de aplicación, no la clave personal para enviar huellas. Disponible en el menú Editar de las pistas sin colección: Analizar con AcoustID. Los resultados se revisan antes de guardar.</p>
      <hr>
      <label class="check">
        <input type="checkbox" name="lrclib_enabled" value="1" <?= !empty($cfg['lrclib_enabled']) ? 'checked' : '' ?>>
        Buscar letras (LRCLIB → lyrics.ovh → Musixmatch → Genius)
      </label>
      <p class="muted">Orden de fallback automático. Musixmatch y Genius solo si pegas una <strong>API key / token</strong> (no uses usuario y contraseña).</p>
      <div class="sf-settings-fields two"><div><label>Musixmatch API key (opcional; se conserva si lo dejas vacío)</label>
      <input type="password" name="musixmatch_api_key" value="" autocomplete="new-password" placeholder="Dejar vacío para conservar la clave guardada"></div><div><label>Genius access token (opcional; se conserva si lo dejas vacío)</label>
      <input type="password" name="genius_access_token" value="" autocomplete="new-password" placeholder="Dejar vacío para conservar el token guardado"></div></div>


</div></div></section><section class="sf-settings-section"><h2>Diagnóstico, auditoría e IA</h2><div class="sf-settings-columns three"><div class="sf-settings-card"><h3>Diagnósticos</h3>
<label class="check"><input type="checkbox" name="playback_diagnostics_enabled" value="1" <?=!empty($cfg['playback_diagnostics_enabled'])?'checked':''?>> Activar diagnóstico de reproducción</label>
<label class="check"><input type="checkbox" name="playback_diagnostics_record" value="1" <?=!empty($cfg['playback_diagnostics_record'])?'checked':''?>> Guardar eventos en el servidor</label>
<label class="check"><input type="checkbox" name="playback_diagnostics_console" value="1" <?=!empty($cfg['playback_diagnostics_console'])?'checked':''?>> Mostrar detalles en la consola del navegador</label>
<label>Conservar registros (días)<input type="number" name="playback_diagnostics_days" min="1" max="30" value="<?=(int)($cfg['playback_diagnostics_days']??7)?>"></label>
<p class="muted">Para depuración: tiempo de inicio, pausas por búfer, errores y reconexiones. Desactivado no recopila eventos nuevos. Los reproductores abiertos reciben el cambio en un máximo de un minuto. No registra contraseñas, cookies ni enlaces de audio.</p>
<p><a class="btn" href="playback_diagnostics.php<?=$embed?'?embed=1':''?>">Ver diagnóstico y registros</a></p>
</div><div class="sf-settings-card">      <h3>Sonoridad y eventos</h3><p><a class="btn" href="loudness_maintenance.php<?=$embed?'?embed=1':''?>">Revisar análisis de sonoridad</a></p>
<label class="check" title="Guarda hasta 1000 cambios de estado del worker. Desactivarlo detiene solo los registros nuevos; conserva el historial, la cola, la validación y la recuperación de tareas."><input type="checkbox" name="worker_diagnostics_enabled" value="1" <?=!array_key_exists('worker_diagnostics_enabled',$cfg)||!empty($cfg['worker_diagnostics_enabled'])?'checked':''?>> Guardar historial detallado de workers</label>
<p class="muted">Fecha y hora, equipo, pista y estado de cada tarea. Los estados operativos necesarios para recuperar trabajos se mantienen aunque se desactive el historial.</p>
<label class="check"><input type="checkbox" name="media_operations_log_enabled" value="1" <?=!array_key_exists('media_operations_log_enabled',$cfg)||!empty($cfg['media_operations_log_enabled'])?'checked':''?>> Guardar eventos de conversiones y mantenimiento</label>
<p class="muted">Registra conversiones, reemplazos, recortes, limpieza y cuarentena en media-log. Al desactivarlo no se guardan eventos nuevos; se conservan los anteriores. No desactiva las comprobaciones de integridad, los estados de las tareas ni la cuarentena. Se aplica a los procesos en curso en un máximo de dos segundos.</p>
</div><div class="sf-settings-card"><h3>Herramientas y sistema</h3><h4>Autorizar eliminación de colecciones</h4><p><a class="btn" href="admin_deletions.php<?= $embed?'?embed=1':'' ?>">Revisar solicitudes<?= $sfPendingDeletes?' ('.count($sfPendingDeletes).')':'' ?></a></p><h4>Integraciones externas e IA</h4><p><a class="btn" href="external_maintenance.php<?= $embed ? '?embed=1' : '' ?>">Editar adaptadores y mantenimiento con IA</a></p><h4>Comunicación</h4><p><a class="btn" href="communication.php<?= $embed ? '?embed=1&tab=notices' : '?tab=notices' ?>">Avisos a usuarios y sugerencias</a></p><h4>Music Charts</h4><p><a class="btn" href="music_charts_config.php<?= $embed ? '?embed=1' : '' ?>">Rankings, calendario y descargas automáticas</a></p><h4>Mornye API</h4><p><a class="btn" href="mornye_control.php<?= $embed ? '?embed=1' : '' ?>">Servicio, preferencias y notificaciones</a></p><h4>Respaldos e integridad</h4><p><a class="btn" href="maintenance.php<?= $embed ? '?embed=1' : '' ?>">Ver respaldos y auditoría</a></p></div></div></section><section class="sf-settings-section"><h2>Seguridad y sesiones</h2><div class="sf-settings-card"><label>Duración de «Mantener la sesión abierta» (días)<input type="number" name="remember_days" min="1" max="365" required value="<?= (int)($cfg['remember_days']??30) ?>"></label><p class="muted">De 1 a 365 días desde el inicio de sesión. Reducir el plazo también limita tokens existentes; aumentarlo se aplica a los nuevos.</p><p>Protección CSRF obligatoria en todas las operaciones de escritura.</p></div></section></div>      <p class="sf-config-actions">
        <button class="btn primary" type="submit">Guardar configuración</button>
      </p>
    </form>
  </main>
<script src="assets/navigation.js?v=album-return1"></script>
<link rel="stylesheet" href="assets/player-icons.css?v=3"><script src="assets/player-icons.js?v=3"></script><script src="assets/shell-player.js?v=20261116-console1"></script>
<link rel="stylesheet" href="assets/player.css?v=mini-contrast1">
<script src="assets/external-maintenance-notices.js?v=theme-sweep1"></script><script src="assets/source-cleanup.js?v=20261115-feedback1"></script></body>
</html>
