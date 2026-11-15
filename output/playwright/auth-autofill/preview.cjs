const http=require('node:http'),fs=require('node:fs'),path=require('node:path');
const root=process.cwd();
http.createServer((req,res)=>{
 const url=new URL(req.url,'http://127.0.0.1');
 if(url.pathname.startsWith('/api/')){
  const data=url.pathname.endsWith('/config')?{is_captcha:0,is_email_verify:1,telegram_login_enable:0,is_invite_force:0,email_whitelist_suffix:[],tos_url:''}:{};
  res.writeHead(200,{'Content-Type':'application/json'});res.end(JSON.stringify({data}));return;
 }
 if(url.pathname.startsWith('/theme/ElephantRoute/assets/')){
  const file=path.resolve(root,'public'+url.pathname);
  if(!file.startsWith(path.join(root,'public/theme/ElephantRoute/assets/'))||!fs.existsSync(file)){res.writeHead(404);return res.end();}
  res.writeHead(200,{'Content-Type':({'.js':'text/javascript','.css':'text/css','.svg':'image/svg+xml','.png':'image/png','.ico':'image/x-icon'})[path.extname(file)]||'application/octet-stream','Cache-Control':'no-store'});fs.createReadStream(file).pipe(res);return;
 }
 let html=fs.readFileSync(path.join(root,'theme/ElephantRoute/dashboard.blade.php'),'utf8');
 const replacements={'{{$theme}}':'ElephantRoute','{{$title}}':'大象网络','{{$version}}':'auth-preview-20261106','{{$description}}':'','{{$logo}}':'',"{{ $theme_config['theme_color'] ?? \"default\" }}":'default',"{{$theme_config['background_url']}}":'','{!! $theme_config[\'custom_html\'] !!}':''};
 for(const [from,to] of Object.entries(replacements))html=html.replaceAll(from,to);
 res.writeHead(200,{'Content-Type':'text/html; charset=utf-8','Cache-Control':'no-store'});res.end(html);
}).listen(7336,'127.0.0.1',()=>console.log('Auth preview http://127.0.0.1:7336/#/login'));
