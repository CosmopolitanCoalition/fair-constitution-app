// node --experimental-vm-modules --test tests/js/i18nNamedArgs.test.mjs
//
// Named parameters reach vue-i18n ONLY under options.named (2026-09-15, the
// blank counts on /system/translations). @intlify/core-base parseTranslateArgs:
// a string second argument is the inline default; a plain-object THIRD
// argument is merged into the translate options, so
//     t('k', '{n} things', { n: 5 })          renders "{n}" empty
//     t('k', '{n} things', { named: { n: 5 } }) renders "5 things"
// This pin walks every .vue/.js under resources/js and refuses the first form.

import assert from 'node:assert/strict';
import test from 'node:test';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = fileURLToPath(new URL('../../', import.meta.url));
const JS = path.join(ROOT, 'resources', 'js');
const OPTION_KEYS = new Set(['named', 'plural', 'list', 'locale', 'default', 'missingWarn', 'fallbackWarn', 'escapeParameter', 'resolvedMessage']);
const CALL = /(?<![\w$.])\$?t\(\s*(['"])([\w.\-]+)\1\s*,\s*/g;

function walk(dir, acc = []) {
    for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
        const p = path.join(dir, e.name);
        if (e.isDirectory()) {
            if (p.replace(/\\/g, '/').includes('/i18n/locales')) continue;
            walk(p, acc);
        } else if (/\.(vue|js)$/.test(e.name)) acc.push(p);
    }
    return acc;
}
const ws = (s, i) => { while (i < s.length && ' \n\t\r'.includes(s[i])) i++; return i; };
function str(s, i) {
    const q = s[i];
    if (!q || !`'"\``.includes(q)) return null;
    for (let j = i + 1; j < s.length; j++) {
        if (s[j] === '\\') { j++; continue; }
        if (s[j] === q) return j + 1;
    }
    return null;
}
function obj(s, i) {
    let depth = 0;
    for (let j = i; j < s.length; j++) {
        const c = s[j];
        if (`'"\``.includes(c)) { const e = str(s, j); if (e === null) return null; j = e - 1; continue; }
        if (c === '{') depth++;
        else if (c === '}' && --depth === 0) return j + 1;
    }
    return null;
}

function offenders(src) {
    const found = [];
    for (const m of src.matchAll(CALL)) {
        let i = ws(src, m.index + m[0].length);
        const e = str(src, i);
        if (e === null) continue;
        let k = ws(src, e);
        if (src[k] !== ',') continue;
        k = ws(src, k + 1);
        if (src[k] !== '{') continue;
        const oe = obj(src, k);
        if (oe === null) continue;
        const inner = src.slice(k + 1, oe - 1).trim();
        const first = inner.match(/^([A-Za-z_$][\w$]*)\s*:/);
        if (inner === '' || (first && OPTION_KEYS.has(first[1]))) continue;
        found.push(`${m[2]} (line ${src.slice(0, m.index).split('\n').length})`);
    }
    return found;
}

test('every t(key, default, {…}) passes its values under named', () => {
    const files = walk(JS);
    assert.ok(files.length > 100, `walks the source tree (${files.length} files)`);
    const bad = [];
    for (const f of files) {
        for (const o of offenders(fs.readFileSync(f, 'utf8'))) bad.push(`${path.relative(ROOT, f)}: ${o}`);
    }
    assert.deepEqual(bad, [], `values must sit under options.named:\n${bad.join('\n')}`);
});

test('the detector recognises the broken form and accepts the fixed one', () => {
    assert.deepEqual(offenders(`t('a.b', '{n} things', { n: 5 })`), ['a.b (line 1)']);
    assert.deepEqual(offenders(`t('a.b', '{n} things', { named: { n: 5 } })`), []);
    assert.deepEqual(offenders(`$t('a.b', 'plain')`), []);
    assert.deepEqual(offenders(`t('a.b', { n: 5 })`), [], 'a two-argument named call is fine');
});
