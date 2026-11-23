<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "ERROR: no se pudo resolver el directorio audio/.\n");
    exit(1);
}
$files = [
    $root . '/album_edit.php',
    $root . '/assets/player.js',
    $root . '/config.php',
];
foreach ($files as $file) {
    if (!is_file($file) || !is_readable($file) || !is_writable($file)) {
        fwrite(STDERR, "ERROR: archivo no disponible o no escribible: {$file}\n");
        exit(1);
    }
}

$contents = [];
foreach ($files as $file) {
    $value = file_get_contents($file);
    if (!is_string($value)) {
        fwrite(STDERR, "ERROR: no se pudo leer {$file}\n");
        exit(1);
    }
    $contents[$file] = $value;
}

$albumFile = $root . '/album_edit.php';
$album = $contents[$albumFile];
$oldOnlineGrid = '.lab-album-layout #onlineAlbumCard { grid-column: 2; grid-row: 1; }';
$newOnlineGrid = '.lab-album-layout #onlineAlbumCard { grid-column: 2; grid-row: 2; }';
$oldAddGrid = '.lab-album-layout #addTracksForm { grid-column: 2; grid-row: 2; min-height: 500px; }';
$newAddGrid = '.lab-album-layout #addTracksForm { grid-column: 2; grid-row: 1; min-height: 500px; }';
if (str_contains($album, $oldOnlineGrid) && str_contains($album, $oldAddGrid)) {
    $album = str_replace($oldOnlineGrid, $newOnlineGrid, $album, $n1);
    $album = str_replace($oldAddGrid, $newAddGrid, $album, $n2);
    if ($n1 !== 1 || $n2 !== 1) {
        fwrite(STDERR, "ERROR: no se pudieron reordenar los dos paneles de la columna derecha.\n"); exit(1);
    }
} elseif (!str_contains($album, $newOnlineGrid) || !str_contains($album, $newAddGrid)) {
    fwrite(STDERR, "ERROR: no se reconoció la versión de album_edit.php; no se modificó ningún archivo.\n"); exit(1);
}
$oldMobileOrder = "      .lab-album-layout #onlineAlbumCard { order: 2; }\n      .lab-album-layout #youtubeImportCard { order: 3; min-height: 0; }\n      .lab-album-layout #addTracksForm { order: 4; min-height: 0; }";
$newMobileOrder = "      .lab-album-layout #addTracksForm { order: 2; min-height: 0; }\n      .lab-album-layout #youtubeImportCard { order: 3; min-height: 0; }\n      .lab-album-layout #onlineAlbumCard { order: 4; }";
if (str_contains($album, $oldMobileOrder)) {
    $album = str_replace($oldMobileOrder, $newMobileOrder, $album, $nm);
    if ($nm !== 1) { fwrite(STDERR, "ERROR: no se pudo ajustar el orden móvil.\n"); exit(1); }
} elseif (!str_contains($album, $newMobileOrder)) {
    fwrite(STDERR, "ERROR: no se reconoció el orden móvil de album_edit.php.\n"); exit(1);
}
$contents[$albumFile] = $album;

$playerFile = $root . '/assets/player.js';
$player = $contents[$playerFile];
$replacements = [
    '/abrEwmaDefaultEstimate:\s*\d+/' => 'abrEwmaDefaultEstimate: 256000',
    '/abrEwmaFastVoD:\s*[\d.]+/' => 'abrEwmaFastVoD: 1.5',
    '/abrEwmaSlowVoD:\s*[\d.]+/' => 'abrEwmaSlowVoD: 4',
    '/abrBandWidthFactor:\s*[\d.]+/' => 'abrBandWidthFactor: 0.80',
    '/abrBandWidthUpFactor:\s*[\d.]+/' => 'abrBandWidthUpFactor: 0.92',
];
foreach ($replacements as $pattern => $replacement) {
    $player = preg_replace($pattern, $replacement, $player, 1, $count);
    if (!is_string($player) || $count !== 1) {
        fwrite(STDERR, "ERROR: no se pudo ajustar el parámetro ABR " . $pattern . ".\n"); exit(1);
    }
}
$oldFrag = <<<'JS'
      hls.on(Hls.Events.FRAG_LOADED, () => {
        if (!netGaveUp && netOverlay && !netOverlay.hidden) {
          // Datos otra vez: quitar espera si el audio avanza
          if (!audio.paused || wantPlaying) hideNet();
        }
      });
