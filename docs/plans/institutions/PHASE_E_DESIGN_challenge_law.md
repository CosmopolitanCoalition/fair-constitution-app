# PHASE E DESIGN — THE CONSTITUTIONAL CHALLENGE & LAW
The constitutional heart of Phase E · Art. IV §5 · the Phase E exit criterion
F-IND-016 challenge → F-JDG-004 finding → F-JDG-005 remedy+windows → three-path resolution → F-JDG-006 judicial remedy → law text edited, version history preserved

> **Scope boundary.** This document owns the Art. IV §5 *resolving questions of law* machinery: the `constitutional_challenges` table, F-IND-016, F-JDG-004/005/006/007/008, F-LEG-035, the per-case CLK-11/CLK-12 arming/firing, the amendments two-door, and the petition/emergency reserved-hold wiring. It does **not** own judiciary creation/seating, the `cases` lifecycle, jury empanelment, civil/criminal case filing (F-IND-017, F-ADV-*, F-JDG-001/002/003/009/010), or the R-19/R-20/R-21 role derivations — those belong to the sibling **PHASE_E_DESIGN_judiciary_core** doc. Where I depend on its substrate (`judiciaries`, `judicial_seats`, seated-judge roles, the `cases` row a finding attaches to) I name the dependency and the coordination point; I never redefine it.

Verified against the live worktree (`E:\fair-constitution-app\.claude\worktrees\practical-payne-17d537`):

- **The forward-references prior phases left for me are real and exact.** `Law::ORIGIN_JUDICIAL_REMEDY` + `Law::STATUS_STRUCK` + `Law.scope_judiciary_id` (`app/Models/Law.php:36,43,52`); `LawVersion::SOURCE_JUDICIAL_REMEDY` (`app/Models/LawVersion.php:24`); `EnactmentService::amendLaw()` **already special-cases** `SOURCE_JUDICIAL_REMEDY` as the *one* source that may pierce a CLK-19 referendum shield (`app/Services/EnactmentService.php:177`) — the judicial-remedy append path is wired, it just has no caller yet.
- `ClockTimer.override_value` exists (`jsonb`, cast `array`) and is documented as *"the Phase E per-case slot (CLK-11/CLK-12: window set by the judiciary per finding) — present now, written by nothing yet"* (`app/Models/ClockTimer.php:23,48,55`).
- The clock registry already seeds **CLK-11 "Judicial Veto Window"** (`fires_workflow` = `WF-JUD-05 override deadline`, `unit` `set_by_judiciary_per_finding`, `override_slot` `clock_timers.override_value`, basis Art. IV §5) and **CLK-12 "Legislative Remedy Timeframe"** (`fires_workflow` = `WF-JUD-05 auto-remedy trigger`, `unit` `reasonable_timeframe_set_by_judiciary`, basis Art. IV §5) — `database/seeders/ClockRegistrySeeder.php:148-165`. Both are `type='window'`, `amendable=false` (the *value* is per-case; the *clock* is hardened).
- The vote-type registry already carries **`judiciary_override`** (`category='supermajority'`, `engine='chamber'`, `basis='supermajority'`, `denominator='serving'`, `phase='E'`, citation Art. IV §5 — `config/constitution/vote_types.php:231`), plus `judiciary_create`/`judiciary_convert`/`judicial_election` for the sibling doc.
- The **petition review HOLD** is live and explicit: `Petition::STATUS_CONSTITUTIONAL_REVIEW`, `Petition.review_case_id`, `Petition.review_stub` (`app/Models/Petition.php:33,63,64`); `PetitionService::runSignatureAudit()` parks a passing petition at `constitutional_review` (`app/Services/PetitionService.php:422`) and `PetitionService::stubConstitutionalReview()` is the *"audited Phase C advance"* that records `petition.review.stub_validated` with `ref: 'F-JDG-008'` (`app/Services/PetitionService.php:469-500`). Phase E replaces the stub with the real F-JDG-008 review.
- The **emergency-powers review HOLD** is live: `EmergencyPower.judicial_review_case_id` + `EmergencyPower.review_outcome`, statuses `under_review | struck | narrowed` already in the enum and `LIVE_STATUSES` (`app/Models/EmergencyPower.php:33-41,59,60`). `ExpireEmergencyPowerJob` docblock notes a power "struck by Phase E review" is left untouched by CLK-03 (`app/Jobs/Clocks/ExpireEmergencyPowerJob.php:23`).
- The substrate I reuse is all proven: `EnactmentService::amendLaw()`/`enactDirect()`/`writeLaw()`; `ConstitutionalValidator::supermajority()` (PROTECTED, `:533` — `ceil(serving·2/3)` clamped to majority+1); `ChamberVoteService::dispatchVotableEffects()`/`votableType()` (the votable-arm pattern, `:1100-1148`); `ChamberVoteProposal::KIND_*` + the `chamber_vote_proposals_kind_check` recut pattern (`database/migrations/2026_06_23_000109_*`); `ClockService::arm()/fire()/cancel()` (`:103/152/207`) with `STEP_HANDLERS`/`HANDLERS` dispatch maps; `EvaluateClocksJob` sweep + `ClockTimer::scopeDue()`; `PublicRecordService` (kind `opinion`/`act`/`correction`); the hash-chained `AuditService::append()`; `SettingsResolver`; `MultiJurisdictionVoteService` (for the amendments constituent door).
- The mockup `mockups/judiciary/constitutional-challenge.html` is the binding surface contract: *"Fixture case: Novák finding on Curfew Ordinance §3 (New York County) — finding issued 2031-05-21, remedy due within 60 days (CLK-12), override window closes 2031-06-20 (CLK-11); all three Art. IV §5 paths open."* ESM strip: `Filed → Heard → Finding+Remedy-Issued → Legislative-Window-Open → Amended-by-Legislature | Overridden | Judiciary-Applies-Remedy → Law-Edited → Closed`. Workflow WF-JUD-05.

---

## 0) THE CONSTITUTION, VERBATIM (Art. IV §5 — what binds, clause by clause)

Every state transition below maps to one of these six clauses. They are reproduced exactly (from `docs/extracted/fair_constitution.md`, "Article IV – Section 5 - Judiciaries: Resolving Questions of Law").

| # | Clause (verbatim) | Binds |
|---|---|---|
| §5.1 | "All individuals who inhabit a Jurisdiction have the right to make claims against a Government if they believe a law is unjustly impeding their rights under a Fair Constitution or any other valid law." | **F-IND-016** — filer is **any inhabitant** (R-03); standing is association-only, an absolute right (Art. I). No fee, no eligibility test beyond residency. |
| §5.2 | "If the Judiciary finds that any legislation passed by a Legislature is contradictory to other law or The Constitution, it informs The Legislature of what laws are in error and recommends a remedy." | **F-JDG-004** (the finding: which law(s), against what) + **F-JDG-005** (informs the legislature, recommends a remedy). |
| §5.3 | "The Legislature modifies or removes the offending laws in a reasonable timeframe as outlined by the Judiciary." | **Path 1** within the **CLK-12** timeframe — judge-set, per case. |
| §5.4 | "A Supermajority of The Legislature may disagree with the Judiciary and overrule its judgement within a set Judicial veto window." | **Path 2** — **F-LEG-035** `judiciary_override` within the **CLK-11** window — judge-set, per case. |
| §5.5 | "If The Legislature does not modify the law nor override the Judiciary within the window, then the Judiciary applies its own remedy to the law directly to make the law non-contradictory and bring it in line with The Constitution." | **Path 3** — **F-JDG-006**: the windows expire with neither → judiciary edits the law text directly, appends a `law_version` `source='judicial_remedy'`, version history preserved. THE exit criterion. |
| §5.6 | "Executive Officers uphold constitutional order and the outcome of this process." | The executive enforcement hook (WF-EXE-07) — out of scope for build; the closed challenge publishes an `act`/`opinion` record the executive consumes. |

Two adjacent clauses also bind this doc:

- **Art. II §7** "Emergency Powers are subject to Judicial review." → **F-JDG-007**.
- **Art. II §6 / §8** (double jeopardy, petition kill-path) → **F-JDG-008** is the petition's constitutional kill-path; "All other Judgements can be overturned only by proven contradictions in law and errors found in the cases" (Art. II §8) is the standard the finding applies.

---

## A) MIGRATION SET (all additive; `database/migrations/2026_08_xx_*`, after judiciary-core's `cases` migration)

### E-1 `create_constitutional_challenges_table.php` — THE Art. IV §5 machine (ESM-CC)

The challenge is its own entity, distinct from a `case`. A challenge **may** be heard inside a `cases` row (the judiciary-core lifecycle), but the Art. IV §5 *resolution* (finding → windows → three paths) is challenge-scoped state that outlives any single hearing. One challenge ⇒ at most one finding ⇒ at most one remedy recommendation ⇒ exactly one terminal path.

**`constitutional_challenges`**:

