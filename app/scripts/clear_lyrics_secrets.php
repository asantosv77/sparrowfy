<?php
declare(strict_types=1);

/**
 * Limpia las claves de proveedores de letras del JSON sin tocar las demás opciones.
 * Seguro por defecto: solo informa. Requiere --apply para escribir.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/lib.php';

$apply = in_array('--apply', $argv, true);
if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo "Uso: php scripts/clear_lyrics_secrets.php [--apply]\n";
    echo "Por defecto solo informa. --apply crea un respaldo privado y vacía las claves guardadas en data/config.json.\n";
    exit(0);
}

if (!is_file(LAB_CONFIG) || !is_readable(LAB_CONFIG)) {
    fwrite(STDERR, "ERROR: no se encuentra data/config.json o no se puede leer.\n");
    exit(1);
}
$rawText = (string)file_get_contents(LAB_CONFIG);
$cfg = json_decode($rawText, true);
if (!is_array($cfg)) {
    fwrite(STDERR, "ERROR: data/config.json no contiene JSON válido; no se modificó.\n");
    exit(1);
}
$keys = ['musixmatch_api_key', 'genius_access_token'];
$found = [];
foreach ($keys as $key) {
    if (isset($cfg[$key]) && trim((string)$cfg[$key]) !== '') $found[] = $key;
}
if (!$found) {
    echo "No hay claves de proveedores guardadas en data/config.json.\n";
    exit(0);
}
echo 'Claves encontradas (no se muestran sus valores): ' . implode(', ', $found) . "\n";
if (!$apply) {
    echo "Simulación: no se escribió ningún archivo. Para aplicar, respalda la configuración y ejecuta con --apply.\n";
    exit(0);
}

$backup = LAB_DATA . '/config-' . gmdate('Ymd-His') . '.json.bak';
if (!@copy(LAB_CONFIG, $backup)) {
    fwrite(STDERR, "ERROR: no se pudo crear el respaldo; no se modificó la configuración.\n");
    exit(1);
}
@chmod($backup, 0600);
foreach ($keys as $key) {
    if (array_key_exists($key, $cfg)) $cfg[$key] = '';
}
$json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
if (!is_string($json)) {
    fwrite(STDERR, "ERROR: no se pudo serializar la configuración. Respaldo: {$backup}\n");
    exit(1);
}
$tmp = LAB_CONFIG . '.tmp-' . bin2hex(random_bytes(5));
if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false || !@rename($tmp, LAB_CONFIG)) {
    @unlink($tmp);
    fwrite(STDERR, "ERROR: no se pudo reemplazar la configuración. Respaldo: {$backup}\n");
    exit(1);
}
@chmod(LAB_CONFIG, 0640);
echo "Claves vaciadas. Las demás opciones se conservaron. Respaldo privado creado en: {$backup}\n";
echo "Después de comprobar la integración, elimina el respaldo si ya no necesitas conservarlo: contiene los valores anteriores.\n";
