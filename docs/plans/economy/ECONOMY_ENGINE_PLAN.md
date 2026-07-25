# Economy Engine — Phases L + M — Design

**Lane 13 · D-14 · 2026-07-25**
*The build plan for public finance and the market economy, as one unit.
Audit basis: [`ECONOMY_AUDIT_PLAN.md`](ECONOMY_AUDIT_PLAN.md) (`0bd9a63`, `b459881`).*

---

## 0. The four rulings this is built on

| # | Ruling (operator, 2026-07-25) | Consequence |
|---|---|---|
| **R1** | **Economic records sync between nodes; privacy is *reader* privacy, like a ballot.** | One consistent world. The privacy mechanism is pseudonymous accounts + a restricted owner-binding — **not** an export filter. §3. |
| **R2** | **Funding is a founder dial: minted *or* drawn from treasury.** | Both engines ship. `stipend_funding_source` joins the founding settings. §4.6. |
| **R3** | **Everything — all 14 screens.** | Full L+M surface, sequenced but not trimmed. §11. |
| **R4** | **Skip the walkthrough; design now.** | This document supersedes the walkthrough as the next gate. |

R1 **closes the §1b conflict** in the audit plan: the roadmap's "never federated" rail and the
`FORBIDDEN_SUBJECT_TYPES` additions it prescribed are **superseded**. Recorded in §6 so no later
reader re-opens it.

Two audit findings are load-bearing here and are treated as scope, not footnotes: the money dials
already exist and are inert (§4.1), and the grants half of the budget cycle already exists and is
unreachable (§4.4). **We complete two things and build the rest.**

---

## 1. The architecture on one page

```
                    ledger_entries  (append-only · double-entry · hash-chained)
                            ▲   LedgerService is the ONLY writer
                            │
        ┌───────────────────┼────────────────────┬─────────────────────┐
        │                   │                    │                     │
   treasury_accounts   economic_accounts    issuance_events      appropriations
   (public: jurisdiction   (pseudonymous:      (mint/burn,        (EXISTS — Phase D;
    + department)           user + org)         root only)        budget now feeds it)
        │                   │                                          │
        │                   ├── wallet · transfers · market orders     │
        │                   ├── stipend receipts                       │
        │                   └── joint ledgers (co-owned, N-of-M)       │
        │                                                              │
   revenue_streams → levies → tax_filings          budgets → budget_lines
   borrowings                                       (enactment spawns appropriations)
```

**One spine.** Every value movement in the app is a `ledger_entries` pair. Nothing debits or credits
anything except through `LedgerService`. That single constraint is what makes the public ledger
audit-complete and what lets `Σdebits = Σcredits per currency` be a test rather than a hope.

**Two account families, one table shape.** `treasury_accounts` are public by construction (a
jurisdiction's or department's money is public business, Art. III §4). `economic_accounts` are
pseudonymous (§3). Both are ledger endpoints; only their *readability* differs.

---

## 2. Constitutional grounding, and what is `[POLICY]`

Source: `mockups/v3/CONSTITUTION-CURRENCY-OPS.md` — the clause-by-clause sweep, and the only
surviving spec-grade document for this phase (the `treasury-economics.md` the roadmap cites was
never committed; audit §5 C6).

| Operation | Grounding |
|---|---|
| Currency production, regulation, worth, measurement standards | **Art. V §5** — reserved to the most encompassing jurisdiction. HARDENED: issuer must be root. |
| Oversight of extra-jurisdictional trade | **Art. V §5** — the encompassing jurisdiction oversees cross-jurisdiction trade. *(Roadmap §M does not cite this; it is the grounding for market oversight above the local level.)* |
| Taxes, fees, borrowing, IP, infrastructure | **Art. V §4** — Joint Powers |
| Shared & indivisible resources | **Art. V §2** — the joint-ledger basis |
| Treasury departments manage capital and finances | **Art. II §9** |
| Population records apportion taxes | **Art. II §2** |
| Boards of Governors execute charters and disburse | **Art. III §4** |
| Shares, fair-market compensation, the Open Market, CGC parity, public-domain IP | **Art. III §5** — the *only* place the Template ties money to ownership |
| Work councils: contracted work → worker board seats | **Art. III §6** |
| **No compulsory payment for civic rights** | **Art. II §8** — HARDENED, §6.1 |
| Economic freedom, free movement of capital/goods, freedom to contract | **Art. I** |

