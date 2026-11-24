// BLUE-2725 live check, run 2026-11-24 against schwitzers.com (Borlabs Cookie 3.5.1).
// Usage: node bin/live-consent-check.cjs <dir with node_modules/playwright> assets/js/bluerails-openai-ads-measurement.js
// Injects the loader with a fake Pixel ID, clicks the real banner's "Ich akzeptiere alle", lets OpenAI's SDK load,
// and aborts every POST to bzr.openai.com (printing its event types), so nothing reaches a real pixel.
const { chromium } = require(process.argv[2] + '/node_modules/playwright');
const fs = require('fs');
const loader = fs.readFileSync(process.argv[3], 'utf8');

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ locale: 'de-DE' });
  const openai = [];
  await ctx.route(/openai\.com/, (route) => { if (route.request().url().includes('bzrcdn')) { openai.push('ALLOW ' + route.request().url()); return route.continue(); }
    openai.push(route.request().method() + ' ' + route.request().url() + ' ' + (function(){try{return JSON.parse(route.request().postData()).events.map(e=>e.type+(e.data&&e.data.type?'/'+e.data.type:'')+(e.name?':'+e.name:'')+JSON.stringify(e.data&&e.data.contents!==undefined?{contents:e.data.contents}:{})).join(' , ')}catch(x){return (route.request().postData()||'').slice(0,300)}})());
    route.abort();
  });
  await ctx.addInitScript(
    `window.bluerailsOaiAds = ${JSON.stringify({
      pixelId: 'bluerailsTestPixel0000',
      bookingHosts: ['reservation.one.dirs21.de', 'schwitzers.com/hotel-buchen'],
      rcbService: 'openai-ads-measurement',
    })};\n${loader}`,
  );
  const page = await ctx.newPage();
  await page.goto('https://schwitzers.com/', { waitUntil: 'load' });
  await page.waitForTimeout(2500);
  const state = () =>
    page.evaluate(() => ({
      borlabs: !!window.BorlabsCookie,
      marketing: window.BorlabsCookie ? window.BorlabsCookie.Consents.hasConsentForServiceGroup('marketing') : null,
      oaiq: typeof window.oaiq,
      queue: window.oaiq && window.oaiq.q ? window.oaiq.q.map((a) => Array.from(a)) : null,
      sdkTags: Array.from(document.scripts).filter((s) => s.src.includes('bzrcdn')).length,
    }));
  console.log('before consent:', JSON.stringify(await state()), 'openai requests:', openai.length);

  // Click "Alle akzeptieren" in the real banner.
  const btn = page.locator('#BorlabsCookieBox button', { hasText: 'Ich akzeptiere alle' }).first();
  console.log('accept button found:', await btn.count(), await btn.textContent());
  await btn.click({ timeout: 10000 });
  await page.waitForTimeout(2500);
  console.log('after accept-all:', JSON.stringify(await state()));
  console.log('openai requests:', JSON.stringify(openai));

  // A booking click: navigate to the booking page via the menu link, catching the measure call first.
  const before = (await state()).queue.length;
  await page.evaluate(() => {
    const a = Array.from(document.querySelectorAll('a[href*="/hotel-buchen/"]'))[0];
    a.addEventListener('click', (e) => e.preventDefault());
    a.click();
  });
  const after = await state();
  console.log('after booking click, new queue entries:', JSON.stringify(after.queue.slice(before)));
  await page.evaluate(() => {
    const a = Array.from(document.querySelectorAll('a[href*="instagram"]'))[0];
    a.addEventListener('click', (e) => e.preventDefault());
    a.click();
  });
  console.log('after instagram click, queue length:', (await state()).queue.length);
  await page.waitForTimeout(6000); console.log('final openai requests:', JSON.stringify(openai, null, 1)); await browser.close();
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
