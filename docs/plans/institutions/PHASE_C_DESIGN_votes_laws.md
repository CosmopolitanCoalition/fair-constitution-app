All inputs read and the substrate verified (engine, validator, clocks, Phase B election/ballot/term/vacancy tables, FormRegistry handler map, live legislatures schema). Design follows.

---

# PHASE C DESIGN — CHAMBER VOTE ENGINE · BILLS/LAWS/ACTS · REFERENDUMS/PETITIONS/EMERGENCY POWERS

Scope: the constitutional core of Phase C. Sibling section owns: committees(+seats/preferences/chair RCV wiring), admin_offices, investigations, removal_proceedings, speaker *election* (F-LEG-008) and the Vue surfaces. This section owns sessions/attendance/motions, the vote engine itself (which the sibling's committee and removal votes ride), bills→laws, settings path, referendums, petitions, emergency powers, F-LEG-036, and `public_records` (verified absent from `database/migrations/` — Phase A's A-5 never shipped; it lands here because F-LEG-004/005/006 + F-SPK-009 are its first writers; dedupe with sibling if both specify it).

Substrate facts this design builds on (verified in code):
- `ConstitutionalValidator::supermajority($serving)` = `max(ceil(serving·n/d), floor(serving/2)+2)`; `::quorum($serving)` = `floor(serving/2)+1` — **never reimplemented anywhere below; every threshold resolves through these two functions** (`app/Services/ConstitutionalValidator.php`).
- `ConstitutionalEngine::file()` (`app/Domain/Engine/ConstitutionalEngine.php`) — all handlers below register in `FormRegistry::HANDLERS`.
- `ClockService` (`app/Services/ClockService.php`): `HANDLERS`/`STEP_HANDLERS` job maps; `clock_timers` has `fires_at`, `state`, `payload`, `override_value`.
- `legislatures` already carries `speaker_id`, `quorum_required`, `last_met_on`, `next_meeting_due_by` (`2026_01_01_000004`) — CLK-02 needs no legislatures migration.
- `legislature_members` carries `seat_type` char(1) a/b, `vote_share_norm numeric(8,4)`, `seated_at`, statuses `elected|seated|vacated|removed|term_ended` (`2026_06_13_000011`).
- `ballot_envelopes`/`ballots` carry `kind IN ('ranked','referendum')`; envelopes have nullable `referendum_question_id` (FK deferred to now); **`ballots` has NO question column and `envelopes.race_id` is NOT NULL** — fixed in migration C-8 below.
- `elections.kind` already includes `'referendum'`; `constitutional_settings.last_amended_by_act_id` exists FK-less.

---

## A) MIGRATION SET

All files `database/migrations/2026_06_20_0000NN_*.php`, additive only (live DB), UUID PKs `gen_random_uuid()`, timestamptz, string enums + CHECK, soft deletes unless an exception is documented. Order matters (FK chains): C-1 → C-10.

### C-1 `create_public_records_table` (WF-SYS-03 substrate)

Per DESIGN_schema_engine §A-5, unchanged except `kind` gains `'minutes'` and `'bill'`:

| column | type | notes |
|---|---|---|
| seq | bigint generated always as identity PK | publication order |
| id | uuid unique default gen_random_uuid() | cross-instance ref |
| kind | varchar(24) | CHECK in (`registration`,`residency`,`participation`,`statement`,`vote`,`bill`,`act`,`minutes`,`opinion`,`certification`,`correction`,`other`) |
| title / body | varchar / text null | |
| actor_user_id | uuid null, **no FK** | immutability over cascade |
| actor_display | varchar null | denormalized snapshot |
| jurisdiction_id / legislature_id | uuid null | scope |
| via_form / via_workflow / via_clock | varchar null | canonical IDs |
| subject_type / subject_id | varchar / uuid null | |
| audit_seq | bigint null | seals into chain |
| translations | jsonb default '{}' | locale → `{text, quality}` (pipeline itself deferred — machine-translate hook Phase F) |
| supersedes_record_id | uuid null | corrections append |
| published_at / created_at | timestamptz | **no updated_at, no soft deletes — append-only trigger identical to audit_log's** |

New `App\Services\PublicRecordService::publish(...): int` — writes the row **inside the caller's transaction** and stores `audit_seq` of the engine's audit entry. Hard rule in the service, not callers: never ballot content, never raw coordinates.

### C-2 `create_legislature_sessions_tables`

**`legislature_sessions`** (no ESM of its own; drives ESM-08 context + CLK-02):

| column | type | notes |
|---|---|---|
| id | uuid PK | |
| legislature_id | uuid FK legislatures restrict | |
| session_no | int | unique `(legislature_id, session_no)` |
| called_by_member_id | uuid FK legislature_members null | F-SPK-001 (null = system/first-session bootstrap) |
| scheduled_for / opened_at / adjourned_at | timestamptz null | |
| serving_at_open | smallint null | snapshot of ALL serving (vacancies excluded from serving but seat stays vacant — denominator = currently serving members; the *vacant seat* is simply not serving, matching the fixture: 9 seats, 8 serving, quorum 5) |
| quorum_required | smallint null | snapshot `ConstitutionalValidator::quorum(serving_at_open)` |
| quorum_met | boolean null | F-SPK-003 outcome |
| agenda | jsonb default '[]' | ordered items `{slot, kind, ref_type, ref_id, title, locked, status}`; kinds: `emergency_power` (slot-1 locked), `constitutional_matter` (slot-2 locked, Phase E feed), `committee_report`, `bill_floor`, `motion`, `statement`, `general` |
| minutes_record_id | uuid null → public_records.id (by uuid, no FK — append-only table) | F-SPK-009 |
| status | varchar(16) | CHECK in (`scheduled`,`open`,`adjourned`,`failed_quorum`,`cancelled`) |
| timestamps + soft deletes | | |

**`session_attendance`**: `id`, `session_id` FK cascade, `member_id` FK legislature_members restrict, `status` CHECK (`present`,`absent`,`compelled`,`excused`), `recorded_via_form` varchar(12) (F-LEG-002 \| F-SPK-008), `recorded_at`. Unique `(session_id, member_id)`. No soft deletes (corrections re-record; history in audit chain).

### C-3 `create_chamber_votes_tables` — THE VOTE ENGINE

**Decision: per-kind tallies live in a `chamber_vote_tallies` lane table, not columns.** Rationale: (1) unicameral, committee, and each bicameral kind run *identical* quorum+threshold math — one lane row shape means one code path and zero nullable column forest (`serving_a/serving_b/yes_a/...` would be 12+ nullables); (2) `BicameralDualAgreementTest` pins row-level data instead of parsing jsonb; (3) Phase D board votes reuse the same lane with zero migration.

**`chamber_votes`** — the universal in-body decision record:

| column | type | notes |
|---|---|---|
| id | uuid PK | |
| body_type | varchar(16) | CHECK in (`legislature`,`committee`,`board`) — board fired Phase D |
| body_id | uuid | polymorphic, no FK |
| legislature_id | uuid FK legislatures null | owning chamber, denormalized (set for committee votes too; null for org boards) |
| jurisdiction_id | uuid FK jurisdictions | record scope |
| votable_type / votable_id | varchar(32) / uuid null | bill, motion, emergency_power proposal, renewal, referendum delegation, appointment, removal_proceeding…; null for free-standing votes (speaker RCV) |
| vote_type | varchar(40) | key into `config/constitution/vote_types.php`; **no DB CHECK — documented exception** (registry is a code artifact, like `audit_log.form_id`) |
| vote_method | varchar(8) | CHECK in (`yes_no`,`rcv`) |
| threshold_basis | varchar(16) | CHECK in (`majority`,`supermajority`) — snapshot resolved from vote_type at open |
| stage | varchar(12) null | CHECK in (`committee`,`floor`) — bills carry it (q7 applies at both) |
| bicameral | boolean default false | snapshot: lanes = type_a+type_b vs single `all` |
| serving_snapshot | smallint | Σ lane serving at open |
| held_in_session_id | uuid FK legislature_sessions null | floor votes; committee votes null in C (committee meeting rows are sibling scope) |
| opened_by_member_id | uuid FK legislature_members null | |
| opened_at / closes_at / decided_at | timestamptz / null / null | |
| outcome | varchar(12) null | CHECK in (`adopted`,`failed`,`tied`) — `tied` is terminal only if no tie-break filed before close |
| speaker_tiebreak | boolean default false | F-SPK-004 |
| rcv_record | jsonb null | round-by-round RCV record from `VoteCountingService::countRcv` over PUBLIC rankings |
| status | varchar(8) | CHECK in (`open`,`closed`,`void`) |
| timestamps; **no soft deletes** | | vote records are immutable once closed — documented exception; corrections = new vote |

**`chamber_vote_tallies`** — one row per threshold lane:

| column | type | notes |
|---|---|---|
| id | uuid PK | |
| vote_id | uuid FK chamber_votes cascade | |
| lane | varchar(8) | CHECK in (`all`,`type_a`,`type_b`); unicameral/committee = one `all` row; bicameral = exactly two rows |
| serving | smallint | serving members of the lane at open |
| quorum_required | smallint | `quorum(serving)` snapshot |
| required_yes | smallint | majority ⇒ `quorum(serving)`; supermajority ⇒ `supermajority(serving, n, d)` with n/d resolved from `constitutional_settings` at open |
| present | smallint null | set at close (see §B presence rule) |
| yes / no / abstain | smallint default 0 | maintained transactionally per cast |
| quorate / passed | boolean null | set at close |
| CHECK `yes + no + abstain <= serving` · unique `(vote_id, lane)` | | |

**`vote_casts`** — PUBLIC member votes (Art. II §2 — the exact opposite of `ballots`):

| column | type | notes |
|---|---|---|
| id | uuid PK | |
| vote_id | uuid FK cascade | |
| member_id | uuid FK legislature_members restrict | |
| lane | varchar(8) | CHECK as above; snapshot of member's seat_kind at cast |
| value | varchar(8) null | CHECK in (`yes`,`no`,`abstain`) — null for rcv casts |
| rankings | jsonb null | RCV ballots, **public and published** |
| is_tiebreak | boolean default false | F-SPK-004 |
| explanation | text null | published with the cast |
| cast_via_form | varchar(12) | F-LEG-004 / F-LEG-005 / F-LEG-008 / F-LEG-011 / F-SPK-004 |
| public_record_id | uuid null | every cast publishes a `vote` record |
| cast_at | timestamptz | |
| CHECK `(value IS NOT NULL) <> (rankings IS NOT NULL)` · unique `(vote_id, member_id)` · partial unique `(vote_id) WHERE is_tiebreak` | | no soft deletes — casts immutable; a member may NOT change a cast (the record is the record) |

### C-4 `create_motions_table` — ESM-08

`id`, `session_id` FK cascade, `bill_id` uuid null (FK added in C-6), `moved_by_member_id` FK, `seconded_by_member_id` FK null, `text`, `kind` CHECK (`procedural`,`referral`,`direct_to_floor`,`amendment`,`table`,`adjourn`,`replace_speaker`,`other`), `status` CHECK (`submitted`,`recognized`,`debated`,`voted`,`adopted`,`failed`,`withdrawn`), `vote_id` uuid FK chamber_votes null, `amendment_text` text null (for `amendment` kind → becomes a bill_version on adoption), timestamps + soft deletes.

### C-5 `create_multi_jurisdiction_votes_tables` — Phase D/E/F dual-supermajority substrate (schema now, minimal wiring)

