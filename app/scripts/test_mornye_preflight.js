const fs=require('fs'),assert=require('assert');
const source=fs.readFileSync('assets/client-processing.js','utf8');
const code=source.slice(source.indexOf('async function mornyePreflight('),source.indexOf('async function mornyeSourceBlob('));
async function run(supported,bad=false){
 let requests=0;
 const fetch=async(url,options)=>{requests++;assert.equal(url,'api_client_source.php');assert.equal(options.method,'POST');assert.equal(options.body.get('action'),'preflight');assert.equal(options.body.get('id'),'fixture');assert.equal(options.body.get('csrf_token'),'token');assert.deepEqual(JSON.parse(options.body.get('capabilities')),[96,128,160,192]);return {ok:!bad,json:async()=>bad?{error:'session expired'}:{local_supported:supported,rates:[],reason:'missing AAC rates'}};};
 const fn=new Function('csrf','capabilities','fetch',code+';return mornyePreflight;')(()=> 'token',async()=>[96,128,160,192],fetch);
 if(bad)await assert.rejects(fn('fixture'),/session expired/);else assert.equal((await fn('fixture')).local_supported,supported);
 assert.equal(requests,1);console.log('PASS preflight',supported,bad);
}
(async()=>{await run(true);await run(false);await run(false,true);})().catch(e=>{console.error(e);process.exitCode=1;});
