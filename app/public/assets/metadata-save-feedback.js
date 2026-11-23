(()=>{'use strict';
const form=document.getElementById('trackMetadataForm'),feedback=document.getElementById('metadataSaveFeedback');
if(!form||!feedback)return;
const buttons=[...form.querySelectorAll('[type="submit"]')];let saving=false,skeleton=null;
const cover=document.getElementById('coverImageFile'),preview=document.getElementById('preview'),coverFeedback=document.getElementById('coverPreparation'),coverStatus=document.getElementById('coverPreparationStatus');
let coverLoading=null,coverTimer=null,coverSequence=0;
function finishCover(message){clearTimeout(coverTimer);coverLoading?.stop();coverLoading=null;coverStatus.textContent=message;}
cover?.addEventListener('change',()=>{
 const file=cover.files[0];if(!file)return;
 const sequence=++coverSequence;
 coverFeedback.hidden=false;
 if(file.size>8*1024*1024||!['image/jpeg','image/png','image/webp','image/gif'].includes(file.type)){finishCover('La imagen debe ser JPG, PNG, WebP o GIF de hasta 8 MiB.');return;}
 finishCover('Preparando la imagen seleccionada…');
 coverLoading=window.sfLoadingSkeletons?.start(document.getElementById('coverPreparationSkeleton'),{kind:'covers',count:1,label:'Preparando portada'});
 const loaded=()=>{if(sequence!==coverSequence)return;finishCover('Imagen lista. Pulsa Guardar metadatos para aplicar la portada.');cleanup();};
 const failed=()=>{if(sequence!==coverSequence)return;finishCover('No se pudo mostrar la imagen. Elige otra imagen antes de guardar.');cleanup();};
 const cleanup=()=>{preview.removeEventListener('load',loaded);preview.removeEventListener('error',failed);};
 preview.addEventListener('load',loaded);preview.addEventListener('error',failed);
 coverTimer=setTimeout(failed,15000);
},true);
form.addEventListener('invalid',event=>{
 coverFeedback.hidden=false;coverStatus.textContent='No se ha guardado: revisa el campo '+(event.target.closest('label')?.querySelector('span')?.textContent||event.target.name||'marcado')+'.';
},true);
form.addEventListener('submit',event=>{
 if(event.defaultPrevented)return;
 if(saving){event.preventDefault();return;}
 if(cover?.files.length){coverFeedback.hidden=false;finishCover('Guardando la imagen y los metadatos…');coverLoading=window.sfLoadingSkeletons?.start(document.getElementById('coverPreparationSkeleton'),{kind:'covers',count:1,label:'Guardando portada'});}
 saving=true;form.setAttribute('aria-busy','true');feedback.hidden=false;
 buttons.forEach(button=>{button.disabled=true;});
 skeleton=window.sfLoadingSkeletons?.start(document.getElementById('metadataSaveSkeleton'),{kind:'tracks',count:1,label:'Guardando metadatos y portada'});
});
window.addEventListener('pageshow',()=>{
 saving=false;form.removeAttribute('aria-busy');feedback.hidden=true;skeleton?.stop();skeleton=null;
 buttons.forEach(button=>{button.disabled=false;});
});
})();
