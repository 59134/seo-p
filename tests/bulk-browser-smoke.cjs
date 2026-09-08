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
        const fixtures = { '/bulk': 'rendered-bulk.html', '/empty': 'rendered-bulk-empty.html', '/advisories': 'rendered-bulk-advisories.html', '/review': 'rendered-bulk-review.html', '/review-only': 'rendered-bulk-review-only.html' };
        if (fixtures[url.pathname]) {
          return route.fulfill({ contentType: 'text/html', path: path.join(__dirname, fixtures[url.pathname]) });
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
      let lastConfirmation = '';
      page.on('dialog', async dialog => { confirmations++; lastConfirmation = dialog.message(); await dialog.dismiss(); });
      await submit.click();
      assert.equal(confirmations, 1);
      assert.equal(new URL(page.url()).pathname, '/bulk');
      const formAction = await page.locator('#seo-bulk-publish-form').getAttribute('action');
      assert.equal(new URL(formAction).searchParams.get('routeName'), 'admin_seo_page_bulk_publish');
      await page.screenshot({ path: path.join(__dirname, `rendered-bulk-${viewport.width}.png`), fullPage: true });
      await page.goto('https://example.test/empty');
      assert.equal(await page.locator('#seo-bulk-publish-form').count(), 0);
      assert.equal(await page.locator('[role="alert"]').count(), 1);
      await page.goto('https://example.test/advisories');
      assert.equal(await page.locator('.seo-page-checkbox:disabled').count(), 0);
      assert.equal(await page.locator('.badge.text-bg-warning').innerText(), 'Éligible, à vérifier');
      assert.equal(await page.locator('table').innerText().then(text => text.includes('90/100')), true);
      await page.locator('details summary').click();
      assert.equal(await page.locator('details[open] li').isVisible(), true);
      assert.match(await page.locator('details li').innerText(), /parc de chauffage/);
      await page.screenshot({ path: path.join(__dirname, `rendered-bulk-advisories-${viewport.width}.png`), fullPage: true });
      await page.goto('https://example.test/review');
      const reviewed = page.locator('input[name="reviewed_page_ids[]"]');
      assert.equal(await reviewed.isEnabled(), true);
      assert.equal(await reviewed.isChecked(), false);
      assert.equal(await page.locator('#seo-selected-count').innerText(), '1');
      await selectAll.uncheck();
      await selectAll.check();
      assert.equal(await reviewed.isChecked(), false);
      await reviewed.check();
      assert.equal(await page.locator('#seo-selected-count').innerText(), '2');
      assert.deepEqual(await page.locator('form').evaluate(form => new FormData(form).getAll('reviewed_page_ids[]')), ['2']);
      await submit.click();
      assert.match(lastConfirmation, /relu les 1 page/);
      assert.equal(new URL(page.url()).pathname, '/review');
      await selectAll.uncheck();
      assert.equal(await reviewed.isChecked(), true);
      assert.equal(await page.locator('#seo-selected-count').innerText(), '1');
      await reviewed.uncheck();
      assert.equal(await submit.isDisabled(), true);
      await page.screenshot({ path: path.join(__dirname, `rendered-bulk-review-${viewport.width}.png`), fullPage: true });
      await page.goto('https://example.test/review-only');
      assert.equal(await selectAll.isDisabled(), true);
      assert.equal(await submit.isDisabled(), true);
      await reviewed.check();
      assert.equal(await submit.isEnabled(), true);
      assert.equal(await page.locator('#seo-selected-count').innerText(), '1');

      const individualPosts = [];
      await page.route('**/individual-publish', async route => {
        individualPosts.push(route.request().postData());
        await route.fulfill({ contentType: 'text/html', body: '<p>Published test fixture</p>' });
      });
      await page.setContent('<a href="/individual-publish" data-seo-post-action="true" data-csrf-token="test-token" data-confirm="Confirmer la relecture ?" data-seo-review-confirmed="true">Publier</a>');
      await page.addScriptTag({ path: path.join(__dirname, '../files/public/js/seo-admin-actions.js') });
      await page.getByRole('link', { name: 'Publier' }).click();
      assert.deepEqual(individualPosts, []);
      page.removeAllListeners('dialog');
      page.on('dialog', async dialog => dialog.accept());
      await page.getByRole('link', { name: 'Publier' }).click();
      await page.waitForURL('https://example.test/individual-publish');
      assert.equal(individualPosts.length, 1);
      const post = new URLSearchParams(individualPosts[0]);
      assert.equal(post.get('_token'), 'test-token');
      assert.equal(post.get('editorial_review_confirmed'), '1');
      assert.deepEqual(errors, []);
      await page.close();
      console.log(`Publication browser OK: ${viewport.width}px, bulk/manual selection, individual confirmation and empty state`);
    }
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
