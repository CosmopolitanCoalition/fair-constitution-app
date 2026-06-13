# PHASE E DESIGN — THE JUDICIARY (STRUCTURE)
Judiciary creation/conversion · the two nomination paths · nomination consent seating · elected-judge machinery · judge removal · the ESM status machine · seat classes · the panel/seat-pool distinction

Verified against the live worktree (`E:\fair-constitution-app\.claude\worktrees\practical-payne-17d537`): the `judiciaries`/`judicial_seats` STUB (`database/migrations/2026_04_25_000003_create_judiciaries_tables.php` — `type` default `appointed`, `min_judges>=5` CHECK, `term_years` default 10, `status='forming'`, `parent_judiciary_id` self-ref; `judicial_seats` with `user_id`/`seat_number`/`term_*`/`status IN ('vacant','seated','recused','retired')`), the `InstitutionStubService` that already inserts one `forming` judiciary per legislature-bearing jurisdiction (`generate()` — judiciaries_created), `app/Services/Executive/ExecutiveFormationService.php` (the EXACT conversion pattern to mirror — `applyConversionAdoption` / `openConstituentConsentVote` / `resolveConstituentConsentVote` / `onProcessEvaluated` / `scheduleConversionElection`), `app/Services/MultiJurisdictionVoteService.php` (`open`/`recordConsent`/`evaluate` — `MultiJurisdictionVote::KINDS` ALREADY carries `'judiciary_convert'`), `app/Services/Executive/BoardGovernorService.php` (the consent-seat-civil-term pipeline to mirror for F-LEG-021), `app/Services/CivilAppointmentService.php` (`openCivilTerm` — the ONE CLK-09 path; `office_kind='judicial_seat'` is already in the `terms_office_kind_check`), `app/Services/CertificationService.php` (`certify` dispatches on `Election::KIND_EXECUTIVE` → `certifyExecutive`/`seatExecutiveMember`/`inheritedWindow` — the elected-seating pattern to mirror), `app/Services/ElectionLifecycleService.php` (`scheduleExecutive` — the conversion-election pattern; `Election::KIND_JUDICIAL` already exists), `app/Services/Legislature/OversightService.php` (`openProceeding`/`resolveRemovalVote` — `executive_members` arm live; `judicial_seats` arm is the parallel), `app/Services/ChamberVoteService.php` (`dispatchVotableEffects`/`votableType` — the new judiciary votable arms slot in), `app/Services/Legislature/ChamberActService.php` (`resolveConsentVote` — the `appointable_type` match dispatches consent seating; `judicial_seats` is a new arm), `config/constitution/vote_types.php` (`judiciary_create`, `judiciary_convert`, `judiciary_override`, `judicial_election` ALL pre-registered, phase `E`), `database/seeders/ClockRegistrySeeder.php` (CLK-11 Judicial Veto Window, CLK-12 Legislative Remedy Timeframe, CLK-16 Panel Size — all seeded; `override_value` slot pre-provisioned on `clock_timers`), `ConstitutionalValidator::SETTING_BOUNDS` (`judicial_appointment_years` 1–10, `judiciary_min_judges_per_race` 5–99, `judiciary_is_elected` true/false all bounded; the "Courts (Phase E) — protected from day one" hook at line ~219), `Law::ORIGIN_JUDICIAL_REMEDY` + `LawVersion::SOURCE_JUDICIAL_REMEDY` + `laws.scope_judiciary_id` FK (the remedy substrate the cases agent consumes — RESERVED here), `RemovalProceeding::KIND_JUDGE_REMOVAL` (declared, NOT yet in `ACTIVE_KINDS` — Phase E activates it), and the 6 mockups under `mockups/judiciary/` (judiciary-home confirms: per-constituent equal nomination, one consent vote per nominee, judicial-committee fallback, conversion meter, panel-by-severity CLK-16, 10-year terms 2027→2037).

This document owns the judiciary **structure**: how a court is created, who sits on it, how it converts, how judges are removed, and the **seat pool** it carries. It does NOT own cases, panels-per-case, opinions, findings, remedies, advocates, juries, or warrants — that is the **cases agent's** scope (F-JDG-*, F-ADV-*, F-IND-015/016/017). The contract between us is two columns and one clock family, called out explicitly in §F.

---

## A) MIGRATION SET (all additive; `database/migrations/2026_08_xx_*`)

### E-1 `evolve_judiciaries_tables.php` — the ESM-18 machine (EVOLVE the stub)

The stub is a scaffold (4 columns + a 4-status seat enum). It is evolved exactly the way D-1 evolved the `executives` stub: additive, the existing `forming` rows back-fill every new CHECK as a no-op, and the status enum widens from the 3-value stub default to the full ESM. **ONE row per jurisdiction is preserved** — conversion EVOLVES the same row (`type` flips `appointed → elected`, status walks the machine); the appointed era's seat rows close, never delete.

**`judiciaries`** evolve:

| change | detail |
|---|---|
| RECUT `judiciaries_status_check` | the stub default enum is `forming\|active\|dissolved`; recut to the full ESM-18: `status IN ('forming','creating','appointed','conversion_voted','elected','dissolved','reverted')`. Back-fill no-op (all rows `forming`). Mirrors `executives_status_check` exactly (D-1). |
| RECUT `judiciaries_type_check` | stub allows `appointed\|elected`; UNCHANGED in values, but the **DEFAULT stays `appointed`** (Art. IV §1 hard constraint — never silently flipped). |
| `creation_law_id uuid NULL` FK laws nullOnDelete | the F-LEG-017 Judiciary Creation Act (law kind `charter`) |
| `nomination_mode varchar(20) NULL` CHECK in (`constituent`,`committee`) | which Art. IV §2 path seated the bench — `constituent` (equal-per-constituent) or `committee` (judicial committee). NULL until creation adopts. Drives the nomination surface + F-LEG-021 routing. |
| `conversion_process_id uuid NULL` FK multi_jurisdiction_votes nullOnDelete | the F-LEG-018 dual-supermajority process (the SAME column shape as `executives.conversion_process_id`) |
| `conversion_law_id uuid NULL` FK laws nullOnDelete | the F-LEG-018 conversion act (charter of the elected court) |
| `converted_at timestamptz NULL` | |
| `judge_count smallint NULL` CHECK `>= min_judges` | the bench size fixed by the creation act — the size of the **seat pool**, distinct from `min_judges` (the per-RACE floor for elected courts, Art. IV §1). For a constituent-nominated court `judge_count` is `equal_per_constituent × constituent_count`; for a committee-nominated court it is the act's stated count; for an elected court it is the race's seat count (`≥ min_judges`). |
| `source_legislature_id uuid NULL` FK legislatures nullOnDelete | the chartering chamber (lockstep anchor for elected judges; the same field `executives` carries) |
| keep `parent_judiciary_id`, `court_name`, `min_judges`, `term_years` | `term_years` is now redundant with the `judicial_appointment_years` setting — kept as the per-court snapshot at creation (display), but the AUTHORITATIVE term length resolves through `SettingsResolver`/`judicial_appointment_years` at seating (never the column) — same posture as `executives` never reading a frozen term column. |

