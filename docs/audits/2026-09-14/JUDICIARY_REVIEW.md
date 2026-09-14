# Judiciary review lane (S1 · judiciary seating, S1 · cases, S1 · challenges)

Date: 2026-09-14. Run on branch `review/jud` (worktree `.wt/jud`, base `aee71e9a`), each row built by one implementer, refuted by two reviewers (pass criterion; isolation), repaired where a finding held, committed per row, merged into main as `0eaf6078`. Every PostgreSQL journey ran in its own nonce-guarded disposable database (`cga_<domain>_YYYYMMDD_<16hex>`, refusal when `public.organizations` exists, statement and lock timeouts set, dropped in a `finally` block); the world database was never a fixture (`live_world_used:false` on every run). No protected file was edited. No product defect was found; the one inline fix was in the journey file itself.

## S1 · judiciary seating (commit ffc90194). Passed.

`tests/concurrency/judiciary_seating_journey.php --run`: exit 0, failures 0, deterministic on two runs.

Journey on the real engine and services (ConstitutionalEngine, ChamberVoteService, ChamberActService, JudiciaryActService, JudiciaryFormationService, JudicialNominationService, JudicialSeatService, EnactmentService with its advisory lock, CivilAppointmentService, ClockService, MultiJurisdictionVoteService, ElectionLifecycleService; only AuditService, SettingsResolver, AchievementService, TrainingGateService and the role gate doubled):

1. F-LEG-017 filed and carried by chamber supermajority; the court is created (charter `Act 2026-01`, LawVersion v1, five seats one per constituent, equal-seat invariant asserted) before any nomination.
2. Per constituent, F-LEG-037 nomination by constituent-chamber majority, then F-LEG-021 consent by the creating chamber; after the fifth seat the court advances to appointed.
3. F-LEG-018 conversion filed by a chamber member, carried by chamber supermajority, then the constituent dual supermajority (four of five consent); the process passes and the real judicial election is scheduled.
4. History preserved: the five appointed seats and terms are identical before and after conversion; two charter laws each keep one v1 version; the court stays appointed until the scheduled election certifies.
5. Roles: the real `RoleService::hasJudicialSeat` returns true for a seated judge before and after conversion, false for a non-judge and for the unseated elected bench.
6. Refusals: a foreign legislator filing F-LEG-018 (`ChamberActor::member`); a second conversion on a court no longer appointed (`JudiciaryActService::proposeConversion`).

Scope notes: the nomination-path refusals and configured terms already pinned by `tests/Unit/JudicialNominationAuthorizationTest.php` were not re-run; the committee nomination path was not re-exercised. Plan flag: the campaign plan named court creation F-LEG-007 (Motion Submission in the registry); the code truth is F-LEG-017 and the journey uses it.

## S1 · cases (commit a37b34be). Passed, one journey-file fix.

`tests/concurrency/case_multi_actor_journey.php --run`: exit 0, failures 0, three cases, 38 audit entries with the chain intact, disposable database dropped.

Six distinct simulated identities hold the fixture offices (complainant, accused, advocate, presiding judge, jurors, witness). Three cases through the real CaseService, PanelService, JuryService, AdvocateService, CaseFilingService, PublicRecordService, AuditService and the protected `ConstitutionalValidator::checkCaseFiling` (read only): a criminal jury case through filing, acceptance, panel seating with a recusal conflict, juror screening, evidence and testimony filings, hearing, deliberation, verdict, sentencing and opinion, with the records preserved and the audit chain verified; a civil serious-panel case (panel verdict arithmetic; the jury refusal on a case without a jury); a dismissal. Refusals held for wrong actor, wrong state, a brief after the verdict and a second criminal filing for the same act (double jeopardy).

Reviewer finding repaired by extension: the hearing-room half of the row was not exercised in the first build. The journey now drives the real RoomFloorService over LiveFloorService against its own case: the presider gate (only the seated presiding judge may preside), a witness raise with real Matrix identity provisioning, presider-only witness recognition, yield, a non-presider floor-control refusal (403) and a closed-case floor refusal (403). The floor is cache-backed live choreography and writes no audit entry, which matches the LiveFloorService contract.

Inline fix: the journey's hand-rolled `users` table lacked `deleted_at`, which `App\Models\User` (SoftDeletes) filters on; one column added in the journey file.

Not established here: real Matrix and LiveKit transport lifetime (joined connections, interruption and rejoin, grant revocation); that scope is register row R2. The closed-case refusals were exercised through the domain services and validator rather than the engine's form dispatch.

## S1 · challenges (commit dd4fc693). Passed.

`tests/concurrency/challenge_direct_remedy.php --run`: failures 0, disposable database dropped. `tests/Unit/ChallengeTrackerControlsTest.php`: OK (9 tests, 72 assertions) in the worktree and again on merged main.

Path 3 on the real EnactmentService: the clock windows arm (CLK-11 30 days, CLK-12 60 days); a premature F-JDG-006 is refused (Art. IV §5); a wrong actor is refused by the seated-judge gate; the direct remedy appends `Act 2026-A` v2 with source `judicial_remedy`, v1 preserved, law status amended, challenge closed; a repeated F-JDG-006 after the remedy is refused. Paths 1 and 2 are driven to their outcomes through the real `ConstitutionalChallengeService::onRemedialEnactment` hook with a real `amendLaw`, and the real JudiciaryOverrideService supermajority override; the repeated-application refusals after a legislative remedy and after an override are pinned in the SQLite extension (`test_resolved_challenge_enables_no_repeated_application`).

Scope note: the F-LEG-003 bill floor vote that triggers the remedial hook is pinned separately (`tests/Constitutional/Art4Section5Test.php`, the SQLite bill-prefill test); the journey closes Path 1 from the hook.

Flag carried, not fixed here: `Art4Section5Test` runs against the default live PostgreSQL name when `LIVE_PG_DATABASE` is set; it is a Feature test that should be pointed at a disposable database in any future run.

## Verification on merged main

| Run | Result |
| --- | --- |
| `tests/Unit/ChallengeTrackerControlsTest.php` on main `0eaf6078` | OK (9 tests, 72 assertions) |
| Three disposable-PostgreSQL journeys | exit 0 each in the worktree at the merged code |
