I now have everything I need, grounded in real symbols. The dual-door mechanism (`ConstitutionalValidator::DUAL_DOOR_KEYS`, `SettingAmendmentDoorService`, `EnactmentService::applySettingChange`) is real and is the exact lever the treasury doc's monetary keys would join. The design is complete. Let me write the design section.

This is a design round only — I will produce the design as my final response (not write a file, per instructions).

---

# Design: A Player-Controllable Civic Stipend ("thank-you bump") for Operators, Moderators & Office-Holders

## 0. What this is and where it lives

This designs a **small, default differential** paid in the instance's currency to three classes of people who spend electricity / bandwidth / time keeping the mesh and the order running:

1. **Node operators** — the people who run a server/Pi in the Phase G mesh (Track A volunteer mirror operators and Track B co-member-cluster operators). *Not* a constitutional role — I searched `roles_forms_chart.md` and there is **no R-## for "operator" or "moderator"**; "operator" appears only as an instance/deploy concept in `PHASE_G_MASTER_PLAN.md:31` ("operator's call. Authority is earned by population…").
2. **Moderators** — the public-square / halls moderators that arrive in **Phase K** (`CGA_PHASE_G_AND_BEYOND_ROADMAP.md:282-287`, the four-carve-out moderation model). Also not a constitutional role.
3. **Civic office-holders** — these **are** constitutional roles, enumerated in `roles_forms_chart.md`: legislators (R-09), speaker (R-10), committee chairs (R-12), election-board members (R-08), executives (R-14/15/16), Boards of Governors (R-18), judges (R-19/20), advocates (R-21), administrative-office staff (R-29), civil officers (R-30).

**It is NOT designed as a new mechanism.** It is a **new use of the monetary lever** already designed in `treasury-economics.md`. The whole point of the brief — "player base sets the numbers via the constitutional monetary lever (F-LEG-031), never a hardcoded admin value" — is satisfied by making the stipend a set of **new `constitutional_settings` keys, root-scoped, dual-door-gated, written only through `EnactmentService::applySettingChange`**, exactly like the existing `ubi_amount_per_period` / `monetary_issuance_rate_bps` keys. It is **UBI with a role-keyed top-up coefficient**, riding the exact `IssuanceService → public ledger → private receipt` path the treasury doc already specifies.

This slots into **Phase M — Market Economy** (`CGA_PHASE_G_AND_BEYOND_ROADMAP.md:354-386`), as an extension of the UBI section (`treasury-economics.md` §3.C "UBI" + §4.3 "UBI disbursement run"), with **one supporting key class** that must be declared in **Phase L — Public Finance** alongside the other monetary-lever keys (`treasury-economics.md` §3.B). See §6 for the exact placement.

---

## 1. The core design decision: it is a **differential**, not a salary

The brief says "a small default differential … a thank-you bump." That word **differential** is load-bearing and constitutionally necessary. Here is why a *salary* is wrong and a *UBI bump* is right:

- A **salary** for office implies office is **compensated employment**, which collides with the absolute, residency-only right to stand for office (Art. I "Right to Stand for Office", `fair_constitution.md:33-34`) — pay attached to office risks becoming an indirect *qualification* or *inducement*, and risks the Art. II §8 paywall logic in reverse (you must never be able to say "you only get civic standing if you're paid / you must be paid to serve").
- A **differential on top of UBI** says: *everyone who is residency-associated already gets the UBI floor (the absolute baseline); these roles get a small additional coefficient as recognition.* It is structurally a **multiplier/adder on the existing UBI run**, so it inherits UBI's constitutional posture wholesale — `[POLICY]`, residency-gated baseline, public aggregate / private receipt.

**Mechanism (the differential):** the per-period amount a recipient receives is

```
amount = ubi_amount_per_period  +  Σ over the recipient's active eligible roles ( role_stipend_bump[role] )
```

