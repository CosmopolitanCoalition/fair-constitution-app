// node --experimental-vm-modules --test tests/js/a11yFixes2026_09_15.test.mjs
//
// Pin for the 2026-09-15 signed-in accessibility sweep (axe, WCAG 2.1 AA),
// lane fix-a11y-ui. DB-free, no browser. Source regex, CSS assertions, WCAG
// contrast maths, and an SFC compile of every touched component.
//
// Findings fixed and pinned here:
//   (1) Leaflet attribution links measured 3.11:1 (#0078a8 on #cdcdd0, 12px).
//       A global rule repaints the control to an opaque light panel with
//       AA-passing ink and link colour. Leaflet's own CSS is untouched.
//   (2) Two in-prose <Link>s (ElectionDetail, Actions) opt into the app's
//       prose-link underline rule; every link inside a .banner body underlines.
//   (3) PrivateRoom's locked-body paragraph drops opacity-70 for the muted
//       theme token (opacity dimming pushed it to 4.22:1 on the dark shell).
//   (4) Residency's two Leaflet map containers no longer sit as a focusable
//       element with focusable descendants: role="region" (non-interactive
//       landmark) and keyboard: false so Leaflet never sets tabIndex=0.
//   (5) CommunityNav's paragraph reads at the muted theme token (verify only).
//
// Component paths are built in two segments on purpose: NavRoleGateParityTest's
// action-door census treats a full 'Pages/<module>/<file>.vue' literal in any
// tests/js file as a DOM-mount companion. These are source-string pins, not
// mounts, so the literals stay split.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';
import { hexToRgb, contrast } from './lib/wcagContrast.mjs';

const root = fileURLToPath(new URL('../../', import.meta.url));
const read = rel => readFileSync(root + rel, 'utf8');
const AA = 4.5;

// Composite an opaque-over-bg blend: out = a*fg + (1-a)*bg, per channel.
function over(fg, a, bg) { return fg.map((c, i) => Math.round(a * c + (1 - a) * bg[i])); }

const CSS = 'resources/css/app.css'; // unlayered: Leaflet's own CSS is unlayered too

// --- (1) Leaflet attribution -------------------------------------------------
test('Leaflet attribution: opaque light panel + AA ink and link', () => {
    const css = read(CSS);
    const panel = css.match(/\.leaflet-container \.leaflet-control-attribution \{([^}]*)\}/);
    assert.ok(panel, 'attribution panel rule present');
    assert.match(panel[1], /background:\s*rgba\(255,\s*255,\s*255,\s*\.92\)/, 'panel is a .92 opaque white');
    assert.match(panel[1], /color:\s*#1f2937/, 'panel ink is #1f2937');

    const link = css.match(/\.leaflet-container \.leaflet-control-attribution a \{([^}]*)\}/);
    assert.ok(link, 'attribution link rule present');
    assert.match(link[1], /color:\s*#0b4f7a/, 'link colour is #0b4f7a');
    assert.match(link[1], /text-decoration:\s*underline/, 'link is underlined');

    // Worst case for a light .92 panel is a black basemap tile behind it.
    const worstPanel = over([255, 255, 255], 0.92, [0, 0, 0]); // ~[235,235,235]
    const ink = contrast(hexToRgb('#1f2937'), worstPanel);
    const lnk = contrast(hexToRgb('#0b4f7a'), worstPanel);
    assert.ok(ink >= AA, 'ink clears AA over the worst-case panel (got ' + ink.toFixed(2) + ')');
    assert.ok(lnk >= AA, 'link clears AA over the worst-case panel (got ' + lnk.toFixed(2) + ')');

    // Leaflet's own stylesheet is never loaded or edited by this lane.
    assert.ok(!css.includes('leaflet/dist/leaflet.css'), 'does not import leaflet CSS');
});

// --- (2) prose links + banner underline --------------------------------------
test('banner body links underline', () => {
    const css = read(CSS);
    assert.match(css, /\.banner a \{\s*text-decoration:\s*underline;?\s*\}/, '.banner a underline rule present');
});

