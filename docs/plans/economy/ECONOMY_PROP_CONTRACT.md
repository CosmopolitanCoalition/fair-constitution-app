# Economy — Inertia prop contract (v1)

**Lane 13 → @lane-06 · 2026-07-25 · written BEFORE the controllers, per the split's binding condition**

This is the shape the economy controllers return. It exists because parallel prop/page work is the
exact geometry that produced the v3 mapper's "Proposing…" freeze: the panel read `plan.total_pop`,
the backend never shipped it, `formatPop(undefined).toLocaleString()` threw *inside render*, the Vue
patch aborted, and the DOM froze at the last paint — it looked like a hang, not a type error.

**So: verify against the actual response, never against this file.** If they disagree, the response
is right and this document is a bug — tell me and I fix the doc, or the controller, whichever is
actually wrong. Contract drift is caught automatically by `EconomyPropContractTest`, which asserts
the live controller output carries exactly the keys published here.

---

## Rules that hold on every surface

| Rule | Why |
|---|---|
| **Money is a STRING**, never a float — `"45.000000"` | `numeric(24,6)`. A float would silently lose precision on a ledger. Format it, don't arithmetic it. |
| **Every money field can be `"0.000000"`, never `null`** | so a formatter never sees undefined |
| **Nullable fields are explicitly listed below** and are the ONLY ones that can be null | everything else is guaranteed present |
| **Ids are uuid strings** | |
| **Timestamps are ISO-8601 strings**, or null where marked | |
| **Collections are always arrays**, `[]` when empty — never null, never absent | an empty market is `[]`, not a missing key |
| **No `user_id` anywhere.** Accounts only. | the reader-privacy model (operator ruling): rows carry account ids; only `economic_account_bindings` links an account to a person |

**Currency is not assumed to exist.** On a world whose root has not defined one, `currency` is
`null` and every collection is `[]`. Pages must render that state rather than assume a symbol.

---

## `GET /economy` → `Economy/Home`

```
currency: null | {
  id: string, name: string, code: string, symbol: string, precision: number
}
supply: string            // minted − burned, "0.000000" when none
ledger: {
  entries: number,        // integer count
  verified: boolean,      // hash chain intact
  residual: string        // MUST read "0.000000" on a healthy ledger
}
counts: {
  wallets: number, listings: number, postings: number,
  assistance: number, assets: number
}
stipend: {
  enabled: boolean,
  floor: string, cap: string, interval: string,
  funding_source: string, // "minted" | "treasury_draw"
  last_run: null | { ran_at: string, recipients: number, total: string, short_paid: boolean }
}
clock: { interval: string, period_days: number|null,     // Wave 4: the economic clock, derived
         last_run: string|null, next_run: string|null }
```

## `GET /economy/wallet` → `Economy/Wallet`

The signed-in resident's own wallet. **`account` is null when they have none** (no currency yet, or
no confirmed residency) — that is a normal state, not an error.

```
currency: null | {…as above}
account: null | { id: string, balance: string, status: string }
transactions: [                       // newest first, max 50
  { id: string, direction: "in"|"out", amount: string,
    kind: string, memo: string|null, at: string,
    counterparty_account_id: string|null }
]
receipts: [                           // stipend receipts, newest first, max 12
  { id: string, base: string, bump: string, amount: string, at: string }
]
assets: [                             // things you hold, newest first, max 50
  { id: string, name: string, kind: "physical"|"virtual",
    quantity: string, origin: string, at: string }
]
```

## `GET /economy/market` → `Economy/Market`

Both sides of the board, because a market with only sellers is a catalogue.

```
currency: null | {…}
offers: [
  { id: string, kind: "good"|"service", title: string, description: string|null,
    price: string, quantity: string, status: string,
    seller_account_id: string,
    asset: null | { id: string, kind: "physical"|"virtual", name: string,
                    attributes: object|null } }
]
work: [
  { id: string, title: string, terms: string, rate: string|null,
    status: string, organization_id: string, applications: number }
]
assistance: [                         // PRIVACY-FILTERED: 'private' rows never appear here
  { id: string, title: string, need: string, privacy: string, status: string }
]
my_assets: [                          // yours, NOT already listed — what F-IND-022 may point at
  { id: string, name: string, kind: "physical"|"virtual" }
]                                     // [] for a guest or anyone without a wallet
```

## `GET /economy/market/{listing}` → `Economy/Listing`

```
currency: null | {…}
listing: { …one offers[] element… }
orders: number                        // count only; buyer identities are not published
can_order: boolean                    // false when it is your own listing, or not open
is_seller: boolean                    // drives the settle control
pending_orders: [                     // SELLER ONLY — [] for everyone else, always an array
  { id: string, buyer_account_id: string, quantity: string, at: string }
]
```