**`[POLICY]` — the Template is silent, so these are the player base's to set, never ours:**
the civic stipend itself · compensation for office · member dues · the disbursement cadence · tax
bases and rates · inflation targets · whether a marketplace exists at all. The app ships the
**governance and transparency machinery** and takes **no economic position**. Every `[POLICY]`
number is an amendable variable on the public record with its enacting act attached.

---

## 3. Reader privacy — the architecture R1 forces

### 3.1 The problem, stated honestly

R1 says every economic row syncs. But `BallotCrypto`'s own docblock is candid about the limit of the
existing crypto posture: at-rest encryption under a KEK derived from the app key gives
*"confidentiality against DB exfiltration, **NOT** against the server operator (who holds the app
key)."* A peer node holds **its own** app key. So "encrypt the wallet and ship it" either makes the
row unreadable to the peer that must validate spends against it, or readable to that peer's
operator. Encryption alone does not deliver R1.

### 3.2 The mechanism: pseudonymous accounts, restricted binding

Take the ballot analogy literally, because it already works in this codebase: **the ballot travels;
the identity linkage does not.**

- `economic_accounts.id` is an opaque UUID. **Every** ledger entry, market transaction, order and
  receipt references **account IDs, never user IDs.** That entire graph syncs in plaintext, so any
  node can verify the double-entry sums and any peer can validate a spend.
- The **binding** — which person or organization owns which account — lives in
  `economic_account_bindings`, a separate table, and is the only restricted object in the economy.
- A binding is readable by: the owner, and the **authoritative instance** for that owner's residency
  (which must resolve it to run a stipend, enforce eligibility, and answer judicial process).
  It is **not** readable by other nodes' operators: the binding row replicates with its payload
  sealed under a key the authoritative instance holds, and peers store it opaquely.

**What this guarantees:** reading the world's ledger — from any node — tells you what moved and
between which accounts, and tells you **nothing about who**. Public aggregates are exact and
auditable; individual identity is not derivable from them.

**What it does not guarantee, stated plainly (the BallotCrypto discipline):** the authoritative
instance for a person *can* bind their accounts — it has to. So the guarantee is *"no other node
learns who owns an account,"* not *"nobody can."* UI copy must say exactly that and never more.

### 3.3 The three rails that fall out

1. **No user_id on any economic row** except `economic_account_bindings`. Pinned by a schema test
   that greps the economy tables for `user_id`.
2. **k-anonymity on aggregates.** Where a per-class aggregate would have fewer than *k* members in a
   jurisdiction (the stipend classes are the sharp case — a jurisdiction with one office-holder), the
   class is suppressed and folded into the general total. Reuses the Phase-I/K k-anon posture.
3. **Public aggregate, private line.** A stipend run publishes recipients-count and total minted;
   the per-person receipt is an account-scoped row. Both sync; only the binding is restricted.

---

## 4. Phase L — public finance

### 4.1 Complete the money dials (they exist and are inert)

Nine columns already ship on `constitutional_settings` (`2026_07_05_000001_setup_wizard_v2.php:39-57`)
and **nothing reads them**. We do not mint new names — **the shipped names win** over the older
design doc's `stipend_bump_*` vocabulary (audit §5 C2).

Work:
- Add the nine (plus the new ones below) to `ConstitutionalValidator::SETTING_BOUNDS` with bounds and
  citations, to `DUAL_DOOR_KEYS`, and to `SettingsController::REGISTER_KEYS`. **All three**, plus
  extending the exact-ordered pin in `SettingsBoundsTest:46-72` — adding to `DUAL_DOOR_KEYS` alone is
  inert, because the bounds check precedes the door check (audit §S2).
- **Citation convention for `[POLICY]` keys** (the question the pin forces): currency keys cite
  `Art. V §5`; stipend keys cite `Art. II §9 · [POLICY]` — the Treasury clause that authorises
  managing the money, with the policy marker making clear the Template mandates no such payment.
- Add a cross-field rail: `Σ pay_* ≤ stipend_bump_cap` — today the cap is decorative (audit §E4).

New keys: `stipend_funding_source` (R2), `stipend_enabled` (**default TRUE — operator ruling
2026-07-25: the stipend is always on, for all players**; a legislature can turn it off by act, but a
fresh world pays from day one), `issuance_rate_bps`, `inflation_target_bps`, `stipend_period_days`.

### 4.2 The public ledger