The base term is the existing UBI floor (every residency-associated person). The `role_stipend_bump` term is the new "thank-you" differential. A person who is *both* a node operator *and* a legislator receives the base + the operator bump + the office-holder bump (capped — see §3, R-cap below). Someone with no eligible role gets exactly the existing UBI base — **the differential never reduces anyone's floor; it only adds**.

This is the cleanest fold-in: the treasury doc's `UbiService` (`treasury-economics.md` §4.1) already mints the total, writes one **public** `ubi_disbursements` aggregate row, and writes **private** `ubi_receipts` + `market_transactions(kind='ubi')` per recipient. The differential changes **only the per-recipient `amount` computation inside that run** — no new disbursement path, no new ledger, no new privacy surface.

---

## 2. Player-controllable: the keys and the lever (the heart of the brief)

The numbers are **never hardcoded and never an admin knob**. They live as new keys in `constitutional_settings`, scoped to the **root jurisdiction only** (Art. V §5 currency reservation — verified text at `fair_constitution.md:306-307`), and are changed **only** through the existing **F-LEG-031 → `EnactmentService::applySettingChange`** path, which is the *single* writer of constitutional settings (verified: `app/Services/EnactmentService.php`, and the validator's bound check at `ConstitutionalValidator.php:324`).

### 2.1 New `constitutional_settings` keys (root-scoped, `[POLICY]`)

Added to `ConstitutionalValidator::SETTING_BOUNDS` (founder-set rails) and to `ConstitutionalValidator::DUAL_DOOR_KEYS` (currently `['judiciary_is_elected']`, `ConstitutionalValidator.php:132`):

| Setting key `[POLICY]` | Default | Founder-set rail | Lever for |
|---|---|---|---|
| `civic_stipend_enabled` | `false` | bool | master on/off (off = pure UBI, no differential — the safe default) |
| `stipend_bump_operator` | `0` | `≥ 0`, `≤ stipend_bump_cap` | node-operator thank-you bump per period |
| `stipend_bump_moderator` | `0` | `≥ 0`, `≤ stipend_bump_cap` | moderator thank-you bump per period |
| `stipend_bump_officeholder` | `0` | `≥ 0`, `≤ stipend_bump_cap` | civic-office-holder thank-you bump per period |
| `stipend_bump_cap` | founder | `≥ 0` | hard per-period ceiling on the **sum** of bumps one person can receive (anti-capture rail) |
| `stipend_officeholder_roles` | `[]` | enum-subset of {R-08,R-09,R-10,R-11,R-12,R-14,R-15,R-16,R-18,R-19,R-20,R-29,R-30,…} | **which** office roles qualify (policy choice — see §3) |

These default to **disabled / zero** so that on a fresh instance the stipend does nothing until the root legislature deliberately turns it on by act. That mirrors the treasury doc's "monetary keys have no Template default — founder-authored at setup, amendable only by the root legislature" posture (`treasury-economics.md:286-293`).

### 2.2 Why this satisfies "player-controllable, not an admin value"

- **No admin write path exists.** The codebase already forbids any direct `constitutional_settings` mutation outside an enactment transaction (`treasury-economics.md:533`; the validator throws on any unbounded key, `ConstitutionalValidator.php:324`). A node operator who runs the server **cannot** raise their own bump by editing a config file or hitting an admin endpoint — there is none. They must persuade the **root legislature** to pass an F-LEG-031 act.
- **Dual-door (the anti-self-dealing rail).** Because these keys join `DUAL_DOOR_KEYS`, an F-LEG-031 bill targeting them is *structurally barred* unless it carries `requires_constituent_consent` (verified guard at `ConstitutionalValidator.php:336-342`), routing through `SettingAmendmentDoorService::onDualDoorChamberAdoption` (`app/Services/Judiciary/SettingAmendmentDoorService.php:50`) → the `MultiJurisdictionVote` substrate. So setting these numbers requires **chamber supermajority AND a supermajority of constituent jurisdictions** — the people whose money is being spent. This is the most important rail for a stipend, because the recipients (office-holders) overlap heavily with the deciders (the legislature). Dual-door pulls the decision out to the constituents.
- **Player base sets the numbers over time.** Once on, the bumps are ordinary amendable levers: as the mesh grows or shrinks, as electricity costs change, as the player base decides operators deserve more or less, they pass another F-LEG-031 act. The number is *theirs*, on the public record, with its enacting act attached (every `[POLICY]` value "must show its enacting act", `CGA_PHASE_G_AND_BEYOND_ROADMAP.md:55`).

### 2.3 Currency-agnostic

The bumps are stored as `numeric(20,4)` strings denominated in the instance's `currencies` row (`treasury-economics.md` §3.B), with `unit_kind ∈ {fiat, commodity, social_credit, external_peg}`. The design **bakes in no specific currency** — exactly the Coalition's "dollars … rubles … crypto, it doesn't matter" intent (`treasury-economics.md:96-98`). A `social_credit`-unit instance and a `fiat`-unit instance both express the bump as a number of their own units.

---

## 3. The eligibility map: who counts, and the anti-capture rails

This is where the three role-classes get pinned to real, verifiable facts in the existing schema — **the differential reads derived/queryable state, it does not introduce a new "is this person special" flag.**

### 3.1 Office-holders (constitutional roles — derived, never stored)

The roadmap's memory note is explicit: **derived roles (R-01→R-04) are never stored** (`CLAUDE.md` Phase 1). Office-holding is likewise derivable from live institutional rows. `UbiService` resolves a recipient's eligible office roles at run time:

- R-09 legislator ⇐ active `legislature_members` row (term not ended, not vacant).
- R-18 BoG ⇐ active `board_seats` row, `seat_class='governor'`.
- R-19/R-20 judge ⇐ active judicial seat / `terms` row.
- R-08 election board, R-29 admin staff, R-30 civil officer ⇐ active appointment in the relevant table.
- R-10/R-12/R-14/R-15/R-16 ⇐ their respective seat/role rows.

**Which of these qualify is the `stipend_officeholder_roles` policy key (§2.1).** The Template is **silent** on whether office is compensated at all (I confirmed — no emolument/salary clause exists; the only money-and-office text is fair-market share compensation on monopoly conversion, `fair_constitution.md:193-194`, which is unrelated). So the *set* of paid offices is `[POLICY]`, founder/legislature-authored.

### 3.2 Operators (a Phase G mesh fact — the one genuinely new attestation)

There is **no operator role today**. The cleanest, federation-safe way to recognize an operator without inventing a stored privilege is to tie the bump to a **G-ID-attested, government-acknowledged node-operator declaration**, ratified by the seated government of the jurisdiction the node serves — consistent with Phase G's cardinal rule that **authority is earned by population / granted by the seated government, never self-asserted** (`PHASE_G_MASTER_PLAN.md:31-34`, "authority ≠ leadership", `CGA_PHASE_G_AND_BEYOND_ROADMAP.md:74-77`).

Concretely: a small additive `node_operator_grants` table (root/jurisdiction-scoped, append-on-grant, revocable) recording `{ user_id, server_id, granted_by (a chamber act / BoG ack), status, period }`. The operator bump is paid only while a **non-revoked grant** exists. This keeps the operator stipend **government-validated, not operator-claimed** — a node operator cannot pay themselves merely by spinning up a Pi. *(This is the only new substrate the whole design needs; everything else reuses existing tables.)*

### 3.3 Moderators (a Phase K fact)

Moderators arrive in Phase K (`CGA_PHASE_G_AND_BEYOND_ROADMAP.md:282-287`). Eligibility ⇐ an active moderator assignment in Phase K's social schema (a `social_*` role row, government- or community-appointed per that phase's design). Because Phase K is built *before* Phase M in the critical path (`G→H→I→J→K→L→M→N→O`, `CGA_PHASE_G_AND_BEYOND_ROADMAP.md:498`), the moderator-assignment table already exists by the time this differential ships — **no forward dependency inversion.** Until Phase K, `stipend_bump_moderator` simply has no eligible recipients (paid to nobody), which is harmless.

