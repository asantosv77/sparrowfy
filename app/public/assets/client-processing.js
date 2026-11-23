/* Optional local conversion. Only validated server results enter the catalog. */
(()=>{'use strict';if(window.sfClientProcessing)return;
const desktop=()=>matchMedia('(min-width:769px) and (hover:hover) and (pointer:fine)').matches&&!(/Android|iPhone|iPad|iPod/i.test(navigator.userAgent)||(navigator.platform==='MacIntel'&&navigator.maxTouchPoints>1));
const account=document.querySelector('meta[name="sf-player-account"]')?.content||'session',key='sf-client-processing:'+account;let active=0;
const csrf=()=>document.querySelector('meta[name="sf-csrf"]')?.content||document.querySelector('[name="csrf_token"]')?.value||'';
const records=()=>{try{return JSON.parse(localStorage.getItem(key)||'[]');}catch{return [];}};
function save(record){const all=records().filter(r=>r.id!==record.id&&r.updated>Date.now()-86400000);all.push({...record,updated:Date.now()});localStorage.setItem(key,JSON.stringify(all.slice(-50)));}
function notice(id,text,error=false){if(window.sfReplacementNotice){window.sfReplacementNotice(id,text,error);return;}const el=document.createElement('div');el.setAttribute('role','status');el.style.cssText='position:fixed;right:16px;top:80px;max-width:340px;padding:16px;background:var(--card,#102630);color:var(--text,#fff);border:1px solid var(--primary,#10b981);border-radius:12px;z-index:10002';const label=document.createElement('span'),close=document.createElement('button');label.textContent=text;close.textContent='×';close.className='btn';close.onclick=()=>el.remove();el.append(label,close);document.body.append(el);if(!error)setTimeout(()=>{el.style.opacity='0';setTimeout(()=>el.remove(),300);},5000);}
async function api(action,values={}){const body=new FormData();body.set('csrf_token',csrf());body.set('action',action);for(const [k,v] of Object.entries(values))body.set(k,v);const c=new AbortController(),timer=setTimeout(()=>c.abort(),60000);try{const r=await fetch('api_client_processing.php',{method:'POST',body,signal:c.signal});const d=await r.json();if(!r.ok)throw Error(d.error||'Falló la subida local');return d;}finally{clearTimeout(timer);}}
function database(){return new Promise((resolve,reject)=>{const req=indexedDB.open('sparrowfy-client-processing',1);req.onupgradeneeded=()=>req.result.createObjectStore('encoded');req.onsuccess=()=>resolve(req.result);req.onerror=()=>reject(req.error);});}
async function stored(id,value,remove=false){const db=await database();try{return await new Promise((resolve,reject)=>{const tx=db.transaction('encoded',value===undefined&&!remove?'readonly':'readwrite'),store=tx.objectStore('encoded');const req=remove?store.delete(id):value===undefined?store.get(id):store.put(value,id);let result;req.onsuccess=()=>{result=req.result;};tx.oncomplete=()=>resolve(result);tx.onerror=()=>reject(tx.error);tx.onabort=()=>reject(tx.error);});}finally{db.close();}}
const enabled=()=>document.querySelector('meta[name="sf-webcodecs-enabled"]')?.content==='1';
let capabilityCache;
async function capabilities(){if(!enabled())return [];if(capabilityCache)return capabilityCache;if(!isSecureContext||typeof AudioEncoder==='undefined'||typeof AudioData==='undefined'||typeof Worker==='undefined')return [];const rates=[];for(const rate of [32,48,64,96,128,160,192,256,320]){try{if((await AudioEncoder.isConfigSupported({codec:'mp4a.40.2',sampleRate:48000,numberOfChannels:2,bitrate:rate*1000,aac:{format:'aac'}})).supported)rates.push(rate);}catch{}}capabilityCache=rates;return rates;}
async function compatible(){return (await capabilities()).length>0;}
async function send(record,variants,progress=()=>{}){
 const state=await api('status',{id:record.id});if(state.job_id){watch(state.job_id,record.name);return state;}
 record.state='uploading';save(record);progress(80,'Subiendo al servidor: preparando los bloques convertidos…');let completed=0;const total=variants.reduce((n,v)=>n+Math.ceil(v.blob.size/1048576),0),counts={};
 for(const {rate,blob} of variants){counts[rate]=Math.ceil(blob.size/1048576);const done=state.chunks?.[rate]||[];
 for(let index=0;index<counts[rate];index++){if(!done[index]){let failure;for(let attempt=0;attempt<3;attempt++){try{await api('chunk',{id:record.id,rate,index,chunk:blob.slice(index*1048576,(index+1)*1048576)});failure=null;break;}catch(error){failure=error;await new Promise(r=>setTimeout(r,500*(attempt+1)));}}if(failure)throw failure;}completed++;progress(80+completed/total*19,'Subiendo al servidor: bloque '+completed+' de '+total);}}
 progress(99,'Servidor: verificando la subida y preparando la publicación…');const result=await api('finish',{id:record.id,counts:JSON.stringify(counts)});record.state='queued';record.job_id=result.job_id;save(record);await stored(record.id,null,true).catch(()=>{});watch(result.job_id,record.name);return result;
}
function watch(id,name){const jobKey='sf-import-jobs:'+account;let list;try{list=JSON.parse(localStorage.getItem(jobKey)||'[]');}catch{list=[];}if(!list.some(j=>j.id===id)){list.push({id,name});localStorage.setItem(jobKey,JSON.stringify(list));}pollJobs();}
let polling=false;
async function pollJobs(){if(polling)return;polling=true;const jobKey='sf-import-jobs:'+account;try{let list=JSON.parse(localStorage.getItem(jobKey)||'[]'),pending=[];for(const item of list){try{const r=await fetch('api_processing_status.php?id='+encodeURIComponent(item.id),{cache:'no-store'});if(!r.ok){pending.push(item);continue;}const d=await r.json(),j=d.job;if(['done','failed'].includes(j.state)){for(const record of records())if(record.job_id===item.id)save({...record,state:j.state,error:j.error||null});}if(j.state==='done'){window.sfLooseLive?.completed(item.id);notice(item.id,'Pista incorporada: '+item.name);}else if(j.state==='failed')notice(item.id,'No se pudo incorporar '+item.name+': '+j.error,true);else pending.push(item);}catch{pending.push(item);}}localStorage.setItem(jobKey,JSON.stringify(pending));}catch{}finally{polling=false;}}
let childActive=false,allowLeaveUntil=0,pendingMemory=null;
const leaveWarning='La conversión local está en curso. Si cierras esta ventana ahora, quedará inconclusa y la pista no se publicará. Las fuentes descargadas y los bloques ya codificados podrán recuperarse durante 24 horas. ¿Cerrar de todos modos?';
function activity(delta){active=Math.max(0,active+delta);if(!active&&pendingMemory!==null){applyMemoryMiB(pendingMemory);pendingMemory=null;}if(parent!==window)parent.postMessage({source:'sf-client-work',active},location.origin);}
window.sfResetLocalChild=()=>{childActive=false;};
window.sfAllowLocalLeave=()=>{allowLeaveUntil=Date.now()+5000;};
window.sfLocalWorkActive=()=>active>0||childActive;
window.sfConfirmLocalLeave=()=>{if(!active&&!childActive)return true;if(!confirm(leaveWarning))return false;allowLeaveUntil=Date.now()+5000;document.getElementById('labConfigFrame')?.contentWindow?.sfAllowLocalLeave?.();return true;};
window.addEventListener('message',e=>{if(e.origin!==location.origin)return;const d=e.data;if(!d)return;if(d.source==='sf-client-work'&&e.source===document.getElementById('labConfigFrame')?.contentWindow)childActive=Number(d.active)>0;else if(d.source==='sf-account-preferences'&&d.type==='client-memory-changed')applyUserMemory(d.mib);});
window.addEventListener('beforeunload',e=>{if((active||childActive)&&Date.now()>allowLeaveUntil){e.preventDefault();e.returnValue=leaveWarning;}});
async function sourceDuration(file){return new Promise((resolve,reject)=>{const audio=new Audio(),url=URL.createObjectURL(file);const finish=(error)=>{clearTimeout(timer);audio.onloadedmetadata=audio.onerror=null;const d=audio.duration;audio.removeAttribute('src');audio.load();URL.revokeObjectURL(url);if(error)reject(error);else resolve(d);};const timer=setTimeout(()=>finish(Error('No se pudo leer la duración local; utiliza el servidor')),20000);audio.preload='metadata';audio.onloadedmetadata=()=>finish();audio.onerror=()=>finish(Error('El navegador no reconoce este formato; utiliza el servidor'));audio.src=url;});}
// Presupuesto de PCM segun la memoria declarada por el navegador (Chrome la
// limita a 8 GiB). Safari/Firefox no la exponen y usan el tramo conservador.
// La cuenta gestora puede fijarlo en Mi cuenta; el valor global de Configuracion
// actua como predeterminado cuando la preferencia personal es automatica.
const deviceGiB=(()=>{const v=Number(navigator.deviceMemory);return Number.isFinite(v)&&v>0?v:0;})();
const defaultMiB=(()=>{const v=Number(document.querySelector('meta[name="sf-client-memory-default-mib"]')?.content);return Number.isFinite(v)&&v>0?Math.max(128,Math.min(640,v)):0;})();
let configuredMiB=(()=>{const v=Number(document.querySelector('meta[name="sf-client-memory-mib"]')?.content);return Number.isFinite(v)&&v>0?Math.max(128,Math.min(640,v)):0;})();
let PCM_LIMIT=0,PCM_MAX_SAMPLES=0,FILE_LIMIT=0,FILE_LIMIT_MIB=0;
function applyMemoryMiB(mib){configuredMiB=mib>0?Math.max(128,Math.min(640,mib)):0;PCM_LIMIT=configuredMiB?configuredMiB*1048576:(deviceGiB>=8?512*1048576:deviceGiB>=4?384*1048576:256*1048576);PCM_MAX_SAMPLES=PCM_LIMIT/8;FILE_LIMIT=Math.max(128*1048576,PCM_LIMIT/2);FILE_LIMIT_MIB=Math.round(FILE_LIMIT/1048576);}
applyMemoryMiB(configuredMiB);
function applyUserMemory(mib){const n=Number(mib);const effective=Number.isFinite(n)&&n>0?Math.max(128,Math.min(640,n)):defaultMiB;if(active||childActive){pendingMemory=effective;return;}applyMemoryMiB(effective);}
function webmLike(file){const type=(file?.type||'').toLowerCase();return type.includes('webm')||/\.(webm|weba)$/i.test(file?.name||'');}
function ebmlVint(u8,p,end,asId=false){
 if(p>=end)throw Error('WebM truncado');const first=u8[p];if(!first)throw Error('WebM inválido');
 let length=1,mask=0x80,limit=asId?4:8;while(length<limit&&!(first&mask)){mask>>=1;length++;}
 if(!(first&mask)||p+length>end)throw Error('WebM inválido');
 if(asId){let value=0;for(let i=0;i<length;i++)value=value*256+u8[p+i];return{length,value,unknown:false};}
 let value=first&(mask-1),all=value===(mask-1);
 for(let i=1;i<length;i++){const b=u8[p+i];value=value*256+b;if(b!==255)all=false;}
 return{length,value,unknown:all};
}
function ebmlSigned(u8,p,end){const v=ebmlVint(u8,p,end);return{length:v.length,value:v.value-(Math.pow(2,7*v.length-1)-1)};}
function ebmlElement(u8,p,end){const id=ebmlVint(u8,p,end,true),size=ebmlVint(u8,p+id.length,end);const data=p+id.length+size.length;return{id:id.value,size:size.value,unknown:size.unknown,data,end:size.unknown?end:Math.min(end,data+size.value)};}
function ebmlUint(u8,start,end){let value=0;for(let i=start;i<end;i++)value=value*256+u8[i];return value;}
function ebmlFloat(u8,start,end){const n=end-start;if(n!==4&&n!==8)return NaN;const view=new DataView(u8.buffer,u8.byteOffset+start,n);return n===8?view.getFloat64(0,false):view.getFloat32(0,false);}
function ebmlString(u8,start,end){return new TextDecoder().decode(u8.subarray(start,end));}
function webmTrack(u8,start,end){
 const track={number:0,type:0,codec:'',private:null,rate:48000,channels:0};
 let p=start;
 while(p<end){const el=ebmlElement(u8,p,end),q=el.data,e=el.end;
  switch(el.id){
   case 0xD7:track.number=ebmlUint(u8,q,e);break;
   case 0x83:track.type=ebmlUint(u8,q,e);break;
   case 0x86:track.codec=ebmlString(u8,q,e);break;
   case 0x63A2:track.private=u8.subarray(q,e);break;
   case 0xE1:{let a=q;while(a<e){const audio=ebmlElement(u8,a,e);if(audio.id===0xB5)track.rate=ebmlFloat(u8,audio.data,audio.end)||48000;else if(audio.id===0x9F)track.channels=ebmlUint(u8,audio.data,audio.end);if(audio.end<=a)break;a=audio.end;}break;}
  }
  if(el.end<=p)break;p=el.end;
 }
 return track;
}
function webmTracks(u8,start,end){const list=[];let p=start;while(p<end){const el=ebmlElement(u8,p,end);if(el.id===0xAE)list.push(webmTrack(u8,el.data,el.end));if(el.end<=p)break;p=el.end;}return list;}
function webmAudioTrack(list){const audio=list.filter(track=>track.type===2);return audio.find(track=>track.codec==='A_OPUS')||audio[0]||null;}
function opusSamplesPerPacket(u8,start,end){
 if(start>=end)return 0;const toc=u8[start],config=toc>>3,code=toc&3;
 const frameMs=config<12?[10,20,40,60][config&3]:config<16?[10,20,10,20][config&3]:[2.5,5,10,20][config&3];
 const count=code===0?1:code<3?2:((u8[start+1]||0)&63)||1;
 return Math.round(frameMs*48)*count;
}
function webmBlock(u8,start,end,clusterTime,ctx){
 let p=start;const number=ebmlVint(u8,p,end);p+=number.length;
 if(p+3>end)throw Error('WebM bloque truncado');
 let relative=(u8[p]<<8)|u8[p+1];if(relative&0x8000)relative-=0x10000;p+=2;
 const flags=u8[p++];if(!ctx.track||number.value!==ctx.track.number)return;
 const lacing=(flags>>1)&3,frames=[];
 if(lacing===0)frames.push([p,end]);
 else{
  const count=(u8[p++]||0)+1;
  if(lacing===2){const size=Math.floor((end-p)/count);if(size<1||size*count!==end-p)throw Error('WebM lacing inválido');for(let i=0;i<count;i++)frames.push([p+i*size,p+(i+1)*size]);}
  else if(lacing===1){const sizes=[];for(let i=0;i<count-1;i++){let size=0;while(p<end&&u8[p]===255){size+=255;p++;}if(p>=end)throw Error('WebM lacing truncado');sizes.push(size+u8[p++]);}let used=sizes.reduce((a,b)=>a+b,0);sizes.push(end-p-used);for(const size of sizes){if(size<0||p+size>end)throw Error('WebM lacing inválido');frames.push([p,p+size]);p+=size;}}
  else{const sizes=[];let previous=0;for(let i=0;i<count-1;i++){if(i===0){const value=ebmlVint(u8,p,end);p+=value.length;previous=value.value;}else{const value=ebmlSigned(u8,p,end);p+=value.length;previous+=value.value;}if(previous<0||p>end)throw Error('WebM lacing inválido');sizes.push(previous);}let used=sizes.reduce((a,b)=>a+b,0);sizes.push(end-p-used);for(const size of sizes){if(size<0||p+size>end)throw Error('WebM lacing inválido');frames.push([p,p+size]);p+=size;}}
 }
 const time=(clusterTime+relative)*ctx.scale/1e6;
 for(const [s,e] of frames)if(e>s)ctx.packets.push({start:s,end:e,time});
}
const WEBM_SEGMENT_IDS=new Set([0x1F43B675,0x1C53BB6B,0x1254C367,0x1043A770,0x1941A469,0x114D9B74,0x18538067]);
function webmCluster(u8,start,end,ctx,unknown){
 let p=start,time=0;
 while(p<end){
  const el=ebmlElement(u8,p,end);
  if(unknown&&WEBM_SEGMENT_IDS.has(el.id))return p;
  if(el.id===0xE7)time=ebmlUint(u8,el.data,el.end);
  else if(el.id===0xA3)webmBlock(u8,el.data,el.end,time,ctx);
  else if(el.id===0xA0){let q=el.data;while(q<el.end){const block=ebmlElement(u8,q,el.end);if(block.id===0xA1)webmBlock(u8,block.data,block.end,time,ctx);if(block.end<=q)break;q=block.end;}}
  if(el.end<=p)break;p=el.end;
 }
 return p;
}
function parseWebm(data){
 const u8=new Uint8Array(data);if(u8.length<16)throw Error('WebM vacío o truncado');
 const header=ebmlElement(u8,0,u8.length);if(header.id!==0x1A45DFA3)throw Error('No es un contenedor WebM válido');
 const ctx={packets:[],tracks:[],track:null,scale:1e6,durationMs:0};let found=false,p=header.end;
 while(p<u8.length){
  const el=ebmlElement(u8,p,u8.length);
  if(el.id===0x18538067){
   found=true;const end=el.unknown?u8.length:el.end;let q=el.data;
   while(q<end){const child=ebmlElement(u8,q,end);
    if(child.id===0x1549A966){let i=child.data;while(i<child.end){const info=ebmlElement(u8,i,child.end);if(info.id===0x2AD7B1)ctx.scale=ebmlUint(u8,info.data,info.end)||1e6;else if(info.id===0x4489)ctx.durationMs=ebmlFloat(u8,info.data,info.end)*ctx.scale/1e6;if(info.end<=i)break;i=info.end;}}
    else if(child.id===0x1654AE6B){ctx.tracks.push(...webmTracks(u8,child.data,child.end));if(!ctx.track)ctx.track=webmAudioTrack(ctx.tracks);}
    else if(child.id===0x1F43B675){if(child.unknown){q=webmCluster(u8,child.data,end,ctx,true);continue;}webmCluster(u8,child.data,child.end,ctx,false);}
    if(child.end<=q)break;q=child.end;
   }
  }
  if(el.unknown||el.end<=p)break;p=el.end;
 }
 if(!found)throw Error('No se encontró el segmento WebM');return ctx;
}
function opusHead(channels,rate){
 const head=new Uint8Array(19);head.set([79,112,117,115,72,101,97,100],0);head[8]=1;head[9]=Math.max(1,Math.min(255,channels||2));
 const pre=312;head[10]=pre&255;head[11]=(pre>>8)&255;const hz=Math.max(1,rate||48000);head[12]=hz&255;head[13]=(hz>>8)&255;head[14]=(hz>>16)&255;head[15]=(hz>>24)&255;
 return head;
}
async function decodeWebm(data,progress=()=>{}){
 if(typeof AudioDecoder==='undefined')throw Error('Este navegador no puede decodificar WebM con WebCodecs');
 const u8=new Uint8Array(data),parsed=parseWebm(data),track=parsed.track||webmAudioTrack(parsed.tracks);
 if(!track)throw Error('El WebM no contiene ninguna pista de audio');
 if(track.codec!=='A_OPUS')throw Error('El audio WebM usa '+track.codec+'; este equipo solo normaliza Opus');
 if(!parsed.packets.length)throw Error('El WebM no contiene muestras de audio');
 let estimate=0;for(const packet of parsed.packets)estimate+=opusSamplesPerPacket(u8,packet.start,packet.end);
 if(estimate>PCM_MAX_SAMPLES)throw Error('El audio WebM supera el límite de memoria local; utiliza el servidor');
 const rate=48000,head=track.private&&track.private.length>=19&&ebmlString(track.private,0,8)==='OpusHead'?track.private:opusHead(track.channels||2,track.rate||rate),headChannels=head.length>=10?head[9]:0;
 const description=head,numberOfChannels=Math.max(1,Math.min(255,headChannels||track.channels||2));
 const config={codec:'opus',sampleRate:rate,numberOfChannels,description};
 if(typeof AudioDecoder.isConfigSupported==='function'){const supported=await AudioDecoder.isConfigSupported(config);if(!supported.supported)throw Error('Este equipo no admite la decodificación Opus');}
 let capacity=Math.min(PCM_MAX_SAMPLES,Math.max(rate,estimate+rate)),out=[new Float32Array(capacity),new Float32Array(capacity)],written=0,failure=null;
 const ensure=frames=>{if(written+frames<=capacity)return;const needed=written+frames;if(needed>PCM_MAX_SAMPLES)throw Error('El audio WebM supera el límite de memoria local; utiliza el servidor');const size=Math.min(PCM_MAX_SAMPLES,Math.max(capacity*2,needed)),grown=[new Float32Array(size),new Float32Array(size)];grown[0].set(out[0].subarray(0,written));grown[1].set(out[1].subarray(0,written));out=grown;capacity=size;};
 let decoder;
 try{
  decoder=new AudioDecoder({output(audioData){try{const frames=audioData.numberOfFrames,planes=[];for(let channel=0;channel<Math.min(2,Math.max(1,audioData.numberOfChannels));channel++){const plane=new Float32Array(frames),options={planeIndex:channel};if(audioData.format!=='f32-planar')options.format='f32-planar';audioData.copyTo(plane,options);planes.push(plane);}if(!planes.length)throw Error('El decodificador devolvió audio sin canales');ensure(frames);out[0].set(planes[0],written);out[1].set(planes[1]||planes[0],written);written+=frames;}catch(error){failure=error;}finally{audioData.close();}},error(error){failure=error;}});
  decoder.configure(config);
  for(let i=0;i<parsed.packets.length;i++){
   if(failure)throw failure;const packet=parsed.packets[i],timestamp=Number.isFinite(packet.time)?Math.max(0,Math.round(packet.time*1000)):0;
   decoder.decode(new EncodedAudioChunk({type:'key',timestamp,data:u8.subarray(packet.start,packet.end)}));
   if(decoder.decodeQueueSize>24)await new Promise(resolve=>decoder.addEventListener('dequeue',resolve,{once:true}));
   if(i%250===0)progress(Math.min(60,(i+1)/parsed.packets.length*60),'Extrayendo audio PCM del WebM en este equipo…');
  }
  await decoder.flush();if(failure)throw failure;
 }finally{try{decoder&&decoder.close();}catch{}}
 if(written<1)throw Error('El decodificador no devolvió muestras de audio');
 return{channels:[out[0].subarray(0,written),out[1].subarray(0,written)],duration:written/rate,sampleRate:rate,normalized:true};
}
async function decodeNative(data,file,progress=()=>{}){
 progress(0,'Leyendo audio en este equipo…');
 const duration=await sourceDuration(file);if(!Number.isFinite(duration)||duration<=0||duration>1800||duration*rateBytes>PCM_LIMIT)throw Error('El audio supera el límite de memoria local; utiliza el servidor');
 const ctx=new AudioContext({sampleRate:48000});
 try{const buffer=await ctx.decodeAudioData(data);if(buffer.duration>1800||buffer.length*8>PCM_LIMIT)throw Error('El audio requiere demasiada memoria para el procesamiento local; utiliza el servidor');
  const channels=[buffer.getChannelData(0).slice(),buffer.getChannelData(Math.min(1,buffer.numberOfChannels-1)).slice()];return{channels,duration:buffer.duration,sampleRate:buffer.sampleRate,normalized:false};
 }finally{await ctx.close();}
}
const rateBytes=48000*8;
async function decodeLocal(file,progress=()=>{}){
 const data=await file.arrayBuffer(),isWebm=webmLike(file);let webmError=null;
 if(isWebm){progress(0,'WebM detectado: extrayendo audio PCM en este equipo…');try{return await decodeWebm(data,progress);}catch(error){webmError=error;}}
 try{const decoded=await decodeNative(data,file,progress);return isWebm?{...decoded,normalized:true}:decoded;}catch(error){if(webmError)throw Error(webmError.message+'. '+error.message);throw error;}
}
async function upload(file,albumId,progress=()=>{},options={}){
 const checkbox=options.local!==undefined?{checked:options.local}:document.querySelector('#addTracksForm [data-local-processing],#createForm [data-local-processing],#loose-upload [data-local-processing]');
 const server=async()=>{progress(0,'Enviando al servidor; esperará su turno en la cola');const body=new FormData();body.set('csrf_token',csrf());body.set('album_id',albumId);body.set('audio',file);if(options.replaceTrackId)body.set('track_id',options.replaceTrackId);if(options.sourceId){body.delete('audio');body.set('id',options.sourceId);body.set('filename',file.name);if(options.sourceRecovery)body.set('recovery_reason','client_transfer_failed');if(options.replaceTrackId)body.set('replace_track_id',options.replaceTrackId);}const r=await fetch(options.sourceId?'api_client_source.php':options.replaceTrackId?'api_replace_track.php':'api_convert.php',{method:'POST',body});const d=await r.clone().json();if(d.job_id){watch(d.job_id,file.name);notice(d.job_id+'-queued','En cola: '+file.name+'. Se notificará cuando esté disponible.');}return r;};
 if(!enabled()||!desktop()||!checkbox?.checked)return server();
 // Incompatibilidades: decidir en automático y avisar en el estado visible.
 // Nunca bloquear la importación con una pregunta.
 const useServer=async(reason,onlyThis=false)=>{if(!onlyThis&&checkbox?.isConnected)checkbox.checked=false;notice('local-server-'+Date.now(),reason+' Se procesará en el servidor; el avance aparecerá en la cola.',false);progress(0,reason+' Se procesará en el servidor; esperará su turno en la cola');return await server();};
 if(!await compatible())return useServer('Este equipo no puede codificar AAC con WebCodecs.');
  if(file.size>FILE_LIMIT&&!(!options.sourceId&&/\.mp3$/i.test(file.name)))return useServer('El archivo supera el límite local de '+FILE_LIMIT_MIB+' MiB.');
 let record,worker;activity(1);
 try{
  const decoded=await decodeLocal(file,progress);if(decoded.duration>1800||decoded.channels[0].length*8>PCM_LIMIT)throw Error('El audio requiere demasiada memoria para el procesamiento local; utiliza el servidor');
  const normalizedName=decoded.normalized?((file.name||'pista').replace(/\.[^.]+$/,'')||'pista')+'.m4a':file.name;
  const init=await api('init',{album_id:albumId,filename:normalizedName,duration:decoded.duration,size:file.size,capabilities:JSON.stringify(await capabilities()),replace_track_id:options.replaceTrackId||'',source_id:options.sourceId||''});record={id:init.id,name:file.name,album:albumId,state:'encoding',sourceId:options.sourceId||'',replaceTrackId:options.replaceTrackId||'',created:Date.now()};save(record);if(options.sourceId){const parentRecord=records().find(r=>r.id===options.sourceId);if(parentRecord)save({...parentRecord,state:'source_processing'});}
  const channels=decoded.channels;
  progress(0,'Procesando en este equipo: iniciando conversión AAC…');const variants=await new Promise((resolve,reject)=>{worker=new Worker('assets/client-encoder-worker.js?v=2');const all=[],timer=setTimeout(()=>reject(Error('La conversión local excedió diez minutos')),600000);worker.onmessage=({data})=>{if(data.error){clearTimeout(timer);reject(Error(data.error));}else if(data.variant)all.push(data.variant);else if(data.done){clearTimeout(timer);resolve(all);}else progress(data.progress*.8,'Procesando en este equipo: '+data.stage);};worker.onerror=e=>{clearTimeout(timer);reject(Error(e.message||'Falló el procesador local'));};worker.postMessage({channels,sampleRate:48000,rates:init.rates},channels.map(c=>c.buffer));});worker.terminate();worker=null;
  await stored(record.id,{variants,created:Date.now(),account});record.state='ready';save(record);const d=await send(record,variants,progress);return new Response(JSON.stringify(d),{status:202,headers:{'Content-Type':'application/json'}});
 }catch(error){if(!record)return await useServer(error.message+'.',true);record.state='interrupted';record.error=error.message;save(record);api('interrupted',{id:record.id}).catch(()=>{});notice('local-error-'+Date.now(),error.message+'. La conversión local quedó inconclusa; los bloques temporales se conservan hasta 24 horas. Usa «Reanudar subidas locales».',true);throw error;}
 finally{worker?.terminate();activity(-1);}
}
async function youtube(url,name,progress=()=>{},options={}){
 const body=new FormData();body.set('csrf_token',csrf());body.set('url',url);body.set('title',name||'Pista de YouTube');body.set('album_id',options.albumId||'');
 const local=desktop()&&options.local!==false&&await compatible();
 if(enabled()&&desktop()&&options.local!==false&&!local)notice('youtube-server-'+Date.now(),'Este equipo no puede codificar AAC localmente. YouTube se descargará y convertirá en el servidor; el avance aparecerá en la cola.',false);
 if(local)body.set('local','1');if(options.formatId)body.set('format_id',options.formatId);for(const field of ['cookies_file','cookies_text']){const value=options.extraBody?.get(field);if(value)body.set(field,value);}
 if(!local&&options.replaceTrackId){body.set('track_id',options.replaceTrackId);const response=await fetch('api_replace_track.php',{method:'POST',body});return response;}
 const r=await fetch('api_add_youtube.php',{method:'POST',body});if(!local){const d=await r.clone().json();if(d.job_id)watch(d.job_id,name);return r;}
 const d=await r.json();if(!r.ok)throw Error(d.error||'No se pudo preparar la descarga');const record={id:d.id,job_id:d.job_id,album:d.album_id,name:name||'Pista de YouTube',replaceTrackId:options.replaceTrackId||'',state:'source_waiting',created:Date.now()};save(record);return resumeYoutube(record,progress);
}
async function mornye(url,name,progress=()=>{},options={}){
 const local=desktop()&&options.local!==false&&await compatible();const body=new FormData();body.set('csrf_token',csrf());body.set('url',url);body.set('title',name);body.set('album_id',options.albumId||'');body.set('action',options.replaceTrackId?'replace':'add');if(options.duplicateChoice)body.set('duplicate_choice',options.duplicateChoice);if(options.replaceTrackId)body.set('track_id',options.replaceTrackId);if(local)body.set('local','1');
 if(enabled()&&options.local!==false&&!local){progress(0,'Este navegador no permite conversión local; se procesará en el servidor.');notice('mornye-server-'+Date.now(),'Este navegador no permite conversión AAC local. Mornye se procesará en el servidor.',false);}
 const r=await fetch('api_mornye.php',{method:'POST',body});if(!local)return r;const d=await r.json();if(!r.ok||!d.id)throw Error(d.error||'No se pudo iniciar Mornye');const record={id:d.id,job_id:d.job_id,album:d.album_id,name,provider:'Mornye',replaceTrackId:options.replaceTrackId||'',state:'source_waiting',created:Date.now()};save(record);return resumeYoutube(record,progress);
}
async function resumeMornye(record,progress=()=>{}){
 const prior=records().find(r=>r.sourceId===record.id&&['ready','uploading','interrupted','queued','done'].includes(r.state));
 if(prior){if(prior.job_id&&['queued','done'].includes(prior.state))return new Response(JSON.stringify({job_id:prior.job_id}),{status:202});const blocks=await stored(prior.id);if(blocks?.variants){const result=await send(prior,blocks.variants,progress);return new Response(JSON.stringify(result),{status:202});}}
 const saved=records().find(r=>r.id===record.id);const source={...record,...saved,provider:'Mornye'};save(source);return resumeYoutube(source,progress);
}
async function mornyePreflight(id){
 const body=new FormData();body.set('csrf_token',csrf());body.set('action','preflight');body.set('id',id);body.set('capabilities',JSON.stringify(await capabilities()));
 const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),15000);
 try{const response=await fetch('api_client_source.php',{method:'POST',body,cache:'no-store',signal:controller.signal});const data=await response.json();if(!response.ok||typeof data.local_supported!=='boolean')throw Error(data.error||'No se pudo comprobar el codificador local');return data;}finally{clearTimeout(timer);}
}
async function mornyeSourceBlob(id,progress){
 let last;for(let attempt=1;attempt<=2;attempt++){
  const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),45000);
  try{progress(0,'Recuperando fuente Mornye en la PC · intento '+attempt+' de 2');
   const response=await fetch('api_client_source.php?id='+encodeURIComponent(id),{cache:'no-store',signal:controller.signal});
   if(!response.ok||response.redirected||!response.headers.get('Content-Type')?.startsWith('application/octet-stream'))throw Error('Fuente no disponible (HTTP '+response.status+'); comprueba la sesión');
   const buffer=await response.arrayBuffer();if(!buffer.byteLength)throw Error('La transferencia devolvió un archivo vacío');return {buffer,type:response.headers.get('Content-Type')||'application/octet-stream'};
  }catch(error){last=error;}finally{clearTimeout(timer);}
 }throw Error('No se pudo transferir el audio de Mornye a esta PC tras dos intentos ('+(last?.name==='AbortError'?'tiempo de espera agotado':last?.message||'red interrumpida')+')');
}
async function resumeYoutube(record,progress=()=>{}){
 activity(1);try{
  let job;for(;;){const r=await fetch('api_processing_status.php?id='+encodeURIComponent(record.job_id),{cache:'no-store'});const d=await r.json().catch(()=>({}));if(!r.ok||!d.job)throw Error(d.error||'No se pudo consultar la descarga. Comprueba tu sesión.');job=d.job;if(job.state==='failed')throw Error(job.error||'Falló la descarga');if(job.state==='done')break;progress(0,(job.state==='queued'?'Descarga en servidor: esperando turno':'Descargando de '+(record.provider||'YouTube')+' en el servidor: '+(job.stage||'descarga en curso'))+' · Después se convertirá en este equipo. Mantén la pestaña abierta.');await new Promise(resolve=>setTimeout(resolve,2000));}
  const info=job.result;if(!info?.source_id)throw Error('No se recibió una fuente descargable');record.state='source_ready';save(record);const sourceExt=(info.filename||'youtube.m4a').split('.').pop(),reviewedName=record.name&&record.name!=='Pista de YouTube'?record.name.replace(/\.(audio|mp3|m4a|aac|opus|ogg|webm|mp4|flac|wav|aiff|alac)$/i,''):null,sourceFilename=reviewedName?reviewedName+'.'+sourceExt:info.filename||'youtube.m4a';if(info.duration>1800||info.duration*48000*8>PCM_LIMIT){notice(record.id+'-server','La fuente requiere demasiada memoria local; se convertirá en el servidor sin volver a descargarla.',false);const fallback=await upload(new File([],sourceFilename),record.album,progress,{local:false,sourceId:record.id,replaceTrackId:record.replaceTrackId});record.state='done';save(record);return fallback;}if(record.provider==='Mornye'){
 const check=await mornyePreflight(info.source_id);
 if(!check.local_supported){const message='Esta PC no admite toda la escalera AAC configurada. El servidor continuará con la fuente ya descargada. '+check.reason;progress(0,message);notice(record.id+'-capability',message,false);const result=await upload(new File([],sourceFilename),record.album,progress,{local:false,sourceId:record.id,replaceTrackId:record.replaceTrackId});record.state='done';save(record);return result;}
 }progress(0,'Descargando la fuente del servidor a este equipo…');let blob,sourceBuffer,sourceType;try{if(record.provider==='Mornye'){const transferred=await mornyeSourceBlob(info.source_id,progress);sourceBuffer=transferred.buffer;sourceType=transferred.type;}else{const response=await fetch('api_client_source.php?id='+encodeURIComponent(info.source_id),{cache:'no-store'});if(!response.ok||response.redirected||!response.headers.get('Content-Type')?.startsWith('application/octet-stream'))throw Error('La fuente ya no está disponible o tu sesión venció; vuelve a iniciar sesión.');blob=await response.blob();sourceType=blob.type;}}catch(error){if(record.provider!=='Mornye')throw error;progress(0,error.message+'. Recuperando la conversión en el servidor sin descargar otra vez de Mornye…');let fallback;try{fallback=await upload(new File([],sourceFilename),record.album,progress,{local:false,sourceId:record.id,replaceTrackId:record.replaceTrackId,sourceRecovery:true});}catch(recoveryError){throw Error(error.message+'. No se pudo confirmar la continuación en el servidor ('+recoveryError.message+'). La fuente queda conservada; recupera la conexión y usa Reanudar subidas locales.');}const result=await fallback.clone().json();if(!fallback.ok||!result.job_id)throw Error(error.message+'. No se pudo continuar en el servidor: '+(result.error||'respuesta no válida')+'. Usa Reanudar subidas locales.');record.state='done';save(record);notice(record.id+'-transfer-recovered',error.message+'. La fuente está conservada: la conversión continuará en el servidor; recibirás el resultado.',false);return fallback;}const file=new File([sourceBuffer||blob],sourceFilename,{type:sourceType});
  const result=await upload(file,record.album,(p,text)=>progress(p,text),{local:true,sourceId:record.id,replaceTrackId:record.replaceTrackId});record.state='done';save(record);return result;
 }catch(error){const current=records().find(r=>r.id===record.id);save({...record,state:current?.state||record.state,error:error.message});throw error;}finally{activity(-1);}
}
async function resumePanel(){
 const dialog=document.createElement('dialog');dialog.style.cssText='max-width:600px;width:90vw;max-height:85vh;overflow:auto;background:var(--card,#102630);color:var(--text,#fff);border:1px solid var(--border,#315563);border-radius:14px;padding:20px';const title=document.createElement('h2');title.textContent='Subidas locales pendientes';const close=document.createElement('button');close.className='btn';close.textContent='Cerrar';close.onclick=()=>dialog.close();dialog.append(title,close);
 for(const record of records().filter(r=>['encoding','ready','uploading','interrupted','source_waiting','source_ready'].includes(r.state)&&r.updated>Date.now()-86400000)){const row=document.createElement('p'),text=document.createElement('span'),resume=document.createElement('button'),discard=document.createElement('button');text.textContent=record.name+' · '+record.state+' ';resume.textContent='Reanudar subida';discard.textContent='Descartar';resume.className=discard.className='btn';row.append(text,resume,discard);dialog.append(row);
 resume.onclick=async()=>{resume.disabled=true;activity(1);try{if(record.state==='source_waiting'||record.state==='source_ready'){await resumeYoutube(record,(p,s)=>text.textContent=Math.round(p)+'% · '+s);text.textContent='Conversión enviada para publicar.';return;}const saved=await stored(record.id);if(!saved?.variants&&record.sourceId){const source=records().find(r=>r.id===record.sourceId);if(source){await resumeYoutube(source,(p,s)=>text.textContent=Math.round(p)+'% · '+s);save({...record,state:'done'});api('discard',{id:record.id}).catch(()=>{});text.textContent='En cola para publicar.';return;}}if(!saved?.variants)throw Error('La codificación no terminó antes de cerrar. Selecciona el archivo original otra vez; el audio aún no está registrado.');await send(record,saved.variants,(p,s)=>text.textContent=Math.round(p)+'% · '+s);text.textContent='En cola. Recibirás una notificación al finalizar.';}catch(error){text.textContent=error.message;resume.disabled=false;}finally{activity(-1);}};
 discard.onclick=async()=>{try{await api('discard',{id:record.id});await stored(record.id,null,true);localStorage.setItem(key,JSON.stringify(records().filter(r=>r.id!==record.id)));row.remove();}catch(error){text.textContent=error.message;}};
 }
 if(!dialog.querySelector('p')){const p=document.createElement('p');p.textContent='No hay subidas pendientes de las últimas 24 horas.';dialog.append(p);}dialog.onclose=()=>dialog.remove();document.body.append(dialog);dialog.showModal();
}
function install(){if(!enabled())return;for(const form of document.querySelectorAll('#loose-upload,#addTracksForm,#createForm')){if(form.querySelector('[data-local-processing]'))continue;const box=document.createElement('div');box.style.cssText='flex-basis:100%;margin:10px 0;font-size:.9rem';box.innerHTML='<label title="Tu computadora decodificará y convertirá la música. Los MP3 locales no tienen un tope fijo de MB; se mantienen los límites de duración y memoria. Si cierras la pestaña durante la codificación se pierde ese progreso y no se registra la pista. Las subidas ya codificadas pueden reanudarse durante 24 horas."><input type="checkbox" data-local-processing checked> Procesar en este equipo (WebCodecs)</label><p class="muted" style="margin:6px 0">Al desmarcarlo, el servidor procesará por turnos en su cola y te notificará cuando la pista esté incorporada. YouTube se descarga en el servidor y puede convertirse en este equipo desde su propio control.</p><small data-capability></small> <button class="btn" type="button" data-resume-local>Reanudar subidas locales</button>';if(!desktop())box.style.setProperty('display','none','important');form.append(box);box.querySelector('[data-resume-local]').onclick=resumePanel;capabilities().then(rates=>box.querySelector('[data-capability]').textContent=rates.length?'AAC estéreo local: '+rates.join(', ')+' kbps. Si este equipo no admite toda la escalera configurada, el servidor la generará completa.':'AAC local no disponible: las pistas se procesarán automáticamente en el servidor.');}}
function desktopControls(){
 for(const input of document.querySelectorAll('[data-local-processing],#ytLocalProcessing,[name=localYoutube],[data-local-mornye]')){
  if(!enabled()){input.checked=false;input.disabled=true;input.title='WebCodecs está deshabilitado por el administrador.';}
 }
 for(const box of document.querySelectorAll('[data-local-processing]'))box.parentElement.parentElement.style.setProperty('display',enabled()&&desktop()?'block':'none','important');
 for(const box of document.querySelectorAll('[data-local-youtube-control]')){const source=box.closest('form')?.elements?.source;box.style.setProperty('display',enabled()&&desktop()&&(!source||['youtube','mornye'].includes(source.value))?'block':'none','important');}
} 
window.addEventListener('resize',desktopControls);
window.sfClientProcessing={upload,watch,compatible,desktop,enabled,youtube,mornye,resumeMornye,busy:()=>active>0,webmPcm:(buffer,progress)=>decodeWebm(buffer,progress),limits:()=>({pcmMiB:Math.round(PCM_LIMIT/1048576),fileMiB:FILE_LIMIT_MIB,deviceGiB,configuredMiB,defaultMiB}),setMemory:applyUserMemory};install();desktopControls();new MutationObserver(()=>{desktopControls();if(!document.querySelector('#loose-upload:not(:has([data-local-processing])),#addTracksForm:not(:has([data-local-processing])),#createForm:not(:has([data-local-processing]))'))return;install();}).observe(document.body,{childList:true,subtree:true});
pollJobs();setInterval(pollJobs,5000);
(async()=>{try{const db=await database();const tx=db.transaction('encoded','readwrite'),req=tx.objectStore('encoded').openCursor();req.onsuccess=()=>{const cursor=req.result;if(cursor){if((cursor.value.created||0)<Date.now()-86400000)cursor.delete();cursor.continue();}};tx.oncomplete=()=>db.close();}catch{}})();
})();
