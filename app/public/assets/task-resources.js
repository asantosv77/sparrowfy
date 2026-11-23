(()=>{'use strict';window.sfTaskResources={attach(root,getJob){let stopped=false,busy=false,timer;
 async function refresh(){if(stopped||busy||!root.isConnected)return;busy=true;try{
  const job=getJob?.()||'',r=await fetch('api_container_memory.php'+(job?'?job_id='+encodeURIComponent(job):''),{cache:'no-store',signal:AbortSignal.timeout(12000)});if(!r.ok)throw Error();const data=await r.json();if(stopped)return;
  window.sfRenderCpu?.(root,data);const node=root.querySelector('[data-node-label]'),ram=root.querySelector('[data-node-ram]'),ring=root.querySelector('[data-node-memory]');if(node)node.textContent=(data.node_name||'Raspberry · www')+(data.stale?' · sin lectura reciente':'');const item=data.containers?.www,valid=!data.stale&&Number.isFinite(item?.percent);if(ram)ram.textContent='RAM: '+(valid?item.percent.toFixed(1)+'%':'—');if(ring){const c=2*Math.PI*Number(ring.getAttribute('r'));ring.style.strokeDasharray=c;ring.style.strokeDashoffset=c*(1-(valid?item.percent:0)/100);}
 }catch{window.sfRenderCpu?.(root,null);const node=root.querySelector('[data-node-label]');if(node)node.textContent='Recursos: lectura no disponible';}finally{busy=false;if(!stopped)timer=setTimeout(refresh,5000);}}
 refresh();return {stop(){stopped=true;clearTimeout(timer);},refresh};
}};})();
