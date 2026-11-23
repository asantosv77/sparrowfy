(()=>{'use strict';window.sfRenderCpu=(root,data)=>{
 if(!root)return;
 const raw=data?.stale?null:data?.containers?.www?.cpu_percent;
 const valid=typeof raw==='number'&&Number.isFinite(raw),percent=valid?Math.max(0,Math.min(100,raw)):0;
 const color=percent>=90?'#ef4444':percent>=70?'#eab308':'#22c55e';
 for(const rect of root.querySelectorAll('[data-cpu-fill]')){const size=Number(rect.dataset.cpuSize),bottom=Number(rect.dataset.cpuBottom);rect.setAttribute('y',String(bottom-size*percent/100));rect.setAttribute('height',String(size*percent/100));rect.setAttribute('fill',color);}
 for(const label of root.querySelectorAll('[data-cpu-label]'))label.textContent=valid?percent.toFixed(1)+'%':'—';
 for(const meter of root.querySelectorAll('[data-cpu-meter]')){meter.setAttribute('aria-label',valid?'CPU de www: '+percent.toFixed(1)+' por ciento del límite asignado':'CPU de www sin lectura');}
};})();