test('the two in-prose Links opt into prose-link', () => {
    const detail = read('resources/js/Pages/Elections/' + 'ElectionDetail.vue');
    assert.ok(detail.includes('<Link href="/jurisdictions" class="prose-link">'), 'ElectionDetail jurisdictions link opts in');
    assert.ok(!detail.includes('<Link href="/jurisdictions">'), 'no bare jurisdictions link remains');

    const actions = read('resources/js/Pages/Executive/' + 'Actions.vue');
    assert.ok(actions.includes('<Link href="/legislature/bills?intro=1" class="prose-link">'), 'Actions open-bill link opts in');
    assert.ok(!actions.includes('<Link href="/legislature/bills?intro=1">'), 'no bare open-bill link remains');
});

// --- (3) PrivateRoom muted token ---------------------------------------------
test('PrivateRoom locked body uses the muted token, not opacity-70', () => {
    const src = read('resources/js/Pages/Civic/' + 'PrivateRoom.vue');
    const line = src.split('\n').find(l => l.includes('private_room.locked_body'));
    assert.ok(line, 'locked-body paragraph present');
    assert.ok(!/opacity-70/.test(line), 'no opacity-70 on the locked-body paragraph');
    assert.match(line, /color:\s*var\(--gov-fg-muted\)/, 'reads at the muted theme token');
});

// --- (4) Residency map containers: non-interactive landmark, no tabindex ------
test('Residency map containers are non-interactive regions', () => {
    const src = read('resources/js/Pages/Civic/' + 'Residency.vue');
    // No focusable/interactive role on a map that holds focusable descendants.
    assert.ok(!src.includes('role="img"'), 'the display map no longer carries role="img"');
    assert.ok(!/role="button"/.test(src), 'no interactive button role on a map container');
    // Both boundary-map containers carry role="region" (a non-interactive landmark).
    const mapEl = src.match(/ref="mapEl"[\s\S]{0,120}?role="region"/);
    const pickerEl = src.match(/ref="pickerEl"[^>]*role="region"/);
    assert.ok(mapEl, 'display map (mapEl) carries role="region"');
    assert.ok(pickerEl, 'picker map (pickerEl) carries role="region"');
    // No author tabindex on either container (Leaflet is prevented from adding one).
    assert.ok(!/class="boundary-map"[^>]*tabindex/i.test(src), 'no author tabindex on a map container');
});

test('Residency Leaflet inits disable the keyboard handler', () => {
    const src = read('resources/js/Pages/Civic/' + 'Residency.vue');
    // Both L.map(...) option objects carry keyboard: false so Map.Keyboard
    // never runs addHooks and never sets container.tabIndex = 0.
    const picker = src.match(/L\.map\(pickerEl\.value, \{[\s\S]*?\}\)/);
    const display = src.match(/L\.map\(mapEl\.value, \{[\s\S]*?\}\)/);
    assert.ok(picker, 'picker L.map init present');
    assert.ok(display, 'display L.map init present');
    assert.match(picker[0], /keyboard:\s*false/, 'picker map disables keyboard');
    assert.match(display[0], /keyboard:\s*false/, 'display map disables keyboard');
});

// --- (5) CommunityNav muted token (verify already on main) --------------------
test('CommunityNav paragraph reads at the muted theme token', () => {
    const src = read('resources/js/Components/Civic/' + 'CommunityNav.vue');
    const rule = src.match(/\.community-nav p \{([^}]*)\}/);
    assert.ok(rule, '.community-nav p rule present');
    assert.match(rule[1], /var\(--gov-fg-muted/, 'reads at the muted theme token');
});

// --- SFC compile of every touched component ----------------------------------
test('every touched SFC compiles (template + script)', () => {
    const files = [
        'resources/js/Pages/Elections/' + 'ElectionDetail.vue',
        'resources/js/Pages/Executive/' + 'Actions.vue',
        'resources/js/Pages/Civic/' + 'PrivateRoom.vue',
        'resources/js/Pages/Civic/' + 'Residency.vue',
        'resources/js/Components/Civic/' + 'CommunityNav.vue',
    ];
    for (const rel of files) {
        const { descriptor } = parse(read(rel), { filename: rel });
        const id = 'pin';
        if (descriptor.scriptSetup || descriptor.script) compileScript(descriptor, { id });
        const r = compileTemplate({ source: descriptor.template.content, id, filename: rel, scoped: descriptor.styles.some(s => s.scoped) });
        assert.equal(r.errors.length, 0, rel + ' template errors: ' + JSON.stringify(r.errors));
    }
});