**Why `pending_orders` is not a privacy hole.** The seller must choose which order to accept, so
they have to be told the orders exist. What they are NOT told is who placed one: a buyer appears as
an ACCOUNT, exactly as everywhere else. Knowing which order to settle is a different fact from
knowing who bought your blanket, and only the first is necessary.

## `GET /economy/treasury` → `Economy/Treasury`

Public money. Everything here is public by construction (Art. III §4).

```
currency: null | {…}
accounts: [ { id: string, owner_type: string, owner_id: string,
              label: string|null, balance: string, public: boolean } ]
ledger: [ { seq: number, at: string, direction: "debit"|"credit",
            amount: string, kind: string, account_type: string,
            account_id: string, hash: string } ]     // newest first, max 50
issuance: [ { id: string, direction: "mint"|"burn", amount: string,
              reason: string, at: string } ]         // newest first, max 20
budgets: [ { id: string, fiscal_label: string, total: string, status: string,
             is_current: boolean, enacted_at: string|null, lines: number,
             enacting_act: null | {act_number: string|null, title: string},
             line_items: [ {line, amount} ] } ]        // is_current = status 'enacted'
revenue: [ { id: string, name: string, kind: string, status: string,
             levies: [ {base: string, rate: string, civic_exempt: boolean} ],
             enacting_act: null | {act_number: string|null, title: string} } ]
clock: { interval: string, period_days: number|null,
         last_run: string|null, next_run: string|null }  // Wave 4: the economic clock, derived
totals: { supply: string, treasury_balance: string }
```

**Wave 4 (partial → built):** `revenue[].levies` (Art. V §4 — how money is
raised is public: base, rate, civic-exempt), `revenue[].enacting_act` +
`budgets[].enacting_act`/`is_current`, and the shared `clock` (the stipend
disbursement cycle, derived from `ubi_disbursements` + `stipend_period_days`;
both `last_run`/`next_run` null before a world's first run). A levy **rate is a
ratio, not money**, but crosses as a string for the same anti-float reason.

## `GET /economy/units` → `Economy/Units`

The currency and its levers. **Read-only by design** — every lever moves only through F-LEG-031,
dual-door. `enacting_act_id` is what the page should link when present.

```
currency: null | {… unit_kind, worth_basis, subdivisions all shipped …}
levers: [
  { key: string, label: string, value: string|number|boolean|null,
    dual_door: boolean,          // true for every monetary key
    citation: string,            // e.g. "Art. II §9 · [POLICY]"
    bounds: null | { min?: number, max?: number, allowed?: array },
    enacting_act: null | {act_number: string|null, title: string} }  // Wave 4: which act last moved it
]
supply: string
issuance_rate_bps: number|null
inflation_target_bps: number|null
issuer: string|null              // Wave 4: the issuing authority (root jurisdiction, by name)
clock: {…as home…}               // Wave 4: the shared economic clock
telemetry: null | {…}            // Design Round 2 ④, account-clean; null pre-currency
```

**Wave 4 (partial → built):** per-lever `enacting_act` (from `setting_changes`
→ `laws`; null while a lever sits at its constitutional default), the `issuer`
label, and the shared `clock`. `currency.unit_kind`/`worth_basis`/`subdivisions`
were already shipped in the Design-Round build and now render on units + wallet.

---

## Things I am deliberately NOT shipping in v1

- **`exchange`** (share trading) — the exchange trades organisation shares, and an ordinary org's cap
  table cannot be populated today (`acquired_via='founding'` has one writer, the CGC charter). Shipping
  a trading floor with nothing to trade would be a worse lie than an honest "planned".
- **`joint-ledgers`, `agreements`** — tables exist, no read surface yet.
- ~~**Any write endpoint.** v1 is read-only.~~ **SUPERSEDED 2026-07-26 — the write path SHIPPED.**
  `F-IND-022` (marketplace list/order/settle), `F-IND-023` (funds transfer) and `F-IND-024` (asset
  register/transfer) are registered handlers and file through `ConstitutionalEngine::file()` like every
  other form. Pages submit to the **engine**, not to a REST endpoint — there is no economy write API and
  there should not be one, because a second write path is a second set of rails to keep honest.

  What a page needs to know to build a submit:

  | Form | `payload` keys | Returns (`EngineResult::recorded`) |
  |---|---|---|
  | `F-IND-022` | `action` = `list` \| `order` \| `settle`; **list**: `kind`, `title`, `price`, `asset_id?`, `description?`, `quantity?`; **order**: `listing_id`, `quantity?`; **settle**: `order_id` | `listing_id` / `order_id` / `entry_group` + `contract_id` + `asset_id` |
  | `F-IND-023` | `to_account_id`, `amount`, `memo?`, `currency_id?` | `entry_group`, `from_account_id`, `to_account_id`, `amount` |
  | `F-IND-024` | **register**: `name`, `kind`, `description?`, `attributes?`, `quantity?`; **transfer**: `asset_id`, `to_account_id`, `quantity?` | `asset_id`, `kind`, `name`, `account_id` |

  Three rules the UI must respect, because the handlers enforce them and a page that pretends otherwise
  will show a form that always fails:
  - **Counterparties are ACCOUNT ids, never people.** There is no user picker. The only identity lookup
    is the filer resolving their own wallet, which the handler does — the page never sends it.
  - **A refusal is an answer, not an error state.** Insufficient balance, a closed listing, a buyer
    trying to settle their own order — each returns a `ConstitutionalViolation` carrying a citation.
    Render the message; do not treat it as a failed request.
  - **Only the seller settles.** Do not put a settle control on the buyer's view of an order.

## Route registration

Routes land in `routes/web.php` behind `auth`. **I will announce the `surfaces.js` one-liners on the
board rather than editing that file** — it is @lane-06's tree, and blind edits are how prop drift
starts. The rows to flip from `href: null` when the pages exist: `market`, `marketplace`, `wallet`,
`stipend`, `treasury`; and the contract's other four (`exchange`, `agreements`, `joint-ledgers`,
`units`, `org-settings`) have no registry row at all — @lane-06 confirmed 9 declared against the
mockups' set, not the 5 I first counted.