### 3.4 Anti-capture rails (HARDENED-by-rail, the part that protects the system from itself)

- **`stipend_bump_cap`** caps the **sum** of bumps a single person can receive in one period, regardless of how many roles they stack (operator + legislator + judge). Enforced in `SETTING_BOUNDS` (each bump `≤ cap`) and again at run time in `UbiService` (`min(Σ bumps, cap)`).
- **Bumps are `≥ 0` only** — the differential can never be negative, so it can never be used to *penalize* a role or *withhold* the UBI floor. The base UBI is untouchable.
- **No paywall, ever (Art. II §8, HARDENED, verified text `fair_constitution.md:144-145`).** The stipend is a *payment to* people; it can never become a *payment required of* people. The existing `NO_FEE_FORMS` pin (`treasury-economics.md` §5.2) already forbids attaching a charge to a civic-right form; the stipend adds nothing on the "charge" side. A `CivicStipendTest` pins that no stipend key can be wired as a precondition on any civic-right form, and that disabling/zeroing the stipend never alters anyone's R-01→R-30 eligibility (the iron rule: *no money fact ever gates a governance act* — `CGA_PHASE_G_AND_BEYOND_ROADMAP.md:534-536`).
- **The stipend confers no governance advantage.** Receiving a bump grants no extra vote, no seat, no priority — it is purely a currency transfer on the private ledger. This mirrors the Phase K/I rule "no badge/score/balance affects any governance act" (`CGA_PHASE_G_AND_BEYOND_ROADMAP.md:534-536`). A grep-style pin asserts the stipend writes only `economic_accounts` / `market_transactions` / `ubi_receipts`, never any role/seat/vote table.

