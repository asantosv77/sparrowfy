(()=>{'use strict';
// Ephemeral, same-origin cache: never persisted or shared across accounts.
window.sfNextBuffer=function(){
 let controller=null,cache=new Map(),key=null,bytes=0;
 const limit=1024*1024;
 function clear(){controller?.abort();controller=null;cache.clear();key=null;bytes=0;}
 function safe(url){const u=new URL(url,location.href);if(u.origin!==location.origin)throw Error('origin');return u.href;}
 async function fetchPart(url,text,signal){
  url=safe(url);const r=await fetch(url,{credentials:'same-origin',cache:'no-store',signal});
  if(!r.ok)throw Error('prefetch');
  const reader=r.body.getReader();let parts=[],size=0;
  try{while(true){const {done,value}=await reader.read();if(done)break;size+=value.byteLength;if(bytes+size>limit)throw Error('limit');parts.push(value);}}catch(e){await reader.cancel();throw e;}
  const all=new Uint8Array(size);let offset=0;for(const p of parts){all.set(p,offset);offset+=p.length;}
  if(signal.aborted)throw Error('cancelled');bytes+=size;
  const data=text?new TextDecoder().decode(all):all.buffer;cache.set(url,data);return data;
 }
 async function prepare(url){
  if(key===url)return;clear();key=url;controller=new AbortController();const signal=controller.signal;
  try{
   let base=safe(url),body=await fetchPart(base,true,signal);
   if(body.includes('#EXT-X-STREAM-INF')){
    const lines=body.split(/\r?\n/);let variants=[];
    for(let i=0;i<lines.length;i++)if(lines[i].startsWith('#EXT-X-STREAM-INF:')){const bw=Number(lines[i].match(/BANDWIDTH=(\d+)/)?.[1]||Infinity);const next=lines.slice(i+1).find(l=>l.trim()&&!l.startsWith('#'));if(next)variants.push({bw,url:new URL(next.trim(),base).href});}
    variants.sort((a,b)=>a.bw-b.bw);if(!variants.length)return;base=variants[0].url;body=await fetchPart(base,true,signal);
   }
   // Encrypted or initialization-map streams use their ordinary loading path.
   if(/#EXT-X-(KEY|MAP):/.test(body))return;
   const segments=body.split(/\r?\n/).map(s=>s.trim()).filter(s=>s&&!s.startsWith('#')).slice(0,2);
   for(const segment of segments)await fetchPart(new URL(segment,base).href,false,signal);
  }catch(e){/* Optional prefetch failure must not affect playback. */}
 }
 function loader(Base){return class extends Base{
  load(context,config,callbacks){
   let url;try{url=safe(context.url);}catch{return super.load(context,config,callbacks);}
   const data=cache.get(url);
   if(data===undefined)return super.load(context,config,callbacks);
   cache.delete(url);this.context=context;const now=performance.now();
   this.stats.loading={start:now,first:now,end:now};this.stats.loaded=this.stats.total=typeof data==='string'?new TextEncoder().encode(data).length:data.byteLength;
   queueMicrotask(()=>{if(!this.stats.aborted)callbacks.onSuccess({url:context.url,data},this.stats,context,null);});
  }
 };}
 function activate(url){if(key===url){controller?.abort();controller=null;}else clear();}
 return {prepare,clear,loader,activate,has:url=>key===url&&cache.size>0};
};
})();
