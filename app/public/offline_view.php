<?php
declare(strict_types=1);
if (!isset($view) || $view !== 'offline') return;
?>
<section class="sf-offline-view">
  <header class="sf-offline-head">
    <div>
      <p class="eyebrow">BIBLIOTECA SIN CONEXIÓN</p>
      <h1>Oír sin conexión</h1>
      <p class="muted">Las descargas se guardan en este navegador o PWA y se borran al cerrar sesión. Solo se descarga lo que elijas desde el reproductor o desde tus listas.</p>
    </div>
  </header>
  <section class="sf-offline-panel">
    <div class="storage-head">
      <strong id="offlineUsage">Calculando espacio…</strong>
      <label>Límite
        <select id="offlineQuota">
          <option value="50">50 MiB</option>
          <option value="100">100 MiB</option>
          <option value="200" selected>200 MiB</option>
          <option value="500">500 MiB</option>
          <option value="1000">1000 MiB</option>
        </select>
      </label>
    </div>
    <div class="offline-quality-settings"><label for="offlineQuality">Calidad de descarga <select id="offlineQuality" data-highest-kbps="256"><option value="64">64 kbps · Ahorro</option><option value="96">96 kbps</option><option value="128" selected>128 kbps · Equilibrada</option><option value="160">160 kbps</option><option value="192">192 kbps</option><option value="256">256 kbps</option><option value="320">320 kbps</option><option value="max">Máxima disponible</option></select></label><p class="muted">Se aplica a las próximas descargas de esta cuenta en este navegador o PWA. Para cambiar una copia ya guardada, vuelve a descargarla.</p><p class="muted">La calidad depende de los trenes disponibles en el servidor. Si falta la solicitada, se guardará la más cercana; en un empate, la inferior. «Máxima disponible» elige el mejor tren de cada pista. Cada descarga muestra la calidad que se guardó.</p><div class="offline-estimates" role="status" aria-live="polite"><div><span>Por minuto</span><strong id="offlineMinuteEstimate">Calculando…</strong></div><div><span>Por melodía de 4 minutos</span><strong id="offlineSongEstimate">Calculando…</strong></div><div><span>En el espacio elegido</span><strong id="offlineCapacityEstimate">Calculando…</strong></div><div><span>En el espacio libre actual</span><strong id="offlineRemainingEstimate">Calculando…</strong></div></div><p class="muted" id="offlineEstimateNote">Estimaciones con 15 % de margen para los segmentos HLS. El tamaño real varía según duración, audio y calidad disponible.</p></div><progress id="offlineStorage" max="100" value="0" aria-label="Espacio utilizado"></progress>
    <p id="offlineStatus" role="status"></p>
  </section>
  <section class="sf-offline-panel" id="offlinePlayer" hidden>
    <h2 id="offlineNow">Reproductor</h2>
    <audio id="offlineAudio" aria-label="Reproductor de canciones descargadas" controls preload="auto"></audio>
    <p class="muted">Reproduciendo la copia descargada en este equipo.</p>
  </section>
  <section class="sf-offline-panel">
    <h2>Mis descargas</h2>
    <div id="offlineTracks"></div>
  </section>
</section>
