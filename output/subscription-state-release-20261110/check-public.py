import concurrent.futures,hashlib,json,sys,time,urllib.request
from pathlib import Path
base=Path(__file__).resolve().parent
manifest=json.loads((base/'manifest.json').read_text())
section=sys.argv[1]
checks=[]
for host in ['https://elephant111.org','https://www.elephant111.org']:
 for path in ['/','/app','/api/v1/guest/comm/config']:
  checks.append((host,path,None))
 for f,v in manifest['files'].items():
  if f.startswith('assets/') and v[section] is not None:checks.append((host,'/theme/ElephantRoute/'+f,v[section]))
def check(args):
 host,path,expected=args
 request=urllib.request.Request(host+path+'?release_check='+str(time.time_ns()),headers={'User-Agent':'Xboard-Release-Verification/1.0','Accept-Encoding':'identity'})
 with urllib.request.urlopen(request,timeout=40) as response:
  data=response.read();sha=hashlib.sha256(data).hexdigest()
  result={'host':host,'path':path,'status':response.status,'bytes':len(data),'sha256':sha,'hash_match':sha==expected if expected else None}
  assert response.status==200,str(result)
  if expected:assert sha==expected,'ASSET_HASH_MISMATCH '+str(result)
  if section=='after' and path=='/app':
   html=data.decode();assert 'er20261110subscriptionState2' in html and 'er20261110importClients1' in html,'STALE_APP_HTML'
 return result
with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:rows=list(pool.map(check,checks))
(base/('public-'+section+'.json')).write_text(json.dumps(rows,indent=2))
print(json.dumps({'phase':section,'requests':len(rows),'asset_hashes':sum(r['hash_match'] is True for r in rows),'status':'passed'}))
