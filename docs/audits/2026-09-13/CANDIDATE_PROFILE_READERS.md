# B6 completed: bounded candidacy and endorsement readers on one public profile

13 September 2026. Development and required internal testing are complete. B6 has moved from the active punch list to Completed. EO-5 individual endorsement action controls remain a separate build.

There is **one public profile per person** at `/people?who=...`. Candidate links still open that person's Candidacy tab; public activity, publications and current/past offices remain together. This repair extends the earlier [public-history work](PERSON_PUBLIC_HISTORY.md).

## Delivered

- The standing card reads the selected candidate, snapshot size, leader and finalist threshold from `approval_standings`. It no longer materializes the entire race. Snapshot selection is shared with `ApprovalService`: the latest frozen date takes precedence over a newer unfrozen date. No secret approval rows are read.
- Organization endorsements, public individual endorsers, the selected endorser's other public endorsements, owner-only requests and public endorsements given each have independent 20-row cursor pages. Each reader selects 21 rows at most, using the extra row to detect the next page. Stable ID ordering is used; these lists do not claim chronological order.
- The endorsement network loads only when a visitor selects a public endorser. Two exact identity lookups recheck the selected public edge; the subsequent page is limited to that person and election and excludes the focused candidacy. Other endorsers and election-wide candidate IDs are never eagerly expanded.
- Private individual endorsements contribute anonymous totals only. Their IDs and names are not returned. Names use the chosen civic name, an explicitly public social name/handle, or the existing anonymous fallback, never a legal name or private social field.
- Current and legacy `user/users` and `organization/organizations` spellings are recognized and deduplicated. A canonical row supersedes its legacy counterpart even when private, inactive or withdrawn, preventing resurrection of a stale public endorsement. Existing rows are not globally rewritten.
- The existing organization-grant handler reuses an endorsement's identity. Shared `Endorsement::recordFor` serializes writes through the selected endorser's row, covering both existing and missing endorsements. New simulator rows use canonical spellings. Future EO-5 individual controls must use this primitive after their authority checks; B6 does not itself introduce individual filing powers or controls.
- Partial visits do not rebuild standings, other directories or public histories. Paging and connection expansion preserve profile/candidacy context and unsaved statement edits. Loading, failure/retry feedback, previous/next/first-page controls and invalid-bookmark recovery are present.

## Executed internal checks

| Check | Result |
|---|---|
| PHP readers, controller partial responses, naming, existing profile/history, lesson-profile and approval regressions | 39 targeted tests passed across this pass. Final B6 class: 11 tests / 221 assertions. Fixtures traverse 43–45 entries in both directions and cover frozen/latest snapshots, threshold parity, private/withdrawn duplicate precedence, wrong-person/candidacy/endorser tokens, canonical writes, rollback and actual grant authority. |
| Compiled Vue profile and endorsement components | 4 tests passed: existing public history, independent pagination, candidacy loading/drafts, and on-demand connections with loading/errors and same-profile target links. |
| PostgreSQL concurrent writers | 6 cases passed on distinct connections with observed lock waits: missing, legacy and rolled-back endorsements, for both individuals and organizations. Exactly one logical row survives; the subsequent privacy update wins. |
| Populated PostgreSQL readers | 3,006 synthetic endorsements; 14 actual query plans inspected. First/next pages, anonymous counts and selected connections passed. No endorsement relation scans; received/given/web indexes and exact identity keys were used. The disposable database was identity-checked and removed after testing. |
| Existing-world browser/read check | Ruth Ellery's two candidacies remained on the same profile. The elected candidacy showed frozen rank 6 of 7, matching its stored snapshot. Record navigation preserved the person and showed the existing publications. These selected candidacies had no endorsements; populated endorsement behavior is established by the isolated fixtures, not this browser sample. |

Final populated PostgreSQL plan measurements were 0.043–0.066 ms for exact public-edge identity lookups, 0.060–0.341 ms for the paginated collections, and 2.114–2.125 ms for the selected candidacy's anonymous totals. These are local synthetic measurements, not a planet-scale throughput promise. Live selected-candidate standing queries used the frozen-date, candidate/date and rank indexes; the tested populated snapshot had seven rows.

Reproducible probes:

```text
php artisan test tests/Unit/CandidacyEndorsementDirectoryTest.php tests/Unit/PersonProfileHistoryTest.php tests/Unit/CandidacyPublicNameTest.php tests/Unit/LearnProfileReadFixtureTest.php tests/Unit/ApprovalDirectoryTest.php
node --experimental-vm-modules --test tests/js/personProfileHistory.test.mjs
php tests/concurrency/endorsements.php --run
```

The PostgreSQL probe connects only to the maintenance database and a nonce-guarded disposable test database. It does not boot application providers, read/copy the existing world, or dispatch real jobs.

## Deployment

Apply additive migration `2026_09_13_140000_candidacy_profile_directory_indexes.php` on pulling hosts. Its six concurrent indexes are applied locally and verified valid. Horizon was restarted and confirmed running locally. Pulling hosts must refresh their existing workers after the shared model/handler/simulator changes. No route change or production frontend build is needed on this development host. No live endorsement, election or simulation action was submitted.
