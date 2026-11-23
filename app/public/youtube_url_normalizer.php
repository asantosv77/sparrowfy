<?php
declare(strict_types=1);

/**
 * Normalize accepted YouTube URLs before the import endpoint validates the video.
 * Host validation is intentionally strict; this is not a general URL normalizer.
 */
function hlsmlNormalizeYouTubeUrl(string $input): string
{
    $input = trim($input);
    if ($input === '' || strlen($input) > 2048) {
        throw new InvalidArgumentException('URL vacía o demasiado larga.');
    }
    if (preg_match('/[\x00-\x20\x7f]/', $input)) {
        throw new InvalidArgumentException('La URL contiene caracteres no válidos.');
    }
    if (!preg_match('~^https?://~i', $input)) {
        $input = 'https://' . $input;
    }

    $parts = parse_url($input);
    if (!is_array($parts) || empty($parts['host'])) {
        throw new InvalidArgumentException('La URL no es válida.');
    }
    if (!empty($parts['user']) || !empty($parts['pass'])) {
        throw new InvalidArgumentException('La URL no debe incluir credenciales.');
    }
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new InvalidArgumentException('Usa una URL web válida de YouTube.');
    }
    if (isset($parts['port']) && (int)$parts['port'] !== 443) {
        throw new InvalidArgumentException('El puerto de la URL no es válido.');
    }

    $host = strtolower(rtrim((string)$parts['host'], '.'));
    $allowed = [
        'youtube.com',
        'www.youtube.com',
        'm.youtube.com',
        'music.youtube.com',
        'youtu.be',
    ];
    if (!in_array($host, $allowed, true)) {
        throw new InvalidArgumentException('Solo se admiten URLs de YouTube.');
    }

    $path = (string)($parts['path'] ?? '/');
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';
    $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

    // Keep short links valid; yt_validate_url will extract the ID and canonicalize it.
    if ($host === 'youtu.be') {
        return 'https://youtu.be' . $path . $query . $fragment;
    }

    // Convert desktop, mobile and YouTube Music hosts to the canonical host.
    return 'https://www.youtube.com' . $path . $query . $fragment;
}
