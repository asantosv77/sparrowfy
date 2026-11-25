<?php
declare(strict_types=1);
/** Cover candidates for the Bearer API. Mirrors api_cover.php providers without web session. */
require_once __DIR__.'/external_services.php';

function sf_cover_lookup_candidates(string $artist, string $title, string $albumQ): array {
    $artist = trim($artist);
    $title = trim($title);
    $albumQ = trim($albumQ);
    $title = preg_replace('/^\d+[\s._\-]+/u', '', $title) ?? $title;
    $termBase = trim(($artist !== '' ? $artist.' ' : '').($albumQ !== '' ? $albumQ : $title));
    if ($termBase === '' && $title === '') return [];

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

    $itunesTerm = $albumQ !== '' ? trim($artist.' '.$albumQ) : trim($artist.' '.$title);
    $itunes = cov_get('https://itunes.apple.com/search?' . http_build_query([
        'term' => $itunesTerm !== '' ? $itunesTerm : $title,
        'media' => 'music',
        'entity' => $albumQ !== '' ? 'album' : 'song',
        'country' => 'mx',
        'limit' => 15,
    ]));
    if (($itunes['code'] ?? 0) === 200) {
        $data = json_decode((string)($itunes['body'] ?? ''), true);
        foreach (($data['results'] ?? []) as $row) {
            $art = (string)($row['artworkUrl100'] ?? '');
            if ($art === '') continue;
            $push(
                str_replace('100x100bb', '600x600bb', $art),
                'itunes',
                (string)($row['collectionName'] ?? ''),
                (string)($row['artistName'] ?? ''),
                (string)($row['trackName'] ?? '')
            );
        }
    }

    $deezer = cov_get('https://api.deezer.com/search'.($albumQ !== '' ? '/album' : '').'?q='.urlencode($itunesTerm !== '' ? $itunesTerm : $title).'&limit=10');
    if (($deezer['code'] ?? 0) === 200) {
        $data = json_decode((string)($deezer['body'] ?? ''), true);
        foreach (($data['data'] ?? []) as $row) {
            if ($albumQ !== '') {
                $cover = $row['cover_xl'] ?? $row['cover_big'] ?? $row['cover_medium'] ?? '';
                $push((string)$cover, 'deezer', (string)($row['title'] ?? ''), (string)($row['artist']['name'] ?? ''));
            } else {
                $cover = $row['album']['cover_xl'] ?? $row['album']['cover_big'] ?? '';
                $push((string)$cover, 'deezer', (string)($row['album']['title'] ?? ''), (string)($row['artist']['name'] ?? ''), (string)($row['title'] ?? ''));
            }
        }
    }

    $mbQuery = 'https://musicbrainz.org/ws/2/release/?query='.urlencode(
        ($artist !== '' ? 'artist:"'.$artist.'" AND ' : '').
        ($albumQ !== '' ? 'release:"'.$albumQ.'"' : 'release:"'.$title.'"')
    ).'&fmt=json&limit=5';
    $mb = cov_get($mbQuery);
    if (($mb['code'] ?? 0) === 200) {
        $data = json_decode((string)($mb['body'] ?? ''), true);
        foreach (array_slice(($data['releases'] ?? []), 0, 5) as $rel) {
            $mbid = (string)($rel['id'] ?? '');
            if ($mbid === '') continue;
            $ca = cov_get('https://coverartarchive.org/release/'.rawurlencode($mbid));
            if (($ca['code'] ?? 0) !== 200) continue;
            $cj = json_decode((string)($ca['body'] ?? ''), true);
            $img = $cj['images'][0]['thumbnails']['large']
                ?? $cj['images'][0]['thumbnails']['small']
                ?? $cj['images'][0]['image']
                ?? '';
            $push((string)$img, 'musicbrainz', (string)($rel['title'] ?? ''), (string)($rel['artist-credit'][0]['name'] ?? ''));
        }
    }

    return array_slice($candidates, 0, 12);
}

function sf_cover_first_https(array $candidates): string {
    foreach ($candidates as $candidate) {
        $url = trim((string)($candidate['cover'] ?? ''));
        if ($url !== '' && preg_match('~^https://~i', $url)) return $url;
    }
    return '';
}
