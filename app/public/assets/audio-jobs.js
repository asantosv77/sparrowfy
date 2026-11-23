(() => {
 'use strict';
 const endpoint='audio_edit_jobs.php';

 // Only the top-level page owns the floating notification. Album management
 // runs inside the shell iframe and used to create a second copy of the same
 // panel; embedded pages now forward watches/refreshes to the top window.
 if (window.self !== window.top) {
  const tell=(type,extra={})=>{try{window.top.postMessage({source:'sf-audio-jobs',type,...extra},location.origin);}catch(_){}};
  window.sfAudioJobsWatch=id=>{
   const value=String(id||'');
   if(!/^[a-f0-9]{32}$/.test(value))return;
   tell('watch',{id:value});
  };
  tell('refresh');
  return;
 }

 const SUCCESS_MS=6000;
 let activeId=null;let polling=null;let metadataOpen=false;
 let successId=null,successTimer=null,fadeTimer=null;
 const seenLive=new Set();

 const panel=document.createElement('aside');panel.id='sf-audio-jobs';panel.setAttribute('aria-live','polite');panel.setAttribute('role','status');
 panel.style.cssText='position:fixed;right:max(18px,env(safe-area-inset-right,0px));bottom:max(18px,env(safe-area-inset-bottom,0px));z-index:101;background:var(--surface,#20242b);color:var(--text,#fff);border:1px solid var(--border,#777);border-radius:14px;box-shadow:0 8px 30px #0005;padding:16px;max-width:360px;width:calc(100% - 36px);font:14px system-ui;display:none';
 panel.innerHTML='<strong>Procesamiento de audio</strong><button type="button" id="sf-job-close" style="float:right" aria-label="Ocultar">×</button><div id="sf-job-live"><p id="sf-job-stage">Esperando…</p><progress id="sf-job-progress" style="width:100%"></progress><small id="sf-job-dates"></small></div><p id="sf-job-success" hidden style="margin:8px 0 0;font-weight:600"></p><div id="sf-job-history" style="margin-top:8px;max-height:160px;overflow:auto"></div>';
 document.body.append(panel);
 const live=panel.querySelector('#sf-job-live'),stage=panel.querySelector('#sf-job-stage'),bar=panel.querySelector('#sf-job-progress'),dates=panel.querySelector('#sf-job-dates'),successEl=panel.querySelector('#sf-job-success'),history=panel.querySelector('#sf-job-history');
 panel.querySelector('#sf-job-close').onclick=()=>{panel.style.display='none'};

 async function request(url,options){const r=await fetch(url,{credentials:'same-origin',cache:'no-store',...options});const d=await r.json();if(!r.ok||!d.success){const error=Error(d.error||'Sin conexión');error.status=r.status;throw error}return d}
 const date=v=>v?new Date(v).toLocaleString():'—';

 function refreshPanel(){
  live.hidden=!activeId;
  successEl.hidden=!successId;
  const has=!!activeId||!!successId||history.childElementCount>0;
  panel.style.display=(!metadataOpen&&has)?'block':'none';
 }
 // The processing panel is hidden while the metadata editor is open and comes
 // back as soon as it closes, even if the album management overlay stays open.
 function setMetadataOpen(open){
  open=!!open;
  if(open===metadataOpen)return;
  metadataOpen=open;
  if(open){clearTimeout(successTimer);successTimer=null}
  else if(successId)scheduleSuccess();
  refreshPanel();
 }

 async function ackJob(id){
  try{
   const fd=new FormData();
   fd.set('csrf_token',document.querySelector('meta[name="sf-csrf"]')?.content||'');
   fd.append('action','ack');fd.append('id',id);
   await request(endpoint,{method:'POST',body:fd});
  }catch(_){}
 }

 function scheduleSuccess(){
  clearTimeout(successTimer);successTimer=null;
  if(!successId||metadataOpen)return;
  successTimer=setTimeout(fadeSuccess,SUCCESS_MS);
 }

 function clearSuccess(ack){
  const id=successId;
  clearTimeout(successTimer);clearTimeout(fadeTimer);
  successTimer=null;fadeTimer=null;successId=null;
  successEl.style.transition='';successEl.style.opacity='';successEl.hidden=true;
  if(ack&&id)ackJob(id);
 }

 // A success that was seen live does not need to be marked as read: it fades
 // out and acknowledges itself so it is not reported again on the next visit.
 function fadeSuccess(){
  clearTimeout(successTimer);successTimer=null;
  const id=successId;
  if(!id)return;
  successEl.style.transition='opacity .6s ease';
  successEl.style.opacity='0';
  clearTimeout(fadeTimer);
  fadeTimer=setTimeout(async()=>{
   successEl.style.transition='';successEl.style.opacity='';
   successId=null;
   await ackJob(id);
   await list();
   refreshPanel();
  },650);
 }

 function render(job){
  if(!job)return;
  activeId=job.id||activeId;
  live.hidden=false;successEl.hidden=true;
  stage.textContent=job.stage+(job.source_kind==='hls_reconstructed'?' · Fuente HLS '+(job.source_quality||''):'')+(job.error?' · '+job.error:'');
  if(job.progress===null||job.progress===undefined)bar.removeAttribute('value');else{bar.max=100;bar.value=job.progress}
  dates.textContent='Inicio: '+date(job.started_at||job.created_at)+' · Fin: '+date(job.finished_at);
  if(!metadataOpen)panel.style.display='block';
 }

 function finishSuccess(job){
  clearInterval(polling);polling=null;
  activeId=null;
  if(job.id)seenLive.add(job.id);
  successId=job.id||null;
  live.hidden=true;
  if(successId){
   successEl.hidden=false;
   successEl.style.transition='';successEl.style.opacity='';
   successEl.textContent='✓ '+(job.track_name||'Trabajo de audio')+' · '+(job.stage||'Completado');
  }else successEl.hidden=true;
  refreshPanel();
  scheduleSuccess();
  list();
 }

 async function tick(){
  const id=activeId;
  if(!id)return;
  try{
   const d=await request(endpoint+'?action=status&id='+encodeURIComponent(id));const job=d.job;
   if(job.state==='done'){finishSuccess(job);return}
   if(job.state==='failed'){
    clearInterval(polling);polling=null;activeId=null;
    live.hidden=true;successEl.hidden=true;refreshPanel();await list();return;
   }
   render(job);
  }catch(e){
   if(e.status===403||e.status===404){clearInterval(polling);polling=null;activeId=null;live.hidden=true;refreshPanel();list();return}
   stage.textContent='Conexión interrumpida. Se recuperará el progreso automáticamente.';live.hidden=false;if(!metadataOpen)panel.style.display='block';
  }
 }

 window.sfAudioJobsWatch=id=>{
  const value=String(id||'');
  if(!/^[a-f0-9]{32}$/.test(value))return;
  if(value===activeId)return;
  clearSuccess(true);
  activeId=value;clearInterval(polling);tick();polling=setInterval(tick,3000);
 };

 async function list(){
  try{
   const d=await request(endpoint+'?action=list');const jobs=d.jobs||[];
   const pending=jobs.find(j=>['running','queued'].includes(j.state));
   if(pending&&!activeId)window.sfAudioJobsWatch(pending.id);
   const finished=jobs.filter(j=>['done','failed'].includes(j.state)&&!j.notified_at&&!seenLive.has(j.id));
   history.replaceChildren();
   for(const j of finished){
    const item=document.createElement('div');item.style.cssText='border-top:1px solid #8885;padding:8px 0';
    item.textContent=(j.state==='done'?'✓ ':'⚠ ')+j.track_name+' · '+j.stage+' · Inicio '+date(j.started_at||j.created_at)+' · Fin '+date(j.finished_at)+(j.error?' · '+j.error:'');
    const b=document.createElement('button');b.type='button';b.textContent='Marcar leída';b.style.marginLeft='6px';
    b.onclick=async()=>{await ackJob(j.id);item.remove();refreshPanel()};
    item.append(b);history.append(item);
   }
   refreshPanel();
  }catch(e){refreshPanel()}
 }

 // The metadata editor can open inside this window or inside the management
 // iframe. Detect both and reconcile in case a close event is missed.
 function metadataOpenNow(){
  const has=doc=>{try{return !!doc?.querySelector('dialog[aria-label="Editar melodía"]')}catch(_){return false}};
  if(has(document))return true;
  const frame=document.getElementById('labConfigFrame');
  return !!(frame&&frame.contentDocument&&has(frame.contentDocument));
 }
 window.addEventListener('sf-metadata-editor',e=>setMetadataOpen(!!e.detail?.open));
 setInterval(()=>{const open=metadataOpenNow();if(open!==metadataOpen)setMetadataOpen(open)},1000);

 // Closing the album management panel also closes any editor inside it.
 (function watchManagement(){
  const attach=host=>{
   const sync=()=>{if(host.hidden&&metadataOpen)setMetadataOpen(false)};
   new MutationObserver(sync).observe(host,{attributes:true,attributeFilter:['hidden','class']});
   sync();
  };
  const host=document.getElementById('labConfigHost');
  if(host){attach(host);return}
  const observer=new MutationObserver(()=>{const found=document.getElementById('labConfigHost');if(found){observer.disconnect();attach(found)}});
  observer.observe(document.body,{childList:true,subtree:true});
 })();

 window.addEventListener('message',e=>{
  if(e.origin!==location.origin)return;
  const data=e.data;
  if(data?.source==='sf-metadata-editor'){setMetadataOpen(data.type==='open');return}
  if(data?.source!=='sf-audio-jobs')return;
  if(data.type==='watch')window.sfAudioJobsWatch(data.id);
  else if(data.type==='refresh')list();
 });

 list();window.addEventListener('online',()=>{if(activeId)tick();else list()});document.addEventListener('visibilitychange',()=>{if(!document.hidden){if(activeId)tick();else list()}});
})();
