<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/youtube_url_normalizer.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

function yt_json(array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

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
    $proc = @proc_open($args, $spec, $pipes, __DIR__, null, ['bypass_shell' => true]);
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
    if ($r['code'] !== 0) throw new RuntimeException('No se pudo inspeccionar el video. Puede requerir cookies, no estar disponible o haber sido bloqueado por YouTube.');
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
    usort($formats, static fn($a, $b) => (($b['kbps'] ?? 0) <=> ($a['kbps'] ?? 0)) ?: strcmp($a['ext'], $b['ext']));
    $unique = [];
    $seen = [];
    foreach ($formats as $f) {
        $key = $f['ext'] . '|' . $f['codec'] . '|' . ($f['kbps'] ?? 'unknown');
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $unique[] = $f;
        if (count($unique) >= 8) break;
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

function yt_safe_filename(string $name, string $ext): string {
    $name = trim($name);
    $name = preg_replace('/\.(?:mp3|opus|webm|m4a|aac|ogg)$/i', '', $name) ?? $name;
    $name = preg_replace('~[\\\\/\x00-\x1F:*?"<>|]+~u', '_', $name) ?? $name;
    $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
    $name = trim($name, " .\t\n\r\0\x0B");
    if ($name === '') $name = 'Pista';
    if (function_exists('mb_substr')) $name = mb_substr($name, 0, 170, 'UTF-8'); else $name = substr($name, 0, 170);
    return $name . '.' . $ext;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') yt_json(['error' => 'POST requerido'], 405);
try { lab_require_same_origin_post(); } catch (Throwable $e) { yt_json(['error' => 'Solicitud no permitida. Recarga la página e inténtalo de nuevo.'], 403); }

if (function_exists('set_time_limit')) @set_time_limit(0);
$cookiePath = null;
register_shutdown_function(static function () use (&$cookiePath): void {
    if (is_string($cookiePath) && $cookiePath !== '' && is_file($cookiePath)) @unlink($cookiePath);
});
try {
    $action = (string)($_POST['action'] ?? '');
    if (in_array($action, ['inspect', 'import', 'create_online_album'], true)) lab_auth_require_album_manager();
    if (!in_array($action, ['inspect', 'import', 'create_online_album', 'resolve'], true)) yt_json(['error' => 'Acción no válida.'], 400);
    $cookiePath = yt_cookie_temp();

    if ($action === 'resolve') {
        $url = yt_validate_url((string)($_POST['url'] ?? ''));
        $args = [yt_bin(), '--no-warnings', '--no-playlist', '--no-progress', '--format', 'bestaudio', '--get-url', '--socket-timeout', '15', '--retries', '1'];
        if ($cookiePath !== null) { $args[] = '--cookies'; $args[] = $cookiePath; }
        $args[] = $url;
        $r = yt_run($args, 75, 262144);
        if ($r['code'] !== 0) throw new RuntimeException('YouTube no permitió resolver el audio. El video puede requerir acceso, estar bloqueado o no estar disponible.');
        $resolved = trim(explode("\n", trim($r['stdout']))[0] ?? '');
        $parts = parse_url($resolved);
        if (!$parts || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) throw new RuntimeException('yt-dlp no devolvió una URL de audio HTTPS válida.');
        yt_json(['success' => true, 'url' => $resolved, 'source_url' => $url]);
    }

    if ($action === 'create_online_album') {
        $rawTracks = json_decode((string)($_POST['tracks'] ?? ''), true);
        if (!is_array($rawTracks) || !$rawTracks || count($rawTracks) > 10) yt_json(['error' => 'Selecciona entre 1 y 10 pistas válidas.'], 400);
        $title = trim((string)($_POST['title'] ?? ''));
        $artist = trim((string)($_POST['artist'] ?? ''));
        if (function_exists('mb_substr')) { $title = mb_substr($title, 0, 160, 'UTF-8'); $artist = mb_substr($artist, 0, 160, 'UTF-8'); }
        else { $title = substr($title, 0, 160); $artist = substr($artist, 0, 160); }
        $tracks = []; $seen = [];
        foreach ($rawTracks as $item) {
            if (!is_array($item)) continue;
            $url = yt_validate_url((string)($item['url'] ?? ''));
            if (isset($seen[$url])) continue;
            $seen[$url] = true;
            // Revalidar en servidor; no confiar en metadatos enviados por el navegador.
            $meta = yt_metadata($url, $cookiePath);
            if (($meta['duration'] ?? 0) > 10800) throw new RuntimeException('La pista "' . $meta['title'] . '" supera el límite de 3 horas.');
            $tracks[] = [
                'id' => lab_id(),
                'name' => $meta['title'],
                'original' => $meta['title'],
                'duration' => (int)($meta['duration'] ?? 0),
                'source_type' => 'youtube',
                'source_url' => $meta['webpage_url'] ?: $url,
                'hls' => '',
                'renditions' => [],
            ];
        }
        if (!$tracks) yt_json(['error' => 'No quedó ninguna pista válida después de la validación.'], 400);
        $albumId = lab_id();
        if ($title === '') $title = 'Álbum de enlaces · ' . date('Y-m-d H:i');
        if ($artist === '') $artist = 'YouTube Music';
        $album = [
            'id' => $albumId,
            'title' => $title,
            'artist' => $artist,
            'created_at' => date(DATE_ATOM),
            'album_type' => 'online_links',
            'playback_mode' => 'youtube_direct',
            'tracks' => $tracks,
        ];
        lab_save_album($album);
        yt_json(['success' => true, 'album_id' => $albumId, 'tracks_count' => count($tracks), 'message' => 'Álbum virtual creado sin descargar audio.']);
    }

    if ($action === 'inspect') {
        $rawUrls = preg_split('/\R/u', (string)($_POST['urls'] ?? '')) ?: [];
        $urls = [];
        foreach ($rawUrls as $line) {
            $line = trim($line);
            if ($line !== '' && !in_array($line, $urls, true)) $urls[] = $line;
        }
        if (!$urls) yt_json(['error' => 'Pega al menos una URL de YouTube.'], 400);
        if (count($urls) > 10) yt_json(['error' => 'El límite es de 10 enlaces por lote.'], 400);
        $results = [];
        foreach ($urls as $url) {
            try { $results[] = ['ok' => true] + yt_metadata(yt_validate_url($url), $cookiePath); }
            catch (Throwable $e) { $results[] = ['ok' => false, 'url' => $url, 'error' => $e->getMessage()]; }
        }
        yt_json(['success' => true, 'results' => $results]);
    }

    lab_ensure_data_dirs();
    $albumId = strtolower(trim((string)($_POST['album_id'] ?? '')));
    if (!preg_match('/^[a-f0-9]{16}$/', $albumId)) yt_json(['error' => 'Álbum no válido.'], 400);
    $album = lab_load_album($albumId);
    if (!$album) yt_json(['error' => 'No se encontró el álbum.'], 404);
    $format = strtolower((string)($_POST['output_format'] ?? 'opus'));
    if (!in_array($format, ['mp3', 'opus'], true)) yt_json(['error' => 'Formato de salida no permitido.'], 400);
    $tracks = json_decode((string)($_POST['tracks'] ?? ''), true);
    if (!is_array($tracks) || !$tracks || count($tracks) > 10) yt_json(['error' => 'La lista de pistas no es válida (máximo 10).'], 400);
    if (function_exists('set_time_limit')) @set_time_limit(0);
    $bin = yt_bin();
    $cfg = lab_load_config();
    $results = [];
    foreach ($tracks as $idx => $item) {
        if (!is_array($item)) { $results[] = ['ok' => false, 'index' => $idx, 'error' => 'Elemento inválido.']; continue; }
        $tmpDir = null;
        try {
            $url = yt_validate_url((string)($item['url'] ?? ''));
            $filename = yt_safe_filename((string)($item['filename'] ?? $item['title'] ?? 'Pista'), $format);
            // Revalidar los metadatos en el servidor; no confiar en duración/título enviados por el navegador.
            $meta = yt_metadata($url, $cookiePath);
            if (($meta['duration'] ?? 0) > 10800) throw new RuntimeException('El video supera el límite de 3 horas.');
            $tmpDir = rtrim(sys_get_temp_dir(), '/') . '/hls-ytdlp-' . bin2hex(random_bytes(12));
            if (!mkdir($tmpDir, 0700)) throw new RuntimeException('No se pudo crear el directorio temporal de descarga.');
            $args = [$bin, '--no-warnings', '--no-playlist', '--no-progress', '--extract-audio', '--audio-format', $format, '--audio-quality', '0', '--match-filter', 'duration <= 10800', '--max-filesize', '500M', '--socket-timeout', '20', '--retries', '2', '--fragment-retries', '2', '--output', $tmpDir . '/%(id)s.%(ext)s', '--print', 'after_move:filepath'];
            if ($cookiePath !== null) { $args[] = '--cookies'; $args[] = $cookiePath; }
            $args[] = $url;
            $r = yt_run($args, 1200, 1048576);
            if ($r['code'] !== 0) throw new RuntimeException('Falló la descarga o conversión de audio. Revisa el acceso al video, las cookies y que yt-dlp/FFmpeg estén actualizados.');
            $candidates = [];
            foreach (scandir($tmpDir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                $path = $tmpDir . '/' . $entry;
                if (is_file($path) && preg_match('/\.(?:mp3|opus)$/i', $entry) && filesize($path) > 0) $candidates[] = $path;
            }
            if (count($candidates) !== 1) throw new RuntimeException('No se encontró un único archivo de audio final en formato ' . strtoupper($format) . '.');
            $audioPath = $candidates[0];
            $info = lab_convert_to_hls($audioPath, $filename, $cfg, ['album_id' => $album['id'], 'album_title' => (string)($album['title'] ?? 'album')]);
            $track = [
                'id' => lab_id(),
                'name' => pathinfo($filename, PATHINFO_FILENAME),
                'original' => $filename,
                'job_id' => $info['job_id'],
                'hls' => $info['master_rel'],
                'duration' => $info['duration_sec'],
                'source_kbps' => $info['source_kbps'],
                'renditions' => $info['renditions'],
                'source_url' => $meta['webpage_url'],
                'source_codec' => $info['source_codec'] ?? $format,
            ];
            // Serializar escrituras de este importador y releer el JSON para no pisar
            // pistas añadidas por otra importación concurrente.
            $lockPath = LAB_ALBUMS . '/' . $albumId . '/.youtube-import.lock';
            $lock = @fopen($lockPath, 'c');
            if (!is_resource($lock) || !flock($lock, LOCK_EX)) {
                if (is_resource($lock)) fclose($lock);
                throw new RuntimeException('No se pudo bloquear el álbum para guardar la pista.');
            }
            try {
                $latestAlbum = lab_load_album($albumId);
                if (!$latestAlbum) throw new RuntimeException('El álbum dejó de estar disponible durante la importación.');
                $latestAlbum['tracks'][] = $track;
                lab_save_album($latestAlbum); // conservar JSON como fuente de edición
                // El reproductor lee SQLite: sincronizar el álbum después de guardar JSON.
                // Si SQLite falla, el resultado lo indicará explícitamente; no se oculta el error.
                require_once __DIR__ . '/lib/sqlite_catalog_sync.php';
                lab_sqlite_sync_album($latestAlbum);
                $album = $latestAlbum;
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            $results[] = ['ok' => true, 'index' => $idx, 'title' => $track['name'], 'track' => $track];
        } catch (Throwable $e) {
            $results[] = ['ok' => false, 'index' => $idx, 'error' => $e->getMessage()];
        } finally {
            if ($tmpDir !== null && is_dir($tmpDir)) {
                foreach (scandir($tmpDir) ?: [] as $entry) {
                    if ($entry !== '.' && $entry !== '..') { $p = $tmpDir . '/' . $entry; if (is_file($p) || is_link($p)) @unlink($p); }
                }
                @rmdir($tmpDir);
            }
        }
    }
    $okCount = count(array_filter($results, static fn($r) => !empty($r['ok'])));
    yt_json(['success' => $okCount === count($results), 'imported' => $okCount, 'failed' => count($results) - $okCount, 'tracks_count' => count($album['tracks'] ?? []), 'results' => $results]);
} catch (Throwable $e) {
    yt_json(['error' => $e->getMessage()], 400);
} finally {
    if ($cookiePath !== null && is_file($cookiePath)) @unlink($cookiePath);
}
