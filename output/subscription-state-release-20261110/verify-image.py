import hashlib,json,urllib.request
from pathlib import Path
commit='a83f633c2904b5300a65182ef967b0413765ae60'
repo='joyefrck/xboard'
with urllib.request.urlopen('https://ghcr.io/token?service=ghcr.io&scope=repository:'+repo+':pull',timeout=30) as response: bearer=json.load(response)['token']
def fetch(path):
 request=urllib.request.Request('https://ghcr.io/v2/'+repo+'/'+path,headers={'Authorization':'Bearer '+bearer,'Accept':'application/vnd.oci.image.index.v1+json, application/vnd.oci.image.manifest.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, application/vnd.docker.distribution.manifest.v2+json'})
 with urllib.request.urlopen(request,timeout=40) as response:return response.read()
raw=fetch('manifests/sha-'+commit);index=json.loads(raw)
result={'commit':commit,'index_digest':'sha256:'+hashlib.sha256(raw).hexdigest(),'platforms':[]}
for arch in ['amd64','arm64']:
 d=next(x for x in index['manifests'] if x.get('platform',{}).get('os')=='linux' and x['platform']['architecture']==arch)
 image=json.loads(fetch('manifests/'+d['digest']));config=json.loads(fetch('blobs/'+image['config']['digest']))
 revision=config.get('config',{}).get('Labels',{}).get('org.opencontainers.image.revision')
 assert revision==commit,'WRONG_IMAGE_REVISION '+arch
 result['platforms'].append({'os':'linux','architecture':arch,'digest':d['digest'],'revision':revision})
Path(__file__).with_name('image-verification.json').write_text(json.dumps(result,indent=2));print(json.dumps(result))
