(()=>{'use strict';
 try{document.documentElement.dataset.theme=parent.document.documentElement.dataset.theme||'dark-teal';}catch{}
 const button=document.getElementById('queueTrackAudioEdit'),form=document.getElementById('trackMetadataForm'),status=document.getElementById('trackAudioEditStatus'),progress=document.getElementById('trackAudioEditProgress'),estimate=document.getElementById('audioDurationEstimate');
 const keys=['trim_start','trim_end','fade_in','fade_out'];let timer=null,job=null,polling=false;
 const values=()=>Object.fromEntries(keys.map(k=>[k,Number(form.elements.namedItem(k).value)]));
 const fmt=n=>{const c=Math.max(0,Math.round((Number.isFinite(n)?n:0)*100));return Math.floor(c/6000)+':'+String(Math.floor(c/100)%60).padStart(2,'0')+'.'+String(c%100).padStart(2,'0')};
 function update(){const v=values(),duration=Number(button.dataset.duration)||0,remaining=duration-v.trim_start-v.trim_end;estimate.textContent=duration?'Duración resultante estimada: '+fmt(remaining):'';}
 keys.forEach(k=>form.elements.namedItem(k).addEventListener('input',update));update();
 async function request(url,options={}){const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),20000);try{const r=await fetch(url,{credentials:'same-origin',cache:'no-store',...options,signal:controller.signal}),d=await r.json();if(!r.ok||d.success===false)throw Error(d.error||'No se pudo completar la solicitud.');return d;}finally{clearTimeout(timeout);}}
 async function refreshSource(){try{const url=new URL(location.href);url.searchParams.set('source','1');const d=await request(url.href);document.dispatchEvent(new Event('sf:preview-stop'));document.getElementById('metadataPreviewHost').dataset.source=d.source;button.dataset.duration=String(d.duration);update();}catch{status.textContent+=' Vuelve a abrir el editor para escuchar la versión actualizada.';}}
 async function poll(){if(!job||polling)return;polling=true;try{const d=await request('audio_edit_jobs.php?action=status&id='+encodeURIComponent(job)),j=d.job;
  if(j.state==='failed'){clearInterval(timer);timer=null;job=null;button.disabled=false;progress.hidden=true;progress.removeAttribute('value');status.textContent='No se pudo editar el audio: '+(j.error||j.stage);return;}
  status.textContent=j.stage;progress.hidden=false;if(j.progress==null)progress.removeAttribute('value');else progress.value=Number(j.progress);
  if(j.state==='done'){clearInterval(timer);timer=null;job=null;progress.value=100;status.textContent='Audio actualizado. Los metadatos del formulario se conservan.';await refreshSource();parent.postMessage({source:'sf-track-edit',type:'audio-updated'},location.origin);}
 }catch(e){status.textContent='No se pudo consultar el progreso. La tarea continúa en el servidor; volveremos a intentarlo.';}finally{polling=false;}}
 button.addEventListener('click',async()=>{if(job)return;const v=values(),duration=Number(button.dataset.duration)||0,remaining=duration-v.trim_start-v.trim_end;
  if(Object.values(v).some(n=>!Number.isFinite(n)||n<0||n>10800)||Object.values(v).every(n=>n===0)){status.textContent='Indica al menos un recorte o fundido válido.';return;}
  if(duration&&(remaining<=.25||v.fade_in>remaining||v.fade_out>remaining)){status.textContent='El recorte debe dejar audio y cada fundido debe caber en la duración resultante.';return;}
  button.disabled=true;status.textContent='Comprobando el audio y enviando la edición…';
  try{const fd=new FormData();fd.set('csrf_token',form.elements.namedItem('csrf_token').value);fd.set('action','enqueue');fd.set('album_id',button.dataset.album);fd.set('track_id',button.dataset.track);for(const[k,n]of Object.entries(v))fd.set(k,String(n));const d=await request('audio_edit_jobs.php',{method:'POST',body:fd});job=d.id;status.textContent='Edición en cola. Puedes cerrar esta ventana.';parent.postMessage({source:'sf-track-edit',type:'audio-queued',id:job},location.origin);poll();timer=setInterval(poll,2500);}catch(e){status.textContent=e.message;button.disabled=false;}
 });
 window.addEventListener('pagehide',()=>clearInterval(timer));window.addEventListener('online',poll);
 // Tooltips support mouse, keyboard focus and touch, without submitting the form.
 let tip=null,owner=null;
 function hideTip(){if(tip)tip.hidden=true;owner?.removeAttribute('aria-describedby');owner=null;}
 function showTip(el){hideTip();owner=el;if(!tip){tip=document.createElement('div');tip.className='editor-tooltip';tip.id='editorTooltip';tip.setAttribute('role','tooltip');document.body.append(tip);}tip.textContent=el.dataset.editorHelp;tip.hidden=false;el.setAttribute('aria-describedby',tip.id);const r=el.getBoundingClientRect(),b=tip.getBoundingClientRect();tip.style.left=Math.max(12,Math.min(r.left,innerWidth-b.width-12))+'px';tip.style.top=Math.max(8,Math.min(r.bottom+7,innerHeight-b.height-12))+'px';}
 document.querySelectorAll('[data-editor-help]').forEach(el=>{el.addEventListener('mouseenter',()=>showTip(el));el.addEventListener('mouseleave',hideTip);el.addEventListener('focus',()=>showTip(el));el.addEventListener('blur',hideTip);});
 document.addEventListener('click',e=>{const el=e.target.closest('[data-editor-help]');if(el){e.preventDefault();showTip(el);}else hideTip();});document.addEventListener('keydown',e=>{if(e.key==='Escape'&&owner){e.preventDefault();e.stopPropagation();hideTip();}});window.addEventListener('scroll',hideTip,{passive:true});
})();
