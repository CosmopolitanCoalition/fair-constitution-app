<?php

/*
|--------------------------------------------------------------------------
| CGA surface registry (DESIGN_frontend_port.md §D1)
|--------------------------------------------------------------------------
|
| The server-side successor of the mockups' window.CGA_PAGE blocks: every
| Inertia page passes `'surface' => SurfaceMeta::for('<id>')`. One registry
| feeds the page scaffold (title/eyebrow/About panel), the sidebar
| aria-current (nav), the footer citation line, and — because form entries
| resolve names/aliases through App\Domain\Forms\FormRegistry — the same
| canonical form table the constitutional engine validates against.
|
| Record shape:
|   id        registry key (mirrors the mockup file id, .html dropped)
|   title     <h1> / <Head> title
|   module    eyebrow label (civic | electoral | … | system | auth)
|   nav       sidebar item id carrying aria-current (Navigation/nav.js)
|   roles     R-xx codes the surface addresses (display, not a gate)
|   workflows WF-xx ids listed in the About panel
|   forms     [['id' => F-xx, 'availableTo' => [R-xx] (default: registry
|             roles), 'citation' => '…'], …] — name + alias resolved from
|             FormRegistry by SurfaceMeta::for()
|   clocks    CLK-xx ids cited on the surface
|   citation  the constitutional footer line (mono)
|
| Contract data sourced from docs/plans/institutions/EXPLORE_civic_electoral.md
| and the corresponding mockup CGA_PAGE blocks (mockups/civic/*, system/*).
*/

