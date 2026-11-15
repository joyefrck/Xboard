import hashlib,json,os,shutil,subprocess,urllib.request,gzip
from pathlib import Path
b=Path('/root/xboard-ios-stash-tcp-20261103')
assert not b.exists(), 'BACKUP_DIRECTORY_EXISTS'
b.mkdir(mode=0o700)
m={'commit': 'aa588952fc6e9d7e2474df83c34b60aa3d37f03b', 'parent': 'ed9623f20dc1fa807a4699266757b054918107e6', 'production_root': '/opt/1panel/apps/openresty/openresty/www/sites/xboard/index', 'files': {'app/Protocols/Stash.php': {'before': '090ae7f960964a5b529da6b4dbd46b01f470543d325d89fde726c81f4f9aa18f', 'after': 'c506a93b91fa3fe3f690d0b346a33a4b5ffd4a50f085f81f27e34a8004e9dfd8'}}}
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
shutil.copy2(old/'queue-snapshot.php',b/'queue-snapshot.php')
shutil.copy2(old/'business.php',b/'business.php')
s=(old/'deploy.py').read_text().replace(str(old),str(b))
(b/'deploy.py').write_text(s)
namespace={};exec(s.split('action=sys.argv[1]')[0],namespace);namespace['snapshot']('baseline')
print('STAGED_FILES',len(m['files']),flush=True)
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
print('DATABASE_BACKUP_OK',size,(b/'database-before.sql.gz').stat().st_size,flush=True)
print('PREPARED',str(b),m['commit'],flush=True)
