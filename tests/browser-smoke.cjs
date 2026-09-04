const assert = require('node:assert/strict');
const path = require('node:path');
const { pathToFileURL } = require('node:url');
const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  try {
    for (const viewport of [{ width: 1440, height: 1000 }, { width: 390, height: 844 }]) {
      const page = await browser.newPage({ viewport });
      const errors = [];
      page.on('pageerror', error => errors.push(error.message));
      await page.goto(pathToFileURL(path.join(__dirname, 'rendered-public.html')).href);
      assert.equal(await page.locator('.seo-content-card > h3').count(), 3);
      assert.equal(await page.locator('.seo-content-card > h2').count(), 0);
      await page.locator('#section-2').scrollIntoViewIfNeeded();
      const overflow = await page.evaluate(() => [...document.querySelectorAll('.seo-content-card, .seo-link-list a')]
        .filter(element => element.scrollWidth > element.clientWidth + 1
          || element.getBoundingClientRect().right > innerWidth + 1)
        .map(element => element.className));
      assert.deepEqual(overflow, [], 'Content must fit on desktop and mobile');
      assert.deepEqual(errors, [], 'No JavaScript execution errors');
      await page.screenshot({ path: path.join(__dirname, `rendered-${viewport.width}.png`) });
      console.log(`Browser smoke OK: ${viewport.width}px, H3 hierarchy, links and no overflow`);
      await page.close();
    }
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
