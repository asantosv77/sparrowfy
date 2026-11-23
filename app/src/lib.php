<?php
declare(strict_types=1);
// New files are private to the service owner and its group.
umask(0007);

// Require an authenticated session for all application pages/endpoints using lib.php.
require_once __DIR__ . '/auth.php';
lab_auth_require_login();
require_once __DIR__.'/lib/usage.php';
sf_usage_hook();
// Every HTTP mutation requires a session-bound token, including legacy endpoints.
if (PHP_SAPI !== 'cli' && !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET','HEAD','OPTIONS'], true)) {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!is_string($token) || !lab_auth_verify_csrf($token)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['error'=>'Sesión del formulario caducada. Recarga la página.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

const LAB_ROOT = __DIR__;
const LAB_DATA = __DIR__ . '/data';
const LAB_ALBUMS = __DIR__ . '/data/albums';
const LAB_HLS = __DIR__ . '/hls_out';
const LAB_CONFIG = __DIR__ . '/data/config.json';

function lab_h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Headers defensivos sin impedir el reproductor embebido de la maqueta. */
function lab_security_headers(): void {
    if (headers_sent()) return;
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('X-Frame-Options: SAMEORIGIN');
}

/** Reduce CSRF en endpoints mutables sin introducir cuentas/login. */
function lab_require_same_origin_post(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;

    $site = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
    if ($site === 'cross-site') {
        http_response_code(403);
        throw new RuntimeException('Solicitud cross-site bloqueada. Recarga la maqueta e inténtalo de nuevo.');
    }
    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    $referer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
    $source = $origin !== '' ? $origin : ($referer !== '' ? $referer : '');
    if ($source === '') return; // clientes de desarrollo/CLI pueden omitir ambos encabezados
    $parts = parse_url($source);
    $host = strtolower((string) ($parts['host'] ?? ''));
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    $requestScheme = ((($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') || $forwardedProto === 'https') ? 'https' : 'http';
    $requestHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $requestParts = parse_url('http://' . $requestHost);
    $requestName = strtolower((string) ($requestParts['host'] ?? ''));
    $requestPort = (int) ($requestParts['port'] ?? ($requestScheme === 'https' ? 443 : 80));
    if ($host === '' || $requestName === '' || $host !== $requestName || $port !== $requestPort || $scheme !== $requestScheme) {
        http_response_code(403);
        throw new RuntimeException('Origen no permitido.');
    }
}

function lab_json_error(Throwable $e, int $status = 400): never {
    http_response_code($status);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

lab_security_headers();
require_once __DIR__.'/lib/search.php';

function lab_safe_audio_extensions(): array {
    return ['mp3', 'flac', 'ogg', 'oga', 'wma', 'm4a', 'wav', 'aac', 'aif', 'aiff', 'opus', 'webm', 'weba'];
}

function lab_allowed_audio_extensions(array $cfg): array {
    $allowed = array_values(array_unique(array_map(
        static fn($e) => strtolower((string)$e),
        (array)($cfg['allowed_extensions'] ?? [])
    )));
    // WebM/WebA son contenedores de audio Opus/Ogg. Si el administrador ya
    // permite Opus, se aceptan tambien para que el fallback al servidor no
    // rechace los WebM que el PC no pudo convertir.
    if (in_array('opus', $allowed, true)) {
        foreach (['webm', 'weba'] as $ext) {
            if (!in_array($ext, $allowed, true)) $allowed[] = $ext;
        }
    }
    return array_values(array_intersect(lab_safe_audio_extensions(), $allowed));
}

require_once __DIR__.'/settings.php';

function lab_ffmpeg(): array {
    $ffmpeg = trim((string) shell_exec('command -v ffmpeg 2>/dev/null'));
    $ffprobe = trim((string) shell_exec('command -v ffprobe 2>/dev/null'));
    return [$ffmpeg, $ffprobe];
}

function lab_run(string $cmd): array {
    $out = []; $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}

function lab_probe_audio(string $path, string $ffprobe): array {
    $cmd = escapeshellarg($ffprobe)
        . ' -v error -select_streams a:0'
        . ' -show_entries stream=codec_name,bit_rate,sample_rate,channels,profile'
        . ' -show_entries format=duration,bit_rate'
        . ' -of json ' . escapeshellarg($path);
    [$code, $out] = lab_run($cmd);
    if ($code !== 0) return ['ok' => false, 'error' => $out];
    $j = json_decode($out, true);
    $stream = $j['streams'][0] ?? [];
    $format = $j['format'] ?? [];
    $br = (int) ($stream['bit_rate'] ?? 0);
    if ($br <= 0) $br = (int) ($format['bit_rate'] ?? 0);
    $kbps = $br > 0 ? (int) round($br / 1000) : 0;
    return [
        'ok' => true,
        'codec' => (string) ($stream['codec_name'] ?? ''),
        'profile' => (string) ($stream['profile'] ?? ''),
        'bit_rate' => $br,
        'kbps' => $kbps,
        'duration' => (float) ($format['duration'] ?? 0),
        'sample_rate' => (int) ($stream['sample_rate'] ?? 0),
        'channels' => (int) ($stream['channels'] ?? 0),
    ];
}

/**
 * Elige calidades AAC sin superar el bitrate del origen.
 * Reglas:
 * - Nunca rendition > source_kbps (no inventar 320 desde 192).
 * - Si source >= prefer_128_when_source_ge: incluir 96, 128 y tope (= source redondeado a escalón o max ladder <= source).
 * - Si source ~192: 96 + 192 (si 192 está en ladder o se usa el tope real).
 */
function lab_pick_renditions(int $sourceKbps, array $cfg, string $sourceCodec=''): array {
 require_once __DIR__.'/lib/quality_profiles.php';
 if(($cfg['quality_ladder_mode']??'profiles')==='profiles')return array_map(static fn($k)=>['name'=>$k.'k','bitrate'=>$k.'k','bandwidth'=>$k*1000,'kbps'=>$k],sf_quality_profile_rates($sourceKbps,$cfg,$sourceCodec));
    if ($sourceKbps <= 0) $sourceKbps = 128; // fallback conservador
    $ladder = array_map('intval', $cfg['quality_ladder_kbps'] ?? [64, 96, 128, 192]);
    sort($ladder);
    $low = (int) ($cfg['always_include_low_kbps'] ?? 64);
    $prefer128 = (int) ($cfg['prefer_128_when_source_ge'] ?? 256);

    // Candidatos del ladder que no superan el origen
    $candidates = array_values(array_filter($ladder, static fn ($k) => $k <= $sourceKbps));

    // Si el origen no coincide con un escalón, el tope es min(source, max ladder <= source)
    // Usamos el mayor candidato como "alta"; si no hay ninguno, un solo escalón = sourceKbps (acotado)
    if (!$candidates) {
        $top = max(32, min($sourceKbps, 320));
        return [['name' => $top . 'k', 'bitrate' => $top . 'k', 'bandwidth' => $top * 1000, 'kbps' => $top]];
    }

    $top = max($candidates);
    $picked = [];

    // Baja
    if ($low > 0 && $low < $top && $low <= $sourceKbps) {
        $picked[] = $low;
    } elseif ($candidates[0] < $top) {
        $picked[] = $candidates[0];
    }

    // Media: 128 solo si el origen es "alto" (>= prefer128) y 128 < top
    if ($sourceKbps >= $prefer128 && in_array(128, $ladder, true) && 128 < $top && 128 <= $sourceKbps) {
        $picked[] = 128;
    } else {
        // Para orígenes medios (p.ej. 192): no meter 128 si el usuario pidió solo 96+192
        // Opcional: escalón intermedio del ladder entre low y top
        foreach ($candidates as $k) {
            if ($k > ($picked[0] ?? 0) && $k < $top && $k !== 128) {
                // no añadir extras salvo 128 bajo la regla de arriba
            }
        }
    }

    $picked[] = $top;
    $picked = array_values(array_unique($picked));
    sort($picked);

    $out = [];
    foreach ($picked as $k) {
        $out[] = [
            'name' => $k . 'k',
            'bitrate' => $k . 'k',
            'bandwidth' => $k * 1000,
            'kbps' => $k,
        ];
    }
    return $out;
}

function lab_slug(string $name): string {
    $base = pathinfo($name, PATHINFO_FILENAME);
    $base = preg_replace('/[^\p{L}\p{N}\-_ ]+/u', '', $base) ?? 'track';
    $base = preg_replace('/\s+/', '_', trim($base)) ?? 'track';
    $base = substr($base, 0, 80);
    return $base !== '' ? $base : 'track';
}

function lab_id(): string {
    return bin2hex(random_bytes(8));
}

function lab_list_albums(): array {
    if(is_file(LAB_DATA.'/sqlite-catalog.enabled')){require_once __DIR__.'/lib/sqlite_catalog_read.php';return lab_sqlite_list_albums();}
    if (!is_dir(LAB_ALBUMS)) return [];
    $list = [];
    foreach (scandir(LAB_ALBUMS) ?: [] as $id) {
        if ($id === '.' || $id === '..') continue;
        $meta = LAB_ALBUMS . '/' . $id . '/album.json';
        if (!is_file($meta)) continue;
        $a = json_decode((string) file_get_contents($meta), true);
        if (is_array($a)) $list[] = $a;
    }
    usort($list, static fn ($x, $y) => strcmp($y['created_at'] ?? '', $x['created_at'] ?? ''));
    return $list;
}

function lab_load_album(string $id): ?array {
    if(is_file(LAB_DATA.'/sqlite-catalog.enabled')){require_once __DIR__.'/lib/sqlite_catalog_read.php';return lab_sqlite_load_album($id);}
    $id = preg_replace('/[^a-f0-9]/', '', strtolower($id)) ?? '';
    if ($id === '' || !preg_match('/^[a-f0-9]{16}$/', $id)) {
        return null;
    }
    $meta = lab_album_path($id);
    if (!is_file($meta)) {
        return null;
    }
    $a = json_decode((string) file_get_contents($meta), true);
    return is_array($a) ? $a : null;
}

function lab_ensure_data_dirs(): void {
    foreach ([LAB_DATA, LAB_ALBUMS, LAB_HLS] as $d) {
        if (!is_dir($d) && !@mkdir($d, 0775, true) && !is_dir($d)) {
            throw new RuntimeException('No se pudo crear directorio: ' . $d . ' (revisa permisos de escritura del usuario del servidor web)');
        }
    }
}

function lab_save_album(array $album): void {
    lab_ensure_data_dirs();
    $id = (string) ($album['id'] ?? '');
    if ($id === '' || !preg_match('/^[a-f0-9]{16}$/', $id)) {
        throw new RuntimeException('ID de álbum inválido');
    }
    $dir = LAB_ALBUMS . '/' . $id;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('No se pudo crear ' . $dir);
    }
    $file = $dir . '/album.json';
    $json = json_encode($album, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('JSON de álbum inválido');
    }
    require_once __DIR__.'/lib/sqlite_catalog_sync.php';
    // Retry the entire transaction: a WAL read snapshot cannot be upgraded
    // after another connection commits, regardless of the busy timeout.
    for ($attempt=0; $attempt<3; $attempt++) {
        $catalogDb=lab_auth_pdo();
        $catalogDb->beginTransaction();
        try {
            lab_sqlite_sync_album($album,$catalogDb);
            if (file_put_contents($file,$json,LOCK_EX)===false) {
                throw new RuntimeException('No se pudo escribir el álbum (permisos).');
            }
            @chmod($file,0664);
            $catalogDb->commit();
            return;
        } catch (Throwable $e) {
            if ($catalogDb->inTransaction()) $catalogDb->rollBack();
            $busy=$e instanceof PDOException && (((int)($e->errorInfo[1]??0)&255)===5);
            if (!$busy || $attempt===2) throw $e;
            $catalogDb=null;
            usleep(random_int(60000,180000));
        }
    }
}

function lab_album_path(string $id): string {
    $id = preg_replace('/[^a-f0-9]/', '', $id) ?? '';
    return LAB_ALBUMS . '/' . $id . '/album.json';
}

/**
 * Carpeta padre del álbum dentro de hls_out: hls_out/{albumId}_{slugAlbum}/
 */
function lab_album_hls_dir(string $albumId, string $albumTitle = ''): string {
    $albumId = preg_replace('/[^a-f0-9]/', '', $albumId) ?? '';
    $slug = lab_slug($albumTitle !== '' ? $albumTitle : 'album');
    $folder = $albumId . '_' . $slug;
    $dir = LAB_HLS . '/' . $folder;
    if (!is_dir(LAB_HLS)) mkdir(LAB_HLS, 0755, true);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    return $dir;
}

/**
 * Convierte un audio a HLS bajo el directorio del álbum.
 * Estructura: hls_out/{albumId}_{slug}/{trackJob}/master.m3u8
 *
 * @param array{album_id?:string,album_title?:string} $ctx
 */
/**
 * Bitrate Opus equivalente a la calidad de un MP3. Ya no se usa en el flujo de
 * publicación (la fuente se conserva y la escalera se publica en AAC), pero se
 * mantiene por si se retoma la codificación Opus en el futuro.
 */
function lab_opus_kbps_for_source(int $mp3Kbps): int {
    if ($mp3Kbps >= 320) return 192;
    if ($mp3Kbps >= 256) return 160;
    if ($mp3Kbps >= 224) return 144;
    if ($mp3Kbps >= 192) return 128;
    if ($mp3Kbps >= 160) return 112;
    if ($mp3Kbps >= 128) return 96;
    if ($mp3Kbps >= 112) return 80;
    if ($mp3Kbps >= 96) return 64;
    if ($mp3Kbps >= 80) return 56;
    if ($mp3Kbps >= 64) return 48;
    return max(32, min(48, (int) round($mp3Kbps * 0.75)));
}

function lab_convert_to_hls(string $srcPath, string $originalName, array $cfg, array $ctx = []): array {
    [$ffmpeg, $ffprobe] = lab_ffmpeg();
    if ($ffmpeg === '' || $ffprobe === '') {
        throw new RuntimeException('ffmpeg/ffprobe no disponibles');
    }
    $probe = lab_probe_audio($srcPath, $ffprobe);
    if (empty($probe['ok'])) {
        throw new RuntimeException('El archivo no parece ser audio válido: ' . ($probe['error'] ?? ''));
    }
    if ((float) ($probe['duration'] ?? 0) <= 0 || (float) $probe['duration'] > 10800) {
        throw new RuntimeException('Duración no válida o superior a 3 horas.');
    }
    if ((int) ($probe['channels'] ?? 0) > 8 || (int) ($probe['sample_rate'] ?? 0) > 192000) {
        throw new RuntimeException('El audio supera los límites admitidos de canales o frecuencia de muestreo.');
    }
    $renditions = lab_pick_renditions((int) $probe['kbps'], $cfg, (string)($probe['codec']??''));
    $slug = lab_slug($originalName);
    $jobId = $slug . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));

    $albumId = (string) ($ctx['album_id'] ?? '');
    $albumTitle = (string) ($ctx['album_title'] ?? '');
    $createdAlbumDirCandidate = null;
    if ($albumId !== '') {
        $safeAlbumId = preg_replace('/[^a-f0-9]/', '', $albumId) ?? '';
        $createdAlbumDirCandidate = LAB_HLS . '/' . $safeAlbumId . '_' . lab_slug($albumTitle !== '' ? $albumTitle : 'album');
        $albumDirExisted = is_dir($createdAlbumDirCandidate);
        $albumDir = lab_album_hls_dir($albumId, $albumTitle);
        $albumFolder = basename($albumDir);
        $baseDir = $albumDir . '/' . $jobId;
        $relMasterPrefix = 'hls_out/' . $albumFolder . '/' . $jobId;
    } else {
        $albumDirExisted = true;
        if (!is_dir(LAB_HLS) && !mkdir(LAB_HLS, 0755, true) && !is_dir(LAB_HLS)) {
            throw new RuntimeException('No se pudo crear la raíz HLS.');
        }
        $baseDir = LAB_HLS . '/' . $jobId;
        $relMasterPrefix = 'hls_out/' . $jobId;
        $albumFolder = '';
    }

    try {
    if (!mkdir($baseDir, 0755, true) && !is_dir($baseDir)) {
        throw new RuntimeException('No se pudo crear directorio HLS');
    }
    // Copiar o mover source
    $logs = [];
    $storedSource = $baseDir . '/source' . (str_contains(strtolower($originalName), '.') ? '.' . strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) : '.audio');
    if (!@copy($srcPath, $storedSource)) {
        throw new RuntimeException('No se pudo copiar el origen');
    }

    // La fuente se conserva tal cual (MP3, M4A/AAC, etc.). Ya no se convierte a
    // Opus: la escalera HLS siempre se publica en AAC y convertir primero solo
    // gastaba CPU en la Raspberry y añadía una generación de pérdida. Si la
    // fuente ya es AAC, el tramo más alto se remuxa sin recodificar.
    $sourceCodec = (string) ($probe['codec'] ?? '');
    $sourceName = $originalName;
    $originalKbps = max(0, (int) ($probe['kbps'] ?? 0));

    $seg = max(2, min(12, (int) ($cfg['segment_seconds'] ?? 4)));
    // Mantener la frecuencia del origen en toda la escalera evita remuestreos y
    // que el reproductor cambie de 44,1 a 48 kHz al alternar calidades.
    // Si la fuente ya es AAC-LC de 44,1/48 kHz y su bitrate encaja con el tramo
    // más alto, el tramo alto se recodificaba con `-c:a copy`. Ese remux es
    // justo lo que provocaba el desfase (el remux no lleva el primado del AAC
    // de las calidades recodificadas), así que ya no se usa: la escalera
    // completa sale de una única pasada.
    $ladderRate = in_array((int) ($probe['sample_rate'] ?? 0), [44100, 48000], true)
        ? (int) $probe['sample_rate']
        : 44100;
    // Una sola pasada con asplit + var_stream_map: todas las calidades salen
    // del mismo decodificador, de modo que comparten el retardo de primado del
    // AAC. Antes el tramo superior iba con `-c:a copy` y los inferiores se
    // recodificaban en procesos aparte: eso dejaba 1024 muestras de desfase
    // (23,2 ms a 44,1 kHz / 21,3 ms a 48 kHz) y el reproductor chasqueaba al
    // cambiar de calidad.
    foreach ($renditions as $r) {
        $dir = $baseDir . '/' . $r['name'];
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo crear la carpeta de ' . $r['name']);
        }
    }
    $segmentPattern = $baseDir . '/%v/seg_%03d.ts';
    $playlistPattern = $baseDir . '/%v/prog.m3u8';
    $cmd = escapeshellarg($ffmpeg)
        . ' -nostdin -hide_banner -loglevel error -threads 2 -y -i ' . escapeshellarg($storedSource)
        . ' -vn';
    if (count($renditions) === 1) {
        $cmd .= ' -map 0:a:0 -c:a aac -b:a:0 ' . escapeshellarg($renditions[0]['bitrate'])
            . ' -ac 2 -ar ' . $ladderRate
            . ' -max_muxing_queue_size 1024 -f hls'
            . ' -hls_time ' . $seg
            . ' -hls_playlist_type vod'
            . ' -hls_flags independent_segments'
            . ' -hls_segment_filename ' . escapeshellarg($baseDir . '/' . $renditions[0]['name'] . '/seg_%03d.ts')
            . ' ' . escapeshellarg($baseDir . '/' . $renditions[0]['name'] . '/prog.m3u8');
    } else {
        $split = '[0:a:0]asplit=' . count($renditions);
        foreach ($renditions as $index => $r) {
            $split .= '[a' . $index . ']';
        }
        $cmd .= ' -filter_complex ' . escapeshellarg($split);
        $streamMap = [];
        foreach ($renditions as $index => $r) {
            $cmd .= ' -map ' . escapeshellarg('[a' . $index . ']')
                . ' -c:a aac -b:a:' . $index . ' ' . escapeshellarg($r['bitrate'])
                . ' -ac 2 -ar ' . $ladderRate;
            $streamMap[] = 'a:' . $index . ',name:' . $r['name'];
        }
        $cmd .= ' -max_muxing_queue_size 1024 -f hls'
            . ' -hls_time ' . $seg
            . ' -hls_playlist_type vod'
            . ' -hls_flags independent_segments'
            . ' -hls_segment_filename ' . escapeshellarg($segmentPattern)
            . ' -var_stream_map ' . escapeshellarg(implode(' ', $streamMap))
            . ' ' . escapeshellarg($playlistPattern);
    }
    $desktopConverted = false;
    if (!empty($cfg['desktop_workers_enabled']) && !empty($ctx['worker_user_id'])) {
        require_once __DIR__.'/lib/desktop_agent.php';
        $desktopConverted = sfda_try_convert($storedSource, $baseDir, $renditions, $probe, $cfg, $ctx);
    }
    if (!$desktopConverted) {
        [$code, $out] = lab_run($cmd);
        if ($code !== 0) throw new RuntimeException('FFmpeg falló al generar la escalera: ' . $out);
    } else {
        $logs[] = 'ENCODER desktop-worker';
    }
    foreach ($renditions as $r) {
        if (!is_file($baseDir . '/' . $r['name'] . '/prog.m3u8')) {
            throw new RuntimeException('FFmpeg no generó ' . $r['name']);
        }
        $logs[] = 'OK ' . $r['name'];
    }
    $logs[] = 'ALIGN single-pass';

    $master = ['#EXTM3U', '#EXT-X-VERSION:3', ''];
    foreach ($renditions as $r) {
        $master[] = '#EXT-X-STREAM-INF:BANDWIDTH=' . (int) ceil($r['bandwidth'] * 1.15) . ',AVERAGE-BANDWIDTH=' . (int) $r['bandwidth'] . ',CODECS="mp4a.40.2",NAME="' . $r['name'] . '"';
        $master[] = $r['name'] . '/prog.m3u8';
        $master[] = '';
    }
    $masterTmp = $baseDir . '/master.m3u8.tmp';
    if (file_put_contents($masterTmp, implode("\n", $master) . "\n", LOCK_EX) === false || !@rename($masterTmp, $baseDir . '/master.m3u8')) {
        @unlink($masterTmp);
        throw new RuntimeException('No se pudo publicar el manifiesto maestro.');
    }

    $highest = $renditions[count($renditions) - 1]['name'];

    // Validación previa a publicar: el master completo debe decodificarse sin
    // errores y su duración debe coincidir con la del origen. Si algo falla se
    // lanza la excepción y el bloque catch retira la conversión incompleta.
    [$decodeCode, $decodeOut] = lab_run(
        escapeshellarg($ffmpeg) . ' -nostdin -v error -i ' . escapeshellarg($baseDir . '/master.m3u8') . ' -f null -'
    );
    if ($decodeCode !== 0) {
        throw new RuntimeException('El master HLS no se decodifica: ' . $decodeOut);
    }
    [$durCode, $durOut] = lab_run(
        escapeshellarg($ffprobe) . ' -v error -show_entries format=duration -of default=nw=1:nk=1 '
        . escapeshellarg($baseDir . '/' . $highest . '/prog.m3u8')
    );
    $masterDuration = $durCode === 0 ? (float) trim($durOut) : 0.0;
    if ($masterDuration <= 0 || abs($masterDuration - (float) $probe['duration']) > 0.35) {
        throw new RuntimeException(sprintf(
            'Duración inesperada en el master: %.3f s (origen %.3f s)',
            $masterDuration, (float) $probe['duration']
        ));
    }

    $info = [
        'job_id' => $jobId,
        'album_id' => $albumId,
        'album_folder' => $albumFolder ?? '',
        'source_name' => $sourceName,
        'source_kbps' => $originalKbps,
        'source_codec' => $sourceCodec,
        'duration_sec' => $probe['duration'],
        'renditions' => array_map(static fn ($r) => $r['name'], $renditions),
        'highest' => $highest,
        'master' => 'master.m3u8',
        'master_rel' => $relMasterPrefix . '/master.m3u8',
        'source_file' => basename($storedSource),
        'source_deleted' => false,
        'created_at' => date('c'),
        'alignment' => 'single-pass',
        'logs' => $logs,
    ];

    if (!empty($cfg['delete_source_after_convert'])) {
        @unlink($storedSource);
        $info['source_deleted'] = true;
        $info['source_file'] = null;
    }

    $infoJson = json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($infoJson === false || file_put_contents($baseDir . '/info.json.tmp', $infoJson, LOCK_EX) === false || !@rename($baseDir . '/info.json.tmp', $baseDir . '/info.json')) {
        throw new RuntimeException('No se pudieron guardar los metadatos de la conversión.');
    }
    return $info;
    } catch (Throwable $e) {
        // Nunca dejar una conversión incompleta publicada en la biblioteca.
        if (is_dir($baseDir)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $item) { $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname()); }
            @rmdir($baseDir);
        }
        // Solo retirar la carpeta padre si esta petición la creó y quedó vacía.
        // Nunca tocar carpetas que ya contuvieran pistas de otros trabajos.
        if ($albumId !== '' && !$albumDirExisted && is_string($createdAlbumDirCandidate) && is_dir($createdAlbumDirCandidate)) {
            $leftovers = array_values(array_diff(scandir($createdAlbumDirCandidate) ?: [], ['.', '..']));
            if (!$leftovers) @rmdir($createdAlbumDirCandidate);
        }
        throw $e;
    }
}

