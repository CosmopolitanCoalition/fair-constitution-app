# CGA — Phase B: Elections Engine (detailed plan)

Status: approved direction (operator: "proceed to Phase B", 2026-06-12). Builds on the
completed Phase A substrate (engine/audit, identity/roles, clocks/scheduler, activation,
design system/shell, civic slice).

## Design documents (authoritative detail — read before building any WI)

| Doc | Covers |
|---|---|
| `PHASE_B_DESIGN_counting_engine.md` | THE COUNT (PROTECTED): forensic semantics extracted from the mockup's real 412,383-ballot count — Droop `floor(v/(s+1))+1`, **Weighted Inclusive Gregory** surplus (proven from fixture transfer values), elimination/election ordering, exhausted handling, tie-breaks, BCMath exact-fraction numeric strategy, countback = full re-run at original seat count with the vacating candidacy struck, RCV + sequential-exclusion advisors, test strategy incl. replaying the Queens fixture |
| `PHASE_B_DESIGN_schema_lifecycle.md` | 12 migrations (B-1…B-12: terms, boards, elections evolve, races, candidacies, approvals, ballots/envelopes, tabulations, certifications, vacancies, legislature_members evolve, settings), ESM mappings, the two-phase lifecycle + clock wiring (CLK-01/02/04/10/18/21), bootstrap board path, ballot-crypto Phase B posture (sodium at-rest + salted hash + publication; cryptographer-review list for production), 11 engine handlers, WI-B0…B10 breakdown |
| `PHASE_B_DESIGN_frontend.md` | 8 pages (ElectionDetail, CandidacyRegistration, CandidateProfile, OpenBallot, RankedBallot, Results, BoardConsole, VacancyCountback) with exact props contracts, 8 Electoral components (StvBar/StvRound/BallotReceipt/RankList/ApproveSwitch/CandidateRow/FinalistLine/PhaseBanner), the STV_DATA round-by-round JSON contract + collapse rule, ballot UX integrity (receipt shown once, double-vote, receipt-check), FE-B0…B8 breakdown w/ fixture-first harness |

## Two constitutional rulings embedded (flag to operator in the Phase B report)
1. **Chambers without district maps**: at-large single race is constitutional when seats ≤ 9
   (Art. II §8 requires subdivision only above 9). **Montegiardino (10 seats, leaf, no
   children)** exceeds the cap with nothing to subdivide over — Phase B posture: leaf
   chambers clamp to 9 (re-plan command provided); the true fix is the
   shortest-split-line drawing tool (master-plan backlog #1). San Marino (32 seats over
   9 castelli) gets an auto-generated initial district map at activation (backlog #3
   partially pulled forward).
2. **Earth-scale type_b race structure** deferred to an operator ruling (1,160 type_b
   seats = one per constituent; electing them is a Phase C/bicameral-operations concern).

## Work items (full detail in the schema/lifecycle + frontend designs)

| WI | Item | Size | Depends on |
|---|---|---|---|
| WI-B0 | 12 migrations + 18 models | M | — |
| WI-B1 | VoteCountingService (PROTECTED, BCMath, DB-free) + StvDroopGregory/Countback tests | L | — (parallel w/ B0) |
| FE-B0/B1 | Surfaces/nav/CSS appends + 8 Electoral components against fixtures (dev harness page) | L | — (parallel) |
| WI-B2 | BallotBox crypto unit + BallotSecrecyTest | M | B0 |
| WI-B3 | ElectionLifecycleService + clock jobs + ApprovalService + DistrictingService extraction | L | B0 |
| WI-B4 | 11 engine handlers + RoleService R-06..R-09 + validator rules | M | B0 |
| WI-B5 | TabulateElectionJob (long-running) → certification → seating pipeline + TermLockstepTest | L | B1,B2,B3,B4 |
| WI-B6 | VacancyService: countback → certify-or-special (CLK-04) | M | B5 |
| WI-B7 | Bootstrap board + activation step 3.5 + initial-map generation + Montegiardino re-plan | M | B3,B4 |
| WI-B8 | 8 pages + controllers + routes + surfaces (FE-B2..B8) | XL | B4 (contracts); polish after B5 |
| WI-B9 | elections:demo command (everything through the real engine) | M | B5,B7 |
| WI-B10 | Test gate: un-skip Stv/Countback/TermLockstep + new secrecy/cutoff/clock tests | M | woven |

Critical path: B0 → B3/B4 → B5 → B9. B1 + FE-B0/B1 start in parallel on day one.

## Exit criterion (the demo IS the verification)
`php artisan elections:demo smr-2-montegiardino --voters=40 --candidates=12 --instant`
walks the ENTIRE lifecycle through the real engine: activation → bootstrap board →
scheduled election → seeded verified voters (real residency simulator) → candidacies →
approvals → finalist cutoff → ranked ballots with receipts (a few write-ins) → STV
tabulation → certification → members seated → legislature `active` → CLK-01/CLK-10 armed
→ next approval phase open → `audit:verify` green. Same on San Marino (districted
bicameral). Browser walkthrough of all 8 pages at each phase via dev impersonation.