ESM-18 (the judiciary status machine):

```
forming ──F-LEG-017 proposed──▶ creating ──creation adopted (supermajority)──▶ appointed
   │                                                                              │
   │ (stub resting state; no act yet)                          F-LEG-018 proposed │
   │                                                                              ▼
   │                                                                      conversion_voted
   │                                                  (chamber supermajority + MJV opened)
   │                                                                              │
   │                                          process passes → judicial election → certify
   │                                                                              ▼
   └────────────────────────────────────────────────────────────────────────▶ elected
                                                                                  │
                                            process FAILS at constituent consent  │
                                            ──▶ reverted (stays appointed footing) ┘

  appointed / elected ──F-LEG-022 (dissolution act, supermajority)──▶ dissolved
```

`creating` is the transient between "F-LEG-017 vote opened" and "vote adopted" (the proposal is live but the bench has not seated) — it mirrors `executives` having no analogous intermediate ONLY because executive delegation seats synchronously; judiciary creation has a nomination + consent phase BETWEEN the creation vote and `appointed`, so the intermediate state is real and load-bearing (the nomination surface renders against `creating`). `reverted` is the failed-conversion resting state (mirrors the executive `onProcessEvaluated` failure branch, which falls back to `delegated`/`forming` — here it falls back to `appointed`).

### E-2 `evolve_judicial_seats_tables.php` — seat classes + provenance (EVOLVE the stub)

The stub `judicial_seats` carries `judiciary_id`, `user_id`, `seat_number`, `term_*`, `status IN ('vacant','seated','recused','retired')`. Evolve it to carry the seat CLASS and provenance, mirroring `board_seats`/`executive_members`:

| change | detail |
|---|---|
| `seat_class varchar(24) NOT NULL DEFAULT 'committee_nominated'` CHECK in (`constituent_nominated`,`committee_nominated`,`elected`) | the prompt's three seat classes. Default is harmless (every stub row is `vacant` with no class meaning yet; creation writes the real class). |
| `nominating_jurisdiction_id uuid NULL` FK jurisdictions nullOnDelete | for `constituent_nominated` seats: WHICH constituent nominated this judge (Art. IV §2 "equal number by each constituent"). NULL for committee/elected. **No cascade** — a dissolved constituent does not cascade-delete a seated judge (the WF-JUR data-safety posture). |
| `appointment_id uuid NULL` FK appointments nullOnDelete | the F-LEG-021 consent pipeline row (appointed seats only) — exactly like `board_seats.appointment_id` |
| `elected_in_race_id uuid NULL` FK election_races nullOnDelete | elected-era provenance — exactly like `board_seats.elected_in_race_id` |
| `term_id uuid NULL` FK terms nullOnDelete | the seat's term row (appointed: `civil_appointment` 10y; elected: `lockstep`) — exactly like `board_seats.term_id` |
| RECUT `judicial_seats_status_check` | the stub's `vacant\|seated\|recused\|retired` is recut to the bench-seat lifecycle: `status IN ('vacant','nominated','seated','removal_requested','removed','term_ended','retired')`. (`recused` from the stub is dropped — recusal is a PER-CASE concern owned by the cases agent, not a seat-pool resting state; a recused judge keeps their seat. The cases agent tracks recusal on the case/panel row, never on `judicial_seats`.) |
| keep `seat_number` unique-per-judiciary | the stub's `judicial_seats_judiciary_seat_unique` (`judiciary_id, seat_number, deleted_at`) stays — the seat-pool numbering. |
| `index (judiciary_id, status)` | the bench roster query |

**Seat classes are mutually exclusive per judiciary in a given era** (a court is either constituent-nominated OR committee-nominated while appointed; on conversion every appointed seat closes and the elected race writes `elected` seats). An engine rule, not a CHECK (the class mix lives across rows, and conversion legitimately leaves closed rows of the old class alongside new `elected` rows).

### E-3 `wire_phase_e_proposal_kinds_and_removal.php` — registry/enum widening (the D-9 analogue)

Mirrors `2026_06_23_000109_wire_phase_d_proposal_kinds_and_seat_kind_width.php` (the Phase D wiring-fix migration) so the engine-filed judiciary lane does not die on a stale CHECK:

