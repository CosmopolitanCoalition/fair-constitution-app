// node --experimental-vm-modules --test tests/js/videoManager.test.mjs
//
// W-0449 video manager page pin (namespace c_front.video_manager). Follows the
// i18nWiring pin pattern (tests/js/i18nWiring_i18n_setup.test.mjs), scoped to
// the one page Pages/Learn/VideoManager.vue. DB-free: reads the .vue source and
// the en catalog directly. It asserts:
//
//   1. INSTRUMENT self-check — the helpers detect keys and accessible names.
//   2. COMPILE GATE — the page compiles with @vue/compiler-sfc (script +
//      scoped template). A worktree cannot reach Vite, so this is the gate.
//   3. ADOPTION — the page imports useI18n.
//   4. NO RAW KEY LEAK — every c_front.<key> the page references resolves in
//      resources/js/i18n/locales/en/c_front.json.
//   5. CATALOG SHAPE — every video_manager.* value is a non-empty string.
//   6. NO RAW ENGLISH — no 4+ word sentence in a template text node outside t().
//   7. A11Y — every button carries a name, and every form control is labelled
//      (wrapping <label>, a <label for=id>, or an aria-label / :aria-label).
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';
import { parse as parseTemplateDom } from '@vue/compiler-dom';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const jsRoot = path.join(root, 'resources/js');
const PAGE_REL = ['Pages', 'Learn', 'VideoManager.vue'];
const pageAbs = path.join(jsRoot, ...PAGE_REL);
const pageRel = PAGE_REL.join('/');
const catalogPath = path.join(jsRoot, 'i18n/locales/en/c_front.json');
const NS = 'c_front';

const source = () => readFileSync(pageAbs, 'utf8');

function usesI18n(src) {
    return /useI18n/.test(src);
}

