/* AAC-LC encoding only. Containers and arbitrary remote URLs are never accepted here. */
'use strict';
self.onmessage=async({data})=>{
 try{
  const {channels,sampleRate,rates}=data,frames=channels[0].length;
  for(let ri=0;ri<rates.length;ri++){
   const rate=rates[ri],parts=[];let failure=null;const numberOfChannels=2;
   const encoder=new AudioEncoder({output(chunk){const raw=new Uint8Array(chunk.byteLength);chunk.copyTo(raw);const len=raw.length+7;if(len>8191)throw Error('Trama AAC demasiado grande');
    // ADTS header for AAC-LC / 48 kHz / stereo. Each output is a complete AAC frame.
    const header=new Uint8Array([255,241,76,(numberOfChannels<<6)|((len>>11)&3),(len>>3)&255,((len&7)<<5)|31,252]);parts.push(header,raw);
   },error(error){failure=error;}});
   const config={codec:'mp4a.40.2',sampleRate,numberOfChannels,bitrate:rate*1000,aac:{format:'aac'}};
   const support=await AudioEncoder.isConfigSupported(config);if(!support.supported)throw Error('No se admite AAC '+rate+' kbps en este navegador');encoder.configure(config);
   try{
    for(let offset=0;offset<frames;offset+=1024){if(failure)throw failure;const n=Math.min(1024,frames-offset),pcm=new Float32Array(n*numberOfChannels);pcm.set(channels[0].subarray(offset,offset+n),0);pcm.set((channels[1]||channels[0]).subarray(offset,offset+n),n);
     const audio=new AudioData({format:'f32-planar',sampleRate,numberOfFrames:n,numberOfChannels,timestamp:Math.round(offset/sampleRate*1e6),data:pcm});try{encoder.encode(audio);}finally{audio.close();}
     if(encoder.encodeQueueSize>16)await new Promise(resolve=>encoder.addEventListener('dequeue',resolve,{once:true}));
     if(offset%32768===0)self.postMessage({progress:(ri+offset/frames)/rates.length*100,stage:'Codificando '+rate+' kbps'});
    }
    await encoder.flush();if(failure)throw failure;
   }finally{encoder.close();}
   self.postMessage({variant:{rate,blob:new Blob(parts,{type:'audio/aac'})}});
  }
  self.postMessage({done:true});
 }catch(error){self.postMessage({error:error.message||'Falló la codificación local'});}
};
