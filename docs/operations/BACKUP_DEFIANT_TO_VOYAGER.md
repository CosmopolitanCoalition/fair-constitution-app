# Backup of Defiant to Voyager: outline for the Voyager session

Written 2026-11-03 by the Defiant (Statecraft) session. This is an outline, not a build. The Voyager session builds the backup sequence and the schedule. Facts below were measured on 2026-11-03 with read-only commands.

## 1. Goal

A rolling copy of Defiant's data on Voyager's disk, kept current by a schedule, restorable on demand. Later, Voyager shows as a backup peer node in the Jarvis interface, and as a node in the mesh (see `docs/architecture/NODE_PARADIGM.md`).

## 2. Measured facts

| Item | Value |
|---|---|
| Defiant disk | 908 GB, 717 GB used, **145 GB free (84% full)** |
| Voyager disk | 916 GB, 104 GB used, 766 GB free |
| Network | Voyager reaches Defiant on the LAN, 0.35 ms |
| Postgres | 17.5 in container `fc_postgres`. Data in the docker volume `fair-constitution-app_postgres_data`. Port bound to `127.0.0.1` only. |
| Backup settings | `wal_level=replica` (good). `archive_mode=off`. `max_wal_senders=10`. `summarize_wal=off`. |
| Databases | `fair_constitution` 450 GB, `matrix` 64 MB, `matrix_auth` 11 MB |
| Largest tables (GB) | audit_log 86, public_records 78, vote_casts 39, residency_confirmations 32, ledger_entries 25, terms 18, tabulation_rounds 18, users 14 |
| Other volumes | `matrix_data` (Matrix media), `caddy_data` and `caddy_config` (certificates), `redis_queue_data` (queue, skip), `etl_geodata`, `composer_cache`, `vendor` (all rebuildable) |
| Voyager tools | present: rsync, zstd. Missing: pg_basebackup, pg_receivewal, pgbackrest, borg, restic, wg. The Voyager user is not in the docker group. |
| Compression hint | See the table below. About 43 GB for the text of the eight largest tables. |

Compression sample: 0.1% of each table, text format, zstd level 3. Multiply by 1000 for a whole-table estimate.

| Table | Sample text | Sample compressed | Ratio | Whole table, compressed (estimate) |
|---|---|---|---|---|
| audit_log | 97.2 MB | 11.3 MB | 8.6 | 11.3 GB |
| public_records | 68.5 MB | 10.0 MB | 6.9 | 10.0 GB |
| vote_casts | 31.0 MB | 8.7 MB | 3.6 | 8.7 GB |
| residency_confirmations | 21.1 MB | 3.4 MB | 6.2 | 3.4 GB |
| ledger_entries | 17.1 MB | 3.7 MB | 4.6 | 3.7 GB |
| tabulation_rounds | 15.4 MB | 2.5 MB | 6.2 | 2.5 GB |
| terms | 9.4 MB | 2.0 MB | 4.6 | 2.0 GB |
| users | 7.0 MB | 1.1 MB | 6.4 | 1.1 GB |

The sample is text output, not the stored pages. It leaves out indexes, which a physical backup includes. The real ratio of a physical backup will be lower. Treat the table as a hint.

## 3. What needs protecting

| Kind | What | If lost |
|---|---|---|
| Irreplaceable | Rows that real people made: the personal workspace (calendar, tasks, contacts, mail, drafts, dock state), sealed secrets, API key hashes, invites. Instance settings and the node's signing key. The Matrix databases and media. `.env` including `APP_KEY`. | Cannot be rebuilt. |
| Costly to rebuild | The 450 GB simulated world (about 18.9 million simulated users). | Days of compute. |
| Rebuildable | vendor, node_modules, composer cache, geodata archive (re-fetch), queue. | Skip. |

`APP_KEY` matters most. The secret vault seals each value with a key derived from `APP_KEY`. Lose `APP_KEY` and every stored secret is unreadable. Back up `.env` only inside an archive encrypted with a passphrase that only Joseph holds. Do not copy it to Voyager in plain text.

## 4. Two tiers

### Tier 1: small and frequent

