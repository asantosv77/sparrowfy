(() => {
  'use strict';
  const key = 'sparrowfy.language';
  let language = 'es';
  try { language = localStorage.getItem(key) || language; } catch {}
  const choice = document.cookie.match(/(?:^|; )sf_language=(en|es)(?:;|$)/);
  if (choice) language = choice[1];
  document.documentElement.lang = language === 'en' ? 'en' : 'es';
  document.querySelectorAll('[data-sf-lang]').forEach(button => {
    button.setAttribute('aria-pressed', String(button.dataset.sfLang === language));
    button.addEventListener('click', () => {
      const value = button.dataset.sfLang;
      try { localStorage.setItem(key, value); } catch {}
      document.cookie = 'sf_language=' + value + '; Path=/; Max-Age=34560000; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
      const target = window.parent !== window && document.referrer && new URL(document.referrer).origin === location.origin ? window.parent : window;
      target.location.replace(target.location.href);
    });
  });
  if (language !== 'en') return;
  const source = document.currentScript.src;
  fetch(new URL('sf-en.json', source)).then(r => {
    if (!r.ok) throw Error('Language catalog unavailable');
    return r.json();
  }).then(dictionary => {
    const done = new WeakMap();
    const clean = value => value.trim().replace(/\s+/g, ' ');
    const numbered = new Map();
    for (const [from,to] of Object.entries(dictionary)) {
      const digits = from.match(/\d+/g);
      if (digits && digits.join('|') === (to.match(/\d+/g)||[]).join('|'))
        numbered.set(from.replace(/\d+/g,'#'),to.replace(/\d+/g,'#'));
    }
    function translate(value) {
      if (!value) return value;
      const text = clean(value);
      let result = Object.hasOwn(dictionary,text) ? dictionary[text] : null;
      if (!result && /\d/.test(text)) {
        const template = numbered.get(text.replace(/\d+/g,'#'));
        if (template) {
          const digits = text.match(/\d+/g);
          result = template.replace(/#/g,()=>digits.shift());
        }
      }
      if (!result) result = text.replace(/([^:·|]+)(?=[:·|]|$)/g, part => {
        const trimmed = clean(part);
        return Object.hasOwn(dictionary,trimmed) ? part.replace(trimmed, dictionary[trimmed]) : part;
      });
      return result === text ? value : value.replace(value.trim(),result);
    }
    const skip='script,style,textarea,pre,code,[translate=no],[contenteditable=true]';
    function apply(node) {
      if (node.nodeType === Node.TEXT_NODE) {
        if (!node.parentElement || node.parentElement.closest(skip) || done.get(node) === node.data) return;
        const v = translate(node.data);
        if (v !== node.data) {done.set(node,v); node.data=v;}
        return;
      }
      if (!(node instanceof Element) || node.matches(skip)) return;
      for (const name of ['title','placeholder','aria-label','alt']) {
        const before = node.getAttribute(name), after = translate(before);
        if (before !== after) node.setAttribute(name,after);
      }
      node.childNodes.forEach(apply);
    }
    apply(document.documentElement);
    new MutationObserver(records => {
      for (const record of records) {
        if (record.type==='childList') record.addedNodes.forEach(apply);
        else apply(record.target);
      }
    }).observe(document.documentElement,{childList:true,subtree:true,characterData:true,attributes:true,attributeFilter:['title','placeholder','aria-label','alt']});
    for (const method of ['alert','confirm','prompt']) {
      const original=window[method].bind(window);
      window[method]=(message,...args)=>original(translate(String(message)),...args);
    }
  }).catch(error => console.warn('Sparrow-Fy:', error.message));
})();
