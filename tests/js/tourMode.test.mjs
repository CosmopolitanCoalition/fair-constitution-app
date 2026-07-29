/* ============================================================================
   PIN — the A2 tour-mode reducer (composables/tourMode.js).

   The guided tour is a togglable MODE, armed IN PLACE, and EVERY page is a
   valid stop (operator ruling A2, 2026-07-29). This asserts the pure reducer
   that guarantees it. No test harness in this repo — run with plain node:

       node tests/js/tourMode.test.mjs

   Exit 0 = all pass; exit 1 = a pin broke (the reducer changed behaviour).
   The reducer is pure (no Vue/Inertia/sessionStorage), so this file imports it
   directly. If you break A2, THIS is the file that goes red — fix the reducer,
   not the pin.
   ============================================================================ */
import { resolveTour, stopMatchesUrl, tourHrefFor } from '../../resources/js/composables/tourMode.js';

/* A miniature TOUR: two plain stops and one param-carrying stop. */
const STOPS = [
    { href: '/civic', title: 'Home' },
    { href: '/elections', title: 'An election' },
    { href: '/people?who=amara', title: 'A person' },
];

let failed = 0;
function eq(actual, expected, label) {
    const a = JSON.stringify(actual);
    const e = JSON.stringify(expected);
    if (a === e) {
        console.log(`  ok   ${label}`);
    } else {
        failed++;
        console.log(`  FAIL ${label}\n         expected ${e}\n         actual   ${a}`);
    }
}
function truthy(v, label) {
    if (v) console.log(`  ok   ${label}`);
    else { failed++; console.log(`  FAIL ${label} (expected truthy, got ${JSON.stringify(v)})`); }
}

console.log('A2 tour-mode reducer:');

/* 1. Arm IN PLACE on a registered stop → armed, positioned on that stop.
   The reducer is fed prev.armed=true (arm() seeds it) with the current url. */
eq(
    resolveTour({ armed: true, anchor: 0 }, '/elections', STOPS),
    { armed: true, index: 1, anchor: 1 },
    'arm in place on a registered stop → armed + positioned there',
);

/* 2. Arm IN PLACE on a NON-stop page → STILL armed (mode on), index -1.
   This is the whole ruling: arming never depends on the page being a stop,
   and it implies NO navigation — the anchor (Back/Next base) is preserved. */
eq(
    resolveTour({ armed: true, anchor: 2 }, '/economy/wallet', STOPS),
    { armed: true, index: -1, anchor: 2 },
    'arm in place on a non-stop page → armed, off-trail, anchor kept',
);

/* 3. A ?step=N deep-link arms AND pins, even from a cold (unarmed) state. */
eq(
    resolveTour({ armed: false, anchor: 0 }, '/elections?step=2', STOPS),
    { armed: true, index: 1, anchor: 1 },
    '?step=N arms + pins from cold',
);

/* 4. Armed, wander to a non-stop without ?step → mode persists, anchor kept. */
eq(
    resolveTour({ armed: true, anchor: 1 }, '/economy/market', STOPS),
    { armed: true, index: -1, anchor: 1 },
    'armed + wander off-trail → mode persists',
);

/* 5. Armed, land on a registered stop → position + anchor move to it. */
eq(
    resolveTour({ armed: true, anchor: 0 }, '/people?who=amara', STOPS),
    { armed: true, index: 2, anchor: 2 },
    'armed + land on a param stop → position moves (params matched)',
);

/* 6. NOT armed, navigate to a stop without ?step → stays OFF (no auto-arm). */
eq(
    resolveTour({ armed: false, anchor: 0 }, '/civic', STOPS),
    { armed: false, index: -1, anchor: 0 },
    'unarmed navigation never auto-arms',
);

/* 7. An out-of-range ?step falls through to armed-state resolution. */
eq(
    resolveTour({ armed: false, anchor: 0 }, '/civic?step=99', STOPS),
    { armed: false, index: -1, anchor: 0 },
    'out-of-range ?step does not arm',
);

/* 8. Param matching: a stop's params must all be present; extras are fine. */
truthy(stopMatchesUrl({ href: '/people?who=amara' }, '/people?who=amara&x=1'), 'stopMatchesUrl: extra params ok');
truthy(!stopMatchesUrl({ href: '/people?who=amara' }, '/people?who=bob'), 'stopMatchesUrl: wrong param value rejected');
truthy(!stopMatchesUrl({ href: '/people?who=amara' }, '/people'), 'stopMatchesUrl: missing required param rejected');

/* 9. tourHrefFor builds the deep-link (adds ?step / &step correctly). */
eq(tourHrefFor(STOPS, 0), '/civic?step=1', 'tourHrefFor: adds ?step to a param-free href');
eq(tourHrefFor(STOPS, 2), '/people?who=amara&step=3', 'tourHrefFor: adds &step to a href with a query');

console.log(failed ? `\n${failed} FAILED` : '\nall passed');
process.exit(failed ? 1 : 0);
