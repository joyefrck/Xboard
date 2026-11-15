import concurrent.futures,hashlib,json,pathlib,re,urllib.request,urllib.error
root=pathlib.Path(__file__).resolve().parents[2]
m=json.loads((root/'scripts/releases/20261111-account-security/manifest.json').read_text())
assets={k.removeprefix('public'):v['after'] for k,v in m['files'].items() if k.startswith('public/') and '/assets/' in k}
results=[]
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self, req, fp, code, msg, headers, newurl):return None
no_redirect=urllib.request.build_opener(NoRedirect)
def check(base,path,method='GET'):
 req=urllib.request.Request(base+path, data=b'{}' if method=='POST' else None,method=method,headers={'User-Agent':'Xboard-production-release-check','Accept-Encoding':'identity','Content-Type':'application/json','Cache-Control':'no-cache'})
 try:
  r=no_redirect.open(req,timeout=30) if path.startswith('/api/v1/user/') else urllib.request.urlopen(req,timeout=30)
  body=r.read();status=r.status
 except urllib.error.HTTPError as e:
  body=e.read();status=e.code
  if status in [301,302,307,308] and base=='https://elephant111.org' and path.startswith('/api/v1/user/'):
   assert e.headers['Location']=='https://www.elephant111.org'+path, 'unexpected_redirect'
   follow=check('https://www.elephant111.org',path,method)
   return {'host':base,'path':path,'status':status,'canonical_status':follow['status'],'pass':follow['pass']}
 row={'host':base,'path':path,'status':status}
 if path in assets:
  row['sha256']=hashlib.sha256(body).hexdigest();row['pass']=status==200 and row['sha256']==assets[path]
 elif path=='/app':
  row['markers']={x:x.encode() in body for x in ['er20261111security2','er20261110subscriptionState2','er20261110clashVergeScheme1']};row['pass']=status==200 and all(row['markers'].values())
 elif path.startswith('/api/v1/user/'):
  row['pass']=status in [401,403]
 else: row['pass']=status==200
 return row
calls=[]
for base in ['https://elephant111.org','https://www.elephant111.org']:
 for p in ['/','/app','/api/v1/guest/comm/config']+list(assets):calls.append((base,p))
 for p,method in [('getActiveSession','GET'),('sendPasswordChangeCode','POST'),('removeAllActiveSessions','POST')]:calls.append((base,'/api/v1/user/'+p,method))
with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
 results=list(pool.map(lambda args:check(*args),calls))
out={'all_pass':all(r['pass'] for r in results),'checks':len(results),'results':results}
pathlib.Path(__file__).with_name('public-check.json').write_text(json.dumps(out,indent=2))
print(json.dumps(out,indent=2));raise SystemExit(0 if out['all_pass'] else 1)