---

## Wave 2 additions (2026-07-29 — item 2 of the marching order)

### `surface` joined EVERY economy page

All economy renders now pass `'surface' => SurfaceMeta::for('<id>')` per lane 15's surface-id
contract (`K2_CONTENT_WAVE2.md`): `economy/home` · `economy/wallet` · `economy/marketplace` ·
`economy/listing-detail` · `economy/treasury` · `economy/units` · `economy/request-detail` ·
`economy/stipend`. The records live in `config/cga/surfaces.php` (first `economy` module entries);
lane 15's already-authored Learn corpus joins on these ids with no further wiring. The `stipend`
row in `registry/surfaces.js` flipped from `href: null` to `/economy/stipend`.

### `GET /economy/stipend` → `Economy/Stipend` (route `economy.stipend`)

| Key | Shape | Notes |
|---|---|---|
| `surface` | SurfaceMeta record | |
| `currency` | as everywhere | nullable |
| `stipend` | `{enabled, floor, cap, bumps{node_operator,social_moderator,office_holder}, interval, period_days?, funding_source}` | all money STRINGS; `period_days` nullable int |
| `clock` | `{last_run?, next_run_estimate?}` — `last_run = {ran_at, recipients, total, short_paid}` | both nullable (a world that has not run yet) |
| `k_anon_floor` | int | `StipendService::K_ANON_FLOOR` |
| `examples` | `[{label, roles[], base, bump, amount, capped}]` | computed by `StipendService::bumpFor` on SYNTHETIC role sets — never a real receipt; `amount = base + bump` exactly (pinned) |

### `GET /economy/requests/{posting}` → `Economy/RequestDetail` (route `economy.request`)

