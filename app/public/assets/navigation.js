(()=>{'use strict';
let pending=null,sequence=0;
window.sfNavigate=async(href,{historyMode='push'}={})=>{
 const url=new URL(href,location.href),index=new URL('index.php',location.href);
 if(url.origin!==index.origin||url.pathname!==index.pathname)throw Error('Destino no compatible');
 // Partial catalog navigation is valid only inside the complete application shell.
 if(!document.querySelector('.app-shell .lab-nav')){location.assign(url.href);return false;}
 const request=++sequence;pending?.abort();pending=new AbortController();
 try{
 const r=await fetch(url.href,{credentials:'same-origin',cache:'no-store',signal:pending.signal});
 if(request!==sequence)return false;
 if(r.redirected&&new URL(r.url).pathname!==index.pathname){location.assign(r.url);return false;}
 if(!r.ok)throw Error('No se pudo abrir la sección');
 const doc=new DOMParser().parseFromString(await r.text(),'text/html');
 if(request!==sequence)return false;
 const main=doc.querySelector('.lab-main'),current=document.querySelector('.lab-main');
 if(!main||!current){location.assign(url.href);return false;}
 document.documentElement.dataset.managementView=doc.documentElement.dataset.managementView||'0';
 current.replaceChildren(...Array.from(main.childNodes));
 const view=url.searchParams.get('view')||'home';
 document.querySelectorAll('.lab-nav a').forEach(a=>{const u=new URL(a.href);a.classList.toggle('active',u.pathname===index.pathname&&(u.searchParams.get('view')||'home')===view);});
 if(historyMode==='push'&&url.href!==location.href)history.pushState({labView:url.href},'',url.href);
 window.labBindAlbumMenus?.(current);
 document.dispatchEvent(new CustomEvent('sf:navigated',{detail:{href:url.href}}));return true;
 }catch(e){if(e.name==='AbortError')return false;throw e;}
};
})();
