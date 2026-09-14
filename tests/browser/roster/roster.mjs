// @ts-check
// Guest-page roster for the L2 accessibility sweep — DERIVED from the
// authoritative route table, never hand-written, and PINNED (assert-the-pin,
// the AuditChainSmokeTest discipline).
//
// Source of authority: tests/browser/roster/route-list.json — a machine dump of
//   docker exec fc_app php artisan route:list --json
// normalized to {uri,name,middleware} for the GET routes. That table resolves
// every route file (routes/web.php plus auth/federation/matrix/mesh/oidc) with
// all middleware groups expanded, so it is the true successor of routes/web.php
// for the purpose of "which URLs a guest can GET". Refresh it with
// tests/browser/roster/refresh-route-list.mjs.
//
// deriveGuestPages() applies the documented filter below at test time. The test
// asserts the derived set deep-equals the PIN arrays; any added/removed/reguarded
// route flips the derivation and breaks the pin, forcing a deliberate update.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const TABLE = JSON.parse(fs.readFileSync(path.join(HERE, 'route-list.json'), 'utf8')).routes;

// Auth-class middleware: its presence gates the URL behind a session or role.
// 'guest' is NOT auth-class — it only redirects already-authenticated users
// away, so a guest still reads the page (e.g. /login, /register).
export function isAuthClass(m) {
    return (
        m === 'auth' ||
        m.startsWith('auth:') ||
        m === 'horizon' ||
        m === 'matrix.appservice' ||
        m.startsWith('federation.signed') ||
        m.includes('Authenticate') ||
        m.includes('DevTools') ||
        m.includes('DevTimeControls')
    );
}

// Non-HTML endpoints (JSON APIs, discovery documents, health probe): guest-
// readable but not axe-scannable pages. Recorded in the roster, never scanned.
const NON_PAGE_RE = [/^\/api\//, /^\/\.well-known\//, /^\/oauth\//];
function isNonPage(u) {
    return u === '/up' || NON_PAGE_RE.some((re) => re.test(u));
}

export function deriveGuestPages() {
    const pages = [];
    const nonPageEndpoints = [];
    for (const r of TABLE) {
        const u = r.uri.startsWith('/') ? r.uri : '/' + r.uri;
        if (u.includes('{')) continue; // param-free pages only
        if ((r.middleware || []).some(isAuthClass)) continue; // authless only
        const rec = { uri: u, name: r.name || (u === '/' ? 'root' : u) };
        (isNonPage(u) ? nonPageEndpoints : pages).push(rec);
    }
    const byUri = (a, b) => (a.uri < b.uri ? -1 : a.uri > b.uri ? 1 : 0);
    const dedupe = (arr) => {
        const seen = new Set();
        return arr.filter((x) => (seen.has(x.uri) ? false : (seen.add(x.uri), true))).sort(byUri);
    };
    return { pages: dedupe(pages), nonPageEndpoints: dedupe(nonPageEndpoints) };
}

// ── THE PIN. 41 guest pages, 35 non-page endpoints as resolved from the route
// table captured 2026-09-14. If route-list.json is refreshed and the derivation
// changes, these arrays must be updated deliberately (that is the point). ──
export const PIN_PAGES = [
    '/',
    '/achievements',
    '/atlas',
    '/building',
    '/civic/commons/halls',
    '/civic/commons/square',
    '/continue',
    '/coverage',
    '/coverage-ops',
    '/explore',
    '/federation',
    '/journeys',
    '/jurisdictions',
    '/jurisdictions/bootstrap',
    '/jurisdictions/disintermediation',
    '/jurisdictions/restoration',
    '/jurisdictions/union-formation',
    '/launchpad',
    '/learn',
    '/learn/guides',
    '/learn/manage',
    '/legislatures',
    '/login',
    '/operator/login',
    '/people',
    '/reach',
    '/register',
    '/rooms',
    '/setup',
    '/setup/bootstrap',
    '/setup/join',
    '/setup/mode',
    '/setup/operator',
    '/support/report',
    '/system/accessibility',
    '/system/clocks',
    '/system/constitutional-questions',
    '/system/public-records',
    '/system/term-sync',
    '/tour',
    '/videos',
];

export const PIN_NONPAGE = [
    '/.well-known/cga-federation',
    '/.well-known/matrix/client',
    '/.well-known/matrix/server',
    '/.well-known/openid-configuration',
    '/api/background-jobs',
    '/api/build/progress',
    '/api/cosmic-addresses/default-path',
    '/api/export/jurisdictions',
    '/api/export/jurisdictions/list',
    '/api/export/jurisdictions/tables',
    '/api/geodata/flags',
    '/api/geodata/repairs',
    '/api/geodata/scan/status',
    '/api/jurisdictions/activation-status',
    '/api/maps/latest-pmtiles',
    '/api/mesh/nearest',
    '/api/session/heartbeat',
    '/api/setup/bootstrap/status',
    '/api/setup/state',
    '/api/setup/wizard/step2/progress',
    '/api/setup/wizard/step2/pull-progress',
    '/api/setup/wizard/step2/review/aggregation_discrepancies',
    '/api/setup/wizard/step2/review/orphans',
    '/api/setup/wizard/step2/review/parent_assignment_audit',
    '/api/setup/wizard/step2/review/population_assignment_audit',
    '/api/setup/wizard/step2/review/population_gaps',
    '/api/setup/wizard/step2/review/sovereign_territories',
    '/api/setup/wizard/step2/sources',
    '/api/setup/wizard/step3/autoscale-progress',
    '/api/setup/wizard/step3/summary',
    '/api/setup/wizard/step4/progress',
    '/api/setup/wizard/step5/progress',
    '/oauth/jwks',
    '/oauth/userinfo',
    '/up',
];

// Auth walls: a guest page that redirects here needs a signed-in session and is
// recorded NOT ESTABLISHED by name.
export const AUTH_WALLS = ['/login', '/register', '/operator/login'];
