// Browser writes only to the nonce resident fixture, never an installed world.
import { chromium, firefox, expect } from '@playwright/test';
import fs from 'node:fs';
import assert from 'node:assert/strict';
const state=JSON.parse(fs.readFileSync('storage/framework/testing/resident-browser.json','utf8'));
assert.match(state.database,/^resident_browser_[a-f0-9]{16}$/);
for (const engine of ['chromium','firefox']) {
    const browser=await (engine==='chromium' ? chromium.launch({channel:'msedge',headless:true}) : firefox.launch({headless:true}));
    try {
        const context=await browser.newContext({baseURL:'http://localhost:8099',viewport:{width:412,height:915},
            ...(engine==='chromium'?{isMobile:true,hasTouch:true}:{})});
        await context.route('http://localhost:5173/**',async route=>{
            try { const response=await route.fetch({timeout:90000});
                await route.fulfill({response,headers:{...response.headers(),'access-control-allow-origin':'http://localhost:8099'}});
            } catch { /* Page teardown. */ }
        });
        let githubURL=null;
        await context.route('https://github.com/**',async route=>{
            githubURL=route.request().url();
            await route.fulfill({status:200,contentType:'text/html',body:'<h1>Intercepted issue composer — nothing submitted</h1>'});
        });
        const page=await context.newPage();page.setDefaultTimeout(60000);page.setDefaultNavigationTimeout(90000);
        const errors=[];page.on('pageerror',e=>errors.push(e.message));
        await page.goto('/support/report?ref=legislature%2Fdistricts');
        const subject=page.getByLabel('A one-line summary', {exact:false});
        const body=page.getByLabel('What happened?',{exact:false});
        if(engine==='chromium') await subject.tap();else await subject.click();
        await expect(subject).toBeFocused();
        await page.keyboard.type('Phone keyboard focus');
        await subject.press('Tab');await expect(body).toBeFocused();
        await page.keyboard.type('I can write this report while signed out.');
        await expect(body).toHaveValue('I can write this report while signed out.');
        await page.getByLabel('What kind of report', {exact:false}).selectOption('abuse');
        await expect(page.getByRole('button',{name:'Open issue on GitHub',exact:true})).toHaveCount(0);
        await page.getByLabel('What kind of report', {exact:false}).selectOption('bug');
        await page.getByRole('button',{name:'Sign in to file privately',exact:true}).click();
        await page.waitForURL(url=>url.pathname==='/login');
        assert.ok(!page.url().includes('keyboard'));
        await page.locator('input[name=email]').fill('new@browser.test');
        await page.locator('input[name=password]').fill('fixture-password-only');
        await page.locator('button[type=submit]').click();
        await page.waitForURL(url=>url.pathname==='/support/report');
        await expect(subject).toHaveValue('Phone keyboard focus');
        await expect(body).toHaveValue('I can write this report while signed out.');
        await page.getByRole('button',{name:'File privately with this instance',exact:true}).click();
        await expect(page.getByRole('status').filter({hasText:'Report filed'})).toBeVisible();
        await expect(subject).toHaveValue('');await expect(body).toHaveValue('');
        await subject.fill('Map & keyboard');await body.fill('Exact report text.\nSecond line.');
        await page.screenshot({path:`storage/framework/testing/report-${engine}.png`,fullPage:true});
        await page.getByRole('button',{name:'Open issue on GitHub',exact:true}).click();
        await page.waitForURL(url=>url.hostname==='github.com');
        const target=new URL(githubURL);
        assert.equal(target.pathname,'/CosmopolitanCoalition/fair-constitution-app/issues/new');
        assert.equal(target.searchParams.get('title'),'Map & keyboard');
        assert.ok(target.searchParams.get('body').startsWith('Exact report text.\nSecond line.'));
        assert.ok(!target.searchParams.get('body').includes('@browser.test'));
        assert.deepEqual(errors,[]);
        console.log(`PASS ${engine}: touch/keyboard focus, Tab access, guest draft through sign-in, private filing and intercepted GitHub composer`);
    } finally {await browser.close();}
}
