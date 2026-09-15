// node --experimental-vm-modules --test tests/js/a11yMapperContrast.test.mjs
//
// Gap lane fix-mapper-contrast pin (2026-09-15 signed-in axe sweep, WCAG 2.1 AA).
// The four map pages (/legislatures/{id}/districts, /legislatures/{id}/panels,
// /legislatures/{id}/type-b-map, /jurisdictions/{slug}/map) rendered failing
// grey utilities on the dark shell. type-b-map redirects to panels, so both
// render TypeBDistricts.vue; the four routes map to three page files.
//
// This pin asserts:
//  1. No class string in these files combines text-gray-500 or text-gray-600
//     with a dark surface (bg-gray-900, bg-gray-800, bg-gray-900/80).
//  2. text-gray-500 / text-gray-600 are gone from these files entirely (they
//     failed 4.5:1 on every surface these dark pages use).
//  3. The label toggle buttons no longer use text-gray-400 on bg-gray-900/80.
//  4. Each page file carries an Inertia <Head title>.
//  5. Every touched .vue compiles with @vue/compiler-sfc.
//  6. The replacement shades clear the 4.5:1 AA floor on their real surfaces.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { parse, compileScript } from '@vue/compiler-sfc';
import { hexToRgb, contrast } from './lib/wcagContrast.mjs';

const root = fileURLToPath(new URL('../../', import.meta.url));
const read = rel => readFileSync(root + rel, 'utf8');
const AA = 4.5;

const PAGES = [
    'resources/js/Pages/Legislature/Districts.vue',
    'resources/js/Pages/Legislature/TypeBDistricts.vue',
    'resources/js/Pages/Jurisdictions/Show.vue',
];

// Tailwind palette hexes (v4), plus the toggle button's effective background
// (bg-gray-900/80 over the Leaflet raster reads as #393f4c on the shell).
const PALETTE = {
    'gray-200': '#e5e7eb',
    'gray-300': '#d1d5dc',
    'gray-400': '#99a1af',
    'gray-500': '#6a7282',
    'gray-600': '#4a5565',
    'gray-800': '#1e2939',
    'gray-900': '#101828',
    'toggle': '#393f4c',
};
const ratio = (fg, bg) => contrast(hexToRgb(PALETTE[fg]), hexToRgb(PALETTE[bg]));

// A class string is any single- or double-quoted literal in the file.
function classStrings(src) {
    return src.match(/(['"])(?:(?!\1)[\s\S])*?\1/g) || [];
}
const DARK_SURFACE = /\bbg-gray-900\/80\b|\bbg-gray-900\b|\bbg-gray-800\b/;

for (const rel of PAGES) {
    test(`${rel}: no gray-500/600 text combined with a dark surface`, () => {
        const src = read(rel);
        for (const s of classStrings(src)) {
            if (/\btext-gray-(500|600)\b/.test(s) && DARK_SURFACE.test(s)) {
                assert.fail(`class string still fails contrast: ${s}`);
            }
        }
    });

    test(`${rel}: text-gray-500 and text-gray-600 removed`, () => {
        const src = read(rel);
        assert.ok(!/\btext-gray-500\b/.test(src), 'no text-gray-500 remains');
        assert.ok(!/\btext-gray-600\b/.test(src), 'no text-gray-600 remains');
    });

    test(`${rel}: carries an Inertia <Head title>`, () => {
        const src = read(rel);
        assert.match(src, /<Head\s+:title=/, 'page renders <Head :title>');
        assert.match(src, /import\s*\{[^}]*\bHead\b[^}]*\}\s*from\s*'@inertiajs\/vue3'/, 'Head imported from @inertiajs/vue3');
    });

    test(`${rel}: compiles with @vue/compiler-sfc`, () => {
        const src = read(rel);
        const { descriptor, errors } = parse(src, { filename: rel });
        assert.equal(errors.length, 0, 'no SFC parse errors');
        assert.doesNotThrow(() => compileScript(descriptor, { id: rel }), 'script block compiles');
    });
}

test('label toggle buttons drop text-gray-400 on bg-gray-900/80', () => {
    // All three page files carry the same bg-gray-900/80 map toggle buttons.
    for (const rel of PAGES) {
        const src = read(rel);
        assert.ok(
            !/bg-gray-900\/80 border-gray-700 text-gray-400/.test(src),
            `${rel}: toggle buttons no longer use text-gray-400 on bg-gray-900/80`,
        );
        assert.ok(
            /bg-gray-900\/80 border-gray-700 text-gray-200/.test(src),
            `${rel}: toggle buttons now use text-gray-200 on bg-gray-900/80`,
        );
    }
});

test('replacement shades clear the 4.5:1 AA floor on their real surfaces', () => {
    // gray-300 replaces gray-500/600 on gray-900 panels and gray-800 chips.
    assert.ok(ratio('gray-300', 'gray-900') >= AA, `gray-300 on gray-900 = ${ratio('gray-300', 'gray-900').toFixed(2)}`);
    assert.ok(ratio('gray-300', 'gray-800') >= AA, `gray-300 on gray-800 = ${ratio('gray-300', 'gray-800').toFixed(2)}`);
    // gray-200 replaces gray-400 on the toggle buttons.
    assert.ok(ratio('gray-200', 'toggle') >= AA, `gray-200 on toggle = ${ratio('gray-200', 'toggle').toFixed(2)}`);
    // the shades that were removed did fail (regression evidence).
    assert.ok(ratio('gray-500', 'gray-900') < AA, 'gray-500 on gray-900 failed AA');
    assert.ok(ratio('gray-600', 'gray-900') < AA, 'gray-600 on gray-900 failed AA');
    assert.ok(ratio('gray-400', 'toggle') < AA, 'gray-400 on toggle failed AA');
});
