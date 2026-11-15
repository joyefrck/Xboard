import concurrent.futures
import hashlib
import json
import pathlib
import sys
import urllib.request

root = pathlib.Path(__file__).resolve().parents[2]
version = 'admin-f05198ef4d4924e7ab59'
paths = ['/', '/app', '/api/v1/guest/comm/config', '/cfc29397']
assets = ['assets/vless-config.js', 'assets/bandwagon-vps.js', 'assets/bandwagon-vps.css', 'assets/bandwagon-traffic.js', 'assets/node-automation.js', 'assets/index.js', 'assets/vendor.js', 'assets/vendor.css', 'assets/index.css', 'locales/zh-CN.js', 'locales/en-US.js', 'locales/ko-KR.js']
paths += ['/assets/admin/' + asset + '?v=' + version for asset in assets]
theme_assets = ['umi.js', 'elephant-route-wallet.js', 'elephant-route-wallet.css', 'elephant-route-dashboard-v2.js', 'elephant-route-pages-v2.js']
paths += ['/theme/ElephantRoute/assets/' + asset + '?v=wallet-cc21cfdc' for asset in theme_assets]
paths += ['/assets/payment-icons/' + asset + '.png' for asset in ['alipay', 'wechat', 'usdt']]

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
            if path == '/app':
                result['new_version_in_html'] = b'er20261105walletBorder3' in body and b'er20261105greeting1' in body
            if path.startswith(('/assets/admin/', '/theme/ElephantRoute/assets/', '/assets/payment-icons/')):
                expected_path = path.split('?')[0].lstrip('/')
                expected = (root / (expected_path if expected_path.startswith('theme/') else 'public/' + expected_path)).read_bytes()
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
