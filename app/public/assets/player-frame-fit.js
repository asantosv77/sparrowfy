/* Sparrow-Fy Stage 14: iframe follows player height, capped to viewport. */
(() => {
  'use strict';
  let desiredHeight = 0;
  const getFrame = () => document.getElementById('labPlayerFrame');
  function fit() {
    const frame = getFrame();
    if (!frame || !desiredHeight) return;
    const available = Math.max(180, window.innerHeight - 24);
    frame.style.height = Math.min(desiredHeight, available) + 'px';
    frame.style.maxHeight = available + 'px';
  }
  window.addEventListener('message', (event) => {
    const frame = getFrame();
    if (!frame || event.source !== frame.contentWindow || event.origin !== window.location.origin) return;
    if (event.data?.source !== 'lab-player' || event.data.type !== 'content-height') return;
    const h = Number(event.data.height);
    if (!Number.isFinite(h) || h < 100 || h > 10000) return;
    desiredHeight = Math.ceil(h);
    fit();
  });
  window.addEventListener('resize', fit);
  document.addEventListener('click', () => {
    const frame = getFrame();
    if (frame && !frame.closest('[hidden]')) {
      frame.contentWindow?.postMessage({ source: 'lab-host', type: 'measure-height' }, window.location.origin);
    }
  });
})();
