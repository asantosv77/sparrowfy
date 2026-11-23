(() => {
  'use strict';
  const coverSearches = new WeakMap();

  function escapeHtml(s) {
    return String(s || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function albumNotice(text) {
    if (typeof window.sfPlayerNotice === 'function') window.sfPlayerNotice(text);
    else alert(text);
  }

  // Aviso previo al borrado: cuántos usuarios tienen la colección en favoritos.
  async function albumFavoritesNote(albumId) {
    if (!albumId) return '';
    try {
      const fd = new FormData();
      fd.set('csrf_token', document.querySelector('meta[name="sf-csrf"]')?.content || '');
      fd.set('action', 'favorites_preview');
      fd.set('album_id', albumId);
      const r = await fetch('api_album_manage.php', { method: 'POST', body: fd, credentials: 'same-origin' });
      const j = await r.json().catch(() => ({}));
      const count = Number(j && j.count) || 0;
      if (!r.ok || !count) return '';
      const users = Array.isArray(j.users) ? j.users.filter(Boolean) : [];
      let text = 'Esta colección está en los favoritos de ' + count + (count === 1 ? ' usuario' : ' usuarios') + '.\n';
      if (users.length) text += 'La tienen: ' + users.join(', ') + (count > users.length ? ' y más' : '') + '.\n';
      text += 'Al eliminarla también desaparecerá de sus favoritos.\n\n';
      return text;
    } catch (_) { return ''; }
  }

  async function downloadAlbum(album) {
    if (!window.SFOffline) { albumNotice('El modo sin conexión no está disponible en este navegador.'); return; }
    if (!album || !album.id) return;
    const title = album.title || 'el álbum';
    albumNotice('Preparando «' + title + '» para oír sin conexión…');
    try {
      const r = await fetch('api_personal.php?kind=album&album_id=' + encodeURIComponent(album.id), { credentials: 'same-origin', cache: 'no-store' });
      const d = await r.json().catch(() => ({}));
      if (!r.ok) throw Error(d.error || 'No se pudo cargar el álbum');
      const all = Array.isArray(d.tracks) ? d.tracks : [];
      const list = all.filter(t => t && /^[a-f0-9]{16}$/i.test(t.id || '') && String(t.hls || '').startsWith('hls_out/'));
      const skipped = all.length - list.length;
      if (!list.length) throw Error('Este álbum no tiene pistas con audio local descargable.');
      let done = 0, failed = 0, stopped = false;
      for (let i = 0; i < list.length; i++) {
        const t = list[i], n = i + 1;
        albumNotice('Descargando ' + n + ' de ' + list.length + ' de «' + title + '»: ' + (t.name || 'Pista') + '…');
        let last = -1;
        try {
          await window.SFOffline.download({ ...t, album: t.album || album.title || '', artist: t.artist || album.artist || '' }, (percent, text) => {
            if (percent === 100 || percent - last >= 5) { last = percent; albumNotice('Descargando ' + n + ' de ' + list.length + ' · ' + percent + '% · ' + (text || '')); }
          });
          done++;
        } catch (err) {
          failed++;
          if (/límite|limite|espacio|storage|quota/i.test(err?.message || '')) {
            stopped = true;
            albumNotice((err.message || 'Se superó el límite de almacenamiento') + ' Se detuvo la descarga del álbum.');
            break;
          }
        }
      }
      if (!stopped) {
        const parts = ['«' + (album.title || 'Álbum') + '»: ' + done + (done === 1 ? ' canción descargada' : ' canciones descargadas')];
        if (skipped) parts.push(skipped + ' sin audio local');
        if (failed) parts.push(failed + ' con error');
        albumNotice(parts.join(' · ') + '. Ábrelas en Oír sin conexión.');
      }
    } catch (err) {
      albumNotice('No se pudo descargar el álbum: ' + (err?.message || 'error'));
    }
  }

  function bindAlbumMenus(root) {
    const scope = root || document;
    const menu = document.getElementById('albumCtxMenu');
    const matchModal = document.getElementById('matchModal');
    const frameModal = document.getElementById('frameModal');
    const matchGrid = document.getElementById('matchGrid');
    const matchHint = document.getElementById('matchHint');
    if (!menu) return;

    let current = null;

    function hideMenu() {
      menu.hidden = true;
    }
    function openModal(el) {
      if (!el) return;
      el.hidden = false;
      document.body.style.overflow = 'hidden';
    }
    function closeModal(el) {
      if (!el) return;
      const lookup=coverSearches.get(el);if(lookup){lookup.controller.abort();clearTimeout(lookup.timer);lookup.loading?.stop();coverSearches.delete(el);}
      el.hidden = true;
      document.body.style.overflow = '';
    }

    // Evitar listeners duplicados en el menú global
    if (!menu.dataset.globalBound) {
      menu.dataset.globalBound = '1';
      document.addEventListener('click', () => hideMenu());
      menu.addEventListener('click', (e) => e.stopPropagation());

      menu.querySelectorAll('button[data-act]').forEach((b) => {
        b.addEventListener('click', async (e) => {
          e.stopPropagation();
          if (!current) return;
          const act = b.dataset.act;
          const album = current;
          hideMenu();

          if (act === 'edit') {
            const href='album_edit.php?id='+encodeURIComponent(album.id);
            if(!window.labOpenAlbumManager?.(href))location.href=href;
            return;
          }
          if (act === 'delete') {
            const favNote = await albumFavoritesNote(album.id);
            const ok = confirm(
              favNote +
              '¿Eliminar el álbum «' + album.title + '»?\n\n' +
              'Se borrarán metadatos y archivos HLS del álbum. No se puede deshacer.'
            );
            if (!ok) return;
            const fd = new FormData();
fd.set('csrf_token',document.querySelector('meta[name="sf-csrf"]')?.content||'');
            fd.append('action', 'delete');
            fd.append('album_id', album.id);
            const r = await fetch('api_album_manage.php', { method: 'POST', body: fd, credentials: 'same-origin' });
            const j = await r.json().catch(() => ({}));
            if (!r.ok || j.error) {
              alert(j.error || 'No se pudo eliminar');
              return;
            }
            if (j.warning) alert(j.warning);
            album.card.remove();
            return;
          }
          if (act === 'request-delete') {
            const favNote = await albumFavoritesNote(album.id);
            const ok = confirm(
              favNote +
              '¿Solicitar la eliminación de la colección «' + album.title + '»?\n\n' +
              'Se ocultará de tu biblioteca y un administrador tendrá que aprobar el borrado. ' +
              'Mientras esté pendiente, puedes pedirle que la restaure.'
            );
            if (!ok) return;
            const fd = new FormData();
fd.set('csrf_token',document.querySelector('meta[name="sf-csrf"]')?.content||'');
            fd.append('action', 'request_delete');
            fd.append('album_id', album.id);
            const r = await fetch('api_album_manage.php', { method: 'POST', body: fd, credentials: 'same-origin' });
            const j = await r.json().catch(() => ({}));
            if (!r.ok || j.error) {
              alert(j.error || 'No se pudo registrar la solicitud');
              return;
            }
            album.card.remove();
            if (window.sfPlayerNotice) window.sfPlayerNotice('Solicitud enviada. La colección quedó oculta hasta que el administrador la revise.');
            return;
          }
          if (act === 'match') {
            openMatch(album);
            return;
          }
          if (act === 'frame') {
            openFrame(album);
          }
        });
      });

      document.getElementById('matchClose')?.addEventListener('click', () => closeModal(matchModal));
      document.getElementById('matchCancel')?.addEventListener('click', () => closeModal(matchModal));
      document.getElementById('frameClose')?.addEventListener('click', () => closeModal(frameModal));
      document.getElementById('frameCancel')?.addEventListener('click', () => closeModal(frameModal));
      matchModal?.addEventListener('click', (e) => { if (e.target === matchModal) closeModal(matchModal); });
      frameModal?.addEventListener('click', (e) => { if (e.target === frameModal) closeModal(frameModal); });
    }

    // Guardar current vía propiedad en menu
    const setCurrent = (c) => { current = c; };

    // Reasignar current en cada click de menú usando closure compartida:
    // Parche: los botones del menú ya usan `current` del scope de bindAlbumMenus
    // si se llama varias veces se duplican handlers en .album-menu-btn — marcar bound

    scope.querySelectorAll('.album-menu-btn').forEach((btn) => {
      if (btn.dataset.bound === '1') return;
      btn.dataset.bound = '1';
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        const card = btn.closest('.album-card');
        if (!card) return;
        current = {
          id: card.dataset.albumId,
          title: card.dataset.albumTitle || '',
          artist: card.dataset.albumArtist || '',
          card,
        };
        // Exponer current al handler del menú (mismo closure si es la primera bind global)
        window.__labAlbumCurrent = current;
        const queue=menu.querySelector('[data-queue-add]');if(queue){queue.dataset.queueId=current.id;queue.dataset.queueTracks=card.dataset.queueTracks||'null';}window.sfQueueActions?.refresh();
        const r = btn.getBoundingClientRect();
        menu.hidden = false;
        const mw = menu.offsetWidth || 180;
        const mh = menu.offsetHeight || 160;
        let top = r.bottom + 6;
        let left = r.right - mw;
        if (left < 8) left = 8;
        if (left + mw > window.innerWidth - 8) left = window.innerWidth - mw - 8;
        if (top + mh > window.innerHeight - 8) top = Math.max(8, r.top - mh - 6);
        menu.style.top = top + 'px';
        menu.style.left = left + 'px';
      });
    });

    // Los handlers del menú deben leer window.__labAlbumCurrent si current local está desfasado
    // Reescribimos una vez:
    if (!menu.dataset.useGlobalCurrent) {
      menu.dataset.useGlobalCurrent = '1';
      menu.querySelectorAll('button[data-act]').forEach((b) => {
        // ya hay listener; añadimos pre-hook sustituyendo current al inicio no es posible
      });
    }

    async function openMatch(album) {
      if (!matchModal || !matchGrid) {
        alert('Panel de coincidencias no disponible.');
        return;
      }
      const prior=coverSearches.get(matchModal);if(prior){prior.controller.abort();clearTimeout(prior.timer);prior.loading?.stop();}
      matchGrid.replaceChildren();
      const lookup={controller:new AbortController(),loading:window.sfLoadingSkeletons?.start(matchGrid,{kind:'covers',count:4,label:'Buscando portadas del álbum…'}),timer:null};
      lookup.timer=setTimeout(()=>lookup.controller.abort(),25000);coverSearches.set(matchModal,lookup);
      if (matchHint) matchHint.textContent = (album.artist ? album.artist + ' — ' : '') + album.title;
      openModal(matchModal);
      const q = new URLSearchParams({
        mode: 'search',
        artist: album.artist || '',
        album: album.title || '',
        title: album.title || '',
      });
      try {
        const r = await fetch('api_cover.php?' + q.toString(), { credentials: 'same-origin', cache: 'no-store', signal:lookup.controller.signal });
        const j = await r.json().catch(() => ({}));
        if(coverSearches.get(matchModal)!==lookup||matchModal.hidden)return;
        if(!r.ok||j.error)throw Error(j.error||'No se pudieron consultar las portadas');
        lookup.loading?.stop();
        const list = Array.isArray(j.candidates) ? j.candidates : [];
        if (!list.length) {
          matchGrid.innerHTML = '<p class="muted">No se encontraron coincidencias.</p>';
          return;
        }
        matchGrid.innerHTML = '';
        list.forEach((c, idx) => {
          if (!c?.cover) return;
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'match-item';
          btn.innerHTML =
            '<img src="' + String(c.cover).replace(/"/g, '&quot;') + '" alt="" loading="lazy">' +
            '<span><strong>' + escapeHtml(c.album || ('Opción ' + (idx + 1))) + '</strong>' +
            '<small>' + escapeHtml((c.artist || '') + (c.source ? ' · ' + c.source : '')) + '</small></span>';
          btn.addEventListener('click', async () => {
            btn.disabled = true;
            const fd = new FormData();
fd.set('csrf_token',document.querySelector('meta[name="sf-csrf"]')?.content||'');
            fd.append('action', 'set_cover_url');
            fd.append('album_id', album.id);
            fd.append('cover_url', c.cover);
            fd.append('matched_album', c.album || '');
            fd.append('matched_artist', c.artist || '');
            const rr = await fetch('api_album_manage.php', { method: 'POST', body: fd, credentials: 'same-origin' });
            const jj = await rr.json().catch(() => ({}));
            if (!rr.ok || jj.error) {
              alert(jj.error || 'Error al aplicar portada');
              btn.disabled = false;
              return;
            }
            const coverEl = album.card.querySelector('.album-cover');
            if (coverEl) {
              coverEl.style.backgroundImage = 'url("' + (jj.cover || c.cover) + '")';
              coverEl.querySelector('.cover-placeholder')?.remove();
            }
            closeModal(matchModal);
          });
          matchGrid.appendChild(btn);
        });
      } catch (err) {
        if(coverSearches.get(matchModal)!==lookup||matchModal.hidden)return;
        matchGrid.innerHTML = '<p class="muted">' + (err.name==='AbortError'?'La búsqueda tardó demasiado. Puedes volver a intentarlo.':'Error: '+escapeHtml(err.message||'red')) + '</p>';
      } finally {clearTimeout(lookup.timer);lookup.loading?.stop();if(coverSearches.get(matchModal)===lookup)coverSearches.delete(matchModal);}
    }

    function openFrame(album) {
      if (!frameModal) return;
      const prev = document.getElementById('framePreview');
      const x = document.getElementById('frameX');
      const y = document.getElementById('frameY');
      const z = document.getElementById('frameZ');
      const coverEl = album.card.querySelector('.album-cover');
      const bg = coverEl ? getComputedStyle(coverEl).backgroundImage : '';
      if (!bg || bg === 'none') {
        alert('Sin portada. Usa «Coincidir álbum» primero.');
        return;
      }
      if (prev) {
        prev.style.backgroundImage = bg;
        prev.style.backgroundRepeat = 'no-repeat';
      }
      const apply = () => {
        if (!prev) return;
        prev.style.backgroundPosition = x.value + '% ' + y.value + '%';
        prev.style.backgroundSize = z.value + '%';
      };
      x.oninput = y.oninput = z.oninput = apply;
      apply();
      openModal(frameModal);
      const save = document.getElementById('frameSave');
      if (save) {
        save.onclick = async () => {
          if(save.disabled)return;save.disabled=true;const feedback=window.sfSaveFeedback?.begin(save);
          try{
          const fd = new FormData();
fd.set('csrf_token',document.querySelector('meta[name="sf-csrf"]')?.content||'');
          fd.append('action', 'set_layout');
          fd.append('album_id', album.id);
          fd.append('x', x.value);
          fd.append('y', y.value);
          fd.append('zoom', z.value);
          const r = await fetch('api_album_manage.php', { method: 'POST', body: fd, credentials: 'same-origin' });
          const j = await r.json().catch(() => ({}));
          if (!r.ok || j.error) {
            throw Error(j.error || 'No se pudo guardar');
          }
          if (coverEl) {
            coverEl.style.backgroundPosition = x.value + '% ' + y.value + '%';
            coverEl.style.backgroundSize = z.value + '%';
          }
          feedback?.success('Encuadre guardado.');
          setTimeout(()=>closeModal(frameModal),650);
          }catch(error){feedback?.error(error);if(!feedback)alert(error.message);}finally{save.disabled=false;}
        };
      }
    }

    // Fix: menu actions must use latest current from window
    if (!menu.dataset.rebound) {
      menu.dataset.rebound = '1';
      // Replace click handlers by cloning buttons
      menu.querySelectorAll('button[data-act]').forEach((b) => {
        const nb = b.cloneNode(true);
        b.parentNode.replaceChild(nb, b);
      });
      menu.querySelectorAll('button[data-act]').forEach((b) => {
        b.addEventListener('click', async (e) => {
          e.stopPropagation();
          const album = window.__labAlbumCurrent || current;
          if (!album) return;
          const act = b.dataset.act;
          hideMenu();
          if (act === 'edit') {
            const href='album_edit.php?id='+encodeURIComponent(album.id);
            if(!window.labOpenAlbumManager?.(href))location.href=href;
            return;
          }
          if (act === 'delete') {
            const favNote = await albumFavoritesNote(album.id);
            const ok = confirm(
              favNote +
              '¿Eliminar el álbum «' + album.title + '»?\n\nSe borrarán metadatos y HLS. No se puede deshacer.'
            );
            if (!ok) return;
            const fd = new FormData();
fd.set('csrf_token',document.querySelector('meta[name="sf-csrf"]')?.content||'');
            fd.append('action', 'delete');
            fd.append('album_id', album.id);
            const r = await fetch('api_album_manage.php', { method: 'POST', body: fd, credentials: 'same-origin' });
            const j = await r.json().catch(() => ({}));
            if (!r.ok || j.error) {
              alert(j.error || 'No se pudo eliminar');
              return;
            }
            if (j.warning) alert(j.warning);
            album.card?.remove();
            return;
          }
          if (act === 'request-delete') {
            const favNote = await albumFavoritesNote(album.id);
            const ok = confirm(
              favNote +
              '¿Solicitar la eliminación de la colección «' + album.title + '»?\n\n' +
              'Se ocultará de tu biblioteca y un administrador tendrá que aprobar el borrado. ' +
              'Mientras esté pendiente, puedes pedirle que la restaure.'
            );
            if (!ok) return;
            const fd = new FormData();
fd.set('csrf_token',document.querySelector('meta[name="sf-csrf"]')?.content||'');
            fd.append('action', 'request_delete');
            fd.append('album_id', album.id);
            const r = await fetch('api_album_manage.php', { method: 'POST', body: fd, credentials: 'same-origin' });
            const j = await r.json().catch(() => ({}));
            if (!r.ok || j.error) {
              alert(j.error || 'No se pudo registrar la solicitud');
              return;
            }
            album.card?.remove();
            if (window.sfPlayerNotice) window.sfPlayerNotice('Solicitud enviada. La colección quedó oculta hasta que el administrador la revise.');
            return;
          }
          if (act === 'match') {
            openMatch(album);
            return;
          }
          if (act === 'offline') {
            downloadAlbum(album);
            return;
          }
          if (act === 'frame') openFrame(album);
        });
      });
    }
  }

  window.labBindAlbumMenus = bindAlbumMenus;
  document.addEventListener('DOMContentLoaded', () => bindAlbumMenus(document));
  // por si el script va al final
  if (document.readyState !== 'loading') bindAlbumMenus(document);
})();
