/* ============================================================================
   CGA — Navigation/nav.js
   The single source of truth for the sidebar (DESIGN_frontend_port.md §C2),
   ported from mockups/assets/js/shell.js NAV. Differences from the mockup:
     • `rel:` html file → `href:` Laravel route path (literal paths, Phase A);
     • `isBuilt()` manifest lookup → `phase:` field, checked against the
       shared prop `app.phasesLive` — unbuilt items render disabled with a
       "Planned · Phase X" flag, keeping the full constitutional sitemap
       visible from day one without dead links;
     • the mockups' designContract section (launchpad / styleguide / coverage
       matrix / ledger / accessibility) is not product navigation and is not
       ported.

   Phases per screen follow DESIGN_roadmap_phaseA.md §A:
     A = Foundation (civic identity/residency slice, the 3 developed tools,
         audit chain), B = Elections, C = Legislature, D = Executive & Orgs,
     E = Judiciary & Law, F = Federation & Mobile.

   Role gating (direct port of renderSidebar()):
     • section hidden unless visibility 'all' or persona roles ∩ section.roles;
     • item disabled with a .prereq-hint ("Requires R-09") unless roles ∩
       item.enabledRoles. Roles are ALWAYS server-derived (shared props).
   ============================================================================ */

export const NAV = [
    { key: 'home', titleKey: 'nav.home', visibility: 'all', items: [
        { id: 'civic-home', labelKey: 'nav.civicHome', icon: 'home', href: '/civic', phase: 'A' },
        { id: 'my-record', labelKey: 'nav.myRecord', icon: 'file-text', href: '/civic/record', phase: 'A' },
        /* No /civic/learn route exists yet — flagged Planned (phase D now
           that C is live) so the sidebar never renders a dead link; flip
           back to 'A' when the Learn surface ships. */
        { id: 'learn', labelKey: 'nav.learn', icon: 'book-open', href: '/civic/learn', phase: 'D' },
        /* FE-C10 (live): relocation has its own item (PHASE_C_DESIGN_frontend.md
           §B nav integration) — the mockup parked the surface under civic-home. */
        { id: 'relocation', labelKey: 'nav.relocation', icon: 'globe', href: '/civic/relocation', phase: 'C' },
    ] },
    /* FE-B0: item ids match the `nav` values in config/cga/surfaces.php
       (elections/* entries) so aria-current resolves; items stay phase 'B'
       (Planned flag) until the pages land and phasesLive flips. */
    { key: 'elections', titleKey: 'nav.elections', visibility: 'all', items: [
        { id: 'open-ballot', labelKey: 'nav.openBallot', icon: 'vote', href: '/elections/open-ballot', phase: 'B' },
        { id: 'ranked-ballot', labelKey: 'nav.rankedBallot', icon: 'check', href: '/elections/ranked-ballot', phase: 'B' },
        { id: 'elections', labelKey: 'nav.electionDetail', icon: 'clock', href: '/elections', phase: 'B' },
        { id: 'results', labelKey: 'nav.results', icon: 'bar-chart', href: '/elections/results', phase: 'B' },
        { id: 'candidacy', labelKey: 'nav.candidacy', icon: 'user', href: '/elections/candidacy', phase: 'B' },
    ] },
    { key: 'petitions', titleKey: 'nav.petitions', visibility: 'all', items: [
        { id: 'petitions', labelKey: 'nav.petitions', icon: 'file-text', href: '/civic/petitions', phase: 'C' },
    ] },
    /* Phase K-1 — the civic record plane. Public square (open, uncensorable) + halls of
       governance (deliberation → append-only testimony). ids match the `nav` values in
       config/cga/surfaces.php (civic/public-square, civic/halls). */
    { key: 'commons', titleKey: 'nav.commons', visibility: 'all', items: [
        { id: 'public-square', labelKey: 'nav.publicSquare', icon: 'users', href: '/civic/square', phase: 'K' },
        { id: 'halls', labelKey: 'nav.halls', icon: 'landmark', href: '/civic/halls', phase: 'K' },
        // Phase K-3 (K3-L) — the live commons over the Matrix mesh (Plane B).
        { id: 'commons-square', labelKey: 'nav.liveSquare', icon: 'message-circle', href: '/civic/commons/square', phase: 'K' },
        { id: 'commons-halls', labelKey: 'nav.liveHalls', icon: 'radio', href: '/civic/commons/halls', phase: 'K' },
        // Private rooms (groups / DMs) — the Art. I private half; invite-only, off the public record.
        { id: 'rooms', labelKey: 'nav.rooms', icon: 'lock', href: '/civic/rooms', phase: 'K' },
    ] },
    /* FE-E0 (PHASE_E_DESIGN_frontend.md §B nav integration): the `court`
       section below is role-gated (the officeholder's launchpad), but the
       docket + challenge tracker are PUBLIC RECORD (Art. II §2). This
       all-visibility item is the public entry to the docket for any
       associated resident, who never holds a court role — mirrors how
       org-registry lives in an all-visibility organizations section.
       Stays phase 'E' (Planned flag) until phasesLive flips at the final
       batch; the JudiciaryResolverController keeps /judiciary/docket stable. */
    { key: 'judiciaryPublic', titleKey: 'nav.judiciaryPublic', visibility: 'all', items: [
        { id: 'public-docket', labelKey: 'nav.publicDocket', icon: 'scale', href: '/judiciary/docket', phase: 'E' },
    ] },
    { key: 'organizations', titleKey: 'nav.organizations', visibility: 'all', items: [
        { id: 'org-registry', labelKey: 'nav.orgRegistry', icon: 'building', href: '/organizations', phase: 'D' },
        { id: 'co-determination', labelKey: 'nav.coDetermination', icon: 'users', href: '/organizations/co-determination', phase: 'D' },
        /* FE-D0 (PHASE_C→D nav integration, PHASE_D_DESIGN_frontend.md §B):
           transfers/conversions gets its own item; the detail surfaces
           (org-detail, cgc-detail, board-elections, transfers) map their
           aria-current to `org-registry`/`transfers-conversions` via the
           `nav:` field in config/cga/surfaces.php. */
        { id: 'transfers-conversions', labelKey: 'nav.transfers', icon: 'refresh-cw', href: '/organizations/transfers-conversions', phase: 'D' },
    ] },
    { key: 'jurisdictions', titleKey: 'nav.jurisdictions', visibility: 'all', items: [
        { id: 'jurisdiction-browser', labelKey: 'nav.jurisdictionBrowser', icon: 'globe', href: '/jurisdictions', phase: 'A' },
        /* WI-9: points at the legislature INDEX (all N legislatures listed by
           jurisdiction; each row links into its district mapper). */
        { id: 'legislatures', labelKey: 'nav.legislatures', icon: 'map', href: '/legislatures', phase: 'A' },
    ] },
    /* Phase C complete (FE-C2…C11): phasesLive flipped to ['A','B','C'] with
       the final batch — every legislature/civic/system Phase C item below is
       live. Roadmap provenance stays recorded in config/cga/surfaces.php. */
    { key: 'legislature', titleKey: 'nav.legislature', visibility: 'role', roles: ['R-09', 'R-10', 'R-11', 'R-12', 'R-13', 'R-29'], items: [
        { id: 'legislature-home', labelKey: 'nav.chamber', icon: 'landmark', href: '/legislature', enabledRoles: ['R-09', 'R-10', 'R-11', 'R-12', 'R-13', 'R-29'], prereq: 'R-09', phase: 'A' },
        { id: 'session-console', labelKey: 'nav.session', icon: 'users', href: '/legislature/session', enabledRoles: ['R-09', 'R-10'], prereq: 'R-09', phase: 'A' },
        { id: 'bills', labelKey: 'nav.bills', icon: 'file-text', href: '/legislature/bills', enabledRoles: ['R-09', 'R-10', 'R-11', 'R-12', 'R-13'], prereq: 'R-09', phase: 'A' },
        { id: 'committees', labelKey: 'nav.committees', icon: 'users', href: '/legislature/committees', enabledRoles: ['R-09', 'R-10', 'R-11', 'R-12', 'R-13'], prereq: 'R-09', phase: 'C' },
        { id: 'referendums', labelKey: 'nav.referendums', icon: 'vote', href: '/legislature/referendums', enabledRoles: ['R-09', 'R-10'], prereq: 'R-09', phase: 'C' },
        { id: 'emergency-powers', labelKey: 'nav.emergencyPowers', icon: 'alert-triangle', href: '/legislature/emergency-powers', enabledRoles: ['R-09', 'R-10'], prereq: 'R-09', phase: 'C' },
        { id: 'oversight', labelKey: 'nav.oversight', icon: 'shield', href: '/legislature/oversight', enabledRoles: ['R-09', 'R-10', 'R-29'], prereq: 'R-29', phase: 'C' },
        { id: 'settings', labelKey: 'nav.settings', icon: 'sliders', href: '/legislature/settings', enabledRoles: ['R-09', 'R-10'], prereq: 'R-09', phase: 'A' },
    ] },
    { key: 'speaker', titleKey: 'nav.speakerTools', visibility: 'role', roles: ['R-09', 'R-10'], items: [
        { id: 'speaker-tools', labelKey: 'nav.speakerTools', icon: 'landmark', href: '/legislature/speaker-tools', enabledRoles: ['R-10'], prereq: 'R-10', phase: 'C' },
    ] },
    { key: 'electionBoard', titleKey: 'nav.electionBoard', visibility: 'role', roles: ['R-08'], items: [
        { id: 'election-board-console', labelKey: 'nav.boardConsole', icon: 'shield', href: '/elections/board', enabledRoles: ['R-08'], prereq: 'R-08', phase: 'B' },
        { id: 'vacancy-countback', labelKey: 'nav.countback', icon: 'refresh-cw', href: '/elections/countback', enabledRoles: ['R-08'], prereq: 'R-08', phase: 'B' },
    ] },
    { key: 'executive', titleKey: 'nav.executive', visibility: 'role', roles: ['R-14', 'R-15', 'R-16', 'R-17', 'R-18', 'R-30'], items: [
        { id: 'executive-home', labelKey: 'nav.executiveHome', icon: 'briefcase', href: '/executive', enabledRoles: ['R-14', 'R-15', 'R-16', 'R-17'], prereq: 'R-14', phase: 'D' },
        { id: 'departments', labelKey: 'nav.departments', icon: 'building', href: '/executive/departments', enabledRoles: ['R-14', 'R-15', 'R-16', 'R-30'], prereq: 'R-14', phase: 'D' },
        { id: 'executive-actions', labelKey: 'nav.executiveActions', icon: 'file-text', href: '/executive/actions', enabledRoles: ['R-14', 'R-15', 'R-16'], prereq: 'R-14', phase: 'D' },
        { id: 'department-reporting', labelKey: 'nav.departmentReporting', icon: 'bar-chart', href: '/executive/reporting', enabledRoles: ['R-18'], prereq: 'R-18', phase: 'D' },
    ] },
    { key: 'court', titleKey: 'nav.court', visibility: 'role', roles: ['R-19', 'R-20', 'R-21', 'R-22'], items: [
        { id: 'judiciary-home', labelKey: 'nav.judiciaryHome', icon: 'scale', href: '/judiciary', enabledRoles: ['R-19', 'R-20', 'R-21', 'R-22'], prereq: 'R-19', phase: 'E' },
        { id: 'case-docket', labelKey: 'nav.caseDocket', icon: 'file-text', href: '/judiciary/docket', enabledRoles: ['R-19', 'R-20', 'R-21'], prereq: 'R-19', phase: 'E' },
        { id: 'constitutional-challenge', labelKey: 'nav.challenges', icon: 'scale', href: '/judiciary/challenges', enabledRoles: ['R-19', 'R-20', 'R-21'], prereq: 'R-19', phase: 'E' },
        { id: 'advocate-console', labelKey: 'nav.advocateConsole', icon: 'briefcase', href: '/judiciary/advocate', enabledRoles: ['R-21'], prereq: 'R-21', phase: 'E' },
        { id: 'juror-view', labelKey: 'nav.jurorView', icon: 'users', href: '/judiciary/jury', enabledRoles: ['R-22'], prereq: 'R-22', phase: 'E' },
    ] },
    { key: 'system', titleKey: 'nav.system', visibility: 'all', items: [
        { id: 'setup-wizard', labelKey: 'nav.setupWizard', icon: 'globe', href: '/setup', phase: 'A' },
        /* FE-F / G3c: the federation console (peers, FF&C sync, the host adoption
           console + join wizard). Was URL-only; surfaced here so both consoles are
           discoverable. Public-read (Art. II §2); host actions are operator-gated. */
        { id: 'federation', labelKey: 'nav.federation', icon: 'share-2', href: '/federation', phase: 'F' },
        /* Operator Operations console (Phase 1, read-only): the infra & identity
           inventory. All-visibility item; the inventory itself is operator-gated
           (a citizen sees only a sign-in prompt), like the host block on /federation. */
        { id: 'operator-operations', labelKey: 'nav.operatorOps', icon: 'sliders', href: '/operator/operations', phase: 'F' },
        { id: 'public-records', labelKey: 'nav.publicRecords', icon: 'file-text', href: '/system/public-records', phase: 'C' },
        { id: 'audit-chain', labelKey: 'nav.auditChain', icon: 'lock', href: '/system/audit-chain', phase: 'A' },
        /* No /system/clocks route exists — the clocks browser is not one of
           the 11 Phase D surfaces either (it was parked at D only to avoid a
           dead link at the C flip). Re-flagged Planned (phase E) per
           PHASE_D_DESIGN_frontend.md §E "Flip + critical path" so the D flip
           never renders a dead link; flip back if end-of-phase slack ships
           the read-only clocks DataTable. */
        { id: 'clocks', labelKey: 'nav.clocks', icon: 'clock', href: '/system/clocks', phase: 'E' },
        { id: 'term-sync', labelKey: 'nav.termSync', icon: 'refresh-cw', href: '/system/term-sync', phase: 'C' },
        { id: 'amendments', labelKey: 'nav.amendments', icon: 'file-text', href: '/system/amendments', phase: 'E' },
    ] },
    /* Dev tooling — gated on the WORLD being in SANDBOX game mode (the runtime
       shared prop instance.sandbox), which AppSidebar honors via
       section.requiresSandbox, and which the DevBar uses too. This REPLACES the
       old build-time import.meta.env.DEV gate that leaked dev tooling into every
       `docker compose up` before the operator had chosen a mode. The /dev/*
       routes are backend-gated on sandbox as well (DevToolsEnabled, WI-4), so a
       production world never reaches them even on a dev build. */
    { key: 'dev', titleKey: 'nav.dev', visibility: 'all', requiresSandbox: true, items: [
        { id: 'dev-electoral-kit', labelKey: 'nav.electoralKit', icon: 'sliders', href: '/dev/electoral-kit', phase: 'A' },
        { id: 'dev-legislature-kit', labelKey: 'nav.legislatureKit', icon: 'sliders', href: '/dev/legislature-kit', phase: 'A' },
        { id: 'dev-executive-kit', labelKey: 'nav.executiveOrgKit', icon: 'sliders', href: '/dev/executive-kit', phase: 'A' },
        { id: 'dev-judiciary-kit', labelKey: 'nav.judiciaryKit', icon: 'sliders', href: '/dev/judiciary-kit', phase: 'A' },
    ] },
];

export default NAV;
