#!/usr/bin/env node
/* ============================================================================
   CGA — scripts/i18n/check.mjs
   The i18n gate. Runs STANDALONE and exits non-zero on failure, so it gates
   from day one with no CI. It drops into whatever CI lane 2 stands up later
   without a rewrite.

   WHAT IT ASSERTS
     C1  key parity      — every locale carries every en key (the live 8-key
                           nav drift is the day-one case)
     C2  orphan keys     — no locale carries a key en does not
     C3  placeholders    — {named} tokens match between en and each target
     C4  ID tokens       — R-/WF-/F-/I-/CLK- and Art. …/§ citations are
                           byte-identical in every locale
     C5  compiles        — every message compiles with @intlify/message-compiler,
                           the ACTUAL vue-i18n runtime parser. Not an ICU parser:
                           vue-i18n uses `|` for plurals and `@:` for links.
     C6  empty/untranslated — no blank values; flags targets identical to en
     C7  registry drift  — the PHP and JS locale lists agree

   It also writes resources/js/i18n/coverage.json — the machine-readable status
   the coverage dashboard reads.

   Usage:
     node scripts/i18n/check.mjs                 # human report, exit 0/1
     node scripts/i18n/check.mjs --json          # machine output
     node scripts/i18n/check.mjs --no-write      # skip coverage.json
     node scripts/i18n/check.mjs --warn-only     # always exit 0 (triage runs)
   ============================================================================ */

