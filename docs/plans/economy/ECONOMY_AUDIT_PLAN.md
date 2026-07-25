# Economy Engine (Phases L + M) — Audit Plan

**Lane 13 · 2026-07-25 · D-14 precursor**
*The plan for the operator's live walkthrough of the economic engine. The design document
(`ECONOMY_ENGINE_PLAN.md`) comes after this walk and is shaped by what we see, not by what the
documents claim.*

Base: `docs/plans/docs-recon/BUILT_INVENTORY.md` §3 (Phases L+M) + `BUILT_INVENTORY_EVIDENCE.md`.
Every claim below was re-verified in source or observed on a running box; where the audit and the
code disagreed, §5 records the resolution.

---

## 0. How to run this walkthrough

It is a **script, not a report**. The operator drives; I record. Rules:

1. **One station at a time.** Each ends with a written finding plus evidence — a screenshot for a UI
   station, pasted command output otherwise. A station is not done until the evidence exists.
   (Standing rule: a fix isn't done without an after-shot. Same discipline, applied to observation.)
2. **Act 1 is READ-ONLY.** No writes, no migrations, nothing that could disturb lane 1's live map
   healing on the game box.
3. **Every "you can't see this" is stated in advance**, not discovered mid-walk. Most of this engine
   is not browser-visible; the method column says so up front.
4. **Nothing is settled by argument.** Two propositions (§1a, S4) are written as procedures with
   expected results. We run them.

**Observation methods**, used throughout:

| Code | Means |
|---|---|
| **U** | A page in the running app |
| **T** | `artisan tinker` / console |
| **D** | A read-only SQL query |
| **X** | Run the pinned test and watch it pass |
| **M** | Static mockup file, opened from disk — not served by the app |

---

## 1. Two findings that outrank the lane

Both were found while auditing the economy and neither is economy-scoped. They go first because they
change what the operator does this week, not what lane 13 designs next month.

### 1a. ⚠ SECURITY — an unauthenticated endpoint rewrites every constitutional setting

`POST /api/setup/constants` (`routes/web.php:98`) is registered **with no `auth` middleware**. Its
three immediate neighbours all are, and the game-mode route states the principle in a comment:

> *"AUTH-gated: flipping to sandbox unlocks the dev toolbox, so it must never be a guest trigger (the
> founder is logged in from createFounder onward); the handler additionally requires is_operator +
> refuses once setup is complete."* — `routes/web.php:100-103`

`SetupController::saveConstants` (`:814-888`) has **neither** protection: no `isSetupComplete()`
refusal (compare `:198`, `:222`, `:300`, `:333`, `:2635`, which all carry one) and no `is_operator`
check. It calls `writeConstitutionalSettings` (`:979-1001`), a raw `DB::table()` upsert that
**UPDATEs** an existing row, bypassing both the model and `ConstitutionalValidator`.
`RedirectIfSetupIncomplete` cannot backstop it — self-documented as *"Once setup completes, this is a
no-op"* (`:20`), and it returns early for anything that is not a GET+HTML navigation (`:56`).

**The write persists post-founding.** `saveConstants` takes its `if ($root)` branch once
`resolveRootJurisdiction()` finds Earth (`SetupController.php:868-877`, resolver at `:3047-3057`), so
after map data exists a save lands on the live root row and `ConstitutionalDefaults::flush()` makes it
effective immediately (`:882`).

**Blast radius — all 29 keys, not the 9 economy ones.** `saveConstants` validates and writes the whole
constitutional set (`:816-851`), with only four hand-rolled invariants at `:854-865` standing in for
`SETTING_BOUNDS`. That includes:
- **`judiciary_is_elected`** — the sole `DUAL_DOOR_KEYS` member. This path flips it with no chamber
  supermajority and no constituent consent, defeating `ConstitutionalValidator:345-355`,
  `BillService:360-372` and `SettingAmendmentDoorService` in one POST.
- **`worker_rep_min_employees` / `worker_rep_parity_employees`** — because `RederiveClockTimersJob`
  never fires on this path, armed CLK-13/14 timers **desync from the settings row**.
- `supermajority_numerator/denominator`, `emergency_powers_max_days`, `legislature_min/max_seats` — all
  governed keys whose bounds and citations this path never consults.

The rewrite produces no `setting_changes` row, no audit-chain entry, and no enacting law. The only
residual barrier is the `web` group's CSRF token, which is not authentication.

**⚑ And the same controller proves it is a defect, not a design choice.** `saveGameMode` — the very
next endpoint — *is* `auth`-gated at the route and *does* refuse once setup completes
(`SetupController.php:2635-2637`, 409 *"Game mode is set at founding and locked once setup is
complete"*). The guard exists here, in this file, and was simply not applied to the endpoint that
writes the constitution. Demonstrating both side by side is the cleanest way to show it.

**⚑ Compounding risk.** On success the endpoint returns `next: '/setup/step/2'` (`:886`) and the page
navigates there (`Step1_Constants.vue:244`) — step 2 being the **Map Data** page, which is likewise
re-enterable (`step()` has no completion check either, `:97-169`). Re-entering step 1 on a founded
world leaves the operator one click from a live ETL submit form.

**Routing.** Not lane 13's to fix. → **@operator**, **@lane-02** (launch security gate; the cloud
instance is public 2026-09-01), **@lane-07** for `BUILT_INVENTORY` §7.

**Verification — dev box only, and LAST, because it mutates the fixture.** Never against the game box.

*Pre-state capture:*
```bash
docker exec fcd_postgres psql -U fc_user -d fair_constitution -c "select judiciary_is_elected, worker_rep_min_employees, currency_code, civic_stipend_floor, last_amended_by_act_id, last_amended_at from constitutional_settings;"
docker exec fcd_postgres psql -U fc_user -d fair_constitution -c "select (select count(*) from setting_changes) as changes, (select max(seq) from audit_log) as max_seq;"
```

| Test | Action | Expected if the reading is right |
|---|---|---|
| **A** | Navigate to `/setup/step/1` on the founded fixture | The wizard renders, **pre-filled from the live row** (not template defaults). Screenshot. |
| **B** | Change two values with distinctive signatures — `currency_code` → `AUDIT`, `civic_stipend_floor` → `4242` — and Save | Both change; `setting_changes` count **unchanged**; `max(seq)` **unchanged**; `last_amended_by_act_id` still NULL. **That triple is the finding.** |
| **C** | ⚠ **Operator authorisation required, recorded before running.** Flip `judiciary_is_elected` | It flips, with no `MultiJurisdictionVote`, no `setting_changes` row, no audit entry — against what `Art4Section5Test:566-605` pins. |
| **D** | From a session with **no login**: GET any page for the `XSRF-TOKEN` cookie, then POST the payload with `X-XSRF-TOKEN` | A 200 and a write ⇒ the only gate is CSRF. A 419 narrows the finding but does not remove it. |
| **E** | *Control.* On the same page, toggle game mode | **409.** Proves the lock exists in this controller and was simply not applied to `saveConstants`. |

