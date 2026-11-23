const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const src=fs.readFileSync(__dirname+'/../assets/client-processing.js','utf8');
const resume=src.slice(src.indexOf('async function resumeYoutube('),src.indexOf('async function resumePanel('));
const server=src.slice(src.indexOf(' const server=async()=>'),src.indexOf(' if(!desktop()||!checkbox?.checked)'));
const chosen='Meghan Trainor - Last Christmas',provider=chosen+' (Official Christmas Stroll Video).webm';
async function test(duration,name,expected){
 let captured,request,activity=0;
 const ctx={File,FormData,PCM_LIMIT:2e9,encodeURIComponent,setTimeout,
  activity:n=>activity+=n,save:()=>{},records:()=>[],notice:()=>{},
  fetch:async url=>url.startsWith('api_processing_status')?{ok:true,json:async()=>({job:{state:'done',result:{source_id:'s',filename:provider,duration}}})}:{ok:true,redirected:false,headers:new Headers({'Content-Type':'application/octet-stream'}),blob:async()=>new Blob(['fixture'])},
  upload:async(file,album,progress,options)=>{captured={file,options};return {ok:true};}
 };
 vm.createContext(ctx);vm.runInContext(resume,ctx);
 await ctx.resumeYoutube({id:'s',job_id:'j',album:'a',name});
 assert.equal(captured.file.name,expected);assert.equal(activity,0);
 assert.equal(captured.options.local,duration<=1800);
 const post={...ctx,file:captured.file,options:{sourceId:'s'},albumId:'a',progress:()=>{},csrf:()=>'',watch:()=>{},fetch:async(url,opts)=>{request={url,body:opts.body};return {clone:()=>({json:async()=>({job_id:'j'})})};}};
 vm.createContext(post);await vm.runInContext('(async()=>{'+server+'return server();})()',post);
 assert.equal(request.url,'api_client_source.php');assert.equal(request.body.get('filename'),expected);assert.equal(request.body.has('audio'),false);
}
(async()=>{await test(180,chosen,chosen+'.webm');await test(3600,chosen,chosen+'.webm');await test(3600,chosen+'.audio',chosen+'.webm');await test(180,'Pista de YouTube',provider);console.log('PASS: reviewed names survive local conversion, memory fallback, resume and source POST');})().catch(e=>{console.error(e);process.exit(1)});