import { readFileSync, writeFileSync, readdirSync, statSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { baseCompile } from '@intlify/message-compiler';

const ROOT     = fileURLToPath(new URL('../../', import.meta.url));
const I18N     = join(ROOT, 'resources', 'js', 'i18n');
const LOCALES  = join(I18N, 'locales');
const COVERAGE = join(I18N, 'coverage.json');

const argv      = process.argv.slice(2);
const AS_JSON   = argv.includes('--json');
const NO_WRITE  = argv.includes('--no-write');
const WARN_ONLY = argv.includes('--warn-only');

const ID_TOKEN   = /\b(?:R|WF|F|I|CLK)-[\dA-Z][\dA-Z-]*\b/g;
const CITATION   = /Art\.\s*[IVXLC]+(?:\s*§\s*\d+)?/g;
const PLACEHOLDER = /(?<!\{')\{([A-Za-z_$][\w$]*)\}/g;

const failures = [];
const warnings = [];
const fail = (code, locale, ns, key, detail) =>
    failures.push({ code, locale, ns: ns ?? null, key: key ?? null, detail });
const warn = (code, locale, ns, key, detail) =>
    warnings.push({ code, locale, ns: ns ?? null, key: key ?? null, detail });

/* ─── Load the monolithic chrome dicts + the per-namespace catalogs ───────── */
function readJson(p) {
    try { return JSON.parse(readFileSync(p, 'utf8')); } catch (e) {
        fail('C0-parse', null, null, null, `${p}: ${e.message}`);
        return {};
    }
}

function flatten(obj, prefix = '', out = {}) {
    for (const [k, v] of Object.entries(obj)) {
        const key = prefix ? `${prefix}.${k}` : k;
        if (v && typeof v === 'object' && !Array.isArray(v)) flatten(v, key, out);
        else out[key] = v;
    }
    return out;
}

/* chrome: resources/js/i18n/<code>.json */
const chromeFiles = readdirSync(I18N).filter((f) => /^[a-zA-Z-]+\.json$/.test(f));
const chromeLocales = chromeFiles.map((f) => f.replace(/\.json$/, ''));

/* namespaces: resources/js/i18n/locales/<code>/<ns>.json */
const nsLocales = existsSync(LOCALES)
    ? readdirSync(LOCALES).filter((d) => statSync(join(LOCALES, d)).isDirectory())
    : [];

const allLocales = [...new Set([...chromeLocales, ...nsLocales])].sort();

/* Build locale -> { "<ns>.<key>": message } */
const catalogs = new Map();
for (const code of allLocales) {
    const bag = {};
    const chromePath = join(I18N, `${code}.json`);
    if (existsSync(chromePath)) {
        for (const [k, v] of Object.entries(flatten(readJson(chromePath)))) bag[`chrome:${k}`] = v;
    }
    const nsDir = join(LOCALES, code);
    if (existsSync(nsDir)) {
        for (const f of readdirSync(nsDir).filter((x) => x.endsWith('.json'))) {
            const ns = f.replace(/\.json$/, '');
            for (const [k, v] of Object.entries(flatten(readJson(join(nsDir, f))))) {
                bag[`${ns}:${k}`] = v;
            }
        }
    }
    catalogs.set(code, bag);
}

if (!catalogs.has('en')) {
    console.error('FATAL: no `en` catalog found — nothing to check against.');
    process.exit(2);
}
const en = catalogs.get('en');
const enKeys = Object.keys(en);

/* ─── C5: every en message must compile with the real runtime parser ─────── */
function compiles(msg) {
    try {
        baseCompile(String(msg), { onError: (e) => { throw e; } });
        return true;
    } catch (e) {
        return e?.message ?? 'compile error';
    }
}

function tokensOf(s, re) {
    const m = String(s).match(re);
    return m ? [...m].sort() : [];
}

for (const [k, v] of Object.entries(en)) {
    const c = compiles(v);
    if (c !== true) fail('C5-compile', 'en', k.split(':')[0], k, c);
    if (String(v).trim() === '') fail('C6-empty', 'en', k.split(':')[0], k, 'empty source message');
}

/* ─── C1/C2/C3/C4/C6 per target locale ───────────────────────────────────── */
const perLocale = [];
for (const code of allLocales) {
    if (code === 'en' || code === 'en-XA') continue;
    const bag = catalogs.get(code);
    const keys = new Set(Object.keys(bag));

    const missing = enKeys.filter((k) => !keys.has(k));
    const orphan  = [...keys].filter((k) => !(k in en));

    for (const k of missing) fail('C1-missing', code, k.split(':')[0], k, 'key absent in this locale');
    for (const k of orphan)  fail('C2-orphan',  code, k.split(':')[0], k, 'key not present in en');

    let identical = 0;
    for (const [k, v] of Object.entries(bag)) {
        if (!(k in en)) continue;
        const src = String(en[k]);
        const tgt = String(v);

        if (tgt.trim() === '') { fail('C6-empty', code, k.split(':')[0], k, 'empty translation'); continue; }
        if (tgt === src && src.length > 3) identical += 1;

        const c = compiles(tgt);
        if (c !== true) fail('C5-compile', code, k.split(':')[0], k, c);

        const ps = tokensOf(src, PLACEHOLDER);
        const pt = tokensOf(tgt, PLACEHOLDER);
        if (ps.join('|') !== pt.join('|')) {
            fail('C3-placeholder', code, k.split(':')[0], k, `en[${ps.join(',')}] vs ${code}[${pt.join(',')}]`);
        }

        for (const re of [ID_TOKEN, CITATION]) {
            const a = tokensOf(src, re);
            const b = tokensOf(tgt, re);
            if (a.join('|') !== b.join('|')) {
                fail('C4-idtoken', code, k.split(':')[0], k, `en[${a.join(',')}] vs ${code}[${b.join(',')}]`);
            }
        }
    }

    const present = enKeys.length - missing.length;
    if (identical) warn('C6-untranslated', code, null, null, `${identical} values identical to en`);

    perLocale.push({
        locale: code,
        total: enKeys.length,
        present,
        missing: missing.length,
        orphan: orphan.length,
        identical,
        pct: +((present / Math.max(enKeys.length, 1)) * 100).toFixed(1),
    });
}

/* ─── C7: the locale lists must agree (PHP vs JS) ────────────────────────── */
function jsRegistryCodes() {
    const src = readFileSync(join(I18N, 'index.js'), 'utf8');
    const block = src.match(/export const LOCALES\s*=\s*\[([\s\S]*?)\];/);
    if (!block) return null;
    return [...block[1].matchAll(/code:\s*'([^']+)'/g)].map((m) => m[1]).sort();
}
function phpSupportedCodes() {
    const p = join(ROOT, 'app', 'Http', 'Middleware', 'SetLocale.php');
    if (!existsSync(p)) return null;
    const src = readFileSync(p, 'utf8');
    const block = src.match(/SUPPORTED\s*=\s*\[([\s\S]*?)\];/);
    if (!block) return null;
    return [...block[1].matchAll(/'([^']+)'/g)].map((m) => m[1]).sort();
}
const jsCodes  = jsRegistryCodes();
const phpCodes = phpSupportedCodes();
if (jsCodes && phpCodes && jsCodes.join(',') !== phpCodes.join(',')) {
    fail('C7-registry', null, null, null, `JS[${jsCodes.join(',')}] vs PHP[${phpCodes.join(',')}]`);
}

/* ─── Coverage artifact (the dashboard reads this) ───────────────────────── */
const nsSet = [...new Set(enKeys.map((k) => k.split(':')[0]))].sort();
const byNamespace = nsSet.map((ns) => {
    const total = enKeys.filter((k) => k.startsWith(`${ns}:`)).length;
    const locales = {};
    for (const row of perLocale) {
        const bag = catalogs.get(row.locale);
        locales[row.locale] = enKeys.filter((k) => k.startsWith(`${ns}:`) && k in bag).length;
    }
    return { namespace: ns, total, locales };
});

const coverage = {
    generated_at: new Date().toISOString(),
    source_keys: enKeys.length,
    namespaces: nsSet.length,
    locales: perLocale,
    by_namespace: byNamespace,
    failures: failures.length,
    warnings: warnings.length,
    failure_codes: failures.reduce((a, f) => { a[f.code] = (a[f.code] || 0) + 1; return a; }, {}),
};

if (!NO_WRITE) writeFileSync(COVERAGE, JSON.stringify(coverage, null, 2) + '\n');

/* ─── Report ─────────────────────────────────────────────────────────────── */
if (AS_JSON) {
    console.log(JSON.stringify({ coverage, failures, warnings }, null, 2));
} else {
    console.log('\nCGA i18n check');
    console.log('==============');
    console.log(`source keys (en)  ${enKeys.length}   namespaces ${nsSet.length}`);
    console.log('');
    console.log('locale      present/total    %      missing  orphan  identical');
    for (const r of perLocale) {
        console.log(
            `  ${r.locale.padEnd(9)} ${String(r.present).padStart(5)}/${String(r.total).padEnd(6)} ` +
            `${String(r.pct).padStart(5)}%  ${String(r.missing).padStart(7)} ${String(r.orphan).padStart(7)} ${String(r.identical).padStart(10)}`,
        );
    }
    if (failures.length) {
        console.log(`\nFAILURES: ${failures.length}`);
        const byCode = {};
        for (const f of failures) (byCode[f.code] ??= []).push(f);
        for (const [code, list] of Object.entries(byCode)) {
            console.log(`\n  ${code}  (${list.length})`);
            for (const f of list.slice(0, 8)) {
                console.log(`    ${(f.locale ?? '-').padEnd(8)} ${(f.key ?? '').padEnd(46)} ${f.detail}`);
            }
            if (list.length > 8) console.log(`    … and ${list.length - 8} more`);
        }
    } else {
        console.log('\nno failures');
    }
    if (warnings.length) {
        console.log(`\nwarnings: ${warnings.length}`);
        for (const w of warnings.slice(0, 10)) {
            console.log(`  ${(w.locale ?? '-').padEnd(8)} ${w.code}  ${w.detail}`);
        }
    }
    if (!NO_WRITE) console.log(`\ncoverage -> resources/js/i18n/coverage.json`);
}

process.exit(WARN_ONLY ? 0 : failures.length ? 1 : 0);
