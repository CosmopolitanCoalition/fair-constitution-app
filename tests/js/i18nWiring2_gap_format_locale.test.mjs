// node --experimental-vm-modules --test tests/js/i18nWiring2_gap_format_locale.test.mjs
//
// Gap lane gap-format-locale pin. One behaviour gap: number, date and
// relative-time formatting ignored the app locale (bare toLocaleString /
// toLocaleDateString / toLocaleTimeString and Intl.*Format(undefined all
// follow the browser default). The lane routes every such call through a
// locale-aware composable (useLocaleFormat) / plain helper (localeFormat)
// that reads the active vue-i18n locale.
//
// Three assertions:
//   (a) the composable helpers format for en, pl and ar and honour the locale;
//   (b) no bare toLocaleString( / toLocaleDateString( / toLocaleTimeString( or
//       Intl.*Format(undefined remains under resources/js, outside the three
//       files another lane edits (AppShell, AppShellV2, Register);
//   (c) every .vue that imports the composable compiles with @vue/compiler-sfc.

import assert from 'node:assert/strict';
import test from 'node:test';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';
import { localeFormat, normalizeLocale, useLocaleFormat } from '../../resources/js/composables/useLocaleFormat.js';

const JS_ROOT = fileURLToPath(new URL('../../resources/js', import.meta.url));
const EXCLUDE = new Set([
    'Layouts/AppShell.vue',
    'Layouts/AppShellV2.vue',
    'Pages/Auth/Register.vue',
    'composables/useLocaleFormat.js',
]);

function walk(dir, out = []) {
    for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
        const p = dir + '/' + e.name;
        if (e.isDirectory()) walk(p, out);
        else if (/\.(vue|js)$/.test(e.name)) out.push(p);
    }
    return out;
}

// Mask JS block and line comments with spaces (length preserved) so a
// toLocaleString( mentioned in a comment (money.js documents a historical bug)
// is not counted as a live call.
function maskComments(src) {
    const a = src.split('');
    let i = 0, inStr = null;
    while (i < src.length) {
        const c = src[i];
        if (inStr) { if (c === '\\') { i += 2; continue; } if (c === inStr) inStr = null; i++; continue; }
        if (c === "'" || c === '"' || c === '`') { inStr = c; i++; continue; }
        if (c === '/' && src[i + 1] === '*') { let j = src.indexOf('*/', i + 2); if (j < 0) j = src.length; else j += 2; for (let k = i; k < j; k++) if (a[k] !== '\n') a[k] = ' '; i = j; continue; }
        if (c === '/' && src[i + 1] === '/') { let j = src.indexOf('\n', i); if (j < 0) j = src.length; for (let k = i; k < j; k++) a[k] = ' '; i = j; continue; }
        i++;
    }
    return a.join('');
}

const rel = (p) => p.slice(JS_ROOT.length + 1);

// Node may be built with small-icu (English data only), in which case every
// locale formats like en and cross-locale differences are unobservable. The
// delegation equalities below hold regardless; the "honours the locale"
// inequality checks are only meaningful with full ICU.
const FULL_ICU = (1234567).toLocaleString('pl') !== (1234567).toLocaleString('en');

// ---------------------------------------------------------------------------
// (a) unit tests of the helpers for en, pl and ar
// ---------------------------------------------------------------------------
test('localeFormat.number delegates to the locale and honours it', () => {
    const n = 1234567.5;
    assert.equal(localeFormat('en').number(n), (n).toLocaleString('en'));
    assert.equal(localeFormat('pl').number(n), (n).toLocaleString('pl'));
    assert.equal(localeFormat('ar').number(n), (n).toLocaleString('ar'));
    // locale is actually applied: grouping/digits differ across languages
    // (only observable with full ICU).
    // pl uses a space group separator and a comma decimal — distinct from en.
    if (FULL_ICU) {
        assert.notEqual(localeFormat('en').number(n), localeFormat('pl').number(n));
    }
    // en-US grouping is unchanged (the English viewer must not shift).
    assert.equal(localeFormat('en').number(1234567), '1,234,567');
});

