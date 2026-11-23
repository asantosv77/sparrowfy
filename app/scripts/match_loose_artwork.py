import json,re,sqlite3,time,urllib.request,urllib.parse,unicodedata,os
from pathlib import Path
os.umask(0o007)
root=Path(__file__).resolve().parent.parent
out=root/'backups/loose-artwork-matches.json'
def clean(s):
 s=re.sub(r'[\[(][^\])]*(?:official|audio|lyric|video)[^\])]*[\])]','',s,flags=re.I)
 s=re.sub(r'\s*[-;]\s*(?:animated music video|español)\s*$','',s,flags=re.I)
 return s.strip()
def norm(s):
 s=re.split(r'\s+(?:ft\.?|feat\.?)\s+',s,flags=re.I)[0]
 return re.sub(r'[^a-z0-9]','',unicodedata.normalize('NFKD',s.casefold()).encode('ascii','ignore').decode())
p=sqlite3.connect('/sparrowfy/database/library.sqlite');p.row_factory=sqlite3.Row
report=[]
for row in p.execute("SELECT id,name,duration,metadata_json FROM tracks WHERE album_id='fffffffffffffffe' ORDER BY position").fetchall():
 meta=json.loads(row['metadata_json'] or '{}');entry={'id':row['id'],'name':row['name']}
 if meta.get('artwork'):entry['status']='existing';report.append(entry);continue
 name=clean(row['name']);artist=meta.get('artist','').strip();title=clean(meta.get('title','') or name)
 pairs=[]
 if artist:pairs.append((artist,title))
 if ' - ' in name:
  a,b=name.split(' - ',1);pairs.extend([(a,b),(b,a)])
 if not pairs:entry['status']='insufficient_metadata';report.append(entry);continue
 try:
  query=urllib.parse.urlencode({'term':name,'entity':'song','limit':25,'country':'US'})
  req=urllib.request.Request('https://itunes.apple.com/search?'+query,headers={'User-Agent':'SparrowFy Artwork Matching/1.0'})
  with urllib.request.urlopen(req,timeout=12) as r:results=json.load(r).get('results',[])
  candidates=[]
  for c in results:
   if not any(norm(a)==norm(c.get('artistName','')) and norm(clean(t))==norm(clean(c.get('trackName',''))) for a,t in pairs):continue
   cover=c.get('artworkUrl100','');host=urllib.parse.urlsplit(cover).hostname or ''
   if not (host.endswith('.mzstatic.com') and cover.startswith('https://')):continue
   delta=abs(float(row['duration'] or 0)-c.get('trackTimeMillis',0)/1000)
   candidates.append((delta,c))
  if candidates:
   candidates.sort(key=lambda x:x[0]);c=candidates[0][1]
   entry.update(status='matched',artist=c['artistName'],title=c['trackName'],album=c.get('collectionName',''),artwork=re.sub(r'/100x100[^/]*$','/600x600bb.jpg',c['artworkUrl100']),source=c.get('trackViewUrl',''))
  else:entry['status']='no_exact_match'
 except Exception as e:entry.update(status='lookup_failed',error=type(e).__name__)
 report.append(entry);out.write_text(json.dumps(report,ensure_ascii=False,indent=2));print(entry['status']+': '+row['name'],flush=True);time.sleep(2)
out.write_text(json.dumps(report,ensure_ascii=False,indent=2))
print('DONE',len(report),flush=True)
