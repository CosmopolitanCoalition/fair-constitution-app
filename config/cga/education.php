<?php

/*
|--------------------------------------------------------------------------
| K-2 education — the SERVER-ONLY registry (K2_ENGINE_PLAN §8.1)
|--------------------------------------------------------------------------
|
| ⚠ THIS FILE DELIBERATELY HAS NO CLIENT MIRROR. Every other config/cga/*
| registry mirrors into resources/js/registry/* — this one CANNOT, because
| the education plane's server half will carry answer keys (correct_keys)
| and grading config that must never reach a browser (K2_ENGINE_PLAN §2,
| pinned by EducationAnswerKeySecrecyTest). The display half (titles,
| prompts, choices) lives in resources/js/registry/education.js and the
| two are non-mirroring ON PURPOSE — do not "fix" this by syncing them.
|
| THE ACT-GATE (operator ruling A5, 2026-07-29 — "acquiring is free,
| acting asks"): acquiring any role is NEVER blocked. The gate binds at
| the first ROLE-AUTHORITY act: filing a form listed in `gated_forms`
| while untrained draws a structured `training_required` refusal that
| redirects to the track's Learn content. One rule, one home —
| TrainingGateService, consulted by the ConstitutionalEngine only.
|
| WHAT IS NEVER LISTED HERE, AS LAW (§5.2/§6.5 — pinned by
| EducationNoGateTest, and TrainingGateService additionally refuses the
| rights families STRUCTURALLY even if a bad edit lists one):
|   - Rights-acts: voting (F-IND-007/008), candidacy (F-IND-011),
|     residency (F-IND-003/006), petitions (F-IND-009/010), speech
|     (F-SOC-*), and every other F-IND/F-CAN right.
|   - Acquisition: F-LEG-001 (oath/seating IS acquiring, not acting).
|   - Presence: F-LEG-002 (quorum can form with untrained members).
|   - Speech: F-LEG-006 (a statement on the record is never gated).
|   - The exit door: F-LEG-036 (a member who never trains can still
|     vacate — a locked exit would trap them in the seat).
|   - F-SPK-009 (dual-filer R-10/R-29 record-keeping).
|   - F-EDU-001 itself (the door the redirect points to cannot lock).
|   - The org/content plane: F-ORG-* (private association), F-EDU-002.
*/

