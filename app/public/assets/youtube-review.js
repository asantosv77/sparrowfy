(()=>{'use strict';
function create({results,status,importButton,onCancel=()=>{}}){
 const dialog=document.createElement('dialog');dialog.className='sf-youtube-review';dialog.setAttribute('aria-label','Verificar enlaces de YouTube');
 const head=document.createElement('header'),title=document.createElement('h2'),close=document.createElement('button');title.textContent='Verificar enlaces y preparar pistas';close.type='button';close.className='btn';close.textContent='Cerrar';head.append(title,close);
 const intro=document.createElement('p');intro.className='muted';intro.textContent='Revisa cada fuente, la calidad de origen y el nombre. Solo se importarán las pistas seleccionadas y verificadas.';
 const labels=document.createElement('div');labels.className='sf-youtube-review-head';for(const text of ['','Melodía / enlace','Calidad de origen','Nombre al importar']){const label=document.createElement('span');label.textContent=text;labels.append(label);}
 const footer=document.createElement('footer');footer.append(importButton);dialog.append(head,intro,status,labels,results,footer);document.body.append(dialog);let busy=false;
 const attemptClose=()=>{if(busy&&!confirm('Se cancelará la verificación pendiente. Se conservarán las pistas ya verificadas. ¿Cerrar?'))return;if(busy)onCancel();dialog.close();};
 close.onclick=attemptClose;dialog.addEventListener('cancel',event=>{event.preventDefault();attemptClose();});
 return {open(){if(!dialog.open)dialog.showModal();},close(){if(dialog.open)dialog.close();},setBusy(value){busy=!!value;},isOpen:()=>dialog.open,element:dialog};
}
function showProgress(panel){if(!panel)return;panel.classList.add('sf-import-progress-float');panel.hidden=false;}
window.sfYouTubeReview={create,showProgress};
})();
