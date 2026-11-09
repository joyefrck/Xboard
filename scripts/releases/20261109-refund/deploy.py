import concurrent.futures, hashlib, json, os, shutil, subprocess, sys, time, urllib.request
from datetime import datetime
from pathlib import Path
from zoneinfo import ZoneInfo

b = Path('/root/xboard-refund-release-20261109')
m = json.loads((b / 'manifest.json').read_text())
m['commit'] = (b/'commit').read_text().strip()
r = Path(m['production_root'])
def digest(p):
    return hashlib.sha256(p.read_bytes()).hexdigest() if p.exists() else None
def run(args, **kwargs):
    return subprocess.run(args, check=True, capture_output=True, **kwargs).stdout
def save(name, value):
    (b / name).write_text(json.dumps(value, indent=2))
def runtime():
    rows = json.loads(run(['docker', 'inspect', 'index-web-1', 'index-horizon-1']))
    result = []
    for d in rows:
        result.append({'name': d['Name'], 'image': d['Image'], 'image_ref': d['Config']['Image'],
            'revision': d['Config'].get('Labels', {}).get('org.opencontainers.image.revision'),
            'running': d['State']['Running'], 'restarts': d['RestartCount'], 'init': d['HostConfig'].get('Init'),
            'mounts': [{k: a[k] for k in ['Type', 'Source', 'Destination']} for a in d['Mounts']],
            'networks': sorted(d['NetworkSettings']['Networks'])})
    return result
def snapshot(name):
    args = ['docker', 'exec', '-i', 'index-horizon-1', 'php', '--', '--root=/www']
    if (b/'started.iso').exists(): args += ['--since='+(b/'started.iso').read_text().strip()]
    data = json.loads(run(args, input=(b/'queue-snapshot.php').read_bytes()))
    save(name+'.json', data)
    assert data['coverage_ok'], 'QUEUE_COVERAGE_FAILED'
    print('QUEUE', json.dumps({'sample':name,'at':data['sample_at'],'coverage':data['coverage_ok'],
        'environments':[data['app_environment'],data['horizon_environment'],data['selected_environment_pattern']],
        'failed':data['new_failed_jobs'], 'queues':{k:[v['workers'],v['ready'],v['reserved'],v['delayed'],v['ready_head_age_seconds']] for k,v in data['queues'].items()}}),flush=True)
    return data
def verify(section='after'):
    for f,v in m['files'].items(): assert digest(r/f)==v[section], 'FILE_DRIFT '+f
    now=runtime(); old=json.loads((b/'runtime-before.json').read_text())
    for n,o in zip(now,old):
        assert n['running'] and n['init'], 'CONTAINER_NOT_HEALTHY'
        for key in ['name','image','image_ref','mounts','networks']: assert n[key]==o[key], 'RUNTIME_CHANGED '+key
        delta = int((b/'horizon-reloaded').read_text()) if n['name']=='/index-horizon-1' and (b/'horizon-reloaded').exists() else 0
        assert n['restarts']==o['restarts']+delta, 'UNEXPECTED_CONTAINER_RESTART'
    save('runtime-current.json',now)
def prepare():
    assert not (b/'prepared').exists(), 'ALREADY_PREPARED'
    info=runtime()
    for d in info:
        assert d['running'] and d['init']
        assert {'Type':'bind','Source':str(r),'Destination':'/www'} in d['mounts'], 'WRONG_MOUNT'
    save('runtime-before.json',info)
    drift=[f for f,v in m['files'].items() if digest(r/f)!=v['before']]
    if drift: print('BASELINE_DRIFT',json.dumps(drift),flush=True)
    assert not drift, 'PRODUCTION_BASELINE_DIFFERS'
    for f,v in m['files'].items():
        if (r/f).exists():
            dest=b/'before'/f; dest.parent.mkdir(parents=True,exist_ok=True); shutil.copy2(r/f,dest)
    def fetch(item):
        f,v=item
        url='https://raw.githubusercontent.com/joyefrck/Xboard/'+m['commit']+'/'+v['source']
        payload=urllib.request.urlopen(url,timeout=60).read()
        assert hashlib.sha256(payload).hexdigest()==v['after'], 'SOURCE_HASH_FAILED '+f
        dest=b/'stage'/f; dest.parent.mkdir(parents=True,exist_ok=True); dest.write_bytes(payload)
    with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:list(pool.map(fetch,m['files'].items()))
    database_backup()
    q=snapshot('before')
    out=run(['docker','exec','index-horizon-1','php','scripts/check-horizon-provisioning.php','--environment='+q['horizon_environment']])
    (b/'provisioning-before.json').write_bytes(out)
    for config in ['config/horizon.php','config/queue.php']:
        dest=b/'before'/config;dest.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(r/config,dest)
    git={'head':run(['git','-c','safe.directory='+str(r),'-C',str(r),'rev-parse','HEAD']).decode().strip(),'modified_count':len(run(['git','-c','safe.directory='+str(r),'-C',str(r),'status','--porcelain','--untracked-files=no']).splitlines())}
    save('git-before.json',git);(b/'prepared').touch()
    print('PREPARED',m['commit'],'FILES',len(m['files']),'BACKUP',str(b),'GIT',json.dumps(git),flush=True)
