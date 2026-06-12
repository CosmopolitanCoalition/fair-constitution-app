<?php

/*
|--------------------------------------------------------------------------
| CGA entity state machines — display contracts (FE-C0)
|--------------------------------------------------------------------------
|
| Flat, ordered state lists for Ui/StateStrip and Ui/LifecycleTracker —
| "machine definitions are PHP-owned and arrive in the entity payload; the
| UI never hardcodes state lists" (DESIGN_frontend_port.md §D4). Phase A/B
| machines predate this file and stay where they shipped:
|
|   residency claim   Civic\HomeController::claimMachine()
|   election (ESM-03) Elections\ElectionController::machine()
|   candidacy(ESM-06) Elections\CandidacyController::machine()
|   ballot   (ESM-05) Elections\BallotController::BALLOT_MACHINE
|   vacancy  (ESM-13) Elections\VacancyController::VACANCY_MACHINE
|
| Phase C machines are shared by several controllers each (bills appear on
| Bills/BillDetail/CommitteeDetail/SessionConsole; petitions on two civic
| surfaces), so they live here: `config('cga.state_machines.bill')` etc.
|
| Ordering convention (matches VACANCY_MACHINE): happy path first, then
| branch/terminal states in canonical order. Values are the DB status
| strings (CHECK constraints in PHASE_C_DESIGN_votes_laws.md §A /
| PHASE_C_DESIGN_chamber_ops.md) — controllers may slice the happy path
| for a LifecycleTracker and splice the live branch, per the Phase B
| CandidacyController::machineFor() idiom.
*/

return [

    // ESM-07 — bills (PHASE_C_DESIGN_votes_laws.md C-6).
    'bill' => [
        'introduced', 'referred', 'in_committee', 'reported', 'on_floor',
        'passed', 'enacted',
        // branches
        'tabled', 'failed', 'withdrawn',
    ],

    // ESM-08 — motions (PHASE_C_DESIGN_chamber_ops.md C.1).
    'motion' => [
        'submitted', 'recognized', 'debated', 'voted', 'adopted',
        // branches
        'failed', 'withdrawn',
    ],

    // ESM-09 — committee seats (PHASE_C_DESIGN_chamber_ops.md C.1);
    // tie_broken is the F-SPK-005 normalized-quota branch (ledger #q2).
    'committee_seat' => [
        'allocated', 'assigned', 'tie_broken', 'seated', 'vacated',
    ],

    // ESM-10 — petitions (PHASE_C_DESIGN_votes_laws.md C-8). Two
    // kill-paths: failed audit (→ invalidated) and unconstitutional
    // finding (constitutional_review → invalidated, Phase E).
    'petition' => [
        'created', 'gathering', 'threshold_reached', 'signature_audit',
        'constitutional_review', 'validated', 'on_ballot', 'adopted',
        // branches
        'rejected', 'invalidated',
    ],

    // ESM-11 — referendum questions (PHASE_C_DESIGN_votes_laws.md C-8).
    'referendum_question' => [
        'queued', 'scheduled', 'voted', 'passed',
        // branches
        'failed', 'invalidated',
    ],

    // ESM-12 — emergency powers (PHASE_C_DESIGN_votes_laws.md C-9). The
    // row exists only after the supermajority adoption ('invoked' lives in
    // the vote, not the row); auto-expiry is the default exit — nothing
    // rolls over silently (Art. II §7 · CLK-03).
    'emergency_powers' => [
        'active', 'renewed', 'expired',
        // branches (judicial review, Phase E remedies)
        'under_review', 'narrowed', 'struck',
    ],

];
