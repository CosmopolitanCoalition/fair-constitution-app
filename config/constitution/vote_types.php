<?php

/*
|--------------------------------------------------------------------------
| The 33-row Special Vote Types registry — AS CODE
|--------------------------------------------------------------------------
|
| Source of truth: CGA_Constitutional_Roles_Forms_Chart.xlsx sheet
| "7. Special Vote Types" (transcribed in docs/plans/institutions/
| EXPLORE_registry.md §E), shaped per PHASE_C_DESIGN_votes_laws §B.
|
| This is a CONSTITUTIONAL ARTIFACT versioned with code, exactly like
| FormRegistry — a plain PHP array, never a DB table. chamber_votes
| .vote_type stores these keys with no DB CHECK (documented exception:
| the registry is a code artifact, like audit_log's form refs).
|
| Counting note: the sheet's 33 rows fold as 3 simple-majority + 19
| supermajority + 3 population + 1 bicameral-structural + 6 RCV/STV
| (the sheet's "Speaker election — supermajority RCV" row is the same
| mechanism as the supermajority "Elect Speaker" row: one key,
| `speaker_elect`) + `procedural_motion` (the owner ruling that unstated
| votes are an ordinary majority of all serving, MANIFEST §8).
|
| Shape per key:
|   label        registry row text
|   category     simple_majority | supermajority | population | bicameral | rcv_stv
|   engine       chamber | population_ballot | stv_count | multi_jurisdiction | assignment
|   basis        majority | supermajority | population_majority |
|                population_supermajority | rcv_single | rcv_supermajority |
|                stv | ranked_preference | unanimity_constituents
|   denominator  serving | committee_serving | civic_population |
|                constituent_jurisdictions | board
|   bicameral    per_kind | n/a      (q-ledger #q7: per_kind at committee AND floor)
|   dual         null | constituent_supermajority   (second meter — multi_jurisdiction_votes)
|   phase        A|B|C|D|E|F — when the type is first WIRED to live machinery
|   citation     constitutional basis
|
| Thresholds for chamber-engine keys resolve ONLY through
| ConstitutionalValidator::quorum()/supermajority() at vote open
| (ChamberVoteService) — never recomputed, never reimplemented.
| Pinned by tests/Constitutional/VoteTypeRegistryTest (completeness +
| shape) and PegQuorumTest/BicameralDualAgreementTest (the math).
*/

