import json,sys,subprocess,urllib.request,urllib.parse
phase='local'
try:
 data=json.load(sys.stdin)
 r=subprocess.run(['ffmpeg','-nostdin','-v','error','-threads','1','-protocol_whitelist','file,crypto,data','-i',data['file'],'-t','120','-map','0:a:0','-ac','1','-ar','11025','-f','chromaprint','-fp_format','base64','pipe:1'],capture_output=True,timeout=40,check=True)
 fp=r.stdout.decode().strip()
 if len(fp)<20:raise ValueError('Fingerprint empty')
 body=urllib.parse.urlencode({'client':data['key'],'duration':int(round(data['duration'])),'fingerprint':fp,'meta':'recordings releases releasegroups','format':'json'}).encode()
 phase='service'
 req=urllib.request.Request('https://api.acoustid.org/v2/lookup',data=body,headers={'User-Agent':'SparrowFy/1.0','Content-Type':'application/x-www-form-urlencoded'})
 with urllib.request.urlopen(req,timeout=15) as response: result=json.load(response)
 if result.get('status')!='ok':raise ValueError('AcoustID rejected the query; check application key')
 candidates=[];seen=set()
 for match in sorted(result.get('results',[]),key=lambda x:x.get('score',0),reverse=True):
  if match.get('score',0)<.7:continue
  for rec in match.get('recordings',[]):
   if rec.get('id') in seen or not rec.get('title') or not rec.get('artists'):continue
   seen.add(rec.get('id'));groups=rec.get('releasegroups',[]);releases=rec.get('releases',[])
   album=(groups[0].get('title','') if groups else '') or (releases[0].get('title','') if releases else '')
   candidates.append({'title':rec['title'],'artist':''.join(a.get('name','')+a.get('joinphrase','') for a in rec['artists']),'album':album,'recording_id':rec.get('id',''),'score':round(match['score'],3)})
 print(json.dumps({'candidates':candidates[:8]}))
except Exception as e:
 print(json.dumps({'component':phase,'error':'No se pudo identificar el audio ('+type(e).__name__+'). Revisa la clave y vuelve a intentar.'}));sys.exit(1)