**`multi_jurisdiction_votes`**: `id`, `kind` CHECK (`exec_office_create`,`exec_office_alter`,`judiciary_convert`,`cultural_institution`,`additional_articles`,`union`,`disintermediation`), `subject_type`/`subject_id`, `initiating_legislature_id` FK, `initiating_vote_id` uuid FK chamber_votes null (the initiator's own supermajority where the kind requires one), `basis` CHECK (`supermajority`,`unanimity`), `constituent_total` smallint, `required` smallint (supermajority ⇒ `ConstitutionalValidator::supermajority(total)`; unanimity ⇒ `total`), `yes_count`/`no_count` smallint default 0, `status` CHECK (`open`,`passed`,`failed`,`expired`), `opens_at`/`closes_at` null, timestamps + soft deletes.

**`constituent_consents`**: `id`, `process_id` FK cascade, `jurisdiction_id` FK, `legislature_id` FK null, `chamber_vote_id` uuid FK chamber_votes null (that constituent chamber's own peg-quorum vote), `result` CHECK (`pending`,`yes`,`no`), `decided_at` null. Unique `(process_id, jurisdiction_id)`.

Phase C wiring = `MultiJurisdictionVoteService::{open, recordConsent, evaluate}` only (consent rows update counters; evaluate flips status). No form consumes it until F-LEG-015 (Phase D). **Deferral justified**: schema is the expensive-to-retrofit part (Phase D conversions and Art. VII additional-articles both ride it); the process UI/forms have no Phase C consumer.

### C-6 `create_bills_tables` — ESM-07

**`bills`**:

| column | type | notes |
|---|---|---|
| id | uuid PK | |
| legislature_id | uuid FK restrict | |
| jurisdiction_id | uuid FK | denormalized legislature anchor |
| sponsor_member_id | uuid FK legislature_members restrict | |
| title | varchar | |
| act_type | varchar(20) | CHECK in (`ordinary`,`setting_change`,`supermajority`,`dual_supermajority`) — **fixes the floor-vote `vote_type` at introduction** |
| scale | jsonb NOT NULL | jurisdiction ids bound; default `[own jurisdiction]`; validated ⊆ legislature's subtree at F-LEG-003 (Art. V §4 — fixed at introduction) |
| scope_judiciary_id | uuid FK judiciaries null | which judiciary hears disputes (stub rows exist since setup Step 4) |
| targets_setting_key | varchar null | F-LEG-031 path |
| proposed_value | jsonb null | CHECK `(act_type = 'setting_change') = (targets_setting_key IS NOT NULL)` |
| effective_at | timestamptz null | null = effective at enactment |
| status | varchar(16) | CHECK in (`introduced`,`referred`,`in_committee`,`reported`,`tabled`,`on_floor`,`passed`,`failed`,`enacted`,`withdrawn`) |
| committee_id | uuid null | plain uuid; FK added by the sibling's committees migration (`ALTER ... ADD CONSTRAINT`) |
| current_version_no | smallint default 1 | |
| introduced_at / passed_at / failed_at / enacted_at | timestamptz null | |
| enacted_law_id | uuid null | FK added in C-7 |
| timestamps + soft deletes | | |

**`bill_versions`** (append-only by convention): `id`, `bill_id` FK cascade, `version_no` smallint, `law_text` text, `changed_by_member_id` FK null, `change_kind` CHECK (`introduction`,`committee_amendment`,`floor_amendment`), `created_at`. Unique `(bill_id, version_no)`.

### C-7 `create_laws_tables` — the statute book (git-style)

**`laws`**:

| column | type | notes |
|---|---|---|
| id | uuid PK | |
| jurisdiction_id | uuid FK | scale anchor |
| legislature_id | uuid FK | |
| act_number | varchar | `"Act {YYYY}-{NN}"`, allocated under `pg_advisory_xact_lock(hashtext('act_number:'||legislature_id))`; unique `(legislature_id, act_number)` |
| title | varchar | |
| kind | varchar(24) | CHECK in (`ordinary`,`setting_change`,`rules_of_order`,`ethics_code`,`charter`,`creation_act`,`referendum_act`,`constitutional_article`) |
| scale | jsonb | copied from bill/petition/question at enactment |
| scope_judiciary_id | uuid FK null | |
| origin | varchar(20) | CHECK in (`bill`,`referendum`,`petition_initiative`,`judicial_remedy`,`founding`) — `judicial_remedy` reserved as a *version* source (E); origin `judicial_remedy` exists for E-created severable laws |
| enacting_bill_id | uuid FK bills null | this migration also adds `bills.enacted_law_id` FK → laws (both nullable; created in this order to break the cycle) |
| origin_ref_type / origin_ref_id | varchar / uuid null | referendum_question / petition (no FK — referendum_questions created in C-8; `referendum_questions.resulting_law_id` carries the real FK back) |
| referendum_passed_by_supermajority | boolean null | CLK-19 input |
| shield_expires_with_election_id | uuid FK elections null | CLK-19: protection lapses when this (the next general) certifies |
| status | varchar(12) | CHECK in (`in_force`,`amended`,`repealed`,`superseded`,`struck`) |
| current_version_no | smallint default 1 | |
| effective_at / enacted_at | timestamptz | |
| timestamps + soft deletes | | |

**`law_versions`** (append-only by convention; full text per version — **decision: store complete text, never deltas**; diffs are computed at render time. Justification: content is small, full-text versions make Art. IV §5 judicial edits and Phase F merge incorporation trivially correct, and `text_hash` pins each version into the audit chain):

`id`, `law_id` FK cascade, `version_no` smallint, `text` text, `text_hash` char(64) (sha256, recorded in the enactment/amendment audit payload), `source` CHECK (`enactment`,`legislative_amendment`,`judicial_remedy`,`referendum_modification`,`merge_incorporation`), `source_ref_type`/`source_ref_id` (bill / chamber_vote / constitutional_challenge / disintermediation), `created_at`. Unique `(law_id, version_no)`.

**`setting_changes`** (F-LEG-031 ledger): `id`, `jurisdiction_id` FK, `legislature_id` FK null, `setting_key` varchar, `old_value` jsonb, `new_value` jsonb, `law_id` FK laws restrict, `applied_at` timestamptz, `created_at`. Index `(jurisdiction_id, setting_key)`.

This migration also adds the FK `constitutional_settings.last_amended_by_act_id → laws(id)` (column exists FK-less since `2026_01_01_000002`).

### C-8 `create_petitions_and_referendums_tables`

**`petitions`** — ESM-10:

| column | type | notes |
|---|---|---|
| id | uuid PK | |
| creator_user_id | uuid FK users restrict | R-05 |
| jurisdiction_id | uuid FK | scale anchor |
| title / law_text | varchar / text NOT NULL | law_text = the binding text voters ratify |
| act_type | varchar(20) | CHECK in (`ordinary`,`setting_change`,`supermajority`) — no `dual_supermajority` by petition; drives the referendum threshold |
| targets_setting_key / proposed_value | varchar / jsonb null | same CHECK pairing as bills; bounds-validated at creation (reuse `checkSettingChange`) |
| scale | jsonb | |
| scope_judiciary_id | uuid FK null | |
| population_basis | integer | **civic population snapshot** at creation (see decision below) |
| threshold_pct | numeric(5,2) | snapshot of `initiative_petition_threshold_pct` at creation (CLK-17) |
| threshold_count | integer | `ceil(population_basis × threshold_pct / 100)` |
| status | varchar(24) | CHECK in (`created`,`gathering`,`threshold_reached`,`signature_audit`,`constitutional_review`,`validated`,`on_ballot`,`adopted`,`rejected`,`invalidated`) |
| audit_result | jsonb null | F-ELB-005 output `{checked, valid, invalid, invalid_reasons{}, pct}` |
| review_case_id | uuid null, **no FK** | Phase E (F-JDG-008) |
| review_stub | boolean default false | Phase C stub marker (see §E) |
| referendum_question_id | uuid null | FK added below |
| timestamps + soft deletes | | |

**`petition_signatures`**: `id`, `petition_id` FK cascade, `user_id` FK users restrict, `association_id` uuid null (provenance: the `jurisdiction_associations` row live at signing), `signed_at`, `revoked_at` null. Partial unique `(petition_id, user_id) WHERE revoked_at IS NULL`.

**`referendum_questions`** — ESM-11:

| column | type | notes |
|---|---|---|
| id | uuid PK | |
| jurisdiction_id | uuid FK | |
| origin | varchar(12) | CHECK in (`delegation`,`petition`) |
| delegating_vote_id | uuid FK chamber_votes null | **refines A.3's `delegating_law_id`**: the F-LEG-023 delegation is a supermajority *resolution* (a chamber_vote), not a statute — the law is what the referendum enacts. Avoids minting a contentless laws row per delegation |
| petition_id | uuid FK petitions null | CHECK exactly one of delegating_vote_id/petition_id per origin |
| question | text | ballot text |
| law_text | text | the text that becomes law v1 on passage |
| act_type | varchar(20) | CHECK as petitions |
| threshold | varchar(16) | CHECK in (`majority`,`supermajority`) + CHECK `(act_type IN ('supermajority')) = (threshold = 'supermajority')` — **derived, never editable** (engine computes; no API accepts it) |
| targets_setting_key / proposed_value | varchar / jsonb null | setting-change questions apply on passage like settings bills |
| election_id | uuid FK elections null | rides the NEXT jurisdiction-wide ballot |
| eligible_population | integer null | civic-population snapshot at voting close (the peg denominator) |
| yes_count / no_count | integer null | |
| status | varchar(12) | CHECK in (`queued`,`scheduled`,`voted`,`passed`,`failed`,`invalidated`) |
| resulting_law_id | uuid FK laws null | |
| certified_at | timestamptz null | |
| timestamps + soft deletes | | |

This migration also: adds FK `ballot_envelopes.referendum_question_id → referendum_questions` and `petitions.referendum_question_id` FK; **alters the Phase B ballot tables for question-scoped voting** (anticipated by the B migration's comment "extended with question id in Phase C"):
- `ballot_envelopes.race_id` → nullable; CHECK `(kind='ranked' AND race_id IS NOT NULL AND referendum_question_id IS NULL) OR (kind='referendum' AND referendum_question_id IS NOT NULL AND race_id IS NULL)`; replace unique with two partial uniques: `(race_id, user_id) WHERE kind='ranked'`, `(referendum_question_id, user_id) WHERE kind='referendum'`.
- `ballots`: `race_id` → nullable; add `referendum_question_id uuid null` FK restrict + the mirror CHECK; index `(referendum_question_id, counted)`. Question id in clear on an anonymous ballot leaks nothing (no voter linkage); yes/no stays inside `payload_encrypted` under the election's wrapped key (same `BallotCrypto` path).

**Population-denominator decision (flagged, candidate q-ledger entry):** every "population" threshold (petition CLK-17, referendum majority/supermajority, Phase F union/border votes) uses the **civic population = count of active `jurisdiction_associations` rows for the jurisdiction**, never WorldPop `jurisdictions.population`. Precedents: owner ruling #15 (activation pegs *player* population against real population); union-formation contract "denominator = whole population, never just voters" (the whole *civic* population is the largest knowable electorate); peg-quorum parallelism (denominator = all who *could* vote, absent = no). Real population stays provenance data. Pinned by `ReferendumShieldTest`/petition tests.

### C-9 `create_emergency_powers_tables` — ESM-12

**`emergency_powers`** (row created **on vote adoption**, not at proposal — the proposal lives in the chamber_vote's votable payload; a failed invoke leaves a failed vote + audit trail, no power row):

| column | type | notes |
|---|---|---|
| id | uuid PK | |
| legislature_id | uuid FK | |
| jurisdiction_id | uuid FK | declaring authority |
| cause | varchar(20) | **CHECK in (`natural_disaster`,`actual_invasion`) — closed enum, anything else rejected pre-vote** (Art. II §7) |
| label | varchar | e.g. "Hurricane Dorinda landfall" |
| declared_duration_days | smallint | CHECK `BETWEEN 1 AND 90` (DB belt) + pre-vote validation ≤ resolved `emergency_powers_max_days` (≤ 90 hardened) |
| area_jurisdiction_id | uuid FK jurisdictions | CHECK-less; engine validates = self or descendant of `jurisdiction_id` (≤ legislature's authority). `area_geom` (custom sub-area MULTIPOLYGON) **deferred to the manual line-drawing pass** — named areas are jurisdiction-composites in Phase C, same justification as district composites (q-ledger #q4) |
| methods | text | "within constitutional order" free text; published |
| invoke_vote_id | uuid FK chamber_votes | the supermajority adoption |
| status | varchar(16) | CHECK in (`active`,`under_review`,`renewed`,`expired`,`struck`,`narrowed`) (`invoked` transient state lives in the vote, not the row) |
| starts_at / expires_at | timestamptz | CLK-03 anchor |
| judicial_review_case_id | uuid null, **no FK** | Phase E hook (F-JDG-007) |
| review_outcome | varchar(12) null | CHECK in (`upheld`,`narrowed`,`struck`) — written by E |
| timestamps + soft deletes | | |

**`emergency_power_renewals`**: `id`, `emergency_power_id` FK cascade, `vote_id` FK chamber_votes (fresh supermajority), `extension_days` smallint CHECK 1–90, `previous_expires_at`, `new_expires_at`, `created_at`. (Renewals extend from current expiry; each renewal ≤ max — total lifetime may exceed 90 only through repeated fresh supermajorities, per Art. II §7 "renewal by supermajority, each ≤ max".)

### C-10 `wire_phase_c_clock_jobs` (no schema; registry rows for all 21 clocks were seeded in Phase A)

Code-side additions to `ClockService::HANDLERS`:
- `'CLK-02' => MeetingDeadlineJob::class` — fires at `legislatures.next_meeting_due_by`; appends warning audit + public record; repeated failure flagged for I-ADM (sibling consumes).
- `'CLK-03' => ExpireEmergencyPowerJob::class` — auto-expiry; "nothing rolls over silently".
- `'CLK-17' => EvaluatePetitionThresholdJob::class` — safety-net sweep (signature insert is the event-driven primary path, same pattern as CLK-05/06).
- CLK-19 gets **no timer** — it is a validator gate (rule `referendum.shield`, §D), evaluated at filing time against `laws.shield_expires_with_election_id`. Registry row stays for the record.

---

## B) VOTE ENGINE — `App\Services\ChamberVoteService`

`config/constitution/vote_types.php` — **the 33-row registry as code** (constitutional artifact, like `FormRegistry`). Shape:

```php
'<key>' => [
  'label'      => '...',                       // registry row text
  'category'   => 'simple_majority|supermajority|population|bicameral|rcv_stv',
  'engine'     => 'chamber|population_ballot|stv_count|multi_jurisdiction|assignment',
  'basis'      => 'majority|supermajority|population_majority|population_supermajority|rcv_single|rcv_supermajority|stv|ranked_preference|unanimity_constituents',
  'denominator'=> 'serving|committee_serving|civic_population|constituent_jurisdictions|board',
  'bicameral'  => 'per_kind|n/a',              // q-ledger #q7: per_kind at committee AND floor
  'dual'       => null|'constituent_supermajority',  // second meter (multi_jurisdiction_votes)
  'phase'      => 'C|D|E|F',
  'citation'   => 'Art. ...',
],
```

Full key set (33 registry rows; chamber-engine keys bold = wired in Phase C): **`bill_pass`** (majority, per_kind), **`committee_bill`** (committee_serving majority, per_kind in bicameral chambers), `bog_consent` (D), **`speaker_elect`**/**`speaker_replace`** (rcv_supermajority — *service wired here; forms/UI sibling*), `committee_create` (sibling, supermajority), `exec_delegate` (D), `exec_office_create` (D, dual), `exec_office_alter` (D, constituent-only), `judiciary_create`/`judiciary_convert` (E), **`referendum_delegate`** (supermajority), **`emergency_invoke`**/**`emergency_renew`** (supermajority), `officeholder_remove` (sibling oversight, supermajority), `judiciary_override` (E, supermajority within CLK-11), `cultural_institution` (F, dual), `additional_articles` (F), **`referendum_act_modify`** (supermajority, CLK-19-gated), `boundary_change`/`union_form_join`/`union_exit` (F, population+constituent), `referendum_majority`/`referendum_supermajority`/`petition_initiative` (population_ballot engine, §D/§E), `bicameral_dual_agreement` (structural modifier row — `engine: chamber`, realized as `bicameral: per_kind` on every chamber key), `general_legislative`/`exec_committee_stv`/`exec_individual_rcv`/`judicial_election` (stv_count — Phase B `VoteCountingService`), `committee_chair` (rcv_single by whole house — service wired here, forms sibling), `committee_preference` (assignment — sibling), **`procedural_motion`** (majority; the implicit "unstated votes = ordinary majority of all serving" owner ruling, MANIFEST §8). A boot-time test asserts the config covers all 33 registry rows exactly.

### Service API (every method called from inside engine handlers — never from controllers directly)

**`open(string $bodyType, string $bodyId, string $voteType, ?Model $votable, ?string $stage, ?LegislatureSession $session, ?LegislatureMember $opener): ChamberVote`**
1. Resolve vote_type config; assert `engine === 'chamber'`.
2. Lane construction: `legislature` body → serving members grouped by `seat_type` (`a`/`b` → lanes `type_a`/`type_b`) **iff `legislatures.type_b_seats > 0`**, else one `all` lane. `committee` body → committee members; per-kind lanes iff the parent chamber is bicameral (q7 applies at committee), else `all`.
3. Per lane: `serving` = count, `quorum_required = ConstitutionalValidator::quorum(serving)`, `required_yes` = basis majority ? quorum : `ConstitutionalValidator::supermajority(serving, n, d)` with n/d from `SettingsResolver` at open (amendable fraction, hardened clamp inside the function).
4. Guard `emergency.first_business`: if `$session` is set and the session has slot-1 agenda items with `status='pending'`, only vote_types in `['emergency_invoke','emergency_renew','procedural_motion']` (+ constitutional-matter types in E) may open — Art. II §2 session order, enforced server-side; the console merely renders it.
5. Insert vote + lanes; audit `legislature/vote.opened`.

**`cast(ChamberVote $vote, LegislatureMember $member, ?string $value, ?array $rankings, ?string $explanation, string $viaForm): VoteCast`**
- Guards: vote `open`; member is a *current* member of the body; member's lane exists; **member is not the chamber's speaker** (`legislatures.speaker_id`) unless `viaForm === 'F-SPK-004'`; no prior cast (DB unique is the backstop); method match (`yes_no` ⇒ value, `rcv` ⇒ rankings).
- Mutation (one transaction): insert cast; increment lane counter; publish `public_records` row kind `vote` (member, vote, value/rankings, explanation) — **public by constitutional mandate**.
- Speaker-in-denominator decision (flagged interpretation, matches the chamber fixture: 8 serving incl. speaker, quorum 5, supermajority 6): the speaker IS a serving member and stays in every denominator; they simply may not cast except F-SPK-004. Pinned in PegQuorumTest.

**`close(ChamberVote $vote, ?LegislatureMember $closer): ChamberVote`**
- Presence per lane: floor votes with a session → count of lane members with attendance `present|compelled`; otherwise = casts in lane. `quorate = present >= quorum_required`.
- `passed = yes >= required_yes && quorate`. **Absence/abstention semantics (hardened): the denominator is `serving`; an absent or abstaining member is arithmetically identical to a no against the threshold; `abstain` is recorded distinctly for the public record only.**
- Outcome: `adopted` iff **every** lane passed (q-ledger #q7 — bicameral dual agreement, both kinds independently quorate AND passing, at whatever `stage`); `tied` iff some lane has `yes == no && yes == required_yes - 1` and no lane already failed irrecoverably; else `failed`.
- RCV closes: run `VoteCountingService::countRcv` over the public rankings → `rcv_record`; for `rcv_supermajority` (speaker) the final-round winner must reach `required_yes` of serving, else outcome `failed` (re-ballot = new vote, per WF-LEG-02).
- Audit `vote.closed` with full lane snapshot; votable side-effects dispatch (bill stage transition, emergency activation, etc. — each in the same engine transaction as the closing filing).

**`tiebreak(ChamberVote $vote, User $speaker, string $value, ?string $explanation): ChamberVote`** (F-SPK-004 — **the only speaker vote**)
- Guards: outcome would be `tied`; `value === 'yes'|'no'`; `yes + 1 >= required_yes` when yes (i.e. only resolvable ties — structurally this means majority-basis votes; a supermajority tie is unbreakable by one vote and the form is rejected with citation); actor is `legislatures.speaker_id`'s user. Bicameral: tie-break applies **only to the lane matching the speaker's own seat_kind** (one person, one vote — flagged interpretive decision, candidate q-ledger entry).
- Records `is_tiebreak` cast, sets `speaker_tiebreak = true`, re-closes.

**Committee reuse**: F-LEG-005 casts on `body_type='committee'` votes; role gate R-11 plus *membership in that committee* (the sibling's `committee_seats` is consulted through a narrow `CommitteeRosterContract` interface so this service has no hard dependency on sibling tables landing first — Noop implementation until they do, same pattern as Phase B's `NoopBallotBoxDelegate`).

---

## C) BILL LIFECYCLE — ESM-07

States (DB CHECK above): `introduced → referred → in_committee → (reported | tabled) → on_floor → (passed | failed) → enacted`, plus `withdrawn`. Bicameral chambers pass only when committee vote AND floor vote each adopt per-kind (the vote engine enforces; the lifecycle just consumes `outcome`).

**`App\Services\BillService`** + **`App\Services\EnactmentService`**:

1. **Introduce (F-LEG-003)**: validations — sponsor holds a current seat in the legislature; `act_type` valid; `scale` ⊆ legislature's jurisdiction subtree (recursive-CTE check); `scope_judiciary_id` (when set) belongs to a jurisdiction in the chain; setting bills run `ConstitutionalValidator::checkSettingChange` **pre-vote** (existing Phase A path, unchanged). Creates `bills` + `bill_versions` v1 (`change_kind='introduction'`); public record kind `bill`.
2. **Referral**: motion kind `referral` (to a committee) or `direct_to_floor` — adopted by `procedural_motion` majority vote. Direct-to-floor is the Phase C exit-criterion path for Montegiardino (no committees needed to ship). `referral` → status `referred`/`in_committee` + `committee_id`.
3. **Committee**: sibling's F-CHR-001/002 + F-LEG-005 casts on a `committee_bill` vote (`stage='committee'`); adoption → F-CHR-003 unlocks (`reported`); committee amendments append `bill_versions` (`committee_amendment`). Failure → `tabled`.
4. **Floor**: amendments via `amendment` motions (adoption appends `bill_versions` `floor_amendment`, bumps `current_version_no`); floor vote opened with `vote_type` derived from `act_type` (`ordinary|setting_change` → `bill_pass` majority; `supermajority` → supermajority basis; `dual_supermajority` → supermajority + opens a `multi_jurisdiction_votes` process, completion deferred to D), `stage='floor'`. Casts = F-LEG-004 filings. Adopted → `passed`; failed → `failed` (archived **with casts + explanations** — they're already public records).
5. **Enactment** (system step inside the closing transaction): `EnactmentService::enact(Bill $bill)` —
   - allocate `act_number`; insert `laws` (kind from act_type/special kinds for F-LEG-032/033 rules/ethics — those forms are thin wrappers introducing pre-typed bills) + `law_versions` v1 (`source='enactment'`, text = latest bill_version, `text_hash` into the audit payload);
   - `bills.status='enacted'`, `enacted_law_id`;
   - **setting bills**: re-run `checkSettingChange` (TOCTOU guard against a bounds change between vote and enactment), insert `setting_changes` row, update the `constitutional_settings` row (creating it from the nearest-ancestor copy if the jurisdiction has none — existing activation semantics), set `last_amended_by_act_id`/`last_amended_at`; `SettingsResolver` cache bust; **after-commit `RederiveClockTimersJob`** (below);
   - public record kind `act`; `effective_at` honored (future-dated laws are `in_force` at effective time — a small `effective_at <= now()` scope on readers, no extra clock);
   - Art. IV §5 challenge window opens implicitly (any inhabitant may file F-IND-016 — Phase E; nothing to do now).

**Clock re-derivation** (the exit criterion's second half): `ClockService::rederiveForSetting(string $settingKey, string $jurisdictionId)` — selects armed `clock_timers` joined to `clocks` where `clocks.setting_key = $settingKey` and the timer's jurisdiction is the changed jurisdiction or a descendant inheriting from it, and the timer payload carries a derivation anchor `payload.derive = {anchor_at, unit: 'months'|'days'}`; recomputes `fires_at = anchor_at + resolved_value`, audits `clocks/rederived` per timer. **Requires one CertificationService touch**: when arming CLK-01 it now includes `payload.derive = {anchor_at: certified_at, unit: 'months'}` (CLK-02 arming in SessionService includes `{anchor_at: last_met_on, unit: 'days'}`). CLK-03 timers are deliberately NOT re-derived — an active power keeps its *declared* duration; a lowered max binds only new declarations/renewals (flagged decision, Art. II §7 reading: the declaration fixed the duration at vote time).

**F-LEG-031 upgrade (supersedes the Phase A record-only handler)**: `AmendableSettingChange` becomes "introduce a pre-targeted setting bill" — role gate tightened to `['R-09']` (the Phase A docblock already promises this), payload `{jurisdiction_id, setting_key, value, title?}` → `BillService::introduce(act_type='setting_change', …)`. The Phase A behavior (pre-vote bounds rejection with citation + `rejected=true` chain row) is preserved verbatim because the validator path is untouched.

---

## D) REFERENDUMS

**Delegation (F-LEG-023, canonical per registry — catalog-drift alias F-LEG-022 already mapped in `FormRegistry::CATALOG_DRIFT`)**: handler validates question/law_text/act_type (setting questions bounds-checked), opens a `referendum_delegate` supermajority chamber vote (votable = a transient delegation payload). On adoption (close side-effect): insert `referendum_questions` (`origin='delegation'`, `delegating_vote_id`, `threshold` derived from act_type — **no API ever accepts a threshold input**), status `queued`. Public record.

**Ballot integration (riding Phase B machinery)**:
- **Attach**: `ReferendumService::attachQueued(Election $election)` runs when an election covering the question's jurisdiction has its ranked window scheduled (hook inside the existing F-ELB-001 `ElectionSchedulingOrder` handler + the CLK-18 finalist-cutoff job): all `queued` questions whose `jurisdiction_id` equals the election's jurisdiction (or an ancestor of it equal to the question's scope — exact-match only in C; cross-scope piggybacking deferred) get `election_id` set, status `scheduled`. The "next jurisdiction-wide ballot" is therefore: the open successor general (both live legislatures have one), any special election whose footprint = the whole jurisdiction, or a board-scheduled `kind='referendum'` election (enum exists; **standalone referendum scheduling wiring deferred to the first real need — justification: every active legislature permanently has an open successor election, so a next ballot always exists; a board wanting an earlier ballot files F-ELB-001 kind=referendum, which works through the existing handler with zero new code paths, just no UI**).
- **Vote (F-IND-008)**: new `ReferendumVote` handler mirroring `BallotSubmission` — R-04 + active association with the question's jurisdiction; window = election `ranked_open`; envelope `(kind='referendum', referendum_question_id)` (double-vote via the C-8 partial unique); anonymous ballot row (`kind='referendum'`, question id in clear, `{question_id, choice}` inside `payload_encrypted` under the election's existing wrapped key); receipt hash returned; **never** recorded in the cast audit payload (engine `SENSITIVE_KEYS` already strips `rankings`; add `choice`).
- **Tally**: `TabulateElectionJob` pipeline gains a `ReferendumTallyStep` after race tabulations: decrypt+count per question, write `yes_count/no_count`, snapshot `eligible_population` (civic population at close), status `voted`. Recorded through `TabulationRecorder` for chain-of-custody parity.
- **Certify**: F-ELB-004 side-effects (CertificationService) gain `certifyReferendums()`: per question — `required = threshold === 'majority' ? quorum(eligible) : supermajority(eligible)` (the same two PROTECTED functions; population peg). Passed → `EnactmentService::enactFromReferendum()`: laws row `origin='referendum'`, `kind='referendum_act'`, `referendum_passed_by_supermajority = (yes >= supermajority(eligible))` **computed regardless of the question's threshold class** (a majority-class question can still pass at population-supermajority strength and earn the shield — Art. II §6 shields "acts passed by population supermajority", not "supermajority-class questions"; flagged interpretation), `shield_expires_with_election_id` = the legislature's open successor general election; setting questions apply via the same `setting_changes` path. Failed → archived.

**CLK-19 same-term shield** — two enforcement points, no timer:
1. **Validator rule `referendum.shield`** (new `ConstitutionalValidator` check on F-LEG-034 and on any bill whose scale/subject targets a law): filing that modifies/repeals a law where `origin='referendum'` AND `referendum_passed_by_supermajority` AND `shield_expires_with_election_id` points to a not-yet-certified election ⇒ `ConstitutionalViolation` citing Art. II §6 (rejected pre-vote, recorded). Unshielded referendum acts same-term: F-LEG-034 requires `referendum_act_modify` **supermajority**; post-election they are ordinary laws (ordinary `bill_pass` amendment path).
2. **`law_versions` guard**: `EnactmentService`/amendment writers refuse `source IN ('legislative_amendment')` rows on shielded laws — defense in depth behind the validator.
3. **Shield release**: `CertificationService` (general elections only) clears the shield on certification — laws with `shield_expires_with_election_id = elections.id` get the shield columns nulled + a public record "referendum act converted to ordinary law" (the Phase B design's "convert referendum acts to ordinary law" placeholder, now real).

---

## E) PETITIONS

**F-IND-009 Petition Creation** (roles `['R-05']` per registry; R-05 derives from R-03 + the act of creating — `RoleService` gains the trivial R-05 derivation, effectively gating on R-03): validate law_text non-empty, act_type, scale ⊆ creator's association chain, setting petitions bounds-checked. Snapshot `population_basis` (civic population of the jurisdiction), `threshold_pct` (resolved `initiative_petition_threshold_pct`), `threshold_count`. Status `gathering` immediately (Created→Gathering is atomic at filing). Arm CLK-17 threshold-watch timer (subject = petition, `fires_at` NULL).

**F-IND-010 Petition Signature** (R-03): signer must hold an **active association with the petition's jurisdiction** (the only gate — Art. I); revocable (`revoked_at`) while `gathering`. Event-driven threshold check on insert (CLK-17 sweep is the safety net): live `count(signatures where revoked_at is null) >= threshold_count` → status `threshold_reached`, audit + public record, board notified (election-board console queue). Signatures stay open during audit/review (mockup contract); the audited count freezes at the threshold check.

**F-ELB-005 Petition Signature Audit — handler designed now (Phase B deferred it; no handler exists in `FormRegistry::HANDLERS` — verified)**: role R-08 (member of the active board for the petition's jurisdiction; the bootstrap board's system-actor path works exactly as in Phase B). `PetitionService::runSignatureAudit()`: per unrevoked signature verify (a) user existed and held an active association covering the jurisdiction **at `signed_at`** (associations carry `established_at`/`ended_at` — point-in-time check), (b) no duplicate users (DB-guaranteed; re-asserted), (c) signature predates the audit. Writes `audit_result` jsonb; `valid >= threshold_count` → status `constitutional_review`; else `invalidated` (kill-path 1) with public record.

**Constitutional review (kill-path 2) — STUBBED until Phase E, explicitly on the record**: no judiciary exists (stub rows, status `forming`). `PetitionService::stubConstitutionalReview()` system-files an audit entry `petitions/review.stub_validated` with payload `{note: 'Judiciary forming — F-JDG-008 review lands in Phase E; petition auto-validated', citation: 'Art. II §6 · deferred'}`, sets `review_stub = true`, status `validated`. When Phase E lands, the stub call site is replaced by a real F-JDG-008 referral; `review_stub` rows remain honest history. **Justification**: blocking all petitions on a non-existent institution would let an unbuilt phase veto a live constitutional right; the stub is visible, audited, and reversible by E's real review of any still-pending petition.

**Validated → ballot**: `ReferendumService::queueFromPetition()` creates the `referendum_questions` row (`origin='petition'`, threshold derived from act_type — "majority or supermajority of population matching the legislative equivalent"), petition status `on_ballot` when attached, `adopted`/`rejected` at certification (mirrors §D).

---

## F) EMERGENCY POWERS

**F-LEG-024 Declaration** (R-09): **all validation pre-vote** (rejection rows are the operator-visible demo, exactly like F-LEG-031):
- `cause` ∈ closed enum — economic/political/public-order rationales rejected with Art. II §7 citation;
- `duration_days` 1..min(90, resolved `emergency_powers_max_days`) — inline field error contract;
- `area_jurisdiction_id` = the legislature's jurisdiction or a descendant (recursive-CTE; "≤ this legislature's authority");
- `methods` non-empty.
Opens `emergency_invoke` supermajority chamber vote. **Adoption side-effects** (same transaction): insert `emergency_powers` (`status='active'`, `starts_at=now()`, `expires_at = starts_at + duration_days`), arm CLK-03 (subject = power, `fires_at = expires_at`, payload `{derive: null}` — deliberately non-re-derivable), public record kind `act`.

**F-LEG-025 Renewal** (R-09): power must be `active|renewed|under_review`; `extension_days` 1..min(90, resolved max); fresh `emergency_renew` supermajority. Adoption: renewals row, `expires_at += extension_days`, `status='renewed'`, cancel + re-arm CLK-03. "Nothing rolls over silently" — there is no auto-renewal path anywhere.

**CLK-03 auto-expiry**: `ExpireEmergencyPowerJob` (timer handler): power → `expired`, full audit + public record ("no action required"); Phase D hook documented: `department_rules` rows with `enabling_type='emergency_power'` expire in the same job (cascade lands with D's table).

**Civic-process protection — ENGINE-level, three concrete mechanisms** (validator rule id `emergency.civic_process_shield`):
1. **Structural absence (already true, now pinned)**: there is NO API that an emergency power could invoke — CLK-01/CLK-10 have no reschedule/skip API (Phase B hardening), sessions cannot be cancelled by any form, `EvaluateClocksJob` has no pause flag, `VacancyService` windows are engine-clamped. The constitutional test asserts these APIs still don't exist (architecture test over the service surface).
2. **Protected-form invariance**: the validator maintains `EMERGENCY_PROTECTED_FORMS` = all `F-IND-*` (registration, residency, pings, ballots F-IND-007/008, petitions F-IND-009/010, candidacy), `F-CAN-*`, `F-ELB-001..006`, `F-SPK-001/003`, `F-LEG-002/004/005/036`, `F-JDG-*` (E). No handler for these forms may read `emergency_powers` state, and no payload for them may carry `emergency_power_id`/`enabling_*` keys (rejected with Art. II §7). `EmergencyShieldTest` files each protected form against a fixture with an active power and asserts byte-identical behavior.
3. **Forward rule for enabling-authority consumers**: any handler that accepts `enabling_type='emergency_power'` (Phase D executive orders, department rules) must declare itself in `EMERGENCY_ENABLED_FORMS`; the engine rejects emergency-citing payloads on any undeclared form. This is the registered hook Phase D's "order rejected pre-issuance" contract consumes.

**Session agenda slot 1** (Art. II §2 order of business): `SessionService::open()` materializes agenda slot-1 items = powers `active|renewed|under_review` whose `area_jurisdiction_id` is the session jurisdiction, an ancestor of it (downward visibility — the Dorinda pattern), or a descendant; `locked=true`, `status='pending'`. F-SPK-002 cannot reorder/remove locked slots (handler-enforced). `ChamberVoteService::open` guard (§B.4) blocks general-business votes until the speaker marks each slot-1 item `addressed` via F-SPK-002 progression (each acknowledgment audited). Slot 2 (constitutional matters) is materialized empty-capable now; Phase E's F-JDG-004 inserts into it.

**Judicial review hook**: `judicial_review_case_id` + `review_outcome` columns exist now; `status` already supports `under_review|struck|narrowed`. Phase E's F-JDG-007 writes them; nothing else to build.

---

## G) ENGINE HANDLERS + CONSTITUTIONAL TESTS

New entries for `FormRegistry::HANDLERS` (`app/Domain/Forms/Handlers/…`). All run inside `ConstitutionalEngine::file()`; "audit" = the event recorded; every one also publishes the public-record rows named above.

| Form | Handler | Roles | Validation (beyond role gate) | Mutation | Audit event |
|---|---|---|---|---|---|
| F-LEG-002 | `AttendanceRegistration` | R-09 | session open; actor's member row in that legislature | upsert attendance `present` | `legislature/session.attendance` |
| F-LEG-003 | `BillIntroduction` | R-09 | §C.1 (scale subtree, act_type, setting bounds) | bill + version 1 | `legislature/bill.introduced` |
| F-LEG-004 | `FloorVoteCast` | R-09 | vote open + `stage='floor'`; lane match; not speaker; no duplicate | cast + lane counters + public record | `legislature/vote.cast` |
| F-LEG-005 | `CommitteeVoteCast` | R-11 | committee membership (RosterContract); vote `stage='committee'` | same | `legislature/vote.cast` |
| F-LEG-006 | `PublicRecordStatement` | R-09 | non-empty; optional attach (bill/session/vote) | public_records `statement` | `records/statement.published` |
| F-LEG-007 | `MotionSubmission` | R-09 | session open; kind valid; referral kinds name a bill | motion row; recognition→vote opening (`procedural_motion`) | `legislature/motion.submitted` |
| F-LEG-023 | `ReferendumDelegation` | R-09 | question/law_text/act_type; setting bounds | opens supermajority vote; adoption → question row (§D) | `legislature/referendum.delegated` |
| F-LEG-024 | `EmergencyPowersDeclaration` | R-09 | §F pre-vote set (cause/duration/area/methods) | opens supermajority vote; adoption → power row + CLK-03 | `legislature/emergency.invoked` |
| F-LEG-025 | `EmergencyPowersRenewal` | R-09 | power live; extension ≤ max | vote; adoption → renewal + re-arm | `legislature/emergency.renewed` |
| F-LEG-031 | `AmendableSettingChange` **(upgraded)** | **R-09** | existing bounds path unchanged | now introduces a setting bill (§C) | `settings/setting.bill_introduced` |
| F-LEG-034 | `ReferendumActModification` | R-09 | **CLK-19 shield rule first**; same-term ⇒ supermajority | vote; adoption → `law_versions` `referendum_modification` | `legislature/law.referendum_modified` |
| F-LEG-036 | `VacancyDeclaration` | R-09, R-10 | member current; same legislature as actor | wraps `VacancyService::declare(via:'F-LEG-036')` — **closes the Phase B loop** (B's `declared_via_form='dev'` path retired for live use) | `legislature/vacancy.declared` |
| F-SPK-001 | `SessionCall` | R-10 | actor is speaker; no other open session | session row `scheduled`/`open` | `legislature/session.called` |
| F-SPK-002 | `AgendaSetting` | R-10 | locked slots immutable; progression marks slot-1 `addressed` | agenda jsonb | `legislature/session.agenda` |
| F-SPK-003 | `QuorumCountPublication` | R-10 | session open | snapshot present vs `quorum_required`; not met → `failed_quorum` + WF-LEG-20 branch | `legislature/session.quorum` |
| F-SPK-004 | `TieBreakingVote` | R-10 | §B tiebreak guards | tiebreak cast + re-close | `legislature/vote.tiebreak` |
| F-SPK-008 | `AttendanceCompulsionOrder` | R-10 | session `failed_quorum` | attendance `compelled` rows + record | `legislature/session.compulsion` |
| F-SPK-009 | `SessionMinutesPublication` | R-10, R-29 | session open | adjourn; minutes record; `last_met_on`/`next_meeting_due_by`; CLK-02 cancel+re-arm (`derive {anchor: last_met_on, unit: days}`) | `legislature/session.adjourned` |
| F-IND-008 | `ReferendumVote` | R-04 | question scheduled + window open; association with question jurisdiction | envelope + anonymous ballot + receipt (§D) | `elections/referendum.ballot_committed` (participation only) |
| F-IND-009 | `PetitionCreation` | R-05 (⇐ R-03) | §E | petition + CLK-17 | `civic/petition.created` |
| F-IND-010 | `PetitionSignature` | R-03 | active association in footprint; petition gathering | signature; threshold eval | `civic/petition.signed` |
| F-ELB-005 | `PetitionSignatureAudit` | R-08 | petition `threshold_reached`; board jurisdiction match | audit run → result/status (§E) | `elections/petition.audited` |

(Sibling section registers: F-LEG-001 oath, 008–013, 020–022, 032/033, F-SPK-005/006/007, F-CHR-001..004 — their vote-bearing forms call `ChamberVoteService` with the keys defined here.)

### Constitutional tests (replace the 4 named-skip placeholders in `tests/Constitutional/`)

- **`PegQuorumTest`** — pins: lane `required_yes` for majority = `floor(serving/2)+1` and supermajority = `max(ceil(2s/3), floor(s/2)+2)` at s ∈ {5..9, 41} (San Marino) — asserted *through the service*, delegating to the PROTECTED functions; **vacancy invariance**: Montegiardino fixture 8 serving + 1 vacancy → required 5/6, absent member ≡ no (a 4-yes/3-no/1-absent majority vote FAILS); speaker stays in the denominator and cannot cast outside F-SPK-004; abstain never counts toward yes; outcome can never be computed from `present`.
- **`BicameralDualAgreementTest`** — San Marino-shaped fixture (type_a 32 / type_b 9): a floor vote passing type_a overwhelmingly but failing type_b quorum or threshold ⇒ `failed` (q7); same at `stage='committee'`; lanes are exactly two rows with independent `required_yes`; unicameral body produces exactly one `all` lane; per-kind supermajority uses each lane's own serving.
- **`EmergencyCeilingTest`** — F-LEG-024 with duration 91 ⇒ rejected pre-vote with Art. II §7 + `rejected=true` chain row; cause `'economic_crisis'` ⇒ rejected; renewal extension 91 ⇒ rejected; renewal of an `expired` power ⇒ rejected; CLK-03 fire flips `active → expired` and audits; `EMERGENCY_PROTECTED_FORMS` invariance (file F-IND-007/009, F-ELB-001, F-SPK-001 under an active power — identical results); no service exposes an election/session deferral API (architecture assertion).
- **`ReferendumShieldTest`** — law `origin='referendum'`, `referendum_passed_by_supermajority=true`, shield election open: F-LEG-034 ⇒ rejected citing Art. II §6 (+ chain row); `law_versions` writer refuses `legislative_amendment`; majority-passed (non-supermajority) referendum act same term: F-LEG-034 proceeds but demands supermajority; certify the shield election ⇒ shield cleared, ordinary amendment path opens; population thresholds computed over civic population via `quorum()`/`supermajority()`.

Plus (new, not placeholders): `SettingEnactmentTest` — end-to-end settings bill changes `election_interval_months`, asserts `setting_changes` row, `constitutional_settings` value, `last_amended_by_act_id`, and the armed CLK-01 timer's `fires_at` moved to `anchor_at + new interval` (the exit criterion, pinned).

---

## H) WORK-ITEM BREAKDOWN (this scope)

| WI | Size | Content | Depends on | Parallel with |
|---|---|---|---|---|
| C-V0 | S | `config/constitution/vote_types.php` (33 rows) + boot-time completeness test | — | everything |
| C-V1 | M | Migrations C-1..C-5 (public_records, sessions, chamber_votes×3, motions, multi_jurisdiction×2) + models + `PublicRecordService` | — | sibling's committee migrations (no FK crossings until their ALTER) |
| C-V2 | L | `ChamberVoteService` (open/cast/close/tiebreak, lanes, RCV close via `VoteCountingService::countRcv`) + F-LEG-004/005, F-SPK-004 handlers + `PegQuorumTest`, `BicameralDualAgreementTest` | C-V0, C-V1 | C-S1 |
| C-S1 | M | `SessionService` + F-SPK-001/002/003/008/009, F-LEG-002/006/007 handlers + CLK-02 job + agenda slot-1 guard | C-V1 | C-V2 |
| C-B1 | L | Migrations C-6/C-7 + `BillService`/`EnactmentService` + F-LEG-003 + floor-vote wiring + act numbering + `law_versions` | C-V2 | C-R1 prep |
| C-B2 | M | Settings path: F-LEG-031 upgrade, `setting_changes`, `SettingsResolver` bust, `ClockService::rederiveForSetting` + CertificationService CLK-01 `derive` payload touch + `SettingEnactmentTest` | C-B1 | C-R1, C-P1, C-E1 |
| C-R1 | M | Migration C-8 (referendum half) + F-LEG-023/F-IND-008 handlers + `ReferendumService` (attach/tally/certify) + CertificationService shield release + CLK-19 validator rule + `ReferendumShieldTest` | C-B1 (laws), Phase B ballot machinery (live) | C-P1, C-E1 |
| C-P1 | M | Petitions (C-8 petition half) + F-IND-009/010 + F-ELB-005 handler + CLK-17 + review stub | C-V1; C-R1 for queue-to-ballot | C-E1 |
| C-E1 | M | Migration C-9 + F-LEG-024/025 + CLK-03 expiry job + civic-process shield rules + `EmergencyCeilingTest` + session slot-1 integration | C-V2, C-S1 | C-R1, C-P1 |
| C-X1 | S | F-LEG-036 handler (VacancyService wrap) + F-LEG-034 handler | C-V2 (034 vote), C-R1 (shield rule) | any |

Critical path: **C-V1 → C-V2 → C-B1 → C-B2** (the exit criterion: bill → versioned law under peg quorum, unicameral + bicameral; settings bill re-derives clocks). C-R1/C-P1/C-E1 fan out after C-B1; C-S1 runs beside C-V2. Sibling dependency surface is deliberately one interface (`CommitteeRosterContract`) + one deferred FK (`bills.committee_id`) — neither blocks the exit criterion (direct-to-floor path).

**Flagged interpretive decisions for the q-ledger** (all pinned by tests): per-kind tally lanes table; speaker in denominator / lane-scoped tie-break; population denominators = civic population; majority-class referendum earning the shield when passed at supermajority strength; active emergency powers immune to max-days re-derivation; petition constitutional-review stub. **Deferrals**: `area_geom` sub-jurisdiction emergency areas (manual line-drawing pass); standalone referendum-election UI; multi_jurisdiction_votes consumers (D); translation pipeline on public_records (F); F-JDG-007/008 writers (E).