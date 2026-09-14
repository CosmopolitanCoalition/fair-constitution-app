// @ts-check
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import fs from 'node:fs';
import path from 'node:path';
import { deriveGuestPages, PIN_PAGES, PIN_NONPAGE, AUTH_WALLS } from './roster/roster.mjs';

/**
 * L2 · accessibility (pass 2, browser). Register criterion:
 *   keyboard/focus, screen-reader semantics, narrow layouts, contrast,
 *   media alternatives and language/audio fallbacks pass internal
 *   inspection/automation. Demonstrated failures become repairs.
 *
 * Sweep of every guest-readable route of the LIVE app (READ ONLY): axe
 * (WCAG 2.1 AA, color-contrast included) at desktop and at 375px, no
 * horizontal overflow at 375px, a Tab-order walk asserting a visible focus
 * indicator and an accessible name on every focusable element, the Learn
 * drawer and language switcher keyboard-operable, html lang/dir following the
 * selected locale, and the video player exposing captions/audio alternatives.
 *
 * Route roster: DERIVED at test time from the authoritative route table
 * (tests/browser/roster/route-list.json, a normalized machine dump of
 * `php artisan route:list --json`, the successor of routes/web.php with every
 * middleware group resolved) by deriveGuestPages() in roster.mjs, filtered to
 * param-free GET pages that carry no auth-class middleware. The derived set is
 * PINNED (PIN_PAGES / PIN_NONPAGE) and asserted below, so any added, removed,
 * or re-guarded route breaks the pin and forces a deliberate update. Non-HTML
 * endpoints (/api, /.well-known, /oauth, /up) are recorded, not scanned.
 * config/cga/surfaces.php is a per-surface metadata registry keyed by surface
 * id, not a URL list, so the URL enumeration comes from the route table, not it.
 * A route that redirects to /login (or /register, /operator/login) is recorded
 * NOT ESTABLISHED with its name and not scanned, per the register.
 */

// Guest-page roster derived from the authoritative route table. [uri, name].
const DERIVED = deriveGuestPages();
const CANDIDATES = DERIVED.pages.map((p) => [p.uri, p.name]);

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

const RESULT_DIR = process.env.CGA_A11Y_OUT || 'test-results/a11y';
fs.mkdirSync(RESULT_DIR, { recursive: true });

function writeResult(name, obj) {
    const safe = name.replace(/[^a-z0-9._-]+/gi, '_');
    fs.writeFileSync(path.join(RESULT_DIR, safe + '.json'), JSON.stringify(obj, null, 2));
    // Machine-readable single-line marker for stdout parsing.
    console.log('A11Y_RESULT ' + JSON.stringify(obj));
}

/**
 * Box E runs the Vite DEV server: the blade shell loads its ES modules from
 * http://localhost:5173 (public/hot), and Vite's dev CORS allowlist is
 * localhost:8080 / localhost:5173 only — so an http://nginx page origin is
 * refused the modules and the Vue app never boots ("Loading…" only). This is
 * a DEV-asset-origin mismatch, not an app defect. The harness re-adds the
 * Access-Control-Allow-Origin header to the dev-asset responses the test
 * browser fetches (localhost:5173 is reachable from inside fc_vite). It
 * changes nothing on the live app — only how this browser sees the dev
 * server's response headers. The BUILT bundle (production) serves same-origin
 * and needs none of this.
 */
const DEV_ASSET_RE = /:\/\/localhost:5173\//;
async function bootAssets(page) {
    const origin = () => {
        try {
            return new URL(page.url()).origin;
        } catch {
            return 'http://nginx';
        }
    };
    await page.route(DEV_ASSET_RE, async (route) => {
        let resp;
        try {
            resp = await route.fetch();
        } catch {
            return route.abort();
        }
        const headers = { ...resp.headers() };
        headers['access-control-allow-origin'] = origin() || 'http://nginx';
        headers['access-control-allow-credentials'] = 'true';
        try {
            await route.fulfill({ response: resp, headers });
        } catch {
            await route.abort();
        }
    });
}

