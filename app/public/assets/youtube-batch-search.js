(()=>{'use strict';
function videoKey(value){try{const u=new URL(value);if(u.hostname==='youtu.be')return u.pathname.slice(1);return u.searchParams.get('v')||value;}catch(_){return value.trim();}}
for(const id of ['ytUrls','onlineAlbumIdeaUrls']){
 const textarea=document.getElementById(id);if(!textarea)continue;
 const section=document.createElement('div');section.style.cssText='margin:12px 0;padding:12px;border:1px solid var(--border,#315563);border-radius:10px';
 section.innerHTML='<div>Buscar canciones en <select data-stream-source aria-label="Fuente de búsqueda"><option value="youtube">YouTube</option><option value="mornye">Mornye</option></select></div><label><input type="search" maxlength="240" placeholder="Artista y título" style="width:100%;box-sizing:border-box"></label><button type="button" class="btn" style="margin-top:8px">Buscar y elegir enlaces</button><p role="status" aria-live="polite" style="font-size:.85rem;margin:8px 0 0"></p>';
 textarea.after(section);const input=section.querySelector('input'),status=section.querySelector('p');
 const lines=()=>textarea.value.split(/\r?\n/).map(v=>v.trim()).filter(Boolean);
 function search(){
  if(!input.value.trim()){status.textContent='Escribe el artista o título que quieres buscar.';input.focus();return;}
  if(!window.sfYouTubeSourcePicker){status.textContent='La búsqueda no está disponible. Puedes pegar los enlaces manualmente.';return;}
  if(section.querySelector('[data-stream-source]').value==='mornye'){
   if(!window.sfMornyePicker){status.textContent='Recarga la página para activar Mornye.';return;}
   status.textContent=window.sfMornyeAlbumAppend?'La selección se agregará al lote de Mornye del álbum.':'La canción se añadirá sin asignar a colección.';
   window.sfMornyePicker({query:input.value.trim(),onSelect:item=>{if(window.sfMornyeAlbumAppend){window.sfMornyeAlbumAppend(item);window.sfShowMornyeAlbumImporter?.();}else window.sfMornyeAdd(item);}});return;
  }
  status.textContent='';window.sfYouTubeSourcePicker({query:input.value.trim(),isSelected:url=>lines().some(v=>videoKey(v)===videoKey(url)),onToggle:(url,add)=>{
   let values=lines();const key=videoKey(url),exists=values.some(v=>videoKey(v)===key);
   if(add&&!exists){if(values.length>=(id==='ytUrls'?100:10)){status.textContent='El lote permite hasta '+(id==='ytUrls'?100:10)+' enlaces.';window.sfReplacementNotice?.('youtube-limit-'+Date.now(),'El lote permite hasta '+(id==='ytUrls'?100:10)+' enlaces.',false);return false;}values.push(url);}
   if(!add)values=values.filter(v=>videoKey(v)!==key);
   textarea.value=values.join('\n');textarea.dispatchEvent(new Event('input',{bubbles:true}));textarea.dispatchEvent(new Event('change',{bubbles:true}));status.textContent=values.length+' de 10 enlaces en la lista.';return true;
  }});
 }
 section.querySelector('button').onclick=search;input.onkeydown=e=>{if(e.key==='Enter'){e.preventDefault();search();}};
}
})();
