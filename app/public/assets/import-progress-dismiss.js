(()=>{
'use strict';
function attach(panel,{isBusy=()=>false}={}){
 let wait=null,fade=null,ended=false,suppressed=false,hasErrors=false,records=0;
 const results=panel.querySelector('.progress-results'),summary=results?.querySelector('summary'),log=panel.querySelector('.progress-log');
 const button=document.createElement('button');button.type='button';button.className='btn progress-close';button.textContent='Cerrar';button.title='Ocultar el panel de progreso';panel.appendChild(button);
 const launcher=document.createElement('p'),reopen=document.createElement('button');launcher.className='progress-reopen';launcher.hidden=true;reopen.type='button';reopen.className='btn';reopen.textContent='Ver progreso';launcher.appendChild(reopen);panel.parentNode.insertBefore(launcher,panel);
 function clear(){clearTimeout(wait);clearTimeout(fade);wait=fade=null;panel.classList.remove('progress-panel-fading');}
 function failed(){return hasErrors||!!panel.dataset.progressError||!!log?.querySelector('.err');}
 function updateSummary(){if(summary)summary.textContent='Resultados del proceso ('+records+' registros'+(failed()?' · Con errores':'')+')';}
 function showFailure(){clear();hasErrors=true;panel.dataset.progressError='1';panel.dataset.progressOutcome='error';launcher.hidden=false;suppressed=false;panel.hidden=false;panel.classList.remove('progress-panel-mini');panel.querySelector('.progress-restore')?.setAttribute('hidden','');if(results)results.open=true;panel.scrollTop=0;updateSummary();}
 function record(ok){records++;if(ok===false){hasErrors=true;panel.dataset.progressError='1';if(ended)showFailure();}updateSummary();}
 function close(){
  if(isBusy()&&!confirm('Se ocultará el progreso sin cancelar la tarea. Si procesas en este equipo, no refresques ni cierres la pestaña: se interrumpiría el proceso. Puedes seguir escuchando en esta página. ¿Ocultar el panel?'))return;
  clear();panel.hidden=true;suppressed=true;launcher.hidden=ended&&panel.dataset.progressOutcome==='success';
 }
 function start(){clear();ended=false;suppressed=false;hasErrors=false;records=0;delete panel.dataset.progressError;delete panel.dataset.progressOutcome;launcher.hidden=false;if(results)results.open=false;updateSummary();}
 function finish({success=true}={}){
  clear();ended=true;const ok=success&&!failed();panel.dataset.progressOutcome=ok?'success':'error';launcher.hidden=false;
  if(!ok){showFailure();return;}
  wait=setTimeout(()=>{if(!ended||isBusy())return;if(failed()){showFailure();return;}panel.classList.add('progress-panel-fading');fade=setTimeout(()=>{if(ended&&!isBusy()){if(failed()){showFailure();return;}clear();panel.hidden=true;launcher.hidden=true;}},400);},5000);
 }
 function recover(){clear();ended=false;launcher.hidden=false;return !suppressed;}
 function idle(){clear();ended=true;if(failed()){showFailure();return;}launcher.hidden=true;}
 button.addEventListener('click',close);
 reopen.addEventListener('click',()=>{clear();suppressed=false;panel.hidden=false;panel.classList.remove('progress-panel-mini');panel.querySelector('.progress-restore')?.setAttribute('hidden','');if(ended&&panel.dataset.progressOutcome==='success')finish();});
 window.addEventListener('pagehide',clear);
 return {start,finish,close,recover,idle,record};
}
window.sfImportProgressDismiss={attach};
})();
