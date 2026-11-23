(()=>{'use strict';const panel=document.getElementById('library-organize');if(!panel)return;const status=document.getElementById('library-organize-status'),upload=document.getElementById('loose-upload');let busy=false;let jobTimer=null;const LEGACY_KEY='sparrowfy-loose-jobs',account=document.querySelector('meta[name="sf-player-account"]')?.content||'session',JOB_KEY=LEGACY_KEY+':'+account;let progressDialog=null,uploadFailure='';const progressButton=document.createElement('button');progressButton.type='button';progressButton.className='btn';progressButton.textContent='Ver progreso';progressButton.hidden=true;progressButton.onclick=()=>progressDialog?.open();if(upload)upload.append(progressButton);function report(percent,text,file='',total=percent){status.textContent='';progressDialog?.update(percent,text,file,total);}
function rememberJobs(ids){try{if(ids.length){localStorage.setItem(JOB_KEY,JSON.stringify(ids));localStorage.setItem(JOB_KEY+':updated',String(Date.now()));}else{localStorage.removeItem(JOB_KEY);localStorage.removeItem(JOB_KEY+':updated');}}catch{}}
async function monitorJobs(ids,{restored=false}={}){clearTimeout(jobTimer);if(!ids.length||!panel.isConnected)return;try{
const results=await Promise.all(ids.map(async id=>{const r=await fetch('api_processing_status.php?id='+encodeURIComponent(id),{cache:'no-store'});const d=await r.json();if(!r.ok)throw Error(d.error||'No se pudo consultar la cola');return d.job;}));
if(!panel.isConnected)return;
for(const job of results)if(job.state==='done')window.sfLooseLive?.completed(job.id);
const pending=results.filter(j=>!['done','failed'].includes(j.state)),failed=results.filter(j=>j.state==='failed');
if(!pending.length){rememberJobs([]);busy=false;if(upload)upload.querySelector('[type="submit"]').disabled=false;
if(failed.length||uploadFailure){const text=uploadFailure||('No se pudieron completar '+failed.length+' pista(s): '+(failed[0].error||failed[0].label)+'. Las pistas completadas se conservaron.');status.textContent='';progressDialog?.fail(text);window.sfReplacementNotice?.(ids[0]+'-upload',text,true);}
else if(restored){status.textContent='';progressDialog?.destroy();progressDialog=null;progressButton.hidden=true;}
else{const text='Proceso terminado. Pistas añadidas a la biblioteca. Ya puedes cerrar esta ventana de progreso.';status.textContent='';progressDialog?.done(text);window.sfReplacementNotice?.(ids[0]+'-upload',text,false);}return;}
progressDialog?.serverOnly();const current=pending.find(j=>j.state==='running')||pending[0];const total=results.reduce((n,j)=>n+(['done','failed'].includes(j.state)?100:Number(j.progress)||0),0)/results.length;
progressDialog?.job(current.id);report(current.progress||0,'Servidor: '+current.stage+' · '+pending.length+' pista(s) pendiente(s). El servidor seguirá trabajando aunque recargues; consulta Ver progreso al volver.',current.label,total);
jobTimer=setTimeout(()=>monitorJobs(ids),2500);
}catch(e){report(0,e.message+' · Reintentando seguimiento…');jobTimer=setTimeout(()=>monitorJobs(ids),5000);}}

async function post(action,data={}){const form=new FormData();form.set("csrf_token",panel.dataset.csrf);form.set('action',action);for(const [key,value]of Object.entries(data))form.set(key,value);const r=await fetch('api_library.php',{method:'POST',body:form});const d=await r.json();if(!r.ok)throw Error(d.error||'No se pudo completar');return d;}
if(upload){const add=upload.querySelector('[data-add-youtube]');const sync=()=>{if(add)add.hidden=!!upload.elements.audio.files.length;};upload.elements.audio.addEventListener('change',sync);sync();} if(upload)upload.onsubmit=async e=>{e.preventDefault();if(busy)return;const files=[...upload.elements.audio.files];if(!files.length)return;busy=true;const button=upload.querySelector('[type="submit"]');button.disabled=true;const jobs=[];
uploadFailure='';progressDialog?.destroy();progressDialog=window.sfUploadProgress?.();progressButton.hidden=false;
try{report(0,'Preparando biblioteca…');const root=await post('prepare_upload');
// Barrido completo de coincidencias al inicio del lote: una sola decisión
// (sustituir / subir como nueva / omitir) para todas las pistas.
let decisions=new Map();
if(!window.sfCheckDuplicates)throw Error('No se cargó la comprobación de coincidencias. Recarga antes de importar.');
if(window.sfCheckDuplicates){report(0,'Revisando coincidencias del lote…');
const entries=await Promise.all(files.map(async(file,i)=>({key:String(i),name:file.name,duration:await window.sfMediaDuration?.(file)||0})));
decisions=await window.sfCheckDuplicates(entries,root.album_id,{});}
for(let i=0;i<files.length;i++){
const decision=decisions.get(String(i))||{mode:'new'};
if(decision.mode==='skip'){report(0,'Omitida pista '+(i+1)+' de '+files.length+' por coincidencia',files[i].name,(i+1)/files.length*100);continue;}
const replacement=decision.mode==='replace'&&decision.match?decision.match:null;
report(0,'Preparando pista '+(i+1)+' de '+files.length,files[i].name,i/files.length*100);
const r=await window.sfClientProcessing.upload(files[i],root.album_id,(p,text)=>report(p,text,files[i].name,(i+p/100)/files.length*100),replacement?{replaceTrackId:replacement.id}:{});const d=await r.json();if(!r.ok)throw Error(d.error);if(!d.queued)throw Error('La subida no quedó en cola');jobs.push(d.job_id);rememberJobs(jobs);}
upload.reset();if(jobs.length){monitorJobs(jobs);}else{busy=false;button.disabled=false;status.textContent='';progressDialog?.done('No se añadieron pistas nuevas. Ya puedes cerrar esta ventana.');}
}catch(e){if(/cancelada/i.test(e.message||'')){status.textContent='Importación cancelada. No se subió ninguna pista.';progressDialog?.destroy();progressDialog=null;progressButton.hidden=true;busy=false;button.disabled=false;return;}uploadFailure=e.message+' El lote se interrumpió; las pistas ya enviadas continuarán en el servidor.';status.textContent='';progressDialog?.fail(uploadFailure);busy=false;button.disabled=false;if(jobs.length){busy=true;button.disabled=true;monitorJobs(jobs);}}
};
async function move(row,target){if(busy)return;busy=true;status.textContent='Moviendo pista…';try{await post('move_track',{source:row.dataset.source,target,track_id:row.dataset.trackId});location.reload();}catch(e){status.textContent=e.message;}finally{busy=false;}}
async function restoreServerProgress(){let known=[];try{
// Old global keys mixed accounts and retained completed batches indefinitely.
// Recover active legacy jobs from the server instead of replaying that history.
localStorage.removeItem(LEGACY_KEY);localStorage.removeItem(LEGACY_KEY+':updated');
const updated=Number(localStorage.getItem(JOB_KEY+':updated')||0);known=JSON.parse(localStorage.getItem(JOB_KEY)||'[]');
if(!Array.isArray(known)||!Number.isFinite(updated)||Date.now()-updated>=86400000){known=[];rememberJobs([]);}
known=known.filter(id=>/^[a-f0-9]{32}$/.test(id));
}catch{}try{const r=await fetch('api_loose_jobs.php',{cache:'no-store'});if(r.ok){const d=await r.json();known=[...new Set([...known,...(Array.isArray(d.ids)?d.ids:[])])];}}catch{}if(busy||!known.length||!panel.isConnected)return;progressDialog=window.sfUploadProgress?.({autoOpen:false});progressButton.hidden=false;busy=true;if(upload)upload.querySelector('[type="submit"]').disabled=true;rememberJobs(known);monitorJobs(known,{restored:true});}
restoreServerProgress();
let dragged=null;function bindRows(){document.querySelectorAll('[data-track-id][data-source]:not([data-loose-bound])').forEach(row=>{row.dataset.looseBound='1';row.draggable=true;row.addEventListener('dragstart',e=>{if(busy){e.preventDefault();return;}dragged=row;e.dataTransfer.effectAllowed='move';e.dataTransfer.setData('application/x-sparrowfy-track',row.dataset.trackId);});row.addEventListener('dragend',()=>{dragged=null;document.querySelectorAll('.track-drop-active').forEach(c=>c.classList.remove('track-drop-active'));});const button=row.querySelector('[data-move-track]');if(button)button.onclick=()=>{const target=row.querySelector('select').value;if(target)move(row,target);};});
}bindRows();document.addEventListener('sf:loose-updated',bindRows);
document.querySelectorAll('.album-card[data-album-id]').forEach(card=>{card.addEventListener('dragover',e=>{if(!dragged||busy)return;e.preventDefault();e.dataTransfer.dropEffect='move';card.classList.add('track-drop-active');});card.addEventListener('dragleave',e=>{if(!card.contains(e.relatedTarget))card.classList.remove('track-drop-active');});card.addEventListener('drop',e=>{if(!dragged)return;e.preventDefault();card.classList.remove('track-drop-active');move(dragged,card.dataset.albumId);});});})();
