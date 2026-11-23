(()=>{'use strict';const csrf=document.querySelector('meta[name="sf-csrf"]')?.content;if(!csrf)return;let state={favorites:[],favorite_albums:[],playlists:[]},loading=null,currentTrack='',recorded=false;
let toastTimer,toastRemoval;
function toast(text){
 clearTimeout(toastTimer);clearTimeout(toastRemoval);
 let el=document.getElementById('sf-floating-notice');
 if(!el){el=document.createElement('div');el.id='sf-floating-notice';el.setAttribute('role','status');el.setAttribute('aria-live','polite');document.body.append(el);}
 el.textContent=text;el.classList.remove('sf-notice-out');
 toastTimer=setTimeout(()=>{el.classList.add('sf-notice-out');toastRemoval=setTimeout(()=>el.remove(),350);},2600);
}
window.sfPlayerNotice=message;
function message(text){if(document.getElementById('audioPlayer')){
 document.getElementById('sf-personal-status')?.remove();
 if(parent!==window)parent.postMessage({source:'sf-personal',type:'notice',text:String(text)},location.origin);else toast(text);return;
}let el=document.getElementById('sf-personal-status');if(!el){el=document.createElement('p');el.id='sf-personal-status';el.setAttribute('role','status');(document.querySelector('.lab-main')||document.body).append(el);}el.textContent=text;}
async function api(action,data={}){const body=new FormData();body.set('csrf_token',csrf);body.set('action',action);for(const[k,v]of Object.entries(data))body.set(k,String(v));const r=await fetch('api_personal.php',{method:'POST',body});const d=await r.json();if(!r.ok)throw Error(d.error||'Operacion no disponible');return d;}
async function loadState(force=false){if(force)loading=null;if(!loading)loading=fetch('api_personal.php?kind=state').then(async r=>{const d=await r.json();if(!r.ok)throw Error(d.error);state=d;return d;}).catch(e=>{loading=null;throw e;});return loading;}
function syncStars(){document.querySelectorAll('[data-sf-favorite]').forEach(b=>{const active=state.favorites.includes(b.dataset.sfFavorite);const glyph=active?'★':'☆';if(b.textContent!==glyph)b.textContent=glyph;b.setAttribute('aria-pressed',String(active));b.title=active?'Quitar de favoritos':'Añadir a favoritos';b.setAttribute('aria-label',b.title);});document.querySelectorAll('[data-sf-album-fav]').forEach(b=>{const active=(state.favorite_albums||[]).includes(b.dataset.sfAlbumFav);const glyph=active?'★':'☆';if(b.textContent!==glyph)b.textContent=glyph;b.setAttribute('aria-pressed',String(active));b.title=active?'Quitar colección de favoritos':'Añadir colección a favoritos';b.setAttribute('aria-label',b.title);});}
function bind(root=document){root.querySelectorAll('[data-sf-track]:not([data-sf-ready])').forEach(el=>{el.dataset.sfReady='1';const id=el.dataset.sfTrack;if(!id)return;el.classList.add('sf-track-actions');const star=document.createElement('button');star.type='button';star.className='btn sf-star';star.dataset.sfFavorite=id;star.textContent='☆';el.append(star);if(el.dataset.sfFavoriteOnly==='1')return;const picker=document.createElement('button');picker.type='button';picker.className='btn sf-list-picker';picker.textContent='Listas ▾';picker.dataset.sfPick=id;picker.setAttribute('aria-haspopup','dialog');el.append(picker);});syncStars();}
async function navigate(href){if(await window.sfNavigate(href)){await loadState(true);bind();}}
window.sfPersonalTrack=track=>{currentTrack=track?.id||'';recorded=false;const slot=document.getElementById('sf-player-personal');if(slot){slot.replaceChildren();slot.removeAttribute('data-sf-ready');slot.dataset.sfTrack=currentTrack;loadState().then(()=>bind()).catch(e=>message(e.message));}};
function bindRecentAudio(a){if(!a||a.dataset.sfRecentBound==='1')return;a.dataset.sfRecentBound='1';a.addEventListener('playing',()=>{if(!currentTrack||recorded)return;recorded=true;api('recent',{track_id:currentTrack}).catch(e=>{recorded=false;message('No se pudo guardar en recientes: '+e.message);});});}
bindRecentAudio(document.getElementById('audioPlayer'));
// En iPhone/iPad el audio real lo posee la página principal (shell).
if(parent!==window){try{bindRecentAudio(parent.sfShellAudio);}catch(_){}}
document.addEventListener('click',async e=>{const b=e.target.closest('[data-sf-pick]');if(!b)return;e.preventDefault();const track=b.dataset.sfPick;
 await window.sfMembershipPicker({title:'Listas de reproducción',trigger:b,load:async()=>{const r=await fetch('api_personal.php?kind=memberships&track_id='+encodeURIComponent(track));const d=await r.json();if(!r.ok)throw Error(d.error);return d.items.map(x=>({...x,selected:!!Number(x.selected)}));},save:async(item,enabled)=>{await api(enabled?'playlist_add':'playlist_remove',{track_id:track,playlist_id:item.id});toast(enabled?'Canción añadida a la lista':'Canción retirada de la lista');}});
});
document.addEventListener('click',async e=>{const star=e.target.closest('[data-sf-favorite]'),albumStar=e.target.closest('[data-sf-album-fav]'),add=e.target.closest('[data-sf-add]'),actionButton=e.target.closest('button[data-personal-action]');if(!star&&!albumStar&&!add&&!actionButton)return;const b=star||albumStar||add||actionButton;if(b.disabled)return;e.preventDefault();b.disabled=true;try{
 if(star){const id=star.dataset.sfFavorite;const result=await api('favorite',{track_id:id,enabled:state.favorites.includes(id)?0:1});state.favorites=state.favorites.filter(t=>t!==id);if(result.favorite)state.favorites.push(id);syncStars();message(result.favorite?'Añadida a tus favoritos':'Quitada de tus favoritos');if(parent!==window)parent.postMessage({source:'sf-personal',type:'changed'},location.origin);}
 else if(albumStar){const id=albumStar.dataset.sfAlbumFav;const on=!(state.favorite_albums||[]).includes(id);const result=await api('favorite_album',{album_id:id,enabled:on?1:0});state.favorite_albums=(state.favorite_albums||[]).filter(a=>a!==id);if(result.favorite_album)state.favorite_albums.push(id);syncStars();message(result.favorite_album?'Colección añadida a tus favoritos':'Colección quitada de tus favoritos');if(parent!==window)parent.postMessage({source:'sf-personal',type:'changed'},location.origin);if(!result.favorite_album&&document.querySelector('.sf-personal-page[data-personal-view="favorites"]'))await navigate(location.href);}
 else if(add){const target=add.parentElement.querySelector('select').value;if(!target)throw Error('Elige una lista. Puedes crearla en Mis listas.');await api('playlist_add',{track_id:add.dataset.sfAdd,playlist_id:target});message('Canción añadida a la lista');}
 else{const action=b.dataset.personalAction;if(action==='rename_playlist'){const name=window.prompt('Nuevo nombre de la lista:',b.dataset.playlistName||'');if(name===null)return;await api(action,{playlist_id:b.dataset.playlistId,name});await navigate(location.href);message('Lista renombrada');return;}const d=await api(action,{playlist_id:b.dataset.playlistId||'',track_id:b.dataset.trackId||'',token:b.dataset.token||''});if(action==='share_playlist'){const url=new URL('index.php',location.href);url.searchParams.set('view','playlists');url.searchParams.set('share',d.token);document.getElementById('sf-share-output').hidden=false;document.getElementById('sf-share-url').value=url.href;message('Enlace listo. Requiere iniciar sesión para añadir la lista.');}else if(action==='import_playlist'){await navigate('index.php?view=playlists&list='+encodeURIComponent(d.playlist_id));message(d.already_added?'Ya tienes esta lista en tu cuenta':'Lista añadida a tu cuenta');}else if(action==='playlist_remove'){await navigate(location.href);}else message('Enlace revocado');}
 }catch(err){message(err.message);}finally{b.disabled=false;}});
document.addEventListener('submit',async e=>{const form=e.target.closest('form[data-personal-action]');if(!form)return;e.preventDefault();if(form.dataset.saving==='1')return;form.dataset.saving='1';const buttons=[...form.querySelectorAll('button')];const action=form.dataset.personalAction;const fields=Object.fromEntries(new FormData(form));if(action==='share_user')fields.shared=e.submitter?.value||'1';buttons.forEach(b=>b.disabled=true);try{const d=await api(action,fields);form.reset();await navigate('index.php?view=playlists&list='+encodeURIComponent(d.playlist_id));message(action==='share_user'?(d.shared?'Lista compartida con '+d.shared_with:'Acceso retirado a '+d.shared_with):'Lista creada');}catch(err){message(err.message);}finally{buttons.forEach(b=>b.disabled=false);delete form.dataset.saving;}});
document.addEventListener('click',async e=>{if(e.target.id!=='sf-copy-link')return;const input=document.getElementById('sf-share-url');try{await navigator.clipboard.writeText(input.value);message('Enlace copiado');}catch{input.select();message('Selecciona y copia el enlace');}});
async function downloadPlaylist(button){
 const id=button.dataset.offlinePlaylist||'',name=button.dataset.playlistName||'la lista',offline=window.SFOffline,label=button.textContent;
 if(!offline){message('El modo sin conexión no está disponible en este navegador.');return;}
 button.disabled=true;
 try{
  const r=await fetch('api_personal.php?kind=playlist&playlist_id='+encodeURIComponent(id),{cache:'no-store'});
  const d=await r.json().catch(()=>({}));
  if(!r.ok)throw Error(d.error||'No se pudo cargar la lista');
  const all=Array.isArray(d.tracks)?d.tracks:[],list=all.filter(t=>t&&/^[a-f0-9]{16}$/i.test(t.id||'')&&String(t.hls||'').startsWith('hls_out/')),skipped=all.length-list.length;
  if(!list.length)throw Error('Esta lista no tiene pistas con audio local descargable.');
  let done=0,failed=0,stopped=false;
  for(let i=0;i<list.length;i++){
   const t=list[i],n=i+1;
   button.textContent='Descargando '+n+'/'+list.length;
   message('Descargando '+n+' de '+list.length+' de «'+name+'»: '+(t.name||'Pista')+'…');
   let last=-1;
   try{
    await offline.download({...t,album:t.album||t.album_title||''},(percent,text)=>{if(percent===100||percent-last>=5){last=percent;message('Descargando '+n+' de '+list.length+' · '+percent+'% · '+(text||''));}});
    done++;
   }catch(err){
    failed++;
    if(/límite|limite|espacio|storage|quota/i.test(err?.message||'')){stopped=true;message((err.message||'Se superó el límite de almacenamiento')+' Se detuvo la descarga de la lista.');break;}
   }
  }
  if(!stopped){
   const parts=['«'+name+'»: '+done+(done===1?' canción descargada':' canciones descargadas')];
   if(skipped)parts.push(skipped+' sin audio local');
   if(failed)parts.push(failed+' con error');
   message(parts.join(' · ')+'. Ábrelas en Oír sin conexión.');
  }
 }catch(err){message(err?.message||'No se pudo descargar la lista');}
 finally{button.disabled=false;button.textContent=label;}
}
document.addEventListener('click',e=>{const b=e.target.closest('[data-offline-playlist]');if(!b)return;e.preventDefault();if(!b.disabled)downloadPlaylist(b);});
window.addEventListener('message',e=>{if(e.origin!==location.origin||e.data?.source!=='sf-personal'||e.source!==document.getElementById('labPlayerFrame')?.contentWindow)return;if(e.data.type==='notice'&&typeof e.data.text==='string'){toast(e.data.text.slice(0,500));return;}if(e.data.type==='changed')loadState(true).then(()=>syncStars()).catch(()=>{});});
document.addEventListener('sf:navigated',()=>loadState().then(()=>bind()).catch(()=>{}));
document.addEventListener('sf:personal-changed',()=>loadState().then(()=>bind()).catch(()=>{}));
const observer=new MutationObserver(records=>{const added=records.some(r=>[...r.addedNodes].some(n=>n.nodeType===1&&(n.matches('[data-sf-track]:not([data-sf-ready])')||n.querySelector('[data-sf-track]:not([data-sf-ready])'))));if(added)loadState().then(()=>bind()).catch(()=>{});});
observer.observe(document.querySelector('.lab-main')||document.body,{childList:true,subtree:true});loadState().then(()=>bind()).catch(e=>message(e.message));})();
