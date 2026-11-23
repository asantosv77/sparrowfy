(()=>{'use strict';
// Refresh only the catalogue; the existing audio iframe and navigation stay mounted.
let pending=false;
window.addEventListener('message',async event=>{
 const frame=document.getElementById('labConfigFrame');
 if(event.origin!==location.origin||!frame||event.source!==frame.contentWindow||event.data?.source!=='sf-account-preferences'||event.data.type!=='management-view-changed'||pending)return;
 pending=true;
 try{
  const url=new URL(location.href);
  if(url.pathname.endsWith('/index.php')||url.pathname.endsWith('/')){
   if(url.pathname.endsWith('/'))url.pathname+='index.php';
   await window.sfNavigate(url.href,{historyMode:'none'});
  }
 }catch(error){
  const doc=frame.contentDocument;
  if(doc){const p=doc.createElement('p');p.setAttribute('role','alert');p.textContent='La preferencia se guardó. No se pudo actualizar la vista; recarga cuando termines de escuchar.';doc.querySelector('.lab-main')?.prepend(p);}
 }finally{pending=false;}
});
})();