/** Reconstruye un MP3/AAC a partir de la rendition más alta (concat demuxer). */
function lab_find_job_dir(string $jobId): ?string {
    if (
        $jobId === '' ||
        !preg_match('/\\A[\\p{L}\\p{N}_.-]+\\z/u', $jobId) ||
        $jobId === '.' ||
        $jobId === '..'
    ) return null;
    $direct = LAB_HLS . '/' . $jobId;
    if (is_dir($direct) && is_file($direct . '/info.json')) return $direct;
    if (!is_dir(LAB_HLS)) return null;
    foreach (scandir(LAB_HLS) ?: [] as $albumFolder) {
        if ($albumFolder === '.' || $albumFolder === '..') continue;
        $cand = LAB_HLS . '/' . $albumFolder . '/' . $jobId;
        if (is_dir($cand) && is_file($cand . '/info.json')) return $cand;
    }
    return null;
}

function lab_reconstruct_source(string $jobId): array {
    $base = lab_find_job_dir($jobId);
    if ($base === null) {
        throw new RuntimeException('Job no encontrado');
    }
    $info = json_decode((string) @file_get_contents($base . '/info.json'), true) ?: [];
    $highest = (string) ($info['highest'] ?? '');
    if ($highest === '' || !preg_match('/\A[a-zA-Z0-9_.-]+\z/D', $highest) ||
        $highest === '.' || $highest === '..' || !is_dir($base . '/' . $highest)) {
        throw new RuntimeException('No hay una variante HLS de alta calidad utilizable.');
    }
    $prog = $base . '/' . $highest . '/prog.m3u8';
    if (!is_file($prog) || !is_readable($prog)) throw new RuntimeException('Falta la lista prog.m3u8 de mayor calidad.');

    [$ffmpeg] = lab_ffmpeg();
    if ($ffmpeg === '') throw new RuntimeException('ffmpeg no disponible');

    // La carpeta HLS pertenece al proceso que convirtió el audio (normalmente root)
    // y www-data no puede escribir en ella. Si no hay permiso, reconstruimos en un
    // archivo temporal del sistema y el worker lo elimina al terminar.
    $dirWritable = is_writable($base);
    $outFile = $dirWritable
        ? $base . '/reconstructed.m4a'
        : rtrim(sys_get_temp_dir(), '/\\') . '/sf_recon_' . preg_replace('/[^a-zA-Z0-9_.-]/', '', $jobId) . '_' . bin2hex(random_bytes(4)) . '.m4a';
    $bitrate = preg_match('/\A(\d+)k\z/i', $highest, $m) === 1 ? $m[1] . 'k' : '192k';
    // ffmpeg lee el playlist HLS directamente
    $cmd = escapeshellarg($ffmpeg)
        . ' -nostdin -hide_banner -loglevel error -y -i ' . escapeshellarg($prog)
        . ' -vn -c:a aac -b:a ' . escapeshellarg($bitrate)
        . ' ' . escapeshellarg($outFile);
    [$code, $out] = lab_run($cmd);
    if ($code !== 0 || !is_file($outFile) || filesize($outFile) < 1024) {
        @unlink($outFile);
        $detail = trim(substr((string) $out, -800));
        throw new RuntimeException('Reconstrucción falló' . ($detail !== '' ? ': ' . $detail : '.'));
    }
    $info['reconstructed'] = basename($outFile);
    $info['reconstructed_path'] = $outFile;
    $info['reconstructed_at'] = date('c');
    if ($dirWritable) {
        @file_put_contents($base . '/info.json', json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
    return $info;
}