Then `docker exec fcd_app php artisan audit:verify` — it will pass, because nothing was appended.
That is the point.

**Record the trace, the citations and the reproduction; leave the severity call to the operator.**

**It also falsifies the surviving L/M design doc**, which argues its anti-self-dealing case from
*"No admin write path exists… A node operator who runs the server **cannot** raise their own bump by
editing a config file or hitting an admin endpoint — there is none"*
(`docs/plans/phase-g-continuation/LM-fiscal-civic-stipend.md:63`).

### 1b. ⚠ CHARTER CONFLICT — "never federated" was superseded, and the lane-13 brief still carries it

The roadmap's Phase M hard rail, and the lane-13 opening brief, both state:

> individual balances / transactions / receipts are **private-local, never federated**
> (`FORBIDDEN_SUBJECT_TYPES` += `market_transaction`, `ubi_receipt`, individual `economic_account`)

The v2 mockup fixtures said the same. **The operator superseded it:**

> *"⚑ **SUPERSEDED by the operator: the 'never-federated wallet rail.'** One consistent game world:
> **everything syncs between nodes** — wallet state included."*
> — `docs/plans/mockups-v3-wiring/WIRING_PLAN.md:158-160`

The v3 fixtures were rewritten to match (`mockups/v3/assets/js/fixtures-econ.js:16-19`): economic data
*"syncs between nodes like all data"*, and privacy becomes **reader privacy, like a ballot** — readable
only by its owner. The helper `neverFederated()` was renamed `privacyNote()`.

**These are two architectures, not two wordings.** Non-federation is an **export filter** — a table
never leaves the node. Reader privacy is an **encryption / access-control** problem — the row travels
and must be unreadable in transit and at rest on the peer. The second is substantially harder and
touches the federation layer, not just `PublicRecordService`.

Nothing in the market layer can be designed until this is settled, and per the fleet's standing rule a
later source never silently reverses a settled ruling. → **@operator**, as the walkthrough's first
decision, with both sources quoted.

---

## 2. Station 0 — the fixture

**Runtime as observed 2026-07-25** (read-only `docker ps` + SQL):

| Box | Prefix / port | State |
|---|---|---|
| **Game box** | `fc_*` · :8080 · pg :5432 | 956,336 jurisdictions · 951,622 `constitutional_settings` rows · 1 user · `setup_completed_at` **NULL** · 10/10 containers healthy |
| **Dev box** | `fcd_*` · :8082 · pg :5434 | Virgin — 0 users / 0 jurisdictions / 0 settings rows · `clocks` **0** · `audit_log` 35 · 10/10 containers healthy |

Earth root on the game box: jurisdiction `42157394-9380-4036-b6b9-19be56b84996`,
legislature `952c4b7f-e855-4632-8782-346d1e4cba79`.

### 2.1 The setup lock decides what Act 1 can show

Because `setup_completed_at` is NULL on the game box, `RedirectIfSetupIncomplete` is **active**, and
its allow-list (`:34`) admits only:

```
setup · operator · legislatures · jurisdictions · federation · login · logout · register
```

Every other page 302s to `/setup`. So on the game box we **can** reach Setup Step 1 and
`/legislatures/{id}/settings`, and we **cannot** reach `/organizations`, `/executives/{id}/actions`,
`/system/amendments`, `/civic/record`, or `/journeys`. That is not a defect — it is the founding lock
doing its job — but it splits the walkthrough in two.

### 2.2 Act 1 — game box, read-only

Covers stations **S1, S2, S4, S9, S10, S11, S12** plus every SQL proof. No writes of any kind.
Lane 1's ADM healing wave is live and self-driving on this box (board, 10:58) — we do not touch it.

### 2.3 Act 2 — dev box, optional, operator-authorised at the walkthrough

Covers **S3** (the §1a verification), **S5, S6, S7, S8** — the UI-behaviour stations the setup lock
hides. Requires founding the virgin dev box, which is the only part needing the fleet's one-lane
`migrate` slot (announce on the board first).

**State the yield honestly before spending the time:** there is **no economy demo seeder** anywhere in
the 56-command menu — nothing seeds a wallet, listing, agreement, ledger row or stipend run.
`institutions:demo-d` + `/executive/actions` is the only live money that can ever appear on screen,
and it appears as an *empty state* (S5). Act 2 buys the behaviour stations and the §1a proof; it does
not buy a working economy, because there isn't one.

⚑ **The same fixture is what lane 6 needs.** Their parity tour plans to walk :8080 and :8082, but the
game box is setup-locked and the dev box is virgin — neither renders the app's real pages today. If
Act 2 runs, it should be built once and offered to lane 6 on the board.

### 2.4 ⚠ Three hazards — read before touching anything

1. **`docs/FRESH-NODE-START.md:16` says `docker compose -p fc down -v`.** On this checkout `-p fc` is
   the **game box** — the accepted planet, and lane 1's live work surface. `-p` selects a project by
   name at the daemon level, so it does **not** matter which directory you run it from: that line
   verbatim **destroys 956,336 jurisdictions**. Use `-p fcd`, or bare (the repo `.env:153` pins
   `COMPOSE_PROJECT_NAME=fcd`, so a bare `docker compose` from `E:\fair-constitution-app` already
   targets the dev box). *The runbook line belongs to lane 2's path — flagged, not edited here.*

   **Reassurance, so the rest of the walk is unencumbered:** the two stacks are **independent clones**,
   not worktrees — `fcd_app`'s working dir is `E:\fair-constitution-app`, `fc_nginx`'s is
   `C:\Users\Joseph Sileo\fair-constitution-app`. Nothing done in this checkout (code, ETL control
   files, database) can reach :8080. The only shared resource is `D:/fair-constitution-map-files`,
   mounted **read-only** at `/archive` in both. So `-p` is the single hazard, and it is the only one.
2. **`psql -U postgres` fails** — the role is `fc_user` (`.env:110`). Every query in this document
   uses `-U fc_user -d fair_constitution`.
3. **`ClockRegistrySeeder` must be run explicitly** after `migrate` — the 21-clock registry does not
   ride the schema dump (`clocks` = 0 on the dev box right now), and bare `php artisan db:seed` only
   creates a `Test User` (`database/seeders/DatabaseSeeder.php:18-22`). Always pass `--class=`.

### 2.5 Act 2 bring-up — the ordered procedure

**There is no synthetic-map path** — `saveCosmicAddress` hard-rejects anything but `physical_earth`
(`SetupController.php:739-743`). **But there is a scoped one:** `startMapData` accepts `countries[]`
(ISO3) and `adm_levels[]` (`SetupController.php:1071-1074`), forwarded to the ETL as
`--countries` / `--adm-levels` (`scripts/etl/supervisor.py:178-186`). So the fixture is a
**one-country import**, and the country is **forced, not chosen**: `PhaseDDemoCommand.php:113`
hardcodes `SAN_MARINO_SLUG = 'smr-1-san-marino'`, which is what the SMR **ADM0** file produces
(`import_geoboundaries.py:74` maps ADM0 → `adm_level=1`; `:155-177` builds `{iso}-{adm_level}-{name}`).
SMR ADM0 (36 KB), ADM1 (55 KB, 9 castelli) and the WorldPop raster (49 KB) are all staged in the
archive, which both stacks mount read-only at `/archive`.

