import concurrent.futures,hashlib,json,sys,urllib.request
from pathlib import Path
out=Path(__file__).parent
m=json.loads(Path('scripts/releases/20261109-refund/manifest.json').read_text());m['commit']='e7f5c2f0e0850ae17fd0685aa16aaac6313551b1'
phase=sys.argv[1]
assets={'/'+f.removeprefix('public/'):v[phase] for f,v in m['files'].items() if f.startswith('public/') and not f.endswith('.php') and v[phase] is not None}
paths=['/','/app','/cfc29397','/api/v1/guest/comm/config']+list(assets)
def check(item):
    host,path=item
    url='https://'+host+path+('?v='+m['commit'] if path in assets or path=='/cfc29397' else '')
    with urllib.request.urlopen(urllib.request.Request(url,headers={'User-Agent':'Xboard-release-check','Accept-Encoding':'identity'}),timeout=40) as response:
        body=response.read()
        record={'host':host,'path':path,'status':response.status,'sha256':hashlib.sha256(body).hexdigest()}
        assert record['status']==200,record
        if path in assets: assert record['sha256']==assets[path],record
        if phase=='after' and path=='/cfc29397': assert b'admin-0cd2ebb1fc61037293dc' in body,'OLD_ADMIN_HTML'
        if phase=='after' and path=='/app':
            assert b'er20261109receipt1' in body and b'elephant-route-order-refund.js' in body,'OLD_USER_HTML'
        return record
with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
    rows=list(pool.map(check,[(host,path) for host in ['www.elephant111.org','elephant111.org'] for path in paths]))
(out/('public-'+phase+'.json')).write_text(json.dumps(rows,indent=2))
print('PUBLIC_OK',phase,len(rows),'ASSET_HASHES',sum(row['path'] in assets for row in rows))
