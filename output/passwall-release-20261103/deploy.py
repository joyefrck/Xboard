import hashlib,json,os,shutil,subprocess,sys,time
from datetime import datetime
from pathlib import Path
from zoneinfo import ZoneInfo

b=Path('/root/xboard-passwall-20261103')
m=json.loads((b/'manifest.json').read_text());r=Path(m['production_root'])
digest=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()

def snapshot(name):
 args=['docker','exec','-i','index-horizon-1','php','--','--root=/www']
 if (b/'started.iso').exists():args.append('--since='+(b/'started.iso').read_text().strip())
 x=subprocess.run(args,input=(b/'queue-snapshot.php').read_bytes(),capture_output=True)
 (b/(name+'.json')).write_bytes(x.stdout)
 if x.returncode or not x.stdout:raise RuntimeError('SNAPSHOT_FAILED '+name)
 d=json.loads(x.stdout)
 assert d['coverage_ok'],'QUEUE_COVERAGE_FAILED '+name
 print('QUEUE',json.dumps({'sample':name,'at':d['sample_at'],'coverage':d['coverage_ok'],'env':[d['app_environment'],d['horizon_environment'],d['selected_environment_pattern']],'cached':d['configuration_cached'],'failed':d['new_failed_jobs'],'zombies':d['container_zombies'],'queues':{k:[v['workers'],v['ready'],v['reserved'],v['delayed'],v['ready_head_age_seconds']] for k,v in d['queues'].items()}}),flush=True)
 return d

def verify(section='after'):
 for f,v in m['files'].items():assert digest(r/f)==v[section], 'FILE_MISMATCH '+f
 for name in ['index-web-1','index-horizon-1']:
  d=json.loads(subprocess.check_output(['docker','inspect',name]))[0];old=json.loads((b/(name+'.before.json')).read_text())
  assert d['State']['Running'] and d['HostConfig'].get('Init'),'RUNTIME_DOWN '+name
  assert d['Image']==old['Image'] and d['Config']['Image']==old['Config']['Image'],'UNEXPECTED_IMAGE_CHANGE '+name
  mounts=lambda z:sorted((x['Type'],x['Source'],x['Destination']) for x in z['Mounts'])
  assert mounts(d)==mounts(old),'MOUNTS_CHANGED '+name
  assert set(d['NetworkSettings']['Networks'])==set(old['NetworkSettings']['Networks']),'NETWORKS_CHANGED '+name
  print('RUNTIME_OK',name,d['Config']['Image'],'restarts',d['RestartCount'],'revision',d['Config'].get('Labels',{}).get('org.opencontainers.image.revision'),flush=True)

def replace(source,target):
 old=target.stat();temp=target.with_name(target.name+'.passwall-stage');shutil.copy2(source,temp)
 os.chown(temp,old.st_uid,old.st_gid);os.chmod(temp,old.st_mode&0o777);os.replace(temp,target)

def reload():
 for command in [['docker','exec','index-web-1','php','artisan','octane:reload'],['docker','exec','index-horizon-1','php','artisan','horizon:terminate']]:
  subprocess.run(command,check=True,timeout=120)
 for attempt in range(30):
  try:snapshot('warmup');return
  except (RuntimeError,AssertionError,subprocess.SubprocessError):
   if attempt==29:raise
   time.sleep(2)

action=sys.argv[1]
if action=='apply':
 assert (b/'database-verified.json').is_file(),'DATABASE_BACKUP_NOT_VERIFIED'
 assert not (b/'started.iso').exists(),'ALREADY_STARTED'
 verify('before');snapshot('immediate-before')
 for f,v in m['files'].items():
  assert digest(b/'before'/f)==v['before'] and digest(b/'stage'/f)==v['after'],'BACKUP_OR_STAGE_CHANGED '+f
 (b/'started.iso').write_text(datetime.now(ZoneInfo('Asia/Shanghai')).isoformat())
 for f in m['files']:replace(b/'stage'/f,r/f)
 reload();verify();print('APPLIED',m['commit'],flush=True)
elif action=='rollback':
 verify()
 for f,v in m['files'].items():assert digest(b/'before'/f)==v['before'],'BACKUP_CHANGED '+f
 for f in m['files']:replace(b/'before'/f,r/f)
 reload();verify('before');print('ROLLED_BACK_CODE_ONLY',flush=True)
elif action=='verify':verify();snapshot(sys.argv[2] if len(sys.argv)>2 else 'fresh-session')
elif action=='snapshot':snapshot(sys.argv[2])
else:raise RuntimeError('UNKNOWN_ACTION')