Use `docker exec fcd_app` with the literal container name throughout.

| # | Step | Command / action | Verify | Est. |
|---|---|---|---|---|
| 0 | Pre-flight | `docker ps --filter name=fcd_` | 10 up, `fcd_app` healthy | 1 m |
| 1 | **Clock registry** | `docker exec fcd_app php artisan db:seed --class=ClockRegistrySeeder --force` | `select count(*) from clocks;` → **21** | 10 s |
| 2 | Fork + founder | `http://localhost:8082/setup` → **START (solo)** → create founder + establish founding roles | 1 user, `is_operator=t`; `setup_mode='solo'` | 3 m |
| 3 | Step 0 | `/setup/step/0` — name it, pick **Earth**, time mode `real` | `setup_step_completed=1` | 2 m |
| 4 | **Step 1 = station S1** | `/setup/step/1` — ⚠ **set game mode = `sandbox` FIRST** (below), then record every economy default verbatim, then Save | `setup_step_completed=2` | 10 m |
| 5 | Step 2 — scoped ETL | `/setup/step/2` — source **archive**, Fresh on, **countries `SMR`**, **adm levels `0,1`**, population on | `select adm_level, count(*) from jurisdictions group by 1;` → Earth 1 · San Marino 1 · castelli 9 | **10–30 m (estimate)** |
| 6 | Accept maps | Step 2 "Accept Map Data & Continue" | `map_accepted_at` stamped; `apportionment_completed_at` fills | **5–20 m (estimate)** |
| 7 | Steps 3 + 4 | Step 3 "Back to Setup" → Step 4 **Finish Setup** | `setup_completed_at` stamped; `/civic` stops 302-ing | 3 m |
| 8 | **Seat a chamber** | `docker exec fcd_app php artisan elections:demo smr-1-san-marino --voters=120 --candidates=60 --instant` | certified races + seated members | 5–15 m |
| 9 | Phase D substrate | `docker exec fcd_app php artisan institutions:demo-d` | org crosses 100 workers, `worker_seats` 0→1, CGC + its stake | 2–5 m |
| 10 | Chain integrity | `docker exec fcd_app php artisan audit:verify` | green | 30 s |

**~1–2 hours, most of it steps 5–6.** Migrations are already applied on fcd (13, `pending_count: 0`),
so `migrate` is a no-op. The ETL and autoscale durations are **estimates, not measurements** — replace
them with the real numbers after the first run rather than treating them as promises.

**Three ordering constraints that are load-bearing, not stylistic:**

1. **⚠ `game_mode = sandbox` must be set at step 4 (Step 1) — it is a ONE-WAY DOOR.**
   `saveGameMode` returns 409 once setup completes (`SetupController.php:2635-2637`), and
   `DevToolsEnabled` 404s the entire `/dev/*` toolbox unless sandbox is on
   (`app/Http/Middleware/DevToolsEnabled.php:33-40`). Miss it and `/dev/executive-kit` (S7) and
   `/dev/users` impersonation are unavailable **for the life of this world**.
2. **`elections:demo` must run before `institutions:demo-d`** — the latter fails hard if the chamber
   has ≤ 5 serving members (`PhaseDDemoCommand.php:194-203`). If `elections:demo` refuses, it prints
   the exact minimum voters/candidates it needs (`ElectionsDemoCommand.php:584-593`): read the number
   and re-run rather than guessing.
3. **Queue.** `institutions:demo-d` forces `queue.default = sync` in-process
   (`PhaseDDemoCommand.php:151-154`), so step 9 needs no worker. Horizon **is** required for the
   UI-driven path — `OrgMembershipService.php:298-299` dispatches the recompute `afterCommit()` — which
   makes "add a worker in the browser → the seat appears" the observation that actually proves the
   queue. `fcd_horizon` is already running; `/horizon` shows the `default` supervisor tick.

### 2.6 Two traps that will mislead an operator mid-walk

- **`curl` gives a false pass.** The setup lock keys on `wantsHtml`
  (`RedirectIfSetupIncomplete.php:54-58`), so a bare `curl http://localhost:8082/organizations`
  **without** `Accept: text/html` slips through and looks like the page works. Always send the header:
  `curl -H "Accept: text/html" …` returns the honest `302 /setup`.
- **`WorkerRepresentationTest` fails on a virgin box, and that is not a regression.** Its six pure-math
  pins always run, but the two live pins assert on a real jurisdiction row and the clock registry —
  they need Station 0 to have happened. Run S6's **X** step only after step 9.

---

## 3. Two maps of the same territory

### 3.1 The concordance — one feature set, five naming systems

The single most common error in this area is reading these as different features. They are not.

| Concept | Roadmap §L/§M | Live settings layer | LM design doc | Mockups (v3) | Journeys registry |
|---|---|---|---|---|---|
| Basic income | `ubi_disbursements` / `ubi_receipts` | `civic_stipend_floor`, `stipend_interval` | `ubi_amount_per_period` ⚠ symbol absent | `stipend.html` | `stipend-and-tax` *(planned)* |
| Role top-ups | — | `pay_node_operator`, `pay_social_moderator`, `pay_office_holder`, `stipend_bump_cap` | `stipend_bump_operator/moderator/officeholder` ⚠ **name conflict** | `stipend.html` | — |
| Money of account | `currencies`, `issuance_events` | `currency_name` / `_code` / `_symbol` (Civic Value Unit · CVU · ç) | `currencies.unit_kind` ⚠ **assumes absent table** | `units.html`, `exchange.html` ⚠ **unregistered** | — |
| Public finance | `treasury_accounts`, `budgets`, `ledger_entries` | — | `IssuanceService`, `MonetaryPolicyService` ⚠ absent | `treasury.html`, `joint-ledgers.html` | `budget` *(planned)* |
| Mutual aid | `assistance_requests` | — | — | `marketplace.html?tab=requests` | `mutual-aid` *(not economy-shaped)* |
| Contracts | `org_contracts(labor_recurring\|commercial)` | — | — | `agreements.html` + redline model | — |
| Personal balance | `economic_accounts`, `market_transactions` | — | ⚠ **§1b: federated or not?** | `wallet.html` | — |

### 3.2 The fifteen currency operations, against what exists

`mockups/v3/CONSTITUTION-CURRENCY-OPS.md:156-180` enumerates every economic operation the Template
authorises, with citations. It is the closest thing in-repo to an L/M specification (§5, E13), and it
is the right spine for the walk: the substrate view says what *code* exists, this says what a *citizen
could do*. The gap between them is the lane's scope.

