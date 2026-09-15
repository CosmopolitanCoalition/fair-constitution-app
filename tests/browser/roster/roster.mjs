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
// /federation/cluster/sync-progress is a JSON progress poll that lives outside
// /api (verified 2026-09-14: application/json, 0.1 KB); listed by name so the
// sweep never scans it as a page.
const NON_PAGE_RE = [/^\/api\//, /^\/\.well-known\//, /^\/oauth\//, /^\/federation\/cluster\/sync-progress$/];
function isNonPage(u) {
    return u === '/up' || NON_PAGE_RE.some((re) => re.test(u));
}

// Viewer-bound pages: the URL names no subject, so the controller resolves the
// signed-in viewer and sends a guest to /login itself. The route table shows no
// auth middleware on them, so the derivation would list them as guest pages and
// the sweep would record a false NOT ESTABLISHED (W-0439). They are recorded
// here, never scanned, and pinned like the other two lists.
const VIEWER_BOUND = new Set(['/people']);

export function deriveGuestPages() {
    const pages = [];
    const nonPageEndpoints = [];
    const viewerBound = [];
    for (const r of TABLE) {
        const u = r.uri.startsWith('/') ? r.uri : '/' + r.uri;
        if (u.includes('{')) continue; // param-free pages only
        if ((r.middleware || []).some(isAuthClass)) continue; // authless only
        const rec = { uri: u, name: r.name || (u === '/' ? 'root' : u) };
        (VIEWER_BOUND.has(u) ? viewerBound : isNonPage(u) ? nonPageEndpoints : pages).push(rec);
    }
    const byUri = (a, b) => (a.uri < b.uri ? -1 : a.uri > b.uri ? 1 : 0);
    const dedupe = (arr) => {
        const seen = new Set();
        return arr.filter((x) => (seen.has(x.uri) ? false : (seen.add(x.uri), true))).sort(byUri);
    };
    return { pages: dedupe(pages), nonPageEndpoints: dedupe(nonPageEndpoints), viewerBound: dedupe(viewerBound) };
}

// ── THE PIN. 56 guest pages, 39 non-page endpoints and 1 viewer-bound page as
// resolved from the route table captured 2026-09-14 (refreshed after the reads lane
// opened the economy, sim console and operator read pages to guests). If route-list.json is
// refreshed and the derivation changes, these arrays must be updated
// deliberately (that is the point). ──
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
    '/economy',
    '/economy/agreements',
    '/economy/exchange',
    '/economy/help',
    '/economy/joint-ledgers',
    '/economy/market',
    '/economy/resident-agreements',
    '/economy/stipend',
    '/economy/treasury',
    '/economy/units',
    '/economy/wallet',
    '/economy/work',
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
    '/operator/federation',
    '/operator/login',
    '/operator/operations',
    '/organizations',
    '/reach',
    '/register',
    '/rooms',
    '/setup',
    '/setup/bootstrap',
    '/setup/join',
    '/setup/mode',
    '/setup/operator',
    '/simworld',
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
    '/api/public-records/legislatures',
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
    '/api/simworld/progress',
    '/api/simworld/rails',
    '/federation/cluster/sync-progress',
    '/oauth/jwks',
    '/oauth/userinfo',
    '/up',
];

export const PIN_VIEWER_BOUND = ['/people'];

// Auth walls: a guest page that redirects here needs a signed-in session and is
// recorded NOT ESTABLISHED by name.
export const AUTH_WALLS = ['/login', '/register', '/operator/login'];

// ─────────────────────────────────────────────────────────────────────────
// FULL ROSTER (operator standard: WCAG 2.1 AA on EVERY page, 2026-09-15).
// deriveGuestPages() above stays unchanged (its three pins hold). deriveRoster()
// extends the same route-table derivation to four classes for the whole app:
//   guestPages      param-free page routes a guest opens (== deriveGuestPages.pages)
//   signedInPages   param-free page routes gated by auth-class middleware
//   paramPages      page routes carrying {params} (each with its parameter names)
//   machineEndpoints non-page routes (api/, _matrix/, horizon/, oauth/, storage/,
//                    .well-known/, up, file suffixes, the sync-progress poll)
// The classification is by RULE over the route table, never a hand list.
// ─────────────────────────────────────────────────────────────────────────

