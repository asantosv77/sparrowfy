const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(path.join(__dirname,'../assets/shell-player.js'),'utf8'),back=fs.readFileSync(path.join(__dirname,'../assets/album-back.js'),'utf8');
const cut=(a,b)=>source.slice(source.indexOf(a),source.indexOf(b,source.indexOf(a)));
function classes(){const values=new Set();return {add:x=>values.add(x),remove:x=>values.delete(x),contains:x=>values.has(x),toggle(x,on){if(on)values.add(x);else values.delete(x);}};}
function harness(){
 const handlers={click:[],message:[]},nav=[],notices=[];let timer=0;
 const audio={playing:true,pause(){throw Error('Audio must not pause');}},playerFrame={dataset:{src:'player.php?album=0123456789abcdef'},contentWindow:{audio}},host={hidden:false,classList:classes()},mini={hidden:true};
 Object.defineProperty(playerFrame,'src',{get:()=>playerFrame.dataset.src,set(){throw Error('Live player iframe must not be replaced');}});
 const config={hidden:true,classList:classes()},configFrame={src:'about:blank',contentWindow:{location:{pathname:'/album_edit.php'}}},close={textContent:''},label={textContent:''},loading={hidden:true,textContent:''};
 const ids={labConfigHost:config,labConfigFrame:configFrame,labConfigClose:close,labConfigStatus:loading};
 const location={origin:'https://sparrowfy.ddns.info',href:'https://sparrowfy.ddns.info/index.php?view=library'};
 const document={body:{classList:classes()},getElementById:id=>ids[id]||null,querySelector:selector=>selector==='#labConfigHost strong'?label:selector==='.app-shell'?{}:null,addEventListener:(type,fn)=>(handlers[type]??=[]).push(fn)};
 const window={sfNavigate:async href=>{nav.push(href);return true;},addEventListener:(type,fn)=>(handlers[type]??=[]).push(fn)};window.parent=window;
 const context={window,document,location,URL,Promise,console,frame:()=>playerFrame,host:()=>host,mini:()=>mini,ensureShell(){},applyCompactMode(){},updateDockArtwork(){},persist(){},lastState:{playing:true},configLoadTimer:null,setTimeout:()=>++timer,clearTimeout(){},highlightAlbumCard:()=>false,continueNotice:text=>notices.push(text)};
 vm.createContext(context);
 vm.runInContext(cut('  function minimizeShell(','  function restoreShell(')+cut('  function openConfig(','  function highlightAlbumCard(')+cut('  function showLibrary(','  function highlightAlbumFromHash(')+cut('  function interceptPlayLinks()','  function openAlbumManager(')+cut('  function openAlbumManager(','  window.labOpenPlayer =')+'interceptPlayLinks();globalThis.api={openAlbumManager,openConfig,closeConfig};',context);
 return {c:context,handlers,nav,notices,audio,playerFrame,host,config,configFrame,label,close};
}
function link(href,{back=false,target=''}={}){return {href:new URL(href,'https://sparrowfy.ddns.info/').href,target,hasAttribute:name=>name==='data-album-back'&&back,getAttribute:name=>name==='href'?href:null};}
function event(a,extra={}){return {defaultPrevented:false,button:0,preventDefault(){this.defaultPrevented=true;},target:{closest(selector){if(selector==='[data-album-back]')return a.hasAttribute('data-album-back')?a:null;if(selector.includes('a[href*="album_edit.php"]'))return a.href.includes('album_edit.php')||(selector.includes('create_album.php')&&a.href.includes('create_album.php'))?a:null;if(selector.includes('a[href*="index.php"]'))return a.href.includes('index.php')?a:null;if(selector==='button,a,input,select,textarea,summary,[role="menu"],.ctx-menu')return a;return null;}},...extra};}
function click(h,e){for(const fn of h.handlers.click)fn(e);}
const flush=()=>new Promise(resolve=>setImmediate(resolve));
async function testRoot(){
 const h=harness(),original=h.playerFrame,src=original.src;let e=event(link('album_edit.php#createForm'));click(h,e);assert(e.defaultPrevented);assert(!h.config.hidden);assert.equal(h.label.textContent,'Nuevo álbum');assert.equal(new URL(h.configFrame.src).searchParams.get('embed'),'1');assert.equal(new URL(h.configFrame.src).hash,'#createForm');assert(h.audio.playing);assert.equal(h.playerFrame,original);assert.equal(original.src,src);
 e=event(link('create_album.php'));click(h,e);assert(e.defaultPrevented,'Legacy New album link must use the same persistent shell');assert.equal(new URL(h.configFrame.src).pathname,'/album_edit.php');assert.equal(new URL(h.configFrame.src).hash,'#createForm');assert.equal(h.label.textContent,'Nuevo álbum');
 h.c.api.closeConfig();assert(h.config.hidden);assert(h.audio.playing);assert.equal(original.src,src);
 e=event(link('album_edit.php?id=0123456789abcdef'));click(h,e);assert(e.defaultPrevented);assert.equal(h.label.textContent,'Gestión del álbum');assert(h.c.window.labOpenAlbumManager('album_edit.php'));assert(!h.c.window.labOpenAlbumManager('https://example.org/album_edit.php'));assert(!h.c.window.labOpenAlbumManager('config.php'));
 e=event(link('album_edit.php'),{ctrlKey:true});click(h,e);assert(!e.defaultPrevented);e=event(link('album_edit.php',{target:'_blank'}));click(h,e);assert(!e.defaultPrevented);
 h.c.window.sfConfirmLocalLeave=()=>false;h.config.hidden=true;h.c.api.openAlbumManager('album_edit.php');assert(h.config.hidden,'Declined navigation must not load an album');assert(h.audio.playing);
}