| # | Operation | Cite | Today | Station |
|---|---|---|---|---|
| 1 | Define the currency (root) | Art. V §5 | **PARTIAL** — name/code/symbol collected at setup, no `currencies` table | S1 |
| 2 | Set monetary policy (root, dual-door) | Art. V §5 | **ABSENT** — keys exist but are off the rails | S2 |
| 3 | Run the economic clock | — | **ABSENT** — no `ubi_period_days`, no F-TRE-004 | S1 |
| 4 | Disburse the civic stipend | `[POLICY]` | **ABSENT** — dials only, zero readers | S1 |
| 5 | Raise revenue (levies, fees) | Art. V §4 / II §2 | **ABSENT** | — |
| 6 | Borrow on credit | Art. V §4 | **ABSENT** | — |
| 7 | Enact a budget → appropriations → disburse | Art. III §4 | **SUBSTRATE** — tables + service exist, unreachable | S5 |
| 8 | Keep the public ledger | — | **ABSENT** — no `LedgerService`; the hash-chain it would reuse exists | S5 |
| 9 | Trade on the Open Market | Art. I / III §5 | **ABSENT** — "the Open Market" is a constitutional term, used twice | S11 |
| 10 | Form agreements | Art. I | **PARTIAL** — `labor_recurring` only | S6, S8 |
| 11 | Hold & move joint ledgers | Art. V §2 | **ABSENT** | S11 |
| 12 | Manage shares, fair-market, conversion | Art. III §5 | **BROKEN** — conversion built, cap table unfillable | S7 |
| 13 | Collect dues / file taxes | Art. II §8 | **ABSENT** | S9 |
| 14 | Co-determination via labor contract | Art. III §6 | **BUILT + PINNED** | S6 |
| 15 | Cross-jurisdiction trade & IP oversight | Art. V §4-5 / III §5 | **ABSENT** — ⚑ Art. V §5's trade-oversight clause is not cited by roadmap §M | — |

---

## 4. The stations

### 4.0 Ordering is forced by the fixture's lifecycle, not by editorial taste

Three observations are **destroyed** by advancing the world, and cannot be recovered on that world:

| When | What is only visible then | Why it is lost |
|---|---|---|
| **During Step 1, before Save** | The economy panel in its *founding* state, and the wizard's own claim that these values *"cascade to child jurisdictions"* (`Step1_Constants.vue:707`) | Not lost from the page, but this is the only moment the operator is authoring them rather than reading them back — and it is the exhibit for S4 |
| **During Step 1, before Finish** | The `game_mode` production/sandbox toggle | 409s forever once setup completes (`SetupController.php:2635-2637`) — **one-way door**, and sandbox gates `/dev/*` for the life of the world |
| **After Finish, before any demo command** | The genuine empty-state baseline: `/organizations`, `/organizations/co-determination`, `/organizations/transfers-conversions`, `/executives/{id}/actions` with **zero** rows | `institutions:demo-d` populates them permanently; this is what a real fresh world shows, and it is the evidence for the *absence* claims |

So the running order is: **S11 and S9 first** (source, tests and mockups — they need no fixture and give
the operator something real to walk while the ETL runs) → **S1** at Step 1 → the empty-state baseline →
**S2, S4, S10, S12** → **S5, S6, S7, S8** after the demo commands → **S3 last**, because it mutates the
fixture.

### S1 — The stipend / currency parameter layer · **U + D**

**What it does today.** Setup Step 1 (`resources/js/Pages/Setup/Step1_Constants.vue`) heads its own
section *"Constitution & Economy Defaults"* and tells the founder *"You are founding the constitution
and its economy now"* (`:259-262`). The economy block (`:703-843`) collects nine values, every one
`required` server-side (`app/Http/Controllers/SetupController.php:842-850`):

| Field | Default | Help text |
|---|---|---|
| Currency name / code / symbol | Civic Value Unit / CVU / ç | *"Shown on wallets and the exchange."* |
| Civic stipend — residency floor | 50 | **"Everyone with active residency receives this."** |
| Stipend bump cap (max stacked) | 20 | *"The most the role differentials can add."* |
| Pay: node operators / social moderators / civic office-holders | 8 / 5 / 12 | *"Civic-duty pay for…"* |
| Stipend interval | monthly | *"How often the economic clock pays out."* |

They persist to `constitutional_settings` via the post-baseline migration
`database/migrations/2026_07_05_000001_setup_wizard_v2.php:39-57` and the PROTECTED
`app/Models/ConstitutionalSettings.php:41-51`.

**⚑ Nothing reads them.** An exhaustive repo sweep finds the nine keys in exactly five places: the
migration, the model, `SetupController`, `Step1_Constants.vue`, and two static mockup fixture bundles.
No service, job, clock, controller or non-setup page consumes any of them. Also confirmed absent:
`UbiService`, `IssuanceService`, `MonetaryPolicyService`, `F-TRE-004`, `ubi_period_days` — every symbol
the LM design doc calls "existing."

**⚑ The mockups' levers map 1:1 onto these columns** (`fixtures-econ.js:55-61`). The settings exist;
only the runtime that spends them does not.

**⚑ The cap is decorative.** Validation sets `min:0` and no maximum, and there is no cross-field rule —
nothing enforces `pay_* ≤ stipend_bump_cap` despite the field being labelled "max stacked."

**How to see it live.**
- **U · game box:** `http://localhost:8080/setup/step/1` → scroll to "Economy defaults". Real values,
  pre-filled from the actual root row. *This is the walkthrough's opening screen* — the product asks
  every founder to found an economy, and then ignores the answer.
- **D:** `docker exec fc_postgres psql -U fc_user -d fair_constitution -c "select currency_name, currency_code, currency_symbol, civic_stipend_floor, stipend_bump_cap, pay_node_operator, pay_social_moderator, pay_office_holder, stipend_interval from constitutional_settings where jurisdiction_id='42157394-9380-4036-b6b9-19be56b84996';"`

**Gaps.** The disbursement engine, the `currencies` table, the economic clock, the receipts plane —
everything downstream of the dials.

**Decisions.** (1) Do the shipped key names win over the design doc's (§5)? Recommendation: **yes** —
they are in the database and on screen. (2) Does the cap become a real cross-field rail? (3) Should
Step 1 stop promising a stipend until one exists, or is the promise acceptable as a statement of intent?

---

### S2 — The settings rails: why the dials cannot be amended · **U + T**

**What it does today.** Four independent facts compound:

1. **Not bounded** — the nine keys are absent from `ConstitutionalValidator::SETTING_BOUNDS`
   (22 keys, `:169-211`).
2. **Not doored** — `DUAL_DOOR_KEYS = ['judiciary_is_elected']` only (`:132`).
3. **Not visible** — the post-setup register renders a fixed 17-key list
   (`app/Http/Controllers/Legislature/SettingsController.php:38-56`), none monetary.
