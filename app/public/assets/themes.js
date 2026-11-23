/* Sparrow-Fy Stage 8: accessible local theme selector. No API or account data touched. */
(function () {
  'use strict';
  // The server is authoritative: never share a theme between accounts in one browser.
  var allowed = ['dark-teal', 'dark-blue', 'dark-purple', 'light', 'light-sand', 'solarized', 'monokai', 'deep-black', 'ruby-blue'];
  // El servidor ya renderiza el tema en <html data-theme>, así que el primer pintado es el correcto.
  var serverTheme = document.documentElement.dataset.theme || 'dark-teal';
  if (allowed.indexOf(serverTheme) === -1) serverTheme = 'dark-teal';
  var choiceVersion = 0;
  var labels = {
    'dark-teal': '#091519',
    'dark-blue': '#080f1d',
    'dark-purple': '#100b19',
    'light': '#eef2f6',
    'light-sand': '#f4f0e7',
    'solarized': '#002b36',
    'monokai': '#272822',
    'deep-black': '#000000',
    'ruby-blue': '#0b1f33'
  };
  function syncFrame(frame) {
    try {
      var doc = frame && frame.contentDocument;
      if (doc && doc.documentElement) doc.documentElement.dataset.theme = document.documentElement.dataset.theme;
    } catch (_) { /* cross-origin iframe */ }
    try {
      frame && frame.contentWindow && frame.contentWindow.postMessage({source:'lab-host', type:'theme', theme: document.documentElement.dataset.theme}, location.origin);
    } catch (_) {}
  }
  function applyTheme(theme, persist) {
    if (allowed.indexOf(theme) === -1) theme = 'dark-teal';
    document.documentElement.dataset.theme = theme;
    // La página offline.html se sirve sin sesión: guarda el tema para heredarlo.
    try { localStorage.setItem('sf-theme', theme); } catch (_) {}
    // Los paneles y el reproductor viven en iframes propios: propaga el tema al instante.
    document.querySelectorAll('iframe').forEach(syncFrame);
    var meta = document.getElementById('themeColorMeta');
    if (meta) meta.setAttribute('content', labels[theme]);
    document.querySelectorAll('[data-theme-choice]').forEach(function (button) {
      var active = button.getAttribute('data-theme-choice') === theme;
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    if (persist) {
      choiceVersion += 1;
      var currentVersion = choiceVersion;
      var body = new URLSearchParams({theme: theme, csrf_token: document.querySelector('meta[name="sf-csrf"]')?.content || ""});
      fetch('theme_preference.php', {method:'POST', credentials:'same-origin', body:body, headers:{'Accept':'application/json'}})
        .then(function(r){return r.json().catch(function(){return {};}).then(function(data){if(!r.ok || !data.success) throw new Error(data.error || 'No se pudo guardar el tema'); return data;});})
        .then(function(data){if(currentVersion === choiceVersion) serverTheme = data.theme;})
        .catch(function(e){ console.error('Preferencia de tema:', e.message); alert('No se pudo guardar el tema en tu cuenta: ' + e.message); });
    }
    document.dispatchEvent(new CustomEvent('sparrowfy:themechange', { detail: { theme: theme } }));
  }
  document.addEventListener('DOMContentLoaded', function () {
    applyTheme(serverTheme, false);
    // Los iframes creados después (reproductor, editor, configuración) heredan el tema al cargar.
    document.addEventListener('load', function (event) {
      var el = event.target;
      if (el && el.tagName === 'IFRAME') syncFrame(el);
    }, true);
    var versionAtLoad = choiceVersion;
    fetch('theme_preference.php', {credentials:'same-origin', cache:'no-store', headers:{'Accept':'application/json'}})
      .then(function(r){if(!r.ok) throw new Error('Sin sesión');return r.json();})
      .then(function(result){ if(result.success && versionAtLoad === choiceVersion) { serverTheme = result.theme; applyTheme(serverTheme, false); } })
      .catch(function(){ /* Default theme if account is unavailable. */ });
    document.querySelectorAll('[data-theme-choice]').forEach(function (button) {
      button.addEventListener('click', function () {
        applyTheme(button.getAttribute('data-theme-choice'), true);
      });
    });
    var menu = document.getElementById('themeMenu');
    if (menu) {
      var summary = menu.querySelector('summary');
      var panel = menu.querySelector('.theme-menu__panel');
      if (panel && summary) {
        // Move the panel to <body> so the sidebar's overflow-y:auto cannot clip it.
        document.body.appendChild(panel);
        panel.hidden = true;
        panel.style.position = 'fixed';
        panel.style.zIndex = '10000';
        var placePanel = function () {
          if (!menu.open || panel.hidden) return;
          if (window.matchMedia('(max-width: 720px)').matches) {
            panel.style.left = '12px';
            panel.style.right = '12px';
            panel.style.bottom = 'calc(12px + env(safe-area-inset-bottom, 0px))';
            panel.style.top = 'auto';
            panel.style.width = 'auto';
            panel.style.maxWidth = 'none';
            return;
          }
          panel.style.right = 'auto';
          panel.style.bottom = 'auto';
          panel.style.width = '310px';
          panel.style.maxWidth = 'min(310px, calc(100vw - 28px))';
          var anchor = summary.getBoundingClientRect();
          var panelRect = panel.getBoundingClientRect();
          var width = panelRect.width || 270;
          var height = panelRect.height || 320;
          var left = anchor.right + 12;
          if (left + width > window.innerWidth - 12) left = anchor.left - width - 12;
          left = Math.max(12, Math.min(left, window.innerWidth - width - 12));
          var top = Math.max(12, Math.min(anchor.bottom - height, window.innerHeight - height - 12));
          panel.style.left = left + 'px';
          panel.style.top = top + 'px';
        };
        menu.addEventListener('toggle', function () {
          panel.hidden = !menu.open;
          if (menu.open) requestAnimationFrame(placePanel);
        });
        window.addEventListener('resize', placePanel);
        window.addEventListener('scroll', placePanel, true);
        panel.addEventListener('click', function (event) {
          event.stopPropagation();
          if (!event.target.closest('[data-theme-choice],[data-layout-choice],[data-mobile-menu-choice]')) return;
          // Run after the choice handlers; close both the floating panel and mobile drawer.
          menu.open = false;
          panel.hidden = true;
          document.dispatchEvent(new CustomEvent('sf:appearance-selected'));
          var toggle = document.getElementById('sfMobileMenuToggle');
          if (window.matchMedia('(max-width:720px)').matches && document.documentElement.dataset.mobileMenu === 'hamburger' && toggle) toggle.focus();
          else summary.focus();
        });
        document.addEventListener('keydown', function (event) {
          if (event.key === 'Escape' && menu.open) {
            menu.open = false;
            panel.hidden = true;
            summary.focus();
          }
        });
        document.addEventListener('click', function (event) {
          if (menu.open && !menu.contains(event.target) && !panel.contains(event.target)) {
            menu.open = false;
            panel.hidden = true;
          }
        });
      }
    }
  });
})();
