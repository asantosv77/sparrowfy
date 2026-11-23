<?php
declare(strict_types=1);
/** YouTube adapter: yt-dlp invocation, validation, metadata and error classification.
 * Loaded through external_services.php; no HTTP dispatch or session side effects.
 * Maintenance guide: scripts/EXTERNAL_SERVICES.md.
 */
require_once dirname(__DIR__).'/youtube_url_normalizer.php';
function yt_bin(): string {
    $configured = trim((string)(getenv('YT_DLP_BIN') ?: ''));
    $candidates = array_filter([
        $configured,
        '/usr/local/bin/yt-dlp',
        '/usr/bin/yt-dlp',
        '/opt/yt-dlp/yt-dlp_linux_aarch64',
        '/opt/yt-dlp/yt-dlp',
    ]);
    foreach ($candidates as $candidate) {
        if (str_contains($candidate, '/') && is_file($candidate) && is_executable($candidate)) return $candidate;
    }
    $path = trim((string)@shell_exec('command -v yt-dlp 2>/dev/null'));
    if ($path !== '' && is_file($path) && is_executable($path)) return $path;
    throw new RuntimeException('No se encuentra yt-dlp en el contenedor. Define YT_DLP_BIN o instala el paquete en PATH.');
}

function yt_validate_url(string $url): string {
    $url = trim($url);
    if ($url === '' || strlen($url) > 2048) throw new RuntimeException('URL vacía o demasiado larga.');
    $url = hlsmlNormalizeYouTubeUrl($url);
    $p = parse_url($url);
    if (!is_array($p) || strtolower((string)($p['scheme'] ?? '')) !== 'https' || !empty($p['user']) || !empty($p['pass'])) {
        throw new RuntimeException('Usa una URL HTTPS válida de YouTube.');
    }
    $host = strtolower(rtrim((string)($p['host'] ?? ''), '.'));
    $allowed = in_array($host, ['youtu.be', 'youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com'], true);
    if (!$allowed || (isset($p['port']) && !in_array((int)$p['port'], [443], true))) throw new RuntimeException('Solo se permiten enlaces de YouTube o youtu.be.');
    $path = (string)($p['path'] ?? '/');
    $query = [];
    parse_str((string)($p['query'] ?? ''), $query);
    $videoId = '';
    if ($host === 'youtu.be') {
        $videoId = explode('/', trim($path, '/'))[0] ?? '';
    } elseif ($path === '/watch') {
        $videoId = (string)($query['v'] ?? '');
    } elseif (preg_match('#^/(?:shorts|live|embed|v)/([A-Za-z0-9_-]{6,})$#', $path, $m) === 1) {
        $videoId = $m[1];
    }
    if (!preg_match('/^[A-Za-z0-9_-]{6,}$/', $videoId)) throw new RuntimeException('El enlace debe apuntar a un video individual, no a una lista de reproducción.');

    // YouTube Music y m.youtube pueden responder distinto a yt-dlp. Normalizar
    // los enlaces de video a la URL canónica y quitar parámetros de playlist.
    $canonical = 'https://www.youtube.com/watch?v=' . rawurlencode($videoId);
    foreach (['t', 'start', 'end'] as $key) {
        if (isset($query[$key]) && is_scalar($query[$key]) && preg_match('/^[0-9hms]+$/i', (string)$query[$key])) {
            $canonical .= '&' . $key . '=' . rawurlencode((string)$query[$key]);
        }
    }
    return $canonical;
}