4. **Not amendable** — `checkSettingChange` (`:332-337`) rejects any key absent from `SETTING_BOUNDS`:
   *"[civic_stipend_floor] is not an amendable constitutional setting"*, citation Art. VII.

So no legislature can change a monetary value by act, and no screen displays one after setup — while
`units.html` and `stipend.html` both promise exactly that two-door change.

**⚑ Putting them on the rails is three coordinated edits plus a pin, not a config tweak.**
`SETTING_BOUNDS` is pinned by an exact ordered `assertSame` (`tests/Constitutional/SettingsBoundsTest.php:46-72`)
under its own comment — *"Growing this list requires constitutional review; shrinking it never
happens"* — and every bounded key must carry a non-empty `citation` plus `allowed` or `min`/`max`
(`:75-84`). Adding to `DUAL_DOOR_KEYS` **alone is inert**: the bounds check at `:332` runs before the
door check at `:345`. And the bill-drafting dropdown reads `REGISTER_KEYS`, not `SETTING_BOUNDS`
(`app/Http/Controllers/Legislature/BillController.php:376`). ⇒ bounds + door + register + pin.

**How to see it live.**
- **U · game box:** `http://localhost:8080/legislatures/952c4b7f-e855-4632-8782-346d1e4cba79/settings`
  — the 17-key register. Money keys visibly absent. This is the gap, on screen.
- **T:** file an F-LEG-031 payload with `setting_key: 'civic_stipend_floor'` and watch the
  `ConstitutionalViolation` come back with the Art. VII citation.

**Gaps.** No monetary key is governable.

**Decisions.** (1) Which of the nine become amendable? (2) Which are dual-door — recommendation:
**all monetary ones**, since the deciders overlap the recipients. (3) **What citation does a `[POLICY]`
key carry when the Template is silent?** The pin requires a non-empty citation; currency keys can cite
Art. V §5, but the stipend keys have no Template basis at all. This is a q-ledger question, not an
engineering one.

---

### S3 — ⚠ The unguarded write path · **U + D**, dev box only, **run LAST**

Covered in full at §1a, including the pre-state capture and Tests A–E. Act 2 only, dev box only, never
the game box — and it is the **final** station because Tests B and C mutate the fixture. Test C needs
the operator's authorisation recorded in the log before it runs.

---

### S4 — The settings cascade that isn't · **D**

**What it does today.** The ETL inserts one `constitutional_settings` row per jurisdiction, all columns
taking DB defaults (`scripts/etl/db.py:185-189`, called from `import_geoboundaries.py:452,607,1709`).
Because the economy columns are `NOT NULL DEFAULT`, and `SettingsResolver::resolve()` (`:47-67`) walks
ancestors with `WHERE cs.{column} IS NOT NULL ORDER BY c.depth LIMIT 1`, the resolver **always matches
the child's own row first**. The root's value can never propagate.

Step 1's own copy says the opposite: *"these cascade to child jurisdictions just like the
constitutional defaults above"* (`Step1_Constants.vue:707-708`).

**⚑ This affects every NOT-NULL settings column, not just economy** — it is the broadest finding in the
sweep and the reason this station exists despite being outside the eight named systems.

**How to see it live — the proof, on real data.**
```bash
docker exec fc_postgres psql -U fc_user -d fair_constitution -c "select count(*) as rows, currency_code, civic_stipend_floor, stipend_bump_cap, pay_node_operator, stipend_interval from constitutional_settings group by 2,3,4,5,6;"
```
**Observed:** a single group — **951,622 rows, all `CVU | 50 | 20 | 8 | monthly`.** Every child row
carries a non-null value, so the ancestor walk can never reach the root. The values happen to be
identical today only because the founder kept the defaults; the *mechanism* is dead either way.

**Gaps.** Inheritance is unreachable for every NOT-NULL column.

**Decisions.** (1) Make the economy columns nullable so the resolver can walk (schema change), or
(2) have the ETL stop writing child rows, or (3) accept per-jurisdiction values and fix the Step 1 copy.
Recommendation: **(1)** — it restores the documented behaviour and matches how the nullable settings
already work. Flagged as affecting more than this lane.

---

### S5 — The grants / appropriations stub · **U + T**

**What it does today.** `app/Services/Executive/GrantService.php` (note: `Services/Executive/`, not
`Services/Organizations/`) is complete and correct arithmetic with `FOR UPDATE` guards, audit chaining
and public-record publishing. Of its methods:

| Method | Callers | Verdict |
|---|---|---|
| `apply` | `ExecutiveActionController.php:185` via `routes/web.php:677-678` | routed |
| `createAppropriation` · `award` · `decline` · `disburse` | **none, repo-wide** | **dead code** |

Zero tests reference `Appropriation`, `GrantApplication`, or `GrantDisbursement`. The tables exist
(`pgsql-schema.sql:398`, `:2335`, `:2356`) and are permanently empty, because **nothing in the
application can create an appropriation row**.

**⚑ The card cannot show anything, by construction.** `resources/js/Pages/Executive/Actions.vue:427`
renders `<Card title="Grants & appropriations">`; the appropriations table (`:434-451`) *and* the whole
applications table + apply form (`:457-528`) are wrapped in `v-if="appropriations.length"`. With zero
rows the operator sees exactly one thing — the fallback banner at `:452-455`: *"The legislature has
appropriated no funds — appropriation is an act."* No seeder changes this;
`institutions:demo-d` creates no appropriations.

**⚑ Two further defects.** `can.administerGrants` is computed (`ExecutiveActionController.php:105`) and
consumed by no control. And grant application **bypasses the form engine entirely** — an explicit
comment at `:176-180`: *"There is no F-* form for an application."*

**How to see it live.** **U · dev box, Act 2:** `/executives/{uuid}/actions` → the banner, and nothing
else. **T:** confirm by insertion — hand-insert one `appropriations` row and watch the whole section
appear; that is the clearest demonstration that the UI is finished and the write path is missing.

**Gaps.** Appropriation creation; award/decline/disburse UX; individual (non-org) applicants; the
budget object that would spawn appropriations (F-LEG-038).

