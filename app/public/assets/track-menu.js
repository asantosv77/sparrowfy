(()=>{'use strict';
const selector='details.sf-track-options';
function closeMenus(except){document.querySelectorAll(selector+'[open]').forEach(d=>{if(d!==except)d.open=false;});}
document.addEventListener('click',e=>{
 const d=e.target.closest(selector),summary=e.target.closest('summary');
 closeMenus(d);
 if(!d)return;
 if(summary){e.preventDefault();d.open=!d.open;if(d.open){
 document.querySelectorAll('.ctx-menu').forEach(m=>m.hidden=true);
 const p=d.querySelector('.sf-track-options-panel'),r=summary.getBoundingClientRect();
 p.style.left=Math.max(8,Math.min(r.right-p.offsetWidth,innerWidth-p.offsetWidth-8))+'px';
 p.style.top=Math.max(8,r.bottom+6+p.offsetHeight>innerHeight?r.top-p.offsetHeight-6:r.bottom+6)+'px';
 }}else if(e.target.closest('button'))d.open=false;
},true);
document.addEventListener('keydown',e=>{if(e.key==='Escape'){const d=document.querySelector(selector+'[open]');closeMenus();d?.querySelector('summary').focus();}});
document.addEventListener('scroll',e=>{if(!e.target.closest?.('.sf-track-options-panel'))closeMenus();},true);
window.addEventListener('resize',()=>closeMenus());
})();
(()=>{'use strict';document.addEventListener('click',async e=>{
 const b=e.target.closest('[data-track-properties],[data-track-delete]');if(!b)return;e.preventDefault();b.closest('details').open=false;
 const d=document.createElement('dialog');d.className='sf-membership-picker';if(b.dataset.trackProperties)d.style.width='min(720px, calc(100vw - 32px))';const h=document.createElement('h3'),status=document.createElement('p'),body=document.createElement('div'),close=document.createElement('button');h.textContent=b.dataset.trackProperties?'Propiedades de la pista':'Eliminar pista';status.setAttribute('role','status');close.type='button';close.className='btn';close.textContent='Cerrar';close.onclick=()=>d.close();d.addEventListener('close',()=>{d.remove();b.closest('details')?.querySelector('summary')?.focus();});d.append(h,status,body,close);document.body.append(d);d.showModal();
 if(b.dataset.trackProperties){status.textContent='Cargando…';try{const r=await fetch('api_track_properties.php?id='+encodeURIComponent(b.dataset.trackProperties),{cache:'no-store'});const j=await r.json();if(!r.ok)throw Error(j.error);status.textContent='';const dl=document.createElement('dl');dl.className='sf-property-grid';dl.style.cssText='display:grid;grid-template-columns:minmax(0,2fr) minmax(0,3fr);gap:10px 20px;align-items:start';for(const[k,v]of Object.entries(j.properties)){const dt=document.createElement('dt'),dd=document.createElement('dd');dt.textContent=k;dd.textContent=v||'No indicado';dd.style.cssText='margin:0;min-width:0;overflow-wrap:anywhere';dt.style.cssText='min-width:0;overflow-wrap:anywhere';dl.append(dt,dd);}body.append(dl);}catch(err){status.textContent=err.message;}return;}
 status.textContent='¿Eliminar esta pista de la colección? Si otras colecciones, listas o favoritos la utilizan, se conservará el audio. Si no está en uso, se eliminará definitivamente.';
 const yes=document.createElement('button');yes.type='button';yes.className='btn';yes.textContent='Eliminar';body.append(yes);let busy=false,confirmMemberships=false;d.addEventListener('cancel',e=>{if(busy)e.preventDefault();});yes.onclick=async()=>{busy=true;yes.disabled=true;close.disabled=true;status.textContent='Eliminando…';try{const fd=new FormData();fd.set('csrf_token',document.querySelector('meta[name="sf-csrf"]')?.content||'');fd.set('track_id',b.dataset.trackDelete);if(confirmMemberships)fd.set('confirm_remove_memberships','1');const ref=b.dataset.trackRef==='1';fd.set('action',ref?'detach':'delete_track');fd.set(ref?'target':'album_id',b.dataset.trackAlbum);const r=await fetch(ref?'api_track_reuse.php':'api_album_manage.php',{method:'POST',body:fd});const j=await r.json();if(j.requires_membership_confirmation){confirmMemberships=true;status.textContent=j.error;yes.textContent='Eliminar definitivamente y quitar de listas';yes.disabled=false;close.disabled=false;busy=false;return;}if(!r.ok||!j.success)throw Error(j.error||'No se pudo eliminar');location.reload();}catch(err){status.textContent=err.message;yes.disabled=false;close.disabled=false;busy=false;}};
});})();
