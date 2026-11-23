(() => {
  const splash = document.getElementById('sparrowSplash');
  if (!splash || document.documentElement.classList.contains('sparrow-splash-seen')) return;
  try { sessionStorage.setItem('sparrowfySplashSeen', '1'); } catch (_) {}
  const finish = () => {
    splash.classList.add('is-leaving');
    window.setTimeout(() => { splash.hidden = true; splash.remove(); }, 520);
  };
  const skip = splash.querySelector('[data-splash-skip]');
  if (skip) skip.addEventListener('click', finish, { once: true });
  const delay = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 450 : 1350;
  window.setTimeout(finish, delay);
})();
