/* ============================================================================
   CGA — registry/surfaces.js  (Phase 1 of docs/plans/mockups-v3-wiring/MASTER_PLAN.md)

   THE single machine source for the v2 player chrome: the player-tier nav,
   the full two-tier sitemap, the guided tour, and the Learn drawer's
   plain-language "about" text. The v2 shell (Layouts/AppShellV2.vue), the
   tour composable (composables/useTour.js), and the coverage instrument all
   read from HERE — never from parallel hardcoded lists.

   The design contract is mockups/v3 (its shell-v2.js TOUR + two-tier menu).
   Every entry carries `contract:` — the mockup rel it implements — so the
   registry can always be diffed against the 107-screen contract. `href: null`
   means the surface is not wired yet: the menu renders it as
   "Planned · Phase N" (MASTER_PLAN phase number) and the tour skips it.
   As phases land, entries flip from null to a real route and the tour grows
   toward the mockups' full 117-stop walkthrough (the 10a acceptance list).

   Role-gated app surfaces carry `roles:` (mirrors Navigation/nav.js
   enabledRoles) so the menu can show the prereq hint instead of a 403 link.
   ============================================================================ */

/* ---------------------------------------------------------------- tier 1
   The player tier — where you actually go. Mirrors the mockups' "Go". */
export const PLAYER_NAV = [
    { id: 'home', label: 'Home', icon: 'home', href: '/civic', contract: 'civic/today.html', phase: 3 },
    { id: 'atlas', label: 'The Atlas', icon: 'globe', href: null, contract: 'atlas.html', phase: 7 },
    { id: 'public-square', label: 'The square', icon: 'users', href: '/civic/square', contract: 'social/social-home.html', phase: 3 },
    { id: 'rooms', label: 'Messages', icon: 'message-square', href: '/civic/rooms', contract: 'groups/groups-home.html', phase: 3 },
    { id: 'commons-square', label: 'Live rooms', icon: 'landmark', href: '/civic/commons/square', contract: 'shared/live-room.html?variant=group', phase: 6 },
    { id: 'jurisdiction-browser', label: 'Places', icon: 'map', href: '/jurisdictions', contract: 'jurisdictions/jurisdiction-browser.html', phase: 5 },
    { id: 'market', label: 'Market', icon: 'bar-chart', href: '/economy', contract: 'economy/economy-home.html' },
    { id: 'my-record', label: 'My profile', icon: 'user', href: '/civic/record', contract: 'civic/my-civic-life.html' },
    { id: 'journeys', label: 'Journeys', icon: 'list-checks', href: '/journeys', contract: 'index.html#journeys-h' },
    { id: 'learn', label: 'Learn & help', icon: 'graduation-cap', href: null, contract: 'learn/learn-home.html', phase: 7 },
    /* 'tour' is special-cased by the menu: the sentinel 'tour:start' renders a
       TOGGLE that arms the tour MODE in place (A2) — it does not navigate. */
    { id: 'tour', label: 'Guided tour', icon: 'map', href: 'tour:start', contract: 'tour.html', phase: 1 },
];

/* ---------------------------------------------------------------- tier 2
   Every screen — the full design-contract sitemap, collapsed under the
   player tier ("All screens — the full map"). Section keys/titles mirror
   mockups/v3 shell-v2.js sidebarNavInner(). */