/**
 * Establish the page with EVIDENCE. Returns { ready, via, reason }.
 *
 * A route counts as established only when the page reaches a deterministic ready
 * marker OR network idle (register criterion). The marker is exact: app.js
 * removes #initial-page-loading after the Vue app mounts, and on a boot failure
 * leaves that loader in place with role="alert" and the start-failed text. So:
 *   ready  = loader removed AND a real <main>/<h1>/<form> painted with text
 *   failed = loader still present with role="alert"
 * Network idle is unreliable under the Vite dev server (its HMR socket stays
 * open), so the ready marker is primary; network idle is accepted as the
 * alternative only when content actually painted. When neither holds the reason
 * (still "Loading…", explicit boot failure, no content, or timeout) is recorded
 * and the caller marks the route NOT ESTABLISHED — never a silent pass.
 */
async function settle(page) {
    await page.waitForLoadState('domcontentloaded').catch(() => {});

    const probe = () => {
        const loader = document.getElementById('initial-page-loading');
        const el = document.querySelector('main, [role="main"], form, h1');
        const painted = !!el && (el.textContent || '').trim().length > 0 && !(loader && loader.contains(el));
        if (loader && loader.getAttribute('role') === 'alert') return 'boot-failed';
        return !loader && painted ? 'ready-marker' : false;
    };

    let ready = false;
    let via = null;
    let reason = '';

    try {
        const handle = await page.waitForFunction(probe, { timeout: 30_000 });
        const state = await handle.jsonValue();
        if (state === 'ready-marker') {
            ready = true;
            via = 'ready-marker';
        } else if (state === 'boot-failed') {
            reason = 'boot failed — #initial-page-loading kept role="alert" (start_failed); Vue app did not mount';
        }
    } catch {
        // waitForFunction timed out — reason captured below.
    }

    // Network idle as the alternative acceptance, but only if content painted.
    if (!ready) {
        const idle = await page.waitForLoadState('networkidle', { timeout: 4_000 }).then(() => true).catch(() => false);
        if (idle) {
            const painted = await page
                .evaluate(() => {
                    const loader = document.getElementById('initial-page-loading');
                    const el = document.querySelector('main, [role="main"], form, h1');
                    return !!el && (el.textContent || '').trim().length > 0 && !(loader && loader.getAttribute('role') === 'alert');
                })
                .catch(() => false);
            if (painted) {
                ready = true;
                via = 'networkidle';
                reason = '';
            }
        }
    }

    if (!ready && !reason) {
        reason = await page
            .evaluate(() => {
                const loader = document.getElementById('initial-page-loading');
                const el = document.querySelector('main, [role="main"], form, h1');
                const loaderState = loader
                    ? `present(role=${loader.getAttribute('role')},text="${(loader.textContent || '').trim().slice(0, 40)}")`
                    : 'removed';
                return `no ready marker after 30s; loader=${loaderState}; content=${el ? el.tagName.toLowerCase() : 'none'}`;
            })
            .catch((e) => 'ready probe error: ' + e.message);
    }

    return { ready, via, reason };
}

function summarizeViolations(violations) {
    const out = [];
    for (const v of violations) {
        for (const node of v.nodes) {
            out.push({
                rule: v.id,
                impact: v.impact || node.impact || 'unknown',
                help: v.help,
                selector: Array.isArray(node.target) ? node.target.join(' ') : String(node.target),
                summary: (node.failureSummary || '').replace(/\s+/g, ' ').trim().slice(0, 400),
            });
        }
    }
    return out;
}

async function runAxe(page) {
    const builder = new AxeBuilder({ page }).withTags(AXE_TAGS);
    const res = await builder.analyze();
    return summarizeViolations(res.violations);
}

// In-page accessible-name approximation + focus-indicator probe.
const ACC_NAME_FN = `
(el) => {
    if (!el) return '';
    const t = (s) => (s || '').replace(/\\s+/g, ' ').trim();
    const aria = el.getAttribute && el.getAttribute('aria-label');
    if (t(aria)) return t(aria);
    const lb = el.getAttribute && el.getAttribute('aria-labelledby');
    if (lb) {
        const txt = lb.split(/\\s+/).map((id) => (document.getElementById(id)?.textContent) || '').join(' ');
        if (t(txt)) return t(txt);
    }
    if (el.labels && el.labels.length) {
        const txt = Array.from(el.labels).map((l) => l.textContent).join(' ');
        if (t(txt)) return t(txt);
    }
    const title = el.getAttribute && el.getAttribute('title');
    const alt = el.getAttribute && el.getAttribute('alt');
    const inner = t(el.innerText || el.textContent);
    const val = (el.tagName === 'INPUT' || el.tagName === 'SELECT' || el.tagName === 'TEXTAREA') ? t(el.value) : '';
    const ph = el.getAttribute && el.getAttribute('placeholder');
    // image button
    if (el.tagName === 'IMG') return t(alt);
    return t(inner) || t(alt) || t(title) || val || t(ph) || '';
}`;