// Machine (non-page) endpoints: JSON/data APIs, discovery documents, dashboards
// served as data, file downloads, tiles and the health probe. Classified by URI
// pattern, which outranks page classification (a machine URI is never a page).
// Returns the exclusion reason for the roster, or null when the URI is a page.
export function machineReason(u) {
    if (/^\/api\//.test(u)) return 'api/ endpoint (JSON)';
    if (/^\/_matrix\//.test(u)) return '_matrix/ appservice endpoint';
    if (/^\/horizon(\/|$)/.test(u)) return 'horizon/ dashboard data endpoint';
    if (/^\/oauth\//.test(u)) return 'oauth/ endpoint';
    if (/^\/storage\//.test(u)) return 'storage/ file';
    if (/^\/\.well-known\//.test(u)) return '.well-known/ discovery document';
    if (u === '/up') return 'health probe';
    if (u === '/federation/cluster/sync-progress') return 'sync-progress poll (JSON)';
    // JSON polls that live outside /api (verified 2026-09-15: application/json).
    if (u === '/system/translations/progress') return 'translations progress poll (JSON)';
    if (u === '/dev/playtest/state' || u === '/dev/scenario/state') return 'dev state poll (JSON)';
    const m = u.match(/\.(csv|geojson|png|json)$/);
    if (m) return `${m[1]} file download`;
    return null;
}
export function isMachine(u) {
    return machineReason(u) !== null;
}

// Extract the ordered parameter names of a route URI template, dropping the
// optional marker. '/learn/{track}/{module?}' -> ['track','module'].
export function paramNames(u) {
    return (u.match(/\{([^}]+)\}/g) || []).map((s) => s.replace(/[{}?]/g, ''));
}

const byUriTpl = (a, b) => (a.uri < b.uri ? -1 : a.uri > b.uri ? 1 : 0);
function dedupeBy(arr) {
    const seen = new Set();
    return arr.filter((x) => (seen.has(x.uri) ? false : (seen.add(x.uri), true))).sort(byUriTpl);
}

export function deriveRoster() {
    const guestPages = deriveGuestPages().pages; // the existing 56, unchanged
    const signedInPages = [];
    const paramPages = [];
    const machineEndpoints = [];
    const viewerBound = [];
    for (const r of TABLE) {
        const u = r.uri.startsWith('/') ? r.uri : '/' + r.uri;
        const name = r.name || (u === '/' ? 'root' : u);
        const reason = machineReason(u);
        if (reason) {
            machineEndpoints.push({ uri: u, name, reason });
            continue;
        }
        if (u.includes('{')) {
            paramPages.push({ uri: u, name, params: paramNames(u) });
            continue;
        }
        if (VIEWER_BOUND.has(u)) {
            viewerBound.push({ uri: u, name });
            continue;
        }
        if ((r.middleware || []).some(isAuthClass)) {
            signedInPages.push({ uri: u, name });
            continue;
        }
        // else: an authless param-free page — already in guestPages.
    }
    return {
        guestPages,
        signedInPages: dedupeBy(signedInPages),
        paramPages: dedupeBy(paramPages),
        machineEndpoints: dedupeBy(machineEndpoints),
        viewerBound: dedupeBy(viewerBound),
    };
}

// ── THE PINS for the full roster, resolved from route-list.json captured
// 2026-09-14. PIN_SIGNED_IN and PIN_PARAM are URI templates asserted for deep
// equality; PIN_MACHINE is the machine-endpoint count (that class is large and
// volatile, so a count guards it against drift). A route added, removed or
// re-guarded flips the derivation and breaks a pin, forcing a deliberate update. ──
export const PIN_SIGNED_IN = [
    '/board',
    '/civic',
    '/civic/halls',
    '/civic/identity',
    '/civic/jurisdictions/search',
    '/civic/petitions',
    '/civic/record',
    '/civic/relocation',
    '/civic/residency',
    '/civic/rooms',
    '/civic/rooms/new',
    '/civic/square',
    '/constitutional-challenges',
    '/dev/electoral-kit',
    '/dev/executive-kit',
    '/dev/judiciary-kit',
    '/dev/legislature-kit',
    '/dev/users',
    '/elections',
    '/elections/board',
    '/elections/candidacy',
    '/elections/countback',
    '/elections/open-ballot',
    '/elections/ranked-ballot',
    '/elections/results',
    '/judiciary/advocate',
    '/judiciary/docket',
    '/operator',
    '/operator/console',
    '/operator/dns',
    '/operator/identity',
    '/operator/mesh',
    '/operator/moderation',
    '/operator/operations/apply-status',
    '/operator/roles',
    '/operator/versioning',
    '/organizations/co-determination',
    '/organizations/transfers-conversions',
    '/support/tickets',
    '/system/amendments',
    '/system/audit-chain',
    '/system/translations',
];

export const PIN_PARAM = [
    '/bills/{bill}',
    '/bills/{bill}/conversation',
    '/candidates/{candidacy}',
    '/cases/{case}',
    '/civic/petitions/{petition}',
    '/civic/rooms/{space}',
    '/committees/{committee}',
    '/constitutional-challenges/{challenge}',
    '/departments/{department}',
    '/departments/{department}/reporting',
    '/economy/agreements/{contract}',
    '/economy/help/{assistance}',
    '/economy/market/{listing}',
    '/economy/requests/{posting}',
    '/elections/{election}',
    '/elections/{election}/candidacy',
    '/elections/{election}/open-ballot',
    '/elections/{election}/ranked-ballot',
    '/elections/{election}/results',
    '/executive/{sub?}',
    '/executives/{executive}',
    '/executives/{executive}/actions',
    '/executives/{executive}/departments',
    '/i/{token}',
    '/journeys/{id}',
    '/judiciaries/{judiciary}',
    '/judiciaries/{judiciary}/docket',
    '/judiciary/jury/{summons}',
    '/judiciary/{sub?}',
    '/jurisdictions/{jurisdiction}',
    '/jurisdictions/{jurisdiction}/map',
    '/learn/manage/{module}',
    '/learn/{track}/{module?}',
    '/legislature/{sub?}',
    '/legislatures/{legislature_id}',
    '/legislatures/{legislature_id}/districts',
    '/legislatures/{legislature_id}/panels',
    '/legislatures/{legislature_id}/type-b-map',
    '/legislatures/{legislature}/bills',
    '/legislatures/{legislature}/chamber',
    '/legislatures/{legislature}/committees',
    '/legislatures/{legislature}/emergency-powers',
    '/legislatures/{legislature}/institution-acts',
    '/legislatures/{legislature}/oversight',
    '/legislatures/{legislature}/referendums',
    '/legislatures/{legislature}/session',
    '/legislatures/{legislature}/sessions',
    '/legislatures/{legislature}/settings',
    '/legislatures/{legislature}/speaker',
    '/organizations/{organization}',
    '/organizations/{organization}/board-elections',
    '/organizations/{organization}/cgc',
    '/organizations/{organization}/delegations',
    '/organizations/{organization}/economy',
    '/rooms/board/{board}',
    '/rooms/chamber/{legislature}',
    '/rooms/committee/{meeting}',
    '/rooms/court/{case}',
    '/setup/step/{n}',
    '/support/ticket/{ref}',
    '/system/translations/review/{locale}',
    '/vacancies/{vacancy}',
];

// Machine-endpoint count (api/, _matrix/, horizon/, oauth/, storage/,
// .well-known/, up, file suffixes, sync-progress) across param and param-free.
export const PIN_MACHINE = 90;

// Print the derived counts when run directly: node tests/browser/roster/roster.mjs
if (import.meta.url === `file://${process.argv[1]}` || import.meta.url === pathToFileUrlSafe(process.argv[1])) {
    const R = deriveRoster();
    // eslint-disable-next-line no-console
    console.log(
        `roster: guestPages=${R.guestPages.length} signedInPages=${R.signedInPages.length} ` +
            `paramPages=${R.paramPages.length} machineEndpoints=${R.machineEndpoints.length} viewerBound=${R.viewerBound.length}`,
    );
}
function pathToFileUrlSafe(p) {
    try {
        return p ? new URL('file://' + (p.startsWith('/') ? '' : '/') + p.replace(/\\/g, '/')).href : '';
    } catch {
        return '';
    }
}
