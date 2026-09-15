// node --experimental-vm-modules --test tests/js/i18nRawScan.test.mjs
//
// REPO-WIDE RAW-STRING PIN (gap-wiring-locale, gap 5).
//
// Built from the 2026-09-15 skeptic scanner (scratchpad gaps/skeptic/scan.js,
// attrs.js, classify.js, fourfilter.js). Walks EVERY .vue under resources/js
// with the installed @vue/compiler-sfc / @vue/compiler-dom and asserts:
//
//   (a) no template TEXT node carrying letters outside t(), of ANY length; and
//   (b) no STATIC user-text attribute (the fixed list below) carrying letters
//       outside t().
//
// A t()-routed string is an INTERPOLATION ({{ t('...') }}) or a bound attribute
// (:title="t('...')") — a DIRECTIVE, never a static TEXT node or a static
// ATTRIBUTE — so anything this scan reaches is un-routed by construction.
//
// Allowances (legitimate non-translatable text):
//   - a node, or any ancestor, carrying data-no-i18n;
//   - pure citation text (Art. / § / F- / CLK- / R- / WF- / I- tokens only);
//   - punctuation, numbers, single glyphs (letters-only length <= 1);
//   - a single machine token as an attribute value (snake/kebab/one lower word
//     — icon glyph names, enum values, not prose);
//   - the dev-bar / dev-ops surfaces the vue rules exempt (dev-only chrome and
//     the geodata / sim ops tooling — never player-facing product copy).
//
// DB-free. Prints every offender file:line before asserting, so a failure is a
// worklist. Expected GREEN once every gap lane has merged; residue in files
// this lane does not own is the desk's, listed here for it.
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { parse as parseSfc } from '@vue/compiler-sfc';
import * as CompilerDOM from '@vue/compiler-dom';

const jsRoot = fileURLToPath(new URL('../../resources/js', import.meta.url));

const parseTemplate = typeof CompilerDOM.parse === 'function'
    ? (src) => CompilerDOM.parse(src)
    : (src) => CompilerDOM.baseParse(src, CompilerDOM.parserOptions ?? {});

const T = CompilerDOM.NodeTypes ?? {};
const T_ELEMENT = T.ELEMENT ?? 1;
const T_TEXT = T.TEXT ?? 2;
const T_ATTRIBUTE = T.ATTRIBUTE ?? 6;
const T_DIRECTIVE = T.DIRECTIVE ?? 7;

// The static user-text attributes (exactly the gap-5 list).
const USERTEXT_ATTRS = new Set([
    'title', 'placeholder', 'alt', 'aria-label', 'aria-description', 'label',
    'help', 'hint', 'description', 'caption', 'eyebrow', 'submit-label',
    'processing-label', 'quota-title', 'empty-text',
]);

// Dev-bar / dev-ops surfaces the vue rules exempt: dev-only chrome and the
// geodata / simulation ops tooling, never player-facing product copy.
const DEV_EXEMPT = [
    /(^|\/)Pages\/Dev\//,
    /(^|\/)Pages\/Geodata\//,
    /(^|\/)Components\/Geodata\//,
    /(^|\/)Components\/Shell\/DevBar\.vue$/,
    /(^|\/)Components\/Shell\/DevPersonaSwitcher\.vue$/,
    /(^|\/)Components\/ShellV2\/Dev[A-Za-z]*\.vue$/,
    /(^|\/)Demo\/SimConsole/,
];

const HAS_LETTER = /[A-Za-z]/;

// Strip citation tokens, then non-letters; a residue of no letters = citation.
function isCitationOnly(raw) {
    const stripped = String(raw)
        .replace(/Art\.?\s*[IVXLCDM]+/gi, '')
        .replace(/Article\s*[IVXLCDM]+/gi, '')
        .replace(/§\s*\d+/g, '')
        .replace(/\bF-[A-Z]{2,4}-\d+[A-Za-z0-9-]*/g, '')
        .replace(/\bCLK-\d+/g, '')
        .replace(/\bR-\d+/g, '')
        .replace(/\bWF-[A-Z]{2,4}-\d+[A-Za-z0-9-]*/g, '')
        .replace(/\bI-[A-Z]{2,4}-\d+[A-Za-z0-9-]*/g, '')
        .replace(/[·§|]/g, '');
    return !HAS_LETTER.test(stripped);
}