```
treasury_accounts   id · owner_type(jurisdictions|departments) · owner_id · currency_id
                    · balance numeric(24,6) · public bool default true
ledger_entries      id · seq bigserial · entry_group uuid · account_type · account_id
                    · currency_id · direction(debit|credit) · amount numeric(24,6)
                    · kind · ref_type · ref_id · prev_hash · hash · created_at
```

- **Append-only at the DB level**, reusing the existing trigger pattern
  (`audit_log_block_mutation` / `public_records_block_mutation`) — a new
  `ledger_entries_immutable` BEFORE DELETE OR UPDATE trigger.
- **Hash-chained** exactly as `audit_log` is: `hash = sha256(prev_hash ‖ canonical(row))`.
  `audit:verify` gains a ledger arm.
- **`LedgerService` is the only writer.** Enforced the way the CGC register is: a source-scan test
  asserting no file outside `LedgerService` + the model names `ledger_entries`.
- **Balanced by construction:** `LedgerService::post(array $legs)` accepts a balanced set or throws.
  `Σdebits = Σcredits per entry_group per currency` is a constitutional pin, not a report.

### 4.3 Currency — root-reserved (Art. V §5, HARDENED)

```
currencies       id · jurisdiction_id (MUST be root) · name · code · symbol · precision
                 · unit_kind(fiat|commodity|social_credit|external_peg) · worth_basis
                 · subdivisions jsonb · created_by_act_id
issuance_events  id · currency_id · direction(mint|burn) · amount · reason · act_id
                 · ledger_entry_group · created_at    [append-only]
```

The founding `currency_name/code/symbol` dials become the root's first `currencies` row at setup.
**Hardened rail:** `issuer_jurisdiction_id` must be the root jurisdiction; a non-root currency is
rejected pre-commit citing Art. V §5. Pinned.

### 4.4 Budgets — completing the Phase-D stub, not replacing it

`appropriations`, `grant_applications`, `grant_disbursements` and `GrantService` all exist; only
`apply()` is reachable, and **nothing in the app can create an appropriation** (audit §S5).

```
budgets       id · jurisdiction_id · legislature_id · fiscal_label · status(draft|enacted|closed)
              · enacting_act_id · total numeric(24,6)
budget_lines  id · budget_id · line · amount · department_id nullable
```

**F-LEG-038 enactment creates the existing `appropriations` rows in the same transaction** — the
Phase-D table becomes the budget's execution substrate exactly as chartered. Then:
- Wire `GrantService::createAppropriation` (called by the budget enactment), `award`, `decline`,
  `disburse` to routes and to the executive UI. **This closes D-20 #1.**
- Every award and disbursement posts to the ledger.
- Add the tests the service has never had (`award ≤ remaining`, `Σ disbursements ≤ award`).
- Grant application becomes a real form (**F-IND-021**) instead of a bare service call — today it
  bypasses the constitutional engine entirely.

### 4.5 Revenue, levies, filings, borrowing

```
revenue_streams  id · jurisdiction_id · name · kind(levy|fee|transfer|other) · enacting_act_id
levies           id · revenue_stream_id · base(income|transaction|property|per_capita|custom)
                 · rate numeric(9,6) · civic_exempt bool default true · enacting_act_id
tax_filings      id · account_id (NOT user_id) · period · declared numeric(24,6)
                 · assessed numeric(24,6) · status · ledger_entry_group
borrowings       id · jurisdiction_id · lender_account_id · principal · terms · status
                 · enacting_act_id
```

`civic_exempt` defaults **true** and is the schema-level echo of Art. II §8: a levy cannot attach to
the exercise of a civic right. Filings are account-scoped, so they inherit §3's privacy without a
special case.

### 4.6 The stipend engine — both funding paths (R2)

**F-TRE-004**, `systemOnly()`, fired by the `stipend_period_days` sweep:

```
1. eligibility  = active residency association ONLY  (the same absolute gate as voting)
   — no age condition, no balance, no badge, no fee.  Always on (stipend_enabled = true).
2. amount       = civic_stipend_floor
                + min( Σ pay_* for the recipient's active roles , stipend_bump_cap )
   the three shipped dials ARE the class set (operator ruling): every node operator,
   every social moderator, and EVERY civic role-holder qualifies — no role subset,
   no per-role table. An office is an office.
3. funding:
     minted        → IssuanceService::mint(total) → issuance_event + ledger credit
     treasury_draw → debit the designated treasury_account
                     └─ insufficient funds → PRO-RATA SHORT-PAY, published as such.
                        Never a silent skip, never a partial-population run.
4. post one ledger entry_group; write ubi_disbursements (public aggregate)
   + ubi_receipts (account-scoped)
5. k-anon suppression on per-class aggregates
```

