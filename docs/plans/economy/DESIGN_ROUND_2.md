# The Economy Design Round — Round 2

**Lane 13 · Wave 3 · 2026-07-29 · DESIGN ONLY — no code, no migrations, no FormRegistry changes**

*Authoring basis: [`ECONOMY_ENGINE_PLAN.md`](ECONOMY_ENGINE_PLAN.md) (the L+M build, shipped),
[`ECONOMY_PROP_CONTRACT.md`](ECONOMY_PROP_CONTRACT.md) (the surface contract), `V3_SYNTHESIS_PLAN §10 D1`
(the electoral-season doctrine), and `WIRING_PLAN §8` (the operator's economy seed, 2026-07-01).
This round designs the four surfaces the L+M build deliberately left for a design gate. **Nothing here
builds until the operator rules on it** (§11 of the engine plan). Ground truth was re-verified against
the live schema, services, and `FormRegistry` on 2026-07-29 before writing.*

---

## 0. What this round is — and is not

The L+M build (shipped 2026-07-26, extended in Wave 2 2026-07-29) delivered the economy **spine**: the
append-only hash-chained ledger, root-reserved currency, the stipend engine, wallets and transfers, the
goods marketplace, the asset registry, joint ledgers, and the read surfaces for agreements and treasury.
Four surfaces were left **by design** for a round the operator rules on before any build:

1. **The exchange** — the fungible + non-fungible trading floor, the last economy surface still absent.
2. **The org-settings economy half** — share register, dues, org economic policy.
3. **Clause redlines** — the negotiate panel shared between agreements and bills.
4. **Currency-distribution auto-management** — telemetry letting tax structures manage inflation.

Each section states **what the surface is**, **what already exists in the code**, then **OPTIONS + COSTS
+ a RECOMMENDATION**. The recommendation is mine to make and the operator's to settle — this is an OPEN
QUESTION report, not a build order. Every section also states, explicitly, **what it does NOT build** —
the honest-absence discipline the whole economy has held to (a surface tells the truth about its own
limits rather than faking capability). **This round writes no code.** The build slot is post-review.

---

## 1. The standing constraints every surface inherits

These are the rails the whole economy already runs on, pinned by the constitutional test suite. Each
design below is checked against them.

| Rail | What it means for these surfaces |
|---|---|
| **One door — `ConstitutionalEngine::file()`** | There is no economy write API and there must not be a second one. Every new action is a FORM routed through the engine, where the role gate and state guard live. A page that POSTs anywhere else is wrong by construction. |
| **One ledger writer — `LedgerService`** | Nothing debits or credits an account except through `LedgerService::post()`: balanced-or-throw, hash-chained, no overdraft. The exchange's settlement, every value move — all route here. A handler that writes `ledger_entries` itself is a pin failure. |
| **Reader privacy — two planes, drawn exactly** | The **money plane** (ledger, orders, transfers, holdings) carries ACCOUNT ids, never user ids; only `economic_account_bindings` links an account to a person. The **consent/ownership plane** (contracts, signatures, redlines, cap tables) shows parties by NAME — a signature *is* a name, and who owns a company is not a secret the way a wallet balance is. **These four surfaces straddle the line; each section names which plane each of its rows sits on.** This distinction is load-bearing for ① and ③. |
| **Refusals are answers** | A `ConstitutionalViolation` with a citation IS the response, rendered app-wide. No try/catch swallows it. Every "you can't do that" below is a citation the user reads, not a 500. |
| **Currency is root-reserved (Art. V §5, HARDENED, double-walled)** | Only the topmost/root jurisdiction may define a currency (`CurrencyService::assertRoot` at definition, `IssuanceService::assertIssuable` at mint). This is the wall ① and ② press against: an org may issue **shares** or **non-monetary scrip**, but it may never mint **money**. Stated as a hard rail so no design quietly crosses it. |
| **Money is a string; nothing is absent** | `numeric(24,6)` rendered as a string; collections are `[]` never null; a currency-less world renders honestly. New props obey the existing prop contract. |
| **No paywall on a civic right (Art. II §8, `NO_FEE_FORMS`)** | No form payload may carry a fee-shaped key at any depth. Nothing in these surfaces may attach a price to the exercise of a right. |

---

## 2. The ID reconciliation — against the LIVE registry, not the reservation list

**The desk flagged this explicitly as a real spec-staleness find.** The old manifest "reserved" an
`F-IND-018..021` band and an `F-ORG-008`; Wave 2 then MINTED into that band, so the reservation list no
longer matches reality. This table is the LIVE `FormRegistry` truth (grep on
`app/Domain/Forms/FormRegistry.php`, 2026-07-29): **113 canonical forms** (119 catalog ids − 6 legacy
aliases), the exact count pinned in `AuditChainSmokeTest`.

| ID | Reserved as (old manifest) | LIVE status | Reality |
|---|---|---|---|
| **F-IND-018** | Tax Filing | **FREE** | never minted; `tax_filings` table exists |
| **F-IND-019** | *(Tax Filing, in stale lists)* | **TAKEN** | **= Work Application** — minted Wave 2, routes `LaborBoardService::apply` (`FormRegistry.php:77,383`). **NOT available to the exchange.** |
| **F-IND-020** | Assistance Request | **FREE** | never minted; `assistance_requests` table exists |
| **F-IND-021** | Grant Application | **FREE** | never minted |
| **F-IND-022** | Marketplace Listing / Order | **TAKEN** | list/order/settle, shipped L+M |
| **F-IND-023** | Funds Transfer | **TAKEN** | transfer + joint-ledger movement, shipped |
| **F-IND-024** | Asset Registration / Transfer | **TAKEN** | register/transfer, shipped |
| **F-ORG-008** | Organization Market Participation | **FREE** | never minted; **the mockup relabels it "Organization economic settings"** — a name to reconcile |
| **F-ORG-009** | *(unreserved)* | **TAKEN** | **= Internal Restructuring** — minted Wave 2 |
| **F-TRE-001..004** | Treasury disbursement / account-open / report / stipend-run | **ALL FREE** | the `F-TRE-*` family is entirely empty — zero forms |
| **F-LEG-037..040** | Revenue stream / Budget / Borrowing / Currency definition | **ALL FREE** | family ends at 036; tables exist, no handlers |
| **F-LEG-031** | Amendable Setting Change (via Bill) | **TAKEN** | the real door behind the mockup's "Monetary policy act" chip |

**Consequence:** the exchange and org-settings surfaces have `F-IND-018/020/021`, `F-ORG-008`, and the
whole `F-TRE-*` and `F-LEG-037+` bands available — but **not `F-IND-019`** (Work Application). Each
surface section names the specific ids it would mint, verified free here. **Any build order that lands
after this round must re-verify against the registry at build time** — Wave 2 moved these numbers and a
later wave (lane 15's `F-EDU`, expected to carry the count to 115) will move them again.

---

## 3. Constitutional grounding

| Clause | Governs | Surface |
|---|---|---|
| **Art. V §5** | Currency production, regulation, worth, measurement standards — reserved to the most encompassing jurisdiction; oversight of extra-jurisdictional trade | ④; the wall on ① and ② |
| **Art. V §4** | Taxes, fees, borrowing, IP, infrastructure (Joint Powers) | ④ tax/inflation |
| **Art. V §2** | Shared & indivisible resources | joint-ledger basis; touches ① |
| **Art. IV §3** | Constituent-supermajority consent (the second of the two doors) | ④ — the wall a rate controller collides with |
| **Art. III §5** | Shares, fair-market compensation, the Open Market, CGC parity, public-domain IP — the *only* place the Template ties money to ownership | ① exchange, ② share register |
| **Art. III §6** | Work councils: contracted work → worker board seats | ② (do not disturb the hardened co-determination math) |
| **Art. II §8** | No compulsory payment for civic rights (HARDENED) | all — `NO_FEE_FORMS` |
| **Art. I** | Economic freedom, freedom to contract; **the non-waivable rights floor**; a one-sided contract never takes effect | ③ redlines; all |

`[POLICY]` marker: where the Template is silent — whether an exchange exists, what dues cost, how units
are distributed — the design ships the **machinery and its transparency**, never the number. Every
`[POLICY]` value is an amendable variable on the public record with its enacting act attached.

---

# ① THE EXCHANGE

## What it is

The last economy surface with no live read or write path. `mockups/v3/economy/exchange.html` is
unambiguous about its intent: a **stock-market-style trading floor for organization shares** — a
continuous double-auction order book (resting bids/asks, depth bars, best-bid/best-ask, computed
spread), a limit-order ticket, a live trade tape streaming simulated fills, per-instrument
last/change/volume/sparkline KPIs, and a marquee ticker. It carries an explicit rail — *"only
organization shares trade on this floor"* — and a *"Looking for goods?"* card that hands single items
off to the goods marketplace. The operator seed (`WIRING_PLAN §8`) frames it more broadly:
*"users transfer/trade directly or via a common marketplace (**fungible + non-fungible**)."*

## What exists today

| Capability | State |
|---|---|
| The settlement rail (money + thing + agreement, atomic) | **EXISTS, reusable.** `MarketplaceService::settle` does, in one transaction or none: `AccountService::transfer` (money leg → `LedgerService`, no overdraft) + an `org_contracts` `kind='commercial'` row (the agreement, when a party is an org) + `AssetService::transfer` (the thing, append-only provenance). Reachable only through `ConstitutionalEngine::file` (F-IND-022). |
| Non-fungible + fungible substrate | **EXISTS.** `assets` already carries `quantity` (fungible stacks) and `origin='issued'` — a general fungible+non-fungible venue could ride this rail with **no new token table**. |
| Root-reserved currency + pseudonymous accounts | **EXISTS** (the wall + the money plane). |
| A tradeable org cap table | **BLOCKED.** `org_ownership_stakes.acquired_via='founding'` has exactly one writer (`CgcService`, seats the jurisdiction at 100%); `='issue'` (the `VIA_ISSUE` constant) has **zero** writers; `OrganizationRegistration` opens no stake. **Every live cap table is a single 100% holder — there is nothing fractional to trade.** |
| An order book / matching engine | **ABSENT.** No resting-order table, no matching, no trade-tape table. The mockup's continuous double-auction is entirely unbacked. The live venue is fixed-price `list → order → settle` with seller-accept. |
| Live price / valuation data | **ABSENT.** Mockup prices are browser-simulated. |
| A share/unit balance container | **ABSENT.** `economic_accounts` hold a currency balance only. |

**The privacy fault line, stated plainly.** `org_ownership_stakes.holder_id` is a **real
`users`/`organizations`/`jurisdictions` id — not a pseudonymous account id.** The rest of the economy
plane is account-scoped. So *"put shares on the trading floor"* is not a lookup; it is a **reader-privacy
design decision**: either equity lives on the NAMED ownership plane (like `org_contracts` shows parties
by name), or holdings must be re-homed onto accounts. (The live `MarketplaceService::settle` already
blurs this — it writes `org_contracts.counterparty_type='users'` with an *account* id.)

## The design questions, and the options

**Q1 — What does "the exchange" actually trade?** The mockup, the plan, and the operator seed disagree.

| Option | What trades | Cost / consequence |
|---|---|---|
| **A. Org equity only** (mockup + plan §5.3) | Fractional org shares | Requires unblocking the cap table (issuance writer + founding stake) AND a privacy ruling on named-vs-account holdings. Fractional multi-holder cap tables then flow into the co-determination and restructuring math. |
| **B. Org-issued fungible units/tokens** (operator seed) | Non-equity scrip/loyalty units orgs issue to members | No table today; **cannot be a currency** (root-reserved). Naturally lands on the `assets` rail (`origin='issued'`, virtual). Overlaps ②. |
| **C. General fungible + non-fungible venue** (operator seed) | Any tradeable thing — assets (non-fungible, already live) + fungible stacks | Reuses the shipped `assets`/`AssetService`/`F-IND-024` rail; **no new mechanics**. The "fungible" half is just `quantity>1` assets. |
| **D. Unify marketplace + exchange** | One venue for goods and shares | Contradicts the mockup, which explicitly links the two apart. Throws away a working, shipped surface. |

**Q2 — Order book, or the settlement rail already built?**

| Option | Cost |
|---|---|
| **Build a genuine matching engine** (resting orders, price-time priority, continuous double-auction) | Large greenfield: new order-book tables, a matcher, price-discovery — none of which exists, and none of which the one-door/one-ledger rails cover for free. High risk, high build. |
| **Reuse fixed-price `list → order → settle`** | The shipped rail. Shares/units list at a price, a buyer orders, the seller (or a bilateral accept) settles atomically. The order book becomes **honest chrome** — presented as "not built," or derived from *real* resting listings, never simulated. |

**Q3 — Do shares trade on the pseudonymous money plane?** (a) Re-home holdings onto `economic_accounts`
with a new share-balance container (keeps pseudonymity, more build); (b) accept equity lives on the
**named ownership plane** — a cap table is a public/consent fact — while the **money leg** of every
trade stays account-scoped; (c) keep equity off the exchange entirely and trade only account-scoped
fungible/non-fungible instruments.

## RECOMMENDATION

**Ship the exchange as C-then-A on the settlement rail already built, not as the mockup's matching
engine.** Concretely:

1. **Reuse the shipped `list → order → settle` rail; do not build a matching engine this round.** The
   order book is aspirational chrome with no schema and heavy new mechanics that the constitutional rails
   don't cover. Present price/book honestly (derived from real resting offers or marked "not built").
2. **Deliver the fungible + non-fungible venue first (Option C)** — it rides the live `assets` /
   `AssetService` / `F-IND-024` rail with essentially no new plumbing, and it satisfies the operator
   seed's "fungible + non-fungible" directly.
3. **Then unblock equity (Option A) as a distinct, later step:** mint **`F-ORG-008`** as the
   share-**issuance** writer (`acquired_via='issue'` via `OrgOwnershipService`, plus a founding stake on
   `OrganizationRegistration`) — the plan §5.3 already names this in scope. Equity trades through the
   *same* fixed-price rail.
4. **On Q3, recommend option (b):** equity holdings live on the **named ownership plane** (who owns a
   company is a public fact, consistent with `org_contracts` showing parties by name), while the **money
   leg** of a share trade stays account-scoped on the ledger. This is the cleanest reconciliation and
   needs no new balance container — **but it is a genuine privacy ruling and I flag it for the operator,
   not settle it.**

**Rationale:** every piece of C is already built and pinned; A is a bounded extension of the cap-table
writer the plan already scoped; the matching engine is the one genuinely large, genuinely new,
genuinely risky piece — and the mockup itself labels its prices "simulated," which is the tell that it
is presentation, not spec.

## What it does NOT build

- **No continuous order-book / matching engine** — the live economy is fixed-price; the mocked
  double-auction is new mechanics with no schema, deliberately out of scope.
- **No live valuation/price feed** — any price shown is honest-absence or derived from real settled
  trades, never simulated.
- **No second write API** — all trading files through `ConstitutionalEngine::file`, exactly like
  F-IND-022.
- **No share-balance container on `economic_accounts`** unless the operator picks Q3(a).
- **No automatic resolution of equity onto the pseudonymous plane** — Q3 is an explicit ruling, not an
  inherited default.

## Forms & IDs (verified free)

- **`F-ORG-008`** — Organization Market Participation / share issuance (`acquired_via='issue'` + founding
  stake). R-23.
- The fungible/non-fungible venue (Option C) mints **nothing new** — it rides `F-IND-024` (asset) and
  `F-IND-022` (list/order) as they stand, or a sibling `action` on F-IND-022.

## Pins it would need

Settlement stays atomic (money+thing+agreement or none) · `LedgerService` remains the sole ledger writer
(the fleet-wide write-scan already proves this) · a share-issuance writer never becomes a co-determination
bypass (`CoDeterminationService` untouched) · CGC listings trade on identical terms (Art. III §5, badge
informational) · a CGC's public-domain IP is never a tradeable asset.

---

# ② THE ORG-SETTINGS ECONOMY HALF

## What it is

`mockups/v3/economy/org-settings.html` renders six sections — board/worker seats (link-out), the
elections nomination-window dial, **Shares**, **Dues**, the private org ledger, and **Taxes the org
settles** — as an organization's economic control panel, role-gated to its seated board. The operator
seed: *"orgs may issue their own units to workers/members/owners under their own agreements."*

## What exists today

| Piece | State |
|---|---|
| The dial substrate | **EXISTS + proven.** `organizations.settings` jsonb (migration `2026_07_29_000020`) + `OrgSettingsService` — a **closed** `KEYS` registry (unknown key refuses), role-gated, audit-chained, filed through `F-ORG-001` action `update_settings`. Today it holds exactly one key: `board_nomination_window_days`. |
| Equity storage | **EXISTS.** `org_ownership_stakes` (`acquired_via='issue'`, `units numeric(20,6)`) is the lawful home for an org "issuing units" as equity — **but has no share-class column.** |
| Scrip / loyalty-token home | **EXISTS.** `assets.kind='virtual'`, `origin='issued'` — a non-monetary issued unit. |
| The currency wall | **EXISTS, double-walled.** An org can never define a currency (`CurrencyService::assertRoot` + `IssuanceService`). The mockup's "abstract unit of account" *is* the root currency (`unit_kind='abstract'`), not an org mint. |
| Dues machinery | **ABSENT.** `'dues'` is only a free-text `kind` label on `ledger_entries` / `market_transactions`. No `dues_schedules`, no amount/cadence on `org_memberships`. |
| Share classes | **ABSENT.** The mockup's common / worker-allocated split has no schema home. |
| Any economic dial key | **ABSENT.** `OrgSettingsService::KEYS` holds only the nomination window. |

**Two live wrinkles to fix, not design around:** (1) The `F-ORG-001` handler restricts
`update_settings` to the org **agent only** (`OrganizationProfileManagement.php:69`), while
`OrgSettingsService::assertMaySteer` allows agent **or seated board member** — so the mockup's "the board
sets economic policy" model is currently unreachable through the engine. (2) The co-determination math
(`CoDeterminationService`, PROTECTED, Art. III §6) is the sole writer of board worker-seat columns and
must be read around, never altered.

## The design questions, and the options

**Q1 — What is an org "unit"?** (The crux the operator seed raises.)

| Option | Maps to | Verdict |
|---|---|---|
| **Equity share** | `org_ownership_stakes.acquired_via='issue'` | Exists (no class column). Ownership, co-determination-relevant. |
| **Non-monetary scrip / loyalty token** | `assets.kind='virtual', origin='issued'` | Exists. No ownership, no co-determination effect. |
| **Dues-credit** | — | No table. |
| **A currency** | — | **FORBIDDEN** (Art. V §5). Must be actively refused, not merely unbuilt. |

**Q2 — Dues: build an engine, or model as an agreement?** (a) A new `dues_schedules` table + form +
recurring generation (a scheduler that does not exist); (b) model dues as an `org_contract` (a recurring
member↔org agreement), paid via an `F-IND-023` transfer with `kind='dues'`; (c) keep dues narrative /
honest-absence — the mockup's "5 ç/month" is a *display of an agreement*, not a live charge.

**Q3 — Where does the economy half live?** (a) Extend `F-ORG-001` `update_settings` with new economic
POLICY keys (the proven door); (b) mint `F-ORG-008` as a dedicated economic form. These are not
exclusive — settings-dials vs world-changing-acts is a natural split.

**Q4 — Share classes?** (a) Add a `class` column to `org_ownership_stakes`; (b) drop classes for v1, show
aggregate units per holder (honest-absence). Note worker-allocated pools sit near Art. III §6 — they must
never be confused with co-determination seats.

## RECOMMENDATION

1. **An org unit is equity (`org_ownership_stakes`) OR virtual scrip (`assets`) — and the design states,
   affirmatively, that it can never be a currency.** The share register is the equity view; scrip is the
   asset view. Both already have homes.
2. **Do NOT build a dues engine (Q2 → b/c).** Model dues as a recurring `org_contract`; render "this org
   charges no dues" as honest-absence when none exists; a paid due is an `F-IND-023` transfer with
   `kind='dues'`. No scheduler, no new table. The Art. I floor rides free: a due can never gate a civic
   right, and lapse ends membership without withholding any right.
3. **Split Q3: POLICY dials extend `F-ORG-001` `update_settings`** (add economic keys to the closed
   `KEYS` registry — dues policy, whether the org issues units, issuance authorization); **world-changing
   ACTS mint `F-ORG-008`** (issue shares, open a scrip series). Reconcile the `F-ORG-008` name to
   **"Organization economic settings / Market Participation."**
4. **Q4 → defer classes (b) for v1**; aggregate units per holder is honest and sufficient. A `class`
   column is a clean later add if the operator wants named classes.
5. **Fix the role-gate divergence** to match the mockup's "seated board steers economic policy" model —
   align the `F-ORG-001` handler gate to `assertMaySteer`. **This is a code-match fix, not a rule change**
   (announce → fix → pin, per the protected-files doctrine).

## What it does NOT build

- **No dues scheduler / recurring-charge engine.** · **No per-org currency** (actively refused). ·
- **No named share classes in v1.** · **No stored fair-market price** on an org (any figure shown is a
  live quote/derivation; only `org_conversions.fair_market_floor` is persisted, and only for conversions).
- **No change to `CoDeterminationService`** — worker-allocated equity is read around the hardened math.

## Forms & IDs (verified free)

- **`F-ORG-008`** — Organization economic settings / Market Participation (the ACT door; shared with ①'s
  share issuance — one form, `action`-dispatched). R-23.
- POLICY dials ride the existing **`F-ORG-001`** `update_settings`.

## Pins it would need

`OrgSettingsService::KEYS` stays a closed registry (unknown key refuses) · a due can never attach to a
civic-right form (`NO_FEE_FORMS`) · an org "unit" can never write a `currencies` row (Art. V §5) · the
co-determination formula is untouched by any economic dial.

---

# ③ CLAUSE REDLINES

## What it is

`mockups/v3/assets/js/negotiate-v2.js` is **one** authored negotiate interface that mounts identically
over both an agreement (`economy/agreement-detail.html`) and a bill (`shared/bill.html`): a clause list,
redlines (edit / add / strike with pending → accepted/rejected state), a threaded discussion, and a
per-side "agree/sign then submit" gated on *zero pending redlines*. The only kind-dependent output is the
footer verb ("Sign & submit" vs "Submit to the floor"). `WIRING_PLAN §6` pins the hard constraint: the
clause/redline model is **additive** and **"BillVersion stays whole-text."**

## What exists today

| Piece | State |
|---|---|
| The shared UI | **EXISTS in the mockup** — a complete, authored interaction spec. |
| The read-only agreements register | **EXISTS** (Wave 2). `EconomyController::agreements/agreement`, party-scoped, consent-plane NAMES. Both Vue pages self-describe drafting/redlines as *"Wave 3, design-gated."* |
| The both-sign floor | **EXISTS but coarse.** `org_contracts_cosign_check` ties `status='active'` to BOTH `signed_by_org_at` AND `signed_by_counterparty_at` — **bilateral only** (org + one counterparty), and it gates only the transition, not versioning. |
| Bill amendment path | **EXISTS but different.** A bill "redline" is a **Motion** (`F-LEG-007`, `kind='amendment'`, carrying `amendment_text`) the **chamber VOTES to adopt**, whereupon `BillService::applyAmendment` appends a new **whole-text** `BillVersion`. |
| Clause / redline / comment / per-clause-signature tables | **ABSENT — entirely greenfield.** Both parents are single whole-text blobs (`org_contracts.terms`, `bill_versions.law_text`). No comment store exists for agreements or bills. |
| The Art. I "no clause waives a right" floor | **PROSE ONLY.** `MarketplaceService` concatenates the sentence into `terms`; there is no structured validator. `NO_FEE_FORMS` inspects payload KEYS, not free-text clauses. |
| A home for person-to-person "custom" agreements | **ABSENT.** `org_contracts.organization_id` is NOT NULL — two residents contracting have no table. |

**The core tension:** one UI hides **two incompatible authority models** — agreements are **bilateral
mutual consent** (accept/reject); bills are **floor-vote adoption** (a chamber majority/supermajority,
both seat kinds independently for a bicameral). The shared surface must not leak name-based bilateral
assumptions into the bill side, or vote/quorum assumptions into the agreement side.

## The design questions, and the options

**Q1 — The polymorphic shape of one clause/redline model over two asymmetric parents.**
(1) `clauses`/`redlines` carry `subject_type ∈ {org_contract, bill}` + `subject_id`; whole-text stays
authoritative; clauses are a parallel display/negotiation overlay (matches the WIRING pin). (2) Attach to
the `bill_version` head and re-derive each amendment. (3) Attach to the `bill` (not the version) so
redlines survive version bumps. **Recommend (1)+(3): attach to the bill, not the version.**

**Q2 — Who may propose a redline?** Agreements: either party (bilateral) — fits `org_contracts`. Bills:
**NOT bilateral** — any seated member proposes via a Motion and the **chamber votes**; adoption (not a
counterparty click) appends the version. The shared UI **wraps** motion+vote for bills; it does not
replace the authority.

**Q3 — Does a new redline reset the both-sign floor?** A signature is on a *specific text*. (1) Any
accepted redline **clears both `signed_at` and forces re-sign** (safest); (2) version the contract
(mirror `bill_versions`) and sign per-version; (3) freeze redlines once the first party signs. The mockup
sidesteps this (submit gated only on zero-pending).

**Q4 — Where does the Art. I floor live, honestly?** Free text **cannot be mechanically proven** to
waive no right. (1) A pre-file guard in `ConstitutionalEngine::file` for the redline/agreement form(s)
that refuses (`ConstitutionalViolation`, Art. I) on **structured rights-reference tags** — a clause
flagged as touching voting/candidacy/residency/petition/due-process; (2) **"void-in-that-part"**
semantics — a clause that waives a right is unenforceable even if its prose slips through, the rest
standing (this is the Template's own rule); (3) attestation. **Recommend (1)+(2) together.**

**Q5 — Person-to-person / N-party agreements.** `org_contracts.organization_id` is NOT NULL, so the
mockup's "custom" (person↔person, `form:null`) kind has no home. (1) A new resident-agreements table with
N-party signers; (2) declare custom/N-party **out of scope** for this round. **Recommend (2)** unless the
operator wants person-to-person contracting now.

## RECOMMENDATION

**One shared clause/redline DATA model + UI, two plane-specific authority adapters.**

- **Data:** additive `clauses` + `redlines` (+ optional `negotiate_comments`) with `subject_type ∈
  {org_contract, bill}`. **Whole-text stays authoritative** — clauses are a negotiation/display overlay,
  and `BillVersion` stays whole-text (the WIRING pin). Attach bill clauses to the **bill**, not the
  version, so they survive amendment appends.
- **Agreements adapter (consent plane):** bilateral accept/reject; **any accepted redline clears both
  signatures and forces re-sign** (Q3→1). Introduce contract versioning for provenance (mirror
  `bill_versions`); the both-sign floor binds the *current* version.
- **Bills adapter (governance plane):** the redline surface is a *proposal composer* that **files a
  Motion** (`F-LEG-007`, `kind='amendment'`) carrying the assembled text; **adoption is the chamber vote**
  (`F-LEG-004/005`), bicameral-aware. The UI wraps the existing motion+vote machinery; it invents no new
  bill authority.
- **Art. I floor (Q4→1+2):** a pre-file guard citing Art. I refuses a filing whose clause is *tagged* as
  touching a listed right, **and** "void-in-that-part" is the constitutional backstop for anything that
  slips through. **State the honest limit: prose cannot be fully validated; we enforce structured tags +
  unenforceability, not natural-language proof.**
- **DocuSeal stays parked** (operator, 2026-07-25): in-app cosign is what ships; DocuSeal is a
  deliberately-not-built future option for real-world execution.

## What it does NOT build

- **No replacement of the whole-text version chain or the vote-adopts-amendment authority** (additive
  only). · **No claim of natural-language rights-waiver detection** — structured tags + void-in-part only.
- **No N-party or person-to-person agreements** unless the operator opts in (Q5). · **No persisted
  comment store** unless threaded discussion is explicitly in scope (recommend deferring comments to keep
  the round tight). · **No new bill form** — bill redlines ride `F-LEG-007`.

## Forms & IDs

- **Bills:** no new form — `F-LEG-007` (Motion Submission) is the proposal door; `F-LEG-004/005` adopt.
- **Agreements:** a drafting/redline/sign write path needs a door. **`F-ORG-008`** (org-scoped) is the
  candidate if agreements stay org-scoped; a person-to-person custom agreement (Q5→1) would instead take a
  free **`F-IND`** slot (`018/020/021`) as a resident act. Verified free.

## Pins it would need

Whole-text `bill_versions` stays authoritative and append-only · a bill redline cannot mutate a version
except through an adopted motion · an accepted agreement redline clears signatures · a clause tagged as
waiving a right refuses pre-commit (Art. I) and is void-in-part regardless · engine-only writes.

---

# ④ CURRENCY-DISTRIBUTION AUTO-MANAGEMENT

## What it is

The operator seed (`WIRING_PLAN §8`, verbatim): *"as a centralized game currency, currency-distribution
telemetry should let tax structures auto-manage inflation within bounds … no basket of goods … Earth
legislature (topmost) sets regulations … tax/inflation controls are UIs operated by the people empowered
to use them."* And, decisively for the fold-or-separate question: *"⚑ that mechanism deserves its own
design round."*

## What exists today

| Piece | State |
|---|---|
| The rate levers | **INERT.** `issuance_rate_bps` and `inflation_target_bps` are real, bounded (0–10000 bps), dual-door-gated `constitutional_settings` keys — **stored, displayed on `units.html`, and read by NOTHING.** `IssuanceService`'s own docblock: these are *"machinery, not the policy."* |
| The dual-door enactment machinery | **EXISTS.** Every monetary lever moves only via `F-LEG-031` → `EnactmentService::applySettingChangeForKey` → `SettingAmendmentDoorService` — chamber supermajority **AND** constituent-supermajority (Art. IV §3). |
| Currency-distribution telemetry | **ABSENT.** No velocity, no Gini/spread, no supply time-series, no per-jurisdiction holdings. Only `IssuanceService::supply()` (a scalar Σmints−Σburns) and the `ubi_disbursements` aggregate. |
| A jurisdiction link on accounts | **ABSENT.** `economic_accounts` has no `jurisdiction_id` — so "per-jurisdiction holdings" has no clean substrate (the only route crosses the reader-privacy wall). |
| The tax half | **SUBSTRATE-ONLY.** `revenue_streams`/`levies`/`tax_filings` tables exist, but `F-LEG-037` is unregistered — **a levy rate cannot yet be set by act in code** (demo-seeded only). |
| The automated mint | **PARTIAL.** `StipendService` (minted path) is the only recurring mint, and it **ignores both rate levers** (mints exactly the stipend owed). No wired scheduler (`F-TRE-004` unregistered). |

**So the "auto-manage inflation within bounds" mechanism is 100% aspiration today**, and it would collide
head-on with the dual-door rule: an algorithm that changes a rate without an act contradicts both
`DUAL_DOOR_KEYS` and *"the Board of Governors executes, it does not authorize."*

## The design questions, and the options

**Q1 — Fold into this round (beside `units.html`), or give it its own round?**

| Evidence to FOLD | Evidence to SEPARATE |
|---|---|
| `units.html` already renders both rate levers; telemetry is their natural companion panel. | The operator wrote, verbatim, *"deserves its own design round."* |
| The settings keys already exist; designing the read-model beside the levers is efficient. | A bounded automated controller raises a **genuinely new constitutional question** — delegated rate-setting — that `units.html` deliberately does not touch. |

**Q2 — How does "auto-manage within bounds" relate to the dual-door rule?**

| Option | What it does | Constitutional cost |
|---|---|---|
| **A. Continuous controller in a legislature-set band** | A system actor mints/burns or nudges a levy *within* a band each period, no per-step act; the band itself set by a dual-door act. | **Highest.** Collides with `DUAL_DOOR_KEYS` + "BoG execute, not authorize." Needs a **NEW constitutional carve-out** — an act that *delegates* bounded rate-setting to a `systemOnly()` actor (analogous to the stipend sweep) — and every automated action still files through the engine. |
| **B. Telemetry + recommendation; humans enact** | The app surfaces *"supply is X above target — consider ±Y"*; each change is a human `F-LEG-031`/`F-LEG-037` act. | **Lowest.** Zero new authority. Matches the seed's *"UIs operated by the people empowered"* exactly. Only new build is the telemetry. |
| **C. Manual levers + rich telemetry** | B minus the recommendation engine. | Closest to what's built; least ambition. |

**Q3 — What telemetry is privacy-safe?** Supply (exists, public). Velocity — aggregate over
`market_transactions`/`ledger_entries` (account-scoped, **clean**). Gini/spread over
`economic_accounts.balance` — touches **accounts, not people** (arguably **clean**, inside the
accounts-never-people rail). Per-jurisdiction holdings — **no clean substrate** (`economic_accounts` has
no jurisdiction; the only route crosses the privacy wall).

## RECOMMENDATION

**Separate the controller into its own future round — but fold the TELEMETRY read-model into this
round's `units` surface now.**

- **On Q1 → SEPARATE the controller**, honoring the operator's own instinct and because Option A raises a
  new constitutional question (delegated rate-setting) that deserves dedicated treatment. **But the
  telemetry is the prerequisite every option needs first**, it is constitutionally cheap, and it is the
  natural companion to the two levers already on `units.html` — so **design and build the telemetry
  read-model as part of the economy surfaces now.**
- **On Q2 → Option B for the near term** (telemetry + recommendation; humans still enact). It needs zero
  new constitutional authority and matches the seed precisely. **Option A (the continuous controller) is
  the separate future round**, and it must be built as a `systemOnly()` actor acting *inside a band a
  dual-door act delegated to it*, every action engine-filed — never as an admin knob.
- **On Q3 → build the account-clean metrics** (supply-over-time, velocity, balance spread/Gini over
  accounts). **Declare per-jurisdiction holdings out of scope** pending a privacy-safe aggregation design
  — do not cross the reader-privacy wall to draw a distribution map.
- **State the prerequisites honestly:** `F-LEG-037` (levy enactment) and a wired scheduler (`F-TRE-004`
  clock sweep) must exist before *any* tax-based auto-management — today the tax half can't be set by act
  and the economic clock is unwired.

## What it does NOT build

- **No auto-management controller this round** (the separate future round). · **No per-jurisdiction /
  per-person holdings map** (no privacy-safe substrate). · **No delegated rate-setting authority** without
  a new constitutional act. · **No assumption of a working levy-enactment path** (`F-LEG-037` is
  unregistered). · **No new position on how much money should exist** — the app ships telemetry and the
  human levers, never the target number (`[POLICY]`).

## Forms & IDs

- The near-term (Option B) mints **no new form** — recommendations feed the existing `F-LEG-031`
  (monetary lever) and, once built, `F-LEG-037` (levy). The controller's future round would need a
  `systemOnly()` form in the free `F-TRE-*` band, plus the delegating act.

## Pins it would need

Telemetry aggregates over accounts, never people · the levers still move only by dual-door act · a future
controller can act only inside a legislature-delegated band and files every action through the engine ·
currency stays root-reserved.

---

## 5. The two cross-cutting rulings I'm asking for, and the OPEN QUESTIONS

**Ruling A — Fold-or-separate on ④ (the operator's explicit question): SEPARATE the controller, FOLD the
telemetry.** The controller is its own future round (it needs a new constitutional carve-out); the
telemetry read-model ships with the economy surfaces now because every option depends on it and it costs
no new authority.

**Ruling B — The reader-privacy plane for equity (①/②): the NAMED ownership plane, with money legs
account-scoped.** A cap table is a public fact; a wallet is not. I recommend this split but flag it as a
genuine privacy ruling for the operator to confirm.

### The decisions this round puts to the operator

| # | Surface | The question | My recommendation |
|---|---|---|---|
| 1 | ① Exchange | What does it trade — equity, fungible units, general fungible+non-fungible, or unify with the marketplace? | **Fungible+non-fungible first (rides `assets`), equity second (mint `F-ORG-008`)** — not the mockup's matching engine |
| 2 | ① Exchange | Order book, or the shipped fixed-price settlement rail? | **The shipped rail;** order book is honest chrome |
| 3 | ① Exchange | Do shares trade on the pseudonymous plane or the named ownership plane? | **Named ownership plane; money leg account-scoped** (Ruling B) |
| 4 | ② Org-settings | Is a "unit" a share, scrip, or (forbidden) currency? | **Share or scrip; currency actively refused** |
| 5 | ② Org-settings | Build a dues engine, or model dues as an agreement? | **Agreement + honest-absence; no scheduler** |
| 6 | ② Org-settings | Extend `F-ORG-001` or mint `F-ORG-008`? | **Both — POLICY dials on `F-ORG-001`, ACTS on `F-ORG-008`** |
| 7 | ③ Redlines | One shared model over two authority planes — how? | **Shared data + UI; bilateral adapter for agreements, motion+vote adapter for bills** |
| 8 | ③ Redlines | Where does the Art. I floor live, given prose can't be proven safe? | **Structured rights-tags refuse pre-commit + void-in-part backstop** (honest limit stated) |
| 9 | ③ Redlines | Person-to-person / N-party agreements now, or deferred? | **Deferred** unless the operator wants them |
| 10 | ④ Currency | Fold or separate? | **Separate the controller, fold the telemetry** (Ruling A) |
| 11 | ④ Currency | Controller vs recommendation vs manual? | **Recommendation-only near-term (Option B); controller is the future round** |

**Nothing here builds until the operator rules.** On his ruling, the build sequences behind the standing
work order — the cheap, already-substrated pieces first (② dues-as-agreement, ④ account-clean telemetry,
① the fungible/non-fungible venue), the new-authority pieces last (④ the delegated controller, ①'s
matching engine if it is ever wanted).

*Lane 13 owns `docs/plans/economy/`. Design-gated per §11. Build slot post-review.*
