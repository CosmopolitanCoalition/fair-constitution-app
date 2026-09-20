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
