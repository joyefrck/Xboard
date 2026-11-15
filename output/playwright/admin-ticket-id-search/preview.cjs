const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const root = '/Users/joyefrack/Documents/GitHub/Xboard';
const users = [{id:41,email:'member@example.test',invite_user:null,plan_id:null,group_id:null,commission_type:0,commission_rate:null,commission_balance:0,balance:0,u:0,d:0,transfer_enable:0,traffic_package_remaining:0,expired_at:null,banned:false,is_admin:false,is_staff:false,created_at:1793560000,updated_at:1793560000,remarks:null}];
const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://127.0.0.1');
  const json = value => { res.writeHead(200, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' }); res.end(JSON.stringify({ status: 'success', data: value })); };
  if (url.pathname.startsWith('/api/')) {
    let body = ''; for await (const chunk of req) body += chunk;
    const data = body ? JSON.parse(body) : Object.fromEntries(url.searchParams);
    if(url.pathname.endsWith('/ticket/fetch')){const all=[{id:3145,user_id:41,subject:'节点不能使用',level:0,status:0,reply_status:1,created_at:1793560000,updated_at:1793560000,user:{id:41,email:'member@example.test'},messages:[]},{id:3144,user_id:42,subject:'已解决的历史工单',level:0,status:1,reply_status:0,created_at:1793550000,updated_at:1793550000,user:{id:42,email:'closed@example.test'},messages:[]}];let filters=data.filter||[];if(typeof filters==='string')filters=JSON.parse(filters);if(req.method==='GET'){filters=[];for(const [key,value] of url.searchParams){const m=key.match(/^filter\[(\d+)\]\[(id|value)\]$/);if(m){filters[+m[1]]??={};filters[+m[1]][m[2]]=value;}}}let rows=all.filter(t=>!data.ticket_id||t.id===Number(data.ticket_id));if(!data.ticket_id){for(const f of filters){if(f.id==='status')rows=rows.filter(t=>String(t.status)===String(f.value));}}if(data.email)rows=rows.filter(t=>t.user.email.includes(data.email));fs.writeFileSync(path.join(root,'output/playwright/admin-ticket-id-search/last-request.json'),JSON.stringify(data));res.writeHead(200,{'Content-Type':'application/json'});return res.end(JSON.stringify({status:'success',data:rows,total:rows.length}));}
    if(url.pathname.endsWith('/user/fetch')){res.writeHead(200,{'Content-Type':'application/json'});return res.end(JSON.stringify({status:'success',data:users,total:users.length}));}
    if(url.pathname.endsWith('/plan/fetch')||url.pathname.endsWith('/plan/getList')||url.pathname.endsWith('/traffic-package/fetch')||url.pathname.endsWith('/server/group/fetch'))return json([]);
    if(url.pathname.endsWith('/user/update')){fs.writeFileSync(path.join(root,'output/playwright/admin-user-identity/last-save.json'),JSON.stringify(data));return json(true);}
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
    .replaceAll('{{ $title }}', '大象网络 · 工单号搜索 隔离预览').replaceAll('{{ $version }}', 'vless-preview')
    .replaceAll('{{ $logo }}', '').replaceAll('{{ $secure_path }}', 'preview');
  html = html.replace('<div id="root"></div>', '<script>localStorage.setItem("XBOARD_ACCESS_TOKEN", JSON.stringify({value:"fixture-only",time:Date.now(),expire:null}));localStorage.setItem("i18nextLng","zh-CN");</script><div id="root"></div>');
  res.writeHead(200, { 'Content-Type': 'text/html', 'Cache-Control': 'no-store' }); res.end(html);
});
server.listen(7332, '127.0.0.1', () => console.log('用户编辑 isolated preview: http://127.0.0.1:7332/preview#/user/ticket'));