async function activeElementInfo(page) {
    return page.evaluate(
        ({ accNameSrc }) => {
            // eslint-disable-next-line no-eval
            const accName = eval(accNameSrc);
            const el = document.activeElement;
            if (!el || el === document.body || el === document.documentElement) {
                return { tag: el ? el.tagName : null, atBody: true };
            }
            const cs = getComputedStyle(el);
            const rect = el.getBoundingClientRect();
            const desc =
                el.tagName.toLowerCase() +
                (el.id ? '#' + el.id : '') +
                (el.className && typeof el.className === 'string'
                    ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.')
                    : '');
            const role = el.getAttribute('role') || '';
            // A focus indicator is present if a non-transparent outline OR any
            // box-shadow is painted (the design system uses a box-shadow ring
            // and a transparent outline for forced-colors). Elements that are
            // not visible are skipped by the caller.
            const outlineVisible =
                cs.outlineStyle !== 'none' &&
                parseFloat(cs.outlineWidth) > 0 &&
                cs.outlineColor !== 'transparent' &&
                cs.outlineColor !== 'rgba(0, 0, 0, 0)';
            const shadowVisible = cs.boxShadow && cs.boxShadow !== 'none';
            return {
                atBody: false,
                tag: el.tagName,
                desc,
                role,
                name: accName(el),
                visible: rect.width > 0 && rect.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none',
                focusIndicator: !!(outlineVisible || shadowVisible),
                outline: cs.outline,
                boxShadow: cs.boxShadow && cs.boxShadow.slice(0, 80),
            };
        },
        { accNameSrc: ACC_NAME_FN },
    );
}

/** Walk Tab order; return per-element focus-indicator + accessible-name gaps. */
async function walkTabOrder(page, maxTabs = 45) {
    const seen = new Set();
    const nameGaps = [];
    const focusGaps = [];
    let visited = 0;
    // Start from the top of the document.
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.locator('body').focus().catch(() => {});
    for (let i = 0; i < maxTabs; i++) {
        await page.keyboard.press('Tab');
        const info = await activeElementInfo(page).catch(() => null);
        if (!info || info.atBody) continue;
        const key = info.desc + '|' + (info.name || '');
        if (seen.has(key)) {
            // Likely cycled through the whole page; stop once we revisit.
            if (visited > 3) break;
        }
        seen.add(key);
        if (!info.visible) continue;
        visited++;
        if (!info.focusIndicator) {
            focusGaps.push({ desc: info.desc, role: info.role, name: info.name, outline: info.outline, boxShadow: info.boxShadow });
        }
        if (!info.name) {
            nameGaps.push({ desc: info.desc, role: info.role });
        }
    }
    return { focusableVisited: visited, focusGaps, nameGaps };
}

async function narrowOverflow(page) {
    return page.evaluate(() => {
        const de = document.documentElement;
        const scrollW = Math.max(de.scrollWidth, document.body ? document.body.scrollWidth : 0);
        const innerW = window.innerWidth;
        const overflow = scrollW - innerW;
        let widest = [];
        if (overflow > 2) {
            const all = Array.from(document.querySelectorAll('body *'));
            for (const el of all) {
                const r = el.getBoundingClientRect();
                if (r.right > innerW + 2 && r.width > 0) {
                    widest.push({
                        desc:
                            el.tagName.toLowerCase() +
                            (el.id ? '#' + el.id : '') +
                            (typeof el.className === 'string' && el.className.trim()
                                ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.')
                                : ''),
                        right: Math.round(r.right),
                        width: Math.round(r.width),
                    });
                }
            }
            widest = widest.sort((a, b) => b.right - a.right).slice(0, 6);
        }
        return { scrollW, innerW, overflow, widest };
    });
}

test.beforeEach(async ({ page }) => {
    await bootAssets(page);
});

