/* Sparrow-Fy Stage 14: report embedded player content height to its shell. */
(() => {
  'use strict';
  if (!document.body.classList.contains('is-embed') || window.parent === window) return;
  const root = document.getElementById('playerRoot');
  if (!root) return;
  const desktop=window.parent.matchMedia('(min-width: 769px)');
  function mode(){document.body.classList.toggle('sf-desktop-player',desktop.matches);document.body.style.setProperty('--player-screen-height',window.parent.innerHeight+'px');schedule();}
  desktop.addEventListener('change',mode);
  let last = -1;
  let scheduled = false;
  function report(force = false) {
    scheduled = false;
    if(window.parent.document.getElementById('labPlayerHost')?.classList.contains('is-min'))return;
    const queue=document.getElementById('playlist');
    if(desktop.matches && queue){
      const chrome=root.getBoundingClientRect().height-queue.getBoundingClientRect().height;
      queue.style.maxHeight=Math.max(80,window.parent.innerHeight-26-chrome)+'px';
    }else if(queue)queue.style.removeProperty('max-height');
    const rect = root.getBoundingClientRect();
    const height = Math.ceil(rect.height + Math.max(0, rect.top) + 2);
    if (!Number.isFinite(height) || height < 100) return;
    if (!force && Math.abs(height - last) < 2) return;
    last = height;
    window.parent.postMessage({ source: 'lab-player', type: 'content-height', height }, window.location.origin);
  }
  function schedule() {
    if (scheduled) return;
    scheduled = true;
    requestAnimationFrame(() => report());
  }
  if ('ResizeObserver' in window) new ResizeObserver(schedule).observe(root);
  window.addEventListener('load', schedule);
  window.addEventListener('message', (event) => {
    if (event.origin !== window.location.origin || event.data?.source !== 'lab-host') return;
    if (event.data.type === 'measure-height'){mode();report(true);}
  });
  mode();
})();