return [

    // ── The role→track map (§5.2/§8.1) ─────────────────────────────────
    // Which curriculum track a role's acquisition notice names, and which
    // track a gated form demands. Speaker/committee roles share the
    // chamber's track (v1): their authority is chamber authority.
    'tracks_by_role' => [
        'R-08' => 'election_board',
        'R-09' => 'legislature',
        'R-10' => 'legislature',
        'R-11' => 'legislature',
        'R-12' => 'legislature',
        'R-14' => 'executive',
        'R-15' => 'executive',
        'R-16' => 'executive',
        'R-18' => 'board_of_governors',
        'R-19' => 'judiciary',
        'R-20' => 'judiciary',
        'R-21' => 'advocate',
    ],

    // ── The gated forms: form id → the track whose completion opens it ──
    'gated_forms' => [
        // Legislature — chamber votes, motions, sponsorships, creation
        // acts, committee + speaker authority.
        'F-LEG-003' => 'legislature', 'F-LEG-004' => 'legislature',
        'F-LEG-005' => 'legislature', 'F-LEG-007' => 'legislature',
        'F-LEG-008' => 'legislature', 'F-LEG-009' => 'legislature',
        'F-LEG-010' => 'legislature', 'F-LEG-011' => 'legislature',
        'F-LEG-012' => 'legislature', 'F-LEG-013' => 'legislature',
        'F-LEG-014' => 'legislature', 'F-LEG-015' => 'legislature',
        'F-LEG-016' => 'legislature', 'F-LEG-017' => 'legislature',
        'F-LEG-018' => 'legislature', 'F-LEG-019' => 'legislature',
        'F-LEG-022' => 'legislature', 'F-LEG-023' => 'legislature',
        'F-LEG-024' => 'legislature', 'F-LEG-025' => 'legislature',
        'F-LEG-026' => 'legislature', 'F-LEG-027' => 'legislature',
        'F-LEG-028' => 'legislature', 'F-LEG-029' => 'legislature',
        'F-LEG-030' => 'legislature', 'F-LEG-031' => 'legislature',
        'F-LEG-032' => 'legislature', 'F-LEG-033' => 'legislature',
        'F-LEG-034' => 'legislature', 'F-LEG-035' => 'legislature',
        'F-SPK-001' => 'legislature', 'F-SPK-002' => 'legislature',
        'F-SPK-003' => 'legislature', 'F-SPK-004' => 'legislature',
        'F-SPK-005' => 'legislature', 'F-SPK-006' => 'legislature',
        'F-SPK-007' => 'legislature', 'F-SPK-008' => 'legislature',
        'F-CHR-001' => 'legislature', 'F-CHR-002' => 'legislature',
        'F-CHR-003' => 'legislature', 'F-CHR-004' => 'legislature',

        // Election board.
        'F-ELB-001' => 'election_board', 'F-ELB-002' => 'election_board',
        'F-ELB-003' => 'election_board', 'F-ELB-004' => 'election_board',
        'F-ELB-005' => 'election_board', 'F-ELB-006' => 'election_board',
        'F-ELB-008' => 'election_board',

        // Executive.
        'F-EXE-001' => 'executive', 'F-EXE-002' => 'executive',
        'F-EXE-003' => 'executive', 'F-EXE-004' => 'executive',
        'F-EXE-005' => 'executive',

        // Board of Governors.
        'F-BOG-001' => 'board_of_governors', 'F-BOG-002' => 'board_of_governors',

        // Judiciary.
        'F-JDG-001' => 'judiciary', 'F-JDG-002' => 'judiciary',
        'F-JDG-003' => 'judiciary', 'F-JDG-004' => 'judiciary',
        'F-JDG-005' => 'judiciary', 'F-JDG-006' => 'judiciary',
        'F-JDG-007' => 'judiciary', 'F-JDG-008' => 'judiciary',
        'F-JDG-009' => 'judiciary', 'F-JDG-010' => 'judiciary',

        // Advocates.
        'F-ADV-001' => 'advocate', 'F-ADV-002' => 'advocate',
        'F-ADV-003' => 'advocate', 'F-ADV-004' => 'advocate',
    ],

    // ── Where the refusal's redirect lands, per track (§5.2) ────────────
    // The lesson pages themselves are the Learn build (this wave, step ⑤);
    // the contract is fixed here so the structured refusal is stable.
    'lesson_href_by_track' => [
        'legislature'        => '/learn/legislature',
        'election_board'     => '/learn/election_board',
        'executive'          => '/learn/executive',
        'board_of_governors' => '/learn/board_of_governors',
        'judiciary'          => '/learn/judiciary',
        'advocate'           => '/learn/advocate',
    ],

    // ── Journey → role track (Wave 4 §③ — the "Understand it first" door) ─
    // A guided journey whose subject IS a role's work links to that role's
    // Learn track, so a player can understand the system before walking the
    // steps. Rights/process/org journeys (become-a-resident, form-a-group,
    // petition-to-referendum, …) map to nothing — there is no role to train
    // for, and the panel falls back to the general Learn library.
    'journey_to_track' => [
        'election'          => 'election_board',
        'committee-session' => 'legislature',
        'bill'              => 'legislature',
        'budget'            => 'legislature',
        'court-case'        => 'judiciary',
        'board-meeting'     => 'board_of_governors',
    ],

    // ── The one-time training stipend (§5.0.1) ──────────────────────────
    // Call-site default for the amendable `training_stipend_amount`
    // setting (nullable column; SettingsResolver inherits up the chain).
    // The once-only proof is the achievement ledger: the stipend pays iff
    // ACH-EDU-001 was NEWLY minted — no second idempotency mechanism.
    'training_stipend_default' => 10,

    // ── Grading (§3.5) ──────────────────────────────────────────────────
    // A basic comprehension check: pass at or above the threshold; retakes
    // unlimited by design (throttle the guessing, never gate the learner —
    // the route carries the house throttle). Wrong answers are never
    // persisted.
    'pass_threshold_pct' => 80,

    // ── THE CONTENT CATALOG (server-side canonical, §8.1) ───────────────
    // `education:seed` writes this into education_tracks/modules/questions.
    // Prompt/choice/explain values are i18n KEYS into c_education.json —
    // the K-2 i18n contract (lane 5 translates what is authored here).
    // `correct` names the correct choice keys and is written ONLY to
    // education_questions.correct_keys (SERVER ONLY — the §2 rail). The
    // explain key is the teaching text served AFTER grading; it never
    // names the correct choice outright.
    //
    // ⚠ ARMING: seeding a live module for a track ARMS the act-gate for
    // that track's forms on this box (a redirect now has a destination) —
    // every untrained role-holder's next role-act will redirect. Sequence
    // with fixture training (plan §5.2, the availability precondition).
    'content' => [
        'legislature' => [
            'title' => 'c_education.learn.tracks.legislature.title',
            'unit_ref' => 'K2_CURRICULUM U-LEG',
            'modules' => [[
                'key' => 'chamber-basics',
                'title' => 'c_education.learn.modules.chamber_basics.title',
                'summary' => 'c_education.learn.modules.chamber_basics.summary',
                'surface_id' => 'legislature/floor',
                'minutes' => 6,
                'questions' => [
                    ['key' => 'q1', 'prompt' => 'c_education.learn.q.chamber_basics.q1.prompt',
                     'choices' => ['a' => 'c_education.learn.q.chamber_basics.q1.a', 'b' => 'c_education.learn.q.chamber_basics.q1.b', 'c' => 'c_education.learn.q.chamber_basics.q1.c'],
                     'correct' => ['b'], 'explain' => 'c_education.learn.q.chamber_basics.q1.explain'],
                    ['key' => 'q2', 'prompt' => 'c_education.learn.q.chamber_basics.q2.prompt',
                     'choices' => ['a' => 'c_education.learn.q.chamber_basics.q2.a', 'b' => 'c_education.learn.q.chamber_basics.q2.b', 'c' => 'c_education.learn.q.chamber_basics.q2.c'],
                     'correct' => ['c'], 'explain' => 'c_education.learn.q.chamber_basics.q2.explain'],
                    ['key' => 'q3', 'prompt' => 'c_education.learn.q.chamber_basics.q3.prompt',
                     'choices' => ['a' => 'c_education.learn.q.chamber_basics.q3.a', 'b' => 'c_education.learn.q.chamber_basics.q3.b', 'c' => 'c_education.learn.q.chamber_basics.q3.c'],
                     'correct' => ['a'], 'explain' => 'c_education.learn.q.chamber_basics.q3.explain'],
                ],
            ]],
        ],
        'election_board' => [
            'title' => 'c_education.learn.tracks.election_board.title',
            'unit_ref' => 'K2_CURRICULUM U-ELB',
            'modules' => [[
                'key' => 'running-elections',
                'title' => 'c_education.learn.modules.running_elections.title',
                'summary' => 'c_education.learn.modules.running_elections.summary',
                'surface_id' => 'elections/board',
                'minutes' => 6,
                'questions' => [
                    ['key' => 'q1', 'prompt' => 'c_education.learn.q.running_elections.q1.prompt',
                     'choices' => ['a' => 'c_education.learn.q.running_elections.q1.a', 'b' => 'c_education.learn.q.running_elections.q1.b', 'c' => 'c_education.learn.q.running_elections.q1.c'],
                     'correct' => ['a'], 'explain' => 'c_education.learn.q.running_elections.q1.explain'],
                    ['key' => 'q2', 'prompt' => 'c_education.learn.q.running_elections.q2.prompt',
                     'choices' => ['a' => 'c_education.learn.q.running_elections.q2.a', 'b' => 'c_education.learn.q.running_elections.q2.b', 'c' => 'c_education.learn.q.running_elections.q2.c'],
                     'correct' => ['b'], 'explain' => 'c_education.learn.q.running_elections.q2.explain'],
                    ['key' => 'q3', 'prompt' => 'c_education.learn.q.running_elections.q3.prompt',
                     'choices' => ['a' => 'c_education.learn.q.running_elections.q3.a', 'b' => 'c_education.learn.q.running_elections.q3.b', 'c' => 'c_education.learn.q.running_elections.q3.c'],
                     'correct' => ['b'], 'explain' => 'c_education.learn.q.running_elections.q3.explain'],
                ],
            ]],
        ],
        'executive' => [
            'title' => 'c_education.learn.tracks.executive.title',
            'unit_ref' => 'K2_CURRICULUM U-EXE',
            'modules' => [[
                'key' => 'executive-basics',
                'title' => 'c_education.learn.modules.executive_basics.title',
                'summary' => 'c_education.learn.modules.executive_basics.summary',
                'surface_id' => 'executive/office',
                'minutes' => 5,
                'questions' => [
                    ['key' => 'q1', 'prompt' => 'c_education.learn.q.executive_basics.q1.prompt',
                     'choices' => ['a' => 'c_education.learn.q.executive_basics.q1.a', 'b' => 'c_education.learn.q.executive_basics.q1.b', 'c' => 'c_education.learn.q.executive_basics.q1.c'],
                     'correct' => ['c'], 'explain' => 'c_education.learn.q.executive_basics.q1.explain'],
                    ['key' => 'q2', 'prompt' => 'c_education.learn.q.executive_basics.q2.prompt',
                     'choices' => ['a' => 'c_education.learn.q.executive_basics.q2.a', 'b' => 'c_education.learn.q.executive_basics.q2.b', 'c' => 'c_education.learn.q.executive_basics.q2.c'],
                     'correct' => ['a'], 'explain' => 'c_education.learn.q.executive_basics.q2.explain'],
                    ['key' => 'q3', 'prompt' => 'c_education.learn.q.executive_basics.q3.prompt',
                     'choices' => ['a' => 'c_education.learn.q.executive_basics.q3.a', 'b' => 'c_education.learn.q.executive_basics.q3.b', 'c' => 'c_education.learn.q.executive_basics.q3.c'],
                     'correct' => ['b'], 'explain' => 'c_education.learn.q.executive_basics.q3.explain'],
                ],
            ]],
        ],
        'board_of_governors' => [
            'title' => 'c_education.learn.tracks.board_of_governors.title',
            'unit_ref' => 'K2_CURRICULUM U-BOG',
            'modules' => [[
                'key' => 'department-basics',
                'title' => 'c_education.learn.modules.department_basics.title',
                'summary' => 'c_education.learn.modules.department_basics.summary',
                'surface_id' => 'executive/departments',
                'minutes' => 4,
                'questions' => [
                    ['key' => 'q1', 'prompt' => 'c_education.learn.q.department_basics.q1.prompt',
                     'choices' => ['a' => 'c_education.learn.q.department_basics.q1.a', 'b' => 'c_education.learn.q.department_basics.q1.b', 'c' => 'c_education.learn.q.department_basics.q1.c'],
                     'correct' => ['b'], 'explain' => 'c_education.learn.q.department_basics.q1.explain'],
                    ['key' => 'q2', 'prompt' => 'c_education.learn.q.department_basics.q2.prompt',
                     'choices' => ['a' => 'c_education.learn.q.department_basics.q2.a', 'b' => 'c_education.learn.q.department_basics.q2.b', 'c' => 'c_education.learn.q.department_basics.q2.c'],
                     'correct' => ['a'], 'explain' => 'c_education.learn.q.department_basics.q2.explain'],
                ],
            ]],
        ],
        'judiciary' => [
            'title' => 'c_education.learn.tracks.judiciary.title',
            'unit_ref' => 'K2_CURRICULUM U-JDG',
            'modules' => [[
                'key' => 'bench-basics',
                'title' => 'c_education.learn.modules.bench_basics.title',
                'summary' => 'c_education.learn.modules.bench_basics.summary',
                'surface_id' => 'judiciary/court',
                'minutes' => 6,
                'questions' => [
                    ['key' => 'q1', 'prompt' => 'c_education.learn.q.bench_basics.q1.prompt',
                     'choices' => ['a' => 'c_education.learn.q.bench_basics.q1.a', 'b' => 'c_education.learn.q.bench_basics.q1.b', 'c' => 'c_education.learn.q.bench_basics.q1.c'],
                     'correct' => ['c'], 'explain' => 'c_education.learn.q.bench_basics.q1.explain'],
                    ['key' => 'q2', 'prompt' => 'c_education.learn.q.bench_basics.q2.prompt',
                     'choices' => ['a' => 'c_education.learn.q.bench_basics.q2.a', 'b' => 'c_education.learn.q.bench_basics.q2.b', 'c' => 'c_education.learn.q.bench_basics.q2.c'],
                     'correct' => ['a'], 'explain' => 'c_education.learn.q.bench_basics.q2.explain'],
                    ['key' => 'q3', 'prompt' => 'c_education.learn.q.bench_basics.q3.prompt',
                     'choices' => ['a' => 'c_education.learn.q.bench_basics.q3.a', 'b' => 'c_education.learn.q.bench_basics.q3.b', 'c' => 'c_education.learn.q.bench_basics.q3.c'],
                     'correct' => ['b'], 'explain' => 'c_education.learn.q.bench_basics.q3.explain'],
                ],
            ]],
        ],
        'advocate' => [
            'title' => 'c_education.learn.tracks.advocate.title',
            'unit_ref' => 'K2_CURRICULUM U-ADV',
            'modules' => [[
                'key' => 'advocacy-basics',
                'title' => 'c_education.learn.modules.advocacy_basics.title',
                'summary' => 'c_education.learn.modules.advocacy_basics.summary',
                'surface_id' => 'judiciary/cases',
                'minutes' => 5,
                'questions' => [
                    ['key' => 'q1', 'prompt' => 'c_education.learn.q.advocacy_basics.q1.prompt',
                     'choices' => ['a' => 'c_education.learn.q.advocacy_basics.q1.a', 'b' => 'c_education.learn.q.advocacy_basics.q1.b', 'c' => 'c_education.learn.q.advocacy_basics.q1.c'],
                     'correct' => ['a'], 'explain' => 'c_education.learn.q.advocacy_basics.q1.explain'],
                    ['key' => 'q2', 'prompt' => 'c_education.learn.q.advocacy_basics.q2.prompt',
                     'choices' => ['a' => 'c_education.learn.q.advocacy_basics.q2.a', 'b' => 'c_education.learn.q.advocacy_basics.q2.b', 'c' => 'c_education.learn.q.advocacy_basics.q2.c'],
                     'correct' => ['c'], 'explain' => 'c_education.learn.q.advocacy_basics.q2.explain'],
                ],
            ]],
        ],
    ],
];
