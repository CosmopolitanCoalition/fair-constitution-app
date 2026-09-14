// node --experimental-vm-modules --test tests/js/a11yContrastFixes.test.mjs
//
// W-0336 pin. Raises the subtle foreground token, drops opacity dimming on
// role cards, replaces the failing Tailwind gray-500 labels, gives the Atlas
// domain eyebrow a readable accent, and raises the bootstrap Continue button.
// The test computes the WCAG contrast of every changed colour against the
// surfaces it lands on and asserts the 4.5:1 AA floor, and compiles every
// affected component so a template or script syntax error fails the run.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';
import { oklchToRgb, hexToRgb, mixOklab, contrast } from './lib/wcagContrast.mjs';

const root = fileURLToPath(new URL('../../', import.meta.url));
const read = rel => readFileSync(root + rel, 'utf8');
const AA = 4.5;

// --- token readers -----------------------------------------------------------
const tokens = read('resources/css/cga/tokens.css');
function oklchToken(name) {
    const m = tokens.match(new RegExp(String.raw`--${name}:\s*oklch\(([^)]+)\)`));
    assert.ok(m, 'oklch token --' + name);
    const [L, C, H] = m[1].trim().split(/\s+/).map(Number);
    return oklchToRgb(L, C, H);
}
function hexToken(name) {
    const m = tokens.match(new RegExp(String.raw`--${name}:\s*(#[0-9a-fA-F]{6})`));
    assert.ok(m, 'hex token --' + name);
    return hexToRgb(m[1]);
}
const gray = { 400: oklchToken('cc-gray-400'), 500: oklchToken('cc-gray-500'), 800: oklchToken('cc-gray-800'), 900: oklchToken('cc-gray-900'), 950: oklchToken('cc-gray-950') };

test('W-0336 subtle token clears AA on every surface it lands on', () => {
    const m = tokens.match(/--gov-fg-subtle:\s*color-mix\(in oklch, var\(--cc-gray-400\) (\d+)%, var\(--cc-gray-500\)\)/);
    assert.ok(m, '--gov-fg-subtle is a gray-400/gray-500 oklch mix');
    const pct = Number(m[1]);
    const subtle = mixOklab(gray[400], pct, gray[500]);
    for (const bg of [950, 900, 800]) {
        const ratio = contrast(subtle, gray[bg]);
        assert.ok(ratio >= AA, `subtle on gray-${bg} = ${ratio.toFixed(2)} (>= ${AA})`);
    }
    // still quieter than --gov-fg-muted (raw gray-400)
    assert.ok(contrast(subtle, gray[900]) < contrast(gray[400], gray[900]), 'subtle stays quieter than muted');
});

test('W-0336 Atlas domain eyebrow accents clear AA on the card surface', () => {
    // adm-N-fg = color-mix(in oklch, adm-N P%, white). Read the base hex + pct.
    function admFg(n, pct) {
        const base = hexToken('adm-' + n);
        return mixOklab(base, pct, hexToRgb('#ffffff'));
    }
    const atlas = read('resources/js/Pages/System/Atlas.vue');
    // The three formerly-dark tier accents now use the readable -fg variants.
    for (const acc of ['adm-0-fg', 'adm-2-fg', 'adm-4-fg']) {
        assert.ok(atlas.includes(`accent: '${acc}'`), 'Atlas uses accent ' + acc);
        assert.ok(!atlas.match(/accent: 'tier-(planetary|national|municipal)'/), 'no dark tier accents remain');
    }
    for (const [n, pct] of [[0, 30], [2, 30], [4, 35]]) {
        const ratio = contrast(admFg(n, pct), gray[900]);
        assert.ok(ratio >= AA, `adm-${n}-fg on gray-900 = ${ratio.toFixed(2)}`);
    }
});

test('W-0336 bootstrap Continue button white text clears AA', () => {
    const boot = read('resources/js/Pages/Setup/Bootstrap.vue');
    assert.ok(boot.includes('bg-emerald-700'), 'Continue button raised to emerald-700');
    // The resting background must not be emerald-600 (a hover: state is fine).
    assert.ok(!/(?<!hover:)bg-emerald-600/.test(boot), 'no resting white-on-emerald-600 button');
    // Tailwind v4 emerald-700 oklch(.508 .118 165.612)
    const ratio = contrast(hexToRgb('#ffffff'), oklchToRgb(0.508, 0.118, 165.612));
    assert.ok(ratio >= AA, `white on emerald-700 = ${ratio.toFixed(2)}`);
});

test('W-0336 role cards no longer dim with opacity, labels no longer gray-500', () => {
    const ach = read('resources/js/Pages/Social/Achievements.vue');
    assert.ok(!ach.includes('opacity: 0.72'), 'no inline opacity dimming');
    assert.ok(ach.includes('role-card--unearned'), 'unearned state via class');
    const css = read('resources/css/cga/components.css');
    const rule = css.match(/\.role-card--planned, \.role-card--unearned \{[^}]*\}/);
    assert.ok(rule && !/opacity/.test(rule[0]), 'planned/unearned rule uses no opacity');
    for (const rel of ['resources/js/Pages/Build/Progress.vue', 'resources/js/Pages/Social/Reach.vue']) {
        const src = read(rel);
        assert.ok(!src.includes('text-gray-500'), rel + ' drops text-gray-500');
        assert.ok(src.includes('var(--gov-fg-subtle)'), rel + ' uses the subtle token');
    }
});

test('W-0336 every affected SFC compiles (template + script)', () => {
    for (const rel of ['resources/js/Pages/Social/Achievements.vue', 'resources/js/Pages/Build/Progress.vue', 'resources/js/Pages/Social/Reach.vue', 'resources/js/Pages/System/Atlas.vue', 'resources/js/Pages/Setup/Bootstrap.vue']) {
        const { descriptor } = parse(read(rel), { filename: rel });
        const id = 'pin';
        compileScript(descriptor, { id });
        const r = compileTemplate({ source: descriptor.template.content, id, filename: rel, scoped: descriptor.styles.some(s => s.scoped) });
        assert.equal(r.errors.length, 0, rel + ' template errors: ' + JSON.stringify(r.errors));
    }
});
