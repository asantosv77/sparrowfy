<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
umask(0007);require_once __DIR__.'/lib/control_log.php';
header('Cache-Control: no-store');

// El botón de acceso fija el idioma (EN con bandera de EE. UU., ES con bandera de México).
$lang = 'es';
$postedLang = strtolower((string)($_POST['lang'] ?? ''));
if (in_array($postedLang, ['en', 'es'], true)) {
    $lang = $postedLang;
    $cookieParams = session_get_cookie_params();
    setcookie(
        'SF_LANG',
        $lang,
        time() + 60 * 60 * 24 * 365,
        (string)($cookieParams['path'] ?: '/'),
        '',
        lab_auth_is_https(),
        true
    );
    $_COOKIE['SF_LANG'] = $lang;
} elseif (isset($_COOKIE['SF_LANG']) && in_array((string)$_COOKIE['SF_LANG'], ['en', 'es'], true)) {
    $lang = (string)$_COOKIE['SF_LANG'];
}

$t = $lang === 'en' ? [
    'title' => 'Sign in · Sparrow-Fy',
    'intro' => 'Sign in to continue.',
    'username' => 'Username',
    'password' => 'Password',
    'remember' => 'Keep me signed in on this device',
    'remember_days' => 'For %d days. Use this only on a trusted device.',
    'csrf' => 'The form session expired. Reload the page and try again.',
    'rate' => 'Too many attempts. Wait 15 minutes before trying again.',
    'bad' => 'Incorrect username or password.',
    'fault' => 'Authentication is not configured yet or the database is unavailable. Run the CLI installer described in the README.',
    'reference' => 'Reference: ',
    'splash_title' => 'PREPARING YOUR MUSIC',
    'splash_skip' => 'Skip intro',
    'login_en' => 'Login',
    'login_es' => 'Iniciar sesión',
] : [
    'title' => 'Iniciar sesión · Sparrow-Fy',
    'intro' => 'Inicia sesión para continuar.',
    'username' => 'Usuario',
    'password' => 'Contraseña',
    'remember' => 'Mantener la sesión abierta en este equipo',
    'remember_days' => 'Durante %d días. Úsalo solo en un equipo de confianza.',
    'csrf' => 'La sesión del formulario expiró. Recarga la página e inténtalo de nuevo.',
    'rate' => 'Demasiados intentos. Espera 15 minutos antes de probar de nuevo.',
    'bad' => 'Usuario o contraseña incorrectos.',
    'fault' => 'Autenticación aún no configurada o base de datos no disponible. Ejecuta el instalador CLI indicado en el README.',
    'reference' => 'Referencia: ',
    'splash_title' => 'PREPARANDO TU MÚSICA',
    'splash_skip' => 'Omitir presentación',
    'login_en' => 'Login',
    'login_es' => 'Iniciar sesión',
];

if (lab_auth_is_logged_in()) { header('Location: index.php', true, 302); exit; }
$error = '';
$next = lab_auth_safe_next((string)($_GET['next'] ?? $_POST['next'] ?? ''));
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    sf_control_event('login.received',['account_fingerprint'=>hash('sha256',strtolower(trim((string)($_POST['username']??'')))),'session_cookie_present'=>isset($_COOKIE[session_name()])]);
    if (!lab_auth_verify_csrf(isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : null)) {
        sf_control_event('login.rejected',['reason'=>'csrf_invalid']);
        http_response_code(403); $error = $t['csrf'];
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $authStage = 'login.open_database';
        try {
            $pdo = lab_auth_pdo();
            $key = lab_auth_attempt_key($username);
            $authStage = 'login.check_rate_limit';
            if (lab_auth_login_blocked($pdo, $key)) {
                sf_control_event('login.rejected',['reason'=>'rate_limit']);
                http_response_code(429); $error = $t['rate'];
            } else {
                $authStage = 'login.lookup_user';
                $q = $pdo->prepare('SELECT id, username, password_hash, role, is_active FROM users WHERE username = ? COLLATE NOCASE LIMIT 1');
                $q->execute([$username]); $user = $q->fetch(); $q->closeCursor();
                $ok = is_array($user) && (int)$user['is_active'] === 1 && password_verify($password, (string)$user['password_hash']);
                sf_control_event('login.credentials_checked',['success'=>$ok,'reason'=>$ok?'valid':(!is_array($user)?'unknown_account':((int)$user['is_active']!==1?'inactive_account':'password_mismatch')),'user_id'=>is_array($user)?(string)$user['id']:'']);
                $authStage = 'login.record_attempt';
                lab_auth_record_attempt($pdo, $key, $ok);
                if ($ok) {
                    if (password_needs_rehash((string)$user['password_hash'], PASSWORD_DEFAULT)) {
                        $authStage = 'login.update_password_hash';
                        $newHash = password_hash($password, PASSWORD_DEFAULT);
                        $pdo->prepare('UPDATE users SET password_hash=?, updated_at=? WHERE id=?')->execute([$newHash, time(), $user['id']]);
                    }
                    $authStage = 'login.regenerate_session';
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = (string)$user['id'];
                    $_SESSION['username'] = (string)$user['username'];
                    $_SESSION['role'] = (string)$user['role'];
                    $_SESSION['auth_at'] = time();
                    $_SESSION['credential_stamp'] = hash('sha256', $newHash ?? (string)$user['password_hash']);
                    $authStage='login.register_device';
                    sf_device_login((string)$user['id']);
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    $pdo->prepare('UPDATE users SET last_login_at=? WHERE id=?')->execute([time(), $user['id']]);
                    $authStage='login.remember_token';
                    if (!empty($_POST['remember_me'])) sf_remember_issue((string)$user['id'], $_SESSION['credential_stamp']);
                    else sf_remember_forget();
                    $authStage='login.persist_session';
                    if(!session_write_close())throw new RuntimeException('Session persistence failed');
                    sf_control_event('login.success',['user_id'=>(string)$user['id']]);
                    header('Location: ' . $next, true, 302); exit;
                }
                $error = $t['bad'];
            }
        } catch (Throwable $e) {
            sf_control_error('login.exception',$e,['stage'=>$authStage]);
            error_log('HLS Music Lab auth [' . $authStage . ']: ' . get_class($e) . ': ' . $e->getMessage());
            http_response_code(503); $error = $t['fault'];
        }
    }
}
if($error!=='')$error.=' '.$t['reference'].sf_control_request_id();
?><!doctype html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#091519">
  <script>try{document.documentElement.classList.add(sessionStorage.getItem("sparrowfySplashSeen")==="1"?"sparrow-splash-seen":"sparrow-splash-enabled")}catch(e){}</script>
  <link rel="stylesheet" href="assets/splash.css?v=theme-sweep1"><meta name="robots" content="noindex,nofollow"><title><?= htmlspecialchars($t['title'], ENT_QUOTES, 'UTF-8') ?></title><script>try{document.documentElement.dataset.theme="dark-teal"}catch(e){document.documentElement.dataset.theme="dark-teal"}</script>
