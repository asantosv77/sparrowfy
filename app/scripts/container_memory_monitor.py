#!/usr/bin/env python3
"""Host pi cron collector. Fixed read-only Docker targets; PHP has no socket access."""
import fcntl,json,os,subprocess,time,shutil
from pathlib import Path
os.umask(0o007)
root=Path(__file__).resolve().parent.parent
out=root/'data/container-memory'
limits={}
try:
 r=subprocess.run(['/usr/bin/docker','inspect','www','dbwww'],capture_output=True,text=True,timeout=8,check=True)
 for c in json.loads(r.stdout):
  h=c.get('HostConfig',{});nano=h.get('NanoCpus',0);quota=h.get('CpuQuota',0);period=h.get('CpuPeriod',0) or 100000
  cores=nano/1e9 if nano>0 else quota/period if quota>0 else os.cpu_count() or 1
  limits[c['Name'].lstrip('/')]=cores
except Exception: pass

with (out/'.collector.lock').open('a') as lock:
 try: fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
 except BlockingIOError: raise SystemExit(0)
 end=time.monotonic()+55
 while time.monotonic()<end:
  started=time.monotonic();items={}
  try:
   r=subprocess.run(['/usr/bin/docker','stats','--no-stream','--format','{{json .}}','www','dbwww'],capture_output=True,text=True,timeout=12)
   if r.returncode:raise RuntimeError('Docker stats unavailable')
   for line in r.stdout.splitlines():
    d=json.loads(line);name=d.get('Name') or d.get('Container')
    if name in ('www','dbwww'):items[name]={'percent':min(100,max(0,float(d['MemPerc'].rstrip('%')))),'usage':d['MemUsage'],'cpu_percent':round(min(100,max(0,float(d.get('CPUPerc','0%').rstrip('%'))/limits[name])),2) if name in limits else None,'cpu_limit_cores':limits.get(name)}
   data={'updated_at':int(time.time()),'containers':items}
  except Exception: data={'updated_at':int(time.time()),'containers':{},'error':'Lectura de memoria no disponible'}
  try:
   result=subprocess.run(['/usr/bin/docker','exec','-u','www-data','-w','/var/www/html/audio','www','python3','-c',"import shutil,json;d=shutil.disk_usage('hls_out');print(json.dumps({'percent':round(d.used/d.total*100,2),'used':d.used,'total':d.total,'free':d.free}))"],capture_output=True,text=True,timeout=8,check=True)
   data['storage']=json.loads(result.stdout)
  except Exception: data['storage']=None
  temp=out/'latest.tmp';temp.write_text(json.dumps(data));os.chmod(temp,0o660);temp.replace(out/'latest.json')
  time.sleep(max(0,5-(time.monotonic()-started)))
