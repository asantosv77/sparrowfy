(()=>{'use strict';
const csrf=()=>document.querySelector('meta[name="sf-csrf"]')?.content||'',account=document.querySelector('meta[name="sf-player-account"]')?.content||'';
const key=id=>'sf-album-discovery:'+account+':'+id;
const node=(tag,text,cls)=>{const n=document.createElement(tag);if(text)n.textContent=text;if(cls)n.className=cls;return n;};
const time=s=>s>0?Math.floor(s/60)+':'+String(Math.round(s)%60).padStart(2,'0'):'Duración no indicada';
const currentId=new URL(location.href).searchParams.get('id');
let discoverySerial=0;
let pageWait=null,pageWaitOverflow='';
function showPageWait(message,host){
 if(pageWait)return;
 pageWait=document.createElement('div');
 pageWait.className='sf-page-wait';pageWait.setAttribute('role','status');pageWait.setAttribute('aria-live','polite');
 pageWait.innerHTML='<div class="sf-page-wait__card"><p class="sf-page-wait__label"></p><span class="sf-page-wait__bar sf-page-wait__bar--title" aria-hidden="true"></span><span class="sf-page-wait__bar" aria-hidden="true"></span><span class="sf-page-wait__bar sf-page-wait__bar--short" aria-hidden="true"></span><p class="sf-page-wait__hint">Esto puede tardar unos segundos. No cierres esta ventana.</p></div>';
 pageWait.querySelector('.sf-page-wait__label').textContent=message||'Preparando pistas…';
 pageWaitOverflow=document.body.style.overflow;document.body.style.overflow='hidden';
 (host||document.body).append(pageWait);
}
function hidePageWait(){
 if(!pageWait)return;
 pageWait.remove();pageWait=null;document.body.style.overflow=pageWaitOverflow||'';
}
async function runSourceQueue(items,search,shouldStop,onComplete,limit=3){
 let next=0;
 async function worker(){while(!shouldStop()){const index=next++;if(index>=items.length)return;await search(items[index]);onComplete();}}
 await Promise.all(Array.from({length:Math.min(limit,items.length)},worker));
}
function mornyePreparationState(rows){
 const marked=rows.filter(row=>row.check.checked),urls=marked.map(row=>row.select.value),pending=urls.filter(url=>!/^https:\/\/open\.spotify\.com\/track\/[A-Za-z0-9]{22}$/.test(url||'')).length,duplicate=new Set(urls.filter(Boolean)).size!==urls.filter(Boolean).length;
 return {marked:marked.length,pending,duplicate,ready:marked.length>0&&pending===0&&!duplicate};
}
function open(container=null,initial=null){
 const inline=!!container;const headingId='albumDiscoveryTitle-'+(++discoverySerial);
 const modal=document.createElement(inline?'div':'dialog');modal.className='sf-album-discovery'+(inline?' sf-album-discovery-inline':'');modal.setAttribute('aria-labelledby','albumDiscoveryTitle');
 modal.innerHTML='<header><h2 id="albumDiscoveryTitle">Buscar un álbum y agregarlo</h2><button type="button" class="btn" data-close>Cerrar</button></header><p class="muted" data-intro>Elige una edición, revisa sus canciones y confirma las fuentes. Los enlaces pasarán al importador habitual.</p><fieldset data-destination hidden><legend>¿Dónde agregar estas pistas?</legend><label><input type="radio" name="sf-album-destination" value="current" checked> Añadir al álbum actual: <strong data-current-album></strong></label><label><input type="radio" name="sf-album-destination" value="new"> Crear un álbum nuevo con los datos de la edición</label></fieldset><form data-search><div class="sf-album-fields"><label>Artista *<input name="artist" required maxlength="160"></label><label>Álbum *<input name="title" required maxlength="160"></label><label>Año<input name="year" inputmode="numeric" pattern="[12][0-9]{3}" maxlength="4"></label><label>Catálogo<select name="provider"><option value="musicbrainz">MusicBrainz</option><option value="itunes">iTunes</option></select></label></div><button class="btn primary" type="submit">Buscar coincidencias</button></form><p data-status role="status" aria-live="polite"></p><progress data-progress hidden max="100"></progress><div data-releases></div><section data-edition hidden></section><footer hidden><div><label><input type="checkbox" data-cover> Usar portada de esta edición</label><br><label title="Comprueba los enlaces de YouTube y obtiene sus nombres. Después podrás revisarlos, salvo que actives Importar sin revisar."><input type="checkbox" data-process checked> Validar enlaces y obtener nombres automáticamente</label><br><label title="Inicia la descarga con los nombres preparados, sin detenerse en el panel de revisión. Requiere fuentes seleccionadas y válidas."><input type="checkbox" data-auto-import> Importar sin revisar (omite la edición de nombres)</label><p class="muted" data-destination-note></p></div><button type="button" class="btn primary" data-use>Llevar enlaces al álbum</button></footer>';
 modal.querySelector('h2').id=headingId;modal.setAttribute('aria-labelledby',headingId);for(const radio of modal.querySelectorAll('input[name="sf-album-destination"]'))radio.name=headingId+'-destination';
 const form=modal.querySelector('form'),status=modal.querySelector('[data-status]'),progress=modal.querySelector('progress'),results=modal.querySelector('[data-releases]'),edition=modal.querySelector('[data-edition]'),footer=modal.querySelector('footer'),destination=modal.querySelector('[data-destination]'),destinationNote=modal.querySelector('[data-destination-note]');
 const submitStatus=node('p',null,'sf-album-submit-status');submitStatus.setAttribute('role','status');submitStatus.setAttribute('aria-live','polite');submitStatus.dataset.submitStatus='';footer.querySelector('div').append(submitStatus);
 const report=text=>{status.textContent=text;submitStatus.textContent=text;};
 const currentAlbum=document.querySelector('#albumMetaCard [name="title"]')?.value?.trim()||document.getElementById('newTitle')?.value?.trim()||'este álbum';
 const wantsNewAlbum=()=>!destination.hidden&&!!destination.querySelector('input[value="new"]:checked');
 function updateDestination(){
  const use=modal.querySelector('[data-use]');if(isMornye()){use.textContent=modal.querySelector('[data-auto-import]').checked?'Descargar e importar seleccionadas desde Mornye':'Preparar seleccionadas en el importador de Mornye';return;}
  if(destination.hidden){destinationNote.hidden=true;use.textContent=currentId?'Llevar enlaces al álbum':'Crear álbum y preparar pistas';return;}
  const create=wantsNewAlbum();use.textContent=create?'Crear álbum nuevo y preparar pistas':'Llevar enlaces al álbum actual';
  destinationNote.hidden=false;destinationNote.textContent=create?'Se creará un álbum nuevo con los datos de la edición y las pistas elegidas.':'Se agregarán las pistas elegidas al álbum actual «'+currentAlbum+'».';
 }
 modal.querySelector('[data-close]').hidden=inline;
 if(inline){
  modal.querySelector('h2').textContent='Buscar álbum e incorporar pistas';
  modal.querySelector('[data-intro]').textContent='Estás en «'+currentAlbum+'». Elige si las pistas se agregan a este álbum o si se crea uno nuevo.';
  destination.hidden=false;modal.querySelector('[data-current-album]').textContent=currentAlbum;
 }
 if(!inline&&currentId){destination.hidden=false;modal.querySelector('[data-current-album]').textContent=currentAlbum;if(initial?.create)destination.querySelector('input[value="new"]').checked=true;}
 const sourceLabel=node('label','Buscar canciones en '),sourceSelect=node('select');sourceSelect.add(new Option('YouTube','youtube'));sourceSelect.add(new Option('Mornye','mornye'));sourceSelect.value=initial?.streamingSource||'youtube';sourceLabel.append(sourceSelect);form.prepend(sourceLabel);const isMornye=()=>sourceSelect.value==='mornye';sourceSelect.onchange=()=>{syncAutoImport();updateDestination();selected=null;rows=[];edition.replaceChildren();footer.hidden=true;status.textContent='Fuente cambiada. Elige de nuevo una edición.';syncPreparation();};
 const coverChoice=modal.querySelector('[data-cover]');coverChoice.checked=!!initial?.create||!currentId;
 const processChoice=modal.querySelector('[data-process]'),autoImportChoice=modal.querySelector('[data-auto-import]');
 const syncAutoImport=()=>{processChoice.closest('label').style.display=isMornye()?'none':'';autoImportChoice.closest('label').style.display='';autoImportChoice.disabled=!isMornye()&&!processChoice.checked;if(autoImportChoice.disabled)autoImportChoice.checked=false;};
 processChoice.addEventListener('change',syncAutoImport);syncAutoImport();autoImportChoice.addEventListener('change',()=>{updateDestination();syncPreparation();});
 destination.addEventListener('change',()=>{coverChoice.checked=wantsNewAlbum();updateDestination();});
 updateDestination();
 form.elements.artist.value=document.getElementById('newArtist')?.value||document.querySelector('#albumMetaCard [name="artist"]')?.value||'';
 form.elements.title.value=document.getElementById('newTitle')?.value||document.querySelector('#albumMetaCard [name="title"]')?.value||'';
 if(initial){for(const field of ['artist','title','year','provider'])if(initial[field]!=null)form.elements[field].value=initial[field];}
 let selected=null,rows=[],busy=false,request=null,stopped=false,destinationId=currentId;
 const requests=new Set();
 const abortRequests=()=>{request?.abort();for(const active of requests)active.abort();};
 function syncPreparation(){
  if(!isMornye())return;const state=mornyePreparationState(rows),use=modal.querySelector('[data-use]'),find=edition.querySelector('[data-find-sources]');
  use.disabled=busy||!state.ready;use.classList.toggle('primary',state.ready);find?.classList.toggle('primary',!state.ready);use.title=state.ready?(autoImportChoice.checked?'Descargar e importar directamente las fuentes seleccionadas':'Pasar las fuentes seleccionadas al importador para revisar y confirmar'):'Primero busca y elige una fuente válida para cada canción marcada';
  if(!busy&&rows.length){submitStatus.textContent=state.duplicate?'Hay canciones marcadas con el mismo enlace. Elige fuentes diferentes antes de preparar.':state.pending?state.pending+' canción(es) marcada(s) sin fuente válida. Usa Buscar fuentes o Buscar / cambiar.':state.ready?state.marked+' canción(es) listas. Siguiente paso: '+(autoImportChoice.checked?'Descargar e importar seleccionadas desde Mornye.':'Preparar seleccionadas en el importador de Mornye.'):'Siguiente paso: Buscar fuentes de las canciones. Después marca las que quieras preparar.';}
 }
 function work(on,text){busy=on;sourceSelect.disabled=on;form.querySelector('button').disabled=on;modal.querySelector('[data-use]').disabled=on;for(const el of edition.querySelectorAll('button,select,input'))el.disabled=on;if(text)status.textContent=text;if(!on)syncPreparation();}
 async function post(url,values,{signal,timeoutMs=40000}={}){
  if(stopped)throw Error('Cancelado');const body=new FormData();body.set('csrf_token',csrf());for(const [k,v]of Object.entries(values))body.set(k,String(v));
  const active=new AbortController(),abort=()=>active.abort();requests.add(active);if(signal?.aborted)abort();signal?.addEventListener('abort',abort,{once:true});const timer=setTimeout(abort,timeoutMs);
  try{const r=await fetch(url,{method:'POST',body,signal:active.signal,credentials:'same-origin'});const d=await r.json();if(stopped)throw Error('Cancelado');if(!r.ok||d.error||d.success===false){const e=Error(d.error||d.message||'No se pudo consultar el servicio');e.status=r.status;e.retryAfter=Math.max(1,Math.min(5,Number(r.headers.get('Retry-After'))||2));throw e;}return d;}
  finally{clearTimeout(timer);requests.delete(active);signal?.removeEventListener('abort',abort);}
 }
 const close=()=>{if(busy&&!confirm('Se cancelará la búsqueda pendiente. Las pistas aún no se han importado. ¿Cerrar?'))return;modal.close();};
 modal.querySelector('[data-close]').onclick=close;modal.addEventListener('cancel',e=>{e.preventDefault();close();});modal.addEventListener('close',()=>{stopped=true;abortRequests();modal.remove();},{once:true});
 function chosen(){return rows.filter(r=>r.check.checked&&r.select.value).map(r=>({url:r.select.value,title:r.track.title,artist:r.track.artist,filename:(r.name?.value||'').trim()||r.track.artist+' - '+r.track.title}));}
 function pick(row,item){if(!/^[A-Za-z0-9_-]{11}$/.test(item.id||''))return;const url='https://www.youtube.com/watch?v='+item.id;let opt=[...row.select.options].find(o=>o.value===url);if(!opt){opt=new Option(item.title||row.track.title,url);row.select.add(opt);}row.select.value=url;row.check.checked=true;}
 async function selectRelease(item){if(busy)return;work(true,'Obteniendo la lista de pistas…');results.replaceChildren();edition.replaceChildren();edition.hidden=false;footer.hidden=true;const loading=window.sfLoadingSkeletons?.start(edition,{kind:'tracks',count:5,label:'Cargando pistas de la edición…'});try{const d=await post('api_album_discovery.php',{action:'release',provider:item.provider,id:item.id});selected=d.release;if(!Array.isArray(selected?.tracks))throw Error('El catálogo no devolvió una lista de pistas válida');rows=[];loading?.stop();edition.replaceChildren();edition.hidden=false;
 const heading=node('div',null,'sf-album-selected');if(selected.cover){const img=node('img');img.src=selected.cover;img.alt='Portada de '+selected.title;img.referrerPolicy='no-referrer';img.onerror=()=>img.remove();heading.append(img);}heading.append(node('h3',selected.artist+' — '+selected.title),node('p',selected.date+' · '+selected.tracks.length+' pistas'));edition.append(heading);
 const preference=node('label');preference.hidden=isMornye();if(isMornye())preference.style.display='none';preference.innerHTML='<input type="checkbox" data-lyrics checked> Preferir resultados con letra / lyrics';edition.append(preference);
 const find=node('button','Buscar fuentes de las canciones','btn');find.type='button';find.dataset.findSources='';edition.append(find);const labels=node('div',null,'sf-album-track-labels');labels.setAttribute('aria-hidden','true');for(const text of ['','Melodía',isMornye()?'Fuente de Mornye':'Fuente de YouTube','Nombre al importar','Acción'])labels.append(node('span',text));edition.append(labels);const list=node('div',null,'sf-album-tracklist');edition.append(list);
 for(const track of selected.tracks){
  const wrap=node('article',null,'sf-album-track');
  const check=node('input');check.type='checkbox';check.setAttribute('aria-label','Incluir '+track.title);check.addEventListener('change',syncPreparation);
  const title=node('div',null,'sf-album-track-title');title.append(node('strong',track.disc+'.'+track.position+' · '+track.title),node('small',track.artist+' · '+time(track.duration)));
  const select=node('select');select.setAttribute('aria-label','Fuente de '+track.title);select.add(new Option('Sin fuente seleccionada',''));
  const manual=node('button','Buscar / cambiar','btn');manual.type='button';
  const hint=node('small','Pendiente','muted');
  const name=node('input');name.type='text';name.maxLength=160;name.value=(track.artist+' - '+track.title).slice(0,160);name.setAttribute('aria-label','Nombre final de '+track.title);
  const nameLabel=node('label',null,'sf-album-name');const caption=node('span','Nombre de la pista','sf-album-field-caption');nameLabel.append(caption,name);
  const slot=node('span',null,'sf-album-source-slot'),skeleton=node('span',null,'sf-album-source-skeleton');skeleton.hidden=true;skeleton.setAttribute('aria-hidden','true');slot.append(check,skeleton);
  const row={track,check,select,hint,wrap,name,skeleton};rows.push(row);
  manual.onclick=()=>{if(busy)return;if(isMornye()){window.sfMornyePicker?.({query:track.artist+' '+track.title,owner:modal,onSelect:item=>{row.select.add(new Option(item.artist+' - '+item.title,item.url));row.select.value=item.url;row.check.checked=true;row.hint.textContent='Elegida manualmente';syncPreparation();}});return;}if(!window.sfYouTubeSourcePicker){status.textContent='La búsqueda no está disponible. Puedes pegar los enlaces manualmente.';return;}window.sfYouTubeSourcePicker({query:track.artist+' '+track.title,limit:3,owner:modal,onSelect:(url,pickedName)=>{const id=new URL(url).searchParams.get('v');pick(row,{id,title:pickedName});hint.textContent='Elegida manualmente';}});};
  select.onchange=()=>{check.checked=!!select.value;syncPreparation();};
  title.append(hint);wrap.append(slot,title,select,manual,nameLabel);list.append(wrap);
 }
 if(isMornye())form.querySelector('button').classList.remove('primary');find.onclick=()=>findSources(find,preference.querySelector('input').checked);footer.hidden=false;status.textContent='Revisa la edición, busca las fuentes y ajusta el nombre de cada pista. Después confirma el destino.';
 }catch(e){edition.replaceChildren();edition.hidden=true;status.textContent=e.name==='AbortError'?'El catálogo tardó demasiado. Prueba otra fuente.':e.message;}finally{loading?.stop();work(false);}}
 function sourceWaiting(row,text){row.check.hidden=true;row.skeleton.hidden=false;row.wrap.setAttribute('aria-busy','true');row.hint.textContent=text;}
 async function searchSource(row,lyrics){
  if(stopped)return;sourceWaiting(row,'Buscando coincidencias…');
  const deadline=new AbortController(),timer=setTimeout(()=>deadline.abort(),45000);
  const pause=ms=>new Promise((resolve,reject)=>{
   const abort=()=>{clearTimeout(t);reject(new DOMException('Tiempo agotado','AbortError'));};
   const t=setTimeout(()=>{deadline.signal.removeEventListener('abort',abort);resolve();},ms);
   if(deadline.signal.aborted)abort();else deadline.signal.addEventListener('abort',abort,{once:true});
  });
  try{
   if(isMornye()){const d=await post('api_mornye.php',{action:'search',query:(row.track.artist+' '+row.track.title).slice(0,240)},{signal:deadline.signal});const items=d.items||[];row.select.replaceChildren(new Option('Sin fuente seleccionada',''));for(const item of items)row.select.add(new Option(item.artist+' - '+item.title+' · '+time(item.duration),item.url));const clean=x=>x.toLocaleLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/[^a-z0-9]/g,'');const match=items.find(i=>clean(i.title)===clean(row.track.title)&&clean(i.artist)===clean(row.track.artist)&&(!i.duration||!row.track.duration||Math.abs(i.duration-row.track.duration)<8));if(match){row.select.value=match.url;row.check.checked=true;row.hint.textContent='Preseleccionada: título, artista y duración coinciden';}else{row.check.checked=false;row.hint.textContent=items.length?'Revisa y elige una fuente de Mornye':'Sin coincidencias en Mornye';}return;}
   let d;const query=(row.track.artist+' '+row.track.title).slice(0,240);
   for(let attempt=0;;attempt++){
    try{d=await post('api_youtube_search.php',{query,limit:3,purpose:'album_sources'},{signal:deadline.signal,timeoutMs:35000});break;}
    catch(e){if(stopped||deadline.signal.aborted||![429,503].includes(e.status)||attempt>=2)throw e;row.hint.textContent='Esperando turno de búsqueda…';await pause(e.retryAfter*1000+100);if(stopped)return;}
   }
   const rank=await post('api_album_discovery.php',{action:'rank',track:JSON.stringify(row.track),items:JSON.stringify(d.items||[]),prefer_lyrics:lyrics?'1':'0'},{signal:deadline.signal,timeoutMs:8000});
   if(stopped)return;
   const items=(Array.isArray(rank.items)?rank.items:[]).filter(item=>/^[A-Za-z0-9_-]{11}$/.test(item.id||''));
   row.select.replaceChildren(new Option('Sin fuente seleccionada',''));row.check.checked=false;
   for(const item of items){const detail=[item.score+'/100',item.channel,item.duration?time(item.duration):''].filter(Boolean).join(' · ');row.select.add(new Option((item.confident?'✓ ':'')+(item.title||'Pista')+' — '+detail,'https://www.youtube.com/watch?v='+item.id));}
   if(items[0]?.confident){pick(row,items[0]);row.hint.textContent='Preseleccionada: '+items[0].score+'/100'+(items[0].match_reason?' · '+items[0].match_reason:'');}
   else row.hint.textContent=items.length?'Sin coincidencia segura ('+(items[0]?.score||0)+'/100): revisa y elige':'Sin coincidencia: usa Buscar / cambiar';
  }catch(e){if(!stopped)row.hint.textContent=e.name==='AbortError'?'Tiempo agotado: usa Buscar / cambiar para reintentar':'No se pudo buscar: '+e.message;}
  finally{clearTimeout(timer);row.hint.title=row.hint.textContent;row.skeleton.hidden=true;row.check.hidden=false;row.wrap.removeAttribute('aria-busy');}
 }
 async function findSources(button,lyrics){
  if(busy)return;work(true,isMornye()?'Buscando fuentes exclusivamente en Mornye…':'Buscando fuentes (hasta 3 a la vez)…');button.disabled=true;progress.hidden=false;progress.value=0;
  const pending=modal.querySelector('[data-use]'),waiting=rows.filter(row=>!row.select.value);let completed=rows.length-waiting.length;
  const update=()=>{progress.value=rows.length?completed/rows.length*100:100;if(!stopped){const text='Fuentes revisadas: '+completed+'/'+rows.length+(isMornye()?' · búsqueda en Mornye':' · hasta 3 búsquedas simultáneas');if(isMornye())report(text);else status.textContent=text;}};
  pending.classList.add('sf-btn-skeleton');pending.setAttribute('aria-busy','true');
  for(const row of waiting)sourceWaiting(row,'En espera de búsqueda…');update();
  try{await runSourceQueue(waiting,row=>searchSource(row,lyrics),()=>stopped,()=>{completed++;update();},isMornye()?1:3);if(!stopped)status.textContent='Búsqueda terminada. '+chosen().length+' de '+rows.length+' fuentes seleccionadas. Revisa las pendientes con Buscar / cambiar y confirma el destino.';}
  finally{for(const row of waiting){row.skeleton.hidden=true;row.check.hidden=false;row.wrap.removeAttribute('aria-busy');}pending.classList.remove('sf-btn-skeleton');pending.removeAttribute('aria-busy');button.disabled=false;work(false);}
 }
 form.onsubmit=async e=>{e.preventDefault();if(busy)return;if(inline){const values={create:wantsNewAlbum(),streamingSource:sourceSelect.value};for(const field of ['artist','title','year','provider'])values[field]=form.elements[field].value;const review=open(null,values);review.querySelector('form').requestSubmit();return;}work(true,'Buscando ediciones…');results.replaceChildren();edition.hidden=true;footer.hidden=true;const loading=window.sfLoadingSkeletons?.start(results,{kind:'albums',count:3,label:'Buscando ediciones del álbum…'});try{const d=await post('api_album_discovery.php',{action:'search',artist:form.elements.artist.value.trim(),title:form.elements.title.value.trim(),year:form.elements.year.value.trim(),provider:form.elements.provider.value});loading?.stop();results.replaceChildren();for(const item of d.items||[]){const card=node('article',null,'sf-album-release');const info=node('div');info.append(node('strong',item.artist+' — '+item.title),node('p',[item.date,item.country,item.tracks+' pistas'].filter(Boolean).join(' · ')));const choose=node('button','Ver pistas y elegir','btn');choose.type='button';choose.onclick=()=>selectRelease(item);card.append(info,choose);results.append(card);}status.textContent=d.items?.length?'Elige la edición que corresponda.':'No hubo coincidencias. Ajusta los datos o cambia de catálogo.';}catch(e){results.replaceChildren();status.textContent=e.name==='AbortError'?'El catálogo tardó demasiado; puedes probar otra fuente.':e.message;}finally{loading?.stop();work(false);}};
 modal.querySelector('[data-use]').onclick=async()=>{if(busy||!selected)return;if(window.sfAlbumImportBusy?.()){status.textContent='Hay una validación o importación en curso. Espera a que termine antes de enviar otro lote.';return;}if(isMornye()&&!mornyePreparationState(rows).ready){syncPreparation();return;}if(stopped)return;const tracks=chosen();if(!tracks.length){report('Elige al menos una fuente.');return;}if(tracks.length<rows.length&&!confirm('Hay '+(rows.length-tracks.length)+' canciones sin seleccionar. ¿Continuar solo con '+tracks.length+'?'))return;
 const ids=tracks.map(t=>isMornye()?t.url:new URL(t.url).searchParams.get('v'));if(new Set(ids).size!==ids.length){status.textContent='Hay canciones con el mismo enlace. Revisa las fuentes antes de continuar.';return;}
 if(!wantsNewAlbum()&&currentId&&inline&&!confirm('Se agregarán '+tracks.length+' pista(s) al álbum actual «'+currentAlbum+'». ¿Continuar?'))return;
 work(true,'Preparando álbum y portada…');showPageWait(wantsNewAlbum()?'Creando el álbum y preparando las pistas…':'Preparando las pistas…',modal.tagName==='DIALOG'?modal:document.body);try{let id=wantsNewAlbum()?null:destinationId;
 if(!id){const body=new FormData();body.set('csrf_token',csrf());body.set('title',selected.title);body.set('artist',selected.artist);const r=await window.sfCreateAlbumRequest('api_album.php',{method:'POST',body,credentials:'same-origin',headers:{Accept:'application/json'}});const d=await r.json();if(!r.ok||d.error)throw Error(d.error||'No se pudo crear el álbum');id=d.album_id||d.album?.id;if(!id)throw Error('No se recibió el álbum');destinationId=id;}if(stopped){hidePageWait();return;}
 let warning='';if(modal.querySelector('[data-cover]').checked){let cover=selected.cover;if(!cover){request=new AbortController();const active=request,timer=setTimeout(()=>active.abort(),25000);try{const r=await fetch('api_cover.php?'+new URLSearchParams({artist:selected.artist,album:selected.title,title:selected.title}),{signal:active.signal});const d=await r.json();cover=d.cover;}catch{}finally{clearTimeout(timer);request=null;}}if(stopped){hidePageWait();return;}
 if(cover){try{await post('api_album_manage.php',{action:'set_cover_url',album_id:id,cover_url:cover,matched_album:selected.title,matched_artist:selected.artist});}catch{warning='No se pudo guardar la portada; puedes elegirla en Gestionar álbum.';}}
 if(!cover)warning='No se encontró portada; puedes elegir una en Gestionar álbum.';}if(stopped){hidePageWait();return;}if(isMornye()){const autoImport=autoImportChoice.checked;hidePageWait();work(false,autoImport?'Iniciando descarga e importación desde Mornye…':'Fuentes preparadas en el importador de Mornye. Revisa los nombres y confirma la descarga.');if(id===currentId&&window.sfMornyeAlbumSetTracks){if(!inline)modal.close();window.sfMornyeAlbumSetTracks(tracks,{autoImport});}else{sessionStorage.setItem('sf-mornye-album-draft:'+id,JSON.stringify({account,album:id,tracks,autoImport,created:Date.now()}));if(!inline)modal.close();location.href='album_edit.php?id='+encodeURIComponent(id)+'&mornye=1';}return;}const draft={account,album:id,tracks,process:modal.querySelector('[data-process]').checked,autoImport:modal.querySelector('[data-auto-import]').checked,created:Date.now(),warning};sessionStorage.setItem(key(id),JSON.stringify(draft));if(id===currentId){if(inline){hidePageWait();work(false,'Enlaces enviados al importador de este álbum. Revisa y edita los nombres antes de importar.');footer.hidden=true;loadDraft();return;}hidePageWait();stopped=true;abortRequests();modal.close();loadDraft();return;}stopped=true;abortRequests();if(!inline)modal.close();location.href='album_edit.php?id='+encodeURIComponent(id)+'&youtube=1';
 }catch(e){hidePageWait();report(e.message);work(false);}};
 if(inline)container.append(modal);else{document.body.append(modal);modal.showModal();form.elements.artist.focus();}
 return modal;
}
function loadDraft(){if(!currentId||!document.getElementById('ytUrls'))return;let draft;try{draft=JSON.parse(sessionStorage.getItem(key(currentId))||'null');}catch{return;}if(!draft||draft.account!==account||draft.album!==currentId||Date.now()-draft.created>3600000||!Array.isArray(draft.tracks)){sessionStorage.removeItem(key(currentId));return;}sessionStorage.removeItem(key(currentId));const target=document.getElementById('ytUrls');target.value=draft.tracks.map(t=>t.url).join('\n');target.dispatchEvent(new Event('input',{bubbles:true}));document.getElementById('youtubeImportCard')?.scrollIntoView({behavior:'smooth',block:'start'});if(draft.warning)window.sfReplacementNotice?.('album-cover-'+Date.now(),draft.warning,true);document.dispatchEvent(new CustomEvent('sf-album-links-ready',{detail:draft}));}
const button=document.getElementById('discoverAlbumBtn');if(button)button.onclick=()=>open();
const inlineHost=document.querySelector('[data-album-discovery-inline]');if(currentId&&inlineHost&&document.getElementById('ytUrls'))open(inlineHost);
window.sfOpenAlbumDiscovery=open;
loadDraft();
})();
