const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  const publicRoot = path.join(__dirname, 'vendor/easycorp/easyadmin-bundle/src/Resources/public');
  const manifest = JSON.parse(fs.readFileSync(path.join(publicRoot, 'manifest.json'), 'utf8'));
  try {
    for (const viewport of [{ width: 1440, height: 1000 }, { width: 390, height: 844 }]) {
      const page = await browser.newPage({ viewport });
      const errors = [];
      page.on('pageerror', error => errors.push(error.message));
      await page.route('**/*', async route => {
        const url = new URL(route.request().url());
        if (url.pathname === '/bulk' || url.pathname === '/empty') {
          return route.fulfill({ contentType: 'text/html', path: path.join(__dirname,
            url.pathname === '/bulk' ? 'rendered-bulk.html' : 'rendered-bulk-empty.html') });
        }
        const key = url.pathname.replace(/^\/assets\//, '');
        const relative = (manifest[key] || key).replace(/^\/?bundles\/easyadmin\//, '');
        const file = path.resolve(publicRoot, relative);
        if (file.startsWith(publicRoot + path.sep) && fs.existsSync(file) && fs.statSync(file).isFile()) {
          return route.fulfill({ path: file });
        }
        return route.fulfill({ status: 404, body: '' });
      });
      await page.goto('https://example.test/bulk');
      const submit = page.locator('#seo-bulk-publish-submit');
      const selectAll = page.locator('#seo-select-all-eligible');
      assert.equal(await page.locator('.seo-page-checkbox:disabled').count(), 1);
      assert.equal(await page.locator('#seo-selected-count').innerText(), '1');
      await selectAll.uncheck();
      assert.equal(await submit.isDisabled(), true);
      await selectAll.check();
      assert.equal(await submit.isEnabled(), true);
      let confirmations = 0;
      page.on('dialog', async dialog => { confirmations++; await dialog.dismiss(); });
      await submit.click();
      assert.equal(confirmations, 1);
      assert.equal(new URL(page.url()).pathname, '/bulk');
      const formAction = await page.locator('#seo-bulk-publish-form').getAttribute('action');
      assert.equal(new URL(formAction).searchParams.get('routeName'), 'admin_seo_page_bulk_publish');
      await page.screenshot({ path: path.join(__dirname, `rendered-bulk-${viewport.width}.png`), fullPage: true });
      await page.goto('https://example.test/empty');
      assert.equal(await page.locator('#seo-bulk-publish-form').count(), 0);
      assert.equal(await page.locator('[role="alert"]').count(), 1);
      assert.deepEqual(errors, []);
      await page.close();
      console.log(`Bulk publish browser OK: ${viewport.width}px, selection, confirmation and empty state`);
    }
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
