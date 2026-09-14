#!/usr/bin/env node
// D1 · final rehearsal runner — SKELETON (prepare-only).
//
// The full simulated-presenter end-to-end rehearsal is BLOCKED (see the three
// rows in scripts/demo/d1_steps.mjs → BLOCKED_BY, all deferred by operator
// ruling 2026-09-14). This runner is the instrument the operator runs once
// those rows unblock. Today it supports only two safe modes and refuses the
// rest.
//
// Modes:
//   --plan       Print the actors, the blocking rows and every step. No browser.
//   --dry-run    Open each guest-readable page as a GUEST and assert it renders;
//                assert each auth-walled page redirects a guest to /login.
//                Action steps are SKIPPED (they require a demo session and are
//                not implemented). Read-only against the live app.
//   (no flag)    Refuse: the full rehearsal is BLOCKED. Print the blocking rows
//                and exit non-zero. Action steps throw NotImplemented if called.
//
// Run ONLY inside the fc_vite container:
//   docker exec fc_vite sh -c 'cd /var/www/html && node scripts/demo/d1_rehearsal.mjs --plan'
//   docker exec fc_vite sh -c 'cd /var/www/html && node scripts/demo/d1_rehearsal.mjs --dry-run --base http://nginx'
//
// Base URL: --base <url> or env CGA_D1_BASE (default http://nginx).

import { STEPS, ACTORS, BLOCKED_BY, PRECONDITIONS } from './d1_steps.mjs';
import fs from 'node:fs';

