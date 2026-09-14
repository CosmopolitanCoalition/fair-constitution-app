// D1 · final rehearsal — the presenter journey as DATA.
//
// This module is the single machine-readable source the runner
// (scripts/demo/d1_rehearsal.mjs) reads. docs/demo/D1_REHEARSAL_PLAN.md is the
// human copy of the same steps and must be kept in agreement with this file.
//
// Prepare-only. The rehearsal itself is BLOCKED (see BLOCKED_BY). No step here
// performs a write on the live app: read steps open a guest-readable page and
// assert it renders; auth-wall steps assert a guest is redirected to /login;
// action steps are explicit not-implemented stubs that refuse to run without a
// demo session and are never executed by --plan or --dry-run.
//
// Route facts drawn from routes/web.php and routes/auth.php at the commit this
// file was written against; re-open those files before trusting a path.

// The exact rows this rehearsal waits on, verbatim from the review register row
// "D1 final rehearsal" and the campaign plan section 4. All deferred by
// operator ruling 2026-09-14.
export const BLOCKED_BY = [
    {
        row: 'Setup · worlds',
        unblocked_by: 'operator GO (BENCHMARK_GO_GATE absolute) + a seeded simulated world',
        why: 'No simulated world is seeded, so the world-watch pages carry no rich data and the action journey has no live election, legislature or case to act on.',
    },
    {
        row: 'Mesh/rooms · TLS',
        unblocked_by: 'Linux host + Docker Engine + DNS + cert (Caddy/Synapse/MAS/LiveKit)',
        why: 'The live commons (Matrix square/halls) and voice (LiveKit) need the public TLS overlay; without it the commons degrade to empty and rooms media is absent.',
    },
    {
        row: 'Host · rollout / Host · internet',
        unblocked_by: 'a provisioned Linux conference host with public DNS/TLS/TURN (cloud box OFF since 2026-09-10)',
        why: 'The presenter drives the demo on a deployed public host over the internet; box E is a dev/test box only and no conference host is provisioned.',
    },
];

// Additional precondition recorded by the campaign plan (section 1/4): every
// non-blocked S1 predecessor review must be passing before D1 runs.
export const PRECONDITIONS = [
    'All non-blocked S1 predecessor reviews passing (campaign plan section 4).',
];

export const ACTORS = [
    { id: 'guest', role: 'Unauthenticated visitor', note: 'Reads every public page. No session, no writes. This is the only actor the dry-run drives.' },
    { id: 'presenter', role: 'Signed-in demo operator', note: 'Registers on the beta box (instant residency, CGA_RESIDENCY_INSTANT), which opens a demo session. Every filing is a real write captured against the demo session and voided at logout. Never driven by this lane.' },
    { id: 'audience', role: 'Conference viewers', note: 'Watch the presenter. Not a software actor; present for narration only.' },
];

