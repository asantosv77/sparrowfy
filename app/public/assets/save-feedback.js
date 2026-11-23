(()=>{'use strict';if(window.sfSaveFeedback)return;
const active=new WeakMap(),forms=new WeakMap();
const isSave=b=>b&&/^guardar(?:\s|$)/i.test(b.textContent.trim());
function check(){const svg=document.createElementNS('http://www.w3.org/2000/svg','svg');svg.setAttribute('viewBox','0 0 24 24');svg.setAttribute('aria-hidden','true');svg.classList.add('sf-save-check');const path=document.createElementNS(svg.namespaceURI,'path');path.setAttribute('d','M4 12.5 9.5 18 20 6');svg.append(path);return svg;}
function notice(message='Guardado correctamente.',error=false){
 let node=document.getElementById('sf-save-toast');if(node){clearTimeout(node.timer);clearTimeout(node.removeTimer);node.remove();}
 node=document.createElement('div');node.id='sf-save-toast';node.className='sf-save-toast';node.setAttribute('role',error?'alert':'status');if(error)node.dataset.error='1';else node.append(check());const text=document.createElement('span');text.textContent=message;node.append(text);document.body.append(node);
 node.timer=setTimeout(()=>{node.classList.add('sf-save-toast-fade');node.removeTimer=setTimeout(()=>node.remove(),650);},error?7000:3200);
}
function begin(button){
 if(!button)return {success:()=>notice(),error:e=>notice(e?.message||'No se pudo guardar.',true),reset(){}};
 active.get(button)?.reset();
 const children=[...button.childNodes],label=document.createElement('span'),oldAria=button.getAttribute('aria-label'),oldDisabled=button.getAttribute('aria-disabled');
 label.className='sf-save-label';label.append(...children);button.replaceChildren(label);button.classList.add('sf-save-button','sf-save-busy');button.setAttribute('aria-busy','true');button.setAttribute('aria-disabled','true');button.setAttribute('aria-label','Guardando…');let timer;
 const handle={reset(){clearTimeout(timer);button.classList.remove('sf-save-busy','sf-save-done','sf-save-button');button.removeAttribute('aria-busy');if(oldAria===null)button.removeAttribute('aria-label');else button.setAttribute('aria-label',oldAria);if(oldDisabled===null)button.removeAttribute('aria-disabled');else button.setAttribute('aria-disabled',oldDisabled);button.replaceChildren(...children);if(active.get(button)===handle)active.delete(button);},success(message='Guardado correctamente.'){
  if(active.get(button)!==handle)return;button.classList.remove('sf-save-busy');button.classList.add('sf-save-done');button.removeAttribute('aria-busy');button.removeAttribute('aria-disabled');button.setAttribute('aria-label',message);label.replaceChildren(check(),document.createTextNode('Guardado'));notice(message);timer=setTimeout(()=>handle.reset(),3150);
 },error(error){if(active.get(button)!==handle)return;handle.reset();notice(error?.message||String(error||'No se pudo guardar. Revisa los datos e inténtalo de nuevo.'),true);}};active.set(button,handle);return handle;
}
window.sfSaveFeedback={begin,notice};
const account=document.querySelector('meta[name="sf-session-account"]')?.content||'',key='sf-save-pending:'+account+':'+location.pathname+location.search;
function saveButtons(form){return [...document.querySelectorAll('button,input[type=submit]')].filter(b=>b.form===form&&isSave(b));}
document.addEventListener('submit',event=>{
 if(event.defaultPrevented)return;const form=event.target;if(!(form instanceof HTMLFormElement)||form.method.toUpperCase()!=='POST')return;
 const button=event.submitter||saveButtons(form)[0];if(!isSave(button))return;
 if(forms.has(form)){event.preventDefault();return;}
 const h=begin(button);forms.set(form,h);
 try{sessionStorage.setItem(key,JSON.stringify({at:Date.now(),form:[...document.forms].indexOf(form),button:saveButtons(form).indexOf(button),scroll:window.scrollY}));}catch(_){}
});
window.addEventListener('pageshow',event=>{if(event.persisted){for(const form of document.forms){forms.get(form)?.reset();forms.delete(form);}try{sessionStorage.removeItem(key);}catch(_){}}});
function restore(){let pending;try{pending=JSON.parse(sessionStorage.getItem(key)||'null');sessionStorage.removeItem(key);}catch(_){}if(!pending||Date.now()-pending.at>1800000)return;
 const result=document.querySelector('meta[name="sf-save-result"]')?.content;if(result==='success'){const button=saveButtons(document.forms[pending.form]||null)[pending.button];begin(button).success();requestAnimationFrame(()=>{if(Number.isFinite(pending.scroll))window.scrollTo({top:pending.scroll,behavior:'instant'});button?.scrollIntoView({block:'nearest',behavior:'instant'});});}else if(result==='error')notice('No se pudo guardar. Revisa el mensaje del formulario y vuelve a intentarlo.',true);
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',restore,{once:true});else restore();
})();
