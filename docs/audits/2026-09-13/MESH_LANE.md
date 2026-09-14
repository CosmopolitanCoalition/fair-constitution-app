# Mesh lane (M3, M4, M5, M6)

Date: 2026-09-13. Built in parallel on branch `lane/mesh` (worktree `.wt/mesh`), each item reviewed by three adversarial lenses and repaired, committed per item, merged into main as `d810d9b8`. No script was run for real; every check uses stub harnesses or sqlite fixtures.

## M3 join reruns preserve identity (commit 6fa0335f). Closed.

Ruling `join-rerun-identity` = A. `deploy.sh` and `deploy.ps1` generate an APP_KEY only when none exists or it equals the shipped example; `federation:init` runs without `--rotate` on every ordinary run; `--clone-rekey` / `-CloneRekey` is the only rotate and prints the re-handshake warning. The join step detects a rerun (an existing own key) and calls `federation:resume-join` first: exit 0 resumed, exit 3 no membership (then a keyed `cluster:join`), exit 4 a departed or rejected membership (fail loud with the mint instruction, never re-adopt, never rotate). `FederationResumeJoinCommand` gained the distinct exit codes.

## M4 bootstrap project and failures (commit bdc967de). Closed.

Ruling `bootstrap-project-and-failures` = A. `bootstrap.sh` reads the project `deploy.sh` pinned into `.env` and binds every post-deploy call to it; `federation:init` and `transport:register` are fatal; `directory:publish` is fatal only on a genuine error (its no-authority no-op now exits 0; an air-gapped node with no transports skips it); `mesh:gates` is the completion contract and the done line prints only after it passes. `deploy.ps1` and `bootstrap.ps1` carry the same fixes.

## M5 async join (commit 296ef771). Closed.

Ruling `join-async-dispatch` = A. `cluster:join` dispatches `ClusterJoinJob` by default (`--sync` opt-in) and resumes an existing membership instead of re-admitting; `deploy.sh` and `deploy.ps1` dispatch the join after nginx is up so the UI serves during the transfer. The job contract (long-running queue, no overlap, one try, no timeout) is pinned.

## M6 bounded import finalization (commit 73981899, merged as f94b4545). Closed.

Ruling `import-finalization-bounds` = A. The authority stamp rides each drained page inside the page transaction (a scoped id-range UPDATE); the planet-wide stamp and count are gone from the paginated path; completion is the page ledger plus one index-assisted exists() probe before `seeded_at`, and the membership stays SYNCING with a visible reason if unowned rows remain; the legacy tarball path is marked legacy and stamps by keyset chunks; the progress denominator reads `pg_class.reltuples` on PostgreSQL (count on sqlite); the mirror writes only the host server id. Reviewers caught two real defects in the first build (the legacy keyset started at an empty string, which PostgreSQL rejects for a uuid) and both were repaired. The geodata counts poll ended up on G3's snapshot rather than M6's 8-second cache, which supersedes it; the M6 test now pins that a poll never scans the world table.

## Passed checks on the merged main

| Run | Result |
| --- | --- |
| `bash tests/deploy/test_join_rerun.sh` | ALL DEPLOY JOIN-RERUN CASES PASSED |
| `bash tests/deploy/test_bootstrap_project.sh` | ALL BOOTSTRAP PROJECT + FAILURE CASES PASSED |
| `InstanceIdentityIdempotenceTest`, `ClusterJoinCommandTest` with the other lane and pin sets | OK (155 tests, 5,706 assertions, 1 expected skip) |

Cloud-only checks (not claimed): a real two-node join and a rerun of the same packaged command on a Linux host; the PowerShell harness where `pwsh` is present.
