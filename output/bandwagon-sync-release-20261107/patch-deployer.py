from pathlib import Path
import hashlib
b = Path('/root/xboard-bandwagon-sync-20261107')
p = b/'deploy.py'
s = p.read_text()
old = "for key in ['name','image','image_ref','mounts','networks','restarts']: assert n[key]==o[key], 'RUNTIME_CHANGED '+key"
new = """for key in ['name','image','image_ref','mounts','networks']: assert n[key]==o[key], 'RUNTIME_CHANGED '+key
        delta = int((b/'horizon-reloaded').read_text()) if n['name']=='/index-horizon-1' and (b/'horizon-reloaded').exists() else 0
        assert n['restarts']==o['restarts']+delta, 'UNEXPECTED_CONTAINER_RESTART'"""
assert s.count(old)==1
s=s.replace(old,new)
s=s.replace("['view:clear','octane:reload']", "['route:clear','view:clear','octane:reload']")
addition="""def reload_horizon():
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

"""
assert s.count('def business(name):')==1
s=s.replace('def business(name):',addition+'def business(name):')
s=s.replace("install('after');reload_web();verify();snapshot('after-apply')", "install('after');reload_web();reload_horizon();verify();snapshot('after-apply')")
s=s.replace("install('before');reload_web();print('AUTO_ROLLED_BACK'", "install('before');reload_web();reload_horizon();print('AUTO_ROLLED_BACK'")
s=s.replace("verify();install('before');reload_web();verify('before')", "verify();install('before');reload_web();reload_horizon();verify('before')")
compile(s,str(p),'exec')
p.write_text(s)
print('DEPLOYER_READY',hashlib.sha256(p.read_bytes()).hexdigest())
