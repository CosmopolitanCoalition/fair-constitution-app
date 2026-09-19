import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';
import { renderToString } from '@vue/server-renderer';
import { parse, compileScript } from '@vue/compiler-sfc';
import * as activity from '../../resources/js/lib/simWorkerActivity.js';

const base = new URL('../../resources/js/', import.meta.url);
const setupCatalogue = JSON.parse(await readFile(new URL('i18n/locales/en/c_setup.json', base), 'utf8'));
const operatorCatalogue = JSON.parse(await readFile(new URL('i18n/locales/en/c_operator_pages.json', base), 'utf8'));
const t = (key, arg, options) => {
    const catalogue = key.startsWith('c_setup.') ? setupCatalogue : operatorCatalogue;
    const named = typeof arg === 'object' ? arg : options?.named ?? {};
    const text = catalogue[key.replace(/^c_(setup|operator_pages)\./, '')] ?? (typeof arg === 'string' ? arg : key);
    return text.replace(/\{(\w+)\}/g, (match, k) => named[k] ?? match);
};

test('known acquisition and waiting states are distinct from execution', () => {
    assert.equal(activity.workerBusy({ activity: 'acquiring' }), true);
    assert.equal(activity.workerBusy({ activity: 'waiting' }), false);
    assert.equal(activity.workerActivity({ activity: 'executing', claim_type: 'election_scope' }), 'executing');
    assert.equal(activity.activityLabel({ activity: 'acquiring' }, t), 'Acquiring work');
    assert.equal(activity.activitySeconds({ activity: 'acquiring', activity_secs: 4 }), 4);
});

test('legacy workers with no claim report unknown rather than idle', () => {
    assert.equal(activity.workerActivity({ claim_type: null }), 'unknown');
    assert.equal(activity.activityLabel({ claim_type: null }, t), 'Activity not reported');
    assert.equal(activity.activitySeconds({ claim_type: null }), null);
    assert.equal(activity.workerActivity({ claim_type: 'cohort_scope' }), 'executing');
    assert.equal(activity.activitySeconds({ claim_type: 'cohort_scope', claim_secs: 8 }), 8);
});

const rows = [
    { part: 'lane.claim_next', recent_count: 10, recent_total_us: 2_730_000, window_seconds: 60, sampled_at: '2026-09-19T20:00:00Z' },
    { part: 'stage.election_scope', recent_count: 9, recent_total_us: 90_000, window_seconds: 60 },
    { part: 'stage.cohort_scope', recent_count: 1, recent_total_us: 100_000, window_seconds: 60 },
    { part: 'election.field', recent_count: 9, recent_total_us: 80_000, window_seconds: 60 },
    { part: 'lane.between_claims', recent_count: 10, recent_total_us: 30_000, window_seconds: 60 },
];

test('recent cards weight stage averages by counts and exclude nested stage timers', () => {
    const cards = activity.recentTimingCards(rows);
    assert.deepEqual(cards.map(card => card.avg_ms), [273, 19, 3]);
    assert.deepEqual(cards.map(card => card.count), [10, 10, 10]);
});

test('first samples and zero new work never render a zero-millisecond average', () => {
    const cards = activity.recentTimingCards([
        { part: 'lane.claim_next', recent_count: null, recent_total_us: null, window_seconds: null },
        { part: 'stage.election_scope', recent_count: 0, recent_total_us: 0, window_seconds: 60 },
    ]);
    assert.deepEqual(cards.map(card => card.avg_ms), [null, null, null]);
    assert.equal(cards[0].window_seconds, null);
    assert.equal(cards[1].window_seconds, 60);
});

async function render(relative, props) {
    const context = vm.createContext({ URLSearchParams, console, Date, setInterval: () => 0, clearInterval: () => {} });
    const source = await readFile(new URL(relative, base), 'utf8');
    const { descriptor, errors } = parse(source);
    assert.deepEqual(errors, []);
    const compiled = compileScript(descriptor, { id: relative, inlineTemplate: true });
    const mod = new vm.SourceTextModule(compiled.content, { context });
    const stub = { setup: (_, { slots }) => () => Vue.h('div', slots.default?.()) };
    await mod.link(async name => {
        const values = name === 'vue' ? Vue
            : name === 'vue-i18n' ? { useI18n: () => ({ t }) }
            : name.endsWith('/simWorkerActivity') ? activity
            : name.endsWith('/useLocaleFormat') ? { useLocaleFormat: () => ({ number: n => String(n), time: n => String(n) }) }
            : name === '@inertiajs/vue3' ? { Head: stub, router: { visit() {} } }
            : name.endsWith('/csrf') ? { csrfFetch: async () => ({ ok: true, json: async () => ({}) }) }
            : { default: stub };
        return new vm.SyntheticModule(Object.keys(values), function () {
            for (const [key, value] of Object.entries(values)) this.setExport(key, value);
        }, { context });
    });
    await mod.evaluate();
    return renderToString(Vue.createSSRApp(mod.namespace.default, props));
}

test('recent timing summary renders measured values, window and timestamp', async () => {
    const html = await render('Components/Progress/SimTimingSummary.vue', { timings: rows });
    assert.match(html, /273 ms/);
    assert.match(html, /19 ms/);
    assert.match(html, /3 ms/);
    assert.match(html, /10 samples over 60s/);
    assert.match(html, /2026-09-19T20:00:00Z/);
});

test('an empty timing sample tells the viewer it is still collecting measurements', async () => {
    const html = await render('Components/Progress/SimTimingSummary.vue', { timings: [] });
    assert.match(html, /Collecting a recent sample/);
    assert.doesNotMatch(html, /0 ms/);
});

const workers = [
    { id: 'acquire', activity: 'acquiring', activity_secs: 3, claim_type: null, claim_secs: null },
    { id: 'execute', activity: 'executing', activity_secs: 1, claim_type: 'election_scope', claim_label: 'Test place', claim_secs: 1 },
    { id: 'waiting', activity: 'waiting', activity_secs: 1, claim_type: null, claim_secs: null },
    { id: 'legacy', claim_type: null, claim_secs: null },
];
for (const page of ['Setup/Step5_Simulate', 'Demo/SimConsole']) {
    test(`${page} renders all worker states without calling unreported activity idle`, async () => {
        const progress = { run: { id: 'r', status: 'running', phase: 'elections', phases: [] }, lanes: workers, workers };
        const props = page.startsWith('Setup') ? { step: 5, settings: { ladder: [] }, progress }
            : { initial: progress, instanceClass: 'scale_demo', isScaleDemo: true };
        const html = await render(`Pages/${page}.vue`, props);
        for (const text of ['Acquiring work', 'Executing items', 'No claim available', 'Activity not reported']) {
            assert.ok(html.includes(text), text);
        }
        assert.ok(html.includes('Test place'));
        assert.doesNotMatch(html, />idle</);
    });
}