test('localeFormat.date / dateTime / time delegate per locale', () => {
    const d = new Date('2026-03-09T13:05:00Z');
    const dateOpts = { dateStyle: 'medium' };
    for (const loc of ['en', 'pl', 'ar']) {
        assert.equal(localeFormat(loc).date(d, dateOpts), d.toLocaleDateString(loc, dateOpts));
        assert.equal(localeFormat(loc).dateTime(d, { dateStyle: 'medium', timeStyle: 'short' }),
            d.toLocaleString(loc, { dateStyle: 'medium', timeStyle: 'short' }));
        assert.equal(localeFormat(loc).time(d, { hour: '2-digit', minute: '2-digit' }),
            d.toLocaleTimeString(loc, { hour: '2-digit', minute: '2-digit' }));
    }
    // date order differs between English and Polish medium style (full ICU only).
    if (FULL_ICU) {
        assert.notEqual(localeFormat('en').date(d, dateOpts), localeFormat('pl').date(d, dateOpts));
    }
});

test('localeFormat.relative formats and honours the locale', () => {
    const base = new Date('2026-01-10T00:00:00Z');
    const past = new Date('2026-01-07T00:00:00Z'); // 3 days before base
    assert.equal(localeFormat('en').relative(past, base),
        new Intl.RelativeTimeFormat('en', { numeric: 'auto' }).format(-3, 'day'));
    assert.equal(localeFormat('en').relative(past, base), '3 days ago');
    // other locales produce a non-empty string (and a different one under full ICU).
    for (const loc of ['pl', 'ar']) {
        const out = localeFormat(loc).relative(past, base);
        assert.ok(out && out.length > 0);
        if (FULL_ICU) assert.notEqual(out, localeFormat('en').relative(past, base));
    }
    // future direction.
    const future = new Date('2026-01-12T00:00:00Z'); // 2 days after base
    assert.equal(localeFormat('en').relative(future, base),
        new Intl.RelativeTimeFormat('en', { numeric: 'auto' }).format(2, 'day'));
});

test('en-XA pseudo-locale is normalised to en (never thrown to Intl)', () => {
    assert.equal(normalizeLocale('en-XA'), 'en');
    assert.equal(normalizeLocale(null), undefined);
    assert.equal(normalizeLocale(''), undefined);
    assert.equal(normalizeLocale('pl'), 'pl');
    const n = 1234567;
    assert.equal(localeFormat('en-XA').number(n), localeFormat('en').number(n));
});

test('useLocaleFormat() falls back to the browser default outside a component', () => {
    // No vue app / i18n installed here: useI18n() throws and is swallowed.
    const fmt = useLocaleFormat();
    for (const k of ['number', 'date', 'dateTime', 'time', 'relative']) {
        assert.equal(typeof fmt[k], 'function');
    }
    // browser-default path equals localeFormat(undefined)
    assert.equal(fmt.number(1234567), localeFormat(undefined).number(1234567));
});

