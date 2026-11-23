<?php
declare(strict_types=1);
/** LRCLIB, lyrics.ovh, Musixmatch and Genius adapters. No endpoint dispatch.
 * Keep LRCLIB matching, budget and fallback order compatible; see scripts/EXTERNAL_SERVICES.md. */
function lyrics_norm(string $text): string {
    $text = mb_strtolower(trim($text), 'UTF-8');
    return preg_replace('/[^\p{L}\p{N}]+/u', '', $text) ?? $text;
}

function lyrics_http_get(string $url, array $headers = [], int $timeout = 12): array {
    $headers = array_merge(['Accept: application/json', 'User-Agent: HLS-Music-Lab/1.1 (personal)'], $headers);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $code, 'body' => is_string($raw) ? $raw : ''];
    }
    $ctx = stream_context_create([
        'http' => [
            'timeout' => $timeout,
            'header' => implode("\r\n", $headers) . "\r\n",
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $code = (int) $m[1];
    }
    return ['code' => $code, 'body' => is_string($raw) ? $raw : ''];
}

function lyrics_clean_title(string $title): string {
    $title = preg_replace('/^\d+[\s._\-]+/u', '', $title) ?? $title;
    $title = preg_replace('/\.(mp3|flac|ogg|wma|m4a|wav|aac)$/i', '', $title) ?? $title;
    $title=preg_replace('/[\[(][^\])]*(?:official\s*(?:audio|music\s*video|video|lyric\s*video)|lyrics?|audio\s*only)[^\])]*[\])]/iu','',$title)??$title;
    return trim($title);
}

/** @return array{synced:?string,plain:?string,source:string,title?:string,artist?:string}|null */
function lyrics_from_lrclib(string $artist, string $title, int $duration): ?array {
    $deadline=microtime(true)+18; // Total budget across searches and retries.
    $queries = [];
    if ($artist !== '') {
        $queries[] = ['artist_name' => $artist, 'track_name' => $title];
    }
    $queries[] = ['track_name' => $title];
    if ($artist !== '') {
        $queries[] = ['q' => $artist . ' ' . $title];
    } else {
        $queries[] = ['q' => $title];
    }

    $best = null;
    $wantArtist = $artist !== '' ? lyrics_norm($artist) : '';
    $wantTitle = lyrics_norm($title);

    foreach ($queries as $q) {
        $res=['code'=>0,'body'=>''];
        for($attempt=0;$attempt<3;$attempt++){
            $remaining=$deadline-microtime(true);
            if($remaining<1)break;
            $res=lyrics_http_get('https://lrclib.net/api/search?'.http_build_query($q),[],max(1,min(6,(int)$remaining)));
            // Retry transport failure, throttling and transient provider errors only.
            if(!in_array($res['code'],[0,408,429,500,502,503,504],true))break;
            if($attempt<2 && $deadline-microtime(true)>1)usleep(250000*(1<<$attempt));
        }
        if ($res['code'] !== 200 || $res['body'] === '') {
            continue;
        }
        $results = json_decode($res['body'], true);
        if (!is_array($results)) {
            continue;
        }
        foreach (array_slice($results, 0, 25) as $entry) {
            if (!is_array($entry) || !empty($entry['instrumental'])) {
                continue;
            }
            $sync = (string) ($entry['syncedLyrics'] ?? '');
            $plain = (string) ($entry['plainLyrics'] ?? '');
            if ($sync === '' && $plain === '') {
                continue;
            }
            $eArtist = lyrics_norm((string) ($entry['artistName'] ?? ''));
            $eTitle = lyrics_norm((string) ($entry['trackName'] ?? ($entry['name'] ?? '')));
            $score = 0;
            if ($wantTitle !== '' && $eTitle === $wantTitle) {
                $score += 500;
            } elseif ($wantTitle !== '' && str_contains($eTitle, $wantTitle)) {
                $score += 200;
            }
            if ($wantArtist !== '' && $eArtist === $wantArtist) {
                $score += 400;
            } elseif ($wantArtist !== '' && str_contains($eArtist, $wantArtist)) {
                $score += 150;
            }
            if ($sync !== '') {
                $score += 1000;
            }
            if ($duration > 0) {
                $score -= abs((int) ($entry['duration'] ?? $duration) - $duration);
            }
            if ($best === null || $score > $best['score']) {
                $best = [
                    'score' => $score,
                    'synced' => $sync !== '' ? $sync : null,
                    'plain' => $plain !== '' ? $plain : null,
                    'artist' => (string) ($entry['artistName'] ?? ''),
                    'title' => (string) ($entry['trackName'] ?? ''),
                ];
            }
        }
        if ($best && !empty($best['synced']) && $best['score'] >= 1400) {
            break;
        }
    }

    if (!$best) {
        return null;
    }
    return [
        'synced' => $best['synced'],
        'plain' => $best['plain'],
        'source' => 'lrclib',
        'artist' => $best['artist'],
        'title' => $best['title'],
    ];
}

/** @return array{synced:?string,plain:?string,source:string}|null */
function lyrics_from_ovh(string $artist, string $title): ?array {
    if ($artist === '' || $title === '') {
        return null;
    }
    $url = 'https://api.lyrics.ovh/v1/' . rawurlencode($artist) . '/' . rawurlencode($title);
    $res = lyrics_http_get($url, [], 10);
    if ($res['code'] !== 200 || $res['body'] === '') {
        return null;
    }
    $data = json_decode($res['body'], true);
    $plain = trim((string) ($data['lyrics'] ?? ''));
    if ($plain === '') {
        return null;
    }
    // Normalizar saltos
    $plain = preg_replace("/\r\n|\r/", "\n", $plain) ?? $plain;
    return ['synced' => null, 'plain' => $plain, 'source' => 'lyrics.ovh'];
}

/** @return array{synced:?string,plain:?string,source:string}|null */
function lyrics_from_musixmatch(string $artist, string $title, string $apiKey): ?array {
    if ($apiKey === '' || $title === '') {
        return null;
    }
    $q = [
        'apikey' => $apiKey,
        'q_track' => $title,
        'f_has_lyrics' => 1,
        's_track_rating' => 'desc',
        'page_size' => 5,
        'format' => 'json',
    ];
    if ($artist !== '') {
        $q['q_artist'] = $artist;
    }
    $search = lyrics_http_get('https://api.musixmatch.com/ws/1.1/track.search?' . http_build_query($q));
    if ($search['code'] !== 200) {
        return null;
    }
    $sj = json_decode($search['body'], true);
    $list = $sj['message']['body']['track_list'] ?? [];
    if (!is_array($list) || !$list) {
        return null;
    }
    $trackId = (int) ($list[0]['track']['track_id'] ?? 0);
    if ($trackId <= 0) {
        return null;
    }
    $lq = [
        'apikey' => $apiKey,
        'track_id' => $trackId,
        'format' => 'json',
    ];
    $ly = lyrics_http_get('https://api.musixmatch.com/ws/1.1/track.lyrics.get?' . http_build_query($lq));
    if ($ly['code'] !== 200) {
        return null;
    }
    $lj = json_decode($ly['body'], true);
    $plain = trim((string) ($lj['message']['body']['lyrics']['lyrics_body'] ?? ''));
    // Plan gratuito suele truncar y añadir disclaimer
    $plain = preg_replace('/\*\*\*\*\*\*.*$/s', '', $plain) ?? $plain;
    $plain = trim($plain);
    if ($plain === '') {
        return null;
    }
    return ['synced' => null, 'plain' => $plain, 'source' => 'musixmatch'];
}

/** @return array{synced:?string,plain:?string,source:string}|null */
function lyrics_from_genius(string $artist, string $title, string $token): ?array {
    if ($token === '' || $title === '') {
        return null;
    }
    $q = trim($artist . ' ' . $title);
    $search = lyrics_http_get(
        'https://api.genius.com/search?' . http_build_query(['q' => $q]),
        ['Authorization: Bearer ' . $token]
    );
    if ($search['code'] !== 200) {
        return null;
    }
    $sj = json_decode($search['body'], true);
    $hits = $sj['response']['hits'] ?? [];
    if (!is_array($hits) || !$hits) {
        return null;
    }
    $path = (string) ($hits[0]['result']['path'] ?? '');
    $songTitle = (string) ($hits[0]['result']['title'] ?? $title);
    if ($path === '') {
        return null;
    }
    // Genius API no entrega letra completa; la página pública a veces incluye el texto.
    // Intentamos extraer bloques de letra del HTML (mejor esfuerzo; puede fallar).
    $page = lyrics_http_get('https://genius.com' . $path, ['Accept: text/html'], 12);
    if ($page['code'] !== 200 || $page['body'] === '') {
        return null;
    }
    $html = $page['body'];
    $plain = '';
    // Contenedores data-lyrics-container (markup actual de Genius)
    if (preg_match_all('/data-lyrics-container="true"[^>]*>(.*?)<\/div>/is', $html, $m)) {
        $chunks = [];
        foreach ($m[1] as $chunk) {
            $chunk = preg_replace('/<br\s*\/?>/i', "\n", $chunk) ?? $chunk;
            $chunk = html_entity_decode(strip_tags($chunk), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $chunk = trim($chunk);
            if ($chunk !== '') {
                $chunks[] = $chunk;
            }
        }
        $plain = trim(implode("\n", $chunks));
    }
    if ($plain === '') {
        return null;
    }
    return [
        'synced' => null,
        'plain' => $plain,
        'source' => 'genius',
        'title' => $songTitle,
    ];
}

