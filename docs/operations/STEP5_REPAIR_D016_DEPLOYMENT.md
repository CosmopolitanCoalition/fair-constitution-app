# D016 direct deployment and repair benchmark

**Storage recovered:** [D019 recovery](STEP5_REPAIR_D019_STORAGE_INCIDENT.md).
The operator/Claude added 64 GiB; PostgreSQL recovered, bounded chain checks
passed and the same repair resumed at 18:27 UTC. Public HTTP 200.

**Current population-ceiling correction:** see [D018](STEP5_REPAIR_D018_HANDOFF.md).
The operator confirmed the existing zero-population rule; no policy decision is pending.

**New review incident (17:00 UTC onward):** see
[D017 vacancy detection and unresolved zero-electorate cases](STEP5_REPAIR_D017_HANDOFF.md).
Later heartbeat checkpoints are in the remote `STATE.json`; earlier zero-review
measurements below are historical results, not a claim about the current run.

**Current checkpoint:** `ff044c79252075f0fa3db4528ea6492859069275` deployed and
resumed at 15:26 UTC on September 20. At 15:32:55 UTC (17:32:55 Warsaw),
261,247 / 923,095 repair scopes DONE, zero reviews, 73 healthy workers. Same
run/version; original payment phase preserved. See final follow-up below.

2026-09-20. The operator authorized this Windows developer to deploy and measure
over `ssh wos-demo` after the remote Codex login failed. No separate task was
messaged. The independent Claude storage/monitoring/shutdown loop is untouched.

## First release: eacd0a8851ae7935fef282caae86758ec58dfc1a

Deployed from `7aa09548`, halted/drained/refreshed/resumed the SAME repair run
`01a0bed7-d6c6-7399-b288-44053ffe00e1`, version 1. Applied only the guarded
publication-index retirement migration. No new inventory, repeated Apply,
payment-phase replay, frontend build, PostgreSQL or Redis restart.

| Direct completion window (UTC) | Repairs | Seconds | Repairs/hour |
|---|---:|---:|---:|
| Before: 14:39:45–14:43:46 | 5,376 | 241.439 | 80,159 |
| After: 14:48:57–14:50:09 | 6,091 | 72.281 | 303,368 |
| After: 14:50:09–14:51:30 | 7,748 | 81.308 | 343,052 |

Combined after: **324,376/hour, 4.05×**. Both sides already used 4GB PostgreSQL
shared buffers; this comparison does not claim the earlier buffer change as a
code benefit. Seventy-three workers at all benchmark endpoints; startup/drain
excluded. Sequential scopes and background workload differ; these are live
windows, not an identical-work replay. Timing counters flush in batches.

Full item averaged 3,290ms before versus 823ms after. Collected audit-lock wait
per completed scope fell from 2,664ms to 150ms, but collection now also covers
dependent actions; invocation counts changed. Do not sum nested timers.
Training repairs now dominate: 4,133ms per training action, 590ms per completed
scope across the mixed workload. Twelve subsequent lock-owner samples found
ten money-lock owners waiting for another advisory lock; code tracing identifies
the late audit flush. This motivates the focused coordination follow-up below.

Live checks: 24 repaired scopes, 50 adopted chair elections, 150 ballots with
individual publication seals matching their exact audit events; all 150 new
publication IDs were UUIDv7. Each bounded chain sample passed 513 hashes and
512 links for audit and money. All 903,500 original stipend items remain DONE.
The original `.env` and four local configuration edits retain their checksums;
PostgreSQL/Redis/app container identities and start times are unchanged.
The public Step 5 URL returned HTTP 200. Zero repair reviews in the sampled
endpoints; 102,956 repairs done at 14:51:30 UTC. These checks do not constitute
whole-world verification or coverage of every election-recovery case.

Local release validation: **70 tests / 4,372 assertions**, guarded disposable
PostgreSQL and DB-free tests. Remote evidence:
`/home/cosmo/wos-step5-operations/evidence/D016-DEVELOPER/`.

## Focused follow-up: training's two append locks

Only a collected repair's minted-training payment tail reserves both existing
transaction-scoped locks. It waits for audit before owning money, then tries
money without blocking. If an older writer owns money, it rolls back only the
reservation savepoint, releasing audit, waits for money without retaining audit,
releases that temporary reservation, and retries. Thus a legacy money-then-audit
writer can complete instead of deadlocking. Earlier domain work and staged
evidence survive these reservation retries; both acquired locks remain owned
through the ordinary atomic action commit.

No asynchronous payments, early durable commits, relaxed thresholds, new workers,
or money/audit schema changes. Ordinary training and treasury-funded inline
payments are unchanged. `repair.training_append_wait` records coordinated
acquisition; full-item timing continues to include the outer commit. An
additional live speedup remains to be measured after the follow-up deploys.

