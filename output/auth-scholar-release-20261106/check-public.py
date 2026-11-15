import concurrent.futures,hashlib,json,pathlib,sys,urllib.request,urllib.error
out=pathlib.Path(__file__).parent;root=out.parents[1];manifest=json.loads((out/'manifest.json').read_text());phase=sys.argv[1]
assets={('/'+f):v['source'] for f,v in manifest['files'].items() if f.startswith('public/theme/') and '/assets/' in f}
assets={p.replace('/public/','/',1):s for p,s in assets.items()}
paths=['/','/app','/support/ai','/api/v1/guest/comm/config','/cfc29397']+list(assets)
def check(item):
 host,path=item
 request_path=path+('?v='+manifest['commit'] if path in assets else '')
 req=urllib.request.Request('https://'+host+request_path,headers={'User-Agent':'Xboard-release-check','Accept-Encoding':'identity'})
 try:
  with urllib.request.urlopen(req,timeout=30) as response:
   body=response.read();r={'host':host,'path':path,'status':response.status,'sha256':hashlib.sha256(body).hexdigest()}
   if path in assets:r['matches_local']=body==(root/assets[path]).read_bytes()
   if path=='/app':r['new_version']=all(x in body for x in [b'er20261106auth1',b'er20261106support1',b'er20261106scholar1'])
   if path=='/support/ai':r['new_version']=b'/app#/ticket' in body and b'open_ai_support' not in body
   return r
 except urllib.error.HTTPError as e:return {'host':host,'path':path,'status':e.code}
 except Exception as e:return {'host':host,'path':path,'error':type(e).__name__}
with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:results=list(pool.map(check,[(h,p) for h in ['www.elephant111.org','www.elephant111.com'] for p in paths]))
(out/(phase+'.json')).write_text(json.dumps(results,indent=2));bad=[r for r in results if r.get('status')!=200 or not r.get('matches_local',True) or not r.get('new_version',True)]
print(json.dumps({'checks':len(results),'http200':sum(r.get('status')==200 for r in results),'matched_assets':sum(r.get('matches_local') is True for r in results),'updated_pages':sum(r.get('new_version') is True for r in results),'mismatches':bad},indent=2))
if phase!='public-before':assert not bad
