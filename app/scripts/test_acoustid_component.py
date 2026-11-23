import io,json,runpy,subprocess,urllib.error
from unittest.mock import patch
from pathlib import Path
worker=Path(__file__).parent/'acoustid_lookup.py'
class Reply:
 def __enter__(self):return io.StringIO('{"status":"ok","results":[]}')
 def __exit__(self,*args):pass
for scenario in ['local_failure','service_failure','no_match']:
 output=io.StringIO()
 process=subprocess.CompletedProcess([],0,stdout=b'A'*80,stderr=b'')
 with patch('sys.stdin',io.StringIO(json.dumps({'file':'fixture','duration':180,'key':'test-only'}))),patch('sys.stdout',output),patch('subprocess.run',side_effect=subprocess.TimeoutExpired('ffmpeg',40) if scenario=='local_failure' else None,return_value=process),patch('urllib.request.urlopen',side_effect=urllib.error.URLError('fixture') if scenario=='service_failure' else None,return_value=Reply()):
  try:runpy.run_path(str(worker),run_name='__main__')
  except SystemExit:pass
 data=json.loads(output.getvalue())
 if scenario=='local_failure':assert data['component']=='local'
 elif scenario=='service_failure':assert data['component']=='service'
 else:assert data=={'candidates':[]}
print('PASS: local failure, unavailable service and no-match distinguished')
