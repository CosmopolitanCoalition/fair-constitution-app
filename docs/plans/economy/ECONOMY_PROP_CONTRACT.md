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
             enacted_at: string|null, lines: number } ]
revenue: [ { id: string, name: string, kind: string, status: string } ]
totals: { supply: string, treasury_balance: string }
```

## `GET /economy/units` → `Economy/Units`

The currency and its levers. **Read-only by design** — every lever moves only through F-LEG-031,
dual-door. `enacting_act_id` is what the page should link when present.

```
currency: null | {…}
levers: [
  { key: string, label: string, value: string|number|boolean|null,
    dual_door: boolean,          // true for every monetary key
    citation: string,            // e.g. "Art. II §9 · [POLICY]"
    bounds: null | { min?: number, max?: number, allowed?: array } }
]
supply: string
issuance_rate_bps: number|null
inflation_target_bps: number|null
```

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
