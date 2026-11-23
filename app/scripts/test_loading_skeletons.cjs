const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const root=path.join(__dirname,'..');const source=name=>fs.readFileSync(path.join(root,'assets',name),'utf8');
const cut=(s,a,b)=>s.slice(s.indexOf(a),s.indexOf(b,s.indexOf(a)));
class Element{
 constructor(tag='div'){this.tag=tag;this.children=[];this.attrs={};this.dataset={};this.style={};this.hidden=false;this.open=false;this.disabled=false;this.listeners={};this.value='';this.textContent='';this.options=[];}
 append(...items){for(const e of items){e.parent=this;this.children.push(e);}}
 appendChild(e){this.append(e);}
 replaceChildren(...items){for(const e of this.children)e.parent=null;this.children=[];this.append(...items);}
 remove(){if(this.parent){this.parent.children=this.parent.children.filter(e=>e!==this);this.parent=null;}}
 setAttribute(k,v){this.attrs[k]=v;}removeAttribute(k){delete this.attrs[k];}
 addEventListener(k,fn){(this.listeners[k]??=[]).push(fn);}
 showModal(){this.open=true;}close(){this.open=false;for(const fn of this.listeners.close||[])fn();}
 add(o){this.options.push(o);}querySelector(){return new Element('input');}querySelectorAll(){return [];}
 set innerHTML(text){this.replaceChildren();this.html=text;}get innerHTML(){return this.html||'';}
}
function environment(){
 let next=0;const timers=new Map(),calls=[];
 const document={createElement:tag=>new Element(tag),body:{style:{}},getElementById:()=>({value:'Fixture'})};
 const context={document,window:{addEventListener(){}},console,URL,URLSearchParams,AbortController,DOMException,FormData,WeakMap,Option:function(t,v){this.text=t;this.value=v;},setTimeout:fn=>{timers.set(++next,fn);return next;},clearTimeout:id=>timers.delete(id)};
 context.fetch=(url,options)=>new Promise((resolve,reject)=>{const c={url,options,resolve,reject};calls.push(c);if(!context.ignoreAbort)options.signal?.addEventListener('abort',()=>reject(new DOMException('Aborted','AbortError')),{once:true});});
 vm.createContext(context);vm.runInContext(source('loading-skeletons.js'),context);
 return {context,calls,timers,resolve(i,data,ok=true){calls[i].resolve({ok,json:async()=>data});},timeout(){const fn=[...timers.values()].at(-1);assert(fn);fn();}};
}
function skeleton(container){return container.children.find(e=>e.dataset.loadingSkeleton);}
async function testHelper(){
 const e=environment(),host=new Element();const first=e.context.window.sfLoadingSkeletons.start(host,{kind:'albums'});assert.equal(skeleton(host).children.length,3);assert.equal(host.attrs['aria-busy'],'true');
 const second=e.context.window.sfLoadingSkeletons.start(host,{kind:'covers',count:4});first.stop();assert(skeleton(host));assert.equal(host.attrs['aria-busy'],'true','Old cleanup must not clear the new operation');second.stop();assert(!skeleton(host));assert(!('aria-busy' in host.attrs));
}
async function testAlbumCovers(){
 const e=environment(),c=e.context;c.matchModal=new Element();c.matchModal.hidden=true;c.matchGrid=new Element();c.matchHint=new Element();c.alert=()=>{};c.escapeHtml=String;c.openModal=el=>el.hidden=false;c.coverSearches=new WeakMap();
 const s=source('library.js');vm.runInContext(cut(s,'    function closeModal(','    // Evitar listeners')+cut(s,'    async function openMatch(','    function openFrame(')+'globalThis.api={openMatch,closeModal};',c);
 const album={artist:'Fixture',title:'Album',id:'0123456789abcdef',card:new Element()};
 let pending=c.api.openMatch(album);assert.equal(skeleton(c.matchGrid).dataset.loadingSkeleton,'covers');e.resolve(0,{candidates:[]});await pending;assert.match(c.matchGrid.innerHTML,/No se encontraron/);assert(!skeleton(c.matchGrid));assert(!('aria-busy' in c.matchGrid.attrs));
 pending=c.api.openMatch(album);e.timeout();await pending;assert.match(c.matchGrid.innerHTML,/tardó demasiado/);assert(!skeleton(c.matchGrid));
 pending=c.api.openMatch(album);c.api.closeModal(c.matchModal);await pending;assert(c.matchModal.hidden);assert(!skeleton(c.matchGrid));
 c.ignoreAbort=true;const old=c.api.openMatch(album),current=c.api.openMatch(album);e.resolve(3,{candidates:[]});await old;assert(skeleton(c.matchGrid),'Old response must not replace a new skeleton');assert.equal(c.matchGrid.attrs['aria-busy'],'true');e.resolve(4,{candidates:[]});await current;assert(!skeleton(c.matchGrid));
}

