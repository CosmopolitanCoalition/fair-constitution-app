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
        'roles'     => ['R-03', 'R-04'],
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
