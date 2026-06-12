All inputs read and verified against the live Phase A code (migrations `2026_06_12_000001–000005`, `ConstitutionalEngine`, `FormRegistry`, `ClockService`/`ClockTimer`, `ActivationService`, `ConstitutionalValidator`, the original elections/endorsements/legislature_members skeletons, and the apportionment cleanup). Here is the Phase B backbone.

---

# PHASE B — ELECTIONS SCHEMA + LIFECYCLE + CRYPTO + WORK ITEMS

Verified facts this design is built on (not assumptions):

- `elections`, `endorsements`, `legislature_members` are **empty skeletons** (zero writers anywhere in `app/`); `legislature_members.user_id` is already uuid (retyped in `2026_06_12_000002`), faction columns gone (`2026_05_22_000002`). `legislature_members` keeps `seat_type char(1) 'a'|'b'`, `district_id`, `election_id`, `is_speaker`, and a **full unique `(legislature_id, user_id)` that must become partial** (it blocks re-election history).
- `clock_timers` as implemented has `fires_at` + `payload` + `override_value` but **no `window_opens_at/closes_at` columns** (the DESIGN doc's window columns were simplified away) — window semantics ride `payload` + paired timers. `ClockService::HANDLERS` maps only CLK-05/06 today; `fire()` dispatches handler jobs with **no arguments** (`$handler::dispatch()`) — Phase B must extend the dispatch to pass the timer (additive change to `ClockService::fire`).
- Associations live on **`residency_confirmations`** (evolved with `claim_id`, `depth`, partial uniques `WHERE is_active`) — *not* the `jurisdiction_associations` table from DESIGN A.1. All electorate queries below target `residency_confirmations`.
- `constitutional_settings` has `election_interval_months` (60), `special_election_min_days` (90) / `max_days` (180), `critical_population_threshold` — but **no `finalist_multiplier`** (DESIGN A-4 planned it for Phase A; it never landed — Phase B must add it). CLK-21 factor verified = **3 × seats** (fixtures: 7 seats → 21 finalist places; 5 seats → 15; EXPLORE_civic_electoral §1.4 "current setting 3 × seats (amendable)").
- Horizon queue `long-running` exists (`config/horizon.php` `supervisor-long-running`); the `scheduler` compose service runs `EvaluateClocksJob` every minute.
- Dev legislatures: Earth `type_a=1999, type_b=1160` (active map, 274+ districts); San Marino bicameral `32+9`, Montegiardino unicameral `10` — **both without district maps**, both `status='forming'`, zero members.

---

## A) MIGRATION SET

All files `database/migrations/2026_06_13_0000NN_*.php` (additive against the live dev DB; the three "evolve" targets are verified empty, so in-place column drops/renames there are safe and are guarded by a runtime row-count assert in the migration, same pattern as the users rebuild). Order below is FK dependency order — run as listed.

### B-1 `create_terms_table` — implements the CLK-10 lockstep substrate (no ESM of its own; consumed by ESM-03/13)

| column | type | notes |
|---|---|---|
| id | uuid PK default gen_random_uuid() | |
| office_kind | varchar(24) | CHECK in (`legislature_seat`,`executive_seat`,`judicial_seat`,`election_board_member`,`board_governor`,`admin_staff`,`civil_officer`) — full enum now, only `legislature_seat`/`election_board_member` written in B |
| office_type / office_id | varchar(64) / uuid null | polymorphic seat row (`legislature_members`, later `executive_members`…) — filled after seating |
| holder_user_id | uuid FK users restrictOnDelete | |
| jurisdiction_id | uuid (no FK — dev reseeds, same rationale as clock_timers) | |
| legislature_id | uuid null FK legislatures nullOnDelete | lockstep anchor for elected terms |
| term_class | varchar(20) | CHECK in (`lockstep`,`civil_appointment`) |
| starts_on / ends_on | date NOT NULL | **hardened: no update path for `ends_on` on lockstep terms** (engine rule, pinned by `TermLockstepTest`) |
| source_election_id / source_appointment_id | uuid null | FKs added in B-3/B-2 respectively (forward refs) |
| status | varchar(12) | CHECK in (`active`,`completed`,`vacated`,`removed`) |
| timestampsTz + softDeletesTz | | |

Indexes: `(legislature_id, status)`, `(holder_user_id, status)`, `(office_type, office_id)`.

### B-2 `create_appointments_and_election_boards`

**`appointments`** (generic civil-appointment pipeline; R-08 in B, R-18/29/30 later):
`id` uuid PK · `appointable_type varchar(64)` + `appointable_id uuid` · `nominee_user_id` uuid FK users · `nominated_by uuid null` · `nominated_via_form varchar(16) null` · `consent_vote_id uuid null` (**no FK** — chamber_votes is Phase C; nullable covers bootstrap) · `status varchar(12)` CHECK (`nominated`,`consented`,`rejected`,`seated`,`ended`) · `term_id uuid null FK terms nullOnDelete` · timestampsTz + softDeletesTz. Index `(appointable_type, appointable_id)`. Then `ALTER TABLE terms ADD CONSTRAINT terms_source_appointment_id_foreign …`.

**`election_boards`** (I-ELB):
`id` uuid PK · `jurisdiction_id` uuid FK jurisdictions cascade · `legislature_id uuid null FK legislatures nullOnDelete` (creating legislature; NULL for bootstrap) · `created_by_act_id uuid null` (**no FK** — laws is Phase C) · `is_bootstrap boolean NOT NULL default false` (system-as-board, "temporary · replacement queued") · `status varchar(12)` CHECK (`forming`,`active`,`retired`) · `retired_at timestamptz null` · timestampsTz + softDeletesTz.
Partial unique: `CREATE UNIQUE INDEX election_boards_one_active ON election_boards (jurisdiction_id) WHERE status = 'active' AND deleted_at IS NULL`.

**`election_board_members`**:
`id` uuid PK · `election_board_id` FK cascade · `user_id uuid null FK users nullOnDelete` (**NULL = the system itself on a bootstrap board** — gives the bootstrap board exactly one synthetic "member" row so every F-ELB filing has a board-member provenance without inventing a fake user) · `appointment_id uuid null FK appointments` · `status varchar(12)` CHECK (`nominated`,`seated`,`removed`,`term_ended`) · `term_starts_on/term_ends_on date null` · timestampsTz + softDeletesTz. CHECK: `(user_id IS NOT NULL) OR (status = 'seated')` — the system row is always seated. Partial unique `(election_board_id, user_id) WHERE status='seated' AND user_id IS NOT NULL`.

### B-3 `evolve_elections_table` — implements **ESM-03 Election** (guard: `abort if count(*) > 0`)

On the existing `elections` table:
- **rename** `type` → `kind`; recut CHECK: (`general`,`special`,`executive`,`judicial`,`referendum`,`org_board_owner`,`org_board_worker`,`restoration`) — full enum now, only `general`/`special` writable in B (engine-gated).
- **recut `status`** to ESM-03: CHECK (`scheduled`,`approval_open`,`finalist_cutoff`,`ranked_open`,`voting_closed`,`tabulating`,`certified`,`audit_rerun`,`final`,`cancelled`). (`audit_rerun` is the ESM's `[Recount]` under the no-hand-count reframing.)
- **drop**: `nomination_opens_on`, `nomination_closes_on`, `voting_opens_on`, `voting_closes_on`, `seats_to_fill`, `droop_quota`, `district_id`, `office_type`, `office_id`, `total_valid_votes`, `legislative_act_id`, `referendum_question`, `referendum_requires_supermajority`, `referendum_passed` (referendum fields return as `referendum_questions` in Phase C; per-race data moves to `election_races`). `voting_method` **stays** (whole-election snapshot of the amendable setting).
- **add**: `legislature_id uuid null FK legislatures` (body being filled) · `district_map_id uuid null FK legislature_district_maps restrictOnDelete` (map snapshot races were generated from; NULL = at-large) · `approval_opens_at` / `finalist_cutoff_at` / `ranked_opens_at` / `ranked_closes_at` / `certified_at` timestamptz null (CLK-18 window + schedule; UTC) · `prior_election_id uuid null` self-FK (cycle chain: certification of N opens approval of N+1) · `triggered_by_timer_id uuid null FK clock_timers nullOnDelete` (elections fire from clocks — provenance) · `vacancy_id uuid null` (FK added in B-10; special elections point at their vacancy).
- **make real**: `election_board_id` FK → election_boards.
- New indexes: `(legislature_id, status)`, `(kind, status)`, `finalist_cutoff_at`, `ranked_closes_at`.

### B-4 `create_election_races` — race-level slice of ESM-03 (status mirrors parent; quota/ballot tallies live here)

| column | type | notes |
|---|---|---|
| id | uuid PK | |
| election_id | uuid FK elections cascade | |
| district_id | uuid null FK legislature_districts restrictOnDelete | NULL = at-large race |
| jurisdiction_id | uuid FK jurisdictions | race footprint (district's parent scope, or the legislature's jurisdiction for at-large) |
| seat_kind | varchar(8) | CHECK (`type_a`,`type_b`,`single`) — bicameral dual-kind races; `single` reserved for individual exec (Phase D fires it) |
| seats | smallint | CHECK `seats BETWEEN 1 AND 9`; engine rule (not CHECK): chamber races require 5–9, `single` requires 1 |
| finalist_count | smallint NOT NULL | X pre-published with the scheduling order — frozen here so later `finalist_multiplier` amendments never move a published cutoff (CLK-21) |
| electorate_type | varchar(12) | CHECK (`residents`,`owners`,`workers`) default `residents` (owners/workers = Phase D org boards reuse) |
| quota | integer null | Droop snapshot, set at tabulation: `floor(valid/(seats+1))+1` |
| total_valid_ballots | integer null | |
| status | varchar(16) | mirrors parent ESM-03 states (per-race close/tabulate can stagger) |
| timestampsTz + softDeletesTz | | |

Unique `(election_id, district_id, seat_kind)` (Postgres treats NULL district_id as distinct — add partial unique `(election_id, seat_kind) WHERE district_id IS NULL` to cap one at-large race per kind). Index `(election_id, status)`, `district_id`.

### B-5 `create_candidacies_and_endorsement_evolutions` — implements **ESM-06 Candidacy**

**`candidacies`**:
`id` uuid PK · `election_id` FK cascade · `race_id uuid null FK election_races` (bound at F-ELB-002 validation from the candidate's deepest active `residency_confirmations` row mapped through `legislature_district_jurisdictions`; NULL until validated) · `user_id` FK users · `status varchar(16)` CHECK (`registered`,`validated`,`rejected`,`in_pool`,`finalist`,`non_finalist`,`withdrawn`,`elected`,`defeated`) · `platform_statement text null` · `position_tags jsonb NOT NULL default '[]'` · `residency_attested_at timestamptz NOT NULL` (the F-IND-011 checkbox — the only attestation that may exist) · `validated_at timestamptz null` · `validated_by_member_id uuid null FK election_board_members` · `rejection_reason varchar(32) null` CHECK (`rejection_reason IS NULL OR rejection_reason = 'no_residency_association'`) — **the only permissible ground is enforced by the database itself** (Art. I; Pham v. NY County) · `withdrawn_at timestamptz null` (engine blocks after `finalist_cutoff_at` — ballot lock) · timestampsTz + softDeletesTz.
Unique `(election_id, user_id)`. Index `(race_id, status)`, `(user_id)`.

**`endorsements` evolutions** (empty table): add FK `candidate_id` → `candidacies(id)` cascade (column exists, FK was deferred since 2026-01); add `is_public boolean NOT NULL default false` (individual endorsers disclose by choice — my-record contract; org endorsements forced `true` by the handler); drop nothing.

**`endorsement_requests`** (the F-CAN-002 → F-ORG-002 handshake; candidate-profile contract):
`id` uuid PK · `candidacy_id` FK cascade · `organization_id` FK organizations · `message text null` · `status varchar(12)` CHECK (`pending`,`granted`,`declined`) · `requested_at` · `decided_at null` · `endorsement_id uuid null FK endorsements` · timestampsTz. Unique `(candidacy_id, organization_id)`.

**`organizations`** add: `agent_user_id uuid null FK users nullOnDelete` — minimal R-23 substrate so F-ORG-002 has a role gate (full org module stays Phase D).

### B-6 `create_approvals_tables` — implements **ESM-04 Approval Standing**

**`approvals`** (secret individual approvals — WF-CIV-08; **deliberately NOT a form**, see §C):
`id` uuid PK · `election_id` FK cascade · `candidacy_id` FK cascade · `user_id` FK users cascade · `created_at timestamptz` · `revoked_at timestamptz null`. **No updated_at, no soft deletes** (append + revoke only). Partial unique `(candidacy_id, user_id) WHERE revoked_at IS NULL`. Index `(user_id, election_id)` (the "your active approvals" panel), `(candidacy_id) WHERE revoked_at IS NULL` (rollup count). Row access: Eloquent model has a global scope restricting reads to owner; aggregates only ever leave through `approval_standings`.

**`approval_standings`** (public daily aggregates — **never per-request**, Earth-scale rule):
`id` uuid PK · `race_id` FK cascade · `candidacy_id` FK cascade · `as_of_date date` · `approvals_count integer` · `rank smallint` · `delta integer` (vs prior day) · `is_frozen boolean default false` (cutoff snapshot — archived to chain) · `created_at`. Unique `(candidacy_id, as_of_date)`. Index `(race_id, as_of_date)`.

### B-7 `create_ballot_tables` — implements **ESM-05 Ballot (Ranked)**; the secrecy boundary

**`ballot_envelopes`** — participation record (double-vote prevention; the *only* voter-linked row):
`id` uuid PK · `race_id` FK cascade · `user_id` FK users · `kind varchar(12)` CHECK (`ranked`,`referendum`) · `referendum_question_id uuid null` (no FK until C) · `committed_at timestamptz` · `created_at`. Unique `(race_id, user_id, kind)` (extend with question id in C). **No content, no hash, no receipt — nothing here can reach the ballot.**

**`ballots`** — anonymous content:
`id` uuid PK default gen_random_uuid() (random — no sequence) · `race_id` FK cascade · `kind varchar(12)` CHECK as above · `payload_encrypted text NOT NULL` (encrypted ranking JSON, incl. write-in candidacy ids — write-ins tabulated identically) · `salt char(64) NOT NULL` (hex; commitment salt, see crypto) · `ballot_hash char(64) NOT NULL UNIQUE` (published self-audit list) · `cast_bucket timestamptz NOT NULL` (**hour-truncated** — `date_trunc('hour', now())`, computed in the BallotBox unit) · `counted boolean NOT NULL default false`. **NO `created_at`/`updated_at`/`deleted_at`** — wall-clock insert time is itself a linking channel; `cast_bucket` is the only time. **No user column, no envelope column, no FK to anything voter-shaped — enforced by the constitutional test suite asserting the table's column list.** Index `(race_id, counted)`.

### B-8 `create_tabulation_tables`

**`tabulations`**: `id` uuid PK · `race_id` FK cascade · `kind varchar(12)` CHECK (`initial`,`audit_rerun`,`countback`) · `excluded_candidacy_id uuid null FK candidacies` (the countback strike; NOT NULL iff kind='countback' — CHECK) · `engine_version varchar(16)` (`VoteCountingService::VERSION`) · `total_valid integer` · `quota integer` · `seats smallint` · `status varchar(12)` CHECK (`running`,`complete`,`superseded`) · `started_at` / `completed_at` · `record_hash char(64) null` (sha256 of the canonical full round record — sealed into audit chain on completion) · timestampsTz. Index `(race_id, kind, status)`.

**`tabulation_rounds`**: `id` uuid PK · `tabulation_id` FK cascade · `round_no smallint` · `action varchar(12)` CHECK (`elect`,`eliminate`) · `candidacy_id` FK · `transfer jsonb` (`{kind: 'surplus'|'elimination', value, to: [[candidacy_id, votes]], exhausted}` — Gregory fractional, mirrors the mockup `STV_DATA.display[]` contract exactly so `Results.vue` lifts straight from `results.html`) · `tallies jsonb` · `created_at`. Unique `(tabulation_id, round_no)`.

**`race_results`**: `id` uuid PK · `tabulation_id` FK cascade · `candidacy_id` FK · `round_elected smallint null` · `seat_no smallint null` · `vote_share_norm numeric(8,4) null` (normalized-quota share — committee tie-break input, q-ledger #2; computed once here, copied to the member row at seating) · `is_runner_up boolean default false` · `runner_up_rank smallint null` (1–4 sequential-exclusion advisors — schema lands now, written by Phase D exec races) · `created_at`. Unique `(tabulation_id, candidacy_id)`.

### B-9 `create_certifications_and_audits`

**`election_certifications`**: `id` uuid PK · `election_id` FK cascade · `election_board_id` FK · `certified_by_member_id uuid null FK election_board_members` (NULL only when bootstrap-system board — the synthetic member row makes this effectively NOT NULL; keep nullable + engine check) · `certified_at timestamptz` · `count_record_hash char(64)` (hash over all race `record_hash`es) · `status varchar(24)` CHECK (`certified`,`superseded_by_audit`) · timestampsTz. Unique partial `(election_id) WHERE status='certified'`.

**`election_audits`** (the recount = **audit re-run** reframing — never a hand count): `id` uuid PK · `election_id` FK · `race_id uuid null FK` · `cause text NOT NULL` (F-ELB-006 requires stated cause) · `ordered_by uuid FK users null` · `ordered_at` · `tabulation_id uuid null FK tabulations` (the re-run) · `outcome varchar(12) null` CHECK (`reaffirmed`,`corrected`) · `resolved_at null` · timestampsTz. Engine gate: creatable only when a certification row exists (F-ELB-006 contract).

### B-10 `create_vacancies_table` — implements **ESM-13 Vacancy**

`id` uuid PK · `seat_type varchar(64)` + `seat_id uuid` (polymorphic; only `legislature_members` written in B) · `legislature_id uuid FK` · `jurisdiction_id uuid` · `declared_by uuid null FK users` · `declared_via_form varchar(16) null` (F-LEG-036 arrives in C; B writes `dev` / system detection) · `status varchar(32)` CHECK (`detected`,`declared`,`countback_running`,`filled`,`countback_failed`,`special_election_scheduled`) · `detected_at` / `declared_at null` · `countback_tabulation_id uuid null FK tabulations` · `special_election_id uuid null FK elections` · `filled_by_user_id uuid null` · `filled_at null` · timestampsTz. Then `ALTER TABLE elections ADD CONSTRAINT elections_vacancy_id_foreign …`. Index `(seat_type, seat_id)`, `(status)`.

### B-11 `evolve_legislature_members` — seating substrate (R-09 derivation source)

(guard: table verified empty)
- **add**: `seat_no smallint null` · `elected_in_race_id uuid null FK election_races` · `term_id uuid null FK terms` · `vote_share_norm numeric(8,4) null` · `seated_at timestamptz null` (oath F-LEG-001 is Phase C; certification sets `status='elected'`, C's oath flips to `seated` — in B the demo seats directly with an audit note) · `home_jurisdiction_id uuid null` (the member's association at election time — countback/relocation provenance).
- **recut `status`** CHECK: (`elected`,`seated`,`vacated`,`removed`,`term_ended`) (replaces free-string `active|vacant|expelled|resigned|deceased`).
- **drop**: `vote_count` (unsignedSmallInteger — overflows at Earth scale and duplicates `race_results`; superseded by `vote_share_norm`).
- **replace unique**: drop `UNIQUE (legislature_id, user_id)`; add partial `CREATE UNIQUE INDEX legislature_members_one_current ON legislature_members (legislature_id, user_id) WHERE status IN ('elected','seated') AND deleted_at IS NULL` — re-election across terms keeps history rows.
- `district_id` gains its real FK → `legislature_districts` nullOnDelete (comment said "future" — future is now). `seat_type` char(1) stays (`a`/`b` — matches `seat_kind` `type_a`/`type_b` via the model).

### B-12 `add_phase_b_constitutional_settings`

`constitutional_settings` add: `finalist_multiplier smallint NOT NULL DEFAULT 3` (CLK-21: X = multiplier × seats; amendable — add bounds `['min'=>1,'max'=>10,'citation'=>'Art. II §2 · as implemented']` to `ConstitutionalValidator::SETTING_BOUNDS`) · `ranked_window_days smallint NOT NULL DEFAULT 14` (length of the ranked-voting window; as-implemented amendable, bounds 1–60) · `approval_min_days smallint NOT NULL DEFAULT 30` (minimum approval-phase length before a cutoff may be set — bootstrap elections compress it via dev config, never via data).

**Deferred from A.2 with justification**: `referendum_questions` (Phase C — F-IND-008 referendum ballots reuse `ballot_envelopes`/`ballots.kind='referendum'` whose columns land now so C doesn't migrate the secrecy tables); `boards`/`board_seats` (Phase D); F-ELB-003 manual boundary-drawing storage (uses existing `legislature_district_maps` draft rows — no new table needed).

---

## B) LIFECYCLE DESIGN

### B.1 Election state machine (ESM-03) and its clock wiring

```
                       CLK-01 fires (or bootstrap F-ELB-001)
                                   │
   certification of N ────────────▼──────────────────────────────┐
   (F-ELB-004)            elections row N+1 created              │
        │                 status='approval_open'                 │
        │                 approval_opens_at = now()              │
        ▼                 (CLK-18 window opens — no dead period) │
  ESM-03: …Certified → Final                                     │
        │                                                        ▼
        └─ arms CLK-01 (next cycle) + CLK-10 (terms)   approval_open
                                                            │  F-ELB-001 confirms/sets:
                                                            │  finalist_cutoff_at, ranked_opens_at,
                                                            │  ranked_closes_at; X per race pre-published
                                                            ▼
                                              CLK-18 timer fires_at=finalist_cutoff_at
                                                            │ FinalistCutoffJob:
                                                            │  standings frozen (is_frozen),
                                                            │  top-X candidacies → 'finalist',
                                                            │  rest → 'non_finalist' (write-in eligible),
                                                            │  withdrawals locked (ballot lock)
                                                            ▼
                                                    finalist_cutoff
                                                            │ timer fires_at=ranked_opens_at
                                                            ▼
                                                      ranked_open  ← F-IND-007 ballots commit
                                                            │ timer fires_at=ranked_closes_at
                                                            ▼
                                                     voting_closed → tabulating
                                                            │ TabulateElectionJob (queue: long-running)
                                                            ▼
                                                  results + record_hash per race
                                                            │ F-ELB-004 (board / system-as-board)
                                                            ▼
                                                       certified ──► (loop: N+2 approval opens)
                                                            │ F-ELB-006 (+cause) → audit_rerun → final
                                                            ▼
                                                          final
```

Exact timer rows (all `clock_timers`, armed via `ClockService::arm`, every arm/fire audit-chained already):

| clock_id | subject | fires_at | payload.step | handler job (added to `ClockService::HANDLERS`) |
|---|---|---|---|---|
| CLK-01 | `legislature` / legislature_id | `term_starts + election_interval_months − (approval lead)` — concretely `certified_at + interval` minus `(ranked_window_days + cutoff lead)` so the **next certification lands at lockstep expiry** | `schedule_general` | `Clocks\ScheduleGeneralElectionJob` |
| CLK-18 | `election` / election_id | `finalist_cutoff_at` | `finalist_cutoff` | `Clocks\FinalistCutoffJob` |
| CLK-01 | `election` / election_id | `ranked_opens_at` | `ranked_open` | `Clocks\AdvanceElectionPhaseJob` |
| CLK-01 | `election` / election_id | `ranked_closes_at` | `ranked_close` | `Clocks\AdvanceElectionPhaseJob` (→ dispatches `TabulateElectionJob` onto `long-running`) |
| CLK-10 | `term` / term_id | — (`fires_at` NULL, derived/flag) | `lockstep` | none — CLK-10 is the **no-API guarantee**: no service exposes `ends_on` mutation on lockstep terms; the timer row exists so term-sync screens and `audit:verify` can observe lockstep state |
| CLK-04 | `vacancy` / vacancy_id | `declared_at + special_election_max_days` (hard close; payload carries `{window_opens_at: +min_days, window_closes_at: +max_days}`) | `special_window_close` | `Clocks\SpecialElectionBackstopJob` |
| CLK-21 | — | never armed | — | **derived formula, not a timer**: `finalist_count = finalist_multiplier × seats`, resolved per jurisdiction by `ClockService::resolvedInt('CLK-21', …)` at race creation and frozen into `election_races.finalist_count` |

`ClockService::fire()` change (small, audited): pass the fired timer into the handler — `$handler::dispatch($timer->id)` — so election jobs know their subject. Existing CLK-05/06 jobs take an optional ignored arg (backward compatible).

**Hardened no-skip**: there is no API to move `fires_at` on CLK-01/CLK-10 timers; `ScheduleGeneralElectionJob` runs as **system actor** through `ConstitutionalEngine::file('F-ELB-001', null, …)` — elections fire from clocks, never official discretion. The board's F-ELB-001 can only *refine dates within bounds* (cutoff ≥ `approval_opens_at + approval_min_days`, ranked window = `ranked_window_days`, all before lockstep expiry); it cannot delay past the lockstep boundary (engine rule, citation Art. II §2).

### B.2 Two-phase open ballot, end-to-end

1. **Approval phase (CLK-18, continuous)** — opens automatically inside the F-ELB-004 certification transaction for election N: the handler **creates election N+1** (`prior_election_id = N`, `status='approval_open'`, `approval_opens_at = now()`, races generated from the *current* active district map — re-snapshot at F-ELB-001 confirmation if the map changed). Candidacy registration (F-IND-011) and endorsements run the entire inter-election period. Approvals accumulate revocably (service-level, no form — §C). `ApprovalStandingsRollupJob` (scheduled **daily** in `routes/console.php`) recomputes `approval_standings` per race and appends one audit entry per race (`module='elections', event='standings.rolled'` with count hash) — never per-request, never per-approval.
2. **Finalist cutoff (CLK-18 close / CLK-21)** — `FinalistCutoffJob`: in one transaction — final rollup with `is_frozen=true`, top `finalist_count` per race → `candidacies.status='finalist'`, others `'non_finalist'` (**write-in eligible — right to stand preserved**), election → `finalist_cutoff`, frozen standings archived to the chain. Ties at the line: broken by earlier `validated_at` (registration seniority), recorded with citation "Art. II §2 · as implemented" — flagged for operator ratification.
3. **Ranked window** — `ranked_open`: F-IND-007 commits ballots (see crypto). At `ranked_closes_at` → `voting_closed` → `TabulateElectionJob` per race (queued `long-running`; Earth = 274+ jobs fanned via a batch).
4. **Tabulation** — `VoteCountingService` (PROTECTED, new): PR-STV, Droop `floor(valid/(seats+1))+1`, **Gregory fractional surplus transfers, all seats of the race filled in one count**; write-ins decrypted ranking entries pointing at any **validated** candidacy id, tabulated identically; per-round rows written to `tabulation_rounds`; `record_hash` sealed into the chain.
5. **Certification (F-ELB-004)** — board (or system-as-board) certifies: winners → `legislature_members` rows (`status='elected'`, `elected_in_race_id`, `vote_share_norm`, `seat_type` from race `seat_kind`) + `terms` rows (`lockstep`, `starts_on=certified date`, `ends_on=starts + election_interval_months`); legislature `status forming→active`, `term_starts_on/term_ends_on` set; CLK-01 armed for the next cycle; CLK-10 flags armed per term; election N+1 approval phase opened (loop closes); winners gain **R-09 automatically** (RoleService derives from the member row — certification seats winners, nothing else needed).
6. **Vacancy → countback → special** — vacancy row (`declared`) → `CountbackService`: re-run the race's stored ballots with `excluded_candidacy_id` struck, **universal, no filter of any kind** (q-ledger #q6); winner → F-ELB-004 countback certification → seat filled, **term row inherits the original `ends_on`** (lockstep — never a fresh term); ballots exhausted → `countback_failed` → system **auto-schedules** the special election (kind `special`, scoped to the one race/district, `vacancy_id` set) with ranked window inside `[declared_at+90d, +180d]` — the board may move dates via F-ELB-001 but the engine rejects any date outside the window (CLK-04; the `SpecialElectionBackstopJob` at +180d appends a violation entry and force-schedules if a board somehow cancelled — discretion can never produce "no election").

### B.3 Bootstrap path (WF-ELE-02, completes WF-JUR-01 step 3)

`ActivationService::activate()` gains **step 3.5** (between stubs and self_governing):
1. Insert `election_boards` row `is_bootstrap=true, status='active'` + the synthetic system member row. Audit `event='bootstrap_board_constituted'`, ref WF-ELE-02.
2. System-files **F-ELB-001** (`ConstitutionalEngine::file('F-ELB-001', null, …)` — `null` actor passes the role gate per the engine's existing system-filing rule): creates the bootstrap `elections` row (kind `general`, `trigger='bootstrap'`, `prior_election_id=NULL`), generates races (§B.4), opens approval phase immediately, arms the CLK-18/phase timers. Dates: `finalist_cutoff_at = now() + approval_min_days`, ranked window per `ranked_window_days` (dev: `config('cga.election_demo_compression')` shrinks these for the demo — config, never data).
3. `jurisdiction_activations` gains state `'election_scheduled'` between `bootstrapping` and `self_governing`? **No — additive instead**: keep the 4-state machine, record the election id in `notes.bootstrap_election_id`; `self_governing` keeps meaning "legislature row exists". The legislature flips `forming→active` only at first certification (existing semantics preserved; the roadmap's exit criterion "chamber appears in the browser" reads the member rows).
4. Bootstrap board carries the persistent "temporary · replacement queued" banner until WF-ELE-10 (Phase C) retires it.

### B.4 Races for chambers WITHOUT district maps — the ruling

**Constitutional reading (Art. II §8 / Art. V §3)**: subdivision into separate voter pools is *forbidden unless* seats exceed the max (9). Therefore:

- **`type_a_seats ≤ 9`, no map → single at-large race is not a workaround, it is the constitutional default.** One `election_races` row, `district_id=NULL`, `seats=type_a_seats`, electorate = all active `residency_confirmations` on the legislature's jurisdiction.
- **`type_a_seats > 9` → subdivision is mandatory before any election.** An at-large 10+-seat race is unconstitutional on its face; the engine must refuse to generate it.
- **`type_b` seats**: at-large by construction (elected by the whole population, Art. V §3). San Marino `type_b=9` → one at-large 9-seat STV race ✓. **Earth `type_b=1160` exceeds 9 and has no constitutional grouping mechanism defined** (grouping at-large seats into races contradicts "elected by whole population"; per-constituent single-winner races would smuggle RCV outside the individual-executive exception). **Defer with operator ruling required** — Phase B elections for Earth exercise type_a district races only; the type_b race generator throws a recorded engine rejection for `type_b_seats > 9`.

Concrete Phase B posture per chamber:

| Chamber | type_a | type_b | Phase B handling |
|---|---|---|---|
| **Montegiardino** (leaf, unicameral) | 10 | 0 | **10 > 9 with no constituents and no manual drawing tool = cannot be elected as-is.** Recommendation: clamp the *leaf* sizing path in `ActivationService::instantiateLeaf` to `min(cubeRoot, resolved legislature_max_seats)` = 9, with an audit note `'clamped_pending_subdivision_capability'`. This is **amendable-layer** (the cube-root sizing law is a setting, `legislature_sizing_law`; the 9-max is the hardened bound) — clamping resolves toward the constitution, not away from it. The existing 10-seat dev row is re-planned by a one-line dev command (`jurisdiction:activate --replan` on a memberless `forming` chamber). When the raster-based manual district tool lands (the `worldpop_rasters` + `population_within()` purpose), F-ELB-003 subdivision restores cube-root sizing with districts. **Flag prominently: this is a DECISION for the operator; the alternative (election blocks in a `blocked_pending_subdivision` state) is constitutionally equally valid but kills the Phase B demo on exactly the chamber sized for it.** |
| **San Marino** (bicameral, 9 castelli) | 32 | 9 | type_a 32 > 9 → **activation/scheduling generates the initial district map** (backlog #3): extract `LegislatureController::runAutoCompositeForScope` into `app/Services/DistrictingService` (already on the A.2 plan) and have `ScheduleGeneralElectionJob`/F-ELB-001 create a draft `legislature_district_maps` row + Webster composites of the 9 castelli into 5–9-seat districts, activate it, snapshot `elections.district_map_id`. Draft maps are published for observation (board console) before activation. type_b 9 → one at-large race. |
| **Earth** | 1999 | 1160 | type_a: races generated from the existing active map (274+ districts, one race each — exercised at district scope per the exit criteria). type_b: **deferred** (above). |

Engine rule (in `ConstitutionalValidator`, new `elections.race_structure` rule): a race may only be created with `district_id=NULL` when `seats ≤ legislature_max_seats`; race generation for a chamber whose seats exceed the max without an active map must either auto-generate one (constituents exist) or reject with citation Art. II §8.

### B.5 Ballot crypto — the realistic Phase B posture

What Phase B ships (per Architecture C.5 + audit-chain mockup, achievable without research):

1. **Structural separation** (the strongest guarantee here): `ballot_envelopes` (voter-linked, content-free) and `ballots` (content, voter-free) — **no linking column, no FK path, no shared id**, written by one dedicated code unit `app/Domain/Ballots/BallotBox.php` whose insert path is the *only* writer (constitutional test asserts the column lists and greps for rogue writers).
2. **At-rest encryption**: `payload_encrypted = sodium_crypto_secretbox(canonical_json(rankings), nonce, k_e)` where `k_e` is a per-election data key generated at `ranked_open`, stored wrapped by the Laravel app key in `elections` (add `ballot_key_wrapped text null` to B-3). Nonce prepended. This is confidentiality against DB exfiltration, **not** against the server operator — stated plainly in the migration docblock.
3. **Hash commitment + receipt**: `salt = random_bytes(32)`; `ballot_hash = sha256(salt ‖ canonical_json(rankings))`. The **receipt returned to the voter is `{ballot_hash, salt}`** (salt also stored in the row so audit re-runs can re-verify commitments). Voter self-audit: published list contains `ballot_hash` for every counted ballot; the voter checks inclusion. The salt prevents brute-forcing the small ranking space from the published hash.
4. **Publication list**: at `voting_closed`, `PublishBallotHashesJob` writes the sorted hash list + its root hash into the audit chain and exposes `/elections/{id}/races/{id}/ballot-hashes`.
5. **Timing decorrelation**: `cast_bucket` hour-truncated; `ballots` has no timestamps; envelope audit entries record participation only. **Known residual channel**: physical heap insertion order (ctid) correlates ballots↔envelopes for a DB superuser — mitigated by a post-close `CLUSTER ballots_<...> USING ballot_hash` re-order step inside `PublishBallotHashesJob`, documented as a mitigation not a proof.
6. **Audit chain discipline**: per-ballot audit entry = envelope only (`event='ballot.committed'`, payload `{race_id, envelope_id}`) — **never `ballot_hash`, never content** (hash + seq adjacency would re-link).

**Flag for a real cryptographer before production** (do not improvise these): receipt-freeness/coercion resistance (the salt+hash receipt *proves* a vote — vote-selling channel; fixes are Benaloh challenges / E2E-verifiable schemes of the ElectionGuard/Helios class), threshold key custody (one operator holds the wrap key today), mixnet or homomorphic tally to remove the trusted-tally assumption, end-to-end verifiability of the *count* (today: trust + published round record + audit re-runs), and the insertion-order channel above. Phase B's claim is precisely: *cryptographic separation + tamper-evident record + voter inclusion-audit*, not coercion resistance — the UI copy must not overclaim.

---

## C) ENGINE HANDLERS (Phase B additions to `FormRegistry::HANDLERS`)

All in `app/Domain/Forms/Handlers/`, implementing the existing `FormHandler` contract; role gates via `RoleService` (which gains: R-06 = has candidacy in `registered..finalist`; R-07 = R-06 + granted endorsement from an org; R-08 = seated `election_board_members` row **or** is_operator acting on a bootstrap board in dev; R-09 = `legislature_members` `status in ('elected','seated')`). System filings (`null` actor) bypass gates per the engine's existing rule — that is the bootstrap-board mechanism.

| Form | Handler class | Role | Validation (ConstitutionalValidator + handler) | Mutation | Audit payload (never ballot content) |
|---|---|---|---|---|---|
| F-ELB-001 | `ElectionSchedulingOrder` | R-08 (system for bootstrap/CLK-01) | dates UTC + ordered; cutoff ≥ opens+`approval_min_days`; ranked window = `ranked_window_days`; ≤ lockstep expiry; special: window ∈ `[declared_at+min_days, +max_days]` (**out-of-window rejected with citation Art. II §5**); race structure rule §B.4 | create/confirm `elections` + `election_races` (X frozen per race), arm phase timers; auto-generate initial map when required | election_id, kind, dates, races `[{race_id, district_id, seats, finalist_count}]` — **X pre-published with the order** |
| F-ELB-002 | `CandidateValidation` | R-08 | **only check permitted: active `residency_confirmations` row inside the race footprint** — payload carrying any other ground is rejected (extends `RIGHTS_AUTOMATIC_FORMS` guard to candidacy) | candidacy → `validated` + `race_id` bound, or `rejected` (`rejection_reason='no_residency_association'` — DB CHECK) | candidacy_id, decision, race_id; rejection includes the appeal-path notice |
| F-ELB-003 | `SubdivisionBoundaryDrawing` | R-08 | draft map exists; every district 5–9; full coverage of footprint | **Phase B minimal**: flip draft map → active (+ archive prior). Manual drawing UI deferred (needs the raster tool) — auto-composite path covers B | map_id, district count, seat vector |
| F-ELB-004 | `ElectionResultsCertification` | R-08 | all races `tabulating→complete`; record hashes present; idempotency (one certification) | the big transaction: certification row → seat winners (`legislature_members`) → `terms` → legislature `active` → arm CLK-01/CLK-10 → **create election N+1 + open approval (CLK-18)**; countback variant seats one member with inherited `ends_on` | election_id, count_record_hash, winners `[{user_id, race_id, seat_no}]`, next_election_id |
| F-ELB-006 | `RecountAuditOrder` | R-08 | certification exists; `cause` non-empty | `election_audits` row; dispatch `TabulateElectionJob(kind='audit_rerun')`; outcome `corrected` → superseding certification via F-ELB-004 path | audit_id, race_id, cause |
| F-IND-007 | `BallotSubmission` | R-04 | election `ranked_open`; voter's association resolves to the race electorate; **no existing envelope** (double-vote); rankings reference finalist or validated (write-in) candidacies only; rankings well-formed (no dup ranks) | `BallotBox::commit()` — envelope + encrypted ballot + receipt (one transaction, separated code unit) | `{race_id, envelope_id}` — participation only; receipt returned in `EngineResult->recorded` but **stripped before audit append** (BallotBox hands the engine a pre-shaped audit payload) |
| F-IND-011 | `CandidacyRegistration` | R-03 | CLK-18 open (`approval_open` phase); office ∈ actor's association chain; `residency_attested_at` checkbox; one candidacy per election | `candidacies` row `registered`; queues board validation | candidacy_id, election_id, office jurisdiction |
| F-CAN-001 | `CampaignProfileSetup` | R-06 | statement length rails only | update platform_statement/position_tags (changes are appended to the public record) | candidacy_id, fields changed |
| F-CAN-002 | `EndorsementRequest` | R-06 | org exists; no duplicate pending | `endorsement_requests` row `pending` | request_id, org_id |
| F-CAN-003 | `CandidacyWithdrawal` | R-06 | **`now() < finalist_cutoff_at`** (ballot lock — after cutoff rejected, citation open-ballot spec) | candidacy → `withdrawn` (permanent public record) | candidacy_id |
| F-ORG-002 | `CandidateEndorsementGrant` | R-23 (`organizations.agent_user_id`) | pending request exists; grantor is the org's agent | `endorsements` row (`is_public=true`) + request `granted` → candidate derives R-07 | endorsement_id, candidacy_id, org_id |

**Not forms, by design**: approvals (cast/revoke) — `app/Services/ApprovalService.php`, owner-scoped writes, **zero per-approval audit entries** (individual approvals are constitutionally secret; an audit row `user→candidacy` would violate that). The deliberate audit exception is documented in the class docblock + pinned by `ApprovalSecrecyTest`. The daily rollup is the audited event. **Deferred handlers**: F-ELB-005 (petitions — Phase C), F-IND-008 (referendum — Phase C, tables ready), F-LEG-036 (vacancy declaration — Phase C; B uses system detection/dev command writing `declared_via_form='dev'`).

---

## D) WORK-ITEM BREAKDOWN

| # | Work item | Size | Depends on | Parallel with |
|---|---|---|---|---|
| WI-B0 | Migrations B-1…B-12 + Eloquent models | M | — | WI-B1 |
| WI-B1 | `VoteCountingService` (PROTECTED) + constitutional tests | L | — (pure, DB-free) | WI-B0 |
| WI-B2 | `BallotBox` crypto unit + secrecy tests | M | WI-B0 | WI-B3, WI-B4 |
| WI-B3 | `ElectionLifecycleService` + clock wiring + jobs | L | WI-B0 | WI-B2, WI-B4 |
| WI-B4 | Engine handlers + RoleService R-06..R-09 | M | WI-B0 | WI-B2, WI-B3 |
| WI-B5 | Tabulation→certification→seating pipeline | L | WI-B1, WI-B2, WI-B3, WI-B4 | WI-B7 |
| WI-B6 | Vacancy / countback / special election | M | WI-B5 | WI-B7, WI-B8 |
| WI-B7 | Bootstrap board + activation step 3.5 + initial-map generation + Montegiardino re-plan | M | WI-B3, WI-B4 | WI-B5 |
| WI-B8 | Screens (8 Vue pages + controllers + SurfaceMeta) | XL | WI-B4 (contracts); polish after WI-B5 | WI-B5/B6 backend |
| WI-B9 | `elections:demo` end-to-end command | M | WI-B5, WI-B7 | WI-B8 polish |
| WI-B10 | Constitutional + feature test gate | M | woven through; gate at end | — |

**Critical path**: WI-B0 → WI-B3/B4 → WI-B5 → WI-B9. WI-B1 starts day one in parallel (it is the constitutional law and the schedule risk).

### WI-B0 — Migration set + models 〔M〕
Files: the 12 migrations above; `app/Models/{Term,Appointment,ElectionBoard,ElectionBoardMember,Election,ElectionRace,Candidacy,EndorsementRequest,Approval,ApprovalStanding,BallotEnvelope,Ballot,Tabulation,TabulationRound,RaceResult,ElectionCertification,ElectionAudit,Vacancy}.php` (+ evolve `LegislatureMember`, `Endorsement`, `Organization`). `Ballot` model: `$timestamps = false`, guarded relations (none voter-shaped). `Approval` model: owner global scope.
Verification: `php artisan migrate` green on the live dev DB; `migrate:rollback` ×12 green on scratch; row-count guards proven by a test seeding one fake election row and asserting the evolve migration aborts.

### WI-B1 — `app/Services/VoteCountingService.php` (PROTECTED — add to CLAUDE.md list) 〔L〕
Contents: pure PHP, DB-free API — `countStv(array $ballots, int $seats, ?string $excludedCandidacyId = null): CountRecord` (Droop, Gregory fractional surplus at full precision via BCMath strings — float drift is a constitutional bug; elect/eliminate rounds; exhausted tracking; deterministic tie-break = fewest first-prefs then registration seniority, documented), `countRcv()` (single-winner, Phase D consumer), `runnersUpBySequentialExclusion()` (schema consumer in D), `VERSION` const. `CountRecord` serializes to the `STV_DATA` shape (`tabulation_rounds.transfer/tallies` contract).
Verification: `tests/Constitutional/StvDroopGregoryTest` (un-skip the Phase A placeholder): known worked examples (small hand-computed 3-seat case; the mockup Queens 9-seat fixture replayed from `results.html`'s generator inputs), property tests (every seat filled exactly once; total transferred ≤ surplus; quota math; write-ins indistinguishable), `CountbackUniversalTest`: re-run minus winner === countback; no candidacy attribute other than the struck id can change the result (reflection: the function signature *cannot receive* faction-like inputs).

### WI-B2 — `app/Domain/Ballots/BallotBox.php` + publication 〔M〕
Contents: `commit(User, ElectionRace, array $rankings): BallotReceipt` (one transaction: envelope insert, sodium encrypt, salt+hash, hour-bucket; returns `{ballot_hash, salt}`), `decryptForCount(ElectionRace): iterable` (only callable by tabulation jobs), `app/Jobs/PublishBallotHashesJob.php` (sorted list + root hash → chain; CLUSTER re-order), key wrap/unwrap helpers, `elections.ballot_key_wrapped` writer.
Verification: `tests/Constitutional/BallotSecrecyTest` — asserts `ballots` column list contains no voter/timestamp columns; envelope↔ballot join impossible at schema level; receipt verifies against published list; double-vote rejected; audit chain contains participation but never hashes/content (regex over the chain payloads).

### WI-B3 — `app/Services/ElectionLifecycleService.php` + clocks 〔L〕
Contents: phase transitions (single authority for ESM-03 moves, each audited); race generation incl. §B.4 rules + at-large path + `DistrictingService` extraction from `LegislatureController::runAutoCompositeForScope` (mechanical move, controller delegates); `app/Jobs/Clocks/{ScheduleGeneralElectionJob, FinalistCutoffJob, AdvanceElectionPhaseJob, SpecialElectionBackstopJob}.php`; `ApprovalStandingsRollupJob` + daily schedule in `routes/console.php`; `ClockService::HANDLERS` additions + the timer-arg dispatch change; `ApprovalService` (cast/revoke, secrecy exception documented).
Verification: feature test — fake election walked through every phase by firing timers via `EvaluateClocksJob`; rollup never runs per-request (no controller references `approvals` count directly — arch test); CLK-04 backstop fires and force-schedules.

### WI-B4 — Handlers + roles 〔M〕
Contents: the 11 handler classes of §C registered in `FormRegistry::HANDLERS`; `RoleService` R-06/R-07/R-08/R-09 derivations (+ shared-prop exposure for the sidebar); `ConstitutionalValidator` additions: `elections.race_structure`, candidacy rights-automatic extension (F-IND-011/F-ELB-002 in the guard list), `finalist_multiplier`/`ranked_window_days` bounds.
Verification: per-handler feature tests incl. the three signature rejections — F-ELB-002 non-residency ground, F-CAN-003 post-cutoff, F-ELB-001 out-of-window special date — each leaving a `rejected=true` chain row with citation.

### WI-B5 — Tabulation → certification → seating 〔L〕
Contents: `app/Jobs/TabulateElectionJob.php` (`$queue='long-running'`; per-race; batch fan-out per election; writes tabulations/rounds/results + record_hash; idempotent via `tabulations.status`); certification side-effect block inside `ElectionResultsCertification` (winners→members, terms, legislature `forming→active`, CLK-01/CLK-10 arming, N+1 approval open — single transaction); `vote_share_norm` computation.
Verification: constitutional `TermLockstepTest` un-skipped (no API mutates lockstep `ends_on`; special-election term inherits expiry); E2E feature: certify → R-09 derived → chamber renders members in the legislature browser; chain verifies green.

### WI-B6 — Vacancy / countback / special 〔M〕
Contents: `app/Services/VacancyService.php` (declare [dev/system], run countback via `VoteCountingService` `excludedCandidacyId`, certify-or-fail, auto-schedule special per §B.2.6); CLK-04 arming; `elections.kind='special'` scoping to one race.
Verification: feature test both branches (winner found / exhausted → special inside window); engine rejects manual out-of-window scheduling.

### WI-B7 — Bootstrap board + activation step 3.5 〔M〕
Contents: `ActivationService` step 3.5 (board + system F-ELB-001 per §B.3); leaf-clamp decision implementation (`instantiateLeaf` clamp + `jurisdiction:activate --replan` for memberless forming chambers — **flag in PR description for operator sign-off**); initial-map auto-generation hook for >9-seat chambers with children (San Marino path).
Verification: `php artisan jurisdiction:activate smr-0-san-marino --force` yields board + scheduled election + draft→active map with 5–9 districts over 9 castelli; Montegiardino re-plan to 9 seats + single at-large race; both visible in the board console.

### WI-B8 — Screens 〔XL — split across the team/time〕
Files (mockup → page, per the roadmap): `Pages/Elections/{ElectionDetail,CandidacyRegistration,CandidateProfile,OpenBallot,RankedBallot,Results,BoardConsole,VacancyCountback}.vue`; controllers `app/Http/Controllers/Elections/{ElectionController,CandidacyController,ApprovalController,BallotController,ResultsController,BoardConsoleController,VacancyController}.php`; routes under `auth`; `config/cga/surfaces.php` SurfaceMeta entries; `Results.vue` consumes the `STV_DATA`-shaped serialization of `tabulation_rounds`; civic Home elections list goes live (replaces the Phase A empty state).
Verification: manual walkthrough of the demo election in the browser at each phase; receipt verify UX against the published list; board console bootstrap banner.

### WI-B9 — `app/Console/Commands/ElectionsDemoCommand.php` (`elections:demo`) 〔M〕
Mechanics (everything through the real engine — the demo *is* the verification):
```
php artisan elections:demo smr-2-montegiardino --voters=40 --candidates=12 --instant
```
1. `ActivationService::activate` (force) → board + scheduled election (WI-B7).
2. Seed voters: N users via `F-IND-001` (faker emails, password `demo`), each `F-IND-003` on the target jurisdiction + backdated qualifying pings via the existing dev simulate path (`ST_GeneratePoints` inside `jurisdictions.geom` for plausible coordinates), run the CLK-05 evaluator → verified → R-04. (Reuses the Phase A residency simulator wholesale.)
3. K candidacies (`F-IND-011`) from the seeded voters; system-as-board validates (`F-ELB-002`).
4. Random-but-skewed approval sets via `ApprovalService` (Zipf-ish so standings look real); rollup job.
5. Phase advance: `--instant` calls `ElectionLifecycleService` transitions synchronously (each still audited + timer-cancel-and-fire so the chain shows real clock provenance); without `--instant`, `config('cga.election_demo_compression')` arms minute-scale timers and the scheduler drives it live.
6. Ballots: every voter files `F-IND-007` with randomized rankings (a few write-ins of non-finalists — proving identical tabulation); receipts printed.
7. Close → `TabulateElectionJob` (`--instant` runs `queue:work --once` inline loop) → `F-ELB-004` system certification → members seated, legislature `active`, CLK-01/CLK-10 armed, next approval phase open.
8. Prints: chamber URL, results URL, one receipt + verification instructions, `audit:verify` result.
Demo voters remain impersonatable via the Phase A dev bar for manual UI walking.
Verification: command green on Montegiardino (at-large) **and** San Marino (districted bicameral type_a + at-large type_b); `audit:verify` green after both; running twice is idempotent-safe (second run refuses on an `approval_open` election unless `--again`).

### WI-B10 — Test gate 〔M〕
Un-skip/satisfy: `StvDroopGregoryTest`, `CountbackUniversalTest`, `TermLockstepTest`; new `BallotSecrecyTest`, `ApprovalSecrecyTest`, `FinalistCutoffTest` (X=3×seats frozen at publication), `ElectionClockTest` (no-skip: no code path mutates CLK-01 `fires_at`; out-of-window special rejected). CI: constitutional suite remains the merge gate.

---

**Deferred (explicit, with reasons)**: Earth `type_b` race structure (needs operator constitutional ruling — §B.4); manual district drawing UI for F-ELB-003 (needs the raster tool; auto-composite covers B); F-ELB-005 / F-IND-008 / F-LEG-036 handlers (Phase C consumers; their schema lands now); production ballot crypto upgrades (cryptographer review list in §B.5); proper election boards via F-LEG-012 + WF-ELE-10 bootstrap retirement (Phase C — needs chamber votes); oath F-LEG-001 `elected→seated` transition (Phase C; B's certification seats with status `elected`).

**Key files referenced**: `E:\fair-constitution-app\.claude\worktrees\practical-payne-17d537\app\Services\ClockService.php` (HANDLERS + fire dispatch change), `app\Services\ActivationService.php` (step 3.5 + leaf clamp), `app\Services\ConstitutionalValidator.php` (new rules — PROTECTED), `app\Domain\Engine\ConstitutionalEngine.php` (unchanged — handlers plug in), `app\Http\Controllers\LegislatureController.php` (`runAutoCompositeForScope` → `app\Services\DistrictingService.php`), `config\horizon.php` (`long-running` queue), `database\migrations\2026_01_01_000005/000006/000008` + `2026_05_22_000002` (the evolved skeletons).