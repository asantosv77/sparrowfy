(()=>{'use strict';
window.sfPrepareMornyeTracks=async(tracks,album)=>{
 if(!window.sfCheckDuplicates)throw Error('No se cargó la comprobación de coincidencias. Recarga antes de importar.');
 const decisions=await window.sfCheckDuplicates(tracks.map((t,i)=>({key:String(i),name:t.filename||t.title,duration:Number(t.duration)||0})),album,{}),ready=[];
 for(const [i,t] of tracks.entries()){
  const d=decisions.get(String(i))||{mode:'new'};
  if(d.mode==='skip')continue;
  if(d.mode==='reuse'&&d.match){
   const body=new FormData();for(const [k,v] of Object.entries({csrf_token:document.querySelector('meta[name="sf-csrf"]')?.content||'',action:'attach',source:d.match.album_id,track_id:d.match.id,target:album}))body.set(k,v);
   const r=await fetch('api_track_reuse.php',{method:'POST',body}),x=await r.json();if(!r.ok)throw Error(x.error||'No se pudo reutilizar la pista existente');
   window.dispatchEvent(new CustomEvent('sf:mornye-published',{detail:{album,url:t.url,title:t.filename}}));continue;
  }
  ready.push(d.mode==='replace'&&d.match?{...t,replace_track_id:d.match.id,target_album:d.match.album_id}:t);
 }
 return ready;
};
})();