---

## 4. Where it lives in the run, and the privacy split

It rides the **existing UBI disbursement run** verbatim (`treasury-economics.md` §4.3 "UBI disbursement run"):

```
ubi_period_days sweep → F-TRE-004 (system actor, systemOnly())
  → UbiService enumerates active residency associations at root (Art. I eligibility — UNCHANGED)
  → for each recipient: amount = ubi_base + min(Σ eligible role bumps, cap)   ← THE ONLY NEW LINE
  → IssuanceService mints the run total (public issuance_event + public ledger_entry)
  → one PUBLIC ubi_disbursements aggregate row
  → PRIVATE ubi_receipts + market_transactions(kind='ubi') per recipient
```

### 4.1 Public-ledger / private-local split (the brief's explicit privacy requirement)

The differential inherits the treasury doc's actor-split (`treasury-economics.md` §5.5) **unchanged** — this is the key privacy claim:

- **Public (`public_ledger` / `public_aggregate`):** the **total** stipend minted this run, the issuance event, the money-supply effect, and — critically — **the policy itself** (the bump amounts and which roles qualify are `constitutional_settings` rows = public law, with the enacting F-LEG-031 act on the public record). *Anyone can audit what the stipend rates are and how much was paid in aggregate.*
- **Private (`private_local`, never federated, like ballots):** **who received a stipend and how much** lives in `ubi_receipts` + `market_transactions`, which `treasury-economics.md` §5.6 already adds to `PublicRecordService::FORBIDDEN_SUBJECT_TYPES` and excludes from every Phase-F export filter. So you **cannot** read the public ledger and learn "person X is a paid moderator" — that linkage stays on the authoritative instance only.

**The one genuine new privacy question** (flagged, not decided): an operator/office-holder bump is more *re-identifying* than a flat UBI, because the eligible set (operators, named office-holders) is small and partly public (office-holders are publicly known; node operators may be). A small jurisdiction with one legislator means "the office-holder bump went to the legislator" is inferable from the public aggregate alone. **Mitigation:** apply the same **k-anonymity floor** Phase I/K already use for reach counts (`CGA_PHASE_G_AND_BEYOND_ROADMAP.md:224, 533`) — if fewer than *k* recipients in a stipend class within a jurisdiction, the per-class aggregate is **suppressed** and folded into the general UBI aggregate, never published separately. This is an extension of an existing pattern, not a new mechanism.

