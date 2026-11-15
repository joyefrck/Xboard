import json,subprocess,time
from pathlib import Path
b=Path('/root/xboard-passwall-20261103')
s=(b/'deploy.py').read_text();namespace={};exec(s.split('action=sys.argv[1]')[0],namespace)
namespace['verify']()
for i in range(1,4):
 namespace['snapshot']('trend-'+str(i))
 if i<3:time.sleep(40)
args=['docker','exec','-i','index-horizon-1','php','--','--since='+(b/'started.iso').read_text().strip()]
x=subprocess.run(args,input=(b/'business.php').read_bytes(),capture_output=True,check=True)
d=json.loads(x.stdout);(b/'business.json').write_bytes(x.stdout)
print('BUSINESS',json.dumps({k:v for k,v in d.items() if k!='completed_job_evidence'}),flush=True)
health=[]
for name in ['index-web-1','index-horizon-1']:
 d=json.loads(subprocess.check_output(['docker','inspect',name]))[0]
 php='$z=0;foreach(glob("/proc/[0-9]*/stat") as $p){if(preg_match("/^\\d+ \\(.*\\) Z /",file_get_contents($p)))$z++;}echo json_encode(["pid1"=>basename(explode(chr(0),file_get_contents("/proc/1/cmdline"))[0]),"zombies"=>$z,"pids_current"=>trim(file_get_contents("/sys/fs/cgroup/pids.current")),"pids_max"=>trim(file_get_contents("/sys/fs/cgroup/pids.max"))]);'
 p=json.loads(subprocess.check_output(['docker','exec',name,'php','-r',php]))
 health.append({'name':name,'running':d['State']['Running'],'restarts':d['RestartCount'],'init':d['HostConfig'].get('Init'),'image':d['Config']['Image'],'networks':list(d['NetworkSettings']['Networks']),**p})
(b/'health.json').write_text(json.dumps(health,indent=2));print('HEALTH',json.dumps(health),flush=True)

ids=subprocess.check_output(['docker','ps','-aq']).decode().split()
records=json.loads(subprocess.check_output(['docker','inspect']+ids)) if ids else []
others=[{'name':x['Name'].lstrip('/'),'image':x['Config']['Image'],'state':x['State']['Status'],'restarts':x['RestartCount'],'health':x['State'].get('Health',{}).get('Status')} for x in records]
(b/'all-containers.json').write_text(json.dumps(others,indent=2));print('ALL_CONTAINERS',json.dumps(others),flush=True)
print('POST_CHECKS_DONE',flush=True)
