All inputs read (MASTER_PLAN, DESIGN_schema_engine §A.3, all five EXPLORE docs, the live Phase A/B code: engine, validator, FormRegistry, RoleService, ClockService, VacancyService, CertificationService, legislature/member models + migrations, ResolvesBoardActor, election_boards migration). Design follows.

---

# PHASE C DESIGN — CHAMBER OPERATIONS: Sessions · Speaker · Committees · Admin/Oversight · Board Transition · Vacancy Loop

Scope boundary: this section owns the chamber's *operating machinery*. The sibling votes-laws design owns `chamber_votes`/`vote_casts`, `bills`/`laws`, referendums, petitions, emergency powers. Every interface with that scope is flagged **[IFACE]**. Verified substrate facts this design builds on: `legislatures` already carries `speaker_id`, `quorum_required`, `last_met_on`, `next_meeting_due_by`, `term_number`; `legislature_members` already carries `is_speaker`, `vote_share_norm (numeric 8,4)`, `seat_type char(1) a/b`, `seat_no`, `home_jurisdiction_id`, partial unique `legislature_members_one_current`; **`public_records` does NOT exist yet** (the Phase A skeleton was planned but never migrated — verified by repo grep); `ClockService::HANDLERS` covers CLK-01/04/05/06/18 only — CLK-02 is unwired; `Appointment`/`Term`/`ElectionBoard` machinery is live with `election_boards` partial unique `(jurisdiction_id) WHERE status='active'`.

---

## A) SESSION LIFECYCLE

### A.1 `public_records` first (prerequisite, not optional)

Statements, minutes, testimony, compulsion orders, and CLK-02 violations all land here; nothing in this section works without it. Migration `2026_06_15_000001_create_public_records.php` — exactly the DESIGN_schema_engine §A.1 A-5 spec:

```
seq bigint generated always as identity PK · id uuid unique default gen_random_uuid()
kind varchar(24) CHECK in (registration, residency, participation, statement, vote, act,
     opinion, certification, testimony, minutes, violation, correction, other)
title varchar · body text null · actor_user_id uuid null (NO FK — immutability over cascade)
actor_display varchar null (snapshot) · jurisdiction_id uuid null
via_form varchar(16) null · via_workflow varchar(16) null · via_clock varchar(8) null
subject_type varchar null + subject_id uuid null · audit_seq bigint null
translations jsonb default '{}' · supersedes_record_id uuid null (corrections append, never edit)
published_at timestamptz · created_at
```
Append-only Postgres trigger (same pattern as `audit_log`). New `app/Services/RecordService.php`: `publish(kind, title, body, actor, subject, via…)` — inserts row, then seals it by appending an `records/published` audit entry and back-filling `audit_seq` in the same transaction. API-level guarantee (enforced in RecordService, pinned by test): **never ballot content, never raw locations** (WF-SYS-03). Read surface: `Pages/System/PublicRecords.vue` + `System\PublicRecordsController` per the public-records contract (filterable register, `{seq, kind, title, actor, via, date, tr}` rows; translation pipeline = `translations` jsonb with machine-now/human-later badges — machine translation itself **deferred to the i18n pass (Phase F)**, the column and badge render honestly as "original only").

### A.2 `legislature_sessions` — migration `2026_06_15_000002_create_sessions_tables.php`

```
legislature_sessions
  id uuid PK · legislature_id FK legislatures · session_no int (unique per legislature)
  kind varchar(12) CHECK in (first, regular, special) default 'regular'
  called_by uuid null (user; NULL = system: first session, CLK-02 auto-schedule)
  called_via varchar(16) ('F-SPK-001' | 'system')
  scheduled_for timestamptz NOT NULL · opened_at / adjourned_at timestamptz null
  serving_at_open smallint null            -- snapshot of ALL current members at open
  serving_by_kind jsonb null               -- {"type_a": n, "type_b": n} when bicameral
  quorum_required smallint null            -- ConstitutionalValidator::quorum(serving)
  quorum_required_by_kind jsonb null       -- per-kind peg quorum (bicameral; see A.4)
  present_at_call smallint null · quorum_met boolean null        (F-SPK-003)
  agenda jsonb NOT NULL default '[]'       -- ordered items, see A.3
  minutes_record_id uuid null → public_records.id                (F-SPK-009)
  status varchar(16) CHECK in (scheduled, quorum_call, in_session, adjourned,
                               failed_quorum, cancelled)
  created_at / updated_at                  -- NO deleted_at: sessions are records
                                           -- (documented soft-delete exception)
```

