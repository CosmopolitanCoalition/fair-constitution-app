// node --experimental-vm-modules --test tests/js/i18nGaps_gap_content_data.test.mjs
//
// Gap lane gap-content-data (kind jsdata, namespaces c_media / c_states /
// c_system / c_operator_pages / lang) pin. DB-free. Reads the .vue sources,
// the generated registries, the exported PHP fixture, and the en catalogs.
//
// The lane wires content that ships as data and was rendered raw:
//   1. media.php video titles      -> c_media.video.<slug>     (VideoLibrary.vue)
//   2. state-machine status tokens -> c_states.<machine>.<tok> (StateStrip / LifecycleTracker)
//   3. seeded clock names          -> c_system.clocks.<CLK-id> (Clocks.vue)
//   4. mesh role/channel strings   -> c_operator_pages.roles.mesh_* (Roles.vue)
//   5. app.blade Loading           -> lang/en.json (already __()-wrapped)
//
// It asserts:
//   1. COMPILE GATE. Every listed .vue file compiles with @vue/compiler-sfc.
//   2. ADOPTION. Every listed .vue file imports useI18n; none keeps a
//      component-local vue-i18n messages block.
//   3. CALL SHAPES. Each consumer calls t() for the content it renders, in the
//      exact shape this lane introduced (regex over source).
//   4. REGISTRY <-> CATALOG. For every registry/config the lane wired, every
//      entry has a catalog key whose value equals the registry English value.
//   5. CATALOG SHAPE. Every touched catalog parses; every value is a
//      non-empty string.
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import test from 'node:test';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';
import { plainState } from '../../resources/js/lib/plain.js';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const jsRoot = path.join(root, 'resources/js');
const localesDir = path.join(jsRoot, 'i18n/locales/en');

// The listed .vue consumers, paths built from segments.
const VUE_FILES = [
    ['Pages', 'Learn', 'VideoLibrary.vue'],
    ['Components', 'Ui', 'StateStrip.vue'],
    ['Components', 'Ui', 'LifecycleTracker.vue'],
    ['Pages', 'System', 'Clocks.vue'],
    ['Pages', 'Operator', 'Roles.vue'],
].map((seg) => ({ rel: seg.join('/'), abs: path.join(jsRoot, ...seg) }));

const rd = (p) => JSON.parse(readFileSync(p, 'utf8'));
const catalog = (ns) => rd(path.join(localesDir, `${ns}.json`));
const fixture = () => rd(path.join(root, 'tests/js/gap_content_data.fixture.json'));

function hasScopedStyle(descriptor) {
    return (descriptor.styles || []).some((s) => s.scoped);
}

// ── SECTION 0 — instrument self-check.
test('instrument — helpers and fixture load', () => {
    assert.equal(plainState('in_committee'), 'in committee');
    assert.equal(plainState('threshold_reached'), 'threshold reached');
    const fx = fixture();
    assert.equal(Object.keys(fx.state_machines).length, 12, '12 state machines');
    assert.equal(Object.keys(fx.mesh_roles).length, 4, '4 mesh roles');
    assert.equal(Object.keys(fx.mesh_channels).length, 9, '9 mesh channels');
    assert.equal(Object.keys(fx.clocks).length, 22, '22 clocks');
    console.log(`  vue files: ${VUE_FILES.length}`);
});

