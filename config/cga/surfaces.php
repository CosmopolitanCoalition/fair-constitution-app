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

];
