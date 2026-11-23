(()=>{'use strict';if(window.sfAudioDownload)return;window.sfAudioDownload=true;
let allowed=false;
async function api(url,options={}){const c=new AbortController(),t=setTimeout(()=>c.abort(),15000);try{const r=await fetch(url,{...options,signal:c.signal,cache:'no-store'});const j=await r.json();if(!r.ok)throw Error(j.error||'No se pudo consultar la descarga');return j;}finally{clearTimeout(t);}}
function decorate(){if(!allowed)return;for(const anchor of document.querySelectorAll('[data-sf-track]')){
 const id=anchor.dataset.sfTrack;if(!/^[a-f0-9]{16}$/.test(id||''))continue;
 const root=anchor.matches('.lab-track-buttons')?anchor:anchor.parentElement;if(!root)continue;
 if(root.querySelector('[data-audio-download="'+id+'"]'))continue;
 let menu=root.querySelector('details.sf-track-options'),panel=menu?.querySelector('.sf-track-options-panel');
 if(!panel){menu=document.createElement('details');menu.className='sf-track-options sf-audio-download-menu';const summary=document.createElement('summary');summary.textContent='⋮';summary.setAttribute('aria-label','Opciones de pista');panel=document.createElement('div');panel.className='sf-track-options-panel';menu.append(summary,panel);root.append(menu);}
 const b=document.createElement('button');b.type='button';b.className='btn';b.dataset.audioDownload=id;b.textContent='Descargar';panel.append(b);
}}
async function refreshPermission(){try{const p=await api('api_audio_download.php?action=permission');allowed=p.allowed===true;if(allowed)decorate();else document.querySelectorAll('[data-audio-download]').forEach(b=>b.remove());}catch{allowed=false;}}
let scheduled=false;new MutationObserver(()=>{if(scheduled||!allowed)return;scheduled=true;requestAnimationFrame(()=>{scheduled=false;decorate();});}).observe(document.body,{childList:true,subtree:true});
refreshPermission();document.addEventListener('visibilitychange',()=>{if(!document.hidden)refreshPermission();});
document.addEventListener('click',e=>{const btn=e.target.closest('[data-audio-download]');if(btn){e.preventDefault();btn.closest('details')?.removeAttribute('open');open(btn);return;}for(const menu of document.querySelectorAll('details.sf-audio-download-menu[open]'))if(!menu.contains(e.target))menu.open=false;});
function open(trigger){
 const id=trigger.dataset.audioDownload,d=document.createElement('dialog');d.className='sf-audio-download-dialog';
 const header=document.createElement('header'),h=document.createElement('h2'),close=document.createElement('button');h.textContent='Descargar pista';close.type='button';close.className='btn';close.textContent='Cerrar';header.append(h,close);
 const intro=document.createElement('p');intro.textContent='Elige el archivo que quieres obtener. Se usará el tren HLS de mayor calidad disponible.';
 const choices=document.createElement('fieldset'),legend=document.createElement('legend');legend.textContent='Formato de descarga';choices.append(legend);
 for(const [value,title,text] of [['m4a','M4A · máxima calidad disponible','Une los segmentos sin recodificar. Conserva la calidad almacenada; no es el archivo original previo a la conversión.'],['mp3','MP3 · mayor compatibilidad','Convierte desde el mejor tren HLS. Requiere más procesamiento y no aumenta la calidad de la fuente.']]){
  const label=document.createElement('label'),radio=document.createElement('input'),content=document.createElement('span'),strong=document.createElement('strong'),small=document.createElement('small');radio.type='radio';radio.name='download-format';radio.value=value;radio.checked=value==='m4a';strong.textContent=title;small.textContent=text;content.append(strong,small);label.append(radio,content);choices.append(label);
 }
 const start=document.createElement('button');start.className='btn primary';start.type='button';start.textContent='Preparar descarga';
 const status=document.createElement('p');status.setAttribute('role','status');status.setAttribute('aria-live','polite');
 const progress=document.createElement('progress');progress.max=100;progress.hidden=true;progress.setAttribute('aria-label','Preparación del archivo');
 const save=document.createElement('button');save.type='button';save.className='btn primary';save.textContent='Descargar archivo';save.hidden=true;
 d.append(header,intro,choices,start,progress,status,save);document.body.append(d);d.showModal();let job=null,timer=null,closed=false;
 close.onclick=()=>d.close();d.addEventListener('close',()=>{closed=true;clearTimeout(timer);d.remove();trigger.focus();});
 const fail=e=>{progress.hidden=true;status.textContent=e.name==='AbortError'?'La consulta tardó demasiado. Puedes volver a consultar sin duplicar la tarea.':e.message;start.disabled=false;start.textContent=job?'Consultar progreso':'Preparar descarga';};
 async function poll(){if(closed)return;try{const r=await api('api_audio_download.php?id='+encodeURIComponent(job));if(closed)return;
  if(r.state==='failed'||r.expired){job=null;choices.disabled=false;throw Error(r.error||'El archivo caducó; vuelve a prepararlo');}
  if(r.ready){progress.value=100;status.textContent='Archivo listo. Puedes descargarlo durante una hora.';save.hidden=false;start.hidden=true;return;}
  status.textContent=r.state==='queued'?'En cola del servidor. Puedes cerrar esta ventana y volver a solicitar la misma descarga para consultar su preparación.':r.stage+' · '+r.progress+'%';
  progress.hidden=false;if(r.progress>0)progress.value=r.progress;else progress.removeAttribute('value');timer=setTimeout(poll,2000);
 }catch(e){if(!closed)fail(e);}}
 start.onclick=async()=>{start.disabled=true;progress.hidden=false;progress.removeAttribute('value');status.textContent='Solicitando preparación…';try{
  if(!job){const body=new FormData();body.set('csrf_token',document.querySelector('meta[name="sf-csrf"]')?.content||'');body.set('track_id',id);body.set('format',choices.querySelector('input:checked').value);const r=await api('api_audio_download.php',{method:'POST',body});job=r.job_id;choices.disabled=true;}
  await poll();
 }catch(e){if(!closed)fail(e);}};
 save.onclick=async()=>{save.disabled=true;try{const r=await api('api_audio_download.php?id='+encodeURIComponent(job));if(!r.ready)throw Error('El archivo ya no está disponible; vuelve a prepararlo');let frame=document.getElementById('sf-audio-download-target');if(!frame){frame=document.createElement('iframe');frame.id='sf-audio-download-target';frame.title='Descarga de audio';frame.hidden=true;document.body.append(frame);}frame.src='api_audio_download.php?action=file&id='+encodeURIComponent(job);status.textContent='Descarga enviada al navegador.';}catch(e){status.textContent=e.message;}finally{save.disabled=false;}};
}
})();
