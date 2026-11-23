(()=>{'use strict';if(window.sfQueueActions)return;
let scheduled=false,lastState='',listening=null;
const selectionIds=new Map();
function context(){
 const chain=[];let candidate=window;for(let i=0;i<8;i++){try{if(candidate.location.origin!==location.origin)break;chain.push(candidate);if(candidate===candidate.parent)break;candidate=candidate.parent;}catch(_){break;}}
 for(const owner of chain.reverse()){try{const host=owner.document.getElementById('labPlayerHost'),frame=owner.document.getElementById('labPlayerFrame'),dock=owner.document.getElementById('labMiniDock');if(host&&!host.hidden&&frame?.dataset.src){const api=frame.contentWindow?.sfPlayerQueue;if(typeof api?.addMany==='function')return {api,owner,token:frame.dataset.src,minimized:host.classList.contains('is-min')&&!!dock&&!dock.hidden};}}catch(_){}}
 try{if(window===window.parent&&document.getElementById('playerRoot')&&typeof window.sfPlayerQueue?.addMany==='function'){const root=document.getElementById('playerRoot'),mini=document.getElementById('miniDock');return {api:window.sfPlayerQueue,owner:window,token:location.href,minimized:root.hidden&&!!mini&&!mini.hidden};}}catch(_){}return null;
}
function hide(el,value){if(el.hidden!==value)el.hidden=value;}
function enhance(){
 for(const panel of document.querySelectorAll('.sf-track-options-panel')){if(panel.querySelector('[data-queue-add]'))continue;const properties=panel.querySelector('[data-track-properties]');if(!properties)continue;const b=document.createElement('button');b.type='button';b.dataset.queueAdd='track';b.dataset.queueId=properties.dataset.trackProperties;b.hidden=true;b.textContent='Añadir a cola';panel.prepend(b);}
}
function knownIds(button){
 try{const ids=JSON.parse(button.dataset.queueTracks||'null');if(Array.isArray(ids))return ids;}catch(_){}
 return selectionIds.get(button.dataset.queueAdd+':'+button.dataset.queueId)||null;
}
function alreadyQueued(kind,id,ids,queued,source){
 if(kind==='track')return queued.has(id);
 if(kind==='album'){try{if(new URL(source,location.href).searchParams.get('album')===id)return true;}catch(_){}}
 return Array.isArray(ids)&&ids.every(track=>queued.has(track));
}
function refresh(){
 enhance();const ctx=context(),open=!!ctx,minimized=!!ctx?.minimized,queued=new Set((ctx?.api.tracks?.()||[]).map(t=>t.id));
 for(const button of document.querySelectorAll('[data-queue-add]'))hide(button,!open||(button.hasAttribute('data-queue-minimized')&&!minimized)||alreadyQueued(button.dataset.queueAdd,button.dataset.queueId||'',knownIds(button),queued,ctx?.token||''));
 for(const element of document.querySelectorAll('[data-queue-only]')){
  let unavailable=!open;
  if(open&&element.matches('.album-menu-btn')){const card=element.closest('[data-album-id]');let ids=null;try{ids=JSON.parse(card?.dataset.queueTracks||'null');}catch(_){}unavailable=alreadyQueued('album',card?.dataset.albumId||'',ids,queued,ctx.token);}
  else if(open&&element.tagName==='DETAILS')unavailable=Array.from(element.querySelectorAll('[data-queue-add]')).every(b=>b.hidden);
  hide(element,unavailable);if(unavailable&&element.tagName==='DETAILS')element.open=false;
 }
 const state=JSON.stringify([open,minimized,ctx?.token||'',Array.from(queued).sort()]);if(state!==lastState){lastState=state;document.dispatchEvent(new CustomEvent('sf:queue-context'));}
}
function schedule(){if(scheduled)return;scheduled=true;requestAnimationFrame(()=>{scheduled=false;refresh();});}
function notice(text){if(typeof window.sfPlayerNotice==='function'){window.sfPlayerNotice(text);return;}const parent=context()?.owner;if(parent&&parent!==window&&typeof parent.sfPlayerNotice==='function'){parent.sfPlayerNotice(text);return;}let p=document.getElementById('sf-queue-action-notice');if(!p){p=document.createElement('p');p.id='sf-queue-action-notice';p.setAttribute('role','status');p.style.cssText='position:fixed;bottom:20px;right:20px;z-index:10050;max-width:min(420px,90vw);padding:12px;border-radius:8px;background:var(--card,#102630);color:var(--text,#fff);border:1px solid var(--border,#315563)';document.body.append(p);}p.textContent=text;clearTimeout(p.timer);p.timer=setTimeout(()=>p.remove(),5000);}
async function selection(kind,id,signal){
 let url;if(kind==='track'){if(!/^[a-f0-9]{16}$/.test(id))throw Error('Pista inválida');url='api_player_queue.php?id='+encodeURIComponent(id);}else{if(!['album','favorites','playlist'].includes(kind)||!id)throw Error('Selección inválida');if(kind==='album'&&!/^[a-f0-9]{16}$/.test(id))throw Error('Álbum inválido');if(kind==='playlist'&&!/^[a-f0-9]{32}$/.test(id))throw Error('Lista inválida');url='player.php?payload=1&'+new URLSearchParams({[kind]:id});}
 const response=await fetch(url,{credentials:'same-origin',cache:'no-store',signal});if(!response.ok)throw Error('No se pudo cargar la selección o ya no tienes acceso');const data=await response.json();const tracks=kind==='track'?data.tracks:data.album?.tracks;if(!Array.isArray(tracks)||!tracks.length)throw Error('Esta selección no contiene pistas disponibles');
 selectionIds.set(kind+':'+id,tracks.map(t=>t.id));
 return tracks.map(t=>kind==='track'?t:{...t,origin_album:t.origin_album||data.album.id,collection_title:t.collection_title||data.album.title||'',collection_artist:t.collection_artist||data.album.artist||'',collection_cover:t.collection_cover||data.localCover||''});
}
async function add(button){
 const ctx=context();if(!ctx||(button.hasAttribute('data-queue-minimized')&&!ctx.minimized)){refresh();return;}
 if(button.disabled||alreadyQueued(button.dataset.queueAdd,button.dataset.queueId||'',knownIds(button),new Set((ctx.api.tracks?.()||[]).map(t=>t.id)),ctx.token)){refresh();return;}button.disabled=true;const original=button.textContent;button.textContent='Añadiendo…';const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),20000);
 try{const tracks=await selection(button.dataset.queueAdd,button.dataset.queueId||'',controller.signal);const current=context();if(!current||current.api!==ctx.api||current.token!==ctx.token||(button.hasAttribute('data-queue-minimized')&&!current.minimized))throw Error('El reproductor cambió o se cerró. Vuelve a intentar desde su cola actual');const result=ctx.api.addMany(tracks);notice(result.added+' pista(s) añadida(s) a la cola'+(result.duplicates?' · '+result.duplicates+' ya estaban':'')+(result.limited?' · '+result.limited+' no caben: máximo 500 pistas':''));}
 catch(error){notice(error.name==='AbortError'?'La selección tardó demasiado. Puedes volver a intentar.':error.message);}
 finally{clearTimeout(timer);button.textContent=original;button.disabled=false;refresh();}
}
document.addEventListener('click',event=>{const button=event.target.closest('[data-queue-add]');if(!button)return;event.preventDefault();event.stopPropagation();button.closest('details')?.removeAttribute('open');const menu=button.closest('.ctx-menu');if(menu)menu.hidden=true;add(button);},true);
window.sfQueueActions={refresh,context:()=>({open:!!context(),minimized:!!context()?.minimized})};
const observer=new MutationObserver(schedule);observer.observe(document.body,{subtree:true,childList:true,attributes:true,attributeFilter:['hidden','class','src']});
document.addEventListener('sf:queue-updated',schedule);document.addEventListener('sf:navigated',schedule);window.addEventListener('pageshow',schedule);document.addEventListener('load',schedule,true);
try{if(window.parent!==window&&window.parent.location.origin===location.origin){listening=window.parent.document;listening.addEventListener('sf:queue-context',schedule);}}catch(_){}
window.addEventListener('pagehide',()=>listening?.removeEventListener('sf:queue-context',schedule));refresh();
})();