<link rel="stylesheet" href="assets/lab.css?v=theme-sweep1"><style>
.auth-wrap{min-height:100vh;display:grid;place-items:center;padding:1rem}.auth-card{width:min(100%,420px)}.auth-card label{display:block;margin:.85rem 0 .3rem}.auth-card input{width:100%;box-sizing:border-box;padding:.8rem}.auth-card button{width:100%;margin-top:1rem}.auth-actions{display:grid;grid-template-columns:1fr 1fr;gap:.5rem}.auth-actions button{margin-top:1rem;display:flex;align-items:center;justify-content:center;gap:.5rem;padding:.7rem}.auth-actions img{display:block;width:30px;height:auto;border-radius:2px}.auth-error{padding:.8rem;border-radius:.5rem;background:#fee2e2;color:#991b1b}@media(max-width:360px){.auth-actions{grid-template-columns:1fr}}
</style><link rel="manifest" href="manifest.webmanifest"><link rel="apple-touch-icon" href="assets/pwa-180.png"><meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes"><link rel="stylesheet" href="assets/about-pwa.css?v=1"><script src="assets/about-pwa.js?v=3" defer></script><meta name="sf-session-account" content="<?= htmlspecialchars((string)(lab_auth_current_account()['id']??''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><script src="assets/session-sync.js?v=auth-poll3" defer></script></head><body>
<div class="sparrow-splash" id="sparrowSplash" role="status" aria-label="Cargando Sparrow-Fy">
  <div class="sparrow-splash__content">
    <div class="sparrow-splash__halo" aria-hidden="true"></div>
    <img class="sparrow-splash__logo" src="assets/sparrowfy-emblem.webp" alt="" width="178" height="178" fetchpriority="high">
    <p class="sparrow-splash__title">SPARROW-FY</p>
    <p class="sparrow-splash__subtitle">HLS Music Lab</p>
    <div class="sparrow-splash__wave" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span></div>
    <span class="sparrow-splash__status"><?= htmlspecialchars($t['splash_title'], ENT_QUOTES, 'UTF-8') ?></span>
    <button class="sparrow-splash__skip" type="button" data-splash-skip><?= htmlspecialchars($t['splash_skip'], ENT_QUOTES, 'UTF-8') ?></button>
  </div>
</div><main class="auth-wrap"><section class="card auth-card"><h1 class="sparrow-brand"><img class="sparrow-brand__logo" src="assets/sparrowfy-emblem.webp" alt="" width="38" height="38"><span class="sparrow-brand__text"><strong>Sparrow-Fy</strong><small>HLS Music Lab</small></span></h1><p class="muted"><?= htmlspecialchars($t['intro'], ENT_QUOTES, 'UTF-8') ?></p><?php if ($error !== ''): ?><p class="auth-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?><form method="post" action="login.php"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(lab_auth_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="next" value="<?= htmlspecialchars($next, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><label for="username"><?= htmlspecialchars($t['username'], ENT_QUOTES, 'UTF-8') ?></label><input id="username" name="username" autocomplete="username" required maxlength="80" autofocus><label for="password"><?= htmlspecialchars($t['password'], ENT_QUOTES, 'UTF-8') ?></label><input id="password" name="password" type="password" autocomplete="current-password" required><label style="display:flex;align-items:center;gap:.6rem"><input type="checkbox" name="remember_me" value="1" style="width:auto"> <?= htmlspecialchars($t['remember'], ENT_QUOTES, 'UTF-8') ?></label><p class="muted"><?= htmlspecialchars(sprintf($t['remember_days'], sf_remember_days()), ENT_QUOTES, 'UTF-8') ?></p><div class="auth-actions"><button class="btn primary" type="submit" name="lang" value="en" aria-label="<?= htmlspecialchars($t['login_en'], ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($t['login_en'], ENT_QUOTES, 'UTF-8') ?>"><img src="assets/flag-us.svg" alt=""><span><?= htmlspecialchars($t['login_en'], ENT_QUOTES, 'UTF-8') ?></span></button><button class="btn primary" type="submit" name="lang" value="es" aria-label="<?= htmlspecialchars($t['login_es'], ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($t['login_es'], ENT_QUOTES, 'UTF-8') ?>"><img src="assets/flag-mx.svg" alt=""><span><?= htmlspecialchars($t['login_es'], ENT_QUOTES, 'UTF-8') ?></span></button></div></form></section></main><script src="assets/splash.js" defer></script>
</body></html>