**Decisions.** (1) **Sequencing of the cheap win** — wiring `createAppropriation`/`award`/`disburse`
(D-20 #1) is the lane's least-expensive real progress and is a Phase-D defect fix, not L/M scope. Does
it ride the launch window or wait for the L/M build slot? → @lane-02. (2) Does grant application become
a real form? Recommendation: **yes** — a money action outside the engine has no constitutional
validation and no form-level audit identity.

---

### S6 — The labor → co-determination chain · **U + X**

**What it does today.** The one station that simply works, end to end, and is constitutionally pinned:

```
OrgDetail "Join as a worker" (F-IND-014)
  → OrgMembershipService::registerWorker  (org_contracts kind=labor_recurring, status=offered,
                                           worker-side signed at filing; org_workers status=applied)
  → F-ORG-001 countersign_contract  (POST /contracts/{id}/cosign)
  → both signatures present → contract active, workers active, R-25 flushed
  → afterCommit RecomputeWorkerHeadcountJob (ShouldBeUnique)
  → CoDeterminationService::recompute  [PROTECTED, Art. III §6]
  → CLK-13 / CLK-14 → OrgBoardService::reconcile → worker board election opens
```

Pinned by `tests/Constitutional/WorkerRepresentationTest.php` — 8 tests, including `:250-317` which
drives the real engine path and asserts the 100th worker trips the first seat.

**How to see it live.** **U · dev box, Act 2**, after `institutions:demo-d`:
`/organizations/{uuid}` (worker form + co-sign button) → `/organizations/co-determination`
→ `/organizations/{uuid}/board-elections`. **X:** `php artisan test --filter=WorkerRepresentationTest`
(**only after Act 2 step 9** — see §2.6; the two live pins need a real jurisdiction row and the clock
registry, so on a virgin box they fail as a fixture artifact, not a regression)
— watching the pin pass is faster and more convincing than clicking it.
**Prerequisite:** a queue worker must be running or the chain stalls after the co-sign.

**Gaps.** Only the **posting / application / discovery board** — the two tables and the form in front of
F-IND-014. Everything behind it is done.

**Decisions.** (1) Confirm the labor board is a front door onto this chain and never a bypass —
recommendation: **hard rail, pinned by test**. (2) Does a marketplace hire use the same path? (Yes, or
Art. III §6 becomes optional.)

---

### S7 — Org ownership, stakes, transfers, conversions · **U + D**

**What it does today.** Substantial Phase-D machinery: `org_ownership_stakes` (cap table),
`org_transfers` (mutual-consent CHECK), `org_conversions` (`fair_market_floor ≤ compensation` CHECK),
services `OrgOwnershipService` / `OrgTransferService` / `OrgConversionService`, forms F-ORG-005/006/007,
and the page `resources/js/Pages/Organizations/TransfersConversions.vue`.

**⚑ But an ordinary organization's cap table can never be populated.** `OrgOwnershipService::openStake`
has exactly three callers — CGC charter, conversion, and transfer completion —
and `OrgRegistryService::register` opens **none**. Precisely:

- `acquired_via='founding'` has **exactly one writer**: `CgcService.php:147-153`, which seats the
  jurisdiction at 100% when a CGC is chartered ("the jurisdiction stands where shareholders would").
  So `institutions:demo-d` does produce one stake row — the CGC's.
- `acquired_via='issue'` has **no writer anywhere**.
- **An organization registered through the UI (F-IND-012) gets no stake at all**, so its ownership
  panel is permanently empty, and there is no share-issuance surface anywhere in the app.

This is constitutionally load-bearing, not cosmetic. `CONSTITUTION-CURRENCY-OPS.md:56,61-65` observes
that **the only place the Template ties money to ownership is shares** — Art. III §5's guarantee that
shareholders are paid *at least fair market price* on monopoly conversion. The conversion path's
`fair_market_floor` guard therefore operates on a table nothing can fill.

**⚑ Two further absences.** Organization **type** conversion (business ↔ nonprofit ↔ party) does not
exist — the only axis built is private ↔ CGC via the `is_cgc` flag. And `equal_partnership` is a
validated label with no behaviour distinguishing it from `partnership`.

**How to see it live.** **U · dev box, Act 2:** `/organizations/{uuid}` → the Ownership panel, empty
for any UI-registered org. **U:** `/dev/executive-kit` renders the *populated* component from fixtures —
useful for showing what it is supposed to look like without seeding one (**requires sandbox game mode**
— see §2.5's one-way door). **D:** `select acquired_via, count(*) from org_ownership_stakes where ended_at is null group by 1;`
— after `institutions:demo-d`, expect exactly **one row, `founding`**, held by the jurisdiction against
the demo CGC. Contrast it with the demo's ordinary 100-worker org, which has none.

**Gaps.** Founding cap table; share issuance; valuation; org-type conversion.

**Decisions.** (1) Does org registration open a founding stake? Recommendation: **yes** — Art. III §5
presumes shares exist. (2) Is share issuance L/M scope or a Phase-D completion? (3) Does
`equal_partnership` acquire real forced-equal behaviour, or get retired to a label?

---

### S8 — Commercial `org_contracts` · **D**

**What it does today.** The schema is ready and the write path is not.
`org_contracts.kind` CHECK (`pgsql-schema.sql:3560`) allows `labor_recurring | labor_single |
commercial | other`; the cosign constraint (`:3558`) enforces that no contract reaches `active` without
both signatures. **The single `OrgContract::create()` call in the entire codebase**
(`OrgMembershipService.php:175`) is hardcoded to `labor_recurring`. `commercial` exists as a CHECK
value plus a `match` arm in a read-side label helper (`OrganizationController.php:607`) that can never
be reached.

**How to see it live.** **D:**
`select kind, count(*) from org_contracts group by 1;` — and read the CHECK constraint beside it. This
station is a two-minute SQL stop; there is no UI to visit.

**Gaps.** The creation path, the counterparty model for non-user counterparties, and the marketplace
surface that would produce one.

**Decisions.** (1) Is `commercial` the marketplace's contract primitive (recommendation: **yes** — the
cosign gate and the constraint already model "both parties agreed"), or does the market get its own
`marketplace_orders` object with contracts reserved for negotiated agreements? The v3 mockup
`agreement-detail.html` implies the former plus a **redline/negotiation model** (`negotiate-v2.js`) —
*the same interface a bill uses*.

---

### S9 — The Art. II §8 fee rail · **T + X**

**⚠ CORRECTION to the lane-13 brief and to master §3.0.** Both describe `fee`/`payment_required` in
`FORBIDDEN_ELIGIBILITY_KEYS` as *"an Art. II §8 proto-rail"* that `NO_FEE_FORMS` would *generalize*.
The code does not support that description:

- The constant (`ConstitutionalValidator.php:140-154`, **private**, 13 keys) is enforced by
  `guardAutomaticRights()` (`:1461-1479`), which **returns immediately** unless the form is one of the
  six in `RIGHTS_AUTOMATIC_FORMS` (`:109-120`): F-IND-003, F-IND-005, F-IND-006, F-IND-011, F-ELB-002,
  F-IND-016.
- It inspects **top-level payload keys only** — a nested `payload['meta']['fee']` is not caught even on
  those six.
- It rejects citing **`Art. I`** — *"may never carry eligibility conditions beyond jurisdictional
  association"* — not Art. II §8. **No code path anywhere cites Art. II §8 for the paywall clause.**
- ⚑ The key list has **no test pin** (unlike `SETTING_BOUNDS` and `RIGHTS_AUTOMATIC_FORMS`), so it can
  be silently shrunk.

⇒ It is a rights-automatic **eligibility** guard that happens to catch two fee-shaped keys. Outside
those six forms — **102 of 108** — no fee guard exists at all. `NO_FEE_FORMS` would be **the first**
no-fee rail, not a generalization of an existing one.

The no-paywall *doctrine* is real and live, but as **copy**: `Judiciary/JurorView.vue:232-236`
(*"No fees, ever."* + the Art. II §8 citation), `ConstitutionalChallenge.vue:110,195`,
`Civic/Home.vue:304`, `IdentityVerification.vue:84`.

**How to see it live.** **X:** `php artisan test --filter=Art4Section5Test` — `:88-97` is the only live
exercise of a `fee` key, and the expected citation in the assertion is `Art. I`.
**U · game box:** the doctrine copy is on `/judiciary/*`, which the setup lock blocks — read it in
source, or defer to Act 2.

**Gaps.** Everything except six forms. Verbatim clause, for the record
(`docs/extracted/fair_constitution.md:144-145`): *"Individuals cannot be compelled to pay taxes, fees,
liens, or costs to exercise their Civic Rights and Obligations."*

**Decisions.** (1) Does `NO_FEE_FORMS` cover **all** civic-right forms, or all forms period?
Recommendation: **a deny-by-default rail over every form**, since a levy attached anywhere is the
failure mode. (2) Deep-key inspection, not top-level only. (3) Add the missing test pin. (4) Should the
guard cite Art. II §8 where the clause actually lives? → @lane-07 for the addendum correction.

---

### S10 — The dual-door monetary machinery · **U + X**

**What it does today.** Genuinely live and multi-service:

- **Door 1** — `BillService::basisFor` (`:358-372`) force-upgrades a setting-change bill on a
  `DUAL_DOOR_KEYS` key to `BASIS_SUPERMAJORITY`.
- **Pre-filing bar** — `ConstitutionalValidator:345-355` rejects an F-LEG-031 filing on a dual-door key
  that lacks `requires_constituent_consent`, citing Art. IV §3.
- **Door 2a** — `EnactmentService::applySettingChange` (`:384-402`) detours to
  `SettingAmendmentDoorService::onDualDoorChamberAdoption`, which opens a MultiJurisdictionVote.
- **Sole mutation point** — `applySettingChangeForKey` (`:412-481`): TOCTOU re-check, `forceFill` with
  provenance, an append-only `setting_changes` row, audit chaining, resolver flush, clock re-derivation.
- **Public register** — `/system/amendments`.

⚑ Its docblock names `AmendmentDualDoorTest` as its pin — **that test does not exist**. The real pin is
`tests/Constitutional/Art4Section5Test.php:566-605`.

**How to see it live.** **X:** `php artisan test --filter=Art4Section5Test` — the door-one-alone
rejection and the flagged pass are both in `:566-605`. **U:** `/system/amendments` is setup-locked on
the game box; it is an Act 2 stop.

**Gaps.** No monetary key is on this machinery (S2). The mechanism is not the missing piece — the
configuration is.

**Decisions.** Deferred to S2. Plus: fix or remove the dangling pin reference.

---

### S11 — The mockup contract and the live nav seam · **M + U**

**What it does today.** `mockups/v3/economy/` holds 14 finished pages, all booting the shared shell and
rendering from one data spine, `mockups/v3/assets/js/fixtures-econ.js` (15 entity shapes: currency,
monetaryKeys, economicClock, stipend, accounts, publicLedger, jointLedgers, wallet, marketplace,
requests, agreements, treasury, stock, dues/taxes, exchange). Every page shows *"Planned — a preview…
Nothing here is live yet, and no real money is anywhere."* They occupy **tour stops 67–79**.

**⚑ v3 is a substantive revision of v2, not a restyle.** All 14 files differ. `requests.html` became a
**434-byte redirect stub** folded into `marketplace.html?tab=requests`; the economy tour grew from
7 stops to 13; a plain-language pass stripped constitutional citations and form IDs from user-facing
copy (`Art. V §5` → plain words, `bps` → `%`, `R-09, R-18…` → "legislators, governors, judges"); and v3
added real affordances — a wallet transfer composer, a stipend "Propose a change" action, marketplace
tabs, `?create=1` / `?draft=` modes, and the agreement redline model. **It also carries §1b.**

**⚑ The live registry under-covers the contract by five surfaces.** `resources/js/registry/surfaces.js`
declares a tier-1 `market` entry (`:31`) and a "Market · planned" section (`:109-114`) with four items —
marketplace, wallet, stipend, treasury — all `href: null, phase: 8`, rendered by
`Components/ShellV2/MenuNav.vue:43-54` as a greyed *"Planned · Phase 8"* that the tour skips. The
mockup nav has **nine**. **exchange, agreements, joint-ledgers, units and org-settings have no registry
row at all** — they cannot render even as "Planned," and the coverage instrument cannot diff them.

**How to see it live.** **M:** open `mockups/v3/tour.html` from disk (the mockups are **not** served by
the app — no `public/mockups`, no alias) and walk stops 67–79. **U · game box:** the sidebar shows
*"Market · Planned · Phase 8"* greyed; expand "All screens" for the four-item section.

**Gaps.** Five unregistered surfaces; no backend for any of the fourteen.

**Decisions.** (1) Register the missing five — recommendation: **yes**, as a Phase-8 prerequisite, and
it is a lane-6 coordination item. (2) Does the design target the v3 contract verbatim? Recommendation:
**yes** — it is the most complete statement of intent that exists, and it is the operator's own.
(3) §1b, which lives in these fixtures.

---

### S12 — The planned journey arcs and the locked wallet tab · **U**

**What it does today.** `config/cga/journeys.php` holds 13 arcs, 10 live and **3 planned** — and the
planned three are the economy's:

| Slug | Title | Steps | Phase |
|---|---|---|---|
| `budget` | Enacting a budget | Revenue → Budget bill → Appropriations → Disbursement → Ledger | L |
| `stipend-and-tax` | The money between a person and their government | Stipend run → Your receipt → Tax filing → Public ledger | L/M |
| `mutual-aid` | Asking for and giving help | Post request → A neighbor responds → Coordinate → Resolved | M |

⚑ **`mutual-aid` is not economy-shaped** — `cls: 'people'`, no monetary step. Two are fiscal; the third
is a neighbour-help arc that happens to sit in Phase M. Worth not conflating.

They behave honestly: fully readable, badged "Planned", **no CTA** (*"never a locked door"* —
`Journeys.vue:9`), detail pages return 200 with an info banner, and `JourneyService.php:141` refuses to
mark a step server-side. Likewise `Civic/MyRecord.vue:636-655` ships a **Wallet tab** carrying a
planned banner, a `Planned · Phase 8` badge, and *"Private — like a ballot, only you can read it."*

⚑ The `budget` arc's five steps are a **1:1 match** to `treasury.html`'s budget cycle — the roadmap
already named the missing rail, in the product, twice.

**How to see it live.** **U · dev box, Act 2:** `/journeys`, then `/journeys/budget`,
`/journeys/stipend-and-tax`, `/journeys/mutual-aid`, and `/civic/record?tab=wallet`.

**Gaps.** The engines behind all four surfaces.

**Decisions.** (1) Do these four flip to live as L/M lands (they are the natural exit criteria)?
(2) Does `mutual-aid` stay in M or move? (3) The wallet tab's copy is the clearest statement of the §1b
question — *"only you can read it"* is reader privacy, not non-federation. It should be treated as
evidence of the operator's intent.

---

## 5. Contradiction register

Where the documents and the code disagree, resolved so no later reader re-opens them.

| # | Contradiction | Resolution |
|---|---|---|
| C1 | `BUILT_INVENTORY §2` claim 3: parameter layer *"live end-to-end"* vs `_EVIDENCE:264`: `grep currency` in `pgsql-schema.sql` → *"no matches"* | **Both true.** The columns arrived in the post-baseline migration `2026_07_05_000001_setup_wizard_v2.php`; a schema-dump grep cannot see them. |
| C2 | `LM-fiscal-civic-stipend.md:50-57` proposes `stipend_bump_operator/moderator/officeholder` and a `currencies.unit_kind` | **The shipped columns win** — `pay_node_operator/pay_social_moderator/pay_office_holder`; no `currencies` table exists. The doc was written without knowledge of setup-wizard v2. |
| C3 | Step 1: *"these cascade to child jurisdictions"* vs `SettingsResolver` | **The copy is wrong** — S4, proven on 951,622 rows. |
| C4 | Brief + master §3.0: *"Art. II §8 fee proto-rail"* | **Miscited** — the guard covers 6 of 108 forms, top-level keys only, and cites Art. I. S9. → @lane-07 |
| C5 | `DUAL_DOOR_KEYS` docblock names `AmendmentDualDoorTest` | **That test does not exist**; the pin is `Art4Section5Test:566-605`. |
| C6 | Roadmap `:316`/`:355` attribute L and M to `treasury-economics.md` §8 | **The file was never added in any commit on any branch.** Nothing to recover; per the operator, proceed without it. ⇒ §L/§M are **unfounded pending fresh derivation**, not a distillation. |
| C7 | *"never federated"* (brief, roadmap §M, v2 fixtures) vs *"everything syncs between nodes"* (`WIRING_PLAN.md:158-160`, v3 fixtures) | **UNRESOLVED — §1b.** The operator's ruling is the later source, but the brief still carries the older rail. Blocking decision. |
| C8 | Master §3.0 calls `FORBIDDEN_SUBJECT_TYPES` the federation privacy rail with *"four Phase-F export filters"* | **Overstated** — it guards one write path, throws `InvalidArgumentException` (a 500, no citation), is not exact-pinned, and its own comment calls it *"a tripwire."* The real export filters are table-name allow/deny lists. → @lane-07 |

---

## 6. Decision register

### Constitutional — q-ledger candidates, the operator's to settle

| # | Question | My recommendation |
|---|---|---|
| Q1 | **§1b — do individual economic records federate (reader-private) or never leave the node?** | Blocking. The operator's own later ruling says they sync; the wallet mockup's *"only you can read it"* agrees. But it is the harder build, and it must be an explicit ruling, not an inference. |
| Q2 | What **citation** does a `[POLICY]` setting key carry, when the pin requires one and the Template is silent? | A `[POLICY]` marker plus the nearest enabling clause (Art. V §5 for currency, Art. II §9 Treasury for the stipend), never a bare invention. |
| Q3 | Are **market transactions public or private**? Roadmap §L marks this DERIVED from Art. II §2 vs Art. I — i.e. the Template does not say. | Private like ballots, with public aggregates. Subsumed by Q1. |
| Q4 | Should the **no-fee rail** cover all forms or only civic-right forms? | All forms, deny-by-default. |
| Q5 | **`age_of_majority` / `age_of_consent`** (D-09) — confirmed absent everywhere. | Add as bounded settings, and pin that neither can ever gate voting or standing for office (the Template exempts both from any age condition). |
| Q6 | The 7 open items already at `phase-g-continuation/DECISIONS.md:65-70` (funding source, which offices qualify, operator-grant authority, dual-door vs majority, k-anon floor, flat vs scaled, cap absolute vs ratio) | Carried forward unchanged; this plan **extends** that register rather than forking one. Consolidation into `docs/plans/economy/` is the operator's call — that path is not lane 13's. |

### Engineering — mine to settle under the standing auto-fix ruling, recorded for visibility

E1 shipped key names beat the design doc's · E2 the cap becomes a real cross-field rail · E3 grant
application becomes a form · E4 org registration opens a founding stake · E5 `commercial` is the
market's contract primitive · E6 the missing five surfaces get registry rows · E7 the fee-guard test
pin gets written · E8 the dangling `AmendmentDualDoorTest` reference is fixed or removed.

---

## 7. Handoff to `ECONOMY_ENGINE_PLAN.md` (D-14)

| Finding | Feeds |
|---|---|
| S1 · dials live, inert, 1:1 with the mockups | (a) fiscal layer — the currency/stipend key set is **already chosen**; design adopts, does not mint |
| S2 · rails, and the three-edit cost | (c) hard rails as pins — bounds + door + register + pin extension |
| S4 · non-cascade | Schema decision, flagged beyond this lane |
| S5 · grants half-built | (a) budgets → **existing** appropriations rows; **finish, don't invent** |
| S6 · chain built + pinned | (b) labor board = a front door, never a bypass |
| S7 · cap table unfillable | (b) + Art. III §5 shares; a Phase-D completion inside L/M |
| S8 · `commercial` schema-only | (b) marketplace contract primitive |
| S9 · fee rail is 6/108 | (c) `NO_FEE_FORMS` is **the first** rail, not a generalization |
| S11 · v3 contract + 5 unregistered | (b) the UI contract to design to; a lane-6 item |
| S12 · three planned arcs | The exit criteria, already written into the product |
| §1b | **Blocks the whole (b) market layer** until ruled |
| C6 | §L/§M unfounded — the design derives fresh from `CONSTITUTION-CURRENCY-OPS.md` + the mockup contract |

**Form plan carried forward** (all IDs verified free; families end at LEG-036 / IND-017 / ORG-007, and
no `F-TRE-*` exists): F-LEG-037 Revenue · F-LEG-038 Budget · F-LEG-039 Borrowing · F-LEG-040 Currency ·
F-TRE-001..003 (R-18 / Board of Governors) · F-TRE-004 UBI Run (`systemOnly()`) · F-IND-018..023 ·
F-ORG-008 Org Market Participation (**confirmed lane 13's**, board ruling 2026-07-25 12:38).

**App_Flows dispositions (D-08), corrected by the audit** — carried into the design, not decided here:
grants = **half-built** (finish); fund **distribution** exists (appropriations-by-act) so only
donation-intake fundraising is absent (dispose of that half); asset registration = **truly absent**
(fold in or retire, with reasoning).

---

*Lane 13 owns `docs/plans/economy/`. No code, no migrations until the operator settles the plan; the
build slot is post-launch per the standing work order.*
