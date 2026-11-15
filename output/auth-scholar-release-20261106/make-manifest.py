import base64,gzip,hashlib,json,subprocess
from pathlib import Path
r=Path.cwd();out=r/'output/auth-scholar-release-20261106'
commit=subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip();parent=subprocess.check_output(['git','rev-parse','HEAD^'],text=True).strip()
files=[f for f in (out/'commit-files.txt').read_text().splitlines() if not f.startswith('tests/') and f!='public/theme/.gitignore']
mapping={f:f for f in files}
for name in ['elephant-route-auth.css','umi.js','umi.js.gz','umi.js.br']:mapping['public/theme/ElephantRoute/assets/'+name]='theme/ElephantRoute/assets/'+name
manifest={'commit':commit,'parent':parent,'production_root':'/opt/1panel/apps/openresty/openresty/www/sites/xboard/index','backup':'/root/xboard-auth-scholar-20261106','files':{}}
for target,source in mapping.items():
 old=subprocess.run(['git','show',parent+':'+source],capture_output=True)
 manifest['files'][target]={'source':source,'before':hashlib.sha256(old.stdout).hexdigest() if old.returncode==0 else None,'after':hashlib.sha256((r/source).read_bytes()).hexdigest()}
(out/'manifest.json').write_text(json.dumps(manifest,indent=2))
print(base64.b64encode(gzip.compress(json.dumps(manifest).encode())).decode())
print('RUNTIME_FILES',len(mapping))