async function testTrackCovers(){
 const e=environment(),c=e.context;c.coverDialog=new Element('dialog');c.grid=new Element();c.status=new Element();c.input=new Element();c.preview=new Element();c.resetCoverFile=()=>{};const button=new Element('button');
 const s=source('loose-track-editor.js');vm.runInContext(cut(s,'let coverLookup=null;',"document.getElementById('findCover').onclick=findTrackCovers")+'globalThis.api={findTrackCovers,cancelCoverLookup};',c);
 let pending=c.api.findTrackCovers({currentTarget:button});assert(button.disabled);assert.equal(skeleton(c.grid).dataset.loadingSkeleton,'covers');e.resolve(0,{candidates:[{cover:'https://is1-ssl.mzstatic.com/image.jpg',album:'Fixture'}]});await pending;assert.equal(c.grid.children.length,1);assert(!button.disabled);assert(!skeleton(c.grid));c.grid.children[0].onclick();assert.equal(c.input.value,'https://is1-ssl.mzstatic.com/image.jpg');
 pending=c.api.findTrackCovers({currentTarget:button});e.resolve(1,{},false);await pending;assert.match(c.status.textContent,/No se pudieron/);assert(!button.disabled);assert(!skeleton(c.grid));
 pending=c.api.findTrackCovers({currentTarget:button});e.timeout();await pending;assert.match(c.status.textContent,/tardó demasiado/);assert(!button.disabled);
 pending=c.api.findTrackCovers({currentTarget:button});c.coverDialog.close();await pending;assert(!button.disabled);assert(!skeleton(c.grid));
 c.ignoreAbort=true;const old=c.api.findTrackCovers({currentTarget:button});c.coverDialog.close();const current=c.api.findTrackCovers({currentTarget:button});e.resolve(4,{candidates:[]});await old;assert(skeleton(c.grid));assert(button.disabled,'Old cleanup must not enable a new lookup button');e.resolve(5,{candidates:[]});await current;assert(!button.disabled);assert(!skeleton(c.grid));
}
async function testDiscovery(){
 const e=environment(),c=e.context,s=source('album-discovery.js');c.inline=false;c.status=new Element();c.results=new Element();c.edition=new Element();c.footer=new Element();c.modal=new Element();const use=new Element('button'),submit=new Element('button');c.modal.querySelector=()=>use;
 c.form=new Element('form');c.form.querySelector=()=>submit;c.form.elements={artist:{value:'Fixture'},title:{value:'Album'},year:{value:''},provider:{value:'musicbrainz'}};
 c.node=(tag,text,cls)=>{const el=new Element(tag);el.textContent=text||'';el.className=cls;return el;};c.time=()=>'';
 const calls=[];c.post=()=>new Promise((resolve,reject)=>calls.push({resolve,reject}));
 vm.runInContext('let busy=false,selected=null,rows=[];'+cut(s,' function work(on,text)',' async function post(')+cut(s,' async function selectRelease(',' function sourceWaiting(')+cut(s,' form.onsubmit='," modal.querySelector('[data-use]')")+'globalThis.api={selectRelease};',c);
 let pending=c.form.onsubmit({preventDefault(){}});assert.equal(skeleton(c.results).dataset.loadingSkeleton,'albums');assert(submit.disabled);calls[0].resolve({items:[{artist:'Fixture',title:'Album',date:'2026',tracks:2}]});await pending;assert(!skeleton(c.results));assert.equal(c.results.children.length,1);assert(!submit.disabled);
 pending=c.api.selectRelease({provider:'musicbrainz',id:'fixture'});assert.equal(skeleton(c.edition).dataset.loadingSkeleton,'tracks');calls[1].resolve({release:{artist:'Fixture',title:'Album',date:'2026',tracks:[{disc:1,position:1,title:'Song',artist:'Fixture',duration:120}]}});await pending;assert(!skeleton(c.edition));assert(!c.edition.hidden);assert(!c.footer.hidden);assert(!submit.disabled);
 pending=c.api.selectRelease({provider:'musicbrainz',id:'fixture'});calls[2].reject(new DOMException('Timeout','AbortError'));await pending;assert(!skeleton(c.edition));assert(c.edition.hidden);assert(!submit.disabled);assert.match(c.status.textContent,/tardó demasiado/);
 pending=c.form.onsubmit({preventDefault(){}});calls[3].reject(Error('Provider unavailable'));await pending;assert(!skeleton(c.results));assert(!submit.disabled);assert.match(c.status.textContent,/Provider unavailable/);
}
(async()=>{await testHelper();await testAlbumCovers();await testTrackCovers();await testDiscovery();console.log('PASS skeleton ownership, album/release results, cover pickers, errors, timeout, cancellation, stale responses and button restoration');})().catch(e=>{console.error(e);process.exitCode=1;});