| column | type / constraint | notes |
|---|---|---|
| `id` | uuid PK default `gen_random_uuid()` | |
| `jurisdiction_id` | uuid FK jurisdictions restrict | where the challenge is filed (the law's binding jurisdiction or a descendant) |
| `judiciary_id` | uuid FK judiciaries restrict | the court hearing it (judiciary-core's table; resolved from the law's `scope_judiciary_id` or the jurisdiction's active court — see B.1) |
| `challenged_law_id` | uuid FK laws restrict | the legislation alleged contradictory (§5.1) |
| `challenged_version_no` | smallint NOT NULL | the law version as of filing (challenges pin the text they attack; `law_versions(law_id, version_no)`) |
| `filed_by_user_id` | uuid FK users restrict | the inhabitant (R-03) — §5.1 |
| `claim_text` | text NOT NULL | "a law is unjustly impeding their rights" — the asserted contradiction |
| `claimed_basis` | varchar(20) CHECK (`constitution`,`other_law`) | §5.2 "contradictory to **other law or The Constitution**" |
| `cited_authority_law_id` | uuid NULL FK laws nullOnDelete | when `claimed_basis='other_law'`: the superior/conflicting law cited |
| `case_id` | uuid NULL FK cases nullOnDelete | the `cases` row the court opens to hear it (judiciary-core); NULL until F-JDG-001 panels it |
| `status` | varchar(28) — the ESM-CC enum below | |
| `finding_id` | uuid NULL FK constitutional_findings | set by F-JDG-004 |
| `remedy_id` | uuid NULL FK remedy_recommendations | set by F-JDG-005 |
| `resolution_path` | varchar(20) NULL CHECK (`legislative_amendment`,`legislature_override`,`judicial_remedy`,`dismissed`) | the terminal Art. IV §5 path; NULL until closed |
| `resolution_ref_type` varchar(40) NULL + `resolution_ref_id` uuid NULL | polymorphic pointer to the artifact that closed it (a `law_versions` row for Path 1/3, a `chamber_votes` row for Path 2, an `opinion` record for dismissal) |
| `filed_at`, `heard_at`, `finding_at`, `closed_at` | timestamptz NULL | stage stamps |
| `record_id` | uuid NULL | public_records linkage at filing (the challenge is public) |
| timestamps + soft deletes | | |
| Index `(challenged_law_id, status)`, `(judiciary_id, status)` | | dockets render off these |

**ESM-CC — `constitutional_challenges.status`** (CHECK enum; the mockup's state strip, made precise):

```
filed
  → under_review        (F-JDG-001 panels it — judiciary-core opens a cases row; case_id set)
  → dismissed           (court finds NO contradiction — F-JDG-003 opinion; terminal, resolution_path='dismissed')
  → finding_issued      (F-JDG-004 — contradiction found; finding_id set)
  → remedy_recommended  (F-JDG-005 — informs legislature + recommends remedy + ARMS CLK-11 & CLK-12)
  → legislative_window_open   (the resting state while both clocks run)
       ├→ amended_by_legislature  (Path 1: F-LEG-003→…→law amendment lands inside CLK-12 → both clocks cancelled)
       ├→ overridden              (Path 2: F-LEG-035 supermajority adopts inside CLK-11 → both clocks cancelled)
       └→ judicial_remedy_applied (Path 3: CLK-11 + CLK-12 both expire with neither → F-JDG-006 fires)
  → closed              (terminal; closed_at, resolution_path, resolution_ref set)
```

CHECK enum literal set: `('filed','under_review','dismissed','finding_issued','remedy_recommended','legislative_window_open','amended_by_legislature','overridden','judicial_remedy_applied','closed')`. The three branch states + `dismissed` all transition to `closed` (the closed state carries `resolution_path`); they are kept distinct so the docket shows *which* Art. IV §5 path resolved it.

**Why a challenge is not folded into `cases`:** a `cases` row is a hearing with a panel, severity, jury option (judiciary-core, CLK-16). The Art. IV §5 *windows* run for weeks-to-months **after** the hearing closes, gated on legislature action, not court action — they cannot live on a case that has already published its opinion. The challenge row is the durable subject the CLK-11/CLK-12 timers point at (`subject_type='constitutional_challenges'`), and the subject the legislature's amendment/override is checked against.

### E-2 `create_constitutional_findings_and_remedies.php`

**`constitutional_findings`** (F-JDG-004 — §5.2 first half):

| column | type / constraint | notes |
|---|---|---|
| `id` | uuid PK | |
| `challenge_id` | uuid FK constitutional_challenges cascade, **partial unique `WHERE deleted_at IS NULL`** | one finding per challenge |
| `judiciary_id` | uuid FK judiciaries restrict | |
| `case_id` | uuid NULL FK cases nullOnDelete | the hearing that produced it |
| `full_court` | boolean NOT NULL DEFAULT false | Art. IV §4 "Constitutional Questions of significant importance are heard by the entire court" — set true when the panel was the whole court (CLK-16 input, judiciary-core decides; this column records the fact) |
| `finds_contradiction` | boolean NOT NULL | F-JDG-004 always records the determination; `false` ⇒ challenge dismissed (no remedy) |
| `contradiction_against` | varchar(20) CHECK (`constitution`,`other_law`) | §5.2 |
| `superior_authority_law_id` | uuid NULL FK laws nullOnDelete | when `other_law` |
| `constitutional_citation` | varchar(64) NULL | when `constitution` (e.g. `Art. I`, `Art. II §8`) |
| `offending_law_id` | uuid FK laws restrict | "what laws are in error" — §5.2 |
| `offending_version_no` | smallint NOT NULL | the exact version found in error |
| `opinion_text` | text NOT NULL | the reasoning (published as an `opinion` record) |
| `panel_snapshot` | jsonb NOT NULL DEFAULT `'[]'` | the seated judges who concurred (audit completeness; same posture as F-SPK-005 snapshots) |
| `record_id` | uuid NULL | `opinion` public record |
| `issued_at` | timestamptz NOT NULL | |
| timestamps + soft deletes | | |

> **Multi-law findings — DECISION (q-ledger candidate).** §5.2 says "what law**s** are in error" (plural). The common case is one offending law; a finding implicating several is modeled as **one challenge → one finding row → N `finding_offending_laws` rows** rather than denormalizing onto the finding. To keep E lean and the exit criterion crisp, the V1 finding carries a **single** `offending_law_id` (the table above) and a child join table is the documented extension:

**`finding_offending_laws`** (built now, written only for multi-law findings; the single-law path leaves it empty and reads `findings.offending_law_id` directly):
`id`, `finding_id` FK cascade, `law_id` FK laws restrict, `version_no` smallint, `remedy_recommendation_id` uuid NULL (each offending law gets its own recommended remedy + its own window in the multi-law case), unique `(finding_id, law_id)`. *Flagged: the multi-law fan-out of CLK-11/CLK-12 (one window pair per offending law vs. one pair for the whole finding) is a real design fork — V1 builds the single-law spine and one window pair; the join table reserves the fan-out without committing the clock multiplicity. q-ledger entry.*

**`remedy_recommendations`** (F-JDG-005 — §5.2 second half + the §5.3/§5.4 window-setting):

| column | type / constraint | notes |
|---|---|---|
| `id` | uuid PK | |
| `finding_id` | uuid FK constitutional_findings cascade | |
| `challenge_id` | uuid FK constitutional_challenges cascade, **partial unique** | one recommendation per challenge |
| `judiciary_id` | uuid FK judiciaries restrict | |
| `remedy_kind` | varchar(16) CHECK (`modify`,`remove`) | §5.3 "modifies or removes" — the recommended disposition |
| `recommended_text` | text NULL | the proposed replacement text (for `modify`); the text F-JDG-006 will apply directly if the windows expire (§5.5). NULL ⇒ `remove` (repeal). |
| `rationale_text` | text NOT NULL | why this remedy makes the law non-contradictory |
| `remedy_timeframe_days` | smallint NOT NULL CHECK `> 0` | §5.3 "reasonable timeframe as outlined by the Judiciary" — **the judge-set CLK-12 value** |
| `veto_window_days` | smallint NOT NULL CHECK `> 0` | §5.4 "a set Judicial veto window" — **the judge-set CLK-11 value** |
| `remedy_due_at` | timestamptz NOT NULL | `issued_at + remedy_timeframe_days` (denormalized for docket sort + CLK-12 `fires_at`) |
| `veto_closes_at` | timestamptz NOT NULL | `issued_at + veto_window_days` (CLK-11 `fires_at`) |
| `clk11_timer_id` uuid NULL + `clk12_timer_id` uuid NULL | the two armed `clock_timers` (set in the F-JDG-005 transaction) |
| `record_id` | uuid NULL | the recommendation is published (the legislature is "informed" on the record — §5.2) |
| `issued_at` | timestamptz NOT NULL | |
| timestamps + soft deletes | | |

> **The judge SETS the windows; nothing in the constitution caps them.** §5.3 ("reasonable timeframe") and §5.4 ("a set ... window") leave both durations to the court. Therefore CLK-11/CLK-12 are **`amendable=false` registry rows whose *value* is per-case** — they read `clock_timers.override_value`, never `constitutional_settings`. The CHECK floors at `> 0` (a zero-day window is incoherent); there is **no ceiling** in the engine. *Flagged for q-ledger: whether "reasonable" deserves a soft engine ceiling (e.g. ≤ election_interval) — V1 declines to invent one the constitution does not state; the value is a published, audit-chained judicial act, reviewable like any other.*

### E-3 `create_emergency_power_reviews_table.php` (F-JDG-007 — Art. II §7)

The emergency power already carries the hook columns (`judicial_review_case_id`, `review_outcome`, statuses). This table records the **review act** and its disposition (the power table holds only the current state).

**`emergency_power_reviews`**:

| column | type / constraint | notes |
|---|---|---|
| `id` | uuid PK | |
| `emergency_power_id` | uuid FK emergency_powers restrict | |
| `judiciary_id` | uuid FK judiciaries restrict | |
| `case_id` | uuid NULL FK cases nullOnDelete | |
| `challenge_id` | uuid NULL FK constitutional_challenges nullOnDelete | a review may be triggered *by* an F-IND-016 challenge of the power, or sua sponte |
| `review_basis` | varchar(28) CHECK (`duration`,`area`,`methods`,`civic_process_disruption`,`cause`) | the Art. II §7 limit allegedly breached (the §7 limit clauses: duration ≤ max, area ≤ authority, methods ≤ constitutional order, non-disruption of civic processes, closed cause enum) |
| `outcome` | varchar(12) CHECK (`upheld`,`narrowed`,`struck`) | maps to `EmergencyPower::STATUS_*` (`active`/`narrowed`/`struck`) |
| `narrowed_area_jurisdiction_id` uuid NULL + `narrowed_methods` jsonb NULL | the narrowed scope when `outcome='narrowed'` (Art. II §7 "area of affect, not exceeding Jurisdictional authority ... specific enforcement methods, not breaching Constitutional Order") |
| `opinion_text` | text NOT NULL | |
| `record_id` | uuid NULL | `opinion` record |
| `issued_at` | timestamptz NOT NULL | |
| timestamps + soft deletes | | |

### E-4 `evolve_laws_and_petitions_for_phase_e.php` (tiny, surgical)

- **`laws`** — the reserved columns already exist (`origin`/`status`/`scope_judiciary_id`); **no schema change**. The CHECK constraints `laws_origin_check` and `laws_status_check` are verified to already include `judicial_remedy` and `struck` (they are in the model constants `ORIGIN_JUDICIAL_REMEDY`/`STATUS_STRUCK`; confirm the migration CHECK matches at WI-E1 — if the Phase C laws migration omitted them from the DB CHECK while the model declared them, this migration recuts the CHECK. **This is the one place to verify, not assume.**).
- **`petitions`** — `review_case_id` exists with no FK (forward ref). Add `petitions.review_case_id` FK → `cases` nullOnDelete now that `cases` exists, and add `review_outcome varchar(16) NULL CHECK (review_outcome IN ('cleared','struck'))` to record the F-JDG-008 disposition distinctly from the `review_stub` boolean (which Phase E flips to a no-op path — see C.2). Keep `review_stub` for the historical Phase C rows.
- **`emergency_powers.judicial_review_case_id`** — add FK → `cases` nullOnDelete (was a forward ref). No status change (the enum already holds `under_review`/`struck`/`narrowed`).

### E-5 `add_amendable_settings_amendment_route_columns.php` (the amendments two-door — see §E)

**`constitutional_settings`** add two provenance columns (the existing `last_amended_by_act_id`/`last_amended_at` stay):

| column | type | notes |
|---|---|---|
| `last_amendment_route` | varchar(24) NULL CHECK (`legislative_supermajority`,`constituent_supermajority`,`population_supermajority`) | which of the two-door routes last amended a setting (audit/display) |
| `last_amendment_process_id` | uuid NULL | the `multi_jurisdiction_votes` or `referendum_questions` row for a non-legislative route (NULL for the ordinary F-LEG-031 legislative path) |

No new amendment *table* — amendments ride the existing `setting_changes` ledger + `constitutional_settings` mutation (§E.3 reconciliation).

---

## B) THE THREE-PATH RESOLUTION WITH PER-CASE CLOCKS (the core flow, WF-JUD-05)

New service **`App\Services\Judiciary\ConstitutionalChallengeService`** (owns the challenge lifecycle + clock arming/firing; the only writer of CLK-11/CLK-12 timers). New service **`App\Services\Judiciary\JudicialRemedyService`** (owns the §5.5 direct law edit — the single most constitutionally load-bearing method). Both depend on judiciary-core's `JudiciaryService` (resolve the court for a jurisdiction/law) and seated-judge role checks.

### B.1 F-IND-016 — Constitutional Challenge Filing (§5.1)

Handler **`ConstitutionalChallengeFiling`** (`app/Domain/Forms/Handlers/`), registered `F-IND-016 ⇒ ...`.

1. **Engine gate (the absolute-right surface):** actor holds **R-03** (any active residency association covering the challenge jurisdiction). **No other gate** — §5.1 grants the right to "all individuals who inhabit a Jurisdiction"; Art. I makes it fee-free and condition-free. `ConstitutionalValidator::check('F-IND-016', …)` runs `guardAutomaticRights` (already the posture for the rights surface) — a rejection that adds *any* eligibility test beyond association is itself unconstitutional. F-IND-016 is already in `EMERGENCY_PROTECTED_FORMS` (`ConstitutionalValidator.php:210`) — an emergency power can never suspend the right to challenge.
2. **Payload:** `{challenged_law_id, claim_text, claimed_basis: constitution|other_law, cited_authority_law_id?, constitutional_citation?}`. Validate: the law exists, is `in_force|amended` (a repealed/struck law cannot be challenged — nothing to remedy), and the jurisdiction is the law's binding jurisdiction or a descendant under it.
3. **Resolve the court** (`JudiciaryService::courtFor($law, $jurisdiction)`, judiciary-core): the law's `scope_judiciary_id` if set, else the active `judiciaries` row for the jurisdiction (walking ancestors until a non-`forming` court). If no active court exists → the filing is **accepted and parked at `filed`** with a published record noting "judiciary forming" (the right to file is absolute even when no court is seated; the challenge waits, it is never rejected). The judiciary-core docket surfaces parked challenges when a court activates.
4. **Create** the `constitutional_challenges` row (`status='filed'`, `challenged_version_no` = the law's `current_version_no`), publish a public record (kind `testimony` — a citizen claim on the record), audit-chain `challenge.filed` (ref `F-IND-016`).
5. **Hand-off to judiciary-core:** the court accepts via **F-JDG-001** (Case Acceptance / Panel Assignment), which opens a `cases` row (`case_kind='constitutional'`, full-court if significant per Art. IV §4 / CLK-16) and sets `challenge.case_id` + `status='under_review'`, `heard_at`. **F-JDG-001/002/003 and the cases lifecycle are judiciary-core's** — I consume the `case_id` and the published opinion.

### B.2 F-JDG-004 — Constitutional Finding (§5.2 first half)

Handler **`ConstitutionalFinding`**, registered `F-JDG-004 ⇒ ...`. Filed by a seated judge of the panel (R-19 appointed / R-20 elected — derived by judiciary-core).

1. **Gate:** actor R-19/R-20 on the challenge's `judiciary_id`; the challenge is `under_review`; the case is at the deliberation/decision stage (judiciary-core exposes `Case::isDecidable()`).
2. **Payload:** `{finds_contradiction: bool, contradiction_against: constitution|other_law, superior_authority_law_id?, constitutional_citation?, offending_law_id, offending_version_no, opinion_text}`. The standard applied is **Art. II §8** ("All other Judgements can be overturned only by proven contradictions in law and errors found in the cases") — the engine cannot adjudicate *whether* a contradiction exists (that is the court's deliberation); it records the determination and enforces the consequences.
3. **`finds_contradiction = false`** → write the finding row (`finds_contradiction=false`), publish an `opinion`, set `challenge.status='dismissed'`, `resolution_path='dismissed'`, `closed_at`, audit `challenge.dismissed`. Terminal. No remedy, no clocks.
4. **`finds_contradiction = true`** → write the finding row, set `challenge.finding_id`, `status='finding_issued'`, `finding_at`, publish the `opinion` record. The challenge now **requires** an F-JDG-005 to set the windows (the finding alone arms nothing — §5.2 ties the remedy recommendation to the timeframe-and-window setting; they are one judicial act in two forms for auditability, filed in immediate succession or atomically — see B.3).
5. **Double-jeopardy guard (Art. II §8, hardened):** a finding cannot re-open a `criminal` case's verdict — the constitutional-finding path operates on **legislation**, never on a closed criminal judgement. `ConstitutionalValidator` asserts `offending_law_id` is a `laws` row, never a `cases` verdict; the criminal-judgement overturn path (Art. II §8 second sentence) is judiciary-core's appeal machinery, distinct from F-JDG-004.

### B.3 F-JDG-005 — Remedy Recommendation + the per-case clock arming (§5.2/§5.3/§5.4)

Handler **`RemedyRecommendation`**, registered `F-JDG-005 ⇒ ...`. This is where the judge **sets both windows**; the engine arms the clocks.

1. **Gate:** R-19/R-20 on the judiciary; challenge `status='finding_issued'`; `finding.finds_contradiction=true`.
2. **Payload:** `{remedy_kind: modify|remove, recommended_text?, rationale_text, remedy_timeframe_days, veto_window_days}`. Validate: `remedy_timeframe_days > 0`, `veto_window_days > 0`; `recommended_text` required when `modify`, must be NULL when `remove`.
3. **Transaction (one engine `file()` call):**
   - Write `remedy_recommendations` (`remedy_due_at = now + remedy_timeframe_days`, `veto_closes_at = now + veto_window_days`).
   - **Arm CLK-12 (Legislative Remedy Timeframe):** `ClockService::arm('CLK-12', jurisdictionId, 'constitutional_challenges', $challenge->id, firesAt: $remedy_due_at, payload: ['remedy_id'=>…, 'step'=>'remedy_timeframe'])`, then **stamp `override_value`** = `{days: remedy_timeframe_days, set_by_finding: finding_id, fires_workflow: 'auto-remedy trigger'}` on the created timer (the per-case slot the registry reserves). Store `remedy.clk12_timer_id`.
   - **Arm CLK-11 (Judicial Veto Window):** `ClockService::arm('CLK-11', …, firesAt: $veto_closes_at, payload:['remedy_id'=>…, 'step'=>'veto_window'])`, `override_value` = `{days: veto_window_days, set_by_finding: finding_id, fires_workflow:'override deadline'}`. Store `remedy.clk11_timer_id`.
   - Set `challenge.remedy_id`, `status='remedy_recommended' → legislative_window_open` (the row lands directly at `legislative_window_open`; `remedy_recommended` is the transient stamp the audit records).
   - Publish the recommendation as an `opinion`/`act` record titled "Remedy recommended — Act {act_number}: {modify|remove} within {N} days; override window {M} days" — this **is** the §5.2 "informs The Legislature" act; the legislature's queue surfaces it.
   - Audit `challenge.remedy_recommended` (ref `F-JDG-005`) with both override_values.

> **Two clocks, two distinct deadlines, one resting state.** CLK-12 (`remedy_due_at`) governs Path 1 (the legislature's window to *act*). CLK-11 (`veto_closes_at`) governs Path 2 (the supermajority's window to *override*). They are independent durations — the judge may set, e.g., a 60-day remedy timeframe (CLK-12) and a 30-day override window (CLK-11), exactly the mockup fixture (Novák: remedy due 60 days, override closes 30 days). **Path 3 fires only when BOTH have expired with neither Path 1 nor Path 2 taken** (§5.5 "does not modify the law **nor** override ... **within the window**"). See B.7 for the precise firing arithmetic.

### B.4 Path 1 — Legislature MODIFIES/REMOVES (§5.3)

The normal bill→law-version flow, re-entered (mockup: "PATH A re-enters the bill flow (WF-LEG-06)"). **No new vote type** — an ordinary amendment bill (F-LEG-003 → committee/floor → `EnactmentService`).

1. A legislator files **F-LEG-003** (Bill Introduction) carrying `targets_challenge_id = $challenge->id` (new optional bill payload field — a remedial bill is tagged to the challenge it answers). The bill's text is the modification, or it repeals the law (`remove`).
2. On enactment (`EnactmentService::enact` → `amendLaw` for `modify`, or a repeal that flips `laws.status='repealed'` for `remove`), a **post-enactment hook** (`BillService::resolveBillVote` → `ConstitutionalChallengeService::onRemedialEnactment($bill, $law)`):
   - Confirms the enacted law is the `offending_law_id` and the change is within the **CLK-12 window** (`now ≤ remedy_due_at`). A remedial bill that enacts *after* CLK-12 fired is too late — the challenge has already moved to `judicial_remedy_applied`; the late bill stands as ordinary legislation but does not close the challenge (Path 3 already did).
   - **Cancel both timers** (`ClockService::cancel(clk11_timer)` + `cancel(clk12_timer)` — "subject resolved before the deadline", the exact cancel semantics).
   - Set `challenge.status='amended_by_legislature' → closed`, `resolution_path='legislative_amendment'`, `resolution_ref_type='law_versions'`, `resolution_ref_id` = the new version row, `closed_at`. Audit `challenge.resolved_legislative` (ref `WF-JUD-05`).

> **Why the hook, not a new form:** §5.3 is satisfied by *ordinary* legislative action — there is no special "remedy bill" form in the catalog. The challenge linkage is metadata on a normal F-LEG-003; the engine watches enactments against open challenges. This keeps the legislature's constitutional supremacy intact (it may craft *any* compliant remedy, not only the judge's recommended text).

### B.5 Path 2 — SUPERMAJORITY OVERRULES the judiciary (§5.4) — F-LEG-035

Handler **`JudiciaryOverrideVote`**, registered `F-LEG-035 ⇒ ...`. Vote type **`judiciary_override`** (already in the registry — supermajority of serving, per-kind bicameral).

1. **Gate:** actor R-09 of the offending law's legislature; challenge `status='legislative_window_open'`; **`now ≤ veto_closes_at`** (the CLK-11 window is open — an override filed after CLK-11 fires is rejected with citation Art. IV §5; §5.4 binds it "within a set Judicial veto window").
2. New proposal kind **`ChamberVoteProposal::KIND_JUDICIARY_OVERRIDE`** (added to the model + the `chamber_vote_proposals_kind_check` recut, same pattern as Phase D's `2026_06_23_000109`). Payload `{challenge_id, dissent_text}`. New service **`App\Services\Judiciary\JudiciaryOverrideService`** (sibling of ChamberActService, owns the proposal→vote→resolution). `propose()` opens a `judiciary_override` chamber vote (supermajority; **`ConstitutionalValidator::supermajority(serving)`** is the threshold — `ceil(serving·2/3)` clamped to majority+1, the PROTECTED method, never re-derived).
3. New votable arm in `ChamberVoteService::dispatchVotableEffects`: `'judiciary_override' => app(JudiciaryOverrideService::class)->resolveOverrideVote($vote, $outcome)`, and `votableType(JudiciaryOverride::class) => 'judiciary_override'` (a thin `JudiciaryOverride` model wrapping the proposal, or reuse `ChamberVoteProposal` with the new kind routing through `resolveProposalVote` — **DECISION: reuse `chamber_vote_proposal`** votable type with a new proposal kind, resolved in `ChamberActService::resolveProposalVote`'s match by delegating the `judiciary_override` kind to `JudiciaryOverrideService`; no new votable type, one new proposal kind — minimal blast radius, mirrors how Phase D added `exec_delegation`).
4. **Adopted (supermajority met within CLK-11):** `JudiciaryOverrideService::resolveOverrideVote`:
   - **Cancel both timers** (CLK-11 + CLK-12). The judiciary's finding is overruled — the offending law stands **unchanged**, no `law_version` appended (§5.4: the legislature "disagree[s] with the Judiciary and overrule[s] its judgement"; the law is not edited).
   - Set `challenge.status='overridden' → closed`, `resolution_path='legislature_override'`, `resolution_ref_type='chamber_votes'`, `resolution_ref_id` = the override vote, `closed_at`. Publish an `act` record ("Legislature overruled constitutional finding — Act {n}, supermajority {yes}/{required}"). Audit `challenge.overridden` (ref `F-LEG-035`).
5. **Rejected/expired vote (supermajority not met):** the override fails; the challenge **stays** `legislative_window_open` — Path 1 or Path 3 remains available (a failed override does not bar a later amendment, nor accelerate the auto-remedy; both clocks keep running). Audit `challenge.override_failed`.

### B.6 Path 3 — JUDICIARY APPLIES ITS OWN REMEDY DIRECTLY (§5.5) — F-JDG-006, THE EXIT CRITERION

Handler **`JudicialRemedyApplication`**, registered `F-JDG-006 ⇒ ...`, and the **clock-fired** path (CLK-11 firing job). This is the irreducible Art. IV §5 guarantee: the judiciary edits the law text directly, version history preserved, the law made non-contradictory.

**Trigger — both windows expired with neither path taken.** The auto-remedy is driven by the **CLK-11 fire** (the *later-or-equal* of the two deadlines in the design; see B.7 for why CLK-11 is the trigger and CLK-12 is the guard). When `EvaluateClocksJob` fires the CLK-11 timer for a challenge, `ClockService::fire` dispatches the mapped job:

- **`ClockService::HANDLERS['CLK-11'] = \App\Jobs\Clocks\JudicialAutoRemedyJob::class`** (new — added to the HANDLERS map). The job:
  1. Loads the challenge (FOR UPDATE). Idempotency: if `status != 'legislative_window_open'` (already amended/overridden/remedied), **no-op** (the timer fired but the subject resolved — exactly the `fire()` idempotency contract; a cancelled timer never fires, but a race-fired one self-guards).
  2. Confirms **CLK-12 has also expired** (`now ≥ remedy_due_at`, i.e. CLK-12 is `expired`/`fired`/past). §5.5 requires *both* "does not modify ... nor override ... within the window". If CLK-12 still runs (judge set veto_window < remedy_timeframe), the auto-remedy **waits** — re-arm a short re-check or let CLK-12's own fire carry it (see B.7). In the design, CLK-11's `fires_at` is set to `max(veto_closes_at, remedy_due_at)` so a single fire suffices (B.7).
  3. **Calls `JudicialRemedyService::applyRemedy($challenge)`** — the §5.5 act, below.
- **F-JDG-006 may also be filed by a judge** (the handler) to apply the remedy the moment both windows have demonstrably expired, without waiting for the sweep — same `applyRemedy` body, gated on `now ≥ max(veto_closes_at, remedy_due_at)` and `status='legislative_window_open'`. The clock-fired path is the automatic guarantee; the handler is the judge's explicit invocation. Both converge on one service method.

**`JudicialRemedyService::applyRemedy($challenge)` — the constitutional heart:**

1. Load the `remedy_recommendations` row + the offending `laws` row (FOR UPDATE).
2. **Apply the recommended remedy to the law text directly:**
   - `remedy_kind='modify'` → `EnactmentService::amendLaw($law, $recommendation->recommended_text, source: LawVersion::SOURCE_JUDICIAL_REMEDY, sourceRefType: 'constitutional_challenges', sourceRefId: $challenge->id, viaForm: 'F-JDG-006')`. This **appends a `law_versions` row** (`version_no = current + 1`, `source='judicial_remedy'`, full text, sha256 `text_hash` into the audit chain) and bumps `laws.current_version_no` — **version history preserved** (the prior version row is never mutated or deleted; the offending text remains in `law_versions` as the superseded version). `Law.status` set to `amended`. **This path already pierces a CLK-19 referendum shield** — `amendLaw` explicitly admits `SOURCE_JUDICIAL_REMEDY` as the one legislative-immune source (`EnactmentService.php:177`), the exact §5.5 guarantee that even a population-supermajority-shielded law yields to a constitutional remedy.
   - `remedy_kind='remove'` → append a final `law_versions` row with empty/repeal text (`source='judicial_remedy'`) **and** flip `laws.status = Law::STATUS_STRUCK` (the reserved status — distinct from legislative `repealed`: `struck` records that the *judiciary* removed it under Art. IV §5). History preserved identically.
3. Set `challenge.status='judicial_remedy_applied' → closed`, `resolution_path='judicial_remedy'`, `resolution_ref_type='law_versions'`, `resolution_ref_id` = the appended version, `closed_at`.
4. **Cancel the sibling timer** (whichever of CLK-11/CLK-12 is still `armed` — defensive; one fired, cancel the other), publish an `act` record ("Judicial remedy applied — Act {n} v{new}: law brought into constitutional order, Art. IV §5"), audit `challenge.judicial_remedy_applied` (ref `F-JDG-006`) with the text_hash.
5. **Executive enforcement (§5.6)** — the `act` record + the law's new `in_force` version are what the executive consumes (WF-EXE-07); no engine call into the executive branch (it acts on published records, the existing pattern).

> **The exit criterion, end to end:** any resident files F-IND-016 → court opens a case (judiciary-core) → F-JDG-004 finds contradiction → F-JDG-005 informs the legislature and sets `remedy_timeframe_days`/`veto_window_days` (arming CLK-12/CLK-11 with `override_value`) → the legislature lets **both** windows expire (no amendment, no override) → CLK-11 fires `JudicialAutoRemedyJob` → `JudicialRemedyService::applyRemedy` appends a `law_versions` row `source='judicial_remedy'`, version history preserved, the law made non-contradictory. Pinned by `ChallengeAutoRemedyTest` (E.3).

### B.7 PER-CASE CLOCK ARMING/FIRING — the precise mechanics

This is the load-bearing detail the prompt demands "precisely". The judge **sets** the windows (F-JDG-005 payload); the clocks **fire** the path transitions.

**Arming (in the F-JDG-005 transaction, B.3):**
- Both timers `subject_type='constitutional_challenges'`, `subject_id=challenge.id`, `state='armed'`, `jurisdiction_id` = the challenge's jurisdiction.
- CLK-12 `fires_at = issued_at + remedy_timeframe_days`; CLK-11 `fires_at = max(issued_at + veto_window_days, issued_at + remedy_timeframe_days)` — **CLK-11 is armed to the later of the two deadlines** so that a single CLK-11 fire occurs only once *both* §5.5 conditions ("does not modify ... nor override ... within the window") are met. CLK-12 still fires at its own (possibly earlier) deadline but its job is a **no-op marker** (records that the legislative timeframe lapsed; does not itself apply the remedy) — the remedy is CLK-11's job. This makes Path 3 fire exactly once, deterministically, at `max(remedy_due_at, veto_closes_at)`.
- Each timer's `override_value` carries the judge-set days + the finding id (the per-case provenance the registry reserved).

**Firing (the `EvaluateClocksJob` sweep + `ClockService::fire` dispatch):**
- The sweep (`ClockTimer::scopeDue()` → `fires_at <= now AND state='armed'`) is the existing machinery — no new sweep code; CLK-11/CLK-12 timers are picked up like any windowed clock.
- **CLK-12 fire** → `JudicialAutoRemedyJob` is **not** mapped to CLK-12; instead CLK-12 maps to a light **`LegislativeWindowLapsedJob`** that audits `challenge.legislative_timeframe_lapsed` and updates the docket badge ("remedy timeframe expired"). It does **not** transition the challenge (Path 3 belongs to CLK-11). (Alternative considered and rejected: making CLK-12 the trigger and CLK-11 the guard — rejected because §5.4's override window is the one that must *close* before the judiciary may act, and arming CLK-11 to the max keeps the trigger single.)
- **CLK-11 fire** → `JudicialAutoRemedyJob` (B.6). Idempotent: re-checks `status='legislative_window_open'` and `now ≥ max(remedy_due_at, veto_closes_at)`.

**Cancellation (Path 1 / Path 2 resolve early):**
- A successful amendment (B.4) or override (B.5) calls `ClockService::cancel()` on **both** timers — state `armed → cancelled`, audit `clocks.cancelled`. A cancelled timer never fires; the auto-remedy never runs. This is the exact "subject resolved before the deadline" semantics the ClockService docblock describes.

**Threshold-watch vs. window:** CLK-11/CLK-12 are **windows** (`fires_at` non-NULL), not threshold-watches — the deadline is a wall-clock instant, evaluated by the due-sweep, not a quantity re-evaluated each tick. This is why `override_value` carries the day-count (for display/audit) while `fires_at` carries the resolved instant (for firing).

---

## C) THE RESERVED-HOLD WIRINGS (F-JDG-007 emergency review · F-JDG-008 petition review)

### C.1 F-JDG-007 — Emergency Powers Review (Art. II §7)

Handler **`EmergencyPowersReview`**, registered `F-JDG-007 ⇒ ...`. Wires the reserved `EmergencyPower.judicial_review_case_id`/`review_outcome` hook.

1. **Trigger paths:** (a) an F-IND-016 challenge whose `challenged_law_id` is actually the act invoking an emergency power, routed to emergency review; (b) sua sponte review opened by the court (any seated judge, R-19/R-20). Art. II §7 "Emergency Powers are subject to Judicial review" — review needs no challenge trigger.
2. **Gate:** R-19/R-20 on the jurisdiction's court; the power is in a `LIVE_STATUS` (`active`/`renewed`/`under_review`/`narrowed`).
3. **On opening review:** set `emergency_power.status='under_review'`, `judicial_review_case_id` = the `cases` row (judiciary-core opens it), publish. **CLK-03 (auto-expiry) keeps running** — review does not pause the constitutional ceiling (`ExpireEmergencyPowerJob` already leaves a struck power untouched; an `under_review` power that hits its `expires_at` still expires).
4. **On decision** (F-JDG-007 payload `{review_basis, outcome: upheld|narrowed|struck, narrowed_area_jurisdiction_id?, narrowed_methods?, opinion_text}`): write `emergency_power_reviews` row; apply outcome to the power:
   - `upheld` → `status` back to `active`/`renewed`, `review_outcome='upheld'`.
   - `narrowed` → `status='narrowed'`, write `narrowed_area_jurisdiction_id`/`narrowed_methods` onto the power (Art. II §7 area/methods limits), dependent department rules whose enabling power was narrowed re-validate (the CLK-03 cascade hook already exists for expiry; narrowing reuses it).
   - `struck` → `status='struck'`, the power ends immediately (Art. II §7 limits breached); emergency-enabled rules/orders citing it expire (the existing `expires_with_enabling` cascade). `review_outcome='struck'`.
   - Publish an `opinion` record; audit `emergency.reviewed` (ref `F-JDG-007`).
5. **Civic-process-disruption basis (Art. II §7 hardened):** "Emergency Powers cannot disrupt the Legislative, Judicial, or Electoral process" — `review_basis='civic_process_disruption'` is the judicial counterpart to the *engine's* pre-issuance `EMERGENCY_PROTECTED_FORMS` floor (`ConstitutionalValidator.php:206`). The engine blocks the typed attempt; F-JDG-007 strikes the semantic evasion the engine could not catch. The two are complementary, not redundant.

### C.2 F-JDG-008 — Petition Constitutional Review (replaces the Phase C stub)

Handler **`PetitionConstitutionalReview`**, registered `F-JDG-008 ⇒ ...`. Wires the reserved `Petition.review_case_id`/`review_stub`/`STATUS_CONSTITUTIONAL_REVIEW` hold.

1. **Where the stub was:** `PetitionService::runSignatureAudit()` parks a passing petition at `constitutional_review`; `PetitionService::stubConstitutionalReview()` was the *audited Phase C advance* (records `petition.review.stub_validated`, ref `F-JDG-008`, flips `review_stub=true` → `validated`). Phase E **supersedes the stub** with the real review.
2. **F-JDG-008 flow:** a seated judge (R-19/R-20) reviews the petition's proposed `law_text` for constitutionality **before** it reaches the ballot (the petition's kill-path is constitutional, not skippable — `Petition.php:20`). Payload `{outcome: cleared|struck, opinion_text, contradiction_citation?}`:
   - `cleared` → set `petition.review_outcome='cleared'`, `review_case_id` = the cases row, `status='validated'`, then `ReferendumService::queueFromPetition()` (the existing onward path the stub already called). Publish `opinion`.
   - `struck` → `petition.status='invalidated'`, `review_outcome='struck'` (kill-path 2, the one `Petition.php:20` names: "unconstitutional finding (Phase E F-JDG-008) → invalidated"). Publish `opinion` with the contradiction citation. **No referendum queued.**
3. **Stub retirement:** `PetitionService::stubConstitutionalReview()` is **deprecated, not deleted** — kept callable for the Phase C demo seeds whose petitions predate the judiciary, but the **production path is F-JDG-008**. A petition at `constitutional_review` in a jurisdiction with an *active* court can no longer be stub-advanced (the service guards: if a non-`forming` court exists, the stub throws "use F-JDG-008"); only courts still `forming` fall back to the stub. This is the clean hand-off the Phase C docblock promised ("When Phase E lands, the stub call site is [replaced]").

> **Coordination point (PetitionService is Phase C, PROTECTED-adjacent):** I add `reviewByJudiciary(Petition, $outcome, $opinion, $caseId)` to `PetitionService` (the service already owns petition state); the F-JDG-008 handler calls it. I do **not** move petition state ownership into judiciary code — single source of truth stays.

---

## D) HANDLERS, ROLES — registrations

### D.1 Handler registrations (`app/Domain/Forms/Handlers/`, wired in `FormRegistry::HANDLERS`)

| Form | Handler class | Engine effect (this doc's scope) |
|---|---|---|
| **F-IND-016** | `ConstitutionalChallengeFiling` | R-03 absolute-right filing → `constitutional_challenges` row (`filed`); parks if no court |
| **F-JDG-004** | `ConstitutionalFinding` | finding row; `finds_contradiction=false` ⇒ dismiss; `true` ⇒ `finding_issued` |
| **F-JDG-005** | `RemedyRecommendation` | recommendation row; **arms CLK-11 + CLK-12 with judge-set `override_value`**; `legislative_window_open` |
| **F-JDG-006** | `JudicialRemedyApplication` | §5.5 direct edit → `amendLaw(source='judicial_remedy')` / `STATUS_STRUCK`; `closed` (also the CLK-11 fired path) |
| **F-JDG-007** | `EmergencyPowersReview` | `emergency_power_reviews` row; `upheld`/`narrowed`/`struck` applied to the power |
| **F-JDG-008** | `PetitionConstitutionalReview` | real petition review → `cleared` (queue referendum) / `struck` (invalidate); supersedes the stub |
| **F-LEG-035** | `JudiciaryOverrideVote` | proposal kind `judiciary_override` → supermajority vote → on adoption: law stands, challenge `overridden` |

**Forms wired elsewhere (named for completeness, NOT this doc):** F-IND-015 (advocate reg), F-IND-017 (case filing), F-JDG-001/002/003/009/010 (case lifecycle/jury/opinion/sentencing/warrant), F-ADV-001..004, F-LEG-017/018/021 (judiciary creation/conversion/nomination consent) — **all owned by PHASE_E_DESIGN_judiciary_core**. Path 1 uses the **already-registered** F-LEG-003 (no new handler).

New `ChamberVoteProposal::KIND_JUDICIARY_OVERRIDE = 'judiciary_override'` (model const + `chamber_vote_proposals_kind_check` recut migration, the Phase D `2026_06_23_000109` pattern). New `ChamberActService::resolveProposalVote` arm delegating the `judiciary_override` kind to `JudiciaryOverrideService` (no new votable type — reuses `chamber_vote_proposal`).

### D.2 Role derivations (consumed, not authored here)

| Role | Derivation | Owner |
|---|---|---|
| R-03 | any active residency association (the F-IND-016 gate) | **already derived** (`RoleService.php:191`) — I consume it |
| R-09 | seated legislator (the F-LEG-035 gate) | already derived (`RoleService.php:212`) |
| R-19 / R-20 | seated **appointed** / **elected** judge | **PHASE_E_DESIGN_judiciary_core** authors these (seated `judicial_seats`); F-JDG-* handlers gate on them |
| R-21 | registered advocate | judiciary-core |

This doc adds **no** role derivations. Every gate reads a role another phase/doc derives — the clean dependency the FormRegistry posture intends.

---

## E) THE AMENDMENTS TWO-DOOR (how "unless otherwise amended" settings get amended)

Art. IV §5's "non-contradictory" guarantee presumes the amendable settings (`constitutional_settings`, the "Unless otherwise amended ..." values — judicial term years, min judges, supermajority ratio, emergency max days, etc.) have a *constitutional* amendment route. Phase A/C built **one door** (F-LEG-031, legislative). This doc reconciles the **second door** (the constituent/population route) the constitution implies, without re-inventing the settings substrate.

### E.1 What exists (Door 1 — legislative supermajority)

`F-LEG-031` (Amendable Setting Change) → a `setting_change` bill → floor vote → `EnactmentService::applySettingChangeForKey` mutates `constitutional_settings`, writes the `setting_changes` ledger row, stamps `last_amended_by_act_id`, re-derives dependent clocks. The PROTECTED `ConstitutionalValidator::checkSettingChange` enforces the bounds (e.g. `legislature_min_seats ≥ 5`, `worker_rep_min_employees ≤ 100`). **This is Door 1, complete.**

### E.2 What the constitution requires for Door 2 (constituent / population route)

The constitution gates certain changes on **more than a single legislature's supermajority**:

- **`judiciary_is_elected` flip** (default `false` → `true`): Art. IV §3 "If a Jurisdiction is composed of Constituent Jurisdictions, then a Supermajority of Constituent Jurisdictions must **also** consent." → **legislature supermajority AND constituent supermajority**. (The bounds row already allows `[true,false]` — `ConstitutionalValidator.php:182` — but notes "that PROCESS gate lands in Phase C/E"; **this doc lands it**.)
- **Boundary / shared-sovereignty changes** (Art. V §2 "If a Supermajority of the Population in the affected area agree then the new boundaries are adopted") → **population supermajority** (referendum route). Not a `constitutional_settings` key, but the same two-door principle; out of scope for the settings table, named for completeness.

So Door 2 has two arms: **constituent-supermajority** (reuse `MultiJurisdictionVoteService`, the exact exec-conversion pattern) and **population-supermajority** (reuse the referendum machinery + the CLK-19 shield).

### E.3 The design — Door 2 rides existing substrate, ZERO new settings table

**Door 2a — constituent supermajority (for `judiciary_is_elected` and any setting the constitution gates on constituent consent):**

1. The change is proposed as an F-LEG-031 setting bill **flagged `requires_constituent_consent=true`** (a payload flag the handler sets when the targeted key is in a **`DUAL_DOOR_KEYS`** allowlist — initially `['judiciary_is_elected']`, citation Art. IV §3).
2. The chamber leg passes at **supermajority** (not ordinary — a dual-door setting bill upgrades its own threshold; the `judiciary_convert` vote type's `dual='constituent_supermajority'` is the precedent, `vote_types.php:178`).
3. On chamber adoption, **before** `applySettingChangeForKey` runs, the handler opens a `MultiJurisdictionVoteService::open('setting_amendment', $legislature, $constituentIds, BASIS_SUPERMAJORITY, $vote, 'constitutional_settings', $settingsRowId)` — the **identical** constituent-consent flow Phase D built for exec conversion (each constituent legislature casts via the generic `constituent_consent` votable arm, `ChamberVoteService.php:1123`). New `MultiJurisdictionVote::KINDS` entry `'setting_amendment'`.
4. **Only when the constituent process `passed`** does the setting mutate: a post-process hook calls `EnactmentService::applySettingChangeForKey` (the existing path), then stamps the new `last_amendment_route='constituent_supermajority'` + `last_amendment_process_id`. If the constituent door fails, the setting **does not change** (the chamber supermajority alone is insufficient — exactly Art. IV §3 "must **also** consent").
5. No constituents exist (jurisdiction has no legislature-bearing children) → the constituent door is vacuously satisfied (same "where constituents exist" reading as exec conversion, B.2 of PHASE_D_DESIGN_executive); the chamber supermajority suffices. Published in the process record.

**Door 2b — population supermajority (referendum route):** an amendable setting may also be changed by a **referendum question** targeting that key — the path **already exists**: `ReferendumQuestion.targets_setting_key` + `EnactmentService::enactFromReferendum` → `applySettingChangeForKey`, and a population-supermajority pass earns the CLK-19 shield (`Law.referendum_passed_by_supermajority`). This doc only **labels** it as Door 2b and stamps `last_amendment_route='population_supermajority'`. No new code beyond the provenance stamp (E-5).

### E.4 Reconciliation with F-LEG-031 / `constitutional_settings`

- **F-LEG-031 stays the single mutation entry point.** Both doors converge on `EnactmentService::applySettingChangeForKey` (the TOCTOU-guarded, ledger-writing, clock-re-deriving path). The doors differ only in the **gate before** the mutation (ordinary supermajority vs. + constituent process vs. + population referendum), never in the mutation itself. This preserves the single-source-of-truth invariant and the `setting_changes` audit ledger.
- **`DUAL_DOOR_KEYS` is a hardened allowlist** in `ConstitutionalValidator` (constitutional-review path), citation Art. IV §3 / Art. V §2. A setting in this list **cannot** be amended through Door 1 alone — the validator rejects an ordinary F-LEG-031 setting bill targeting it ("this setting requires constituent supermajority — Art. IV §3"). This is the structural enforcement that the dual-door requirement cannot be bypassed.
- **The supermajority ratio itself** (`supermajority_numerator/denominator`) is bounds-checked so it can never produce a result below majority+1 (already enforced by `ConstitutionalValidator::supermajority`'s clamp). Amending it via Door 1 is allowed within bounds; it is **not** in `DUAL_DOOR_KEYS` (the constitution does not gate it on constituent consent) — but the clamp guarantees no amendment can weaken the supermajority below the hard floor. Documented, not changed.

> **Flagged for q-ledger:** the constitution does not enumerate *exactly* which settings require the constituent door beyond `judiciary_is_elected` (explicit, Art. IV §3) and boundary changes (Art. V §2, not a settings key). `DUAL_DOOR_KEYS` starts minimal (`judiciary_is_elected`) and grows only under constitutional review. The population-referendum door (2b) is available for *any* amendable setting (Art. II §6 makes initiative a general right), so it is never the *only* route — it widens, never narrows.

---

## F) EXIT-CRITERION / WORKFLOW CHAINS (surface chains for the constitutional tests)

**THE exit criterion (full chain, the one the prompt names):**

```
F-IND-016 (R-03 inhabitant, absolute right)
   → challenge.filed → [judiciary-core: F-JDG-001 panels → cases row → hearing]
   → F-JDG-004 (finds_contradiction=true) → challenge.finding_issued
   → F-JDG-005 (remedy_timeframe_days=60, veto_window_days=30)
        → ARM CLK-12 fires_at=+60d (override_value{days:60})
        → ARM CLK-11 fires_at=max(+30d,+60d)=+60d (override_value{days:30})
        → challenge.legislative_window_open
   → [legislature does NOTHING — no F-LEG-003 remedial bill, no F-LEG-035 override]
   → CLK-12 fires at +30..60d → LegislativeWindowLapsedJob (badge only, no transition)
   → CLK-11 fires at +60d → JudicialAutoRemedyJob
        → JudicialRemedyService::applyRemedy
        → EnactmentService::amendLaw(source='judicial_remedy')  → law_versions v(n+1) APPENDED, v(n) PRESERVED
        → challenge.judicial_remedy_applied → closed (resolution_path='judicial_remedy')
   → law non-contradictory, version history intact, opinion+act on the public record
```

**Path 1 chain (legislature amends in time):** F-IND-016 → F-JDG-004 → F-JDG-005 → F-LEG-003 remedial bill (`targets_challenge_id`) → enactment inside CLK-12 → `ConstitutionalChallengeService::onRemedialEnactment` → cancel CLK-11+CLK-12 → `amended_by_legislature → closed`.

**Path 2 chain (supermajority override in time):** F-IND-016 → F-JDG-004 → F-JDG-005 → F-LEG-035 (`judiciary_override`, supermajority, inside CLK-11) → adopted → cancel both clocks → law UNCHANGED → `overridden → closed`.

**Petition kill-path chain (F-JDG-008):** F-IND-009 → … → F-ELB-005 audit passes → `constitutional_review` (HOLD) → F-JDG-008 `struck` → `invalidated` (no referendum) | `cleared` → `validated` → `queueFromPetition` → ballot.

**Emergency review chain (F-JDG-007):** F-LEG-024 emergency invoked → (challenge or sua sponte) → F-JDG-007 `under_review` → `struck`/`narrowed`/`upheld` → power state + dependent-rule cascade.

---

## G) WHAT I REUSE vs. BUILD NEW

**REUSE (proven, untouched or extended-by-contract):**
- `EnactmentService::amendLaw()` — the §5.5 law-version append, **already wired to accept `judicial_remedy`** and pierce the CLK-19 shield. Path 3 calls it; I add no law-versioning code.
- `Law::ORIGIN_JUDICIAL_REMEDY` / `Law::STATUS_STRUCK` / `LawVersion::SOURCE_JUDICIAL_REMEDY` / `Law.scope_judiciary_id` — reserved, I consume.
- `ClockService::arm/fire/cancel`, `ClockTimer.override_value`, `EvaluateClocksJob` sweep, `ClockTimer::scopeDue()`, the `HANDLERS`/`STEP_HANDLERS` dispatch — I add CLK-11/CLK-12 timers + map CLK-11 to `JudicialAutoRemedyJob`, CLK-12 to `LegislativeWindowLapsedJob`. No new clock infrastructure.
- CLK-11/CLK-12 registry rows (`ClockRegistrySeeder`) — already seeded, I write to them.
- `ConstitutionalValidator::supermajority()` (PROTECTED) — the F-LEG-035 threshold, never re-derived.
- `ChamberVoteService` votable-arm pattern + `ChamberVoteProposal::KIND_*` + the `chamber_vote_proposals_kind_check` recut — add `KIND_JUDICIARY_OVERRIDE`.
- `vote_types.php` `judiciary_override` — already registered.
- `MultiJurisdictionVoteService` + the `constituent_consent` votable arm — Door 2a (setting amendment) reuses it verbatim (exec-conversion pattern).
- `ReferendumService` + CLK-19 shield — Door 2b.
- `PetitionService` (review HOLD, stub) — I add `reviewByJudiciary()`, deprecate the stub.
- `EmergencyPower` review hook columns + `ExpireEmergencyPowerJob` cascade — F-JDG-007 writes the disposition.
- `PublicRecordService` (kinds `opinion`/`act`/`testimony`/`correction`), hash-chained `AuditService`, `SettingsResolver` — throughout.
- `judiciaries`/`judicial_seats` + R-19/R-20 + `cases` lifecycle + F-JDG-001/002/003 — **PHASE_E_DESIGN_judiciary_core**, consumed via `case_id`/`judiciary_id` FKs and published opinions.

**BUILD NEW (this doc):**
- Tables: `constitutional_challenges`, `constitutional_findings`, `finding_offending_laws`, `remedy_recommendations`, `emergency_power_reviews`; columns on `petitions`/`emergency_powers`/`constitutional_settings`.
- Services: `ConstitutionalChallengeService`, `JudicialRemedyService`, `JudiciaryOverrideService`.
- Jobs: `JudicialAutoRemedyJob` (CLK-11), `LegislativeWindowLapsedJob` (CLK-12).
- Handlers: `ConstitutionalChallengeFiling` (F-IND-016), `ConstitutionalFinding` (F-JDG-004), `RemedyRecommendation` (F-JDG-005), `JudicialRemedyApplication` (F-JDG-006), `EmergencyPowersReview` (F-JDG-007), `PetitionConstitutionalReview` (F-JDG-008), `JudiciaryOverrideVote` (F-LEG-035).
- Validator rules (PROTECTED, constitutional-review path): `challenge.absolute_right` (F-IND-016 — no gate beyond R-03), `challenge.law_challengeable` (in-force/amended only), `remedy.windows_positive` (CLK-11/12 days > 0), `override.within_veto_window` (CLK-11 open), `settings.dual_door` (`DUAL_DOOR_KEYS` enforcement), `finding.not_double_jeopardy` (operates on legislation, never closed criminal verdicts).
- `DUAL_DOOR_KEYS` allowlist + the Door 2a constituent-consent wiring on F-LEG-031.

### G.1 Work-item breakdown (challenge-law scope; sizes S/M/L, deps, parallelism)

| WI | Size | Content | Deps / parallel-with |
|---|---|---|---|
| **WI-E-CL1** Migrations E-1…E-5 | M | all tables + ESM-CC CHECK + petition/emergency/laws FK reconcile + settings provenance cols; verify `laws` origin/status CHECK already carries `judicial_remedy`/`struck` (recut if not) | judiciary-core's `cases` migration (FK target); else none |
| **WI-E-CL2** `ConstitutionalChallengeService` + F-IND-016 handler + parked-no-court path + docket reads | M | challenge spine; absolute-right gate | WI-E-CL1; judiciary-core `JudiciaryService::courtFor` |
| **WI-E-CL3** F-JDG-004 finding + F-JDG-005 remedy + **CLK-11/CLK-12 arming with override_value** | L | the per-case clock heart; the arming arithmetic (B.7) | WI-E-CL2; judiciary-core R-19/R-20 + `Case::isDecidable` |
| **WI-E-CL4** Path 3: `JudicialRemedyService::applyRemedy` + `JudicialAutoRemedyJob` (CLK-11) + `LegislativeWindowLapsedJob` (CLK-12) + F-JDG-006 handler | L | THE exit criterion; touches `EnactmentService` call (judicial_remedy source) | WI-E-CL3 |
| **WI-E-CL5** Path 1 hook (`onRemedialEnactment`, `bills.targets_challenge_id`) + Path 2 (F-LEG-035, `KIND_JUDICIARY_OVERRIDE`, `JudiciaryOverrideService`, votable arm) | M | the two legislature-driven paths + clock cancellation | WI-E-CL3 |
| **WI-E-CL6** F-JDG-008 petition review (replace stub, `PetitionService::reviewByJudiciary`) + F-JDG-007 emergency review (`emergency_power_reviews`, cascade) | M | the two reserved-hold wirings | WI-E-CL1; parallel WI-E-CL3/4/5 |
| **WI-E-CL7** Amendments two-door: `DUAL_DOOR_KEYS` validator rule + F-LEG-031 constituent-consent leg (MJV `setting_amendment` kind) + provenance stamps | M | Door 2a/2b reconciliation | WI-E-CL1; reuses Phase D MJV (no new substrate) |
| **WI-E-CL8** Frontend: `constitutional-challenge` tracker (the three-path live state + CLK-11/CLK-12 countdowns), challenge filing form, finding/remedy surfaces; the frontend agent reads all 6 judiciary mockups | L | consumes all services | WI-E-CL2+; grows per WI |
| **WI-E-CLT** Constitutional tests (E.3, woven; CI merge gate) | M | | per-WI |

Critical path: CL1 → CL2 → CL3 → CL4 (exit criterion). CL5/CL6/CL7 parallel after CL3. Exit-criterion map: F-IND-016 → auto-remedy law edit = CL2+CL3+CL4; petition kill-path = CL6; dual-door amendment = CL7.

---

## H) CONSTITUTIONAL TEST SPECS (`tests/Constitutional/`)

1. **ChallengeAbsoluteRightTest** — F-IND-016 accepts on R-03 association alone; any added eligibility ground rejects unconstitutionally (Art. I); F-IND-016 in `EMERGENCY_PROTECTED_FORMS` (emergency can never suspend it); parked-no-court accepts (never rejected for a forming court).
2. **FindingThreePathOpensTest** — F-JDG-004 `finds_contradiction=true` → `finding_issued`; F-JDG-005 arms **exactly two** `clock_timers` (CLK-11 + CLK-12) with `override_value` carrying the judge-set days; `false` → dismissed, **zero** clocks armed.
3. **PerCaseClockArmingTest** — the judge sets `remedy_timeframe_days`/`veto_window_days`; CLK-12 `fires_at = issued+timeframe`, CLK-11 `fires_at = max(timeframe, veto)`; both `subject_type='constitutional_challenges'`; `override_value.days` matches payload; no `constitutional_settings` read (per-case, never amendable-resolved). Fixture: the Novák case (60/30 days).
4. **ChallengeAutoRemedyTest** — THE exit criterion: legislature does nothing → CLK-11 fires `JudicialAutoRemedyJob` → `amendLaw(source='judicial_remedy')` appends `law_versions` v(n+1), v(n) **unchanged and present** (history preserved), `laws.current_version_no` bumped, challenge `judicial_remedy_applied → closed`; `remove` kind sets `Law::STATUS_STRUCK`; the judicial remedy **pierces a CLK-19 referendum shield** (shielded law still edited — §5.5 over Art. II §6).
5. **LegislativeAmendmentCancelsClocksTest** — Path 1: remedial F-LEG-003 enacted inside CLK-12 → both timers `cancelled`, `JudicialAutoRemedyJob` never runs (cancelled timer never fires); enacted *after* CLK-12 fired → does not re-close an already-remedied challenge.
6. **OverrideSupermajorityWindowTest** — Path 2: F-LEG-035 needs `ConstitutionalValidator::supermajority(serving)` (not majority); adopted inside CLK-11 → both clocks cancelled, law UNCHANGED, `overridden`; filed after CLK-11 fired → rejected (Art. IV §5); failed override leaves `legislative_window_open` (Path 1/3 still available).
7. **PetitionConstitutionalReviewTest** — F-JDG-008 `struck` → petition `invalidated`, no referendum queued; `cleared` → `validated` → `queueFromPetition`; the Phase C stub is unreachable when an active court exists.
8. **EmergencyJudicialReviewTest** — F-JDG-007 `struck` ends the power + cascades to emergency-enabled rules; `narrowed` writes area/methods; CLK-03 still expires an `under_review` power at its ceiling (review never extends a power); civic-process-disruption basis is the judicial complement to the engine floor.
9. **AmendmentDualDoorTest** — `judiciary_is_elected` flip rejects through Door 1 alone (`DUAL_DOOR_KEYS`, Art. IV §3); passes only with chamber supermajority AND constituent supermajority (MJV `setting_amendment`); the supermajority clamp still floors any ratio amendment at majority+1; population-referendum door stamps `last_amendment_route='population_supermajority'`.
10. **ChallengeVersionHistoryTest** — across all three paths, `law_versions` is append-only: Path 1 appends a `legislative_amendment` version, Path 3 a `judicial_remedy` version, Path 2 appends nothing (law unchanged); no path mutates or deletes a prior version row (the Art. IV §5 "version history preserved" guarantee).

---

## I) DEFERRALS (flagged, justified)

- **Multi-law findings fan-out** — `finding_offending_laws` table built, but V1 arms **one** CLK-11/CLK-12 pair per finding (single offending law spine). The N-law fan-out (one window pair per offending law) is a real clock-multiplicity fork reserved, not built. q-ledger. Justification: the exit criterion and every mockup fixture are single-law; building speculative fan-out risks the clock arithmetic the exit criterion depends on.
- **§5.6 executive enforcement** — the closed challenge publishes the `act`/`opinion` record the executive consumes (WF-EXE-07); no engine call into the executive branch (it acts on published records, the existing pattern). No new executive-enforcement code.
- **"Reasonable timeframe" soft ceiling** — the engine floors CLK-11/CLK-12 at `> 0` and imposes **no ceiling** (the constitution states none). Whether "reasonable" deserves a soft cap (≤ election_interval?) is a q-ledger entry; V1 declines to invent a bound the constitution does not state — the value is a published, reviewable judicial act.
- **Appeal of a constitutional finding** — Art. II §8 "All other Judgements can be overturned only by proven contradictions in law and errors found in the cases" implies an appeal path; appeals of *cases* belong to judiciary-core. An F-IND-016 challenge of the *finding itself* (meta-challenge) is not built — the override (Path 2) is the legislature's check; a higher court's review of a lower court's finding rides judiciary-core's `parent_judiciary_id` hierarchy in a later slice.
- **Population-referendum door (2b) full UX** — the path exists end-to-end via `ReferendumQuestion.targets_setting_key`; this doc adds only the provenance stamp. A dedicated "amend a setting by referendum" wizard is frontend polish, post-E.
- **`DUAL_DOOR_KEYS` breadth** — starts at `['judiciary_is_elected']` (the one explicitly gated, Art. IV §3); grows only under constitutional review. Boundary changes (Art. V §2 population supermajority) are not `constitutional_settings` keys and are named, not built, here.
- **F-JDG-007 sua-sponte trigger UX** — the engine path (judge opens review without a challenge) exists; the docket surface for a court to *initiate* review (vs. respond to a challenge) is minimal in E, full in the judiciary-core docket.
- **Stub retirement, not deletion** — `PetitionService::stubConstitutionalReview` stays callable for `forming`-court jurisdictions (and historical demo seeds); production petitions in courts that are active route through F-JDG-008. Deleting the stub would break Phase C demo reproducibility.