JS;
$newFrag = <<<'JS'
      // Recuperación de calidad: después de tres segmentos rápidos consecutivos,
      // probar el siguiente nivel solo si hay margen de ancho de banda y búfer.
      let abrRecoveryStreak = 0;
      let abrLastProbeLevel = -1;
      hls.on(Hls.Events.FRAG_LOADED, (_event, data) => {
        if (data && data.frag && data.stats && hls && hls.autoLevelEnabled) {
          const loadedBytes = Number(data.stats.loaded || data.stats.total || 0);
          const loadStart = Number(data.stats.loading && data.stats.loading.start || 0);
          const loadEnd = Number(data.stats.loading && data.stats.loading.end || 0);
          const elapsedMs = loadEnd - loadStart;
          const currentLevel = Number(data.frag.level);
          if (abrLastProbeLevel >= 0 && currentLevel < abrLastProbeLevel) abrLastProbeLevel = -1;
          const levels = hls.levels || [];
          const nextLevel = currentLevel + 1;
          const bufferEnd = audio.buffered.length
            ? audio.buffered.end(audio.buffered.length - 1)
            : audio.currentTime;
          const bufferedSeconds = Math.max(0, bufferEnd - audio.currentTime);
          const measuredBps = elapsedMs > 0 ? (loadedBytes * 8 * 1000 / elapsedMs) : 0;
          const nextBitrate = levels[nextLevel] ? Number(levels[nextLevel].bitrate || 0) : 0;
          const canProbeUp = nextLevel < levels.length
            && nextBitrate > 0
            && measuredBps >= nextBitrate * 1.35
            && bufferedSeconds >= 8;

          if (canProbeUp) {
            abrRecoveryStreak += 1;
            if (abrRecoveryStreak >= 3 && abrLastProbeLevel !== nextLevel) {
              hls.nextLevel = nextLevel;
              abrLastProbeLevel = nextLevel;
              abrRecoveryStreak = 0;
            }
          } else {
            abrRecoveryStreak = 0;
            if (measuredBps < nextBitrate * 1.15 || bufferedSeconds < 5) abrLastProbeLevel = -1;
          }
        }
        if (!netGaveUp && netOverlay && !netOverlay.hidden) {
          // Datos otra vez: quitar espera si el audio avanza
          if (!audio.paused || wantPlaying) hideNet();
        }
      });
JS;
if (str_contains($player, $oldFrag)) {
    $player = str_replace($oldFrag, $newFrag, $player, $count);
    if ($count !== 1) { fwrite(STDERR, "ERROR: no se pudo insertar la recuperación ABR.\n"); exit(1); }
} elseif (!str_contains($player, 'let abrRecoveryStreak = 0;') || !str_contains($player, 'abrBandWidthUpFactor: 0.92')) {
    fwrite(STDERR, "ERROR: no se reconoció el bloque FRAG_LOADED de player.js.\n"); exit(1);
}
$contents[$playerFile] = $player;

$configFile = $root . '/config.php';
$config = $contents[$configFile];
$anchor = <<<'ANCHOR'
  <main class="lab-main">
    <?php if ($msg): ?>
ANCHOR;
$card = <<<'PHPHTML'
  <main class="lab-main">
    <?php if (empty($embed)): ?>
    <section class="card account-management-entry">
      <h2>Usuarios y permisos</h2>
      <p class="muted">Crea cuentas, activa o desactiva usuarios y decide quién puede únicamente escuchar música y quién puede gestionar la biblioteca.</p>
      <a class="btn primary" href="admin_users.php">Administrar cuentas y permisos</a>
    </section>
    <?php endif; ?>
    <?php if ($msg): ?>
PHPHTML;
if (str_contains($config, $anchor)) {
    $config = str_replace($anchor, $card, $config, $nc);
    if ($nc !== 1) { fwrite(STDERR, "ERROR: no se pudo insertar el acceso a administración de usuarios en config.php.\n"); exit(1); }
} elseif (!str_contains($config, 'class="account-management-entry"') && !str_contains($config, 'Administrar cuentas y permisos')) {
    fwrite(STDERR, "ERROR: no se reconoció el punto de inserción de config.php.\n"); exit(1);
}
$contents[$configFile] = $config;

$stamp = date('Ymd-His');
$backups = [];
foreach ($files as $file) {
    $backup = $file . '.bak-layout-abr-' . $stamp;
    if (!copy($file, $backup)) {
        foreach ($backups as $dst => $bak) @unlink($bak);
        fwrite(STDERR, "ERROR: no se pudo crear la copia de seguridad de {$file}.\n"); exit(1);
    }
    $backups[$file] = $backup;
    echo "Copia de seguridad: {$backup}\n";
}

try {
    foreach ($contents as $file => $content) {
        if (file_put_contents($file, $content, LOCK_EX) === false) {
            throw new RuntimeException('No se pudo escribir ' . $file);
        }
    }
    foreach ([$albumFile, $configFile] as $file) {
        $output = [];
        $code = 1;
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $code);
        echo implode("\n", $output), "\n";
        if ($code !== 0) throw new RuntimeException('Falló la validación PHP de ' . basename($file));
    }
    $nodeOutput = [];
    $nodeCode = 127;
    exec('node --check ' . escapeshellarg($playerFile) . ' 2>&1', $nodeOutput, $nodeCode);
    if ($nodeCode === 0) echo "Sintaxis JavaScript: OK\n";
    elseif ($nodeCode !== 127) throw new RuntimeException('Falló la validación JavaScript: ' . implode("\n", $nodeOutput));
    else echo "Aviso: Node.js no está instalado en el contenedor; omitiendo node --check.\n";
} catch (Throwable $e) {
    foreach ($backups as $file => $backup) @copy($backup, $file);
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\nSe restauraron las copias de seguridad.\n");
    exit(1);
}

echo "Parche aplicado: paneles reordenados en escritorio/móvil, ABR con recuperación gradual y acceso a cuentas desde Configuración.\n";
echo "No se modificó la base de datos ni los archivos de audio.\n";