async function testEmbeddedReturn(){
 const h=harness(),listeners=[];h.c.api.openAlbumManager('album_edit.php');
 const child={location:{href:'https://sparrowfy.ddns.info/album_edit.php?embed=1',origin:'https://sparrowfy.ddns.info',pathname:'/album_edit.php'},parent:h.c.window};h.configFrame.contentWindow=child;
 h.c.window.postMessage=(data,origin)=>{for(const fn of h.handlers.message)fn({data,origin,source:child});};
 const context={window:child,document:{addEventListener:(type,fn)=>listeners.push(fn),referrer:h.c.location.href},location:child.location,URL,history:{length:2,back(){throw Error('Embedded back must not navigate history');}}};vm.createContext(context);vm.runInContext('(()=>{'+cut('  if(window!==window.parent){','  const account=')+'})();',context);vm.runInContext(back,context);
 let e=event(link('index.php?view=library'));for(const fn of listeners)fn(e);await flush();assert(e.defaultPrevented);assert(h.config.hidden);assert.equal(h.nav.at(-1),'index.php?view=library');assert(h.audio.playing);
 h.c.api.openAlbumManager('album_edit.php');e=event(link('index.php?view=library',{back:true}));const count=h.nav.length;for(const fn of listeners)fn(e);await flush();assert(e.defaultPrevented);assert(h.config.hidden);assert.equal(h.nav.length,count,'Back must return to the existing catalogue rather than navigate again');assert(h.audio.playing);
 h.c.api.openAlbumManager('album_edit.php');h.c.window.sfConfirmLocalLeave=()=>false;e=event(link('index.php?view=library'));for(const fn of listeners)fn(e);await flush();assert(!h.config.hidden,'Active local job veto must keep the editor open');assert.equal(h.nav.length,count);assert(h.audio.playing);
 h.c.window.sfConfirmLocalLeave=()=>true;h.c.window.sfNavigate=async()=>{throw Error('Network unavailable');};e=event(link('index.php?view=library'));for(const fn of listeners)fn(e);await flush();assert(h.notices.at(-1).includes('reproducción continúa'));assert(h.audio.playing);
 e=event(link('https://example.org/index.php?view=library'));for(const fn of listeners)fn(e);assert(!e.defaultPrevented,'External links must not be redirected through the host');
 h.config.hidden=false;for(const fn of h.handlers.message)fn({origin:'https://example.org',source:child,data:{source:'sf-navega',type:'show-library'}});assert(!h.config.hidden,'Untrusted messages must be ignored');
}
(async()=>{await testRoot();await testEmbeddedReturn();console.log('PASS new/existing album routes, live audio/iframe identity, Library/Back without reload, local-job veto, modifier/external links and navigation failure');})().catch(error=>{console.error(error);process.exitCode=1;});
