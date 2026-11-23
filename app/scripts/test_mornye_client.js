const fs=require('fs'),assert=require('assert');
const source=fs.readFileSync('assets/client-processing.js','utf8');
const fn=source.slice(source.indexOf('async function mornye('),source.indexOf('async function resumeYoutube('));
async function run(desktopValue,compatibleValue,local){let sent,resumed=false,saved;const resume=async r=>{resumed=true;assert.equal(r.provider,'Mornye');return {local:true};};
 const factory=new Function('desktop','compatible','csrf','fetch','save','resumeYoutube','notice',fn+';return mornye;');
 const call=factory(()=>desktopValue,async()=>compatibleValue,()=> 'test-csrf',async(url,opts)=>{assert.equal(url,'api_mornye.php');sent=opts.body;return {ok:true,json:async()=>({id:'a'.repeat(32),job_id:'b'.repeat(32),album_id:'c'.repeat(16)})};},r=>saved=r,resume,()=>{});
 await call('https://open.spotify.com/track/1234567890123456789012','Nombre revisado',()=>{},{albumId:'c'.repeat(16),local});
 assert.equal(sent.get('title'),'Nombre revisado');assert.equal(sent.get('album_id'),'c'.repeat(16));const expected=desktopValue&&compatibleValue&&local!==false;assert.equal(sent.get('local')==='1',expected);assert.equal(resumed,expected);console.log('PASS desktop='+desktopValue+' compatible='+compatibleValue+' checkbox='+local);}
(async()=>{await run(true,true,true);await run(true,true,false);await run(false,true,true);await run(true,false,true);})().catch(e=>{console.error(e);process.exitCode=1});
