(()=>{'use strict';
let sequence=Promise.resolve();
async function refresh({appendOnly=true,trackId=null,removeMissing=false}={}){
 const album=new URL(location.href).searchParams.get('id');if(!/^[a-f0-9]{16}$/.test(album||''))return false;
 const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),15000);
 try{
  const url=new URL('album_edit.php',location.href);url.searchParams.set('id',album);
  const response=await fetch(url,{credentials:'same-origin',cache:'no-store',signal:controller.signal,headers:{Accept:'text/html'}});if(!response.ok)throw Error('No se pudo actualizar el listado');
  const parsed=new DOMParser().parseFromString(await response.text(),'text/html'),source=parsed.querySelector('.lab-tracks-card'),target=document.querySelector('.lab-tracks-card');if(!source||!target)throw Error('La sesión o el listado no están disponibles; las pistas existentes se conservan');
  const incoming=source.querySelector('tbody'),ids=new Set();let body=target.querySelector('tbody');
  if(incoming&&!body){const scroll=source.querySelector('.lab-tracks-scroll').cloneNode(true);scroll.querySelector('tbody').replaceChildren();target.querySelector(':scope > p.muted')?.remove();target.append(scroll);body=scroll.querySelector('tbody');}
  const y=window.scrollY,x=window.scrollX;
  for(const row of incoming?.querySelectorAll('tr[data-track-id]')||[]){const id=row.dataset.trackId;if(!/^[a-f0-9]{16}$/.test(id))continue;ids.add(id);const existing=body.querySelector('tr[data-track-id="'+id+'"]');if(existing){if(removeMissing||(!appendOnly&&(!trackId||trackId===id)))existing.replaceWith(document.importNode(row,true));}else body.append(document.importNode(row,true));}
  if(removeMissing&&body)for(const row of body.querySelectorAll('tr[data-track-id]'))if(!ids.has(row.dataset.trackId))row.remove();
  const heading=target.querySelector('.album-tracks-heading h2'),sourceHeading=source.querySelector('.album-tracks-heading h2');if(heading&&sourceHeading)heading.textContent=sourceHeading.textContent;
  window.scrollTo(x,y);document.dispatchEvent(new CustomEvent('sf:personal-changed'));return true;
 }finally{clearTimeout(timer);}
}
window.sfRefreshAlbumTracks=options=>{const next=sequence.catch(()=>{}).then(()=>refresh(options));sequence=next;return next;};
})();
