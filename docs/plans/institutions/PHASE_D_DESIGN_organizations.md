All inputs read; substrate verified against the live code (FormRegistry, ChamberVoteService lanes, vote_casts FK shape, elections.kind enum, election_races.electorate_type, ClockRegistrySeeder CLK-13/14, ChamberActService civil-appointment pipeline, MultiJurisdictionVoteService, the two Phase D placeholder tests). Design follows.

---

# PHASE D DESIGN — SECTION: ORGANIZATIONS (registry · membership/workers · co-determination · board elections · ownership · transfers/conversions · CGC)

Scope note: `boards` + `board_seats` are OWNED by the executive designer (one table set shared by departments/CGCs/private orgs — MASTER_PLAN binding decision). This section designs the org-side consumption, the co-determination engine (which I propose to own since it is the shared engine), and everything org-specific. Coordination points are marked **[COORD-EXEC]**.

Verified substrate facts this design builds on:
- `elections.kind` already CHECKs `org_board_owner`/`org_board_worker` (migration `2026_06_13_000003`); `election_races.electorate_type` already CHECKs `residents|owners|workers` (`2026_06_13_000004`). The only Phase B gate is `ElectionSchedulingOrder::WRITABLE_KINDS` (handler-level, line 52).
- `chamber_votes.body_type` CHECK already includes `'board'`, and the lane table was explicitly built so "Phase D board votes reuse the lane with zero migration" — but `ChamberVoteService::resolveBody()` throws for `'board'` (line 751), `cast()` is typed to `LegislatureMember`, and `vote_casts.member_id` is NOT NULL FK `legislature_members`. A small additive widening is required (D-O8 below).
- `organizations` (Phase 0 `2026_01_01_000003`) already has `type`, `is_cgc`, `created_by_legislature_id`, `overseen_by_executive_id`, `ownership_type`, `employee_count`, `ip_is_public_domain`, plus Phase B's `agent_user_id` (R-23 fact used by `RoleService` + `CandidateEndorsementGrant`).
- CLK-13/14 seeded (`ClockRegistrySeeder` rows, `setting_key worker_rep_min_employees / worker_rep_parity_employees`, fires WF-ORG-04) but unwired; `ConstitutionalValidator::SETTING_BOUNDS` already rails both keys (min ≤ 100 / ≤ 2000).
- The two placeholders to convert are in `tests/Constitutional/FuturePhasePlaceholdersTest.php`: `test_worker_representation_thresholds_and_scaling`, `test_cgc_intellectual_property_is_public_domain_forever`.
- Registry-gap precedent: `ChamberActService` opens acts with no dedicated vote-type key under an identical-threshold existing key, flagged in the docblock. Reused below for F-LEG-019/026/027.

---

## A) MIGRATION SET (refines DESIGN_schema_engine §A.4)

All files `database/migrations/2026_06_23_0001NN_*.php` (org block; exec block takes `0000NN` and MUST land `boards`/`board_seats`/`departments` first — D-O2/O6/O8 FK them). Conventions: uuid PK `gen_random_uuid()`, timestamptz, string enums + CHECK, soft deletes on mutable entities, none on append-only.

### D-O1 `2026_06_23_000101_evolve_organizations.php`

Additive evolution of the existing table (it has live rows — San Marino/Montegiardino/Earth seeds — so no drop/recreate; rename guarded the way `2026_06_13_000003` guards):

