// @ts-check
// Node test (host): node --experimental-vm-modules --test tests/browser/roster/roster.test.mjs
//
// Pins the full app roster derived by deriveRoster() from the authoritative
// route table (route-list.json). The four classes are asserted for deep
// equality against the PIN arrays / count. A route added, removed or re-guarded
// in the app flips the derivation and breaks a pin here, forcing a deliberate
// update. This is the AuditChainSmokeTest discipline applied to the a11y
// denominator: the denominator comes from the route table by rule, never a hand
// list.
import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const HERE = path.dirname(fileURLToPath(import.meta.url));
import { readFileSync } from 'node:fs';
import {
    deriveRoster,
    deriveGuestPages,
    PIN_PAGES,
    PIN_NONPAGE,
    PIN_VIEWER_BOUND,
    PIN_SIGNED_IN,
    PIN_PARAM,
    PIN_MACHINE,
} from './roster.mjs';

const R = deriveRoster();
const G = deriveGuestPages();

// Counts printed once for the report.
console.log(
    'ROSTER_COUNTS ' +
        JSON.stringify({
            guestPages: R.guestPages.length,
            signedInPages: R.signedInPages.length,
            paramPages: R.paramPages.length,
            machineEndpoints: R.machineEndpoints.length,
            viewerBound: R.viewerBound.length,
            total:
                R.guestPages.length +
                R.signedInPages.length +
                R.paramPages.length +
                R.machineEndpoints.length +
                R.viewerBound.length,
        }),
);

test('guest pages derive from the route table and match PIN_PAGES', () => {
    assert.deepEqual(R.guestPages.map((p) => p.uri), PIN_PAGES);
    // The existing three-list derivation is unchanged.
    assert.deepEqual(G.pages.map((p) => p.uri), PIN_PAGES);
    assert.deepEqual(G.nonPageEndpoints.map((p) => p.uri), PIN_NONPAGE);
    assert.deepEqual(G.viewerBound.map((p) => p.uri), PIN_VIEWER_BOUND);
});

test('signed-in param-free pages derive and match PIN_SIGNED_IN', () => {
    assert.deepEqual(R.signedInPages.map((p) => p.uri), PIN_SIGNED_IN);
});

test('parameterised page routes derive and match PIN_PARAM (uri templates)', () => {
    assert.deepEqual(R.paramPages.map((p) => p.uri), PIN_PARAM);
    // Every param page carries at least one parameter name.
    for (const p of R.paramPages) {
        assert.ok(p.params.length >= 1, `${p.uri} has no parameter names`);
    }
});

test('machine endpoints derive and match PIN_MACHINE count', () => {
    assert.equal(R.machineEndpoints.length, PIN_MACHINE);
    // Every machine endpoint carries an exclusion reason.
    for (const m of R.machineEndpoints) {
        assert.ok(typeof m.reason === 'string' && m.reason.length > 0, `${m.uri} has no reason`);
    }
});

test('the classes are disjoint and cover the whole route table', () => {
    const uris = [
        ...R.guestPages,
        ...R.signedInPages,
        ...R.paramPages,
        ...R.machineEndpoints,
        ...R.viewerBound,
    ].map((p) => p.uri);
    const set = new Set(uris);
    assert.equal(set.size, uris.length, 'a route was classified into more than one class');
    // Coverage: every GET route in the table lands in exactly one class.
    const tableLen = JSON.parse(readFileSync(path.join(HERE, 'route-list.json'), 'utf8')).routes.length;
    assert.equal(uris.length, tableLen, 'a route in the table was left unclassified');
});