Eligibility reads active residency and **nothing else** — no balance, no badge, no age, no fee.
A pinned test asserts that zeroing or disabling the stipend changes no R-01…R-30 eligibility.

---

## 5. Phase M — the market

### 5.1 Accounts and transfers

```
economic_accounts          id · kind(user|organization) · currency_id · balance · status
economic_account_bindings  id · account_id · owner_type · owner_id · sealed_payload
                           [the ONLY table binding an account to a person — §3]
market_transactions        id · from_account_id · to_account_id · currency_id · amount
                           · kind(transfer|order|stipend|levy|dues|grant) · memo_sealed
                           · ledger_entry_group
```

**F-IND-023** Funds Transfer. Every transfer is a ledger post; the wallet is a *view* of the ledger,
never a second source of truth.

### 5.2 Labor board — a front door onto a built chain

The hire → co-determination chain is **built and pinned** (`WorkerRepresentationTest:250-317`).
We add only the discovery layer in front of it:

```
work_postings     id · organization_id · title · terms · rate · status · form_ref
work_applications id · posting_id · applicant_account_id · status
```

**Acceptance files F-IND-014**, which is the existing path — draft `labor_recurring` contract →
countersign → CLK-13/14 recompute. **Hard rail: a hire can never reach an org's headcount except
through F-IND-014.** Pinned by a test asserting no other writer touches `org_workers`.

### 5.3 Marketplace and the exchange

```
marketplace_listings id · seller_account_id · kind(good|service) · asset_id nullable → §5.4
                     · title · description · qty · price · currency_id · status
                     · cgc bool (derived)
marketplace_orders   id · listing_id · buyer_account_id · qty · price · status
                     · contract_id · ledger_entry_group
```

An accepted order creates an **`org_contracts` row of kind `commercial`** — the schema and its
two-signature cosign constraint already exist with no writer (audit §S8). Settlement posts to the
ledger. CGC listings carry a badge and trade on **identical terms** (Art. III §5).

**The exchange trades organization shares**, not goods — and that exposes a dependency the audit
found: `acquired_via='founding'` has exactly one writer (CGC charter), so an ordinary organization's
cap table is empty and there is nothing to trade. **Therefore in scope here:** organization
registration opens a founding stake, and share issuance (`acquired_via='issue'`) gets a writer.
Fair-market valuation feeds the Art. III §5 monopoly-conversion floor that already exists.

### 5.4 Assets — the thing being traded (operator ruling)

A marketplace listing must point at a **thing that exists**, not at free text. The registry is the
inventory model: an asset has its own identity, attributes and history, it is owned, and it moves.

```
assets           id · owner_account_id · kind(physical|virtual) · name · description
                 · attributes jsonb        ← open schema: dimensions, materials, stats, rarity…
                 · quantity numeric        ← fungible stacks (10 planks) vs unique (this blanket)
                 · origin(crafted|imported|issued|split) · created_by_account_id
                 · jurisdiction_id nullable ← where a PHYSICAL thing is; null for virtual
                 · status(held|listed|escrow|transferred|retired) · deleted_at
asset_transfers  id · asset_id · from_account_id · to_account_id · quantity
                 · order_id nullable · contract_id nullable · at   [append-only]
```

**Design notes.**
- **`attributes` is deliberately open.** A blanket carries materials and dimensions; a game item
  carries whatever the world needs. The app does not enumerate what a thing can be — that is content,
  and enumerating it would be the app deciding what people may make.
- **Physical vs virtual is one flag, not two systems.** Same table, same transfer path. A physical
  asset optionally carries a jurisdiction (where it is); a virtual one does not. This is what lets
  the fantasy maps and a real craft economy run on one engine.
- **`marketplace_listings.asset_id`** (nullable) — a listing either points at a registered asset or
  is a pure service offer. Selling an asset moves it: order settles → `asset_transfers` row →
  `assets.owner_account_id` changes → ledger posts the money leg. **One transaction, both legs.**
- **Assets are account-scoped**, so they inherit §3's reader privacy with no special case: the
  ledger shows a thing moved between accounts, not between people.