// ---------------------------------------------------------------------------
// (b) source scan: no bare toLocale*/Intl(undefined) outside the excluded files
// ---------------------------------------------------------------------------
test('no bare toLocale* or Intl.*Format(undefined remains under resources/js', () => {
    const bare = /\.(toLocaleString|toLocaleDateString|toLocaleTimeString)\s*\(\s*(\)|undefined|\[\s*\])/;
    const intlUndef = /\bIntl\.(DateTimeFormat|NumberFormat|RelativeTimeFormat)\s*\(\s*undefined/;
    const offenders = [];
    for (const p of walk(JS_ROOT)) {
        const r = rel(p).replace(/\\/g, '/');
        if (EXCLUDE.has(r)) continue;
        const masked = maskComments(fs.readFileSync(p, 'utf8'));
        masked.split('\n').forEach((ln, i) => {
            if (bare.test(ln) || intlUndef.test(ln)) offenders.push(`${r}:${i + 1}  ${ln.trim()}`);
        });
    }
    assert.deepEqual(offenders, [], 'bare locale-ignoring format calls remain:\n' + offenders.join('\n'));
});

// ---------------------------------------------------------------------------
// (d) money.js formatWhen is locale-capable and does not shift English output.
//     money.js imports the composable through the '@/' Vite alias, so it cannot
//     be imported into a plain node test. Instead: (1) assert its source
//     threads its `locale` parameter into localeFormat(locale).dateTime, and
//     (2) prove that path honours the locale while leaving the English viewer
//     unchanged, using localeFormat directly (imported here) with the exact
//     option set formatWhen passes. localeFormat(undefined) is the pre-fix
//     browser-default behaviour; en equals it, pl differs.
// ---------------------------------------------------------------------------
test('money.js formatWhen threads its locale argument through the composable', () => {
    const money = fs.readFileSync(fileURLToPath(new URL('../../resources/js/lib/money.js', import.meta.url)), 'utf8');
    const src = maskComments(money);
    // signature carries the locale parameter ...
    assert.match(src, /export\s+function\s+formatWhen\s*\(\s*iso\s*,\s*locale\s*\)/);
    // ... and the body formats through the composable with that locale, not a
    // bare toLocale* / hard-coded / undefined locale.
    assert.match(src, /localeFormat\s*\(\s*locale\s*\)\s*\.\s*dateTime\s*\(/);
    assert.doesNotMatch(src, /\.toLocaleString\s*\(/); // no bare call survives in the live source
});

test('the composable path formatWhen uses honours the locale without shifting English', () => {
    const d = new Date('2026-03-09T13:05:00Z');
    const opts = { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' };
    // no locale (undefined) is the pre-fix browser default; en equals it: the
    // English viewer must not shift.
    assert.equal(localeFormat('en').dateTime(d, opts), localeFormat(undefined).dateTime(d, opts));
    // pl reaches Intl and differs (only observable with full ICU).
    if (FULL_ICU) {
        assert.notEqual(localeFormat('pl').dateTime(d, opts), localeFormat('en').dateTime(d, opts));
    }
});

// ---------------------------------------------------------------------------
// (e) money.js formatWhen (locale-capable) is never called bare in a
//     component: every .vue importing it aliases the raw formatter and threads
//     the active app locale into it. A locale-capable helper called without a
//     locale renders the browser default, which the (b) scan cannot see.
// ---------------------------------------------------------------------------
test('every .vue using money.js formatWhen threads the active locale', () => {
    const importRe = /import\s*\{[^}]*\}\s*from\s*['"]@\/lib\/money(\.js)?['"]/;
    const offenders = [];
    for (const p of walk(JS_ROOT).filter((x) => x.endsWith('.vue'))) {
        const src = fs.readFileSync(p, 'utf8');
        const imp = src.match(importRe);
        if (!imp || !/\bformatWhen\b/.test(imp[0])) continue;
        const r = rel(p).replace(/\\/g, '/');
        const alias = imp[0].match(/\bformatWhen\s+as\s+(\w+)/);
        if (!alias) {
            offenders.push(`${r}: imports formatWhen from money.js unaliased (called bare, no locale)`);
            continue;
        }
        const bound = new RegExp(alias[1] + '\\s*\\([^)]*locale\\.value');
        if (!bound.test(maskComments(src))) {
            offenders.push(`${r}: aliases formatWhen as ${alias[1]} but does not thread locale.value into it`);
            continue;
        }
        // the locale-bound file still compiles (these pages do not import the
        // composable, so section (c) does not cover them).
        const { descriptor, errors } = parse(src, { filename: r });
        assert.equal(errors.length, 0, `${r} parse errors: ${errors.map((e) => e.message).join('; ')}`);
        assert.doesNotThrow(() => compileScript(descriptor, { id: 'gapfmt' }), `${r} script compile failed`);
        if (descriptor.template) {
            const tpl = compileTemplate({ source: descriptor.template.content, filename: r, id: 'gapfmt' });
            assert.equal(tpl.errors.length, 0, `${r} template errors: ${tpl.errors.join('; ')}`);
        }
    }
    assert.deepEqual(offenders, [], 'money.js formatWhen used without the app locale:\n' + offenders.join('\n'));
});

// ---------------------------------------------------------------------------
// (c) every .vue importing the composable compiles
// ---------------------------------------------------------------------------
test('every .vue importing useLocaleFormat compiles with @vue/compiler-sfc', () => {
    const vues = walk(JS_ROOT).filter((p) => p.endsWith('.vue'));
    let checked = 0;
    for (const p of vues) {
        const src = fs.readFileSync(p, 'utf8');
        if (!/useLocaleFormat/.test(src)) continue;
        checked++;
        const r = rel(p).replace(/\\/g, '/');
        const { descriptor, errors } = parse(src, { filename: r });
        assert.equal(errors.length, 0, `${r} parse errors: ${errors.map((e) => e.message).join('; ')}`);
        const id = 'gapfmt';
        assert.doesNotThrow(() => compileScript(descriptor, { id }), `${r} script compile failed`);
        if (descriptor.template) {
            const t = compileTemplate({ source: descriptor.template.content, filename: r, id });
            assert.equal(t.errors.length, 0, `${r} template errors: ${t.errors.join('; ')}`);
        }
    }
    assert.ok(checked >= 90, `expected the composable wired into many pages, saw ${checked}`);
});