// ─────────────────────────────────────────────────────────────────────────
// Roster is derived from the route table and matches the pin (assert-the-pin).
// A guest route added, removed, or re-guarded in routes/web.php flips the
// derivation and fails here until the pin is updated deliberately.
// ─────────────────────────────────────────────────────────────────────────
test('guest-page roster derives from the route table and matches the pin', () => {
    const pageUris = DERIVED.pages.map((p) => p.uri);
    const nonPageUris = DERIVED.nonPageEndpoints.map((p) => p.uri);
    writeResult('roster-pin', {
        check: 'roster-pin',
        derivedPages: pageUris,
        derivedNonPageEndpoints: nonPageUris,
        pinnedPageCount: PIN_PAGES.length,
        pinnedNonPageCount: PIN_NONPAGE.length,
    });
    expect(pageUris, 'derived guest pages drifted from PIN_PAGES — refresh route-list.json and update the pin deliberately').toEqual(PIN_PAGES);
    expect(nonPageUris, 'derived non-page endpoints drifted from PIN_NONPAGE — update the pin deliberately').toEqual(PIN_NONPAGE);
});

// ─────────────────────────────────────────────────────────────────────────
// Per-route sweep.
// ─────────────────────────────────────────────────────────────────────────
for (const [uri, name] of CANDIDATES) {
    test(`route ${uri} (${name})`, async ({ page }) => {
        const result = { uri, name, established: null, establishedVia: null, redirectedTo: null, settle: null, axeDesktop: [], axeNarrow: [], overflow: null, tab: null, notes: [] };

        const resp = await page.goto(uri, { waitUntil: 'commit' }).catch((e) => {
            result.notes.push('goto error: ' + e.message);
            return null;
        });
        const s = await settle(page);
        result.settle = s;

        const finalPath = new URL(page.url()).pathname.replace(/\/$/, '') || '/';
        const requested = uri.replace(/\/$/, '') || '/';
        const wall = AUTH_WALLS.find((w) => finalPath === w);
        if (wall && requested !== wall) {
            result.established = false;
            result.redirectedTo = finalPath;
            result.notes.push(`NOT ESTABLISHED — redirects to ${finalPath} (guest session required)`);
            writeResult(name, result);
            // Recorded outcome, not a failure: login-gated route by name.
            return;
        }
        if (resp && resp.status() >= 400) {
            result.established = false;
            result.notes.push(`NOT ESTABLISHED — HTTP ${resp.status()}`);
            writeResult(name, result);
            expect(resp.status(), `${uri} returned HTTP ${resp.status()}`).toBeLessThan(400);
            return;
        }
        // Evidence gate: no ready marker and no network idle → not established,
        // reason recorded, never scanned as a silent pass.
        if (!s.ready) {
            result.established = false;
            result.notes.push(`NOT ESTABLISHED — ${s.reason}`);
            writeResult(name, result);
            expect(s.ready, `${uri} did not establish: ${s.reason}`).toBeTruthy();
            return;
        }
        result.established = true;
        result.establishedVia = s.via;
        if (finalPath !== requested) result.notes.push(`redirected to ${finalPath} (established, scanned as ${finalPath})`);

        // Desktop axe (WCAG 2.1 AA + color-contrast).
        await page.setViewportSize({ width: 1280, height: 900 });
        result.axeDesktop = await runAxe(page);

        // Tab-order walk at desktop.
        result.tab = await walkTabOrder(page);

        // Narrow viewport: reload at 375px, axe again, assert no page overflow.
        await page.setViewportSize({ width: 375, height: 812 });
        await page.goto(uri, { waitUntil: 'commit' }).catch(() => {});
        const sNarrow = await settle(page);
        result.settleNarrow = sNarrow;
        if (!sNarrow.ready) result.notes.push(`narrow (375px) did not reach ready: ${sNarrow.reason}`);
        result.axeNarrow = await runAxe(page);
        result.overflow = await narrowOverflow(page);

        writeResult(name, result);

        // ── Assertions (each recorded even if others fail). ──
        const axeAll = [...result.axeDesktop, ...result.axeNarrow];
        expect.soft(axeAll, `axe WCAG 2.1 AA violations on ${uri}:\n` + JSON.stringify(axeAll, null, 2)).toEqual([]);
        expect
            .soft(result.overflow.overflow, `horizontal page overflow at 375px on ${uri} (${result.overflow.overflow}px): ` + JSON.stringify(result.overflow.widest))
            .toBeLessThanOrEqual(2);
        expect
            .soft(result.tab.focusGaps, `focusable elements without a visible focus indicator on ${uri}:\n` + JSON.stringify(result.tab.focusGaps, null, 2))
            .toEqual([]);
        expect
            .soft(result.tab.nameGaps, `focusable elements without an accessible name on ${uri}:\n` + JSON.stringify(result.tab.nameGaps, null, 2))
            .toEqual([]);

        const totalDefects =
            axeAll.length + (result.overflow.overflow > 2 ? 1 : 0) + result.tab.focusGaps.length + result.tab.nameGaps.length;
        expect(totalDefects, `${uri}: ${axeAll.length} axe + ${result.tab.focusGaps.length} focus + ${result.tab.nameGaps.length} name gaps + overflow ${result.overflow.overflow}px`).toBe(0);
    });
}

