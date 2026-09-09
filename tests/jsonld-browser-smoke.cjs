const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  try {
    const page = await browser.newPage();
    for (const file of ['rendered-structured-data.html', 'rendered-cms-structured-data.html']) {
      await page.setContent(fs.readFileSync(path.join(__dirname, file), 'utf8'));
      const result = await page.evaluate(() => ({
        graphs: [...document.querySelectorAll('script[type="application/ld+json"]')].map(script => JSON.parse(script.textContent)['@graph']),
        scripts: document.scripts.length,
        leaked: window.jsonLdLeak !== undefined,
      }));
      assert.equal(result.scripts, 1);
      assert.equal(result.leaked, false);
      assert.deepEqual(result.graphs[0][1].openingHours, ['Mo-Fr 09:00-18:00']);
      assert.equal(result.graphs[0][2]['@type'], 'FAQPage');
      console.log(`${file}: browser JSON.parse OK, script boundary intact, hours normalized`);
    }
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
