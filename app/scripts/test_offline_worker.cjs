// Network/cache boundary tests. No browser profile or real network is used.
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const origin='https://sparrowfy.test/',url=origin+'stream.php?path=track%2Fseg.ts';
let active='user-a',rows=[{user:'user-a',state:'complete',cache:'sf-audio-v1-test',urls:[url]}],network,clears=0,clientPath='index.php';
const handlers=new Map(),cache={match:async key=>key===url?new Response(new Uint8Array([1,2,3,4]),{headers:{'Content-Type':'video/mp2t'}}):key===origin+'offline.html'?new Response('OFFLINE SHELL'):null};
const self={registration:{scope:origin},navigator:{onLine:true},clients:{get:async()=>({url:origin+clientPath}),matchAll:async()=>[],claim:async()=>{}},addEventListener:(type,fn)=>handlers.set(type,fn)};
vm.runInNewContext(fs.readFileSync('sw.js','utf8'),{self,URL,Map,Headers,Request,Response,AbortController,setTimeout,clearTimeout,importScripts:()=>{},SFOfflineStore:{get:async()=>active?{value:active}:null,all:async()=>rows,clear:async()=>{active=null;rows=[];clears++;}},caches:{open:async()=>cache,keys:async()=>[],delete:async()=>true},fetch:(req,options)=>network(req,options)});
async function dispatch(request,mode){let promise;const r=new Request(request);if(mode)Object.defineProperty(r,'mode',{value:mode});handlers.get('fetch')({request:r,clientId:'client',respondWith:p=>{promise=p;},waitUntil:()=>{}});return promise?await promise:undefined;}
(async()=>{
 network=async()=>new Response('NETWORK');assert.equal(await (await dispatch(url)).text(),'NETWORK','online prefers network');
 network=async()=>{throw Error('offline');};assert.deepEqual([...new Uint8Array(await (await dispatch(url)).arrayBuffer())],[1,2,3,4],'network failure uses download');
 network=async()=>new Response('DENIED',{status:403});assert.equal((await dispatch(url)).status,403,'never bypass denied authorization');
 network=async()=>new Response('EXPIRED',{status:401});assert.equal((await dispatch(url)).status,401,'never bypass expired session');
 clientPath='offline.html';let calls=0;network=async()=>{calls++;return new Response('NETWORK');};assert.equal((await dispatch(url)).status,200);assert.equal(calls,0,'offline screen reads local copy without data transfer');
 const range=await dispatch(new Request(url,{headers:{Range:'bytes=1-2'}}));assert.equal(range.status,206);assert.equal(range.headers.get('Content-Range'),'bytes 1-2/4');assert.deepEqual([...new Uint8Array(await range.arrayBuffer())],[2,3]);
 const invalid=await dispatch(new Request(url,{headers:{Range:'bytes=99-100'}}));assert.equal(invalid.status,416);
 clientPath='index.php';network=async()=>{throw Error('offline');};assert.equal(await (await dispatch(origin+'index.php','navigate')).text(),'OFFLINE SHELL');assert.equal(await dispatch(origin+'config.php','navigate'),undefined,'no authenticated configuration HTML cache');
 assert.equal(await dispatch(new Request(origin+'api_personal.php',{method:'POST',body:'x'})),undefined,'no API write interception');
 active='user-b';network=async()=>{throw Error('offline');};await assert.rejects(dispatch(url),/offline/,'never serve another account cache');active='user-a';
 network=async()=>new Response('LOGOUT');assert.equal(await (await dispatch(new Request(origin+'logout.php',{method:'POST',body:'csrf_token=x'}))).text(),'LOGOUT');assert.equal(clears,1,'logout clears offline data');assert.equal(active,null);
 console.log('PASS: network preference, offline fallback, 401/403, range, shell scope, POST and logout');
})().catch(e=>{console.error(e);process.exitCode=1;});
