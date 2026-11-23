// Run with Node on the host; no network or production preference changes.
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
(async()=>{
 const listeners={},calls=[],player={playing:true},win={},frame={contentWindow:win};
 const context={URL,location:{href:'https://example.test/index.php?view=library&loose_page=2',origin:'https://example.test'},document:{getElementById:id=>id==='labConfigFrame'?frame:null},window:{addEventListener:(name,fn)=>listeners[name]=fn,sfNavigate:async(...args)=>{calls.push(args);return true;}}};
 vm.runInNewContext(fs.readFileSync('assets/management-view.js','utf8'),context);
 const message={origin:context.location.origin,source:win,data:{source:'sf-account-preferences',type:'management-view-changed'}};
 await listeners.message({...message,origin:'https://other.test'});
 await listeners.message({...message,source:{}});
 assert.equal(calls.length,0,'Ignore other origins/windows');
 await listeners.message(message);
 assert.equal(calls.length,1);
 assert.equal(calls[0][0],context.location.href,'Keep view and pagination');
 assert.equal(calls[0][1].historyMode,'none');assert.equal(player.playing,true);
 // Actual partial navigation only replaces the main content, never the player frame.
 const root={dataset:{managementView:'1'}},main={replaceChildren(...nodes){this.nodes=nodes;}},next={childNodes:['updated catalogue']};
 const navContext={URL,AbortController,location:context.location,history:{},CustomEvent:class{},fetch:async()=>({ok:true,redirected:false,text:async()=>''}),DOMParser:class{parseFromString(){return{documentElement:{dataset:{managementView:'0'}},querySelector:()=>next};}},document:{documentElement:root,querySelector:sel=>{if(sel==='.app-shell .lab-nav')return {};assert.equal(sel,'.lab-main');return main;},querySelectorAll:()=>[],dispatchEvent:()=>{}},window:{}};
 vm.runInNewContext(fs.readFileSync('assets/navigation.js','utf8'),navContext);
 assert.equal(await navContext.window.sfNavigate(context.location.href,{historyMode:'none'}),true);
 assert.equal(root.dataset.managementView,'0');assert.deepEqual(main.nodes,['updated catalogue']);
 let fallback='';navContext.location.assign=url=>fallback=url;navContext.document.querySelector=()=>null;assert.equal(await navContext.window.sfNavigate(context.location.href,{historyMode:'none'}),false);assert.equal(fallback,context.location.href);
 console.log('PASS: trusted preference events, current page retained, partial refresh without touching player.');
})().catch(e=>{console.error(e);process.exitCode=1;});
