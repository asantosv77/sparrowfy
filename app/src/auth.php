<?php
declare(strict_types=1);
require_once __DIR__.'/lib/devices.php';

/**
 * ID inmutable de la cuenta administradora principal de esta instalación.
 * No se identifica por username, que puede cambiar.
 */
const LAB_PROTECTED_ADMIN_ID = '759409f6084a7db8f7e9de69f2de38b3';

function lab_auth_is_protected_admin_id(string $id): bool {
    return hash_equals(LAB_PROTECTED_ADMIN_ID, $id);
}


/** Autenticación de HLS Music Lab. Requiere PHP 8.1+ y pdo_sqlite. */
function lab_auth_db_path(): string {
    $env = getenv('HLS_MUSIC_LAB_SQLITE_DB');
    return is_string($env) && trim($env) !== '' ? trim($env) : '/sparrowfy/database/library.sqlite';
}
function lab_auth_pdo(): PDO {
    $path = lab_auth_db_path();
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('No existe la base SQLite de la aplicación. Ejecuta primero el instalador CLI de autenticación.');
    }
    $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 15]);
    $pdo->exec('PRAGMA busy_timeout=15000');
    return $pdo;
}

/**
 * Diagnóstico temporal de las operaciones de sesión SQLite.
 * No registrar identificadores ni datos de sesión.
 */
function lab_auth_session_diag(
    string $operation,
    float $startedAt,
    string $result = 'ok',
    ?Throwable $exception = null
): void {
    $durationMs = round((microtime(true) - $startedAt) * 1000, 1);
    $message = sprintf(
        'HLS Music Lab session diag pid=%d operation=%s result=%s duration_ms=%.1f',
        getmypid(),
        $operation,
        $result,
        $durationMs
    );

    if ($exception !== null) {
        $message .= sprintf(
            ' exception=%s error=%s',
            get_class($exception),
            $exception->getMessage()
        );
    }

    error_log($message);
}

function lab_auth_is_https(): bool {
    $proto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $proto === 'https';
}
final class LabSqliteSessionHandler implements SessionHandlerInterface {
    private ?PDO $pdo = null;
    public function open(string $path, string $name): bool { try { $this->pdo = lab_auth_pdo(); return true; } catch (Throwable $e) { error_log('HLS Music Lab session: ' . $e->getMessage()); return false; } }
    public function close(): bool { $this->pdo = null; return true; }
    public function read(string $id): string|false {
        if (!$this->pdo) return false;
        try {
            $q = $this->pdo->prepare('SELECT session_data FROM auth_sessions WHERE session_id=? AND expires_at>?');
            $q->execute([$id, time()]);
            $v = $q->fetchColumn();
            return is_string($v) ? $v : '';
        } catch (Throwable $e) {
            error_log('HLS Music Lab session read failed: ' . get_class($e) . ': ' . $e->getMessage());
            throw $e;
        }
    }


public function write(string $id, string $data): bool {
    if (!$this->pdo) return false;

    $startedAt = microtime(true);

    try {
        $q = $this->pdo->prepare(
            'INSERT INTO auth_sessions(session_id,session_data,expires_at)
             VALUES(?,?,?)
             ON CONFLICT(session_id) DO UPDATE SET
             session_data=excluded.session_data,
             expires_at=excluded.expires_at'
        );

        $result = $q->execute([
            $id,
            $data,
            time() + (int)ini_get('session.gc_maxlifetime')
        ]);

        lab_auth_session_diag('write', $startedAt);
        return $result;
    } catch (Throwable $e) {
        lab_auth_session_diag('write', $startedAt, 'failed', $e);
        throw $e;
    }
}


public function destroy(string $id): bool {
    if (!$this->pdo) return false;

    $startedAt = microtime(true);

    try {
        $q = $this->pdo->prepare(
            'DELETE FROM auth_sessions WHERE session_id=?'
        );

        $result = $q->execute([$id]);

        lab_auth_session_diag('destroy', $startedAt);
        return $result;
    } catch (Throwable $e) {
        lab_auth_session_diag('destroy', $startedAt, 'failed', $e);
        throw $e;
    }
}


public function gc(int $max_lifetime): int|false {
    if (!$this->pdo) return false;

    $startedAt = microtime(true);

    try {
        $q = $this->pdo->prepare(
            'DELETE FROM auth_sessions WHERE expires_at<?'
        );

        $q->execute([time()]);
        $result = $q->rowCount();

        lab_auth_session_diag('gc', $startedAt);
        return $result;
    } catch (Throwable $e) {
        lab_auth_session_diag('gc', $startedAt, 'failed', $e);
        throw $e;
    }
}
}

