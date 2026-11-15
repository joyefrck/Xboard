#!/usr/bin/python3
"""Restore the exported SQL in a temporary database with no network access."""
import hashlib
import json
from pathlib import Path
import re
import subprocess as sp
import time

base = Path('/var/lib/elephant-backup')
work = base / 'work'
app = '/opt/1panel/apps/openresty/openresty/www/sites/xboard/index'
name = 'elephant-backup-restore-20261107'
assert name not in sp.check_output(['docker', 'ps', '-a', '--format', '{{.Names}}'], text=True).splitlines()
socket = base / 'restore-socket'
socket.mkdir(mode=0o755, exist_ok=True)
created = False


def run(command, **kwargs):
    return sp.run(command, capture_output=True, check=True, **kwargs).stdout


try:
    run(['docker', 'run', '-d', '--name', name, '--network', 'none', '--memory', '2g', '--cpus', '0.5',
         '--tmpfs', '/var/lib/mysql:rw,size=1536m', '-v', str(socket) + ':/run/mysqld',
         '-e', 'MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1', 'mariadb:11.8.5',
         '--skip-networking', '--skip-log-bin', '--innodb-buffer-pool-size=64M', '--max-connections=8'])
    created = True
    for attempt in range(120):
        response = sp.run(['docker', 'exec', name, 'mariadb-admin', '-uroot', 'ping', '--silent'], capture_output=True)
        if response.returncode == 0:
            break
        time.sleep(1)
    else:
        raise RuntimeError('isolated database did not start')
    sql = work / 'databases.sql'
    with sql.open('rb') as stream:
        sql_digest = hashlib.file_digest(stream, 'sha256').hexdigest()
    expected_tables = len(re.findall(rb'^CREATE TABLE ', sql.read_bytes(), re.M))
    with sql.open('rb') as stream:
        run(['docker', 'exec', '-i', name, 'mariadb', '-uroot'], stdin=stream)
    run(['docker', 'exec', name, 'mariadb-check', '-uroot', '--all-databases', '--check', '--silent'])
    rows = json.loads(run(['docker', 'inspect', 'index-web-1']))
    php = r'''require '/www/vendor/autoload.php';
$env=Dotenv\Dotenv::parse(file_get_contents('/www/.env'));
$key=$env['APP_KEY']; if(str_starts_with($key,'base64:'))$key=base64_decode(substr($key,7));
$crypt=new Illuminate\Encryption\Encrypter($key,'AES-256-CBC');
$db=new PDO('mysql:unix_socket=/restore-socket/mysqld.sock;dbname=xboard','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$counts=[];
foreach($tables as $table){if(!preg_match('/^[A-Za-z0-9_]+$/',$table))throw new Exception('invalid table');$counts[$table]=(int)$db->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();}
$checked=0;foreach(['v2_bandwagon_vps'=>'api_key','v2_node_automation_connections'=>'api_token'] as $table=>$column){
 if(!in_array($table,$tables,true))continue;
 foreach($db->query('SELECT `'.$column.'` FROM `'.$table.'` WHERE `'.$column.'` IS NOT NULL') as $row){if($row[0]==='')continue;$plain=$crypt->decryptString($row[0]);if($plain==='')throw new Exception('empty decrypted value');$checked++;unset($plain);}}
echo json_encode(['table_count'=>count($tables),'row_counts'=>$counts,'encrypted_credentials_checked'=>$checked]);'''
    result = json.loads(run(['docker', 'run', '--rm', '--network', 'none', '--memory', '256m',
                             '--entrypoint', 'php', '-v', app + ':/www:ro',
                             '-v', str(socket) + ':/restore-socket:ro', rows[0]['Image'], '-r', php]))
    assert result['table_count'] == expected_tables, 'restored table count mismatch'
    result.update({'sql_sha256': sql_digest, 'check_tables': 'passed', 'network': 'none'})
    (base / 'restore-result.json').write_text(json.dumps(result, indent=2))
    print(json.dumps({k: v for k, v in result.items() if k != 'row_counts'}), flush=True)
finally:
    if created:
        run(['docker', 'rm', '-f', name])
