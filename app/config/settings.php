<?php
declare(strict_types=1);
function lab_default_config(): array {
    return [
        'delete_source_after_convert' => false,
        'remember_days' => 30,
        'playback_diagnostics_enabled' => false,
        'playback_diagnostics_record' => false,
        'playback_diagnostics_console' => false,
        'playback_diagnostics_days' => 7,
        'csrf_protection_enabled' => true, // Validación de origen para peticiones POST
        'segment_seconds' => 4,
        // Perfil ABR del reproductor: continuity | balanced_mobile | audio_quality
        'hls_profile' => 'balanced_mobile',
        'allowed_extensions' => ['mp3', 'flac', 'ogg', 'oga', 'wma', 'm4a', 'wav', 'aac', 'webm', 'weba'],
        // Escalones posibles (kbps). Se eligen solo los <= bitrate del origen.
        'quality_ladder_mode' => 'profiles',
        'quality_source_profiles' => ['128'=>[64,128],'192'=>[64,128,192],'256'=>[64,128,256],'320'=>[64,128,256],'lossless'=>[64,128,256]],
        'quality_ladder_kbps' => [64, 96, 128, 192],
        // Si el origen es >= este valor, se usa 128 como calidad media (además de 96 y tope).
        'prefer_128_when_source_ge' => 256,
        // Siempre incluir un escalón bajo si el origen lo permite.
        'always_include_low_kbps' => 64,
        'lrclib_enabled' => true,
        'max_upload_mib' => 120,
        // 0 = automatico segun navigator.deviceMemory; tambien 128,192,256,384,512,640.
        'client_memory_mib' => 0,
        'webcodecs_enabled' => false,
        'desktop_workers_enabled' => false,
        // Solo API keys / tokens — nunca usuario y contraseña
        'musixmatch_api_key' => '',
        'genius_access_token' => '',
    ];
}

function lab_load_config(): array {
    $defaults = lab_default_config();
    if (!is_file(LAB_CONFIG)) {
        lab_save_config($defaults);
        $envMx = getenv('MUSIXMATCH_API_KEY');
        $envGenius = getenv('GENIUS_ACCESS_TOKEN');
        if ($envMx !== false && trim($envMx) !== '') $defaults['musixmatch_api_key'] = trim($envMx);
        if ($envGenius !== false && trim($envGenius) !== '') $defaults['genius_access_token'] = trim($envGenius);
        return $defaults;
    }
    $raw = json_decode((string) file_get_contents(LAB_CONFIG), true);
    if (!is_array($raw)) return $defaults;
    $cfg = array_merge($defaults, $raw);
    // Las variables de entorno tienen prioridad para no incluir secretos en el paquete.
    $envMx = getenv('MUSIXMATCH_API_KEY');
    $envGenius = getenv('GENIUS_ACCESS_TOKEN');
    if ($envMx !== false && trim($envMx) !== '') $cfg['musixmatch_api_key'] = trim($envMx);
    if ($envGenius !== false && trim($envGenius) !== '') $cfg['genius_access_token'] = trim($envGenius);
    $cfg['allowed_extensions'] = array_values(array_intersect(
        lab_safe_audio_extensions(),
        array_unique(array_map(static fn($e) => strtolower((string)$e), (array)($cfg['allowed_extensions'] ?? [])))
    ));
    if (!$cfg['allowed_extensions']) $cfg['allowed_extensions'] = $defaults['allowed_extensions'];
    $cfg['segment_seconds'] = max(2, min(8, (int)($cfg['segment_seconds'] ?? 4)));
    $allowedHlsProfiles = ['continuity', 'balanced_mobile', 'audio_quality'];
    $cfg['hls_profile'] = in_array((string)($cfg['hls_profile'] ?? ''), $allowedHlsProfiles, true)
        ? (string)$cfg['hls_profile'] : 'balanced_mobile';
    $cfg['max_upload_mib'] = max(10, min(250, (int)($cfg['max_upload_mib'] ?? 120)));
    $memTiers = [0, 128, 192, 256, 384, 512, 640];
    $cfg['client_memory_mib'] = in_array((int)($cfg['client_memory_mib'] ?? 0), $memTiers, true) ? (int)$cfg['client_memory_mib'] : 0;
    $cfg['quality_ladder_kbps'] = array_values(array_unique(array_filter(array_map('intval', (array)($cfg['quality_ladder_kbps'] ?? [])), static fn($v) => $v >= 32 && $v <= 256)));
    if (!$cfg['quality_ladder_kbps']) $cfg['quality_ladder_kbps'] = $defaults['quality_ladder_kbps'];
    sort($cfg['quality_ladder_kbps']);
    return $cfg;
}

function lab_save_config(array $cfg): void {
    if (!is_dir(LAB_DATA)) mkdir(LAB_DATA, 0755, true);
    $merged = array_merge(lab_default_config(), $cfg);
    $merged['segment_seconds'] = max(2, min(8, (int) ($merged['segment_seconds'] ?? 4)));
    $allowedHlsProfiles = ['continuity', 'balanced_mobile', 'audio_quality'];
    $merged['hls_profile'] = in_array((string)($merged['hls_profile'] ?? ''), $allowedHlsProfiles, true)
        ? (string)$merged['hls_profile'] : 'balanced_mobile';
    $merged['max_upload_mib'] = max(10, min(250, (int) ($merged['max_upload_mib'] ?? 120)));
    $merged['client_memory_mib'] = in_array((int) ($merged['client_memory_mib'] ?? 0), [0, 128, 192, 256, 384, 512, 640], true) ? (int) $merged['client_memory_mib'] : 0;
    $merged['quality_ladder_kbps'] = array_values(array_filter(array_map('intval', (array) ($merged['quality_ladder_kbps'] ?? [])), static fn($v) => $v >= 32 && $v <= 256));
    if (!$merged['quality_ladder_kbps']) $merged['quality_ladder_kbps'] = [64, 96, 128, 192];
    // normalizar listas
    if (isset($merged['quality_ladder_kbps']) && is_array($merged['quality_ladder_kbps'])) {
        $merged['quality_ladder_kbps'] = array_values(array_unique(array_map('intval', $merged['quality_ladder_kbps'])));
        sort($merged['quality_ladder_kbps']);
    }
    if (isset($merged['allowed_extensions']) && is_array($merged['allowed_extensions'])) {
        $merged['allowed_extensions'] = array_values(array_unique(array_map(
            static fn ($e) => strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $e) ?? ''),
            $merged['allowed_extensions']
        )));
        $merged['allowed_extensions'] = array_values(array_intersect(lab_safe_audio_extensions(), array_filter($merged['allowed_extensions'])));
        if (!$merged['allowed_extensions']) $merged['allowed_extensions'] = lab_default_config()['allowed_extensions'];
    }
    // Si un secreto viene del entorno, nunca lo copiamos al JSON al guardar otras opciones.
    if (($env = getenv('MUSIXMATCH_API_KEY')) !== false && trim($env) !== '') $merged['musixmatch_api_key'] = '';
    if (($env = getenv('GENIUS_ACCESS_TOKEN')) !== false && trim($env) !== '') $merged['genius_access_token'] = '';
    $json = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) throw new RuntimeException('No se pudo serializar la configuración.');
    $tmp = LAB_CONFIG . '.tmp-' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $json, LOCK_EX) === false || !@rename($tmp, LAB_CONFIG)) {
        @unlink($tmp);
        throw new RuntimeException('No se pudo guardar la configuración. Revisa permisos.');
    }
    @chmod(LAB_CONFIG, 0640);
}

