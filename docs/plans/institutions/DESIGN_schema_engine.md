All inputs read and the current schema verified. Here is the design.

---

# CGA MASTER PLAN — SECTION: DATA ARCHITECTURE + CONSTITUTIONAL ENGINE

Scope: full target schema for all 17 institutions (I-JUR…I-CGC), the Constitutional Engine, the 21-clock scheduler, the activation engine, and the audit chain. Phase letters A–F follow the operator roadmap (A = identity/foundation, B = elections, C = legislature ops, D = executive + organizations, E = judiciary + law, F = federation/topology). Phase A is implementation-ready; B–F are target-schema complete. ESM-01…ESM-20 cite the 20 entity state machines in DATA-MODEL_REGISTRY.md §C (order as listed there). Clock and form IDs cite the registry (CLK-01…21, F-xxx canonical with alias mapping).

Global conventions (unchanged from CLAUDE.md): UUID PKs with `gen_random_uuid()` DB default, `timestamptz` UTC, snake_case, string enums + CHECK constraints (app-layer is authoritative, CHECK is belt-and-suspenders), soft deletes on mutable entities (never on append-only tables), `geom` SRID 4326, federation columns where rows can be mirrored.

---

## A) MIGRATION SET

### A.0 Cross-cutting decisions (justifications)

