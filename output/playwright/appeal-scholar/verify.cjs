async (page) => {
 const errors=[];page.on('pageerror',error=>errors.push(error.message));
 const results=[];
 for(const [width,height] of [[1440,900],[1024,600],[390,844],[320,568],[430,932]]){
  await page.setViewportSize({width,height});
  await page.goto('http://127.0.0.1:7337/app#/dashboard');
  const mobile=width<768;
  if(mobile){
   await page.locator('header i.n-icon').first().click();
   await page.locator('#er-v2-mobile-sidebar').waitFor({state:'visible'});
   await page.waitForFunction(()=>{const e=document.querySelector('.n-drawer.er-v2-mobile-drawer');return e&&Math.abs(e.getBoundingClientRect().left)<1});
  }
  const root=mobile?'#er-v2-mobile-sidebar':'#er-v2-sidebar';
  const art=page.locator(root+' .er-v2-appeal-scholar');
  await art.evaluate(e=>e.decode());
  const card=page.locator(root+' a[href="#/ticket"]');
  await card.scrollIntoViewIfNeeded();
  await card.click({trial:true});
  const data=await page.locator(root).evaluate((nav,{width,height,mobile})=>{
   const image=nav.querySelector('.er-v2-appeal-scholar'),link=nav.querySelector('a[href="#/ticket"]');
   const im=image.getBoundingClientRect(),rect=link.getBoundingClientRect(),scene=link.parentElement.getBoundingClientRect();
   const hit=document.elementFromPoint(rect.left+rect.width/2,rect.top+rect.height/2);
   const scrollParent=nav.closest('.n-scrollbar-content')?.parentElement;
   return {width,height,mobile,loaded:image.complete&&image.naturalWidth===512,decorative:image.alt===''&&image.getAttribute('aria-hidden')==='true',pointerEvents:getComputedStyle(image).pointerEvents,overlap:Math.round(rect.bottom-im.top),imageInsideScene:im.left>=scene.left-1&&im.right<=scene.right+1,cardClickable:!!hit&&hit.closest('a[href="#/ticket"]')===link,horizontalOverflow:document.documentElement.scrollWidth>innerWidth,scrollable:scrollParent?{client:scrollParent.clientHeight,height:scrollParent.scrollHeight}:null};
  },{width,height,mobile});
  if(!data.loaded||!data.decorative||!data.imageInsideScene||!data.cardClickable||data.pointerEvents!=='none'||(data.overlap<5||data.overlap>8)||data.horizontalOverflow)throw new Error(JSON.stringify(data));
  results.push(data);
  if(mobile)await page.screenshot({path:`output/playwright/appeal-scholar/sidebar-${width}x${height}.png`,animations:'disabled'});
  else await page.locator(root).screenshot({path:`output/playwright/appeal-scholar/sidebar-${width}x${height}.png`,animations:'disabled'});
  await card.click();
  await page.waitForURL(url=>url.hash==='#/ticket');
  if(mobile)await page.locator('#er-v2-mobile-sidebar').waitFor({state:'hidden'});
 }
 if(errors.length)throw new Error(JSON.stringify(errors));
 await page.evaluate(data=>window.__scholarVerification=data,{results,errors});
}
