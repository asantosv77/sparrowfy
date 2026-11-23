/* Mobile presentation only. Never changes account permissions or the player. */
(()=>{'use strict';
 const root=document.documentElement,nav=document.getElementById('mobileNavigation'),toggle=document.getElementById('sfMobileMenuToggle'),backdrop=document.getElementById('sfMobileMenuBackdrop');
 if(!nav||!toggle||!backdrop)return;
 const mobile=matchMedia('(max-width:720px)'),account=document.querySelector('meta[name="sf-player-account"]')?.content,key=account?'sf-mobile-menu:'+account:null;
 let variant='current',open=false;
 function position(){if(!open)return;const bottom=toggle.getBoundingClientRect().bottom;root.style.setProperty('--sf-mobile-menu-top',Math.max(8,Math.min(bottom+6,innerHeight-180))+'px');}
 function sync(){
  const drawer=mobile.matches&&variant==='hamburger';
  if(!drawer)open=false;
  root.dataset.mobileMenu=variant;root.classList.toggle('sf-mobile-menu-open',drawer&&open);
  toggle.setAttribute('aria-expanded',String(drawer&&open));toggle.setAttribute('aria-label',open?'Cerrar menú de navegación':'Abrir menú de navegación');
  nav.inert=drawer&&!open;backdrop.hidden=!(drawer&&open);position();
 }
 function close(restore=false){open=false;sync();if(restore&&mobile.matches&&variant==='hamburger')toggle.focus();}
 function apply(value,save=false){variant=value==='hamburger'?'hamburger':'current';open=false;sync();
  document.querySelectorAll('[data-mobile-menu-choice]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.mobileMenuChoice===variant)));
  if(save&&key)try{localStorage.setItem(key,variant);}catch{}
  if(save&&mobile.matches&&variant==='hamburger')toggle.focus();
 }
 let saved='current';if(key)try{saved=localStorage.getItem(key)||saved;}catch{}apply(saved);
 toggle.addEventListener('click',()=>{open=!open;sync();if(open)nav.querySelector('a:not([hidden])')?.focus();});
 backdrop.addEventListener('click',()=>close(true));
 // Capture because the floating theme panel stops bubbling click events.
 document.addEventListener('click',event=>{
  const choice=event.target.closest('[data-mobile-menu-choice]');if(choice){apply(choice.dataset.mobileMenuChoice,true);return;}
  if(!open)return;
  if(event.target.closest('.theme-menu__panel'))return;
  if(nav.contains(event.target)){if(event.target.closest('a'))close();return;}
  if(!toggle.contains(event.target))close();
 },true);
 document.addEventListener('keydown',event=>{if(event.key==='Escape'&&open){close(true);}});
 document.addEventListener('focusin',event=>{if(open&&!nav.contains(event.target)&&!toggle.contains(event.target)&&!event.target.closest('.theme-menu__panel'))close();});
 document.addEventListener('sf:navigated',()=>close());
 document.addEventListener('sf:appearance-selected',()=>close(true));
 mobile.addEventListener('change',sync);window.addEventListener('resize',position);window.addEventListener('scroll',position,{passive:true});
 window.addEventListener('storage',event=>{if(key&&event.key===key)apply(event.newValue);});
})();