| Key | Shape | Notes |
|---|---|---|
| `surface` | SurfaceMeta record | |
| `currency` | as everywhere | nullable |
| `posting` | `{id, title, terms, rate?, status, org_name, applications, at}` | `rate` nullable string |
| `codetermination` | `{first_seat_at, parity_at, headcount}` all ints | **RESOLVED from settings** (`worker_rep_min_employees` / `worker_rep_parity_employees`, org's jurisdiction, root fallback) — never the 100/2000 literals |
| `can_apply` | bool | open ∧ has wallet ∧ not already applied |
| `has_applied` | bool | |

### `POST /economy/requests/{posting}/apply` → F-IND-019 (route `economy.request.apply`)

| Form | `payload` keys | Returns (`EngineResult::recorded`) |
|---|---|---|
| `F-IND-019` | `posting_id`, `note?` (≤500) | `application_id`, `posting_id`, `applicant_account_id` |

F-IND-019 (Work Application, R-01) was minted with this page — the id `ECONOMY_ENGINE_PLAN` §5.2
reserved. Form count 111 → **112**, raised deliberately in `AuditChainSmokeTest`. Applying commits
nobody: the handler is PINNED unable to reach the hire chain (`EconomyWriteFormsTest`), and
`LaborBoardService::apply` refuses duplicates. Acceptance (the org's act, which files F-IND-014 as
the applicant per the built chain) has **no web door yet** — it stays service-level; the org-side
console is item-6/Wave-3 territory and the gap is recorded, not hidden.

### `GET /economy/agreements` → `Economy/Agreements` (route `economy.agreements`) — item 4

| Key | Shape | Notes |
|---|---|---|
| `surface` | SurfaceMeta record | |
| `agreements` | `[{id, kind, org_name, counterparty, terms (≤200), status, signed_by_org, signed_by_counterparty, signed_by_org_at?, signed_by_counterparty_at?}]` | **PARTY-SCOPED**: only instruments the viewer is party to (their counterparty side · they signed for the org · active org membership). `counterparty` is `'You'` or a NAME — never a user id. |

### `GET /economy/agreements/{contract}` → `Economy/AgreementDetail` (route `economy.agreement`)

Same card shape + `terms_full`, `org_signer?` (name), `effective_at?`, `ended_at?`, `created_at?`.
**404 to a non-party** — an outsider is not told the instrument exists (pinned:
`test_an_agreement_is_invisible_to_a_non_party` / `test_a_party_sees_their_own_agreement`).

Privacy note for this plane: contracts are the CONSENT plane, not the money plane — parties see
each other by NAME (that is what a signature is); the accounts-never-people rule governs money
rows, not signatures. What stays private is the instrument: terms never reach a non-party.

READ-ONLY v1: drafting/negotiation (clauses, redlines) is the Wave 3 design-gated build; the
draft CTA is deliberately absent until then. The both-sign floor is DB-enforced
(`org_contracts_cosign_check`) and renders on every card. Nav row `agreements` flipped live.

### Item 3 — joint ledgers (2026-07-29, migration slot granted)

**Migration `2026_07_29_000020`** (one slot use, three needs folded): account plane admits
`joint_ledgers` owner kind (escrow design) + `organizations.settings` jsonb (6a's home) +
`org_restructures`/`org_restructure_consents` (6b's consent state). `down()` proven live.

**`GET /economy/joint-ledgers` → `Economy/JointLedgers`** (route `economy.joint`):
`surface` · `currency` · `ledgers[]` (`{id, name, purpose, public, approval_rule, balance
(escrow truth), escrow_account_id, parties[{account_id, role, is_me}], is_party,
movements[{id, to_account_id, amount, memo, status, approvals, needed, i_approved,
can_approve, at}]}`) · `can_open` · `my_account_id`. Visibility: public ledgers to all;
private ones to co-owners only. Money plane — parties are ACCOUNTS.

**Writes — F-IND-023 actions** (the manifest's own pairing "Funds Transfer · Joint-Ledger
Movement"): `joint_open` (POST /economy/joint-ledgers), `joint_propose`
(POST /economy/joint-ledgers/{ledger}/propose), `joint_approve`
(POST /economy/joint-movements/{movement}/approve). Proposing signs; the approval that
meets the rule settles IN THE SAME ACT (consent and settlement are one transaction — an
underfunded escrow refuses and the refused approval is not recorded; pinned).

**THE DESIGN**: the ledger's money lives in an escrow economic account owned by the ledger
row. Funding = plain F-IND-023 transfer to the escrow. Settlement = AccountService transfer
escrow→recipient. `joint_ledgers.balance` is a cached mirror of the escrow truth.
JointLedgerService writes ONLY the joint governance tables — the fleet-wide ledger write
scan proves it. `AccountService::open` (PROTECTED) gained the `joint_ledgers` owner kind —
announce-extend-pin, migration-backed. Pins: `JointLedgerTest` (rule gates settlement ·
non-party refused · one signature per signer · no-overdraft parity · majority = ⌊N/2⌋+1 ·
mirror = escrow). Demo: `institutions:demo-treasury` step 8b seeds a funded ledger with a
movement awaiting its second signature. Nav row `joint-ledgers` flipped live.

### Item 5 — additive props on live pages (2026-07-29)

| Where | Added | Notes |
|---|---|---|
| `currency` (every page) | `unit_kind`, `worth_basis?`, `subdivisions[]` | the measurement-standards power; `[]`/null honest when unexercised. Wallet + Units grew subdivision sections. |
| market `offers[]` + listing | `seller_org: {name, type, is_cgc} \| null` | **the identity boundary, drawn exactly**: an ORGANIZATION seller resolves to its public name (its listing is its public act; CGC badge informational — identical terms, Art. III §5); a HUMAN seller never resolves past the account. Pinned both directions. |
| treasury `budgets[]` | `line_items: [{line, amount}]` (≤50) | where public money is DIRECTED — the half a count cannot show |
| treasury | `borrowings: [{id, principal, terms, status, lender_account_id?, at}]` | Art. V §4 jurisdiction instruments; lenders are ACCOUNTS |
