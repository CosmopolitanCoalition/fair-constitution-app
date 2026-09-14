// node --experimental-vm-modules --test tests/js/a11yProseLinks.test.mjs
//
// W-0337 pin. Prose links inside text blocks must be distinguishable from the
// surrounding text without colour alone. The shared stylesheet gives bare
// prose links and the citation/gloss voices a persistent underline, and the
// Federation prose link no longer underlines on hover only. This test pins the
// rule presence and that no prose link is left hover-only.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const root = fileURLToPath(new URL('../../', import.meta.url));
const read = rel => readFileSync(root + rel, 'utf8');

test('W-0337 shared stylesheet underlines prose and citation links', () => {
    const css = read('resources/css/cga/components.css');
    // The selector group that covers text-block links plus the citation/gloss voices.
    const m = css.match(/:where\(p, li[^{]*a:not\(\[class\]\)[^{]*\{([^}]*)\}/);
    assert.ok(m, 'prose-link underline rule present');
    assert.match(m[1], /text-decoration:\s*underline/, 'rule sets text-decoration: underline');
    // The selector list includes the citation link voices.
    const sel = css.slice(css.indexOf(':where(p, li'), css.indexOf(':where(p, li') + 260);
    assert.match(sel, /\.citation a/, 'covers .citation a');
    assert.match(sel, /\.gloss a/, 'covers .gloss a');
});

test('W-0337 Federation prose link is not hover-only underline', () => {
    // Build the path in two segments. NavRoleGateParityTest's action-door
    // census reads a full 'Pages/<module>/<file>.vue' literal in any tests/js
    // file as a DOM-mount companion. This is a source-string pin, not a mount,
    // so the literal must stay split. Federation stays a recorded gap there.
    const fed = read('resources/js/Pages/Jurisdictions/' + 'Federation.vue');
    assert.ok(!fed.includes('hover:underline'), 'no hover-only underline on a prose link');
});

test('W-0337 pin does not trip the action-door census', () => {
    // A merged full door path literal here would flip Federation into
    // NavRoleGateParityTest's DOM-covered set and collide with its recorded
    // gap. Keep the literal split. This guards the regression. The target
    // substring is itself built in segments so this guard does not embed it.
    const self = read('tests/js/a11yProseLinks.test.mjs');
    const doorLiteral = 'Pages/Jurisdictions/' + 'Federation.vue';
    assert.ok(
        !self.includes(doorLiteral),
        'do not embed the full Federation door path as one literal',
    );
});
