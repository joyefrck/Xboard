async (page) => {
  await page.getByRole('button', {name:'一键导入订阅',exact:true}).click();
  const labels = () => page.locator('.n-modal .n-list-item').evaluateAll(rows => rows.filter(row => getComputedStyle(row).display !== 'none').map(row => row.textContent));
  const before = await labels();
  if (!before.some(text => text.includes('Clash Mi'))) throw new Error('Menu did not load before focus stress');
  for (let i = 0; i < 12; i++) {
    const request = page.waitForResponse(response => response.url().includes('/api/v1/user/info'));
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await (await request).finished();
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    if (JSON.stringify(await labels()) !== JSON.stringify(before)) throw new Error('Menu changed after account refresh ' + i);
  }
  await page.screenshot({path:'output/subscription-state-release-20261110/focus-stable-after.png'});
}
