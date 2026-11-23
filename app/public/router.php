<?php
declare(strict_types=1);
$uriPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$decoded = rawurldecode($uriPath);
if (str_contains($decoded, "\0") || preg_match('#(?:^|/)\.\.(?:/|$)#', $decoded)) {
    http_response_code(400); exit('Bad request');
}
$path = __DIR__ . $decoded;
$normalized = str_replace('\\', '/', $decoded);
if (preg_match('#^/hls_out/(.+)$#i', $normalized, $hlsMatch)) {
    $_GET['path'] = $hlsMatch[1];
    require __DIR__ . '/stream.php';
    exit;
}
if (preg_match('#^/data(?:/|$)#i', $normalized)) {
    // Public only: raster cover art. Metadata/config/source is never served directly.
    if (!preg_match('#^/data/albums/[a-f0-9]{16}/cover\.(?:jpe?g|png|webp|gif)$#i', $normalized)) {
        http_response_code(404); exit('Not found');
    }
}
if (preg_match('#^/hls_out(?:/|$)#i', $normalized) && preg_match('#(?:^|/)(?:source\.[^/]+|info\.json|reconstructed\.m4a|[^/]+\.tmp)$#i', $normalized)) {
    http_response_code(404); exit('Not found');
}
if (is_file($path) && preg_match('/\.php$/i', $path)) return false;
if (is_file($path)) return false;
if ($uriPath === '/') return false;
http_response_code(404); exit('Not found');
