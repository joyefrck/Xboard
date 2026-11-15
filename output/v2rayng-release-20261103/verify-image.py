import hashlib,json,urllib.request
from pathlib import Path
out=Path(__file__).parent
expected=json.loads((out/'manifest.json').read_text())['commit']
repo='joyefrck/xboard';base='https://ghcr.io/v2/'+repo
with urllib.request.urlopen('https://ghcr.io/token?scope=repository:'+repo+':pull&service=ghcr.io',timeout=30) as r:token=json.load(r)['token']
def get(path):
 req=urllib.request.Request(base+path,headers={'Authorization':'Bearer '+token,'Accept':'application/vnd.docker.distribution.manifest.list.v2+json, application/vnd.docker.distribution.manifest.v2+json, application/vnd.oci.image.index.v1+json, application/vnd.oci.image.manifest.v1+json'})
 with urllib.request.urlopen(req,timeout=60) as r:return r.read(),r.headers.get('Docker-Content-Digest')
b,index_digest=get('/manifests/sha-'+expected);index=json.loads(b);index_digest=index_digest or 'sha256:'+hashlib.sha256(b).hexdigest()
configs={}
for item in index['manifests']:
 p=item.get('platform',{});key=p.get('os','')+'/'+p.get('architecture','')
 if key not in ['linux/amd64','linux/arm64']:continue
 raw,_=get('/manifests/'+item['digest']);manifest=json.loads(raw);raw,_=get('/blobs/'+manifest['config']['digest']);c=json.loads(raw);labels=c.get('config',{}).get('Labels',{})
 assert labels.get('org.opencontainers.image.revision')==expected,(key,labels)
 configs[key]={'digest':item['digest'],'config_digest':manifest['config']['digest'],'revision':labels['org.opencontainers.image.revision'],'architecture':c['architecture'],'os':c['os']}
assert set(configs)=={'linux/amd64','linux/arm64'}
(out/'image-manifest.json').write_text(json.dumps(index,indent=2));(out/'image-configs.json').write_text(json.dumps(configs,indent=2));(out/'image-verification.json').write_text(json.dumps({'index_digest':index_digest,'commit':expected,'platforms':configs},indent=2));print('IMAGE_VERIFIED',index_digest,json.dumps(configs))