### 4.2 No new forms, no new clocks

- **Forms:** reuses **F-LEG-031** (the lever) + **F-TRE-004** (the UBI run, `systemOnly()`, `treasury-economics.md:415`). The operator-grant act is an ordinary chamber act (or a BoG acknowledgment); the moderator assignment is a Phase K form. **No new F-## is required** for the stipend itself — consistent with the treasury doc keeping the monetary levers form-free (`treasury-economics.md:276-280`).
- **Clocks:** none. Cadence is the existing `ubi_period_days` nightly sweep — "no new CLK codes for ordinary cadence" (`treasury-economics.md` §4.4, the Phase D precedent).

---

## 5. Funding & monetary-loop honesty

The bump is **minted** like the rest of UBI (injection), and the treasury doc's UBI↔inflation loop (`treasury-economics.md:466-475`) already governs the macro side: the root legislature turns `inflation_target_bps` / `monetary_issuance_rate_bps` and now also the three bumps. The app provides **only the governance + transparency machinery** and takes **no position** on whether stipends are inflationary or how big they should be — that is "the job of a legislature and a Judiciary to come together and find a system that works" (`treasury-economics.md:96-101`). The bumps simply become three more dials on the same honest interface (`MonetaryPolicyService` exposes the levers and realized aggregates).

A founder/legislature that prefers a **fee-funded** stipend (operators paid from a dedicated `revenue_stream`/`treasury_account` rather than minting) can wire it that way instead — `UbiService` would draw from a designated account instead of minting. **This funding-source choice is `[POLICY]`** and flagged as an open decision (§7).

---

## 6. (a) Roadmap placement

- **Primary home: Phase M — Market Economy** (`CGA_PHASE_G_AND_BEYOND_ROADMAP.md:354-386`), as an **extension of the UBI mechanism** (`treasury-economics.md` §3.C UBI + §4.3). The per-recipient `amount` computation in `UbiService`, the `node_operator_grants` table, and the `CivicStipendTest` pins land here.
- **Supporting declaration in Phase L — Public Finance** (`CGA_PHASE_G_AND_BEYOND_ROADMAP.md:315-350`): the six new `constitutional_settings` keys (§2.1) join the other monetary-lever keys in `SETTING_BOUNDS` + `DUAL_DOOR_KEYS` when those are introduced (`treasury-economics.md` §3.B), so the lever rails exist before Phase M consumes them.
- **Cross-phase dependencies it already respects:** moderator eligibility consumes **Phase K** (built earlier in the critical path); operator eligibility consumes **Phase G** G-ID + the "authority granted by government" rule (already merged, `main`). No dependency inversion — all upstream phases land first.
- Sub-phase label within Phase M: **M-STIPEND** (a WI after the base UBI run is green), mirroring the A–G "build backend sequentially with tests in-flight" discipline (`CGA_PHASE_G_AND_BEYOND_ROADMAP.md:551-553`).

## 7. (b) Open decisions for the operator