const argv = process.argv.slice(2);
const has = (f) => argv.includes(f);
const argOf = (f) => {
    const i = argv.indexOf(f);
    return i >= 0 && i + 1 < argv.length ? argv[i + 1] : null;
};
const BASE = (argOf('--base') || process.env.CGA_D1_BASE || 'http://nginx').replace(/\/+$/, '');
const AUTH_WALL_RE = /\/(login|register|operator\/login)(\?|#|$)/;
const STEP_CAP_MS = Number(process.env.CGA_D1_STEP_CAP_MS || 40_000);
const LOG_PATH = process.env.CGA_D1_LOG || '/tmp/d1_dryrun.log';

// Synchronous append so progress is observable live through the docker-exec
// pipe buffer (stdout to a pipe is block-buffered; this file is not).
function logLine(s) {
    try { fs.appendFileSync(LOG_PATH, s + '\n'); } catch { /* ignore */ }
    console.log(s);
}

// Hard cap around a step so a stuck navigation cannot stall the whole run.
function withCap(promise, ms, label) {
    let t;
    const cap = new Promise((_, rej) => { t = setTimeout(() => rej(new Error(`step-cap ${ms}ms exceeded (${label})`)), ms); });
    return Promise.race([promise.finally(() => clearTimeout(t)), cap]);
}

// Reserved static path segments under /jurisdictions that are NOT place slugs.
const JUR_RESERVED = new Set(['bootstrap', 'union-formation', 'disintermediation', 'restoration', 'search', 'map']);

function line() { console.log('─'.repeat(72)); }

function printPlan() {
    console.log('D1 · FINAL REHEARSAL — PLAN (prepare-only, verdict BLOCKED)');
    line();
    console.log('BLOCKED BY (all deferred by operator ruling 2026-09-14):');
    for (const b of BLOCKED_BY) {
        console.log(`  • ${b.row}`);
        console.log(`      unblocked by: ${b.unblocked_by}`);
        console.log(`      why:          ${b.why}`);
    }
    console.log('PRECONDITIONS:');
    for (const p of PRECONDITIONS) console.log(`  • ${p}`);
    line();
    console.log('ACTORS:');
    for (const a of ACTORS) console.log(`  • ${a.id} — ${a.role}: ${a.note}`);
    line();
    console.log(`STEPS (${STEPS.length}):`);
    let phase = null;
    for (const s of STEPS) {
        if (s.phase !== phase) { phase = s.phase; console.log(`\n[phase: ${phase}]`); }
        const route = s.route || (s.discover ? `<discover:${s.discover}>` : '');
        console.log(`  ${s.id}  ${s.mode.toUpperCase().padEnd(9)} ${s.actor.padEnd(9)} ${route}`);
        console.log(`        ${s.title}${s.form ? '  [form ' + s.form + ']' : ''}`);
        console.log(`        nav:     ${s.expectNav}`);
        if (s.expectRefusal) console.log(`        refusal: ${s.expectRefusal}`);
        if (s.dataFrom) console.log(`        data-from (blocked): ${s.dataFrom}`);
        if (s.opensDemoSession) console.log(`        opens demo session: yes`);
        if (s.requiresDemoSession) console.log(`        requires demo session: yes`);
    }
    line();
    console.log('End of plan. No browser opened, no page fetched, no action performed.');
}

// The action stub. Every action step routes here and REFUSES: no demo session
// exists for this lane and the write path is deliberately not implemented.
function runActionStub(step) {
    throw new Error(
        `NotImplemented: action step ${step.id} (${step.name}) requires an established demo session ` +
        `and is not implemented in this skeleton. The full rehearsal is BLOCKED by: ` +
        BLOCKED_BY.map((b) => b.row).join(', ') + '.',
    );
}

// ── dry-run browser plumbing ─────────────────────────────────────────────────
// Box E serves the Vite DEV bundle; the blade shell pulls its ES modules from
// http://localhost:5173. Vite's dev CORS allowlist does not include the
// http://nginx page origin, so without re-adding the CORS header the Vue app
// never boots and every page shows only "Loading…". This patch changes only
// how THIS browser sees the dev server's response headers; it touches nothing
// on the live app. The built (production) bundle serves same-origin and needs
// none of this. (Mirrors tests/browser/accessibility.test.mjs.)
const DEV_ASSET_RE = /:\/\/localhost:5173\//;
async function bootAssets(page) {
    const origin = () => { try { return new URL(page.url()).origin; } catch { return BASE; } };
    await page.route(DEV_ASSET_RE, async (route) => {
        let resp;
        try { resp = await route.fetch({ timeout: 8000 }); } catch { return route.abort(); }
        const headers = { ...resp.headers() };
        headers['access-control-allow-origin'] = origin() || BASE;
        headers['access-control-allow-credentials'] = 'true';
        try { await route.fulfill({ response: resp, headers }); } catch { await route.abort(); }
    });
}

async function settle(page) {
    // domcontentloaded first, then wait for the Vue app to paint real content.
    // networkidle is NOT used: a Vite dev page holds an open HMR websocket so
    // the network is never idle and the wait would burn its whole timeout on
    // every step. The content probe is the meaningful signal.
    await page.waitForLoadState('domcontentloaded').catch(() => {});
    await page.waitForFunction(() => {
        const app = document.getElementById('app') || document.body;
        const hasMain = document.querySelector('main, [role="main"], form, h1');
        return !!hasMain && (app?.textContent || '').trim().length > 0;
    }, { timeout: 15_000 }).catch(() => {});
    // Small settle for late-mounting content; bounded, never networkidle.
    await page.waitForTimeout(300);
}

async function rendered(page) {
    return page.evaluate(() => {
        const app = document.getElementById('app') || document.body;
        const hasMain = !!document.querySelector('main, [role="main"], form, h1');
        const text = (app?.textContent || '').replace(/\s+/g, ' ').trim();
        return { hasMain, textLen: text.length, sample: text.slice(0, 80) };
    });
}

async function discoverPlaceSlug(page) {
    await page.goto(BASE + '/jurisdictions', { waitUntil: 'domcontentloaded' }).catch(() => {});
    await settle(page);
    return page.evaluate((reserved) => {
        const set = new Set(reserved);
        for (const a of document.querySelectorAll('a[href]')) {
            const m = (a.getAttribute('href') || '').match(/^\/jurisdictions\/([a-z0-9][a-z0-9-]*)(?:\/map)?$/i);
            if (m && !set.has(m[1])) return m[1];
        }
        return null;
    }, [...JUR_RESERVED]);
}

async function discoverLesson(page) {
    await page.goto(BASE + '/learn', { waitUntil: 'domcontentloaded' }).catch(() => {});
    await settle(page);
    return page.evaluate(() => {
        for (const a of document.querySelectorAll('a[href]')) {
            const m = (a.getAttribute('href') || '').match(/^\/learn\/([a-z0-9_-]+)\/([a-z0-9_-]+)$/i);
            if (m && m[1] !== 'manage' && m[1] !== 'guides') return `/learn/${m[1]}/${m[2]}`;
        }
        return null;
    });
}

// Run one function against a fresh, CORS-booted guest page and always close it,
// so a slow or wedged page (e.g. /building) cannot stall the next step.
async function onFreshPage(context, fn) {
    const page = await context.newPage();
    await bootAssets(page);
    try { return await fn(page); }
    finally { await page.close().catch(() => {}); }
}

async function resolveUrl(step, context, ctx) {
    if (step.route) return { url: BASE + step.route };
    if (step.discover === 'place' || step.discover === 'placeMap') {
        if (ctx.placeSlug === undefined) ctx.placeSlug = await onFreshPage(context, discoverPlaceSlug);
        if (!ctx.placeSlug) return { skip: 'no place slug discoverable (depends on Setup · worlds data)' };
        return { url: `${BASE}/jurisdictions/${ctx.placeSlug}${step.discover === 'placeMap' ? '/map' : ''}` };
    }
    if (step.discover === 'lesson') {
        const l = await onFreshPage(context, discoverLesson);
        return l ? { url: BASE + l } : { skip: 'no lesson discoverable (depends on education content)' };
    }
    return { skip: 'no route and no known discoverer' };
}

async function dryRun() {
    let chromium;
    try { ({ chromium } = await import('@playwright/test')); }
    catch { ({ chromium } = await import('playwright')); }

    try { fs.writeFileSync(LOG_PATH, ''); } catch { /* ignore */ }
    console.log(`D1 · DRY-RUN (guest, read-only) against ${BASE}`);
    console.log(`Live per-step log: ${LOG_PATH} (step cap ${STEP_CAP_MS}ms).`);
    console.log('Action steps are SKIPPED — they require a demo session and are not implemented.');
    line();

    const browser = await chromium.launch({ headless: true, args: ['--autoplay-policy=no-user-gesture-required'] });
    // Fresh context = a guest: no storageState, no credentials.
    const context = await browser.newContext();

    const ctx = { placeSlug: undefined };
    const results = [];
    let nonGet = 0;
    context.on('request', (r) => { if (r.method() !== 'GET') nonGet++; });

    // The work of one read/auth-wall step on a FRESH page, bounded by the cap.
    async function runVisit(step, target) {
      return onFreshPage(context, async (page) => {
        const t0 = Date.now();
        let resp = null, err = null;
        try { resp = await page.goto(target.url, { waitUntil: 'domcontentloaded', timeout: 25_000 }); }
        catch (e) { err = e.message; }
        await settle(page);
        const ms = Date.now() - t0;
        const finalUrl = page.url();
        const status = resp ? resp.status() : null;
        if (step.mode === 'auth-wall') {
            const walled = AUTH_WALL_RE.test(finalUrl);
            return { verdict: walled ? 'PASS' : 'FAIL', url: target.url, finalUrl, status, ms,
                detail: walled ? 'redirected to auth wall' : 'expected /login redirect, page rendered instead' };
        }
        if (err) return { verdict: 'FAIL', url: target.url, finalUrl, status, ms, detail: 'navigation error: ' + err };
        if (AUTH_WALL_RE.test(finalUrl)) return { verdict: 'FAIL', url: target.url, finalUrl, status, ms, detail: 'read page unexpectedly redirected to auth wall' };
        const r = await rendered(page);
        const ok = status && status < 400 && r.hasMain && r.textLen > 0;
        return { verdict: ok ? 'PASS' : 'FAIL', url: target.url, finalUrl, status, ms, textLen: r.textLen,
            detail: ok ? `rendered (${r.textLen} chars)` : `status=${status} hasMain=${r.hasMain} textLen=${r.textLen}` };
      });
    }

    for (const step of STEPS) {
        const tag = `${step.id} [${step.mode}] ${step.name}`;
        if (step.mode === 'action') {
            results.push({ id: step.id, name: step.name, verdict: 'SKIPPED', detail: 'action step; requires demo session; not implemented' });
            logLine(`SKIP  ${tag} — action, requires demo session (not implemented)`);
            continue;
        }
        let target;
        try { target = await withCap(resolveUrl(step, context, ctx), STEP_CAP_MS, step.id + ' resolve'); }
        catch (e) { target = { skip: 'resolver error/cap: ' + e.message }; }
        if (target.skip) {
            results.push({ id: step.id, name: step.name, verdict: 'SKIPPED', detail: target.skip });
            logLine(`SKIP  ${tag} — ${target.skip}`);
            continue;
        }

        logLine(`....  ${tag} — GET ${target.url}`);
        let out;
        try { out = await withCap(runVisit(step, target), STEP_CAP_MS, step.id + ' visit'); }
        catch (e) { out = { verdict: 'FAIL', url: target.url, status: null, detail: 'step-cap: ' + e.message }; }
        results.push({ id: step.id, name: step.name, ...out });
        const nav = out.finalUrl && out.finalUrl !== out.url ? ` → ${out.finalUrl}` : '';
        logLine(`${out.verdict}  ${tag} — ${out.url}${nav} [${out.status}] ${out.detail}${out.ms != null ? ' (' + out.ms + 'ms)' : ''}`);
    }

    await context.close();
    await browser.close();

    line();
    const c = (v) => results.filter((r) => r.verdict === v).length;
    logLine(`DRY-RUN SUMMARY: PASS=${c('PASS')} FAIL=${c('FAIL')} SKIPPED=${c('SKIPPED')} (of ${results.length})`);
    logLine(`Non-GET requests issued by the runner: ${nonGet} (expected 0 — the runner only navigates).`);
    logLine('D1 verdict remains BLOCKED: ' + BLOCKED_BY.map((b) => b.row).join(', ') + '.');
    return c('FAIL') === 0 ? 0 : 1;
}

function refuseFull() {
    console.error('D1 · FULL REHEARSAL IS BLOCKED — refusing to run.');
    console.error('Waits on (all deferred by operator ruling 2026-09-14):');
    for (const b of BLOCKED_BY) console.error(`  • ${b.row} — ${b.unblocked_by}`);
    console.error('Run with --plan to print the journey, or --dry-run for the guest read-only pass.');
    return 3;
}

async function main() {
    if (has('--plan')) { printPlan(); return 0; }
    if (has('--dry-run')) { return dryRun(); }
    return refuseFull();
}

main().then((code) => process.exit(code)).catch((e) => { console.error(e); process.exit(2); });

export { runActionStub };
