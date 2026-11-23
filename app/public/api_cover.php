<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
lab_auth_require_login();
// Provider requests must not serialize HLS requests behind the session lock.
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=600');

require_once __DIR__.'/lib/external_services.php';

$artist = trim((string) ($_GET['artist'] ?? ''));
$title = trim((string) ($_GET['title'] ?? ''));
$albumQ = trim((string) ($_GET['album'] ?? ''));
$mode = (string) ($_GET['mode'] ?? 'one'); // one | search
$title = preg_replace('/^\d+[\s._\-]+/u', '', $title) ?? $title;
$termBase = trim(($artist !== '' ? $artist . ' ' : '') . ($albumQ !== '' ? $albumQ : $title));
if ($termBase === '' && $title === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Falta título o álbum']);
    exit;
}

$candidates = [];
$seen = [];

$push = static function (string $cover, string $source, string $album = '', string $artistName = '', string $trackName = '') use (&$candidates, &$seen): void {
    $cover = trim($cover);
    if ($cover === '' || isset($seen[$cover])) return;
    $seen[$cover] = true;
    $candidates[] = [
        'cover' => $cover,
        'source' => $source,
        'album' => $album,
        'artist' => $artistName,
        'title' => $trackName,
    ];
};

// 1) iTunes
$itunesTerm = $albumQ !== '' ? trim($artist . ' ' . $albumQ) : trim($artist . ' ' . $title);
$itunes = cov_get('https://itunes.apple.com/search?' . http_build_query([
    'term' => $itunesTerm !== '' ? $itunesTerm : $title,
    'media' => 'music',
    'entity' => $albumQ !== '' ? 'album' : 'song',
    'country' => 'mx',
    'limit' => 15,
]));
if ($itunes['code'] === 200) {
    $data = json_decode($itunes['body'], true);
    foreach (($data['results'] ?? []) as $row) {
        $art = (string) ($row['artworkUrl100'] ?? '');
        if ($art === '') continue;
        $art = str_replace('100x100bb', '600x600bb', $art);
        $push(
            $art,
            'itunes',
            (string) ($row['collectionName'] ?? ''),
            (string) ($row['artistName'] ?? ''),
            (string) ($row['trackName'] ?? '')
        );
    }
}

// 2) Deezer
$dq = urlencode($itunesTerm !== '' ? $itunesTerm : $title);
$deezer = cov_get('https://api.deezer.com/search' . ($albumQ !== '' ? '/album' : '') . '?q=' . $dq . '&limit=10');
if ($deezer['code'] === 200) {
    $data = json_decode($deezer['body'], true);
    $list = $data['data'] ?? [];
    foreach ($list as $row) {
        if ($albumQ !== '') {
            $cover = $row['cover_xl'] ?? $row['cover_big'] ?? $row['cover_medium'] ?? '';
            $push((string) $cover, 'deezer', (string) ($row['title'] ?? ''), (string) ($row['artist']['name'] ?? ''));
        } else {
            $cover = $row['album']['cover_xl'] ?? $row['album']['cover_big'] ?? '';
            $push((string) $cover, 'deezer', (string) ($row['album']['title'] ?? ''), (string) ($row['artist']['name'] ?? ''), (string) ($row['title'] ?? ''));
        }
    }
}

// 3) MusicBrainz + Cover Art (solo unos pocos)
$mq = 'https://musicbrainz.org/ws/2/release/?query=' . urlencode(
    ($artist !== '' ? 'artist:"' . $artist . '" AND ' : '') .
    ($albumQ !== '' ? 'release:"' . $albumQ . '"' : 'release:"' . $title . '"')
) . '&fmt=json&limit=5';
$mb = cov_get($mq);
if ($mb['code'] === 200) {
    $data = json_decode($mb['body'], true);
    foreach (array_slice($data['releases'] ?? [], 0, 5) as $rel) {
        $mbid = (string) ($rel['id'] ?? '');
        if ($mbid === '') continue;
        $ca = cov_get('https://coverartarchive.org/release/' . rawurlencode($mbid));
        if ($ca['code'] !== 200) continue;
        $cj = json_decode($ca['body'], true);
        $img = $cj['images'][0]['thumbnails']['large']
            ?? $cj['images'][0]['thumbnails']['small']
            ?? $cj['images'][0]['image']
            ?? '';
        $push((string) $img, 'musicbrainz', (string) ($rel['title'] ?? ''), (string) ($rel['artist-credit'][0]['name'] ?? ''));
    }
}

if ($mode === 'search') {
    echo json_encode(['candidates' => array_slice($candidates, 0, 12)], JSON_UNESCAPED_UNICODE);
    exit;
}

$first = $candidates[0] ?? null;
echo json_encode([
    'cover' => $first['cover'] ?? null,
    'source' => $first['source'] ?? null,
    'album' => $first['album'] ?? '',
    'candidates' => array_slice($candidates, 0, 8),
], JSON_UNESCAPED_UNICODE);