- **No constitutional hook needed and none claimed** — assets are `[POLICY]`, Art. I freedom to
  contract. The one hard rail: **an asset can never be a civic-right precondition** (§6.1), and a
  CGC's public-domain IP is not an asset that can be privatised (Art. III §5).

### 5.5 Mutual aid, joint ledgers, agreements

```
assistance_requests id · requester_account_id · title · need · privacy default 'private'
                    · status
joint_ledgers       id · name · purpose · currency_id · balance · approval_rule(all|majority)
joint_ledger_parties / joint_ledger_movements (N-of-M approval before posting)
```

Mutual aid is **non-market**: no price, no order, no fee — it is Art. I association, and the
`mutual-aid` journey arc is correctly classed `people`, not economy. Joint ledgers are the Art. V §2
shared-resource basis; movements post only when the approval rule is met.

Agreements get the **redline/negotiation model** the v3 mockup already mounts (`negotiate-v2.js`) —
clauses, redlines with accept/reject state, threaded comments, per-party signatures. Reuse the bill
redline interface rather than inventing a second one. **The Art. I floor is non-negotiable: no
clause in any agreement can waive a constitutional right**, enforced pre-commit.

**⏸ PARKED — DocuSeal** (operator, 2026-07-25, relayed via lane 7). An open-source option worth
evaluating for contract execution and signature when contracting is built out properly. **Recorded
as a future option; deliberately not built.** The in-app cosign path (`org_contracts`' two-signature
constraint) is what ships now, and it is sufficient for a sale. Evaluate DocuSeal at the point
agreements need real-world execution — the two are complementary, not alternatives: the constitution
cares who consented, DocuSeal would care what the artefact looks like outside the app.

---

## 6. The hard rails, as pins

### 6.1 No paywall on a civic right — `NO_FEE_FORMS`

The audit corrected the premise: today's guard covers **6 of 108 forms**, inspects **top-level keys
only**, and cites **Art. I**, not Art. II §8 (audit §S9). So this is the **first** general no-fee
rail, not a generalisation of one.

- `NO_FEE_FORMS` = **deny-by-default across every form**: no payload on any form may carry a
  fee-shaped key, at **any** nesting depth.
- Rejection cites **Art. II §8** with the clause text.
- The key list gets the exact-list test pin it has never had.
- A levy may never reference a civic-right form; `levies.civic_exempt` defaults true.

### 6.2 Monetary levers move only by act

Every monetary and stipend key is in `SETTING_BOUNDS` **and** `DUAL_DOOR_KEYS` — chamber
supermajority **and** constituent consent (the recipients overlap the deciders; the constituents whose
money it is must consent). The only writer stays `EnactmentService::applySettingChangeForKey`.

**⚠ Prerequisite, not optional:** the audit found `POST /api/setup/constants` unauthenticated and
unguarded post-founding, able to rewrite every constitutional setting including the dual-door key
with no act and no audit entry. **Putting monetary levers on the dual-door rail is meaningless while
that endpoint is open.** It is lane 2's file; this design depends on it being closed.

### 6.3 Money never gates governance

No balance, receipt, holding or filing may be read by any eligibility path. Pinned by a test
asserting the economy tables appear in no role-resolution or eligibility query. Corollary: the
stipend is a payment **to** people and never a payment **required of** them.

### 6.4 `FORBIDDEN_SUBJECT_TYPES` — superseded, and why

The roadmap prescribed adding `market_transaction`, `ubi_receipt` and individual `economic_account`
to this constant. **R1 supersedes that** — those rows now sync by design. The constant continues to
guard `public_records` against carrying identity-bearing content, so
**`economic_account_binding` is added instead**: the binding may never be published, which is the
one thing that must never be public. Recorded here because it directly reverses a charter line.

---

## 7. What we complete rather than build

| Exists | State | This plan |
|---|---|---|
| 9 money dials | Live, inert, unamendable | Consume them; put them on the rails (§4.1) |
| `appropriations` + `GrantService` | Half-wired, 4 dead methods, 0 tests | Wire, test, feed from budgets (§4.4) — closes D-20 #1 |
| Labor → co-determination | Built + pinned | Add the board in front (§5.2); never bypass |
| `org_contracts.commercial` | Schema + cosign, no writer | Marketplace orders write it (§5.3) |
| Ownership stakes / transfers / conversions | Built; cap table unfillable | Founding stake + issuance (§5.3) |
| Treasury departments + R-18 | Built | F-TRE-001..003 actors |
| Dual-door machinery | Built + pinned | Add keys, don't rebuild (§6.2) |
| Hash-chain + append-only triggers | Built | Reuse for `ledger_entries` (§4.2) |
| 14 economy screens | Designed, "planned" | Build to them; register the 5 missing nav rows |
| 3 journey arcs | `planned` | Flip to `live` — they are the exit criteria |

