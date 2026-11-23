(()=>{'use strict';
window.sfYouTubeSourcePicker=({query,onSelect,owner,onToggle,isSelected,limit=2,adding=false})=>{
 const modal=document.createElement('dialog');modal.className='sf-yt-picker';modal.setAttribute('aria-label','Elegir fuente de YouTube');
 modal.innerHTML=`<header><h2>Buscar en YouTube</h2><button class="btn" type="button" data-close>Volver</button></header><form><label>Buscar una canción<input name="query" maxlength="240" required style="width:100%;box-sizing:border-box"></label><button class="btn" type="submit">Buscar</button></form><p data-status role="status" aria-live="polite"></p><div data-results></div><p class="muted"><span data-help></span></p>`;
 const form=modal.querySelector('form'),status=modal.querySelector('[data-status]'),results=modal.querySelector('[data-results]');form.elements.query.value=query;
 modal.querySelector('[data-help]').textContent=adding?'Elige una fuente, afina la búsqueda si es necesario y pulsa Procesar y añadir.':onToggle?'Selecciona resultados para añadirlos a la lista. Pulsa de nuevo para quitar un enlace.':'Elige una fuente para completar el enlace. El reemplazo comenzará cuando pulses «Procesar y reemplazar».';
 let request=null;
 const close=()=>{if(modal.open)modal.close();};
 modal.querySelector('[data-close]').onclick=close;
 owner?.addEventListener('close',close,{once:true});
 modal.addEventListener('close',()=>{request?.abort();owner?.removeEventListener('close',close);modal.remove();},{once:true});
 async function search(){
  request?.abort();const active=new AbortController();request=active;
  const timer=setTimeout(()=>active.abort(),35000);
  const text=form.elements.query.value.trim();if(!text){clearTimeout(timer);return;}
  form.querySelector('button').disabled=true;status.textContent='Buscando hasta '+limit+' opciones…';results.replaceChildren();
  try{
   const body=new FormData();body.set('query',text);body.set('limit',String(limit));body.set('csrf_token',document.querySelector('meta[name="sf-csrf"]')?.content||document.querySelector('[name="csrf_token"]')?.value||'');
   const r=await fetch('api_youtube_search.php',{method:'POST',body,signal:active.signal});let data;try{data=await r.json();}catch(_){throw Error('YouTube devolvió una respuesta incompatible. Puedes volver y pegar un enlace manualmente.');}if(!data||typeof data!=='object'||(data.success&&!Array.isArray(data.items)))throw Error('La respuesta de búsqueda cambió. Puedes seguir pegando enlaces manualmente.');
   if(request!==active||!modal.isConnected)return;
   if(!r.ok||!data.success)throw Error(data.message||data.error||'No se pudo consultar YouTube. Puedes volver y pegar un enlace manualmente.');
   const items=Array.isArray(data.items)?data.items.slice(0,limit):[];
   status.textContent=items.length?'Elige la fuente que corresponda a tu canción.':(data.message||'No hay resultados. Puedes pegar el enlace manualmente.');
   for(const item of items){
    if(!/^[A-Za-z0-9_-]{11}$/.test(item.id))continue;
    const card=document.createElement('article');card.className='sf-yt-result';
    const image=document.createElement('img');image.src='https://i.ytimg.com/vi/'+item.id+'/mqdefault.jpg';image.alt='';image.loading='lazy';image.referrerPolicy='no-referrer';image.onerror=()=>image.remove();
    const details=document.createElement('div'),title=document.createElement('strong'),meta=document.createElement('p');title.textContent=item.title;
    const duration=Number(item.duration);meta.className='muted';meta.textContent=String(item.channel||'')+(duration>0?' · '+Math.floor(duration/60)+':'+String(duration%60).padStart(2,'0'):'');
    const choose=document.createElement('button');choose.type='button';choose.className='btn primary';choose.textContent='Usar esta fuente';
    const url='https://www.youtube.com/watch?v='+item.id;
    if(onToggle){let selected=!!isSelected?.(url);const render=()=>{choose.textContent=selected?'✓ Añadido · quitar':'Añadir a la lista';choose.setAttribute('aria-pressed',String(selected));};render();choose.onclick=()=>{const result=onToggle(url,!selected);if(result===false)return;selected=!selected;render();};}
    else choose.onclick=()=>{onSelect(url,item.title);close();};
    details.append(title,meta,choose);card.append(image,details);results.append(card);
   }
  }catch(error){if(request===active&&modal.isConnected)status.textContent=active.signal.aborted?'La búsqueda tardó demasiado. Puedes volver y pegar un enlace manualmente.':(error.name==='TypeError'?'No se pudo conectar con la búsqueda. Puedes volver y pegar un enlace manualmente.':error.message);}
  finally{clearTimeout(timer);if(request===active&&modal.isConnected)form.querySelector('button').disabled=false;}
 }
 form.onsubmit=e=>{e.preventDefault();search();};document.body.append(modal);modal.showModal();search();
};
})();