| change | detail |
|---|---|
| RE-STATE `chamber_vote_proposals_kind_check` | add the three Phase E proposal kinds — `'judiciary_creation'`, `'judiciary_conversion'`, `'judiciary_dissolution'` — to the existing Phase C + Phase D list (the D-9 migration's exact technique: drop, re-add with the full union). Without this every F-LEG-017/018 proposal dies SQLSTATE 23514, the way Phase D's did. |
| RE-STATE `election_races_seat_kind_check` | add `'judicial_group'` (the new seat kind, §B.3) to the existing `type_a\|type_b\|single\|exec_committee` list. `seat_kind` is already `varchar(16)` (widened by D-9) — `judicial_group` is 14 chars, fits. |
| RE-STATE `election_races_seats_check` | add the judicial arm to the recut CHECK: `OR (seat_kind = 'judicial_group' AND seats >= 5)` — Art. IV §1 floors a judicial race at `judiciary_min_judges_per_race` (default 5), no constitutional ceiling (the same shape as `exec_committee`). |

`RemovalProceeding::ACTIVE_KINDS` gains `KIND_JUDGE_REMOVAL` — a **model-constant change, not a migration** (`ACTIVE_KINDS` is a PHP array, `removal_proceedings.kind` has no DB CHECK on the value; the Phase C/D precedent). Called out here so the migration PR and the model PR land together.

**No new `terms` migration:** `office_kind='judicial_seat'` is ALREADY in `terms_office_kind_check` (the B-1 migration seeded the full enum); `term_class` already carries both `lockstep` and `civil_appointment`. Judges write straight into the existing table. **No new `appointments` migration:** `appointable_type` is `varchar(64)` with NO value CHECK — `'judicial_seats'` is admissible today (the same freedom `board_seats` used). **No new `laws`/`law_versions` migration:** `scope_judiciary_id`, `origin='judicial_remedy'`, `source='judicial_remedy'` are all reserved.

### E-4 `create_judicial_nominations_table.php` — the nomination tracking row

The two nomination paths (Art. IV §2) both produce a set of nominees, each of which gets its OWN F-LEG-021 consent vote (the mockup shows "one consent vote per nominee"). Appointments + the consent vote alone carry the seating; but the **nomination round** (a constituent's slate, a committee's slate) needs a row so the surface can render "Westchester County · judicial committee fallback" and the audit can prove equal-per-constituent. Minimal:

**`judicial_nominations`**:

| column | type / constraint |
|---|---|
| id | uuid PK default gen_random_uuid() |
| judiciary_id | uuid FK judiciaries cascadeOnDelete |
| seat_id | uuid NULL FK judicial_seats nullOnDelete (the seat this nominee fills; set when the seat is allocated) |
| mode | varchar(20) CHECK (`constituent`,`committee`) — copies `judiciaries.nomination_mode` |
| nominating_jurisdiction_id | uuid NULL FK jurisdictions nullOnDelete (constituent path: who nominated; NULL for committee) |
| nominee_user_id | uuid FK users restrictOnDelete |
| appointment_id | uuid NULL FK appointments nullOnDelete (the consent-pipeline row) |
| dossier_record_id | uuid NULL (public_records — nominee dossier published at nomination, the F-EXE-001 posture) |
| status | varchar(16) CHECK (`nominated`,`consented`,`rejected`,`withdrawn`) |
| timestamps + soft deletes | |
| index `(judiciary_id, status)`; `(nominating_jurisdiction_id)` | |

This is the judiciary analogue of "nominate → appointment → consent → seat" but with the row that makes equal-per-constituent auditable (count nominations grouped by `nominating_jurisdiction_id` must be uniform — the constitutional invariant, §B.2).

---

## B) JUDICIARY FORMATION FLOWS

New service `App\Services\Judiciary\JudiciaryFormationService` (sibling of `ExecutiveFormationService`, same package shape) + new proposal kinds `ChamberVoteProposal::KIND_JUDICIARY_CREATION`, `KIND_JUDICIARY_CONVERSION`, `KIND_JUDICIARY_DISSOLUTION`. The proposal → vote → adoption loop rides a new `App\Services\Judiciary\JudiciaryActService` (sibling of `ExecutiveActService`, same 25-line `propose()` duplication ruling — keeps the chamber-act classes from growing unboundedly; `JUD_KINDS` is the dispatch-router key, dispatched from `ChamberActService::applyProposalAdoption` exactly as `EXEC_KINDS` are).

### B.1 F-LEG-017 — Judiciary Creation Act (WF-JUD-01), APPOINTED by supermajority

Handler `JudiciaryCreationAct` (the prompt's F-LEG-017). The DEFAULT and ONLY output of F-LEG-017 is an **appointed** court (Art. IV §1 hard constraint — `judiciary_is_elected=false`; elected courts come ONLY via F-LEG-018 conversion, never F-LEG-017 directly).

1. **File** F-LEG-017: actor R-09 of this legislature; the jurisdiction's judiciary exists in `forming` (the stub row — already live for every legislature-bearing jurisdiction via `InstitutionStubService`). Payload `{court_name, function_text, judges_per_constituent?: n, committee_judge_count?: m}`. Proposal kind `judiciary_creation` → `ChamberVoteService::open` vote type **`judiciary_create`** (supermajority — the registry key already exists, phase E, `Art. IV §1`). Judiciary `forming → creating`.
2. **Pure asserts** (DB-free, `JudiciaryFormationService::assertCreationShape()` — pinned by the test suite, the `assertDelegationSize` posture): the nomination mode is DERIVED, never an input — it is a structural fact of the jurisdiction (does it have constituents?). If `judges_per_constituent` is supplied it must be `>= 1`; the resulting `judge_count` must be `>= min_judges` (Art. IV §1, the seat-pool floor).
3. **Adoption** (votable-effect dispatch, same txn, in `JudiciaryFormationService::applyCreation`):
   - `EnactmentService::enactDirect(legislature, 'charter', 'Judiciary Creation Act', functionText, vote)` → `creation_law_id` (law kind `charter`, the F-LEG-016 precedent — `enactDirect` writes `scope_judiciary_id=null`; a follow-up `forceFill` sets `laws.scope_judiciary_id` to the new court so judicial-remedy versioning can later scope to it).
   - **Nomination mode resolution** (Art. IV §2 — the constitution decides, not the act): `constituents = JudiciaryFormationService::constituentJurisdictionIds($legislature)` — REUSE the EXACT method from `ExecutiveFormationService` (direct child jurisdictions holding a non-dissolved legislature; the WF-JUR-04 precedent). The constituent-resolution logic is **extracted** into a shared `App\Services\ConstituentResolver` (a small surgical refactor — `ExecutiveFormationService::constituentJurisdictionIds` + `directChildrenWithoutLegislatures` move there; both formation services consume it; zero behavioral change, `ExecConversionDualSupermajorityTest` stays green). This is the §C.1 CivilAppointmentService-extraction posture applied to constituent resolution.
     - `constituents !== []` ⇒ `nomination_mode = 'constituent'` (Art. IV §2: equal number per constituent). `judge_count = equal × count(constituents)` where `equal = judges_per_constituent` (payload, default 1). Each constituent gets `equal` `constituent_nominated` seats.
     - `constituents === []` ⇒ `nomination_mode = 'committee'` (Art. IV §2: judicial committee nominates). `judge_count = committee_judge_count` (payload, `>= min_judges`). All seats are `committee_nominated`.
   - Create `judicial_seats` rows: `seat_number` 1..judge_count, `status='vacant'`, `seat_class` per mode, `nominating_jurisdiction_id` set for the constituent path (round-robin across constituents so the count is provably equal).
   - Judiciary `creating`, `nomination_mode`, `judge_count`, `creation_law_id`, `source_legislature_id` set. (It stays `creating` until the bench seats — it reaches `appointed` only when all seats are consented, §B.4.)
   - Public record (`act`), audit (`F-LEG-017`), `RoleService::flush`.

### B.2 The two nomination paths (Art. IV §2)

**Both paths run THROUGH the F-LEG-021 consent pipeline** (§B.3). They differ only in WHO nominates and the equal-count invariant.

**(a) Constituent nomination** (`nomination_mode='constituent'`, Art. IV §2): an EQUAL number of judges nominated by EACH constituent jurisdiction. New handler family is **not** needed for the nomination act itself — nomination is filed by the constituent's executive/legislature agent through the same `JudiciaryFormationService::nominate(seat, nomineeUserId, nominatingJurisdiction)` which (i) asserts the nominating jurisdiction is a constituent of THIS court (the `judicial_nominations` row's `nominating_jurisdiction_id` must match an allocated `constituent_nominated` seat), (ii) asserts `nominee` holds an active jurisdiction association (Art. I — association is the ONLY eligibility check; neutrality is a duty of office, never an eligibility test — the BoG/F-LEG-012 posture verbatim), (iii) creates the `appointments` row (`appointable_type='judicial_seats'`, `nominated_via_form='F-LEG-021'`-adjacent — see §B.3) + the `judicial_nominations` row + publishes the dossier, (iv) opens the F-LEG-021 consent vote.
   - **Equal-count invariant** (`ConstitutionalValidator::assertEqualConstituentNomination` — PROTECTED, the Courts hook at validator line ~219): across all `constituent_nominated` seats of a judiciary, `count(seats GROUP BY nominating_jurisdiction_id)` must be uniform. This is asserted at seat allocation (creation writes equal slots) AND re-asserted before the court can advance to `appointed`. Citation Art. IV §2.

**(b) Judicial Committee nomination** (`nomination_mode='committee'`, Art. IV §2, for jurisdictions WITHOUT constituents): a Judicial Committee nominates, **by supermajority**. The Judicial Committee is NOT a new institution table — it is modeled as a `committees` row of the chartering legislature with a reserved `kind='judicial'` (the committee substrate already exists from Phase C). The committee's nomination is a **committee vote** (`committee_bill`/`committee_create` machinery) that, on adoption, produces the nominee slate; each nominee then flows into the SAME F-LEG-021 consent vote of the full chamber. The "by supermajority" of Art. IV §2 attaches to the committee's nomination vote (vote type **`committee_create`** reused at supermajority within the committee — the same registry-reuse flag the executive design used for board-chair; flagged for the q-ledger). Minimal Phase E surface: the committee slate is collected as a `JudiciaryFormationService::committeeNominate(judiciary, nominees[])` call gated on a passed committee supermajority vote; the full consent pipeline (§B.3) is identical to path (a).

> **Deferral (committee internal mechanics):** the full Judicial-Committee *membership* lifecycle (who sits on the judicial committee, its own seating) reuses the Phase C committee machinery untouched; Phase E only wires the committee's nomination OUTPUT into seating. A bespoke judicial-committee composition UX is post-E polish.

### B.3 F-LEG-021 — Judicial Nomination Consent Vote (the chamber consent seating, like the BoG consent lane)

New `App\Services\Judiciary\JudicialSeatService` — a **near-verbatim mirror of `BoardGovernorService`** (the prompt's instruction). F-LEG-021 IS the consent vote, cast via F-LEG-004 like every consent — it **stays UNREGISTERED as a handler** (the FormRegistry posture, exactly as F-LEG-020 stays unregistered; the comment at `FormRegistry.php:269/293`). The vote rides the EXISTING `appointment_consent` votable arm.

1. **Nominate** (§B.2 produces this): `appointments` row `appointable_type='judicial_seats'`, `appointable_id=$seat->id`, `nominee_user_id`, `nominated_via_form='F-LEG-021'`; seat `vacant → nominated`; dossier published; consent vote opened in the **chartering legislature**, vote type **`bog_consent`** REUSED (ordinary majority of ALL serving — the same consent threshold the constitution gives board confirmations; Art. IV is silent on the consent threshold, so the unstated-threshold owner ruling MANIFEST §8 applies — flagged q-ledger). *(Alternative considered and rejected: a dedicated `judicial_consent` vote type. Rejected because the threshold is identical to `bog_consent` and the constitution states no special judicial-consent supermajority — adding a key would imply a distinction that does not exist. The registry-reuse flag is recorded for the q-ledger, the board-chair precedent.)*
2. **Consent close**: extend `ChamberActService::resolveConsentVote()` match with `'judicial_seats' => app(JudicialSeatService::class)->seat($appointment)`. This is the ONLY change to the PROTECTED-adjacent consent dispatch — one new arm, the exact shape `board_seats`/`departments` already occupy.
3. **Seat** (`JudicialSeatService::seat`, mirroring `BoardGovernorService::seat`): `judicial_seats` → `seated`, holder set; **10-year civil-appointment term** via the shared `CivilAppointmentService::openCivilTerm(officeKind: 'judicial_seat', officeType: 'judicial_seats', officeId: $seat->id, …)` — `years = SettingsResolver::resolveInt(jurisdiction, 'judicial_appointment_years', 10)`; CLK-09 armed at `ends_on` (the lockstep-with-civil constraint CLK-09/10; the judicial+civil setting pair moves in lockstep per `ClockRegistrySeeder` CLK-09 `setting_keys`). Certification public record; `judicial_nominations` row `consented`; roles flushed → judge derives **R-19** (appointed judge). Rejection → appointment `rejected`, seat back to `vacant`, the constituent/committee re-nominates (the loop — `handleRejectedNomination` mirror).
4. **Advance to `appointed`** (`JudiciarySeatService::maybeAdvanceToAppointed`, the `maybeAdvanceToOperating` mirror): when EVERY seat is `seated` AND the equal-constituent invariant holds → judiciary `creating → appointed`. The court is now live (cases can be filed — the cases agent's entry gate is `status='appointed'|'elected'`).

### B.4 F-LEG-018 — Judiciary Conversion Act → ELECTED (WF-JUD-02), DUAL supermajority

Mirror the Phase D exec-conversion (F-LEG-015) **exactly** — the prompt's explicit instruction. `JudiciaryFormationService` grows `applyConversionAdoption` / `onProcessEvaluated` / `scheduleConversionElection` as direct analogues of the executive methods; the constituent-consent leg REUSES `ExecutiveFormationService::openConstituentConsentVote` / `resolveConstituentConsentVote` machinery — these are **already generic** (the executive design built them "so Phase E judiciary conversion and Art. VII reuse it", per the comment at `ChamberVoteService.php:1119`; the `constituent_consent` votable arm is already wired). The ONLY judiciary-specific work is the subject-effect branch in `onProcessEvaluated` keyed on `subject_type='judiciaries'`.

1. **File** F-LEG-018 (handler `JudiciaryConversionAct`): payload `{judge_count: n ≥ min_judges, charter_text}`. Judiciary must be `appointed` (Art. IV §3 converts an existing appointed court). Validator: `n >= judiciary_min_judges_per_race` (the elected-race floor; Art. IV §1). Proposal kind `judiciary_conversion` → vote type **`judiciary_convert`** (supermajority, `dual='constituent_supermajority'` — the registry key ALREADY carries the dual flag, phase E, `Art. IV §1`).
2. **Chamber leg adopts** (supermajority of the chartering chamber): `enactDirect('charter', 'Judiciary Conversion Act', charterText, vote)` → `conversion_law_id`; then the dual leg, byte-for-byte the executive pattern:
   - `constituents = ConstituentResolver::ids($legislature)` (the shared resolver from §B.1).
   - **None exist** → conversion completes on the chamber supermajority alone (Art. IV §3 "if composed of constituent jurisdictions" — no constituents means no second consent required); judiciary `appointed → conversion_voted`, schedule the judicial election immediately.
   - **Else** → `MultiJurisdictionVoteService::open('judiciary_convert', $legislature, $constituentIds, BASIS_SUPERMAJORITY, $vote, 'judiciaries', $judiciaryId)`; judiciary `appointed → conversion_voted`, `conversion_process_id` set. `required = ConstitutionalValidator::supermajority(total)` (already wired in `MultiJurisdictionVoteService::open`). Childless constituents' absence published on the process record (the executive posture).
3. **Constituent consent flow** (the SAME UX as exec conversion): each constituent legislature receives the queue item; any R-09 opens that chamber's own `constituent_consent` vote (ordinary majority of all serving — `ExecutiveActService::CONSTITUENT_CONSENT_VOTE_TYPE = 'procedural_motion'`, the owner ruling pin; reused verbatim). On close: `recordConsent` → `evaluate` flips the process when arithmetic decides.
4. **`onProcessEvaluated` (subject `judiciaries`)**: passed → `scheduleConversionElection`; failed/expired → judiciary `conversion_voted → reverted` (stays on its appointed footing; the appointed bench keeps sitting — the act stands as a record, nothing re-seats), record published. (The executive falls back to `delegated`/`forming`; the judiciary falls back to `appointed` — the only structural difference, because an appointed court is the resting prior state.)

### B.5 Elected judges via the PROTECTED election machinery (WF-JUD-02 cont.)

`ElectionLifecycleService::scheduleJudicial` — a direct mirror of `scheduleExecutive`:

- `Election::KIND_JUDICIAL` (already exists), `judiciary_id` (new nullable FK on `elections`, §A note below), `legislature_id` (lockstep anchor — judges' terms last the SAME length as legislators, Art. IV §3), `election_board_id` = the jurisdiction's active board.
- **ONE race** (judges are elected in GROUPS, Art. IV §3): `seat_kind='judicial_group'`, `seats = n` (the act's judge_count, `>= min_judges`), counted by the UNTOUCHED `countStv` (PR-STV/Droop/Gregory — STV is the only voting method; min 5 per race is the seat floor, NOT a separate clamp). `electorate_type='residents'` (the whole population, Art. IV §3). `finalist_count = finalistMultiplier × seats`.
- **`elections.judiciary_id`** lands in E-1 (additive nullable FK on `elections` → judiciaries; the `executive_id` precedent — it is a judiciary analogue of the column D-1 added for executives). No recut of `elections_kind_check` — `'judicial'` is already in the enum (the stub elections migration seeded it alongside `executive`).

**Certification** (`CertificationService::certify` grows a `KIND_JUDICIAL` branch → `certifyJudicial`, the `certifyExecutive` mirror — this touches the PROTECTED `CertificationService`, constitutional-review path):
- `certifyJudicial` seats every STV winner as a `judicial_seats` row `seat_class='elected'`, `selection`/provenance via `elected_in_race_id`, holder set, `status='seated'`.
- **Terms (lockstep, CLK-10):** every elected judge gets a `terms` row `office_kind='judicial_seat'`, `term_class='lockstep'`, `legislature_id` set — the SAME `seatExecutiveMember`/`Term::create` shape, just `office_type='judicial_seats'`. **First election after conversion uses `inheritedWindow`** ending on the chartering legislature's CURRENT `term_ends_on` (term = remainder; lockstep is never reset by conversion — the CLK-10 no-API guarantee). Thereafter judicial races ride the general election (the executive precedent — `armNextGeneralElection`/`openSuccessor` extended to add the judicial race whenever the jurisdiction's judiciary is `elected`; CLK-01/CLK-10, one clock, no separate judicial cycle).
- On seating: the appointed-era `judicial_seats` rows close (`status='term_ended'` for the old bench — their civil-appointment terms complete; CLK-09 timers cancelled the same way governor removal cancels them); judiciary `conversion_voted → elected`, `type='elected'`, `converted_at` set, `judge_count` updated. Roles flush → judges derive **R-20** (elected judge). I-JUD evolves on the SAME row (no second judiciary row — ESM-18 is one machine, the executive ESM-16 posture).

**No advisors, no succession-by-advisor** (the executive individual model has runners-up advisors; the judiciary does NOT — judges are elected in a group, there is no single-winner judicial office). A judicial vacancy mid-term fills by **countback** off the certified STV tabulation (the EXISTING `CertificationService::certifyCountback` path — judges are an STV group exactly like a legislature, so countback applies unchanged; the inherited window pins the replacement to the original `ends_on`). Advisors and the `single`-seat succession chain are explicitly N/A here.

### B.6 Judge removal by supermajority (Art. IV §4 — same exposure as legislators)

REUSE the oversight/removal machinery — the prompt's instruction; judges have the SAME removal exposure as legislators (Art. IV §4 "the capacity to be removed from office by Supermajority vote"). This is the EXACT executive-removal-parity pattern (`OversightService` §B.4), now activated for judges:

- `RemovalProceeding::KIND_JUDGE_REMOVAL` joins `ACTIVE_KINDS` (E-3). `OversightService::openProceeding` grows a `subject_type='judicial_seats'` arm (the parallel of the live `executive_members` arm at lines 218–234): the subject must be a `seated` judge of THIS jurisdiction's judiciary. `ConstitutionalValidator::assertRemovalPresider` already handles arbitrary subject types.
- Proceeding → Speaker presides (or chamber designates a substitute) → supermajority vote (`officeholder_remove` — the SAME vote type and threshold as a legislator impeachment; removal parity is the whole point, never a softer threshold). On adoption (`OversightService::resolveRemovalVote` grows the `subject_type='judicial_seats'` effect branch, mirroring the `executive_members` branch at lines 414–434):
  - seat → `removed`, holder cleared, term → `removed`, CLK-09 timer cancelled (appointed judge) — the `BoardGovernorService::resolveRemovalVote` term-cancellation mechanics.
  - **appointed seat** → the constituent/committee re-nominates into a fresh `vacant` seat (the §B.3 loop; equal-constituent invariant re-checked — a removed constituent-nominated judge reopens THAT constituent's slot).
  - **elected seat** → a `vacancies` row → countback off the original tabulation (the legislator path; advisors are N/A so countback is the sole fallback, then special judicial election in the CLK-04 window if the countback pool is exhausted).
- **Threshold pin:** judge removal is `officeholder_remove` (supermajority), NOT the `procedural_motion` ordinary-majority used for governor removal — because Art. IV §4 explicitly grants judges "the same … duties including the capacity to be removed from office by Supermajority vote", whereas governor removal is hiring-and-firing (owner ruling #14). The asymmetry is constitutional and is pinned by a test (§E.3).

---

## C) THE PANEL / SEAT-POOL DISTINCTION (structural only)

Art. IV §4 has two distinct numbers, and this design owns ONLY the first:

| number | what it is | who owns it |
|---|---|---|
| **bench size** (`judiciaries.judge_count`, the `judicial_seats` pool) | how many judges the court HAS — constituent-nominated × constituents, or committee count, or elected group size; `>= min_judges` | **THIS design (structure)** |
| **panel size per case** (≥ 3, odd, severity-scaled; full court for major constitutional questions — CLK-16) | how many of the bench SIT a given case | **the cases agent** (CLK-16, F-JDG-001 case-acceptance/panel-assignment) |

This design provides the **seat pool** (`judicial_seats` where `status='seated'`) and the **en-banc count** (`judiciaries.judge_count` = "the entire court" for major constitutional questions, the mockup's "Full court — all 5 judges"). The cases agent reads `judicial_seats` to assign a panel of `CLK-16`-many seated judges to a case, and reads `judge_count` for en-banc. **No panel/case/recusal columns live on `judicial_seats`** — per-case recusal, panel membership, and case assignment are the cases agent's rows. The structural contract is: *the seat pool is queryable, stable, and class-tagged; the cases agent never mutates seat-pool rows, only reads them.*

CLK-16 (Panel Size, hardened: `value=3`, `odd=true`, `severity_scaled=true`) is SEEDED and owned by the cases agent. This design does not arm it.

---

## D) AMENDABLE SETTINGS + HARD CONSTRAINTS (reuse, nothing new)

Everything the judiciary needs is already in `constitutional_settings` + `ConstitutionalValidator::SETTING_BOUNDS`:

| setting | default | bound (live) | judiciary use |
|---|---|---|---|
| `judicial_appointment_years` | 10 | 1–10, Art. IV §1 | appointed-judge term length (resolved at seating; lockstep with `civil_appointment_years` per CLK-09 `setting_keys`) |
| `civil_appointment_years` | 10 | 1–10, Art. II §9 | the lockstep partner — CLK-10 keeps the pair moving together; `ConstitutionalValidator` must enforce the lockstep equality (see hardened guard below) |
| `judiciary_min_judges_per_race` | 5 | 5–99, Art. IV §1 | the elected-race seat floor (`seats >= this`) AND the appointed seat-pool floor (`judge_count >= this`) |
| `judiciary_is_elected` | false | {true,false}, Art. IV §1 | the DEFAULT-appointed constraint; flipping it is EXACTLY F-LEG-018 (legislature supermajority + constituent supermajority) — the setting is the resolved consequence, never an independent toggle |

**Hardened cross-key guard (added to `ConstitutionalValidator`, PROTECTED — the Courts hook at line ~219):** `settings.judicial_civil_lockstep` — `judicial_appointment_years === civil_appointment_years` (CLAUDE.md: "Must stay in lockstep with civil"; the CLK-09 `semantics: lockstep_pair`). Any F-LEG-031 setting change touching one must move the other (the rule rejects a divergent pair, citation Art. IV §1 · Art. II §9). This is the judiciary analogue of the D-2 `codetermination_ordering` relational guard.

**Never modified (Art. IV §1, CLAUDE.md hard constraints):** default judiciary type = appointed; min judges per race = 5 (amendable floor, never below 5 via the bound); judiciary_is_elected flip needs BOTH supermajorities; STV/Droop is the only method for elected judges (the PROTECTED `VoteCountingService::countStv`, untouched).

---

## E) HANDLERS, ROLES, TESTS, WORK ITEMS

### E.1 Handler registrations (`app/Domain/Forms/Handlers/`, wired in `FormRegistry::HANDLERS`)

| Form | Handler class | Engine effect |
|---|---|---|
| F-LEG-017 | `JudiciaryCreationAct` | proposal `judiciary_creation` → **supermajority** (`judiciary_create`) → charter law + judiciary `creating` + vacant seat pool (mode-derived) |
| F-LEG-018 | `JudiciaryConversionAct` | proposal `judiciary_conversion` → **supermajority** (`judiciary_convert`, dual) → MJV process (`judiciary_convert`) → judicial election |
| F-LEG-022 | `RemovalVote` (EXISTING) | now accepts `subject_type='judicial_seats'` — judge removal at supermajority (`ACTIVE_KINDS` += `judge_removal`) |
| F-LEG-035 | `JudiciaryOverrideVote` | **CASES-AGENT scope** — the Art. IV §5 legislature override of a constitutional finding within the CLK-11 veto window (`judiciary_override` vote type). Listed here for completeness; NOT wired by this (structure) design — it has no structural effect, it acts on a case finding. |
| (F-LEG-021 stays UNREGISTERED — the Judicial Nomination Consent VOTE, cast via F-LEG-004 like every consent; the F-LEG-020 posture) | | |
| (F-JDG-001..010, F-ADV-001..004, F-IND-015/016/017 — CASES-AGENT scope; this design wires NONE of them) | | |

New `ChamberVoteService` plumbing: the `constituent_consent` votable arm is REUSED (already routes to `ExecutiveFormationService` — judiciary conversion shares the generic `ConstituentConsent` row; the `onProcessEvaluated` branch keyed on `subject_type` dispatches the judiciary effect — the cleanest seam is a tiny `subject_type` switch in `resolveConstituentConsentVote`'s `onProcessEvaluated` call, or a thin `JudiciaryFormationService::onProcessEvaluated` that the generic resolver invokes when `subject_type='judiciaries'`). The `appointment_consent` arm gains the `judicial_seats` seating dispatch in `ChamberActService::resolveConsentVote`. New `chamber_vote_proposal` kinds `judiciary_creation`/`judiciary_conversion`/`judiciary_dissolution` resolve in `JudiciaryActService` (dispatched from `ChamberActService::applyProposalAdoption`, the `EXEC_KINDS` precedent).

### E.2 Role derivations (`app/Services/RoleService.php` additions — derived, never stored)

| Role | Derivation (authoritative seat rows) |
|---|---|
| R-19 | seated `judicial_seats` on an **appointed** judiciary (`judiciaries.type='appointed'`, seat `status='seated'`) — the appointed judge |
| R-20 | seated `judicial_seats` on an **elected** judiciary (`type='elected'`) — the elected judge |
| R-21 (advocate) | **CASES-AGENT scope** — derived from F-IND-015 Advocate Registration; not wired here |

R-19/R-20 are the structural roles this design lands (both grant the F-JDG-* filing capability the cases agent consumes). The judiciary-home surface renders the bench from these.

### E.3 Constitutional test specs (`tests/Constitutional/`)

1. **JudiciaryCreationSupermajorityTest** — F-LEG-017 opens at `judiciary_create` (supermajority via `ConstitutionalValidator::supermajority` ONLY); default output is `appointed` (never `elected`); `judge_count >= min_judges`; nomination_mode is DERIVED from constituent presence (constituents ⇒ `constituent`, none ⇒ `committee`), never an input.
2. **EqualConstituentNominationTest** — a constituent-nominated court allocates a provably EQUAL number of `constituent_nominated` seats per constituent (`count GROUP BY nominating_jurisdiction_id` uniform); the court cannot reach `appointed` while the invariant is violated; Art. IV §2 cited on failure.
3. **JudicialConsentSeatingTest** — F-LEG-021 (cast via F-LEG-004, `bog_consent` majority of ALL serving — vacancy in denominator); on adoption a 10-year `civil_appointment` term opens via the shared `CivilAppointmentService` (CLK-09 armed at `ends_on`); rejection reopens the seat (the loop); neutrality is NEVER an eligibility check (association is the only gate).
4. **JudiciaryConversionDualSupermajorityTest** (the F-LEG-018 analogue of `ExecConversionDualSupermajorityTest`) — constituents exist ⇒ chamber adoption alone never flips status past `conversion_voted`; `required = supermajority(constituent_total)`; no constituents ⇒ immediate; per-constituent chamber threshold = ordinary majority (the owner-ruling pin, reused).
5. **JudicialTermLockstepTest** (extends TermLockstepTest) — elected judges' terms are `lockstep` ending on the chartering legislature's `term_ends_on`; first post-conversion term uses `inheritedWindow` (remainder, never reset); NO API mutates `ends_on`; appointed judges' terms are `civil_appointment` 10y; the `judicial_appointment_years === civil_appointment_years` hardened guard.
6. **JudicialElectionStvTest** — the elected race is `judicial_group`/`seats=n>=5`, counted by the UNTOUCHED `countStv` (reflection-pin: no parallel judicial counting math); group election (no single-winner office, no advisors); min-5 floor enforced via `judiciary_min_judges_per_race`.
7. **JudgeRemovalSupermajorityParityTest** — judge removal runs at `officeholder_remove` (supermajority of all serving — identical threshold and machinery as a legislator); structurally it is the `KIND_JUDGE_REMOVAL` proceeding, NOT the ordinary-majority governor path (contrast assertion: governor removal is `procedural_motion`); removed appointed judge reopens that constituent's slot, removed elected judge → countback.
8. **JudiciaryEsmTest** — the ESM-18 walk: `forming → creating → appointed`; `appointed → conversion_voted → elected`; `conversion_voted → reverted` on failed consent; ONE row per jurisdiction across the whole machine (conversion never creates a second `judiciaries` row); appointed-era seats close (`term_ended`) on conversion, never delete.
9. **SeatPoolStabilityTest** (the structural contract with the cases agent) — `judicial_seats` carries the pool and the class tag; `judge_count` = en-banc count = "entire court"; recusal is NOT a seat-pool status (the dropped `recused` value); the seat pool is read-only to anything outside the formation/removal services.

### E.4 Work-item breakdown (judiciary-structure scope; sizes S/M/L, deps, parallelism)

| WI | Size | Content | Deps / parallel-with |
|---|---|---|---|
| **WI-E1** Migrations E-1…E-4 + model-constant changes (proposal kinds, `judicial_group` seat kind, `ACTIVE_KINDS += judge_removal`, `elections.judiciary_id`) | M | one reviewable PR; evolves both stubs; mirrors the D-9 wiring-fix technique so the engine lane never dies on a stale CHECK | none — first |
| **WI-E2** `ConstituentResolver` extraction (surgical refactor out of `ExecutiveFormationService`) + `JudiciaryFormationService` skeleton + `JudiciaryActService` (proposal→vote→adoption loop, `JUD_KINDS` dispatch) | M | the shared substrate; zero behavioral change to Phase D (ExecConversion test stays green) | WI-E1 |
| **WI-E3** F-LEG-017 creation (handler, supermajority, mode resolution, seat-pool allocation, equal-constituent invariant, R-19) | M | exit-criterion leg 1 (appointed court created) | WI-E2 |
| **WI-E4** F-LEG-021 consent pipeline (`JudicialSeatService` — the `BoardGovernorService` mirror; `appointable_type='judicial_seats'` consent arm; 10-yr civil term; both nomination paths feed it; `maybeAdvanceToAppointed`) | M | exit-criterion leg 2 (bench seated, court `appointed`) | WI-E3 |
| **WI-E5** F-LEG-018 conversion + MJV reuse + `scheduleJudicial` + `CertificationService::certifyJudicial` (PROTECTED — constitutional review) + countback for elected vacancies + R-20 | L | the largest item; touches PROTECTED CertificationService; reuses the generic `constituent_consent` arm | WI-E3 (status machine), WI-E1 |
| **WI-E6** Judge removal (`OversightService` `judicial_seats` arm + effect branch; `ACTIVE_KINDS`; reopen/countback) | S | exit-criterion leg 3 (judge removed at supermajority) | WI-E4 (appointed seats) / WI-E5 (elected) |
| **WI-E7** Hardened settings guard (`judicial_civil_lockstep` in ConstitutionalValidator — PROTECTED review) + `judicial_appointment_years` resolution wiring | S | | WI-E1; parallel everything |
| **WI-ET** Constitutional tests E.3 (woven through; CI merge gate) | M | | per-WI |

Critical path: E1 → E2 → E3 → E4 (appointed court, the structural core) and E3/E4 → E5 (conversion). E6 depends on a seated bench; E7 fully parallel. Exit criteria map: **appointed judiciary with a consented, equal-per-constituent bench = E3+E4**; **conversion to an elected court via dual supermajority + STV-elected judges seated lockstep = E5**; **judge removed at supermajority with the seat correctly reopened = E6**.

### E.5 Deferrals (flagged, justified)

- **All case/panel/opinion/finding/remedy/advocate/jury/warrant machinery** — F-JDG-001..010, F-ADV-001..004, F-IND-015/016/017, F-LEG-035 override, CLK-11/CLK-12 arming, CLK-16 panel assignment, and the `law_versions` `judicial_remedy` APPEND are the **cases agent's** scope. This design RESERVES the substrate they consume (`laws.scope_judiciary_id` set at creation, `R-19`/`R-20` derived, the stable seat pool) and wires NONE of it. The seam is §C + §F.
- **Judicial Committee internal composition** — Phase E wires the committee's nomination OUTPUT into seating (path b); the committee's own membership reuses untouched Phase C committee machinery. A bespoke judicial-committee composition UX is post-E.
- **`parent_judiciary_id` (nested/appellate courts)** — column retained from the stub, no flows. The constitution (Art. IV) does not define an appeal hierarchy as a structural workflow; "Judgements overturned only by proven contradictions in law" (Art. II §8, line 143) is a CASE outcome, not a court-hierarchy structure. No structural workflow defines a parent court in the Template; deferred with the column intact.
- **`judiciary_dissolution` (F-LEG-022 against a whole court)** — the proposal kind + ESM `dissolved` state land now (cannot retrofit the state machine cheaply); the dissolution FLOW (what happens to in-flight cases) is cases-agent-adjacent and minimal in E (the court stops accepting filings; seated judges' terms close). Full wind-down is post-E.
- **Elected-judiciary general-election integration** — `scheduleJudicial` + the first inherited-window conversion election land in E; folding the recurring judicial race into `armNextGeneralElection` (so it rides CLK-01 thereafter) reuses the executive pattern and lands with E5, but the multi-cycle recurrence is exercised only when a general election fires (the executive precedent — same posture).
- **Registry gaps flagged for the q-ledger:** F-LEG-021 reuses `bog_consent` (the judicial-consent threshold is unstated → ordinary-majority owner ruling); the judicial-committee nomination reuses `committee_create` at supermajority; "constituent = legislature-bearing direct child" (the shared `ConstituentResolver`, the WF-JUR-04 precedent inherited from the executive design).

---

## F) THE STRUCTURE ↔ CASES CONTRACT (binding for the cases agent)

What this (structure) design GUARANTEES and the cases agent CONSUMES:

1. **The seat pool** — `judicial_seats WHERE judiciary_id = ? AND status = 'seated'` is the stable, class-tagged roster. The cases agent reads it to assemble a CLK-16-sized panel and to identify the full bench (en-banc) for major constitutional questions. The cases agent NEVER writes seat-pool rows (no recusal column, no panel column — those are case-side).
2. **`judiciaries.judge_count`** — the en-banc count ("the entire court", Art. IV §4) and the seat-pool floor.
3. **`laws.scope_judiciary_id`** — set on the creation law at F-LEG-017 adoption so judicial-remedy `law_versions` (`source='judicial_remedy'`, Art. IV §5 — the cases agent appends them when a finding's remedy window lapses) can scope to the court that issued them. The append substrate (`Law::ORIGIN_JUDICIAL_REMEDY`, `LawVersion::SOURCE_JUDICIAL_REMEDY`) is RESERVED, not written, here.
4. **`R-19`/`R-20`** — the appointed/elected judge roles that grant F-JDG-* filing capability. Derived by this design; consumed by the cases agent's handlers.
5. **`status IN ('appointed','elected')`** — the cases agent's entry gate: a case can only be filed against / accepted by a court that has reached one of these resting states (a `forming`/`creating`/`conversion_voted`/`reverted` court cannot hear cases).

The clock family CLK-11 (Judicial Veto Window) / CLK-12 (Legislative Remedy Timeframe) / CLK-16 (Panel Size) is SEEDED but armed ENTIRELY by the cases agent — this design touches none of them.
