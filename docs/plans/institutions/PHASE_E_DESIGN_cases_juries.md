# PHASE E DESIGN — CASES, JURIES & ADVOCATES

The adjudication core of Article IV §4: cases (+ parties), filings, panels (judges SAT to a case — odd, ≥3, severity-scaled, en banc for major constitutional questions), juries (+ members — "a jury of their peers"), verdicts, sentencing orders, warrants, opinions (+ the laws they cite), and advocates (R-21 · F-IND-015 registration). Double-jeopardy enforcement is a hardened validator rule; the case ESM and the panel-sizing function are the two pinned cores.

Verified against the live worktree (`E:\fair-constitution-app\.claude\worktrees\practical-payne-17d537`):
- **`judiciaries` / `judicial_seats` already exist** (`database/migrations/2026_04_25_000003_create_judiciaries_tables.php`): `judiciaries` carries `type IN ('appointed','elected')` (default appointed), `min_judges >= 5` CHECK, `term_years` default 10, `status IN ('forming','active','dissolved')`, `parent_judiciary_id`; `judicial_seats` carries `user_id`, `seat_number`, `term_starts_on/ends_on`, `status IN ('vacant','seated','recused','retired')`. Models `App\Models\Judiciary` + `App\Models\JudicialSeat` live. **This area does NOT design those tables** — the *judiciary-formation* sibling design owns judiciary creation/conversion/seating; THIS area assumes a seated `judicial_seats` pool exists and builds the adjudication tables on top. The dependency is one-directional.
- `terms` (`2026_06_13_000001_create_terms_table.php`) already carries `office_kind='judicial_seat'` in its CHECK and `term_class='civil_appointment'` — appointed judges open 10-yr terms through `App\Services\CivilAppointmentService::openCivilTerm(...)` exactly like board governors (CLK-09 armed at `ends_on`), the formation design's concern.
- `elections.kind` CHECK already contains `'judicial'` (`2026_06_13_000003_evolve_elections_table.php`); `config/constitution/vote_types.php` already registers `judicial_election` (`category rcv_stv`, `engine stv_count`, `denominator civic_population`, phase E), `judiciary_create`, `judiciary_convert` (`dual constituent_supermajority`), and `judiciary_override` (phase E, Art. IV §5) — all UNWIRED to handlers. The elected-judge path reuses the PROTECTED `VoteCountingService::countStv` wholesale, like board elections did.
- `LawVersion::SOURCE_JUDICIAL_REMEDY` exists and `EnactmentService::amendLaw(...)` already pierces the referendum shield **only** for that source (`EnactmentService.php:177`) — the F-JDG-006 law-edit hook is fully reserved. The Art. IV §5 finding→remedy tracker (F-JDG-004/005/006/007/008 + F-LEG-035 override) is the **constitutional-challenge sibling design's** scope; THIS area builds cases/panels/juries/verdicts/sentencing/warrants/opinions and the F-JDG-001/002/003/009/010 + F-IND-015/017 + F-ADV-001..004 family.
- `public_records.kind` CHECK already contains `'opinion'` (`2026_06_20_000001`) — reserved for Phase E opinion publication; `RemovalProceeding::KIND_JUDGE_REMOVAL` exists; `ClockTimer.override_value` jsonb is "the Phase E per-case slot (CLK-11/CLK-12: window set by the judiciary per finding) — present now, written by nothing yet"; `ConstitutionalValidator::EMERGENCY_PROTECTED_FORMS` already shields `F-JDG-001..010` + `F-IND-016/017` (courts are protected from emergency powers from day one).
- `RoleService` derives R-01..R-30 with R-18/R-23..R-30 already live; **R-19/R-20 (judges) / R-21 (advocate) / R-22 (juror) are the new derivations this area adds** (judge derivations may also land with the formation design — coordinated below). `App\Services\Executive\ExecutiveActService` is the proposal→vote→adoption template (`propose()` helper, `KIND_*` proposal kinds) this area mirrors as `JudicialActService` for the formation siblings only — cases/filings do NOT ride the chamber-vote lane.

