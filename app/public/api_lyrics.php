<?php
/**
 * Letras con fallbacks:
 * 1) LRCLIB (sincronizadas + plano)
 * 2) lyrics.ovh (plano)
 * 3) Musixmatch (si hay API key en config)
 * 4) Genius (si hay access token en config)
 *
 * GET: artist, title, album (opcional), duration (opcional)
 * Nunca uses usuario/contraseña de colaborador aquí: solo API keys.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
// External providers must not hold the PHP session lock while audio loads.
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

function lyrics_fail(int $code, string $msg): never {
    http_response_code($code);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__.'/lib/external_services.php';

// --- main ---
$cfg = lab_load_config();
$artist = trim((string) ($_GET['artist'] ?? ''));
$title = lyrics_clean_title((string) ($_GET['title'] ?? ''));
$album = trim((string) ($_GET['album'] ?? ''));
$duration = (int) round((float) ($_GET['duration'] ?? 0));

if ($title === '') {
    lyrics_fail(400, 'Falta el título de la pista');
}

$mxKey = trim((string) ($cfg['musixmatch_api_key'] ?? ''));
$geniusToken = trim((string) ($cfg['genius_access_token'] ?? ''));

$tried = [];
$result = null;

// 1) LRCLIB
$tried[] = 'lrclib';
$result = lyrics_from_lrclib($artist, $title, $duration);

// 2) lyrics.ovh
if (!$result || (($result['plain'] ?? null) === null && ($result['synced'] ?? null) === null)) {
    $tried[] = 'lyrics.ovh';
    $ovh = lyrics_from_ovh($artist, $title);
    if ($ovh) {
        $result = $ovh;
    }
}

// 3) Musixmatch (API key)
if ((!$result || (empty($result['plain']) && empty($result['synced']))) && $mxKey !== '') {
    $tried[] = 'musixmatch';
    $mx = lyrics_from_musixmatch($artist, $title, $mxKey);
    if ($mx) {
        $result = $mx;
    }
}

// 4) Genius (token)
if ((!$result || (empty($result['plain']) && empty($result['synced']))) && $geniusToken !== '') {
    $tried[] = 'genius';
    $gn = lyrics_from_genius($artist, $title, $geniusToken);
    if ($gn) {
        $result = $gn;
    }
}

if (!$result) {
    echo json_encode([
        'title' => $title,
        'artist' => $artist,
        'album' => $album,
        'synced' => null,
        'plain' => null,
        'source' => null,
        'tried' => $tried,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'title' => $result['title'] ?? $title,
    'artist' => $result['artist'] ?? $artist,
    'album' => $album,
    'synced' => $result['synced'] ?? null,
    'plain' => $result['plain'] ?? null,
    'source' => $result['source'] ?? null,
    'tried' => $tried,
], JSON_UNESCAPED_UNICODE);