Follow-up release validation: **94 tests / 6,038 assertions passed**, including
the real payment path in multiple PostgreSQL processes, both conflicting lock
orders, concurrent repair groups, savepoint timeout/retry, complete rollback,
once-only handler retakes, exact achievement seals, and the earlier repair suite.
Deploy PHP only: halt/drain, pull, refresh Horizon, resume the same run. No
migration, scheduler refresh, frontend build or PostgreSQL/Redis restart required.

The coordination release `801aa06194b8d1029db608d169f82f00c534e21b` was deployed
at 15:13 UTC. A further disposable concurrency regression then reproduced an
edge case in collected training awards: different modules for one person can
both stage an award before either commits, so the later immutable duplicate
INSERT is ignored after both actions have already paid. The developer halted
repairs at 15:16:57 UTC; 227,658 done, zero reviews and no active claims after
drain. This is a reproduced fixture defect, not evidence of a live duplicate.

The correction retains each queued stipend's earner and, under the coordinated
audit/money ownership, checks the existing once-only achievement in bounded
500-person batches. An award committed by another action removes that provisional
payment before minting; totals are recalculated from the remaining payments.
The current action's staged award remains payable. No payment is deleted or
rewritten, and the training completion still succeeds. The real concurrent
different-module regression requires exactly one payment and achievement, two
completed module records, valid seals, and intact audit/money chains.

Correction validation: **95 tests / 6,069 assertions passed**. A bounded live
sample of 24 recent training award recipients found exactly one recent repair
stipend credit for each wallet, zero sampled duplicates. This does not establish
that every earlier payment is duplicate-free. Evidence is in `D016-LOCKS` beside
the deployment logs; no historical balances or postings were changed.

## Final follow-up deployment and measurements

`ff044c79` deployed while the same run was safely halted; only Horizon refreshed.
No migrations, database restart, configuration changes or payment replay.
The site returned HTTP 200 after resumption. New bounded checks passed for
24 scopes, 48 chair elections and 144 correctly sealed ballot publications;
513 hashes/512 links passed in each audit and money tail. A new 21-wallet sample
contained exactly one recent training payment per wallet, no sampled duplicates.

The current-setting baseline on `eacd0a88` was 328,087 repairs/hour over
15:04:43–15:10:27 UTC. Final-release windows were 302,541/hour over
15:29:13–15:31:05 and approximately 315,500/hour over 15:31:05–15:32:55.
This demonstrates **no additional overall speedup** from lock coordination;
training wait fell but audit waiting rose. Retain the correctness fence and
do not attribute the first release's fourfold improvement to these follow-ups.
Sequential scopes/background work differ, so this also is not an isolated
replay proving a throughput regression. No tuning or further speculative
lock rewrite was performed.

Final evidence: `/home/cosmo/wos-step5-operations/evidence/D016-AWARD/`, including
`benchmark.json`, fresh outcomes, seals, hashes, wallet samples, config hashes
and unchanged-service checks. At the measured pace the remaining work is about
two hours; larger repair cases can change that and world completion is not yet
established.

Heartbeat `check-demo-repair-progress` checks this same run every 10 minutes.
On September 20 the operator explicitly answered **"approved"** to unattended
development, testing, pushing and deployment of necessary Step 5 repair fixes.
The automation was successfully updated to carry that authority. This resolves
the earlier automatic-approval rejection; approval is no longer pending.

Use bounded diagnostics and evidence-based changes, guarded disposable test
databases, main-branch commits, the exclusive remote developer deployment lock,
and the established halt/drain/refresh/resume procedure. Preserve this existing
world and run, configuration, certifications, audit history and once-only
payments. Do not repeat inventory/Apply, alter policy or worker counts, weaken
durability, reset the world, or interfere with the independent Claude loop.
Report meaningful changes and completed results; after repair completion perform
bounded acceptance checks, report remaining blockers and remove the heartbeat.
Advancing setup or shutting down the host requires separate authorization.

At 15:50:42 UTC the run remained healthy: 355,517 of 923,095 repairs complete,
567,507 pending, 71 running, zero review, and 73 fresh workers. Evidence:
`evidence/D016-AWARD/snapshot-approved-check.json`. Completion is not yet established.

## Remote Codex login diagnosis

SSH and the game are healthy. The remote Codex app-server log reports repeated
HTTP 401 `token_revoked` responses when fetching models. `codex login status`
reports a cached ChatGPT login, which does not establish that the token remains
valid. The binary resolves correctly in the remote user's login shell.

Fresh interactive sign-in is required to repair that Codex connection; no
credentials were printed, copied, revoked or replaced by this developer. From
an operator terminal, `ssh wos-demo`, then `~/.local/bin/codex login --device-auth`
and sign in to the intended account using the newly issued link/code. Reconnect
the desktop SSH connection afterward. Desktop/phone pairing is separate: use
the same account/workspace on both, then a fresh connection QR from desktop
Settings > Connections. CLI device auth does not itself pair a phone.

Official references: https://learn.chatgpt.com/docs/auth and
https://learn.chatgpt.com/docs/remote-connections.
