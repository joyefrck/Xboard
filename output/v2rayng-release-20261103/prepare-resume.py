import hashlib,json,os,shutil,subprocess,urllib.request,gzip
from pathlib import Path
b=Path('/root/xboard-v2rayng-20261103');m=json.loads((b/'manifest.json').read_text());r=Path(m['production_root'])
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
