<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
require_once __DIR__ . '/lib/user_preferences.php';
lab_auth_require_login();
lab_auth_session_start();

function cp_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function cp_redirect(): never { header('Location: change_password.php'.(!empty($_GET['embed'])?'?embed=1':''), true, 303); exit; }

$account = lab_auth_current_account();
if (!is_array($account)) {
    http_response_code(401);
    exit('Debes iniciar sesión para cambiar tu contraseña.');
}
$error = '';
$notice = (string)($_SESSION['change_password_notice'] ?? '');
unset($_SESSION['change_password_notice']);
$managementViewChanged = !empty($_SESSION['management_view_changed']);
unset($_SESSION['management_view_changed']);
$clientMemoryChanged = !empty($_SESSION['client_memory_changed']);
unset($_SESSION['client_memory_changed']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!lab_auth_verify_csrf(isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : null)) {
        http_response_code(403);
        $error = 'La sesión del formulario expiró. Recarga la página e inténtalo de nuevo.';
    } elseif (($_POST['action'] ?? '') === 'save_management_view') {
        try {
            lab_user_save_management_controls((string)$account['id'], isset($_POST['show_management_controls']));
            $_SESSION['management_view_changed'] = true;
            $_SESSION['change_password_notice'] = 'Tu preferencia de gestión se guardó. Puedes cambiarla aquí cuando quieras.';
            cp_redirect();
        } catch (Throwable $e) {
            error_log('SparrowFy management view: '.get_class($e));
            $error = 'No se pudo guardar la preferencia de gestión.';
        }
    } elseif (($_POST['action'] ?? '') === 'save_tips') {
        try {
            lab_user_save_tips((string)$account['id'], isset($_POST['show_tips']));
            $_SESSION['change_password_notice'] = 'Tu preferencia de tips se guardó correctamente.';
            cp_redirect();
        } catch (Throwable $e) {
            error_log('SparrowFy save tips: '.get_class($e));
            $error = 'No se pudo guardar la preferencia de tips. Inténtalo de nuevo.';
        }
    } elseif (($_POST['action'] ?? '') === 'save_client_memory') {
        try {
            if (!lab_auth_can_manage_albums()) throw new RuntimeException('Esta preferencia es solo para cuentas gestoras.');
            lab_user_save_client_memory((string)$account['id'], (int)($_POST['client_memory_mib'] ?? 0));
            $_SESSION['change_password_notice'] = 'Tu límite de memoria local se guardó. Se aplicará a las próximas conversiones de este navegador.';
            $_SESSION['client_memory_changed'] = true;
            cp_redirect();
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            error_log('SparrowFy client memory preference: '.get_class($e));
            $error = 'No se pudo guardar la preferencia de memoria local. Inténtalo de nuevo.';
        }
    } elseif (($_POST['action'] ?? '') === 'save_hls_profile') {
        try {
            $profile = (string)($_POST['hls_profile'] ?? '');
            lab_user_save_hls_profile((string)$account['id'], $profile);
            $_SESSION['change_password_notice'] = 'Tu preferencia HLS se guardó correctamente y se aplicará a tus reproducciones.';
            cp_redirect();
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            error_log('HLS Music Lab save HLS preference: ' . get_class($e) . ': ' . $e->getMessage());
            $error = 'No se pudo guardar la preferencia HLS. Verifica que se haya aplicado la migración de preferencias.';
        }
    } else {
        $current = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        try {
            if ($current === '' || $new === '' || $confirm === '') throw new RuntimeException('Completa los tres campos de contraseña.');
            if (strlen($new) < 14 || strlen($new) > 72) throw new RuntimeException('La nueva contraseña debe tener entre 14 y 72 bytes.');
            if (!hash_equals($new, $confirm)) throw new RuntimeException('La confirmación no coincide con la nueva contraseña.');
            $pdo = lab_auth_pdo();
            $q = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
            $q->execute([(string)$account['id']]);
            $hash = $q->fetchColumn();
            $q->closeCursor();
            if (!is_string($hash) || !password_verify($current, $hash)) throw new RuntimeException('La contraseña actual es incorrecta.');
            if (password_verify($new, $hash)) throw new RuntimeException('La nueva contraseña debe ser distinta de la actual.');
            $newHash = password_hash($new, PASSWORD_DEFAULT);
            $update = $pdo->prepare('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ? AND is_active = 1');
            $update->execute([$newHash, time(), (string)$account['id']]);
            if ($update->rowCount() !== 1) throw new RuntimeException('No se pudo actualizar la contraseña. Inténtalo de nuevo.');
            // Keep this browser session valid after the credential change...
            $_SESSION['credential_stamp'] = hash('sha256', $newHash);
            // ...but revoke every mobile Bearer token tied to the account.
            require_once __DIR__ . '/lib/mobile_sessions.php';
            try { sf_mobile_sessions_revoke_user($pdo, (string)$account['id']); } catch (Throwable $e) { error_log('SparrowFy revoke mobile sessions: '.get_class($e)); }
            $_SESSION['change_password_notice'] = 'Tu contraseña se cambió correctamente. Se cerraron las sesiones móviles asociadas.';
            cp_redirect();
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            error_log('HLS Music Lab change password: ' . get_class($e) . ': ' . $e->getMessage());
            $error = 'No se pudo cambiar la contraseña por un error interno. Inténtalo más tarde.';
        }
    }
}
$csrf = lab_auth_csrf_token();
$sfTheme = lab_user_theme((string)$account['id']);
?><!doctype html>
<html lang="es" data-theme="<?= cp_h($sfTheme) ?>" data-theme-source="server"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Mi cuenta · HLS Music Lab</title><script>try{document.documentElement.dataset.theme=<?= json_encode($sfTheme) ?>}catch(e){document.documentElement.dataset.theme=<?= json_encode($sfTheme) ?>}</script>
<link rel="stylesheet" href="assets/lab.css?v=theme-sweep1"><style>.cp-wrap{max-width:760px;margin:1.5rem auto}.cp-form{display:grid;gap:1rem}.cp-form label{display:grid;gap:.4rem}.cp-form input[type=password]{width:100%;max-width:100%;box-sizing:border-box;padding:.75rem}.cp-note{font-size:.92rem}.cp-alert{padding:.8rem 1rem;border-radius:.6rem;margin:.8rem 0}.cp-ok{background:#dcfce7;color:#166534}.cp-error{background:#fee2e2;color:#991b1b}</style><meta name="sf-player-account" content="<?= htmlspecialchars((string)(lab_auth_current_account()['id']??''), ENT_QUOTES, 'UTF-8') ?>"><?php if(!empty($_GET['embed'])): ?><style>body>.lab-header{display:none}body>.lab-main{margin:0 auto;padding:20px}</style><?php endif; ?><meta name="sf-session-account" content="<?= htmlspecialchars((string)(lab_auth_current_account()['id']??''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><script src="assets/session-sync.js?v=auth-poll3" defer></script>
<meta name="sf-save-result" content="<?= $error!==''?'error':($notice!==''?'success':'') ?>"><link rel="stylesheet" href="assets/save-feedback.css?v=2"><script src="assets/save-feedback.js?v=2"></script><script defer src=assets/sf-language.js></script></head>
<body><header class="lab-header"><div><h1>Mi cuenta</h1><p class="muted">Contraseña y preferencias de reproducción · Usuario: <?=cp_h((string)$account['username'])?></p></div><div class="header-actions"><a class="btn" href="index.php">Cerrar</a></div></header>
<main class="lab-main cp-wrap"><div class="sf-language-picker" aria-label="Idioma / Language" translate="no"><button type="button" data-sf-lang="es"><img src="assets/mx.svg" width="24" alt=""> Español</button> <button type="button" data-sf-lang="en"><img src="assets/us.svg" width="24" alt=""> English</button></div>
<?php if ($notice !== ''): ?><p class="cp-alert cp-ok" role="status"><?=cp_h($notice)?></p><?php endif; ?>
<?php if ($error !== ''): ?><p class="cp-alert cp-error" role="alert"><?=cp_h($error)?></p><?php endif; ?>
<?php if (lab_auth_can_manage_albums()): ?>
<section class="card"><h2>Interfaz de gestión</h2>
<p class="muted">Desactiva los controles para escuchar música con una vista más sencilla. Conservarás tus permisos y podrás reactivarlos aquí. Esta preferencia se guarda para tu cuenta.</p>
<form method="post" id="management-view-form"><input type="hidden" name="csrf_token" value="<?=cp_h($csrf)?>"><input type="hidden" name="action" value="save_management_view">
<label><input type="checkbox" name="show_management_controls" value="1" <?=lab_user_show_management_controls((string)$account['id'])?'checked':''?>> Mostrar controles de gestión</label>
<p><button class="btn primary" type="submit">Guardar preferencia</button></p></form></section>
<script>document.querySelector('#management-view-form input[type=checkbox]').addEventListener('change',function(){this.form.requestSubmit();});</script>
<?php endif; ?>
<?php if (lab_auth_can_manage_albums()): $memCurrent = lab_user_client_memory((string)$account['id']); ?>
<section class="card"><h2>Worker de escritorio</h2>
<p class="muted">Ofrece tu PC para convertir con Python y FFmpeg, con toda la escalera de calidad. Tu permiso de gestor permite registrar y vincular tus propias PC sin aprobación del administrador.</p>
<a class="btn" href="worker_access.php?view=mine<?=!empty($_GET['embed'])?'&amp;embed=1':''?>" title="Registra tu PC como worker y genera tu propio código de vinculación, sin aprobación del administrador. Requiere instalar el agente; requiere mantener el agente en ejecución y conexión HTTPS. Si falla, continúa el servidor.">Configurar mi PC como worker ⓘ</a></section>
<section class="card"><h2>Procesamiento local (WebCodecs)</h2>
<p role="note"><?=!empty(lab_load_config()['webcodecs_enabled'])?'Habilitado por el administrador. Solo se usa si el navegador admite toda la escalera, incluidos 64 kbps.':'Deshabilitado por el administrador. Las conversiones se realizarán en el servidor; esta preferencia de memoria se conserva para cuando se habilite.'?></p>
<p class="muted cp-note">Solo afecta a la conversión en este navegador de escritorio: los móviles y los fallbacks siguen en el servidor. Esta preferencia se guarda para tu cuenta y se aplica a las próximas conversiones.</p>
<form method="post" class="cp-form"><input type="hidden" name="csrf_token" value="<?=cp_h($csrf)?>"><input type="hidden" name="action" value="save_client_memory">
<label for="client_memory_mib">Uso de memoria local con WebCodecs</label>
<select id="client_memory_mib" name="client_memory_mib">
<?php foreach ([0 => 'Automático (según la memoria del equipo)', 128 => '128 MiB · ~5 min', 192 => '192 MiB · ~8 min', 256 => '256 MiB · ~11 min', 384 => '384 MiB · ~17 min', 512 => '512 MiB · ~23 min', 640 => '640 MiB · ~29 min'] as $mib => $memLabel): ?>
<option value="<?=(int)$mib?>" <?=$memCurrent===$mib?'selected':''?>><?=cp_h($memLabel)?></option>
<?php endforeach; ?>
</select>
<p class="muted cp-note">Automático usa 512 MiB en equipos de 8 GiB o más, 384 MiB en 4 GiB y 256 MiB cuando el navegador no declara memoria (Safari/Firefox). Los valores altos permiten pistas más largas pero consumen más RAM; 640 MiB puede cerrar la pestaña en equipos justos.</p>
<div><button class="btn primary" type="submit">Guardar preferencia</button></div></form></section>
<?php endif; ?>
<?php if ($clientMemoryChanged): ?><script>if(window.parent!==window)window.parent.postMessage({source:'sf-account-preferences',type:'client-memory-changed',mib:<?=(int)lab_user_client_memory((string)$account['id'])?>},location.origin);</script><?php endif; ?>
<?php if ($managementViewChanged): ?><script>if(window.parent!==window)window.parent.postMessage({source:'sf-account-preferences',type:'management-view-changed'},location.origin);</script><?php endif; ?>
<section class="card"><h2>Consejos de Sparrow-Fy</h2><p class="muted">Recibe consejos al iniciar la webapp para descubrir sus funciones. Esta preferencia se guarda para tu cuenta.</p><form method="post"><input type="hidden" name="csrf_token" value="<?=cp_h($csrf)?>"><input type="hidden" name="action" value="save_tips"><label><input type="checkbox" name="show_tips" value="1" <?=lab_user_show_tips((string)$account['id'])?'checked':''?>> Mostrar tips</label><p><button class="btn primary" type="submit">Guardar preferencia</button></p></form></section><section class="card"><h2>Comportamiento de reproducción HLS</h2><p class="muted cp-note">Esta preferencia pertenece a tu cuenta, no a un dispositivo. Se guarda en SQLite, se aplica a tus reproducciones web y está disponible para sincronización mediante la API móvil.</p>
<form method="post" class="cp-form"><input type="hidden" name="csrf_token" value="<?=cp_h($csrf)?>"><input type="hidden" name="action" value="save_hls_profile"><label for="hls_profile">Perfil HLS</label><select id="hls_profile" name="hls_profile" required><option value="continuity" <?= lab_user_hls_profile((string)$account['id']) === 'continuity' ? 'selected' : '' ?>>Asegurar transmisión continua</option><option value="balanced_mobile" <?= lab_user_hls_profile((string)$account['id']) === 'balanced_mobile' ? 'selected' : '' ?>>Equilibrado móvil (recomendado)</option><option value="audio_quality" <?= lab_user_hls_profile((string)$account['id']) === 'audio_quality' ? 'selected' : '' ?>>Priorizar calidad de audio</option></select>
<ul class="muted cp-note"><li><strong>Asegurar transmisión continua:</strong> decisiones más conservadoras y más margen de búfer; puede permanecer más tiempo en calidades bajas.</li><li><strong>Equilibrado móvil:</strong> balance entre estabilidad, calidad y consumo de datos; recomendado para conexiones móviles variables.</li><li><strong>Priorizar calidad de audio:</strong> intenta usar calidades superiores cuando la red lo permite; consume más datos y puede aumentar el riesgo de pausas en redes inestables.</li></ul><p class="muted cp-note">El perfil no cambia las calidades disponibles de cada canción ni supera la calidad de la fuente. El modo Automático sigue adaptándose a la red; el selector manual de calidad sigue disponible.</p><div><button class="btn primary" type="submit">Guardar preferencia HLS</button></div></form></section>
<section class="card"><h2>Uso de datos</h2><p>Transferencias, gráficas y actividad.</p><a class="btn" href="usage.php?return=account<?= !empty($embed)?'&amp;embed=1':'' ?>">Ver consumo</a></section><section class="card"><h2>Oír sin conexión</h2><p>Consulta tus canciones descargadas, cambia el límite de espacio o borra copias de este equipo. Las descargas no se comparten entre dispositivos.</p><a class="btn" href="offline.html" target="_top">Oír sin conexión</a></section><section class="card"><h2>Dispositivos y sesiones</h2><p>Revisa tus accesos y cierra sesiones que no reconozcas.</p><a class="btn" href="devices.php<?= !empty($_GET['embed']) ? '?embed=1' : '' ?>">Administrar mis accesos</a></section><section class="card"><h2>Cambiar contraseña</h2><p class="muted cp-note">Puedes actualizar tu contraseña sin pedir al administrador que la restablezca. Usa al menos 14 caracteres.</p><form method="post" class="cp-form" autocomplete="on"><input type="hidden" name="csrf_token" value="<?=cp_h($csrf)?>"><label>Contraseña actual<input type="password" name="current_password" required maxlength="72" autocomplete="current-password"></label><label>Nueva contraseña (mínimo 14 caracteres)<input type="password" name="new_password" required minlength="14" maxlength="72" autocomplete="new-password"></label><label>Confirmar nueva contraseña<input type="password" name="confirm_password" required minlength="14" maxlength="72" autocomplete="new-password"></label><div><button class="btn primary" type="submit">Guardar nueva contraseña</button></div></form></section></main><link rel="stylesheet" href="assets/player.css?v=mini-contrast1"><script src="assets/navigation.js?v=album-return1"></script>
<link rel="stylesheet" href="assets/player-icons.css?v=3"><script src="assets/player-icons.js?v=3"></script><script src="assets/shell-player.js?v=20261116-console1"></script></body></html>
