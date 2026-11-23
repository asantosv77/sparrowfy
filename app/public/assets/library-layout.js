(()=>{'use strict';let grid=null,observer=null,pending=null,sequence=0;
function bind(){const next=document.querySelector('.sf-recent-grid');if(next===grid)return;observer?.disconnect();pending?.abort();sequence++;grid=next;if(!grid)return;
 let loaded=grid.children.length,lastCount=0;
 async function layout(){if(!grid?.isConnected)return;const target=grid;const columns=getComputedStyle(target).gridTemplateColumns.split(' ').filter(Boolean).length;const count=Math.min(Number(target.dataset.homeTotal),Math.max(2,columns*(matchMedia('(max-width: 720px)').matches?3:2)));
 [...target.children].forEach((c,i)=>c.hidden=i>=count);
 if(count<=loaded||count===lastCount)return;lastCount=count;const request=++sequence;pending?.abort();pending=new AbortController();
 try{const r=await fetch('index.php?home_cards='+count,{credentials:'same-origin',cache:'no-store',signal:pending.signal});if(!r.ok||r.redirected)throw Error('No se pudieron cargar las novedades');const html=await r.text();if(request!==sequence||target!==grid)return;const doc=new DOMParser().parseFromString(html,'text/html');target.replaceChildren(...doc.body.children);loaded=target.children.length;window.labBindAlbumMenus?.(target);layout();}
 catch(e){if(e.name!=='AbortError'){lastCount=0;console.warn(e.message);}}
 }
 observer=new ResizeObserver(layout);observer.observe(grid);layout();
}
document.addEventListener('sf:navigated',bind);bind();
})();
