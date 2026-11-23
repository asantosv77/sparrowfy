(()=>{'use strict';
if(window.sfSessionSync)return;
const nativeFetch=window.fetch.bind(window),expected=document.querySelector('meta[name="sf-session-account"]')?.content;
if(expected===undefined)return;
let current=null,pending=null,lastSync=0,authError=null;
function invalidate(message){const first=!authError;authError=Error(message||'Tu sesión venció. Inicia sesión nuevamente para continuar; tus formularios siguen abiertos.');current=null;lastSync=0;if(first){notice(authError);window.dispatchEvent(new CustomEvent('sf:session-state',{detail:{available:false}}));}return authError;}
function inspect(response){if(response.status===401&&expected!=='')invalidate();return response;}
function notice(error){if(error.navigation)return;let n=document.getElementById('sf-session-notice');if(!n){n=document.createElement('div');n.id='sf-session-notice';n.setAttribute('role','alert');n.style.cssText='position:fixed;right:16px;top:16px;z-index:2147483600;max-width:min(420px,calc(100vw - 32px));padding:14px;border:1px solid var(--danger,#ef4444);border-radius:10px;background:var(--card,#102630);color:var(--text,#fff);box-shadow:0 6px 24px #0006';const text=document.createElement('span'),close=document.createElement('button');text.dataset.sessionMessage='1';close.type='button';close.textContent='×';close.setAttribute('aria-label','Cerrar aviso');close.style.cssText='float:right;margin-left:12px';close.onclick=()=>n.remove();n.append(close,text);document.body.append(n);}n.querySelector('[data-session-message]').textContent=error.message||'No se pudo actualizar la sesión. Tus datos siguen en el formulario.';}
function apply(data){
 if(expected===''&&data.authenticated===true&&data.account_id){const e=Error('La sesión se restauró. Abriendo Sparrow-Fy…');e.navigation=true;location.replace(new URL('index.php',location.href).href);throw e;}
 if(String(data.account_id??'')!==expected)throw invalidate('La cuenta abierta cambió. Recarga la página antes de enviar el formulario; tus datos no se enviaron.');
 if(!/^[a-f0-9]{64}$/.test(data.csrf_token||''))throw Error('No se pudo actualizar el token del formulario. Inténtalo de nuevo.');
 const recovering=!!authError;authError=null;current=data;lastSync=Date.now();if(recovering)setTimeout(()=>window.dispatchEvent(new CustomEvent('sf:session-state',{detail:{available:true}})),0);for(const input of document.querySelectorAll('input[name="csrf_token"]'))input.value=data.csrf_token;
 for(const meta of document.querySelectorAll('meta[name="sf-csrf"]'))meta.content=data.csrf_token;
 document.getElementById('sf-session-notice')?.remove();return data;
}
async function sync(force=false){
 if(!force&&authError)throw authError;if(pending)return pending;if(!force&&current&&Date.now()-lastSync<10000)return current;
 const work=async()=>{const c=new AbortController(),timer=setTimeout(()=>c.abort(),12000);try{
 const url=new URL('api_session_sync.php',location.href);if(expected==='')url.searchParams.set('anonymous','1');
 const response=await nativeFetch(url.href,{cache:'no-store',credentials:'same-origin',signal:c.signal});
 if(response.status===401)throw invalidate();
 if([502,503,504].includes(response.status))throw Error('Sparrowfy está temporalmente fuera de servicio. Espera unos segundos y vuelve a intentarlo.');
 let data;
 try{data=await response.json();}catch{throw Error('El servidor devolvió una respuesta inesperada. Vuelve a intentarlo.');}
 if(!response.ok)throw Error(data?.error||'No se pudo verificar la sesión. Tus datos no se enviaron.');
 return apply(data);
 }catch(e){current=null;lastSync=0;if(e.name==='AbortError')throw Error('La conexión tardó demasiado. Tus datos siguen en el formulario; vuelve a intentarlo.');throw e;}finally{clearTimeout(timer);}};
 pending=(async()=>{try{return await (navigator.locks?.request?navigator.locks.request('sparrowfy-session-sync',work):work());}finally{pending=null;}})();return pending;
}
function protectedBody(body){return (body instanceof FormData||body instanceof URLSearchParams)&&body.has('csrf_token');}
window.fetch=async function(input,options={}){
 const url=new URL(typeof input==='string'||input instanceof URL?input:input.url,location.href),method=String(options.method||(input instanceof Request?input.method:'GET')).toUpperCase();
 if(url.origin!==location.origin)return nativeFetch(input,options);
 if(method!=='POST'||!protectedBody(options.body))return inspect(await nativeFetch(input,options));
 try{let state=await sync();options.body.set('csrf_token',state.csrf_token);let response=await nativeFetch(input,options);
 // Retry only an explicit CSRF rejection, never timeouts or ambiguous failures.
 if(response.status>=400&&response.headers.get('X-SparrowFy-CSRF')==='invalid'){
  state=await sync(true);options.body.set('csrf_token',state.csrf_token);response=await nativeFetch(input,options);
 }
 return inspect(response);
 }catch(e){notice(e);throw e;}
};
const allowedSubmit=new WeakSet(),waitingSubmit=new WeakSet();
document.addEventListener('submit',event=>{
 const form=event.target;if(!(form instanceof HTMLFormElement)||event.defaultPrevented)return;
 if(allowedSubmit.has(form)){allowedSubmit.delete(form);return;}
 const submitter=event.submitter,method=(submitter?.hasAttribute('formmethod')?submitter.formMethod:form.method).toUpperCase();
 if(method!=='POST'||new URL(form.action,location.href).origin!==location.origin||!Array.from(form.elements).some(e=>e.name==='csrf_token'))return;
 // Logout must remain usable when account/device validation fails. The native
 // POST still carries the form token and logout.php verifies CSRF server-side.
 if(new URL(form.action,location.href).pathname.endsWith('/logout.php'))return;

 event.preventDefault();event.stopImmediatePropagation();if(waitingSubmit.has(form))return;
 waitingSubmit.add(form);const wasBusy=form.getAttribute('aria-busy');form.setAttribute('aria-busy','true');
 sync().then(()=>{allowedSubmit.add(form);if(form.requestSubmit)form.requestSubmit(submitter||undefined);else HTMLFormElement.prototype.submit.call(form);}).catch(notice).finally(()=>{waitingSubmit.delete(form);allowedSubmit.delete(form);if(wasBusy===null)form.removeAttribute('aria-busy');else form.setAttribute('aria-busy',wasBusy);});
},true);
const resume=()=>{if(document.visibilityState==='hidden'||navigator.onLine===false)return;sync(true).catch(notice);};
window.addEventListener('pageshow',resume);window.addEventListener('online',resume);document.addEventListener('visibilitychange',()=>{if(!document.hidden)resume();});
window.addEventListener('focus',()=>{if(!document.hidden&&navigator.onLine!==false)sync(true).catch(notice);});
window.sfSessionSync={refresh:()=>sync(true),check:()=>sync(),available:()=>!authError};
// Also covers pages embedded after their parent's pageshow event.
resume();
})();