return [

    // ── Simple Majority (3) ─────────────────────────────────────────────────

    'bill_pass' => [
        'label'       => 'Pass a bill into law',
        'category'    => 'simple_majority',
        'engine'      => 'chamber',
        'basis'       => 'majority',
        'denominator' => 'serving',
        'bicameral'   => 'per_kind',
        'dual'        => null,
        'phase'       => 'C',
        'citation'    => 'Art. II §2',
    ],

    'committee_bill' => [
        'label'       => 'Committee vote on a bill',
        'category'    => 'simple_majority',
        'engine'      => 'chamber',
        'basis'       => 'majority',
        'denominator' => 'committee_serving',
        'bicameral'   => 'per_kind', // q7 applies at committee in bicameral chambers
        'dual'        => null,
        'phase'       => 'C',
        'citation'    => 'Art. II §2 · Art. V §3',
    ],

    'bog_consent' => [
        'label'       => 'Board of Governors consent',
        'category'    => 'simple_majority',
        'engine'      => 'chamber',
        'basis'       => 'majority',
        'denominator' => 'serving',
        'bicameral'   => 'per_kind',
        'dual'        => null,
        'phase'       => 'D',
        'citation'    => 'Art. III §4',
    ],

    // ── Supermajority (19) ──────────────────────────────────────────────────

    'speaker_elect' => [
        'label'       => 'Elect Speaker (supermajority RCV)',
        'category'    => 'supermajority',
        'engine'      => 'chamber',
        'basis'       => 'rcv_supermajority',
        'denominator' => 'serving',
        'bicameral'   => 'n/a', // constitutive election of the whole body — one office
        'dual'        => null,
        'phase'       => 'C', // service wired here; F-LEG-008 forms/UI are sibling scope
        'citation'    => 'Art. II §3',
    ],

    'speaker_replace' => [
        'label'       => 'Replace Speaker (supermajority RCV)',
        'category'    => 'supermajority',
        'engine'      => 'chamber',
        'basis'       => 'rcv_supermajority',
        'denominator' => 'serving',
        'bicameral'   => 'n/a',
        'dual'        => null,
        'phase'       => 'C',
        'citation'    => 'Art. II §3',
    ],

    'committee_create' => [
        'label'       => 'Create committees',
        'category'    => 'supermajority',
        'engine'      => 'chamber',
        'basis'       => 'supermajority',
        'denominator' => 'serving',
        'bicameral'   => 'per_kind',
        'dual'        => null,
        'phase'       => 'C', // F-LEG-009 is sibling scope
        'citation'    => 'Art. II §2',
    ],

    'exec_delegate' => [
        'label'       => 'Delegate executive authority to committee',
        'category'    => 'supermajority',
        'engine'      => 'chamber',
        'basis'       => 'supermajority',
        'denominator' => 'serving',
        'bicameral'   => 'per_kind',
        'dual'        => null,
        'phase'       => 'D',
        'citation'    => 'Art. III §1',
    ],

    'exec_office_create' => [
        'label'       => 'Delegate executive authority to elected office',
        'category'    => 'supermajority',
        'engine'      => 'chamber',
        'basis'       => 'supermajority',
        'denominator' => 'serving',
        'bicameral'   => 'per_kind',
        'dual'        => 'constituent_supermajority',
        'phase'       => 'D',
        'citation'    => 'Art. III §2',
    ],

    'exec_office_alter' => [
        'label'       => 'Alter existing executive office',
        'category'    => 'supermajority',
        'engine'      => 'multi_jurisdiction',
        'basis'       => 'supermajority',
        'denominator' => 'constituent_jurisdictions',
        'bicameral'   => 'n/a',
        'dual'        => null,
        'phase'       => 'D',
        'citation'    => 'Art. III §2',
    ],

    'judiciary_create' => [
        'label'       => 'Create appointed judiciary',
        'category'    => 'supermajority',
        'engine'      => 'chamber',
        'basis'       => 'supermajority',
        'denominator' => 'serving',
        'bicameral'   => 'per_kind',
        'dual'        => null,
        'phase'       => 'E',
        'citation'    => 'Art. IV §1',
    ],

    'judiciary_convert' => [
        'label'       => 'Create elected judiciary (conversion)',
        'category'    => 'supermajority',
        'engine'      => 'chamber',
        'basis'       => 'supermajority',
        'denominator' => 'serving',
        'bicameral'   => 'per_kind',
        'dual'        => 'constituent_supermajority',
        'phase'       => 'E',
        'citation'    => 'Art. IV §1',
    ],

    'referendum_delegate' => [
        'label'       => 'Delegate to referendum',
        'category'    => 'supermajority',
        'engine'      => 'chamber',
        'basis'       => 'supermajority',
        'denominator' => 'serving',
        'bicameral'   => 'per_kind',
        'dual'        => null,
        'phase'       => 'C', // F-LEG-023 handler is Phase C batch 2
        'citation'    => 'Art. II §6',
    ],

    'emergency_invoke' => [
        'label'       => 'Invoke emergency powers',
        'category'    => 'supermajority',
        'engine'      => 'chamber',
        'basis'       => 'supermajority',
        'denominator' => 'serving',
        'bicameral'   => 'per_kind',
        'dual'        => null,
        'phase'       => 'C', // F-LEG-024 handler is Phase C batch 2
        'citation'    => 'Art. II §7',
    ],

    'emergency_renew' => [
        'label'       => 'Renew emergency powers',
        'category'    => 'supermajority',
        'engine'      => 'chamber',
        'basis'       => 'supermajority',
        'denominator' => 'serving',
        'bicameral'   => 'per_kind',
        'dual'        => null,
        'phase'       => 'C',
        'citation'    => 'Art. II §7',
    ],

    'officeholder_remove' => [
        'label'       => 'Remove officeholder (impeach / expel)',
        'category'    => 'supermajority',
        'engine'      => 'chamber',
        'basis'       => 'supermajority',
        'denominator' => 'serving',
        'bicameral'   => 'per_kind',
        'dual'        => null,
        'phase'       => 'C', // F-LEG-022 oversight machinery is sibling scope
        'citation'    => 'Art. II §3',
    ],

    'judiciary_override' => [
        'label'       => 'Override judiciary constitutional finding',
        'category'    => 'supermajority',
        'engine'      => 'chamber',
        'basis'       => 'supermajority',
        'denominator' => 'serving',
        'bicameral'   => 'per_kind',
        'dual'        => null,
        'phase'       => 'E', // within the CLK-11 veto window
        'citation'    => 'Art. IV §5',
    ],

    'cultural_institution' => [
        'label'       => 'Recognize Cultural Institution of State',
        'category'    => 'supermajority',
        'engine'      => 'chamber',
        'basis'       => 'supermajority',
        'denominator' => 'serving',
        'bicameral'   => 'per_kind',
        'dual'        => 'constituent_supermajority',
        'phase'       => 'F',
        'citation'    => 'Art. V §2 · as implemented',
    ],

    'additional_articles' => [
        'label'       => 'Amend additional constitutional articles',
        'category'    => 'supermajority',
        'engine'      => 'multi_jurisdiction',
        'basis'       => 'supermajority',
        'denominator' => 'constituent_jurisdictions', // legislature itself when no constituents
        'bicameral'   => 'n/a',
        'dual'        => null,
        'phase'       => 'F',
        'citation'    => 'Art. VII',
    ],

    'referendum_act_modify' => [
        'label'       => 'Modify referendum-passed act (same term)',
        'category'    => 'supermajority',
        'engine'      => 'chamber',
        'basis'       => 'supermajority',
        'denominator' => 'serving',
        'bicameral'   => 'per_kind',
        'dual'        => null,
        'phase'       => 'C', // F-LEG-034 + CLK-19 shield are Phase C batch 2
        'citation'    => 'Art. II §6',
    ],

    'boundary_change' => [
        'label'       => 'Boundary changes between jurisdictions',
        'category'    => 'supermajority',
        'engine'      => 'population_ballot',
        'basis'       => 'population_supermajority',
        'denominator' => 'civic_population',
        'bicameral'   => 'n/a',
        'dual'        => null,
        'phase'       => 'F',
        'citation'    => 'Art. V §4',
    ],

    'union_form_join' => [
        'label'       => 'Union formation / joining',
        'category'    => 'supermajority',
        'engine'      => 'multi_jurisdiction',
        'basis'       => 'population_supermajority',
        'denominator' => 'civic_population',
        'bicameral'   => 'n/a',
        'dual'        => 'constituent_supermajority',
        'phase'       => 'F',
        'citation'    => 'Art. V §5',
    ],

    'union_exit' => [
        'label'       => 'Union departure',
        'category'    => 'supermajority',
        'engine'      => 'multi_jurisdiction',
        'basis'       => 'population_supermajority',
        'denominator' => 'civic_population',
        'bicameral'   => 'n/a',
        'dual'        => 'constituent_supermajority',
        'phase'       => 'F',
        'citation'    => 'Art. V §5',
    ],

    // ── Population-Level / Referendum (3) ───────────────────────────────────
    // Denominator decision (flagged, q-ledger candidate): civic population
    // = active jurisdiction associations, never WorldPop population.

    'referendum_majority' => [
        'label'       => 'Referendum — simple majority issue',
        'category'    => 'population',
        'engine'      => 'population_ballot',
        'basis'       => 'population_majority',
        'denominator' => 'civic_population',
        'bicameral'   => 'n/a',
        'dual'        => null,
        'phase'       => 'C', // ballot integration is Phase C batch 2
        'citation'    => 'Art. II §6',
    ],

    'referendum_supermajority' => [
        'label'       => 'Referendum — supermajority issue',
        'category'    => 'population',
        'engine'      => 'population_ballot',
        'basis'       => 'population_supermajority',
        'denominator' => 'civic_population',
        'bicameral'   => 'n/a',
        'dual'        => null,
        'phase'       => 'C',
        'citation'    => 'Art. II §6',
    ],

    'petition_initiative' => [
        'label'       => 'Citizen petition initiative',
        'category'    => 'population',
        'engine'      => 'population_ballot',
        'basis'       => 'population_majority', // or supermajority — matches the legislative equivalent (derived from act_type, never an input)
        'denominator' => 'civic_population',
        'bicameral'   => 'n/a',
        'dual'        => null,
        'phase'       => 'C',
        'citation'    => 'Art. II §6',
    ],

    // ── Bicameral Special (1) ───────────────────────────────────────────────
    // Structural modifier row: realized as bicameral=per_kind on every
    // chamber key — both kinds must independently meet their OWN peg
    // quorum and threshold, at committee AND floor (q-ledger #q7).

    'bicameral_dual_agreement' => [
        'label'       => 'Any act in a bicameral legislature (dual agreement)',
        'category'    => 'bicameral',
        'engine'      => 'chamber',
        'basis'       => 'majority',
        'denominator' => 'serving',
        'bicameral'   => 'per_kind',
        'dual'        => null,
        'phase'       => 'C',
        'citation'    => 'Art. V §3',
    ],

    // ── Ranked Choice / STV elections (6) ───────────────────────────────────

    'general_legislative' => [
        'label'       => 'General legislative election (STV/Droop, 5–9 seats)',
        'category'    => 'rcv_stv',
        'engine'      => 'stv_count',
        'basis'       => 'stv',
        'denominator' => 'civic_population',
        'bicameral'   => 'n/a',
        'dual'        => null,
        'phase'       => 'B', // live since Phase B (VoteCountingService)
        'citation'    => 'Art. II §2',
    ],

    'exec_committee_stv' => [
        'label'       => 'Executive committee election (PR-STV, 5+ seats)',
        'category'    => 'rcv_stv',
        'engine'      => 'stv_count',
        'basis'       => 'stv',
        'denominator' => 'civic_population',
        'bicameral'   => 'n/a',
        'dual'        => null,
        'phase'       => 'D',
        'citation'    => 'Art. III §2',
    ],

    'exec_individual_rcv' => [
        'label'       => 'Individual executive election (RCV + top-4 advisors)',
        'category'    => 'rcv_stv',
        'engine'      => 'stv_count',
        'basis'       => 'rcv_single',
        'denominator' => 'civic_population',
        'bicameral'   => 'n/a',
        'dual'        => null,
        'phase'       => 'D',
        'citation'    => 'Art. III §3',
    ],

    'judicial_election' => [
        'label'       => 'Judicial election (STV in groups, min 5 per race)',
        'category'    => 'rcv_stv',
        'engine'      => 'stv_count',
        'basis'       => 'stv',
        'denominator' => 'civic_population',
        'bicameral'   => 'n/a',
        'dual'        => null,
        'phase'       => 'E',
        'citation'    => 'Art. IV §4',
    ],

    'committee_chair' => [
        'label'       => 'Committee chair election (RCV by whole legislature)',
        'category'    => 'rcv_stv',
        'engine'      => 'chamber',
        'basis'       => 'rcv_single',
        'denominator' => 'serving',
        'bicameral'   => 'n/a',
        'dual'        => null,
        'phase'       => 'C', // service wired here; F-LEG-011 forms are sibling scope
        'citation'    => 'Art. II §2 · as implemented',
    ],

    'committee_preference' => [
        'label'       => 'Committee preference assignment (ranked preferences)',
        'category'    => 'rcv_stv',
        'engine'      => 'assignment',
        'basis'       => 'ranked_preference',
        'denominator' => 'serving',
        'bicameral'   => 'n/a',
        'dual'        => null,
        'phase'       => 'C', // F-SPK-005 assignment algorithm is sibling scope
        'citation'    => 'Art. II §2 · as implemented',
    ],

    // ── Phase D addition (PHASE_D_DESIGN_organizations §C.3) ────────────────
    // Board joint chair — RCV by the FULL board (owner-elected, worker-
    // elected, and governor seats all cast, one lane, equal votes); the
    // final-round winner must reach a MAJORITY of ALL seated board seats
    // (rcv_majority — the peg-quorum close gate with the majority
    // threshold). Additive registry change under constitutional review;
    // VoteTypeRegistryTest pins it.

    'board_chair_elect' => [
        'label'       => 'Board joint chair election (RCV by entire board)',
        'category'    => 'rcv_stv',
        'engine'      => 'chamber',
        'basis'       => 'rcv_majority',
        'denominator' => 'board',
        'bicameral'   => 'n/a',
        'dual'        => null,
        'phase'       => 'D',
        'citation'    => 'Art. III §6',
    ],

    // ── The implicit 33rd row ───────────────────────────────────────────────
    // Owner ruling (mockups MANIFEST §8): unstated votes are an ordinary
    // majority of all serving members.

    'procedural_motion' => [
        'label'       => 'Procedural motion (unstated-threshold default)',
        'category'    => 'simple_majority',
        'engine'      => 'chamber',
        'basis'       => 'majority',
        'denominator' => 'serving',
        'bicameral'   => 'per_kind',
        'dual'        => null,
        'phase'       => 'C',
        'citation'    => 'Art. II §2 · as implemented',
    ],

];
