(()=>{'use strict';
const csrf=()=>document.querySelector('meta[name="sf-csrf"]')?.content||'';
async function request(action,data={}){const fd=new FormData();fd.set('action',action);fd.set('csrf_token',csrf());for(const[k,v]of Object.entries(data))fd.set(k,v);const r=await fetch('api_track_reuse.php',{method:'POST',body:fd});const d=await r.json();if(!r.ok)throw Error(d.error||'No se pudo guardar');if(d.already_exists)throw Error(d.message);return d;}
function modal(title){const d=document.createElement('dialog');d.className='sf-reuse-dialog';const h=document.createElement('h3');h.textContent=title;d.append(h);const status=document.createElement('p');status.setAttribute('role','status');d.append(status);document.body.append(d);d.showModal();return {d,status};}
function button(text,fn){const b=document.createElement('button');b.type='button';b.className='btn';b.textContent=text;b.onclick=fn;return b;}
function fmtDuration(s){s=Number(s);return s>0?Math.floor(s/60)+':'+String(Math.round(s)%60).padStart(2,'0'):'';}
window.sfMediaDuration=file=>new Promise(resolve=>{
 if(!file||typeof file.name!=='string'||(!/\.(mp3|m4a|aac|ogg|opus|flac|wav|webm|mp4)$/i.test(file.name)&&!/^audio\//.test(file.type||''))){resolve(0);return;}
 let done=false;const audio=document.createElement('audio'),url=URL.createObjectURL(file);
 const finish=value=>{if(done)return;done=true;audio.onloadedmetadata=audio.onerror=null;audio.removeAttribute('src');audio.load();try{URL.revokeObjectURL(url);}catch(_){}resolve(Number.isFinite(value)&&value>0?value:0);};
 audio.preload='metadata';audio.onloadedmetadata=()=>finish(audio.duration);audio.onerror=()=>finish(0);setTimeout(()=>finish(audio.duration),5000);audio.src=url;
});
/**
 * Barrido completo de coincidencias al inicio de un lote.
 * entries: [{key,name,duration}] — devuelve un Map key → {mode,match}
 * mode: 'replace' | 'new' | 'reuse' | 'skip'
 * Se pregunta una sola vez por todo el lote, con opciones globales y ajuste por fila.
 */
window.sfCheckDuplicates=async(entries,target,options={})=>{
 const list=(Array.isArray(entries)?entries:[]).filter(e=>e&&typeof e.name==='string'&&e.name.trim());
 if(!list.length||!window.fetch)return new Map();
 const targetId=String(target||'');
 const realTarget=/^[a-f0-9]{16}$/i.test(targetId)&&targetId!=='fffffffffffffffe';
 const lookup=async entry=>{
  const seconds=Number(entry.duration)>0&&Number(entry.duration)<10800?Number(entry.duration):0;
  const r=await fetch('api_track_reuse.php?q='+encodeURIComponent(entry.name)+(seconds?'&duration='+encodeURIComponent(seconds):''),{cache:'no-store',signal:AbortSignal.timeout(20000)});
  const data=await r.json().catch(()=>({}));
  if(!r.ok)throw Error(data.error||'No se pudo consultar duplicados');
  return{entry,items:Array.isArray(data.items)?data.items:[]};
 };
 const settled=new Array(list.length);let next=0;
 await Promise.all(Array.from({length:Math.min(3,list.length)},async()=>{while(next<list.length){const i=next++;try{settled[i]=await lookup(list[i]);}catch(error){throw Error('No se pudo comprobar si «'+list[i].name+'» ya existe. No se iniciará la importación. '+error.message);}}}));
 const found=settled.filter(x=>x.items.length);
 if(!found.length)return new Map();
 return new Promise((resolve,reject)=>{
  const d=document.createElement('dialog');
  d.className='sf-reuse-dialog';
  d.style.cssText='width:min(760px,94vw);max-height:88vh;overflow:auto;padding:20px;border:1px solid var(--border,#315563);border-radius:14px;background:var(--card,#102630);color:var(--text,#eff7f7)';
  const title=document.createElement('h3');title.textContent='Coincidencias en el lote';
  const intro=document.createElement('p');intro.className='muted';
  intro.textContent='Se revisaron '+(list.length===1?'1 pista':list.length+' pistas')+' y '+(found.length===1?'1 tiene':found.length+' tienen')+' posibles coincidencias. Decide una sola vez para todo el lote; puedes ajustar cada fila antes de aplicar.';
  const rows=document.createElement('div');rows.style.cssText='display:grid;gap:10px;margin:14px 0';
  const choices=[];
  for(const {entry,items} of found){
   const match=items[0],sameAlbum=String(match.album_id)===targetId;
   const row=document.createElement('article');row.dataset.key=String(entry.key);row.style.cssText='padding:10px 12px;border:1px solid var(--border,#315563);border-radius:10px;background:var(--surface-raised,rgba(255,255,255,.03))';
   const name=document.createElement('strong');name.textContent=entry.name;
   const where=document.createElement('p');where.className='muted';where.style.cssText='margin:4px 0;font-size:.86rem';
   const place=(match.locations||[]).map(l=>l.title).filter(Boolean).join(' · ')||'biblioteca';
   where.textContent='Mejor coincidencia: '+match.name+' · '+place+(match.match_score?' · '+match.match_score+'/100':'')+(match.match_reason?' · '+match.match_reason:'');
   const actions=document.createElement('select');actions.setAttribute('aria-label','Acción para '+entry.name);actions.style.cssText='width:100%;margin-top:4px';
   const add=(value,label)=>{const o=document.createElement('option');o.value=value;o.textContent=label;actions.append(o);};
   const replaceLabel=sameAlbum?'Sustituir la pista existente (conserva listas y favoritos)':'Sustituir la copia de «'+place+'»';
   if(sameAlbum||realTarget)add('replace',replaceLabel);
   add('new','Subir como nueva pista');
   if(realTarget&&!sameAlbum)add('reuse','Reutilizar la pista existente en esta colección');
   add('skip','Omitir esta canción');
   actions.value=sameAlbum?'replace':(realTarget&&!sameAlbum?'reuse':'new');
   const matchRef={item:match};
   if(items.length>1){
    const pick=document.createElement('select');pick.setAttribute('aria-label','Elegir coincidencia para '+entry.name);pick.style.cssText='width:100%;margin-top:6px';
    items.slice(0,8).forEach((item,index)=>{const o=document.createElement('option');o.value=String(index);o.textContent='Coincidencia '+(index+1)+': '+item.name+' · '+((item.locations||[]).map(l=>l.title).join(' · ')||'');pick.append(o);});
    pick.onchange=()=>{const selected=items[Number(pick.value)]||items[0];matchRef.item=selected;const selectedPlace=(selected.locations||[]).map(l=>l.title).filter(Boolean).join(' · ')||'biblioteca';where.textContent='Mejor coincidencia: '+selected.name+' · '+selectedPlace+(selected.match_score?' · '+selected.match_score+'/100':'')+(selected.match_reason?' · '+selected.match_reason:'');};
    row.append(name,where,actions,pick);
   }else row.append(name,where,actions);
   choices.push({key:String(entry.key),actions,get match(){return matchRef.item;},row});
   rows.append(row);
  }
  const bulk=document.createElement('div');bulk.style.cssText='display:flex;flex-wrap:wrap;gap:8px;margin:6px 0 16px';
  // Solo se ofrecen las acciones globales que alguna fila puede aplicar.
  const bulkModes=new Set();
  for(const c of choices)for(const o of c.actions.options)bulkModes.add(o.value);
  const bulkButton=(label,mode)=>{if(!bulkModes.has(mode))return;const b=button(label,()=>{for(const c of choices){if([...c.actions.options].some(o=>o.value===mode))c.actions.value=mode;}});bulk.append(b);return b;};
  bulkButton('Sustituir todas','replace');bulkButton('Subir todas como nuevas','new');bulkButton('Reutilizar todas','reuse');bulkButton('Omitir todas','skip');
  const footer=document.createElement('div');footer.style.cssText='display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap';
  const apply=button('Aplicar al lote',()=>{const decisions=new Map();for(const c of choices)decisions.set(c.key,{mode:c.actions.value,match:c.match});settledDone(decisions);});
  apply.className='btn primary';
  const cancel=button('Cancelar importación',()=>{settledDone(null,true);});
  footer.append(cancel,apply);
  d.append(title,intro,rows,bulk,footer);
  let done=false;
  const settledDone=(decisions,cancelled=false)=>{if(done)return;done=true;try{d.close();}catch{}d.remove();if(cancelled)reject(Error('Importación cancelada'));else resolve(decisions||new Map());};
  d.addEventListener('cancel',e=>{e.preventDefault();settledDone(null,true);});
  d.addEventListener('close',()=>{if(!done)settledDone(null,true);});
  document.body.append(d);d.showModal();
 });
};
window.sfCheckDuplicate=async(name,target,duration=0)=>{
 const decisions=await window.sfCheckDuplicates([{key:'0',name,duration}],target,{});
 const decision=decisions.get('0');
 if(!decision)return false;
 if(decision.mode==='new')return false;
 if(decision.mode==='reuse'&&decision.match){
  await request('attach',{source:decision.match.album_id,track_id:decision.match.id,target});
  return true;
 }
 return decision.mode!=='new';
};
document.addEventListener('click',async e=>{const b=e.target.closest('[data-reuse-track],[data-detach-track]');if(!b)return;e.preventDefault();
 if(b.dataset.detachTrack){b.disabled=true;try{await request('detach',{target:b.dataset.detachAlbum,track_id:b.dataset.detachTrack});if(window.sfRefreshAlbumTracks)await window.sfRefreshAlbumTracks({appendOnly:true,removeMissing:true});else location.reload();}catch(err){b.disabled=false;alert(err.message);}return;}
 await window.sfMembershipPicker({title:'Colecciones de la pista',trigger:b,load:async()=>{const r=await fetch('api_track_reuse.php?action=memberships&track_id='+encodeURIComponent(b.dataset.reuseTrack));const j=await r.json();if(!r.ok)throw Error(j.error);return j.items;},save:async(item,enabled)=>{await request(enabled?'attach':'detach',{source:item.source,track_id:b.dataset.reuseTrack,target:item.id});}});

});})();



