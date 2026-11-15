import concurrent.futures,hashlib,json
from pathlib import Path
from urllib.request import Request,urlopen
out=Path(__file__).parent
m=json.loads((out/'manifest.json').read_text())
assets={'/'+f.removeprefix('public/'):v['after'] for f,v in m['files'].items() if f.startswith('public/') and '/assets/' in f}
paths=['/','/app','/cfc29397','/api/v1/guest/comm/config']+list(assets)
def check(item):
    host,path=item
    request_path=path+('?v=er20261106notice1' if path in assets else '')
    with urlopen(Request('https://'+host+request_path,headers={'User-Agent':'Xboard-release-check','Accept-Encoding':'identity'}),timeout=40) as response:
        body=response.read();result={'host':host,'path':path,'status':response.status,'sha256':hashlib.sha256(body).hexdigest()}
        assert response.status==200,result
        if path in assets:assert result['sha256']==assets[path],result
        if path=='/app':
            html=body.decode();assert 'er20261106notice1' in html and 'notice-purify.min.js?v=3.4.17' in html
        return result
with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
    rows=list(pool.map(check,[(host,p) for host in ['www.elephant111.org','elephant111.org'] for p in paths]))
(out/'public-after.json').write_text(json.dumps(rows,indent=2))
print('PUBLIC_OK',len(rows),'ASSET_HASHES',sum(1 for r in rows if r['path'] in assets))
