// node --experimental-vm-modules --test tests/js/a11ySetupWizard.test.mjs
//
// Gap lane fix-setup-wizard-a11y pin. Accessibility fixes on the setup wizard
// steps /setup/step/1..6 and their shared Setup components, which render on the
// dark shell (gray-900 #101828 panels).
//
// Findings (axe, WCAG 2.1 AA, 2026-09-15) and the fixes pinned here:
//  (a) helper/hint/code text used text-gray-500 (#6a7282 on #101828 = 3.3:1);
//      replaced with text-gray-400 (#99a1af on #101828 = 7.0:1).
//  (b) buttons used white on bg-emerald-600 (#009966 = 3.0:1); replaced with
//      bg-emerald-700 (#047857 = 5.0:1). Hover shades that landed on
//      emerald-500/emerald-600 (below 4.5:1 on white) moved to emerald-800.
//  (c) opacity-60/70 that dimmed always-visible text blocks removed and an
//      explicit colour used instead. disabled: / cursor-not-allowed dimming is
//      left in place: disabled UI is exempt from WCAG 1.4.3.
//  (d) <Head :title=.../> added to steps 1..6 (t() keys in the c_setup
//      namespace), so each step renders a non-empty document title.
//  (e) two horizontal overflows at 375 px fixed (step 2 controls row, step 5
//      timing table) by letting the wide element shrink or scroll in place.
//
// English on-screen text is unchanged: only class strings, the Head import and
// tag, and layout utilities were touched.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { parse, compileScript } from '@vue/compiler-sfc';

const root = fileURLToPath(new URL('../../', import.meta.url));
const read = (rel) => readFileSync(root + rel, 'utf8');

const STEP_PAGES = [
    'resources/js/Pages/Setup/Step1_Constants.vue',
    'resources/js/Pages/Setup/Step2_MapData.vue',
    'resources/js/Pages/Setup/Step3_Districts.vue',
    'resources/js/Pages/Setup/Step4_ScaleUp.vue',
    'resources/js/Pages/Setup/Step5_Simulate.vue',
    'resources/js/Pages/Setup/Step6_Confirm.vue',
];
const SETUP_COMPONENTS = [
    'resources/js/Components/Setup/CurrentJurisdictionCard.vue',
    'resources/js/Components/Setup/EventToasts.vue',
    'resources/js/Components/Setup/ExportBackupPanel.vue',
    'resources/js/Components/Setup/ImportBackupPanel.vue',
    'resources/js/Components/Setup/JurisdictionCountsGrid.vue',
    'resources/js/Components/Setup/LiveProgress.vue',
    'resources/js/Components/Setup/LogTailPanel.vue',
    'resources/js/Components/Setup/MiniMap.vue',
    'resources/js/Components/Setup/PhaseSummary.vue',
    'resources/js/Components/Setup/ProgressStatusBadge.vue',
    'resources/js/Components/Setup/QueueBadges.vue',
    'resources/js/Components/Setup/ReviewIssuesSection.vue',
    'resources/js/Components/Setup/RowDetailPanel.vue',
    'resources/js/Components/Setup/StackedProgressBars.vue',
    'resources/js/Components/SetupStepper.vue',
];
const SCOPED = [...STEP_PAGES, ...SETUP_COMPONENTS];

// ── (a) no low-contrast helper text ──────────────────────────────────────────
test('no text-gray-500 remains in the setup steps or Setup components', () => {
    for (const rel of SCOPED) {
        assert.ok(!read(rel).includes('text-gray-500'),
            `${rel} still uses text-gray-500 (3.3:1 on gray-900); use text-gray-400 (7.0:1)`);
    }
});

// ── (b) no low-contrast emerald button surface ───────────────────────────────
test('no bg-emerald-600 remains in the setup steps or Setup components', () => {
    for (const rel of SCOPED) {
        assert.ok(!read(rel).includes('bg-emerald-600'),
            `${rel} still uses bg-emerald-600 (white = 3.0:1); use bg-emerald-700 (5.0:1)`);
    }
});

test('no emerald hover lands below 4.5:1 (emerald-500/600) in scoped files', () => {
    for (const rel of SCOPED) {
        const s = read(rel);
        assert.ok(!s.includes('hover:bg-emerald-500'), `${rel} has hover:bg-emerald-500 (white below 4.5:1)`);
        assert.ok(!s.includes('hover:bg-emerald-600'), `${rel} has hover:bg-emerald-600 (white 3.0:1)`);
    }
});