1. **Funding source:** minted (UBI-style injection, default) **vs.** drawn from a dedicated fee-funded `treasury_account` (no new currency). `[POLICY]` — see §5.
2. **Which offices qualify** (`stipend_officeholder_roles`): all of R-08…R-30, or only the high-burden ones (e.g. R-09 legislators, R-18 BoG, R-19/20 judges)? Should *neutral* officers (R-08 election board, R-29 admin staff — constitutionally "politically neutral") be paid, given pay could be argued to compromise neutrality?
3. **Operator-grant authority:** who ratifies a node-operator grant — the seated government by chamber act, the Treasury BoG by acknowledgment, or both? And does an operator serving a *not-yet-activated* (pre-government) jurisdiction qualify at all?
4. **Dual-door vs. ordinary majority:** I recommend dual-door (constituents whose money is spent must consent, anti-self-dealing). Art. V §5 names no threshold; founders may relax to chamber-only supermajority. q-ledger candidate (parallels `treasury-economics.md` open question #2).
5. **k-anonymity floor value** for suppressing small-class stipend aggregates (§4.1) — reuse Phase I/K's *k* or set a stipend-specific floor?
6. **Should the bump be flat per role or scaled** (e.g. by hours served, by node uptime)? Recommend **flat** — uptime/hours metering re-introduces surveillance and complexity the Template's privacy clause (Art. I, `fair_constitution.md:15-16`) disfavors. Flagged.
7. **Caps relative to UBI base:** should `stipend_bump_cap` be an absolute number or a *multiple of `ubi_amount_per_period`* (so the bump auto-scales with the floor and can't dwarf it)? A ratio cap is more capture-resistant.

## 8. (c) Risks

| # | Risk | Severity | Stance |
|---|---|---|---|
| S1 | **Self-dealing** — office-holders vote themselves a large bump. | High | Dual-door (constituent supermajority) + `stipend_bump_cap` rail + everything on the public record with its enacting act. The deciders can't quietly raise their own pay. |
| S2 | **Re-identification** — small eligible sets make the private receipt inferable from the public aggregate. | High | k-anon suppression of small-class aggregates (§4.1); receipts stay `private_local`, never federated (inherits `FORBIDDEN_SUBJECT_TYPES`). |
| S3 | **Pay-as-qualification drift** — stipend silently becoming a condition of/for office (Art. I, Art. II §8). | High | HARDENED: bumps are `≥ 0` add-only, never gate any role; `CivicStipendTest` pins that zeroing/disabling the stipend changes no R-## eligibility. |
| S4 | **Operator buys influence** — paying a node operator could look like buying authority. | High | Operator bump requires a **government-granted** node-operator grant (authority≠leadership, Phase G); a bump confers **zero** governance advantage (grep pin). |
| S5 | **Inflation from minting role bumps.** | Med | Inherits the UBI↔inflation loop and its rails; app takes no macro position; bumps default to **zero/disabled**; cap bounds the injection. |
| S6 | **No Template basis** — office compensation is entirely silent. | Med | Tag the whole mechanism `[POLICY]`; defaults off; never promoted to the hardened layer. |
| S7 | **Federation leak** of "who is a paid operator/mod." | Med | Recipient identity lives only in private-local tables already excluded from all four Phase-F export filters; the *policy* (rates) federates as public law, the *recipients* never do. |
| S8 | **Moderator role not yet built (Phase K).** | Low | Critical path puts K before M; until then `stipend_bump_moderator` pays nobody — harmless no-op. |

---

**Files/symbols this design is grounded in (all read):** `docs/plans/explorations/treasury-economics.md` (§3.B/§3.C/§4.3/§5.5/§5.6 — the UBI + monetary-lever substrate this extends); `docs/extracted/fair_constitution.md:33-34` (Right to Stand), `:144-145` (Art. II §8 no compulsory payment), `:193-194` (only money-and-office text, unrelated), `:306-307` (Art. V §5 currency reserved to root); `docs/extracted/roles_forms_chart.md` (R-08…R-30 office-holders; **confirmed no operator/moderator role exists**); `app/Services/ConstitutionalValidator.php:132` (`DUAL_DOOR_KEYS`), `:324-342` (settings-bound + dual-door guard); `app/Services/Judiciary/SettingAmendmentDoorService.php:50` (`onDualDoorChamberAdoption`); `app/Services/EnactmentService.php` (sole settings writer); `docs/plans/CGA_PHASE_G_AND_BEYOND_ROADMAP.md:74-77, 354-386, 498, 534-536` (authority≠leadership, Phase M home, critical path, no-paywall/no-advantage rails); `docs/plans/institutions/PHASE_G_MASTER_PLAN.md:31-34` (operator = mesh concept, not a role). **No code written — design round only.**