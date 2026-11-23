const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const s=fs.readFileSync('assets/player.js','utf8');
const begin=s.indexOf('    const appleMobile ='),end=s.indexOf('      // Perfiles de ABR',begin);
const selection=s.slice(begin,end).replace(/if \((.*)\) \{\s*$/,'selectedHls = ($1);');
for(const [ua,platform,touch,native,mse,expected] of [
 ['iPad','iPad',5,true,true,false],['iPhone','iPhone',5,true,false,false],
 ['Macintosh','MacIntel',5,true,true,false],['Chrome','Win32',0,true,true,true],
 ['Firefox','Linux',0,false,true,true],['iPad','iPad',5,false,true,true]]){
 const c={navigator:{userAgent:ua,platform,maxTouchPoints:touch},audio:{canPlayType:()=>native?'maybe':''},window:{Hls:true},Hls:{isSupported:()=>mse}};
 vm.runInNewContext(selection,c);assert.equal(c.selectedHls,expected,ua+' engine');
}
const stalled=s.slice(s.indexOf("  audio.addEventListener('stalled'"),s.indexOf("  audio.addEventListener('playing'",s.indexOf("  audio.addEventListener('stalled'")));
let handler,visual=0;vm.runInNewContext(stalled,{audio:{addEventListener:(e,fn)=>handler=fn},wantPlaying:true,showNetAfterDelay:()=>visual++,scheduleNetRetry:()=>assert.fail('stalled must not restart buffered audio')});handler();assert.equal(visual,1);
const recovery=s.slice(s.indexOf('  function recoverPlayback()'),s.indexOf('  async function resolveOnlineTrack'));
function recover(overrides={}){const calls=[];const c={netGaveUp:false,wantPlaying:true,hasPlayedCurrentTrack:true,navigator:{onLine:true},audio:{error:null,paused:false,seeking:false,readyState:4,currentTime:12},lastAdvanceAt:Date.now(),hideNet:()=>calls.push('hide'),setStatus:()=>{},hls:null,diagnostics:{note:()=>{}},netAttempt:3,lastRecovery:0,recoveryPosition:12,index:0,loadTrack:()=>calls.push('rebuild'),scheduleNetRetry:()=>calls.push('retry'),...overrides};vm.runInNewContext(recovery+';recoverPlayback();',c);return calls;}
assert.deepEqual(recover(),['hide']);
assert.deepEqual(recover({lastAdvanceAt:Date.now()-20000}),['rebuild','retry']);
assert.deepEqual(recover({audio:{error:{code:2},paused:true,readyState:0,currentTime:12}}),['rebuild','retry']);
assert.deepEqual(recover({wantPlaying:false}),[]);
console.log('PASS: iOS native / desktop hls.js, stalled is non-destructive, healthy retries cancelled, real stalls recover');