---

## 8. Forms

| ID | Name | Actor |
|---|---|---|
| F-LEG-037 | Revenue Stream / Levy Enactment | R-09 |
| F-LEG-038 | Budget Enactment *(spawns appropriations)* | R-09 |
| F-LEG-039 | Borrowing Authorisation | R-09 |
| F-LEG-040 | Currency Definition **[HARDENED — root only]** | R-09 |
| F-LEG-031 | *(existing)* Monetary lever change — dual-door | R-09 |
| F-TRE-001..003 | Treasury: disbursement · account open · financial report | R-18 / BoG |
| F-TRE-004 | Stipend Run — **`systemOnly()`** | system |
| F-IND-018 | Tax Filing | R-01 |
| F-IND-019 | Work Application | R-01 |
| F-IND-020 | Assistance Request | R-01 |
| F-IND-021 | Grant Application *(replaces the engine-bypassing service call)* | R-01 / R-23 |
| F-IND-022 | Marketplace Listing / Order | R-01 / R-23 |
| F-IND-023 | Funds Transfer · Joint-Ledger Movement | R-01 |
| **F-IND-024** | **Asset Registration / Transfer** *(§5.4 — operator ruling)* | R-01 / R-23 |
| F-ORG-008 | Organization Market Participation | R-23 |

All IDs verified free (families end at LEG-036 / IND-017 / ORG-007; no `F-TRE-*` exists).
**No new CLK codes** — the stipend rides a settings-driven sweep, per the Phase-D precedent.

---

## 9. App_Flows dispositions (D-08)

| Concept | Audited truth | Disposition |
|---|---|---|
| Apply for Grants | Half-built | **Fold in** — §4.4 completes it, including individual applicants |
| Fund distribution | Exists (appropriations by act) | **Already delivered** — no new work |
| Fundraising (donation intake) | The genuinely absent half | **Fold in** — a donation is a transfer to a treasury or org account (F-IND-023); no new plane, and it gives nonprofits the intake side Phase J will want |
| Asset registration | Truly absent | **BUILD IT — §5.5.** *(Operator ruling 2026-07-25, overturning my recommendation to retire it. I read it as a property-rights regime; it is an **inventory model**. A seller lists a specific thing — a blanket they made — and that thing has its own identity and attributes. Required for virtual **and** physical goods, and it is the substrate the fantasy/D&D-style maps need.)* |
| Endorse Policies | Confirmed absent | Not economy — out of lane |

---

## 10. D-09 — age settings: OUT OF SCOPE

**Operator ruling 2026-07-25: this is not part of the game's scope, and nothing ships.**

`age_of_majority` = 18 is real and factually correct — it is **Template/website constitutional text**,
belonging to the document and the public site. It is **not a property of the simulated world**.
This is a game; every account is a character; there is no date of birth in the system and there will
not be one.

**Nothing is built here** — no settings keys, no columns, no rail, no test. There is nothing to gate
and therefore nothing to guard against.

I got this wrong twice before landing on it, and both attempts are recorded so the next reader stops
sooner: first I framed the keys as existing for *"contract capacity and market participation"*, which
would have quietly built an age gate into the economy; then I proposed shipping them as inert
"declared world facts," which was still building a thing the game does not have. **The correct answer
was to build none of it.**

**@lane-07 — D-09 should be closed as NOT APPLICABLE to the app**, or reassigned to whoever owns the
Template text / website copy. It is not an L/M deliverable and it is not a code deliverable at all.

---

## 11. Build order — STATUS 2026-07-25

