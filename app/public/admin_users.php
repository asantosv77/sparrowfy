<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
require_once __DIR__ . '/lib/user_preferences.php';
lab_auth_require_admin();
require_once __DIR__.'/lib/audio_download.php';sfd_db();
$sfTheme = lab_user_theme((string)(lab_auth_current_account()['id'] ?? ''));
$pdo = lab_auth_pdo();
require_once __DIR__.'/lib/worker_access.php';
sf_worker_schema($pdo);
$notice = (string)($_SESSION['admin_users_notice'] ?? '');
unset($_SESSION['admin_users_notice']);
$error = '';
function au_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function au_redirect(): never { header('Location: admin_users.php'.(!empty($_GET['embed'])?'?embed=1':''), true, 303); exit; }
/** Identificador seguro para asociar el restablecimiento de contraseña de cada fila. */
function au_reset_form_id(string $uid): string { return 'reset-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $uid); }
/**
 * Normaliza y valida un cambio de estado/permisos. Devuelve el valor efectivo
 * de can_manage_albums (el rol admin siempre lo implica).
 */
function au_checked_permissions(PDO $pdo, array $target, int $active, int $canManage, bool $checkLastAdmin = true): int {
    // El rol admin implica gestión de álbumes; mantener también el indicador coherente.
    if ((string)$target['role'] === 'admin') $canManage = 1;
    // La cuenta principal se protege por su ID inmutable, nunca por el nombre de usuario.
    if (lab_auth_is_protected_admin_id((string)$target['id'])) {
        if ($active !== 1) throw new RuntimeException('La cuenta administradora principal está protegida y no se puede desactivar.');
        if ((string)$target['role'] !== 'admin') throw new RuntimeException('La cuenta administradora principal debe conservar el rol admin.');
        if ($canManage !== 1) throw new RuntimeException('La cuenta administradora principal debe conservar permanentemente el permiso de gestión de álbumes.');
        // La interfaz no ofrece cambios a estos campos; este control también bloquea POST manipulados.
    }
    if ((string)$target['id'] === (string)($_SESSION['user_id'] ?? '') && $active !== 1) throw new RuntimeException('No puedes desactivar tu propia cuenta mientras tienes sesión iniciada.');
    if ($checkLastAdmin && (string)$target['role'] === 'admin' && (int)$target['is_active'] === 1 && $active === 0) {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND is_active=1")->fetchColumn();
        if ($n <= 1) throw new RuntimeException('No puedes desactivar al último administrador activo.');
    }
    return $canManage;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $csrf = $_POST['csrf_token'] ?? null;
    if (!is_string($csrf) || !lab_auth_verify_csrf($csrf)) {
        http_response_code(403);
        echo json_encode(['error'=>'La sesión del formulario expiró. Recarga la página e inténtalo de nuevo.']);
        exit;
    }
    try {
        $id = $_POST['id'] ?? null;
        if (!is_string($id)) throw new DomainException('Identificador de cuenta inválido.');
        require_once __DIR__.'/lib/user_account_delete.php';
        sf_delete_account($pdo, (string)($_SESSION['user_id'] ?? ''), $id, LAB_DATA, getenv('PHP_SESSION_DIR') ?: '/var/lib/hlsml-sessions');
        echo json_encode(['success'=>true]);
    } catch (DomainException $e) {
        http_response_code(409);
        echo json_encode(['error'=>$e->getMessage()]);
    } catch (Throwable $e) {
        error_log('Sparrow-Fy account cleanup: '.get_class($e));
        http_response_code(500);
        echo json_encode(['error'=>'No se pudo completar el borrado. Si la cuenta quedó desactivada, vuelve a intentar.']);
    }
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!lab_auth_verify_csrf(isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : null)) {
        http_response_code(403); $error = 'La sesión del formulario expiró. Recarga la página e inténtalo de nuevo.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'create') {
                $username = trim((string)($_POST['username'] ?? ''));
                $password = (string)($_POST['password'] ?? '');
                $canManage = !empty($_POST['can_manage_albums']) ? 1 : 0;
                if (!preg_match('/^[A-Za-z0-9_.@-]{3,80}$/', $username)) throw new RuntimeException('El usuario debe tener entre 3 y 80 caracteres: letras, números, punto, guion o guion bajo.');
                if (strlen($password) < 14 || strlen($password) > 72) throw new RuntimeException('La contraseña debe tener entre 14 y 72 bytes.');
                $now = time();
                $idMeta = null;
                foreach ($pdo->query('PRAGMA table_info(users)') as $column) {
                    if ((string)$column['name'] === 'id') { $idMeta = $column; break; }
                }
                if (!is_array($idMeta)) throw new RuntimeException('No se encontró la columna id en users.');
                $hash = password_hash($password, PASSWORD_DEFAULT);
                // Las instalaciones existentes pueden usar id INTEGER AUTOINCREMENT o id TEXT.
                // En el segundo caso generamos un identificador estable explícitamente.
                if (strtoupper(trim((string)$idMeta['type'])) === 'INTEGER' && (int)$idMeta['pk'] === 1) {
                    $q = $pdo->prepare("INSERT INTO users(username,password_hash,role,is_active,can_manage_albums,created_at,updated_at) VALUES(?,?,'user',1,?,?,?)");
                    $q->execute([$username, $hash, $canManage, $now, $now]);
                } else {
                    $newId = bin2hex(random_bytes(16));
                    $q = $pdo->prepare("INSERT INTO users(id,username,password_hash,role,is_active,can_manage_albums,created_at,updated_at) VALUES(?,?,?,'user',1,?,?,?)");
                    $q->execute([$newId, $username, $hash, $canManage, $now, $now]);
                }
                $notice = 'Cuenta creada correctamente.';
            } elseif ($action === 'update') {
                $id = trim((string)($_POST['id'] ?? ''));
                if ($id === '' || strlen($id) > 128) throw new RuntimeException('Identificador de cuenta inválido.');
                $active = !empty($_POST['is_active']) ? 1 : 0;
                $canManage = !empty($_POST['can_manage_albums']) ? 1 : 0;
                $q = $pdo->prepare('SELECT id, username, role, is_active, can_manage_albums FROM users WHERE id=? LIMIT 1'); $q->execute([$id]); $target = $q->fetch();
                if (!$target) throw new RuntimeException('La cuenta ya no existe.');
                $canManage = au_checked_permissions($pdo, $target, $active, $canManage);
                if (isset($_POST['role']) && (string)$_POST['role'] !== (string)$target['role']) {
                    if (lab_auth_is_protected_admin_id((string)$target['id'])) throw new RuntimeException('No se puede cambiar el rol de la cuenta administradora principal.');
                    throw new RuntimeException('El cambio de rol no está permitido desde este formulario.');
                }
                $pdo->beginTransaction();
                try {
                    $q = $pdo->prepare('UPDATE users SET is_active=?, can_manage_albums=?, updated_at=? WHERE id=?');
                    $q->execute([$active, $canManage, time(), $id]);
                    sf_worker_sync_account($pdo,$id,(string)($_SESSION['user_id']??''));
                    $pdo->commit();
                } catch (Throwable $e) {
                    if($pdo->inTransaction())$pdo->rollBack();
                    throw $e;
                }
                if (!$active) sf_remember_db()->prepare('DELETE FROM sf_remember_tokens WHERE user_id=?')->execute([$id]);
                $notice = 'Permisos y estado actualizados.';
            } elseif ($action === 'update_bulk') {
                // Un único guardado para todos los registros visibles. Se valida el
                // lote completo antes de escribir y se aplica en una transacción.
                $downloadsIn = $_POST['download'] ?? [];
                if(!is_array($downloadsIn))throw new RuntimeException('Permisos de descarga no válidos.');
                $activeIn = $_POST['active'] ?? [];
                $manageIn = $_POST['manage'] ?? [];
                if (!is_array($activeIn) || !is_array($manageIn)) throw new RuntimeException('Los permisos enviados no son válidos.');
                $ids = [];
                foreach (array_merge(array_keys($activeIn), array_keys($manageIn), array_keys($downloadsIn)) as $key) {
                    $key = (string)$key;
                    if ($key === '' || strlen($key) > 128) throw new RuntimeException('Identificador de cuenta inválido.');
                    $ids[$key] = true;
                }
                if (!$ids) throw new RuntimeException('No se recibió ningún cambio.');
                $targets = [];
                foreach ($pdo->query('SELECT id, username, role, is_active, can_manage_albums FROM users') as $row) {
                    $targets[(string)$row['id']] = $row;
                }
                $desired = [];
                foreach (array_keys($ids) as $id) {
                    // Una cuenta borrada mientras se editaba se ignora en silencio.
                    if (!isset($targets[$id])) continue;
                    $target = $targets[$id];
                    $active = array_key_exists($id,$activeIn) ? (!empty($activeIn[$id]) ? 1 : 0) : (int)$target['is_active'];
                    $canManage = array_key_exists($id,$manageIn) ? (!empty($manageIn[$id]) ? 1 : 0) : (int)$target['can_manage_albums'];
                    // Comprobaciones por cuenta: cuenta principal, autodesactivación y rol.
                    $canManage = au_checked_permissions($pdo, $target, $active, $canManage, false);
                    $desired[$id] = [$active, $canManage];
                }
                // El "último administrador" se valida sobre el resultado del lote,
                // no cuenta por cuenta: así no se puede desactivar a dos y dejar cero.
                $activeAdmins = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND is_active=1")->fetchColumn();
                foreach ($desired as $id => [$active, $canManage]) {
                    if ((string)$targets[$id]['role'] === 'admin' && (int)$targets[$id]['is_active'] === 1 && $active === 0) $activeAdmins--;
                }
                if ($activeAdmins <= 0) throw new RuntimeException('No puedes dejar el sistema sin ningún administrador activo.');
                $deactivated = [];
                $saved = 0;$changedUsers=[];
                $pdo->beginTransaction();
                try {
                    $upd = $pdo->prepare('UPDATE users SET is_active=?, can_manage_albums=?, updated_at=? WHERE id=?');
                    $now = time();
                    foreach ($desired as $id => [$active, $canManage]) {
                        if ((int)$targets[$id]['is_active'] === $active && (int)$targets[$id]['can_manage_albums'] === $canManage) continue;
                        $upd->execute([$active, $canManage, $now, $id]);
                        if ($upd->rowCount() > 0) $changedUsers[$id]=true;
                        sf_worker_sync_account($pdo,$id,(string)($_SESSION['user_id']??''));
                        if ($active === 0) $deactivated[] = $id;
                    }
                    $downloadSave=$pdo->prepare('INSERT INTO sf_audio_download_permissions(user_id,enabled) VALUES(?,?) ON CONFLICT(user_id) DO UPDATE SET enabled=excluded.enabled');
                    foreach($downloadsIn as $downloadUid=>$enabled){
                        $downloadUid=(string)$downloadUid;
                        if(!isset($targets[$downloadUid]))continue;
                        $enabled=!empty($enabled)?1:0;
                        if(sfd_allowed($downloadUid)!==(bool)$enabled)$changedUsers[$downloadUid]=true;
                        $downloadSave->execute([$downloadUid,$enabled]);
                    }
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
                // Fuera de la transacción: otra conexión no debe competir por el bloqueo de escritura.
                foreach ($deactivated as $id) sf_remember_db()->prepare('DELETE FROM sf_remember_tokens WHERE user_id=?')->execute([$id]);
                $saved=count($changedUsers);
                $notice = $saved > 0 ? 'Cambios guardados en ' . $saved . ' ' . ($saved === 1 ? 'cuenta' : 'cuentas') . '.' : 'No había cambios que guardar.';
            } elseif ($action === 'reset_password') {
                $id = trim((string)($_POST['id'] ?? '')); $password = (string)($_POST['password'] ?? '');
                if ($id === '' || strlen($id) > 128) throw new RuntimeException('Identificador de cuenta inválido.');
                if (strlen($password) < 14 || strlen($password) > 72) throw new RuntimeException('La contraseña debe tener entre 14 y 72 bytes.');
                $q = $pdo->prepare('UPDATE users SET password_hash=?, updated_at=? WHERE id=?'); $q->execute([password_hash($password, PASSWORD_DEFAULT), time(), $id]);
                if ($q->rowCount() !== 1) throw new RuntimeException('La cuenta no existe o no se pudo actualizar.');
                // A reset invalidates persistent web logins and mobile Bearer tokens for that account.
                sf_remember_db()->prepare('DELETE FROM sf_remember_tokens WHERE user_id=?')->execute([$id]);
                require_once __DIR__ . '/lib/mobile_sessions.php';
                sf_mobile_sessions_revoke_user($pdo, $id);
                $notice = 'Contraseña restablecida. Se cerraron las sesiones web persistentes y móviles de esa cuenta.';
            } else { throw new RuntimeException('Acción no reconocida.'); }
        } catch (PDOException $e) {
            if (str_contains(strtolower($e->getMessage()), 'unique')) $error = 'Ya existe una cuenta con ese nombre de usuario.';
            else { error_log('HLS Music Lab admin_users: ' . $e->getMessage()); $error = 'No se pudo guardar el cambio. Revisa los registros del servidor.'; }
        } catch (Throwable $e) { $error = $e->getMessage(); }
        if ($notice !== '') { $_SESSION['admin_users_notice'] = $notice; au_redirect(); }
    }
}
$users = $pdo->query('SELECT id, username, role, is_active, can_manage_albums, created_at, last_login_at FROM users ORDER BY CASE role WHEN \'admin\' THEN 0 ELSE 1 END, username COLLATE NOCASE')->fetchAll();
$csrf = lab_auth_csrf_token();
?><!doctype html><html lang="es" data-theme="<?= lab_h($sfTheme) ?>" data-theme-source="server"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Administrar cuentas · HLS Music Lab</title><script>try{document.documentElement.dataset.theme=<?= json_encode($sfTheme) ?>}catch(e){document.documentElement.dataset.theme=<?= json_encode($sfTheme) ?>}</script>
<link rel="stylesheet" href="assets/lab.css?v=theme-sweep1"><style>.admin-wrap{max-width:1100px;margin:auto}.user-row{display:grid;grid-template-columns:minmax(140px,1fr) minmax(250px,2fr);gap:1rem;padding:1rem 0;border-bottom:1px solid var(--border,#ddd)}.inline-form{display:flex;flex-wrap:wrap;align-items:center;gap:.7rem}.inline-form label{display:inline-flex;gap:.35rem;align-items:center}.inline-form input[type=password],.create-grid input[type=text],.create-grid input[type=password]{padding:.65rem;max-width:100%}.create-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.9rem}.create-grid label{display:flex;flex-direction:column;gap:.35rem}.hint{font-size:.9rem}.account-state{font-size:.9rem}.protected-account-note{padding:.8rem;border:1px solid #0d9b75;border-radius:.5rem;background:rgba(16,185,129,.08);font-size:.9rem;line-height:1.45}.protected-controls{padding:.7rem;border:1px solid var(--border,#334155);border-radius:.5rem;opacity:.85}.protected-controls input:disabled{cursor:not-allowed}.notice{padding:.8rem;border-radius:.5rem;background:#dcfce7;color:#166534}.error{padding:.8rem;border-radius:.5rem;background:#fee2e2;color:#991b1b}@media(max-width:650px){.user-row{grid-template-columns:1fr}}.user-search{display:flex;flex-wrap:wrap;align-items:center;gap:.75rem;margin:.25rem 0 1rem}
.user-search label{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
.user-search input[type=search]{flex:1 1 260px;min-width:0;padding:.7rem .9rem;border:1px solid var(--border,#334155);border-radius:.55rem;background:var(--input-bg,transparent);color:inherit;font:inherit}
.user-search input[type=search]:focus{outline:2px solid var(--primary,#10b981);outline-offset:1px}
.user-search .search-count{font-size:.85rem;color:var(--muted,#94a3b8);white-space:nowrap}
.user-table{display:grid;gap:0}
.user-row{display:grid;grid-template-columns:minmax(100px,1fr) auto auto auto 180px auto auto;align-items:center;gap:.7rem .65rem;padding:.85rem 0;border-bottom:1px solid var(--border,#334155)}
.user-row.admin-row{grid-template-columns:minmax(100px,1fr) auto auto auto 180px auto auto auto}
@media(min-width:761px){
.user-table{overflow-x:auto}
.user-row,.user-row.admin-row{min-width:900px;gap:.55rem .5rem}
.user-row .user-check{font-size:.78rem;gap:.3rem}
.user-row .user-pass{width:180px;min-width:180px;max-width:180px;box-sizing:border-box;font-size:.8rem;padding:.55rem .6rem}
.user-row .btn{font-size:.78rem;padding:.55rem .6rem}
}
.user-row[hidden]{display:none!important}
.user-row .user-id{min-width:0;overflow-wrap:anywhere}
.user-row .account-state{font-size:.85rem}
.user-row .user-check{display:inline-flex;align-items:center;align-self:center;gap:.4rem;white-space:nowrap;margin:0;line-height:1.25}.user-row .user-check input[type=checkbox]{margin:0;flex-shrink:0;align-self:center}
.user-row .user-download{margin:0}.user-row .user-pass{box-sizing:border-box;width:100%;min-width:0;padding:.65rem .75rem;border:1px solid var(--border,#334155);border-radius:.5rem;background:var(--input-bg,transparent);color:inherit;font:inherit}
.user-row .user-pass:focus{outline:2px solid var(--primary,#10b981);outline-offset:1px}
.user-row .btn{white-space:nowrap}
.user-row .btn.user-delete{width:2.35rem;min-height:2.35rem;display:inline-grid;place-items:center;justify-self:end;padding:.35rem;color:var(--danger,#f87171)}
.user-row .btn.user-delete:disabled{opacity:.42;cursor:default}
.user-row details.protected-details{margin-top:.35rem}
.user-row details.protected-details summary{cursor:pointer;font-size:.82rem;color:var(--muted,#94a3b8)}
.user-row details.protected-details p{margin:.4rem 0 0;padding:.55rem .65rem;border:1px solid #0d9b75;border-radius:.45rem;background:rgba(16,185,129,.08);font-size:.82rem;line-height:1.4}
.user-search-empty{margin:.85rem 0 0;font-size:.9rem;color:var(--muted,#94a3b8)}
.user-search-empty[hidden]{display:none!important}
.bulk-actions{margin-top:.9rem;display:flex;flex-wrap:wrap;align-items:center;gap:.75rem}
.bulk-actions[hidden]{display:none!important}
.bulk-status{font-size:.85rem;color:var(--primary,#10b981);font-weight:600}
.bulk-status:empty{display:none}
.user-row .btn.bulk-save-primary{background:var(--primary,#10b981);color:#06281c;border-color:var(--primary,#10b981);font-weight:600}
@media(max-width:760px){.user-row,.user-row.admin-row{grid-template-columns:1fr auto auto;gap:.55rem .8rem}.user-row .user-id,.user-row .user-pass{grid-column:1/-1}}
@media(max-width:560px){.user-row,.user-row.admin-row{grid-template-columns:1fr}.user-row .user-id,.user-row .user-pass{grid-column:auto}.user-row .user-check{justify-self:start}.user-row .btn{width:100%}}
</style><meta name="sf-player-account" content="<?= htmlspecialchars((string)(lab_auth_current_account()['id']??''), ENT_QUOTES, 'UTF-8') ?>"><?php if(!empty($_GET['embed'])): ?><style>body>.lab-header{display:none}body>.lab-main{margin:0 auto;padding:20px}</style><?php endif; ?><meta name="sf-session-account" content="<?= htmlspecialchars((string)(lab_auth_current_account()['id']??''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><script src="assets/session-sync.js?v=auth-poll3" defer></script>
<meta name="sf-save-result" content="<?= $error!==''?'error':($notice!==''?'success':'') ?>"><link rel="stylesheet" href="assets/save-feedback.css?v=2"><script src="assets/save-feedback.js?v=2"></script><script defer src=assets/sf-language.js></script></head><body><header class="lab-header"><div><h1>Administrar cuentas</h1><p class="muted">Solo el administrador puede crear cuentas y asignar permisos.</p></div></header><main class="lab-main admin-wrap"><?php if($notice!==''):?><p class="notice" role="status"><?=au_h($notice)?></p><?php endif;?><?php if($error!==''):?><p class="error" role="alert"><?=au_h($error)?></p><?php endif;?>
<section class="card"><h2>Uso de datos</h2><p>Transferencias, gráficas y actividad.</p><a class="btn" href="usage.php?scope=all&amp;return=users<?= !empty($_GET['embed'])?'&amp;embed=1':'' ?>">Ver consumo</a></section><section class="card"><h2>Dispositivos y sesiones de usuarios</h2><p>Consulta accesos recientes por cuenta y revoca los que correspondan.</p><a class="btn" href="devices.php?admin=1<?= !empty($_GET['embed']) ? '&amp;embed=1' : '' ?>">Revisar accesos de usuarios</a></section><section class="card"><h2>Crear cuenta</h2><p class="muted">Las cuentas nuevas son usuarios normales. Marca el permiso de gestión solo para quienes deban crear, subir o editar álbumes.</p><form method="post" class="create-grid"><input type="hidden" name="csrf_token" value="<?=au_h($csrf)?>"><input type="hidden" name="action" value="create"><label>Nombre de usuario<input type="text" name="username" required minlength="3" maxlength="80" autocomplete="off" pattern="[A-Za-z0-9_.@-]+"></label><label>Contraseña inicial (mínimo 14 caracteres)<input type="password" name="password" required minlength="14" maxlength="72" autocomplete="new-password"></label><label><span>Permisos</span><span><input type="checkbox" name="can_manage_albums" value="1"> Puede crear, subir y editar álbumes</span></label><div><button class="btn primary" type="submit">Crear cuenta</button></div></form></section>
<section class="card"><h2>Cuentas existentes (<?=count($users)?>)</h2>
<div class="user-search">
 <label for="userSearch">Buscar usuario</label>
 <input type="search" id="userSearch" placeholder="Buscar usuario…" autocomplete="off" aria-controls="usersTable">
 <span class="search-count" id="userSearchCount" role="status" aria-live="polite"></span>
 <span class="bulk-status" id="usersBulkStatus" role="status" aria-live="polite"></span>
 <span class="bulk-status" id="usersDeleteStatus" role="status" aria-live="polite"></span>
</div>
<form method="post" id="usersBulkForm">
<input type="hidden" name="csrf_token" value="<?=au_h($csrf)?>">
<input type="hidden" name="action" value="update_bulk">
<?php $downloadPermissions=sfd_db()->query('SELECT user_id,enabled FROM sf_audio_download_permissions')->fetchAll(PDO::FETCH_KEY_PAIR); ?>
<div class="user-table" id="usersTable">
<?php foreach($users as $u): $uid=(string)$u['id']; $protected=lab_auth_is_protected_admin_id($uid); $isActive=(int)$u['is_active']===1; $isAdmin=(string)$u['role']==='admin'; $resetId=au_reset_form_id($uid); ?>
<div class="user-row<?=$protected?' admin-row':''?>" data-search="<?=au_h(strtolower((string)$u['username']))?>">
 <div class="user-id"><strong><?=au_h((string)$u['username'])?></strong><div class="account-state muted"><?=au_h((string)$u['role'])?> · <?=$isActive?'Activa':'Desactivada'?><?php if($isAdmin):?> · acceso completo<?php endif;?></div>
 <?php if($protected):?><details class="protected-details"><summary>Cuenta principal protegida</summary><p>Esta cuenta conserva sus permisos de administración. Está protegida contra la desactivación y la eliminación.</p></details><?php endif;?>
 </div>
 <input type="hidden" name="download[<?=au_h($uid)?>]" value="0"><label class="user-check user-download" title="Permite preparar y descargar audio en M4A o MP3. No concede permisos de gestión."><input type="checkbox" name="download[<?=au_h($uid)?>]" value="1" <?=!empty($downloadPermissions[$uid])?'checked':''?>> Permitir descarga</label>
 <?php if($protected):?>
  <label class="user-check"><input type="checkbox" checked disabled aria-label="Cuenta principal siempre activa"> Activa</label>
  <label class="user-check"><input type="checkbox" checked disabled aria-label="La cuenta principal siempre puede gestionar álbumes"> gestionar álbumes</label>
  <input class="user-pass" type="password" name="password" form="<?=au_h($resetId)?>" placeholder="Nueva contraseña" aria-label="Nueva contraseña" maxlength="72" autocomplete="new-password" onclick="this.value=''" onfocus="this.value=''" oninput="this.setCustomValidity('')">
  <button class="btn user-reset" type="submit" form="<?=au_h($resetId)?>">Restablecer contraseña</button>
  <button class="btn bulk-save-primary" type="submit" title="Guardar todo cambio realizado en cualquier usuario" aria-label="Guardar todo cambio realizado en cualquier usuario">Guardar cambios</button>
 <?php else:?>
  <input type="hidden" name="active[<?=au_h($uid)?>]" value="0">
  <label class="user-check"><input type="checkbox" name="active[<?=au_h($uid)?>]" value="1" <?=$isActive?'checked':''?>> Activa</label>
  <input type="hidden" name="manage[<?=au_h($uid)?>]" value="0">
  <label class="user-check"><input type="checkbox" name="manage[<?=au_h($uid)?>]" value="1" <?=(((int)$u['can_manage_albums']===1 || $isAdmin)?'checked':'')?> <?=($isAdmin?'disabled':'')?> aria-label="Puede gestionar álbumes"> gestionar álbumes</label>
  <input class="user-pass" type="password" name="password" form="<?=au_h($resetId)?>" placeholder="Nueva contraseña" aria-label="Nueva contraseña" maxlength="72" autocomplete="new-password" onclick="this.value=''" onfocus="this.value=''" oninput="this.setCustomValidity('')">
  <button class="btn user-reset" type="submit" form="<?=au_h($resetId)?>">Restablecer contraseña</button>
 <?php endif;?>
 <?php $deleteBlocked=$protected||$uid===(string)($_SESSION['user_id']??''); ?>
 <button type="button" class="btn user-delete" data-delete-account="<?=au_h($uid)?>" title="<?=au_h($protected?'La cuenta principal está protegida':($deleteBlocked?'No puedes eliminar tu propia cuenta':'Eliminar cuenta'))?>" aria-label="<?=au_h('Eliminar cuenta '.$u['username'])?>" <?=$deleteBlocked?'disabled':''?>>
  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 6h18M9 6V4h6v2m4 0-1 14H6L5 6m5 4v6m4-6v6"/></svg>
 </button>
</div>
<?php endforeach;?>
</div>
</form>
<div class="user-reset-forms" hidden>
<?php foreach($users as $u): $uid=(string)$u['id']; ?>
<form method="post" id="<?=au_h(au_reset_form_id($uid))?>">
 <input type="hidden" name="csrf_token" value="<?=au_h($csrf)?>">
 <input type="hidden" name="action" value="reset_password">
 <input type="hidden" name="id" value="<?=au_h($uid)?>">
</form>
<?php endforeach;?>
</div>
<p class="user-search-empty" id="userSearchEmpty" hidden>No se encontraron cuentas.</p>
<div class="bulk-actions" id="usersBulkFallback" hidden>
 <button class="btn bulk-save-primary" type="submit" form="usersBulkForm" title="Guardar todo cambio realizado en cualquier usuario" aria-label="Guardar todo cambio realizado en cualquier usuario">Guardar cambios</button>
 <span class="hint muted">La fila del administrador está oculta por la búsqueda; este botón guarda igualmente todo el formulario.</span>
</div>
<script>
(()=>{const input=document.getElementById('userSearch');const table=document.getElementById('usersTable');if(!input||!table)return;const rows=[...table.querySelectorAll('.user-row')];const count=document.getElementById('userSearchCount');const empty=document.getElementById('userSearchEmpty');const fallback=document.getElementById('usersBulkFallback');const status=document.getElementById('usersBulkStatus');const bulk=document.getElementById('usersBulkForm');const primary=table.querySelector('.bulk-save-primary');function apply(){const currentRows=rows.filter(row=>row.isConnected);const total=currentRows.length;const q=input.value.trim().toLowerCase();let visible=0;for(const row of currentRows){const hit=!q||(row.dataset.search||'').includes(q);row.hidden=!hit;if(hit)visible++;}if(count)count.textContent=q?visible+' de '+total+' cuentas':'';if(empty)empty.hidden=visible!==0;if(fallback)fallback.hidden=!(primary&&primary.offsetParent===null);}input.addEventListener('input',apply);table.addEventListener('sf:account-deleted',apply);apply();document.querySelectorAll('form[id^="reset-"]').forEach(form=>{form.addEventListener('submit',e=>{const pass=form.elements.password;if(!pass)return;if(pass.value.length<14){pass.setCustomValidity('La contraseña debe tener al menos 14 caracteres.');pass.reportValidity();e.preventDefault();return;}pass.setCustomValidity('');});});if(!bulk)return;const markDirty=()=>{bulk.dataset.dirty='1';if(status)status.textContent='Cambios sin guardar';};bulk.addEventListener('change',e=>{if(e.target.matches('input[type=checkbox]'))markDirty();});bulk.addEventListener('submit',()=>{delete bulk.dataset.dirty;if(status)status.textContent='';});window.addEventListener('beforeunload',e=>{if(bulk.dataset.dirty!=='1')return;e.preventDefault();e.returnValue='';});})();
</script>
</section>
<section class="card"><h2>Qué puede hacer cada tipo de cuenta</h2><ul><li><strong>Administrador:</strong> acceso completo, configuración y administración de cuentas.</li><li><strong>Usuario con permiso de álbumes:</strong> reproducción y biblioteca, además de crear álbumes, subir audio, importar desde YouTube y editar/eliminar álbumes y pistas.</li><li><strong>Usuario estándar:</strong> solo explorar la biblioteca y reproducir audio.</li></ul><p class="hint muted">Las contraseñas se guardan mediante password_hash(). El panel nunca muestra hashes ni contraseñas existentes.</p></section></main><link rel="stylesheet" href="assets/player.css?v=mini-contrast1"><script src="assets/navigation.js?v=album-return1"></script>
<link rel="stylesheet" href="assets/player-icons.css?v=3"><script src="assets/player-icons.js?v=3"></script><script src="assets/admin-users-delete.js?v=1" defer></script><script src="assets/shell-player.js?v=20261116-console1"></script></body></html>
