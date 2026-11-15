import concurrent.futures
import hashlib
import json
import pathlib
import sys
import urllib.request

root = pathlib.Path(__file__).resolve().parents[2]
version = 'admin-902a8fba4ce16f963651'
paths = ['/', '/app', '/api/v1/guest/comm/config', '/cfc29397']
assets = ['assets/index.js', 'assets/vendor.js', 'assets/vendor.css', 'assets/index.css', 'locales/zh-CN.js']
paths += ['/assets/admin/' + asset + '?v=' + version for asset in assets]

def check(item):
    host, path = item
    request = urllib.request.Request('https://' + host + path, headers={'User-Agent': 'Xboard-release-check'})
    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            body = response.read()
            result = {'host': host, 'path': path, 'status': response.status,
                      'cache_control': response.headers.get('Cache-Control'),
                      'sha256': hashlib.sha256(body).hexdigest()}
            if path == '/cfc29397':
                result['new_version_in_html'] = version.encode() in body
            if path.startswith('/assets/admin/'):
                expected = (root / 'public' / path.split('?')[0].lstrip('/')).read_bytes()
                result['matches_local'] = body == expected
            return result
    except Exception as error:
        return {'host': host, 'path': path, 'error': type(error).__name__}

with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
    results = list(pool.map(check, [(host, path) for host in ['www.elephant111.org', 'www.elephant111.com'] for path in paths]))
output = pathlib.Path(__file__).with_name(sys.argv[1] + '.json')
output.write_text(json.dumps(results, indent=2))
print(json.dumps(results, indent=2))
if sys.argv[1] != 'public-before':
    assert all(result.get('status') == 200 and result.get('matches_local', True)
               and result.get('new_version_in_html', True) for result in results)