// letters-only run of the text.
function lettersOnly(raw) {
    return String(raw).replace(/[^A-Za-z]/g, '');
}

// A single machine token as an attribute value: one lower word, snake or kebab,
// no spaces — an icon glyph name, an enum value, not prose.
function isMachineToken(raw) {
    const t = String(raw).trim();
    if (/\s/.test(t)) return false;
    return /^[a-z][a-z0-9]*([_-][a-z0-9]+)*$/.test(t);
}

// TEXT node allowed to carry letters?
function textAllowed(raw) {
    if (!HAS_LETTER.test(raw)) return true;
    if (lettersOnly(raw).length <= 1) return true;   // single glyph
    if (isCitationOnly(raw)) return true;
    return false;
}

// Static user-text attribute value allowed to carry letters?
function attrAllowed(raw) {
    if (!HAS_LETTER.test(raw)) return true;
    if (lettersOnly(raw).length <= 1) return true;
    if (isCitationOnly(raw)) return true;
    if (isMachineToken(raw)) return true;
    return false;
}

function vueFiles() {
    return readdirSync(jsRoot, { recursive: true, encoding: 'utf8' })
        .filter((rel) => rel.endsWith('.vue'))
        .map((rel) => rel.split(path.sep).join('/'))
        .sort();
}

function scanFile(rel) {
    if (DEV_EXEMPT.some((re) => re.test(rel))) return [];
    const file = path.join(jsRoot, rel.split('/').join(path.sep));
    const source = readFileSync(file, 'utf8');
    let descriptor;
    try {
        ({ descriptor } = parseSfc(source, { filename: rel }));
    } catch {
        return [];
    }
    if (!descriptor.template) return [];
    const tplStartOffset = descriptor.template.loc.start.offset;
    let ast;
    try {
        ast = parseTemplate(descriptor.template.content);
    } catch {
        return [];
    }
    const lineOf = (node) => {
        const abs = tplStartOffset + (node.loc?.start?.offset ?? 0);
        let n = 1;
        for (let i = 0; i < abs && i < source.length; i += 1) if (source[i] === '\n') n += 1;
        return n;
    };
    const hasNoI18n = (node) =>
        !!node.props?.some((p) => p.type === T_ATTRIBUTE && p.name === 'data-no-i18n');

    const offenders = [];
    (function walk(node, suppressed) {
        if (node.type === T_ELEMENT) {
            const suppress = suppressed || hasNoI18n(node);
            if (!suppress) {
                for (const p of node.props ?? []) {
                    if (p.type !== T_ATTRIBUTE) continue;      // directives (:x, v-x) are bound / t()-able
                    if (!USERTEXT_ATTRS.has(p.name)) continue;
                    const val = p.value?.content ?? '';
                    if (!attrAllowed(val)) {
                        offenders.push({ rel, line: p.loc?.start?.line ?? lineOf(node), kind: `attr:${p.name}`, sample: val.trim().replace(/\s+/g, ' ').slice(0, 80) });
                    }
                }
            }
            for (const c of node.children ?? []) walk(c, suppress);
            return;
        }
        if (node.type === T_TEXT) {
            if (!suppressed && !textAllowed(node.content ?? '')) {
                offenders.push({ rel, line: lineOf(node), kind: 'text', sample: String(node.content).trim().replace(/\s+/g, ' ').slice(0, 80) });
            }
            return;
        }
        // ROOT / IF / FOR containers: descend, carrying suppression.
        for (const c of node.children ?? []) walk(c, suppressed);
        for (const b of node.branches ?? []) walk(b, suppressed);
    })(ast, false);
    return offenders;
}

test('raw-string scan — no un-t() template text or static user-text attribute', () => {
    const files = vueFiles();
    const all = [];
    for (const rel of files) all.push(...scanFile(rel));
    all.sort((a, b) => (a.rel === b.rel ? a.line - b.line : a.rel.localeCompare(b.rel)));
    console.log(`  scanned ${files.length} .vue files; ${all.length} offenders`);
    for (const o of all) console.log(`  ${o.rel}:${o.line} [${o.kind}] ${o.sample}`);
    assert.equal(all.length, 0, `${all.length} raw user-facing string(s) outside t() — see the list above`);
});