/** @return array{code:int,stdout:string,stderr:string} */
function yt_run(array $args, int $timeoutSeconds, int $maxOutput = 4194304): array {
    $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($args, $spec, $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
    if (!is_resource($proc)) throw new RuntimeException('No se pudo iniciar yt-dlp.');
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $start = microtime(true);
    $exit = -1;
    $timedOut = false;
    do {
        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        if (strlen($stdout) + strlen($stderr) > $maxOutput) {
            proc_terminate($proc, 15);
            usleep(250000);
            proc_terminate($proc, 9);
            foreach ([1, 2] as $i) if (is_resource($pipes[$i])) fclose($pipes[$i]);
            proc_close($proc);
            throw new RuntimeException('La respuesta de yt-dlp excedió el límite de seguridad.');
        }
        $status = proc_get_status($proc);
        if (!$status['running']) { $exit = (int)$status['exitcode']; break; }
        if (microtime(true) - $start > $timeoutSeconds) {
            $timedOut = true;
            proc_terminate($proc, 15);
            usleep(500000);
            $status = proc_get_status($proc);
            if ($status['running']) proc_terminate($proc, 9);
            break;
        }
        usleep(100000);
    } while (true);
    $stdout .= (string)stream_get_contents($pipes[1]);
    $stderr .= (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $closed = proc_close($proc);
    if ($exit < 0 && $closed >= 0) $exit = $closed;
    if ($timedOut) throw new RuntimeException('yt-dlp excedió el tiempo límite. Intenta de nuevo o revisa el acceso al video.');
    return ['code' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
}

/** Clasifica errores sin exponer la salida completa de yt-dlp (puede contener datos sensibles). */
function yt_failure_message(array $result, bool $cookiesProvided = false, string $phase = 'descarga'): string {
    $raw = strtolower((string)($result['stderr'] ?? '') . "\n" . (string)($result['stdout'] ?? ''));
    if (preg_match('/sign in to confirm|not a bot|confirm you.re not a bot|cookies.*(required|needed)|login required|sign in to|authentication required|please log in/', $raw)) {
        return $cookiesProvided
            ? 'YouTube rechazó la autenticación. Actualiza el archivo cookies.txt y vuelve a intentarlo.'
            : 'YouTube requiere autenticación o verificación. Proporciona un archivo cookies.txt válido y vuelve a intentarlo.';
    }
    if (preg_match('/age.restricted|confirm your age|age verification|inappropriate for some users/', $raw)) {
        return 'El video requiere verificación de edad. Utiliza cookies de una cuenta autorizada.';
    }
    if (preg_match('/private video|video is private/', $raw)) return 'El video es privado o tu cuenta no tiene permiso para verlo.';
    if (preg_match('/video unavailable|video is unavailable|not available in your country|geo.restricted/', $raw)) return 'El video no está disponible para esta cuenta o región.';
    if (preg_match('/failed to load cookies|invalid cookie|cookie file|could not read cookies/', $raw)) return 'No se pudo leer el archivo cookies.txt. Comprueba que esté en formato Netscape.';
    if (preg_match('/unable to download|timed out|connection reset|network is unreachable/', $raw)) return 'Error de red al contactar con YouTube. Intenta nuevamente.';
    return 'No se pudo completar la ' . ($phase === 'metadatos' ? 'inspección' : 'descarga') . ' de YouTube (yt-dlp, código ' . (int)($result['code'] ?? -1) . '). Revisa el acceso al video y el registro del servidor.';
}

/** Crea un fichero Netscape temporal con permisos privados y lo elimina al terminar la petición. */
function yt_cookie_temp(): ?string {
    $content = '';
    if (isset($_FILES['cookies_file']) && is_array($_FILES['cookies_file']) && (int)($_FILES['cookies_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $f = $_FILES['cookies_file'];
        if ((int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($f['tmp_name'] ?? ''))) {
            throw new RuntimeException('No se pudo leer el archivo de cookies.');
        }
        if ((int)($f['size'] ?? 0) > 1048576) throw new RuntimeException('El archivo de cookies supera 1 MiB.');
        $content = (string)file_get_contents((string)$f['tmp_name']);
    } else {
        $content = (string)($_POST['cookies_text'] ?? '');
    }
    if (trim($content) === '') return null;
    if (strlen($content) > 1048576) throw new RuntimeException('Las cookies superan 1 MiB.');
    $hasHeader = str_contains($content, '# Netscape HTTP Cookie File') || str_contains($content, '# HTTP Cookie File');
    $hasCookieRow = preg_match('/^(?:\.?[A-Za-z0-9_-]+\.)?[A-Za-z0-9._-]+\t(?:TRUE|FALSE)\t[^\t]+\t(?:TRUE|FALSE)\t\d+\t[^\t]+\t[^\t]+$/mi', $content) === 1;
    if (!$hasHeader && !$hasCookieRow) throw new RuntimeException('Las cookies deben estar en formato Netscape cookies.txt (no pegues solo el valor de una cookie).');
    $path = tempnam(sys_get_temp_dir(), 'hls-ytdlp-cookies-');
    if ($path === false) throw new RuntimeException('No se pudo crear el archivo temporal de cookies.');
    @chmod($path, 0600);
    if (file_put_contents($path, $content, LOCK_EX) === false) { @unlink($path); throw new RuntimeException('No se pudieron guardar las cookies temporales.'); }
    return $path;
}

function yt_metadata(string $url, ?string $cookies): array {
    $args = [yt_bin(), '--dump-single-json', '--skip-download', '--no-warnings', '--no-playlist', '--no-progress', '--socket-timeout', '15', '--retries', '1'];
    if ($cookies !== null) { $args[] = '--cookies'; $args[] = $cookies; }
    $args[] = $url;
    $r = yt_run($args, 75);
    if ($r['code'] !== 0) throw new RuntimeException(yt_failure_message($r, $cookies !== null, 'metadatos'));
    $meta = json_decode(trim($r['stdout']), true);
    if (!is_array($meta) || empty($meta['title'])) throw new RuntimeException('yt-dlp no devolvió metadatos válidos.');
    $formats = [];
    foreach ((array)($meta['formats'] ?? []) as $f) {
        if (!is_array($f) || empty($f['acodec']) || $f['acodec'] === 'none' || (!empty($f['vcodec']) && $f['vcodec'] !== 'none')) continue;
        $br = (float)($f['abr'] ?? $f['tbr'] ?? 0);
        $formats[] = [
            'format_id' => (string)($f['format_id'] ?? ''),
            'ext' => (string)($f['ext'] ?? ''),
            'codec' => (string)($f['acodec'] ?? 'unknown'),
            'kbps' => $br > 0 ? (int)round($br) : null,
            'sample_rate' => isset($f['asr']) ? (int)$f['asr'] : null,
            'filesize' => isset($f['filesize']) ? (int)$f['filesize'] : (isset($f['filesize_approx']) ? (int)$f['filesize_approx'] : null),
            'note' => (string)($f['format_note'] ?? ''),
        ];
    }
    // Preferencia de calidad: AAC (m4a) primero y, dentro de AAC, el bitrate más alto.
    // La escalera HLS siempre se publica en AAC, así que partir de AAC permite
    // remuxar el tramo alto sin decodificar Opus ni recodificar en la Raspberry Pi.
    // Si el video no ofrece AAC se usa Opus y, en último caso, el resto de códecs.
    $isAac = static fn(array $f): bool => stripos((string)($f['codec'] ?? ''), 'mp4a') === 0 || strtolower((string)($f['ext'] ?? '')) === 'm4a';
    $isOpus = static fn(array $f): bool => stripos((string)($f['codec'] ?? ''), 'opus') === 0 || strtolower((string)($f['ext'] ?? '')) === 'webm';
    usort($formats, static function ($a, $b) use ($isAac, $isOpus): int {
        $rank = static fn(array $f): int => $isAac($f) ? 0 : ($isOpus($f) ? 1 : 2);
        $codecOrder = $rank($a) <=> $rank($b);
        if ($codecOrder !== 0) return $codecOrder;
        return (($b['kbps'] ?? 0) <=> ($a['kbps'] ?? 0)) ?: strcmp($a['ext'], $b['ext']);
    });
    $unique = [];
    $seen = [];
    foreach ($formats as $f) {
        $key = $f['format_id'];
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $unique[] = $f;
        if (count($unique) >= 30) break;
    }
    return [
        'url' => $url,
        'id' => (string)($meta['id'] ?? ''),
        'title' => trim((string)$meta['title']),
        'uploader' => trim((string)($meta['uploader'] ?? $meta['channel'] ?? '')),
        'duration' => isset($meta['duration']) ? (int)$meta['duration'] : null,
        'duration_text' => isset($meta['duration']) ? gmdate(((int)$meta['duration'] >= 3600 ? 'H:i:s' : 'i:s'), (int)$meta['duration']) : 'Desconocida',
        'webpage_url' => (string)($meta['webpage_url'] ?? $url),
        'formats' => $unique,
        'best_audio' => $unique[0] ?? null,
    ];
}

/** Validar contra los IDs de audio anunciados nuevamente por yt-dlp para esta URL. */
function yt_validate_audio_format_id(string $formatId, array $metadata): string {
    if ($formatId === '') return '';
    if (strlen($formatId) > 100 || !preg_match('/^[a-zA-Z0-9._-]+$/D', $formatId)) {
        throw new RuntimeException('Identificador de formato de audio inválido.');
    }
    foreach ((array)($metadata['formats'] ?? []) as $f) {
        if (($f['format_id'] ?? '') === $formatId) return $formatId;
    }
    throw new RuntimeException('El formato de audio seleccionado ya no está disponible. Inspecciona nuevamente el enlace.');
}

function yt_safe_filename(string $name, string $ext): string {
    $name = trim($name);
    $name = preg_replace('/\.(?:audio|mp3|opus|webm|m4a|aac|ogg|mp4)$/i', '', $name) ?? $name;
    $name = preg_replace('~[\\\\/\x00-\x1F:*?"<>|]+~u', '_', $name) ?? $name;
    $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
    $name = trim($name, " .\t\n\r\0\x0B");
    if ($name === '') $name = 'Pista';
    if (function_exists('mb_substr')) $name = mb_substr($name, 0, 170, 'UTF-8'); else $name = substr($name, 0, 170);
    return $name . '.' . $ext;
}

