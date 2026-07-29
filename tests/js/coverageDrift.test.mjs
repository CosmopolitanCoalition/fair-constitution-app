/* ============================================================================
   PIN — the coverage drift algorithm (registry/coverage.js).

   "An instrument that cannot fail measures nothing" (desk, 2026-07-29). This
   proves the algorithm both PASSES clean input and FAILS on each kind of
   seeded drift — a dead nav link, a dead tour stop, an unresolved surface nav —
   and that a resolver / param route (/legislature/{sub?}) correctly SERVES its
   sub-paths (the false-dead-link bug that this pin locks out). Run with node:

       node tests/js/coverageDrift.test.mjs

   Exit 0 = all pass; exit 1 = the algorithm changed behaviour.
   ============================================================================ */
import { computeCoverageDrift, patternToRegex, flattenRegistryNav } from '../../resources/js/registry/coverage.js';

let failed = 0;
function ok(cond, label) {
    if (cond) console.log(`  ok   ${label}`);
    else { failed++; console.log(`  FAIL ${label}`); }
}

console.log('patternToRegex — resolver / param routes:');
ok(patternToRegex('/legislature/{sub?}').test('/legislature'), 'optional-param route serves the bare path');
ok(patternToRegex('/legislature/{sub?}').test('/legislature/bills'), 'optional-param route serves a sub-path');
ok(!patternToRegex('/legislature/{sub?}').test('/legislature/bills/x'), 'optional-param route does NOT serve two extra segments');
ok(patternToRegex('/economy/market/{listing}').test('/economy/market/abc'), 'required-param route serves a concrete segment');
ok(!patternToRegex('/economy/market/{listing}').test('/economy/market'), 'required-param route does NOT serve the bare path');
ok(patternToRegex('/people').test('/people') && !patternToRegex('/people').test('/people/x'), 'literal route is exact');
ok(patternToRegex('/').test('/'), 'root pattern matches root');

/* A small world: routes (patterns), the registry nav, surfaces, the tour. */
const ROUTES = ['/civic', '/legislature/{sub?}', '/economy', '/people'];
const NAV = flattenRegistryNav(
    [{ id: 'home', href: '/civic' }, { id: 'tour', href: 'tour:start' }, { id: 'atlas', href: null }],
    [{ key: 'chamber', items: [{ id: 'bills', href: '/legislature/bills', roles: ['R-09'] }] }],
);
const SURFACES = [{ id: 'civic/home', nav: 'home', title: 'Home' }, { id: 'x/y', nav: null, title: 'No nav' }];
const TOUR = [{ act: 'A', href: '/civic', title: 'Home' }, { act: 'A', href: '/economy', title: 'Market' }];

console.log('\ncomputeCoverageDrift — a clean world:');
const clean = computeCoverageDrift({ surfaces: SURFACES, routes: ROUTES, nav: NAV, tour: TOUR });
ok(clean.ok === true, 'clean input → ok:true');
ok(clean.deadNavLinks.length === 0, 'clean → no dead nav links (bills resolves via the resolver route)');
ok(clean.deadTourStops.length === 0, 'clean → no dead tour stops');
ok(clean.navUnresolved.length === 0, 'clean → no unresolved surface navs');

console.log('\ncomputeCoverageDrift — MUST fail on each seeded drift:');

/* Seed A — a nav row wired to a route the app does not serve. */
const seedDeadNav = computeCoverageDrift({
    surfaces: SURFACES, routes: ROUTES, tour: TOUR,
    nav: [...NAV, { id: 'ghost', href: '/nowhere', section: 'player' }],
});
ok(seedDeadNav.ok === false, 'seeded dead nav link → ok:false');
ok(seedDeadNav.deadNavLinks.some((d) => d.href === '/nowhere'), 'seeded dead nav link → reported');

/* Seed B — a tour stop pointing at an unserved route. */
const seedDeadStop = computeCoverageDrift({
    surfaces: SURFACES, routes: ROUTES, nav: NAV,
    tour: [...TOUR, { act: 'Z', href: '/ghost-stop', title: 'Ghost' }],
});
ok(seedDeadStop.ok === false, 'seeded dead tour stop → ok:false');
ok(seedDeadStop.deadTourStops.some((d) => d.href === '/ghost-stop'), 'seeded dead tour stop → reported');

/* Seed C — a surface whose nav names no registry menu id. */
const seedNavDrift = computeCoverageDrift({
    surfaces: [...SURFACES, { id: 'z/z', nav: 'no-such-menu-id', title: 'Drift' }],
    routes: ROUTES, nav: NAV, tour: TOUR,
});
ok(seedNavDrift.ok === false, 'seeded unresolved surface nav → ok:false');
ok(seedNavDrift.navUnresolved.some((d) => d.nav === 'no-such-menu-id'), 'seeded unresolved surface nav → reported');

console.log(failed ? `\n${failed} FAILED` : '\nall passed');
process.exit(failed ? 1 : 0);
