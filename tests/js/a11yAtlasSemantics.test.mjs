// node --experimental-vm-modules --test tests/js/a11yAtlasSemantics.test.mjs
//
// W-0234 pin. Atlas domain cards carry a real heading (h3 under the page h1/h2),
// the nodes table headers carry scope="col", and the interactive controls
// declare the 24px minimum target height. Atlas is compiled and mounted through
// a headless renderer so a template or script error fails the run.
import assert from 'node:assert/strict';
import { readFile as readFileP } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';
import { parse, compileScript } from '@vue/compiler-sfc';

function element(tag, text = '') {
    return Vue.markRaw({ tag, tagName: String(tag).toUpperCase(), text, children: [], parent: null, props: {}, listeners: {},
        addEventListener() {}, removeEventListener() {}, setAttribute(n, v) { this.props[n] = v; }, removeAttribute(n) { delete this.props[n]; } });
}
const renderer = Vue.createRenderer({
    createElement: element, createText: t => element('#text', t), createComment: t => element('#comment', t),
    insert(el, parent) { el.parent = parent; parent.children.push(el); },
    remove(el) { if (el.parent) el.parent.children.splice(el.parent.children.indexOf(el), 1); },
    patchProp(el, key, old, value) { el.props[key] = value; },
    setText: (el, t) => { el.text = t; }, setElementText: (el, t) => { el.text = t; el.children = []; },
    parentNode: el => el.parent, nextSibling: el => el.parent?.children[el.parent.children.indexOf(el) + 1] ?? null,
});
const nodesOf = root => [root, ...root.children.flatMap(nodesOf)];
const textOf = el => (el.text || el.children.map(textOf).join('')).trim();

test('W-0234 Atlas domain cards use h3 headings and the nodes table has th scope', async (t) => {
    const source = await readFileP(new URL('../../resources/js/Pages/System/Atlas.vue', import.meta.url), 'utf8');
    const { descriptor } = parse(source);
    const context = vm.createContext({ console });
    const mod = new vm.SourceTextModule(compileScript(descriptor, { id: 'atlas', inlineTemplate: true }).content, { context });
    const plain = { setup: (_, { slots }) => () => Vue.h('stub', slots.default?.()) };
    await mod.link(name => {
        const values = name === 'vue' ? Vue
            : name === '@inertiajs/vue3' ? { router: { visit() {} } }
            : { default: plain };
        return new vm.SyntheticModule(Object.keys(values), function () { for (const [k, v] of Object.entries(values)) this.setExport(k, v); }, { context });
    });
    await mod.evaluate();
    const rootEl = element('root');
    const app = renderer.createApp(mod.namespace.default, {
        surface: {}, generatedAt: '2026-09-14',
        directory: [{ label: 'Node A', name: 'node-a', place: 'Earth', operator: 'Op', operatorHref: '/o', status: 'alive', role: 'authoritative', residents: 5, uptimePct: 99, syncSeq: 10, self: false }],
    });
    app.mount(rootEl); t.after(() => app.unmount()); await Vue.nextTick();
    const all = nodesOf(rootEl);

    // Nine domain cards, each titled by an <h3 class="eyebrow"> with text.
    const domainHeadings = all.filter(el => el.tag === 'h3' && el.props.class === 'eyebrow');
    assert.equal(domainHeadings.length, 9, 'nine domain-card h3 headings');
    for (const h of domainHeadings) assert.ok(textOf(h).length > 0, 'domain heading has text');

    // Every table header carries scope="col".
    const ths = all.filter(el => el.tag === 'th');
    assert.ok(ths.length >= 8, 'nodes table headers rendered');
    for (const th of ths) assert.equal(th.props.scope, 'col', `th "${textOf(th)}" has scope=col`);
});

test('W-0234 Atlas interactive controls declare the 24px minimum target', async () => {
    const v2 = await readFileP(new URL('../../resources/css/cga/components-v2.css', import.meta.url), 'utf8');
    const base = await readFileP(new URL('../../resources/css/cga/components.css', import.meta.url), 'utf8');
    const toggle = v2.match(/\.atlas-toggle \{[^}]*\}/s);
    assert.ok(toggle && /min-block-size:\s*1\.5rem/.test(toggle[0]), '.atlas-toggle min-block-size 1.5rem');
    const sm = base.match(/\.btn--sm \{[^}]*\}/);
    assert.ok(sm && /min-block-size:\s*1\.5rem/.test(sm[0]), '.btn--sm min-block-size 1.5rem');
});

test('W-0234 Atlas OFF map-layer toggles use a colour cue, not opacity dimming', async () => {
    // opacity on the whole control composites text and background toward the
    // parent, so axe reads the OFF toggles as low contrast on /system/atlas.
    // The off state now uses the subtle token (>= 4.5:1 on surface-2, pinned
    // numerically in a11yContrastFixes) plus the line-through cue. No opacity.
    const v2 = await readFileP(new URL('../../resources/css/cga/components-v2.css', import.meta.url), 'utf8');
    const off = v2.match(/\.atlas-toggle:not\(\.is-on\) \{[^}]*\}/);
    assert.ok(off, 'off-toggle rule present');
    assert.doesNotMatch(off[0], /opacity\s*:/, 'off toggle must not dim with opacity');
    assert.match(off[0], /color:\s*var\(--gov-fg-subtle\)/, 'off toggle uses the subtle token');
    assert.match(off[0], /text-decoration:\s*line-through/, 'off cue kept as line-through');
});
