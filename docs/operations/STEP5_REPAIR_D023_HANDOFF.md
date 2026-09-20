# D023 — Final four population/allocation conflicts

The operator asked to resolve the four remaining reviews, then clarified:
"If there are too few representatives to go around then that means the chamber
cant size to that number." Resignations were not authorized or implemented.
Preserve existing officeholders, occupied terms, certified results and payments.

## Corrections

- Nomination and supplementary-contest ordering now reserve constrained real
  populations before broader contests at the same administrative level. Jelka's
  last unused Erec resident was previously consumed by an earlier broad vacancy.
  This ordering is shared with normal fresh candidate generation.
- Uncounted panels account for residents already committed elsewhere in the
  election. Capacity uses **real population**, not the configured sample size;
  absent/unknown population never means zero. Unfilled zero-capacity contests
  retire through the existing population-ceiling path. Existing candidates in
  other races and their counts remain unchanged.
- A certified chamber can retire only demonstrably unfillable, never-occupied
  panel capacity. Its current panel/group/chamber size and derived quorum change;
  its historical race sizes, certificates, counts, members and terms do not.
  Additional fillable vacancies still go through real special elections. The
  atomic recovery rolls sizing changes back if any subsequent work fails.
- Exact `--retry-distinct-capacity=RUN --scope=UUID` continuation accepts only
  source-bound, blocked tiny-panel election failures, with apply/recovery already
  authorized. It preserves prior failed receipts and requeues existing items.
  A completed/drained run returns to halted/repairing only if a matching item was
  found; worklist completion is retained. No new run, inventory or repeated Apply.

## Validation

**53 tests / 757 assertions passed**, guarded disposable PostgreSQL plus
constitutional compatibility checks. Real counting, certification and special
elections cover constrained-pool ordering, empty uncounted retirement, certified
capacity correction, unchanged original members/terms/certificates/counts, exact
terminal CLI continuation, repeat delivery and injected post-correction rollback.
The constitutional fingerprint remains unchanged. No tests wrote to the demo.

## Deployment

The operator is having Claude lower disk performance and resize to a D8. Finish
that external operation and verify capacity/services before deploying. Do not
override or interfere with Claude's sizing, storage or monitoring process.

PHP only, under the existing exclusive deployment lock. Same repair run:
`01a0bed7-d6c6-7399-b288-44053ffe00e1`. It is currently fully settled, with
923,091 DONE and four review after D022. Refresh drained Horizon only; no schema,
assets or other service refresh is required by this code change.

Retry exact scopes:

| Jurisdiction | Scope |
|---|---|
| Not Under Any Cd Block | `4476019e-aed2-4cbd-893d-e402602dd31b` |
| Jelka | `ecc8386d-f62b-4d46-8b85-706b7ef0f4b1` |
| Malinovo | `f11286b1-a2c2-4312-ae30-89659a3a3600` |
| North & Middle Andaman | `09e9dcf7-01bf-499d-88bb-9de05f907371` |

Resume this same run. Validate outcomes, retained old officeholders/terms/count
hashes, real new certifications, court appointments, boards, once-only payments,
both bounded chain tails, readiness and HTTP health. Prior records are saved in
`/home/cosmo/wos-step5-operations/evidence/D023/preserved-before.json`.
Do not claim live completion until these checks pass. Do not finish setup or
shut down the host; the operator will finish Simulate and prepare presentation
materials. Remove the repair heartbeat after final acceptance.

## Deployed and accepted — September 20, 21:28 UTC

Deployed `f4b7236e` (includes repair `fbb69211`) on the resized D8. All four
existing items completed; fresh `WorldReadiness` reports **923,095/923,095 DONE,
zero reviews, complete=true**. All four fresh institutional inspections have no
actions, blockers or gaps. No new run, inventory, Apply or payment phase replay.

| Scope | Current chamber target | Distinct seated representatives |
|---|---:|---:|
| Not Under Any Cd Block | 16 | 16 |
| Jelka | 30 | 30 |
| Malinovo | 21 | 21 |
| North & Middle Andaman | 84 | 86 |

The last chamber retains existing Type A district-rounding surplus; Type B
capacity and coverage pass. All 41 captured prior members, 51 terms and two
certificates are byte-for-byte unchanged; all 30 captured count hashes remain
unchanged. Both bounded chain tails pass 129 hashes/128 links each. These are
bounded acceptance checks, not a replay of the whole world's transactions.

Resize checks found two concrete problems: cached Horizon configuration still
requested 73 lanes, causing repeated OOM exits, and rederive while PostgreSQL
was stopped selected the geodata profile. Rebuilt the cache, then rederived
with PostgreSQL online and applied the mapping profile. Final actual/cache
lane count is **7**, Horizon cap **5,499 MiB**, PostgreSQL cap **3,325 MiB**,
shared buffers **369 MiB**, queue Redis cap **767 MiB**, data limit **306 MiB**.
Horizon stays running without restarts; public setup returns HTTP 200; `/data`
has approximately 88 GiB free. The four original local config files and all
non-sizing environment values were preserved. Linux installer contract tests
pass, including retaining the existing profile while the database is offline.

The full original stipend count exceeded the unchanged three-second diagnostic
cap after resize, so it was not forced through. These four scopes have no
original stipend work item; a separate three-item sample remains DONE with
pre-repair finish times. The last previously verified full count is 903,500.

Evidence: `evidence/D023/preservation-acceptance.json`, `final-acceptance.json`,
`deployment.jsonl`, and `evidence/D023-RESIZE/commands.jsonl` under the remote
operations directory. STATE.json records completion. The repair heartbeat was
removed after acceptance. The operator may finish Simulate; the developer has
not advanced setup or shut down the host.
