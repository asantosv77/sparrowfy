(()=>{'use strict';function sfEditorState(open){try{window.dispatchEvent(new CustomEvent('sf-metadata-editor',{detail:{open:!!open}}));}catch(_){}if(window.self!==window.top){try{window.parent.postMessage({source:'sf-metadata-editor',type:open?'open':'close'},location.origin);}catch(_){}}}const preview=document.getElementById('preview');if(preview){const identify=document.getElementById('identifyAudio');if(identify)identify.onclick=async()=>{const status=document.getElementById('identifyStatus'),results=document.getElementById('identifyResults');identify.disabled=true;results.replaceChildren();status.textContent='Analizando la huella acústica y consultando AcoustID…';try{const token=document.querySelector('[name="csrf_token"]').value;const r=await fetch('api_identify_track.php',{method:'POST',headers:{'X-CSRF-Token':token,'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({track_id:identify.dataset.track})});const data=await r.json();if(!r.ok)throw Error(data.error||'Falló la identificación');if(data.skipped){status.textContent=data.warning;return;}status.textContent=data.candidates.length?'Selecciona la grabación correcta para rellenar los metadatos. Después guarda los cambios.':'No se encontró una coincidencia suficientemente clara.';for(const c of data.candidates){const row=document.createElement('p'),button=document.createElement('button');button.type='button';button.className='btn';button.textContent='Usar: '+c.artist+' — '+c.title+(c.album?' · '+c.album:'');button.onclick=()=>{for(const k of ['title','artist','album']){const field=document.getElementById('edit-'+k);if(c[k])field.value=c[k]}status.textContent='Metadatos propuestos cargados. Revisa la portada y pulsa Guardar cambios.';document.getElementById('findCover').click()};row.append(button);results.append(row)}}catch(e){status.textContent=e.message}finally{identify.disabled=false}};
const fileInput=document.getElementById('coverImageFile'),fileStatus=document.getElementById('coverFileStatus');let selectedCoverUrl=null;
function resetCoverFile(){if(fileInput)fileInput.value='';if(selectedCoverUrl){if(preview.getAttribute('src')===selectedCoverUrl)preview.removeAttribute('src');URL.revokeObjectURL(selectedCoverUrl);selectedCoverUrl=null;}if(fileStatus)fileStatus.textContent='JPG, PNG, WebP o GIF · máximo 8 MiB.';}
document.getElementById('chooseCoverFile')?.addEventListener('click',()=>fileInput?.click());
fileInput?.addEventListener('change',()=>{const file=fileInput.files[0];if(!file)return;if(file.size>8*1024*1024||!['image/jpeg','image/png','image/webp','image/gif'].includes(file.type)){resetCoverFile();if(input.value){preview.src=input.value;preview.hidden=false;}else preview.hidden=true;fileStatus.textContent='Selecciona JPG, PNG, WebP o GIF de hasta 8 MiB.';return;}const previousCoverUrl=selectedCoverUrl;selectedCoverUrl=URL.createObjectURL(file);preview.src=selectedCoverUrl;if(previousCoverUrl)URL.revokeObjectURL(previousCoverUrl);preview.hidden=false;fileStatus.textContent='Imagen seleccionada: '+file.name+'. Pulsa Guardar metadatos para aplicarla.';});
window.addEventListener('pagehide',event=>{if(event.persisted)return;if(selectedCoverUrl){if(preview.getAttribute('src')===selectedCoverUrl)preview.removeAttribute('src');URL.revokeObjectURL(selectedCoverUrl);selectedCoverUrl=null;}});
const input=document.getElementById('artwork'),status=document.getElementById('coverStatus'),grid=document.getElementById('covers');const coverDialog=document.getElementById('coverPickerDialog');document.getElementById('closeCoverPicker').onclick=()=>coverDialog.close();coverDialog.addEventListener('click',e=>{if(e.target===coverDialog){const r=coverDialog.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)coverDialog.close()}});document.getElementById('closeEdit').onclick=()=>{if(parent!==window)parent.postMessage({source:'sf-track-edit',type:'close'},location.origin);else location.href='index.php?view=library#loose-tracks'};document.getElementById('clearCover').onclick=()=>{resetCoverFile();input.value='';preview.hidden=true};let coverLookup=null;
function cancelCoverLookup(){const lookup=coverLookup;if(!lookup)return;coverLookup=null;lookup.controller.abort();clearTimeout(lookup.timer);lookup.loading?.stop();lookup.button.disabled=false;}
coverDialog.addEventListener('close',cancelCoverLookup);window.addEventListener('pagehide',cancelCoverLookup);
async function findTrackCovers(e){
 const b=e.currentTarget;if(coverLookup)return;
 const form=document.getElementById('trackMetadataForm');
 const value=key=>String(form?.elements.namedItem(key)?.value??'').trim();
 const title=value('title')||value('name'),artist=value('artist'),album=value('album');
 if(!title&&!artist&&!album){
  fileStatus.textContent='Para buscar una portada, escribe el título, el artista o el álbum. También puedes usar Elegir imagen.';
  form?.elements.namedItem('title')?.focus();return;
 }
 b.disabled=true;if(!coverDialog.open)coverDialog.showModal();status.textContent='Buscando coincidencias…';grid.replaceChildren();
 const lookup={controller:new AbortController(),button:b,timer:null,loading:window.sfLoadingSkeletons?.start(grid,{kind:'covers',count:4,label:'Buscando portadas de la pista…'})};coverLookup=lookup;lookup.timer=setTimeout(()=>lookup.controller.abort(),25000);
 try{
  const q=new URLSearchParams({mode:'search',title,artist,album});
  const r=await fetch('api_cover.php?'+q,{signal:lookup.controller.signal,credentials:'same-origin'});const d=await r.json();if(!r.ok){const error=new Error(r.status===400?'Revisa el título, el artista o el álbum antes de buscar la portada.':'No se pudieron buscar las portadas. Inténtalo de nuevo.');error.coverSearch=true;throw error;}if(coverLookup!==lookup||!coverDialog.open)return;
  lookup.loading?.stop();grid.replaceChildren();let count=0;
  for(const c of d.candidates||[]){let u;try{u=new URL(c.cover);}catch{continue;}if(u.protocol!=='https:'||!/(^|\.)mzstatic\.com$/i.test(u.hostname))continue;count++;
   const btn=document.createElement('button');btn.type='button';const img=document.createElement('img');img.src=c.cover;img.alt=c.album||c.title||'Portada';const label=document.createElement('span');label.textContent=c.album||c.title||'Seleccionar';btn.append(img,label);
   btn.onclick=()=>{resetCoverFile();input.value=c.cover;preview.src=c.cover;preview.hidden=false;status.textContent='Portada seleccionada. Guarda los cambios.';coverDialog.close()};grid.append(btn);
  }
  status.textContent=count?'Selecciona la portada correcta.':'No se encontraron portadas de iTunes.';
 }catch(err){if(coverLookup===lookup&&coverDialog.open){grid.replaceChildren();status.textContent=err.name==='AbortError'?'La búsqueda tardó demasiado. Inténtalo de nuevo.':err.coverSearch?err.message:'No se pudieron buscar las portadas. Inténtalo de nuevo.';}}
 finally{clearTimeout(lookup.timer);lookup.loading?.stop();if(coverLookup===lookup){coverLookup=null;b.disabled=false;}}
}
document.getElementById('findCover').onclick=findTrackCovers;return}

let modal=null,frame=null,trigger=null,dirty=false;
function refresh(){if(window.sfRefreshAlbumTracks){window.sfRefreshAlbumTracks({appendOnly:false,trackId:trigger?.dataset.editTrack||trigger?.dataset.editLoose||null}).catch(error=>alert(error.message));return;}if(window.sfNavigate&&/\/(index\.php)?$/.test(location.pathname))window.sfNavigate(location.href).catch(()=>location.reload());else location.reload();}
document.addEventListener('click',e=>{
 const b=e.target.closest('[data-edit-loose],[data-edit-track]');if(!b)return;e.preventDefault();if(modal?.open)return;
 const id=b.dataset.editTrack||b.dataset.editLoose;if(!/^[a-f0-9]{16}$/.test(id))return;
 b.closest('details')?.removeAttribute('open');trigger=b;dirty=false;modal=document.createElement('dialog');modal.setAttribute('aria-label','Editar melodía');
 modal.style.cssText='width:min(1120px,96vw);max-width:96vw;height:92dvh;max-height:94dvh;padding:0;border:1px solid var(--border,#315563);border-radius:16px;background:var(--bg,#0b2028);overflow:hidden';
 frame=document.createElement('iframe');frame.title='Editar melodía: metadatos, portada y audio';frame.style.cssText='display:block;width:100%;height:100%;border:0';const source='track_edit.php?track='+encodeURIComponent(id)+'&editor='+Date.now();modal.append(frame);document.body.append(modal);
 modal.addEventListener('close',()=>{const changed=dirty;modal.remove();modal=null;frame=null;trigger?.focus();sfEditorState(false);if(changed)refresh();});modal.showModal();sfEditorState(true);
 const openedModal=modal,openedFrame=frame;
 let attempts=0,loadTimer;
 const loadEditor=()=>{
  if(!openedModal.open)return;
  openedFrame.src=source+'&attempt='+(++attempts);
  clearTimeout(loadTimer);
  loadTimer=setTimeout(()=>{
   if(!openedModal.open)return;
   try{if(openedFrame.contentDocument?.querySelector('#trackMetadataForm'))return;}catch(_){}
   if(attempts<3)loadEditor();
  },3500);
 };
 openedModal.addEventListener('close',()=>clearTimeout(loadTimer),{once:true});
 loadEditor();
});
window.addEventListener('pagehide',()=>{if(modal)sfEditorState(false);});
window.addEventListener('message',e=>{
 if(e.origin!==location.origin||e.source!==frame?.contentWindow||e.data?.source!=='sf-track-edit')return;
 if(e.data.type==='audio-queued'&&/^[a-f0-9]{32}$/.test(e.data.id||'')){window.sfAudioJobsWatch?.(e.data.id);return;}
 if(e.data.type==='audio-updated'){dirty=true;return;}
 if(e.data.type==='saved'){window.sfSaveFeedback?.notice('Metadatos y portada guardados.');dirty=true;modal.close();}
 else if(e.data.type==='close')modal.close();
});
})();
