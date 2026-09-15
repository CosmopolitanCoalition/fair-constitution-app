// node --experimental-vm-modules --test tests/js/a11yCommonsContrast.test.mjs
//
// Gap lane fix-commons-contrast pin. WCAG 2.1 AA colour-contrast and
// link-in-text findings from the 2026-09-15 signed-in axe sweep on
// /civic/commons/square and /civic/commons/halls. Both routes render the
// same live-commons component (Vite dev scoped id data-v-bd45e66f =
// sha256('resources/js/Pages/Civic/MatrixCommons.vue')[:8]).
//
// The findings and their measured ratios on the dark shell:
//   - .opacity-70.text-sm  #707886 on gray-900 #101828 = 3.99:1 (fail)
//   - .py-6 (opacity-70)   #707886 on gray-900 #101828 = 3.99:1 (fail)
//   - .text-xs (opacity-60) #737373 on gray-950 #030712 = 4.25:1 (fail on 900)
//   - div > a in running text: no underline, 1.4:1 vs surrounding text (fail)
// FIX: drop opacity-based dimming for the text-gray-300 token (#d1d5dc:
//   12.05:1 on gray-900, 13.67:1 on gray-950) and give each in-text banner
//   link class="prose-link" (components.css:423 opt-in underline rule).
// English text on screen is unchanged; only colour and underline change.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';

const root = fileURLToPath(new URL('../../', import.meta.url));
const read = rel => readFileSync(root + rel, 'utf8');

// Paths split in two segments: NavRoleGateParityTest's action-door census
// treats a contiguous 'Pages/<module>/<file>.vue' literal in a tests/js file
// as a DOM-mount companion. These are source-string pins, not mounts.
const COMMONS = 'resources/js/Pages/Civic/' + 'MatrixCommons.vue';
const SQUARE = 'resources/js/Pages/Civic/' + 'PublicSquare.vue';
const HALLS = 'resources/js/Pages/Civic/' + 'Halls.vue';
// VoiceControls renders inside LiveRoom on both commons routes; it carried
// text-neutral-500 status/label text (#737373, 3.74:1 on the dark shell).
// It keeps opacity-70 only on decorative aria-hidden icons (no contrast
// target), so it is excluded from the no-opacity assertion below.
const VOICE = 'resources/js/Components/Civic/Room/' + 'VoiceControls.vue';
const TOUCHED = [COMMONS, SQUARE, HALLS];
const COMPILE = [COMMONS, SQUARE, HALLS, VOICE];

test('no opacity-based text dimming on the commons surfaces', () => {
    // opacity-70 dims the muted base to #707886 (3.99:1); opacity-60 dims it
    // to #737373 (fails on gray-900). No opacity utility survives on any of
    // the three files.
    for (const rel of TOUCHED) {
        const src = read(rel);
        const hits = src.match(/\bopacity-\d+\b/g) || [];
        assert.deepEqual(hits, [], rel + ' carries no opacity-* dimming utility, found: ' + hits.join(', '));
    }
});

test('no failing grey text token on the dark shell', () => {
    // gray-500 #6a7282 = 3.67:1 and neutral-500 #737373 = 3.74:1 on gray-900
    // both fail 4.5:1; gray-400 #99a1af opacity-dimmed was the source colour.
    // None of the sub-4.5:1 greys appear as a text token.
    for (const rel of TOUCHED) {
        const src = read(rel);
        assert.ok(!/\btext-gray-500\b/.test(src), rel + ' has no text-gray-500 (3.67:1)');
        assert.ok(!/\btext-neutral-500\b/.test(src), rel + ' has no text-neutral-500 (3.74:1)');
        assert.ok(!/\btext-gray-400\b/.test(src), rel + ' has no text-gray-400 (bare, was the dimmed base)');
    }
});