| Slice | Commit | State |
|---|---|---|
| L-1 monetary levers on the rails | `dee246f` | ✅ 25 tests / 199 assertions |
| L-2 ledger + currency *(L-3's currency half merged in — see below)* | `01d019a` | ✅ 11 tests / 25 assertions |
| L-3 issuance · L-6 stipend · M-1 wallets · M-2 labour board · M-3 market+assets | `ff013c2` | ✅ 7 tests / 33 assertions |
| L-4 budgets → appropriations · L-5 `NO_FEE_FORMS` | *this commit* | ✅ +2 tests |

**Two orderings changed during the build, both recorded rather than quietly done:**
1. **The currency moved from L-3 into L-2.** A ledger entry must be denominated in something; a
   nullable FK to backfill later is the half-wiring this lane's own audit criticised.
2. **The remaining schema landed as ONE additive migration** (`000030`) instead of seven. The tables
   reference each other and the fleet shares a single migrate slot; services then landed slice by
   slice on top of it.

Nine slices. Each is committable, tested, and leaves the app working.

| Slice | Contents | Gate |
|---|---|---|
| **L-1** | Settings on the rails: bounds + dual-door + register + pin extension, cross-field cap rail, new keys | §6.2's prerequisite closed first |
| **L-2** | `LedgerService` + `ledger_entries` + `treasury_accounts` + immutability trigger + `audit:verify` arm | Σ pin green |
| **L-3** | Currency + issuance, root-reserved, F-LEG-040 | Art. V §5 pin |
| **L-4** | Budgets → **existing** appropriations; wire + test `GrantService`; F-LEG-038, F-IND-021 (**D-20 #1**) | Grants card renders real data |
| **L-5** | Revenue, levies, filings, borrowing; F-LEG-037/039; `NO_FEE_FORMS` | Art. II §8 pin |
| **L-6** | Stipend engine, both funding paths, F-TRE-001..004, k-anon aggregates | **L exit: a budget funded by an enacted stream, disbursed on a verifiable ledger** |
| **M-1** | `economic_accounts` + bindings + transfers; the §3 privacy rails and their pins | No-`user_id` pin green |
| **M-2** | Labor board → F-IND-014; founding stakes + share issuance | **M exit: a board hire auto-triggers co-determination** |
| **M-3** | **Assets/inventory (§5.4)** + marketplace + exchange + agreements/redlines + mutual aid + joint ledgers; F-ORG-008, F-IND-024 | All 14 screens live; 3 journey arcs flip to `live` |

**Slot:** post-launch, per the standing work order. **L-1 and L-4 are the exceptions worth
considering earlier** — both are completions of shipped-but-broken code rather than new surface.

---

## 12. Test plan

Constitutional pins (the hardened layer): currency issuer is root · `Σdebits = Σcredits` per group
per currency · `LedgerService` is the sole writer (source scan) · ledger rows are immutable
(trigger) · no fee-shaped key on any form at any depth · monetary keys are dual-door only · stipend
eligibility reads residency and nothing else · no economy table is read by any eligibility path ·
no `user_id` outside `economic_account_bindings` · a binding can never be published · a hire reaches
headcount only via F-IND-014.

Property tests: ledger balances under random transaction sets · stipend arithmetic including cap
saturation and pro-rata short-pay · `award ≤ remaining` and `Σ disbursements ≤ award` (the guards
`GrantService` has always had and never had a test for).

Standing demo: `institutions:demo-treasury --fresh`, the chartered name, in the vein of
`institutions:demo-d`.

---

## 13. Open questions

**All four are ANSWERED (operator, 2026-07-25) — nothing blocks the build:**

| # | Question | Ruling |
|---|---|---|
| 1 | Age values | **OUT OF SCOPE — nothing ships.** 18 is Template/website text, not a property of the simulated world (§10). D-09 → close as N/A to the app |
| 2 | `stipend_enabled` default | **ON, always, for all players.** A legislature may switch it off by act; a fresh world pays from day one |
| 3 | Which roles earn a bump | **All civic roles and operators** — the three shipped `pay_*` dials are the class set; no subset, no per-role table |
| 4 | Asset registration | **BUILD IT** — an inventory model for virtual *and* physical things, required for the fantasy/D&D maps (§5.4) |

**Carried from `phase-g-continuation/DECISIONS.md:65-70`** — the 7 existing L/M items remain open and
are answered by this design where R1/R2 settle them (funding source: **both**; dual-door: **yes**;
flat per role: **yes**; cap: absolute, with the cross-field rail).

**Dependency outside this lane:** §6.2's prerequisite — the unauthenticated settings endpoint —
must be closed before dual-door monetary levers mean anything.

---

*Lane 13 owns `docs/plans/economy/`. No code until this plan settles; build slot post-launch.*
