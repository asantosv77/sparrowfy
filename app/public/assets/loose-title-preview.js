/* Mobile title disclosure and double-tap playback. Player routing stays in shell-player.js. */
(()=>{'use strict';
 const mobile=matchMedia('(max-width:720px)');let target=null,tip=null,lastTap=null,lastTime=0;
 function hide(){if(target){target.removeAttribute('aria-describedby');target.setAttribute('aria-expanded','false');}target=null;if(tip)tip.hidden=true;}
 function reset(){hide();lastTap=null;lastTime=0;}
 function bind(){document.querySelectorAll('.loose-track-list .sf-loose-title').forEach(el=>{el.tabIndex=mobile.matches?0:-1;if(mobile.matches){el.setAttribute('role','button');el.setAttribute('aria-expanded',String(el===target));}else{el.removeAttribute('role');el.removeAttribute('aria-expanded');}});}
 function show(el){
  if(!mobile.matches||!el.isConnected)return;
  hide();target=el;
  if(!tip){tip=document.createElement('div');tip.id='sfLooseTitlePreview';tip.className='sf-loose-title-preview';tip.setAttribute('role','tooltip');document.body.append(tip);}
  tip.textContent=el.textContent.trim();tip.hidden=false;tip.style.left='10px';tip.style.top='0px';
  el.setAttribute('aria-describedby',tip.id);el.setAttribute('aria-expanded','true');
  const r=el.getBoundingClientRect(),box=tip.getBoundingClientRect();
  const top=r.top>=box.height+12?r.top-box.height-7:r.bottom+7;
  tip.style.top=Math.max(8,Math.min(top,innerHeight-box.height-8))+'px';
 }
 function play(el){const link=el.closest('li')?.querySelector('.job-actions>a[href*="player.php"]');reset();link?.click();}
 document.addEventListener('click',event=>{
  if(!mobile.matches)return;
  const el=event.target.closest('.loose-track-list .sf-loose-title');
  if(!el){reset();return;}
  event.preventDefault();
  const now=Date.now(),double=lastTap===el&&now-lastTime<=350;
  lastTap=el;lastTime=now;
  if(double){play(el);return;}
  if(target===el)hide();else show(el);
 },true);
 // Pointer movement/scroll does not become a tap. Pinch zoom remains available.
 document.addEventListener('contextmenu',event=>{if(mobile.matches&&event.target.closest('.loose-track-list .sf-loose-title'))event.preventDefault();});
 document.addEventListener('dragstart',event=>{if(mobile.matches&&event.target.closest('.loose-track-list .sf-loose-title'))event.preventDefault();},true);
 document.addEventListener('keydown',event=>{
  if(event.key==='Escape'){reset();return;}
  const el=event.target.closest('.loose-track-list .sf-loose-title');if(!mobile.matches||!el)return;
  if(event.key==='Enter'){event.preventDefault();play(el);}
  if(event.key===' '){event.preventDefault();if(target===el)reset();else show(el);}
 });
 document.addEventListener('focusout',event=>{if(event.target===target)reset();});
 document.addEventListener('sf:loose-updated',bind);document.addEventListener('sf:navigated',()=>{reset();bind();});window.addEventListener('scroll',reset,{passive:true,capture:true});window.addEventListener('resize',reset);window.addEventListener('blur',reset);mobile.addEventListener('change',()=>{reset();bind();});bind();
})();