def install(section):
    for f,v in m['files'].items():
        p=r/f
        if v[section] is None:
            if p.exists():p.unlink()
            continue
        source=b/('stage' if section=='after' else 'before')/f
        assert digest(source)==v[section], 'STAGED_HASH_FAILED '+f
        p.parent.mkdir(parents=True,exist_ok=True)
        previous=p.stat() if p.exists() else p.parent.stat()
        temp=p.with_name(p.name+'.refund-release-stage');shutil.copyfile(source,temp)
        os.chown(temp,previous.st_uid,previous.st_gid);os.chmod(temp,0o644);os.replace(temp,p)
def reload_web():
    for command in ['route:clear','view:clear','octane:reload']:
        run(['docker','exec','index-web-1','php','artisan',command],timeout=120)
def reload_horizon():
    previous=runtime()[1]['restarts']
    run(['docker','exec','index-horizon-1','php','artisan','horizon:terminate'],timeout=30)
    for _ in range(50):
        now=runtime()[1]
        if now['running'] and now['restarts']==previous+1: break
        assert now['restarts']<=previous+1, 'HORIZON_RESTART_LOOP'
        time.sleep(2)
    else: raise RuntimeError('HORIZON_RESTART_TIMEOUT')
    initial=json.loads((b/'runtime-before.json').read_text())[1]['restarts']
    (b/'horizon-reloaded').write_text(str(now['restarts']-initial))
    for _ in range(30):
        try:
            snapshot('worker-reload')
            return
        except (subprocess.CalledProcessError,AssertionError): time.sleep(2)
    raise RuntimeError('HORIZON_CONSUMERS_NOT_READY')

def business(name):
    out=run(['docker','exec','-i','index-horizon-1','php','--','--since='+(b/'started.iso').read_text().strip()],input=(b/'verify.php').read_bytes())
    data=json.loads(out);save(name+'.json',data)
    print('BUSINESS',json.dumps({k:v for k,v in data.items() if k!='completed_job_evidence'}),flush=True)
def health():
    result=[]
    php='$z=0;foreach(glob("/proc/[0-9]*/stat") as $p){if(preg_match("/^\\d+ \\(.*\\) Z /",file_get_contents($p)))$z++;}echo json_encode(["pid1"=>basename(explode(chr(0),file_get_contents("/proc/1/cmdline"))[0]),"zombies"=>$z,"pids_current"=>trim(file_get_contents("/sys/fs/cgroup/pids.current")),"pids_max"=>trim(file_get_contents("/sys/fs/cgroup/pids.max"))]);'
    for d in runtime():
        d.update(json.loads(run(['docker','exec',d['name'].lstrip('/'),'php','-r',php])));result.append(d)
    ids=run(['docker','ps','-aq']).decode().split()
    records=json.loads(run(['docker','inspect']+ids))
    containers=[{'name':x['Name'],'image':x['Config']['Image'],'state':x['State']['Status'],'restarts':x['RestartCount'],'health':x['State'].get('Health',{}).get('Status')} for x in records]
    pong=run(['docker','exec','index-redis-1','redis-cli','ping']).decode().strip()
    assert pong=='PONG';save('health.json',{'runtime':result,'containers':containers,'redis':pong})
    print('HEALTH',json.dumps({'runtime':[{'name':d['name'],'init':d['init'],'restarts':d['restarts'],'pid1':d['pid1'],'zombies':d['zombies'],'pids_current':d['pids_current'],'pids_max':d['pids_max']} for d in result],'containers':containers,'redis':pong}),flush=True)