export const SITEMAP = [
    { key: 'rooms', title: 'Rooms & the square', items: [
        { id: 'public-square', label: 'The public square', icon: 'users', href: '/civic/square', contract: 'social/social-home.html' },
        { id: 'halls', label: 'The halls (testimony)', icon: 'landmark', href: '/civic/halls', contract: 'shared/live-room.html?variant=townhall', phase: 6 },
        { id: 'commons-square', label: 'The live square', icon: 'message-square', href: '/civic/commons/square', contract: 'shared/live-room.html?variant=group', phase: 6 },
        { id: 'commons-halls', label: 'Live halls', icon: 'landmark', href: '/civic/commons/halls', contract: 'shared/live-room.html?variant=committee', phase: 6 },
        { id: 'rooms', label: 'Private rooms & messages', icon: 'lock', href: '/civic/rooms', contract: 'groups/groups-home.html', phase: 3 },
        { id: 'petitions', label: 'Petitions', icon: 'file-text', href: '/civic/petitions', contract: 'civic/petitions.html' },
    ] },
    { key: 'me', title: 'Me & my account', items: [
        { id: 'launchpad', label: 'Launchpad (the cover)', icon: 'globe', href: '/launchpad', contract: 'index.html' },
        { id: 'join', label: 'You’re invited (arrival)', icon: 'user', href: null, contract: 'civic/join.html', phase: 3 },
        { id: 'onboarding', label: 'Create your account', icon: 'user', href: '/register', contract: 'civic/onboarding.html' },
        { id: 'residency', label: 'Say where you live', icon: 'map-pin', href: '/civic/residency', contract: 'civic/residency.html' },
        { id: 'relocation', label: 'Move somewhere new', icon: 'map', href: '/civic/relocation', contract: 'civic/relocation.html' },
        { id: 'my-record', label: 'My record', icon: 'file-text', href: '/civic/record', contract: 'civic/my-civic-life.html' },
        { id: 'achievements', label: 'Achievements', icon: 'award', href: '/achievements', contract: 'social/achievements.html' },
    ] },
    { key: 'elections', title: 'A place’s elections', items: [
        { id: 'candidacy', label: 'Stand for office', icon: 'user', href: '/elections/candidacy', contract: 'electoral/candidacy-registration.html' },
        { id: 'elections', label: 'An election', icon: 'clock', href: '/elections', contract: 'electoral/election-detail.html' },
        { id: 'open-ballot', label: 'Open ballot', icon: 'vote', href: '/elections/open-ballot', contract: 'electoral/open-ballot.html' },
        { id: 'ranked-ballot', label: 'Ranked ballot', icon: 'check', href: '/elections/ranked-ballot', contract: 'electoral/ranked-ballot.html' },
        { id: 'results', label: 'Results', icon: 'bar-chart', href: '/elections/results', contract: 'electoral/results.html' },
        { id: 'vacancy-countback', label: 'Filling an empty seat', icon: 'refresh-cw', href: '/elections/countback', contract: 'electoral/vacancy-countback.html', roles: ['R-08'] },
        { id: 'election-board-console', label: 'The election board', icon: 'shield', href: '/elections/board', contract: 'electoral/election-board-console.html', roles: ['R-08'] },
    ] },
    { key: 'chamber', title: 'A place’s chamber', items: [
        { id: 'legislature-home', label: 'The chamber', icon: 'landmark', href: '/legislature', contract: 'legislature/legislature-home.html', roles: ['R-09', 'R-10', 'R-11', 'R-12', 'R-13', 'R-29'] },
        { id: 'bills', label: 'Bills', icon: 'file-text', href: '/legislature/bills', contract: 'legislature/bills.html', roles: ['R-09', 'R-10', 'R-11', 'R-12', 'R-13'] },
        { id: 'committees', label: 'Committees', icon: 'users', href: '/legislature/committees', contract: 'legislature/committees.html', roles: ['R-09', 'R-10', 'R-11', 'R-12', 'R-13'] },
        { id: 'referendums', label: 'Referendums', icon: 'vote', href: '/legislature/referendums', contract: 'legislature/referendums.html', roles: ['R-09', 'R-10'] },
        { id: 'emergency-powers', label: 'Emergency powers', icon: 'alert-triangle', href: '/legislature/emergency-powers', contract: 'legislature/emergency-powers.html', roles: ['R-09', 'R-10'] },
        { id: 'oversight', label: 'Ethics & removals', icon: 'shield', href: '/legislature/oversight', contract: 'legislature/oversight.html', roles: ['R-09', 'R-10', 'R-29'] },
        { id: 'session-console', label: 'Run a session (Speaker)', icon: 'users', href: '/legislature/session', contract: 'legislature/session-console.html', roles: ['R-09', 'R-10'] },
        { id: 'speaker-tools', label: 'The Speaker', icon: 'landmark', href: '/legislature/speaker-tools', contract: 'legislature/speaker-tools.html', roles: ['R-10'] },
        { id: 'settings', label: 'The chamber’s rules', icon: 'sliders', href: '/legislature/settings', contract: 'legislature/settings.html', roles: ['R-09', 'R-10'] },
    ] },
    { key: 'executive', title: 'A place’s executive', items: [
        { id: 'executive-home', label: 'The executive', icon: 'briefcase', href: '/executive', contract: 'executive/executive-home.html', roles: ['R-14', 'R-15', 'R-16', 'R-17'] },
        { id: 'departments', label: 'Departments', icon: 'building', href: '/executive/departments', contract: 'executive/departments.html', roles: ['R-14', 'R-15', 'R-16', 'R-30'] },
        { id: 'executive-actions', label: 'Executive actions', icon: 'file-text', href: '/executive/actions', contract: 'executive/executive-actions.html', roles: ['R-14', 'R-15', 'R-16'] },
        { id: 'department-reporting', label: 'Department reporting', icon: 'bar-chart', href: '/executive/reporting', contract: 'executive/department-reporting.html', roles: ['R-18'] },
    ] },
    { key: 'courts', title: 'A place’s courts', items: [
        { id: 'public-docket', label: 'The docket', icon: 'scale', href: '/judiciary/docket', contract: 'judiciary/case-docket.html' },
        { id: 'judiciary-home', label: 'The courts', icon: 'scale', href: '/judiciary', contract: 'judiciary/judiciary-home.html', roles: ['R-19', 'R-20', 'R-21', 'R-22'] },
        { id: 'constitutional-challenge', label: 'Challenge a law', icon: 'scale', href: '/judiciary/challenges', contract: 'judiciary/constitutional-challenge.html', roles: ['R-19', 'R-20', 'R-21'] },
        { id: 'advocate-console', label: 'The advocate console', icon: 'briefcase', href: '/judiciary/advocate', contract: 'judiciary/advocate-console.html', roles: ['R-21'] },
        { id: 'juror-view', label: 'A juror’s view', icon: 'users', href: '/judiciary/jury', contract: 'judiciary/juror-view.html', roles: ['R-22'] },
    ] },
    { key: 'organizations', title: 'Organizations', items: [
        { id: 'org-registry', label: 'The registry', icon: 'building', href: '/organizations', contract: 'organizations/org-registry.html' },
        { id: 'co-determination', label: 'Worker seats on the board', icon: 'users', href: '/organizations/co-determination', contract: 'organizations/co-determination.html' },
        { id: 'transfers-conversions', label: 'Ownership changes', icon: 'refresh-cw', href: '/organizations/transfers-conversions', contract: 'organizations/transfers-conversions.html' },
        { id: 'board-elections', label: 'Board elections', icon: 'vote', href: null, contract: 'organizations/board-elections.html', phase: 2 },
    ] },
    { key: 'places', title: 'Places & their processes', items: [
        { id: 'jurisdiction-browser', label: 'Places', icon: 'globe', href: '/jurisdictions', contract: 'jurisdictions/jurisdiction-browser.html' },
        { id: 'legislatures', label: 'Legislatures & districts', icon: 'map', href: '/legislatures', contract: 'jurisdictions/district-mapper.html', phase: 5 },
        { id: 'reach', label: 'Reach', icon: 'bar-chart', href: '/reach', contract: 'social/legitimacy.html', phase: 7 },
        { id: 'bootstrap', label: 'Wake a place up', icon: 'globe', href: '/jurisdictions/bootstrap', contract: 'jurisdictions/bootstrap.html' },
        { id: 'union-formation', label: 'Merge places into a union', icon: 'users', href: '/jurisdictions/union-formation', contract: 'jurisdictions/union-formation.html' },
        { id: 'disintermediation', label: 'Remove a middle layer', icon: 'globe', href: '/jurisdictions/disintermediation', contract: 'jurisdictions/disintermediation.html' },
        { id: 'restoration', label: 'Rebuild a lost government', icon: 'refresh-cw', href: '/jurisdictions/restoration', contract: 'jurisdictions/restoration.html' },
        { id: 'federation', label: 'Between governments', icon: 'globe', href: '/federation', contract: 'jurisdictions/federation.html', phase: 4 },
    ] },
    /* Order follows the mockup sidebar (shell-v2.js "Market · planned"), which
       declares NINE surfaces; the registry carried four. The five that were
       missing had no row at all, so MenuNav — which iterates only PLAYER_NAV
       and SITEMAP — could not render them even as "Planned": they were
       invisible rather than greyed.

       `href: null` IS the planned flag (there is no `planned:` key), so a row
       with no route behind it is an honest placeholder, not a dead link.
       Lane 13's v1 deliberately ships neither `exchange` (it trades org shares,
       and no ordinary org's cap table can be populated yet) nor `agreements` /
       `joint-ledgers` (tables exist, no read surface). `stipend` has no route
       of its own — it reads on the economy home page. */
    { key: 'market', title: 'Market', items: [
        { id: 'exchange', label: 'The exchange', icon: 'bar-chart', href: null, contract: 'economy/exchange.html', phase: 8 },
        { id: 'marketplace', label: 'The open market', icon: 'building', href: '/economy/market', contract: 'economy/marketplace.html' },
        { id: 'agreements', label: 'Agreements', icon: 'file-text', href: '/economy/agreements', contract: 'economy/agreements.html' },
        { id: 'wallet', label: 'My wallet', icon: 'lock', href: '/economy/wallet', contract: 'economy/wallet.html' },
        { id: 'joint-ledgers', label: 'Joint ledgers', icon: 'users', href: '/economy/joint-ledgers', contract: 'economy/joint-ledgers.html' },
        { id: 'units', label: 'Units & money', icon: 'sliders', href: '/economy/units', contract: 'economy/units.html' },
        { id: 'stipend', label: 'The civic stipend', icon: 'refresh-cw', href: '/economy/stipend', contract: 'economy/stipend.html' },
        { id: 'treasury', label: 'Public finance', icon: 'bar-chart', href: '/economy/treasury', contract: 'economy/treasury.html' },
        { id: 'org-settings', label: 'Org economics', icon: 'building', href: null, contract: 'economy/org-settings.html', phase: 8 },
    ] },
    { key: 'help', title: 'Learn & help', items: [
        { id: 'learn', label: 'Learn & lessons', icon: 'graduation-cap', href: null, contract: 'learn/learn-home.html', phase: 7 },
        { id: 'support-report', label: 'Report an issue', icon: 'flag', href: '/support/report', contract: 'support/report.html' },
        { id: 'support-tickets', label: 'Your reports', icon: 'list-checks', href: '/support/tickets', contract: 'support/tickets.html' },
        { id: 'accessibility', label: 'Accessibility', icon: 'shield', href: '/system/accessibility', contract: 'shared/accessibility.html' },
        { id: 'constitutional-questions', label: 'Constitutional questions', icon: 'file-text', href: '/system/constitutional-questions', contract: 'shared/constitutional-questions.html' },
    ] },
    { key: 'records', title: 'Records & the clock', items: [
        { id: 'public-records', label: 'Public records', icon: 'file-text', href: '/system/public-records', contract: 'system/public-records.html' },
        { id: 'audit-chain', label: 'The audit chain', icon: 'lock', href: '/system/audit-chain', contract: 'system/audit-chain.html' },
        { id: 'amendments', label: 'Amendments', icon: 'file-text', href: '/system/amendments', contract: 'system/amendments.html' },
        { id: 'term-sync', label: 'Terms end together', icon: 'refresh-cw', href: '/system/term-sync', contract: 'system/term-sync.html' },
        { id: 'clocks', label: 'The clocks', icon: 'clock', href: '/system/clocks', contract: 'shared/clocks.html', public: true }, // public read (RULED §10-8)
    ] },
    { key: 'node', title: 'Run a node', items: [
        { id: 'setup-wizard', label: 'Found the instance', icon: 'sliders', href: '/setup', contract: 'system/setup.html' },
        { id: 'operator-home', label: 'The operator plane', icon: 'sliders', href: '/operator', contract: 'operator/operator-home.html' },
        { id: 'operator-console', label: 'The console', icon: 'landmark', href: '/operator/console', contract: 'operator/console.html' },
        { id: 'operator-roles', label: 'Roles & channels', icon: 'users', href: '/operator/roles', contract: 'operator/roles.html' },
        { id: 'operator-mesh', label: 'Mesh & peers', icon: 'globe', href: '/operator/mesh', contract: 'operator/mesh.html' },
        { id: 'operator-identity', label: 'Identity', icon: 'lock', href: '/operator/identity', contract: 'operator/identity.html' },
        { id: 'operator-versioning', label: 'Versions & upgrades', icon: 'refresh-cw', href: '/operator/versioning', contract: 'operator/versioning.html' },
        { id: 'operator-dns', label: 'DNS & certificates', icon: 'lock', href: '/operator/dns', contract: 'operator/dns.html' },
        { id: 'operator-moderation', label: 'Moderation & the legal floor', icon: 'shield', href: '/operator/moderation', contract: 'operator/moderation.html' },
        { id: 'operator-operations', label: 'Operations (legacy console)', icon: 'sliders', href: '/operator/operations', contract: 'operator/console.html' },
        { id: 'federation-console', label: 'Federation console', icon: 'globe', href: '/operator/federation', contract: 'operator/mesh.html' },
    ] },
    /* The mockup sidebar ends with this section (shell-v2.js "For the build
       team") — V3 synthesis S6. The four dev kits are app-ahead surfaces with
       no mockup rel (contract: null); they only register server-side in
       sandbox/local, so they carry `sandbox: true` and MenuNav hides them on
       a non-demo world instead of rendering a dead link. Coverage and the
       style guide have no app page yet — Planned, per the registry idiom. */
    { key: 'build-team', title: 'For the build team', items: [
        { id: 'coverage', label: 'Coverage', icon: 'check', href: null, contract: 'shared/coverage.html' },
        { id: 'styleguide', label: 'Style guide', icon: 'sliders', href: null, contract: 'shared/styleguide.html' },
        { id: 'electoral-kit', label: 'Electoral kit', icon: 'vote', href: '/dev/electoral-kit', contract: null, sandbox: true },
        { id: 'legislature-kit', label: 'Legislature kit', icon: 'landmark', href: '/dev/legislature-kit', contract: null, sandbox: true },
        { id: 'executive-kit', label: 'Executive & orgs kit', icon: 'briefcase', href: '/dev/executive-kit', contract: null, sandbox: true },
        { id: 'judiciary-kit', label: 'Judiciary kit', icon: 'scale', href: '/dev/judiciary-kit', contract: null, sandbox: true },
        { id: 'building', label: 'Build progress', icon: 'check', href: '/building', contract: null },
    ] },
];

