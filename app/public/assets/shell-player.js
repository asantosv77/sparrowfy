(() => {
  'use strict';
  if(window!==window.parent){
    document.addEventListener('click',event=>{
      if(event.defaultPrevented||event.button!==0||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey)return;
      const libraryLink=event.target.closest('a[href*="index.php"]');
      if(libraryLink&&!libraryLink.hasAttribute('data-album-back')&&(!libraryLink.target||libraryLink.target==='_self')){
        const destination=new URL(libraryLink.href,location.href),index=new URL('index.php',location.href);
        if(destination.origin===index.origin&&destination.pathname===index.pathname&&(destination.searchParams.get('view')||'home')==='library'){
          event.preventDefault();window.parent.postMessage({source:'sf-navega',type:'show-library',hash:destination.hash},location.origin);return;
        }
      }
      const link=event.target.closest('a[href*="player.php"]');if(!link)return;
      const url=new URL(link.href,location.href);if(url.origin!==location.origin||url.pathname!==new URL('player.php',location.href).pathname)return;
      const kind=url.searchParams.has('playlist')?'playlist':url.searchParams.has('favorites')?'favorites':'album',id=url.searchParams.get(kind);
      if(id && typeof window.parent.labOpenPlayer==='function'){event.preventDefault();(window.parent.labOpenPlayerFromPanel||window.parent.labOpenPlayer)(id,url.searchParams.get('i'),kind,url.searchParams.get('track_id'));}
    });
    return;
  }
  const account=document.querySelector('meta[name="sf-player-account"]')?.content;
  const storageKey='sf-player-shell:'+account;
  // En iPhone/iPad WebKit no permite iniciar audio dentro de un iframe sin un
  // toque propio del iframe, así que el <audio> real vive en esta página. El
  // toque sobre el álbum desbloquea ese elemento y el reproductor embebido lo
  // controla directamente (mismo origen).
  const appleMobile=/iPhone|iPad|iPod/.test(navigator.userAgent)||(navigator.platform==='MacIntel'&&navigator.maxTouchPoints>1);
  let hostAudio=null,hostAudioUnlocked=false;
  // Cualquier primer toque en la página principal sirve para desbloquear el
  // elemento; así también funcionan las sesiones restauradas.
  if(appleMobile)document.addEventListener('pointerdown',()=>{if(!hostAudioUnlocked)unlockHostAudio();},{once:true,passive:true});
  let lastState={},leaving=false,configLoadTimer=null;
  const continueKey='sf-player-last:'+account;
  let compactMode=false,compactPosition=null;
  let floatingPlayer=null, openingFloating=false;
  let lastQueue=[],lastCheckpointAt=0,continueRequested=false;
  function savedSelection(){try{return JSON.parse(localStorage.getItem(continueKey)||'null');}catch{return null;}}
  function updateContinue(){const b=document.getElementById('sfContinuePlayer');if(b){b.disabled=false;b.title=lastState.title?'Continuar: '+lastState.title:'Continuar la última cola de reproducción';}}
  function persist(force=false){
    if(!force && Date.now()-lastCheckpointAt<1000)return;
    if(leaving||!account||!frame()?.dataset.src)return;
    try{
      lastCheckpointAt=Date.now();
      const snapshot=frame().contentWindow?.sfPlayerSnapshot?.();if(snapshot?.length)lastQueue=snapshot;
      if(!lastQueue.length)return;
      const saved={src:frame().dataset.src,minimized:!floatingPlayer&&host().classList.contains('is-min'),compact:compactMode,position:compactPosition,state:lastState,queue:lastQueue};
      sessionStorage.setItem(storageKey,JSON.stringify(saved));
      const durable=JSON.stringify(saved);if(durable.length<1500000)localStorage.setItem(continueKey,durable);
      updateContinue();
    }catch(e){console.warn('Player checkpoint failed',e.message);}
  }
  function recover(){if(!account)return;try{ensureHostAudio();const saved=JSON.parse(sessionStorage.getItem(storageKey)||'null');if(!saved?.src)return;const u=new URL(saved.src,location.href);if(u.origin!==location.origin||u.pathname!==new URL('player.php',location.href).pathname)return;
    u.searchParams.delete('track_id');
    u.searchParams.set('resume','1');u.searchParams.set('resume_time',String(saved.state?.time||0));u.searchParams.set('resume_index',String(saved.state?.index||0));u.searchParams.set('resume_track',saved.state?.trackId||'');u.searchParams.set('resume_play',saved.state?.playing===false?'0':'1');
    lastQueue=Array.isArray(saved.queue)?saved.queue:[];frame().addEventListener('load',()=>{const player=frame()?.contentWindow;player?.sfPlayerRestoreQueue?.(lastQueue,lastState);if(continueRequested){continueRequested=false;player?.sfPlayerResume?.();}},{once:true});
    frame().dataset.src=saved.src;lastState={...(saved.state||{}),playing:false};frame().src=u.href;host().hidden=false;
    compactMode=!!saved.compact;compactPosition=saved.position||null;
    if(saved.minimized)minimizeShell(lastState);else restoreShell();
  }catch(e){console.warn('Player recovery failed',e.message);}}
  window.addEventListener('pagehide',()=>{persist(true);leaving=true;});
  function ensurePlayerVisible(){
    if(floatingPlayer || document.hidden || !frame()?.dataset.src)return;
    const h=host(),m=mini();
    if(h.hidden || (h.classList.contains('is-min') && m?.hidden)){
      h.hidden=false;
      minimizeShell(lastState);
    }
  }
  function pauseRecoveredPlayer(){
    continueRequested=false;
    const pause=frame()?.contentWindow?.sfPlayerPause;
    if(typeof pause==='function')pause();
    lastState={...lastState,playing:false};
    const button=document.getElementById('labMiniPlay');if(button)window.sfPlayerIcons.set(button,'play');
    persist();ensurePlayerVisible();
  }
  let startupChannel=null;
  window.addEventListener('pageshow',()=>{leaving=false;ensurePlayerVisible();});
  document.addEventListener('visibilitychange',()=>{if(!document.hidden)ensurePlayerVisible();});


  const OVERLAY_HTML = `
<dialog id="labPlayerHost" class="lab-player-host" aria-label="Reproductor" hidden style="width:auto;height:auto;max-width:none;max-height:none;margin:0;border:0">
  <iframe id="labPlayerFrame" class="lab-player-frame" title="Reproductor" allow="autoplay; fullscreen"></iframe>
</dialog>
<div id="labConfigHost" class="lab-config-host" hidden>
  <div class="lab-config-bar">
    <strong>Configuración</strong><span id="labConfigStatus" role="status" hidden></span>
    <button type="button" class="btn" id="labConfigClose">Cerrar</button>
  </div>
  <iframe id="labConfigFrame" class="lab-config-frame" title="Configuración" src="about:blank"></iframe>
</div>
<div id="labMiniDock" class="mini-dock lab-shell-mini" hidden aria-label="Reproductor minimizado">
  <img class="mini-art" id="labMiniArt" alt="Portada de la pista" width="52" height="52" hidden>
  <span class="mini-placeholder" aria-hidden="true">♫</span>
  <div class="mini-meta" title="En modo compacto, arrastra aquí para mover el reproductor">
    <strong id="labMiniTitle">—</strong>
    <span id="labMiniStatus">Reproductor minimizado</span>
    <div class="mini-progress" aria-hidden="true"><span id="labMiniProgress"></span></div>
  </div>
  <button type="button" class="mini-control mini-play" id="labMiniPlay" aria-label="Reproducir o pausar" title="Reproducir o pausar"><svg class="sf-media-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path fill="currentColor" stroke="none" d="M7 4.5v15l12-7.5z"/></svg></button>
  <button type="button" class="mini-control" id="labMiniCompact" title="Reducir a reproductor flotante" aria-label="Reducir a reproductor flotante" aria-pressed="false"><svg class="sf-media-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="5" y="8" width="14" height="8" rx="2"/><path d="M3 5h18M3 19h18"/></svg></button>
  <button type="button" class="mini-control" id="labMiniRestore" title="Abrir reproductor" aria-label="Abrir reproductor"><svg class="sf-media-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M8 3H3v5m13-5h5v5M3 16v5h5m13-5v5h-5"/></svg></button>
  <button type="button" class="mini-control mini-close" id="labMiniClose" title="Cerrar reproductor" aria-label="Cerrar reproductor"><svg class="sf-media-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m6 6 12 12M6 18 18 6"/></svg></button>
</div>`;

  function ensureShell() {
    if (document.getElementById('labPlayerHost')) return;
    const wrap = document.createElement('div');
    wrap.innerHTML = OVERLAY_HTML;
    while (wrap.firstChild) document.body.appendChild(wrap.firstChild);
    bindShell();
    ensureHostAudio();
  }

  // WAV silencioso de 0,1 s generado en memoria: sirve para desbloquear el
  // elemento de audio durante el toque del usuario (patrón iOS).
  function silentWavUrl() {
    const rate=8000,samples=800,bytes=44+samples*2;
    const buffer=new ArrayBuffer(bytes),view=new DataView(buffer);
    const write=(offset,text)=>{for(let i=0;i<text.length;i++)view.setUint8(offset+i,text.charCodeAt(i));};
    write(0,'RIFF');view.setUint32(4,bytes-8,true);write(8,'WAVEfmt ');
    view.setUint32(16,16,true);view.setUint16(20,1,true);view.setUint16(22,1,true);
    view.setUint32(24,rate,true);view.setUint32(28,rate*2,true);
    view.setUint16(32,2,true);view.setUint16(34,16,true);
    write(36,'data');view.setUint32(40,samples*2,true);
    // This tiny WAV needs no revocable object URL.
    let binary='';for(const byte of new Uint8Array(buffer))binary+=String.fromCharCode(byte);
    return 'data:audio/wav;base64,'+btoa(binary);
  }
  function ensureHostAudio() {
    if (!appleMobile) return null;
    if (hostAudio) return hostAudio;
    hostAudio=document.createElement('audio');
    hostAudio.id='sfShellAudio';
    hostAudio.setAttribute('playsinline','');
    hostAudio.preload='metadata';
    hostAudio.style.cssText='position:fixed;left:-9999px;top:0;width:1px;height:1px;opacity:0;pointer-events:none';
    document.body.append(hostAudio);
    window.sfShellAudio=hostAudio;
    bindHostMediaSession();
    return hostAudio;
  }
  function unlockHostAudio() {
    const el=ensureHostAudio();
    if(!el||hostAudioUnlocked)return;
    // Si el reproductor ya cargó una melodía real, no la interrumpimos con el WAV.
    if(el.dataset.sfRealSrc==='1'||(el.currentSrc&&!el.currentSrc.startsWith('blob:'))){hostAudioUnlocked=true;return;}
    try{
      const url=silentWavUrl();
      el.src=url;
      const playing=el.play();
      // No se pausa ni se reinicia aquí: el reproductor puede haber sustituido
      // ya el origen por la melodía real. El WAV es tan corto que termina solo.
      const done=()=>{hostAudioUnlocked=true;};
      if(playing&&typeof playing.then==='function')playing.then(done).catch(done);
      else done();
    }catch(_){}
  }
  function bindHostMediaSession() {
    if(!('mediaSession' in navigator))return;
    const act=(name,handler)=>{try{navigator.mediaSession.setActionHandler(name,handler);}catch(_){}};
    const player=()=>frame()?.contentWindow;
    act('play',()=>{const p=player();if(typeof p?.sfPlayerResume==='function')p.sfPlayerResume();else postToPlayer({type:'playpause'});});
    act('pause',()=>{const p=player();if(typeof p?.sfPlayerPause==='function')p.sfPlayerPause();else postToPlayer({type:'playpause'});});
    act('nexttrack',()=>postToPlayer({type:'next'}));
    act('previoustrack',()=>postToPlayer({type:'prev'}));
    act('seekto',details=>{if(Number.isFinite(details?.seekTime))postToPlayer({type:'seek',time:details.seekTime});});
  }
  function updateHostMediaSession(data) {
    if(!hostAudio||!('mediaSession' in navigator))return;
    try{
      const artwork=data.art?[{src:data.art,sizes:'512x512'}]:[];
      navigator.mediaSession.metadata=new MediaMetadata({title:data.title||'',artist:data.artist||'',album:data.album||'',artwork});
      navigator.mediaSession.playbackState=data.playing?'playing':'paused';
    }catch(_){}
  }

  function host() { return document.getElementById('labPlayerHost'); }
  function frame() { return document.getElementById('labPlayerFrame'); }
  function mini() { return document.getElementById('labMiniDock'); }

  function postToPlayer(msg) {
    const f = frame();
    if (!f?.contentWindow) return;
    f.contentWindow.postMessage(Object.assign({ source: 'lab-host' }, msg), '*');
  }

  function openPlayer(albumId, trackIndex, kind='album', trackId=null) {
    unlockHostAudio();
    ensureShell();
    const h = host();
    const f = frame();
    const m = mini();
    if (!h || !f) return;
    const q = new URLSearchParams({ embed: '1' });q.set(kind,albumId);
    const view=new URLSearchParams(location.search).get('view')||'home';
    q.set('prefetch',kind==='album' && ['home','library','albums'].includes(view) ? '1':'0');
    if (trackIndex != null && trackIndex !== '') q.set('i', String(trackIndex));
    if (trackId !== null) q.set('track_id', trackId);
    const next = 'player.php?' + q.toString();
    // Recargar para aplicar progreso guardado e autoplay
    lastState={playing:true,index:Number(trackIndex)||0,time:0};
    f.dataset.src = next;
    const existing=f.contentWindow?.sfPlayerOpen;
    if(typeof existing==='function'){existing(next).catch(error=>{window.sfPlayerNotice?.(trackId !== null ? error.message : 'No se pudo abrir la selección. Inténtalo de nuevo.');});}
    else f.src = next;
    if (!floatingPlayer) restoreShell();
    if (m) m.hidden = true;
    persist();
  }

  function updateDockArtwork(art){
    const image=document.getElementById('labMiniArt');if(!image)return;
    if(!art){image.hidden=true;image.removeAttribute('src');return;}
    if(image.dataset.failedSrc===art){image.hidden=true;return;}
    if(image.getAttribute('src')!==art)image.src=art;
    image.hidden=false;
  }
  function minimizeShell(data) {
    const h = host();
    const m = mini();
    if (!h || !m) return;
    if (h.open) h.close();
    h.classList.add('is-min');
    // mantener iframe vivo (audio); no display:none
    m.hidden = false;
    applyCompactMode();
    const title = data?.title || document.getElementById('labMiniTitle')?.textContent;
    const status = data?.status || 'Mini';
    const art = data?.art || '';
    const tEl = document.getElementById('labMiniTitle');
    const sEl = document.getElementById('labMiniStatus');
    const aEl = document.getElementById('labMiniArt');
    const pEl = document.getElementById('labMiniPlay');
    if (tEl && title) tEl.textContent = title;
    if (sEl) sEl.textContent = status;
    updateDockArtwork(art);
    if (pEl) window.sfPlayerIcons.set(pEl,data?.playing === false?'play':'pause');
    const progress = document.getElementById('labMiniProgress');
    if (progress && Number.isFinite(Number(data?.progress))) progress.style.width = Math.max(0, Math.min(100, Number(data.progress))) + '%';
    document.body.classList.remove('lab-player-open');
    document.body.classList.add('lab-mini-open');
    persist();
  }

  function restoreShell() {
    if (floatingPlayer) { integratePlayer(); return; }
    const h = host();
    const m = mini();
    if (!h) return;
    h.hidden = false;
    h.classList.remove('is-min');
    if (!h.open) h.showModal();
    if (m) m.hidden = true;
    document.body.classList.remove('lab-mini-open');
    document.body.classList.add('lab-player-open');
    persist();
    postToPlayer({ type: 'restore' });
    postToPlayer({ type: 'measure-height' });
  }

  const floatStyle = `
    html,body.player-body.is-embed.sf-player-floating{margin:0;padding:0;min-height:100%;background:var(--bg,#0b161a)}
    body.sf-player-floating{overflow:auto}
    body.sf-player-floating #playerRoot{width:100%;max-width:none;box-shadow:none;border-radius:0}
    body.sf-player-floating #playerRoot .playlist{max-height:36vh!important}
    body.sf-player-floating #btnMin[hidden]{display:none!important}
  `;
  function integratePlayer(restore = true) {
    const floating = floatingPlayer;
    if (!floating) return;
    floatingPlayer = null;
    for (const [position, node] of floating.nodes) position.replaceWith(node);
    floating.button.title = 'Separar reproductor';
    floating.button.setAttribute('aria-label', floating.button.title);
    floating.button.setAttribute('aria-pressed', 'false');
    floating.shrink.hidden = false;
    if (!floating.window.closed) floating.window.close();
    if (restore) {
      restoreShell();
      floating.button.focus();
    }
  }

  window.labToggleFloatingPlayer = async () => {
    if (floatingPlayer) { integratePlayer(); return; }
    if (openingFloating || !window.documentPictureInPicture) return;
    const f = frame(), h = host(), doc = f?.contentDocument;
    const root = doc?.getElementById('playerRoot');
    const button = doc?.getElementById('btnFloat');
    const shrink = doc?.getElementById('btnMin');
    if (!root || !button || !shrink || !h?.open) return;
    openingFloating = true;
    try {
      const pip = await window.documentPictureInPicture.requestWindow({width:480,height:700});
      if (f.contentDocument !== doc || !h.open) { pip.close(); return; }
      const target = pip.document;
      target.title = 'Sparrowfy · Reproductor';
      target.documentElement.lang = doc.documentElement.lang || 'es';
      target.documentElement.dataset.theme = doc.documentElement.dataset.theme || '';
      target.body.className = doc.body.className + ' sf-player-floating';
      const base = target.createElement('base');
      base.href = doc.baseURI;
      target.head.append(base);
      for (const style of doc.querySelectorAll('link[rel="stylesheet"],head style')) target.head.append(style.cloneNode(true));
      const style = target.createElement('style');
      style.textContent = floatStyle;
      target.head.append(style);
      const nodes = [root,doc.getElementById('karaokeOverlay')].filter(Boolean).map(node => {
        const position = doc.createComment('floating player');
        node.before(position);
        return [position,node];
      });
      floatingPlayer = {window:pip,nodes,button,shrink};
      pip.addEventListener('pagehide', () => { if (floatingPlayer?.window === pip) integratePlayer(); }, {once:true});
      target.body.append(...nodes.map(([,node])=>node));
      shrink.hidden = true;
      button.title = 'Integrar reproductor';
      button.setAttribute('aria-label', button.title);
      button.setAttribute('aria-pressed', 'true');
      h.close();
      h.classList.add('is-min');
      document.body.classList.remove('lab-player-open');
      button.focus();
      persist(true);
    } catch (error) {
      if (floatingPlayer) integratePlayer();
      continueNotice('No se pudo separar el reproductor. Vuelve a intentarlo desde el botón.');
    } finally {
      openingFloating = false;
    }
  };


  let continueLoading=false;
  function continueNotice(message){
    let toast=document.getElementById('sfContinueNotice');
    if(!toast){toast=document.createElement('div');toast.id='sfContinueNotice';toast.setAttribute('role','status');toast.setAttribute('aria-live','polite');document.body.append(toast);}
    clearTimeout(toast.timer);clearTimeout(toast.hideTimer);
    toast.style.cssText='position:fixed!important;z-index:10001!important;box-sizing:border-box;max-width:min(340px,calc(100vw - 24px));padding:14px 16px;border:1px solid var(--primary,#10b981);border-radius:12px;background:var(--card,#102630);color:var(--text,#fff);box-shadow:0 8px 30px #0005;opacity:1;transition:opacity .25s ease;pointer-events:none';
    toast.textContent=message;toast.hidden=false;
    const button=document.getElementById('sfContinuePlayer'),r=button?.getBoundingClientRect();
    const width=toast.getBoundingClientRect().width,height=toast.getBoundingClientRect().height;
    toast.style.left=Math.max(12,Math.min(innerWidth-width-12,r?r.right-width:innerWidth-width-12))+'px';
    toast.style.top=Math.max(12,Math.min(innerHeight-height-12,r?r.bottom+10:76))+'px';
    toast.timer=setTimeout(()=>{toast.style.opacity='0';toast.hideTimer=setTimeout(()=>{toast.hidden=true;},250);},3000);
  }
  async function continueLastPlayer(){
    if(continueLoading)return;
    unlockHostAudio();
    ensureShell();const f=frame();
    if(f?.dataset.src && typeof f.contentWindow?.sfPlayerResume==='function' && f.contentWindow?.sfPlayerSnapshot?.()?.length){restoreShell();f.contentWindow.sfPlayerResume();return;}
    const saved=savedSelection();
    if(!saved?.src){continueNotice('Todavía no hay una última reproducción guardada. Elige una canción o colección para comenzar.');return;}
    let url;try{url=new URL(saved.src,location.href);}catch{continueNotice('No se pudo recuperar la última reproducción. Elige una colección.');return;}
    if(url.origin!==location.origin||url.pathname!==new URL('player.php',location.href).pathname){continueNotice('La última reproducción guardada no es válida.');return;}
    url.searchParams.delete('track_id');
    continueLoading=true;const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),12000);
    try{
      const query=new URL(url);query.searchParams.set('payload','1');
      const response=await fetch(query,{credentials:'same-origin',cache:'no-store',signal:controller.signal});
      if(!response.ok || response.redirected)throw Error('No se pudo recuperar la última reproducción. Comprueba tu sesión y que la colección siga disponible.');
      const payload=await response.json();
      if(!payload?.album?.tracks?.length)throw Error('La última colección ya no tiene pistas disponibles. Elige otra para reproducir.');
      lastState={...(saved.state||{}),playing:false};lastQueue=Array.isArray(saved.queue)&&saved.queue.length?saved.queue:payload.album.tracks;
      url.searchParams.set('resume','1');url.searchParams.set('resume_play','1');url.searchParams.set('resume_time',String(lastState.time||0));url.searchParams.set('resume_index',String(lastState.index||0));url.searchParams.set('resume_track',lastState.trackId||'');
      f.addEventListener('load',()=>{const player=f.contentWindow;if(typeof player?.sfPlayerResume!=='function'){closePlayerHost();continueNotice('No se pudo abrir el reproductor. Comprueba tu sesión y vuelve a intentar.');return;}player.sfPlayerRestoreQueue?.(lastQueue,lastState);player.sfPlayerResume();},{once:true});
      f.dataset.src=saved.src;f.src=url.href;restoreShell();
    }catch(error){continueNotice(error.name==='AbortError'?'La recuperación tardó demasiado. Puedes volver a intentar.':error.message);}
    finally{clearTimeout(timer);continueLoading=false;}
  }
  document.getElementById('sfContinuePlayer')?.addEventListener('click',continueLastPlayer);

  function bindShell() {
    window.sfPlayerIcons.hydrate(document);
    enableDrag(mini());
    host()?.addEventListener('cancel',event=>event.preventDefault());
    document.getElementById('labMiniCompact')?.addEventListener('click',()=>{compactMode=!compactMode;applyCompactMode();persist(true);});
    window.addEventListener('resize',()=>{applyCompactMode();postToPlayer({type:'measure-height'});});
    document.getElementById('labPlayerFrame')?.addEventListener('load',()=>{if(frame()?.dataset.src && !frame().contentDocument?.getElementById('playerRoot')){closePlayerHost();continueNotice('No hay pistas disponibles para recuperar o tu sesión venció. Abre una colección o inicia sesión de nuevo.');return;}postToPlayer({type:'measure-height'});});
    document.getElementById('labMiniArt')?.addEventListener('error',event=>{event.target.dataset.failedSrc=event.target.getAttribute('src')||'';event.target.hidden=true;});
    document.getElementById('labMiniRestore')?.addEventListener('click', restoreShell);
    document.getElementById('labMiniPlay')?.addEventListener('click', () => { unlockHostAudio(); const toggle=document.getElementById('labPlayerFrame')?.contentWindow?.sfPlayerToggle; if(typeof toggle==='function')toggle();else postToPlayer({type:'playpause'}); });

    window.addEventListener('message', (ev) => {
      const d = ev.data;
      if (!d || d.source !== 'lab-player' || ev.origin!==location.origin || ev.source!==frame()?.contentWindow) return;
      if(d.type==='content-height' && !floatingPlayer && Number.isFinite(d.height)){const f=frame();if(f)f.style.setProperty('--player-content-height',Math.max(100,Math.min(5000,d.height))+'px');}
      if(!floatingPlayer && d.type==='karaoke-size'){const f=frame();if(f){if(d.active)f.style.width='min(520px,100%)';else{f.style.removeProperty('width');f.style.removeProperty('height');}}}
      if(!floatingPlayer && d.type==='karaoke-full'){const f=frame();if(f){f.style.width='100%';f.style.height='calc(100dvh - 24px)';}}
      if (d.type === 'minimize') minimizeShell(d);
      if (d.type === 'close') closePlayerHost();
      if (d.type === 'nowplaying') updateHostMediaSession(d);
      if (d.type === 'queue-changed') {persist(true);document.dispatchEvent(new Event('sf:queue-updated'));}
      if (d.type === 'state') {
        lastState=d;if(d.playing)ensurePlayerVisible();persist();
        const pEl = document.getElementById('labMiniPlay');
        const tEl = document.getElementById('labMiniTitle');
        if (pEl && typeof d.playing === 'boolean') window.sfPlayerIcons.set(pEl,d.playing?'pause':'play');
        if (tEl && d.title) tEl.textContent = d.title;
        const sEl = document.getElementById('labMiniStatus');
        if (sEl && d.status) sEl.textContent = d.status;
        const aEl = document.getElementById('labMiniArt');
        if(typeof d.art==='string')updateDockArtwork(d.art);
        const progress = document.getElementById('labMiniProgress');
        if (progress && Number.isFinite(Number(d.progress))) progress.style.width = Math.max(0, Math.min(100, Number(d.progress))) + '%';
      }
    });

    document.addEventListener('sparrowfy:themechange', (event) => {
      const theme = event?.detail?.theme;
      if (theme) postToPlayer({ type: 'theme', theme });
    });

    document.getElementById('labMiniClose')?.addEventListener('click', () => {
      postToPlayer({ type: 'close' });
      // si el iframe no responde, cerrar igual
      setTimeout(closePlayerHost, 50);
    });
    document.getElementById('labConfigClose')?.addEventListener('click', closeConfig);
    document.getElementById('labConfigFrame')?.addEventListener('load',()=>{
      clearTimeout(configLoadTimer);const loading=document.getElementById('labConfigStatus');if(loading)loading.hidden=true;
      try{
        const frame=document.getElementById('labConfigFrame');
        const external=frame.contentWindow.location.pathname.endsWith('/external_maintenance.php');const maintenance=external||frame.contentWindow.location.pathname.endsWith('/maintenance.php');
        document.getElementById('labConfigClose').textContent=maintenance?'← Volver atrás':frame.contentWindow.location.pathname.endsWith('/album_edit.php')?'← Regresar':'Cerrar';
        if(maintenance)document.querySelector('#labConfigHost strong').textContent=external?'Integraciones externas e IA':'Respaldos e integridad';
        else if(frame.contentWindow.location.pathname.endsWith('/communication.php'))document.querySelector('#labConfigHost strong').textContent='Comunicación';
        else if(frame.contentWindow.location.pathname.endsWith('/config.php'))document.querySelector('#labConfigHost strong').textContent='Configuración';
      }catch(_){}
    });
  }

  function closePlayerHost() {
    if (floatingPlayer) integratePlayer(false);
    continueRequested=false;
    persist(true);
    try{sessionStorage.removeItem(storageKey);}catch{}
    lastState={};
    if(hostAudio){try{hostAudio.pause();hostAudio.removeAttribute('src');hostAudio.load();}catch(_){}}
    const h = host();
    const f = frame();
    const m = mini();
    if (f) {
      try { f.src = 'about:blank'; } catch (_) {}
      f.dataset.src = '';
    }
    if (h) {
      if (h.open) h.close();
      h.hidden = true;
      h.classList.remove('is-min');
    }
    if (m) m.hidden = true;
    document.body.classList.remove('lab-player-open', 'lab-mini-open');
  }

  function openConfig(page='config.php',title='Configuración') {
    if(window.sfConfirmLocalLeave && !window.sfConfirmLocalLeave())return;
    ensureShell();
    const ch = document.getElementById('labConfigHost');
    const cf = document.getElementById('labConfigFrame');
    if (!ch || !cf) return;
    document.querySelector('#labConfigHost strong').textContent=title;cf.title=title;
    const destination=new URL(page,location.href);destination.searchParams.set('embed','1');ch.classList.toggle('is-album',destination.pathname.endsWith('/album_edit.php'));cf.src=destination.href;
    ch.hidden = false;
    document.getElementById('labConfigClose').textContent=ch.classList.contains('is-album')?'← Regresar':'Cerrar';
    const loading=document.getElementById('labConfigStatus');if(loading){loading.hidden=false;loading.textContent='Cargando…';}
    clearTimeout(configLoadTimer);configLoadTimer=setTimeout(()=>{if(loading&&!ch.hidden){loading.hidden=false;loading.textContent='La carga está tardando; la reproducción continúa.';}},12000);
    document.body.classList.add('lab-config-open');
  }

  function closeConfig() {
    if(window.sfConfirmLocalLeave && !window.sfConfirmLocalLeave())return;
    clearTimeout(configLoadTimer);
    const ch = document.getElementById('labConfigHost');
    const cf = document.getElementById('labConfigFrame');
    try{if(cf && (cf.contentWindow.location.pathname.endsWith('/maintenance.php')||cf.contentWindow.location.pathname.endsWith('/external_maintenance.php'))){cf.src='config.php?embed=1';return;}}catch(_){}
    if (cf) cf.src = 'about:blank';window.sfResetLocalChild?.();
    if (ch) ch.hidden = true;
    document.body.classList.remove('lab-config-open');
  }

  function highlightAlbumCard(albumId){
    if(!/^[a-f0-9]{16}$/i.test(String(albumId||'')))return false;
    const card=document.querySelector('.album-card[data-album-id="'+albumId+'"]');
    if(!card)return false;
    card.scrollIntoView({behavior:'smooth',block:'center'});
    card.classList.remove('album-card-glow');
    void card.offsetWidth;
    card.classList.add('album-card-glow');
    const clear=()=>{card.classList.remove('album-card-glow');card.removeEventListener('animationend',clear);};
    card.addEventListener('animationend',clear);
    setTimeout(clear,9000);
    return true;
  }
  function showLibrary(albumId,requestedHash=''){
    const hash=albumId?'#album-'+albumId:/^#(?:loose-tracks|album-[a-f0-9]{16})$/i.test(requestedHash)?requestedHash:'';
    const target='index.php?view=library'+hash;
    const highlight=()=>{if(!highlightAlbumCard(albumId))setTimeout(()=>highlightAlbumCard(albumId),220);};
    const result=typeof window.sfNavigate==='function'?window.sfNavigate(target):Promise.resolve(false);
    Promise.resolve(result).then(()=>{if(hash==='#loose-tracks')document.getElementById('loose-tracks')?.scrollIntoView({block:'start'});else highlight();}).catch(()=>{continueNotice('No se pudo abrir la biblioteca. La reproducción continúa; inténtalo otra vez.');});
  }
  window.addEventListener('message',event=>{
    if(event.origin!==location.origin)return;
    const cf=document.getElementById('labConfigFrame');
    if(!cf||event.source!==cf.contentWindow)return;
    const data=event.data;
    if(!data||data.source!=='sf-navega'||data.type!=='show-library')return;
    const album=/^[a-f0-9]{16}$/i.test(String(data.album||''))?String(data.album):'';
    closeConfig();
    const host=document.getElementById('labConfigHost');
    if(host && !host.hidden)return;
    showLibrary(album,typeof data.hash==='string'?data.hash:'');
  });
  function highlightAlbumFromHash(){
    const match=location.hash.match(/^#album-([a-f0-9]{16})$/i);
    if(match)setTimeout(()=>highlightAlbumCard(match[1]),180);
  }
  window.addEventListener('hashchange',highlightAlbumFromHash);

  // All three display states share the live iframe and audio instance.
  function applyCompactMode(){
    const el=mini();if(!el)return;
    el.classList.toggle('is-compact',compactMode);
    const button=document.getElementById('labMiniCompact');
    button?.setAttribute('aria-pressed',String(compactMode));
    if(button){window.sfPlayerIcons.set(button,compactMode?'bar':'compact');button.title=compactMode?'Volver a barra minimizada':'Reducir a reproductor flotante';button.setAttribute('aria-label',button.title);}
    for(const prop of ['left','top','right','bottom'])el.style.removeProperty(prop);
    if(compactMode && compactPosition){
      const width=el.offsetWidth||Math.min(340,innerWidth-16),height=el.offsetHeight||80;
      compactPosition={x:Math.max(8,Math.min(innerWidth-width-8,Number(compactPosition.x)||8)),y:Math.max(8,Math.min(innerHeight-height-8,Number(compactPosition.y)||8))};
      el.style.left=compactPosition.x+'px';el.style.top=compactPosition.y+'px';el.style.right='auto';el.style.bottom='auto';
    }
  }
  function enableDrag(el){
    if(!el)return;
    let drag=null;
    el.addEventListener('pointerdown',e=>{
      if(!compactMode || e.button!==0 || e.target.closest('button,a,input,select'))return;
      const r=el.getBoundingClientRect();drag={id:e.pointerId,x:e.clientX,y:e.clientY,left:r.left,top:r.top};
      el.setPointerCapture(e.pointerId);el.classList.add('is-dragging');e.preventDefault();
    });
    el.addEventListener('pointermove',e=>{
      if(!drag || e.pointerId!==drag.id)return;
      compactPosition={x:Math.max(8,Math.min(innerWidth-el.offsetWidth-8,drag.left+e.clientX-drag.x)),y:Math.max(8,Math.min(innerHeight-el.offsetHeight-8,drag.top+e.clientY-drag.y))};
      el.style.left=compactPosition.x+'px';el.style.top=compactPosition.y+'px';el.style.right='auto';el.style.bottom='auto';
    });
    function finish(e){if(!drag||e.pointerId!==drag.id)return;drag=null;el.classList.remove('is-dragging');if(el.hasPointerCapture(e.pointerId))el.releasePointerCapture(e.pointerId);persist(true);}
    el.addEventListener('pointerup',finish);el.addEventListener('pointercancel',finish);el.addEventListener('lostpointercapture',finish);
  }

  // Interceptar enlaces/botones de reproducir
  function interceptPlayLinks() {
    document.addEventListener('click', (e) => {
      if(e.defaultPrevented||e.ctrlKey||e.metaKey||e.shiftKey||e.altKey||e.button!==0)return;
      const a = e.target.closest('a[href*="player.php"]') ||
        (!e.target.closest('button,a,input,select,textarea,summary,[role="menu"],.ctx-menu') && e.target.closest('.album-card')?.querySelector('a[href*="player.php"]'));
      if (a) {
        try {
          const u = new URL(a.getAttribute('href'), window.location.href);
          if (u.pathname.endsWith('player.php')) {
            const kind=u.searchParams.has('playlist')?'playlist':u.searchParams.has('favorites')?'favorites':'album';
            const album=u.searchParams.get(kind);
            if (album) {
              e.preventDefault();
              openPlayer(album,u.searchParams.get('i'),kind,u.searchParams.get('track_id'));
              return;
            }
          }
        } catch (_) {}
      }
      const originLink=e.target.closest('a[href*="album_edit.php"],a[href*="create_album.php"]');
      if(originLink&&(!originLink.target||originLink.target==='_self')&&(frame()?.dataset.src||document.querySelector('.app-shell'))&&openAlbumManager(originLink.href)){
        e.preventDefault();return;
      }
      const communicationLink=e.target.closest('a[href*="communication.php"]');
      if(communicationLink && window.parent===window){const u=new URL(communicationLink.href,location.href);if(u.origin===location.origin){e.preventDefault();openConfig(u.href,'Comunicación');return;}}
      const accountLink=e.target.closest('a[href*="change_password.php"],a[href*="admin_users.php"]');
      if(accountLink){const u=new URL(accountLink.href,location.href);if(u.origin===location.origin){e.preventDefault();const users=u.pathname.endsWith('/admin_users.php');openConfig(users?'admin_users.php':'change_password.php',users?'Cuentas de usuario':'Mi cuenta');return;}}
      const maintenanceLink=e.target.closest('a[href*="maintenance.php"]');
      if(maintenanceLink && window.parent===window){const u=new URL(maintenanceLink.href,location.href);if(u.origin===location.origin){e.preventDefault();const external=u.pathname.endsWith('/external_maintenance.php');openConfig(external?'external_maintenance.php':'maintenance.php',external?'Integraciones externas e IA':'Respaldos e integridad');return;}}
      const chartsLink=e.target.closest('a[href*="music_charts.php"],a[href*="music_charts_config.php"]');
      if(chartsLink && window.parent===window){const u=new URL(chartsLink.href,location.href);if(u.origin===location.origin){e.preventDefault();openConfig(u.href,u.pathname.endsWith('/music_charts_config.php')?'Configurar Music Charts':'Music Charts');return;}}
      const c = e.target.closest('a[href*="config.php"], a[href*="view=config"]');
      if (c) {
        e.preventDefault();
        openConfig();
      }
    });
  }

  function openAlbumManager(href){
    let url;try{url=new URL(href,location.href);}catch{return false;}
    if(url.origin!==location.origin)return false;
    if(url.pathname===new URL('create_album.php',location.href).pathname){url.pathname=new URL('album_edit.php',location.href).pathname;url.search='';url.hash='#createForm';}
    if(url.pathname!==new URL('album_edit.php',location.href).pathname)return false;
    if(frame()?.dataset.src&&!host()?.classList.contains('is-min'))minimizeShell(lastState);
    openConfig(url.href,url.searchParams.get('id')?'Gestión del álbum':'Nuevo álbum');return true;
  }
  window.labOpenAlbumManager=openAlbumManager;
  window.labCloseAlbumManager=()=>closeConfig();
  window.labOpenPlayer = openPlayer;
  window.labOpenPlayerFromPanel=(...args)=>openPlayer(...args);
  ensureShell();
  interceptPlayLinks();
  recover();
  updateContinue();
  highlightAlbumFromHash();
  // A reopened app must not leave a previous same-account tab playing unseen.
  if(account && typeof BroadcastChannel==='function'){
    startupChannel=new BroadcastChannel('sf-player-startup:'+account);
    startupChannel.addEventListener('message',event=>{if(event.data?.type==='startup-pause')pauseRecoveredPlayer();});
    startupChannel.postMessage({type:'startup-pause'});
  }

  document.addEventListener('submit',e=>{if(e.target.action && new URL(e.target.action,location.href).pathname.endsWith('/logout.php')){closePlayerHost();/* Last queue belongs to this account and survives logout. */}});

  // Navegación Inicio / Biblioteca / Config sin matar el iframe del reproductor
  function labInPageNav() {
    document.addEventListener('click', async (e) => {
        if(e.defaultPrevented)return;
        const a=e.target.closest('a[href*="index.php"]');
        if(!a||e.ctrlKey||e.metaKey||e.shiftKey||e.altKey||e.button!==0)return;
        const href = a.getAttribute('href') || '';
        if (!href.includes('index.php')) return;
        // Si hay reproductor activo (iframe con src), evitar reload completo
        const f = frame();
        if (!f || !f.dataset.src) return; // sin player: navegación normal
        e.preventDefault();
        try {
          await window.sfNavigate(href);
        } catch {
          continueNotice('No se pudo abrir la sección. La reproducción continúa; inténtalo otra vez.');
        }
      });
    document.addEventListener('submit', e=>{
      if(!e.target.matches('.sf-page-size')||!frame()?.dataset.src)return;
      e.preventDefault();
      const a=document.createElement('a');a.className='sf-compact';a.href='index.php?'+new URLSearchParams(new FormData(e.target));a.hidden=true;document.body.append(a);a.click();a.remove();
    });
    window.addEventListener('popstate', () => {
      window.sfNavigate(location.href,{historyMode:'none'}).catch(()=>location.reload());
    });
  }
  labInPageNav();
})();

