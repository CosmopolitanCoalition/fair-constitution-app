// Only the nonce resident browser fixture; never register a device on a real account.
import { chromium, expect } from '@playwright/test';
import fs from 'node:fs';
import assert from 'node:assert/strict';
const state = JSON.parse(fs.readFileSync('storage/framework/testing/resident-browser.json', 'utf8'));
assert.match(state.database, /^resident_browser_[a-f0-9]{16}$/);
const profile = fs.mkdtempSync('storage/framework/testing/installed-profile-');
const browser = await chromium.launchPersistentContext(profile, { channel: 'msedge', headless: true,
    baseURL: 'http://localhost:8099', viewport: { width: 412, height: 915 },
    permissions: ['geolocation', 'notifications'], geolocation: { latitude: 50.0614, longitude: 19.9383 } });
try {
    const context = browser;
    await context.route('http://localhost:5173/**', async route => {
        try {
            const response = await route.fetch({ timeout: 90000 });
            await route.fulfill({ response, headers: { ...response.headers(), 'access-control-allow-origin': 'http://localhost:8099' } });
        } catch { /* Browser teardown must not dump fixture session headers. */ }
    });
    const page = await context.newPage(); page.setDefaultTimeout(60000); page.setDefaultNavigationTimeout(90000);
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    await page.goto('/system/app');
    await expect(page.getByRole('heading', { name: 'Install the app', exact: true })).toBeVisible();
    await page.evaluate(() => navigator.serviceWorker.ready);
    const cdp = await context.newCDPSession(page);
    await cdp.send('Page.enable');
    const manifest = await cdp.send('Page.getAppManifest');
    assert.equal(manifest.errors.length, 0, JSON.stringify(manifest.errors));
    const parsed = JSON.parse(manifest.data); assert.equal(parsed.display, 'standalone');
    for (const size of [192, 512]) {
        const icon = await page.request.get(`/app-icons/icon-${size}.png`);
        assert.equal(icon.status(), 200); assert.match(icon.headers()['content-type'], /image\/png/);
    }
    const install = await cdp.send('Page.getInstallabilityErrors');
    assert.deepEqual(install.installabilityErrors, [], JSON.stringify(install));
    console.log('PASS Chromium manifest, icons, service worker and browser installability checks');
    await page.goto('/login');
    await page.locator('input[name=email]').fill('new@browser.test');
    await page.locator('input[name=password]').fill('fixture-password-only');
    await page.locator('button[type=submit]').click();
    await page.waitForURL(url => url.pathname !== '/login');
    await page.goto('/system/app');
    await page.getByRole('button', { name: 'Allow / check location', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Location works.' })).toBeVisible();
    await page.getByRole('button', { name: 'Stop using location in this app' }).click();
    await page.goto('/civic/residency');
    await page.getByRole('button', { name: /Use my current location/i }).click();
    await expect(page.getByRole('alert').filter({ hasText: 'Location requests are turned off' })).toBeVisible();
    await page.goto('/system/app');
    await page.getByRole('button', { name: 'Allow / check location', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Location works.' })).toBeVisible();
    await page.goto('/civic/residency');
    const located = page.waitForResponse(r => r.url().includes('/civic/residency/locate') && r.request().method() === 'POST');
    await page.getByRole('button', { name: /Use my current location/i }).click();
    const response = await located; assert.equal(response.status(), 200); assert.equal((await response.json()).found, true);
    console.log('PASS real geolocation grant, app opt-out and residency boundary lookup');
    await cdp.send('Browser.setPermission', { permission: { name: 'geolocation' }, setting: 'denied', origin: 'http://localhost:8099' });
    await page.getByRole('button', { name: /Use my current location/i }).click();
    await expect(page.getByRole('alert').filter({ hasText: 'Location is blocked' })).toBeVisible();
    console.log('PASS blocked location gives manual map alternative');
    await page.goto('/system/app');
    // Simulate only the external browser push registration. All ownership, preference,
    // CSRF and outbox requests below go through real Laravel + migrated PostgreSQL.
    await page.evaluate(async () => {
        const publicKey = JSON.parse(document.getElementById('app').dataset.page).props.pushKey;
        const object = { endpoint: 'https://fcm.googleapis.com/fcm/send/disposable-browser-test', keys: { p256dh: publicKey, auth: 'AAAAAAAAAAAAAAAAAAAAAA' } };
        let current = null;
        Object.defineProperty(Notification, 'requestPermission', { value: async () => 'granted' });
        Object.defineProperty(PushManager.prototype, 'subscribe', { value: async () => current = {
            ...object, toJSON: () => object, unsubscribe: async () => { current = null; return true; },
        } });
        Object.defineProperty(PushManager.prototype, 'getSubscription', { value: async () => current });
    });
    await page.getByRole('button', { name: 'Enable notifications', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Notifications are enabled' })).toBeVisible();
    await page.getByRole('checkbox', { name: 'Private messages', exact: true }).uncheck();
    await page.getByRole('button', { name: 'Save notification choices', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Notification choices saved' })).toBeVisible();
    await page.getByRole('button', { name: 'Send me a test notification', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Test queued' })).toBeVisible();
    await page.screenshot({ path: 'storage/framework/testing/app-settings-mobile.png', fullPage: true });
    await page.getByRole('button', { name: 'Disable on this device', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'disabled' })).toBeVisible();
    console.log('PASS real subscription API, preferences, test outbox and disable (browser push transport simulated)');
    const cacheContents = await page.evaluate(async () => {
        const result = [];
        for (const key of await caches.keys()) for (const request of await (await caches.open(key)).keys()) result.push(new URL(request.url).pathname);
        return result;
    });
    assert.deepEqual(cacheContents, ['/app-offline.html']);
    await context.setOffline(true);
    await page.goto('/civic/residency');
    await expect(page.getByRole('heading', { name: 'Connection needed' })).toBeVisible();
    const postFailed = await page.evaluate(async () => { try { await fetch('/civic/residency/declare', { method: 'POST' }); return false; } catch { return true; } });
    assert.equal(postFailed, true);
    assert.deepEqual(errors, []);
    console.log('PASS offline fallback without personal-page caching or queued mutations');
    await context.close();
} finally { await browser.close(); }