**ESM (refines DESIGN A.3's 4-state cut):**
`scheduled → quorum_call → in_session → adjourned` · `quorum_call → failed_quorum` (after the WF-LEG-20 compulsion branch fails) · `scheduled → cancelled` (only while no member has registered attendance; audited).

```
session_attendance
  id uuid PK · session_id FK cascade · member_id FK legislature_members
  status varchar(12) CHECK in (present, absent, compelled, excused)
  recorded_via varchar(16) ('F-LEG-002' | 'F-SPK-008' | 'system')
  recorded_at timestamptz · UNIQUE (session_id, member_id)
```
At `quorum_call`, the service materializes one row per current member (status `absent`); F-LEG-002 flips own row to `present`; F-SPK-008 marks `compelled`. **Hardened framing (engine + test-pinned): attendance feeds the quorum CALL and the public record only. It is NEVER a vote denominator** — every `chamber_votes.serving_snapshot` **[IFACE]** is taken from `legislature_members` current rows, not from attendance. An absent member counts the same as a no.

```
motions  (ESM-08)
  id uuid PK · session_id FK · moved_by_member_id FK
  kind varchar(20) CHECK in (procedural, bill_reading, referral, amendment, adjourn,
                             replace_speaker, designate_presider, other)
  text text · status CHECK in (submitted, recognized, debated, voted, adopted, failed, withdrawn)
  vote_id uuid null → chamber_votes [IFACE] · timestamps
```
Speaker recognizes (`recognized`), opens debate, closes to vote (creates the yes/no chamber_vote, majority class). Two engine specials: `replace_speaker` is **auto-recognized** — the chair cannot block its own replacement (Art. II §3 "replaceable by supermajority anytime"; the supermajority lives in the resulting ballot, not the motion); `adjourn` adopted → session adjourns through F-SPK-009.

### A.3 Agenda — Art. II §2 order as data

`agenda` jsonb = ordered list of `{position, kind, title, ref_type, ref_id, locked, added_via, addressed_at}`. Item kinds: `emergency_review`, `constitutional_matter`, `speaker_ballot`, `committee_report`, `member_priority`, `motion`, `bill_reading`, `statement_window`, `general`.

On session open, `SessionService` **composes the locked head automatically**:
1. one `emergency_review` item per `emergency_powers` row active in the legislature's footprint **[IFACE — emergency table is sibling scope; it exposes `activeInFootprint(jurisdictionId)`]** — locked, position 1..n;
2. one `constitutional_matter` item per open Art. IV §5 window — **Phase C renders this slot honestly empty** (challenges are Phase E; the slot exists, the feed does not — deferral justified: no judiciary is seated);
3. then the Speaker-ordered general tail (F-SPK-002).

Hardened rule (new validator rule `session.agenda_order`, constitutional review required since `ConstitutionalValidator` is PROTECTED): F-SPK-002 filings may only reorder/insert items after the locked head; items are addressed strictly in position order (`addressed_at` set sequentially; the console disables item N+1 until N is addressed). Quorum count precedes everything — it is the `quorum_call` state itself, not an agenda item.

### A.4 Quorum call — peg semantics

`F-SPK-003` snapshots: `serving_at_open` = COUNT of current members (vacancies already absent from the count but **the chamber's seat total is irrelevant — serving is the denominator everywhere**); `quorum_required = ConstitutionalValidator::quorum(serving)`. **Bicameral (q-ledger #q7 extended, cite "Art. V §3 · as implemented"): each kind must independently meet its own peg quorum** — `quorum_required_by_kind = {type_a: quorum(serving_a), type_b: quorum(serving_b)}`; a session where type_b is absent below its own quorum has not met quorum, because no bicameral act could validly pass in it. Quorum met → `in_session`. Not met → WF-LEG-20: F-SPK-008 compulsion order (public_records `violation`-adjacent entry kind `other`, attendance rows `compelled`) → re-count → met = resume; not met = `failed_quorum` + auto-reschedule a new session inside the CLK-02 window + (repeated failure, 2+ consecutive) auto-intake at the admin office (D.3).

### A.5 CLK-02 — 90-day enforcement

- `ClockService::HANDLERS += 'CLK-02' => App\Jobs\Clocks\MeetingDeadlineJob::class`.
- **Arm points:** (1) `CertificationService` (general certification) arms the first timer: `fires_at = certified_at + resolved max_days_between_meetings` — a freshly seated chamber must meet within 90 days; (2) `SessionService::open()` — when a session reaches `in_session` with quorum met, cancel the armed timer and re-arm from `opened_at`. **Design divergence from DESIGN A.3 ("adjournment resets"), justified:** Art. II §2 requires the chamber to *meet*; the meeting constitutionally occurs at quorum-verified opening. A `failed_quorum` session never resets (WF-LEG-20: "90-day clock still enforced"). `legislatures.last_met_on` / `next_meeting_due_by` maintained in the same write (the chamber-home "due in 12 days" banner reads these — no timer needed for the warning).
- **Breach posture (the honest answer to "what happens constitutionally"):** the Template provides no dissolution or forfeiture remedy, and the system cannot teleport humans into a room. `MeetingDeadlineJob` on fire (idempotent — verifies no quorum-met session since arm):
  1. audit entry + `public_records` row kind `violation`, citing Art. II §2 (WF-SYS-02);
  2. admin-office referral: if an `admin_offices` row exists → auto-intake `misconduct_investigations` row (subject = the legislature, complainant NULL = system); if not, the violation record stands alone and chamber-home surfaces it;
  3. **system-files F-SPK-001** (actor null) creating a session `scheduled_for = now + config('cga.session_notice_days', 7)` — mirrors the CLK-04 posture: *discretion can never produce "no session"*; humans must still attend, and non-attendance then flows into the quorum-failure/compulsion machinery;
  4. re-arms CLK-02 from the breach — a chamber that stays dark chains one violation per 90 days, forever, on the public record. Ultimate remedies (Art. IV §5 challenge, Art. VI restoration) are Phase E/F and honestly out of scope.

### A.6 Session scheduling & forms

`F-SPK-001` (R-10; system bypass for first session + CLK-02): creates `scheduled` session. **First session:** created by `CertificationService` at general certification (kind `first`, system-called, `scheduled_for` = certification + 7 days default). Until a Speaker exists there is no R-10; first sessions are engine-administered: the only permitted business is oath filing (F-LEG-001) and speaker balloting (B.1) — matching dependency-chain order 3.1 → 3.2; the quorum call runs system-side. `F-SPK-009` (R-10 or R-29): publishes minutes → `public_records` kind `minutes`, sets `minutes_record_id`, transitions `adjourned`. Statements: `F-LEG-006` (R-09) → `public_records` kind `statement` (attachable to session/bill/vote via `subject_*`). Oath `F-LEG-001` (R-09): flips own member row `elected → seated`, stamps `seated_at` — closing the gap the Phase B migration documented.

Orchestrator: `app/Services/Legislature/SessionService.php` (open/quorum/compel/adjourn/agenda composition); every transition flows through `ConstitutionalEngine::file()`.

Frontend: `Pages/Legislature/SessionConsole.vue` per the session-console contract (attendance grid, quorum meter, locked agenda head, motions list with tally chips + "Speaker broke the tie" annotation, statement composer, session-due banner) + `Pages/Legislature/Chamber.vue` (legislature-home: seat map, roster, quorum/supermajority stats from serving, FIRST_SESSIONS checklist driven by real rows: speaker_id, rules law, ethics law, admin office, proper board, committees).

---

## B) SPEAKER

### B.1 Election — supermajority RCV (the honest mechanic)

The PROTECTED `VoteCountingService` is **not touched**: its `countRcv` wins at majority-of-continuing over secret ballots — the wrong win condition and the wrong ballot model. Chamber ballots are public, tiny (5–41 casts), and peg-denominated. New non-protected unit:

**`app/Services/Legislature/ChamberRcvService.php`** — IRV over public `vote_casts.rankings` **[IFACE: chamber_votes vote_method='rcv']** with a parameterized win condition:
- `WIN_MAJORITY_CONTINUING` (chair elections, F-LEG-011);
- `WIN_SUPERMAJORITY_SERVING` (speaker, F-LEG-008): a candidate is elected only when their round tally ≥ `ConstitutionalValidator::supermajority(serving)` where `serving` = ALL current members — **non-casters and exhausted ballots stay in the denominator** (peg).
- Rounds: tally first preferences among continuing candidates → leader meets threshold → elected. Else eliminate lowest; elimination ties broken backward (prior-round tallies), then fewest first-preferences, then a deterministic seeded lot — seed = the opening audit entry's hash, published (every step lands in the vote's audit payload).
- **Exhaustion → failure:** when one candidate remains (or all remaining are tied) and the threshold was never met, the balloting closes with outcome `failed` / reason `no_supermajority`.

**Repeat-balloting posture (WF-LEG-02: "no supermajority → re-ballot per rules"; the flow's own terminal):** the engine never auto-loops. Each F-LEG-008 balloting is one discrete chamber_vote; on failure the console surfaces the full round record + "Open new ballot." Nominees may change between ballotings. **First election:** until a Speaker is seated, `legislatures.speaker_id` is NULL and the engine permits only oath + speaker-ballot agenda kinds — the chamber structurally cannot conduct business it has no neutral chair for (dependency chain 3.1→3.2; this is the honest deadlock: the constitution offers no fallback chair, so the chamber ballots until it produces one). **Replacement:** a failed re-election ballot leaves the incumbent seated ("serves until next legislature unless replaced").

Filing model: ballot opened by system (first session) or by an adopted `replace_speaker` motion; each member's F-LEG-008 filing appends their rankings as a `vote_cast`; close (all serving cast, or presiding close after a published deadline) triggers the tally. Candidates = serving members (any member is nominable; politically-neutral is a duty of the office, not an eligibility test — nothing structural can verify neutrality and the engine must not pretend otherwise).

Seating: winner → `legislatures.speaker_id = member.id`, `legislature_members.is_speaker = true` (authoritative fact = `legislatures.speaker_id`; `is_speaker` maintained as the denormalized convenience both already in schema), prior speaker's flag cleared, `public_records` kind `certification` entry. R-10 derives (G).

### B.2 Neutral posture — engine rule (not a denominator change)

Re-read confirmed (Art. II §3): the Speaker **remains a serving member** — they stay in every quorum and threshold denominator. New PROTECTED-validator rule `speaker.tiebreak_only`:
- On `chamber_votes` with `vote_method='yes_no'`: a cast by the Speaker's member row is **rejected pre-commit** (ConstitutionalViolation, Art. II §3) — and the rejection chains, like all rejections — **unless** the vote is in tie state: every other serving member's cast is resolved (cast, or the vote is closing) AND yes == no. Then the Speaker's cast records via **F-SPK-004**, flagged `speaker_tiebreak=true` on the vote **[IFACE: chamber_votes.speaker_tiebreak boolean — sibling adds the column]**. The outcome is then recomputed against the unchanged peg threshold — no special outcome math: at serving 8/majority 5, a 4–4 + speaker-yes adopts 5–4 (the mockup's exact case); at serving 8/supermajority 6, 4–4 + 1 = 5 still fails — the tie-break never manufactures a supermajority, and the engine doesn't pretend it can.
- On `vote_method='rcv'` (speaker/chair elections): **all serving members cast, Speaker included.** Justification: these are constitutive elections of the body, not deliberative business; excluding the Speaker from their own replacement ballot would warp the supermajority-of-serving denominator (6-of-9 needed with only 8 able to cast). Cite "Art. II §3 · as implemented" — flagged as a q-ledger candidate.
- Committee scope: the Speaker receives committee placements and votes in committee (F-LEG-005) — the mockup arithmetic (9 placements = 9 members incl. speaker) requires it; the no-cast rule is floor-scoped. Same `as implemented` flag.

### B.3 Replacement, tie-break record, removal adjudication, tools

- **Replacement (F-LEG-008 re-run):** `replace_speaker` motion (auto-recognized, B/A.2) → fresh supermajority-RCV balloting; incumbent is candidate-eligible and casts.
- **Removal adjudication (F-SPK-007):** the Speaker presides over every removal proceeding **except their own** — engine rule `removal.presider` (validator): `removal_proceedings.presided_by_member_id != subject member`. Speaker's own case: presider designated by `designate_presider` motion (ordinary majority); the console pre-suggests the most senior member (max `daysServed`, tie `vote_share_norm` — deterministic), but the chamber chooses. Cite "Art. II §3 · as implemented" (the Template names no substitute presider).
- **Speaker-tools surface:** `Pages/Legislature/SpeakerTools.vue` per contract — all 9 F-SPK form cards from `FormRegistry::meta()`, tie-break record (`chamber_votes WHERE speaker_tiebreak`), member-priorities queue (F-SPK-006: R-10 files `{member_id, text}` → `member_priority` agenda items + a priorities log on the session row), assignment admin (F-SPK-005 run + snapshot viewer), removal-proceedings list (except own — the page hides presiding controls on the speaker's own case).

---

## C) COMMITTEES

### C.1 Schema — migration `2026_06_15_000003_create_committees_tables.php`

```
committees (I-COM)
  id uuid PK · legislature_id FK · name · purpose text
  seats smallint CHECK (seats >= 1)
  type_a_seats / type_b_seats smallint null      -- bicameral kind split; NULL = unicameral
       CHECK (type_a_seats IS NULL OR type_a_seats + type_b_seats = seats)
  created_by_vote_id uuid null → chamber_votes [IFACE]
  created_by_law_id uuid null → laws [IFACE — backfilled when the act enrolls]
  chair_member_id / alternate_member_id uuid null FK legislature_members
  status CHECK in (created, seated, dissolved) · timestamps + deleted_at

committee_seats (ESM-09)
  id · committee_id FK · member_id FK · seat_kind varchar(8) null (type_a|type_b)
  status CHECK in (allocated, assigned, tie_broken, seated, vacated)
  assigned_via CHECK in (algorithm, tie_break, whole_house_rcv)
  preference_rank_honored smallint null · seated_at / vacated_at · vacated_reason varchar(24) null
  PARTIAL UNIQUE (committee_id, member_id) WHERE vacated_at IS NULL · timestamps

committee_preferences
  id · legislature_id · member_id · rankings jsonb (ordered committee ids, F-LEG-010)
  submitted_at · UNIQUE (legislature_id, member_id) · timestamps

committee_meetings
  id · committee_id FK · called_by_member_id (R-12/R-13, F-CHR-001)
  scheduled_for · agenda jsonb (F-CHR-002) · opened_at / adjourned_at
  status CHECK in (scheduled, open, adjourned) · minutes_record_id uuid null · timestamps

committee_reports
  id · committee_id FK · bill_id uuid null [IFACE] · filed_by_member_id
  report_record_id uuid → public_records (F-CHR-004) · created_at
```

### C.2 Creation (F-LEG-009) + the allocation formula

F-LEG-009 (R-09 files; **supermajority** chamber vote, `votable_type='committee_creation'`) — payload `{name, purpose, seats}`. On adoption: committee row `created`; bicameral kind split computed by the service — **committees mirror the chamber-kind ratio (Art. V §3)**: largest-remainder apportionment of `seats` over serving `type_a : type_b`, with each kind ≥ 1 whenever `seats ≥ 2` (engine-validated; a committee containing one kind makes per-kind dual agreement vacuous — q7 at committee stage). San Marino (32a:9b), a 5-seat committee → 4a + 1b.

**The formula, as the EXPLORE docs actually state it** (WF-LEG-03 / committees.html): `per-member share = Total Reps ÷ (Committees × seats per committee)`; *total committee seats across all committees = the number of placements to fill* (mockup: 9 ÷ (3×3) = 1 → exactly one placement each). **What it constrains:** placements are distributed *evenly across members* — placement counts may differ by at most 1. It does **not** require P (total seats) = M (serving members); the algorithm handles the general case via rounds (below). The pre-vote validator surfaces the computed share on the F-LEG-009 form (UI honesty) but does not hard-block non-integral shares.

### C.3 Preferences (F-LEG-010)

R-09 files ranked committee ids (keyboard-reorderable rank list, no drag — mockup). Re-submittable until an F-SPK-005 run consumes them; the run snapshots all inputs into its audit payload, so later edits affect only future runs. Non-submitters default to committee creation order (mockup rule).

### C.4 THE ASSIGNMENT ALGORITHM — `app/Services/Legislature/CommitteeAssignmentService.php` (F-SPK-005)

Deterministic, pure over a snapshot; pinned by `tests/Constitutional/CommitteeAssignmentTest`.

Inputs: committees in `created`/refill scope (seats by kind), serving members (kind, `vote_share_norm`, `seat_no`), preferences (defaults applied).

1. **Partition by seat kind** (unicameral = one partition over unsplit seats). All subsequent steps run per partition.
2. **Per-member placement budget:** `P` = Σ kind-seats, `M` = members of the kind. Everyone gets `floor(P/M)`; the `P mod M` extra placements go to the members with the **highest `vote_share_norm`** (the same tie-break currency, q-ledger #q2; deterministic fallback `seat_no ASC`, then member uuid ASC). Mockup case P=M → exactly 1 each.
3. **Placement rounds** `r = 1..ceil(P/M)`: members still owed an r-th placement are processed by preference depth `d = 1, 2, …`: collect contenders whose current top *unfulfilled* preference is committee `c` with open kind-seats. If contenders ≤ open seats → all `assigned` (`assigned_via='algorithm'`, `preference_rank_honored=d`). If contested → **order by `vote_share_norm` DESC** (fallback as above); winners take the seats (`status='tie_broken'`, `assigned_via='tie_break'` when the comparison decided it); **losers' next preference is honored within the same pass** (mockup: Chen 1.08 beats Okonkwo 0.99 for the last Environment seat; Okonkwo's next preference honored).
4. A member never holds two seats on one committee (partial unique is the backstop).
5. **Exhaustion guard:** a member whose list runs out with seats still open is placed on the open committee with the most remaining kind-seats (deterministic; `preference_rank_honored = NULL`).
6. All seats filled → seats flip `seated`, committee → `seated`; the run's complete input/output — shares, every contested comparison, every honored rank — is the F-SPK-005 audit payload, and a `public_records` kind `certification` entry publishes the assignment.

### C.5 Chairs + alternates (F-LEG-011, F-CHR forms)

Per committee: whole-house RCV (`chamber_votes` `votable_type='committee_chair_election'`, `vote_method='rcv'`) — **all serving members cast** (incl. Speaker, B.2), candidates = that committee's seated members (R-12 requires R-11 — roles sheet acquisition). `ChamberRcvService` with `WIN_MAJORITY_CONTINUING`. Winner → `chair_member_id` (R-12); **alternate = top runner-up by sequential exclusion** (re-run without the winner — consistent with the Phase B `deriveAdvisors` doctrine) → `alternate_member_id` (R-13, acts when chair absent: F-CHR forms accept R-12 or R-13-when-chair-absent — handler checks chair's attendance row of the live meeting, else falls back to a simple `chair unavailable` attestation recorded in the filing).

F-CHR-001/002 → `committee_meetings` rows; testimony = `RecordService::publish(kind 'testimony', subject=meeting)` — open to any R-03 (witnesses), per the contract "testimony to public record"; F-CHR-004 → `committee_reports` + record entry. F-CHR-003 (refer to floor) is the sibling's bill transition; **[IFACE]** their handler enforces the gate "enabled only after the committee vote passes" — this design supplies the committee membership + chair facts it authorizes against.

### C.6 Vacancy / new-committee fills (WF-LEG-13) + recheck

- **Seat vacated** (chamber vacancy cascades — F.2; or committee resignation via motion): refill by **whole-house RCV** (`votable_type='committee_seat_fill'`); *proportion-safe eligibility*: candidates = members of the same seat kind whose current placement count is at the chamber minimum — prevents placement concentration, which is what "preserving proportion" can honestly mean with no faction layer (cite Art. II §4 · as implemented). Chair/alternate vacancies → F-LEG-011 re-run for that committee.
- **New committee mid-term:** F-LEG-009 as usual → fresh F-LEG-010 collection scoped to it → F-SPK-005 run scoped to it (budgets recomputed over the enlarged P).
- **Recheck:** after any chamber countback/special seating, `CommitteeAssignmentService::recheck(legislature)` recomputes kind ratios + placement evenness and surfaces drift on the committees page (the mockup's `committees[2].note`). **No auto-rebalancing** — seated members are not unseated by arithmetic; drift resolves only through vacancy events. Honest and stated on-surface.

Frontend: `Pages/Legislature/Committees.vue` (creation acts with tallies, preference rank list, tie-break table showing `vote_share_norm`), `Pages/Legislature/CommitteeDetail.vue` (roster with kind chips, meetings, testimony feed, report filing, committee-vote meter — vote itself **[IFACE]**).

---

## D) ADMIN OFFICE + RULES/ETHICS + OVERSIGHT

### D.1 Admin office (F-LEG-013) — migration `2026_06_15_000004_create_admin_oversight_tables.php`

```
admin_offices (I-ADM)
  id · legislature_id FK · created_by_vote_id uuid null [IFACE] · created_by_law_id uuid null [IFACE]
  status CHECK in (created, staffed, dissolved) · timestamps + deleted_at
  PARTIAL UNIQUE (legislature_id) WHERE status != 'dissolved' AND deleted_at IS NULL
```
F-LEG-013: **ordinary majority** (peg) — not in the supermajority list; owner ruling: unstated = majority of all serving. Staffing reuses the live `appointments` pipeline: `appointable_type='admin_offices'` → consent chamber_vote (majority) → `seated` + `terms` row (`term_class='civil_appointment'`, `civil_appointment_years`, CLK-09 armed). First seat flips office → `staffed`. R-29 derives (G).

### D.2 Rules of Order + Ethics Code (F-LEG-032/033) — DECISION: they are LAWS

They bind officeholders, are challengeable under Art. IV §5, and need versioning — exactly the `laws`/`law_versions` substrate; a parallel "documents" table would fork law versioning. But they are adopted at first sessions **before committees exist** (bootstrap steps 12 < 15), so they take a **direct-adoption path**, not the bill pipeline: handler opens a yes/no chamber_vote (**ordinary majority** — neither form appears in the supermajority list) → on adoption calls **[IFACE — the votes-laws designer must expose]** `LawService::enactDirect(legislature, kind: 'rules_of_order'|'ethics_code', title, text, vote)` → `laws` row (kind already in their A.3 enum) + `law_versions` v1, act number allocated ("Act 2030-01" idiom). **Re-adoption appends a version** (source `enactment`), never edits. If law-substrate sequencing lags, these two handlers are blocked behind that WI — flagged on the critical path, since the FIRST_SESSIONS checklist needs them.

### D.3 Misconduct investigations + removal proceedings

```
misconduct_investigations
  id · admin_office_id FK · code varchar (INV-YYYY-NN, unique per office)
  subject_type + subject_id (legislature_members | users | legislatures)
  complainant_user_id uuid null      -- any resident; NULL = own motion / system (CLK-02)
  summary text · status CHECK in (intake, investigating, referred, closed_no_finding)
  findings_record_id uuid null → public_records · referred_proceeding_id uuid null
  timestamps + deleted_at

removal_proceedings  (F-LEG-022 canonical; F-SPK-007 presides)
  id · legislature_id FK · kind CHECK in (impeachment, censure, expulsion,
                                          judge_removal, executive_removal)
  subject_type + subject_id · source_investigation_id uuid null
  presided_by_member_id FK            -- engine: != subject (removal.presider rule)
  opened_via varchar(16) ('F-SPK-007' | 'F-LEG-007')
  vote_id uuid null → chamber_votes [IFACE: threshold_class supermajority]
  outcome varchar(12) null CHECK in (removed, censured, expelled, retained)
  closed_at · timestamps
```
**Proceeding ESM (minimal):** `opened → presiding_designated → voted → closed(outcome)`. Intake: any resident files a complaint (no catalog form exists for I-ADM intake — **flagged registry gap**; implemented as an audited RecordService/controller action, not an engine form), or own-motion/system. Findings published (`public_records`), then `referred` (→ proceeding) or `closed_no_finding`. F-LEG-022 vote: **supermajority of all serving**; Speaker presides except own case (B.3). `removed`/`expelled` member → system-files F-LEG-036 → VacancyService (closed loop, F). `censured` → record only. **Removal parity** (same standard for legislators, executives, judges): the `kind` enum reserves `judge_removal`/`executive_removal`; Phase C activates only `legislature_members` subjects — justified deferral: no seated judges or elected executives exist until D/E.

Oversight surface: `Pages/Legislature/Oversight.vue` per contract — investigations docket (`{id, subject, re, state}`), removal panel computing `NEED = supermajority(SERVING)` live from serving rows, vacancy state strip (reads `vacancies` ESM-13), F-LEG-022 + F-LEG-036 form cards.

---

## E) PROPER ELECTION BOARD — WF-ELE-10 completion

### E.1 F-LEG-012 → appointments → seating

Handler `Handlers\ElectionBoardCreationAct` (R-09 files): payload `{jurisdiction_id = legislature's, nominees: [user_id…] (optional at filing), board_size}` → **supermajority** chamber_vote `votable_type='election_board_creation'`. On adoption: `election_boards` row `status='forming'`, `is_bootstrap=false`, `legislature_id` set, `created_by_act_id` backfilled when the act enrolls **[IFACE]**. Per nominee: `appointments` row (`appointable_type='election_boards'`) → consent chamber_vote (**ordinary majority** — unstated threshold rule) → on consent: `election_board_members` row `seated` + `terms` row (civil appointment, 10y, CLK-09 armed), appointment `seated`. Nominee eligibility: active association in the jurisdiction (Art. I — the only check); independence is a duty, not a test.

### E.2 Bootstrap retirement — `app/Services/Legislature/ElectionBoardTransitionService.php`

Trigger: the forming board reaches readiness — all noticed appointments resolved AND seated ≥ `config('cga.election_board_min_members', 3)` (no constitutional number exists; "as implemented", flagged). **One transaction** (forced by the partial unique `(jurisdiction_id) WHERE status='active'`):
1. bootstrap board (same jurisdiction, `is_bootstrap`, `active`) → `retired`, `retired_at = now()`;
2. proper board → `active`;
3. **custody transfer of in-flight elections:** `UPDATE elections SET election_board_id = proper WHERE election_board_id = bootstrap AND status NOT IN ('final','cancelled')` — one audit entry `elections.custody_transferred` enumerating election ids (the WF-ELE-10 "transfers custody records" step). **Certified/final elections keep their historical bootstrap board id** — provenance is immutable;
4. `public_records` kind `certification`: "bootstrap board retired; custody transferred; board authoritative for all future elections."

**What happens to in-flight elections:** nothing stops — open approval phases, scheduled specials, queued tabulations are board-agnostic jobs. The board id only gates **F-ELB filings**: from the flip, F-ELB forms on those elections require a seated proper-board member; the operator's bootstrap posture ends instantly and *automatically* — `RoleService::hasActiveBoardSeat` keys on `is_bootstrap + active` (now false) and seated humans match the first branch; `ResolvesBoardActor::boardActorFor` needs **zero changes**. San Marino's and Montegiardino's open successor elections + the scheduled Montegiardino special are the live verification cases.

Edge handling: proper-board member vacancy → re-run the appointment pipeline; board falling below the minimum does **not** resurrect the bootstrap board (honest: the legislature must appoint; chamber-home surfaces the gap).

---

## F) VACANCY CLOSED LOOP

### F.1 F-LEG-036 (closes the `vacancy:declare` dev gap)

Handler `Handlers\VacancyDeclaration` — roles R-09|R-10 (+system). **Authorization rule:** R-10 or system may declare any current seat; a plain R-09 may declare only **their own** (resignation) — prevents declaration-as-weapon; cite Art. II §5 · as implemented. Payload `{member_id, reason CHECK in (resigned, deceased, removed, relocation, other)}`. Delegates to the existing `VacancyService::declare(member, reason, actor, via: 'F-LEG-036')` — countback → certify-or-special machinery unchanged. `VacancyDeclareCommand` kept but rerouted through `ConstitutionalEngine::file('F-LEG-036', null, …)` so even dev declarations chain identically.

**New cascades added to `VacancyService::declare()`:**
- committee echo: vacate the member's `committee_seats` (`vacated_reason='chamber_vacancy'`), clear chair/alternate pointers where held → WF-LEG-13 refill queue + `recheck()` note;
- speaker echo: if the member is Speaker → `legislatures.speaker_id = NULL`, public record, and a `speaker_ballot` item auto-queued as the first general-business item of the next session (replacement, not the first-election lockout — "serves until next legislature *unless replaced*"; the chamber had a functioning organization).

### F.2 Member relocation (WF-CIV-03) — trigger linkage

Backend hook in `ResidencyService::verify()`: when a new claim supersedes a prior one (associations transferred), dispatch `App\Jobs\HandleOfficeholderRelocationJob(userId)`:
1. find the user's current `legislature_members` rows;
2. **footprint test:** districted seat → the district's member jurisdictions (via `legislature_district_jurisdictions`) ∩ user's NEW active associations; type_b/at-large → `legislature.jurisdiction_id` ∈ associations;
3. out of footprint → **system-files F-LEG-036** (`reason='relocation'`, `via='WF-CIV-03'`) → the full Phase B countback/special loop. Federation notification is a Phase F stub (audit entry only).

**Grace period — the honest reading:** the constitutional grace IS the new jurisdiction's CLK-05 threshold (~30 days): rights never gap (old claim stays Active until the new claim Verifies — already enforced), and the seat vacates only at actual re-association. **Away-pattern detection** (the relocation screen's "9/30 days away" meter — sustained pings outside *without* a re-declaration) requires continuous ping telemetry → **deferred to Phase F mobile geofencing**, justification: Phase C has manual/simulated pings only; the screen ships with the meter honestly empty ("away detection arrives with mobile pinging"). Board members / admin staff relocation: constitution is silent for appointed officers → deferred to Phase D with a flag (appointed civil officers are not seat-footprint-bound the way elected reps are).

Frontend: `Pages/Civic/Relocation.vue` per the civic contract — travel-vs-move choice, 3-step re-association tracker reusing Residency components, held-office card rendered iff actor holds R-09 ("seat vacates → countback Art. II §5").

---

## G) HANDLERS + ROLESERVICE ADDITIONS

### G.1 Handler map additions (`FormRegistry::HANDLERS`), all under `app/Domain/Forms/Handlers/`

| Form | Handler class | Roles (engine-authorized) | Validation (validator/handler) | Writes | Audit/record |
|---|---|---|---|---|---|
| F-LEG-001 | `OathOfOffice` | R-09 (own row) | member status `elected` | member → `seated`, `seated_at` | audit + record `participation` |
| F-LEG-002 | `AttendanceRegistration` | R-09 | session in `quorum_call`/`in_session`; own row | attendance → `present` | audit |
| F-LEG-006 | `PublicRecordStatement` | R-09 | non-empty body | public_records `statement` | sealed entry |
| F-LEG-007 | `MotionSubmission` | R-09 | session open; kind enum | motions row | audit |
| F-LEG-008 | `SpeakerElectionVote` | R-09 (cast); system opens first ballot | open speaker ballot; one cast/member | vote_cast rankings; close → ChamberRcv tally → speaker_id | full round record in payload; record `certification` on seating |
| F-LEG-009 | `CommitteeCreationAct` | R-09 | seats ≥ 1; kind-split validity; supermajority class | chamber_vote → committees row | audit + record `act` (via law enrollment [IFACE]) |
| F-LEG-010 | `CommitteePreferenceRanking` | R-09 | rankings ⊆ open committees | committee_preferences upsert | audit |
| F-LEG-011 | `CommitteeChairVote` | R-09 (cast) | candidates = committee members | vote_cast; close → chair+alternate | round record |
| F-LEG-012 | `ElectionBoardCreationAct` | R-09 | supermajority class; nominee association | chamber_vote → board forming + appointments | audit + record |
| F-LEG-013 | `AdminOfficeCreationAct` | R-09 | one live office per legislature | chamber_vote → admin_offices | audit + record |
| F-LEG-022 | `RemovalVote` | R-09 (cast) | supermajority; presider ≠ subject | proceeding vote; outcome → member `removed` → F-LEG-036 | audit + record `act` |
| F-LEG-032 | `RulesOfOrderAdoption` | R-09 | majority; non-empty text | chamber_vote → `LawService::enactDirect` [IFACE] | act + record |
| F-LEG-033 | `EthicsCodeAdoption` | R-09 | same | same (kind `ethics_code`) | same |
| F-LEG-036 | `VacancyDeclaration` | R-09 (own) / R-10 / system | member current; reason enum | `VacancyService::declare` | existing ESM-13 chain |
| F-SPK-001 | `SessionCall` | R-10 / system | scheduled_for ≥ now; ≤ CLK-02 due encouraged (warn) | sessions row | audit |
| F-SPK-002 | `AgendaSetting` | R-10 | `session.agenda_order` (locked head immutable) | agenda tail | audit |
| F-SPK-003 | `QuorumCountPublication` | R-10 / system | session in quorum_call | snapshots + quorum_met; per-kind | audit + record |
| F-SPK-004 | `TieBreakingVote` | R-10 | `speaker.tiebreak_only` tie-state | speaker cast, `speaker_tiebreak` | audit |
| F-SPK-005 | `CommitteeAssignmentAdministration` | R-10 | committees created; budget computable | assignment run (C.4) | full snapshot + record `certification` |
| F-SPK-006 | `MemberPriorityFacilitation` | R-10 | member is serving | priority agenda item + log | audit |
| F-SPK-007 | `RemovalPresiding` | R-10 (or designated presider) | presider ≠ subject | removal_proceedings open/advance | audit |
| F-SPK-008 | `AttendanceCompulsionOrder` | R-10 | quorum_met === false | attendance `compelled`; record entry | audit + record |
| F-SPK-009 | `SessionMinutesPublication` | R-10 / R-29 | session in_session/failed_quorum | minutes record; session → adjourned; CLK-02 bookkeeping | sealed record |
| F-CHR-001 | `CommitteeMeetingCall` | R-12 / R-13(chair absent) | committee seated | committee_meetings row | audit |
| F-CHR-002 | `CommitteeAgendaSetting` | R-12 / R-13 | meeting scheduled/open | meeting agenda | audit |
| F-CHR-003 | `BillReferralToFloor` | R-12 / R-13 | committee vote passed **[IFACE — sibling owns the bill transition; this handler may be theirs; listed for registry completeness]** | bill → on_floor | audit |
| F-CHR-004 | `CommitteeReportFiling` | R-12 / R-13 | committee seated | committee_reports + record | sealed record |

Sibling-owned (for the registry's sake, NOT mine): F-LEG-003/004/005, 023/024/025, 031 (exists), 034, 035, F-IND-008/009/010, F-ELB-005.

### G.2 RoleService additions (derived, never stored — pattern unchanged)

| Role | Fact query |
|---|---|
| **R-10 Speaker** | `legislatures.speaker_id` → current member row → `user_id` (member status ∈ CURRENT_STATUSES) |
| **R-11 Committee member** | `committee_seats` row `status='seated'`, `vacated_at IS NULL`, committee not dissolved, member current |
| **R-12 Committee chair** | `committees.chair_member_id` → current member row → user |
| **R-13 Alternate chair** | `committees.alternate_member_id` → same |
| **R-29 Admin staff** | seated `appointments` (`appointable_type='admin_offices'`) on a non-dissolved office, term active |

`derive()` gains five booleans (R-12 ⇒ R-11 by construction — chair candidates are committee members); fact writers (`SessionService`, speaker seating, assignment runs, appointment consents, VacancyService cascades) call `flushUser()`. `EnsureRole` middleware + sidebar gates pick the codes up automatically via shared props.

### G.3 Validator additions (PROTECTED — constitutional review required)

New rules in `ConstitutionalValidator`: `speaker.tiebreak_only` (B.2), `removal.presider` (`presided_by != subject`, Art. II §3), `session.agenda_order` (locked head, Art. II §2), committee kind-split validity (Art. V §3), F-LEG-036 declarer rule (Art. II §5 · as implemented). Cross-field setting rules deferred from Phase A land with the sibling's F-LEG-031 bill path (min ≤ max, civil/judicial lockstep) — noted, theirs.

---

## H) WORK-ITEM BREAKDOWN (this scope)

| WI | Size | Content | Depends on |
|---|---|---|---|
| **WI-C-S0** | S | `public_records` migration + append trigger + `RecordService` + `PublicRecordsController` + `Pages/System/PublicRecords.vue` | — (first; everything records) |
| **WI-C-S1** | L | sessions/attendance/motions migrations + `SessionService` + F-SPK-001/002/003/008/009, F-LEG-001/002/006/007 handlers + CLK-02 (`MeetingDeadlineJob`, arm points in CertificationService + SessionService) + `Chamber.vue` + `SessionConsole.vue` | S0; **[IFACE]** chamber_votes for motion votes (motions can land read-only before votes wire) |
| **WI-C-S2** | M | `ChamberRcvService` + F-LEG-008 + F-SPK-004 + `speaker.tiebreak_only` + speaker seating/replacement + `SpeakerTools.vue` | S1 + sibling chamber_votes/vote_casts |
| **WI-C-S3** | L | committees migrations + F-LEG-009/010/011 + F-SPK-005 `CommitteeAssignmentService` (+ recheck) + F-CHR-001/002/004 + `Committees.vue`/`CommitteeDetail.vue` | S2 (chairs need RCV; assignment needs speaker) |
| **WI-C-S4** | M | admin_offices + investigations + removal_proceedings migrations + F-LEG-013/022/032/033 + F-SPK-007 + `Oversight.vue` | S1; F-LEG-032/033 blocked on sibling `LawService::enactDirect` |
| **WI-C-S5** | M | F-LEG-012 + appointment consents + `ElectionBoardTransitionService` (bootstrap retirement + custody transfer) | S1 (votes); verifies against live San Marino/Montegiardino successor elections |
| **WI-C-S6** | M | F-LEG-036 handler + VacancyService cascades (committee/speaker echo) + `HandleOfficeholderRelocationJob` + `Civic/Relocation.vue` + dev command reroute | S1; S3 for committee echo |
| **WI-C-ST** | M (woven) | `tests/Constitutional/`: PegQuorumTest (serving-not-present, per-kind), SpeakerTiebreakOnlyTest, SupermajorityRcvTest (incl. no-supermajority terminal), CommitteeAssignmentTest (determinism + #q2 tie-break + bicameral mirror), AgendaOrderTest, MeetingDeadlineTest (failed-quorum never resets), BoardTransitionTest (custody + R-08 flip), RelocationVacancyTest | each WI |

Critical path: S0 → S1 → S2 → S3; S4/S5/S6 parallel after S1. Exit contribution to the phase gate: S1+S2+S3 produce the organized chamber that the sibling's bill machinery passes its bill through under peg quorum in both Montegiardino (unicameral) and San Marino (bicameral, per-kind quorum at committee and floor).

**Deferral register (justifications inline above):** constitutional-matter agenda feed (Phase E — no judiciary); judge/executive removal subjects (Phase D/E — no seated subjects); away-pattern relocation detection (Phase F — needs mobile pinging); machine-translation pipeline for public_records (Phase F i18n); appointed-officer relocation rule (Phase D, constitution silent); I-ADM intake form (registry gap — no catalog form exists, implemented as audited non-form action, flagged as q-ledger/registry candidate); election-board minimum size = 3 (config, "as implemented" — no constitutional number).

**[IFACE] summary for the votes-laws designer:** `chamber_votes`/`vote_casts` with `vote_method ∈ (yes_no, rcv)`, `serving_snapshot`(+`serving_by_kind`), `speaker_tiebreak boolean`, `votable_type` values used here (`speaker_election`, `committee_creation`, `committee_chair_election`, `committee_seat_fill`, `election_board_creation`, `admin_office_creation`, `appointment_consent`, `removal_proceeding`, `law_direct_adoption`); `LawService::enactDirect(kind, title, text, vote)` + act-number allocation; `created_by_law_id` backfill hook on enrollment; emergency-powers `activeInFootprint()` agenda feed; F-CHR-003 referral gate consumes my committee facts; my `quorum_required_by_kind` per-kind peg posture (q7) must match their bicameral vote thresholds at committee AND floor.