function lab_auth_session_start(): void {
    if (PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_name('HLSMLSESSID');
    ini_set('session.save_path', getenv('PHP_SESSION_DIR') ?: '/var/lib/hlsml-sessions');
    // Sesiones gestionadas por el manejador nativo de PHP (archivos), no por SQLite.
    // Evita competir por bloqueos de escritura con usuarios y catálogo en library.sqlite.
    // Se conserva el nombre HLSMLSESSID y la configuración segura de cookies.
    // session_set_save_handler(new LabSqliteSessionHandler(), true);
    session_set_cookie_params(['lifetime' => 0, 'path' => rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/audio/index.php'))), '/') . '/', 'secure' => lab_auth_is_https(), 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
function lab_auth_session_matches(array $account, array $session): bool {
    $stamp = $session['credential_stamp'] ?? null;
    return is_string($stamp) && isset($account['password_hash']) &&
        hash_equals(hash('sha256', (string)$account['password_hash']), $stamp);
}
function lab_auth_current_account(): ?array {
    lab_auth_session_start();
    if (!isset($_SESSION['user_id'])) return null;
    try {
        $pdo = lab_auth_pdo();
        $q = $pdo->prepare('SELECT id, username, role, is_active, can_manage_albums, password_hash FROM users WHERE id = ? LIMIT 1');
        $q->execute([(string)$_SESSION['user_id']]);
        $row = $q->fetch();
        if (!is_array($row) || (int)$row['is_active'] !== 1 ||
            !lab_auth_session_matches($row, $_SESSION) || !sf_device_check($pdo,$row)) return null;
        unset($row['password_hash']);
        return $row;
    } catch (Throwable $e) {
        // Fail closed for permission checks; never grant access when account state cannot be verified.
        return null;
    }
}
function lab_auth_is_logged_in(): bool {
    lab_auth_session_start();
    if (!isset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'])) return false;
    $account = lab_auth_current_account();
    return $account !== null;
}
function lab_auth_is_admin(): bool {
    $account = lab_auth_current_account();
    return is_array($account) && (string)$account['role'] === 'admin';
}
function lab_auth_can_manage_albums(): bool {
    $account = lab_auth_current_account();
    if (!is_array($account)) return false;
    return (string)$account['role'] === 'admin' || (int)($account['can_manage_albums'] ?? 0) === 1;
}
function lab_auth_require_admin(): void {
    if (PHP_SAPI === 'cli' || lab_auth_is_admin()) return;
    http_response_code(403);
    header('Cache-Control: no-store');
    echo 'Acceso reservado al administrador.';
    exit;
}
function lab_auth_require_album_manager(): void {
    if (PHP_SAPI === 'cli' || lab_auth_can_manage_albums()) return;
    http_response_code(403);
    header('Cache-Control: no-store');
    $path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
    if (str_contains($path, '/api_')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Tu cuenta no tiene permiso para subir o editar álbumes.'], JSON_UNESCAPED_UNICODE);
    } else {
        echo 'Tu cuenta no tiene permiso para subir o editar álbumes.';
    }
    exit;
}
function lab_auth_login_path(): string {
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/audio/index.php');
    return rtrim(str_replace('\\', '/', dirname($script)), '/') . '/login.php';
}
function lab_auth_require_login(): void {
    if (PHP_SAPI === 'cli' || lab_auth_is_logged_in()) return;
    $path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
    $isApi = str_contains($path, '/api_') || str_ends_with($path, '/stream.php') || str_ends_with($path, '/convert.php');
    if ($isApi) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['error' => 'Autenticación requerida', 'login' => lab_auth_login_path()], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $login = lab_auth_login_path();
    $target = (string)($_SERVER['REQUEST_URI'] ?? '');
    header('Location: ' . $login . '?next=' . rawurlencode($target), true, 302);
    exit;
}
function lab_auth_csrf_token(): string {
    lab_auth_session_start();
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['csrf_token'];
}
function lab_auth_verify_csrf(?string $token): bool {
    lab_auth_session_start();
    $valid=is_string($token) && isset($_SESSION['csrf_token']) && hash_equals((string)$_SESSION['csrf_token'], $token);
    if(!$valid && PHP_SAPI!=='cli' && !headers_sent())header('X-SparrowFy-CSRF: invalid');
    return $valid;
}
function lab_auth_safe_next(string $next): string {
    // Solo rutas relativas dentro de esta aplicación; nunca redirecciones externas.
    if ($next === '' || $next[0] !== '/' || str_starts_with($next, '//') || str_contains($next, "\\") || preg_match('/[\r\n]/', $next)) return 'index.php';
    $base = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/audio/login.php'))), '/');
    if ($base !== '' && !str_starts_with($next, $base . '/') && $next !== $base) return 'index.php';
    return $next;
}
function lab_auth_attempt_key(string $username): string {
    return hash('sha256', strtolower(trim($username)) . "\0" . (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}
function lab_auth_login_blocked(PDO $pdo, string $key): bool {
    $q = $pdo->prepare('SELECT COUNT(*) FROM auth_login_attempts WHERE attempt_key=? AND attempted_at >= ? AND success=0');
    $q->execute([$key, time() - 900]);
    return (int)$q->fetchColumn() >= 8;
}
function lab_auth_record_attempt(PDO $pdo, string $key, bool $success): void {
    $q = $pdo->prepare('INSERT INTO auth_login_attempts(attempt_key, attempted_at, success) VALUES(?,?,?)');
    $q->execute([$key, time(), $success ? 1 : 0]);
    if ($success) {
        // A correct password clears the failure window for this account/IP pair.
        $pdo->prepare('DELETE FROM auth_login_attempts WHERE attempt_key=? AND success=0')->execute([$key]);
    }
    $pdo->prepare('DELETE FROM auth_login_attempts WHERE attempted_at < ?')->execute([time() - 86400]);
}

lab_auth_session_start();

require_once __DIR__.'/lib/remember_login.php';
if(PHP_SAPI !== 'cli' && ($_SERVER['REQUEST_METHOD']??'GET')==='GET' && !lab_auth_current_account()) {
    try { sf_remember_restore(); } catch(Throwable $e) { error_log('Remember login unavailable: '.get_class($e)); }
}
