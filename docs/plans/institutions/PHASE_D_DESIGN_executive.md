# PHASE D DESIGN — THE EXECUTIVE BRANCH
Delegation/conversion · departments · Boards of Governors · executive orders · reporting · grants

Verified against the live worktree (`E:\fair-constitution-app\.claude\worktrees\practical-payne-17d537`): the `executives`/`executive_members` stubs (`database/migrations/2026_04_25_000002_create_executives_tables.php`), `app/Services/Legislature/ChamberActService.php` (consent pipeline + `openCivilTerm` + CLK-09 arming), `app/Services/MultiJurisdictionVoteService.php` (kind `exec_office_create` already in `MultiJurisdictionVote::KINDS`), `app/Services/Legislature/CommitteeAssignmentService.php` (pure `assign()`), `app/Services/VoteCountingService.php` (`countRcv`, `deriveAdvisors`, `countback`), `app/Services/CertificationService.php` (`certify`, `lockstepWindow`/`inheritedWindow`), `app/Services/ChamberVoteService.php` (`BODY_BOARD` exists; votable-effect dispatch), `config/constitution/vote_types.php` (`exec_delegate`, `exec_office_create`, `exec_office_alter`, `bog_consent`, `exec_committee_stv`, `exec_individual_rcv` all pre-registered for Phase D), `terms` CHECK already carries `executive_seat` + `board_governor`, `RemovalProceeding::KIND_EXECUTIVE_REMOVAL` exists, `executive_members.user_id` FK already added by `2026_06_12_000002_rebuild_users_uuid.php`, and `ConstitutionalValidator::SETTING_BOUNDS` already bounds `worker_rep_min_employees`/`worker_rep_parity_employees`.

---

## A) MIGRATION SET (all additive; `database/migrations/2026_07_xx_*`)

### D-1 `evolve_executives_tables.php`

**`executives`** (keep stub table; evolve — ESM-16):

| change | detail |
|---|---|
| ADD CHECK `executives_status_check` | `status IN ('forming','delegated','conversion_voted','elected','dissolved','reverted')` — the stub has NO status CHECK today (only `type`); backfill is a no-op (all rows `forming`). `modified` from A.4 is dropped as a status: modification is an event (`exec_office_alter` process row + audit), not a resting state — ESM-16's `[Modified]` is bracketed/transient. |
| `delegation_law_id uuid NULL` FK laws nullOnDelete | F-LEG-014 act |
| `delegated_scope text NULL` | explicit scope text from the delegation act (order scope validation input) |
| `conversion_process_id uuid NULL` FK multi_jurisdiction_votes nullOnDelete | F-LEG-015 dual-supermajority process |
| `conversion_law_id uuid NULL` FK laws nullOnDelete | the F-LEG-015 creation/conversion act (charter of the elected office) |
| `converted_at timestamptz NULL` | |
| `delegated_member_count smallint NULL` CHECK `>= 5` | size of the delegated committee fixed by the act |

One row per jurisdiction is preserved (`executives_jurisdiction_unique` stays): **conversion evolves the same row** (committee→individual flips `type`); the delegated era's member rows are closed, never deleted. No second `executives` row, ever — ESM-16 is one machine.

**`executive_members`** evolve:

