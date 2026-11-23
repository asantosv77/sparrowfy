const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync('assets/player.js','utf8'),start=source.indexOf('  function requestPlayback()'),end=source.indexOf('  function destroyHls()',start),fn=source.slice(start,end);
async function run(name,stale=false){const calls=[];const context={document:{hidden:false},clearTransientStatus:()=>{},setAutoplayHint:s=>calls.push(s),loadSequence:1,wantPlaying:true,audio:{play:()=>Promise.reject(Object.assign(Error(name),{name}))},diagnostics:{note:n=>calls.push(n)},hideNet:()=>calls.push('hide'),setStatus:s=>calls.push(s),showNetFailed:()=>calls.push('FAILED'),scheduleNetRetry:()=>calls.push('RETRY')};vm.createContext(context);vm.runInContext(fn+';requestPlayback();',context);if(stale)context.loadSequence=2;await new Promise(setImmediate);return {context,calls};}
(async()=>{let r=await run('NotAllowedError');assert.equal(r.context.wantPlaying,false);assert(r.calls.includes('autoplay_blocked'));assert(r.calls.some(x=>x.includes('Reproducir')));assert(!r.calls.includes('FAILED'));assert(!r.calls.includes('RETRY'));r=await run('AbortError');assert.equal(r.calls.length,0);r=await run('NotAllowedError',true);assert.equal(r.calls.length,0);r=await run('NetworkError');assert(r.calls.includes('RETRY'));console.log('PASS: autoplay is not network failure; abort/stale ignored; real errors still retry');})().catch(e=>{console.error(e);process.exitCode=1;});

// All visible playback controls share the same error classification.
const sourceCheck=require('node:fs').readFileSync('assets/player.js','utf8');
const shellCheck=require('node:fs').readFileSync('assets/shell-player.js','utf8');
require('node:assert/strict').ok(!sourceCheck.includes("showNetFailed('Pulsa Reintentar para recuperar el audio.')"));
require('node:assert/strict').ok(sourceCheck.includes('window.sfPlayerToggle='));
require('node:assert/strict').ok(shellCheck.includes('contentWindow?.sfPlayerToggle'));
