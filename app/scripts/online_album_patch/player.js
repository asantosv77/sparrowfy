(() => {
  'use strict';
  const payload = window.LAB_PAYLOAD;
  if (!payload?.album?.tracks?.length) return;

  const tracks = payload.album.tracks;
  const lrclibOn = !!payload.lrclib;
  let index = Math.max(0, Math.min(tracks.length - 1, Number(payload.startIndex) || 0));
  let hls = null;
  let pendingIndex = index;
  let currentUrl = '';
  let wantPlaying = false;
  let netTimer = null;
  let netAttempt = 0;
  let netGaveUp = false;
  const NET_MAX_MS = 90000; // ~90 s de reintentos
  let netStartedAt = 0;

  const netOverlay = document.getElementById('netOverlay');
  const netSpinner = document.getElementById('netSpinner');
  const netMsg = document.getElementById('netMsg');
  const netRetryBtn = document.getElementById('netRetryBtn');

  const audio = document.getElementById('audioPlayer');
  const playPauseBtn = document.getElementById('playPauseBtn');
  const progressBarBg = document.getElementById('progressBarBg');
  const progressBar = document.getElementById('progressBar');
  const currentTimeEl = document.getElementById('currentTime');
  const durationTimeEl = document.getElementById('durationTime');
  const volumeSlider = document.getElementById('volumeSlider');
  const muteBtn = document.getElementById('muteBtn');
  const qualitySelect = document.getElementById('qualitySelect');
  const prevBtn = document.getElementById('prevBtn');
  const nextBtn = document.getElementById('nextBtn');
  const trackTitle = document.getElementById('trackTitle');
  const trackStatus = document.getElementById('trackStatus');
  const playlistEl = document.getElementById('playlist');
  const lyricsCheck = document.getElementById('lyricsCheck');
  const lyricsSource = document.getElementById('lyricsSource');
  const lyricsBox = document.getElementById('lyricsBox');
  const playlistWrap = document.getElementById('playlistWrap');
  const karaokeOverlay = document.getElementById('karaokeOverlay');
  const karaokeLyrics = document.getElementById('karaokeLyrics');
  const karaokeClose = document.getElementById('karaokeClose');
  const libPanel = document.getElementById('libPanel');
  const libPanelBody = document.getElementById('libPanelBody');
  const libRestorePlayer = document.getElementById('libRestorePlayer');
  const trackArt = document.getElementById('trackArt');
  const btnMin = document.getElementById('btnMin');
  const btnMax = document.getElementById('btnMax');
  const btnClose = document.getElementById('btnClose');
  const miniDock = document.getElementById('miniDock');
  const miniArt = document.getElementById('miniArt');
  const miniTitle = document.getElementById('miniTitle');
  const miniStatus = document.getElementById('miniStatus');
  const miniPlay = document.getElementById('miniPlay');
  const miniRestore = document.getElementById('miniRestore');
  const eqToggle = document.getElementById('eqToggle');
  const eqPanel = document.getElementById('eqPanel');
  const eqBass = document.getElementById('eqBass');
  const eqMid = document.getElementById('eqMid');
  const eqTreble = document.getElementById('eqTreble');
  const eqBassVal = document.getElementById('eqBassVal');
  const eqMidVal = document.getElementById('eqMidVal');
  const eqTrebleVal = document.getElementById('eqTrebleVal');
  const eqReset = document.getElementById('eqReset');
  const playerRoot = document.getElementById('playerRoot');

  // Web Audio EQ (graves / medios / agudos)
  let audioCtx = null, sourceNode = null, bassF = null, midF = null, trebleF = null, eqReady = false;

  const LYRICS_KEY = 'lab_show_lyrics';
  const albumIdKey = (payload.album && payload.album.id) ? String(payload.album.id) : 'single';
  const PROGRESS_KEY = 'lab_progress_' + albumIdKey;
  let pendingSeek = 0;
  let progressTimer = null;

  function readProgress() {
    try {
      const j = JSON.parse(localStorage.getItem(PROGRESS_KEY) || 'null');
      if (!j || typeof j !== 'object') return null;
      return j;
    } catch { return null; }
  }

  function writeProgress() {
    try {
      localStorage.setItem(PROGRESS_KEY, JSON.stringify({
        index: index,
        time: audio.currentTime || 0,
        updated: Date.now(),
      }));
    } catch (_) {}
  }

  function scheduleProgressSave() {
    if (progressTimer) return;
    progressTimer = setTimeout(() => {
      progressTimer = null;
      writeProgress();
    }, 1500);
  }

  function updateMediaSession(track) {
    if (!('mediaSession' in navigator)) return;
    const artist = (payload.album && payload.album.artist) || 'HLS Music Lab';
    const album = (payload.album && payload.album.title) || 'HLS Music Lab';
    const title = (track && track.name) ? cleanTitle(track.name) : (trackTitle?.textContent || 'Reproduciendo');
    const art = trackArt && !trackArt.hidden && trackArt.src ? trackArt.src : '';
    try {
      const meta = {
        title: title,
        artist: artist,
        album: album,
      };
      if (art) {
        meta.artwork = [
          { src: art, sizes: '512x512', type: 'image/jpeg' },
          { src: art, sizes: '256x256', type: 'image/jpeg' },
        ];
      }
      navigator.mediaSession.metadata = new MediaMetadata(meta);
      navigator.mediaSession.playbackState = audio.paused ? 'paused' : 'playing';
    } catch (_) {}
  }

  function bindMediaSessionActions() {
    if (!('mediaSession' in navigator)) return;
    try {
      navigator.mediaSession.setActionHandler('play', () => { wantPlaying = true; audio.play().catch(() => {}); });
      navigator.mediaSession.setActionHandler('pause', () => { wantPlaying = false; audio.pause(); writeProgress(); });
      navigator.mediaSession.setActionHandler('previoustrack', () => loadTrack(Math.max(0, index - 1), true));
      navigator.mediaSession.setActionHandler('nexttrack', () => {
        if (index + 1 < tracks.length) loadTrack(index + 1, true);
      });
      navigator.mediaSession.setActionHandler('seekto', (d) => {
        if (d.seekTime != null && Number.isFinite(audio.duration)) {
          audio.currentTime = Math.max(0, Math.min(audio.duration, d.seekTime));
          writeProgress();
        }
      });
    } catch (_) {}
  }

  function setLyricsSource(label) {
    if (!lyricsSource) return;
    if (!label) {
      lyricsSource.hidden = true;
      lyricsSource.textContent = '';
      return;
    }
    lyricsSource.hidden = false;
    lyricsSource.textContent = '· ' + label;
  }
  const isEmbed = !!payload.embed;

  function labPostParent(msg) {
    if (!isEmbed || window.parent === window) return;
    try { window.parent.postMessage(Object.assign({ source: 'lab-player' }, msg), '*'); } catch (_) {}
  }
  let lyricLines = [];   // {time, text, el}
  let lyricLast = -1;
  let lyricManualUntil = 0;
  let lyricFollowTimer = null;
  let lyricSerial = 0;

  function formatTime(seconds) {
    if (!Number.isFinite(seconds) || seconds < 0) return '00:00';
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
  }

  function setStatus(msg) { if (trackStatus) trackStatus.textContent = msg; }

  // Obtiene la calidad nominal (por ejemplo, 64/128/192 kbps) desde el nombre
  // de la variante HLS. level.bitrate suele ser BANDWIDTH máximo e incluye
  // sobrecarga, por lo que no debe usarse como primera opción para mostrar kbps.
  function getLevelKbps(level) {
    if (!level) return null;

    const candidates = [
      level.name,
      level.attrs?.NAME,
      level.url,
      level.uri,
      level.details?.url
    ];

    for (const candidate of candidates) {
      if (typeof candidate !== 'string' || !candidate.trim()) continue;
      // Admite nombres como "128k", "128 kbps" y rutas como "/128k/prog.m3u8".
      const match = candidate.trim().match(/(?:^|[\/\s_-])(\d+)\s*k(?:bps)?(?:$|[\/\s._?#&-])/i);
      if (match) return Number(match[1]);
    }

    // Fallback para manifiestos que no declaran NAME ni una ruta con la calidad.
    const average = Number(level.averageBitrate);
    if (Number.isFinite(average) && average > 0) return Math.round(average / 1000);

    const bitrate = Number(level.bitrate);
    if (Number.isFinite(bitrate) && bitrate > 0) return Math.round(bitrate / 1000);

    return null;
  }

  function ensureEq() {
    if (eqReady) return;
    try {
      const AC = window.AudioContext || window.webkitAudioContext;
      if (!AC) return;
      audioCtx = new AC();
      sourceNode = audioCtx.createMediaElementSource(audio);
      bassF = audioCtx.createBiquadFilter();
      bassF.type = 'lowshelf';
      bassF.frequency.value = 200;
      midF = audioCtx.createBiquadFilter();
      midF.type = 'peaking';
      midF.frequency.value = 1000;
      midF.Q.value = 1;
      trebleF = audioCtx.createBiquadFilter();
      trebleF.type = 'highshelf';
      trebleF.frequency.value = 3200;
      sourceNode.connect(bassF);
      bassF.connect(midF);
      midF.connect(trebleF);
      trebleF.connect(audioCtx.destination);
      eqReady = true;
    } catch (e) {
      console.warn('EQ no disponible', e);
    }
  }

  function applyEqGains() {
    if (!eqReady) return;
    const b = Number(eqBass?.value || 0);
    const m = Number(eqMid?.value || 0);
    const tr = Number(eqTreble?.value || 0);
    bassF.gain.value = b;
    midF.gain.value = m;
    trebleF.gain.value = tr;
    if (eqBassVal) eqBassVal.textContent = String(b);
    if (eqMidVal) eqMidVal.textContent = String(m);
    if (eqTrebleVal) eqTrebleVal.textContent = String(tr);
  }

  async function loadCoverForTrack(track) {
    const local = payload.localCover || '';
    if (local && trackArt) {
      trackArt.src = local;
      trackArt.hidden = false;
      if (miniArt) { miniArt.src = local; miniArt.hidden = false; }
    }
    const artist = (payload.album.artist || '').trim();
    const title = cleanTitle(track.name || '');
    try {
      const q = new URLSearchParams({ artist, title });
      const r = await fetch('api_cover.php?' + q.toString(), { credentials: 'same-origin', cache: 'force-cache' });
      const j = await r.json().catch(() => ({}));
      if (j.cover && trackArt) {
        trackArt.src = j.cover;
        trackArt.hidden = false;
        if (miniArt) { miniArt.src = j.cover; miniArt.hidden = false; }
      } else if (!local && trackArt) {
        trackArt.hidden = true;
      }
    } catch (_) {
      if (!local && trackArt) trackArt.hidden = true;
    }
  }

  function destroyHls() {
    if (hls) { try { hls.destroy(); } catch (_) {} hls = null; }
  }

  function clearNetTimer() {
    if (netTimer) { clearTimeout(netTimer); netTimer = null; }
  }

  function showNetWaiting(msg) {
    if (!netOverlay) return;
    netOverlay.hidden = false;
    if (netSpinner) {
      netSpinner.hidden = false;
      netSpinner.classList.remove('stopped');
    }
    if (netRetryBtn) netRetryBtn.hidden = true;
    if (netMsg) netMsg.textContent = msg || 'Reconectando…';
  }

  function showNetFailed(msg) {
    if (!netOverlay) return;
    netOverlay.hidden = false;
    if (netSpinner) {
      netSpinner.classList.add('stopped');
    }
    if (netRetryBtn) netRetryBtn.hidden = false;
    if (netMsg) netMsg.textContent = msg || 'La red se ha interrumpido.';
    netGaveUp = true;
    clearNetTimer();
  }

  function hideNet() {
    if (netOverlay) netOverlay.hidden = true;
    if (netSpinner) netSpinner.classList.remove('stopped');
    if (netRetryBtn) netRetryBtn.hidden = true;
    clearNetTimer();
    netAttempt = 0;
    netGaveUp = false;
    netStartedAt = 0;
  }

  function scheduleNetRetry(reason) {
    if (netGaveUp) return;
    if (!netStartedAt) netStartedAt = Date.now();
    const elapsed = Date.now() - netStartedAt;
    if (elapsed > NET_MAX_MS) {
      showNetFailed('La red se ha interrumpido. Cuando vuelva la conexión, pulsa Reintentar.');
      setStatus('Sin red');
      return;
    }
    // Backoff: 1s, 2s, 4s, 8s… máx 10s (estilo streaming)
    const delay = Math.min(10000, 1000 * Math.pow(2, Math.min(netAttempt, 3)));
    netAttempt += 1;
    showNetWaiting(reason || ('Reconectando… (intento ' + netAttempt + ')'));
    setStatus('Reconectando…');
    clearNetTimer();
    netTimer = setTimeout(() => recoverPlayback(), delay);
  }

  function recoverPlayback() {
    if (netGaveUp) return;
    // Preferir recuperación in-place de hls.js
    if (hls) {
      try {
        hls.startLoad();
        if (wantPlaying) audio.play().catch(() => {});
        return;
      } catch (_) {}
    }
    // Reenganche completo en la misma posición
    const t = audio.currentTime || 0;
    const i = index;
    const resume = wantPlaying;
    loadTrack(i, false);
    const trySeek = () => {
      try {
        if (t > 0.5 && Number.isFinite(audio.duration)) {
          audio.currentTime = Math.min(t, Math.max(0, audio.duration - 0.25));
        }
      } catch (_) {}
      if (resume) audio.play().catch(() => {});
    };
    audio.addEventListener('loadedmetadata', trySeek, { once: true });
    setTimeout(trySeek, 800);
  }

  async function resolveOnlineTrack(track, trackIndex, autoplay) {
    trackStatus.textContent = 'Resolviendo audio desde YouTube Music…';
    qualitySelect.innerHTML = '<option value="-1">Online · fuente externa</option>';
    qualitySelect.disabled = true;
    const fd = new FormData();
    fd.append('action', 'resolve');
    fd.append('url', String(track.source_url || ''));
    try {
      const response = await fetch('api_youtube.php', {method:'POST', body:fd, credentials:'same-origin', headers:{'Accept':'application/json'}});
      const data = await response.json().catch(() => ({}));
      if (!response.ok || !data.success || !data.url) throw new Error(data.error || `No se pudo resolver el audio (HTTP ${response.status}).`);
      if (index !== trackIndex || tracks[index] !== track) return;
      // El enlace resuelto es temporal. No se guarda en la biblioteca ni en localStorage.
      audio.crossOrigin = 'anonymous';
      currentUrl = data.url;
      audio.src = data.url;
      audio.load();
      trackStatus.textContent = 'YouTube Music · audio externo';
      hideNet();
      if (autoplay) {
        try { await audio.play(); }
        catch (e) { trackStatus.textContent = 'Audio listo · pulsa reproducir si el navegador lo bloqueó'; }
      }
    } catch (e) {
      if (index !== trackIndex) return;
      trackStatus.textContent = 'No se pudo acceder al audio online';
      showNetFailed(e.message || 'YouTube no permitió resolver el audio.');
    }
  }

  function loadTrack(i, autoplay = true) {
    if (!tracks[i]) return;
    index = i;
    pendingIndex = i;
    const t = tracks[i];
    trackTitle.textContent = t.name || ('Pista ' + (i + 1));
    if (miniTitle) miniTitle.textContent = trackTitle.textContent;
    loadCoverForTrack(t);
    updateMediaSession(t);
    clearNetTimer();
    hideNet();
    netAttempt = 0;
    netGaveUp = false;
    netStartedAt = 0;
    wantPlaying = !!autoplay;
    destroyHls();
    audio.removeAttribute('src');
    // Keep catalog paths unchanged; deliver HLS only through the authenticated PHP proxy.
    const storedUrl = String(t.hls || '');
    const url = storedUrl.startsWith('hls_out/')
      ? 'stream.php?path=' + encodeURIComponent(storedUrl.slice('hls_out/'.length))
      : storedUrl;
    currentUrl = url;
    qualitySelect.innerHTML = '<option value="-1">Auto</option>';
    qualitySelect.disabled = false;

    if (t.source_type === 'youtube' && t.source_url) {
      resolveOnlineTrack(t, i, autoplay);
      return;
    }

    audio.crossOrigin = 'anonymous';
    if (window.Hls && Hls.isSupported()) {
      hls = new Hls({
        enableWorker: true,
        // Preferir cambio al límite de segmento (menos cortes audibles)
        startLevel: -1,
        // Perfil equilibrado para audio VOD: subida algo más rápida, bajada prudente.
        // El EWMA rápido responde antes a mejoras; el lento evita reaccionar a un pico aislado.
        abrEwmaDefaultEstimate: 320000,
        // Para audio VOD, responder más pronto a una mejora sostenida de red.
        // hls.js prueba fragmentos reales y sube de nivel cuando estima margen suficiente.
        abrEwmaFastVoD: 2,
        abrEwmaSlowVoD: 5,
        abrBandWidthFactor: 0.78,
        abrBandWidthUpFactor: 0.88,
        maxBufferLength: 18,
        maxMaxBufferLength: 36,
        backBufferLength: 12,
        maxBufferSize: 24 * 1000 * 1000,
        fragLoadingTimeOut: 20000,
        manifestLoadingTimeOut: 15000,
        levelLoadingTimeOut: 15000,
      });
      hls.loadSource(url);
      hls.attachMedia(audio);
      setStatus('Cargando…');
      hls.on(Hls.Events.MANIFEST_PARSED, () => {
        setStatus('Auto (adaptativo)');
        qualitySelect.innerHTML = '<option value="-1">Auto (adaptativo)</option>';
        hls.levels.forEach((level, idx) => {
          const o = document.createElement('option');
          o.value = String(idx);
          const kbps = getLevelKbps(level);
          o.textContent = kbps ? `${kbps} kbps` : `Nivel ${idx + 1}`;
          qualitySelect.appendChild(o);
        });
        const tryPlay = () => {
          if (pendingSeek > 1) {
            try { audio.currentTime = pendingSeek; } catch (_) {}
            pendingSeek = 0;
          }
          if (autoplay || wantPlaying) audio.play().catch(() => {});
        };
        tryPlay();
        audio.addEventListener('loadedmetadata', tryPlay, { once: true });
      });
      // Aviso mientras espera el fin del segmento actual
      hls.on(Hls.Events.LEVEL_SWITCHING, (_e, data) => {
        const level = hls.levels[data.level];
        const kbps = getLevelKbps(level);
        if (!hls.autoLevelEnabled) {
          setStatus(kbps ? `Cambiando a ${kbps} kbps…` : 'Cambiando de calidad…');
        }
      });
      hls.on(Hls.Events.LEVEL_SWITCHED, (_e, data) => {
        const level = hls.levels[data.level];
        const kbps = getLevelKbps(level);
        if (hls.autoLevelEnabled) {
          setStatus(kbps ? `Auto · ~${kbps} kbps` : 'Auto (adaptativo)');
        } else {
          setStatus(kbps ? `${kbps} kbps` : `Nivel ${data.level + 1}`);
        }
        // Solo sincronizar el select si no hay un cambio manual pendiente al siguiente segmento.
        if (hls.nextLevel === -1 || hls.nextLevel === data.level || hls.autoLevelEnabled) {
          qualitySelect.value = hls.autoLevelEnabled ? '-1' : String(data.level);
        }
      });

      hls.on(Hls.Events.ERROR, (_e, data) => {
        if (!data) return;
        // Buffer / red no fatal: esperar y reintentar
        if (!data.fatal) {
          if (data.type === Hls.ErrorTypes.NETWORK_ERROR) {
            showNetWaiting('Red inestable, reintentando…');
          }
          return;
        }
        if (data.type === Hls.ErrorTypes.NETWORK_ERROR) {
          try { hls.startLoad(); } catch (_) {}
          scheduleNetRetry('Sin conexión. Reintentando…');
          return;
        }
        if (data.type === Hls.ErrorTypes.MEDIA_ERROR) {
          try { hls.recoverMediaError(); } catch (_) {}
          showNetWaiting('Recuperando reproducción…');
          return;
        }
        showNetFailed('No se pudo continuar la reproducción.');
      });
      hls.on(Hls.Events.FRAG_LOADED, () => {
        if (!netGaveUp && netOverlay && !netOverlay.hidden) {
          // Datos otra vez: quitar espera si el audio avanza
          if (!audio.paused || wantPlaying) hideNet();
        }
      });
    } else if (audio.canPlayType('application/vnd.apple.mpegurl')) {
      audio.src = url;
      qualitySelect.innerHTML = '<option value="-1">Nativo</option>';
      qualitySelect.disabled = true;
      setStatus('Safari nativo');
      const tryPlayN = () => {
        if (pendingSeek > 1) {
          try { audio.currentTime = pendingSeek; } catch (_) {}
          pendingSeek = 0;
        }
        if (autoplay || wantPlaying) audio.play().catch(() => {});
      };
      audio.addEventListener('loadedmetadata', tryPlayN, { once: true });
      tryPlayN();
    } else {
      setStatus('Sin soporte HLS');
    }
    renderPlaylist();
    scrollPlayingSoon();
    if (lyricsCheck?.checked) fetchLyrics(t);
    else {
      lyricLines = [];
      lyricLast = -1;
      if (lyricsBox) lyricsBox.innerHTML = '';
    }
  }

  function displayTrackTitle(name) {
    return String(name || 'Pista').replace(/^\s*\d+\s*[.)-]\s*/, '');
  }

  function renderPlaylist() {
    if (!playlistEl) return;
    playlistEl.innerHTML = '';
    tracks.forEach((t, i) => {
      const li = document.createElement('li');
      li.textContent = `${i + 1}. ${displayTrackTitle(t.name)}`; 
      li.dataset.i = String(i);
      if (i === index) li.classList.add('is-playing');
      if (i === pendingIndex) li.classList.add('is-selected');
      const pick = () => {
        pendingIndex = i;
        playlistEl.querySelectorAll('li').forEach((n) => n.classList.toggle('is-selected', Number(n.dataset.i) === i));
      };
      const go = () => loadTrack(i, true);
      // Escritorio: clic selecciona, doble clic reproduce
      li.addEventListener('dblclick', (e) => { e.preventDefault(); go(); });
      li.addEventListener('click', (e) => {
        // Ignorar click sintético tras touch (iOS/Android)
        if (li.dataset.touchHandled === '1') {
          e.preventDefault();
          e.stopPropagation();
          return;
        }
        pick();
      });
      // Móvil: doble toque en la misma fila (~450 ms)
      let lastTap = 0;
      li.addEventListener('touchend', (e) => {
        // Evita el click fantasma que reseteaba el doble toque
        e.preventDefault();
        li.dataset.touchHandled = '1';
        setTimeout(() => { li.dataset.touchHandled = '0'; }, 450);
        const now = Date.now();
        if (now - lastTap < 450 && pendingIndex === i) {
          lastTap = 0;
          go();
        } else {
          lastTap = now;
          pick();
        }
      }, { passive: false });
      playlistEl.appendChild(li);
    });
  }

  function scrollPlaying() {
    const box = playlistEl;
    const item = box?.querySelector('li.is-playing');
    if (!item || !box) return;
    // Si la lista está oculta (letras activas), no hace falta
    if (playlistWrap && playlistWrap.hidden) return;
    if (box.clientHeight < 8) return;

    // Centrar en el viewport visible del <ul>, no solo marcar selección
    const boxRect = box.getBoundingClientRect();
    const elRect = item.getBoundingClientRect();
    const viewMid = boxRect.top + boxRect.height / 2;
    const elMid = elRect.top + elRect.height / 2;
    const delta = elMid - viewMid;
    const max = Math.max(0, box.scrollHeight - box.clientHeight);
    const next = Math.max(0, Math.min(max, box.scrollTop + delta));
    // 'auto' es más fiable dentro del iframe/overlay
    box.scrollTop = next;
  }

  function scrollPlayingSoon() {
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        scrollPlaying();
        // segundo intento por si el layout del iframe aún no midió bien
        setTimeout(scrollPlaying, 80);
      });
    });
  }

  function cleanTitle(name) {
    return String(name || '')
      .replace(/^\d+[\s._\-]+/, '')
      .replace(/\.(mp3|flac|ogg|wma|m4a|wav|aac)$/i, '')
      .trim();
  }

  function lrcParse(s) {
    return String(s || '')
      .split(/\r?\n/)
      .flatMap((line) => {
        const times = [...line.matchAll(/\[(\d{1,3}):(\d{2})(?:[.:](\d{1,3}))?\]/g)];
        const text = line.replace(/\[(?:\d{1,3}):(\d{2})(?:[.:](?:\d{1,3}))?\]/g, '').trim();
        if (!times.length || !text) return [];
        return times.map((m) => ({
          time: Number(m[1]) * 60 + Number(m[2]) + Number('0.' + (m[3] || 0)),
          text,
        }));
      })
      .sort((a, b) => a.time - b.time);
  }

  /**
   * Centra la línea activa en el VIEWPORT del contenedor (no respecto a todo el texto).
   * Al inicio/final el scroll se satura solo; en el tramo medio queda a mitad de pantalla.
   */
  function centerLyricLine(el, force) {
    if (!lyricsBox || !el) return;
    if (!force && Date.now() < lyricManualUntil) return;
    const box = lyricsBox;
    const boxRect = box.getBoundingClientRect();
    const elRect = el.getBoundingClientRect();
    if (boxRect.height < 8) return;

    const viewMid = boxRect.top + boxRect.height / 2;
    const elMid = elRect.top + elRect.height / 2;
    const delta = elMid - viewMid;

    // Evitar micro-ajustes si ya está cerca del centro (salvo force tras scroll manual)
    const band = boxRect.height * 0.1;
    if (!force && Math.abs(delta) < band) return;

    const max = Math.max(0, box.scrollHeight - box.clientHeight);
    const next = Math.max(0, Math.min(max, box.scrollTop + delta));
    box.scrollTo({ top: next, behavior: force ? 'auto' : 'smooth' });
  }

  function scheduleLyricFollow() {
    if (lyricFollowTimer) clearTimeout(lyricFollowTimer);
    lyricFollowTimer = setTimeout(() => {
      lyricFollowTimer = null;
      lyricManualUntil = 0;
      const el = lyricLast >= 0 ? lyricLines[lyricLast]?.el : null;
      if (el) centerLyricLine(el, true);
    }, 1800);
  }

  function bindLyricUserScroll() {
    if (!lyricsBox || lyricsBox.dataset.scrollBound === '1') return;
    lyricsBox.dataset.scrollBound = '1';
    const suspend = () => {
      lyricManualUntil = Date.now() + 1800;
      scheduleLyricFollow();
    };
    lyricsBox.addEventListener('wheel', suspend, { passive: true });
    lyricsBox.addEventListener('touchstart', suspend, { passive: true });
    lyricsBox.addEventListener('touchmove', suspend, { passive: true });
    lyricsBox.addEventListener('pointerdown', suspend, { passive: true });
  }

  function updateLyricHighlight() {
    if (!lyricLines.length) return;
    const cur = audio.currentTime || 0;
    let i = -1;
    for (let k = 0; k < lyricLines.length; k++) {
      if (lyricLines[k].time <= cur) i = k;
      else break;
    }
    if (i !== lyricLast) {
      if (lyricLast >= 0 && lyricLines[lyricLast]) {
        lyricLines[lyricLast].el.classList.remove('is-active');
        if (lyricLines[lyricLast].kEl) lyricLines[lyricLast].kEl.classList.remove('is-active');
      }
      if (i >= 0 && lyricLines[i]) {
        lyricLines[i].el.classList.add('is-active');
        if (lyricLines[i].kEl) lyricLines[i].kEl.classList.add('is-active');
      }
      lyricLast = i;
    }
    if (i >= 0 && lyricLines[i]) {
      if (lyricsBox && !lyricsBox.hidden) centerLyricLine(lyricLines[i].el, false);
      if (karaokeOverlay && !karaokeOverlay.hidden && lyricLines[i].kEl) {
        centerInBox(karaokeLyrics, lyricLines[i].kEl, false);
      }
    }
  }

  function centerInBox(box, el, force) {
    if (!box || !el) return;
    if (!force && Date.now() < lyricManualUntil) return;
    const boxRect = box.getBoundingClientRect();
    const elRect = el.getBoundingClientRect();
    if (boxRect.height < 8) return;
    const delta = (elRect.top + elRect.height / 2) - (boxRect.top + boxRect.height / 2);
    const band = boxRect.height * 0.1;
    if (!force && Math.abs(delta) < band) return;
    const max = Math.max(0, box.scrollHeight - box.clientHeight);
    box.scrollTo({ top: Math.max(0, Math.min(max, box.scrollTop + delta)), behavior: force ? 'auto' : 'smooth' });
  }

  function syncKaraokeFromLines() {
    if (!karaokeLyrics || !lyricLines.length) return;
    karaokeLyrics.replaceChildren();
    const topGap = document.createElement('div');
    topGap.className = 'lyrics-gap';
    const bottomGap = document.createElement('div');
    bottomGap.className = 'lyrics-gap';
    karaokeLyrics.appendChild(topGap);
    lyricLines.forEach((line) => {
      const p = document.createElement('p');
      p.className = 'lyrics-line karaoke-line';
      p.textContent = line.text;
      if (line.el?.classList.contains('is-active')) p.classList.add('is-active');
      karaokeLyrics.appendChild(p);
      line.kEl = p;
    });
    karaokeLyrics.appendChild(bottomGap);
    const h = karaokeLyrics.clientHeight || (window.innerHeight * 0.7);
    const gapH = Math.max(80, Math.floor(h * 0.4));
    topGap.style.height = gapH + 'px';
    bottomGap.style.height = gapH + 'px';
  }

  function updateMaxEnabled() {
    const on = !!(lyricsCheck && lyricsCheck.checked);
    if (btnMax) {
      btnMax.disabled = !on;
      btnMax.title = on ? 'Karaoke (maximizar letras)' : 'Activa «Mostrar letra» para usar karaoke';
    }
  }

  function renderPlainLyrics(text) {
    lyricLines = [];
    lyricLast = -1;
    lyricsBox.textContent = text;
      }

  function renderSyncedLyrics(synced, plainFallback) {
    const parsed = lrcParse(synced);
    lyricsBox.replaceChildren();
    lyricLines = [];
    lyricLast = -1;
    if (!parsed.length) {
      renderPlainLyrics(plainFallback || 'No se encontró letra sincronizada.');
      return;
    }
    // Espaciadores para poder centrar primera y última línea
    const topGap = document.createElement('div');
    topGap.className = 'lyrics-gap';
    topGap.setAttribute('aria-hidden', 'true');
    const bottomGap = document.createElement('div');
    bottomGap.className = 'lyrics-gap';
    bottomGap.setAttribute('aria-hidden', 'true');
    lyricsBox.appendChild(topGap);
    parsed.forEach((line) => {
      const p = document.createElement('p');
      p.className = 'lyrics-line';
      p.textContent = line.text;
      lyricsBox.appendChild(p);
      lyricLines.push({ time: line.time, text: line.text, el: p });
    });
    lyricsBox.appendChild(bottomGap);
    const syncGaps = () => {
      const h = lyricsBox.clientHeight || 240;
      // Solo lo necesario para permitir centrar en el viewport visible
      const gapH = Math.max(48, Math.floor(h * 0.42));
      topGap.style.height = gapH + 'px';
      bottomGap.style.height = gapH + 'px';
    };
    syncGaps();
    requestAnimationFrame(() => {
      syncGaps();
      bindLyricUserScroll();
      lyricLast = -1;
      updateLyricHighlight();
      // Asegurar primera línea activa visible
      if (lyricLines[0]) centerLyricLine(lyricLines[0].el, true);
    });
    syncKaraokeFromLines();
  }

  async function fetchLyrics(track) {
    if (!lrclibOn || !lyricsBox) return;
    const serial = ++lyricSerial;
    lyricsBox.hidden = false;
    lyricsBox.textContent = 'Consultando LRCLIB (mejor calidad sincronizada)…';
    setLyricsSource('buscando…');
    lyricLines = [];
    lyricLast = -1;
    const artist = (payload.album.artist || '').trim();
    const albumTitle = (payload.album.title || '').trim();
    const title = cleanTitle(track.name);
    const duration = Math.round(Number(track.duration) || audio.duration || 0);
    const q = new URLSearchParams({
      title,
      artist,
      album: albumTitle,
      duration: String(duration || 0),
    });
    try {
      const r = await fetch('api_lyrics.php?' + q.toString(), {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' },
      });
      const data = await r.json().catch(() => ({}));
      if (serial !== lyricSerial) return;
      if (r.status === 503) {
        lyricsBox.textContent = data.error || 'LRCLIB ocupado (503). Reintenta en unos segundos.';
                return;
      }
      if (!r.ok || data.error) {
        lyricsBox.textContent = data.error || ('No se pudo cargar la letra (HTTP ' + r.status + ')');
        return;
      }
      const srcMap = {
        lrclib: 'LRCLIB',
        'lyrics.ovh': 'lyrics.ovh',
        musixmatch: 'Musixmatch',
        genius: 'Genius',
      };
      const srcLabel = srcMap[data.source] || data.source || null;
      if (data.synced) {
        renderSyncedLyrics(data.synced, data.plain || '');
        setLyricsSource(srcLabel || 'LRCLIB');
      } else if (data.plain) {
        renderPlainLyrics(data.plain);
        setLyricsSource(srcLabel ? (srcLabel + ' · texto') : 'texto');
      } else {
        renderPlainLyrics(
          'No se encontró letra para «' + title + '»' + (artist ? ' — ' + artist : '') + '.'
        );
        setLyricsSource(data.tried ? data.tried.join(' → ') : 'sin resultado');
      }
    } catch (e) {
      if (serial !== lyricSerial) return;
      lyricsBox.textContent = e.message || 'Error al consultar letras.';
      setLyricsSource('error');
    }
  }

  playPauseBtn.addEventListener('click', () => {
    ensureEq();
    if (audioCtx?.state === 'suspended') audioCtx.resume().catch(() => {});
    if (audio.paused) {
      wantPlaying = true;
      audio.play().catch(() => {});
    } else {
      wantPlaying = false;
      audio.pause();
    }
  });

  audio.addEventListener('waiting', () => {
    if (wantPlaying) showNetWaiting('Cargando buffer…');
  });
  audio.addEventListener('stalled', () => {
    if (wantPlaying) scheduleNetRetry('Red lenta o cortada. Reintentando…');
  });
  audio.addEventListener('playing', () => {
    hideNet();
    wantPlaying = true;
  });
  audio.addEventListener('pause', () => {
    // pause voluntario: no forzar reintento
  });

  window.addEventListener('online', () => {
    if (netGaveUp) {
      showNetFailed('Red disponible. Pulsa Reintentar.');
      return;
    }
    if (netOverlay && !netOverlay.hidden) {
      netAttempt = 0;
      recoverPlayback();
    }
  });
  window.addEventListener('offline', () => {
    if (wantPlaying) showNetWaiting('Sin conexión. Esperando red…');
  });

  netRetryBtn?.addEventListener('click', () => {
    netGaveUp = false;
    netAttempt = 0;
    netStartedAt = 0;
    wantPlaying = true;
    showNetWaiting('Reintentando…');
    recoverPlayback();
  });
  audio.addEventListener('play', () => {
    playPauseBtn.textContent = '⏸';
    if (navigator.mediaSession) navigator.mediaSession.playbackState = 'playing';
    updateMediaSession(tracks[index]);
  });
  audio.addEventListener('pause', () => {
    playPauseBtn.textContent = '▶';
    if (navigator.mediaSession) navigator.mediaSession.playbackState = 'paused';
    writeProgress();
  });
  audio.addEventListener('ended', () => {
    if (index + 1 < tracks.length) loadTrack(index + 1, true);
  });
  audio.addEventListener('timeupdate', () => {
    const d = audio.duration || 0;
    if (d > 0) progressBar.style.width = `${(audio.currentTime / d) * 100}%`;
    currentTimeEl.textContent = formatTime(audio.currentTime);
    durationTimeEl.textContent = formatTime(d);
    updateLyricHighlight();
  });
  audio.addEventListener('seeked', () => {
    lyricLast = -1;
    updateLyricHighlight();
  });
  progressBarBg.addEventListener('click', (e) => {
    const rect = progressBarBg.getBoundingClientRect();
    const pos = (e.clientX - rect.left) / rect.width;
    if (audio.duration) audio.currentTime = pos * audio.duration;
  });
  prevBtn.addEventListener('click', () => loadTrack(Math.max(0, index - 1), true));
  nextBtn.addEventListener('click', () => {
    if (index + 1 < tracks.length) loadTrack(index + 1, true);
  });
  volumeSlider.addEventListener('input', (e) => {
    audio.volume = Number(e.target.value);
    audio.muted = audio.volume === 0;
    muteBtn.textContent = audio.muted || !audio.volume ? '🔇' : '🔊';
  });
  muteBtn.addEventListener('click', () => {
    audio.muted = !audio.muted;
    volumeSlider.value = audio.muted ? 0 : audio.volume || 1;
    muteBtn.textContent = audio.muted ? '🔇' : '🔊';
  });
  qualitySelect.addEventListener('change', (e) => {
    if (!hls) return;
    const v = parseInt(e.target.value, 10);
    // Auto: reactivar ABR
    if (Number.isNaN(v) || v < 0) {
      hls.currentLevel = -1; // habilita selección automática
      setStatus('Auto (adaptativo)');
      return;
    }
    // Manual: programar cambio en el PRÓXIMO segmento (nextLevel),
    // no cortar el fragmento en curso (currentLevel provoca el “clic”/pausa).
    const level = hls.levels[v];
    if (!level) return;
    const kbps = getLevelKbps(level);
    const label = kbps ? `${kbps} kbps` : `Nivel ${v + 1}`;
    if (hls.currentLevel === v) {
      setStatus(label);
      return;
    }
    hls.nextLevel = v;
    setStatus(`Cambiando a ${label} al final del tramo…`);
  });

  if (lyricsCheck) {
    lyricsCheck.checked = localStorage.getItem(LYRICS_KEY) === 'true';
    const applyLyricsUi = () => {
      const on = lyricsCheck.checked;
      localStorage.setItem(LYRICS_KEY, on ? 'true' : 'false');
      if (playlistWrap) playlistWrap.hidden = on;
      if (lyricsBox) {
        lyricsBox.hidden = !on;
        if (!on) {
          lyricsBox.innerHTML = '';
          lyricLines = [];
          lyricLast = -1;
          setLyricsSource('');
          if (karaokeOverlay) karaokeOverlay.hidden = true;
        }
      }
      updateMaxEnabled();

  function closePlayer() {
    wantPlaying = false;
    try { audio.pause(); } catch (_) {}
    writeProgress();
    labPostParent({ type: 'close', title: trackTitle?.textContent || '', time: audio.currentTime || 0, index: index });
    if (!isEmbed) {
      // Standalone: volver a biblioteca
      const aid = payload.album && payload.album.id;
      window.location.href = aid ? ('index.php?view=library') : 'index.php';
    }
  }
  btnClose?.addEventListener('click', closePlayer);
      if (on) fetchLyrics(tracks[index]);
    };
    lyricsCheck.addEventListener('change', applyLyricsUi);
    if (!lrclibOn) {
      lyricsCheck.disabled = true;
      lyricsCheck.parentElement?.classList.add('muted');
    } else {
      applyLyricsUi();
    }
  }
  karaokeClose?.addEventListener('click', () => { karaokeOverlay.hidden = true; });


  // Controles tipo ventana
  async function loadLibPanel() {
    if (!libPanelBody) return;
    libPanelBody.textContent = 'Cargando biblioteca…';
    try {
      const r = await fetch('index.php?view=library', { credentials: 'same-origin' });
      const html = await r.text();
      const doc = new DOMParser().parseFromString(html, 'text/html');
      const grid = doc.querySelector('.album-grid') || doc.querySelector('.lab-main');
      libPanelBody.innerHTML = '';
      if (grid) libPanelBody.appendChild(document.importNode(grid, true));
      else libPanelBody.innerHTML = '<p class="muted"><a href="index.php?view=library">Abrir biblioteca</a></p>';
      if (typeof window.labBindAlbumMenus === 'function') {
        window.labBindAlbumMenus(libPanelBody);
      }
    } catch {
      libPanelBody.innerHTML = '<p class="muted"><a href="index.php?view=library">Abrir biblioteca</a></p>';
    }
  }

  // Mini-reproductor flotante arrastrable (estilo Drive-Craft)
  function enableMiniDrag(el) {
    if (!el || el.dataset.dragBound === '1') return;
    el.dataset.dragBound = '1';
    let dragging = false;
    let startX = 0, startY = 0, origL = 0, origT = 0;

    const onDown = (e) => {
      if (e.target.closest('button, a, input, select')) return;
      dragging = true;
      el.classList.add('is-dragging');
      const pt = e.touches ? e.touches[0] : e;
      startX = pt.clientX;
      startY = pt.clientY;
      const rect = el.getBoundingClientRect();
      origL = rect.left;
      origT = rect.top;
      el.style.right = 'auto';
      el.style.bottom = 'auto';
      el.style.left = origL + 'px';
      el.style.top = origT + 'px';
      e.preventDefault();
    };
    const onMove = (e) => {
      if (!dragging) return;
      const pt = e.touches ? e.touches[0] : e;
      let nl = origL + (pt.clientX - startX);
      let nt = origT + (pt.clientY - startY);
      const maxL = window.innerWidth - el.offsetWidth - 4;
      const maxT = window.innerHeight - el.offsetHeight - 4;
      nl = Math.max(4, Math.min(maxL, nl));
      nt = Math.max(4, Math.min(maxT, nt));
      el.style.left = nl + 'px';
      el.style.top = nt + 'px';
    };
    const onUp = () => {
      if (!dragging) return;
      dragging = false;
      el.classList.remove('is-dragging');
    };
    el.addEventListener('mousedown', onDown);
    el.addEventListener('touchstart', onDown, { passive: false });
    window.addEventListener('mousemove', onMove);
    window.addEventListener('touchmove', onMove, { passive: false });
    window.addEventListener('mouseup', onUp);
    window.addEventListener('touchend', onUp);
  }

  btnMin?.addEventListener('click', () => {
    if (isEmbed) {
      labPostParent({
        type: 'minimize',
        title: trackTitle?.textContent || '',
        status: trackStatus?.textContent || '',
        art: trackArt?.src || '',
        playing: !audio.paused,
      });
      return;
    }
    if (!playerRoot || !miniDock) return;
    playerRoot.hidden = true;
    miniDock.hidden = false;
    enableMiniDrag(miniDock);
    if (libPanel) {
      libPanel.hidden = false;
      loadLibPanel();
    }
    if (miniTitle) miniTitle.textContent = trackTitle?.textContent || '';
    if (miniStatus) miniStatus.textContent = trackStatus?.textContent || '';
    if (miniPlay) miniPlay.textContent = audio.paused ? '▶' : '⏸';
  });
  function restorePlayer() {
    if (playerRoot) playerRoot.hidden = false;
    if (miniDock) miniDock.hidden = true;
    if (libPanel) libPanel.hidden = true;
  }
  miniRestore?.addEventListener('click', restorePlayer);
  libRestorePlayer?.addEventListener('click', restorePlayer);
  miniPlay?.addEventListener('click', () => playPauseBtn?.click());
  btnMax?.addEventListener('click', () => {
    if (!lyricsCheck?.checked || btnMax.disabled) return;
    if (karaokeOverlay) {
      karaokeOverlay.hidden = false;
      syncKaraokeFromLines();
      requestAnimationFrame(() => {
        const active = lyricLines.find((l) => l.el?.classList.contains('is-active'));
        if (active?.kEl) centerInBox(karaokeLyrics, active.kEl, true);
      });
    }
  });
  karaokeClose?.addEventListener('click', () => {
    if (karaokeOverlay) karaokeOverlay.hidden = true;
  });
  updateMaxEnabled();

  function closePlayer() {
    wantPlaying = false;
    try { audio.pause(); } catch (_) {}
    writeProgress();
    labPostParent({ type: 'close', title: trackTitle?.textContent || '', time: audio.currentTime || 0, index: index });
    if (!isEmbed) {
      // Standalone: volver a biblioteca
      const aid = payload.album && payload.album.id;
      window.location.href = aid ? ('index.php?view=library') : 'index.php';
    }
  }
  btnClose?.addEventListener('click', closePlayer);

  // EQ panel
  eqToggle?.addEventListener('click', () => {
    ensureEq();
    if (audioCtx?.state === 'suspended') audioCtx.resume().catch(() => {});
    if (eqPanel) eqPanel.hidden = !eqPanel.hidden;
  });
  [eqBass, eqMid, eqTreble].forEach((el) => {
    el?.addEventListener('input', () => { ensureEq(); applyEqGains(); });
  });
  eqReset?.addEventListener('click', () => {
    if (eqBass) eqBass.value = '0';
    if (eqMid) eqMid.value = '0';
    if (eqTreble) eqTreble.value = '0';
    applyEqGains();
  });

  // Sync mini play icon
  audio.addEventListener('play', () => { if (miniPlay) miniPlay.textContent = '⏸'; });
  audio.addEventListener('pause', () => { if (miniPlay) miniPlay.textContent = '▶'; });

  window.addEventListener('message', (ev) => {
    const d = ev.data;
    if (!d || d.source !== 'lab-host') return;
    if (d.type === 'playpause') playPauseBtn?.click();
    if (d.type === 'close') closePlayer();
    if (d.type === 'restore') {
      // parent shows iframe again
    }
  });

  audio.addEventListener('play', () => labPostParent({ type: 'state', playing: true, title: trackTitle?.textContent || '' }));
  audio.addEventListener('pause', () => labPostParent({ type: 'state', playing: false, title: trackTitle?.textContent || '' }));

  // Restaurar última pista y posición (estilo Drive-Craft) e iniciar reproducción
  (function boot() {
    const saved = readProgress();
    let start = index;
    pendingSeek = 0;
    // Una selección explícita (botón ▶ de una pista) debe prevalecer sobre
    // el progreso guardado; de lo contrario cualquier botón abría la pista previa.
    if (payload.startIndexExplicit && payload.startIndex >= 0 && payload.startIndex < tracks.length) {
      start = payload.startIndex;
    } else if (saved && Number.isFinite(saved.index) && saved.index >= 0 && saved.index < tracks.length) {
      start = saved.index;
      if (Number(saved.time) > 3) pendingSeek = Number(saved.time);
    } else if (payload.startIndex >= 0 && payload.startIndex < tracks.length) {
      start = payload.startIndex;
    }
    index = start;
    pendingIndex = start;
    loadTrack(start, true);
  })();
})();
