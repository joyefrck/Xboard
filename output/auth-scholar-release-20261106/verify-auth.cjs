async (page) => {
 const requests=[],results=[];
 page.on('request',r=>{if(/dify-context|embed\.min\.js|ai\.443ds443/.test(r.url()))requests.push(r.url())});
 for(const [width,height] of [[1440,1000],[390,844]]){
  await page.setViewportSize({width,height});
  await page.goto('https://www.elephant111.org/app#/login');
  await page.evaluate(()=>localStorage.setItem('VUE_NAIVE_LOCALE',JSON.stringify({value:'en-US',time:Date.now(),expire:null})));
  await page.reload();
  for(const route of ['login','register','forgetpassword']){
   if(route!=='login'){
    await page.evaluate(route=>{location.hash='#/'+route},route);
    await page.waitForURL(url=>url.hash==='#/'+route);
   }
   await page.locator('input[name="email"]').waitFor({state:'visible'});
   await page.locator('body.er-auth-'+({login:'login',register:'register',forgetpassword:'forgot'})[route]).waitFor({state:'visible'});
   const d=await page.evaluate(()=>{
    const email=document.querySelector('input[name="email"]'),password=document.querySelector('input[name="password"]');
    return {route:location.hash,email:email.autocomplete,password:password.autocomplete,sameForm:email.form===password.form&&!!email.form,text:document.querySelector('.n-card').innerText,bubble:!!document.querySelector('#dify-chatbot-bubble-button'),overflow:document.documentElement.scrollWidth>innerWidth};
   });
   if(d.route!=='#/'+route||d.email!=='username'||d.password!==(route==='login'?'current-password':'new-password')||!d.sameForm||d.bubble||d.overflow||/English|简体中文|繁體中文|Register|Password/.test(d.text)||!d.text.includes('邮箱'))throw new Error(JSON.stringify(d));
   results.push({width,height,...d});
   await page.screenshot({path:`output/auth-scholar-release-20261106/production-${route}-${width}.png`,animations:'disabled'});
  }
 }
 if(requests.length)throw new Error(JSON.stringify(requests));
 await page.evaluate(data=>window.__productionAuthVerification=data,{checks:results.length,results,difyRequests:requests.length});
}