| column | type / change | notes |
|---|---|---|
| `structure` | varchar(20) NULL, CHECK in (`stock`,`partnership`,`equal_partnership`,`member_owned`,`worker_owned`,`nonprofit`) | **Decision (reconciliation):** the frozen mockup contract (org-registry.html select) is this 6-value enum; the task brief's `public_good\|stock\|member\|partner\|equal_partnership` is the OPEN_QUESTIONS post-it paraphrase. "Public good" is NOT a structure value — it is expressed by `is_cgc=true` + `ownership_type='public'` (org-registry renders "public charter" exactly when `type='common_good_corp'` and no ownership). NULL for CGCs/informal/political parties. |
| `status` | varchar(16) NOT NULL DEFAULT `'registered'`, CHECK in (`registered`,`active`,`transfer_pending`,`transferred`,`converted`,`dissolved`) | implements **ESM-18**. Backfill: `is_active && is_registered → 'active'`, `dissolved_at IS NOT NULL → 'dissolved'`, else `'registered'`. `is_active`/`is_registered` kept in sync by `OrgRegistryService` for existing readers; **drop deferred** to the all-phases-done pass (flagged). |
| `registered_by_user_id` | uuid NULL FK users nullOnDelete | F-IND-012 provenance (the founding R-23; `agent_user_id` remains the CURRENT agent and is reassignable via F-ORG-001) |
| `registered_via_form` | varchar(12) NULL | `'F-IND-012'` (self) \| `'F-LEG-019'` (CGC) \| `'F-LEG-026'` (monopoly conversion creates no new org — see D-O6) |
| `purpose` | text NULL | charter summary (mockup registration field) |
| `created_by_law_id` | uuid NULL FK laws restrictOnDelete | CGC chartering act (keeps existing `created_by_legislature_id` as denormalized convenience) |
| `board_id` | uuid NULL FK boards nullOnDelete | **[COORD-EXEC]** points at the unified boards row once one stands up |
| `worker_count` | rename `employee_count` → `worker_count` | counter cache; recomputed ONLY by `RecomputeWorkerHeadcountJob` (worker = F-IND-014 signups, owner ruling #12 — "employee" is the wrong word constitutionally) |
| `registration_record_id` | uuid NULL | public_records seal of the registration |

Indexes: `status`, partial `(jurisdiction_id) WHERE status = 'active'`.

### D-O2 `..._000102_create_org_memberships_and_workers.php`

**`org_memberships`** (R-24 substrate; F-IND-013):

| column | type | notes |
|---|---|---|
| id | uuid PK | |
| organization_id | uuid FK organizations cascadeOnDelete | |
| user_id | uuid FK users cascadeOnDelete | |
| kind | varchar(12) CHECK (`member`,`shareholder`,`partner`) | the ownership class; which kinds an org accepts derives from `structure` (engine map: stock→shareholder, partnership/equal_partnership→partner, member_owned/worker_owned/nonprofit→member) |
| status | varchar(10) CHECK (`applied`,`active`,`ended`,`declined`) | WF-ORG-03: individual applies, org accepts per bylaws |
| applied_at / accepted_at / ended_at | timestamptz (applied_at NOT NULL) | |
| accepted_by_user_id | uuid NULL FK users | the R-23 who accepted |
| end_reason | varchar(24) NULL | (`resigned`,`removed`,`transferred`,`dissolved`) |
| timestamps + softDeletes | | |

Partial unique `(organization_id, user_id, kind) WHERE status IN ('applied','active') AND deleted_at IS NULL`. Index `(user_id) WHERE status='active'` (R-24 derivation), `(organization_id, kind) WHERE status='active'` (electorate enumeration).

**`org_workers`** (R-25 substrate; F-IND-014 — **THE worker-count source**):

| column | type | notes |
|---|---|---|
| id | uuid PK | |
| employer_type | varchar(16) CHECK (`organizations`,`departments`) | **Deviation from A.4 (justified):** A.4 gives `org_workers.organization_id` and a parallel `departments.worker_count`. Art. III §6 applies *identically* to departments, CGCs, and private orgs — ONE worker registry means one F-IND-014 handler, one headcount recompute, one CLK-13/14 evaluator, and the WorkerRepresentationTest can pin a single code path. CGCs are organizations, so two employer types suffice. **[COORD-EXEC]**: departments keep their `worker_count` counter cache, maintained by the same job. |
| employer_id | uuid (no FK — polymorphic; existence service-checked) | |
| user_id | uuid FK users cascadeOnDelete | |
| contract_id | uuid NULL FK org_contracts nullOnDelete | the recurring-labor contract backing the signup |
| status | varchar(10) CHECK (`applied`,`active`,`ended`) | active = countersigned; **headcount = COUNT(*) WHERE status='active'** (owner ruling #12: everyone signed via F-IND-014, regardless of contract type) |
| started_at / ended_at | timestamptz NULL | |
| timestamps + softDeletes | | |

Partial unique `(employer_type, employer_id, user_id) WHERE status IN ('applied','active') AND deleted_at IS NULL`. Index `(employer_type, employer_id) WHERE status='active'` (the headcount query), `(user_id) WHERE status='active'` (R-25).

### D-O3 `..._000103_create_org_contracts.php` (minimal viable)

| column | type | notes |
|---|---|---|
| id | uuid PK | |
| organization_id | uuid FK organizations | the org party |
| counterparty_type / counterparty_id | varchar(16) CHECK (`users`,`organizations`) / uuid | |
| kind | varchar(16) CHECK (`labor_recurring`,`labor_single`,`commercial`,`other`) | `labor_recurring` feeds org_workers (mockup contract: "recurring labor contracts count toward worker headcount" — operationally: an active org_workers row requires a co-signed `labor_recurring` contract) |
| terms | text NOT NULL | |
| signed_by_org_user_id / signed_by_org_at | uuid NULL / timestamptz NULL | |
| signed_by_counterparty_at | timestamptz NULL | counterparty user signs for self; counterparty org signs via its agent |
| status | varchar(8) CHECK (`draft`,`offered`,`active`,`ended`,`voided`) | |
| effective_at / ended_at | timestamptz NULL | |
| timestamps + softDeletes | | |

DB belt-and-suspenders: `CHECK (status <> 'active' OR (signed_by_org_at IS NOT NULL AND signed_by_counterparty_at IS NOT NULL))` — **co-sign required; the engine rejects single-sided activation** (`OrgContractService` is the only writer; each signature is its own audit entry).

### D-O4 `..._000104_create_org_ownership_stakes.php` (the share system — owner ruling #12)

| column | type | notes |
|---|---|---|
| id | uuid PK | |
| organization_id | uuid FK organizations | |
| holder_type / holder_id | varchar(16) CHECK (`users`,`organizations`,`jurisdictions`) / uuid | CGC posture: exactly one open stake row, `holder_type='jurisdictions'`, 100% — the BoG stands where shareholders would (owner ruling #12) |
| units | numeric(20,6) NOT NULL CHECK (units > 0) | |
| pct | numeric(7,4) NULL | denormalized snapshot; recomputed by `OrgOwnershipService` on any stake write |
| acquired_via | varchar(12) CHECK (`founding`,`issue`,`transfer`,`conversion`) | |
| source_transfer_id | uuid NULL FK org_transfers nullOnDelete | provenance |
| as_of | timestamptz NOT NULL | |
| ended_at | timestamptz NULL | current cap table = `ended_at IS NULL`; history preserved (no soft deletes — closure via ended_at, mirroring jurisdiction_associations) |
| timestamps | | |

Index `(organization_id) WHERE ended_at IS NULL`. Stakes determine **who is in the owner electorate and the economics — never vote weight** (see §C decision). A user-holder's stake implies an `org_memberships` row of the matching class (service-maintained invariant).

### D-O5 `..._000105_create_org_document_packages.php`

**`org_document_packages`**: `id`, `organization_id` FK, `key` varchar slug, `name`, `kind` CHECK (`charter`,`bylaws`,`hr_policy`,`compensation_policy`,`custom_form`,`other`), `status` CHECK (`active`,`retired`), timestamps+sd. Unique `(organization_id, key) WHERE deleted_at IS NULL`.
**`org_document_package_versions`**: `id`, `package_id` FK cascade, `version_no` smallint, `content` text, `created_by_user_id`, `created_at`. Unique `(package_id, version_no)`. Versions append, never edit.

Engine rule (in the F-ORG-001 handler, pinned by test): a package `key` may never collide with a canonical/alias constitutional form ID (`FormRegistry::exists()` → reject with citation) — **self-managed internal forms live above the constitutional floor and can never override it**.

### D-O6 `..._000106_create_org_transfers_and_conversions.php`

**`org_transfers`** (F-ORG-005, WF-ORG-06 — mutual consent):

| column | type | notes |
|---|---|---|
| id | uuid PK | |
| organization_id | uuid FK | the entity whose ownership moves |
| to_party_type / to_party_id | CHECK (`users`,`organizations`) / uuid | |
| terms | text | |
| consent_from_at / consent_from_user_id | timestamptz NULL / uuid NULL | from-side R-23 |
| consent_to_at / consent_to_user_id | timestamptz NULL / uuid NULL | to-side (user self / org agent) |
| status | varchar(10) CHECK (`proposed`,`consented`,`completed`,`abandoned`) | `consented` requires BOTH consents — engine rejects anything less (the only ownership path overriding owner consent is monopoly acquisition, which is a *conversion*, never a transfer) |
| completed_at | timestamptz NULL | completion = `OrgOwnershipService` closes/opens stake rows in one transaction |
| ffc_synced_at | timestamptz NULL | Phase F full-faith-and-credit stub (WF-JUR-06) |
| timestamps + softDeletes | | |

**`org_conversions`** (WF-ORG-07/08/09; F-ORG-006, F-LEG-026, F-LEG-027):

| column | type | notes |
|---|---|---|
| id | uuid PK | |
| organization_id | uuid FK | |
| direction | CHECK (`private_to_cgc`,`cgc_to_private`) | |
| via | CHECK (`mutual`,`monopoly_acquisition`,`cgc_sale`) | mutual = for-sale entity + F-ORG-006 request; monopoly = F-LEG-026 finding path; cgc_sale = F-LEG-027 sell branch |
| proposal_id / authorizing_vote_id | uuid NULL (chamber_vote_proposals / chamber_votes) | the legislative act vote |
| authorizing_law_id | uuid NULL FK laws | engine: REQUIRED before status may pass `voted` (both directions are legislature-only — CGCs are never self-converted) |
| fair_market_floor | numeric(18,2) NULL | recorded BEFORE the compensation step; required for `private_to_cgc` |
| fair_market_basis | text NULL | published valuation rationale |
| compensation | numeric(18,2) NULL | engine blocks `compensation < fair_market_floor` (hardened — Art. III §5); DB belt: `CHECK (compensation IS NULL OR fair_market_floor IS NULL OR compensation >= fair_market_floor)` |
| compensation_record_id | uuid NULL | public_records seal of the payment record |
| board_transition | jsonb DEFAULT '[]' | founding-governor offers to the prior board: `[{user_id, offered_at, response: pending\|accepted\|declined, appointment_id}]` (accept → rides the F-EXE-001→F-LEG-020 appointment pipeline **[COORD-EXEC]**; decline → seat filled by the ordinary WF-EXE-05 analog) |
| status | CHECK (`proposed`,`voted`,`compensation_pending`,`converting`,`completed`,`abandoned`) | |
| completed_at | timestamptz NULL | |
| timestamps + softDeletes | | |

### D-O7 `..._000107_create_cgc_ip_register.php` — IRREVERSIBLE public-domain dedications

Append-only posture (the audit_log/public_records pattern — no update path, no delete path, by construction):

| column | type | notes |
|---|---|---|
| seq | bigint GENERATED ALWAYS AS IDENTITY, PK | dedication order |
| id | uuid UNIQUE DEFAULT gen_random_uuid() | cross-instance ref |
| organization_id | uuid FK organizations **restrictOnDelete** | orgs only soft-delete; rows outlive dissolution (records archived, dedication eternal) |
| asset | varchar NOT NULL | |
| kind | varchar(24) CHECK (`software`,`patentable_invention`,`copyrightable_work`,`design`,`data`,`process`,`other`) | |
| description | text NULL | |
| status | varchar(13) NOT NULL DEFAULT `'public_domain'` **CHECK (status = 'public_domain')** | the single-value CHECK is the schema-level statement of irreversibility — no other value can ever be stored |
| dedicated_via_form | varchar(12) NOT NULL | `F-LEG-019` (chartering — all existing IP), `F-LEG-026` (monopoly conversion — all acquired IP), `F-BOG-002`/`F-ORG-001` (new works as produced) |
| dedicated_by_user_id | uuid NULL | |
| published_record_id | uuid NULL | public_records seal |
| audit_seq | bigint NULL | chain seal |
| published_at / created_at | timestamptz | **no updated_at, no deleted_at** |

Plus, in the same migration:
```sql
CREATE TRIGGER cgc_ip_register_immutable BEFORE UPDATE OR DELETE ON cgc_ip_register
  FOR EACH ROW EXECUTE FUNCTION raise_append_only();  -- same function audit_log uses
REVOKE UPDATE, DELETE ON cgc_ip_register FROM <app role>;
```

**Irreversibility design (four layers, each independently sufficient):** (1) DB trigger raises on UPDATE/DELETE; (2) privilege revocation; (3) single-value CHECK on status — "privatize" is unrepresentable; (4) the only writer is `CgcIpRegisterService::dedicate()` — the model has `$guarded = ['*']` semantics via `creating`-only lifecycle, no update/delete methods exist, and `cgc_to_private` conversion code never touches the table (new works after privatization follow private rules; existing dedications stand — WF-ORG-09). Pinned by CgcIpPublicDomainTest (§E).

### D-O8 `..._000108_board_vote_and_election_linkage.php` **[COORD-EXEC — shared infrastructure; whichever section lands first ships it]**

1. `elections`: add `board_id uuid NULL` FK boards restrictOnDelete (the body being filled for `org_board_*` kinds; departments' governor consent is an appointment, not an election, so this is org-driven). Index `(board_id, status)`.
2. `vote_casts`: `member_id` → NULLABLE; add `board_seat_id uuid NULL` FK board_seats restrictOnDelete; `CHECK ((member_id IS NOT NULL) <> (board_seat_id IS NOT NULL))`; drop unique `(vote_id, member_id)` → recreate as two partial uniques `(vote_id, member_id) WHERE member_id IS NOT NULL` and `(vote_id, board_seat_id) WHERE board_seat_id IS NOT NULL`. (The lane table needs **zero** change, as designed in Phase C.)
3. `terms.office_kind` CHECK: widen to add `'board_seat'` (elected owner/worker seats; `'board_governor'` already exists), and `terms.term_class` CHECK: add `'org_cycle'` (see §C.4).
4. `org_workers.contract_id` FK declared here if D-O3 ordering demands (forward-ref pattern of B-10).

---

## B) CO-DETERMINATION ENGINE

### B.1 The formula (owner ruling #12 — the proportional formula IS the ordinary meaning of Art. III §6 "scales uniformly")

New service `App\Services\Organizations\CoDeterminationService` — **propose adding to the PROTECTED list** (CLAUDE.md Protected Files): it is the Art. III §6 hardened math, exactly like `VoteCountingService` is the Art. II math.

```php
/**
 * PROTECTED — Art. III §6. Pure, static, DB-free (pinned exhaustively by
 * tests/Constitutional/WorkerRepresentationTest).
 *
 * min = resolved worker_rep_min_employees   (CLK-13, default 100)
 * par = resolved worker_rep_parity_employees (CLK-14, default 2000)
 * Resolved per the org/department's jurisdiction via SettingsResolver at
 * evaluation time — never frozen (clock-registry rule).
 */
public static function workerSeats(int $workers, int $ownerSeats, int $min = 100, int $par = 2000): int
{
    if ($ownerSeats < 1 || $workers < $min) return 0;
    // round = half-up (PHP round() default), matching the mockup's Math.round —
    // pinned by test so the two can never diverge.
    return max(1, min($ownerSeats, (int) round(($workers - $min) / ($par - $min) * $ownerSeats)));
}

/** Smallest headcount at which entitlement first exceeds $seats (UI projection + CLK next_check). */
public static function nextStep(int $seats, int $ownerSeats, int $min = 100, int $par = 2000): ?int
{
    if ($seats >= $ownerSeats) return null;
    return min($par, (int) ceil(($seats + 0.5) / $ownerSeats * ($par - $min) + $min));
}
```

Verbatim contract from `mockups/organizations/co-determination.html` (`workerSeats`/`nextStep`) and cgc-detail's worked case (1,450 workers, 7 governors → 5 worker seats — `round(1350/1900×7) = round(4.97) = 5` ✓). Endpoints: `f(100) = max(1, round(0)) = 1` (CLK-13 first seat); `f(2000) = round(ownerSeats) = ownerSeats` (CLK-14 parity); cap `min(ownerSeats, …)` means parity is the CEILING — worker seats never exceed owner-side seats.

**Applies identically to all three body kinds** by construction: the function takes `(workers, ownerSeats)` and nothing else. Owner side per kind: private org = `owner_elected` seats; CGC = `governor` seats (BoG stands where shareholders would); department = `governor` seats. One engine, one table set (`boards`+`board_seats`), one validity rule.

### B.2 Headcount recompute — queued, never synchronous

`App\Jobs\Organizations\RecomputeWorkerHeadcountJob($employerType, $employerId)`:

- **Dispatch points** (after-commit): every `org_workers` write (`OrgMembershipService::activateWorker/endWorker`), every contract void that ends a worker row, dissolution. Never inline — a 2,000-signup import must not run 2,000 board reconciliations in request path; job is debounced via `ShouldBeUnique` on `(employer_type, employer_id)`.
- **Body**: `count = org_workers active rows` → write counter cache (`organizations.worker_count` / `departments.worker_count`) → load the entity's board (if any) → `required = CoDeterminationService::workerSeats(count, ownerSeats, resolvedMin, resolvedPar)` → reconcile (B.3) → evaluate CLK-13/14 timers (B.4) → audit entry (`organizations` module, event `co_determination.recomputed`).
- **Safety net**: nightly `EvaluateCoDeterminationJob` sweep re-runs for every board-bearing entity whose timers are armed (the CLK-05/06 pattern: event-driven cheap path + scheduled sweep — covers threshold *lowering* by act: `setting_changes` on either key re-derives armed CLK-13/14 timers per the Phase A clock rule).

### B.3 Board-validity rule + enforcement posture

`boards.worker_seats` (required entitlement snapshot) and `boards.composition_valid` exist in the exec designer's schema (A.4). Org-side reconciliation (`OrgBoardService::reconcile(Board $board, int $required)`):

1. **Provision up**: if provisioned `worker_elected` seat rows < required → create vacant seat rows, fire **WF-ORG-04**: open/extend an `org_board_worker` election for the vacant worker seats (F-ORG-004 *system auto-trigger* path — R-23 absence cannot stall it; this is the Phase D exit criterion).
2. **Provision down**: if provisioned > required → no mid-term removal (removal exists only via the board-removal vote). Surplus seated members serve out their terms; their seats are marked `retiring` (board_seats status note) and are not refilled at term end. **Exception (hardened ceiling)**: seated `worker_elected` > `owner_seats` can never occur going forward (the formula caps at parity), and an owner-seat reduction that would push seated workers above parity is itself rejected pre-commit (Art. III §6 — parity is the ceiling).
3. **Validity**: `composition_valid = (seated_or_in_pipeline worker seats >= required) AND (seated worker seats <= owner seats)`, where "in pipeline" = vacant seat with an open election or scheduled WF-ORG-04 event. Recomputed only here.
4. **Enforcement posture — invalid boards cannot ACT, but can always CURE**: while `composition_valid = false`, `ChamberVoteService::open(body_type='board', …)` rejects with citation Art. III §6 for every vote type EXCEPT the cure path (`board_chair_elect`, and consent/seating side-effects that flow from elections/appointments). Already-open votes close normally (no retroactive voiding — the snapshot-at-open discipline of the lane engine). Department boards: an invalid department board additionally blocks F-BOG-001 rule implementation **[COORD-EXEC]**. The block + the open WF-ORG-04 event are both on the public record.
5. **Joint chair re-election on composition change**: any seat-count change OR any seat-holder change in either class (new worker seat seated, governor replaced, owner track re-elected) → `boards.chair_seat_id` cleared → `OrgBoardService::openChairElection()` (C.3). "Any composition change re-triggers joint chair election" — board-elections.html, hardened.

### B.4 CLK-13/CLK-14 wiring

- **Arm**: at board stand-up (or org registration for board-less orgs once they hire their first worker — armed lazily on first `org_workers` write), one CLK-13 timer + one CLK-14 timer per employer entity: `clock_code`, `jurisdiction_id` = employer's jurisdiction, `subject_type/subject_id` = `organizations|departments`/id, `setting_key` = the respective amendable key, `next_check_at` = nightly sweep cadence.
- **Fire**: CLK-13 fires once when active headcount first crosses resolved min with zero worker seats provisioned → dispatches `RecomputeWorkerHeadcountJob` (idempotent — the job is the engine; the clock is the registry-visible trigger + audit entry, `fires_workflow WF-ORG-04`). Re-arms as a threshold watcher (headcount can fall and re-cross). CLK-14 fires at parity crossing, same mechanics. Intermediate interpolation steps are fired by the recompute job directly (they are consequences of the same threshold registry, recorded with `clock_code` CLK-13 refs — matches the registry's "interpolation points" listing under WF-ORG-04).
- **Cross-field validator rule** (lands with this phase, in the Phase C cross-field slot of `ConstitutionalValidator::checkSettingChange`): `worker_rep_min_employees < worker_rep_parity_employees` — the mockup's "must stay below the parity threshold" (CLK-13 card) / "must stay above the minimum" (CLK-14 card). Protected-file change, constitutional review.

---

## C) BOARD ELECTIONS (reuse the Phase B machinery — never fork the PROTECTED counting path)

### C.1 Electorates — **DECISION: one-member-one-vote within the electorate class. Stakes are NEVER vote weights.**

Justification:
1. **The frozen contract says so**: board-elections.html counts "1,204 shareholder **ballots**" (quota `floor(1204/10)+1 = 121`) and "692 **of 740 workers** voted" — integer per-person ballots in both tracks. A stakes-weighted count cannot produce "1,204 of N shareholders" arithmetic.
2. **The registry says so**: R-26 acquisition = "Board election by ownership class (R-24)" — the class (R-24 role holders), not the holding size, is the electorate definition.
3. **Constitutionally**: Art. III §6 is silent on weighting; the system-wide ordinary meaning of an "election" in the Template is one-person-one-vote STV (Art. I/II), and owner ruling #12 assigns the share system the role of defining **who stands on the owner side** (and the economics of compensation/transfer), not how many votes a person casts. The BoG-stands-where-shareholders-would substitution only works under OMOV — governors hold no stakes.
4. **Engineering**: the PROTECTED `VoteCountingService` counts one ballot per envelope; weighting would fork the protected path, which the Phase B binding decision forbids.
- Flag: recorded as a **q-ledger candidate** ("owner-track suffrage is per-owner, not per-share") for the next constitutional draft, mirroring how #q5/#q6 were handled.

Electorate resolution (new `OrgElectorateService`, consumed by the F-IND-007 `BallotSubmission` and F-IND-011 `CandidacyRegistration` handlers when `race.electorate_type !== 'residents'`):
- `owners` → users with an active `org_memberships` row of the org's ownership class (shareholder/partner/member per structure), plus user-type holders of open `org_ownership_stakes` (service keeps these consistent).
- `workers` → users with an active `org_workers` row for the employer.
- Envelope/ballot tables, receipt hashes, two-table secrecy: unchanged — board ballots get the identical commitment scheme (individual approvals/ballots secret; aggregates public).

**Rights-automatic scoping (protected-file change, constitutional review required):** `RIGHTS_AUTOMATIC_FORMS` pins F-IND-011/F-ELB-002 as "association is the only gate." That pin is the Art. I rule for PUBLIC office (`electorate_type='residents'`). Org-class checks on `owners`/`workers` races are Art. III §6 board structure, not an Art. I eligibility condition. The validator's guard gains an explicit scope: the association-only invariant applies to residents races; class-membership checks are permitted (and required) on org races. `RightsAutomaticTest` is updated in the same PR to pin BOTH directions (residents races: class keys forbidden; org races: class check mandatory, and *residency/identity* conditions still forbidden — a worker race may never demand identity verification).

### C.2 Election lifecycle for `org_board_owner` / `org_board_worker`

- **Open** (`F-ORG-003` owner track, R-23; `F-ORG-004` worker track, R-23 OR system auto-trigger from B.3): handler validates board exists + seats vacant for that class, then creates an `elections` row — `kind` per track, `jurisdiction_id` = org's registration jurisdiction, `board_id` (D-O8), `legislature_id` NULL, `election_board_id` NULL (org elections are administered by the org agent under engine supervision, not the jurisdiction's I-ELB — flagged decision: the catalog gives F-ORG-003/004 to R-23, and I-ELB's constitutional remit is public office). One `election_races` row per class: `electorate_type` `owners`/`workers`, `seats` = vacant seats of the class, `seat_kind` `'single'`-equivalent? No — seats are multi-winner: reuse `seat_kind='type_a'`-free posture: add nothing; races already carry arbitrary `seats` 1–9. For boards needing >9 seats in one class, split into grouped races of 5–9 (same Art. II §8 grouping discipline as judicial races; mockup max is 9 — matches).
- **Engine gate widening**: `ElectionSchedulingOrder::WRITABLE_KINDS` stays general/special — org elections are created by the F-ORG-003/004 handlers (not F-ELB-001), so the Phase B gate is untouched. Tabulation runs the same `TabulateElectionJob` → `VoteCountingService::countStv` (WIGM/Droop/Gregory — the mockup's quotas 121/174 are plain Droop ✓). Uncontested races (candidates ≤ seats, e.g. equal partnerships seating every partner) auto-elect without exclusion rounds — countStv already yields this.
- **Two-phase open ballot**: retained (same ESM-03; CLK-18/21 structural and the engine is one code path). F-ORG-003/004 set the schedule (`approval_opens_at`…`ranked_closes_at`) within validator rails (`approval_min_days`, `ranked_window_days`); finalist count = `finalist_multiplier × seats` as everywhere.
- **Candidacy**: F-IND-011 with `race_id` of an org race → handler checks class membership (C.1) instead of jurisdiction association; F-ELB-002 validation is replaced by auto-validation in-handler (no I-ELB in the loop; the class check IS the single permissible ground, mirror of `'no_residency_association'` → `'no_class_membership'`).
- **Certify**: tabulation complete → `OrgBoardSeatingService::certify(Election)` — a Phase D branch dispatched where `CertificationService::certify` currently requires a legislature: F-ELB-004's pipeline gains a kind dispatch (`org_board_*` → board pipeline; everything else unchanged). Certification is filed by R-23 via F-ORG-003/004 `action: certify`, with a **backstop auto-certify** clock payload (48h after tabulation completes, system actor) so a stalling agent can never block constitutionally-mandated worker seats. Seating effects: winners → `board_seats` (status `seated`, `elected_in_race_id`, `holder_user_id`), one `terms` row each (C.4), runner-up record retained for countback (universal countback applies — vacated board seats re-run prior ballots with the vacated member removed; failure → fresh class election, NOT a special public election: CLK-04's 90–180d window is an Art. II §5 public-office rule — flagged decision), `RoleService::flushUser`, composition revalidation (B.3), chair re-election trigger (B.5), public record + audit.

### C.3 Joint chair — RCV by the FULL board via the chamber-vote engine (`body_type='board'`)

- **Engine extension** (the Phase C tables were built for this; only the service needs the adapter) **[COORD-EXEC — shared]**:
  - `BoardRoster` contract (sibling of `CommitteeRoster`): `laneCounts(boardId)` → one `'all'` lane over COUNT(seated board_seats); `isSeatHolder(boardId, seatId)`; resolves jurisdiction via boardable.
  - `ChamberVoteService::resolveBody` gains the `BODY_BOARD` branch (returns `[null, jurisdictionId, ['all' => seated]]` — `legislature_id` NULL as the migration comment anticipated).
  - New cast path `castBoardSeat(ChamberVote, BoardSeat, …)` writing `vote_casts.board_seat_id` (D-O8). `LegislatureMember`-typed internals stay untouched for legislature/committee bodies.
- **Vote type** (additive key in `config/constitution/vote_types.php`, + `VoteTypeRegistryTest` update — registry is a code artifact, change under review):
  ```php
  'board_chair_elect' => [
      'label' => 'Board joint chair election (RCV by entire board)',
      'category' => 'rcv_stv', 'engine' => 'chamber', 'basis' => 'rcv_majority',
      'denominator' => 'board', 'bicameral' => 'n/a', 'dual' => null,
      'phase' => 'D', 'citation' => 'Art. III §6',
  ],
  ```
  `rcv_majority` is a new basis: RCV rounds via the existing public-rankings `countRcv` path, and the final-round winner must reach `quorum_required` = majority of ALL seated board seats — the mockup's "12 ballots, majority is 7" (7 = floor(12/2)+1 of the full board, peg-quorum style, NOT of continuing ballots). Implementation is the `rcv_supermajority` close-gate (speaker) with the majority threshold — ~10 lines in the close path. No winner reaching majority → re-ballot per board rules (vote closes `failed`, new vote opened; same speaker-RCV posture).
- Owner-elected, worker-elected, and governor seats all cast — one lane, equal votes (Art. III §6: "chair elected jointly by entire board"). Winner → `board_seats.is_chair = true`, `boards.chair_seat_id` set, R-28 derivable. Casts are PUBLIC (chamber-vote discipline — board votes are governance acts, not secret ballots; matches the mockup's published round-by-round chair count).

### C.4 Seat terms

| Seat class | term_class | window | basis |
|---|---|---|---|
| `governor` (departments, CGCs) | `civil_appointment` | 10y from seating (resolved `civil_appointment_years`), CLK-09 timer | Art. III §4 — exec designer's appointment pipeline (`ChamberActService::openCivilTerm` generalizes: `office_kind 'board_governor'` already in the terms CHECK) |
| `worker_elected` on DEPARTMENT boards | `lockstep` | ends with the overseeing legislature's term (department-detail mockup: worker terms end 2035-11-01) — `CertificationService::lockstepWindow`/`inheritedWindow` semantics, `legislature_id` anchored | Art. III §3/§6 |
| `owner_elected` + `worker_elected` on ORG boards (private + CGC) | **`org_cycle`** (new class, D-O8) | `ends_on = starts + boards.cycle_months` (org-set; **[COORD-EXEC]** add `cycle_months smallint NOT NULL DEFAULT 60` to `boards` — default mirrors `election_interval_months`); changeable only via org bylaws BEFORE an election opens, never mid-term (no `ends_on` mutation API — inherits the terms-table write-once discipline) | Roles registry: R-26/27/28 term = "Board cycle (WF-ORG-05)" |

Board term expiry → CLK-registry deadline timer per term (the CLK-09 pattern) firing WF-ORG-05: re-open the class election at cycle end.

---

## D) ORG LIFECYCLE + HANDLERS + ROLES

### D.1 ESM-18 mapping

`Registered → Active → [Endorsing] → [Co-determination tiers] → [Transfer-Pending → Transferred] → [Converted Public↔Private] → Dissolved` → `organizations.status` (D-O1). `Endorsing` and co-det tiers are derived display states (endorsements exist / worker_count vs thresholds), not stored statuses — same posture as roles.

### D.2 Handlers (FormRegistry::HANDLERS additions — org scope)

| Form | Handler (`app/Domain/Forms/Handlers/…`) | Effect |
|---|---|---|
| **F-IND-012** Organization Registration (R-03 — **association is the ONLY requirement**, Art. I Economic Freedom) | `OrganizationRegistration` | payload `{name, type ∈ (political_party,business,nonprofit,informal), structure ∈ 6-enum, jurisdiction_id, purpose}`. Engine rejects `type='common_good_corp'` with citation Art. III §5 (legislature-only via F-LEG-019 — mockup hint verbatim). Creates org row (`status='active'` — registration IS activation; the engine validation is the "Org engine validates" step of WF-ORG-01), `agent_user_id = registered_by_user_id = actor`, slug, public record, R-23 flush. |
| **F-IND-013** Org Membership Application (R-01 per catalog) | `OrganizationMembershipApplication` | payload `{organization_id, kind}` → `org_memberships` row `status='applied'`. Class must match the structure map (engine). Grants R-24 on ACCEPTANCE, not application. |
| **F-IND-014** Worker Registration (R-01) | `WorkerRegistration` | payload `{employer_type, employer_id, contract_terms?}` → draft `org_contracts` row (kind `labor_recurring`, counterparty-signed by the worker at filing) + `org_workers` row `status='applied'` linked to it. Activation on org countersign (below) → R-25 + headcount job. |
| **F-ORG-001** Organization Profile Management (R-23) | `OrganizationProfileManagement` | action-dispatching handler (the ChamberActService registry-gap precedent — **FLAGGED**: the catalog has no dedicated acceptance/contract/document forms; F-ORG-001 "Modifies: Organization record" is the canonical R-23 self-management surface): `update_profile`, `reassign_agent`, `accept_member` / `decline_member`, `countersign_contract` (→ activates linked worker rows → headcount job), `void_contract`, `manage_document_package` (create/version — FormRegistry-collision check, D-O5), `dedicate_ip` (CGC only → `CgcIpRegisterService::dedicate`). Every action audit-chained under F-ORG-001 with `payload.action` disambiguation. |
| **F-ORG-002** | exists (Phase B) — unchanged | |
| **F-ORG-003** Board Election Administration (R-23) | `BoardElectionAdministration` | actions: `provision_board` (first board: owner_seats within [1,99], cycle_months; equal_partnership convention: owner_seats = active partners), `open_owner_election` (C.2), `certify` (C.2). |
| **F-ORG-004** Worker Board Election Administration (R-23 / **system auto-trigger**) | `WorkerBoardElectionAdministration` | `open_worker_election` / `certify`; the system-filed path is the WF-ORG-04 trigger from B.3 (handler `systemOnly`-capable like F-IND-006). |
| **F-ORG-005** Ownership Transfer Initiation (R-23) | `OwnershipTransferInitiation` | creates `org_transfers` `proposed` + from-consent; counterparty consent via the same form filed by the to-side agent/user (`action: consent`) — both consents mandatory before `consented`; `complete` executes stake mutations + status `transferred`/`active` per scope. |
| **F-ORG-006** Public-Private Conversion Request (R-23 / R-09) | `PublicPrivateConversionRequest` | creates `org_conversions` `proposed` (via `mutual` or `cgc_sale` intent); routes to the legislature (a proposal awaiting F-LEG-026/027 action — request ≠ act). |
| **F-ORG-007** Organization Dissolution (R-23; judicial path Phase E) | `OrganizationDissolution` | WF-ORG-10: settle obligations (open contracts must be ended/voided — engine-checked), end memberships/workers (headcount job), end stakes, archive document packages, `status='dissolved'`, registry updated, records + audit preserved. CGCs rejected (F-LEG-027 only). |
| **F-LEG-019** CGC Creation Act (R-09) | `CgcCreationAct` | ChamberActService pattern: proposal (`chamber_vote_proposals` kind `cgc_creation`, payload `{name, charter, goods_services, oversight_executive_id, owner_seats}`) → chamber vote under `procedural_motion` (**registry gap, flagged**: the 33-row registry has no CGC-creation key; catalog threshold unstated → ordinary majority of all serving, owner ruling MANIFEST §8) → adoption creates: law (`kind 'creation_act'` via EnactmentService), org row (`type='common_good_corp'`, `is_cgc`, `ownership_type='public'`, `structure NULL`, `ip_is_public_domain=true`, `created_by_law_id`, `overseen_by_executive_id`), jurisdiction stake row (100%), board row (`boardable=organizations`, governor owner-side, `owner_seats` from charter) **[COORD-EXEC: BoG nomination pipeline F-EXE-001 → F-LEG-020 `bog_consent` fills it]**, genesis `cgc_ip_register` dedication ("all existing and future IP — Art. III §5", `dedicated_via_form 'F-LEG-019'`), co-det timers armed. Regulated identically to private peers thereafter (hardened: no code path branches on `is_cgc` except oversight/IP/conversion — pinned by test assertion in §E). |
| **F-LEG-026** Monopoly Acquisition Vote (R-09) | `MonopolyAcquisitionVote` | proposal kind `monopoly_acquisition`, payload `{organization_id, finding (monopolistic control / for-sale), fair_market_floor, fair_market_basis}` → vote under `procedural_motion` (**ordinary majority of all serving — owner ruling #13**) → adoption: `org_conversions` row (`private_to_cgc`, via `monopoly_acquisition`, status `compensation_pending`) + law. Completion service path: record `compensation` (engine BLOCKS `< fair_market_floor`, citation Art. III §5, rejected attempt on the chain) → compensation public record → stakes closed (holders paid), jurisdiction stake opened → org becomes CGC (`is_cgc=true`, `ownership_type='public'`, `ip_is_public_domain=true`, status `converted`→`active`) → bulk IP dedications (`via 'F-LEG-026'`) → `board_transition` founding-governor offers to every seated prior-board member (accept → F-EXE-001/F-LEG-020 pipeline; decline → ordinary WF-EXE-05 analog) → **workforce co-det recheck** (headcount job re-dispatched — WF-ORG-07 final step). |
| **F-LEG-027** CGC Reorganization/Sale Vote (R-09) | `CgcReorganizationSaleVote` | proposal kind `cgc_reorg_sale`, branch `reorganize` (new charter law version) / `dissolve` (wind-down via the F-ORG-007 internals, system actor) / `sell` (→ `org_conversions` `cgc_to_private` via `cgc_sale`: buyer stakes opened at recorded consideration, governor seats end, owner-elected board provisioned, **cgc_ip_register untouched — existing dedications irreversible; new works follow private rules**). Threshold: `procedural_motion` (unstated → ordinary majority; flagged). |

`ConstitutionalValidator::check()` additions (protected-file change): `F-IND-012` (type whitelist — no CGC self-registration), `F-ORG-005` (mutual-consent completeness), `F-LEG-026` (fair-market floor presence + compensation ≥ floor at the completion filing), document-package collision rule, worker-threshold cross-field rule (B.4).

### D.3 Role derivations (`RoleService` — additive facts, same pattern)

| Role | Fact (derived, never stored) |
|---|---|
| R-23 | unchanged (`organizations.agent_user_id` on active org) |
| R-24 | any `org_memberships` row `status='active'` |
| R-25 | any `org_workers` row `status='active'` |
| R-26 | seated `board_seats` row `seat_class='owner_elected'` |
| R-27 | seated `board_seats` row `seat_class='worker_elected'` |
| R-28 | seated seat with `is_chair=true` (any class — chair can be governor/owner/worker) |

`flushUser` called by: membership accept/end, worker activate/end, board seating, chair election. R-18 (governor) is exec-side **[COORD-EXEC]**.

---

## E) CONSTITUTIONAL TEST SPECS + WI BREAKDOWN

### E.1 `tests/Constitutional/WorkerRepresentationTest.php` (replaces placeholder `test_worker_representation_thresholds_and_scaling`)

Pure pins on `CoDeterminationService` (static, DB-free — the RoleService::derive pattern):
1. **Endpoints pinned**: `workerSeats(99,o)=0` ∀o; `workerSeats(100,o)=1` ∀o≥1; `workerSeats(2000,o)=o` ∀o (parity); `workerSeats(w,o)=o` ∀w≥2000 (cap — never exceeds owner seats).
2. **Linear interpolation pinned**: the mockup's worked cases verbatim — `(740, 9) = 3` (Bluefin), `(1450, 7) = 5` (cgc-detail), `(1240, 7) = 4` (PW&U), `(152, ·) = 1` (Treasury); rounding = half-up, asserted at the .5 boundaries so PHP/JS can never diverge.
3. **Monotone**: property sweep `w ∈ [0..2200]`, `o ∈ [1..15]` — non-decreasing in w, bounded `[0, o]`, and `f(w) ≥ 1 ⇔ w ≥ min`.
4. **Amendable thresholds respected**: formula honors `(min, par)` arguments; cross-field validator rejects `min ≥ par` (F-LEG-031 path, rejected with Art. III §6 citation + `rejected=true` chain row).
5. **Applies identically to all three body kinds** (architecture): assert departments-, CGC-, and private-org reconciliation paths all resolve seat counts through `CoDeterminationService::workerSeats` (single-source pin: no second implementation of the formula exists — source scan for the interpolation expression, the TermLockstepTest no-API technique).
6. **Joint chair + validity**: composition change clears `chair_seat_id` and opens a `board_chair_elect` vote; final-round winner below majority-of-all-seated does not seat; invalid board cannot open a non-cure board vote (rejected with Art. III §6 citation) while the cure path stays open.
7. **CLK-13 exit-criterion pin** (feature-level): drive an org from 99 → 100 active `org_workers` via F-IND-014 + countersign → headcount job → CLK-13 fire on the chain → vacant worker seat row + open `org_board_worker` election, with zero R-23 action.

### E.2 `tests/Constitutional/CgcIpPublicDomainTest.php` (replaces `test_cgc_intellectual_property_is_public_domain_forever`)

1. **Schema pins** (live-schema technique from BallotSecrecyTest): CHECK `status='public_domain'` exists; no `updated_at`/`deleted_at` columns; append-only trigger present on UPDATE and DELETE; app role lacks UPDATE/DELETE privileges.
2. **Trigger behavior**: raw `UPDATE cgc_ip_register SET status=…` and `DELETE` both raise (the audit_log tamper-test pattern).
3. **Write-surface architecture pin**: `CgcIpRegisterService` exposes `dedicate()` and reads only (reflection over public methods); the Eloquent model forbids update/delete (overridden `performUpdate`/`delete` throw; asserted); repo-wide source scan: no other writer references the table for UPDATE/DELETE.
4. **Conversion invariance**: complete a `cgc_to_private` conversion → register rows byte-identical before/after; F-LEG-027 `sell` payload carrying any `ip_*`/`reclaim` key is rejected pre-vote with Art. III §5 citation and a `rejected=true` chain entry.
5. **Dedication completeness**: F-LEG-019 chartering and F-LEG-026 completion each produce dedications sealed to public_records + audit chain; `ip_is_public_domain` can never flip false on an `is_cgc` row (model invariant + validator).
6. **Identical-regulation pin** (Art. III §5, cgc-detail "hardened" line): source assertion that org-module services branch on `is_cgc` only in the enumerated places (oversight assignment, IP dedication, conversion/dissolution gating).

### E.3 Work-item breakdown (org scope; sizes S/M/L; deps in parens)

| WI | Size | Contents | Verify |
|---|---|---|---|
| **D-O-1 Schema** | M | Migrations D-O1…D-O7 + models (Organization evolve, OrgMembership, OrgWorker, OrgContract, OrgOwnershipStake, OrgDocumentPackage(+Version), OrgTransfer, OrgConversion, CgcIpRegisterEntry) | migrate fresh-less on live DB; CgcIpPublicDomainTest schema pins green |
| **D-O-2 Board-vote + election linkage** | M (after exec's boards migration) | D-O8; `BoardRoster`; `resolveBody` board branch; `castBoardSeat`; `board_chair_elect` key + `rcv_majority` close gate; VoteTypeRegistryTest update | chair RCV E2E on a seeded board; PegQuorum posture unchanged for legislature/committee |
| **D-O-3 Registry + membership + contracts** | M (D-O-1) | F-IND-012/013/014 + F-ORG-001/007 handlers; OrgRegistryService/OrgMembershipService/OrgContractService; RoleService R-24/25 facts; RecomputeWorkerHeadcountJob | register→join→hire E2E; headcount counter matches active rows; R-24/25 chips derive |
| **D-O-4 Co-determination engine** | M (D-O-3, exec boards) | CoDeterminationService (PROTECTED list + CLAUDE.md update); OrgBoardService::reconcile; CLK-13/14 arming + EvaluateCoDeterminationJob; validity gate in ChamberVoteService; cross-field validator rule | WorkerRepresentationTest green; 100-worker auto-trigger exit criterion |
| **D-O-5 Board elections** | L (D-O-2/3/4) | F-ORG-003/004 handlers; OrgElectorateService; F-IND-011/F-IND-007 electorate branches; rights-automatic scoping (validator + RightsAutomaticTest update, constitutional review); OrgBoardSeatingService + certification dispatch + auto-certify backstop; org_cycle terms + cycle timers; chair trigger | owner STV + worker STV + chair RCV E2E matching the mockup's quota math; uncontested race auto-seats |
| **D-O-6 Transfers + conversions + CGC** | L (D-O-1/3; exec BoG pipeline for governor offers) | F-ORG-005/006 + F-LEG-019/026/027 handlers (ChamberActService proposal kinds `cgc_creation`,`monopoly_acquisition`,`cgc_reorg_sale`); OrgTransferService/OrgConversionService; CgcIpRegisterService; fair-market engine gate | monopoly E2E: finding → majority vote → underpayment rejected on the record → compensation ≥ floor → CGC + founding-governor offers + co-det recheck; CgcIpPublicDomainTest green |
| **D-O-7 Frontend** | L (parallel after D-O-3) | `Pages/Organizations/{Registry,Detail,CgcDetail,BoardElections,CoDetermination,TransfersConversions}.vue` per the six mockup contracts (PageScaffold/FormCard conventions; ThresholdMeter for the co-det meter; stv-* components from the Phase B results page) | mockup-parity walkthrough; scenario states honest-empty |
| **D-O-T Tests** | woven | E.1 + E.2 replace the two placeholders **in the same PRs as the engine code** (FuturePhasePlaceholdersTest discipline); feature tests: membership/worker E2E, mutual-consent rejection, document-package collision, OMOV electorate pins | full constitutional suite green; CI gate |

### E.4 Deferrals (justified)

1. **`is_active`/`is_registered` boolean drop** — kept in sync for existing readers (endorsement handlers, presenters); dropping is a sweep task for the all-phases-done pass. Additive-only rule honored.
2. **Stakes-weighted owner voting** — explicitly decided AGAINST (C.1); q-ledger candidate recorded, revisit only via constitutional draft change.
3. **Full contracts engine** (obligations, payment schedules, breach) — owner post-it "D. Noted for data-structure phase"; D-O3 ships the minimal co-sign + labor-headcount surface only.
4. **`ffc_synced_at` / cross-instance transfer sync** — Phase F (WF-JUR-06); column pre-provisioned because retrofit is cheap, sync is not.
5. **Judicial dissolution path (WF-ORG-10 branch) + org-party case standing** — Phase E (needs cases/verdicts); F-ORG-007 ships voluntary-only with the judicial branch stubbed behind the engine.
6. **Org-internal restructuring rules per structure** (partnership unanimity etc., transfers-conversions.html "internal restructuring") — minimal viable: structure changes via F-ORG-001 with per-structure consent checks stubbed to agent-attestation + document-package citation; full per-structure consent engines deferred to the all-phases pass (no constitutional floor is implicated — these are private-side rules above the floor).
7. **Banking/payment execution for compensation** — owner post-it "C. Tabled"; `compensation` is a recorded constitutional fact (public record + chain), not a funds transfer.
8. **CLK-04-style special-election window for board vacancies** — deliberately NOT applied (C.2): Art. II §5 windows govern public office; board vacancy = countback → fresh class election. Flagged for owner confirmation.

### E.5 Coordination register **[COORD-EXEC]**

| Item | Owner | This section's dependency |
|---|---|---|
| `boards` + `board_seats` (+`cycle_months`, `composition_valid`, `chair_seat_id`) migration | exec designer | D-O1 `board_id` FK, all of B/C |
| `departments.worker_count` + department headcount via shared `org_workers` polymorph | shared (org_workers is mine; departments table is exec's) | B.2 job writes both caches |
| F-EXE-001 → F-LEG-020 (`bog_consent`) appointment pipeline against `boardable=organizations` | exec designer (generalizing `ChamberActService::openCivilTerm`) | CGC governor seating, founding-governor offers |
| D-O8 board-vote widening + `BoardRoster` + `board_chair_elect`/`rcv_majority` | shared infrastructure — single migration/PR, first lander ships it | C.3, exec's policy-proposal board decisions |
| Governor removal (`ordinary majority` — owner ruling #14) | exec designer | board validity treats a removal-created vacancy as pipeline state |

Key files referenced (absolute): `E:\fair-constitution-app\.claude\worktrees\practical-payne-17d537\app\Domain\Forms\FormRegistry.php`, `app\Services\ChamberVoteService.php` (resolveBody:713, cast:210), `app\Services\Legislature\ChamberActService.php` (proposal/consent/civil-term patterns), `app\Services\CertificationService.php` (kind dispatch insertion point: certify:121), `app\Services\ConstitutionalValidator.php` (SETTING_BOUNDS:152, EMERGENCY_ENABLED_FORMS:230, check:237), `app\Services\RoleService.php`, `app\Services\MultiJurisdictionVoteService.php`, `config\constitution\vote_types.php`, `database\migrations\2026_01_01_000003_create_organizations_table.php`, `2026_06_13_000003_evolve_elections_table.php`, `2026_06_13_000004_create_election_races.php`, `2026_06_20_000003_create_chamber_votes_tables.php`, `database\seeders\ClockRegistrySeeder.php` (CLK-13:167), `tests\Constitutional\FuturePhasePlaceholdersTest.php` (placeholders:62,71), mockups `E:\fair-constitution-app\mockups\organizations\*.html` (canonical formula: co-determination.html:154).