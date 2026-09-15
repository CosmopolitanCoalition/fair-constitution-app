// @ts-check
// signin.mjs — Playwright helper that logs the throwaway sweep resident in
// through the REAL /login form and saves a storageState the accessibility sweep
// reuses, so the sweep runs as a signed-in resident.
//
// Credentials come from the environment, never the repo:
//   CGA_SWEEP_EMAIL      (e.g. a11y-sweep@example.test, from a11y:sweep-account)
//   CGA_SWEEP_PASSWORD   (printed once by a11y:sweep-account)
//   CGA_BROWSER_BASE_URL (default http://localhost:8080)
//   CGA_BROWSER_CHANNEL  (e.g. msedge on the host; blank uses the bundled shell)
//   CGA_A11Y_STATE       (output path; default storage/logs/a11y/state.json)
//
// Run directly:
//   node tests/browser/signin.mjs
// or import signIn() from a runner. The saved state file is gitignored.
import { chromium } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const DEFAULT_STATE = 'storage/logs/a11y/state.json';

/**
 * Log in through /login in a fresh browser context and save its storageState.
 * Returns the absolute path of the saved state file.
 *
 * @param {{email:string,password:string,base?:string,statePath?:string,channel?:string}} opts
 */
export async function signIn(opts) {
    const email = opts.email;
    const password = opts.password;
    if (!email || !password) {
        throw new Error('signIn needs email and password (CGA_SWEEP_EMAIL / CGA_SWEEP_PASSWORD).');
    }
    const base = opts.base || process.env.CGA_BROWSER_BASE_URL || 'http://localhost:8080';
    const statePath = path.resolve(opts.statePath || process.env.CGA_A11Y_STATE || DEFAULT_STATE);
    const channel = opts.channel || process.env.CGA_BROWSER_CHANNEL || undefined;

    fs.mkdirSync(path.dirname(statePath), { recursive: true });

    const browser = await chromium.launch({
        headless: true,
        channel,
        args: ['--disable-dev-shm-usage'],
    });
    try {
        const context = await browser.newContext({ baseURL: base });
        const page = await context.newPage();

        await page.goto('/login', { waitUntil: 'domcontentloaded' });
        // The Vue form paints name=email / name=password inputs and a submit button.
        await page.waitForSelector('input[name="email"]', { timeout: 30_000 });
        await page.fill('input[name="email"]', email);
        await page.fill('input[name="password"]', password);

        await Promise.all([
            page.waitForURL((url) => !/\/login\b/.test(url.pathname), { timeout: 30_000 }).catch(() => {}),
            page.click('button[type="submit"]'),
        ]);

        // Confirm the session took: a signed-in shell no longer routes to /login.
        const finalPath = new URL(page.url()).pathname;
        const signedIn = !/\/login\b/.test(finalPath);
        if (!signedIn) {
            throw new Error(`login did not leave /login (still at ${finalPath}); check credentials or that the app booted`);
        }

        await context.storageState({ path: statePath });
        return statePath;
    } finally {
        await browser.close();
    }
}

// Run directly.
if (process.argv[1] && fileURLToPath(import.meta.url) === path.resolve(process.argv[1])) {
    signIn({ email: process.env.CGA_SWEEP_EMAIL || '', password: process.env.CGA_SWEEP_PASSWORD || '' })
        .then((p) => {
            console.log('storageState saved: ' + p);
        })
        .catch((e) => {
            console.error(String(e && e.message ? e.message : e));
            process.exit(1);
        });
}
