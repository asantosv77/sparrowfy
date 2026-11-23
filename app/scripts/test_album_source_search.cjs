/* Isolated tests execute the production queue/request/row functions with controlled fetch. */
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');
const source=fs.readFileSync(path.join(__dirname,'../assets/album-discovery.js'),'utf8');
const slice=(a,b)=>source.slice(source.indexOf(a),source.indexOf(b,source.indexOf(a)));
const queue=slice('async function runSourceQueue','function open(');
const post=slice(' function work(on,text)',' const close=');
const choose=slice(' function chosen()',' async function selectRelease(');
const search=slice(' function sourceWaiting(',' form.onsubmit=');
const classes=()=>({values:new Set(),add(x){this.values.add(x)},remove(x){this.values.delete(x)}});
function row(title){return {track:{title,artist:'Fixture'},check:{checked:false,hidden:false},skeleton:{hidden:true},hint:{textContent:''},wrap:{attrs:{},setAttribute(k,v){this.attrs[k]=v},removeAttribute(k){delete this.attrs[k]}},name:{value:title},select:{value:'',options:[],replaceChildren(...o){this.options=o;this.value=''},add(o){this.options.push(o)}}};}
function harness(titles,behavior={}){
 const rows=titles.map(row),status={textContent:''},progress={hidden:true,value:0},pending={disabled:false,classList:classes(),setAttribute(){},removeAttribute(){}},button={disabled:false},controls=[button,...rows.flatMap(r=>[r.check,r.select,r.name])];
 const form={querySelector:()=>button},edition={querySelectorAll:()=>controls},modal={querySelector:()=>pending};let active=0,max=0,calls=[];
 function fakeFetch(url,options){const query=options.body.get('query')||'',title=query.replace('Fixture ','');calls.push({url,title});active++;max=Math.max(max,active);
  return new Promise((resolve,reject)=>{let settled=false,timer;
   const done=(fn,value)=>{if(settled)return;settled=true;clearTimeout(timer);options.signal.removeEventListener('abort',abort);active--;fn(value);};
   const abort=()=>done(reject,new DOMException('Aborted','AbortError'));if(options.signal.aborted){abort();return;}options.signal.addEventListener('abort',abort,{once:true});
   if(title==='timeout'||behavior.hold)return;
   timer=setTimeout(()=>{
    if(title==='error'){done(resolve,{ok:false,status:502,headers:{get:()=>null},json:async()=>({message:'Provider unavailable'})});return;}
    if(title==='retry'&&!behavior.retried){behavior.retried=true;done(resolve,{ok:false,status:503,headers:{get:()=>1},json:async()=>({message:'Busy'})});return;}
    const payload=url.includes('youtube_search')?{success:true,items:title==='empty'?[]:[{id:'abcdefghijk',title}]}:{success:true,items:JSON.parse(options.body.get('items')).map(i=>({...i,confident:true,score:98}))};
    done(resolve,{ok:true,status:200,headers:{get:()=>null},json:async()=>payload});
   },title==='slow'?22:6);
  });
 }
 const context={syncPreparation:()=>{},sourceSelect:{disabled:false},rows,status,progress,pending,button,form,edition,modal,console,FormData,AbortController,DOMException,Option:function(text,value){this.text=text;this.value=value},fetch:fakeFetch,clearTimeout,setTimeout:(fn,ms)=>setTimeout(fn,ms===45000?120:ms===35000?90:ms===8000?60:ms>1000?5:ms)};
 vm.createContext(context);vm.runInContext(`const isMornye=()=>false;let busy=false,stopped=false,request=null;const requests=new Set();const abortRequests=()=>{request?.abort();for(const active of requests)active.abort();};const csrf=()=>"fixture";const time=()=>"0:00";${queue}${post}${choose}${search};globalThis.api={run:()=>findSources(button,true),close:()=>{stopped=true;abortRequests()},requests};`,context);
 return {rows,status,progress,pending,button,calls,run:context.api.run,close:context.api.close,requests:context.api.requests,max:()=>max,active:()=>active,controls};
}

(async()=>{
 const h=harness(['slow','fast','empty','error','timeout','retry','last','saved']);h.rows[7].select.value='https://www.youtube.com/watch?v=saved123456';h.rows[7].check.checked=true;
 const running=h.run();assert.equal(h.active(),3,'First three start in parallel');assert(h.rows.slice(0,7).every(r=>r.skeleton.hidden===false&&r.check.hidden===true),'Every pending row displays skeleton');assert(h.button.disabled,'Actions disabled while working');
 await running;assert.equal(h.max(),3,'Never more than three outstanding requests');assert.equal(h.progress.value,100);assert(h.rows.every(r=>r.skeleton.hidden&&r.check.hidden===false),'All skeletons restored');assert(h.controls.every(c=>!c.disabled),'Controls restored');
 for(const i of [0,1,5,6,7])assert(h.rows[i].check.checked,'Confident or saved selection checked: '+i);
 for(const i of [2,3,4])assert(!h.rows[i].check.checked,'Unresolved source remains unchecked');
 assert.match(h.rows[2].hint.textContent,/Sin coincidencia/);assert.match(h.rows[3].hint.textContent,/Provider unavailable/);assert.match(h.rows[4].hint.textContent,/Tiempo agotado/);assert(!h.calls.some(c=>c.title==='saved'),'Existing choices not searched again');assert.equal(h.requests.size,0);assert(!h.pending.classList.values.has('sf-btn-skeleton'));
 const cancel=harness(['a','b','c','never started'],{hold:true});const pending=cancel.run();assert.equal(cancel.active(),3);cancel.close();await pending;assert.equal(cancel.calls.length,3,'Close must not start queued rows');assert.equal(cancel.active(),0);assert.equal(cancel.requests.size,0);assert(cancel.rows.every(r=>r.skeleton.hidden));
 console.log('PASS three-way parallelism, row skeletons, selection preservation, independent errors/timeouts, busy retry, cancellation and control restoration');
})().catch(e=>{console.error(e);process.exitCode=1;});
