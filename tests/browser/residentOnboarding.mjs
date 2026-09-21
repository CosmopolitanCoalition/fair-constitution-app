// Run only against tests/browser/harness/residentFixture.php, served on 8099.
// Firefox tracking protection stays enabled; never use a real user's identity.
import { firefox, expect } from '@playwright/test';
import fs from 'node:fs';
import assert from 'node:assert/strict';

const state = JSON.parse(fs.readFileSync('storage/framework/testing/resident-browser.json', 'utf8'));
assert.match(state.database, /^resident_browser_[a-f0-9]{16}$/);
const browser = await firefox.launch({ headless: true, firefoxUserPrefs: {
    'privacy.trackingprotection.enabled': true,
    'privacy.trackingprotection.socialtracking.enabled': true,
    'network.cookie.cookieBehavior': 5,
} });
try {
    for (const person of ['new', 'confirmed']) {
        const context = await browser.newContext({ baseURL: 'http://localhost:8099', viewport: { width: 1280, height: 960 } });
        // Reuse the existing dev asset server for this isolated app origin.
        await context.route('http://localhost:5173/**', async route => {
            try {
                const response = await route.fetch({ timeout: 90000 });
                await route.fulfill({ response, headers: { ...response.headers(), 'access-control-allow-origin': 'http://localhost:8099' } });
            } catch (error) { if (!page.isClosed()) console.error('Asset request failed:', error.message.split('\n')[0]); }
        });
        const page = await context.newPage();
        page.setDefaultTimeout(60000);
        page.setDefaultNavigationTimeout(90000);
        page.on('pageerror', error => console.error('Browser error:', error.message));
        await page.goto('/login');
        const oldToken = await page.locator('meta[name="csrf-token"]').getAttribute('content');
        await page.locator('input[name=email]').fill(`${person}@browser.test`);
        await page.locator('input[name=password]').fill('fixture-password-only');
        await page.locator('button[type=submit]').click();
        await page.waitForURL(url => url.pathname !== '/login', { timeout: 60000 });
        if (person === 'new') {
            await page.goto('/civic/residency');
            // Reproduce the old document token retained across an Inertia login.
            await page.evaluate(token => document.querySelector('meta[name="csrf-token"]').content = token, oldToken);
            const response = page.waitForResponse(r => r.url().includes('/civic/residency/locate') && r.request().method() === 'POST');
            await page.getByRole('region', { name: 'Map — click where you live', exact: true }).click();
            const located = await response;
            assert.equal(located.status(), 200, await located.text());
            assert.equal((await located.json()).found, true);
            await page.locator('input[name=ping_consent]').check();
            await page.getByRole('button', { name: 'Confirm my home', exact: true }).click();
            await expect(page.getByText('You live in Browser Test Earth', { exact: false })).toBeVisible({ timeout: 30000 });
        }
        await page.goto('/economy/wallet');
        if (person === 'confirmed') await page.getByRole('button', { name: 'Open my wallet', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'Balance', exact: true })).toBeVisible({ timeout: 30000 });
        await page.getByLabel('What is it', { exact: false }).fill(`${person} test bowl`);
        await page.getByRole('button', { name: 'Register it', exact: true }).click();
        await expect(page.getByText(`${person} test bowl`, { exact: true })).toBeVisible({ timeout: 30000 });
        await page.screenshot({ path: `storage/framework/testing/wallet-${person}.png`, fullPage: true });
        console.log(`PASS Firefox ETP: ${person} resident → wallet → registered item`);
        await context.close();
    }
} finally { await browser.close(); }