| change | detail |
|---|---|
| `legislature_member_id uuid NULL` FK legislature_members nullOnDelete | delegated members remain seated legislators (Westminster framing) |
| `elected_in_race_id uuid NULL` FK election_races nullOnDelete | elected era provenance |
| `term_id uuid NULL` FK terms nullOnDelete | elected members + advisors get terms rows; **NULL for delegated members** (ex officio — their term IS their legislative seat's term via `legislature_member_id`; duplicating it would create a second lockstep source of truth) |
| `selection varchar(24) NOT NULL DEFAULT 'delegated_proportional'` CHECK in (`delegated_proportional`,`elected_stv`,`elected_rcv`,`advisor_derivation`,`succession`) | the provenance column the prompt asks for |
| `status varchar(16) NOT NULL DEFAULT 'seated'` CHECK in (`seated`,`left`,`removed`,`succeeded`,`term_ended`) | replaces bare joined_at/left_at semantics (dates kept) |
| Partial unique `executive_members_one_principal` | `UNIQUE (executive_id) WHERE role='principal' AND status='seated' AND deleted_at IS NULL` — **only when `type='individual'`** → enforce as engine rule, not index (type lives on parent); keep index `(executive_id, status)` |

Existing `rank 0–4` CHECK + `role principal|advisor` CHECK stay (advisors rank 1–4; all principals rank 0 — multiple rank-0 rows are legal for the committee model).

**`elections`** add: `executive_id uuid NULL` FK executives nullOnDelete (office being filled), `board_id uuid NULL` FK boards nullOnDelete (worker/owner board tracks — **coordination point: the orgs designer consumes this same column for WF-ORG-05**; it lands here, once). `elections_kind_check` already contains `executive`/`org_board_owner`/`org_board_worker` — no recut.

**`election_races`**: recut `election_races_seat_kind_check` to add `exec_committee`, and recut `election_races_seats_check`:

```sql
CHECK ( (seat_kind IN ('type_a','type_b') AND seats BETWEEN 1 AND 9)
     OR (seat_kind = 'single'         AND seats = 1)
     OR (seat_kind = 'exec_committee' AND seats >= 5) )
```

Art. III §2 floors the committee model at 5 and states no ceiling — the blanket 1–9 CHECK would wrongly cap it. Individual-model races reuse the existing `single` kind (the only single-winner race in the system; `election.kind='executive'` disambiguates).

### D-2 `create_boards_tables.php` — THE UNIFIED set (I own the design; orgs designer consumes)

**`boards`**:

| column | type / constraint |
|---|---|
| id | uuid PK default gen_random_uuid() |
| boardable_type varchar(32) + boardable_id uuid | `departments` \| `organizations` (CGC = an organization row; no third type) |
| owner_seats smallint NOT NULL CHECK ≥ 1 | departments: appointed-governor seats per charter; orgs: owner-elected seats |
| worker_seats smallint NOT NULL DEFAULT 0 | co-determination engine OUTPUT — only `CoDeterminationService` writes it |
| worker_headcount int NOT NULL DEFAULT 0 | denormalized; recomputed by headcount events (workers substrate is the orgs designer's table — see contract below) |
| chair_seat_id uuid NULL | FK board_seats (added post-create), the joint chair |
| composition_valid boolean NOT NULL DEFAULT true | recomputed on every seat/headcount change; **false blocks board acts** (engine rule: a board chamber-vote cannot open while invalid, except the worker-seat election + chair election that cure it) |
| status varchar(12) CHECK (`forming`,`active`,`dissolved`) | |
| timestamps + soft deletes | |
| Partial unique `(boardable_type, boardable_id) WHERE deleted_at IS NULL` | one board per body |

**`board_seats`**:

| column | type / constraint |
|---|---|
| id, board_id FK cascade | |
| seat_class varchar(16) CHECK (`governor`,`owner_elected`,`worker_elected`) | departments use `governor` + `worker_elected`; private orgs `owner_elected` + `worker_elected`; CGC `governor` + `worker_elected` (owner ruling #12: the BoG stands where shareholders would) |
| seat_no smallint NOT NULL | |
| holder_user_id uuid NULL FK users nullOnDelete | |
| appointment_id uuid NULL FK appointments nullOnDelete | governor pipeline (F-EXE-001 → F-LEG-020) |
| elected_in_race_id uuid NULL FK election_races nullOnDelete | owner/worker STV tracks |
| term_id uuid NULL FK terms nullOnDelete | governors: `civil_appointment` 10y; worker/owner-elected: `lockstep` (mockup PW&U: worker terms end 2035-11-01 with the legislature) — both via the existing `terms` table, `office_kind='board_governor'` |
| is_chair boolean NOT NULL DEFAULT false | |
| status varchar(20) CHECK (`vacant`,`nominated`,`seated`,`removal_requested`,`removed`,`term_ended`) | |
| timestamps + soft deletes | |
| Partial uniques | `(board_id, seat_no) WHERE deleted_at IS NULL`; `(board_id) WHERE is_chair AND status='seated' AND deleted_at IS NULL` |

**Worker-headcount contract (binding for the orgs designer):** the workers table (F-IND-014 substrate) must carry a polymorphic employer (`employer_type` `organizations`|`departments`, `employer_id`) so departments hire through the *same* table — Art. III §6 applies identically and the headcount source must be singular. Every insert/end of a worker row calls `CoDeterminationService::recompute($board)`; a nightly CLK-13/CLK-14 sweep over `boards` is the safety net (same pattern as CLK-06).

**Co-determination formula** (one engine, lives with boards in `App\Services\CoDeterminationService`, thresholds resolved per jurisdiction through `SettingsResolver` — converting the constitutional placeholders):

```php
requiredWorkerSeats(w, owner, min=worker_rep_min_employees, parity=worker_rep_parity_employees)
  = w < min ? 0 : max(1, min(owner, (int) round((w - min) / ($parity - $min) * owner)))
```

Cross-key hardened guard added to `ConstitutionalValidator`: `worker_rep_min_employees < worker_rep_parity_employees` (the per-key min/max bounds already exist at lines 185–186; the relational check does not — add `settings.codetermination_ordering` rule, citation Art. III §6).

### D-3 `create_departments_tables.php`

**`departments`** (ESM-17):

| column | detail |
|---|---|
| id, jurisdiction_id FK restrict | |
| executive_id FK executives restrict | oversight assignment (named in the creation act) |
| kind varchar(20) CHECK (`chief_executive`,`treasury`,`defense`,`state`,`justice`,`other`) | |
| name varchar | |
| charter_law_id uuid FK laws restrict | the F-LEG-016 act (law kind `charter`) |
| reporting_interval_months smallint NULL | from charter; drives WF-EXE-09 cadence |
| board_id uuid NULL FK boards | set when the board row is created in the same adoption transaction |
| status varchar(24) CHECK (`chartered`,`oversight_assigned`,`governors_nominated`,`consented`,`operating`,`reporting`,`rechartered`,`dissolved`) | |
| timestamps + soft deletes | |
| Partial unique `(jurisdiction_id, kind) WHERE kind <> 'other' AND deleted_at IS NULL` | one Treasury per jurisdiction etc. |

**Mandatory-set seeding posture: NOT auto-seeded.** Art. II §9 says *legislatures create* the five named departments — auto-creating them at delegation would bypass the chamber. The departments surface renders a mandatory-five checklist (missing kinds flagged) and offers a batch "file all five" convenience that opens five independent F-LEG-016 ordinary-majority votes. The engine never blocks an executive for missing departments; the checklist is the nudge.

### D-4 `create_executive_actions_tables.php`

**`executive_orders`** (F-EXE-005):

| column | detail |
|---|---|
| id, executive_id FK, issued_by_member_id FK executive_members | |
| department_id uuid NULL FK departments | |
| order_no varchar(20) | `EO-YYYY-NN` per executive per year |
| title varchar, body text | |
| enabling_type varchar(20) CHECK (`law`,`emergency_power`,`charter`) + enabling_id uuid | the cited instrument (charter ⇒ a departments.charter_law_id; stored as the law id with type `charter` for uniform scope checks) |
| target_domain varchar(24) CHECK (`department_operations`,`public_works`,`emergency_response`,`administration`,`other`,`electoral_process`,`judicial_process`,`legislative_process`) | structured civic-process-protection input — the last three are auto-reject values (kept in the enum so the *attempt* is typed honestly) |
| status varchar(24) CHECK (`drafted`,`scope_validated`,`issued`,`rejected_pre_issuance`,`under_review`,`struck`,`revoked`) | |
| rejection_citation varchar NULL, rejection_reason text NULL | CHECK `(status='rejected_pre_issuance') = (rejection_citation IS NOT NULL)` |
| record_id uuid NULL | public_records linkage (issued AND rejected orders both publish) |
| judicial_review_case_id uuid NULL — **no FK** | Phase E hook; FK added when `cases` exists |
| issued_at timestamptz NULL, timestamps + soft deletes | |

**`policy_proposals`** (F-EXE-002): id, executive_id FK, department_id FK, proposed_by_member_id FK executive_members, title, text, board_vote_id uuid NULL FK chamber_votes, decision varchar(12) CHECK (`pending`,`adopted`,`amended`,`declined`), amended_text text NULL, decided_at, timestamps+sd. The board adopts/amends/declines — proposals never bypass the board.

**`executive_investigations`** (F-EXE-004): id, executive_id FK, department_id NULL FK, ordered_by_member_id FK, scope text, records_access jsonb default `'[]'` (declarative — see deferrals), findings_record_id uuid NULL (public_records), outcome varchar(24) CHECK (`open`,`policy_proposal`,`removal_request`,`legislative_referral`,`closed_no_finding`), outcome_ref_type varchar NULL + outcome_ref_id uuid NULL (the F-EXE-002/F-EXE-003/I-ADM row the branch produced), timestamps+sd.

**`governor_removal_requests`** (F-EXE-003): id, board_seat_id FK board_seats, requested_by_member_id FK executive_members, grounds text NOT NULL (good-faith finding, published), record_id uuid (grounds go on the public record at filing), vote_id uuid NULL FK chamber_votes, outcome varchar(12) CHECK (`pending`,`removed`,`retained`), decided_at, timestamps+sd. Deliberately NOT `removal_proceedings`: governor removal is ordinary-majority hiring-and-firing (owner ruling #14), no Speaker-presides/impeachment trappings — folding it into the supermajority machinery would invite threshold drift.

### D-5 `create_department_rules_reports.php`

**`department_rules`** (F-BOG-001): id, department_id FK, rule_code varchar (`{DEPT}-R-YYYY-NN`), name, text, enabling_type varchar(20) CHECK (`law`,`emergency_power`,`charter`) + enabling_id uuid, expires_with_enabling boolean default false (true when enabled by an emergency power — `EmergencyPowerService` expiry/struck hook flips dependent rules to `expired`, the CLK-03 cascade the mockup shows), version_no smallint, supersedes_rule_id uuid NULL self-FK, filed_by_seat_id FK board_seats, record_id uuid, status varchar(12) CHECK (`draft`,`in_force`,`superseded`,`expired`), timestamps+sd. Engine rule at filing: enabling instrument exists, is in force/active, and its jurisdiction covers the department's — "rules implement, they cannot exceed" is enforced structurally (citation existence + status + scope); semantic excess is judicial-review territory (Phase E).

**`department_reports`** (F-BOG-002): id, department_id FK, kind varchar(8) CHECK (`periodic`,`special`), period_label varchar, due_on date, filed_at timestamptz NULL, filed_by_seat_id uuid NULL FK board_seats, recipients jsonb default `'["executive","legislature"]'`, record_id uuid NULL, status varchar(8) CHECK (`due`,`filed`,`overdue`), timestamps. **Cadence mechanics:** on department reaching `operating`, seed the first `periodic` row at `charter date + reporting_interval_months`; on filing, create the next row. A nightly sweep flips `due → overdue`. **No new clock code** — the 21-clock registry is a constitutional artifact and reporting cadence is charter data, not a constitutional clock; plain `due_on` + sweep suffices (justified deferral from clock_timers).

### D-6 `create_appropriations_grants.php` (minimal viable per executive-actions contract)

**`appropriations`**: id, law_id FK laws restrict (legislatures appropriate by act), jurisdiction_id, executive_id FK (administering), line varchar, amount numeric(18,2), remaining numeric(18,2), status varchar(12) CHECK (`active`,`exhausted`,`lapsed`), timestamps+sd, CHECK `remaining BETWEEN 0 AND amount`.

**`grant_applications`**: id, appropriation_id FK, applicant_org_id FK organizations, amount numeric(18,2) CHECK > 0, purpose text, status varchar(12) CHECK (`submitted`,`awarded`,`declined`,`withdrawn`), decided_by_member_id uuid NULL FK executive_members, decided_at, timestamps+sd.

**`grant_disbursements`**: id, application_id FK, amount numeric(18,2) CHECK > 0, disbursed_by_member_id FK, disbursed_at, created_at (append-only — no updates/soft deletes). Service invariants (FOR UPDATE on the appropriation): award ≤ remaining; Σ disbursements ≤ awarded; every award/disbursement audit-chained (WF-SYS-04) + published.

**Deferral:** appropriations rows are created by a system action against an already-enacted law (operator/exec attaches lines citing the act) — no dedicated appropriations-bill UI or budget module in D. Full budgeting is post-F backlog; the contract only requires the register + application + audit-chained disbursement.

---

## B) EXECUTIVE FORMATION FLOWS

### B.1 F-LEG-014 — delegation (WF-EXE-01)

New service `App\Services\Executive\ExecutiveFormationService`; new proposal kind `ChamberVoteProposal::KIND_EXEC_DELEGATION`.

1. **File** F-LEG-014 (handler `ExecutiveDelegationAct`): payload `{delegated_scope: text, member_count: n ≥ 5}`. Engine checks: actor R-09 of this legislature; jurisdiction's executive exists in `forming` (stub rows already live for San Marino/Montegiardino/Earth); `n ≥  5` (Art. III §2 via validator) and `n ≤ serving`. Proposal → `ChamberVoteService::open` vote type **`exec_delegate`** (supermajority, registry key already present).
2. **Adoption** (votable-effect dispatch, same txn): `EnactmentService::enactDirect(legislature, 'creation_act', title, scopeText, vote)` → `delegation_law_id`; executive `forming → delegated`, `delegated_scope`, `delegated_member_count` set.
3. **Proportional member selection — DECISION: reuse `CommitteeAssignmentService::assign()` verbatim.** Art. III §2: composed "in the same manner as legislative committees". Model the delegated executive as ONE synthetic committee of `n` seats and run the existing pure function:
   - Bicameral chambers: split `n` across `type_a`/`type_b` by the chamber's serving-kind proportions (Webster rounding), exactly as `CommitteeService` sizes committee kind-seats — the exec committee mirrors Art. V §3 structure.
   - `members` input = current serving members with `vote_share_norm` shares; `preferences` input = an opt-in interest ranking collected as proposal payload (members who declared interest rank first; absent = default order, same rule as F-LEG-010).
   - With placements P = n < members M, `assign()` gives `base = 0` and the n "extras" go to the **highest normalized-quota vote shares** — i.e., selection falls out of the #q2 proportionality currency with zero new math, deterministic, already pinned by `CommitteeAssignmentTest`.
   - Output → `executive_members` rows: role `principal`, rank 0, `selection='delegated_proportional'`, `legislature_member_id` set, `user_id` copied, `term_id` NULL (ex officio). Full snapshot is the audit payload (same posture as F-SPK-005).
4. Public record (`act` kind) + `RoleService` flush → members derive **R-14**.
5. Delegated members leave the executive when their legislative seat ends (CertificationService chamber turnover hook closes `executive_members` where `selection='delegated_proportional'`), or via supermajority removal (B.4).

### B.2 F-LEG-015 — conversion to elected (WF-EXE-02) — FIRST live MultiJurisdictionVoteService consumer

New proposal kind `KIND_EXEC_CONVERSION`; handler `ExecutiveOfficeCreationAct` with modes `create|convert` (and `alter`, B.3).

1. **File** F-LEG-015: payload `{target_type: committee|individual, member_count (committee: ≥5), charter_text}`. Validator: `committee ⇒ member_count ≥ 5` (Art. III §3).
2. **Chamber leg**: vote type **`exec_office_create`** (supermajority, `dual = constituent_supermajority` in the registry).
3. **Adoption** → `enactDirect` law (`conversion_law_id`); then:
   - **Constituents = direct child jurisdictions holding a non-dissolved legislature** (a body that can vote). Children without legislatures cannot consent; their absence is published in the process record. *(Flag: q-ledger candidate — the constitution doesn't define "constituent" for consent purposes; this matches the WF-JUR-04 precedent of legislature-bearing constituents.)*
   - None exist → conversion completes immediately (Art. III §3 "where constituents exist").
   - Else → `MultiJurisdictionVoteService::open('exec_office_create', $legislature, $constituentIds, BASIS_SUPERMAJORITY, $vote, 'executives', $execId)`; executive → `conversion_voted`, `conversion_process_id` set. `required = ConstitutionalValidator::supermajority(total)` — already wired.
4. **Constituent consent flow (the new UX):** each constituent legislature receives a queue item ("Consent requested: conversion of {parent} executive office — F-LEG-015"). Any R-09 of that chamber moves to decide → opens that chamber's own vote with **votable_type `constituent_consent`** (new arm in `ChamberVoteService::votableType()` / `dispatchVotableEffects()`, routing to `MultiJurisdictionVoteService` — built generically so Phase E judiciary conversion and Art. VII reuse it). Internal threshold of each constituent chamber: **ordinary majority of all serving** (`procedural_motion` class) — the constitution states the supermajority *across* jurisdictions, the per-chamber threshold is unstated → MANIFEST §8 owner ruling. On close: `recordConsent(process, jurisdictionId, adopted, $vote, $legislatureId)`; `evaluate()` flips the process the moment arithmetic decides (already implemented). Parent surface renders the two meters straight off `chamber vote tallies` + `multi_jurisdiction_votes.yes_count/required`.
5. **Process passes** → `ElectionLifecycleService` schedules the executive election: `elections` row kind `executive`, `executive_id`, `legislature_id` (lockstep anchor), `election_board_id` = the jurisdiction's active board; one race —
   - committee model: `seat_kind='exec_committee'`, `seats = member_count`, counted by `countStv` (PR-STV/Droop/Gregory, untouched);
   - individual model: `seat_kind='single'`, `seats=1`, counted by `countRcv`, then **`deriveAdvisors`** (exists) for ranks 1–4.
6. **Certification integration** (`CertificationService::certify` grows a race-type dispatch — currently legislature-only):
   - exec races route to `seatExecutiveWinners()`: `executive_members` rows (`selection='elected_stv'|'elected_rcv'`), advisors from `deriveAdvisors` ranks 1–4 (`role='advisor'`, `selection='advisor_derivation'`; underivable ranks stay vacant per the engine contract).
   - **Terms (lockstep):** every elected member AND advisor gets a `terms` row `office_kind='executive_seat'`, `term_class='lockstep'`, `legislature_id` set. **First election after conversion uses `inheritedWindow` — ends on the legislature's current `term_ends_on`** (term = remainder; lockstep is never reset by conversion). Thereafter exec races ride the general election: `armNextGeneralElection`/`openSuccessor` extended to add the exec race whenever the jurisdiction's executive is `elected` (CLK-01/CLK-10 — one clock, no separate exec cycle).
   - On seating: executive `conversion_voted → elected`, `converted_at` set, `type` set to target; delegated-era member rows closed (`status='left'`, `left_at`); executive `term_starts_on/term_ends_on` mirror the window. I-EXC dissolves into I-EEO on the same row.
7. **Succession (individual model):** principal vacancy (death/resignation/removal) → `vacancies` row `seat_type='executive_members'` → lowest-rank seated advisor flips `role='principal'` (`selection='succession'`, same term — inherited, never extended). Advisors exhausted → special executive election in the CLK-04 window (countback for a 1-seat RCV race is definitionally the advisor derivation, so the advisor chain *is* the countback; special election is the constitutional fallback).

### B.3 WF-EXE-03 — alter existing elected office

Same handler, mode `alter`: **constituent supermajority ONLY** (registry `exec_office_alter`, engine `multi_jurisdiction`, no chamber-supermajority leg). Opens the process directly; on pass, applies the payload change (e.g. committee size) + audit + record. UI: parent meter only. Minimal surface in D (service + handler + meter), full alteration UX deferred.

### B.4 Removal by supermajority (oversight reuse)

`OversightService` removal proceedings extended: `subject_type='executive_members'` accepted in `intake()`/proceedings; `RemovalProceeding::KIND_EXECUTIVE_REMOVAL` (already in the model) → supermajority vote (`officeholder_remove`) → on adoption: member `status='removed'`; **elected** member → vacancy row → countback/succession path; **delegated** member → removed from the executive only (their legislative seat is untouched — expelling the legislator is a separate F-LEG-022); a delegated committee dropping below 5 triggers an immediate re-run of the B.1 step-3 selection for the open slots (audit-chained top-up, same algorithm). Removal parity: identical threshold and machinery as legislators.

---

## C) DEPARTMENTS + BOARDS OF GOVERNORS

### C.1 F-LEG-016 — Department Creation Act (WF-EXE-04)

New proposal kind `KIND_DEPARTMENT_CREATION`, vote type **`procedural_motion`** (ordinary majority — the mockup threshold table places department creation in the ordinary class; precedent: F-LEG-013). Payload: `{name, kind, executive_id (oversight — must be this jurisdiction's executive, status delegated|elected), charter: {function_text, powers_text, reporting_interval_months}, owner_seats (governor count), nominees?: user_id[]}`.

Adoption (one transaction): `enactDirect` law kind **`charter`** → `departments` row (`chartered → oversight_assigned` immediately — oversight is named in the act) → `boards` row (`boardable=department`, `owner_seats`, `worker_seats=0`, governor `board_seats` rows `vacant`) → optional nominations (C.2) → first `department_reports` periodic row seeded → public record. Mandatory-five checklist per A/D-3 posture.

### C.2 F-EXE-001 nomination → F-LEG-020 consent → seating (WF-EXE-05)

New `App\Services\Executive\BoardGovernorService`, **generalizing the ChamberActService pipeline rather than paralleling it**:

1. **Refactor (small, surgical):** extract `ChamberActService::openCivilTerm()` (lines 574–615) into shared `App\Services\CivilAppointmentService::openCivilTerm(...)` — identical signature, same CLK-09 arm. Election-board and admin-office call sites delegate; BoardGovernorService is the third consumer (`office_kind='board_governor'`, `office_type='board_seats'`). One CLK-09 path, zero behavioral change to Phase C (TermLockstepTest still green).
2. **Nominate** (handler `BoardGovernorNomination`, F-EXE-001): actor holds R-14/15/16 on the overseeing executive; nominee eligibility = active jurisdiction association only (Art. I — neutrality is a duty of office, not an eligibility test; same posture and citation as F-LEG-012 nominees). Dossier text published at nomination. Creates `Appointment` (`appointable_type='board_seats'`, `appointable_id=$seat->id`, `nominated_via_form='F-EXE-001'`) + opens consent vote in the **legislature**, vote type **`bog_consent`** (ordinary majority of all serving — registry key exists). Department → `governors_nominated`. F-LEG-020 itself stays unregistered as a handler — it is the consent *vote*, cast via F-LEG-004 like every other consent (existing FormRegistry posture, comment at `FormRegistry.php:269`).
3. **Consent close**: extend `ChamberActService::resolveConsentVote()` match with `'board_seats' => app(BoardGovernorService::class)->seat($appointment)`. Seat: `board_seats` → `seated`, holder set; civil term opened (10y, `civil_appointment_years` via SettingsResolver; CLK-09 armed at `ends_on`); certification public record; roles flushed → **R-18**. Rejection → appointment `rejected`, seat back to `vacant`, renominate (loop).
4. Department status: all governor seats seated AND `composition_valid` → `consented → operating`.
5. **Term expiry**: CLK-09 fire → seat `term_ended` → renomination opens (the mockup's "renomination open" chip) — handled by the CLK-09 handler job mapping for `board_governor` terms.

### C.3 Governor removal (WF-EXE-06, owner ruling #14)

Handler `BoardMemberRemovalRequest` (F-EXE-003): exec member files good-faith competence/ethics grounds → `governor_removal_requests` row, grounds published immediately, seat → `removal_requested` → legislature vote, **ordinary majority** (`procedural_motion` class; deliberately NOT `officeholder_remove` — hiring-and-firing, supermajority applies only where the constitution states it). Votable arm `'governor_removal'` → `BoardGovernorService::resolveRemovalVote`: removed → seat `removed`, term `removed`, CLK-09 timer cancelled, roles flushed, renomination flow opens; retained → seat back to `seated`. Constitutional test pins the threshold asymmetry (see E).

### C.4 Worker seats + chair on department boards (CLK-13/14 — the shared engine)

- `CoDeterminationService::recompute(Board)` (event-driven + nightly sweep): recomputes `worker_headcount`, required worker seats (formula in A/D-2); if required > current → create `worker_elected` seats (`vacant`), `composition_valid=false`, schedule worker-track election (`elections` kind `org_board_worker`, `board_id` set, race `electorate_type='workers'`, seats = delta, STV); if required < current → seats retire at term end (never mid-term unseating — same posture as committee recheck).
- Worker-elected seating at certification: `term_class='lockstep'` ending at the overseeing legislature's `term_ends_on` (PW&U contract), `selection` provenance via `elected_in_race_id`.
- **Joint chair**: any composition change re-triggers chair election — chamber vote `body_type='board'` (`BODY_BOARD` exists), `body_id=board_id`, vote type **`committee_chair`** reused (single-winner RCV, majority of all serving members of the body — identical mechanism; **flagged registry gap**, same precedent as ChamberActService's `committee_create` reuse). Votable arm `'board'` → chair seat set, `boards.chair_seat_id` updated, `is_chair` flipped.
- `composition_valid=true` once required seats are seated + chair elected.

This section is the substrate the orgs designer consumes for WF-ORG-04/05 — identical code path, different `boardable`.

---

## D) EXECUTIVE ORDERS (F-EXE-005) — pre-issuance scope validation

`App\Services\Executive\ExecutiveOrderService` + handler `ExecutiveOrder`.

**Validator rules (added to `ConstitutionalValidator` — PROTECTED, constitutional-review path):**

1. `order.enabling_instrument` — order MUST cite an enabling instrument that exists and is live: a law `in_force` whose `jurisdiction_id`/scale binds the executive's jurisdiction; OR an `active` emergency power whose area covers the order's target; OR a department charter (law kind `charter`) of a department this executive oversees. Citation: Art. III §2.
2. `order.scope_containment` — `department_id` (if any) must be overseen by this executive; the order cannot reach outside `executives.jurisdiction_id` + `delegated_scope`; emergency powers widen scope **only** within their declared area and duration (cross-check `expires_at`/`area_jurisdiction_id`). Citation: Art. III §2 / Art. II §7.
3. `order.civic_process_protection` (hardened) — `target_domain ∈ {electoral_process, judicial_process, legislative_process}` is rejected unconditionally; citation Art. II §7 (the mockup's rejected fixture: deferring a ranked-window opening). Semantic evasion is Phase E judicial-review territory; the structured enum is the honest engine-checkable floor.

**Rejection-on-record mechanics (the exit criterion):** scope failure during F-EXE-005 is a *domain outcome*, not only an exception. `ExecutiveOrderService::issue()` runs the three rules; on violation it (a) persists the `executive_orders` row `status='rejected_pre_issuance'` with `rejection_citation`/`rejection_reason`, (b) publishes a public record ("Executive order rejected pre-issuance — {citation}", subject = the order row), (c) appends the `rejected=true` audit chain row with citation (engine denial path), then surfaces 422. All three artifacts exist for the constitutional test to assert.

**Lifecycle:** `drafted` (optional save) → `scope_validated` (pre-flight `validate()`, UX only — `issue()` re-runs everything) → `issued` (order_no assigned, published, `record_id`) | `rejected_pre_issuance` → `under_review`/`struck` via `judicial_review_case_id` (Phase E hook field only in D) | `revoked` (by the issuing executive, scope-checked like issuance).

**F-EXE-002 / F-EXE-004 (same surface):** policy proposals route to the department board for a `body_type='board'` yes/no vote (`procedural_motion`) — adopted/declined; `amended` recorded when the board files `amended_text` before voting (minimal per contract). Investigations: row + declared `records_access` + published findings → outcome branch files F-EXE-002, F-EXE-003, or refers to I-ADM intake (`OversightService::intake` — subject list extended with `executive_members`/`board_seats`), or closes.

---

## E) HANDLERS, ROLES, TESTS, WORK ITEMS

### E.1 Handler registrations (`app/Domain/Forms/Handlers/`, wired in `FormRegistry::HANDLERS`)

| Form | Handler class | Engine effect |
|---|---|---|
| F-LEG-014 | `ExecutiveDelegationAct` | proposal `exec_delegation` → supermajority → law + delegated members |
| F-LEG-015 | `ExecutiveOfficeCreationAct` | proposal `exec_conversion` → supermajority → MJV process → election |
| F-LEG-016 | `DepartmentCreationAct` | proposal `department_creation` → majority → charter law + department + board |
| F-EXE-001 | `BoardGovernorNomination` | appointment (`board_seats`) + `bog_consent` vote |
| F-EXE-002 | `DepartmentPolicyProposal` | row + board vote |
| F-EXE-003 | `BoardMemberRemovalRequest` | row + ordinary-majority chamber vote |
| F-EXE-004 | `DepartmentInvestigationOrder` | row; outcome branches file sibling forms |
| F-EXE-005 | `ExecutiveOrder` | scope-validated issuance / rejection-on-record |
| F-BOG-001 | `DepartmentRuleImplementation` | versioned rule citing enabling instrument |
| F-BOG-002 | `DepartmentReportFiling` | report filed → record → next periodic row |
| (F-LEG-020 stays unregistered — consent votes cast via F-LEG-004, existing posture) | | |

New `ChamberVoteService` votable arms: `constituent_consent`, `governor_removal`, `policy_proposal`, `board` (chair), plus `chamber_vote_proposal` kinds `exec_delegation`, `exec_conversion`, `department_creation` resolved in a new `ExecutiveActService` (sibling of ChamberActService — keeps the Phase C class from growing unboundedly; same proposal→vote→adoption pattern, shared `propose()` helper extracted or duplicated 25 lines).

### E.2 Role derivations (`app/Services/RoleService.php` additions — derived, never stored)

| Role | Derivation (authoritative seat rows) |
|---|---|
| R-14 | seated `executive_members` principal, `selection='delegated_proportional'`, executive `status='delegated'` |
| R-15 | seated principal, executive `type='committee'`, `status='elected'` |
| R-16 | seated principal, executive `type='individual'`, `status='elected'` |
| R-17 | seated `executive_members` `role='advisor'` (rank 1–4) |
| R-18 | seated `board_seats` on a **department** board (`governor` and `worker_elected` classes both — worker-elected department members ARE governors per Art. III §6 and need F-BOG capability; **flagged registry tension** with R-27's org framing) |
| R-26/R-27/R-28 | org-board seat classes — orgs designer wires onto the same `board_seats` rows |
| R-30 | active `civil_appointment` term `office_kind='civil_officer'` (department staff via `appointments` `appointable_type='departments'` — thin slice in D: nomination/consent reuses the C.2 pipeline) |
| R-23 | already derived (Phase B substrate) |

### E.3 Constitutional test specs (`tests/Constitutional/`)

1. **ExecDelegationProportionalityTest** — selection = `CommitteeAssignmentService::assign()` over one n-seat committee (reflection-pin: no parallel selection math exists); n<5 rejected citing Art. III §2; bicameral kind-split mirrors chamber proportions; supermajority threshold via `ConstitutionalValidator::supermajority` only.
2. **ExecConversionDualSupermajorityTest** — constituents exist ⇒ chamber adoption alone never flips status past `conversion_voted`; `required = supermajority(total)`; no constituents ⇒ immediate; per-constituent chamber threshold = ordinary majority (owner ruling pin).
3. **ExecTermLockstepTest** (extends TermLockstepTest) — first elected exec term `inheritedWindow` to the legislature's `term_ends_on`; no API mutates `ends_on`; succession/advisor terms inherit, never extend.
4. **AdvisorSeatingTest** — RCV win seats ranks 1–4 via `deriveAdvisors` sequential exclusion; underivable ranks vacant; succession flips lowest seated rank, `selection='succession'`, same term identity.
5. **GovernorPipelineTest** — F-EXE-001→`bog_consent` (majority of ALL serving — vacancy in denominator), 10y CLK-09 term armed, neutrality never an eligibility check (association is the only gate).
6. **GovernorRemovalOrdinaryMajorityTest** — removal passes at majority and is structurally NOT `officeholder_remove` (owner ruling #14 pinned); contrast assertion: executive-member removal requires supermajority.
7. **OrderScopeValidationTest** — THE exit criterion: out-of-scope order ⇒ `rejected_pre_issuance` row + `rejected=true` audit chain row with citation + public record entry, all three; electoral/judicial/legislative `target_domain` always rejected (Art. II §7); emergency-widened scope dies with the power.
8. **CoDeterminationFormulaTest** — boundary table (99→0, 100→1, parity→owner_seats, monotonic, ≤ owner_seats); thresholds resolved from settings; `min < parity` hardened guard; **shared fixture with the orgs designer** (100-worker auto-trigger E2E lives org-side, formula authority lives here).
9. **BoardCompositionValidityTest** — invalid composition blocks board votes except curing elections/chair vote; chair re-election forced on composition change.

### E.4 Work-item breakdown (exec scope; sizes S/M/L, deps, parallelism)

| WI | Size | Content | Deps / parallel-with |
|---|---|---|---|
| **WI-D1** Migrations D-1…D-6 | M | all schema above, one reviewable PR; includes seat_kind/seats CHECK recut + `elections.executive_id/board_id` | none — first; orgs designer's org migrations parallel (boards land HERE) |
| **WI-D2** CivilAppointmentService extraction + boards/board_seats models + CoDeterminationService + chair vote (`board` votable) | M | the shared substrate; unblocks orgs designer | WI-D1 |
| **WI-D3** F-LEG-014 delegation (proposal kind, ExecutiveActService, selection reuse, R-14) | M | exit-criterion leg 1 | WI-D1; parallel WI-D2 |
| **WI-D4** F-LEG-015 conversion + `constituent_consent` votable + MJV wiring + election scheduling + CertificationService race dispatch + advisors + succession + removal reuse | L | the largest item; touches PROTECTED CertificationService (constitutional review) | WI-D3 (status machine), WI-D1 |
| **WI-D5** F-LEG-016 departments + BoG pipeline (F-EXE-001/F-LEG-020/F-EXE-003) + CLK-09 renomination + R-18/R-30 | M | exit-criterion leg 2 | WI-D2; parallel WI-D4 |
| **WI-D6** Executive orders + policy proposals + investigations (validator rules — PROTECTED review) + rejection-on-record | M | exit-criterion leg 3 | WI-D1 only; parallel WI-D3/4/5 |
| **WI-D7** Department rules + reports (F-BOG-001/002) + cadence sweep + emergency-rule expiry hook | S | | WI-D5 |
| **WI-D8** Appropriations + grants (minimal) | S | | WI-D1 only; fully parallel |
| **WI-D9** Frontend: executive-home (model toggle + dual meters), departments, department-detail, department-reporting, executive-actions per EXPLORE_legislature_executive §2b contracts | L | consumes all services; start after WI-D3 lands, grow per WI | WI-D3+ |
| **WI-DT** Constitutional tests E.3 (woven through; CI merge gate) | M | | per-WI |

Critical path: D1 → D2 → D5 (governors) and D1 → D3 → D4 (conversion); D6/D7/D8 parallel. Exit criteria map: delegated exec governing departments with consented governors = D3+D5; 100-worker auto-trigger = D2 (+ orgs designer's F-IND-014); rejected order on the public record = D6.

### E.5 Deferrals (flagged, justified)

- **`executive_investigations.records_access` enforcement** — declarative jsonb only in D; a real record-ACL layer has no substrate until Phase E/F. Findings publication is the operative constitutional duty and IS built.
- **Appropriations bill UX / budget module** — register + audit-chained disbursement only (contract-minimal); legislating money via dedicated bill payloads is post-F backlog.
- **`exec_office_alter` full UX** — service/handler/meter only; alteration scenarios are rare and Phase E/F polish.
- **Sub-executives (`parent_executive_id`)** — column retained, no flows; no constitutional workflow defines them.
- **Judicial review of orders** — `judicial_review_case_id` field + `under_review/struck` states land now (cannot retrofit the state machine cheaply); FK + WF-JUD wiring is Phase E.
- **Department reports translation routing** — rides the existing public_records pipeline; no new i18n machinery.
- **F-LEG-015 via `bills.act_type='dual_supermajority'`** — deliberately NOT used: institution-creation acts ride the proposal machinery (Phase C precedent F-LEG-009/012/013); bills produce laws of general application. The bills enum value stays for genuine dual-supermajority legislation (Art. VII, Phase F).
- **Registry gaps flagged for the q-ledger:** board-chair vote reuses `committee_chair`; F-LEG-016/F-EXE-003 ride `procedural_motion` (ordinary class, unstated-threshold ruling); "constituent = legislature-bearing direct child"; R-18 covering worker-elected department governors.