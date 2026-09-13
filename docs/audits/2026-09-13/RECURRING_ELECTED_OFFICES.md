# Recurring elected executive and judicial cycles — EO-2

Development and targeted internal tests completed on 2026-09-13. This closes recurring office scheduling, exact general-cycle term linkage and outgoing elected office retirement. It does not claim a new count algorithm, full election-board filing journey, or all executive/judicial powers.

**Root integration completed later the same day:** combined 117 PHP tests / 1,731 assertions passed; the additive migration was applied locally, both unique indexes verified valid, and Horizon refreshed. See [integration receipt](INTEGRATION_AND_MAP_CLEANUP.md). Subtask-only rollout limitations below describe the earlier handoff, not the current local migration state.

## Completed behavior

- `elections.general_cycle_election_id` explicitly links a recurring executive or judicial contest to the general election it accompanies. `prior_election_id` retains its original successor-chain meaning. Existing/conversion elections keep a null anchor; no world rows are backfilled.
- Opening a general successor creates approval-open companions for the jurisdiction's elected executive and elected judiciary whose `source_legislature_id` matches. Discovery is jurisdiction scoped and uses 20-row lazy batches. Retrying successor creation reuses its existing general election and companions.
- A committee executive reuses the last certified race's published seat count, including unfilled places, rather than the current number of seated officials or an earlier delegated committee's size. An individual executive keeps the existing one-principal RCV and sequential advisor derivation. The judiciary keeps its published race size, with configured `judge_count` as the fallback. Certification no longer shrinks that count to the number of winners.
- The actual F-ELB-001 scheduling delegate calls `armPhaseTimers`, so companion date confirmation is wired there as well as the direct scheduling path. Companions receive the general election's approval, finalist cutoff and ranked dates. Their published seat/finalist counts remain frozen. If a companion has already crossed cutoff, a conflicting date change rejects transactionally.
- The actual CLK-01 job serializes scheduling on the legislature and records the confirming timer even when adopting an existing successor. A retry reuses that timer's election without new schedules, phase timers or cycles, including delivery after the original cycle has finished. The direct lifecycle path has the same timer identity check.
- Recurring certification validates the exact current office, jurisdiction and creating legislature. It requires the linked general election to be certified/final and uses its immutable original legislature-seat terms. It refuses companion-first certification, wrong/unknown anchors and a historical cycle that no longer matches the current chamber. It does not recompute a certified expiry from today's settings.
- Corrected-count replacements may have later term starts while retaining the original expiry. The anchor uses the earliest source-general term and one consistent expiry, so a valid changed-winner correction does not prevent its companions from seating. The new test invokes the actual EO-8 reconciliation service before both companions certify.
- General turnover retires elected executive principals/advisors/successors and elected judges through the exact term-to-seat/holder link. Office certification also retires prior elected rows still linked to that office's active/completed terms. Their term end dates remain unchanged. Civil/appointed and foreign office histories are preserved.
- The original conversion paths keep their inherited remaining legislative term and their delegated/appointed predecessor transitions. `converted_at` now remains the original conversion date on later cycles.
- The private fixture exposed a missing executive seat identity: `ExecutiveMember` is nonincrementing and has no UUID trait, while PostgreSQL provides a database UUID default. The certification writer now supplies its member UUID explicitly before linking the new term, ensuring the application holds the actual seat ID on both databases. No new term writer was introduced.

## Executed internal checks

| Check | Result |
| --- | --- |
| New private recurring-office journeys | **8 tests / 110 assertions passed** |
| New journeys + existing legislative rollover + corrected-count recertification + CGC governor workflow + protected term/clock tests | **76 tests / 1,160 assertions passed**, after final changes/formatting |
| Syntax | Changed scheduler and certification services passed PHP lint; the explicitly named job, migration and new test passed targeted Pint |
| Diff | Owned paths passed `git diff --check` |

Command run inside `fc_app` with `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`:

```text
php vendor/bin/phpunit tests/Unit/RecurringElectedOfficeCycleTest.php tests/Unit/LegislativeRolloverWorkflowTest.php tests/Unit/CorrectedElectionRecertificationTest.php tests/Unit/CgcGovernorWorkflowTest.php tests/Constitutional/TermLockstepTest.php tests/Constitutional/ElectionClockTest.php --colors=never
```

The new test explicitly establishes and asserts its named `recurring_offices_fixture` SQLite memory connection. It runs the real lifecycle, constitutional engine/F-ELB-001 queued scheduling, certification services, clock creation/cancellation, advisor counting and corrected-winner reconciliation. It supplies sealed upstream results and anonymous ranked inputs, doubles audit/settings/global-role/ballot-retrieval and referendum dependencies, and fakes queue transport. No queued fixture work runs against the live world.

The fixtures cover two successor cycles; a six-seat committee with two winners; a seven-seat court with three winners; full configured 48-month recurring windows; one principal/four derived advisors; duplicate successor/job delivery; attempted office certification before its general; old-anchor refusal after later officers are seated; wrong jurisdiction/legislature/anchor; actual changed-winner general correction; transactional frozen-cutoff schedule refusal; legacy executive/judicial conversion; exact outgoing term history and unaffected foreign/civil neighbors. The migration's SQLite path is applied twice and its uniqueness constraint rejects a duplicate companion.

## Migration and rollout

Apply `2026_09_13_110000_link_recurring_office_elections_to_general_cycles.php` before refreshing workers that load the new lifecycle. The migration is additive, outside a transaction, and creates the nullable column only when absent. PostgreSQL adds a `NOT VALID` foreign key: all new non-null writes are enforced without an existing-world validation scan. Two concurrent partial unique indexes contain only anchored elections with the corresponding owner; invalid indexes left by an interrupted concurrent creation are dropped/recreated on retry. This avoids filling new indexes with every legacy null-anchor row. Concurrent index construction still reads the source table and is not claimed to be zero-cost. SQLite has a portable normal-unique-index branch for private fixtures.

The agent did not apply this migration, restart Horizon, run simulation controls, file live elections, backfill/reschedule the world, reset databases or build the frontend. The integrator owns migration application and the normal Horizon refresh. Production files are stable for that integration after review.

## Scope of remaining verification

The executed tests do not exercise PostgreSQL's actual concurrent index/FK installation, competing worker processes/row locks, network queue delivery, or real voter entry/ballot storage through the entire election-board pipeline. They prove the stated private application journeys and protected invariants. All new reads target a selected jurisdiction/office/general election; no planet-wide diagnostic was run. Per-office history scans and selected-cycle term resolution have not received a production-sized PostgreSQL throughput measurement in this pass. These are review scopes, not assumed passes or new feature claims.
