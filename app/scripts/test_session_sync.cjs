const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../assets/session-sync.js','utf8');
class Element{
 constructor(){this.children=[];this.style={};this.dataset={};this.attrs={};this.value='';}
 append(...nodes){for(const n of nodes){n.parent=this;this.children.push(n);}}
 setAttribute(k,v){this.attrs[k]=v;}getAttribute(k){return this.attrs[k]??null;}removeAttribute(k){delete this.attrs[k];}
 remove(){if(this.parent)this.parent.children=this.parent.children.filter(n=>n!==this);}
 querySelector(sel){return this.children.find(n=>sel==='[data-session-message]'&&n.dataset.sessionMessage)||null;}
}
const docEvents={},winEvents={},body=new Element(),csrf=new Element(),meta=new Element(),text=new Element();csrf.name='csrf_token';csrf.value='old';text.name='title';text.value='Mi título editado';
const inputs=[csrf],document={body,hidden:false,visibilityState:'visible',
 querySelector:s=>s==='meta[name="sf-session-account"]'?{content:'a'}:null,
 querySelectorAll:s=>s.startsWith('input')?inputs:s.startsWith('meta')?[meta]:[],
 createElement:()=>new Element(),getElementById:id=>body.children.find(e=>e.id===id)||null,
 addEventListener:(name,fn)=>docEvents[name]=fn};
let submitted=0;
function submitEvent(form){const e={target:form,submitter:null,preventDefault(){this.prevented=true;},stopImmediatePropagation(){}};docEvents.submit(e);return e;}
class Form extends Element{constructor(){super();this.method='post';this.action='https://sparrowfy.ddns.info/login.php';this.elements=[csrf,text];}requestSubmit(){const e=submitEvent(this);if(!e.prevented)submitted++;}}
let now=100000,token='a'.repeat(64),account='a',getCount=0,posts=[],behavior='ok',serverFailures=0;
function response(status,json,headers={}){return {ok:status<400,status,headers:new Headers(headers),json:async()=>json};}
async function network(url,options={}){
 if(String(url).includes('api_session_sync.php')){getCount++;return response(200,{account_id:account,csrf_token:token,authenticated:!!account});}
 if(String(options.method).toUpperCase()==='POST'){
  posts.push({url:String(url),body:Object.fromEntries(options.body)});
  if(behavior==='csrf'&&serverFailures++===0){token='c'.repeat(64);return response(403,{error:'csrf'},{'X-SparrowFy-CSRF':'invalid'});}
  if(behavior==='500')return response(500,{error:'server'});
  if(behavior==='network')throw Error('offline');
 }
 return response(200,{success:true});
}
const context={document,navigator:{locks:{request:async(_name,work)=>work()}},location:{href:'https://sparrowfy.ddns.info/index.php',origin:'https://sparrowfy.ddns.info'},Date:{now:()=>now},FormData,URLSearchParams,URL,Request,AbortController,HTMLFormElement:Form,setTimeout,clearTimeout,console};
context.window={fetch:network,addEventListener:(name,fn)=>winEvents[name]=fn};vm.createContext(context);vm.runInContext(source,context);
const flush=()=>new Promise(resolve=>setImmediate(resolve));
function data(){const b=new FormData();b.set('csrf_token','stale');b.set('title',text.value);return b;}
(async()=>{
 await flush();assert.equal(csrf.value,token);assert.equal(meta.content,token);assert.equal(text.value,'Mi título editado');
 const before=getCount;now+=11000;await Promise.all([context.window.fetch('/api_test.php',{method:'POST',body:data()}),context.window.fetch('/api_test.php',{method:'POST',body:data()})]);assert.equal(getCount,before+1,'Refresh was not coalesced');assert.equal(posts.length,2);assert(posts.every(p=>p.body.csrf_token===token));
 const form=new Form();let e=submitEvent(form);assert(e.prevented);await flush();assert.equal(submitted,1,'Native form submitted twice or not at all');assert.equal(text.value,'Mi título editado');
 const beforeResume=getCount;token='b'.repeat(64);winEvents.pageshow({persisted:true});await flush();assert.equal(csrf.value,token);assert.equal(getCount,beforeResume+1);
 behavior='csrf';let p=posts.length;await context.window.fetch('/api_test.php',{method:'POST',body:data()});assert.equal(posts.length,p+2);assert.equal(posts.at(-1).body.csrf_token,'c'.repeat(64));assert.equal(posts.at(-1).body.title,'Mi título editado');
 behavior='500';p=posts.length;const failed=await context.window.fetch('/api_test.php',{method:'POST',body:data()});assert.equal(failed.status,500);assert.equal(posts.length,p+1,'Ambiguous server failure was replayed');
 behavior='network';p=posts.length;await assert.rejects(()=>context.window.fetch('/api_test.php',{method:'POST',body:data()}));assert.equal(posts.length,p+1,'Network error replayed a write');
 behavior='ok';account='b';p=posts.length;await assert.rejects(()=>context.window.sfSessionSync.refresh(),/cuenta/);await assert.rejects(()=>context.window.fetch('/api_test.php',{method:'POST',body:data()}),/cuenta/);assert.equal(posts.length,p,'Old page submitted to a different account');assert.equal(text.value,'Mi título editado');
 const redirects=[],anon={...context,document:{...document,querySelector:s=>s==='meta[name="sf-session-account"]'?{content:''}:null},location:{...context.location,replace:url=>redirects.push(url)},window:{fetch:network,addEventListener:()=>{}}};vm.createContext(anon);vm.runInContext(source,anon);await flush();assert.deepEqual(redirects,['https://sparrowfy.ddns.info/index.php']);
 console.log('PASS: resume/BFCache token refresh, concurrent refresh coalescing, preserved fields, one native submit, bounded CSRF retry, no network/server replay, account-switch guard');
})().catch(e=>{console.error(e);process.exitCode=1;});
