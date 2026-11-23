(() => {
  'use strict';
  const payload = window.LAB_PAYLOAD;
  if (!payload?.album?.tracks?.length) return;

  const tracks = payload.album.tracks;
  let lrclibOn = !!payload.lrclib;
  let index = Math.max(0, Math.min(tracks.length - 1, Number(payload.startIndex) || 0));
  let shuffleEnabled=false,shuffleBag=[],shuffleHistory=[],shuffleVisited=new Set([index]);
  function peekNextTrack(){
    if(!shuffleEnabled)return (index+1)%tracks.length;
    shuffleBag=shuffleBag.filter(i=>i!==index);
    if(!shuffleBag.length){if(shuffleVisited.size>=tracks.length&&repeatMode!=='off')shuffleVisited=new Set([index]);shuffleBag=tracks.map((_,i)=>i).filter(i=>i!==index&&!shuffleVisited.has(i));for(let i=shuffleBag.length-1;i>0;i--){const j=Math.floor(Math.random()*(i+1));[shuffleBag[i],shuffleBag[j]]=[shuffleBag[j],shuffleBag[i]];}}
    return shuffleBag.length?shuffleBag[shuffleBag.length-1]:index;
  }
  function advanceTrack(){
    if(!tracks.length)return;
    if(repeatMode==='off'&&!hasNextQueueTrack()){wantPlaying=false;audio.pause();writeProgress();publishShellState();return;}
    if(!shuffleEnabled){loadTrack((index+1)%tracks.length,true);return;}
    shuffleBag=shuffleBag.filter(i=>i!==index);
    if(!shuffleBag.length){if(shuffleVisited.size>=tracks.length&&repeatMode!=='off')shuffleVisited=new Set([index]);
      shuffleBag=tracks.map((_,i)=>i).filter(i=>i!==index&&!shuffleVisited.has(i));
      for(let i=shuffleBag.length-1;i>0;i--){const j=Math.floor(Math.random()*(i+1));[shuffleBag[i],shuffleBag[j]]=[shuffleBag[j],shuffleBag[i]];}
    }
    shuffleBag=shuffleBag.filter(i=>i!==index);
    shuffleHistory.push(index);if(shuffleHistory.length>200)shuffleHistory.shift();
    const next=shuffleBag.length?shuffleBag.pop():index;shuffleVisited.add(next);loadTrack(next,true);
  }
  function previousTrack(){loadTrack(shuffleEnabled&&shuffleHistory.length?shuffleHistory.pop():Math.max(0,index-1),true);}
  const shuffleButton=document.getElementById('shuffleBtn');
  shuffleButton?.addEventListener('click',()=>{
    shuffleEnabled=!shuffleEnabled;shuffleBag=[];shuffleHistory=[];shuffleVisited=new Set([index]);nextBuffer?.clear();
    const label=shuffleEnabled?'Reproducción aleatoria':'Reproducción continua';
    shuffleButton.setAttribute('aria-pressed',String(shuffleEnabled));shuffleButton.title=label;
    shuffleButton.setAttribute('aria-label',shuffleEnabled?'Activar reproducción continua':'Activar reproducción aleatoria');
    window.sfPlayerNotice?.(label);
  });
  let nextBuffer=payload.prefetchNext ? window.sfNextBuffer?.() : null;
  window.addEventListener("pagehide",()=>nextBuffer?.clear());
  let hls = null;
  let pendingIndex = index;
  let currentUrl = '';
  let wantPlaying = false;
  let netTimer = null;
  let netAttempt = 0;
  let netGaveUp = false;
  const NET_MAX_MS = 90000; // ~90 s de reintentos
  let netStartedAt = 0;
  let hasPlayedCurrentTrack = false;
  let netVisualTimer = null;
  let autoResumeTimer = null;
  const NET_VISUAL_DELAY_MS = 1500;
  function clearNetVisualTimer() {
    if (netVisualTimer !== null) { clearTimeout(netVisualTimer); netVisualTimer = null; }
  }
  function showNetAfterDelay(message) {
    if (!wantPlaying || !hasPlayedCurrentTrack || netGaveUp) return;
    if (navigator.onLine === false) { showNetWaiting('Sin conexión. Esperando red…'); return; }
    if (netVisualTimer !== null || (netOverlay && !netOverlay.hidden)) return;
    netVisualTimer = setTimeout(() => {
      netVisualTimer = null;
      if (wantPlaying && hasPlayedCurrentTrack && audio.paused === false && audio.readyState < HTMLMediaElement.HAVE_FUTURE_DATA) {
        showNetWaiting(message || 'Reconectando audio…');
      }
    }, NET_VISUAL_DELAY_MS);
  }

  const netOverlay = document.getElementById('netOverlay');
  const netSpinner = document.getElementById('netSpinner');
  const netMsg = document.getElementById('netMsg');
  const netRetryBtn = document.getElementById('netRetryBtn');

  const appleMobileDevice = /iPhone|iPad|iPod/.test(navigator.userAgent)
    || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const localAudio = document.getElementById('audioPlayer');
  // En iPhone/iPad WebKit no deja iniciar audio dentro del iframe sin un toque
  // propio del iframe. El shell crea y desbloquea un <audio> en la página
  // principal durante el toque real del álbum; aquí lo reutilizamos (mismo
  // origen), de modo que la reproducción empieza con ese único toque.
  let shellAudio = null;
  if (window.parent !== window) {
    try { shellAudio = window.parent.sfShellAudio || null; } catch (_) { shellAudio = null; }
  }
  const audio = (appleMobileDevice && shellAudio) ? shellAudio : localAudio;
  const usesShellAudio = audio !== localAudio;
  const diagnostics=window.sfCreatePlaybackDiagnostics?.(audio,payload,()=>wantPlaying);
  window.addEventListener('sf:offline-session-ended',()=>{wantPlaying=false;audio.pause();clearNetTimer();destroyHls();showNetFailed('La sesión terminó. Inicia sesión para continuar.');});
  let loadSequence=0,recoveryPosition=0,lastRecovery=0,lastAdvanceAt=Date.now(),lastWatchPosition=0;
  const playPauseBtn = document.getElementById('playPauseBtn');
  const progressBarBg = document.getElementById('progressBarBg');
  const progressBar = document.getElementById('progressBar');
  const currentTimeEl = document.getElementById('currentTime');
  const durationTimeEl = document.getElementById('durationTime');
  const volumeSlider = document.getElementById('volumeSlider');
  const muteBtn = document.getElementById('muteBtn');
  const qualitySelect = document.getElementById('qualitySelect');
  const restartBtn = document.getElementById('restartBtn');
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
  let albumIdKey = (payload.album && payload.album.id) ? String(payload.album.id) : 'single';
  let PROGRESS_KEY = 'lab_progress_' + albumIdKey;
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

  function shellNowPlaying() {
    if (!usesShellAudio) return;
    const track = tracks[index];
    const artist = track?.artist || track?.collection_artist || (!track?.origin_album&&payload.album?.artist) || 'Sparrow-Fy';
    const album = track?.album || track?.collection_title || (payload.album && payload.album.title) || 'Sparrow-Fy';
    const title = (track && track.name) ? cleanTitle(track.name) : (trackTitle?.textContent || 'Reproduciendo');
    const art = trackArt && !trackArt.hidden && trackArt.src ? trackArt.src : '';
    labPostParent({ type: 'nowplaying', title, artist, album, art, playing: !audio.paused });
  }

  function updateMediaSession(track) {
    // Con el audio del shell los metadatos y los controles de pantalla
    // bloqueada los gestiona la página principal, que es dueña del elemento.
    if (usesShellAudio) { shellNowPlaying(); return; }
    if (!('mediaSession' in navigator)) return;
    const artist = track?.artist || track?.collection_artist || (!track?.origin_album&&payload.album?.artist) || 'Sparrow-Fy';
    const album = track?.album || track?.collection_title || (payload.album && payload.album.title) || 'Sparrow-Fy';
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
    if(usesShellAudio)return;
    if(!('mediaSession' in navigator))return;
    const seek=seconds=>{if(Number.isFinite(audio.duration)){audio.currentTime=Math.max(0,Math.min(audio.duration,seconds));writeProgress();updateMediaPosition();}};
    const actions={play:()=>{wantPlaying=true;if(audio.error||netGaveUp){netGaveUp=false;recoverPlayback();}else requestPlayback();},pause:()=>{wantPlaying=false;audio.pause();writeProgress();},stop:()=>{wantPlaying=false;audio.pause();seek(0);},previoustrack:previousTrack,nexttrack:advanceTrack,seekto:d=>{if(Number.isFinite(d.seekTime))seek(d.seekTime);},seekbackward:d=>seek(audio.currentTime-(d.seekOffset||10)),seekforward:d=>seek(audio.currentTime+(d.seekOffset||10))};
    for(const [name,handler] of Object.entries(actions)){try{navigator.mediaSession.setActionHandler(name,handler);}catch(_){}}
  }
  let lastMediaPosition=0;
  function updateMediaPosition(){
    if(usesShellAudio)return;
    if(!navigator.mediaSession?.setPositionState||!Number.isFinite(audio.duration)||audio.duration<=0)return;
    try{navigator.mediaSession.setPositionState({duration:audio.duration,playbackRate:audio.playbackRate||1,position:Math.max(0,Math.min(audio.duration,audio.currentTime||0))});}catch(_){}
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
  // Aviso de bloqueo de reproducción automática. Debe desaparecer en cuanto
  // el audio empieza de verdad (al pulsar Reproducir o al desbloquearse iOS).
  let autoplayHint = null;
  function setAutoplayHint(msg) { autoplayHint = msg; setStatus(msg); }
  // Estados pasajeros (reconexión, aviso de reproducción, resolución externa).
  // Si el audio ya suena o se abre otra pista no deben quedarse bajo el título.
  const transientStatus = /^(Reconectando|Recuperando|Esperando|Cargando|Sin conexi[oó]n|Sin red|Pulsa Reproducir|Pulse reproducir|Resolviendo|Audio listo|Red inestable)/i;
  function idleStatusText() {
    const t = tracks[index] || {};
    const artist = t.artist || t.collection_artist || (!t.origin_album && payload.album?.artist) || payload.album?.artist || '';
    if (artist) return String(artist);
    return hls ? 'Auto' : '';
  }
  function clearTransientStatus() {
    if (!trackStatus) return;
    if (transientStatus.test(String(trackStatus.textContent || '').trim())) setStatus(idleStatusText());
  }

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

  let normalizationGain=null,normalizeEnabled=payload.normalizeVolume!==false;
  const normalizeToggle=document.getElementById('normalizeVolume');
  if(normalizeToggle)normalizeToggle.checked=normalizeEnabled;
  function volumeGain(track,enabled){
    const l=track?.loudness;if(!enabled||!l)return 1;
    const integrated=Number(l.lufs),peak=Number(l.true_peak);
    if(!Number.isFinite(integrated)||!Number.isFinite(peak))return 1;
    // Constant gain, maximum +12 dB, with 2 dB of true-peak headroom.
    const db=Math.min(-14-integrated,-2-peak,12);
    return Math.pow(10,db/20);
  }
  function applyNormalization(){
    if(!normalizationGain||!audioCtx)return;
    const gain=volumeGain(tracks[index],normalizeEnabled);
    normalizationGain.gain.setTargetAtTime(gain,audioCtx.currentTime,.04);
    if(normalizeToggle)normalizeToggle.title=tracks[index]?.loudness?'Ajuste de volumen sin compresión':'Pista pendiente de análisis; volumen original';
  }
  normalizeToggle?.addEventListener('change',async()=>{
    const before=normalizeEnabled;normalizeToggle.disabled=true;
    try{
      const fd=new FormData();fd.set('enabled',normalizeToggle.checked?'1':'0');fd.set('csrf_token',document.querySelector('meta[name="sf-csrf"]')?.content||'');
      const r=await fetch('api_volume_preference.php',{method:'POST',body:fd});if(!r.ok)throw Error('No se pudo guardar');
      normalizeEnabled=(await r.json()).enabled;eqUserRequested=true;ensureEq(true);applyNormalization();
      window.sfPlayerNotice?.(normalizeEnabled?'Normalizar activado':'Normalizar desactivado');
    }catch(e){normalizeEnabled=before;normalizeToggle.checked=before;window.sfPlayerNotice?.('No se pudo guardar el ajuste de volumen');}
    finally{normalizeToggle.disabled=false;}
  });
  // En móvil el audio no pasa por Web Audio salvo que el usuario lo pida
  // (ecualizador o normalización). Safari y Chrome suspenden el AudioContext
  // cuando la pestaña pasa a segundo plano y, como createMediaElementSource
  // captura la salida del <audio>, la reproducción se queda en silencio o se
  // pausa al cambiar de app o apagar la pantalla. Con la ruta nativa el audio
  // sigue sonando en segundo plano y el sistema solo lo interrumpe en llamadas
  // o cuando otra app toma el micrófono.
  const mobileNativeAudio = appleMobileDevice
    || /Android/i.test(navigator.userAgent);
  let eqUserRequested = false;

  function ensureEq(force = false) {
    if (eqReady) return;
    // El <audio> del shell vive en otro documento: no se puede conectar a un
    // AudioContext de este iframe. En iPhone/iPad se usa la ruta nativa.
    if (usesShellAudio) return;
    if (mobileNativeAudio && !force && !eqUserRequested) return;
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
      normalizationGain=audioCtx.createGain();
      trebleF.connect(normalizationGain);
      normalizationGain.connect(audioCtx.destination);
      audioCtx.addEventListener?.('statechange',()=>{
        // El AudioContext debe seguir vivo aunque la pestaña esté oculta.
        if(audioCtx.state!=='running'&&wantPlaying)setTimeout(()=>audioCtx.resume().catch(()=>{}),300);
      });
      eqReady = true;
      applyNormalization();
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

  let coverRequestSerial=0;
  let coverAbort=null;
  const coverLookupCache=new Map();
  async function loadCoverForTrack(track,deferUntilPlaying=false) {
    const serial=++coverRequestSerial;
    coverAbort?.abort();
    const abort=new AbortController();coverAbort=abort;
    const saved=String(track.artwork||'').trim();
    const local=saved||(track.origin_album?track.collection_cover:payload.localCover)||'';
    const show=cover=>{
      if(serial!==coverRequestSerial)return;
      // Decode off-screen; stale artwork must never replace a newer track.
      for(const image of [trackArt,miniArt]){
        if(!image)continue;
        const request=(image.sfCoverRequest||0)+1;image.sfCoverRequest=request;
        image.hidden=true;
        if(!cover){image.removeAttribute('src');continue;}
        const prepared=new Image();prepared.decoding='async';
        const reveal=()=>{
          if(serial!==coverRequestSerial||image.sfCoverRequest!==request||!prepared.naturalWidth)return;
          image.src=cover;image.hidden=false;
        };
        prepared.onload=()=>{
          if(prepared.decode)prepared.decode().then(reveal).catch(()=>{});
          else reveal();
        };
        prepared.src=cover;
      }
    };
    show(local);
    // A chosen track cover or collection cover is authoritative.
    if(local)return;
    if(deferUntilPlaying){
      await new Promise(resolve=>{
        audio.addEventListener('playing',resolve,{once:true,signal:abort.signal});
        abort.signal.addEventListener('abort',resolve,{once:true});
      });
      if(abort.signal.aborted || serial!==coverRequestSerial)return;
    }

    const artist=String(track.artist||(track.origin_album?track.collection_artist:payload.album?.artist)||'').trim();
    const title=cleanTitle(track.title||track.name||'');
    const album=String(track.album||'').trim();
    const key=JSON.stringify([artist,title,album]);
    if(coverLookupCache.has(key)){show(coverLookupCache.get(key));return;}
    try{
      const q=new URLSearchParams({artist,title,album});
      const r=await fetch('api_cover.php?'+q.toString(),{credentials:'same-origin',signal:abort.signal,priority:'low'});
      if(!r.ok)return;
      const j=await r.json().catch(()=>({}));
      const artistKnown=artist && !/^(varios|various artists|artista desconocido|unknown artist)$/i.test(artist);
      if(j.cover && artistKnown){coverLookupCache.set(key,j.cover);show(j.cover);}
      else if(track.id && !abort.signal.aborted){
        const csrf=document.querySelector('meta[name="sf-csrf"]')?.content;
        if(!csrf)return;
        const recognition=await fetch('api_identify_track.php',{method:'POST',credentials:'same-origin',signal:abort.signal,headers:{'X-CSRF-Token':csrf,'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({track_id:track.id,automatic:'1'})});
        if(!recognition.ok)return;
        const result=await recognition.json();if(result.skipped)return;
        const matches=(result.candidates||[]).filter(c=>c.score>=0.95);
        if(!matches.length)return;
        const chosen=matches[0];
        // Reject ambiguous high-scoring identities rather than choosing silently.
        const identity=c=>JSON.stringify([c.artist.toLowerCase(),c.title.toLowerCase()]);
        if(matches.some(c=>identity(c)!==identity(chosen)))return;
        if(serial!==coverRequestSerial)return;
        const query=new URLSearchParams({artist:chosen.artist,title:chosen.title,album:chosen.album||''});
        const coverResponse=await fetch('api_cover.php?'+query,{credentials:'same-origin',signal:abort.signal,priority:'low'});
        if(!coverResponse.ok)return;
        const identifiedCover=await coverResponse.json();
        if(identifiedCover.cover){coverLookupCache.set(key,identifiedCover.cover);show(identifiedCover.cover);}
      }

    }catch(_){}
  }

  function requestPlayback(){const sequence=loadSequence;autoplayHint=null;audio.play().then(()=>{autoplayHint=null;clearTransientStatus();}).catch(error=>{if(sequence!==loadSequence||error.name==='AbortError')return;if(error.name==='NotAllowedError'){// En segundo plano iOS puede rechazar el encadenado; no abandonamos la
        // intención de reproducir para poder reintentarlo al volver o al pulsar.
        if(!document.hidden)wantPlaying=false;diagnostics?.note('autoplay_blocked');hideNet();setAutoplayHint('Pulsa Reproducir para iniciar el audio');}else if(wantPlaying)scheduleNetRetry('Recuperando audio…');});}
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
    clearNetVisualTimer();
    if (netOverlay) netOverlay.hidden = true;
    if (netSpinner) netSpinner.classList.remove('stopped');
    if (netRetryBtn) netRetryBtn.hidden = true;
    clearNetTimer();
    netAttempt = 0;
    netGaveUp = false;
    netStartedAt = 0;
  }

  function scheduleNetRetry(reason,minimumDelay=0) {
    if (netGaveUp) return;
    if (!netStartedAt) netStartedAt = Date.now();
    const elapsed = Date.now() - netStartedAt;
    if (elapsed > NET_MAX_MS) {
      showNetFailed('La red se ha interrumpido. Cuando vuelva la conexión, pulsa Reintentar.');
      setStatus('Sin red');
      return;
    }
    // Backoff: 1s, 2s, 4s, 8s… máx 10s (estilo streaming)
    const delay = Math.max(minimumDelay,Math.min(10000, 1000 * Math.pow(2, Math.min(netAttempt, 3))));
    netAttempt += 1;
    showNetAfterDelay(reason || ('Reconectando… (intento ' + netAttempt + ')'));
    setStatus('Reconectando…');
    clearNetTimer();
    netTimer = setTimeout(() => recoverPlayback(), delay);
  }

  function recoverPlayback() {
    if(netGaveUp||!wantPlaying)return;
    // A queued retry must not rebuild a pipeline that already recovered.
    if (hasPlayedCurrentTrack && !audio.error && !audio.paused && !audio.seeking &&
        audio.readyState >= 3 && Date.now()-lastAdvanceAt < 5000) {
      hideNet();
      setStatus(hls ? 'Auto' : 'HLS nativo');
      return;
    }
    if(navigator.onLine===false){showNetWaiting('Sin conexión. Esperando red…');return;}
    diagnostics?.note('retry',{attempt:netAttempt});lastRecovery=Date.now();
    // A soft restart must schedule another check: some engines emit no error.
    if(hls && netAttempt<2 && !audio.error){
      try{hls.startLoad(-1);audio.play().catch(e=>{if(e.name==='NotAllowedError'){wantPlaying=false;diagnostics?.note('autoplay_blocked');hideNet();setAutoplayHint('Pulsa Reproducir para continuar');}});scheduleNetRetry('Recuperando audio…');return;}catch(_){}
    }
    const position=audio.currentTime>0?audio.currentTime:recoveryPosition;
    diagnostics?.note('rebuild',{attempt:netAttempt});
    pendingSeek=Number.isFinite(position)?Math.max(0,position):0;
    loadTrack(index,true,true);
    scheduleNetRetry('Recuperando la conexión…',12000);
  }

  async function resolveOnlineTrack(track, trackIndex, autoplay) {
    trackStatus.textContent = 'Resolviendo audio desde YouTube Music…';
    qualitySelect.innerHTML = '<option value="-1">Online · fuente externa</option>';
    qualitySelect.disabled = true;
    const fd = new FormData();
    fd.set("csrf_token",document.querySelector('meta[name="sf-csrf"]')?.content||"");
    fd.append('action', 'resolve');
    fd.append('url', String(track.source_url || ''));
    try {
      const response = await fetch('api_youtube.php', {method:'POST', body:fd, credentials:'same-origin', headers:{'Accept':'application/json'}});
      const data = await response.json().catch(() => ({}));
      if (!response.ok || !data.success || !data.url) throw new Error(data.error || `No se pudo resolver el audio (HTTP ${response.status}).`);
      if (tracks[index] !== track) return;
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
      if (tracks[index] !== track) return;
      trackStatus.textContent = 'No se pudo acceder al audio online';
      showNetFailed(e.message || 'YouTube no permitió resolver el audio.');
    }
  }

  function loadTrack(i, autoplay = true, recovering = false) {
    if (!tracks[i]) return;
    index = i;
    shuffleVisited.add(index);shuffleBag=shuffleBag.filter(n=>!shuffleVisited.has(n));
    const sequence=++loadSequence;
    if(!recovering)recoveryPosition=0;
    lastAdvanceAt=Date.now();lastWatchPosition=0;
    diagnostics?.load(tracks[i],autoplay);
    applyNormalization();
    hasPlayedCurrentTrack = false;
    pendingIndex = i;
    const t = tracks[i];
    window.sfPersonalTrack?.(t);
    trackTitle.textContent = t.name || ('Pista ' + (i + 1));
    if (miniTitle) miniTitle.textContent = trackTitle.textContent;
    // Al abrir el álbum no debe quedar el estado de la sesión anterior
    // («Reconectando…», «Pulsa Reproducir…») bajo el título.
    autoplayHint = null;
    clearTransientStatus();
    void loadCoverForTrack(t,autoplay);
    updateMediaSession(t);
    clearNetTimer();
    hideNet();
    netAttempt = 0;
    netGaveUp = false;
    netStartedAt = 0;
    wantPlaying = !!autoplay;
    destroyHls();
    audio.removeAttribute('src');
    if (usesShellAudio) audio.dataset.sfRealSrc = '1';
    // Keep catalog paths unchanged; deliver HLS only through the authenticated PHP proxy.
    const storedUrl = String(t.hls || '');
    const url = storedUrl.startsWith('hls_out/')
      ? 'stream.php?path=' + encodeURIComponent(storedUrl.slice('hls_out/'.length))
      : storedUrl;
    nextBuffer?.activate(url);
    currentUrl = url;
    qualitySelect.innerHTML = '<option value="-1">Auto</option>';
    qualitySelect.disabled = false;

    if (t.source_type === 'youtube' && t.source_url) {
      resolveOnlineTrack(t, i, autoplay);
      return;
    }

    audio.crossOrigin = 'anonymous';
    // Apple can expose MSE/MMS too; prefer its native HLS pipeline on iOS.
    // Keep hls.js and manual quality selection on other supported browsers.
    const appleMobile = /iPhone|iPad|iPod/.test(navigator.userAgent) ||
      (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const nativeHls = !!audio.canPlayType('application/vnd.apple.mpegurl');
    if (! (appleMobile && nativeHls) && window.Hls && Hls.isSupported()) {
      // Perfiles de ABR para audio VOD. Dejamos las decisiones de nivel en hls.js;
      // no forzamos nextLevel tras muestras aisladas, porque puede interferir con ABR.
      const hlsProfiles = {
        continuity: {
          abrEwmaDefaultEstimate: 1000000,
          abrEwmaFastVoD: 2.0,
          abrEwmaSlowVoD: 7.0,
          abrBandWidthFactor: 0.72,
          abrBandWidthUpFactor: 0.78,
          maxBufferLength: 24,
          maxMaxBufferLength: 45,
          backBufferLength: 8,
          maxBufferSize: 16 * 1000 * 1000,
        },
        balanced_mobile: {
          abrEwmaDefaultEstimate: 1000000,
          abrEwmaFastVoD: 2.0,
          abrEwmaSlowVoD: 6.0,
          abrBandWidthFactor: 0.80,
          abrBandWidthUpFactor: 0.86,
          maxBufferLength: 20,
          maxMaxBufferLength: 36,
          backBufferLength: 8,
          maxBufferSize: 16 * 1000 * 1000,
        },
        audio_quality: {
          abrEwmaDefaultEstimate: 1000000,
          abrEwmaFastVoD: 2.5,
          abrEwmaSlowVoD: 7.0,
          abrBandWidthFactor: 0.88,
          abrBandWidthUpFactor: 0.94,
          maxBufferLength: 24,
          maxMaxBufferLength: 45,
          backBufferLength: 10,
          maxBufferSize: 20 * 1000 * 1000,
        },
      };
      const requestedProfile = String(payload.hlsProfile || 'balanced_mobile');
      const profileName = Object.prototype.hasOwnProperty.call(hlsProfiles, requestedProfile)
        ? requestedProfile : 'balanced_mobile';
      const abrProfile = hlsProfiles[profileName];
      hls = new Hls({
        ...(nextBuffer?{loader:nextBuffer.loader(Hls.DefaultConfig.loader)}:{}),
        enableWorker: true,
        startLevel: nextBuffer?.has(url)?0:-1,
        ...abrProfile,
        fragLoadingTimeOut: 20000,
        manifestLoadingTimeOut: 15000,
        levelLoadingTimeOut: 15000,
      });
      hls.on(Hls.Events.ERROR,(_event,data)=>{
        diagnostics?.hls(data);
      });
      hls.loadSource(url);
      hls.attachMedia(audio);
      setStatus('Cargando…');
      hls.on(Hls.Events.MANIFEST_PARSED, () => {
        setStatus('Auto');
        qualitySelect.innerHTML = '<option value="-1">Auto</option>';
        hls.levels.forEach((level, idx) => {
          const o = document.createElement('option');
          o.value = String(idx);
          const kbps = getLevelKbps(level);
          o.textContent = kbps ? `${kbps} kbps` : `Nivel ${idx + 1}`;
          qualitySelect.appendChild(o);
        });
        const tryPlay = () => {
          if(sequence!==loadSequence)return;
          if(pendingSeek>0&&audio.readyState>=1&&Number.isFinite(audio.duration)){
            try{audio.currentTime=Math.min(pendingSeek,Math.max(0,audio.duration-.25));pendingSeek=0;}catch(_){}
          }
          if(wantPlaying)requestPlayback();
        };
        tryPlay();
        audio.addEventListener('loadedmetadata', tryPlay, { once: true });
        audio.addEventListener('canplay', tryPlay, { once: true });
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
          setStatus(kbps ? `Auto · ~${kbps} kbps` : 'Auto');
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
            showNetAfterDelay('Red inestable, reintentando…');
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
      // La selección automática permanece bajo control del ABR nativo de hls.js.
      // Esto evita que una rutina propia escriba hls.nextLevel en modo automático,
      // algo que puede provocar saltos inesperados o competir con la lógica ABR.
    } else if (audio.canPlayType('application/vnd.apple.mpegurl')) {
      audio.src = url;
      qualitySelect.innerHTML = '<option value="-1">Auto</option>';
      qualitySelect.disabled = true;
      setStatus('HLS nativo');
      const tryPlayN = () => {
        if(sequence!==loadSequence)return;
        if(pendingSeek>0&&audio.readyState>=1&&Number.isFinite(audio.duration)){
          try{audio.currentTime=Math.min(pendingSeek,Math.max(0,audio.duration-.25));pendingSeek=0;}catch(_){}
        }
        if(wantPlaying)requestPlayback();
      };
      audio.addEventListener('loadedmetadata', tryPlayN, { once: true });
      audio.addEventListener('canplay', tryPlayN, { once: true });
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

  let repeatMode='off',sleepAt=0,sleepAfterTrack=false,draggedIndex=null;
  const queueStatus=document.getElementById('queueActionStatus');
  function queueNotice(text){if(queueStatus)queueStatus.textContent=text;window.sfPlayerNotice?.(text);}
  function queueChanged(current,selected){
    index=tracks.indexOf(current);pendingIndex=tracks.indexOf(selected);if(pendingIndex<0)pendingIndex=Math.max(0,index);
    shuffleBag=[];shuffleHistory=[];shuffleVisited=new Set([index]);nextBuffer?.clear();renderPlaylist();writeProgress();publishShellState();labPostParent({type:'queue-changed'});document.dispatchEvent(new Event('sf:queue-updated'));
    for(const b of [playPauseBtn,nextBtn,prevBtn,restartBtn])if(b)b.disabled=!tracks.length;
  }
  function moveQueue(from,to){if(from===to||from<0||to<0||from>=tracks.length||to>=tracks.length)return;const current=tracks[index],selected=tracks[pendingIndex];const [t]=tracks.splice(from,1);tracks.splice(to,0,t);queueChanged(current,selected);queueNotice('Orden de la cola actualizado');}
  function queueNext(i){if(i===index)return;const current=tracks[index],selected=tracks[pendingIndex],[t]=tracks.splice(i,1);tracks.splice(tracks.indexOf(current)+1,0,t);queueChanged(current,selected);queueNotice('Se reproducirá después');}
  function removeQueue(i){const current=tracks[index],selected=tracks[pendingIndex],wasCurrent=i===index,resume=wantPlaying;tracks.splice(i,1);queueChanged(current,selected);if(wasCurrent){if(tracks.length)loadTrack(Math.min(i,tracks.length-1),resume);else{wantPlaying=false;audio.pause();destroyHls();audio.removeAttribute('src');audio.load();trackTitle.textContent='Cola vacía';index=-1;pendingIndex=-1;hideNet();}}queueNotice('Quitada de la cola; la canción se conserva en la biblioteca');}
  async function downloadQueue(i){const t=tracks[i];if(!t)return;if(!window.SFOffline){queueNotice('El modo sin conexión no está disponible; recarga la página.');return;}if(t.source_type==='youtube'||!String(t.hls||'').startsWith('hls_out/')){queueNotice('Esta pista no admite descarga local.');return;}const title=displayTrackTitle(t.name);queueNotice('Preparando «'+title+'» para oír sin conexión…');try{await window.SFOffline.download({...t,album:t.album||payload.album?.title||''},(percent,text)=>queueNotice(percent+'% · '+(text||'descargando')));queueNotice('«'+title+'» disponible en Oír sin conexión');window.sfPlayerNotice?.('Descarga sin conexión completada');}catch(err){queueNotice('No se pudo descargar: '+(err?.message||'error'));}}
  function addQueue(t,next=false){if(!t||!t.id)return;if(tracks.length>=500){queueNotice('La cola admite hasta 500 pistas');return;}const found=tracks.findIndex(x=>x.id===t.id);if(found>=0){if(next)queueNext(found);else queueNotice('La canción ya está en la cola');return;}const empty=!tracks.length,current=tracks[index],selected=tracks[pendingIndex];tracks.splice(next&&index>=0?index+1:tracks.length,0,t);queueChanged(current,selected);if(empty)loadTrack(0,false);queueNotice(next?'Se reproducirá después':'Canción añadida a la cola');}
  function addManyQueue(incoming){
    if(!Array.isArray(incoming))throw Error('Selección de cola inválida');
    const current=tracks[index],selected=tracks[pendingIndex],empty=!tracks.length,seen=new Set(tracks.map(t=>t.id));let added=0,duplicates=0,limited=0;
    for(const t of incoming){if(!t||!t.id||typeof t.name!=='string'||(typeof t.hls!=='string'&&t.source_type!=='youtube'))continue;if(seen.has(t.id)){duplicates++;continue;}if(tracks.length>=500){limited++;continue;}tracks.push(t);seen.add(t.id);added++;}
    if(added){queueChanged(current,selected);if(empty)loadTrack(0,false);}
    queueNotice(added+' pista(s) añadida(s) a la cola'+(duplicates?' · '+duplicates+' ya estaban':'')+(limited?' · '+limited+' sin añadir: límite de 500 pistas':''));return {added,duplicates,limited};
  }
  window.sfPlayerQueue={add:addQueue,addMany:addManyQueue,tracks:()=>tracks.slice(),current:()=>tracks[index]};
  function hasNextQueueTrack(){return shuffleEnabled?tracks.some((_,i)=>i!==index&&!shuffleVisited.has(i)):index<tracks.length-1;}
  function setRepeatMode(mode){repeatMode=['off','all','one'].includes(mode)?mode:'off';const button=document.getElementById('repeatQueueBtn'),select=document.getElementById('queueRepeat');if(select)select.value=repeatMode;if(button){button.setAttribute('aria-pressed',String(repeatMode!=='off'));button.title=repeatMode==='one'?'Repetir canción: activado':repeatMode==='all'?'Repetir cola: activado':'Repetir cola: desactivado';button.setAttribute('aria-label',repeatMode==='off'?'Activar repetición de toda la cola':'Desactivar repetición');}nextBuffer?.clear();}
  document.getElementById('queueRepeat')?.addEventListener('change',e=>{setRepeatMode(e.target.value);queueNotice(repeatMode==='one'?'Repetir canción':repeatMode==='all'?'Repetir toda la cola':'Repetición desactivada');});
  document.getElementById('repeatQueueBtn')?.addEventListener('click',()=>{setRepeatMode(repeatMode==='off'?'all':'off');queueNotice(repeatMode==='all'?'Repetir toda la cola: activado':'Repetición desactivada');});setRepeatMode('off');
  document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.sf-queue-actions[open]').forEach(d=>d.open=false);});
  document.addEventListener('click',e=>{document.querySelectorAll('.sf-queue-actions[open]').forEach(d=>{if(!d.contains(e.target))d.open=false;});});
  async function searchQueue(){const input=document.getElementById('queueSearch'),box=document.getElementById('queueSearchResults'),query=input.value.trim();box.replaceChildren();if(query.length<2){queueNotice('Escribe al menos dos caracteres');return;}const button=document.getElementById('queueSearchButton');button.disabled=true;const c=new AbortController(),timer=setTimeout(()=>c.abort(),10000);try{const r=await fetch('api_player_queue.php?q='+encodeURIComponent(query),{signal:c.signal});const d=await r.json();if(!r.ok)throw Error(d.error||'No se pudo buscar');for(const t of d.tracks||[]){const row=document.createElement('div'),label=document.createElement('span');row.className='sf-queue-result';label.textContent=t.name+(t.collection_title?' · '+t.collection_title:'');row.append(label);for(const next of [false,true]){const b=document.createElement('button');b.type='button';b.className='btn';b.textContent=next?'Después':'Añadir';b.title=next?'Reproducir después de la canción actual':'Añadir al final de la cola';b.onclick=()=>addQueue(t,next);row.append(b);}box.append(row);}if(!d.tracks?.length)box.textContent='Sin coincidencias';}catch(e){queueNotice(e.name==='AbortError'?'La búsqueda tardó demasiado; puedes reintentar':e.message);}finally{clearTimeout(timer);button.disabled=false;}}
  document.getElementById('queueSearchButton')?.addEventListener('click',searchQueue);document.getElementById('queueSearch')?.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();searchQueue();}});
  document.getElementById('queueSaveButton')?.addEventListener('click',async e=>{const button=e.currentTarget,name=document.getElementById('queueSaveName').value.trim(),ids=tracks.map(t=>t.id).filter(id=>/^[a-f0-9]{16}$/.test(id||''));if(!name||!ids.length){queueNotice('Indica un nombre y añade canciones del catálogo');return;}button.disabled=true;const feedback=window.sfSaveFeedback?.begin(button);try{const r=await fetch('api_player_queue.php',{method:'POST',body:new URLSearchParams({action:'save',name,tracks:JSON.stringify(ids),csrf_token:document.querySelector('meta[name="sf-csrf"]')?.content||''})}),d=await r.json();if(!r.ok)throw Error(d.error||'No se pudo guardar');feedback?.success('Cola guardada en Mis listas.');queueNotice('Cola guardada en Mis listas');document.dispatchEvent(new Event('sf:personal-changed'));document.getElementById('queueSaveName').value='';}catch(e){feedback?.error(e);queueNotice(e.message);}finally{button.disabled=false;}});
  document.getElementById('offlineDownloadCurrent')?.addEventListener('click',async e=>{const b=e.currentTarget,status=document.getElementById('offlineDownloadStatus'),t=tracks[index];if(!window.SFOffline){status.textContent='Preparando descargas; vuelve a intentar en un momento';return;}b.disabled=true;status.textContent='Preparando descarga…';try{await window.SFOffline.download(t,(percent,text)=>{status.textContent=percent+'% · '+text;});status.textContent='Canción disponible en Mis descargas';window.sfPlayerNotice?.('Descarga sin conexión completada');}catch(error){status.textContent=error.message;}finally{b.disabled=false;}});
  const sleepSelect=document.getElementById('playerSleep'),sleepStatus=document.getElementById('playerSleepStatus');
  sleepSelect?.addEventListener('change',()=>{sleepAfterTrack=sleepSelect.value==='end';sleepAt=Number(sleepSelect.value)>0?Date.now()+Number(sleepSelect.value)*60000:0;if(sleepStatus)sleepStatus.textContent='';updateSleep();});
  function stopForSleep(){wantPlaying=false;audio.pause();writeProgress();sleepAt=0;sleepAfterTrack=false;if(sleepSelect)sleepSelect.value='0';if(sleepStatus)sleepStatus.textContent='Reproducción detenida por el temporizador';window.sfPlayerNotice?.('Temporizador: reproducción detenida');}
  function updateSleep(){if(!sleepAt&&!sleepAfterTrack)return;if(sleepAt&&Date.now()>=sleepAt){stopForSleep();return;}if(sleepStatus)sleepStatus.textContent=sleepAfterTrack?'Se detendrá al terminar esta canción':sleepAt?'Se detendrá en '+Math.ceil((sleepAt-Date.now())/60000)+' min':'';}
  const sleepTimer=setInterval(updateSleep,1000);

  function renderPlaylist() {
    if (!playlistEl) return;
    playlistEl.innerHTML = '';
    tracks.forEach((t, i) => {
      const li = document.createElement('li');
      const title = document.createElement('span');
      title.className = 'sf-queue-title';
      title.textContent = `${i + 1}. ${displayTrackTitle(t.name)}`;
      li.append(title);
      if (t.id) {
        const favorite = document.createElement('span');
        favorite.className = 'sf-queue-favorite';
        favorite.dataset.sfTrack = t.id;
        favorite.dataset.sfFavoriteOnly = '1';
        li.append(favorite);
      } 
      li.tabIndex=0;li.setAttribute('aria-label',`${i+1}. ${displayTrackTitle(t.name)}`);li.setAttribute('aria-current',i===index?'true':'false');li.draggable=true;
      const menu=document.createElement('details'),summary=document.createElement('summary'),actions=document.createElement('div');menu.className='sf-queue-actions';summary.textContent='⋮';summary.setAttribute('aria-label','Opciones de '+displayTrackTitle(t.name));menu.append(summary,actions);menu.addEventListener('toggle',()=>{if(!menu.open)return;document.querySelectorAll('.sf-queue-actions[open]').forEach(other=>{if(other!==menu)other.open=false;});const r=summary.getBoundingClientRect();actions.style.position='fixed';actions.style.left=Math.max(8,Math.min(innerWidth-206,r.right-196))+'px';actions.style.top=Math.max(8,Math.min(innerHeight-215,r.top-205))+'px';actions.style.bottom='auto';});
      for(const [label,fn,disabled] of [['Reproducir después',()=>queueNext(i),i===index],['Subir',()=>moveQueue(i,i-1),i===0],['Bajar',()=>moveQueue(i,i+1),i===tracks.length-1],['Oír sin conexión',()=>downloadQueue(i),false],['Quitar de la cola',()=>removeQueue(i),false]]){const b=document.createElement('button');b.type='button';b.textContent=label;b.disabled=disabled;b.onclick=e=>{e.stopPropagation();menu.open=false;fn();};actions.append(b);}li.append(menu);
      li.addEventListener('keydown',e=>{if(e.target!==li)return;if(e.key==='Enter'||e.key===' '){e.preventDefault();loadTrack(i,true);}else if(e.altKey&&e.key==='ArrowUp'){e.preventDefault();moveQueue(i,i-1);}else if(e.altKey&&e.key==='ArrowDown'){e.preventDefault();moveQueue(i,i+1);}});
      li.addEventListener('dragstart',e=>{if(e.target.closest('button,details')){e.preventDefault();return;}draggedIndex=i;e.dataTransfer.effectAllowed='move';e.dataTransfer.setData('text/plain',String(i));});li.addEventListener('dragover',e=>{if(draggedIndex!==null)e.preventDefault();});li.addEventListener('drop',e=>{e.preventDefault();if(draggedIndex!==null)moveQueue(draggedIndex,i);draggedIndex=null;});li.addEventListener('dragend',()=>{draggedIndex=null;});
      li.dataset.i = String(i);
      if (i === index) li.classList.add('is-playing');
      if (i === pendingIndex) li.classList.add('is-selected');
      const pick = () => {
        pendingIndex = i;
        playlistEl.querySelectorAll('li').forEach((n) => n.classList.toggle('is-selected', Number(n.dataset.i) === i));
      };
      const go = () => loadTrack(i, true);
      // Escritorio: clic selecciona, doble clic reproduce
      li.addEventListener('dblclick', (e) => { if(e.target.closest('.sf-queue-favorite,.sf-track-menu,.sf-queue-actions'))return; e.preventDefault(); go(); });
      li.addEventListener('click', (e) => {
        if(e.target.closest('.sf-queue-favorite,.sf-track-menu,.sf-queue-actions'))return;
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
        if(e.target.closest('.sf-queue-favorite,.sf-track-menu,.sf-queue-actions'))return;
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
    const artist = String(track.artist || payload.album.artist || '').trim();
    const albumTitle = (payload.album.title || '').trim();
    const title = cleanTitle(track.title || track.name);
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
    if (audioCtx && audioCtx.state !== 'running') audioCtx.resume().catch(() => {});
    if (audio.paused) {
      wantPlaying = true;
      if(audio.error || netGaveUp){netGaveUp=false;netAttempt=0;netStartedAt=0;recoverPlayback();}
      else requestPlayback();
    } else {
      wantPlaying = false;
      audio.pause();
    }
  });

  audio.addEventListener('waiting', () => {
    if (wantPlaying) showNetAfterDelay('Reconectando audio…');
  });
  audio.addEventListener('stalled', () => {
    // stalled describes network fetching, not necessarily stalled playback.
    // Buffered audio may still play; the progress watchdog handles real stalls.
    if (wantPlaying) showNetAfterDelay('Esperando audio…');
  });
  audio.addEventListener('playing', () => {
    hasPlayedCurrentTrack = true;
    lastAdvanceAt=Date.now();
    hideNet();
    wantPlaying = true;
    autoplayHint = null;
    clearTransientStatus();
  });
  audio.addEventListener('pause', () => {
    clearNetVisualTimer();
    // La pausa voluntaria del usuario no se revierte. Otras pausas (llamada,
    // alarma, cambio temporal de app) se reintentan si la reproducción era intencional.
    if (wantPlaying && !audio.ended) scheduleAutoResume();
  });

  function scheduleAutoResume(delay=3500){
    if(autoResumeTimer!==null)return;
    autoResumeTimer=setTimeout(()=>{
      autoResumeTimer=null;
      if(!wantPlaying||document.hidden||navigator.onLine===false)return;
      if(!audio.paused||audio.ended||audio.seeking)return;
      resumeAfterConnection(true);
    },delay);
  }
  function resumeAfterConnection(force=false){
    // Initial load is already managed by HLS. Visibility alone is not a failure.
    if(!force && !hasPlayedCurrentTrack && !netStartedAt && !netGaveUp && !audio.error)return;
    if(!wantPlaying || navigator.onLine===false)return;
    if(!netGaveUp && !audio.error && !audio.paused && audio.readyState>=3)return;
    netGaveUp=false;netAttempt=0;netStartedAt=0;
    if(audioCtx && audioCtx.state!=='running')audioCtx.resume().catch(()=>{});
    showNetWaiting('Conexión disponible. Recuperando audio…');
    recoverPlayback();
  }
  // iOS/PWA can remain 'online' while the media pipeline is stuck.
  // Watch actual progress; never restart a deliberately paused player.
  const recoveryWatch=setInterval(()=>{
    if(!wantPlaying||audio.ended||audio.seeking)return;
    const now=Date.now(),position=audio.currentTime||0;
    if(position!==lastWatchPosition){lastWatchPosition=position;lastAdvanceAt=now;if(position>0)recoveryPosition=position;return;}
    if(now-lastAdvanceAt>12000&&now-lastRecovery>15000&&(audio.error||audio.paused||audio.readyState<3)){
      lastRecovery=now;if(navigator.onLine!==false)resumeAfterConnection(true);
    }
  },4000);
  window.addEventListener('online',()=>resumeAfterConnection(true));
  window.addEventListener('pageshow',()=>{if(wantPlaying)resumeAfterConnection(true);});
  window.addEventListener('focus',()=>{if(wantPlaying&&audio.paused)scheduleAutoResume(900);});
  document.addEventListener('visibilitychange',()=>{
    // Cambiar de app nunca debe pausar. El sistema pausa por su cuenta en
    // llamadas o si otra app toma el micrófono (pérdida de foco de audio).
    if(document.hidden){
      if(wantPlaying&&audioCtx?.state==='suspended')audioCtx.resume().catch(()=>{});
      return;
    }
    resumeAfterConnection();
    if(wantPlaying&&audio.paused)scheduleAutoResume(1200);
  });
  audio.addEventListener('error',()=>{if(wantPlaying)scheduleNetRetry('Recuperando audio tras la interrupción…');});
  window.addEventListener('offline', () => {
    if (wantPlaying) showNetWaiting('Sin conexión. Esperando red…');
  });

  netRetryBtn?.addEventListener('click', () => {
    ensureEq();if(audioCtx && audioCtx.state!=='running')audioCtx.resume().catch(()=>{});
    netGaveUp = false;
    netAttempt = 0;
    netStartedAt = 0;
    wantPlaying = true;
    showNetWaiting('Reintentando…');
    recoverPlayback();
  });
  audio.addEventListener('play', () => {
    ensureEq();applyNormalization();if(audioCtx?.state==='suspended')audioCtx.resume().catch(()=>{});
    autoplayHint = null;
    clearTransientStatus();
    window.sfPlayerIcons.set(playPauseBtn,'pause');playPauseBtn.setAttribute('aria-label','Pausar');playPauseBtn.setAttribute('aria-pressed','true');
    if (navigator.mediaSession && !usesShellAudio) navigator.mediaSession.playbackState = 'playing';
    updateMediaSession(tracks[index]);
    if (usesShellAudio) shellNowPlaying();
  });
  audio.addEventListener('pause', () => {
    window.sfPlayerIcons.set(playPauseBtn,'play');playPauseBtn.setAttribute('aria-label','Reproducir');playPauseBtn.setAttribute('aria-pressed','false');
    if (navigator.mediaSession && !usesShellAudio) navigator.mediaSession.playbackState = 'paused';
    if (usesShellAudio) shellNowPlaying();
    writeProgress();
  });
  audio.addEventListener('ended', () => {
    if(sleepAfterTrack){stopForSleep();return;}
    if(repeatMode==='one'){pendingSeek=0;loadTrack(index,true);continuePlaybackAfterEnd();return;}
    if(repeatMode==='off'&&!hasNextQueueTrack()){wantPlaying=false;writeProgress();return;}
    advanceTrack();
    // iOS/PWA con la pantalla apagada puede rechazar el primer play del HLS
    // siguiente. Reintentarlo cuando el nuevo flujo ya tenga datos evita que la
    // reproducción se detenga al terminar la primera pista.
    continuePlaybackAfterEnd();
  });
  function continuePlaybackAfterEnd(){
    const sequence=loadSequence;
    for(const delay of [250,900,2200]){
      setTimeout(()=>{
        if(sequence!==loadSequence||!wantPlaying||audio.ended||!audio.paused)return;
        requestPlayback();
      },delay);
    }
  }
  const refreshMediaTime=()=>{const d=Number.isFinite(audio.duration)?audio.duration:0,t=audio.currentTime||0;currentTimeEl.textContent=formatTime(t);durationTimeEl.textContent=formatTime(d);progressBarBg.setAttribute('aria-valuemax',String(Math.round(d)));progressBarBg.setAttribute('aria-valuenow',String(Math.round(t)));progressBarBg.setAttribute('aria-valuetext',formatTime(t)+' de '+formatTime(d));updateMediaPosition();};
  audio.addEventListener('loadedmetadata',refreshMediaTime);audio.addEventListener('durationchange',refreshMediaTime);
  audio.addEventListener('timeupdate', () => {
    const d = audio.duration || 0;
    if(audio.currentTime>0)recoveryPosition=audio.currentTime;
    if(repeatMode!=='one' && !(repeatMode==='off'&&!hasNextQueueTrack()) && nextBuffer && hls && !audio.paused && d>0 && d-audio.currentTime<=20 && tracks.length>1 && !navigator.connection?.saveData && !['slow-2g','2g'].includes(navigator.connection?.effectiveType) && navigator.onLine!==false){
      const next=tracks[peekNextTrack()];
      if(next && next.source_type!=='youtube' && next.hls){const u=String(next.hls);nextBuffer.prepare(u.startsWith('hls_out/')?'stream.php?path='+encodeURIComponent(u.slice(8)):u);}
    }
    if(Date.now()-lastMediaPosition>1000){lastMediaPosition=Date.now();updateMediaPosition();progressBarBg.setAttribute('aria-valuemax',String(Math.round(d)));progressBarBg.setAttribute('aria-valuenow',String(Math.round(audio.currentTime||0)));progressBarBg.setAttribute('aria-valuetext',formatTime(audio.currentTime)+' de '+formatTime(d));}
    if (d > 0) progressBar.style.width = `${(audio.currentTime / d) * 100}%`;
    currentTimeEl.textContent = formatTime(audio.currentTime);
    durationTimeEl.textContent = formatTime(d);
    updateLyricHighlight();
  });
  audio.addEventListener('seeked', () => {
    lyricLast = -1;
    updateMediaPosition();
    updateLyricHighlight();
  });
  progressBarBg.tabIndex=0;progressBarBg.setAttribute('role','slider');progressBarBg.setAttribute('aria-label','Posición de reproducción');progressBarBg.setAttribute('aria-valuemin','0');
  progressBarBg.addEventListener('keydown',e=>{if(!Number.isFinite(audio.duration))return;let target=audio.currentTime;if(e.key==='ArrowRight')target+=5;else if(e.key==='ArrowLeft')target-=5;else if(e.key==='Home')target=0;else if(e.key==='End')target=audio.duration;else return;e.preventDefault();audio.currentTime=Math.max(0,Math.min(audio.duration,target));updateMediaPosition();});
  volumeSlider.setAttribute('aria-label','Volumen');qualitySelect.setAttribute('aria-label','Calidad del audio');
  progressBarBg.addEventListener('click', (e) => {
    const rect = progressBarBg.getBoundingClientRect();
    const pos = (e.clientX - rect.left) / rect.width;
    if (audio.duration) audio.currentTime = pos * audio.duration;
  });
  restartBtn?.addEventListener('click', () => {
    if (!Number.isFinite(audio.duration) && !audio.seekable.length) return;
    try { audio.currentTime = 0; } catch (_) { return; }
    if (wantPlaying && audio.paused) requestPlayback();
    writeProgress();
  });
  prevBtn.addEventListener('click', () => previousTrack());
  nextBtn.addEventListener('click', () => {
    advanceTrack();
  });
  volumeSlider.addEventListener('input', (e) => {
    audio.volume = Number(e.target.value);
    audio.muted = audio.volume === 0;
    window.sfPlayerIcons.set(muteBtn,audio.muted || !audio.volume?'muted':'volume');
  });
  muteBtn.addEventListener('click', () => {
    audio.muted = !audio.muted;
    volumeSlider.value = audio.muted ? 0 : audio.volume || 1;
    window.sfPlayerIcons.set(muteBtn,audio.muted?'muted':'volume');
  });
  qualitySelect.addEventListener('change', (e) => {
    if (!hls) return;
    const v = parseInt(e.target.value, 10);
    // Auto: reactivar ABR
    if (Number.isNaN(v) || v < 0) {
      hls.currentLevel = -1; // habilita selección automática
      setStatus('Auto');
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
    if (miniPlay) window.sfPlayerIcons.set(miniPlay,audio.paused?'play':'pause');
  });
  function restorePlayer() {
    if (playerRoot) playerRoot.hidden = false;
    if (miniDock) miniDock.hidden = true;
    if (libPanel) libPanel.hidden = true;
  }
  miniRestore?.addEventListener('click', restorePlayer);
  libRestorePlayer?.addEventListener('click', restorePlayer);
  miniPlay?.addEventListener('click', () => playPauseBtn?.click());
  window.sfPlayerToggle=()=>playPauseBtn?.click();
  window.sfPlayerPause=()=>{wantPlaying=false;hideNet();audio.pause();writeProgress();publishShellState();};
  window.sfPlayerResume=()=>{ensureEq();if(audioCtx?.state==='suspended')audioCtx.resume().catch(()=>{});wantPlaying=true;if(audio.paused){if(audio.error||netGaveUp){netGaveUp=false;recoverPlayback();}else requestPlayback();}};
  window.sfPlayerSnapshot=()=>tracks.slice(0,500);
  window.sfPlayerRestoreQueue=(queue,state)=>{
    if(!Array.isArray(queue)||!queue.length||queue.length>500)return;
    const valid=queue.filter(t=>t && typeof t.id==='string' && /^[a-f0-9]{16,64}$/i.test(t.id) && typeof t.name==='string' && (typeof t.hls==='string'||t.source_type==='youtube'));
    if(!valid.length)return;
    nextBuffer?.clear();tracks.splice(0,tracks.length,...valid);payload.album.tracks=tracks;
    const byId=tracks.findIndex(t=>t.id===state?.trackId);const saved=Number(state?.index)||0;
    index=byId>=0?byId:Math.max(0,Math.min(tracks.length-1,saved));pendingIndex=index;
    pendingSeek=Math.max(0,Number(state?.time)||0);shuffleBag=[];shuffleHistory=[];shuffleVisited=new Set([index]);loadTrack(index,false);document.dispatchEvent(new Event('sf:queue-updated'));
  };

  btnMax?.addEventListener('click', () => {
    if (!lyricsCheck?.checked || btnMax.disabled) return;
    if (karaokeOverlay) {
      karaokeOverlay.hidden = false;labPostParent({type:'karaoke-size',active:true});
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
  async function karaokeFull(on){
    if(!karaokeOverlay)return;
    karaokeOverlay.classList.toggle('is-fullscreen',on);
    document.getElementById('karaokeFullscreen')?.setAttribute('aria-pressed',String(on));
    labPostParent({type:on?'karaoke-full':'karaoke-size',active:!karaokeOverlay.hidden});
    try{if(on&&karaokeOverlay.requestFullscreen)await karaokeOverlay.requestFullscreen();else if(!on&&document.fullscreenElement===karaokeOverlay)await document.exitFullscreen();}catch{}
    requestAnimationFrame(()=>{syncKaraokeFromLines();const active=lyricLines.find(l=>l.el?.classList.contains('is-active'));if(active?.kEl)centerInBox(karaokeLyrics,active.kEl,true);});
  }
  document.getElementById('karaokeFullscreen')?.addEventListener('click',()=>karaokeFull(!karaokeOverlay.classList.contains('is-fullscreen')));
  karaokeClose?.addEventListener('click',()=>karaokeFull(false));
  document.addEventListener('keydown',event=>{if(event.key==='Escape'&&karaokeOverlay?.classList.contains('is-fullscreen')&&!document.fullscreenElement)karaokeFull(false);});
  document.addEventListener('fullscreenchange',()=>{const on=document.fullscreenElement===karaokeOverlay;karaokeOverlay?.classList.toggle('is-fullscreen',on);document.getElementById('karaokeFullscreen')?.setAttribute('aria-pressed',String(on));if(!on)labPostParent({type:'karaoke-size',active:!karaokeOverlay?.hidden});});
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
    eqUserRequested=true;ensureEq(true);
    if (audioCtx && audioCtx.state !== 'running') audioCtx.resume().catch(() => {});
    if (eqPanel) eqPanel.hidden = !eqPanel.hidden;
  });
  [eqBass, eqMid, eqTreble].forEach((el) => {
    el?.addEventListener('input', () => { eqUserRequested=true;ensureEq(true); applyEqGains(); });
  });
  eqReset?.addEventListener('click', () => {
    if (eqBass) eqBass.value = '0';
    if (eqMid) eqMid.value = '0';
    if (eqTreble) eqTreble.value = '0';
    applyEqGains();
  });

  // Sync mini play icon
  audio.addEventListener('play', () => { if (miniPlay) window.sfPlayerIcons.set(miniPlay,'pause'); });
  audio.addEventListener('pause', () => { if (miniPlay) window.sfPlayerIcons.set(miniPlay,'play'); });

  window.addEventListener('message', (ev) => {
    const d = ev.data;
    if (!d || d.source !== 'lab-host') return;
    if (d.type === 'playpause') playPauseBtn?.click();
    if (d.type === 'next') advanceTrack();
    if (d.type === 'prev') previousTrack();
    if (d.type === 'seek' && Number.isFinite(d.time)) {
      const limit = Number.isFinite(audio.duration) && audio.duration > 0 ? audio.duration : d.time;
      audio.currentTime = Math.max(0, Math.min(limit, d.time));
      writeProgress();
    }
    if (d.type === 'close') closePlayer();
    if (d.type === 'theme') {
      const themes = ['dark-teal','dark-blue','dark-purple','light','light-sand','solarized','monokai','deep-black','ruby-blue'];
      const theme = themes.includes(d.theme) ? d.theme : 'dark-teal';
      document.documentElement.dataset.theme = theme;
      const meta = document.getElementById('themeColorMeta');
      if (meta) meta.setAttribute('content', {
        'dark-teal':'#091519','dark-blue':'#080f1d','dark-purple':'#100b19','light':'#eef2f6','light-sand':'#f4f0e7','solarized':'#002b36','monokai':'#272822','deep-black':'#000000','ruby-blue':'#0b1f33'
      }[theme] || '#091519');
    }
    if (d.type === 'restore') {
      // parent shows iframe again
    }
  });

  function publishShellState() {
    if (!isEmbed) return;
    const duration = Number(audio.duration);
    const current = Number(audio.currentTime) || 0;
    labPostParent({
      type: 'state',
      index, time:current, trackId:tracks[index]?.id||'',
      playing: !audio.paused,
      title: trackTitle?.textContent || '',
      status: trackStatus?.textContent || (audio.paused ? 'En pausa' : 'Reproduciendo'),
      art: trackArt?.src || '',
      progress: Number.isFinite(duration) && duration > 0 ? (current / duration) * 100 : 0
    });
  }
  audio.addEventListener('play', publishShellState);
  audio.addEventListener('pause', publishShellState);
  audio.addEventListener('timeupdate', publishShellState);
  audio.addEventListener('loadedmetadata', publishShellState);
  audio.addEventListener('ended', publishShellState);

  // Restaurar última pista y posición (estilo Drive-Craft) e iniciar reproducción
  let selectionSerial=0,selectionUrl=new URL(location.href);
  function selectionKey(url){for(const key of ['album','playlist','favorites'])if(url.searchParams.has(key))return key+':'+url.searchParams.get(key);return url.search;}
  window.sfPlayerOpen=async source=>{
    const serial=++selectionSerial,url=new URL(source,location.href);
    if(url.origin!==location.origin||!url.pathname.endsWith('/player.php'))throw Error('Selección no válida');
    writeProgress();
    const choose=()=>{const explicit=url.searchParams.has('i'),saved=readProgress();let start=explicit?Number(url.searchParams.get('i')):Number(saved?.index)||0;start=Number.isInteger(start)?Math.max(0,Math.min(tracks.length-1,start)):0;pendingSeek=explicit?0:Math.max(0,Number(saved?.time)||0);loadTrack(start,true);};
    if(selectionKey(url)===selectionKey(selectionUrl)){choose();return;}
    const query=new URL(url);query.searchParams.set('payload','1');const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),12000);
    try{const response=await fetch(query,{credentials:'same-origin',cache:'no-store',signal:controller.signal});if(!response.ok)throw Error('No se pudo abrir la selección');const fresh=await response.json();if(serial!==selectionSerial)return;if(!Array.isArray(fresh.album?.tracks)||!fresh.album.tracks.length)throw Error('La selección no contiene pistas');
      nextBuffer?.clear();Object.assign(payload,fresh);tracks.splice(0,tracks.length,...fresh.album.tracks);payload.album.tracks=tracks;lrclibOn=!!payload.lrclib;albumIdKey=String(payload.album.id||'single');PROGRESS_KEY='lab_progress_'+albumIdKey;nextBuffer=payload.prefetchNext?window.sfNextBuffer?.():null;shuffleBag=[];shuffleHistory=[];shuffleVisited=new Set([index]);selectionUrl=url;document.title=(payload.album.title||'Reproductor')+' · Sparrow-Fy';choose();labPostParent({type:'queue-changed'});document.dispatchEvent(new Event('sf:queue-updated'));
    }finally{clearTimeout(timer);}
  };
  bindMediaSessionActions();
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
    const resume=new URLSearchParams(location.search);
    if(resume.get('resume')==='1'){
      const byId=tracks.findIndex(t=>t.id===resume.get('resume_track'));
      const n=Number(resume.get('resume_index'));start=byId>=0?byId:(Number.isInteger(n)&&n>=0&&n<tracks.length?n:start);
      const time=Number(resume.get('resume_time'));pendingSeek=Number.isFinite(time)&&time>=0?time:0;
      index=start;pendingIndex=start;
    }
    loadTrack(start,resume.get('resume')==='1'?resume.get('resume_play')==='1':true);
  })();
})();