return [

    'civic/home' => [
        'title'     => 'Civic home',
        'module'    => 'civic',
        'nav'       => 'civic-home',
        // R-01 included: a fresh registrant lands here pre-residency (WI-8).
        'roles'     => ['R-01', 'R-03', 'R-04'],
        'workflows' => ['WF-CIV-02', 'WF-CIV-04', 'WF-CIV-06', 'WF-CIV-08'],
        'forms'     => [],
        'clocks'    => [],
        'citation'  => 'Voting and candidacy unlocked — no other requirements · Art. I; Art. V §1',
    ],

    'civic/residency' => [
        'title'     => 'Residency',
        'module'    => 'civic',
        'nav'       => 'civic-home',
        'roles'     => ['R-01', 'R-02', 'R-03'],
        'workflows' => ['WF-CIV-02'],
        'forms'     => [
            ['id' => 'F-IND-003', 'availableTo' => ['R-01'], 'citation' => 'Art. I (Right to Reside)'],
            ['id' => 'F-IND-005', 'availableTo' => ['R-01'], 'citation' => 'Art. I · CLK-05 (system background pings)'],
            ['id' => 'F-IND-006', 'availableTo' => ['R-02'], 'citation' => 'Art. I; Art. V §1 (system-filed on threshold)'],
        ],
        'clocks'    => ['CLK-05'],
        'citation'  => 'Residency verified → all associations → rights unlocked · Art. I; Art. V §1 · CLK-05',
    ],

    'civic/my-record' => [
        'title'     => 'My record',
        'module'    => 'civic',
        'nav'       => 'my-record',
        'roles'     => ['R-03', 'R-04'],
        'workflows' => ['WF-SYS-03', 'WF-SYS-04'],
        'forms'     => [
            ['id' => 'F-IND-002', 'availableTo' => ['R-01'], 'citation' => 'Art. I (self-managed profile)'],
        ],
        'clocks'    => [],
        'citation'  => 'Participation is public; ballot choices are secret · Art. II §2',
    ],

    'civic/identity-verification' => [
        'title'     => 'Identity verification',
        'module'    => 'civic',
        'nav'       => 'civic-home',
        'roles'     => ['R-01'],
        'workflows' => ['WF-CIV-01'],
        'forms'     => [
            ['id' => 'F-IND-004', 'availableTo' => ['R-01'], 'citation' => 'Art. I; Art. II §2 (never a rights requirement)'],
        ],
        'clocks'    => [],
        'citation'  => 'Identity verification strengthens election integrity — never a voting requirement · Art. I; Art. II §2',
    ],

    'auth/register' => [
        'title'     => 'Create your account',
        'module'    => 'civic',
        'nav'       => 'civic-home',
        'roles'     => ['R-01'],
        'workflows' => ['WF-CIV-01'],
        'forms'     => [
            ['id' => 'F-IND-001', 'availableTo' => ['R-01'], 'citation' => 'Art. I (inherent rights)'],
            ['id' => 'F-IND-002', 'availableTo' => ['R-01'], 'citation' => 'Art. I'],
        ],
        'clocks'    => [],
        'citation'  => 'Registration is open to any person — rights are inherent · Art. I',
    ],

    'legislature/index' => [
        'title'     => 'Legislatures',
        'module'    => 'jurisdictions',
        'nav'       => 'legislatures',
        'roles'     => [],
        'workflows' => ['WF-JUR-01'],
        'forms'     => [],
        'clocks'    => ['CLK-06'],
        'citation'  => 'Every legislature sized by the cube-root law; 5–9 seats per district · Art. II §2',
    ],

    /*
    |----------------------------------------------------------------------
    | Phase B — electoral surfaces (FE-B0; PHASE_B_DESIGN_frontend.md §B)
    |----------------------------------------------------------------------
    | Contract data from the mockup CGA_PAGE blocks (mockups/electoral/*)
    | plus the design's §B per-page surface rows. Pages land in FE-B2…B8;
    | registering the ids first lets every controller pass
    | SurfaceMeta::for() from day one.
    */

    'elections/detail' => [
        'title'     => 'Election detail',
        'module'    => 'electoral',
        'nav'       => 'elections',
        'roles'     => ['R-03', 'R-04', 'R-08'],
        'workflows' => ['WF-ELE-01'],
        'forms'     => [
            ['id' => 'F-ELB-001', 'availableTo' => ['R-08'], 'citation' => 'Art. II §2 · CLK-21 (X pre-published with the order)'],
            ['id' => 'F-ELB-004', 'availableTo' => ['R-08'], 'citation' => 'Art. II §2 (transparent election process)'],
            ['id' => 'F-ELB-006', 'availableTo' => ['R-08'], 'citation' => 'Art. II §2 · requires certification first'],
        ],
        'clocks'    => ['CLK-01', 'CLK-18', 'CLK-21', 'CLK-07'],
        'citation'  => 'General election cycle · two-phase open ballot · Art. II §2 · CLK-18 · CLK-21',
    ],

    'elections/candidacy-registration' => [
        'title'     => 'Candidacy registration',
        'module'    => 'electoral',
        'nav'       => 'candidacy',
        'roles'     => ['R-03', 'R-06'],
        'workflows' => ['WF-CIV-05'],
        'forms'     => [
            ['id' => 'F-IND-011', 'availableTo' => ['R-03'], 'citation' => 'Art. I (Right to Stand for Office)'],
            ['id' => 'F-ELB-002', 'availableTo' => ['R-08'], 'citation' => 'Art. I · residency is the only check'],
        ],
        'clocks'    => ['CLK-18'],
        'citation'  => 'Right to stand — residency is the only requirement · Art. I · CLK-18',
    ],

    'elections/candidate-profile' => [
        'title'     => 'Candidate profile',
        'module'    => 'electoral',
        'nav'       => 'open-ballot',
        'roles'     => ['R-03', 'R-04', 'R-06', 'R-07'],
        'workflows' => ['WF-CIV-05', 'WF-CIV-08'],
        'forms'     => [
            ['id' => 'F-CAN-001', 'availableTo' => ['R-06'], 'citation' => 'Art. I (Freedom of Expression)'],
            ['id' => 'F-CAN-002', 'availableTo' => ['R-06'], 'citation' => 'Art. I (Freedom of Assembly)'],
            ['id' => 'F-CAN-003', 'availableTo' => ['R-06'], 'citation' => 'Art. I (Autonomy) · blocked after the finalist cutoff · CLK-21'],
        ],
        'clocks'    => ['CLK-21'],
        'citation'  => 'Candidacy lifecycle · F-CAN-001/002/003 · Art. I; Art. II §2',
    ],

    'elections/open-ballot' => [
        'title'     => 'Open ballot',
        'module'    => 'electoral',
        'nav'       => 'open-ballot',
        'roles'     => ['R-03', 'R-04', 'R-06'],
        'workflows' => ['WF-CIV-08', 'WF-ELE-01', 'WF-CIV-05'],
        // No citizen-facing F-ID: approve/revoke are engine actions
        // (audit-chained) — matching the mockup's empty `forms` contract.
        'forms'     => [],
        'clocks'    => ['CLK-18', 'CLK-21'],
        'citation'  => 'Approval phase · finalists X = f(seats) · CLK-21 · Art. II §2',
    ],

    'elections/ranked-ballot' => [
        'title'     => 'Ranked ballot',
        'module'    => 'electoral',
        'nav'       => 'ranked-ballot',
        'roles'     => ['R-04'],
        'workflows' => ['WF-CIV-04', 'WF-ELE-01'],
        'forms'     => [
            ['id' => 'F-IND-007', 'availableTo' => ['R-04'], 'citation' => 'Art. II §2'],
            ['id' => 'F-IND-008', 'availableTo' => ['R-04'], 'citation' => 'Art. II §6'],
        ],
        'clocks'    => ['CLK-21'],
        'citation'  => 'F-IND-007 ballot submission · STV with Droop quota · Art. II §2',
    ],

    'elections/results' => [
        'title'     => 'Results',
        'module'    => 'electoral',
        'nav'       => 'results',
        'roles'     => ['R-03', 'R-04', 'R-08'],
        'workflows' => ['WF-ELE-01', 'WF-ELE-05'],
        'forms'     => [
            ['id' => 'F-ELB-004', 'availableTo' => ['R-08'], 'citation' => 'Art. II §2 (transparent election process)'],
            ['id' => 'F-ELB-006', 'availableTo' => ['R-08'], 'citation' => 'Art. II §2 · recount = audit re-run, no hand count'],
        ],
        'clocks'    => [],
        'citation'  => 'STV with Droop quota · Gregory transfers · Art. II §2',
    ],

    'elections/board-console' => [
        'title'     => 'Election board console',
        'module'    => 'electoral',
        'nav'       => 'election-board-console',
        'roles'     => ['R-08'],
        'workflows' => [
            'WF-ELE-01', 'WF-ELE-02', 'WF-ELE-03', 'WF-ELE-04', 'WF-ELE-05',
            'WF-ELE-06', 'WF-ELE-07', 'WF-ELE-08', 'WF-ELE-09', 'WF-ELE-10',
        ],
        'forms'     => [
            ['id' => 'F-ELB-001', 'availableTo' => ['R-08'], 'citation' => 'Art. II §2 · CLK-21 (X pre-published with the order)'],
            ['id' => 'F-ELB-002', 'availableTo' => ['R-08'], 'citation' => 'Art. I · residency is the only check'],
            ['id' => 'F-ELB-003', 'availableTo' => ['R-08'], 'citation' => 'Art. II §8 · prereq: chamber seats > 9'],
            ['id' => 'F-ELB-004', 'availableTo' => ['R-08'], 'citation' => 'Art. II §2 · winners granted roles'],
            ['id' => 'F-ELB-005', 'availableTo' => ['R-08'], 'citation' => 'Art. II §6 · CLK-17 (petition at threshold)'],
            ['id' => 'F-ELB-006', 'availableTo' => ['R-08'], 'citation' => 'Art. II §2 · requires certification first'],
        ],
        'clocks'    => ['CLK-01', 'CLK-04', 'CLK-18', 'CLK-21'],
        'citation'  => 'Independent election board — politically neutral · Art. II §2',
    ],

    'elections/vacancy-countback' => [
        'title'     => 'Vacancy countback',
        'module'    => 'electoral',
        'nav'       => 'vacancy-countback',
        'roles'     => ['R-08'],
        'workflows' => ['WF-ELE-03', 'WF-ELE-04'],
        'forms'     => [
            // Alias 'F-LEG-030' (renumbering drift) resolves via FormRegistry.
            ['id' => 'F-LEG-036', 'availableTo' => ['R-09', 'R-10'], 'citation' => 'Art. II §5 · creates the vacancy record → triggers countback'],
            ['id' => 'F-ELB-004', 'availableTo' => ['R-08'], 'citation' => 'Art. II §2 (transparent election process)'],
            ['id' => 'F-ELB-001', 'availableTo' => ['R-08'], 'citation' => 'Art. II §5 · special election 90–180 d · CLK-04'],
        ],
        'clocks'    => ['CLK-04'],
        'citation'  => 'Countback re-runs prior ballots · special election 90–180 d on failure · Art. II §5 · CLK-04',
    ],

    // FE-B1 dev harness — every Electoral component in every state, rendered
    // from resources/js/fixtures/electoral.json. Dev-gated route only.
    'dev/electoral-kit' => [
        'title'     => 'Electoral component kit',
        'module'    => 'electoral',
        'nav'       => null,
        'roles'     => [],
        'workflows' => [],
        'forms'     => [],
        'clocks'    => [],
        'citation'  => 'Dev harness — fixture-first component verification (FE-B1) · not product UI',
    ],

    'system/audit-chain' => [
        'title'     => 'Audit chain',
        'module'    => 'system',
        'nav'       => 'audit-chain',
        'roles'     => [],
        'workflows' => ['WF-SYS-04'],
        'forms'     => [],
        'clocks'    => [],
        'citation'  => 'Invalid transitions rejected pre-commit; complete tamper-evident history · Art. VII · CGA §6.2, §6.4',
    ],

    /*
    |----------------------------------------------------------------------
    | Phase C — legislature / civic / system surfaces (FE-C0;
    | PHASE_C_DESIGN_frontend.md §B + §B "Surface registry")
    |----------------------------------------------------------------------
    | Contract data from each mockup's CGA_PAGE block
    | (mockups/legislature/*, civic/{petitions,petition-detail,relocation},
    | system/{public-records,term-sync}) + the EXPLORE contract tables.
    | Per-form citations carry the mockup form cards' `basis` strings
    | (mockups/assets/js/fixtures.js forms[]); fixture-specific tallies in
    | page citations ("5 of 9") are generalized — the registry serves every
    | chamber, not the mockup's New York County. Pages land in FE-C2…C11.
    */

    'legislature/legislature-home' => [
        'title'     => 'Chamber',
        'module'    => 'legislature',
        'nav'       => 'legislature-home',
        'roles'     => ['R-09', 'R-10', 'R-11', 'R-12', 'R-13', 'R-29'],
        'workflows' => ['WF-LEG-01', 'WF-LEG-02'],
        'forms'     => [
            // The WF-LEG-01 first-sessions checklist, in constituting order.
            ['id' => 'F-LEG-001', 'citation' => 'Art. II §1 (oath / seating acceptance)'],
            ['id' => 'F-LEG-008', 'citation' => 'Art. II §3 (Speaker election — supermajority RCV)'],
            ['id' => 'F-LEG-032', 'citation' => 'Art. II §2 (rules of order)'],
            ['id' => 'F-LEG-033', 'citation' => 'Art. II §2 (ethics code — binds all elected officials and civil officers)'],
            ['id' => 'F-LEG-013', 'citation' => 'Art. II §2 (independent administrative office)'],
            ['id' => 'F-LEG-012', 'citation' => 'Art. II §2 (proper election board — bootstrap board retired)'],
            ['id' => 'F-LEG-009', 'citation' => 'Art. II §4 (committees by supermajority)'],
        ],
        'clocks'    => ['CLK-01', 'CLK-10'],
        'citation'  => 'Legislature constituted by certified election · Art. II §1–4',
    ],

    'legislature/session-console' => [
        'title'     => 'Session console',
        'module'    => 'legislature',
        'nav'       => 'session-console',
        'roles'     => ['R-09', 'R-10'],
        'workflows' => ['WF-LEG-05', 'WF-LEG-09', 'WF-LEG-20', 'WF-SYS-02'],
        'forms'     => [
            ['id' => 'F-SPK-001', 'citation' => 'Art. II §2 (Hold Regular Meetings; Execute in Constitutional Order)'],
            ['id' => 'F-SPK-002', 'citation' => 'Art. II §2 (Execute Legislative Priorities) · slots 1–2 locked, hardened'],
            ['id' => 'F-SPK-003', 'citation' => 'Art. II §2 (Peg Quorum)'],
            ['id' => 'F-SPK-008', 'citation' => 'Art. II §2 (compel attendance) · WF-LEG-20'],
            ['id' => 'F-SPK-009', 'citation' => 'Art. II §2 (Publish Public Records) · adjourning re-arms CLK-02'],
            ['id' => 'F-LEG-002', 'citation' => 'Art. II §2 (Peg Quorum) — feeds the quorum call, never a vote denominator'],
            ['id' => 'F-LEG-004', 'citation' => 'Art. II §2 (Peg Quorum)'],
            ['id' => 'F-LEG-006', 'citation' => 'Art. II §2 (Publish Public Records) · WF-SYS-03'],
            ['id' => 'F-LEG-007', 'citation' => 'Art. II §2 (Rules of Order)'],
        ],
        'clocks'    => ['CLK-02', 'CLK-03'],
        'citation'  => 'Peg quorum of all serving — never of those present · constitutional order of business · Art. II §2',
    ],

    'legislature/bills' => [
        'title'     => 'Bills',
        'module'    => 'legislature',
        'nav'       => 'bills',
        'roles'     => ['R-09', 'R-10', 'R-11', 'R-12', 'R-13'],
        'workflows' => ['WF-LEG-06', 'WF-LEG-07', 'WF-LEG-14'],
        'forms'     => [
            ['id' => 'F-LEG-003', 'citation' => 'Art. II §2 (Enact Laws via Art. V §4) — scale & scope fixed at introduction'],
            ['id' => 'F-LEG-028', 'citation' => 'Art. V §6 (dual-supermajority act class — constituent consent panel)'],
        ],
        'clocks'    => [],
        'citation'  => 'Scale & scope declared at introduction · Art. II §2 via Art. V §4',
    ],

    'legislature/bill-detail' => [
        'title'     => 'Bill detail',
        'module'    => 'legislature',
        'nav'       => 'bills',
        'roles'     => ['R-09', 'R-10', 'R-11', 'R-12'],
        'workflows' => ['WF-LEG-06', 'WF-LEG-07'],
        'forms'     => [
            ['id' => 'F-LEG-004', 'citation' => 'Art. II §2 (Peg Quorum)'],
            ['id' => 'F-LEG-005', 'citation' => 'Art. II §4 (majority of all committee members)'],
        ],
        'clocks'    => [],
        'citation'  => 'Majority of all serving · supermajority = ceil(serving × 2/3) · Art. II §2; Art. V §3',
    ],

    'legislature/committees' => [
        'title'     => 'Committees',
        'module'    => 'legislature',
        'nav'       => 'committees',
        'roles'     => ['R-09', 'R-10', 'R-11', 'R-13'],
        'workflows' => ['WF-LEG-03', 'WF-LEG-04', 'WF-LEG-13'],
        'forms'     => [
            ['id' => 'F-LEG-009', 'citation' => 'Art. II §4 (committee creation — supermajority act)'],
            ['id' => 'F-LEG-010', 'citation' => 'Art. II §4 · faction-independent (every member ranks every committee)'],
            ['id' => 'F-SPK-005', 'citation' => 'Art. II §3 (Committee Assignments) · normalized-quota tie-break (ledger #q2)'],
            ['id' => 'F-LEG-011', 'citation' => 'Art. II §4 (chair/alternate — whole-legislature RCV)'],
        ],
        'clocks'    => [],
        'citation'  => 'Faction-independent assignment · normalized-quota tie-break · Art. II §4 · as implemented',
    ],

    'legislature/committee-detail' => [
        'title'     => 'Committee detail',
        'module'    => 'legislature',
        'nav'       => 'committees',
        'roles'     => ['R-11', 'R-12', 'R-13'],
        'workflows' => ['WF-LEG-08', 'WF-LEG-13'],
        'forms'     => [
            ['id' => 'F-CHR-001', 'availableTo' => ['R-12', 'R-13'], 'citation' => 'Art. II §4 (meeting call)'],
            ['id' => 'F-CHR-002', 'availableTo' => ['R-12', 'R-13'], 'citation' => 'Art. II §4 (committee agenda)'],
            ['id' => 'F-CHR-003', 'citation' => 'Art. II §4 · enabled only after the committee vote passes'],
            ['id' => 'F-CHR-004', 'citation' => 'Art. II §4 (report → public record)'],
            ['id' => 'F-LEG-005', 'citation' => 'Art. II §4 (majority of all committee members)'],
        ],
        'clocks'    => [],
        'citation'  => 'Committee hearing — testimony, vote, referral, report · Art. II §4',
    ],

    'legislature/speaker-tools' => [
        'title'     => 'Speaker tools',
        'module'    => 'legislature',
        'nav'       => 'speaker-tools',
        'roles'     => ['R-10'],
        'workflows' => ['WF-LEG-02', 'WF-LEG-05', 'WF-LEG-17', 'WF-LEG-20'],
        'forms'     => [
            ['id' => 'F-SPK-001', 'citation' => 'Art. II §2 (Hold Regular Meetings; Execute in Constitutional Order)'],
            ['id' => 'F-SPK-002', 'citation' => 'Art. II §2 (Execute Legislative Priorities)'],
            ['id' => 'F-SPK-003', 'citation' => 'Art. II §2 (Peg Quorum)'],
            ['id' => 'F-SPK-004', 'citation' => 'Art. II §3 (Tie-Breaking Vote)'],
            ['id' => 'F-SPK-005', 'citation' => 'Art. II §3 (Committee Assignments)'],
            ['id' => 'F-SPK-006', 'citation' => 'Art. II §3 (Facilitation)'],
            ['id' => 'F-SPK-007', 'citation' => 'Art. II §3 (Judicial Role) · own-case presiding blocked in code'],
            ['id' => 'F-SPK-008', 'citation' => 'Art. II §2 (compel attendance)'],
            ['id' => 'F-SPK-009', 'citation' => 'Art. II §2 (Publish Public Records)'],
        ],
        'clocks'    => ['CLK-02'],
        'citation'  => 'Politically neutral · votes only to break ties · Art. II §3',
    ],

    'legislature/oversight' => [
        'title'     => 'Oversight & ethics',
        'module'    => 'legislature',
        'nav'       => 'oversight',
        'roles'     => ['R-29', 'R-09', 'R-10'],
        'workflows' => ['WF-LEG-16', 'WF-LEG-17', 'WF-LEG-12'],
        'forms'     => [
            ['id' => 'F-LEG-022', 'citation' => 'Art. III §3; Art. IV §4; Art. II §3 — removal parity, supermajority of all serving'],
            // Alias 'F-LEG-030' (renumbering drift) resolves via FormRegistry.
            ['id' => 'F-LEG-036', 'citation' => 'Art. II §5 · creates the vacancy record → triggers countback'],
        ],
        'clocks'    => ['CLK-04'],
        'citation'  => 'Independent admin office · removal by supermajority · Art. II §2; Art. III §3; Art. IV §4',
    ],

    'legislature/referendums' => [
        'title'     => 'Referendums',
        'module'    => 'legislature',
        'nav'       => 'referendums',
        'roles'     => ['R-09', 'R-10'],
        'workflows' => ['WF-LEG-10', 'WF-LEG-19', 'WF-ELE-07'],
        'forms'     => [
            ['id' => 'F-LEG-023', 'citation' => 'Art. II §6 — threshold derived from the act type, never editable'],
            ['id' => 'F-LEG-034', 'citation' => 'Art. II §6 · CLK-19 (population-supermajority acts shielded)'],
        ],
        'clocks'    => ['CLK-19'],
        'citation'  => 'Threshold fixed by act type · population-supermajority acts shielded · Art. II §6 · CLK-19',
    ],

    'legislature/emergency-powers' => [
        'title'     => 'Emergency powers',
        'module'    => 'legislature',
        'nav'       => 'emergency-powers',
        'roles'     => ['R-09', 'R-10'],
        'workflows' => ['WF-LEG-11', 'WF-JUD-06', 'WF-LEG-05'],
        'forms'     => [
            ['id' => 'F-LEG-024', 'citation' => 'Art. II §7 — disaster or invasion only · ≤ 90 days, validated pre-vote'],
            ['id' => 'F-LEG-025', 'citation' => 'Art. II §7 — fresh supermajority, fresh ≤ 90-day maximum'],
            ['id' => 'F-JDG-007', 'citation' => 'Art. II §7 (Judicial Review) — available at any time, by any inhabitant'],
        ],
        'clocks'    => ['CLK-03'],
        'citation'  => 'Disaster or invasion only · ≤ 90 days · cannot disrupt civic processes · Art. II §7 · CLK-03',
    ],

    'legislature/settings' => [
        'title'     => 'Constitutional settings register',
        'module'    => 'legislature',
        'nav'       => 'settings',
        'roles'     => ['R-09', 'R-10'],
        'workflows' => ['WF-LEG-14', 'WF-LEG-15'],
        'forms'     => [
            ['id' => 'F-LEG-031', 'citation' => 'Art. VII; "unless otherwise amended" clauses — out-of-range rejected pre-vote'],
            ['id' => 'F-LEG-032', 'citation' => 'Art. II §2 (rules of order)'],
            ['id' => 'F-LEG-033', 'citation' => 'Art. II §2 (ethics code)'],
        ],
        'clocks'    => ['CLK-09', 'CLK-10'],
        'citation'  => 'Amendable within hardened bounds · out-of-range rejected pre-vote · Art. VII',
    ],

    'civic/petitions' => [
        'title'     => 'Petitions',
        'module'    => 'civic',
        'nav'       => 'petitions',
        'roles'     => ['R-03', 'R-05'],
        'workflows' => ['WF-CIV-06', 'WF-JUD-09'],
        'forms'     => [
            ['id' => 'F-IND-009', 'citation' => 'Art. II §6 (Creation of Laws by Petition)'],
            ['id' => 'F-IND-010', 'citation' => 'Art. II §6 — revocable until the audited count freezes'],
        ],
        'clocks'    => ['CLK-17'],
        'citation'  => 'Creation of Laws by Petition · threshold % of population · CLK-17 · Art. II §6',
    ],

    'civic/petition-detail' => [
        'title'     => 'Petition detail',
        'module'    => 'civic',
        'nav'       => 'petitions',
        'roles'     => ['R-03', 'R-05', 'R-08', 'R-19'],
        'workflows' => ['WF-CIV-06', 'WF-JUD-09'],
        'forms'     => [
            ['id' => 'F-IND-010', 'citation' => 'Art. II §6 — revocable until the audited count freezes'],
            ['id' => 'F-ELB-005', 'citation' => 'Art. II §6 (independent audit) · CLK-17'],
            ['id' => 'F-JDG-008', 'citation' => 'Art. II §6 (petitions invalidated if unconstitutional) · Planned · Phase E'],
        ],
        'clocks'    => ['CLK-17'],
        'citation'  => 'Petitions face two kill-paths: failed audit, unconstitutional finding · Art. II §6',
    ],

    // Phase K-1 — the civic record plane (public square + halls).
    'civic/public-square' => [
        'title'     => 'Public Square',
        'module'    => 'civic',
        'nav'       => 'public-square',
        'roles'     => ['R-03'],
        'forms'     => [
            ['id' => 'F-SOC-001', 'citation' => 'Art. I — the public square cannot be censored'],
        ],
        'citation'  => 'Open resident discourse · residency-only · uncensorable · Art. I',
    ],

    'civic/halls' => [
        'title'     => 'Halls of Governance',
        'module'    => 'civic',
        'nav'       => 'halls',
        'roles'     => ['R-03'],
        'forms'     => [
            ['id' => 'F-SOC-001', 'citation' => 'Art. I'],
            ['id' => 'F-SOC-002', 'citation' => 'Art. II §2 — filing testimony seals it into the append-only record'],
        ],
        'citation'  => 'Deliberation tied to bills/referendums/petitions/committees · append-only · Art. II §2',
    ],

    // Phase K-3 (K3-L) — the LIVE commons over the Matrix mesh (Plane B), distinct from the Plane-A
    // record views above. Same residency-only gate; pseudonymous; a down homeserver degrades to empty.
    'civic/commons-square' => [
        'title'     => 'Live Square (Matrix)',
        'module'    => 'civic',
        'nav'       => 'commons-square',
        'roles'     => ['R-03'],
        'forms'     => [],
        'citation'  => 'The live public square over the Matrix mesh (Plane B) · residency-only · Art. I',
    ],

    'civic/commons-halls' => [
        'title'     => 'Live Halls (Matrix)',
        'module'    => 'civic',
        'nav'       => 'commons-halls',
        'roles'     => ['R-03'],
        'forms'     => [
            ['id' => 'F-SOC-002', 'citation' => 'Art. II §2 — file a live message as testimony to seal it'],
        ],
        'citation'  => 'The live halls over the Matrix mesh (Plane B) · seated deliberation · Art. II §2',
    ],

    'civic/relocation' => [
        'title'     => 'Relocation',
        'module'    => 'civic',
        // Dedicated nav item (FE-C0; PHASE_C_DESIGN_frontend.md §B nav
        // integration) — the mockup parked it under civic-home.
        'nav'       => 'relocation',
        'roles'     => ['R-03'],
        'workflows' => ['WF-CIV-03'],
        // No citizen-facing F-ID: "I'm travelling" is an engine action
        // (audit-chained); "I'm moving" reuses F-IND-003 on /civic/residency.
        'forms'     => [],
        'clocks'    => ['CLK-05'],
        'citation'  => 'Association transfers with sustained residence · Art. V §1–2',
    ],

    'system/public-records' => [
        'title'     => 'Public records',
        'module'    => 'system',
        'nav'       => 'public-records',
        'roles'     => ['R-03', 'R-09'],
        'workflows' => ['WF-SYS-03'],
        'forms'     => [
            ['id' => 'F-LEG-006', 'citation' => 'Art. II §2 (Publish Public Records) — corrections append, never edit'],
        ],
        'clocks'    => [],
        'citation'  => 'Public, readily available, immutable record · Art. II §2 · WF-SYS-03',
    ],

    'system/term-sync' => [
        'title'     => 'Term lockstep',
        'module'    => 'system',
        'nav'       => 'term-sync',
        'roles'     => [],
        'workflows' => ['WF-SYS-01'],
        // Zero actions by design — the page's whole point is that there is
        // no skip/delay/reschedule API (PHASE_C_DESIGN_frontend.md §B.16).
        'forms'     => [],
        'clocks'    => ['CLK-01', 'CLK-09', 'CLK-10'],
        'citation'  => 'Terms expire in lockstep; election triggers derive from one clock · Art. III §3; Art. IV §3 · CLK-01 · CLK-10',
    ],

    // FE-C1 dev harness — every Phase C legislature component in every
    // state, rendered from resources/js/fixtures/legislature.json.
    // Dev-gated route only (pattern: dev/electoral-kit).
    'dev/legislature-kit' => [
        'title'     => 'Legislature component kit',
        'module'    => 'legislature',
        'nav'       => null,
        'roles'     => [],
        'workflows' => [],
        'forms'     => [],
        'clocks'    => [],
        'citation'  => 'Dev harness — fixture-first component verification (FE-C1) · not product UI',
    ],

    /*
    |----------------------------------------------------------------------
    | Phase D — executive / organizations surfaces (FE-D0;
    | PHASE_D_DESIGN_frontend.md §B + §E row FE-D0)
    |----------------------------------------------------------------------
    | Contract data from each mockup's CGA_PAGE block (mockups/executive/*,
    | mockups/organizations/*) + the EXPLORE contract tables. Per-form
    | citations carry the mockup form cards' `basis` strings
    | (mockups/assets/js/fixtures.js forms[]); fixture-specific titles
    | ("Public Works & Utilities — department detail") are generalized —
    | the registry serves every instance, not the mockup's New York County.
    | The F-BOG-001/002 ↔ F-GOV-001/002 catalog drift resolves through
    | FormRegistry as established (SurfaceMeta::form()). Pages land in
    | FE-D2…D9; registering the ids first lets every controller pass
    | SurfaceMeta::for() from day one.
    */

    'executive/executive-home' => [
        'title'     => 'Executive home',
        'module'    => 'executive',
        'nav'       => 'executive-home',
        'roles'     => ['R-14', 'R-15', 'R-16', 'R-17'],
        'workflows' => ['WF-EXE-01', 'WF-EXE-02', 'WF-EXE-03'],
        'forms'     => [
            ['id' => 'F-LEG-014', 'availableTo' => ['R-09'], 'citation' => 'Art. III §2 — delegation by act; members remain seated legislators'],
            ['id' => 'F-LEG-015', 'availableTo' => ['R-09'], 'citation' => 'Art. III §3 · Art. VII — dual supermajority (own chamber + constituent jurisdictions)'],
        ],
        'clocks'    => ['CLK-10'],
        'citation'  => 'Delegated by default · conversion by dual supermajority · Art. III §2–3 · CLK-10',
    ],

    'executive/departments' => [
        'title'     => 'Department registry',
        'module'    => 'executive',
        'nav'       => 'departments',
        'roles'     => ['R-14', 'R-15', 'R-16', 'R-30'],
        'workflows' => ['WF-EXE-04', 'WF-EXE-05', 'WF-EXE-06'],
        'forms'     => [
            ['id' => 'F-LEG-016', 'availableTo' => ['R-09'], 'citation' => 'Art. II §9 — department creation is an ordinary-majority act'],
            ['id' => 'F-EXE-001', 'citation' => 'Art. III §4 — nomination dossier; governors are politically neutral'],
            ['id' => 'F-LEG-020', 'availableTo' => ['R-09'], 'citation' => 'Art. III §4 — consent = peg-quorum majority of all serving'],
            ['id' => 'F-EXE-003', 'citation' => 'Art. III §4 — removal by ordinary majority of all serving (hiring and firing)'],
        ],
        'clocks'    => ['CLK-09', 'CLK-13', 'CLK-14'],
        'citation'  => 'Departments · Art. II §9 — boards & oversight · Art. III §4 — co-determination · Art. III §6',
    ],

    'executive/department-detail' => [
        'title'     => 'Department detail',
        'module'    => 'executive',
        'nav'       => 'departments',
        'roles'     => ['R-14', 'R-15', 'R-16', 'R-18', 'R-30'],
        'workflows' => ['WF-EXE-04', 'WF-EXE-05', 'WF-EXE-06'],
        'forms'     => [
            ['id' => 'F-EXE-001', 'citation' => 'Art. III §4 — triggers the F-LEG-020 consent vote (peg-quorum majority of all serving)'],
            ['id' => 'F-EXE-003', 'citation' => 'Art. III §4 — good-faith competence/ethics finding; grounds published'],
        ],
        'clocks'    => ['CLK-09', 'CLK-13', 'CLK-14'],
        'citation'  => 'Department charter & board · Art. II §9 · Art. III §4 — worker seats · Art. III §6',
    ],

    'executive/department-reporting' => [
        'title'     => 'Department reporting',
        'module'    => 'executive',
        'nav'       => 'department-reporting',
        'roles'     => ['R-18'],
        'workflows' => ['WF-EXE-09'],
        'forms'     => [
            // Catalog aliases F-GOV-001/002 (renumbering drift) resolve via FormRegistry.
            ['id' => 'F-BOG-001', 'citation' => 'Art. III §4 — rules implement, they cannot exceed, the charter and enabling acts'],
            ['id' => 'F-BOG-002', 'citation' => 'Art. III §4 — reports to executive + legislature, published to public record'],
        ],
        'clocks'    => ['CLK-09', 'CLK-03'],
        'citation'  => 'Implementation rules & reports to executive + legislature · Art. III §4 · CLK-09',
    ],

    'executive/executive-actions' => [
        'title'     => 'Executive actions',
        'module'    => 'executive',
        'nav'       => 'executive-actions',
        'roles'     => ['R-14', 'R-15', 'R-16'],
        'workflows' => ['WF-EXE-07', 'WF-EXE-08'],
        'forms'     => [
            ['id' => 'F-EXE-005', 'citation' => 'Art. III §1–3 — scope validated pre-issuance; rejections go on the public record'],
            ['id' => 'F-EXE-002', 'citation' => 'Art. III §4 (Powers and Oversight) — the board adopts, amends, or declines'],
            ['id' => 'F-EXE-004', 'citation' => 'Art. III §4 — full and equal investigative power over overseen departments'],
        ],
        'clocks'    => ['CLK-03'],
        'citation'  => 'Orders within delegated scope · Art. III §2–3 — judicially reviewable · Art. IV §5',
    ],

    'organizations/org-registry' => [
        'title'     => 'Organization registry',
        'module'    => 'organizations',
        'nav'       => 'org-registry',
        'roles'     => ['R-03', 'R-23'],
        'workflows' => ['WF-ORG-01'],
        'forms'     => [
            ['id' => 'F-IND-012', 'availableTo' => ['R-03'], 'citation' => 'Art. I (Economic Freedom) — any associated resident may register'],
            ['id' => 'F-ORG-001', 'citation' => 'Art. I (Economic Freedom)'],
        ],
        'clocks'    => ['CLK-13'],
        'citation'  => 'One registry, no faction layer · Art. I · Art. III §5–6',
    ],

    'organizations/org-detail' => [
        'title'     => 'Organization profile',
        'module'    => 'organizations',
        'nav'       => 'org-registry',
        'roles'     => ['R-23', 'R-24', 'R-06', 'R-07'],
        'workflows' => ['WF-ORG-02', 'WF-ORG-03'],
        'forms'     => [
            ['id' => 'F-ORG-001', 'citation' => 'Art. I (Economic Freedom) — self-managed profile'],
            ['id' => 'F-CAN-002', 'availableTo' => ['R-06'], 'citation' => 'Art. I (Freedom of Assembly) — the candidate requests'],
            ['id' => 'F-ORG-002', 'citation' => 'Art. I (Freedom of Assembly) — granting confers R-07'],
            ['id' => 'F-IND-013', 'availableTo' => ['R-01'], 'citation' => 'Art. I (Freedom of Assembly) — creates the membership record (R-24)'],
            ['id' => 'F-IND-014', 'availableTo' => ['R-01'], 'citation' => 'Art. III §6 (Work Councils) — THE co-determination headcount feed · CLK-13 / CLK-14'],
        ],
        'clocks'    => ['CLK-13', 'CLK-14'],
        'citation'  => 'Endorsements are polymorphic — granting confers R-07 · Art. I · Art. II §2',
    ],

    'organizations/cgc-detail' => [
        'title'     => 'Common Good Corporation',
        'module'    => 'organizations',
        'nav'       => 'org-registry',
        'roles'     => ['R-09', 'R-18', 'R-25', 'R-27'],
        'workflows' => ['WF-ORG-08', 'WF-ORG-09', 'WF-ORG-04'],
        'forms'     => [
            ['id' => 'F-LEG-019', 'availableTo' => ['R-09'], 'citation' => 'Art. III §5 — the legislature creates; oversight assigned at creation'],
        ],
        'clocks'    => ['CLK-13', 'CLK-14'],
        'citation'  => 'Regulated identically to private peers · IP perpetually public domain · Art. III §5',
    ],

    'organizations/board-elections' => [
        'title'     => 'Board elections',
        'module'    => 'organizations',
        'nav'       => 'org-registry',
        'roles'     => ['R-23', 'R-24', 'R-25', 'R-26', 'R-27', 'R-28'],
        'workflows' => ['WF-ORG-05', 'WF-ORG-04'],
        'forms'     => [
            ['id' => 'F-ORG-003', 'citation' => 'Art. III §4, §6 — owner track; the same PR-STV engine as public elections'],
            ['id' => 'F-ORG-004', 'citation' => 'Art. III §6 — worker track; also fired system-side by CLK-13'],
        ],
        'clocks'    => ['CLK-13', 'CLK-14'],
        'citation'  => 'Owner STV + worker STV + joint chair RCV · Art. III §4, §6',
    ],

    'organizations/co-determination' => [
        'title'     => 'Co-determination scaling',
        'module'    => 'organizations',
        'nav'       => 'co-determination',
        'roles'     => ['R-25', 'R-27', 'R-23'],
        'workflows' => ['WF-ORG-04', 'WF-ORG-05'],
        'forms'     => [
            ['id' => 'F-ORG-004', 'citation' => 'Art. III §6 — composition change re-triggers the joint chair election'],
        ],
        'clocks'    => ['CLK-13', 'CLK-14'],
        // Threshold values are AMENDABLE (CLK-13/14) — the registry line
        // states the rule, the page renders the live resolved values.
        'citation'  => 'First worker seat at the CLK-13 minimum · parity at the CLK-14 threshold · Art. III §6',
    ],

    'organizations/transfers-conversions' => [
        'title'     => 'Transfers and conversions',
        'module'    => 'organizations',
        'nav'       => 'org-registry',
        'roles'     => ['R-23', 'R-24', 'R-09'],
        'workflows' => ['WF-ORG-06', 'WF-ORG-07', 'WF-ORG-09', 'WF-ORG-10'],
        'forms'     => [
            ['id' => 'F-ORG-005', 'citation' => 'Art. I (Freedom to Contract) — mutual consent mandatory, no hostile path'],
            ['id' => 'F-LEG-026', 'availableTo' => ['R-09'], 'citation' => 'Art. III §5 — the only path overriding owner consent; ordinary majority of all serving'],
            ['id' => 'F-ORG-006', 'citation' => 'Art. III §5 (conversion provisions)'],
            ['id' => 'F-LEG-027', 'availableTo' => ['R-09'], 'citation' => 'Art. III §5 — public→private runs through the legislature; public-domain IP stays public'],
            ['id' => 'F-ORG-007', 'citation' => 'Art. I (Economic Freedom) — voluntary; judicial dissolution (WF-ORG-10) is Phase E'],
        ],
        'clocks'    => [],
        'citation'  => 'Mutual consent mandatory; monopoly path compensates ≥ fair market (hardened) · Art. III §5',
    ],

    // FE-D1 dev harness — every Phase D executive/organizations component
    // in every state, rendered from resources/js/fixtures/executive.json.
    // Dev-gated route only (pattern: dev/electoral-kit, dev/legislature-kit).
    'dev/executive-kit' => [
        'title'     => 'Executive & organizations component kit',
        'module'    => 'executive',
        'nav'       => null,
        'roles'     => [],
        'workflows' => [],
        'forms'     => [],
        'clocks'    => [],
        'citation'  => 'Dev harness — fixture-first component verification (FE-D1) · not product UI',
    ],

    /*
    |----------------------------------------------------------------------
    | Phase E — judiciary surfaces (FE-E0; PHASE_E_DESIGN_frontend.md §B)
    |----------------------------------------------------------------------
    | Contract data from each mockup's CGA_PAGE block (mockups/judiciary/*)
    | + the §B per-page surface rows. ids = mockup ids. Per-form citations
    | carry the mockup form cards' `basis` strings. PUBLIC-READ posture
    | (the defining Phase E rule, Art. II §2): dockets / opinions /
    | challenges are public record; actions gate by derived role via can.*
    | + engine 422, never a page 403. The F-IND-013 → F-IND-016 drift
    | (CATALOG_DRIFT, WF-JUD-05) resolves through FormRegistry for display
    | only. Pages land in FE-E2…E6; registering the ids first lets every
    | controller pass SurfaceMeta::for() from day one. `nav:` maps the
    | detail surfaces onto the role-gated court items — case-detail →
    | case-docket; the rest map to themselves (the JudiciaryResolverController
    | keeps the nav hrefs stable while canonical surfaces are court-scoped).
    */

    'judiciary/judiciary-home' => [
        'title'     => 'Judiciary home',
        'module'    => 'judiciary',
        'nav'       => 'judiciary-home',
        'roles'     => ['R-19', 'R-20'],
        'workflows' => ['WF-JUD-01', 'WF-JUD-02', 'WF-JUD-07'],
        'forms'     => [
            ['id' => 'F-LEG-017', 'availableTo' => ['R-09'], 'citation' => 'Art. IV §2 — judiciary creation by supermajority act of the legislature'],
            ['id' => 'F-LEG-021', 'availableTo' => ['R-09'], 'citation' => 'Art. IV §2 — judicial nomination consent vote, same supermajority as creation'],
            ['id' => 'F-LEG-018', 'availableTo' => ['R-09'], 'citation' => 'Art. IV §3 — conversion to elected, dual supermajority (legislature + constituent jurisdictions)'],
        ],
        'clocks'    => ['CLK-09', 'CLK-10', 'CLK-15', 'CLK-16'],
        'citation'  => 'Appointed judiciary · panels ≥3 odd, full court for major questions · Art. IV §2; Art. IV §4 · CLK-16',
    ],

    'judiciary/case-docket' => [
        'title'     => 'Case docket',
        'module'    => 'judiciary',
        'nav'       => 'case-docket',
        'roles'     => ['R-19', 'R-20', 'R-21', 'R-03'],
        'workflows' => ['WF-JUD-03'],
        'forms'     => [
            ['id' => 'F-IND-017', 'availableTo' => ['R-03'], 'citation' => 'Art. IV §4 — civil/criminal case filing; the court classifies justiciability + severity at acceptance'],
            ['id' => 'F-IND-016', 'availableTo' => ['R-03'], 'citation' => 'Art. IV §5 — constitutional challenge; any inhabitant, no standing gatekeeper'],
            ['id' => 'F-ADV-001', 'availableTo' => ['R-21'], 'citation' => 'Art. I — case filing on behalf of a client'],
            ['id' => 'F-JDG-001', 'availableTo' => ['R-19', 'R-20'], 'citation' => 'Art. IV §4 — case acceptance + panel assignment with conflict screening; hardened'],
        ],
        'clocks'    => ['CLK-16'],
        'citation'  => 'Panel ≥3 and odd, scaled to severity · full court for major constitutional questions · Art. IV §4 · CLK-16',
    ],

    'judiciary/case-detail' => [
        'title'     => 'Case detail',
        'module'    => 'judiciary',
        'nav'       => 'case-docket',
        'roles'     => ['R-19', 'R-20', 'R-21', 'R-22'],
        'workflows' => ['WF-JUD-03', 'WF-JUD-04'],
        'forms'     => [
            ['id' => 'F-IND-017', 'availableTo' => ['R-03'], 'citation' => 'Art. IV §4 — civil/criminal case filing'],
            ['id' => 'F-JDG-001', 'availableTo' => ['R-19', 'R-20'], 'citation' => 'Art. IV §4 — case acceptance + conflict-screened panel; hardened'],
            ['id' => 'F-JDG-002', 'availableTo' => ['R-19', 'R-20'], 'citation' => 'Art. IV §4 — jury selection order; random draw, seed published to the audit chain'],
            ['id' => 'F-ADV-002', 'availableTo' => ['R-21'], 'citation' => 'Art. IV §4 — motion filing'],
            ['id' => 'F-ADV-003', 'availableTo' => ['R-21'], 'citation' => 'Art. IV §4 — evidence submission'],
            ['id' => 'F-JDG-003', 'availableTo' => ['R-19', 'R-20'], 'citation' => 'Art. IV §4–§5 — opinion / ruling, commentary on the law as written or edited'],
            ['id' => 'F-JDG-009', 'availableTo' => ['R-19', 'R-20'], 'citation' => 'Art. II §8 — sentencing order; criminal outcome carries the double-jeopardy flag'],
            ['id' => 'F-JDG-010', 'availableTo' => ['R-19', 'R-20'], 'citation' => 'Art. I — warrant; stated reason and duration required'],
        ],
        'clocks'    => ['CLK-16'],
        'citation'  => 'Case lifecycle · panel ≥3 odd · Art. IV §4 · CLK-16 — double jeopardy barred · Art. II §8',
    ],

    'judiciary/constitutional-challenge' => [
        'title'     => 'Constitutional challenge tracker',
        'module'    => 'judiciary',
        'nav'       => 'constitutional-challenge',
        'roles'     => ['R-03', 'R-09', 'R-19', 'R-20'],
        'workflows' => ['WF-JUD-05'],
        'forms'     => [
            ['id' => 'F-IND-016', 'availableTo' => ['R-03'], 'citation' => 'Art. IV §5 — constitutional challenge filing; any inhabitant, no standing gatekeeper'],
            ['id' => 'F-JDG-004', 'availableTo' => ['R-19', 'R-20'], 'citation' => 'Art. IV §5 — constitutional finding (a contradiction in law)'],
            ['id' => 'F-JDG-005', 'availableTo' => ['R-19', 'R-20'], 'citation' => 'Art. IV §5 — remedy recommendation; sets the CLK-11/CLK-12 per-case windows'],
            ['id' => 'F-JDG-006', 'availableTo' => ['R-19', 'R-20'], 'citation' => 'Art. IV §5 — judiciary applies the remedy directly when the window closes; judicial_remedy law version'],
            ['id' => 'F-LEG-035', 'availableTo' => ['R-09'], 'citation' => 'Art. IV §5; Art. VII — supermajority override of all serving, cast within the veto window'],
        ],
        'clocks'    => ['CLK-11', 'CLK-12', 'CLK-16'],
        'citation'  => 'Amend, override by supermajority in the veto window, or the judiciary edits the law · Art. IV §5 · CLK-11 · CLK-12',
    ],

    'judiciary/advocate-console' => [
        'title'     => 'Advocate console',
        'module'    => 'judiciary',
        'nav'       => 'advocate-console',
        'roles'     => ['R-21', 'R-03'],
        'workflows' => ['WF-CIV-07', 'WF-JUD-03'],
        'forms'     => [
            ['id' => 'F-IND-015', 'availableTo' => ['R-03'], 'citation' => 'Art. IV §4; Art. I — advocate registration; confers R-21, keeps the bar zealous and competent'],
            ['id' => 'F-ADV-001', 'availableTo' => ['R-21'], 'citation' => 'Art. I — new case on behalf of a client; queued for acceptance + panel assignment'],
            ['id' => 'F-ADV-002', 'availableTo' => ['R-21'], 'citation' => 'Art. IV §4 — motion filing; stage-gated by the engine'],
            ['id' => 'F-ADV-003', 'availableTo' => ['R-21'], 'citation' => 'Art. IV §4 — evidence submission; on the open docket'],
            ['id' => 'F-ADV-004', 'availableTo' => ['R-21'], 'citation' => 'Art. IV §4 — brief / argument; accepted until deliberation'],
        ],
        'clocks'    => [],
        'citation'  => 'Zealous & competent advocates · Art. IV §4 — right to legal representation · Art. I',
    ],

    'judiciary/juror-view' => [
        'title'     => 'Juror view',
        'module'    => 'judiciary',
        'nav'       => 'juror-view',
        'roles'     => ['R-22'],
        'workflows' => ['WF-JUD-04'],
        'forms'     => [
            ['id' => 'F-JDG-002', 'availableTo' => ['R-19', 'R-20'], 'citation' => 'Art. IV §4 — the Jury Selection Order that created this summons'],
        ],
        'clocks'    => [],
        'citation'  => 'Jury of peers · Art. IV §4 — no interference, no fees · Art. II §8',
    ],

    // FE-E1 dev harness — every Phase E judiciary component in every state,
    // rendered from resources/js/fixtures/judiciary.json. Dev-gated route
    // only (pattern: dev/electoral-kit, dev/legislature-kit, dev/executive-kit).
    'dev/judiciary-kit' => [
        'title'     => 'Judiciary component kit',
        'module'    => 'judiciary',
        'nav'       => null,
        'roles'     => [],
        'workflows' => [],
        'forms'     => [],
        'clocks'    => [],
        'citation'  => 'Dev harness — fixture-first component verification (FE-E1) · not product UI',
    ],

    /*
    |----------------------------------------------------------------------
    | mockups-v3-wiring Phase 2 — system read-only pages
    |----------------------------------------------------------------------
    | Contract data from the mockups' CGA_PAGE blocks
    | (mockups/v3/shared/clocks.html, mockups/v3/system/amendments.html).
    | Both READ-ONLY BY DESIGN — zero forms, zero actions.
    */

    'system/clocks' => [
        'title'     => 'The clocks',
        'module'    => 'system',
        'nav'       => 'clocks',
        'roles'     => [],
        'workflows' => [],
        'forms'     => [],
        // The whole registry — this page IS the scheduler spec.
        'clocks'    => [
            'CLK-01', 'CLK-02', 'CLK-03', 'CLK-04', 'CLK-05', 'CLK-06', 'CLK-07',
            'CLK-08', 'CLK-09', 'CLK-10', 'CLK-11', 'CLK-12', 'CLK-13', 'CLK-14',
            'CLK-15', 'CLK-16', 'CLK-17', 'CLK-18', 'CLK-19', 'CLK-20', 'CLK-21',
        ],
        'citation'  => 'Clocks hold no state — they move other things · the registry is the scheduler spec',
    ],

    'system/amendments' => [
        'title'     => 'Amendments',
        'module'    => 'system',
        'nav'       => 'amendments',
        'roles'     => ['R-09'],
        'workflows' => ['WF-SYS-05'],
        'forms'     => [],
        'clocks'    => [],
        'citation'  => 'Two doors: amendable settings by valid act within hardened bounds; the hardened layer only by a release passing every constitutional check · Art. VII',
    ],

    /*
    |----------------------------------------------------------------------
    | mockups-v3-wiring Phase 3c — the journeys engine
    |----------------------------------------------------------------------
    | Contract data from mockups/v3/journeys/journey.html (CGA_PAGE block)
    | + config/cga/journeys.php. Zero forms/clocks — journeys are the learn
    | layer: they nudge, never gate, and a medal grants nothing.
    */

    'civic/journeys' => [
        'title'     => 'Journeys',
        'module'    => 'civic',
        'nav'       => 'journeys',
        'roles'     => [],
        'workflows' => [],
        'forms'     => [],
        'clocks'    => [],
        'citation'  => 'A medal never changes a vote, a seat, or what you are allowed to do.',
    ],

    'civic/journey' => [
        'title'     => 'A journey',
        'module'    => 'civic',
        'nav'       => 'journeys',
        'roles'     => [],
        'workflows' => [],
        'forms'     => [],
        'clocks'    => [],
        'citation'  => 'A medal never changes a vote, a seat, or what you are allowed to do.',
    ],

    /*
    |----------------------------------------------------------------------
    | mockups-v3-wiring Phase 3e — the monolith retirements
    |----------------------------------------------------------------------
    | Legislature/Show (5.2k) split into the overview (legislature/overview)
    | + the extracted district mapper (legislature/districts); the
    | jurisdiction viewer joins the v2 shell (jurisdictions/viewer).
    */

    'legislature/overview' => [
        'title'     => 'A legislature',
        'module'    => 'jurisdictions',
        'nav'       => 'legislatures',
        'roles'     => [],
        'workflows' => ['WF-JUR-01'],
        'forms'     => [],
        'clocks'    => ['CLK-06'],
        'citation'  => 'Every legislature sized by the cube-root law; 5–9 seats per district · Art. II §2',
    ],

    'legislature/districts' => [
        'title'     => 'The district mapper',
        'module'    => 'jurisdictions',
        'nav'       => 'legislatures',
        'roles'     => ['R-08'],
        'workflows' => ['WF-JUR-01'],
        'forms'     => [
            ['id' => 'F-ELB-008', 'citation' => 'Art. II §2 (manual district draw — childless leaf giants)'],
        ],
        'clocks'    => [],
        'citation'  => 'Districts partition the jurisdiction; every district seats 5–9 · Webster rounding · Art. II §2',
    ],

    'jurisdictions/viewer' => [
        'title'     => 'A jurisdiction',
        'module'    => 'jurisdictions',
        'nav'       => 'jurisdiction-browser',
        'roles'     => [],
        'workflows' => ['WF-JUR-01'],
        'forms'     => [],
        'clocks'    => ['CLK-06'],
        'citation'  => 'Every place governs itself — sitting inside a bigger one changes scope, never rank · Art. V §1',
    ],

];
