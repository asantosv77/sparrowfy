(()=>{'use strict';
const active=new WeakMap();
function shape(cls){const el=document.createElement('span');el.className='sf-loading-shape '+cls;el.setAttribute('aria-hidden','true');return el;}
function start(container,{kind='albums',count=3,label='Cargando…'}={}){
 if(!container)return null;active.get(container)?.stop();
 const group=document.createElement('div');group.className='sf-loading-list sf-loading-list--'+(kind==='covers'?'covers':kind==='tracks'?'tracks':'albums');group.setAttribute('role','status');group.setAttribute('aria-label',label);group.dataset.loadingSkeleton=kind;
 for(let i=0;i<Math.max(1,Math.min(6,count));i++){
  const card=document.createElement('div');card.className='sf-loading-card';card.setAttribute('aria-hidden','true');
  if(kind==='covers')card.append(shape('sf-loading-art'));
  const lines=document.createElement('div');lines.className='sf-loading-lines';lines.append(shape('sf-loading-title'),shape('sf-loading-detail'));card.append(lines);
  if(kind!=='covers')card.append(shape('sf-loading-action'));
  group.append(card);
 }
 const handle={stop(){group.remove();if(active.get(container)===handle){container.removeAttribute('aria-busy');active.delete(container);}}};
 active.set(container,handle);container.setAttribute('aria-busy','true');container.replaceChildren(group);return handle;
}
window.sfLoadingSkeletons={start};
})();