| Decision | Choice | Why |
|---|---|---|
| **users → UUID** | **Drop and recreate** (`users`, `sessions`, `password_reset_tokens`); new migration, not an edit of `0001_01_01_000000` | Table is empty and zero auth is wired; converting bigint→uuid in place buys nothing. New migration (not editing the stock file) preserves migration history on the live DB holding ~951k jurisdictions — **no `migrate:fresh` required**. `location_pings` + `residency_confirmations` (both empty, FK'd to bigint users) are dropped in the same migration and recreated in their evolved forms. |
| **residency_confirmations** | **Drop; replaced by `residency_claims` + `jurisdiction_associations`** | The existing table conflates two entities: the *claim* (ESM-02 has 7 states; the table has only booleans) and the *association* (one row per enclosing level, derived). It also stores `voting_right_active`/`candidacy_right_active` flags — storing rights as toggleable booleans is exactly the drift Art. I forbids; rights must be a pure function of an active association. Empty table → free to replace. |
| **location_pings** | **Reuse name/shape, recreate** with uuid `user_id`, `claim_id` FK, qualifying-day evaluation columns, purge policy | The PostGIS point + trigger design is correct; only the user FK type and claim linkage are missing. |
| **Roles: derived, not stored** | No `user_roles` table. `RoleResolver` service + Postgres view `v_user_roles` for reporting | R-01…R-04 are *pure functions* of facts (account exists; claim verified; associations active; nothing else — Art. I absolute rights). Office roles R-06…R-30 already have authoritative rows (candidacies, legislature_members, judicial_seats, board_seats, appointments…) — a stored grants table would be a second source of truth that can disagree with the seat rows. Role *transitions* are recorded as audit_log/public_records events for the historical record, but the log is never read to answer "what can this user do now." |
| **constitutional_settings** | **Keep 1:1**, evolve | The per-jurisdiction unified model makes the 1:1 design exactly right: every activated jurisdiction owns a row. Resolution changes from "fall back to planet row" to **walk the parent chain** (own row → nearest ancestor row → code defaults). Activation copies the nearest ancestor's row as the founding values (then amendable by acts). |
| **Forms catalog** | Code registry (`config/constitution/forms.php`), not a DB table | The 103 forms + alias map (F-CHR←F-COM, F-BOG←F-GOV, F-IND-016←F-IND-013, F-LEG-036←F-LEG-030, etc.) are constitutional artifacts versioned with code, like the hardened rules. `audit_log.form_id` always stores the canonical ID; the registry resolves aliases on the way in. Org "document packages" (self-managed, above the constitutional floor) are data and get their own table (Phase D). |
| **Boards unified** | One `boards` + `board_seats` pair shared by executive departments, CGCs, and private organizations | Art. III §6 co-determination applies *identically* to all three (registry WF-ORG-04; co-determination.html "applies-equally table"). One table set = one co-determination engine, one joint-chair rule, one validity check ("board valid only when composition matches the scale"). |
| **Org/board elections reuse `elections`** | `elections.kind` gains `org_board_owner` / `org_board_worker`; chair votes use chamber-vote RCV machinery | Same hardened STV/Droop+Gregory engine, same ballot tables; only the electorate differs (`races.electorate_type`). Duplicating tabulation tables for orgs would fork the protected counting code path. |
| **Ballot secrecy** | Two tables with **no linking column**: `ballot_envelopes` (voter participation, double-vote prevention) and `ballots` (anonymous encrypted content + published hash) | Commitment scheme per Architecture Plan C.5 / audit-chain.html: the receipt hash is returned to the voter and **never stored alongside their identity**; published `ballot_hash` list enables self-audit; envelope proves *that* a voter voted. `cast_bucket` stores hour-truncated time to kill timing correlation. |
| **Laws ≠ bills** | `bills` (ESM-07 proposal lifecycle) → enactment creates `laws` + `law_versions` v1; Art. IV §5 judicial remedies append `law_versions` with `source='judicial_remedy'` | The mockups demand git-style law versioning (owner backlog item D; constitutional-challenge.html law-diff). Settings changes, referendum origin (CLK-19 shield), and case-law commentary all hang off `laws`. |

### A.1 PHASE A migrations (implementation-ready)

All files `database/migrations/2026_06_xx_0000NN_*.php`, ordered:

#### A-1 `recreate_identity_tables` — drops `residency_confirmations`, `location_pings`, `sessions`, `users`, `password_reset_tokens`; recreates:

**`users`** — implements ESM-01 *Individual* (account-side states only; residency states are derived from claims)

| column | type | null | default |
|---|---|---|---|
| id | uuid PK | no | gen_random_uuid() |
| name | varchar | no | — |
| display_name | varchar | yes | — |
| email | varchar UNIQUE | no | — |
| email_verified_at | timestamptz | yes | — |
| password | varchar | no | — |
| status | varchar(24) | no | 'registered' |
| identity_verified_at | timestamptz | yes | — |
| identity_verified_via | varchar(16) | yes | — (`bridge` \| `attestation`; **document data never stored**) |
| terms_accepted_at | timestamptz | no | — |
| languages | jsonb | no | '["en"]' |
| timezone | varchar | no | 'UTC' |
| locale | varchar(12) | no | 'en' |
| comm_prefs | jsonb | no | '{}' |
| home_server_id | uuid | yes | — (NULL = this instance authoritative for this individual — federation-first) |
| remember_token | varchar(100) | yes | — |
| created_at / updated_at / deleted_at | timestamptz | | |

CHECK `status IN ('registered','identity_verified','deceased','closed')`. Index: `status`, `home_server_id`.
`sessions` recreated stock with `user_id uuid` FK. Session auth (Inertia monolith) now; Sanctum added later for mobile without schema change.

**`residency_claims`** — implements ESM-02 *Residency Claim*

| column | type | null | default |
|---|---|---|---|
| id | uuid PK | no | gen_random_uuid() |
| user_id | uuid FK users cascade | no | — |
| jurisdiction_id | uuid FK jurisdictions restrict | no | — (smallest declared boundary) |
| status | varchar(24) | no | 'declared' |
| declared_at | timestamptz | no | — |
| ping_consent_at | timestamptz | no | — (F-IND-003 requires consent; reject without) |
| qualifying_days_count | smallint | no | 0 (denormalized; recomputed by evaluator) |
| threshold_days_at_verification | smallint | yes | — (snapshot of `residency_confirmation_days` when met) |
| threshold_met_at / verified_at / superseded_at / lapsed_at | timestamptz | yes | — |
| confirmation_action | varchar(24) | yes | — (`confirmed` \| `boundary_corrected`) |
| superseded_by_claim_id | uuid FK self nullOnDelete | yes | — |
| created_at / updated_at / deleted_at | timestamptz | | |

CHECK `status IN ('declared','ping_monitoring','threshold_met','verified','active','superseded','lapsed')`.
**Partial unique**: `UNIQUE (user_id) WHERE status = 'active'` (one active residency per person; relocation keeps old claim Active until new claim Verifies — zero rights gap, WF-CIV-03).
Index: `(user_id, status)`, `jurisdiction_id`.

**`location_pings`** — recreated (private; never exposed)

Columns as today plus: `user_id uuid` FK; `claim_id uuid` FK residency_claims nullOnDelete; `source varchar(16) default 'manual'` CHECK in (`mobile`,`web`,`manual`,`simulated`) — `manual`/`simulated` cover Phase A's dev/simulated pinging; `is_qualifying boolean null` + `evaluated_at timestamptz null` (set by the CLK-05 evaluator: inside claim boundary that day). Same geom trigger. **Retention rule (code, not schema): on claim verification, raw pings for that claim are deleted; only `qualifying_days_count` and the audit entry survive** ("pings encrypted at rest, raw locations purge on verification" — civic/residency.html contract).

**`jurisdiction_associations`** — the derived R-03 substrate; one row per enclosing level

| column | type | null | default |
|---|---|---|---|
| id | uuid PK | no | gen_random_uuid() |
| user_id | uuid FK users cascade | no | — |
| jurisdiction_id | uuid FK jurisdictions cascade | no | — |
| residency_claim_id | uuid FK residency_claims cascade | no | — (provenance) |
| depth | smallint | no | — (0 = declared jurisdiction, +1 per ancestor toward root) |
| established_at | timestamptz | no | — |
| ended_at | timestamptz | yes | — |
| end_reason | varchar(24) | yes | — (`superseded`,`lapsed`,`boundary_change`,`closed`) |
| created_at / updated_at | timestamptz | | |

No rights booleans — **the active row IS the right** (voting + candidacy unlock atomically at R-03; nothing else may gate them). No soft deletes; history via `ended_at`.
**Partial unique** `(user_id, jurisdiction_id) WHERE ended_at IS NULL`. Index: `(jurisdiction_id) WHERE ended_at IS NULL` (population counting), `(user_id) WHERE ended_at IS NULL`.

#### A-2 `create_audit_log` — see §E for full design. Phase A.

#### A-3 `create_clocks` — see §C for full design. Phase A.

#### A-4 `jurisdiction_activation_columns`

- `jurisdictions` add: `lifecycle_status varchar(24) NOT NULL DEFAULT 'dormant'` CHECK in (`dormant`,`critical_population`,`bootstrapping`,`self_governing`,`subdivided`,`in_union`,`intermediary`,`disintermediated`,`restoration`) — implements **ESM-19 Jurisdiction**; `verified_resident_count bigint NOT NULL DEFAULT 0` (counter cache maintained by association writes); `civic_activated_at timestamptz NULL`. Backfill: rows where `is_civic_active = true` → `self_governing` (Earth today). `is_civic_active` retained as a cheap query flag, kept in sync by the activation engine (existing controllers/ETL already read it — no churn).
- `constitutional_settings` add: `critical_population_threshold integer NULL` (NULL = inherit; CLK-06 per-jurisdiction override), `finalist_multiplier smallint NOT NULL DEFAULT 3` (CLK-21, X = multiplier × seats; read in Phase B).
- `instance_settings` add: `critical_population_defaults jsonb NOT NULL DEFAULT '{}'` (per-adm-level tier defaults, e.g. `{"0":50000,"1":5000,"2":1000,"3":500,"4":200,"5":100}` — operator-tunable), `clock_scheduler_enabled boolean NOT NULL DEFAULT true`.

#### A-5 `create_public_records` — append-only public record (WF-SYS-03 skeleton; full pipeline Phase C)

`seq bigint generated always as identity` (PK), `id uuid unique default gen_random_uuid()`, `kind varchar(24)` CHECK in (`registration`,`residency`,`participation`,`statement`,`vote`,`act`,`opinion`,`certification`,`correction`,`other`), `title varchar`, `body text null`, `actor_user_id uuid null` (no FK — immutability over cascade; see §E), `actor_display varchar null` (denormalized snapshot), `jurisdiction_id uuid null`, `via_form varchar(16) null`, `via_workflow varchar(16) null`, `via_clock varchar(8) null`, `subject_type varchar null` + `subject_id uuid null`, `audit_seq bigint null` (link to the sealing audit entry), `translations jsonb default '{}'` (locale → `{text, quality: machine|human}`), `supersedes_record_id uuid null` (corrections append, never edit), `published_at timestamptz`, `created_at`. Append-only trigger (same as audit_log). **Never contains ballot content or raw locations** — enforced in AuditService/RecordService API, not by callers.

#### A-6 `create_role_view` — `v_user_roles` SQL view deriving R-01..R-04 (and later office roles by UNION over seat tables as phases land). Convenience for queries/admin; `RoleResolver` (PHP, request-cached) is the runtime authority.

### A.2 PHASE B — Elections engine (target schema)

**`election_boards`** (I-ELB): `id`, `jurisdiction_id` FK, `legislature_id uuid null` FK (creating legislature; NULL for bootstrap), `created_by_act_id uuid null` (→ laws, Phase C), `is_bootstrap boolean default false` (system acts as board — bootstrap step 3, flagged "temporary · replacement queued"), `status` CHECK (`forming`,`active`,`retired`), `retired_at`, timestamps+soft deletes. Unique partial `(jurisdiction_id) WHERE status='active' AND deleted_at IS NULL`.
**`election_board_members`**: `id`, `election_board_id` FK, `user_id` FK, `appointment_id uuid null` (→ appointments), `status` (`nominated`,`seated`,`removed`,`term_ended`), term dates, timestamps+sd.

**`elections`** (I-ELE) — **evolve existing table** (referenced by endorsements; keep). Implements **ESM-03 Election**. Migration renames/adds:
- `status` recut to ESM-03: CHECK (`scheduled`,`approval_open`,`finalist_cutoff`,`ranked_open`,`voting_closed`,`tabulating`,`certified`,`recount`,`final`,`cancelled`); drop `nomination_*` columns; add `approval_opens_at`, `finalist_cutoff_at`, `ranked_opens_at`, `ranked_closes_at` (all timestamptz; CLK-18 window), `certified_at`.
- `kind` (rename `type`): CHECK (`general`,`special`,`executive`,`judicial`,`referendum`,`org_board_owner`,`org_board_worker`,`restoration`). Speaker/chair votes are **chamber votes**, not population elections — removed from this enum.
- `legislature_id uuid null` FK (the body being filled, for general/special), `executive_id` / `judiciary_id` / `board_id` uuid null (office being filled), `election_board_id` FK now real, `triggered_by_clock_id uuid null` FK clocks, `prior_election_id uuid null` self-FK (cycle chain: certification of N opens approval of N+1).
- Drop per-race columns (`seats_to_fill`, `droop_quota`, `district_id`, referendum fields move to `referendum_questions`/races).

**`election_races`** — one row per district/race (an Earth general election = 274 races): `id`, `election_id` FK cascade, `district_id uuid null` FK legislature_districts, `jurisdiction_id` FK (race footprint), `seat_kind` CHECK (`type_a`,`type_b`,`single`) — bicameral races carry kind, `seats smallint` CHECK 1–9 (5–9 for type_a chamber races; 1 for type_b/individual-exec), `finalist_count smallint` (X pre-published **before** cutoff, CLK-21), `electorate_type` CHECK (`residents`,`owners`,`workers`) default `residents`, `quota integer null` (Droop snapshot post-close), `total_valid_ballots integer null`, `status` mirrors parent. Unique `(election_id, district_id, seat_kind)`.

**`candidacies`** — ESM-06 *Candidacy*: `id`, `election_id` FK, `race_id uuid null` FK (bound at validation from residency district), `user_id` FK, `status` CHECK (`registered`,`validated`,`rejected`,`in_pool`,`finalist`,`non_finalist`,`withdrawn`,`elected`,`defeated`) , `platform_statement text`, `position_tags jsonb default '[]'`, `validated_at`, `validated_by uuid null` (board member), `rejection_reason text null` (**residency is the only permissible ground — engine-enforced**, F-ELB-002), `withdrawn_at null` (engine blocks after `finalist_cutoff_at` = ballot lock), timestamps+sd. Unique `(election_id, user_id)`. `endorsements.candidate_id` FK added now → `candidacies.id`.

**`approvals`** — secret individual approvals (open-ballot engine, WF-CIV-08): `id`, `election_id`, `candidacy_id` FK cascade, `user_id` FK cascade, `created_at`, `revoked_at null`. Partial unique `(candidacy_id, user_id) WHERE revoked_at IS NULL`. Row-level access: never readable except by owner; aggregates only.
**`approval_standings`** — ESM-04 *Approval Standing* (public daily aggregates): `id`, `race_id`, `candidacy_id`, `as_of_date date`, `approvals_count int`, `rank smallint`, `delta int`, `frozen boolean default false` (cutoff snapshot archived to public record). Unique `(candidacy_id, as_of_date)`.

**`ballot_envelopes`** — participation record: `id`, `race_id` FK, `user_id` FK, `kind` CHECK (`ranked`,`referendum`), `referendum_question_id uuid null`, `committed_at timestamptz`. Unique `(race_id, user_id, kind, referendum_question_id)`. **No content, no hash.**
**`ballots`** — ESM-05 *Ballot (Ranked)* (anonymous): `id uuid` (random), `race_id` FK, `kind` CHECK (`ranked`,`referendum`), `referendum_question_id uuid null`, `payload_encrypted text` (encrypted rankings incl. write-in candidacy ids; write-ins tabulated identically), `ballot_hash char(64) UNIQUE` (published for self-audit), `cast_bucket timestamptz` (**hour-truncated**), `counted boolean default false`. No user linkage anywhere. Insert path is a separate code unit audited by the constitutional test suite.

**`tabulations`**: `id`, `race_id` FK, `kind` CHECK (`initial`,`audit_rerun`,`countback`), `excluded_candidacy_id uuid null` (countback strike), `engine_version varchar`, `total_valid int`, `quota int`, `seats smallint`, `status` (`running`,`complete`,`superseded`), `started_at`, `completed_at`, `record_hash char(64)` (hash of full round record → audit chain). 
**`tabulation_rounds`**: `id`, `tabulation_id` FK cascade, `round_no smallint`, `action` CHECK (`elect`,`eliminate`), `candidacy_id`, `transfer jsonb` (`{kind: surplus|elimination, value, to: [[candidacy_id, votes]], exhausted}` — Gregory fractional), `tallies jsonb`, `created_at`. Unique `(tabulation_id, round_no)`.
**`race_results`**: `id`, `tabulation_id`, `candidacy_id`, `round_elected smallint`, `seat_no smallint`, `is_runner_up boolean default false`, `runner_up_rank smallint null` (1–4 sequential-exclusion advisors for individual exec).

**`election_certifications`**: `id`, `election_id` FK, `election_board_id`, `certified_by uuid` (member), `certified_at`, `count_record_hash char(64)`, `status` (`certified`,`superseded_by_audit`). Certification side-effects (seat winners, open next approval phase, convert referendum acts to ordinary law) run in the engine, audit-chained.
**`election_audits`** (recount = audit re-run, never hand count): `id`, `election_id`, `race_id null`, `cause text NOT NULL` (required), `ordered_by`, `ordered_at`, `tabulation_id` (the re-run), `outcome` CHECK (`reaffirmed`,`corrected`), `resolved_at`. Engine gate: only after certification (F-ELB-006).

**`vacancies`** — ESM-13 *Vacancy*: `id`, `seat_type varchar` + `seat_id uuid` (polymorphic: legislature_members | executive_members | judicial_seats), `jurisdiction_id`, `declared_by uuid null`, `declared_via_form varchar` (F-LEG-036), `status` CHECK (`detected`,`declared`,`countback_running`,`filled`,`countback_failed`,`special_election_scheduled`), `detected_at`, `declared_at`, `countback_tabulation_id uuid null`, `special_election_id uuid null`, `filled_by_user_id uuid null`, `filled_at`, timestamps. CLK-04 clock row armed on `countback_failed`; engine rejects special-election dates outside [vacancy+90d, vacancy+180d].

**`terms`** — the term registry (WF-SYS-01, CLK-10 lockstep): `id`, `office_kind` CHECK (`legislature_seat`,`executive_seat`,`judicial_seat`,`board_governor`,`election_board_member`,`admin_staff`,`civil_officer`), `office_type` + `office_id` (polymorphic to the seat row), `holder_user_id`, `jurisdiction_id`, `legislature_id uuid null` (lockstep anchor for elected terms), `term_class` CHECK (`lockstep`,`civil_appointment`), `starts_on date`, `ends_on date`, `source_election_id uuid null`, `source_appointment_id uuid null`, `status` CHECK (`active`,`completed`,`vacated`,`removed`), timestamps. **No update API for `ends_on` on lockstep terms** (hardened — elections cannot be skipped/delayed); civil terms = 10y from `civil_appointment_years`.

**`appointments`** — generic civil-appointment pipeline (R-08 board members now; R-18/R-29/R-30 later): `id`, `appointable_type`+`appointable_id` (election_board, department board seat, admin office…), `nominee_user_id`, `nominated_by uuid null`, `nominated_via_form varchar` (F-EXE-001, F-LEG-021…), `consent_vote_id uuid null` (→ chamber_votes, Phase C; nullable in B for bootstrap), `status` CHECK (`nominated`,`consented`,`rejected`,`seated`,`ended`), `term_id uuid null`, timestamps+sd.

**`legislature_members` evolve**: add `user_id uuid` FK, `seat_no smallint`, `seat_kind` CHECK (`type_a`,`type_b`), `district_id uuid null`, `elected_in_race_id uuid null`, `term_id uuid null`, `vote_share_norm numeric(8,4) null` (normalized-quota share — committee tie-break, q-ledger #2), `seated_at timestamptz null` (oath F-LEG-001), `status` CHECK (`elected`,`seated`,`vacated`,`removed`,`term_ended`).

### A.3 PHASE C — Legislature operations

**`legislature_sessions`**: `id`, `legislature_id` FK, `session_no int`, `called_by uuid` (speaker, F-SPK-001), `scheduled_for timestamptz`, `opened_at`, `adjourned_at`, `quorum_required smallint` (snapshot = floor(serving/2)+1), `serving_at_open smallint` (snapshot), `quorum_met boolean null` (F-SPK-003), `agenda jsonb` (ordered; positions 1–2 locked: emergency powers, constitutional matters — F-SPK-002), `minutes_record_id uuid null` (→ public_records, F-SPK-009), `status` (`scheduled`,`open`,`adjourned`,`failed_quorum`), timestamps. Adjournment resets CLK-02 (`legislatures.last_met_on`, `next_meeting_due_by`).
**`session_attendance`**: `session_id` FK, `member_id` FK, `status` CHECK (`present`,`absent`,`compelled`,`excused`), `recorded_at` (F-LEG-002, F-SPK-008). Unique `(session_id, member_id)`.

**`motions`** — ESM-08: `id`, `session_id` FK, `moved_by member_id`, `text`, `kind` (`procedural`,`referral`,`amendment`,`replace_speaker`,`other`), `status` CHECK (`submitted`,`recognized`,`debated`,`voted`,`adopted`,`failed`), `vote_id uuid null`, timestamps.

**`chamber_votes`** — the universal in-chamber decision record (covers the 33 special vote types' legislative classes): `id`, `legislature_id` (or `committee_id` / `board_id` — `body_type`+`body_id` polymorphic), `votable_type`+`votable_id` (bill, motion, appointment, removal_proceeding, override, emergency_power, referendum delegation, act…), `vote_method` CHECK (`yes_no`,`rcv`), `threshold_class` CHECK (`majority`,`supermajority`,`committee_majority`,`bicameral_majority`,`bicameral_supermajority`,`board_majority`), `serving_snapshot smallint` (denominator = ALL serving — peg quorum, hardened), `serving_by_kind jsonb null` (`{type_a: n, type_b: n}`), `required_yes smallint` (engine-computed snapshot: `ceil(serving*2/3)` etc.), `tallies jsonb` (incl. per-kind for bicameral dual agreement), `outcome` CHECK (`adopted`,`failed`,`tied_broken`), `speaker_tiebreak boolean default false` (F-SPK-004), `held_in_session_id uuid null`, `decided_at`, timestamps.
**`vote_casts`**: `vote_id` FK cascade, `member_id`, `value` CHECK (`yes`,`no`,`abstain`) , `rankings jsonb null` (RCV: speaker/chair elections), `explanation text null` (published — Art. II §2), `cast_at`. Unique `(vote_id, member_id)`. Chamber votes are public by constitutional mandate (unlike ballots).

**`multi_jurisdiction_votes`** — dual-supermajority processes (F-LEG-015/018/028, union/exit, amendments): `id`, `kind`, `subject_type`+`subject_id`, `initiating_legislature_id`, `constituent_total smallint`, `required smallint` (`ceil(n*2/3)`), `status` (`open`,`passed`,`failed`,`expired`), `closes_at null`, timestamps. **`constituent_consents`**: `process_id` FK, `jurisdiction_id`, `chamber_vote_id uuid null` (that constituent legislature's own vote), `result` (`pending`,`yes`,`no`). Unique `(process_id, jurisdiction_id)`.

**`bills`** — ESM-07: `id`, `legislature_id` FK, `sponsor_member_id`, `title`, `act_type` CHECK (`ordinary`,`setting_change`,`supermajority`,`dual_supermajority`) (sets threshold class), `scale jsonb` (jurisdiction ids bound — ≤ legislature's authority, engine-validated), `scope_judiciary_id uuid null` (which judiciary hears disputes), `targets_setting_key varchar null` + `proposed_value jsonb null` (F-LEG-031; **bounds validated pre-vote**), `status` CHECK (`introduced`,`referred`,`in_committee`,`reported`,`tabled`,`on_floor`,`passed`,`failed`,`enacted`,`withdrawn`), `committee_id uuid null`, `introduced_at`, `enacted_law_id uuid null`, timestamps+sd. **`bill_versions`**: `bill_id` FK, `version_no`, `law_text text`, `changed_by member_id`, `change_kind` (`introduction`,`committee_amendment`,`floor_amendment`), `created_at`. Unique `(bill_id, version_no)`.

**`laws`** + **`law_versions`** — the statute book: laws: `id`, `jurisdiction_id` (scale anchor), `legislature_id`, `act_number varchar` (e.g. "Act 2031-07", unique per legislature+term), `title`, `kind` CHECK (`ordinary`,`setting_change`,`rules_of_order`,`ethics_code`,`charter`,`creation_act`,`referendum_act`,`constitutional_article`), `scale jsonb`, `scope_judiciary_id uuid null`, `origin` CHECK (`bill`,`referendum`,`petition_initiative`,`judicial_remedy`,`founding`), `referendum_passed_by_supermajority boolean null` (CLK-19 shield input), `shield_expires_with_election_id uuid null` (referendum-act protection lapses at next general — hardened), `status` CHECK (`in_force`,`amended`,`repealed`,`superseded`,`struck`), `effective_at`, timestamps+sd. law_versions: `law_id` FK, `version_no`, `text`, `source` CHECK (`enactment`,`legislative_amendment`,`judicial_remedy`,`referendum_modification`,`merge_incorporation`), `source_ref_type`+`source_ref_id` (bill / challenge / disintermediation), `created_at`. `constitutional_settings.last_amended_by_act_id` FK now points here. **`setting_changes`**: `id`, `jurisdiction_id`, `setting_key`, `old_value jsonb`, `new_value jsonb`, `law_id` FK, `effective_at` — dependent clocks re-derive on insert.

**`committees`** (I-COM): `id`, `legislature_id`, `name`, `purpose`, `seats smallint`, `created_by_law_id` (F-LEG-009, supermajority), `chair_member_id uuid null`, `alternate_member_id uuid null` (R-12/R-13 via whole-house RCV, F-LEG-011 → chamber_votes), `status` (`created`,`seated`,`dissolved`), timestamps+sd. **`committee_seats`** — ESM-09: `committee_id` FK, `member_id` FK, `status` CHECK (`allocated`,`assigned`,`tie_broken`,`seated`,`vacated`,`refilled`), `assigned_via` (`algorithm`,`tie_break`,`whole_house_rcv`), `seated_at`, `vacated_at`, timestamps. Unique partial `(committee_id, member_id) WHERE vacated_at IS NULL`. **`committee_preferences`**: `legislature_id`, `member_id`, `rankings jsonb` (ordered committee ids, F-LEG-010), `submitted_at`. Unique `(legislature_id, member_id)`. Assignment algorithm (F-SPK-005) is a service; tie-break = `vote_share_norm`.

**`admin_offices`** (I-ADM): `id`, `legislature_id`, `created_by_law_id`, `status` (`created`,`staffed`,`dissolved`), timestamps+sd. Staff via `appointments` (R-29). **`misconduct_investigations`**: `id`, `admin_office_id`, `subject_type`+`subject_id` (any officeholder), `complainant_user_id null`, `summary`, `status` CHECK (`intake`,`investigating`,`referred`,`closed_no_finding`), `findings_record_id uuid null`, timestamps. **`removal_proceedings`** (F-LEG-022/F-SPK-007): `id`, `legislature_id`, `subject_type`+`subject_id`, `kind` CHECK (`impeachment`,`censure`,`expulsion`,`judge_removal`,`executive_removal`), `presided_by uuid` (Speaker except own case — engine-checked), `vote_id uuid null`, `outcome` (`removed`,`censured`,`retained`), timestamps. Removal → vacancies row.

**`petitions`** — ESM-10: `id`, `creator_user_id` (R-05), `jurisdiction_id` (scale), `title`, `law_text text` (binding text), `scale jsonb`, `scope_judiciary_id`, `threshold_count int` (snapshot: round(pop × pct), CLK-17), `status` CHECK (`created`,`gathering`,`threshold_reached`,`signature_audit`,`constitutional_review`,`validated`,`on_ballot`,`adopted`,`rejected`,`invalidated`), `audit_result jsonb null` (F-ELB-005: valid count, pct), `review_case_id uuid null` (Phase E link, F-JDG-008), `ballot_election_id uuid null`, timestamps+sd. **`petition_signatures`**: `petition_id` FK cascade, `user_id` FK, `signed_at`, `revoked_at null`. Partial unique `(petition_id, user_id) WHERE revoked_at IS NULL`.

**`referendum_questions`** — ESM-11: `id`, `jurisdiction_id`, `origin` CHECK (`delegation`,`petition`), `delegating_law_id uuid null` (F-LEG-023), `petition_id uuid null`, `question text`, `law_text text`, `threshold` CHECK (`majority`,`supermajority`) (**derived from act type — never editable**), `election_id uuid null` (rides next jurisdiction-wide ballot), `status` CHECK (`queued`,`scheduled`,`voted`,`passed`,`failed`), `resulting_law_id uuid null`, timestamps.

**`emergency_powers`** — ESM-12: `id`, `legislature_id`, `jurisdiction_id`, `cause` CHECK (`natural_disaster`,`actual_invasion`) (**closed enum — anything else rejected pre-vote**), `label`, `declared_duration_days smallint` CHECK 1–90 (≤ `emergency_powers_max_days`, itself ≤ 90 hardened ceiling), `area_jurisdiction_id uuid` + `area_geom geometry(MULTIPOLYGON,4326) null` (≤ legislature's authority), `methods text`, `invoke_vote_id` FK (supermajority), `status` CHECK (`invoked`,`active`,`under_review`,`renewed`,`expired`,`struck`,`narrowed`), `starts_at`, `expires_at` (CLK-03 countdown clock row armed on activation; auto-expiry job — nothing rolls over silently), `judicial_review_case_id uuid null`, timestamps. **`emergency_power_renewals`**: `power_id` FK, `vote_id` FK, `extension_days` CHECK 1–90, `new_expires_at`. Hard rails (engine): cannot disrupt elections/sessions/courts/civic processes; first order of business in every session agenda.

### A.4 PHASE D — Executive + Organizations

**`executives` evolve** (keep stub table) — implements ESM-16 *Executive Office*: widen `status` CHECK to (`forming`,`delegated`,`conversion_voted`,`elected`,`modified`,`dissolved`,`reverted`); add `delegation_law_id uuid null` (F-LEG-014), `conversion_process_id uuid null` (→ multi_jurisdiction_votes, F-LEG-015), `delegated_scope text null`, `converted_at`. `executive_members` evolve: FK `user_id`→users now real; add `legislature_member_id uuid null` (delegated committee members remain seated legislators), `elected_in_race_id uuid null`, `term_id uuid null`; rank 0–4 CHECK already present (R-17 advisors by sequential exclusion).

**`departments`** (I-DEP): `id`, `jurisdiction_id`, `executive_id` FK (oversight assignment), `kind` CHECK (`chief_executive`,`treasury`,`defense`,`state`,`justice`,`other`), `name`, `charter_law_id` FK (F-LEG-016), `reporting_interval_months smallint`, `worker_count int default 0`, `status` — implements **ESM-17 Department/Board** CHECK (`chartered`,`oversight_assigned`,`governors_nominated`,`consented`,`operating`,`reporting`,`rechartered`,`dissolved`), timestamps+sd.

**`boards`** — unified governance boards: `id`, `boardable_type`+`boardable_id` (departments | organizations), `owner_seats smallint` (governors / owner-elected), `worker_seats smallint default 0` (co-determination engine output: `w<100 ? 0 : max(1, min(owner, round((w−100)/1900×owner)))`), `chair_seat_id uuid null` (joint chair, elected by entire board), `composition_valid boolean default true` (recomputed on headcount change; invalid blocks board acts), timestamps+sd. **`board_seats`**: `board_id` FK, `seat_class` CHECK (`governor`,`owner_elected`,`worker_elected`), `holder_user_id null`, `appointment_id uuid null` (governors: F-EXE-001→F-LEG-020 consent, 10y CLK-09 terms) , `elected_in_race_id uuid null` (owner/worker STV tracks; **worker-elected terms end with the legislative term**, governors 10y — both via `term_id`), `term_id uuid null`, `is_chair boolean default false`, `status` (`vacant`,`nominated`,`seated`,`removal_requested`,`removed`,`term_ended`), timestamps+sd.

**`executive_orders`** (F-EXE-005): `id`, `executive_id`, `issued_by member`, `department_id null`, `title`, `body`, `scope_basis_law_id` (delegation act or emergency power: `enabling_type`+`enabling_id`), `status` CHECK (`drafted`,`scope_validated`,`issued`,`rejected_pre_issuance`,`reviewed`,`struck`), `rejection_citation varchar null` (**rejected attempts persist and publish** — public record), timestamps. **`policy_proposals`** (F-EXE-002): id, executive_id, department_id, board decision (`adopted`,`amended`,`declined`), text, timestamps. **`executive_investigations`** (F-EXE-004): id, executive_id, department_id, scope, records_access jsonb, findings_record_id, outcome CHECK (`policy_proposal`,`removal_request`,`legislative_referral`,`closed`), timestamps.

**`department_rules`** (F-BOG-001): `id`, `department_id`, `rule_code varchar`, `name`, `text`, `enabling_type`+`enabling_id` (law | emergency_power — **expires with the power**, CLK-03 cascade), `status` CHECK (`draft`,`in_force`,`superseded`,`expired`), `version_no`, timestamps. **`department_reports`** (F-BOG-002): id, department_id, kind (`periodic`,`special`), due_on, filed_at, recipients jsonb, record_id, status CHECK (`due`,`filed`,`overdue`).

**`appropriations`**: `id`, `law_id` FK (legislature appropriates by act), `jurisdiction_id`, `line varchar`, `amount numeric(18,2)`, `remaining numeric(18,2)`, timestamps. **`grant_applications`**: id, appropriation_id, applicant_org_id, amount, purpose, status (`submitted`,`awarded`,`declined`), decided_by executive_id. **`grant_disbursements`**: id, application_id, amount, disbursed_at — every award/disbursement audit-chained.

**`organizations` evolve** — implements ESM-18: add `structure` CHECK (`stock`,`partnership`,`equal_partnership`,`member_owned`,`worker_owned`,`nonprofit`) , `status` CHECK (`registered`,`active`,`transfer_pending`,`transferred`,`converted`,`dissolved`) (replaces `is_active`/`is_registered` booleans, kept for compat then dropped), `registered_by_user_id uuid` (R-23 agent), `board_id uuid null` FK, `worker_count` (rename `employee_count`), `created_by_law_id uuid null` (CGCs: F-LEG-019). `is_cgc`/`ip_is_public_domain`/`ownership_type` stay (already correct).
**`org_memberships`** (R-24): id, organization_id, user_id, kind CHECK (`member`,`shareholder`,`partner`), joined_at, ended_at, timestamps; partial unique active. **`org_workers`** (R-25, F-IND-014): id, organization_id, user_id, contract_id uuid null, started_at, ended_at — **headcount = active rows, drives CLK-13/14**; partial unique active. **`org_ownership_stakes`**: id, organization_id, holder_type+holder_id (user|organization|jurisdiction), units numeric, pct numeric(7,4), as_of, timestamps. **`org_contracts`**: id, organization_id, counterparty_type+id, terms text, kind (`labor_recurring`,`labor_single`,`commercial`,`other`), signed_by_a/b + signed_at_a/b (**co-sign required; engine rejects single-sided**), status, timestamps. **`org_document_packages`** + `_versions`: self-managed forms/policies above the constitutional floor (can never override constitutional forms — engine rule), versioned. **`org_transfers`** (F-ORG-005): id, from_org_id, to_party_type+id, consent_a/consent_b timestamps (mutual mandatory), status (`proposed`,`consented`,`completed`,`abandoned`), ffc_synced_at. **`org_conversions`**: id, organization_id, direction CHECK (`private_to_cgc`,`cgc_to_private`), via CHECK (`mutual`,`monopoly_acquisition`,`cgc_sale`), authorizing_law_id (F-LEG-026/027), compensation numeric null + fair_market_floor numeric null (**engine blocks compensation < floor**), board_transition jsonb (founding-governor offers), completed_at. **`cgc_ip_register`**: id, organization_id, asset, kind, published_at, status always `public_domain` (hardened, irreversible — no other value permitted).

### A.5 PHASE E — Judiciary & Law

**`judiciaries` evolve**: add `created_by_law_id` (F-LEG-017), `conversion_process_id uuid null` (F-LEG-018 dual supermajority), `status` widen (`forming`,`nominating`,`active`,`converting`,`elected`,`dissolved`). **`judicial_seats` evolve**: add `appointment_id uuid null` (appointed: constituent nomination + F-LEG-021 consent — via `appointments` with `nominating_jurisdiction_id` added there for equal-numbers rule), `elected_in_race_id uuid null`, `term_id uuid null`.

**`advocates`** (R-21, F-IND-015): id, user_id, judiciary_id, qualifications_attested jsonb, evidence text, status CHECK (`pending`,`granted`,`denied`,`revoked`), reviewed_by judicial_seat_id, granted_at, timestamps+sd. Partial unique (user, judiciary) active.

**`cases`** — ESM-14: `id`, `judiciary_id`, `kind` CHECK (`civil`,`criminal`,`administrative`,`constitutional_challenge`,`petition_review`,`emergency_review`), `filed_by_user_id`, `filed_via_form` (F-IND-016/017, F-ADV-001), `advocate_id uuid null`, `claimed_scale uuid` + `classified_scale uuid` (jurisdiction ids; claimed = input, classified = court's), `claimed_severity` + `classified_severity` CHECK (`minor`,`moderate`,`serious`,`major_constitutional`), `jury_entitled boolean default false` (criminal accusations), `double_jeopardy_blocked_by_case_id uuid null` (**machine-enforced at filing**: engine refuses criminal re-filing on same facts after verdict), `status` CHECK (`filed`,`validated`,`dismissed`,`panel_assigned`,`jury_empaneled`,`scheduled`,`hearing`,`deliberation`,`decided`,`opinion_published`,`appealed`,`closed`), `appealed_to_case_id uuid null`, timestamps+sd. **`case_parties`**: case_id, user_id/org_id, role CHECK (`plaintiff`,`defendant`,`prosecution`,`accused`,`amicus`).

**`case_panel_assignments`**: id, case_id, judicial_seat_id, screening jsonb (personal/financial/prior-involvement), result CHECK (`seated`,`recused`), assigned_at. Engine: ≥3, odd, severity-scaled, full court for major constitutional (CLK-16 hardened). **`juries`**: id, case_id, draw_seed char(64) (**published to audit chain**), pool_size int, drawn_at, status. **`jury_members`** (R-22): jury_id, user_id, role CHECK (`juror`,`alternate`), questionnaire jsonb, status CHECK (`summoned`,`screening`,`empaneled`,`excused`,`discharged`). **`case_filings`** (unifies F-ADV-002/003/004 + prosecution filings): id, case_id, kind CHECK (`motion`,`evidence`,`brief`), filed_by (advocate/party), exhibit_no, description/text, ruling CHECK (`pending`,`granted`,`denied`,`admitted`,`excluded`), ruling_reasons text (published), timestamps. **`verdicts`**: id, case_id, decision text, decided_at, panel_vote jsonb; **`sentencing_orders`** (F-JDG-009): id, case_id, verdict_id, text; **`warrants`** (F-JDG-010): id, case_id null, issued_by seat, reason text NOT NULL, max_hold_duration_days smallint NOT NULL (Art. II §8 — both required), subject_user_id, status (`issued`,`executed`,`expired`,`quashed`). **`opinions`** (F-JDG-003): id, case_id, author_seat_id, text, published_record_id; **`opinion_law_links`**: opinion_id, law_id — commentary only (only Art. IV §5 changes text).

**`constitutional_challenges`** — ESM-15: `id`, `case_id` FK, `law_id` FK, `status` CHECK (`filed`,`heard`,`finding_issued`,`window_open`,`amended_by_legislature`,`overridden`,`remedy_applied`,`closed`,`no_contradiction`), `finding text` (F-JDG-004), `remedy text` (F-JDG-005), `remedy_timeframe_days smallint` (CLK-12, per-case — clock row armed), `veto_window_days smallint` (CLK-11, per-case — clock row armed), `window_opened_at`, `override_vote_id uuid null` (F-LEG-035 supermajority), `remedy_law_version_id uuid null` (F-JDG-006 — judiciary edits law text directly; new `law_versions` row, `source='judicial_remedy'`), timestamps. Finding lands as **mandatory session-priority agenda item** (engine inserts into next session's locked slot 2).

### A.6 PHASE F — Federation & topology

**`federation_peers`** — ESM-20: id, host, public_key, status CHECK (`discovered`,`handshake`,`trust_established`,`syncing`,`conflict_resolution`,`border_settled`,`merged`,`departed`), last_heartbeat_at (CLK-20), timestamps. **`authority_claims`**: id, jurisdiction_id, claimed_by_peer_id null (NULL = us), resolution CHECK (`uncontested`,`recognized`,`negotiating`,`mirrored`), timestamps. **`sync_log`**: seq identity, peer_id, direction, payload_hash, result CHECK (`applied`,`conflict_authoritative_wins`,`rejected_tamper`,`rejected_non_authoritative`), audit_seq link, created_at (append-only). **`audit_checkpoints`**: seq, head_hash, published_to jsonb, created_at. **`partition_exports`**: id, jurisdiction_id, manifest jsonb, checksum, signed_by, status, authority_flipped_at.

**`jurisdiction_maps`** + **`jurisdiction_map_members`** — boundary versioning (the forward-looking table from CLAUDE.md; mirrors `legislature_district_maps`): maps: id, parent_jurisdiction_id, version_no, status CHECK (`draft`,`active`,`archived`), activated_at, created_by (union/border/disintermediation process ref); members: map_id, jurisdiction_id, geom snapshot ref. Border settlements re-run point-in-polygon association for affected residents.

**`union_processes`** (F-LEG-029): id, kind CHECK (`formation`,`join`,`exit`), applicant_jurisdiction_ids jsonb, union_jurisdiction_id null, compatibility_diff jsonb, codified_variables jsonb (founding act inputs), applicant_referendum_election_ids jsonb (population supermajority — denominator = whole population), constituent_process_id (→ multi_jurisdiction_votes), status, resulting_jurisdiction_id null. **`disintermediation_processes`** (F-LEG-030): id, intermediary_jurisdiction_id, constituent_consents (unanimity — reuse constituent_consents with `required = total`), encompassing_consent_law_id, status; **`law_merge_resolutions`**: process_id, law_id, decision CHECK (`incorporate`,`defer`,`lapse`), resolved_by — open Art. IV §5 challenges travel with merged law. **`border_settlements`**: id, jurisdiction_a/b, proposed_geom, affected_population int, referendum_election_id (supermajority of affected population), status. **`restoration_events`**: id, jurisdiction_id, condition CHECK (`countermanded`,`captured`,`destroyed`), evidence jsonb, tier smallint CHECK 1–3, review_case_id, status. **`cultural_institutions`** (F-LEG-028): id, jurisdiction_id, name, recognition_process_id, status — no legislative/executive/judicial powers (engine: never a `body_id`). **`user_relationships`** (Proposed social layer): id, user_a/b, kind, status (`pending`,`confirmed`) — never affects rights.

---

## B) CONSTITUTIONAL ENGINE

**Shape.** New namespace `App\Constitution\`:

- `ConstitutionalEngine` (service, singleton) — the only entry point for state-changing civic actions.
- `ConstitutionalAction` (DTO): `form` (canonical Form enum, alias-resolved), `actor` (User|null=system), `jurisdictionId`, `subject` (model|null), `payload` (array). Constructed by controllers (from FormRequests) and by jobs/clock handlers identically — **one validation path for HTTP, queue, and scheduler**.
- `Verdict`: `allowed()` / `denied(citation, reason)`.
- Rule classes in `App\Constitution\Rules\` implementing `Rule { supports(ConstitutionalAction): bool; check(ConstitutionalAction, Context): ?Violation; }`. Registered per form ID plus a set of global invariants (peg-quorum denominators, term-lockstep no-skip, civic-process protection under emergency powers, rights-never-gated).

**API used by controllers/jobs:**

```php
$verdict = $engine->validate($action);                    // pure check (pre-vote validators, UI pre-flight)
$result  = $engine->execute($action, fn () => ...);       // validate → DB transaction → mutation
                                                          //   → AuditService::append (same tx) → commit
// denial path: throws ConstitutionalViolation (HTTP 422 w/ citation)
//   AND appends a rejected=true audit row — rejections are part of the record
```

**Middleware.** `ValidatesConstitutionally` HTTP middleware on every state-changing route: resolves route → form ID (route metadata `->constitutionalForm(Form::F_LEG_024)`), builds the action skeleton, runs `validate()` *before* the controller, attaches the verdict to the request. Controllers still call `execute()` for the transactional path (middleware pre-check is UX; `execute()` is the enforcement that cannot be bypassed because models' civic mutations go through engine-owned services only). Jobs and clock handlers call `execute()` directly — middleware is not the security boundary, the service is.

**Hardened vs amendable.**
- **Hardened rules live in code**: `App\Constitution\Hardened` (constants + closures) and the rule classes — STV/Droop+Gregory, `supermajority(serving) = max(ceil(serving × num/den), majority(serving)+1)`, `quorum(serving) = floor(serving/2)+1`, bicameral dual agreement per kind, 5–9 band, 90-day emergency ceiling, proportionality ratchet whitelist for `voting_method`, CLK-10/16/19 non-amendable behavior, residency-is-only-candidacy-check, rights-unlock-at-R-03. Protected by the constitutional test suite (CI-gated; the WF-SYS-05 "second door").
- **`ConstitutionalDefaults` is kept** (it is on the protected list) and **extended, not replaced**: it becomes the facade over a new `SettingsResolver` whose `resolve()` walks the parent chain (own row → nearest ancestor with a row → code defaults) instead of the current planet-row fallback. All existing call sites (`floor()`, `ceiling()`, `sizingLaw()`, `sizeFromPopulation()`, `giantThreshold()`) keep working unchanged.
- **Amendable bounds registry**: `config/constitution/hardened_bounds.php` — `setting_key => [min, max | validator, basis]` (e.g. `legislature_max_seats => [5, 9, 'Art. II §2']`, `supermajority` fraction validated against majority+1 floor, `voting_method` against the more-proportional whitelist). `F-LEG-031` bills are validated against this registry **pre-vote** (bills.targets_setting_key path) and again at enactment; out-of-range values are rejected with citation and recorded.

**Per-jurisdiction settings resolution**: keep `constitutional_settings` 1:1. Activation copies the nearest ancestor's row (founding values); thereafter only `setting_changes` rows (linked to enacting laws, with effective dates) mutate it, through the engine. Request-cached per jurisdiction; cache invalidated on `setting_changes` insert; dependent clocks re-derive (see §C).

**Violations in audit_log**: every denial appends `{rejected: true, blocked_reason, citation, form_id, payload}` — matching audit-chain.html's contract that rejections are first-class chain entries (e.g. `legislature_max_seats 9→12 blocked: exceeds hardened ceiling, Art. II §2`).

---

## C) CLOCK SCHEDULER

**`clocks` table (Phase A):**

| column | type | notes |
|---|---|---|
| id | uuid PK | |
| clock_code | varchar(8) | CLK-01…CLK-21 |
| clock_type | varchar(12) | CHECK (`recurring`,`countdown`,`window`,`threshold`,`derived`) — semantics per shared/clocks.html: recurring re-arms on fire; countdown expires once; window opens/closes; threshold watches a quantity |
| jurisdiction_id | uuid null FK | |
| subject_type / subject_id | varchar / uuid null | polymorphic: residency_claim, term, emergency_power, constitutional_challenge, vacancy, election… |
| status | varchar(12) | CHECK (`armed`,`fired`,`expired`,`disarmed`,`closed`) |
| armed_at | timestamptz NOT NULL default now() | |
| due_at | timestamptz null | countdown deadline / recurring next fire |
| window_opens_at / window_closes_at | timestamptz null | CLK-04, CLK-11, CLK-12, CLK-18 |
| next_check_at | timestamptz null | threshold clocks' sweep cadence |
| last_evaluated_at / last_fired_at | timestamptz null | |
| fire_count | int default 0 | |
| setting_key | varchar null | amendable source (`residency_confirmation_days`…) — on `setting_changes`, armed clocks with this key are re-derived |
| threshold_value | numeric null | snapshot for threshold clocks |
| fires_workflow | varchar(16) null | WF-xxx for the record |
| payload | jsonb default '{}' | |
| timestamps | | |

Partial unique `(clock_code, subject_type, subject_id) WHERE status='armed'`. Index `(status, due_at)`, `(status, next_check_at)`, `clock_code`.

**Laravel integration.** `routes/console.php`: `Schedule::command('clocks:tick')->everyMinute()`; cron `* * * * * php artisan schedule:run` added to the app container entrypoint (first scheduler use in the repo). `clocks:tick` selects clocks where `status='armed' AND (due_at <= now() OR window_closes_at <= now() OR next_check_at <= now())` and dispatches one job per row onto a Horizon `clocks` queue (new supervisor, short jobs; the existing long-running supervisor stays for ETL/export). **Dispatch pattern**: `config/constitution/clocks.php` maps `clock_code => handler job class` (`CLK-05 => EvaluateResidencyThresholdJob::class`, …). Every fire/expiry appends an audit entry. Threshold clocks are *also* event-driven (the cheap path): the triggering write (ping recorded, association created) evaluates inline; the scheduled sweep is the safety net.

**CLK-05 (residency) end-to-end, Phase A.**
1. F-IND-003 declares claim → engine validates (consent checkbox required) → `residency_claims` row (`ping_monitoring`) → clock row armed (`clock_code=CLK-05`, subject=claim, `next_check_at=+1 day`, `setting_key=residency_confirmation_days`).
2. Pings (manual/simulated UI in Phase A) insert `location_pings`; nightly `EvaluateResidencyThresholdJob` (or inline on ping) marks each day `is_qualifying` via `ST_Contains(jurisdiction.geom, ping.geom)` and updates `qualifying_days_count`.
3. Count ≥ resolved `residency_confirmation_days` → claim → `threshold_met`; F-IND-006 confirmation surfaces (confirm | correct-boundary → re-declare).
4. Confirm → claim `verified` then `active` (R-02); `AssociateJurisdictionsJob`: recursive CTE up `jurisdictions.parent_id` creates one `jurisdiction_associations` row per ancestor (R-03 → R-04 derived, atomic); prior claim (if any) → `superseded`, its associations `ended_at` set; raw pings purged; counters `verified_resident_count` incremented at every level; audit + public_records (`residency` kind) entries; clock `closed`.

**CLK-06 (activation) end-to-end, Phase A.** Association insert increments `verified_resident_count`; if the jurisdiction is `dormant` and the count ≥ resolved threshold (`constitutional_settings.critical_population_threshold` ?? `instance_settings.critical_population_defaults[adm_level]`), set `critical_population` and dispatch the Activation Engine (§D). A nightly CLK-06 sweep re-checks all dormant jurisdictions whose counters moved (covers threshold *lowering* by act later).

**CLK-01/02 hang-off (Phase B/C).** Certification of an election writes `terms` rows; each lockstep term arms one CLK-01 clock (subject=legislature, `due_at = term_ends - lead_time`, `setting_key=election_interval_months`). Firing dispatches `ScheduleGeneralElectionJob` which creates the next `elections` row and opens its approval phase — **no human action, and no API exists to move `due_at` on a CLK-01/CLK-10 clock** (hardened no-skip; attempts are engine-rejected and audit-logged). CLK-02 (Phase C): adjourning a session re-arms the legislature's rolling 90-day clock; firing dispatches warning → F-SPK-008 compulsion path → I-ADM violation record.

---

## D) ACTIVATION ENGINE

**Trigger**: CLK-06 (above). **Pipeline** (`JurisdictionActivationJob`, each step engine-validated + audit-chained, mirroring bootstrap steps):

1. `lifecycle_status → bootstrapping`.
2. Ensure `constitutional_settings` row: copy nearest ancestor's row (founding values per setup-wizard semantics — reference, not lock).
3. **Instantiate legislature** — extract the sizing math from `ApportionmentSeedCommand` into `ApportionmentService::sizeFor(Jurisdiction $j)`, reusing `ConstitutionalDefaults::sizeFromPopulation()`: `type_a_seats = max(5, round(∛population))` (cube-root law, q-ledger #3). **Bicameral trigger**: if the jurisdiction has direct children (constituents) → `type_b_seats = count(direct children)`, else `type_b_seats = 0` (unicameral, Art. V §3). Insert `legislatures` row `status='forming'`, `quorum_required` snapshot.
4. **Districts** if `type_a_seats > 9`: create a draft `legislature_district_maps` row and run the auto-composite (extract `LegislatureController::runAutoCompositeForScope` into `DistrictingService` — it already implements Webster composites of direct children with 5–9 banding and giant recursion). ≤ 9 seats → single at-large race, no map needed.
5. Insert `executives` + `judiciaries` stub rows `status='forming'` (generalize Setup Step 4's per-legislature insert).
6. **First election trigger**: insert `election_boards` row `is_bootstrap=true` (system as board, bootstrap step 3) and — Phase B onward — create the bootstrap `elections` row (kind `general`, trigger `bootstrap`, F-ELB-001 auto-issued) and open its approval phase. In Phase A (engine not yet built) the job stops after arming a `pending_first_election` clock payload so activation is observable and resumable.
7. `lifecycle_status → self_governing` happens at first certification (Phase B); until then it remains `bootstrapping`. `is_civic_active=true`, `civic_activated_at=now()` set at step 3 (the viewer/browser tools key off it today).

**Driving columns**: `jurisdictions.lifecycle_status / verified_resident_count / civic_activated_at / is_civic_active`; `constitutional_settings.critical_population_threshold` (+ `legislature_sizing_law`, min/max seats already present); `instance_settings.critical_population_defaults`, `clock_scheduler_enabled`. The current single Earth legislature needs **zero migration** — it is simply the first activated instance; the existing seeded row stays valid.

---

## E) AUDIT CHAIN

**`audit_log` (Phase A):**

| column | type | notes |
|---|---|---|
| seq | bigint generated always as identity, PK | chain order |
| id | uuid unique default gen_random_uuid() | cross-instance reference |
| occurred_at | timestamptz NOT NULL default now() | |
| actor_user_id | uuid null — **no FK** | immutability over cascade; users are soft-deleted anyway |
| actor_role | varchar(5) null | computed role code at action time (R-09…) |
| module | varchar(16) NOT NULL | `identity`,`residency`,`jurisdictions`,`elections`,`legislature`,`executive`,`judiciary`,`organizations`,`settings`,`records`,`federation`,`system` |
| event | varchar(64) NOT NULL | |
| form_id / workflow_id / clock_code | varchar null | canonical IDs (alias-resolved) |
| jurisdiction_id | uuid null | |
| subject_type / subject_id | varchar / uuid null | |
| payload | jsonb NOT NULL default '{}' | canonical (sorted-key) JSON; **never ballot content, never raw locations — commitments only** |
| payload_hash | char(64) NOT NULL | sha256 of canonical payload |
| prev_hash | char(64) NOT NULL | |
| hash | char(64) NOT NULL | `sha256(prev_hash ∥ payload_hash)` — exactly audit-chain.html's `H(hash(n−1) ∥ payload(n))` |
| rejected | boolean NOT NULL default false | denials are chain entries |
| blocked_reason / citation | text / varchar null | |
| created_at | timestamptz | no updated_at |

Genesis row: `prev_hash = '000…0'` (matches the mockup's `000000…genesis`). **Append-only enforced at the database**: `CREATE TRIGGER audit_log_immutable BEFORE UPDATE OR DELETE ON audit_log … RAISE EXCEPTION`, plus revoking UPDATE/DELETE from the app role. Indexes: `(subject_type, subject_id)`, `(actor_user_id)`, `(module, occurred_at)`, `(jurisdiction_id)`, `(form_id)`, partial on `rejected`.

**Write API — `AuditService`** (`App\Constitution\AuditService`):
- `append(AuditEntry $e): int` — runs **inside the caller's DB transaction** (the engine's `execute()` wraps mutation + audit atomically: no mutation without its entry, no entry without its mutation). Serializes the chain with `pg_advisory_xact_lock(hashtext('audit_chain'))`, reads the last `hash`, canonicalizes payload (sorted keys, RFC 8785-style), computes `payload_hash` and `hash`, inserts.
- `appendRejected(ConstitutionalAction, Violation)` — same path, `rejected=true`.
- `artisan audit:verify [--from-seq]` — recomputes every link against the head; `audit_checkpoints` (Phase F) publish head hashes to peers on CLK-20.
- `public_records` rows store `audit_seq` so every public entry is sealed into the chain at commit.

**Audited in Phase A**: user registration (F-IND-001) / profile change (F-IND-002) / identity verification outcome only (F-IND-004 — never document data); residency declaration (F-IND-003), threshold-met, confirmation (F-IND-006); each association created/ended (per level); role transitions (R-01→R-02→R-03/R-04, computed at transition); jurisdiction lifecycle transitions and every activation pipeline step; legislature/executive/judiciary instantiation; clock arm/fire/expiry; setting-row founding copies; every engine rejection; **dev impersonation start/stop** (the demo-bar persona switcher becomes a dev-only tool — its use is itself chained, so the dev tool can never be a silent backdoor).

---

### Phase-at-a-glance (every table → phase)

| Phase | Tables |
|---|---|
| **A** | users (recreate) · sessions/password_reset_tokens · residency_claims · location_pings (recreate) · jurisdiction_associations · audit_log · clocks · public_records · jurisdictions+constitutional_settings+instance_settings evolutions · v_user_roles |
| **B** | election_boards(+members) · elections (evolve) · election_races · candidacies · approvals · approval_standings · ballot_envelopes · ballots · tabulations(+rounds) · race_results · election_certifications · election_audits · vacancies · terms · appointments · legislature_members (evolve) |
| **C** | legislature_sessions · session_attendance · motions · chamber_votes · vote_casts · multi_jurisdiction_votes(+constituent_consents) · bills(+versions) · laws(+versions) · setting_changes · committees(+seats, preferences) · admin_offices · misconduct_investigations · removal_proceedings · petitions(+signatures) · referendum_questions · emergency_powers(+renewals) |
| **D** | executives/executive_members (evolve) · departments · boards(+seats) · executive_orders · policy_proposals · executive_investigations · department_rules · department_reports · appropriations · grant_applications(+disbursements) · organizations (evolve) · org_memberships · org_workers · org_ownership_stakes · org_contracts · org_document_packages(+versions) · org_transfers · org_conversions · cgc_ip_register |
| **E** | judiciaries/judicial_seats (evolve) · advocates · cases(+parties) · case_panel_assignments · juries(+members) · case_filings · verdicts · sentencing_orders · warrants · opinions(+law_links) · constitutional_challenges |
| **F** | federation_peers · authority_claims · sync_log · audit_checkpoints · partition_exports · jurisdiction_maps(+members) · union_processes · disintermediation_processes · law_merge_resolutions · border_settlements · restoration_events · cultural_institutions · user_relationships |

Key existing files this design touches: `E:\fair-constitution-app\.claude\worktrees\practical-payne-17d537\app\Services\ConstitutionalDefaults.php` (extended as settings facade — protected file, constitutional review required), `app\Console\Commands\ApportionmentSeedCommand.php` (sizing extracted to ApportionmentService), `app\Http\Controllers\LegislatureController.php` (`runAutoCompositeForScope` extracted to DistrictingService), `database\migrations\2026_01_01_000009_create_location_and_residency_tables.php` (superseded by the Phase A recreate migration), `docker\php\entrypoint.sh` (add `schedule:run` cron).