/* ------------------------------------------------------------------ tour
   Tour-as-a-mode (operator-settled): entering any stop with ?step=N turns the
   mode on for the session; the bar follows the player to any stop, keeps
   their place on non-stops, and Exit ends it. This Phase-1 track walks the
   WIRED surfaces only — it grows every phase toward the mockups' 117-stop
   contract (the Phase-10a acceptance checklist). Stops must be reachable by
   any signed-in player (no role-gated pages). */
export const TOUR = [
    { act: 'Arrive', href: '/civic', title: 'Home', blurb: 'What’s happening now, and what’s yours to act on.' },
    { act: 'Arrive', href: '/civic/record', title: 'My record', blurb: 'Your civic life in one place — residency, votes cast (never how), and your public acts.' },
    { act: 'Arrive', href: '/journeys', title: 'Journeys', blurb: 'Learn by doing — each journey walks one real process; finishing one goes on your profile.' },
    { act: 'Arrive', href: '/civic/residency', title: 'Say where you live', blurb: 'Living in a place is the only requirement for every right here.' },
    { act: 'Your place', href: '/jurisdictions', title: 'Places', blurb: 'Every place on Earth, planet to neighborhood — each governs itself.' },
    { act: 'Your place', href: '/legislatures', title: 'Legislatures', blurb: 'Every chamber, sized by population — seats follow people.' },
    { act: 'Speak & gather', href: '/civic/square', title: 'The public square', blurb: 'Open speech on the public record — no one can quietly remove it.' },
    { act: 'Speak & gather', href: '/civic/rooms', title: 'Messages', blurb: 'Direct and group messages — private, like a ballot.' },
    { act: 'Speak & gather', href: '/civic/petitions', title: 'Petitions', blurb: 'Gather signatures to put a question to everyone.' },
    { act: 'An election', href: '/elections', title: 'An election', blurb: 'The race, its phase, and the clock.' },
    { act: 'An election', href: '/elections/open-ballot', title: 'Open ballot', blurb: 'The approval phase — quietly approve the people you’d want on the ballot.' },
    { act: 'An election', href: '/elections/ranked-ballot', title: 'Ranked ballot', blurb: 'Rank your choices — seats go out in fair shares, and no vote is wasted.' },
    { act: 'An election', href: '/elections/results', title: 'Results', blurb: 'The count, round by round — watch votes move until every seat is filled.' },
    { act: 'An election', href: '/elections/candidacy', title: 'Stand for office', blurb: 'If you live there, you can run — that’s the whole requirement.' },
    { act: 'Organizations', href: '/organizations', title: 'Organizations', blurb: 'Parties, businesses, nonprofits — one open registry, no party machinery.' },
    { act: 'The courts', href: '/judiciary/docket', title: 'The docket', blurb: 'Every case is public record — panels of judges, juries of residents.' },
    { act: 'The record', href: '/system/public-records', title: 'Public records', blurb: 'The permanent public record — it cannot be quietly edited.' },
    { act: 'The record', href: '/system/audit-chain', title: 'The audit chain', blurb: 'Every act, hash-chained — verify it yourself.' },
    { act: 'The record', href: '/system/amendments', title: 'Amendments', blurb: 'The constitution changes through exactly two doors — both in the open.' },
    { act: 'The record', href: '/system/clocks', title: 'The clocks', blurb: 'Every interval, deadline, and threshold that starts a process without anyone asking.' },
    { act: 'Help', href: '/support/report', title: 'Report an issue', blurb: 'A bug, a question, or something that needs review — file it here.' },
    /* Wave 2 pages (lanes 2/13/15) — appended, never inserted, so existing
       ?step=N links keep their positions. Only param-free landings that resolve
       on any world: record-detail pages (/economy/requests/{posting},
       /economy/agreements/{contract}, /people?who=) need a live record or eat
       ?step on redirect, so they are reached from their parents in-app, not
       here. Acts reuse earlier groups where they fit; the index consolidates. */
    { act: 'Your place', href: '/jurisdictions/bootstrap', title: 'How a place wakes up', blurb: 'The critical-population threshold and the seven-step activation that brings a place to life.' },
    { act: 'Your place', href: '/jurisdictions/union-formation', title: 'Union formation', blurb: 'How places join into one — by dual supermajority, with join and exit mirrored.' },
    { act: 'Your place', href: '/jurisdictions/disintermediation', title: 'Removing a middle layer', blurb: 'Constituents fold up one level and keep governing themselves.' },
    { act: 'Your place', href: '/jurisdictions/restoration', title: 'Rebuilding a lost government', blurb: 'The restoration cascade, legitimacy scoring, and the defense of a place brought back.' },
    { act: 'Between governments', href: '/federation', title: 'Between governments', blurb: 'Federation, borders settled by supermajority, and how instances stay one world.' },
    { act: 'The economy', href: '/economy/stipend', title: 'The civic stipend', blurb: 'A modest, unconditional share for taking part — paid to people, never to accounts.' },
    { act: 'The economy', href: '/economy/agreements', title: 'Agreements', blurb: 'Every contract has a floor of terms — open one to see its signatures and the deal.' },
    { act: 'The record', href: '/achievements', title: 'Achievements', blurb: 'Civic firsts, guided journeys, and a place’s milestones, sealed to an append-only record.' },
    /* Wave 3 (A2 "stops toward 117") — appended, never inserted, so existing
       ?step=N links keep their positions. EXISTING, non-dead surfaces only:
       each renders for a signed-in player (auth-gated is fine — the tour is
       walked signed-in, exactly like /civic; the operator/* shells render for
       ANY authenticated user and show a sign-in-as-operator prompt inside, so
       they are demonstrable, not 403). Deliberately NOT here yet: the live-room
       / halls keystone (/civic/commons/*, /civic/halls) waits for lane 3; the
       two param targets (person profile /people?who=, work request
       /economy/requests/{posting}) stay parent-reached — no fixture guarantee
       of a stable handle/UUID, and a dead stop is worse than none; /setup
       refuses once a world is founded, so operator-setup is a dead stop and is
       omitted. The index groups by act; step numbers are global append-order. */
    { act: 'The economy', href: '/economy', title: 'The market', blurb: 'Your money, the open market, and public finance — the whole economy in one place.' },
    { act: 'The economy', href: '/economy/wallet', title: 'My wallet', blurb: 'What you hold and what has moved — private to you, like a ballot.' },
    { act: 'The economy', href: '/economy/market', title: 'The open market', blurb: 'Offers and requests between people — a two-sided market, settled in the open.' },
    { act: 'The economy', href: '/economy/units', title: 'Units & money', blurb: 'What a unit is and how money is issued — abstract, with no payment rails.' },
    { act: 'The economy', href: '/economy/treasury', title: 'Public finance', blurb: 'A place’s treasury, in the open — what came in, what went out, and why.' },
    { act: 'The economy', href: '/economy/joint-ledgers', title: 'Joint ledgers', blurb: 'Shared accounts that move only when the people who share them agree.' },
    { act: 'Organizations', href: '/organizations/co-determination', title: 'Worker seats on the board', blurb: 'Past a threshold of employees, workers elect seats on the board — by the same fair count.' },
    { act: 'Organizations', href: '/organizations/transfers-conversions', title: 'Ownership changes', blurb: 'How an organization changes hands or converts type — every step on the record.' },
    { act: 'Your place', href: '/civic/relocation', title: 'Move somewhere new', blurb: 'Change where you live and your rights follow you — residency is the only key.' },
    { act: 'The record', href: '/system/term-sync', title: 'Terms end together', blurb: 'Why civil and judicial terms move in lockstep — ten years, synchronized.' },
    { act: 'The record', href: '/reach', title: 'Reach', blurb: 'How many have taken part — a gauge that measures, never a lever that rules.' },
    { act: 'Help', href: '/support/tickets', title: 'Your reports', blurb: 'Everything you’ve filed and where it went — a bug, a question, or a call for review.' },
    { act: 'Help', href: '/system/accessibility', title: 'Accessibility', blurb: 'What’s built in, what’s still coming, and how to tell us where it falls short.' },
    { act: 'Help', href: '/system/constitutional-questions', title: 'The hard questions', blurb: 'The design decisions people ask about most, answered against the Template.' },
    { act: 'Run a node', href: '/operator', title: 'The operator plane', blurb: 'The volunteer servers the world runs on — keeping one online buys no vote and no seat.' },
    { act: 'Run a node', href: '/operator/dns', title: 'DNS & certificates', blurb: 'How a node is reached on the network, and how it proves it’s really itself.' },
    { act: 'Run a node', href: '/operator/moderation', title: 'Moderation & the legal floor', blurb: 'The only removals allowed — four narrow carve-outs, never a viewpoint.' },
];