- Matrix: `pg_dump` of `matrix` and `matrix_auth` in full. Rsync of the `matrix_data` volume.
- Statecraft: `pg_dump` of the real-person tables: `api_keys`, `user_secrets`, `personal_calendars`, `personal_events`, `availability_rules`, `task_projects`, `personal_tasks`, `crm_contacts`, `crm_notes`, `crm_followups`, `mail_messages`, `mail_drafts`, `dock_states`, `invites`, `instance_settings`, and the `users` rows of real people.
- OPEN: `users` holds simulated and real people together (14 GB). The Defiant session must supply a rule that selects real people, as a command `backup:manifest`. Until then Tier 1 dumps the small tables and the user ids they reference.
- Files: `.env` (encrypted, see section 3), compose files (already in git).
- Pull, never push, and never stage on Defiant. Defiant has 145 GB free. Run from Voyager: `ssh defiant 'docker exec fc_postgres pg_dump ...' | zstd > file`.
- Cadence: hourly for the databases, nightly for files. Keep 48 hourly, 14 daily, 8 weekly.
- Size: megabytes to low gigabytes once the real-person rule exists.

### Tier 2: the whole database

| Option | How | For | Against |
|---|---|---|---|
| A. pgBackRest | Repo on Voyager. Full plus incrementals plus WAL for point-in-time restore. | Compression, incrementals, tested restore. | Needs `archive_mode=on` (a Postgres restart) and pgBackRest inside the database container (a custom image). Voyager needs the package. |
| B. Native Postgres 17 incrementals | `pg_basebackup --incremental`, combine with `pg_combinebackup`. | No image change. Needs only `summarize_wal=on` (a reload), a replication role, and an SSH tunnel to the port. | Plain-format backups, so no compression on ext4 (verify on 17.5). One full is about 450 GB and leaves about 316 GB. |
| C. Streaming standby | A live replica on Voyager. Doubles as the dormant second node. | Fast failover. | About 450 GB. A bad write replicates too. A standby that goes offline can make Defiant keep WAL and fill its disk. Set `max_slot_wal_keep_size`. Needs docker or a native Postgres on Voyager. |

| D. Logical dump | Weekly `pg_dump` in directory format with zstd and parallel jobs, pulled by Voyager. | Small: the sample puts the eight largest tables near 43 GB. No Postgres restart, no image change. | No point-in-time restore. A restore rebuilds every index, which takes many hours on this disk. A long dump holds back vacuum while it runs. |

Suggested order: build Tier 1 first, measure Tier 2 with a trial, then choose. A is the likely fit for a rolling copy. D is the cheapest to start and fits comfortably on Voyager.

## 5. Steps for the Voyager session

1. Read this file and `docs/architecture/NODE_PARADIGM.md`.
2. Prepare a one-command script for Joseph to run with sudo. It installs `postgresql-client-17` and, if Tier 2 option A is chosen, pgBackRest. Joseph runs it.
3. Create a backup-only SSH key on Voyager. Joseph installs its public key on Defiant with a restricted command.
4. Build the Tier 1 pull script. Test by hand, then schedule it with a systemd timer or an n8n schedule.
5. Restore Tier 1 into a scratch database on Voyager. Compare row counts with Defiant.
6. Run a trial of each Tier 2 option on the small `matrix` database. Report time, size and effort. Joseph picks A, B or C.
7. Build Tier 2.
8. Add monitoring: a success marker with a timestamp per job, and an alert to Joseph's phone through n8n when the last good backup is older than twice its cadence.
9. Write the restore runbook in `~/jarvis-plan`. Test a full restore once a month.
10. Later: show Voyager as a backup peer node in the Jarvis interface mock-up.

## 6. Work on the Defiant side (waits for Joseph's word)

- A read-only database role `backup_reader` for Tier 1.
- The `backup:manifest` command that lists the real-person rows.
- For option A: `archive_mode=on` and the custom image. For option B: `summarize_wal=on`, a replication role, `pg_hba` entry.
- Keep Defiant under 90% disk use. Report if it passes that.

## 7. Risks

- Defiant is 84% full. Nothing may stage on it. WAL archives and replication slots can fill it.
- A full read of 450 GB on one disk slows the site. Run backups with `nice` and `ionice`, off hours.
- Credentials are entered only by Joseph. Scripts for sudo, keys and passphrases are prepared, then run by him.
- `archive_mode` needs a database restart. Defiant reboots often, so plan it with a reboot.

## 8. Decisions needed from Joseph

1. Tier 2 option (A, B, C or D) after the trial.
2. Retention against space: how many fulls to keep.
3. Whether the simulated world needs a backup at all, or only a monthly one, since it can be rebuilt in days.
