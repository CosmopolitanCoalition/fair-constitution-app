// node --experimental-vm-modules --test tests/js/lessonVideo.test.mjs
//
// LE-3 lesson-video association. Two halves:
//   1. Data: every surface carries a `video` (its own or the demo default),
//      every id is a real catalog id, the {surfaceId: videoId} map agrees.
//   2. Lesson.vue mounts the player with the resolved id, and shows the demo
//      note only when the film is the default fallback.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';
import { parse, compileScript } from '@vue/compiler-sfc';
import { EDUCATION_BY_SURFACE } from '../../resources/js/registry/education.js';

const rootPath = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = name => readFileSync(path.join(rootPath, name), 'utf8');

// The catalog ids, read straight from the generated PHP registry.
const catalogIds = new Set([...read('config/cga/media.php').matchAll(/'id'\s*=>\s*'([^']+)'/g)].map(m => m[1]));
const videosJson = JSON.parse(read('resources/js/registry/education.videos.json'));

// The seven surfaces authored with their own film in K2_CONTENT_SURFACES.md.
const OVERRIDES = {
    'legislature/legislature-home': 'v-legislatures1',
    'legislature/committees': 'v-committees',
    'judiciary/judiciary-home': 'v-judiciaries',
    'jurisdictions/viewer': 'v-jurisdictions1',
    'elections/detail': 'v-proportional-ranked-choice-voting',
    'education/material-manager': 'v-education',
    'civic/public-square': 'v-community',
};

test('every surface carries a catalog-backed video (its own or the demo default)', () => {
    const entries = Object.entries(EDUCATION_BY_SURFACE);
    assert.ok(entries.length > 0, 'the registry must have surfaces');
    for (const [id, entry] of entries) {
        assert.ok(entry.video, `surface ${id} has no video`);
        assert.ok(catalogIds.has(entry.video.id), `surface ${id} references unknown video ${entry.video.id}`);
        assert.ok(['surface', 'default'].includes(entry.video.source), `surface ${id} has bad source ${entry.video.source}`);
    }
});

test('exactly one demo default film backs every non-override surface', () => {
    const defaults = new Set(Object.values(EDUCATION_BY_SURFACE).filter(e => e.video.source === 'default').map(e => e.video.id));
    assert.equal(defaults.size, 1, `expected one default film, saw ${[...defaults].join(', ')}`);
});

test('the seven authored overrides carry their own film, not the default', () => {
    for (const [id, expected] of Object.entries(OVERRIDES)) {
        const video = EDUCATION_BY_SURFACE[id]?.video;
        assert.ok(video, `override surface ${id} missing from registry`);
        assert.equal(video.source, 'surface', `${id} should be a per-surface film`);
        assert.equal(video.id, expected, `${id} should map to ${expected}`);
    }
});

test('the PHP-readable map agrees with the registry and the catalog', () => {
    assert.deepEqual(
        Object.keys(videosJson).sort(),
        Object.keys(EDUCATION_BY_SURFACE).sort(),
        'every registry surface must appear in education.videos.json',
    );
    for (const [id, videoId] of Object.entries(videosJson)) {
        assert.equal(videoId, EDUCATION_BY_SURFACE[id].video.id, `map/registry disagree for ${id}`);
        assert.ok(catalogIds.has(videoId), `map references unknown video ${videoId} for ${id}`);
    }
});

// ── Lesson.vue mount ────────────────────────────────────────────────────────
// A text-collecting renderer over compiled Lesson.vue. The heavy shell/UI
// components and the real player are stubbed; the lessonContent composable and
// the education registry load from source so the demo-note logic is real.
const dict = { 'c_learn.ui.lesson_video_demo_note': 'This lesson uses the demo recording; a lesson-specific video is planned.' };

async function mountLesson(t, { surfaceId, video, videoBaseUrl = null }) {
    const nodes = el => [el, ...el.children.flatMap(nodes)];
    const text = el => el.tag === '#comment' ? '' : [el.text, ...el.children.map(text)].join(' ');
    const matches = (el, selector) => selector.startsWith('.') ? String(el.props.class ?? '').split(' ').includes(selector.slice(1)) : el.tag === selector;
    const element = (tag, value = '') => Vue.markRaw({
        tag, text: value, props: {}, children: [], parent: null,
        removeAttribute(key) { delete this.props[key]; },
    });
    const root = element('root');
    const renderer = Vue.createRenderer({
        createElement: element, createText: t => element('#text', t), createComment: t => element('#comment', t),
        insert(el, parent, anchor) {
            if (el.parent) { const at = el.parent.children.indexOf(el); if (at >= 0) el.parent.children.splice(at, 1); }
            el.parent = parent;
            const at = anchor ? parent.children.indexOf(anchor) : -1;
            if (at < 0) parent.children.push(el); else parent.children.splice(at, 0, el);
        },
        remove(el) { const s = el.parent?.children; if (s?.includes(el)) s.splice(s.indexOf(el), 1); el.parent = null; },
        patchProp(el, key, prev, value) { el.props[key] = value; },
        setText: (el, t) => { el.text = t; },
        setElementText: (el, t) => { el.text = t; el.children = []; },
        parentNode: el => el.parent,
        nextSibling: el => el.parent?.children[el.parent.children.indexOf(el) + 1] ?? null,
    });
    const context = vm.createContext({ console });
    const slotComp = { setup: (_, { slots }) => () => Vue.h('div', slots.default?.() ?? []) };
    const player = { props: ['video', 'baseUrl', 'initialLocale'],
        setup: props => () => Vue.h('div', { class: 'player-stub', 'data-video-id': props.video?.id ?? '', 'data-base-url': props.baseUrl ?? '' }) };
    const Link = { props: ['href'], setup: (props, { slots }) => () => Vue.h('a', { href: props.href }, slots.default?.()) };
    const imports = {
        vue: Vue,
        '@inertiajs/vue3': { Link, router: { post() {} } },
        'vue-i18n': { useI18n: () => ({ t: (key, fallback) => dict[key] ?? fallback ?? key, locale: Vue.ref('en') }) },
    };
    const cache = new Map();
    const STUB = /\/(AppShellV2|PageScaffold|Banner|Card|Icon|StatusBadge)\.vue$/;
    function moduleFor(specifier, importer = rootPath) {
        if (imports[specifier]) {
            if (!cache.has(specifier)) {
                const values = imports[specifier];
                cache.set(specifier, new vm.SyntheticModule(Object.keys(values), function () {
                    for (const [k, v] of Object.entries(values)) this.setExport(k, v);
                }, { context, identifier: specifier }));
            }
            return cache.get(specifier);
        }
        const filename = specifier.startsWith('@/') ? path.join(rootPath, 'resources/js', specifier.slice(2)) : path.resolve(path.dirname(importer), specifier);
        if (cache.has(filename)) return cache.get(filename);
        const norm = filename.replaceAll('\\', '/');
        if (/\/MultiTrackVideoPlayer\.vue$/.test(norm)) {
            const mod = new vm.SyntheticModule(['default'], function () { this.setExport('default', player); }, { context, identifier: filename });
            cache.set(filename, mod); return mod;
        }
        if (STUB.test(norm)) {
            const mod = new vm.SyntheticModule(['default'], function () { this.setExport('default', slotComp); }, { context, identifier: filename });
            cache.set(filename, mod); return mod;
        }
        let source = readFileSync(filename, 'utf8');
        if (filename.endsWith('.vue')) {
            const { descriptor } = parse(source);
            source = compileScript(descriptor, { id: filename, inlineTemplate: true, templateOptions: { compilerOptions: { hoistStatic: false } } }).content;
        }
        const module = new vm.SourceTextModule(source, { context, identifier: filename });
        cache.set(filename, module);
        return module;
    }
    const entry = new vm.SourceTextModule(`import Lesson from '@/Pages/Learn/Lesson.vue'; export { Lesson };`, { context });
    await entry.link((name, from) => moduleFor(name, from.identifier));
    await entry.evaluate();
    const { Lesson } = entry.namespace;
    const warnings = [];
    const app = renderer.createApp(Lesson, {
        surface: { id: 'learn/lesson' },
        track: { key: 't', title: 'Track' },
        modules: [], questions: [],
        module: { key: 'm', title: 'Module', surface_id: surfaceId, minutes: 5, completed: false },
        video, videoBaseUrl, quiz: null, auth: {},
    });
    app.config.warnHandler = m => warnings.push(m);
    app.mount(root);
    await Vue.nextTick();
    t.after(() => app.unmount());
    return { root, warnings, nodes: () => nodes(root), text: () => text(root),
        one: selector => nodes(root).find(el => matches(el, selector)) };
}

test('Lesson mounts the player with the resolved film and shows the demo note for defaults', async t => {
    const f = await mountLesson(t, { surfaceId: 'auth/register', video: { id: 'v-committees' }, videoBaseUrl: null });
    const stub = f.one('.player-stub');
    assert.ok(stub, 'the player must mount when a video is present');
    assert.equal(stub.props['data-video-id'], 'v-committees');
    assert.match(f.text(), /demo recording/);
    assert.deepEqual(f.warnings, []);
});

test('Lesson hides the demo note for a surface-specific film', async t => {
    const f = await mountLesson(t, { surfaceId: 'legislature/committees', video: { id: 'v-committees' }, videoBaseUrl: null });
    assert.ok(f.one('.player-stub'), 'the player must still mount');
    assert.doesNotMatch(f.text(), /demo recording/);
    assert.deepEqual(f.warnings, []);
});

test('Lesson renders no player when the surface has no film', async t => {
    const f = await mountLesson(t, { surfaceId: 'legislature/committees', video: null });
    assert.equal(f.one('.player-stub'), undefined);
    assert.doesNotMatch(f.text(), /demo recording/);
    assert.deepEqual(f.warnings, []);
});