// ── SECTION 1 — compile gate.
test('compile gate — every listed .vue file compiles', () => {
    const broken = [];
    for (const f of VUE_FILES) {
        const src = readFileSync(f.abs, 'utf8');
        try {
            const { descriptor, errors } = parse(src, { filename: f.abs });
            if (errors && errors.length) { broken.push(`${f.rel}: ${errors.map((e) => e.message).join('; ')}`); continue; }
            const scoped = hasScopedStyle(descriptor);
            compileScript(descriptor, { id: f.rel });
            const tpl = compileTemplate({
                source: descriptor.template.content,
                filename: f.abs,
                id: f.rel,
                scoped,
                compilerOptions: { scopeId: scoped ? `data-v-${f.rel}` : undefined },
            });
            if (tpl.errors && tpl.errors.length) broken.push(`${f.rel}: ${tpl.errors.join('; ')}`);
        } catch (e) {
            broken.push(`${f.rel}: ${e.message}`);
        }
    }
    console.log(`  compiled ${VUE_FILES.length}, broken ${broken.length}`);
    for (const b of broken) console.log(`    ${b}`);
    assert.deepEqual(broken, [], `files must compile: ${broken.join(' | ')}`);
});

// ── SECTION 2 — adoption + no local messages block.
test('adoption — every listed .vue file imports useI18n and keeps no local messages block', () => {
    const missing = [];
    const local = [];
    for (const f of VUE_FILES) {
        const src = readFileSync(f.abs, 'utf8');
        if (!/useI18n/.test(src)) missing.push(f.rel);
        if (/useScope:\s*['"]local['"]/.test(src)) local.push(f.rel);
    }
    console.log(`  useI18n wired ${VUE_FILES.length - missing.length}/${VUE_FILES.length}, local blocks ${local.length}`);
    assert.deepEqual(missing, [], `useI18n must be imported: ${missing.join(', ')}`);
    assert.deepEqual(local, [], `no local messages block allowed: ${local.join(', ')}`);
});

// ── SECTION 3 — call shapes (the exact shapes this lane introduced).
test('call shapes — each consumer resolves its content through t()', () => {
    const src = (rel) => readFileSync(VUE_FILES.find((f) => f.rel === rel).abs, 'utf8');

    // VideoLibrary: t(v.title_key, v.title)
    assert.match(src('Pages/Learn/VideoLibrary.vue'), /t\(\s*v\.title_key\s*,\s*v\.title\s*\)/,
        'VideoLibrary must render t(v.title_key, v.title)');

    // StateStrip: t('c_states.' + props.machine + '.' + state, plainState(state, props.labels))
    const ss = src('Components/Ui/StateStrip.vue');
    assert.match(ss, /t\(\s*'c_states\.'\s*\+\s*props\.machine\s*\+\s*'\.'\s*\+\s*state\s*,\s*plainState\(state,\s*props\.labels\)\)/,
        'StateStrip must resolve c_states with plainState fallback');

    // LifecycleTracker: t('c_states.' + props.machine + '.' + stage, stage)
    // The RAW token is the fallback here. This component rendered the raw stage
    // token before i18n (baseline: {{ stage }}), so an unset machine keeps the
    // English display byte-identical. plainState must not humanise it.
    const lt = src('Components/Ui/LifecycleTracker.vue');
    assert.match(lt, /t\(\s*'c_states\.'\s*\+\s*props\.machine\s*\+\s*'\.'\s*\+\s*stage\s*,\s*stage\s*\)/,
        'LifecycleTracker must resolve c_states with the raw token as fallback');
    assert.doesNotMatch(lt, /plainState/,
        'LifecycleTracker must not humanise: its baseline rendered the raw token');

    // Clocks: t('c_system.clocks.' + row.id, row.name)
    assert.match(src('Pages/System/Clocks.vue'), /t\(\s*'c_system\.clocks\.'\s*\+\s*row\.id\s*,\s*row\.name\s*\)/,
        'Clocks must resolve the clock name by id');

    // Roles: mesh role + channel resolution
    const rl = src('Pages/Operator/Roles.vue');
    assert.match(rl, /t\(\s*'c_operator_pages\.roles\.mesh_role\.'\s*\+\s*r\.role\s*\+\s*'\.label'\s*,\s*r\.label\s*\)/,
        'Roles must resolve the role label');
    assert.match(rl, /t\(\s*'c_operator_pages\.roles\.mesh_role\.'\s*\+\s*r\.role\s*\+\s*'\.what'\s*,\s*r\.what\s*\)/,
        'Roles must resolve the role what');
    assert.match(rl, /t\(\s*'c_operator_pages\.roles\.mesh_role\.'\s*\+\s*r\.role\s*\+\s*'\.duty'\s*,\s*r\.duty\s*\)/,
        'Roles must resolve the role duty');
    assert.match(rl, /t\(\s*'c_operator_pages\.roles\.mesh_channel\.'\s*\+\s*row\.capability\s*\+\s*'\.label'\s*,\s*row\.label\s*\)/,
        'Roles must resolve the channel label');
    assert.match(rl, /t\(\s*'c_operator_pages\.roles\.mesh_channel\.'\s*\+\s*row\.capability\s*\+\s*'\.what'\s*,\s*row\.what\s*\)/,
        'Roles must resolve the channel what');
    // The channel kind badge (row.kind) is user-visible English rendered raw.
    // It resolves through t() with the raw kind as the fallback; the sibling
    // comparison row.kind === 'self-asserted' is unaffected.
    assert.match(rl, /t\(\s*'c_operator_pages\.roles\.mesh_channel_kind\.'\s*\+\s*row\.kind\s*,\s*row\.kind\s*\)/,
        'Roles must resolve the channel kind badge with the raw kind as fallback');
    console.log('  all call shapes present');
});

// ── SECTION 4a — media registry <-> c_media catalog.
test('media — every video has a title_key and a c_media catalog entry equal to its title', async () => {
    const mod = await import(pathToFileURL(path.join(jsRoot, 'registry/media.js')).href);
    const videos = mod.MEDIA_VIDEOS;
    const cat = catalog('c_media');
    assert.ok(Array.isArray(videos) && videos.length > 0, 'MEDIA_VIDEOS present');
    const bad = [];
    let n = 0;
    for (const v of videos) {
        n += 1;
        const expectKey = 'c_media.video.' + v.slug;
        if (v.title_key !== expectKey) bad.push(`${v.slug}: title_key ${v.title_key} != ${expectKey}`);
        const catKey = 'video.' + v.slug;
        if (cat[catKey] !== v.title) bad.push(`${catKey}: catalog ${JSON.stringify(cat[catKey])} != title ${JSON.stringify(v.title)}`);
    }
    console.log(`  checked ${n} videos`);
    for (const b of bad) console.log(`    ${b}`);
    assert.deepEqual(bad, [], `media titles must match: ${bad.join(' | ')}`);
});

// ── SECTION 4b — state machines <-> c_states catalog.
test('states — every machine token has a c_states entry equal to its plain humanisation', () => {
    const fx = fixture();
    const cat = catalog('c_states');

    // Guard the fixture against the live config drifting under it: the
    // top-level machine keys in state_machines.php must match the fixture.
    const phpSrc = readFileSync(path.join(root, 'config/cga/state_machines.php'), 'utf8');
    const phpMachines = new Set();
    const re = /^\s*'([a-z_]+)'\s*=>\s*\[/gm;
    let m;
    while ((m = re.exec(phpSrc)) !== null) phpMachines.add(m[1]);
    assert.deepEqual([...phpMachines].sort(), Object.keys(fx.state_machines).sort(),
        'fixture machine set must match config/cga/state_machines.php');

    const bad = [];
    let n = 0;
    for (const [machine, tokens] of Object.entries(fx.state_machines)) {
        for (const tok of tokens) {
            n += 1;
            const key = `${machine}.${tok}`;
            const expect = plainState(tok);
            if (cat[key] !== expect) bad.push(`${key}: ${JSON.stringify(cat[key])} != ${JSON.stringify(expect)}`);
        }
    }
    console.log(`  checked ${n} machine tokens across ${Object.keys(fx.state_machines).length} machines`);
    for (const b of bad) console.log(`    ${b}`);
    assert.deepEqual(bad, [], `state labels must match: ${bad.join(' | ')}`);
});

// ── SECTION 4c — clock names <-> c_system catalog.
test('clocks — every clock name has a c_system.clocks.<id> entry equal to the seeded name', () => {
    const fx = fixture();
    const cat = catalog('c_system');
    const bad = [];
    let n = 0;
    for (const [id, name] of Object.entries(fx.clocks)) {
        n += 1;
        const key = 'clocks.' + id;
        if (cat[key] !== name) bad.push(`${key}: ${JSON.stringify(cat[key])} != ${JSON.stringify(name)}`);
    }
    console.log(`  checked ${n} clock names`);
    for (const b of bad) console.log(`    ${b}`);
    assert.deepEqual(bad, [], `clock names must match: ${bad.join(' | ')}`);
});

// ── SECTION 4d — mesh roles/channels <-> c_operator_pages catalog.
test('mesh — every role/channel string has a c_operator_pages entry equal to the config value', () => {
    const fx = fixture();
    const cat = catalog('c_operator_pages');
    const bad = [];
    let n = 0;
    for (const [slug, r] of Object.entries(fx.mesh_roles)) {
        for (const field of ['label', 'what', 'duty']) {
            n += 1;
            const key = `roles.mesh_role.${slug}.${field}`;
            if (cat[key] !== r[field]) bad.push(`${key}: ${JSON.stringify(cat[key])} != ${JSON.stringify(r[field])}`);
        }
    }
    for (const [slug, c] of Object.entries(fx.mesh_channels)) {
        for (const field of ['label', 'what']) {
            n += 1;
            const key = `roles.mesh_channel.${slug}.${field}`;
            if (cat[key] !== c[field]) bad.push(`${key}: ${JSON.stringify(cat[key])} != ${JSON.stringify(c[field])}`);
        }
    }
    // The channel kind badge values (MeshGateService kind: exactly
    // 'self-asserted' | 'governed', MeshGateService.php:193) are user-visible
    // English rendered raw; both must be catalogued verbatim so the English
    // display is byte-identical.
    for (const kind of ['self-asserted', 'governed']) {
        n += 1;
        const key = `roles.mesh_channel_kind.${kind}`;
        if (cat[key] !== kind) bad.push(`${key}: ${JSON.stringify(cat[key])} != ${JSON.stringify(kind)}`);
    }
    console.log(`  checked ${n} mesh strings (${Object.keys(fx.mesh_roles).length} roles, ${Object.keys(fx.mesh_channels).length} channels, 2 kind badges)`);
    for (const b of bad) console.log(`    ${b}`);
    assert.deepEqual(bad, [], `mesh strings must match: ${bad.join(' | ')}`);
});

// ── SECTION 4e — app.blade Loading key in lang/en.json.
test('lang — Loading… is present in lang/en.json', () => {
    const en = rd(path.join(root, 'lang/en.json'));
    assert.equal(en['Loading…'], 'Loading…', 'Loading… must be catalogued for the blade __() call');
    console.log('  Loading… present');
});

// ── SECTION 5 — catalog shape.
test('catalog shape — every touched catalog parses and every value is a non-empty string', () => {
    const files = [
        path.join(localesDir, 'c_media.json'),
        path.join(localesDir, 'c_states.json'),
        path.join(localesDir, 'c_system.json'),
        path.join(localesDir, 'c_operator_pages.json'),
        path.join(root, 'lang/en.json'),
    ];
    const bad = [];
    for (const p of files) {
        assert.ok(existsSync(p), `${p} exists`);
        const obj = rd(p);
        for (const [k, v] of Object.entries(obj)) {
            if (typeof v !== 'string' || v.length === 0) bad.push(`${path.basename(p)}:${k}`);
        }
    }
    console.log(`  checked ${files.length} catalogs`);
    for (const b of bad) console.log(`    ${b}`);
    assert.deepEqual(bad, [], `every value must be a non-empty string: ${bad.join(', ')}`);
});
