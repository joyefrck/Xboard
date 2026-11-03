"""Offline importer check using upstream Lua, with only OpenWrt helpers stubbed.

Usage: python3 tests/passwall-reality-import.py LUA PASSWALL_SOURCE PASSWALL2_SOURCE
Sources: official Openwrt-Passwall repos, root/usr/share/{passwall,passwall2}/subscribe.lua.
This exercises processData, not UCI persistence, core selection/version or networking.
"""
import base64
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parent.parent
SOURCES = {
    'passwall': 'fa38ccbf05b64cd6635c632e4ffef0693847a3e411ef95a1624a764da0458330',
    'passwall2': 'b2d5c4e9dbfdc98666bbb47e5643550440d680e7e089b0759695af497094e303',
}
HELPERS = r'''
local has_xray, has_singbox = true, false
local DEFAULT_VLESS_TYPE = 'xray'
local function split(str, sep)
    local out, start = {}, 1
    if not str then return out end
    while true do
        local first, last = str:find(sep, start)
        if not first then out[#out+1] = str:sub(start); return out end
        out[#out+1] = str:sub(start, first-1)
        start = last+1
    end
end
local function UrlDecode(str)
    return (str:gsub('+', ' '):gsub('%%(%x%x)', function(hex)
        return string.char(tonumber(hex, 16))
    end))
end
local api = {
    is_ipv6addrport = function(s) return s:match('^%[.*%]:%d+$') ~= nil end,
    get_ipv6_only = function(s) return s:match('^%[(.*)%]') end
}
'''


def lua_string(value):
    return json.dumps(value, ensure_ascii=False)


def run(lua, sources):
    checks = 0
    for (name, digest), source in zip(SOURCES.items(), sources):
        raw = Path(source).read_bytes()
        assert hashlib.sha256(raw).hexdigest() == digest, f'{name}: upstream source hash mismatch'
        text = raw.decode()
        start = text.index('local function processData(')
        end = text.index('\nlocal function curl(', start)
        importer = text[start:end]
        for host in ['fixture.test', '2001:db8::1']:
            node = {
                'name': '东京 # & 03', 'type': 'vless', 'host': host, 'port': 3001,
                'password': '11111111-1111-4111-8111-111111111111',
                'protocol_settings': {
                    'tls': 2, 'network': 'tcp', 'flow': 'xtls-rprx-vision',
                    'reality_settings': {
                        'server_name': 'fixture.test', 'public_key': 'fixture-public-key',
                        'short_id': '0123456789abcdef', 'support_x25519mlkem768': True,
                    },
                },
            }
            request = {'ua': name, 'nodes': [node]}
            response = subprocess.run(
                ['php', 'tests/passwall-reality-subscription.php', '--render'],
                input=json.dumps(request), text=True, capture_output=True, check=True, cwd=ROOT,
            )
            response = json.loads(response.stdout)
            assert response['status'] == 200, response
            lines = base64.b64decode(response['body'], validate=True).decode().splitlines()
            assert len(lines) == 1 and lines[0].startswith('vless://')
            expected = {
                'type': 'Xray', 'protocol': 'vless', 'transport': 'raw',
                'remarks': node['name'], 'uuid': node['password'], 'address': host,
                'port': '3001', 'encryption': 'none', 'flow': 'xtls-rprx-vision',
                'tls': '1', 'tls_serverName': 'fixture.test', 'utls': '1',
                'fingerprint': 'chrome', 'reality': '1',
                'reality_publicKey': 'fixture-public-key', 'reality_shortId': '0123456789abcdef',
            }
            assertions = '\n'.join(
                f'assert(node[{lua_string(k)}] == {lua_string(v)}, {lua_string(k)})'
                for k, v in expected.items()
            )
            script = HELPERS + importer + '\nlocal node = processData("vless", ' + lua_string(lines[0][8:]) + ', "2", "fixture")\nassert(node)\n' + assertions
            with tempfile.TemporaryDirectory(prefix='passwall-import-') as folder:
                path = Path(folder) / 'check.lua'
                path.write_text(script)
                subprocess.run([lua, str(path)], check=True, timeout=10)
            checks += len(expected)
        print(f'{name}: official processData imports domain + IPv6 REALITY/Vision URIs; SHA256={digest}')
    print(f'Official Lua import: {checks} field checks passed')


if __name__ == '__main__':
    if len(sys.argv) != 4:
        raise SystemExit(__doc__)
    run(sys.argv[1], sys.argv[2:])
