async (page) => {
 const results=[];
 const inspect=async (route, expectedPassword) => {
  await page.locator('input[name="email"]').waitFor({state:'visible'});
  await page.locator('body.er-auth-'+({login:'login',register:'register',forgetpassword:'forgot'})[route]).waitFor({state:'visible'});
  const data=await page.evaluate(()=>{
   const card=document.querySelector('.n-card'),email=card.querySelector('input[name="email"]'),password=card.querySelector('input[name="password"]'),confirm=card.querySelector('input[name="confirm_password"]');
   return {route:location.hash,email:email.autocomplete,password:password.autocomplete,confirm:confirm.autocomplete,sameForm:email.form===password.form&&!!email.form,method:email.form?.method,localePreference:JSON.parse(localStorage.getItem('VUE_NAIVE_LOCALE')).value,text:card.innerText,overflow:document.documentElement.scrollWidth>innerWidth,formSubmissionBlocked:!email.form.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}))};
  });
  if(data.route!==`#/${route}`||data.email!=='username'||data.password!==expectedPassword||data.confirm!=='new-password'||!data.sameForm||data.method!=='post'||!data.formSubmissionBlocked||data.overflow||data.localePreference!=='en-US'||/简体中文|English|繁體中文|Forgot password|Register|Password/.test(data.text)||!data.text.includes('邮箱'))throw new Error(JSON.stringify(data));
  results.push(data);
 };
 await page.setViewportSize({width:1440,height:1000});
 await page.goto('http://127.0.0.1:7336/#/login');
 await inspect('login','current-password');
 await page.screenshot({path:'output/playwright/auth-autofill/login-desktop.png'});
 await page.getByRole('link',{name:'注册',exact:true}).click();
 await inspect('register','new-password');
 await page.screenshot({path:'output/playwright/auth-autofill/register-desktop.png'});
 await page.getByRole('link',{name:'返回登入',exact:true}).click();
 await inspect('login','current-password');
 await page.getByRole('link',{name:'忘记密码',exact:true}).click();
 await inspect('forgetpassword','new-password');
 await page.screenshot({path:'output/playwright/auth-autofill/forgot-desktop.png'});
 await page.getByRole('link',{name:'返回登入',exact:true}).click();
 await page.setViewportSize({width:390,height:844});
 await inspect('login','current-password');
 await page.screenshot({path:'output/playwright/auth-autofill/login-mobile.png'});
 await page.getByRole('link',{name:'注册',exact:true}).click();
 await inspect('register','new-password');
 await page.screenshot({path:'output/playwright/auth-autofill/register-mobile.png',fullPage:true});
 await page.getByRole('link',{name:'返回登入',exact:true}).click();
 await page.getByRole('link',{name:'忘记密码',exact:true}).click();
 await inspect('forgetpassword','new-password');
 await page.screenshot({path:'output/playwright/auth-autofill/forgot-mobile.png',fullPage:true});
 await page.evaluate(data => {window.__erAuthVerification=data}, {checks:results.length,results});
}
