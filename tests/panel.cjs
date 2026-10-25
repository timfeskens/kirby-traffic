const { chromium } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const net = require('node:net');
const assert = require('node:assert/strict');
const { spawn } = require('node:child_process');

(async () => {
  const root = path.resolve(__dirname, '..');
  const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'kirby-traffic-panel-'));
  const socket = net.createServer();
  await new Promise(resolve => socket.listen(0, '127.0.0.1', resolve));
  const port = socket.address().port;
  await new Promise(resolve => socket.close(resolve));
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(root, 'tests/panel-router.php')], {cwd: root, env: {...process.env, TRAFFIC_PANEL_TEST_ROOT: temporary}});
  let logs = '';
  php.stderr.on('data', chunk => { logs += chunk; });
  const browser = await chromium.launch();
  const page = await browser.newPage();
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  try {
    await new Promise((resolve, reject) => {
      php.stderr.on('data', chunk => { if (chunk.toString().includes('Development Server')) resolve(); });
      php.on('error', reject);
      php.on('exit', code => reject(new Error(`PHP exited ${code}: ${logs}`)));
      if (logs.includes('Development Server')) resolve();
    });
    const base = `http://127.0.0.1:${port}`;
    await page.goto(`${base}/panel/traffic`);
    await page.getByRole('heading', {name: 'Verkeer', exact: true}).waitFor();
    await page.locator('.traffic-empty').waitFor();
    assert.equal(fs.existsSync(path.join(temporary, 'site/logs/traffic/views.sqlite')), false, 'Empty report does not create storage');
    await page.request.get(`${base}/fixture/seed`);
    await page.getByRole('button', {name: 'Vernieuwen', exact: true}).click();
    await page.getByText('fictional-autumn', {exact: true}).waitFor();
    assert.equal(await page.evaluate(() => window.pwned), undefined, 'Report text is escaped');
    for (const days of [7, 90, 30]) {
      await page.getByRole('link', {name: `Laatste ${days} dagen`, exact: true}).click();
      await page.waitForURL(`**/panel/traffic/${days}`);
      await page.getByText('fictional-autumn', {exact: true}).waitFor();
      assert.equal(await page.locator('.traffic-period [aria-current="page"]').innerText(), `Laatste ${days} dagen`);
    }
    for (const width of [1440, 390, 375]) {
      await page.setViewportSize({width, height: 1000});
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, `Panel ${width}px has no document overflow`);
      for (const title of ['Bronnen & media', 'UTM-campagnes & landingspagina’s', 'Verwijzende websites', 'Pagina’s', 'Per dag']) {
        const region = page.getByRole('region', {name: title, exact: true});
        await region.scrollIntoViewIfNeeded();
        assert.equal(await region.getAttribute('tabindex'), '0', 'Tables are keyboard-scrollable');
        const bounds = await region.boundingBox();
        assert.ok(bounds.width > 200 && bounds.x + bounds.width <= width + 1, `${title} fits ${width}px`);
        await region.evaluate(el => { el.scrollLeft = el.scrollWidth; });
        assert.equal(await region.locator('tbody td').last().innerText(), '1', 'Rightmost count remains accessible');
        await region.evaluate(el => { el.scrollLeft = 0; });
      }
      if (process.env.TRAFFIC_SCREENSHOTS) {
        fs.mkdirSync(process.env.TRAFFIC_SCREENSHOTS, {recursive: true});
        await page.screenshot({path: path.join(process.env.TRAFFIC_SCREENSHOTS, `traffic-${width}.png`), fullPage: true});
      }
    }
    await page.request.get(`${base}/fixture/corrupt`);
    await page.getByRole('button', {name: 'Vernieuwen', exact: true}).click();
    await page.getByRole('status').filter({hasText: 'Verkeersgegevens zijn tijdelijk niet beschikbaar'}).waitFor();
    assert.deepEqual(errors, [], 'No Panel JavaScript errors');
    console.log('Real Panel passed: empty/populated/error reports, refresh, periods, safe text and accessible tables at 1440/390/375px.');
  } catch (error) {
    console.error(await page.locator('body').innerText());
    console.error(logs);
    throw error;
  } finally {
    await browser.close();
    php.kill();
    await new Promise(resolve => php.exitCode !== null ? resolve() : php.once('exit', resolve));
    fs.rmSync(temporary, {recursive: true, force: true});
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