// ── (c) no active opacity dimming of text (disabled UI is exempt) ─────────────
test('no active opacity-50/60/70 dims text; disabled affordances are exempt', () => {
    const OPACITY = /\bopacity-(?:50|60|70)\b/;
    for (const rel of SCOPED) {
        const lines = read(rel).split('\n');
        lines.forEach((line, i) => {
            if (!OPACITY.test(line)) return;
            const exempt = line.includes('disabled:opacity-') || line.includes('cursor-not-allowed');
            assert.ok(exempt,
                `${rel}:${i + 1} applies opacity-50/60/70 to non-disabled content: "${line.trim()}"`);
        });
    }
});

test('step 5 phase list uses explicit colours, not opacity/alpha dimming', () => {
    const s = read('resources/js/Pages/Setup/Step5_Simulate.vue');
    // the phase ordinal was emerald-300 at opacity-50 (~3.5:1); now an explicit muted colour
    assert.ok(!/text-xs opacity-50 w-4/.test(s), 'step 5 phase number still dimmed by opacity-50');
    // the current-phase count was blue-300 at /70 alpha; now full blue-300
    assert.ok(!/text-blue-300\/70/.test(s), 'step 5 phase count still uses text-blue-300/70 alpha');
});

// ── (d) every step page renders a document title ─────────────────────────────
test('every step page carries a <Head title', () => {
    for (const rel of STEP_PAGES) {
        const s = read(rel);
        assert.ok(/<Head\b/.test(s), `${rel} has no <Head element`);
        assert.match(s, /<Head\s+:title="t\('c_setup\./,
            `${rel} <Head title is not a t() key in the c_setup namespace`);
    }
});

// ── (e) the two 375 px overflows are contained ───────────────────────────────
test('step 5 timing table scrolls inside its own container', () => {
    const s = read('resources/js/Pages/Setup/Step5_Simulate.vue');
    assert.match(s, /class="space-y-1 text-xs overflow-x-auto"/,
        'step 5 timing rows container is missing overflow-x-auto');
});

test('step 2 controls row can shrink below the viewport at 375 px', () => {
    const s = read('resources/js/Pages/Setup/Step2_MapData.vue');
    // the min-width no longer floors the blurb on narrow screens
    assert.ok(!/min-w-\[16rem\]"/.test(s.replace('sm:min-w-[16rem]', '')),
        'step 2 blurb still has an unconditional min-w-[16rem]');
    assert.match(s, /sm:min-w-\[16rem\]/, 'step 2 blurb min-width is not gated behind sm:');
    assert.match(s, /class="bg-gray-800 border border-gray-700 text-gray-200 text-sm rounded-md px-3 py-2 max-w-full min-w-0"/,
        'step 2 run-mode select is missing max-w-full / min-w-0');
});

// ── every touched .vue compiles ──────────────────────────────────────────────
test('every touched .vue compiles with @vue/compiler-sfc', () => {
    for (const rel of SCOPED) {
        const { descriptor, errors } = parse(read(rel), { filename: rel });
        assert.equal(errors.length, 0, `${rel} SFC parse error: ${errors[0]?.message}`);
        assert.doesNotThrow(() => compileScript(descriptor, { id: rel, inlineTemplate: true }),
            `${rel} failed to compile`);
    }
});

// ── contrast helper: evidence for the chosen shades ──────────────────────────
// WCAG 2.1 relative-luminance contrast. Tailwind v4 hexes from the lane note.
function luminance(hex) {
    const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255)
        .map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}
function ratio(a, b) {
    const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
    return (hi + 0.05) / (lo + 0.05);
}
const C = {
    white: '#ffffff', gray400: '#99a1af', gray500: '#6a7282', gray900: '#101828',
    emerald600: '#009966', emerald700: '#047857', emerald800: '#065f46',
};

test('the replacement shades meet AA where the originals failed', () => {
    // (a) helper text on the dark panel
    assert.ok(ratio(C.gray500, C.gray900) < 4.5, 'gray-500 on gray-900 should fail AA');
    assert.ok(ratio(C.gray400, C.gray900) >= 4.5, 'gray-400 on gray-900 should pass AA');
    // (b) emerald button surface and hover, white text
    assert.ok(ratio(C.white, C.emerald600) < 4.5, 'white on emerald-600 should fail AA');
    assert.ok(ratio(C.white, C.emerald700) >= 4.5, 'white on emerald-700 should pass AA');
    assert.ok(ratio(C.white, C.emerald800) >= 4.5, 'white on emerald-800 (hover) should pass AA');
});
