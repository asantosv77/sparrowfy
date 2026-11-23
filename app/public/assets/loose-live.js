(()=>{'use strict';if(window.sfLooseLive)return;
const pending=new Set(),done=new Set();let busy=false,timer;
async function flush(){
 clearTimeout(timer);const list=document.querySelector('.loose-track-list');if(busy||!pending.size||!list)return;
 busy=true;const ids=[...pending].slice(0,100),page=location.href;
 try{
  const response=await fetch('api_loose_rows.php?jobs='+encodeURIComponent(ids.join(',')),{cache:'no-store'});
  const data=await response.json();if(!response.ok||typeof data.html!=='string')throw Error(data.error||'No se pudo actualizar la lista');
  if(!list.isConnected||location.href!==page)return;
  const parsed=new DOMParser().parseFromString('<ul>'+data.html+'</ul>','text/html');
  const known=new Set([...list.querySelectorAll('[data-loose-id]')].map(row=>row.dataset.looseId));
  const fragment=document.createDocumentFragment();let added=0;
  for(const row of parsed.querySelectorAll('li[data-loose-id]')){const id=row.dataset.looseId;if(known.has(id))continue;known.add(id);fragment.append(document.importNode(row,true));added++;}
  if(added){list.append(fragment);window.sfSortLooseRows?.(list);document.querySelector('[data-loose-empty]')?.remove();document.dispatchEvent(new CustomEvent('sf:loose-updated'));}
  const total=document.querySelector('[data-loose-total]');if(total&&Number.isInteger(data.total)){total.dataset.looseTotal=String(data.total);total.textContent='('+data.total+')';}
  for(const id of ids){pending.delete(id);done.add(id);}
 }catch(error){const status=document.getElementById('library-organize-status');if(status)status.textContent='La pista terminó, pero no se pudo actualizar la lista. Se reintentará automáticamente.';}
 finally{busy=false;if(pending.size)timer=setTimeout(flush,5000);}
}
window.sfLooseLive={completed(jobId){if(!/^[a-f0-9]{32}$/.test(jobId)||done.has(jobId))return;pending.add(jobId);clearTimeout(timer);timer=setTimeout(flush,100);}};
document.addEventListener('sf:navigated',flush);
})();
