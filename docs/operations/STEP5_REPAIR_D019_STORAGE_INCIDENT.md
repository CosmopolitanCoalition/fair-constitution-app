# D019: full data volume interrupts the existing repair

**Resolved:** the operator had Claude expand storage by 64 GiB. PostgreSQL
completed recovery naturally; at 18:27 UTC, 60.4 GiB was available. Under the
exclusive lock, bounded audit and ledger samples each passed 129 hashes and
128 links, and all 903,500 original stipend items remained DONE. Configuration
hashes and the deployed runtime revision `4e0dc44a` are unchanged. Restarted
only Horizon and scheduler, then ran the normal pump for the SAME repair.
No new inventory, Apply, simulation reset or payment-phase replay. Public HTTP
200; repair work is progressing again.

At 18:28:08 UTC: 859,797/923,095 DONE, 63,167 pending, 48 running, 83 review.
Forty-nine fresh workers were still warming toward the unchanged 73-lane pool.
A bounded sample of all 83 reviews showed election/court/governance failures,
with no database-outage exception among their recorded failed actions. These
reviews still require targeted fixes; recovery is not whole-world acceptance.
Remote evidence includes before-restore-acceptance.json, restore-commands.jsonl
and reviews-restored.json. By 18:29:15 UTC: 862,218 DONE, 86 review,
60,721 pending, 70 running, 71 fresh workers. The pool continues its normal
startup toward 73. The following incident record is historical.

2026-09-20, approximately 18:15 UTC. No application release in this heartbeat.
The 18:14 repair check found PostgreSQL rejecting connections during recovery.
The public Step 5 URL returns HTTP 500.

Confirmed PostgreSQL log: at 18:14:59 UTC, PANIC writing `pg_wal/xlogtemp`:
`No space left on device`. `/data` has only about 2.6 MiB available (100% used).
The device size is 755,914,244,096 bytes. The host still has about 97 GiB available
RAM; the container reported OOM=false. WAL redo reaches its end, but recovery
cannot write its checkpoint for the same space failure. Subsequent PostgreSQL
container restarts are automatic, not a developer operation.

Under the exclusive developer-deployment lock, temporarily stopped `fc_horizon`
and `fc_scheduler` with a 15-second bound to reduce the connection retry storm.
The database could not accept a `sim:halt` request; no worker code was changed.
No files, volumes, world records or WAL were removed. No database settings,
worker concurrency, storage provisioning, or independent Claude loop changed.

The independent storage monitor/operator must restore sufficient free space.
Keep both stopped application services stopped until PostgreSQL is ready and
space is available. Then under the deployment lock inspect the SAME repair run
`01a0bed7-d6c6-7399-b288-44053ffe00e1`, perform bounded audit/ledger checks, restart
only those two application services and allow ordinary fenced claim recovery.
Inspect any outage-related reviews before targeted recovery. No new inventory,
Apply, world restart, payment-phase replay or setup advancement.

Last verified database progress is still the D018 checkpoint: 830,897 DONE,
64 review at 18:01 UTC; later progress is unknown while the database is down.
The existing heartbeat remains active. Remote checkpoint: STATE.json; evidence:
`/home/cosmo/wos-step5-operations/evidence/D019-RECOVERY/`.
