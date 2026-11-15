import hashlib,json,os,shutil,subprocess,urllib.request,gzip,base64
from pathlib import Path
b=Path('/root/xboard-v2rayng-20261103')
assert not b.exists(), 'BACKUP_DIRECTORY_EXISTS'
b.mkdir(mode=0o700)
m={'commit': '9dff882fec86449dd2a4f4ab0878c9c4338b8a39', 'parent': 'aa588952fc6e9d7e2474df83c34b60aa3d37f03b', 'production_root': '/opt/1panel/apps/openresty/openresty/www/sites/xboard/index', 'files': {'app/Http/Controllers/V1/Client/ClientController.php': {'before': '4fc7cdaca166a06b6b8dd05e1e60be1069371197415e3721803198f3bdf0f8dd', 'after': '67b689fba251e7c1494479de5644dba57b4579c1c511808ff856730530043bca'}, 'app/Protocols/General.php': {'before': 'ebbf5d314c45a3c462400dd2fa19140afec942d64eca8827758dac1718c29342', 'after': '2c0e5d42c0a37b8551d88d3234ebe70440b52b0bbc56897dd6c8effedfb88792'}, 'app/Services/VlessConfigService.php': {'before': '2f8349150a48ea47bebaf2c2db8fb67cf7b9284412456eb10fcceeddd57d84f3', 'after': '66c1c6a5e435468f96fb08ccac8eabb586cf3365a0305d2a6b58b58ffe7ab5cb'}}, 'backup': '/root/xboard-v2rayng-20261103'}
r=Path(m['production_root'])
old=Path('/root/xboard-reality-default-20261103')
digest=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()
for f,v in m['files'].items():
 assert digest(r/f)==v['before'], 'PRODUCTION_DRIFT '+f
 for section in ['before','stage']:(b/section/f).parent.mkdir(parents=True,exist_ok=True)
 shutil.copy2(r/f,b/'before'/f)
 with urllib.request.urlopen('https://raw.githubusercontent.com/joyefrck/Xboard/'+m['commit']+'/'+f,timeout=45) as response:(b/'stage'/f).write_bytes(response.read())
 assert digest(b/'stage'/f)==v['after'], 'DOWNLOAD_MISMATCH '+f
(b/'manifest.json').write_text(json.dumps(m,indent=2))
(b/'configuration').mkdir()
for name in ['.env','docker-compose.yml','docker-compose.yaml','compose.yml','compose.yaml','config/horizon.php','config/queue.php','bootstrap/cache/config.php']:
 source=r/name
 if source.is_file():
  target=b/'configuration'/name;target.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(source,target)
for name in ['index-web-1','index-horizon-1']:
 d=json.loads(subprocess.check_output(['docker','inspect',name]))[0]
 (b/(name+'.before.json')).write_text(json.dumps(d))
 print('RUNTIME',name,d['Config']['Image'],'init',d['HostConfig'].get('Init'),'restart',d['RestartCount'],'mount',[(x['Source'],x['Destination']) for x in d['Mounts']],flush=True)
(b/'queue-snapshot.php').write_bytes(gzip.decompress(base64.b64decode('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')))
shutil.copy2(old/'business.php',b/'business.php')
s=gzip.decompress(base64.b64decode('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')).decode()
(b/'deploy.py').write_text(s)
namespace={};exec(s.split('action=sys.argv[1]')[0],namespace);namespace['snapshot']('baseline')
print('STAGED_FILES',len(m['files']),flush=True)
for f in m['files']:
 result=subprocess.run(['docker','exec','-i','index-web-1','php','-l'],input=(b/'stage'/f).read_bytes(),capture_output=True)
 assert result.returncode==0,'STAGED_PHP_SYNTAX_FAILED '+f
print('STAGED_PHP_SYNTAX_OK',flush=True)
subprocess.run(['docker','exec','index-horizon-1','php','scripts/check-horizon-provisioning.php','--environment=local'],check=True)
print('PRODUCTION_GIT_HEAD',subprocess.check_output(['git','-c','safe.directory='+str(r),'-C',str(r),'rev-parse','HEAD'],text=True).strip(),flush=True)
(b/'production-git-status.txt').write_bytes(subprocess.check_output(['git','-c','safe.directory='+str(r),'-C',str(r),'status','--short','--untracked-files=no']))
# Run inside the existing database container; credential remains in its environment.
php="require '/www/vendor/autoload.php'; $app=require '/www/bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo config('database.connections.mysql.database');"
dbname=subprocess.check_output(['docker','exec','index-web-1','php','-r',php],text=True).strip()
assert dbname and all(x.isalnum() or x=='_' for x in dbname),'INVALID_DB_NAME'
containers=json.loads(subprocess.check_output(['docker','ps','--format','{{json .}}']).decode().replace('}\n{','},{').join(['[',']']))
dbcontainers=[x['Names'] for x in containers if 'mariadb' in x['Image']]
assert len(dbcontainers)==1,'AMBIGUOUS_DATABASE_CONTAINER'
command='export MYSQL_PWD="${MARIADB_ROOT_PASSWORD:-$MYSQL_ROOT_PASSWORD}"; exec mariadb-dump --user=root --single-transaction --quick --routines --events --databases "$1"'
with (b/'database-backup.stderr').open('wb') as errors:
 proc=subprocess.Popen(['docker','exec',dbcontainers[0],'sh','-c',command,'backup',dbname],stdout=subprocess.PIPE,stderr=errors)
 with gzip.open(b/'database-before.sql.gz','wb') as target:shutil.copyfileobj(proc.stdout,target)
 assert proc.wait()==0,'DATABASE_BACKUP_FAILED'
size=0;tail=b''
with gzip.open(b/'database-before.sql.gz','rb') as source:
 while True:
  chunk=source.read(1048576)
  if not chunk:break
  size+=len(chunk);tail=(tail+chunk)[-4096:]
assert b'Dump completed' in tail and size>1000000,'BACKUP_INCOMPLETE'
(b/'database-verified.json').write_text(json.dumps({'uncompressed_bytes':size,'compressed_bytes':(b/'database-before.sql.gz').stat().st_size,'dump_complete':True}))
print('DATABASE_BACKUP_OK',size,(b/'database-before.sql.gz').stat().st_size,flush=True)
print('PREPARED',str(b),m['commit'],flush=True)