**Scope boundary (binding):** this design is **cases-juries-advocates**. It owns: `cases`, `case_parties`, `case_filings`, `panels`, `panel_judges`, `juries`, `jury_members`, `verdicts`, `sentencing_orders`, `warrants`, `opinions`, `opinion_law_links`, `advocates`; handlers F-IND-015, F-IND-017, F-ADV-001..004, F-JDG-001/002/003/009/010; the double-jeopardy hardened rule; the panel-sizing pure function; the case ESM. It does NOT own judiciary creation/conversion/seating (F-LEG-017/018/021/035, F-JDG-004..008), nor the Art. IV §5 law-remedy tracker — those are the two sibling Phase E designs. Shared substrate (the `judiciaries`/`judicial_seats` tables, the panel-sizing function's home, the R-19/R-20 derivations) is flagged at every coordination point.

---

## A) MIGRATION SET (all additive; `database/migrations/2026_08_xx_*`)

Eight new tables in one reviewable batch. Every table: uuid PK `gen_random_uuid()`, `timestampsTz`, `softDeletesTz` (except the two append-only registers, noted). No PostgreSQL ENUM types — string + app-layer validation + DB CHECK, the project convention.

### E-1 `create_cases_table.php` (ESM-CASE)

**`cases`** — one dispute before a judiciary; the spine of WF-JUD-03.

| column | type / constraint |
|---|---|
| id | uuid PK default gen_random_uuid() |
| docket_no | varchar(24) — `case-YYYY-NNN` per judiciary per year, allocated under `pg_advisory_xact_lock` (the `EnactmentService::allocateActNumber` pattern) |
| judiciary_id | uuid FK `judiciaries` restrictOnDelete — the court hearing it |
| jurisdiction_id | uuid FK `jurisdictions` restrictOnDelete — the resolved scale (which jurisdiction's law is at issue; drives which court level) |
| kind | varchar(16) CHECK (`civil`,`criminal`,`administrative`,`constitutional`) — `constitutional` arrives via F-IND-016 (the challenge filing, sibling design) and routes to the Art. IV §5 tracker; the other three are F-IND-017 / F-ADV-001 |
| title | varchar(255) — `State v. Whitfield`, `Okafor v. Crown Ridge LLC` |
| statement_of_claim | text — what happened + remedy sought |
| claimed_severity | varchar(12) CHECK (`minor`,`moderate`,`serious`) NULL — the filer's input |
| court_severity | varchar(12) CHECK (`minor`,`moderate`,`serious`,`constitutional_major`) NULL — **the court's classification at acceptance** (drives panel size; NULL until accepted). The mockup's "panel size follows the court's classification, not yours" |
| jury_entitled | boolean NOT NULL DEFAULT false — set true on acceptance when `kind='criminal'` AND not waived (Art. IV §4 — jury attaches to criminal accusations) |
| jury_waived | boolean NOT NULL DEFAULT false — the accused may waive |
| filed_via_form | varchar(16) NOT NULL CHECK (`F-IND-017`,`F-ADV-001`,`F-IND-016`) — provenance chip ("filed via F-ADV-001") |
| filed_by_user_id | uuid FK `users` nullOnDelete — the human who filed (the filer OR the advocate) |
| filed_on_behalf_of_user_id | uuid FK `users` nullOnDelete — the client when filed via advocate (F-ADV-001) |
| advocate_id | uuid FK `advocates` nullOnDelete — set when filed via R-21 |
| panel_id | uuid NULL — FK added in E-3 (forward ref, like `terms.source_election_id`) |
| jury_id | uuid NULL — FK added in E-4 (forward ref) |
| status | varchar(20) CHECK — the ESM (below) |
| double_jeopardy_locked | boolean NOT NULL DEFAULT false — set true the moment a `criminal` case reaches a terminal verdict; the hardened re-prosecution bar reads this (Art. II §8) |
| accepted_at, decided_at, closed_at | timestamptz NULL |
| timestamps + soft deletes | |
| Unique `(judiciary_id, docket_no) WHERE deleted_at IS NULL` | docket numbers are per-court |
| Index `(judiciary_id, status)`, `(jurisdiction_id)`, `(kind, status)` | docket queries |

**ESM-CASE** (the case lifecycle, pinned by `CaseLifecycleTest`; bracketed states are conditional passes):

```
filed → accepted → paneled → [jury_empaneled] → heard → deliberation → decided → sentenced → closed
                 ↘ dismissed (not justiciable / withdrawn)            ↘ closed (no sentence: civil/acquittal)
                                                                       [appealed] → (re-enters at a wider panel)
```

- `filed` — row created by F-IND-017 / F-ADV-001 (constitutional challenges enter `filed` too, then branch).
- `accepted` — F-JDG-001 confirms justiciability + fixes `court_severity` + sets `jury_entitled`; OR `dismissed` (not a real dispute / no standing-beyond-association / withdrawn). Standing is association-only (Art. I — "no standing gatekeeper beyond jurisdictional association"); the engine never gates filing by a merits test.
- `paneled` — F-JDG-001's panel-assignment leg seats the `panel`/`panel_judges` (odd, ≥3, severity-scaled; en banc for `constitutional_major`).
- `jury_empaneled` — only when `jury_entitled` AND not waived; F-JDG-002 creates the `juries`/`jury_members` draw.
- `heard` — arguments, evidence, motions (the open-record stages; F-ADV-002/003/004 attach here).
- `deliberation` — chambers + jury room (the only unrecorded space).
- `decided` — a `verdicts` row exists (panel ruling and/or jury verdict).
- `sentenced` — only on a guilty criminal verdict (F-JDG-009 sentencing order); civil/acquittal skip straight to `closed`.
- `closed` — opinion published (F-JDG-003), terminal.
- `appealed` — re-enters the lifecycle at a wider panel (a NEW `cases` row referencing `appeal_of_case_id` — minimal in E; see deferrals).

The status CHECK string enumerates exactly these values plus `dismissed` and `appealed`. Transitions are gated by `CaseService` (no UI sets `status` directly), and every transition writes an audit-chain row + (for public stages) a `public_records` row.

### E-2 `create_case_parties_table.php`

**`case_parties`** — who is on each side (prosecution/plaintiff/defense/respondent/intervenor).

| column | type / constraint |
|---|---|
| id, case_id FK cascade | |
| party_role | varchar(16) CHECK (`prosecution`,`plaintiff`,`defendant`,`respondent`,`intervenor`,`accused`) — `accused` marks the natural person entitled to jury + counsel in a criminal case |
| party_type | varchar(16) CHECK (`individual`,`organization`,`jurisdiction`,`government_body`) — polymorphic principal |
| party_user_id | uuid NULL FK `users` nullOnDelete | the individual when `party_type='individual'` |
| party_ref_type | varchar(32) NULL + party_ref_id uuid NULL | `organizations` / `jurisdictions` / `departments` (a Justice Department as prosecuting authority) when not an individual — no FK (polymorphic, immutability over cascade) |
| represented_by_advocate_id | uuid NULL FK `advocates` nullOnDelete | the engaged advocate (Art. I right to representation); NULL = self-represented |
| retainer_note | text NULL | "the retainer is recorded with the filing" (advocate-console mockup) |
| status | varchar(12) CHECK (`active`,`withdrawn`,`substituted`) |
| timestamps + soft deletes | |
| Index `(case_id, party_role)`, `(party_user_id)` | |

The accused's right to counsel (Art. I) is rendered, never enforced as a precondition: a `defendant`/`accused` may be self-represented; the engine does not block a hearing for lack of an advocate.

### E-3 `create_panels_tables.php` (the odd/severity-scaled panel)

**`panels`** — the bench SAT to one case.

| column | type / constraint |
|---|---|
| id, case_id FK cascade | one panel per case (`UNIQUE (case_id) WHERE deleted_at IS NULL`) |
| judiciary_id FK `judiciaries` restrict | the court the judges are drawn from |
| size | smallint NOT NULL CHECK (`size >= 3 AND size % 2 = 1`) — **odd, ≥3** enforced at the DB belt; the app computes it via the pure function (D.1) |
| is_en_banc | boolean NOT NULL DEFAULT false — true ⇒ the entire seated court (every non-recused `judicial_seats` row) sits; set for `constitutional_major` severity (CLK-16) |
| severity_basis | varchar(20) CHECK (`minor`,`moderate`,`serious`,`constitutional_major`) — the input that produced `size` (snapshot for audit) |
| presiding_judge_seat_id | uuid NULL FK `judicial_seats` nullOnDelete | the presiding judge |
| draw_seed | varchar(64) NULL — the published random-draw seed (audit-chain sealed, like the jury draw); deterministic re-draw on recusal |
| status | varchar(16) CHECK (`drawing`,`screening`,`seated`,`dissolved`) |
| timestamps + soft deletes | |

**`panel_judges`** — the join (which seated judges sit, with recusal screening).

| column | type / constraint |
|---|---|
| id, panel_id FK cascade, judicial_seat_id FK `judicial_seats` restrict | |
| user_id | uuid FK `users` nullOnDelete — snapshot of the seat holder at draw |
| is_presiding | boolean NOT NULL DEFAULT false |
| screening_result | varchar(16) CHECK (`pending`,`cleared`,`recused`) DEFAULT 'pending' — conflict screening (personal / financial / prior-involvement) |
| recusal_reason | text NULL — published when recused ("authored the advisory opinion the prosecution cites") |
| status | varchar(12) CHECK (`drawn`,`seated`,`recused`,`replaced`) |
| timestamps + soft deletes | |
| Partial unique `(panel_id, judicial_seat_id) WHERE deleted_at IS NULL` | a seat sits once |
| Partial unique `(panel_id) WHERE is_presiding AND status='seated' AND deleted_at IS NULL` | one presiding judge |

A recused judge is `replaced` and the draw re-runs over the remaining cleared seats (the seed advances deterministically); the conflict-screening result attaches to the case record (mockup: "screening results attach to the case record").

### E-4 `create_juries_tables.php` ("a jury of their peers")

**`juries`** — one jury per criminal case (when entitled + not waived).

| column | type / constraint |
|---|---|
| id, case_id FK cascade | `UNIQUE (case_id) WHERE deleted_at IS NULL` |
| selection_order_id | uuid NULL — the F-JDG-002 filing row in `case_filings` (forward-ref note) |
| pool_size | int NOT NULL — eligible jurisdictionally-associated residents drawn from (14,733,408 in the fixture) |
| eligible_jurisdiction_id | uuid FK `jurisdictions` restrict — the pool's jurisdiction (residents of New York) |
| seats | smallint NOT NULL DEFAULT 12 | jurors empaneled (jurisdiction-configurable; default 12) |
| alternates | smallint NOT NULL DEFAULT 2 | |
| draw_seed | varchar(64) NOT NULL — **published to the audit chain; "anyone can verify the draw"** (juror-view mockup; the same seal as the panel draw) |
| report_on | timestamptz NULL — when summoned jurors report |
| status | varchar(16) CHECK (`drawing`,`voir_dire`,`empaneled`,`deliberating`,`discharged`) |
| timestamps + soft deletes | |

**`jury_members`** — the summoned/empaneled jurors (the juror-view R-22 surface reads this).

| column | type / constraint |
|---|---|
| id, jury_id FK cascade, user_id FK `users` restrict | |
| seat_kind | varchar(12) CHECK (`juror`,`alternate`) |
| seat_no | smallint NULL — assigned at empanelment |
| screening_status | varchar(16) CHECK (`summoned`,`screening`,`cleared`,`excused`,`empaneled`,`discharged`) — the juror stepper (Summoned → Conflict screening → Empaneled → Trial → Deliberation → Discharged) |
| excusal_reason | varchar(24) NULL CHECK (`conflict`,`hardship`,NULL) — **never opinion/demographics/politics** (voir dire removes conflicts only — hardened gloss); a confirmed conflict excuses without penalty and triggers a replacement draw |
| timestamps + soft deletes | |
| Partial unique `(jury_id, user_id) WHERE deleted_at IS NULL` | a resident is drawn once per jury |
| Index `(user_id, screening_status)` | the juror's own-summons lookup (R-22) |

Civic-obligation protections are NOT new columns — they are existing hardened rules surfaced on this data: jury service may never carry a fee (Art. II §8 Prohibition of Compulsory Payments — the `FORBIDDEN_ELIGIBILITY_KEYS`/no-fee posture already in `ConstitutionalValidator`) and may never be interfered with by an employer (Art. II §8 Non-Interference). The juror-view surface renders these as hardened chips; the engine enforces no-fee structurally (no payment field exists anywhere on the jury forms).

### E-5 `create_verdicts_sentencing_warrants.php`

**`verdicts`** — the decided outcome (panel ruling and/or jury verdict).

| column | type / constraint |
|---|---|
| id, case_id FK cascade | one operative verdict per case (`UNIQUE (case_id) WHERE deleted_at IS NULL`); appeals create a new case |
| decided_by | varchar(12) CHECK (`panel`,`jury`) — criminal guilt is the jury's; the panel rules on law/civil/administrative outcomes |
| outcome | varchar(20) CHECK (`guilty`,`not_guilty`,`liable`,`not_liable`,`dismissed`,`for_petitioner`,`for_respondent`) |
| panel_vote_for, panel_vote_against | smallint NULL — the panel tally (odd size ⇒ no ties possible by construction) |
| jury_unanimous | boolean NULL — fixture posture: criminal verdict reporting (jury-room deliberation is unrecorded; the verdict itself is recorded) |
| summary | text — the recorded verdict statement |
| double_jeopardy_flag | boolean NOT NULL DEFAULT false — **set true ⇔ `cases.kind='criminal'`; a CHECK pins the implication** `(double_jeopardy_flag = (SELECT kind FROM cases ...))` enforced in the service (cross-table CHECK is impractical in PG; the `CaseService` sets both this and `cases.double_jeopardy_locked` in one transaction) |
| record_id | uuid NULL — `public_records` linkage (verdicts publish) |
| decided_at | timestamptz |
| timestamps + soft deletes | |

**`sentencing_orders`** (F-JDG-009) — issued only on a guilty criminal verdict.

| column | type / constraint |
|---|---|
| id, case_id FK cascade, verdict_id FK `verdicts` restrict | |
| issued_by_seat_id | uuid FK `judicial_seats` restrict — the panel issues |
| terms | text NOT NULL — the sentence terms |
| effective_at, expires_at | timestamptz NULL — duration where bounded |
| status | varchar(12) CHECK (`issued`,`stayed`,`vacated`,`completed`) — `vacated` only via the overturn path (E.3 / double jeopardy rule) |
| record_id | uuid NULL | published |
| timestamps + soft deletes | |
| CHECK at service layer: `verdict.outcome='guilty'` before insert | sentencing without a guilty verdict is rejected |

**`warrants`** (F-JDG-010) — arrest/search/seizure authorization (Art. II §8 Arrest Warrant Requirement).

| column | type / constraint |
|---|---|
| id, case_id FK cascade | |
| issued_by_seat_id | uuid FK `judicial_seats` restrict |
| kind | varchar(12) CHECK (`arrest`,`search`,`seizure`) |
| stated_reason | text NOT NULL — **"establishing the reason for the arrest"** (Art. II §8 — constitutionally mandatory; CHECK NOT NULL + non-empty service guard) |
| max_hold_duration_hours | int NULL CHECK (`> 0`) — **"the maximum duration an Individual can be held"** (Art. II §8; required for `arrest`, NULL for search/seizure — service-enforced) |
| subject_user_id | uuid NULL FK `users` nullOnDelete | the named individual for an arrest |
| status | varchar(12) CHECK (`issued`,`executed`,`expired`,`quashed`) |
| issued_at, executed_at, expires_at | timestamptz NULL |
| record_id | uuid NULL | published |
| timestamps + soft deletes | |

A warrant with no stated reason or (for arrest) no maximum hold duration is structurally unfilable — the two constitutional facts are NOT NULL columns and the handler re-asserts them with citation. This is the engine form of "no arrest except with a warrant from a court establishing the reason and the maximum duration."

### E-6 `create_opinions_tables.php`

**`opinions`** (F-JDG-003) — the panel's commentary on the law (NOT a change to it).

| column | type / constraint |
|---|---|
| id, case_id FK cascade, panel_id FK `panels` restrict | |
| authored_by_seat_id | uuid FK `judicial_seats` restrict — the writing judge ("Dr. Lena Novák writing") |
| kind | varchar(12) CHECK (`majority`,`concurrence`,`dissent`) — multiple opinions per case allowed (so no `UNIQUE (case_id)`) |
| title | varchar(255), body text NOT NULL | |
| record_id | uuid NULL — `public_records.kind='opinion'` (the enum value already reserved) |
| published_at | timestamptz NULL |
| timestamps + soft deletes | |
| Index `(case_id, kind)` | |

**`opinion_law_links`** — the laws an opinion cites/interprets (the prompt's "opinion law_links").

| column | type / constraint |
|---|---|
| id, opinion_id FK cascade, law_id FK `laws` restrict | |
| law_version_no | smallint NULL — pins the opinion to the law's text AS IT STOOD ("commentary on the law as written or edited" — the version it interprets) |
| relation | varchar(12) CHECK (`cites`,`interprets`,`distinguishes`,`applies`) |
| note | text NULL — the interpretive note |
| timestamps + soft deletes | |
| Partial unique `(opinion_id, law_id, law_version_no) WHERE deleted_at IS NULL` | |

This is the structural realization of "opinions are linked to every law they interpret." Crucially, an opinion link is **commentary only** — it never mutates `laws`/`law_versions`. Changing a law's text is the Art. IV §5 process (F-JDG-006, the sibling design), which appends a `LawVersion` with `source='judicial_remedy'`. The two are deliberately separate tables: an opinion can interpret a law without editing it.

### E-7 `create_case_filings_table.php` (the docket — append-only)

**`case_filings`** — every motion/evidence/brief/order docketed to a case. Append-only register (no `updated_at`, no soft deletes, BEFORE UPDATE/DELETE trigger — same posture as `public_records`/`audit_log`); the docket is immutable, "nothing argued in open court is ever sealed retroactively."

| column | type / constraint |
|---|---|
| id (uuid unique) + seq (bigint identity PK) | publication-order PK, the `public_records` shape |
| case_id | uuid FK `cases` (no cascade — append-only register survives) |
| filing_form | varchar(16) NOT NULL CHECK (`F-IND-017`,`F-ADV-001`,`F-ADV-002`,`F-ADV-003`,`F-ADV-004`,`F-JDG-001`,`F-JDG-002`,`F-JDG-003`,`F-JDG-009`,`F-JDG-010`) — which instrument |
| filing_kind | varchar(16) CHECK (`case_filing`,`motion`,`evidence`,`brief`,`order`,`panel_assignment`,`jury_order`,`opinion`,`sentence`,`warrant`,`ruling`) |
| filed_by_user_id | uuid NULL — actor snapshot (advocate, judge, party) |
| filed_by_role | varchar(8) NULL — `R-19`/`R-20`/`R-21`/`R-03` snapshot |
| advocate_id | uuid NULL FK `advocates` nullOnDelete — when filed by counsel |
| title, body | text |
| ruling | varchar(12) NULL CHECK (`granted`,`denied`,`admitted`,`excluded`,NULL) — the court's disposition of a motion/evidence (with written reasons on the public record) |
| ruling_reason | text NULL — "written reasons on the public record" |
| accepted_at_state | varchar(20) NULL — the case ESM state the filing attached at (motions before/during `heard`, evidence on the open docket, briefs until `deliberation` — the attach-window gate) |
| record_id | uuid NULL — `public_records` linkage (every filing lands on the public docket) |
| audit_seq | bigint NULL — chain seal |
| created_at | timestamptz default now() |
| Index `(case_id, seq)`, `(advocate_id)`, `(filed_by_user_id)` | |

The attach-window rule ("motions before and during Hearing, evidence on the open docket, briefs until Deliberation") is enforced in `CaseFilingService::docket()` against the live `cases.status` — a brief filed after `deliberation` is rejected with citation; this is the advocate-console "no filings accepted" gate.

### E-8 `create_advocates_table.php` (R-21 · F-IND-015)

**`advocates`** — registered advocates ("keeps the bar of advocates zealous and competent").

| column | type / constraint |
|---|---|
| id, user_id FK `users` restrict | one advocate row per user per judiciary |
| judiciary_id | uuid FK `judiciaries` restrict — the court system the registration covers ("practice rights cover every court of New York and its constituent counties" — the registration is at the judiciary level, inherited by descendants) |
| jurisdiction_id | uuid FK `jurisdictions` restrict — the registering jurisdiction |
| status | varchar(12) CHECK (`registered`,`suspended`,`withdrawn`) DEFAULT 'registered' |
| qualifications_note | text NULL — "qualifications per state law" (the qualifications are *jurisdiction-set competence*, NOT a constitutional eligibility test on the right to practice; see the rights posture below) |
| registered_at | timestamptz |
| timestamps + soft deletes | |
| Partial unique `(user_id, judiciary_id) WHERE deleted_at IS NULL` | |
| Index `(judiciary_id, status)` | the advocate bar lookup |

**Rights posture on advocate registration (Art. I + Art. IV §4):** registration is available to any R-03 (associated resident). Competence qualifications are a property of the *bar* a jurisdiction maintains, not a gate on the underlying right of a client to representation — the client's right to "zealous and competent advocates" is satisfied by the bar existing, not by restricting who may be a party. F-IND-015 is therefore NOT added to `RIGHTS_AUTOMATIC_FORMS` (it is not a residency/voting/candidacy right), but its handler rejects only on association + duplicate, never on a merits/identity test. The judiciary-formation design owns whether a jurisdiction's law adds competence steps; this area builds the registration row + the R-21 derivation.

---

## B) HANDLER LIST (`app/Domain/Forms/Handlers/`, wired in `FormRegistry::HANDLERS`)

The form catalog already names all of these (`FormRegistry::FORMS`); they are currently UNREGISTERED in `HANDLERS`. This area registers nine.

| Form | Handler class | Engine effect |
|---|---|---|
| F-IND-015 | `AdvocateRegistration` | actor R-03; creates `advocates` row (association + duplicate checks); derives R-21; public record kind `registration` |
| F-IND-017 | `CaseFiling` | actor R-03 (self) OR R-21 (advocate, sets `advocate_id`/`filed_on_behalf_of_user_id`); creates `cases` row `status='filed'` + `case_parties` + the opening `case_filings` row; docket_no allocated; published |
| F-ADV-001 | `AdvocateCaseFiling` | actor R-21; same as F-IND-017 but always on-behalf-of a client; the retainer note is recorded with the filing |
| F-ADV-002 | `MotionFiling` | actor R-21 (or self-rep party); appends a `case_filings` row `filing_kind='motion'`; attach-window gate (`heard`/pre-`heard`); awaits a judge ruling |
| F-ADV-003 | `EvidenceSubmission` | actor R-21/party; `case_filings` `filing_kind='evidence'`; attach-window = open evidence docket; admissibility ruling by the panel |
| F-ADV-004 | `BriefFiling` | actor R-21/party; `case_filings` `filing_kind='brief'`; attach-window = until `deliberation` |
| F-JDG-001 | `CaseAcceptanceAndPanelAssignment` | actor R-19/R-20 of the case's judiciary; confirms justiciability, fixes `court_severity`, sets `jury_entitled`; **computes panel size via the pure function**, draws the panel, runs conflict screening, seats `panels`/`panel_judges`; case `filed → accepted → paneled` (or `dismissed`) |
| F-JDG-002 | `JurySelectionOrder` | actor R-19/R-20; creates `juries` + the random draw of `jury_members` (seed published to the audit chain); case `→ jury_empaneled` |
| F-JDG-003 | `OpinionRulingFiling` | actor R-19/R-20; `opinions` + `opinion_law_links` rows; publishes kind `opinion`; case `→ closed` |
| F-JDG-009 | `SentencingOrder` | actor R-19/R-20; requires a guilty `verdicts` row; `sentencing_orders` row; case `→ sentenced`; published |
| F-JDG-010 | `WarrantIssuance` | actor R-19/R-20; `warrants` row; stated-reason + max-hold-duration constitutionally required; published |

**Not in this area's scope (sibling designs register them):** F-IND-016 (Constitutional Challenge Filing — creates a `cases` row `kind='constitutional'` then branches to the Art. IV §5 tracker; the case-creation half coordinates with `CaseService::open` here, the finding half is the challenge design), F-JDG-004/005/006/007/008, F-LEG-017/018/021/035. The `verdict` itself is recorded by the F-JDG-003 path or a dedicated verdict transition inside `CaseService` — **decision: the verdict is NOT a standalone form** (there is no F-JDG verdict form in the catalog; deliberation produces a verdict as a case-state transition, recorded by `CaseService::recordVerdict`, then F-JDG-009/003 follow). This matches the mockup's lifecycle (Judgement is stage 9, between Deliberation and Opinion, carrying the double-jeopardy flag — not a form submission).

The double-jeopardy / no-fee shields are NOT handlers — they are validator rules (D.2) invoked by the engine before any handler runs.

---

## C) SERVICE RESPONSIBILITIES

### New services (`app/Services/Judiciary/`)

**`CaseService`** — the ESM-CASE owner. `open()` (filed), `accept()` / `dismiss()`, `assignPanel()` (delegates sizing to the pure function + draw), `empanelJury()`, `advanceToHearing()`, `enterDeliberation()`, `recordVerdict()` (sets `double_jeopardy_locked` + the verdict's `double_jeopardy_flag` atomically for criminal cases), `sentence()`, `publishOpinion()`, `close()`. Every transition: guard the legal ESM edge, write the audit row, publish the record. No other class mutates `cases.status`. Docket-number allocation reuses the `pg_advisory_xact_lock` pattern from `EnactmentService::allocateActNumber`.

**`PanelService`** — owns the panel draw + conflict screening + recusal re-draw. Calls `PanelSizing::sizeFor()` (D.1). Seeds the draw, publishes the seed to the audit chain (same seal as the jury draw), screens each drawn `judicial_seats` row, recuses + re-runs the draw on a confirmed conflict, sets the presiding judge. En-banc path: when severity is `constitutional_major`, `is_en_banc=true` and the panel = every non-recused seated seat (CLK-16 hardened).

**`JuryService`** — owns the random draw from the eligible jurisdictionally-associated pool, voir-dire screening, empanelment, replacement draws on excusal. Publishes the draw seed. Enforces conflict-only excusal (never opinion/demographics/politics). The pool is residents with an active residency confirmation in the eligible jurisdiction (reuses `residency_confirmations` — the R-03/R-04 substrate).

**`CaseFilingService`** — the append-only docket writer. `docket()` validates the attach-window against `cases.status`, writes the `case_filings` row, seals it to the chain, publishes the public-docket record. Judge rulings on motions/evidence (granted/denied/admitted/excluded + written reasons) append a follow-up filing row, never edit the original.

**`AdvocateService`** — registration (F-IND-015), suspension/withdrawal, and the bar lookup. Thin; the substantive work is the R-21 derivation.

**`PanelSizing`** — a pure, DB-free static class (the home of the panel-sizing function, D.1), pinned exhaustively by the constitutional suite. Lives beside `VoteCountingService`-style pure cores. **Coordination:** the judiciary-formation design may want the same class for nothing else; it is owned here.

### Existing services extended

- **`CivilAppointmentService`** — unchanged; appointed judges open `office_kind='judicial_seat'` terms through it (the formation design's call site). Noted only because cases assume seated judges exist.
- **`VoteCountingService` (PROTECTED)** — unchanged and reused verbatim for elected-judge races (`countStv`); cases never touch counting. Listed so the reuse is explicit: elected judiciaries are a `judicial_election` race (vote_types already registered) feeding `judicial_seats`, exactly the board-election pattern.
- **`EnactmentService::amendLaw`** — unchanged; the F-JDG-006 law-remedy (sibling design) is the only caller that passes `source='judicial_remedy'` to pierce the shield. THIS area's opinions never call it (commentary ≠ edit).
- **`PublicRecordService`** — unchanged; new `kind='opinion'` (already in the CHECK) for opinion publication; `kind='other'`/`'certification'` for verdicts/sentences/warrants (no new enum value needed — opinions are the only judiciary-specific kind, already reserved).
- **`ConstitutionalValidator` (PROTECTED)** — gains the double-jeopardy rule + the panel-sizing invariants (D.2). Constitutional-review path.
- **`RoleService`** — gains R-19/R-20/R-21/R-22 derivations (D.3).
- **`ClockService`** — gains no new clock for cases. CLK-16 (severity→panel sizing / en-banc trigger) is a **validator/pure-function gate, not a timer** — exactly the CLK-19 precedent ("deliberately has NO timer — it is a validator gate"). CLK-11/CLK-12 (the per-case finding windows written into `ClockTimer.override_value`) belong to the Art. IV §5 sibling design, not cases. CLK-04 (special-election fallback for a depleted elected-judge race) is the formation design's. **No new clock-handler registrations in this area.**

---

## D) EXIT-CRITERION / WORKFLOW CHAINS

### D.1 The panel-sizing pure function (`App\Services\Judiciary\PanelSizing`)

The constitutional core (Art. IV §4): "at least three (3), Odd in number, and scale with the severity… Constitutional Questions of significant importance are heard by the entire court."

```php
/**
 * @return array{size:int, en_banc:bool} — odd, ≥3, severity-scaled;
 *   en_banc ⇒ the entire seated court.
 */
public static function sizeFor(string $severity, int $seatedJudges): array
{
    if ($severity === 'constitutional_major') {
        // The ENTIRE court hears major constitutional questions (CLK-16).
        // Forced odd: if the court has an even seated count, the lowest
        // draw is dropped so the en-banc bench stays odd (no ties).
        $size = $seatedJudges % 2 === 1 ? $seatedJudges : $seatedJudges - 1;
        return ['size' => max(3, $size), 'en_banc' => true];
    }

    // Severity ladder, clamped to the seated pool and forced odd:
    //   minor → 3, moderate → 3, serious → 5 (a court with ≥5 judges).
    $target = match ($severity) {
        'serious'  => 5,
        default    => 3,   // minor, moderate
    };

    // Never exceed the seated pool; never below 3; always odd.
    $size = min($target, $seatedJudges);
    if ($size % 2 === 0) { $size -= 1; }       // force odd downward
    return ['size' => max(3, $size), 'en_banc' => false];
}
```

Properties pinned by `PanelSizingTest`: every output is odd and ≥3; `constitutional_major` ⇒ `en_banc=true` and `size` = the whole (forced-odd) court; severity is monotonic non-decreasing in panel size; the function is total and DB-free (severity + seated count are the only inputs). The `panels.size` CHECK (`>= 3 AND % 2 = 1`) is the DB belt behind the function; the `judiciaries.min_judges >= 5` CHECK guarantees a `serious` case can always seat 5.

**Why minor=moderate=3, serious=5 (not a finer ladder):** the constitution mandates only the floor (3), oddness, and monotonic scaling with severity — it does not enumerate sizes. This is the minimal lawful ladder; a jurisdiction wanting more granularity sets it via an amendable rule in a later phase. Flagged for the q-ledger: "severity→size ladder is implementation-chosen within the constitutional constraints (≥3, odd, monotonic, en-banc for major)."

### D.2 Double jeopardy — the hardened validator rule (Art. II §8, constitution line ~143)

"Any Individual who has been prosecuted for a criminal act cannot be prosecuted for that same act again. All other Judgements can be overturned only by proven contradictions in law and errors found in the cases."

Added to `ConstitutionalValidator` (PROTECTED), invoked by the engine before `CaseFiling`/`AdvocateCaseFiling` handlers run:

```php
// double_jeopardy (HARDENED — Art. II §8): a criminal case may not be
// re-filed against the same accused for the same act. Pure assert pinned
// by DoubleJeopardyTest; the engine records the rejected=true chain row.
public static function assertNoDoubleJeopardy(
    bool $isCriminal,
    bool $priorTerminalCriminalVerdictExists,
): void {
    if ($isCriminal && $priorTerminalCriminalVerdictExists) {
        throw new ConstitutionalViolation(
            'This accused has already been prosecuted to a final verdict for this act — '
            . 'a criminal act cannot be prosecuted again.',
            'Art. II §8'
        );
    }
}
```

The filing gate (`checkCaseFiling`, the F-LEG-034-style DB-read-in-validator posture) resolves whether a `cases` row exists with the same `kind='criminal'` + same `accused` party + same act reference that reached a terminal verdict (`double_jeopardy_locked=true`), and delegates to the pure assert. Two-layer hardening: (1) this pre-commit validator rule rejects the re-filing with citation and records the `rejected=true` chain row; (2) `cases.double_jeopardy_locked` is the persisted fact, set atomically with the criminal verdict. "All other judgements overturned only by proven contradictions/errors" is realized as: non-criminal verdicts CAN be vacated, but only through the appeal path (`appealed` state → a new case citing `appeal_of_case_id` with a stated error/contradiction) — there is no API to silently mutate a recorded verdict (the verdicts row is the operative one; corrections append). The same-act matching is structural in E (same accused + same case-reference); semantic "same act" adjudication is judicial territory, flagged as a deferral.

### D.3 Role derivations (`RoleService` — derived, never stored)

| Role | Derivation (authoritative seat/row) | Coordination |
|---|---|---|
| R-19 Judge (appointed) | a SEATED `judicial_seats` row on an `appointed` judiciary with an active `office_kind='judicial_seat'` term | may co-land with the formation design — owned by whichever lands first; this area needs it to gate F-JDG-* |
| R-20 Judge (elected) | a SEATED `judicial_seats` row on an `elected` judiciary, lockstep term | same |
| R-21 Advocate | an `advocates` row `status='registered'` (this area's table) | owned here |
| R-22 Juror | a `jury_members` row in `screening_status IN ('summoned','screening','cleared','empaneled')` on a non-discharged jury | owned here |

R-19/R-20 gate the F-JDG-* handlers (the form catalog lists both for every F-JDG form — either kind of judge may act). R-21 gates F-ADV-* and the advocate path of F-IND-017. R-22 is read-only (the juror-view surface) — jurors file nothing; their only action (conflict screening answers) is a private submission to the panel, not a constitutional form. **Decision:** to avoid `derive()` signature churn, judge/advocate/juror derivations are appended to the `RoleService::derive()` pure function as new boolean inputs in the established pattern (R-19..R-22 after R-30), and the constitutional `RightsAutomaticTest` pin grows to cover them.

### D.4 Exit-criterion workflow chains

**1. File → accept → panel (odd, severity-scaled) → hear → verdict → sentence/close (the WF-JUD-03 spine).**
Chain: `case-docket` filing form → `POST` F-IND-017 (or F-ADV-001) → `CaseService::open` (`filed`) → R-19/R-20 files F-JDG-001 → `CaseService::accept` fixes `court_severity` + `PanelSizing::sizeFor` computes `{size:3, en_banc:false}` → `PanelService` draws + screens + seats `panels`/`panel_judges` (`paneled`) → F-JDG-002 empanels the jury (`jury_empaneled`) → hearing filings (F-ADV-002/003/004) docket within their attach-windows → `deliberation` → `CaseService::recordVerdict` (criminal ⇒ `double_jeopardy_locked=true`, verdict `double_jeopardy_flag=true`) → F-JDG-009 sentence (`sentenced`) → F-JDG-003 opinion + `opinion_law_links` (`closed`). Every stage publishes a `public_records` row; the panel draw seed and jury draw seed are audit-chain-sealed. **Acceptance check:** a `serious` criminal case seats exactly 3 (or 5 on a 5+-judge court) odd judges, with the panel size asserted equal to `PanelSizing::sizeFor` output; a `constitutional_major` case seats the whole court en banc.

**2. Double jeopardy bars re-prosecution (the hardened exit).**
Chain: a criminal case reaches a terminal verdict → `double_jeopardy_locked=true`. A second F-IND-017 against the same accused for the same act → the engine runs `assertNoDoubleJeopardy` BEFORE the handler → `ConstitutionalViolation` (Art. II §8) → the `rejected=true` chain row is appended with citation → 422 with the verbatim citation; no second `cases` row is created. **Acceptance check:** the re-filing is rejected with the Art. II §8 citation and the rejection is on the audit chain; the first verdict is untouched. The contrast assertion: a *civil* re-filing on the same facts is NOT barred (double jeopardy is criminal-only — `assertNoDoubleJeopardy(false, …)` passes).

**3. Jury of peers — random, verifiable draw (the Art. IV §4 jury exit).**
Chain: F-JDG-002 → `JuryService` draws `seats + alternates` jurors at random from the eligible jurisdictionally-associated pool (`residency_confirmations` in the eligible jurisdiction) → the draw seed is published to the audit chain → summoned jurors see their summons on `juror-view` (R-22) → voir-dire screening removes conflicts only (never opinion/demographics/politics) → empanelment. **Acceptance check:** the published seed reproduces the exact draw (anyone can verify); an excused juror triggers a replacement draw advancing the seed; no fee field exists anywhere on the jury path (the no-fee shield is structural).

**4. Advocate registration + zealous representation (the R-21 exit).**
Chain: `advocate-console` → F-IND-015 → `advocates` row → R-21 derived → the advocate files F-ADV-001 (new case for a client, retainer recorded) / F-ADV-002/003/004 onto active cases → every filing lands on the public docket (`case_filings` + `public_records`). **Acceptance check:** an R-03 registers and gains R-21; a non-advocate is rejected from F-ADV-* with a "registration required" engine message (never a 403 — the page explains, the engine gates); a brief filed after `deliberation` is rejected by the attach-window gate.

---

## E) WHAT I REUSE vs BUILD NEW

### Reuse (proven Phase A–D substrate)

- **`ConstitutionalEngine::file()` + `FormRegistry` + the hash-chained `audit_log`** — every judiciary form rides the same dispatch; double jeopardy + panel invariants are validator rules in the same pre-handler stage as the executive-order scope rules.
- **`judiciaries` / `judicial_seats` tables + models** (already migrated) — cases reference them; this area does not redefine them.
- **`terms` + `CivilAppointmentService` + CLK-09** — appointed judges open 10-yr `office_kind='judicial_seat'` terms exactly like board governors (the formation design's call site).
- **The elections machinery** (`elections.kind='judicial'`, `judicial_election` vote type, `election_races`, `VoteCountingService::countStv`, `CertificationService` race dispatch) — elected judges are an STV race feeding `judicial_seats`, the board-election pattern verbatim. THIS area consumes a seated pool; it does not run the race.
- **`EnactmentService::amendLaw` + `LawVersion::SOURCE_JUDICIAL_REMEDY`** — the law-edit hook is reserved and consumed ONLY by the F-JDG-006 sibling; opinions here are commentary (`opinion_law_links`), never edits.
- **`public_records` (kind `opinion` already in the CHECK) + `PublicRecordService`** — verdicts, sentences, warrants, opinions, every docket filing publish through it; the immutability triggers give the append-only docket posture for free.
- **`pg_advisory_xact_lock` docket-number allocation** — the `EnactmentService::allocateActNumber` pattern, reused for `case-YYYY-NNN`.
- **`residency_confirmations`** — the R-03/R-04 substrate IS the eligible jury pool (jurisdictionally-associated residents).
- **`ConstitutionalValidator::EMERGENCY_PROTECTED_FORMS`** — already shields F-JDG-001..010 + F-IND-016/017; no change needed (courts immune to emergency powers from day one, Art. II §7 / Art. IV §5 judicial review of emergency powers).
- **`RoleService::derive()` pure-function pattern** — R-19..R-22 appended in the established style.
- **`RemovalProceeding::KIND_JUDGE_REMOVAL`** — judge removal-by-supermajority reuses the existing oversight machinery (the formation design's concern; noted because a recused/removed judge changes the seated pool a panel draws from).
- **`config/cga/state_machines.php`** — gains a `case` ESM entry (the `StateStrip` consumer), the executive_office/department_board pattern.

### Build new

- The eight adjudication tables (E-1..E-8) + their Eloquent models.
- `PanelSizing` (pure), `CaseService`, `PanelService`, `JuryService`, `CaseFilingService`, `AdvocateService`.
- Nine handlers (B).
- Two validator rules: double jeopardy + panel-size invariants (D.2/D.1).
- R-19..R-22 role derivations (coordinated with the formation design for R-19/R-20).
- The `case` ESM config + `CaseLifecycleTest`, `PanelSizingTest`, `DoubleJeopardyTest`, `JuryDrawTest`, `WarrantRequirementsTest`, `AdvocateRegistrationTest` constitutional specs.

### Work-item breakdown (cases scope; sizes S/M/L)

| WI | Size | Content | Deps / parallel-with |
|---|---|---|---|
| **WI-EC1** Migrations E-1..E-8 + models | M | all eight tables, one PR; CHECKs (panel odd≥3, warrant NOT-NULL reason/duration, docket append-only triggers) | needs the existing `judiciaries`/`judicial_seats` (live) + the formation design's seated-pool seed for E2E |
| **WI-EC2** `PanelSizing` (pure) + `PanelService` + draw/screening/recusal + en-banc | M | the constitutional core; `PanelSizingTest` first | WI-EC1 |
| **WI-EC3** `CaseService` (ESM) + F-IND-017/F-ADV-001 handlers + `case_parties` + double-jeopardy validator rule | L | the spine; touches PROTECTED `ConstitutionalValidator` (constitutional review) | WI-EC1; parallel WI-EC2 |
| **WI-EC4** `JuryService` + F-JDG-002 + `jury_members` + R-22 + the verifiable draw seed | M | the jury-of-peers exit | WI-EC1, WI-EC3 (case must exist) |
| **WI-EC5** `CaseFilingService` (append-only docket) + F-ADV-002/003/004 + attach-window gates + judge rulings | M | the advocate filing path | WI-EC3 |
| **WI-EC6** Verdict/sentence/warrant: `CaseService::recordVerdict` + F-JDG-009 + F-JDG-010 + the warrant-requirements rule | M | guilty-verdict gate + Art. II §8 warrant facts | WI-EC3 |
| **WI-EC7** `opinions` + `opinion_law_links` + F-JDG-003 + the commentary-not-edit boundary | S | opinion publication | WI-EC3, WI-EC6 |
| **WI-EC8** `AdvocateService` + F-IND-015 + R-21 | S | the bar | WI-EC1; fully parallel |
| **WI-EC9** F-JDG-001 acceptance handler (ties EC2's panel + EC3's case) + the `case` ESM config | M | the accept→panel join | WI-EC2, WI-EC3 |
| **WI-ECT** Constitutional tests (woven; CI gate) | M | `PanelSizingTest`, `CaseLifecycleTest`, `DoubleJeopardyTest`, `JuryDrawTest`, `WarrantRequirementsTest`, `AdvocateRegistrationTest` | per-WI |

Critical path: EC1 → EC2/EC3 (parallel) → EC9 (accept+panel) → EC4 (jury) → EC5/EC6 → EC7. EC8 fully parallel. Exit-criterion map: WF-JUD-03 spine = EC1+EC2+EC3+EC9+EC6; double jeopardy = EC3; jury draw = EC4; advocate = EC8+EC5.

---

## F) EXPLICIT DEFERRALS (flagged, justified)

1. **Art. IV §5 finding → remedy → law-edit (F-JDG-004/005/006/007/008, F-LEG-035, CLK-11/CLK-12, the constitutional-challenge tracker)** — a SIBLING Phase E design, not this area. `cases.kind='constitutional'` rows are created here (coordinated with F-IND-016) but the finding/override/law-edit machinery and the per-case `ClockTimer.override_value` windows belong there. The `EnactmentService` judicial-remedy hook is reserved, not consumed here.
2. **Judiciary creation / conversion / seating (F-LEG-017/018/021, judge elections, judge removal, `JudicialActService`)** — the OTHER sibling Phase E design. This area assumes a seated `judicial_seats` pool and the R-19/R-20 derivations (which may land there first). The `judiciaries`/`judicial_seats` tables already exist; cases build on top.
3. **Appeals** — the `appealed` ESM state + an `appeal_of_case_id` self-reference land now (state-machine retrofit is expensive), but the appellate re-draw-at-wider-panel mechanics are a thin follow-up: an appeal opens a new `cases` row at the next severity tier; no separate appellate-court substrate in E. The mockup itself marks `[Appealed]` as an optional, illustrative pass.
4. **Semantic "same act" matching for double jeopardy** — E matches structurally (same accused + same case/act reference reaching a terminal criminal verdict). Whether two differently-described accusations are "the same act" is itself a judicial question; the engine enforces the structural floor, the court adjudicates the semantic edge. Flagged for the q-ledger.
5. **The severity→panel-size ladder (minor=moderate=3, serious=5)** — implementation-chosen within the constitutional constraints (≥3, odd, monotonic, en-banc for major); a finer or jurisdiction-amendable ladder is post-E. Pinned for the q-ledger so the choice is visible.
6. **Jury-room and chambers as access-controlled spaces** — modeled as ESM states (`deliberation`) and the "only unrecorded space" gloss; a real access-control / private-channel substrate has no foundation until Phase F federation. The verdict (the recorded output) IS built; the deliberation privacy is a posture, not a mechanism, in E.
7. **Advocate competence qualifications** — the `qualifications_note` is declarative; a jurisdiction's competence/bar-exam pipeline is its own law (judiciary-formation territory), not a constitutional eligibility gate. The right to representation is satisfied by the bar existing.
8. **Warrant execution / enforcement** — `warrants` records the issuance, stated reason, and max hold duration (the constitutional facts). Actual arrest/search execution is an executive (Justice Department) action — `warrants.status='executed'` is a status field updated by the enforcing body, but the enforcement substrate (linking to executive actions) is a thin Phase F federation/enforcement concern; E builds the issuance + the constitutional-requirement gate.
9. **`opinion_law_links` cross-instance law references** — links target local `laws` only; Full Faith & Credit recognition of another jurisdiction's judicial proceedings (Art. V §2, line ~265) is Phase F federation.
