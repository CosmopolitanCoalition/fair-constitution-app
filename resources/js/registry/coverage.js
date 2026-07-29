/* ============================================================================
   CGA — registry/coverage.js

   The PURE registry-vs-app drift algorithm behind /coverage and /coverage-ops.
   No Vue, no Inertia, no fetch — the two Coverage pages call it with live data
   (registry imported from surfaces.js; the app's routes + config surfaces
   arrive as Inertia props), and tests/js/coverageDrift.test.mjs pins it with
   plain node.

   "An instrument that can't fail measures nothing" (desk, 2026-07-29): the
   headline `ok` is driven by the two ROUTE-REALITY dimensions, which have a
   clean baseline and flip red the moment a registry href or a tour stop points
   at a route the app does not serve. The nav cross-check (config surface `nav`
   → a registry menu id, the SurfaceMeta::ids() flag) is reported alongside.
   ============================================================================ */

/** Flatten PLAYER_NAV + SITEMAP into one list of nav rows. */
export function flattenRegistryNav(playerNav, sitemap) {
    const rows = [];
    (playerNav || []).forEach((it) => rows.push({ ...it, section: 'player' }));
    (sitemap || []).forEach((sec) => (sec.items || []).forEach((it) => rows.push({ ...it, section: sec.key })));
    return rows;
}

/** The static path of an href (drop query + the tour: sentinel), or null. */
function staticPath(href) {
    if (!href || String(href).startsWith('tour:')) return null;
    return String(href).split('?')[0];
}
const isConcrete = (p) => !!p && !p.includes('{');

/* Compile a Laravel URI pattern to an anchored regex so a nav href served by a
   resolver / param route resolves (e.g. /legislature/bills via
   /legislature/{sub?}). Processed segment by segment: '{x?}' -> an optional
   '/segment', '{x}' -> one required '/segment', anything else literal. */
export function patternToRegex(pattern) {
    const escLit = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const segs = String(pattern).replace(/^\//, '').split('/').filter((s) => s.length);
    let re = '';
    for (const seg of segs) {
        if (/^\{.+\?\}$/.test(seg)) re += '(?:/[^/]+)?'; // optional (slash optional too)
        else if (/^\{.+\}$/.test(seg)) re += '/[^/]+'; // required segment
        else re += '/' + escLit(seg); // literal
    }
    return new RegExp('^' + (re || '/') + '$');
}

/** Is a concrete path served by ANY of the compiled route matchers? */
export function pathServed(path, matchers) {
    return isConcrete(path) && matchers.some((re) => re.test(path));
}

/**
 * @param {object} input
 * @param {Array<{id:string,nav:?string,module?:string,title?:string}>} input.surfaces  config/cga/surfaces.php via SurfaceMeta
 * @param {string[]} input.routes   GET route URI patterns the app serves ("/civic", "/legislature/{sub?}", …)
 * @param {Array<{id:string,href:?string,roles?:?string[],sandbox?:boolean,section:string}>} input.nav  flattened registry nav
 * @param {Array<{href:string,title:string,act?:string}>} input.tour  the TOUR
 */
export function computeCoverageDrift({ surfaces = [], routes = [], nav = [], tour = [] }) {
    const matchers = routes.map(patternToRegex);
    const registryIds = new Set(nav.map((n) => n.id));
    const tourPaths = new Set(tour.map((t) => staticPath(t.href)).filter(Boolean));

    /* 1 — DEAD NAV LINKS: a wired registry row whose route the app never serves. */
    const deadNavLinks = nav
        .map((n) => ({ id: n.id, href: n.href, path: staticPath(n.href), section: n.section }))
        .filter((n) => isConcrete(n.path) && !pathServed(n.path, matchers));

    /* 2 — DEAD TOUR STOPS: a stop pointing at a route the app never serves. */
    const deadTourStops = tour
        .map((t) => ({ title: t.title, href: t.href, path: staticPath(t.href) }))
        .filter((t) => isConcrete(t.path) && !pathServed(t.path, matchers));

    /* 3 — NAV CROSS-CHECK: every config surface `nav` must name a registry menu
       id, else the active-nav highlight silently misses (SurfaceMeta::ids flag). */
    const withNav = surfaces.filter((s) => s.nav != null && s.nav !== '');
    const navUnresolved = withNav
        .filter((s) => !registryIds.has(s.nav))
        .map((s) => ({ id: s.id, nav: s.nav, title: s.title }));

    /* 4 — TOUR GAP (informational): wired, player-reachable surfaces (no role
       gate, no sandbox) that no tour stop covers yet — the "toward 117" runway. */
    const tourGap = nav
        .filter((n) => {
            const p = staticPath(n.href);
            return pathServed(p, matchers) && !n.roles && !n.sandbox && !tourPaths.has(p);
        })
        .map((n) => ({ id: n.id, href: n.href, section: n.section }));

    const wired = nav.filter((n) => n.href && !String(n.href).startsWith('tour:'));
    const planned = nav.filter((n) => n.href === null);

    const ok = deadNavLinks.length === 0 && deadTourStops.length === 0 && navUnresolved.length === 0;

    return {
        ok,
        deadNavLinks,
        deadTourStops,
        navUnresolved,
        tourGap,
        counts: {
            surfaces: surfaces.length,
            surfacesWithNav: withNav.length,
            navResolved: withNav.length - navUnresolved.length,
            navWired: wired.length,
            navPlanned: planned.length,
            tourStops: tour.length,
            routes: routes.length,
        },
    };
}
