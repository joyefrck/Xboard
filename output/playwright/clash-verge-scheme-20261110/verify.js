async (page) => {
  await page.getByRole('button',{name:'一键导入订阅',exact:true}).click();
  const row=page.getByRole('listitem',{name:'导入到 Clash Verge',exact:true});
  const layout=await row.evaluate(el=>({fontSize:getComputedStyle(el.querySelector('.text-gray-500')).fontSize,overflow:document.documentElement.scrollWidth>innerWidth,bound:el.dataset.erClashVergeImportBound}));
  if(layout.fontSize!=='14px'||layout.overflow||layout.bound!=='1')throw new Error(JSON.stringify(layout));
  await page.screenshot({path:'output/playwright/clash-verge-scheme-20261110/menu.png'});
  const requestPromise=page.waitForRequest(request=>/^(clash|clash-verge|stash):/.test(request.url()),{timeout:5000});
  await row.getByRole('img',{name:'Clash Verge',exact:true}).click();
  const request=await requestPromise;
  const target=new URL(request.url());
  if(target.protocol!=='clash-verge:'||target.searchParams.get('url')!=='https://example.test/sub?token=fixture'||target.searchParams.get('name')!=='大象网络')throw new Error(request.url());
  console.log(JSON.stringify({layout,importUrl:request.url()}));
}
