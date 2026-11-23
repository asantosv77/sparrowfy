(()=>{
 'use strict';
 const panel=document.getElementById('loudnessProgress');if(!panel)return;
 const find=s=>panel.querySelector(s),ring=find('[role="progressbar"]'),fill=find('.fill');
 let timer=null,controller=null,busy=false,stopped=false,active=false,retry=false;
 const mode=document.getElementById('loudnessTimeoutMode'),minutes=document.getElementById('loudnessTimeoutMinutes');
 if(mode&&minutes&&mode.addEventListener){const update=()=>{minutes.disabled=mode.value!=='fixed';};mode.addEventListener('change',update);update();}
 function renderFailures(failures){
  const box=find('[data-progress-failures]');if(!box)return;
  const items=Array.isArray(failures)?failures:[],signature=JSON.stringify(items);
  if(box.dataset?.signature===signature)return;if(box.dataset)box.dataset.signature=signature;
  box.replaceChildren?.();box.hidden=!items.length;if(!items.length)return;
  const heading=document.createElement('h3');heading.textContent='Análisis fallidos: qué ocurrió y qué hacer';box.appendChild(heading);
  for(const item of items){
   const row=document.createElement('article'),name=document.createElement('strong'),reason=document.createElement('p'),action=document.createElement('p');
   name.textContent=String(item.label||'Pista');reason.textContent=String(item.reason||'No se pudo completar el análisis.');action.textContent=String(item.action||'Revisa la pista antes de volver a analizar.');row.appendChild(name);row.appendChild(reason);row.appendChild(action);box.appendChild(row);
  }
  if(items.some(item=>String(item.kind).startsWith('timeout'))){const link=document.createElement('a');link.className='btn';link.href='#loudnessTimeout';link.textContent='Ajustar time-out';box.appendChild(link);}
 }
 function render(p){
  retry=false;
  if(!p||typeof p.total!=='number')throw Error('Respuesta de progreso inválida');
  const pct=Math.max(0,Math.min(100,Number(p.percent)||0));active=!!p.active;
  ring.setAttribute('aria-valuenow',String(pct));fill.style.strokeDashoffset=String(339.292*(1-pct/100));find('[data-progress-percent]').textContent=Math.round(pct)+'%';
  find('[data-progress-summary]').textContent=!p.total?'No hay un lote de análisis registrado.':active?'Análisis en curso…':(p.failed||p.missing?'Lote terminado con incidencias.':'Análisis completado.');
  find('[data-progress-track]').textContent=p.current?.label||'';
  find('[data-progress-stage]').textContent=p.current?.stage||'';
  find('[data-progress-counts]').textContent=p.total?`${p.done} completadas · ${p.queued} en cola · ${p.running} en ejecución · ${p.failed} fallidas${p.missing?' · '+p.missing+' no disponibles':''} · Total: ${p.total}`:'';
  renderFailures(p.failures);
  find('[data-progress-error]').hidden=true;
 }
 function schedule(){clearTimeout(timer);if(!stopped&&!document.hidden&&(active||retry))timer=setTimeout(poll,3000);}
 async function poll(){
  if(stopped||document.hidden||busy)return;busy=true;controller=new AbortController();const timeout=setTimeout(()=>controller?.abort(),10000);
  try{const response=await fetch('api_loudness_progress.php',{credentials:'same-origin',cache:'no-store',signal:controller.signal});if(!response.ok)throw Error('No disponible');const data=await response.json();if(!data.success)throw Error('No disponible');if(!stopped)render(data.progress);}
  catch(e){retry=true;if(!stopped){const error=find('[data-progress-error]');error.hidden=false;error.textContent='No se pudo actualizar el progreso. Conservamos el último dato; esto no significa que el análisis se haya detenido.';}}
  finally{clearTimeout(timeout);controller=null;busy=false;schedule();}
 }
 find('[data-progress-refresh]').addEventListener('click',poll);
 document.addEventListener('visibilitychange',()=>{clearTimeout(timer);if(!document.hidden)poll();});
 window.addEventListener('pagehide',()=>{stopped=true;clearTimeout(timer);controller?.abort();});
 window.addEventListener('pageshow',()=>{if(stopped){stopped=false;poll();}});
 try{render(JSON.parse(document.getElementById('loudnessProgressInitial').textContent));}catch(e){active=true;}
 poll();
})();