test('VoiceControls status and device-picker text uses the passing token', () => {
    // The AV controls render inside LiveRoom on the commons dark shell. The
    // status <p role="status"> and the device-picker container (whose <select>
    // options inherit its colour) no longer use text-neutral-500 (3.74:1).
    const src = read(VOICE);
    assert.ok(!/\btext-neutral-500\b/.test(src), 'no text-neutral-500 remains');
    assert.ok(!/\btext-gray-500\b/.test(src), 'no text-gray-500 remains');
    assert.match(src, /<p class="text-xs text-gray-300" role="status">/, 'status text uses text-gray-300');
    assert.match(src, /gap-3 text-xs text-gray-300"/, 'device-picker container uses text-gray-300');
});

test('the muted commons text uses the passing text-gray-300 token', () => {
    const src = read(COMMONS);
    // invite_share paragraph, no_messages paragraph, and the "you" tag.
    assert.match(src, /<p class="text-sm text-gray-300">/, 'invite_share uses text-gray-300');
    assert.match(src, /class="text-sm text-gray-300 py-6 text-center"/, 'no_messages uses text-gray-300');
    assert.match(src, /<span v-if="mine\(m\)" class="text-xs text-gray-300">/, 'the "you" tag uses text-gray-300');
});

test('every in-text banner link on the commons component opts into the underline', () => {
    // Banner.vue renders its slot inside a <div>, so a bare <Link> (an <a>
    // with no class) is not reached by the :where(p,li,...) auto-underline
    // rule and needs class="prose-link" (W-0337). The scoped data-v-bd45e66f
    // on these <a> confirms they are authored in this component.
    const src = read(COMMONS);
    assert.match(src, /text\('open_square'\)[\s\S]{0,40}/, 'open_square link present');
    assert.ok(src.includes('encodeURIComponent(jurisdictionId)}`" class="prose-link">{{ text(\'open_square\') }}</Link>'), 'open_square link opts in');
    assert.ok(src.includes('<Link :href="roomHref" preserve-state preserve-scroll class="prose-link">{{ text(\'retry\') }}</Link>'), 'retry links opt in');
    assert.equal((src.match(/preserve-state preserve-scroll class="prose-link">\{\{ text\('retry'\) \}\}/g) || []).length, 2, 'both retry banner links opt in');
    assert.ok(src.includes('<Link href="/login" class="prose-link">{{ text(\'sign_in\') }}</Link>'), 'sign_in link opts in');
    // No bare in-text banner link remains.
    assert.ok(!/<Link href="\/login">/.test(src), 'no bare sign_in link remains');
    assert.ok(!/preserve-scroll>\{\{ text\('retry'\) \}\}/.test(src), 'no bare retry link remains');
});

test('the prose-link underline rule the links rely on is present', () => {
    const css = read('resources/css/cga/components.css');
    const m = css.match(/:where\(p, li[^{]*a:not\(\[class\]\)[^{]*\{([^}]*)\}/);
    assert.ok(m, 'prose-link / text-block underline rule present');
    assert.match(m[1], /text-decoration:\s*underline/, 'rule sets underline');
    assert.match(css.slice(css.indexOf(':where(p, li'), css.indexOf(':where(p, li') + 260), /a\.prose-link/, 'rule covers a.prose-link');
});

test('every touched .vue compiles with @vue/compiler-sfc', () => {
    for (const rel of COMPILE) {
        const src = read(rel);
        const { descriptor, errors } = parse(src, { filename: rel });
        assert.equal(errors.length, 0, rel + ' parses with no SFC errors');
        const id = rel;
        if (descriptor.scriptSetup || descriptor.script) {
            const s = compileScript(descriptor, { id });
            assert.ok(s.content.length > 0, rel + ' script compiles');
        }
        const tpl = compileTemplate({
            source: descriptor.template.content,
            filename: rel,
            id,
            scoped: descriptor.styles.some(x => x.scoped),
        });
        assert.equal(tpl.errors.length, 0, rel + ' template compiles with no errors');
    }
});
