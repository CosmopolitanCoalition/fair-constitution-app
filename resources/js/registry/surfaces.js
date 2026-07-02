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
    { id: 'market', label: 'Market', icon: 'bar-chart', href: null, contract: 'economy/economy-home.html', phase: 8 },
    { id: 'my-record', label: 'My profile', icon: 'user', href: '/civic/record', contract: 'civic/my-civic-life.html', phase: 2 },
    { id: 'journeys', label: 'Journeys', icon: 'list-checks', href: null, contract: 'index.html#journeys-h', phase: 3 },
    { id: 'learn', label: 'Learn & help', icon: 'graduation-cap', href: null, contract: 'learn/learn-home.html', phase: 7 },
    /* 'tour' is special-cased by the menu: it enters tour mode at stop 1. */
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
        { id: 'join', label: 'You’re invited (arrival)', icon: 'user', href: null, contract: 'civic/join.html', phase: 3 },
        { id: 'onboarding', label: 'Create your account', icon: 'user', href: '/register', contract: 'civic/onboarding.html' },
        { id: 'residency', label: 'Say where you live', icon: 'map-pin', href: '/civic/residency', contract: 'civic/residency.html' },
        { id: 'relocation', label: 'Move somewhere new', icon: 'map', href: '/civic/relocation', contract: 'civic/relocation.html' },
        { id: 'my-record', label: 'My record', icon: 'file-text', href: '/civic/record', contract: 'civic/my-civic-life.html', phase: 2 },
        { id: 'achievements', label: 'Achievements', icon: 'award', href: null, contract: 'social/achievements.html', phase: 3 },
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
        { id: 'reach', label: 'Reach', icon: 'bar-chart', href: null, contract: 'social/legitimacy.html', phase: 7 },
        { id: 'bootstrap', label: 'Wake a place up', icon: 'globe', href: null, contract: 'jurisdictions/bootstrap.html', phase: 4 },
        { id: 'union-formation', label: 'Merge places into a union', icon: 'users', href: null, contract: 'jurisdictions/union-formation.html', phase: 4 },
        { id: 'disintermediation', label: 'Remove a middle layer', icon: 'globe', href: null, contract: 'jurisdictions/disintermediation.html', phase: 4 },
        { id: 'restoration', label: 'Rebuild a lost government', icon: 'refresh-cw', href: null, contract: 'jurisdictions/restoration.html', phase: 4 },
        { id: 'federation', label: 'Between governments', icon: 'globe', href: '/federation', contract: 'jurisdictions/federation.html', phase: 4 },
    ] },
    { key: 'market', title: 'Market · planned', items: [
        { id: 'marketplace', label: 'The open market', icon: 'building', href: null, contract: 'economy/marketplace.html', phase: 8 },
        { id: 'wallet', label: 'My wallet', icon: 'lock', href: null, contract: 'economy/wallet.html', phase: 8 },
        { id: 'stipend', label: 'The civic stipend', icon: 'refresh-cw', href: null, contract: 'economy/stipend.html', phase: 8 },
        { id: 'treasury', label: 'Public finance', icon: 'bar-chart', href: null, contract: 'economy/treasury.html', phase: 8 },
    ] },
    { key: 'help', title: 'Learn & help', items: [
        { id: 'learn', label: 'Learn & lessons', icon: 'graduation-cap', href: null, contract: 'learn/learn-home.html', phase: 7 },
        { id: 'support-report', label: 'Report an issue', icon: 'flag', href: '/support/report', contract: 'support/report.html' },
        { id: 'accessibility', label: 'Accessibility', icon: 'shield', href: null, contract: 'shared/accessibility.html', phase: 7 },
    ] },
    { key: 'records', title: 'Records & the clock', items: [
        { id: 'public-records', label: 'Public records', icon: 'file-text', href: '/system/public-records', contract: 'system/public-records.html' },
        { id: 'audit-chain', label: 'The audit chain', icon: 'lock', href: '/system/audit-chain', contract: 'system/audit-chain.html' },
        { id: 'amendments', label: 'Amendments', icon: 'file-text', href: null, contract: 'system/amendments.html', phase: 2 },
        { id: 'term-sync', label: 'Terms end together', icon: 'refresh-cw', href: '/system/term-sync', contract: 'system/term-sync.html' },
        { id: 'clocks', label: 'The clocks', icon: 'clock', href: null, contract: 'shared/clocks.html', phase: 2 },
    ] },
    { key: 'node', title: 'Run a node', items: [
        { id: 'setup-wizard', label: 'Found the instance', icon: 'sliders', href: '/setup', contract: 'system/setup.html' },
        { id: 'operator-operations', label: 'The operator plane', icon: 'sliders', href: '/operator/operations', contract: 'operator/operator-home.html', phase: 4 },
        { id: 'federation-console', label: 'Mesh & peers', icon: 'globe', href: '/federation', contract: 'operator/mesh.html', phase: 4 },
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
    { act: 'Help', href: '/support/report', title: 'Report an issue', blurb: 'A bug, a question, or something that needs review — file it here.' },
];

/* -------------------------------------------------- the Learn drawer text
   Plain-language "what this screen is about", keyed by the surface module
   (config/cga/surfaces.php modules), with per-surface overrides. Ported from
   mockups/v3 shell-v2.js LEARN_BY_MODULE / LEARN_BY_ID. */
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

export const LEARN_BY_SURFACE = {
    'system/audit-chain': 'The audit chain — every constitutional act, hash-chained in order. Anyone can verify that nothing was quietly changed.',
    'system/public-records': 'The permanent public record — testimony, votes, acts, and rulings, readable by anyone, editable by no one.',
    'elections/detail': 'One election, end to end — the schedule, the candidates, the count, and the certification, all on the record.',
};

/* Convenience: the tour entry href (stop 1 with the mode armed). */
export function tourStartHref() {
    return TOUR.length ? TOUR[0].href + (TOUR[0].href.includes('?') ? '&' : '?') + 'step=1' : '/';
}