def database_backup():
    ids=run(['docker','ps','-q']).decode().split()
    rows=json.loads(run(['docker','inspect']+ids))
    dbs=[d for d in rows if d['Config']['Image'].startswith('mariadb:')]
    assert len(dbs)==1, 'DATABASE_TOPOLOGY_CHANGED'
    d=dbs[0];env=dict(s.split('=',1) for s in d['Config'].get('Env',[]) if '=' in s)
    password=env.get('MARIADB_ROOT_PASSWORD') or env.get('MYSQL_ROOT_PASSWORD')
    assert password, 'DATABASE_CREDENTIALS_UNAVAILABLE'
    escaped=password.replace('\\','\\\\').replace('"','\\"').replace('\n','\\n').replace('\r','\\r')
    credentials=('[client]\nuser=root\npassword="'+escaped+'"\n').encode()
    command='set -eu; umask 077; c=$(mktemp /dev/shm/xboard-refund-db.XXXXXX); trap \'rm -f "$c"\' EXIT; cat > "$c"; mariadb-dump --defaults-extra-file="$c" --single-transaction --quick --hex-blob --routines --events --triggers --skip-lock-tables --all-databases'
    with (b/'database-before.sql').open('wb') as target:
        subprocess.run(['docker','exec','-i',d['Name'].lstrip('/'),'sh','-c',command],input=credentials,stdout=target,stderr=subprocess.PIPE,check=True,timeout=600)
    assert (b/'database-before.sql').stat().st_size>1024
    save('database-backup.json',{'bytes':(b/'database-before.sql').stat().st_size,'sha256':digest(b/'database-before.sql')})
    print('DATABASE_BACKED_UP', (b/'database-before.sql').stat().st_size,flush=True)

def migrate():
    target='/tmp/xboard-refund-migrations-20261109'
    run(['docker','exec','index-web-1','mkdir','-p',target])
    for f in m['migrations']:
        run(['docker','cp',str(b/'stage'/f),'index-web-1:'+target+'/'+Path(f).name])
        output=run(['docker','exec','index-web-1','php','artisan','migrate','--force','--realpath','--path='+target+'/'+Path(f).name],timeout=120)
        (b/(Path(f).stem+'.log')).write_bytes(output)
    (b/'migrations-applied').touch()
    print('MIGRATIONS_APPLIED',len(m['migrations']),flush=True)

action=sys.argv[1]
if action=='prepare':prepare()
elif action=='apply':
    assert (b/'prepared').exists() and not (b/'started.iso').exists()
    verify('before');snapshot('immediate-before')
    assert json.loads((b/'ci-result.json').read_text())['conclusion']=='success', 'CI_NOT_SUCCESSFUL'
    assert json.loads((b/'ci-result.json').read_text())['headSha']==m['commit'], 'WRONG_CI_COMMIT'
    (b/'started.iso').write_text(datetime.now(ZoneInfo('Asia/Shanghai')).isoformat())
    try:
        migrate();install('after');reload_web();reload_horizon();verify();snapshot('after-apply')
    except BaseException:
        install('before');reload_web();reload_horizon();print('AUTO_ROLLED_BACK',flush=True);raise
    print('APPLIED',m['commit'],flush=True)
elif action=='rollback':
    verify();install('before');reload_web();reload_horizon();verify('before');print('ROLLED_BACK_CODE_ONLY',flush=True)
elif action=='snapshot':snapshot(sys.argv[2])
elif action=='verify':
    verify();snapshot(sys.argv[2] if len(sys.argv)>2 else 'fresh-session');health();business('business-final')
    out=run(['docker','exec','index-horizon-1','php','scripts/check-horizon-provisioning.php','--environment=local']);(b/'provisioning-after.json').write_bytes(out)
    print('VERIFIED',m['commit'],flush=True)
elif action=='business':business('business-after')
else:raise RuntimeError('UNKNOWN_ACTION')