// ─────────────────────────────────────────────────────────────────────────
// Learn drawer — keyboard operable (native <details id="cmd-learn">).
// ─────────────────────────────────────────────────────────────────────────
test('Learn drawer is keyboard operable', async ({ page }) => {
    const result = { check: 'learn-drawer', notes: [] };
    await page.goto('/videos', { waitUntil: 'commit' });
    await settle(page);
    const details = page.locator('details#cmd-learn');
    const present = (await details.count()) > 0;
    result.present = present;
    if (!present) {
        result.notes.push('NOT ESTABLISHED — #cmd-learn not rendered on this guest surface');
        writeResult('learn-drawer', result);
        expect(present, 'Learn drawer #cmd-learn not found on /videos').toBeTruthy();
        return;
    }
    const summary = details.locator('summary').first();
    await summary.focus();
    const focused = await page.evaluate(() => {
        const s = document.querySelector('details#cmd-learn > summary');
        return document.activeElement === s;
    });
    result.summaryFocusable = focused;
    // Toggle open via keyboard (Enter on a <summary> toggles the <details>).
    await page.keyboard.press('Enter');
    await page.waitForTimeout(150);
    const openedByKeyboard = await details.evaluate((d) => d.open);
    result.openedByKeyboard = openedByKeyboard;
    const panelVisible = await details.locator('.cmdbar-panel--learn').isVisible().catch(() => false);
    result.panelVisible = panelVisible;
    // Escape closes and returns focus to the summary (shell contract).
    await page.keyboard.press('Escape');
    await page.waitForTimeout(120);
    result.closedByEscape = !(await details.evaluate((d) => d.open));

    writeResult('learn-drawer', result);
    expect.soft(focused, 'Learn summary did not receive keyboard focus').toBeTruthy();
    expect.soft(openedByKeyboard, 'Enter did not open the Learn drawer').toBeTruthy();
    expect(panelVisible, 'Learn drawer panel not visible after keyboard open').toBeTruthy();
});

// ─────────────────────────────────────────────────────────────────────────
// Language switcher — keyboard operable + html lang/dir follow selection.
// ─────────────────────────────────────────────────────────────────────────
test('Language switcher is keyboard operable and drives html lang/dir', async ({ page }) => {
    const result = { check: 'language-switcher', notes: [] };
    await page.goto('/videos', { waitUntil: 'commit' });
    await settle(page);

    // The switcher is a native <select> whose accessible name is the
    // visually-hidden label text (t('header.language')).
    const select = page.locator('header select.select').first();
    const present = (await select.count()) > 0;
    result.present = present;
    if (!present) {
        result.notes.push('NOT ESTABLISHED — header language <select> not rendered on this guest surface');
        writeResult('language-switcher', result);
        expect(present, 'language <select> not found in header on /videos').toBeTruthy();
        return;
    }

    result.accName = await select.evaluate((el) => {
        const lbl = el.closest('label');
        return (lbl?.textContent || el.getAttribute('aria-label') || '').replace(/\s+/g, ' ').trim();
    });
    const options = await select.locator('option').evaluateAll((els) => els.map((o) => o.value));
    result.options = options;

    // Keyboard-operable: focus and select an RTL locale (ar is enabled).
    await select.focus();
    const focused = await page.evaluate(() => document.activeElement && document.activeElement.tagName === 'SELECT');
    result.focusable = focused;
    await select.selectOption('ar');
    await page.waitForTimeout(200);
    const afterAr = await page.evaluate(() => ({ lang: document.documentElement.lang, dir: document.documentElement.dir }));
    result.afterAr = afterAr;

    await select.selectOption('en');
    await page.waitForTimeout(200);
    const afterEn = await page.evaluate(() => ({ lang: document.documentElement.lang, dir: document.documentElement.dir }));
    result.afterEn = afterEn;

    writeResult('language-switcher', result);
    expect.soft(focused, 'language <select> did not receive keyboard focus').toBeTruthy();
    expect.soft(result.accName, 'language <select> has no accessible name').not.toBe('');
    expect.soft(afterAr.lang, 'html lang did not follow ar selection').toBe('ar');
    expect.soft(afterAr.dir, 'html dir did not become rtl for ar').toBe('rtl');
    expect.soft(afterEn.lang, 'html lang did not follow en selection').toBe('en');
    expect(afterEn.dir, 'html dir did not return to ltr for en').toBe('ltr');
});