// mode:
//   'read'      — GET a guest-readable page; dry-run opens it and asserts render.
//   'auth-wall' — GET a page that must redirect a guest to /login; dry-run asserts the redirect.
//   'action'    — a write; requires a demo session; NEVER executed here (stub).
// discover: optional runtime resolver ('place' | 'placeMap' | 'lesson') used when
//   the route needs a real id/slug the runner reads from an index page.
export const STEPS = [
    // ── Phase 1 · arrival & framing (guest reads) ─────────────────────────────
    { id: 'D1-01', phase: 'arrival', actor: 'guest', mode: 'read', route: '/', name: 'root',
      title: 'Open the front door (guest cover)',
      expectNav: 'Home cover renders for a guest; a signed-in user would 302 to /civic (routes/web.php:50-54).',
      expectRefusal: null, form: null },
    { id: 'D1-02', phase: 'arrival', actor: 'guest', mode: 'read', route: '/launchpad', name: 'launchpad',
      title: 'Show the arrival hub',
      expectNav: 'Launchpad renders; public, no auth (routes/web.php:61).', expectRefusal: null, form: null },
    { id: 'D1-03', phase: 'arrival', actor: 'guest', mode: 'read', route: '/tour', name: 'tour',
      title: 'Open the guided tour index',
      expectNav: 'Tour index renders; public (routes/web.php:62).', expectRefusal: null, form: null },
    { id: 'D1-04', phase: 'arrival', actor: 'guest', mode: 'read', route: '/explore', name: 'roles.explore',
      title: 'Browse the role explorer',
      expectNav: 'Role explorer renders; public (routes/web.php:63).', expectRefusal: null, form: null },

    // ── Phase 2 · watch the world (guest reads; depends on Setup·worlds data) ──
    { id: 'D1-05', phase: 'world', actor: 'guest', mode: 'read', route: '/atlas', name: 'atlas.index',
      title: 'Open the world atlas',
      expectNav: 'Atlas renders from the nightly world_stats rollup; public read (routes/web.php:357). A withheld figure renders as a gap, never a zero.',
      expectRefusal: null, form: null, dataFrom: 'Setup · worlds' },
    { id: 'D1-06', phase: 'world', actor: 'guest', mode: 'read', route: '/building', name: 'build.progress',
      title: 'Show how much of the world exists',
      expectNav: 'Build screen renders counts only; public read (routes/web.php:338).', expectRefusal: null, form: null, dataFrom: 'Setup · worlds' },
    { id: 'D1-07', phase: 'world', actor: 'guest', mode: 'read', route: '/reach', name: 'reach.index',
      title: 'Show the enrolment gauge',
      expectNav: 'Reach gauge renders the nightly snapshot; read-only by design, no lever (routes/web.php:348).', expectRefusal: null, form: null, dataFrom: 'Setup · worlds' },
    { id: 'D1-08', phase: 'world', actor: 'guest', mode: 'read', route: '/jurisdictions', name: 'jurisdictions.index',
      title: 'Open the jurisdictions index',
      expectNav: 'Jurisdictions index renders; public (routes/web.php:272).', expectRefusal: null, form: null, dataFrom: 'Setup · worlds' },
    { id: 'D1-09', phase: 'world', actor: 'guest', mode: 'read', route: null, discover: 'place', name: 'jurisdictions.show',
      title: "Open a place's own page",
      expectNav: 'Place page renders at /jurisdictions/{slug}; every jurisdiction link lands on the place, not the map (routes/web.php:326, operator 2026-09-10).',
      expectRefusal: null, form: null, dataFrom: 'Setup · worlds' },
    { id: 'D1-10', phase: 'world', actor: 'guest', mode: 'read', route: null, discover: 'placeMap', name: 'jurisdictions.map',
      title: "Open that place's full-bleed map",
      expectNav: 'Map viewer renders at /jurisdictions/{slug}/map (routes/web.php:327).', expectRefusal: null, form: null, dataFrom: 'Setup · worlds' },
    { id: 'D1-11', phase: 'world', actor: 'guest', mode: 'read', route: '/federation', name: 'federation.between',
      title: 'Open Between Governments (read-only citizen view)',
      expectNav: 'Federation view renders; read-only citizen view, public (routes/web.php:757, ruling §10 item 9).', expectRefusal: null, form: null, dataFrom: 'Setup · worlds' },
    { id: 'D1-12', phase: 'world', actor: 'guest', mode: 'read', route: '/jurisdictions/union-formation', name: 'jurisdictions.union',
      title: 'Read a lifecycle door (union formation)',
      expectNav: 'Union-formation page renders; reads are public, the two write doors are auth + chamber-seat guarded (routes/web.php:281, 288).',
      expectRefusal: 'The propose door (F-LEG-029) is auth + seat gated; a guest sees the read view only.', form: null, dataFrom: 'Setup · worlds' },

    // ── Phase 3 · learn & media (guest reads) ─────────────────────────────────
    { id: 'D1-13', phase: 'learn', actor: 'guest', mode: 'read', route: '/learn', name: 'learn.home',
      title: 'Open the education plane',
      expectNav: 'Learn home renders; reading is open to everyone, guests included (routes/web.php:391, §5.0.2).', expectRefusal: null, form: null },
    { id: 'D1-14', phase: 'learn', actor: 'guest', mode: 'read', route: '/learn/guides', name: 'learn.guides',
      title: 'Open the learn guides',
      expectNav: 'Guides render; public (routes/web.php:392).', expectRefusal: null, form: null },
    { id: 'D1-15', phase: 'learn', actor: 'guest', mode: 'read', route: null, discover: 'lesson', name: 'learn.lesson',
      title: 'Open one lesson',
      expectNav: 'Lesson renders at /learn/{track}/{module}; reading is open, the CHECK is authed + throttled (routes/web.php:403-410).',
      expectRefusal: 'Answering (F-EDU-001) requires a session; a guest reads the lesson but cannot submit the check.', form: null },
    { id: 'D1-16', phase: 'learn', actor: 'guest', mode: 'read', route: '/videos', name: 'videos',
      title: 'Open the video library (multi-track player)',
      expectNav: 'Video library renders; public (routes/web.php:69). Media source is config/cga/media.php CGA_MEDIA_BASE_URL; null renders the poster placeholder.',
      expectRefusal: null, form: null },
    { id: 'D1-17', phase: 'learn', actor: 'guest', mode: 'read', route: '/achievements', name: 'achievements.index',
      title: 'Open the achievements catalog',
      expectNav: 'Achievements render read-only; public, the earned overlay is the signed-in viewer\'s own ledger (routes/web.php:375).', expectRefusal: null, form: null },

    // ── Phase 4 · civic proceedings & records (guest reads) ───────────────────
    { id: 'D1-18', phase: 'civic', actor: 'guest', mode: 'read', route: '/legislatures', name: 'legislatures.index',
      title: 'Open the legislatures index',
      expectNav: 'Legislatures index renders; public read.', expectRefusal: null, form: null, dataFrom: 'Setup · worlds' },
    { id: 'D1-19', phase: 'civic', actor: 'guest', mode: 'read', route: '/civic/commons/square', name: 'civic.commons.square',
      title: 'Open the live commons square (Matrix)',
      expectNav: 'Commons square renders; public read, withoutMiddleware(auth) (routes/web.php:1338).',
      expectRefusal: 'Live Matrix reads degrade to empty when the homeserver is down; a fully live commons needs Mesh/rooms · TLS.', form: null, dataFrom: 'Mesh/rooms · TLS' },
    { id: 'D1-20', phase: 'civic', actor: 'guest', mode: 'read', route: '/system/public-records', name: 'system.public-records',
      title: 'Open the public records',
      expectNav: 'Public records render; public system surface.', expectRefusal: null, form: null },
    { id: 'D1-21', phase: 'civic', actor: 'guest', mode: 'read', route: '/system/clocks', name: 'system.clocks',
      title: 'Open the constitutional clocks',
      expectNav: 'Clocks render; public system surface.', expectRefusal: null, form: null },

    // ── Phase 5 · guest-observable refusals (nav state; assertable as a guest) ─
    { id: 'D1-22', phase: 'refusals', actor: 'guest', mode: 'auth-wall', route: '/civic', name: 'civic.home',
      title: 'Confirm the authenticated landing walls a guest',
      expectNav: 'A guest GET of /civic redirects to /login (routes/web.php:1302 auth group).',
      expectRefusal: '/civic is auth-only; guest is bounced to /login.', form: null },
    { id: 'D1-23', phase: 'refusals', actor: 'guest', mode: 'auth-wall', route: '/elections', name: 'elections.index',
      title: 'Confirm the elections plane walls a guest',
      expectNav: 'A guest GET of /elections redirects to /login (routes/web.php:636 auth group).',
      expectRefusal: '/elections is auth-only; guest is bounced to /login.', form: null },
    { id: 'D1-24', phase: 'refusals', actor: 'guest', mode: 'auth-wall', route: '/civic/residency', name: 'civic.residency',
      title: 'Confirm residency claim walls a guest',
      expectNav: 'A guest GET of /civic/residency redirects to /login (routes/web.php:1310 auth group).',
      expectRefusal: '/civic/residency is auth-only; guest is bounced to /login.', form: null },
    { id: 'D1-25', phase: 'refusals', actor: 'guest', mode: 'auth-wall', route: '/simworld', name: 'simworld.console',
      title: 'Confirm the sim console walls a guest',
      expectNav: 'A guest GET of /simworld redirects to /login (routes/web.php:773 auth group).',
      expectRefusal: '/simworld is auth-only; guest is bounced to /login.', form: null },

    // ── Phase 6 · the demo session journey (ACTIONS — stubs, never executed) ──
    { id: 'D1-26', phase: 'session', actor: 'presenter', mode: 'action', route: '/register', name: 'register.store',
      title: 'Presenter registers on the beta box (opens a demo session)',
      expectNav: 'Registration creates the founder/presenter account and, on a scale_demo box, opens a demo session (DemoSessionService::currentId).',
      expectRefusal: 'Requires creating an account and typing a password — prohibited for this lane and never executed.', form: 'F-IND-001',
      requiresDemoSession: false, opensDemoSession: true },
    { id: 'D1-27', phase: 'session', actor: 'presenter', mode: 'action', route: '/civic/residency/declare', name: 'residency.declare',
      title: 'Claim residency (instant on the beta box)',
      expectNav: 'Declare then confirm; CGA_RESIDENCY_INSTANT confirms at once, unlocking voting and candidacy (absolute rights, Art. I).',
      expectRefusal: 'Requires a demo session and a form submission; not implemented, refuses without a session.', form: 'F-IND-003',
      requiresDemoSession: true },
    { id: 'D1-28', phase: 'session', actor: 'presenter', mode: 'action', route: '/elections/{election}/approvals', name: 'elections.approvals.store',
      title: 'Cast an open-ballot approval',
      expectNav: 'Approval recorded for the open ballot; needs a live election from Setup · worlds.',
      expectRefusal: 'Requires a demo session, a seated election and a form submission; not implemented.', form: null,
      requiresDemoSession: true, dataFrom: 'Setup · worlds' },
    { id: 'D1-29', phase: 'session', actor: 'presenter', mode: 'action', route: '/elections/{election}/races/{race}/ballots', name: 'elections.ballots.store',
      title: 'Cast a ranked (STV) ballot',
      expectNav: 'Ranked ballot committed via the two-phase open-ballot scheme; needs a live race from Setup · worlds.',
      expectRefusal: 'Requires a demo session, a seated race and a form submission; not implemented.', form: 'F-IND-007',
      requiresDemoSession: true, dataFrom: 'Setup · worlds' },
    { id: 'D1-30', phase: 'session', actor: 'presenter', mode: 'action', route: '/civic/petitions', name: 'civic.petitions.store',
      title: 'File a petition',
      expectNav: 'Petition filed (F-IND-009).',
      expectRefusal: 'Requires a demo session and a form submission; not implemented.', form: 'F-IND-009',
      requiresDemoSession: true },
    { id: 'D1-31', phase: 'session', actor: 'presenter', mode: 'action', route: '/civic/square', name: 'civic.square.store',
      title: 'Post to the public square',
      expectNav: 'Post recorded on the uncensorable square (F-SOC-001); open to any player.',
      expectRefusal: 'Requires a demo session and a form submission; not implemented.', form: 'F-SOC-001',
      requiresDemoSession: true },
    { id: 'D1-32', phase: 'session', actor: 'presenter', mode: 'action', route: '/bicameral-act', name: 'bicameral.refusal',
      title: 'Demonstrate the empty second-chamber refusal',
      expectNav: 'A bicameral act (create executive/judiciary, enact a bill) is attempted while the Type B chamber is unseated.',
      expectRefusal: 'Both chambers must independently agree; an empty Type B chamber correctly blocks the act until the first election seats it (runbook Part D point 2). Requires a demo session and seated data; not implemented.', form: null,
      requiresDemoSession: true, dataFrom: 'Setup · worlds' },
    { id: 'D1-33', phase: 'session', actor: 'presenter', mode: 'action', route: '/logout', name: 'logout',
      title: 'Log out — demo session voided, writes reversed',
      expectNav: 'Logout ends the demo session (DemoSessionService::endCurrent), voiding every write filed during the session (AuthenticatedSessionController:97).',
      expectRefusal: 'Requires an established demo session; not implemented.', form: null,
      requiresDemoSession: true },
];