/* The first-visit track — a stranger's short arc through the tour, a subset of
   TOUR named by href (the app stops carry no `rel`, so we key on href). The
   /tour index leads with these before the full grouped walkthrough. Only hrefs
   already present as TOUR stops resolve; the rest are skipped, so this list can
   name aspirational stops without breaking as surfaces wire (Slice 4). */
export const FIRST_VISIT = [
    '/civic',
    '/civic/residency',
    '/jurisdictions',
    '/civic/square',
    '/civic/petitions',
    '/elections',
    '/elections/ranked-ballot',
    '/journeys',
    '/system/audit-chain',
    '/support/report',
];

/* -------------------------------------------------- the Learn drawer text
   Plain-language "what this screen is about", keyed by the surface module
   (config/cga/surfaces.php modules). Ported from mockups/v3 shell-v2.js
   LEARN_BY_MODULE.

   K-2 (2026-07-28): per-surface Learn copy now lives in the GENERATED
   registry/education.js (EDUCATION_BY_SURFACE — authored in
   docs/plans/education/K2_CONTENT_*.md, emitted by
   scripts/education/build_education_payload.mjs). The old LEARN_BY_SURFACE
   hand-synced copy is gone: all five of its entries moved into the authored
   corpus verbatim, so this module map is now purely the FALLBACK for pages
   with no authored entry. Three keys here — `federation`, `support`,
   `social` — match no surfaces.php module; they serve LearnFlyout's
   URL-segment fallback (e.g. /support/report) and are deliberate, not drift. */