// Every t('c_front.<key>' ...) / t("c_front.<key>" ...) literal the page uses.
// A key built by concatenation is dynamic (the char after the quote is '+') and
// skipped; this page emits none.
function referencedKeys(src) {
    const out = [];
    const re = /\bt\(\s*(['"])((?:c_front)\.[^'"]+)\1\s*(.?)/g;
    let m;
    while ((m = re.exec(src)) !== null) {
        if (m[3] === '+') continue;
        out.push(m[2]);
    }
    return out;
}

function catalog() {
    return JSON.parse(readFileSync(catalogPath, 'utf8'));
}

// Raw-English heuristic over template TEXT NODES only (same as i18nWiring).
const CITATION = /^(?:Art\.|§|F-[A-Z]|CLK-|R-\d|WF-|ESM-|WCAG)/;
function textNodes(node, out) {
    if (!node) return out;
    if (node.type === 2 && typeof node.content === 'string') out.push(node.content);
    for (const child of node.children || []) textNodes(child, out);
    return out;
}
function rawEnglish(templateSrc) {
    const ast = parseTemplateDom(templateSrc, { comments: false });
    const flagged = [];
    for (const raw of textNodes(ast, [])) {
        const text = raw.replace(/\s+/g, ' ').trim();
        if (!text) continue;
        if (CITATION.test(text)) continue;
        const words = text.match(/[A-Za-z]{2,}/g) || [];
        if (words.length >= 4) flagged.push(text.slice(0, 80));
    }
    return flagged;
}

// ── accessible-name helpers (a compact form of a11yStaticScan) ────────────────
const T_ELEMENT = 1;
const T_TEXT = 2;
const T_INTERPOLATION = 5;
const T_ATTRIBUTE = 6;
const T_DIRECTIVE = 7;

function staticAttr(node, name) {
    const p = node.props?.find((p) => p.type === T_ATTRIBUTE && p.name === name);
    return p ? (p.value?.content ?? '') : undefined;
}
function boundAttr(node, name) {
    const p = node.props?.find((p) => p.type === T_DIRECTIVE && p.name === 'bind' && p.arg && p.arg.content === name);
    return p ? (p.exp?.content ?? '') : undefined;
}
function namePresent(node, name) {
    const s = staticAttr(node, name);
    if (s !== undefined && s.trim() !== '') return true;
    return boundAttr(node, name) !== undefined;
}
function hasTextName(node) {
    for (const c of node.children ?? []) {
        if (c.type === T_TEXT && c.content && c.content.trim() !== '') return true;
        if (c.type === T_INTERPOLATION) return true;
        if (c.type === T_ELEMENT && hasTextName(c)) return true;
    }
    return false;
}

function a11yFindings(templateSrc) {
    const ast = parseTemplateDom(templateSrc);

    // Every <label>'s association target (static and dynamic).
    const labelForStatic = new Set();
    let labelForDynamic = false;
    (function collect(node) {
        if (node.type === T_ELEMENT && node.tag === 'label') {
            const f = staticAttr(node, 'for');
            if (f !== undefined) labelForStatic.add(f);
            if (boundAttr(node, 'for') !== undefined) labelForDynamic = true;
        }
        for (const c of node.children ?? []) collect(c);
    })(ast);

    const findings = [];
    (function walk(node, inLabel) {
        if (node.type === T_ELEMENT) {
            const tag = node.tag;
            if (tag === 'button' && !(namePresent(node, 'aria-label') || namePresent(node, 'title') || hasTextName(node))) {
                findings.push('button with no accessible name');
            }
            if (tag === 'input' || tag === 'select' || tag === 'textarea') {
                const type = (staticAttr(node, 'type') ?? '').toLowerCase();
                const labelled =
                    type === 'hidden' ||
                    inLabel ||
                    namePresent(node, 'aria-label') ||
                    (staticAttr(node, 'id') !== undefined && labelForStatic.has(staticAttr(node, 'id'))) ||
                    (boundAttr(node, 'id') !== undefined && labelForDynamic);
                if (!labelled) findings.push(`<${tag}> with no label`);
            }
            const childInLabel = inLabel || tag === 'label';
            for (const c of node.children ?? []) walk(c, childInLabel);
            return;
        }
        for (const c of node.children ?? []) walk(c, inLabel);
    })(ast, false);

    return findings;
}

// ── SECTION 1 — instrument self-check.
test('instrument — helpers detect keys, raw text and accessible names', () => {
    assert.deepEqual(
        referencedKeys("t('c_front.video_manager.page_title', 'Manage videos') and t('other.x')"),
        ['c_front.video_manager.page_title'],
    );
    assert.deepEqual(referencedKeys("t('c_front.x.' + tail, {})"), []);
    assert.deepEqual(rawEnglish('<p>{{ t(\'c_front.x.y\', \'Four real English words here\') }}</p>'), []);
    assert.deepEqual(rawEnglish('<p>Four real English words here</p>'), ['Four real English words here']);
    assert.deepEqual(a11yFindings('<button>Save</button>'), []);
    assert.deepEqual(a11yFindings('<button><span>x</span></button>'), []);
    assert.deepEqual(a11yFindings('<input type="text" />'), ['<input> with no label']);
    assert.deepEqual(a11yFindings('<label for="a">A</label><input id="a" />'), []);
    assert.deepEqual(a11yFindings('<label><input type="checkbox" /> A</label>'), []);
    assert.ok(existsSync(pageAbs), `missing page ${pageRel}`);
});

// ── SECTION 2 — compile gate.
test('compile gate — the page compiles with @vue/compiler-sfc', () => {
    const src = source();
    const { descriptor, errors } = parse(src, { filename: pageAbs });
    assert.equal((errors ?? []).length, 0, `SFC parse errors: ${(errors ?? []).map((e) => e.message).join('; ')}`);
    compileScript(descriptor, { id: pageRel });
    assert.ok(descriptor.template, 'the page has a template');
    const scoped = (descriptor.styles || []).some((s) => s.scoped);
    const r = compileTemplate({ source: descriptor.template.content, filename: pageAbs, id: pageRel, scoped });
    assert.equal((r.errors ?? []).length, 0, `template errors: ${(r.errors ?? []).map((e) => e.message || e).join('; ')}`);
});

// ── SECTION 3 — adoption.
test('adoption — the page imports useI18n', () => {
    assert.equal(usesI18n(source()), true, 'VideoManager.vue must import useI18n');
});

// ── SECTION 4 — no raw key leak.
test('no raw key leak — every referenced c_front key resolves in the en catalog', () => {
    const cat = catalog();
    const keys = new Set(Object.keys(cat));
    const refs = referencedKeys(source());
    assert.ok(refs.length >= 30, `expected many referenced keys, saw ${refs.length}`);
    const unresolved = [];
    let manager = 0;
    for (const full of refs) {
        const rest = full.slice(full.indexOf('.') + 1);
        if (rest.startsWith('video_manager.')) manager += 1;
        if (full.slice(0, full.indexOf('.')) !== NS) { unresolved.push(`${full} not under ${NS}`); continue; }
        if (!keys.has(rest)) unresolved.push(`${full} absent from c_front.json`);
    }
    assert.ok(manager >= 30, `expected the video_manager.* keys, saw ${manager}`);
    for (const u of unresolved) console.log(`  ${u}`);
    assert.equal(unresolved.length, 0, `referenced keys must resolve: ${unresolved.join(' | ')}`);
});

// ── SECTION 5 — catalog shape.
test('catalog shape — every video_manager.* value is a non-empty string', () => {
    const cat = catalog();
    const bad = [];
    for (const [k, v] of Object.entries(cat)) {
        if (!k.startsWith('video_manager.')) continue;
        if (typeof v !== 'string' || v.length === 0) bad.push(k);
    }
    assert.ok(Object.keys(cat).some((k) => k.startsWith('video_manager.')), 'the catalog has video_manager.* keys');
    assert.deepEqual(bad, [], `every value must be a non-empty string: ${bad.join(', ')}`);
});

// ── SECTION 6 — no raw English left in a template text node.
test('no raw English — no 4+ word sentence outside t()', () => {
    const { descriptor } = parse(source(), { filename: pageAbs });
    const hits = rawEnglish(descriptor.template.content);
    for (const h of hits) console.log(`  ${h}`);
    assert.deepEqual(hits, [], `raw English must be wired: ${hits.join(' | ')}`);
});

// ── SECTION 7 — accessible names on every control.
test('a11y — every button is named and every form control is labelled', () => {
    const { descriptor } = parse(source(), { filename: pageAbs });
    const findings = a11yFindings(descriptor.template.content);
    for (const f of findings) console.log(`  ${f}`);
    assert.deepEqual(findings, [], `unnamed controls: ${findings.join(' | ')}`);
});
