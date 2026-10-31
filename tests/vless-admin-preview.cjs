const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const nodes = [{id:1,display_id:201,name:'隔离测试 VLESS',type:'vless',host:'test.example.invalid',port:443,server_port:443,rate:'1.00',show:true,group_ids:[1],tags:[],route_ids:[],protocol_settings:{tls:2,flow:'xtls-rprx-vision',network:'tcp',reality_settings:{server_name:'www.microsoft.com',server_port:443,private_key:'fixture-private',public_key:'fixture-public',short_id:'0123456789abcdef'}}}];
const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://127.0.0.1');
  const json = value => { res.writeHead(200, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' }); res.end(JSON.stringify({ status: 'success', data: value })); };
  if (url.pathname.startsWith('/api/')) {
    let body = ''; for await (const chunk of req) body += chunk;
    const data = body ? JSON.parse(body) : Object.fromEntries(url.searchParams);
    if(url.pathname.endsWith('/server/manage/getNodes'))return json(nodes);
    if(url.pathname.endsWith('/server/group/fetch'))return json([{id:1,name:'Level2'}]);
    if(url.pathname.endsWith('/server/route/fetch'))return json([]);
    if(url.pathname.endsWith('/server/manage/save')){fs.writeFileSync('/tmp/vless-preview-save.json',JSON.stringify(data));return json(true);}
    if (url.pathname.endsWith('/user/info')) return json({ id: 1, email: 'preview@example.test', is_admin: true, name: '预览管理员', avatar_url: '' });
    return json({});
  }
  if (url.pathname.startsWith('/assets/admin/')) {
    const file = path.resolve(root, 'public' + decodeURIComponent(url.pathname));
    if (!file.startsWith(path.join(root, 'public/assets/admin/') ) || !fs.existsSync(file)) { res.writeHead(404); return res.end(); }
    const type = { '.js': 'text/javascript', '.css': 'text/css', '.ttf': 'font/ttf' }[path.extname(file)] || 'application/octet-stream';
    res.writeHead(200, { 'Content-Type': type, 'Cache-Control': 'no-store' }); return fs.createReadStream(file).pipe(res);
  }
  let html = fs.readFileSync(path.join(root, 'resources/views/admin.blade.php'), 'utf8')
    .replaceAll('{{ $title }}', '大象网络 · VLESS 隔离预览').replaceAll('{{ $version }}', 'vless-preview')
    .replaceAll('{{ $logo }}', '').replaceAll('{{ $secure_path }}', 'preview');
  html = html.replace('<div id="root"></div>', '<script>localStorage.setItem("XBOARD_ACCESS_TOKEN", JSON.stringify({value:"fixture-only",time:Date.now(),expire:null}));localStorage.setItem("i18nextLng","zh-CN");</script><div id="root"></div>');
  res.writeHead(200, { 'Content-Type': 'text/html', 'Cache-Control': 'no-store' }); res.end(html);
});
server.listen(7316, '127.0.0.1', () => console.log('VLESS isolated preview: http://127.0.0.1:7316/preview#/server/manage'));
