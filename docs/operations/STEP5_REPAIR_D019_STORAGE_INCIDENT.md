# D019: full data volume interrupts the existing repair

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
