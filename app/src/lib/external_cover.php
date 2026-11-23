<?php
declare(strict_types=1);
/** Cover HTTP/normalization helpers; provider orchestration remains in api_cover.php. */
function cov_get(string $url): array {
    // Only the documented metadata providers; never fetch arbitrary or internal hosts.
    $parts = parse_url($url);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower((string)($parts['host'] ?? ''));
    $allowed = false;
    foreach (['itunes.apple.com', 'api.deezer.com', 'musicbrainz.org', 'coverartarchive.org'] as $base) {
        if ($host === $base || str_ends_with($host, '.' . $base)) { $allowed = true; break; }
    }
    if ($scheme !== 'https' || !$allowed) return ['code' => 0, 'body' => ''];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'HLS-Music-Lab/1.1',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $code, 'body' => is_string($body) ? $body : ''];
    }
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'header' => "Accept: application/json\r\nUser-Agent: HLS-Music-Lab\r\n"]]);
    $body = @file_get_contents($url, false, $ctx);
    return ['code' => 200, 'body' => is_string($body) ? $body : ''];
}

function cov_norm(string $t): string {
    $t = mb_strtolower($t, 'UTF-8');
    if (class_exists('Normalizer')) {
        $t = Normalizer::normalize($t, Normalizer::FORM_D) ?: $t;
    }
    $t = preg_replace('/\p{Mn}/u', '', $t) ?? $t;
    return preg_replace('/[^a-z0-9]+/u', '', $t) ?? $t;
}

