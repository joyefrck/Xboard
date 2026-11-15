async (page) => {
await page.route('**/api/v1/**', async route => {
  const path = new URL(route.request().url()).pathname;
  const sub = {subscribe_url:'https://example.test/sub?token=fixture',plan_id:1,plan:{id:1,name:'测试套餐'},transfer_enable:107374182400,u:1024,d:2048,expired_at:1900000000};
  const responses = {
    '/api/v1/passport/auth/login': {auth_data:'fixture-token'},
    '/api/v1/guest/comm/config': {is_email_verify:0,is_invite_force:0,app_name:'大象网络'},
    '/api/v1/user/comm/config': {},
    '/api/v1/user/info': {id:1,uuid:'fixture-user',email:'fixture@example.test',balance:0,...sub,subscribe:undefined},
    '/api/v1/user/getSubscribe': sub,
    '/api/v1/user/notice/fetch': [],
    '/api/v1/user/server/fetch': [{id:1,name:'测试节点',type:'vless'}],
    '/api/v1/user/getStat': [0,0,0],
    '/api/v1/user/invite/fetch': {codes:[]},
    '/api/v1/user/order/fetch': [],
    '/api/v1/app-downloads': {platforms:[]},
    '/api/v1/user/traffic-package/fetch': []
  };
  await route.fulfill({json:{data:responses[path] ?? {}}});
});
await page.goto('http://127.0.0.1:8771/output/playwright/clash-verge-scheme-20261110/app.html#/login');
}