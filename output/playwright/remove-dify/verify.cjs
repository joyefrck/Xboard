async (page) => {
 const requests=[];
 const errors=[];
 page.on('request',request=>{if(/dify-context|embed\.min\.js|fixture-widget|ai\.443ds443/.test(request.url()))requests.push(request.url())});
 page.on('pageerror',error=>errors.push(error.message));
 const checks=[];
 const inspect=async route=>{
  const result=await page.evaluate(()=>({hash:location.hash,bubble:!!document.querySelector('#dify-chatbot-bubble-button,#dify-chatbot-bubble-window'),config:!!window.difyChatbotConfig,scripts:[...document.scripts].map(x=>x.src).filter(src=>/embed\.min\.js|fixture-widget|ai\.443ds443/.test(src))}));
  if(result.hash!==`#/${route}`||result.bubble||result.config||result.scripts.length)throw new Error(JSON.stringify(result));
  checks.push(result);
 };
 await page.setViewportSize({width:1440,height:1000});
 await page.goto('http://127.0.0.1:7337/app?open_ai_support=1#/dashboard');
 await page.locator('#er-v2-sidebar').waitFor({state:'visible'});
 await inspect('dashboard');
 await page.screenshot({path:'output/playwright/remove-dify/dashboard-desktop.png'});
 await page.locator('#er-v2-sidebar a[href="#/ticket"]').click();
 await page.waitForURL(url=>url.hash==='#/ticket');
 await inspect('ticket');
 for(const route of ['plan','invite','knowledge','order','node','profile']){
  await page.evaluate(route=>{location.hash='#/'+route},route);
  await page.waitForURL(url=>url.hash==='#/'+route);
  await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
  await inspect(route);
 }
 await page.setViewportSize({width:390,height:844});
 await page.evaluate(()=>{location.hash='#/dashboard'});
 await page.waitForURL(url=>url.hash==='#/dashboard');
 await page.locator('#er-dashboard-v2').waitFor({state:'visible'});
 await inspect('dashboard');
 await page.screenshot({path:'output/playwright/remove-dify/dashboard-mobile.png'});
 await page.evaluate(()=>localStorage.removeItem('VUE_NAIVE_ACCESS_TOKEN'));
 for(const route of ['login','register','forgetpassword']){
  await page.evaluate(route=>{location.hash='#/'+route},route);
  await page.waitForURL(url=>url.hash==='#/'+route);
  await page.locator('input[name="email"]').waitFor({state:'visible'});
  await inspect(route);
 }
 const fixture=await (await page.request.get('http://127.0.0.1:7337/fixture-state')).json();
 const contextCalls=fixture.requests.filter(item=>/dify-context/.test(item.path));
 if(requests.length||contextCalls.length||errors.length)throw new Error(JSON.stringify({requests,contextCalls,errors}));
 await page.evaluate(result=>window.__removeDifyVerification=result,{routes:checks.length,checks,requests,contextCalls,errors});
}