// ─────────────────────────────────────────────────────────────────────────
// Server-side: html lang/dir follow the negotiated locale on first paint.
// ─────────────────────────────────────────────────────────────────────────
test('html lang/dir follow the negotiated locale on first paint (server-side)', async ({ playwright }) => {
    const base = process.env.CGA_BROWSER_BASE_URL || 'http://nginx';
    // Raw HTTP GET — the untouched server-rendered markup, no JS. The blade
    // shell writes <html lang="{locale}" dir="{dir}"> from app()->getLocale(),
    // resolved by the SetLocale middleware (Accept-Language negotiation for a
    // guest with no session choice). 'ar' is an enabled RTL locale.
    async function head(acceptLang) {
        const ctx = await playwright.request.newContext({ baseURL: base, extraHTTPHeaders: { 'Accept-Language': acceptLang } });
        const resp = await ctx.get('/videos');
        const body = await resp.text();
        await ctx.dispose();
        const m = body.match(/<html[^>]*\blang="([^"]+)"[^>]*\bdir="([^"]+)"/i);
        return { lang: m ? m[1] : null, dir: m ? m[2] : null, htmlTag: (body.match(/<html[^>]*>/i) || [''])[0].slice(0, 160) };
    }
    const result = { check: 'server-lang-dir', notes: [] };
    result.ar = await head('ar');
    result.en = await head('en-US,en;q=0.9');
    writeResult('server-lang-dir', result);
    expect.soft(result.ar.lang, 'server-negotiated html lang not ar for Accept-Language: ar').toBe('ar');
    expect.soft(result.ar.dir, 'server-negotiated html dir not rtl for ar').toBe('rtl');
    expect.soft(result.en.lang, 'server html lang not en for Accept-Language: en').toBe('en');
    expect(result.en.dir, 'server html dir not ltr for en').toBe('ltr');
});

// ─────────────────────────────────────────────────────────────────────────
// Video player exposes captions / audio alternatives (poster mode is enough:
// no media host configured on box E → labelled poster + live language controls).
// ─────────────────────────────────────────────────────────────────────────
test('Video player exposes captions and audio alternatives', async ({ page }) => {
    const result = { check: 'video-alternatives', notes: [] };
    await page.goto('/videos', { waitUntil: 'commit' });
    await settle(page);

    const audioSelect = page.locator('.vplayer-tracks label:has-text("Audio") select');
    const capSelect = page.locator('.vplayer-tracks label:has-text("Captions") select');
    const capToggle = page.locator('button[aria-label*="Captions"]').first();

    result.audioSelectCount = await audioSelect.count();
    result.captionSelectCount = await capSelect.count();
    result.captionToggleCount = await capToggle.count();
    result.audioOptions = result.audioSelectCount ? await audioSelect.locator('option').count() : 0;
    result.captionOptions = result.captionSelectCount ? await capSelect.locator('option').count() : 0;
    result.mode = (await page.locator('[role="img"][aria-label*="Video"]').count()) ? 'poster' : 'media';

    // The captions toggle is keyboard-togglable and exposes aria-pressed.
    if (result.captionToggleCount) {
        result.captionAriaPressed = await capToggle.getAttribute('aria-pressed');
        result.captionAccName = await capToggle.getAttribute('aria-label');
    }

    writeResult('video-alternatives', result);
    expect.soft(result.audioSelectCount, 'no audio-language selector in the video player').toBeGreaterThan(0);
    expect.soft(result.audioOptions, 'audio selector has no language options').toBeGreaterThan(0);
    expect.soft(result.captionSelectCount, 'no captions-language selector in the video player').toBeGreaterThan(0);
    expect.soft(result.captionOptions, 'captions selector has no language options').toBeGreaterThan(0);
    expect(result.captionToggleCount, 'no captions on/off toggle in the video player').toBeGreaterThan(0);
});