export const LEARN_BY_MODULE = {
    civic: 'Your civic home — what’s happening now, and what’s yours to act on.',
    electoral: 'How elections work here — anyone who lives in a place can vote and stand for office. Approvals pick the ballot; ranking fills the seats in fair shares, so no vote is wasted.',
    legislature: 'How lawmaking works — bills are shaped in committee, debated on the floor, and every vote is counted against all serving seats, so absence never shrinks the bar.',
    executive: 'The executive is the doing arm — it carries out the laws and runs the departments, and it answers to the legislature that created it.',
    judiciary: 'How the courts work — panels of judges, advocates open to anyone, juries of residents, and rulings on the public record.',
    organizations: 'Organizations — parties, businesses, nonprofits, and common-good corporations share one open registry. Anyone, person or organization, can endorse any candidate.',
    jurisdictions: 'Places — every jurisdiction from a neighborhood to the planet: how they wake up, merge, split, and govern themselves.',
    operator: 'Running a node — the volunteer servers the world runs on. Keeping it online buys no vote and no seat.',
    system: 'The permanent public record, the clocks that drive the world, and how an instance is founded.',
    federation: 'Between governments — how instances discover each other, peer, and stay one world.',
    support: 'Getting help and reporting anything that’s wrong.',
    social: 'How the social layer works — the square, groups, and reaching people.',
};

/* Convenience: the tour entry href (stop 1 with the mode armed). */
export function tourStartHref() {
    return TOUR.length ? TOUR[0].href + (TOUR[0].href.includes('?') ? '&' : '?') + 'step=1' : '/';
}
