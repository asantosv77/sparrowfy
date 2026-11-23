(()=>{'use strict';const account=document.querySelector('meta[name="sf-player-account"]')?.content;const key=account?'sf-layout:'+account:null;
function apply(value,save=false){value=value==='panorama'?'panorama':'classic';document.documentElement.dataset.layout=value;document.querySelectorAll('[data-layout-choice]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.layoutChoice===value)));if(save&&key)try{localStorage.setItem(key,value);}catch{}window.dispatchEvent(new Event('resize'));}
let saved='classic';if(key)try{saved=localStorage.getItem(key)||saved;}catch{}apply(saved);
document.addEventListener('click',e=>{const b=e.target.closest('[data-layout-choice]');if(b)apply(b.dataset.layoutChoice,true);},true);
})();
