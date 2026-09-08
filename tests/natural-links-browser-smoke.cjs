const assert = require('node:assert/strict');
const path = require('node:path');
const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  try {
    for (const mode of ['public', 'preview']) {
      for (const width of [1440, 390, 320]) {
        const page = await browser.newPage({ viewport: { width, height: 1000 } });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/*', route => route.fulfill({
          contentType: 'text/html', path: path.join(__dirname, `rendered-${mode}.html`),
        }));
        await page.goto('https://example.test/page');
        const inline = page.locator('#section-1 .seo-inline-link');
        const cta = page.locator('#section-3 .seo-section-cta');
        assert.equal(await inline.textContent(), 'isolation thermique');
        assert.equal((await cta.textContent()).trim(), 'Decouvrir les travaux electriques');
        assert.equal(await page.locator('.seo-contextual-link').count(), 0);
        assert.equal(await page.locator('.seo-section-actions .seo-section-cta').count(), 2);
        assert.deepEqual(await page.locator('.seo-content-card').evaluateAll(cards => cards.map(card => ({
          overflow: card.scrollWidth > card.clientWidth + 1,
          outsideViewport: card.getBoundingClientRect().right > window.innerWidth + 1,
        }))), Array(3).fill({ overflow: false, outsideViewport: false }));
        await cta.focus();
        assert.equal(await cta.evaluate(el => el === document.activeElement), true);
        await page.locator('.seo-content-grid').screenshot({ path: path.join(__dirname, `rendered-natural-${mode}-${width}.png`) });
        await cta.click();
        assert.equal(new URL(page.url()).pathname, '/electricite');
        await page.goto('https://example.test/page');
        await inline.click();
        assert.equal(new URL(page.url()).pathname, '/isolation');
        assert.deepEqual(errors, []);
        await page.close();
        console.log(`Natural links OK: ${mode}, ${width}px, inline link, CTA, keyboard focus, no card overflow`);
      }
    }
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
