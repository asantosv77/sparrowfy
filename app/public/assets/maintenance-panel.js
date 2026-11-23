(()=>{'use strict';
 const script=document.currentScript,submittedAt=Number(script.dataset.submittedAt),initialVersion=Number(script.dataset.stateVersion),label=document.getElementById('maintenance-state');let busy=false,wasRunning=false,edited=false;
 document.addEventListener('input',()=>{edited=true;});
 document.addEventListener('submit',event=>{
  const form=event.target;if(form.dataset.submitting==='1'){event.preventDefault();return;}
  form.dataset.submitting='1';const button=event.submitter;
  if(button?.name){const value=document.createElement('input');value.type='hidden';value.name=button.name;value.value=button.value;form.append(value);}
  document.querySelectorAll('button[type="submit"],button:not([type])').forEach(b=>{b.disabled=true;});
 });
 setInterval(async()=>{
  if(busy||document.hidden)return;busy=true;
  try{
   const url=new URL(location.href);url.searchParams.set('status','1');const r=await fetch(url,{cache:'no-store'});if(!r.ok)return;
   const s=await r.json(),version=Number(s.finished||s.started||0);
   label.textContent=s.state==='running'?'Tarea en curso: '+s.action:(s.message||'');
   if(s.state==='running')wasRunning=true;
   else if(s.state==='completed'&&(wasRunning||(submittedAt>0&&Number(s.finished)>=submittedAt)||version>initialVersion+0.001)){
    wasRunning=false;if(!edited){url.searchParams.delete('status');location.replace(url.href);}else label.textContent+=' · Actualiza para ver la lista nueva; tus cambios del formulario se conservaron.';
   }
  }catch(_){}finally{busy=false;}
 },2000);
})();
