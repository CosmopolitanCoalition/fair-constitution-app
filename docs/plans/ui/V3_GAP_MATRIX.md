# V3 Gap Matrix — the app measured against the 107-screen specification

*Generated 2026-07-28 by the v3 synthesis investigation (14 recon agents + 1 repair agent, charter: `V3_SYNTHESIS_CHARTER.md`). One row per `mockups/v3/manifest.json` record. The mockups are the spec (operator ruling 2026-07-28); divergences where the APP is ahead or where a mockup contradicts a settled ruling are logged in `V3_SYNTHESIS_PLAN.md` §Reconciliation, never silently decided.*

**Column key** — *In app*: the production Vue page exists · *Route*: it is served · *Props*: controller ships what the screen displays (full/partial/none) · *Backend*: the capability behind the screen exists (forms/services/tables) · *Effort*: S copy/props · M one-surface rework · L new page+wiring · XL new subsystem.

## Rollup

| | count |
|---|---:|
| Conformant (or better) | 43 |
| Partial | 36 |
| Absent (no page) | 28 |
| Redirect stubs | 0 |
| **Total** | **107** |

| Effort to close | screens |
|---|---:|
| none | 18 |
| S | 35 |
| M | 27 |
| L | 21 |
| XL | 6 |

---

## civic

Civic is the strongest-conformed module: residency, petitions, and petition-detail are fully wired through the ConstitutionalEngine and exceed the mockups (real Leaflet/PostGIS point-first declare, CLK-17 threshold snapshots, F-ELB-005 audit and F-JDG-008 review cards), and onboarding plus the invite landing conform with one documented deliberate delta (pick-a-name moved to Register). Headline gap 1: the today feed knows only four row kinds (election/session/petition/referendum) and jurisdiction-only calendar events — the mockup's hearings, forum calls, group meetings, stipend and trade rows plus org/resident calendar events need a TodayFeedService extension over data that already exists. Headline gap 2: the unified profile (/civic/record) lacks the Office and Groups & orgs tabs, the overview open-votes list, and its Wallet tab still shows a Planned banner despite the economy ledger being live since 2026-07-26 — stale by the operator's own staleness warning. Headline gap 3: two honestly-deferred Phase F backends — relocation's away-pattern detection (detection prop ships null) and identity-verification's external ID bridge (manual attestation only). Headline gap 4: advocate registration is instant with a free-text note, missing the mockup's prerequisite checklist, court-of-chain selector, qualification checkboxes, and pending-judiciary-review lifecycle — a policy-shaped question to surface at synthesis, not silently implement. Cross-cutting staleness: PetitionController copy still labels the judiciary "forming · Phase E" although Phase 5 courts are built.

| Screen | In app | Route | Props | Backend | Effort | Headline |
|---|---|---|---|---|---|---|
| `civic/join.html` You're invited | Invite/Landing.vue | `GET /i/{token}` | partial | full | **S** | pick-a-name guest entry — DELIBERATELY dropped (documented decision in Landing.vue: nam... |
| `civic/today.html` Home — today | Civic/Home.vue | `GET /civic` | partial | partial | **M** | scenario row kinds listed above |
| `civic/my-civic-life.html` My profile | Civic/MyRecord.vue | `GET /civic/record` | partial | full | **M** | Office tab |
| `civic/advocate-registration.html` Advocate registration | Judiciary/AdvocateConsole.vue (registration card; suggested Civic/AdvocateRegistration.vue does not exist) | `GET /judiciary/advocate` | partial | partial | **M** | prerequisite checklist section |
| `civic/identity-verification.html` Link an ID (optional) | Civic/IdentityVerification.vue | `GET /civic/identity` | partial | partial | **M** | branch-preview toggle (automatic vs in-person) |
| `civic/onboarding.html` Create your account | Auth/Register.vue | `GET /register` | full | full | **S** | 3-step stepper nav with links/skip (app has the 'step 1 of 3' eyebrow but no stepper ol... |
| `civic/petition-detail.html` Petition detail | Civic/PetitionDetail.vue | `GET /civic/petitions/{petition}; POST/DELETE /petitions/{petition}/signatures` | full | full | **S** | conformant |
| `civic/petitions.html` Petitions | Civic/Petitions.vue | `GET/POST /civic/petitions` | full | full | **S** | free-text Scope field on the create form (app derives scope_label instead — likely deli... |
| `civic/relocation.html` Move somewhere new | Civic/Relocation.vue | `GET /civic/relocation; POST /civic/relocation/travelling` | partial | partial | **L** | sustained-pings-detected banner + qualifying-days-away meter + illustrative two-boundar... |
| `civic/residency.html` Say where you live | Civic/Residency.vue | `GET /civic/residency; POST locate/declare/confirm/redeclare + /civic/pings` | full | full | **none** | conformant |

### `civic/join.html` — You're invited
- **Props missing:** online-presence count for the room preview (app ships memberCount, not who is inside now); live status pill (Meeting now / open) — app shows privacy only
- **Spec has, app lacks:** pick-a-name guest entry — DELIBERATELY dropped (documented decision in Landing.vue: name is asked once, on Register, which adapts when an invite is in flight); live 'people inside now' + meeting-status on the preview card
- **App has, spec lacks (reconcile, don't strip):** invalid/expired-link fail-soft landing (never dead-ends); signed-in auto-redeem + redirect; private-room access grant on space invites; destination carried across signup/login (url.intended + pending_invite)
- **Notes:** Well conformed with one documented intentional delta (the name input). Only gap worth closing is live presence/status on the preview card — do not 're-fix' the name-drop decision.

### `civic/today.html` — Home — today
- **Props missing:** feed rows for hearings, forum/commons calls, group meetings, stipend (UBI) and trade — TodayFeedService only emits election/session/petition/referendum kinds; org- and resident-hosted calendar events (calendar() emits kind 'jurisdiction' only)
- **Backend missing:** feed sources over judiciary hearings, private-room/group meetings, live commons calls, and economy stipend runs — the underlying tables/services all exist (court cases, social_spaces, Matrix commons, Economy ledger), only the feed aggregation misses them
- **Spec has, app lacks:** scenario row kinds listed above; org/resident calendar chips; 'money items are previews' footnote
- **App has, spec lacks (reconcile, don't strip):** 'On the record' section (latest public-record heads); leading residency-claim card with live qualifying-days meter; my-record stat row; shell-wide emergency-banner slot
- **Notes:** Page structure, countdowns, rights section and calendar grouping all match; the gap is feed breadth. Extending TodayFeedService with hearing/group/commons/stipend sources is one service rework, no new schema.

### `civic/my-civic-life.html` — My profile
- **Props missing:** Office tab data (held seat, office hours/open meetings — LegislatureMember data exists, not shipped); Groups & orgs tab (social_spaces + organization memberships); Overview open-votes list (mockup shows open ballots; app overview is stats + roles only); endorsements given on the Record tab; wallet balances (Economy ledger is LIVE since 2026-07-26 but the tab still ships a Planned banner)
- **Spec has, app lacks:** Office tab; Groups & orgs tab; open-votes list on Overview; wallet tab wired to the real Economy/Wallet data; endorsements-given cards
- **App has, spec lacks (reconcile, don't strip):** Settings tab (F-IND-002 panel through the engine); paginated hash-chained audit slice with rejected/blocked reasons; server-side tab validation
- **Notes:** The tab skeleton matches profile-v2.js; gaps are three missing tabs/panels whose backing data already exists. The Planned wallet banner is STALE per the economy L+M ship — wiring it is props work, not a subsystem.

### `civic/advocate-registration.html` — Advocate registration
- **Props missing:** prerequisites checklist facts (judiciary-exists / residency-met badges); jurisdiction-of-practice selector (app auto-resolves ONE judiciary via registerTargetId); jurisdiction-law qualification items (three attestation checkboxes); pending-judiciary-review status
- **Backend missing:** qualification-review lifecycle — F-IND-015 registers INSTANTLY with a free-text qualifications_note; no pending→approved state, no per-jurisdiction qualification catalog
- **Spec has, app lacks:** prerequisite checklist section; court-of-chain select; three-checkbox qualification gate blocking submit; pending-review confirmation state
- **App has, spec lacks (reconcile, don't strip):** the full advocate console rides on the same page (F-ADV-001..004 composer, my-cases list, filings log) — mockup only links onward to it
- **Notes:** Form and handler exist; the delta is the registration UX (checklist, court choice, review-pending state). Whether jurisdiction-law review is required is a policy question the mockup asserts and the app does not implement — flag for synthesis, don't silently decide. Manifest itself notes R-21/R-22 catalog drift.

### `civic/identity-verification.html` — Link an ID (optional)
- **Props missing:** supported-bridge branch data (ID types per jurisdiction, doc-number submit, encrypted yes/no result); 4-state Individual journey strip (app machine ships only registered→identity_verified); onboarding stepper context (step 2 of 3)
- **Backend missing:** external ID bridge subsystem — per-jurisdiction automatic check; explicitly deferred to Phase F federation; only the manual attestation-request stub files today
- **Spec has, app lacks:** branch-preview toggle (automatic vs in-person); bridge form; stepper nav; full journey strip
- **App has, spec lacks (reconcile, don't strip):** persistent 'requested, pending' state read back from the audit chain across visits; declared-jurisdiction context on the request
- **Notes:** Screen-level conformance (stepper, journey strip, branch UI with the bridge branch honestly gated) is M; the bridge BACKEND itself is a Phase F subsystem (XL) already planned there. The never-a-rights-requirement banner — the page's most important element — is present.

### `civic/onboarding.html` — Create your account
- **Spec has, app lacks:** 3-step stepper nav with links/skip (app has the 'step 1 of 3' eyebrow but no stepper ol linking to identity/residency)
- **App has, spec lacks (reconcile, don't strip):** email + password credentials (mockup asks name only — app correctly requires auth credentials); invite-continuation banner + carried destination; browser-guessed timezone via Intl
- **Notes:** Conformant: languages multiselect, timezone, terms with inline error, Individual state strip, engine-sealed registration. Only the stepper navigation is missing.

### `civic/petition-detail.html` — Petition detail
- **App has, spec lacks (reconcile, don't strip):** audit-record deep link into /system/audit-chain; on-ballot card linking the spawned referendum election; revocable signature (mockup detail page only signs); creator attribution
- **Notes:** Fully wired: 9-state lifecycle, law-text blockquote, scale/scope grid, frozen-count rule, F-ELB-005 audit card, F-JDG-008 review card. S is for STALE copy only — scopeLabel/reviewProps still say 'judiciary (forming · Phase E)' though Phase 5 courts and the F-JDG-008 handler are live.

### `civic/petitions.html` — Petitions
- **Spec has, app lacks:** free-text Scope field on the create form (app derives scope_label instead — likely deliberate: scope rides the law, not prose); act-type choice surfaced (actTypes prop ships but the form transform hardcodes 'ordinary')
- **App has, spec lacks (reconcile, don't strip):** signature revocation on the list toggle (real F-IND-010 revoke); per-scale live threshold preview from CIVIC population + per-jurisdiction resolved pct (mockup hardcodes 5%); un-associated viewer read-only posture with residency CTA (never 403s)
- **Notes:** Conformant and richer than the mockup (snapshot thresholds · CLK-17, amendable chip with setting key). Close the act-type exposure and decide the scope-field question at synthesis.

### `civic/relocation.html` — Move somewhere new
- **Props missing:** detection — ships honestly null (away-pattern banner, 9-of-30 away meter, home-vs-detected map all render empty state)
- **Backend missing:** away-pattern detection over sustained pings outside the home boundary — deferred to Phase F/6 mobile geofencing; location_pings infra exists, the detection sweep does not
- **Spec has, app lacks:** sustained-pings-detected banner + qualifying-days-away meter + illustrative two-boundary map (all gated on detection data)
- **App has, spec lacks (reconcile, don't strip):** real audited 'I'm travelling' declaration on the chain; held-office grace computed from the REAL new claim's CLK-05 progress (mockup hardcodes day 9 of 30); move path reusing live F-IND-003 redeclare
- **Notes:** The surface, travel/move fork, grace card and countback citation are all wired; the missing half is the detection service that makes the screen fire on its own. Meter grammar is already in place for the day detection arrives.

### `civic/residency.html` — Say where you live
- **App has, spec lacks (reconcile, don't strip):** point-first declare (browser geolocation or picker-map click → PostGIS smallest-containing resolution); REAL Leaflet boundary + picker maps with PMTiles basemap (mockup admits its SVG is a stand-in); manual ping via browser geolocation + dev ping simulator; jurisdiction text search; per-jurisdiction resolved threshold with code fallback
- **Notes:** Fully conformant and exceeds the spec — the mockup's own about-panel says production uses Leaflet + PostGIS, which is exactly what shipped. All three F-IND forms file through the engine; three-state confirm panel and rights-unlock moment match.

---

## economy

Six of thirteen economy screens are live and routed (Home, Marketplace, Listing, Wallet, Treasury, Units) on EconomyController + EconomyActionController, with the F-IND-022/023/024 write paths filing through the ConstitutionalEngine — their gaps are additive props (CGC/seller identity resolution, currency subdivisions, budget lines, borrowings) and static teaching chrome, mostly S/M. Two screens are backend-complete but pageless and are the cheapest large wins: request-detail (LaborBoardService + the pinned F-IND-014 co-determination chain, just unrouted) and stipend (StipendService implements the mockup's exact formula, classes, cap, and k-anon). Joint-ledgers has all three tables shipped but zero code over them; agreements/agreement-detail have a real substrate (org_contracts with a DB-enforced both-sign constraint) but no unified register, and the clause-negotiation panel is an XL subsystem shared with bills. The exchange is a designed-ahead XL subsystem with no backend at all (F-IND-018..021 reserved/unregistered), and org-settings' economics half (share register, dues) is likewise XL while its governance half already lives under Organizations/*. Cited-but-unregistered forms to reconcile in the mockups: F-TRE-001..004, F-LEG-037/038, F-ORG-008, F-IND-018..021. One settled-ruling conflict needs a mockup-side fix: wallet's name-based transfer picker violates the reader-privacy accounts-never-people boundary the shipped write path enforces.

| Screen | In app | Route | Props | Backend | Effort | Headline |
|---|---|---|---|---|---|---|
| `economy/economy-home.html` The exchange — the economy home | Economy/Home.vue | `/economy` | partial | partial | **M** | exchange hero with live ticker + 'Enter the trading floor' CTA |
| `economy/exchange.html` The exchange — the trading floor | — | `—` | none | none | **XL** | everything: scrolling ticker marquee |
| `economy/marketplace.html` The open market | Economy/Market.vue | `/economy/market` | partial | partial | **M** | CGC 'identical terms' badge on offers |
| `economy/listing-detail.html` Listing detail | Economy/Listing.vue | `/economy/market/{listing}` | partial | full | **S** | CGC badge + identical-terms note |
| `economy/request-detail.html` Work posting detail | — | `—` | none | full | **L** | dedicated posting page: hero (rate/org/recurring) |
| `economy/wallet.html` My wallet — private | Economy/Wallet.vue | `/economy/wallet` | partial | partial | **S** | 'unit you hold' subdivisions section |
| `economy/joint-ledgers.html` Joint ledgers — agreement-gated | — | `—` | none | partial | **L** | everything: ledger cards with balance + approval rule |
| `economy/units.html` Units & monetary policy | Economy/Units.vue | `/economy/units` | partial | partial | **M** | subdivisions section (the measurement-standards power) |
| `economy/stipend.html` Civic stipend — UBI + role differential | — | `—` | none | full | **L** | dedicated page: formula block (base + min(Σ bumps, cap)) |
| `economy/treasury.html` Public finance — the public ledger | Economy/Treasury.vue | `/economy/treasury` | partial | partial | **M** | budget-cycle stepper naming each act |
| `economy/agreements.html` Instruments of agreement | — | `—` | none | partial | **L** | agreements list page: kind-grouped cards with status pills, party chips, terms, signed-... |
| `economy/agreement-detail.html` Agreement detail | — | `—` | none | partial | **XL** | everything: agreement card with form chip |
| `economy/org-settings.html` Organization economics | — | `—` | none | partial | **XL** | the whole steward page: board/worker-seats summary (covered elsewhere by Organizations/... |

### `economy/economy-home.html` — The exchange — the economy home
- **Props missing:** economic clock (next-run timestamp — interval/last_run shipped, next run not computed); exchange ticker instruments (no backend); surface-hub card data (app hardlinks only market + treasury)
- **Backend missing:** exchange/instruments subsystem (F-IND-018..021 unregistered); next-run clock derivation (trivial from stipend_interval + last_run)
- **Spec has, app lacks:** exchange hero with live ticker + 'Enter the trading floor' CTA; economic clock card with next-run; nine-surface 'Where to go' role-card grid; 'rails that hold everywhere' list card
- **App has, spec lacks (reconcile, don't strip):** ledger chain-verification residual health stat (verified/residual-zero check); stipend short_paid shortfall explanation; no-currency-yet empty state
- **Notes:** The page is a solid conformant core; the hub grid and clock are one-surface rework. The exchange hero should degrade gracefully until/unless the XL exchange ships.

### `economy/exchange.html` — The exchange — the trading floor
- **Backend missing:** instruments/share register; order book + matching; trade tape/history; org share issuance and fair-market pricing; F-IND-018..021 (unregistered; manifest itself marks them 'reserved')
- **Spec has, app lacks:** everything: scrolling ticker marquee; order-book ladder with depth bars; streaming trade tape (reduced-motion-safe); price sparklines + KPIs; trader presence strip; order ticket; goods cross-links to marketplace
- **Notes:** Manifest stage 'M' and the mockup's own note say liveness is simulated and forms reserved — this is a designed-ahead subsystem. Nothing in FormRegistry, services, or tables supports share trading today.

### `economy/marketplace.html` — The open market
- **Props missing:** seller display identity/kind (only seller_account_id ships); CGC flag per listing (derivable via economic_account_bindings→organizations.organization_type); tag chips (no column)
- **Backend missing:** work-application web route (LaborBoardService::apply/accept/decline exist but are unrouted); listing tags column; F-IND-021 cited by manifest is unregistered (F-IND-022 covers list+order)
- **Spec has, app lacks:** CGC 'identical terms' badge on offers; seller name + kind chips; tag chips; Apply control and 'On acceptance' trigger note on work cards; two-tab offers/requests contract (?tab=requests merges work + mutual aid; app uses three tabs and honors only its own keys)
- **App has, spec lacks (reconcile, don't strip):** inline live F-IND-022 composer with unlisted-asset picker (mockup routes creation to listing-detail?create=1); per-posting application counts; constitutional-refusal and flash banners; tab counts
- **Notes:** Core market is live end-to-end; the M is the Apply write surface (route + wiring to the already-pinned F-IND-014 chain) plus CGC/seller props. Tab semantics differ but both honor ?tab deep-links.

### `economy/listing-detail.html` — Listing detail
- **Props missing:** seller name/kind + CGC flag (account id only); tags
- **Spec has, app lacks:** CGC badge + identical-terms note; 3-step order-flow stepper (order → agreement → private settlement; static teaching content); 'rails on every order' card; ?create=1 create mode (app puts the composer on Market instead — acceptable divergence)
- **App has, spec lacks (reconcile, don't strip):** seller-only pending-orders queue with live settle (F-IND-022 settle — the mockup has no settlement flow at all); can_order gating and orders count; refusal banners carrying citations
- **Notes:** The full order→agreement→settle chain is live; remaining gaps are prop additions (CGC/seller resolution in the controller) and static stepper copy. The app is functionally AHEAD of the mockup here.

### `economy/request-detail.html` — Work posting detail
- **Spec has, app lacks:** dedicated posting page: hero (rate/org/recurring); two-parties card with both-must-sign floor; 4-step lifecycle stepper (apply → org accepts → F-IND-014 recorded → headcount/co-determination); co-determination threshold panel (100/2000); apply CTA; privacy note
- **Notes:** Cheapest large win in the module: LaborBoardService (post/apply/accept/decline) and the F-IND-014 → co-determination chain are built and pinned (WorkerRepresentationTest, LaborBoardTest) — only the page, route, and controller are missing. Market.vue's work tab shows postings but offers no detail or apply.

### `economy/wallet.html` — My wallet — private
- **Props missing:** currency subdivisions ('the unit you hold' chips — no backend model); worth-basis text
- **Backend missing:** currency subdivisions/worth-basis columns (currencies table carries precision only)
- **Spec has, app lacks:** 'unit you hold' subdivisions section; recipient picker by @handle/org name — CONFLICTS with the settled reader-privacy ruling (accounts never people); the mockup should change, not the app; joint-ledgers and public-ledger cross-links; 'why your wallet stays yours' rails card
- **App has, spec lacks (reconcile, don't strip):** F-IND-024 asset-registration composer + 'things you hold' provenance table; stipend receipts table (base/bump/paid); own-account-id display for receiving; no-wallet/no-currency empty states; constitutional-refusal banner (no-overdraft as a cited answer)
- **Notes:** Both write forms are live through the engine and the page is richer than the mockup. Flag for the synthesis pass: the mockup's name-based transfer picker contradicts the account-only privacy boundary enforced in EconomyActionController — a mockup-side correction.

### `economy/joint-ledgers.html` — Joint ledgers — agreement-gated
- **Backend missing:** any service/handler/form over joint_ledgers/joint_ledger_parties/joint_ledger_movements (tables shipped in 2026_07_25_000030_create_economy_plane.php but nothing in app/ reads or writes them); propose/approve/settle movement workflow; new-ledger creation flow
- **Spec has, app lacks:** everything: ledger cards with balance + approval rule; co-owner signer chips (person/org/jurisdiction); pending movements with n-of-m approval progress; approve action; new-joint-ledger flow; public-vs-private ledger split
- **Notes:** The schema is fully laid (including the Art. V §2 comment in the migration) but the movement-approval engine, a form, and the whole read/write surface are unbuilt. L rather than XL because the tables and the engine-filing pattern already exist to copy.

### `economy/units.html` — Units & monetary policy
- **Props missing:** subdivisions; worth basis + standards basis; issuer label; per-lever enacting act (app ships the constitutional citation instead); economic clock next-run
- **Backend missing:** currency subdivision/worth-basis/standards-basis model; per-lever enacting-act provenance (which F-LEG-031 act set the current value — laws exist, the join isn't shipped)
- **Spec has, app lacks:** subdivisions section (the measurement-standards power); worth/standards basis cards; monetary-policy-act warning banner; economic clock card ('the interval is itself a lever'); currency-agnostic note
- **App has, spec lacks (reconcile, don't strip):** constitutional bounds per lever (min/max/allowed from SETTING_BOUNDS); supply-in-circulation stat; honest boolean/null value rendering
- **Notes:** The dual-door lever posture is correctly implemented read-only against MONETARY_KEYS/DUAL_DOOR_KEYS; the M is a small currency-model migration (subdivisions, worth basis) plus the enacting-act join.

### `economy/stipend.html` — Civic stipend — UBI + role differential
- **Spec has, app lacks:** dedicated page: formula block (base + min(Σ bumps, cap)); residency-only eligibility floor note; economic clock (last/next run); three differential class cards (node operator / moderator / office-holder); current values + 'propose a change' CTA (drafts F-LEG-031, never saves); worked persona examples; public-aggregate vs private-receipt split panel; rails list
- **Notes:** StipendService is complete (formula, the exact three classes as pay_* settings, cap, k-anon suppression, short-pay pro-rating) and fragments already render on Home and Wallet — only the dedicated page + controller method are missing. Manifest's F-TRE-004 is unregistered; the real change path is F-LEG-031 dual-door, which exists.

### `economy/treasury.html` — Public finance — the public ledger
- **Props missing:** budget lines with per-line amounts (only a line COUNT ships); revenue rate/base/civic-exempt detail (name/kind/status only); borrowings (table exists, never queried); budget-cycle state; economic clock
- **Backend missing:** budget-enactment/appropriation forms — manifest's F-LEG-037/038 and F-TRE-001..003 are all unregistered (BudgetService + budgets/budget_lines/revenue_streams/levies/borrowings tables exist, seeded by treasury demo); paired double-entry presentation (ledger_entries are single-direction rows; the mockup shows debit+credit per row)
- **Spec has, app lacks:** budget-cycle stepper naming each act; debit+credit paired columns in the ledger table; budget lines table with amounts + total; revenue cards with rate/base/never-on-a-civic-right badge; borrowing cards (gated, drawdown status); disbursement-cadence clock; rails card
- **App has, spec lacks (reconcile, don't strip):** issuance history table (issued/withdrawn with reason); supply + treasury-balance totals; chain-verification cross-link to the economy overview
- **Notes:** Read surface is live and honest; closing it is mostly controller props (lines, revenue detail, borrowings) plus presentation. The budget-cycle WRITE path (enactment forms) is a separate unbuilt lane the mockup only references by chip.

### `economy/agreements.html` — Instruments of agreement
- **Backend missing:** unified agreements register across kinds (labor exists as org_contracts with a DB-enforced both-sign CHECK; sale as marketplace_orders; ownership via F-ORG-005/org_conversions); person-to-person 'custom' agreements (org_contracts requires an organization side); joint-ledger compact kind (see joint-ledgers row)
- **Spec has, app lacks:** agreements list page: kind-grouped cards with status pills, party chips, terms, signed-by-all badge, floor strip; draft-a-new-agreement kind picker (labor/ownership/joint-ledger/sale/custom); kinds reference table; parties-only privacy note
- **Notes:** An aggregation page over existing records (org_contracts + marketplace_orders + org_conversions) gets most of the mockup; true free-form person-to-person contracts are the one new backend piece. The both-parties-sign floor already exists as a schema constraint, not just copy.

### `economy/agreement-detail.html` — Agreement detail
- **Backend missing:** negotiation/redline subsystem (clauses, per-clause edits, accept/reject, comments — mockup mounts negotiate-v2.js, shared with bills; nothing equivalent exists server-side); generic agreement object with sign/decline endpoints (signing exists only inside each kind's own flow)
- **Spec has, app lacks:** everything: agreement card with form chip; clause-level negotiation panel with redlines; party chips + signer ledger (signed/awaiting); the floor card (void-in-part rule); labor co-determination consequence panel; sign/decline decision block; breadcrumb
- **Notes:** The clause-negotiation interface is a new subsystem (and the mockup expects it shared with bill drafting, doubling its scope). Per-kind signing machinery exists to anchor it, but nothing generic.

### `economy/org-settings.html` — Organization economics
- **Backend missing:** share register (classes/counts/holders/fair-market price — nothing exists; only org_memberships kind='shareholder' and org_conversions.fair_market_floor/basis); dues model (dues exist only as a market_transactions kind); F-ORG-008 'Organization economic settings' (unregistered; registry ends at F-ORG-007); nomination-window dial settings; org-scoped ledger read surface; levies/tax_filings read surface (tables exist, no controller queries them)
- **Spec has, app lacks:** the whole steward page: board/worker-seats summary (covered elsewhere by Organizations/CoDetermination + BoardElections); elections nomination-window dials; shares table + fair-market + monopoly-target flag + conversion rule; dues rows + never-a-civic-gate rails; private org ledger card + inter-org units note; taxes/levies table with civic-exempt badges
- **App has, spec lacks (reconcile, don't strip):** Organizations/TransfersConversions and CoDetermination pages carry the conversion rule and board math with live data the mockup only summarizes
- **Notes:** Governance sections already live under Organizations/*; the economics half (shares, dues, org ledger view, tax surface) needs new backend models plus a new page. The shares register is the genuinely new subsystem.

---

## electoral-exec

Electoral + executive are the most conformant modules audited: all 12 screens exist, are routed, and sit on substantive controllers that file every mutation through the ConstitutionalEngine; 7 of 12 rows are full/full on props and backend, and several app pages exceed their mockups (real STV CSV record, real receipt-check verifier, real order-rejection exit surface, real consent-vote snapshots). The three real gaps: (1) RankedBallot's live first-preference aggregate — the controller hard-ships liveAggregate=null pending a backend work item, so the mockup's signature "if the window closed now" section never renders (M); (2) DepartmentDetail's CGC oversight card — the backend has no department-to-CGC link (oversight is modeled at executive level only), so oversees_cgcs is always empty (M); (3) DepartmentReporting lacks the mockup's opening "Your appointment" card because the viewer's seat/term props are not shipped (S). A recurring small gap is the emergency scenario banner: the mockups render it on board-console, election-detail, executive-home and election pages ("elections cannot be disrupted"), but no Elections/Executive page ships an emergency-power prop — only Actions' scope banner covers it. Two stale-copy defects mislead: CandidacyRegistration still marks the court appeal "Planned · Phase E" (judiciary is built) and VacancyCountback claims F-LEG-036 "arrives with Phase C" (it is registered with a live handler). One open design question for the operator: ExecutiveHome's three-model explorer toggle and Departments' in-page creation-act composer both diverge deliberately (data-driven live model; bill-flow deep link with no side-door POST) — the app posture looks correct, but the mockup-as-spec ruling means those divergences should be ratified, not assumed.

| Screen | In app | Route | Props | Backend | Effort | Headline |
|---|---|---|---|---|---|---|
| `electoral/candidacy-registration.html` Candidacy registration | Elections/CandidacyRegistration.vue | `GET /elections/{election}/candidacy` | full | full | **S** | Rejection path's court-appeal deep link (mockup links judiciary/case-docket + Pham prec... |
| `electoral/election-board-console.html` Election board console | Elections/BoardConsole.vue | `GET /board` | full | full | **S** | Emergency scenario banner asserting elections cannot be disrupted (no emergency-power p... |
| `electoral/election-detail.html` Election detail | Elections/ElectionDetail.vue | `GET /elections/{election}` | full | full | **S** | Emergency scenario banner ('this election proceeds untouched') |
| `electoral/open-ballot.html` Open ballot — approval phase | Elections/OpenBallot.vue | `GET /elections/{election}/open-ballot` | full | full | **none** | conformant |
| `electoral/ranked-ballot.html` Ranked ballot — F-IND-007/008 | Elections/RankedBallot.vue | `GET /elections/{election}/ranked-ballot` | partial | partial | **M** | Live aggregate section with quota-if-closed-now projection |
| `electoral/results.html` Results — round-by-round STV | Elections/Results.vue | `GET /elections/{election}/results` | full | full | **none** | conformant |
| `electoral/vacancy-countback.html` Vacancy countback | Elections/VacancyCountback.vue | `GET /vacancies/{vacancy}` | full | full | **none** | conformant |
| `executive/department-detail.html` Public Works & Utilities — department detail | Executive/DepartmentDetail.vue | `GET /departments/{department}` | partial | partial | **M** | CGC oversight listing on the charter card (renders honest empty state) |
| `executive/department-reporting.html` Department reporting — R-18 governor surface | Executive/DepartmentReporting.vue | `GET /departments/{department}/reporting` | partial | full | **S** | 'Your appointment' card with term dates and the CLK-09 amendable pattern |
| `executive/departments.html` Department registry — Charlotte | Executive/Departments.vue | `GET /executives/{executive}/departments` | full | full | **S** | In-page 'draft creation act' composer (name/type/oversight/charter fields) — the app de... |
| `executive/executive-actions.html` Executive actions — orders, proposals, investigations | Executive/Actions.vue | `GET /executives/{executive}/actions` | full | full | **none** | conformant |
| `executive/executive-home.html` Executive home — three model variants | Executive/Home.vue | `GET /executives/{executive}` | full | full | **S** | Three-model aria-pressed toggle exploring non-live variants (app renders only the route... |

### `electoral/candidacy-registration.html` — Candidacy registration
- **Spec has, app lacks:** Rejection path's court-appeal deep link (mockup links judiciary/case-docket + Pham precedent; app says 'challenge in court — Planned · Phase E', a stale flag now that Phase 5 judiciary is built)
- **App has, spec lacks (reconcile, don't strip):** No-residency empty state with declare-residency CTA; Engine 422 banner surfacing the constitutional citation; Org-board election bounce (Art. III §6 class-gated races redirected); Withdrawn/defeated terminal-branch state strips
- **Notes:** Near-conformant; F-IND-011 files through the engine with footprint-scoped office select, tags, attestation, CLK-18 window. Only gap is wiring the rejection banner to the live court docket and removing the stale Planned flag.

### `electoral/election-board-console.html` — Election board console
- **Spec has, app lacks:** Emergency scenario banner asserting elections cannot be disrupted (no emergency-power prop on this page); District-oversight card deep link to the district mapper (app links the legislature browser instead; F-ELB-008 mapper exists)
- **App has, spec lacks (reconcile, don't strip):** Multi-board picker (?board=) for members of several boards; Petition audit result figures (checked/valid/pct/still-above) and kill-path status; Real duplicate-registration detection across open elections; Bootstrap board derived from data rather than a demo toggle
- **Notes:** All six F-ELB forms are live engine surfaces; the four-stat strip, validation queue, certify-then-recount gating and F-ELB-005 audit all match. Two small chrome gaps: the emergency banner and the mapper deep link.

### `electoral/election-detail.html` — Election detail
- **Spec has, app lacks:** Emergency scenario banner ('this election proceeds untouched')
- **App has, spec lacks (reconcile, don't strip):** CLK-01 empty state with the armed cycle timer; Art. II §8 subdivision-blocked banner from racePlan(); Real boundary map render (mockup ships a stylized SVG stand-in); Certification record card with certifier provenance
- **Notes:** Full state machine, schedule rows with done/current/upcoming, interval + finalist-multiplier amendable patterns, scheduling-order provenance off the audit chain. App exceeds the mockup in several places; only the emergency banner is absent.

### `electoral/open-ballot.html` — Open ballot — approval phase
- **App has, spec lacks (reconcile, don't strip):** Standings payload cap with ?full=1 lift at Earth scale; Race picker for multi-race elections; Footprint gate (browse-not-approve outside the race) with secrecy-preserving rejection; Daily-rollup asOf discipline (viewer's own action never moves the public number)
- **Notes:** The flagship screen is conformant: filter bar (endorser/tags/incumbents/search/clear), FinalistLine at full-race rank X, revocable approval switch, deltas, secrecy banner. Alignment questionnaire is future-flagged in both.

### `electoral/ranked-ballot.html` — Ranked ballot — F-IND-007/008
- **Props missing:** liveAggregate — controller ships null 'until its backend WI lands'; the mockup's 'Live aggregate — if the window closed now' section (first preferences + quota-if-closed-now) renders nothing
- **Backend missing:** Live first-preference aggregate service (secrecy-safe rollup) feeding the liveAggregate prop
- **Spec has, app lacks:** Live aggregate section with quota-if-closed-now projection; First-ballot achievement toast (mockup-flagged 'Proposed — gamification layer'; K-2 achievements engine exists but is not wired here)
- **App has, spec lacks (reconcile, don't strip):** Search-driven write-in lookup via Inertia partial reload (mockup enumerates a small select); Real receipt flow: single-pull flash of {hash, salt} + public anonymized /receipt-check verifier; already-voted state with committed_at; Referendum question sourced from the real F-LEG-023/petition pipeline
- **Notes:** Click-to-rank, write-in, review→commit→receipt and the referendum variant are all live and engine-backed. The one real gap is the live aggregate backend; the achievement toast is a Proposed-layer decision.

### `electoral/results.html` — Results — round-by-round STV
- **App has, spec lacks (reconcile, don't strip):** Real full-precision CSV count record (mockup's CSV button is a stub); Audit re-run (recount) record rendered alongside the certified count; Certification count_record_hash + audits list with cause/outcome; Tabulating/none in-flight states with poll posture
- **Notes:** Round-by-round StvRoundPresenter contract (key rounds inline, mid rounds collapsed), write-in footnote, observers chain-of-custody table, single-winner RCV variant card — all present. App strictly exceeds the mockup.

### `electoral/vacancy-countback.html` — Vacancy countback
- **App has, spec lacks (reconcile, don't strip):** Day-aligned CLK-04 window math (opens_on/latest_start honest date Field min/max); Engine-backed manual countback driver for crashed-queue vacancies; Special election creation linking through F-ELB-001 with full schedule payload
- **Notes:** Conformant: F-LEG-036 trigger card with the F-LEG-030 catalog-alias citation, ESM-13 strip, countback bars with exhausted track, both branches, WF-LEG-13 knock-on card. One stale copy line claims the F-LEG-036 form 'arrives with Phase C Speaker tooling' — it is registered with a live handler.

### `executive/department-detail.html` — Public Works & Utilities — department detail
- **Props missing:** oversees_cgcs — hardcoded [] because the backend has no department↔CGC link (oversight is modeled at executive level via organizations.overseen_by_executive_id); mockup shows 'Oversees: Water & Power CGC' with detail link
- **Backend missing:** Department-level CGC oversight association (column or join table) so the oversight card can list overseen CGCs
- **Spec has, app lacks:** CGC oversight listing on the charter card (renders honest empty state)
- **App has, spec lacks (reconcile, don't strip):** Real F-EXE-001 nomination pipeline with F-LEG-020 consent VoteTally + casts; Real F-EXE-003 removal cards with the MAJORITY vote record (owner ruling #14); Synthetic removal_requested state spliced into ESM-17 from live seat status; Two-clock roster (CLK-09 governors vs CLK-10 worker seats) with expiring badges
- **Notes:** The board roster, nomination and removal machinery all exceed the mockup. The single gap is the department↔CGC oversight link, which needs a small backend association before the card can be filled.

### `executive/department-reporting.html` — Department reporting — R-18 governor surface
- **Props missing:** Viewer appointment card data — mockup opens with 'Your appointment' (seat, chair status, term dates, 10-yr CLK-09 amendable pattern); controller resolves viewerSeat internally but ships only the viewerIsGovernor boolean
- **Spec has, app lacks:** 'Your appointment' card with term dates and the CLK-09 amendable pattern
- **App has, spec lacks (reconcile, don't strip):** Server-filtered enabling-instrument options (charter/laws/active emergency powers the engine would accept); due_soon read-time refinement and public-record seq links on filed reports; Emergency-enabled rules flagged expires-with-power off real EmergencyPower rows
- **Notes:** Rules register and report filings are live F-BOG-001/002 engine surfaces with enabling-act chips. Ship the viewer's seat/term props and render the appointment card to close the gap.

### `executive/departments.html` — Department registry — Charlotte
- **Spec has, app lacks:** In-page 'draft creation act' composer (name/type/oversight/charter fields) — the app deliberately deep-links to the bill flow (/legislature/bills?intro=1&act=department_creation) since F-LEG-016 is a legislative act; verify the deep link prefills those fields
- **App has, spec lacks (reconcile, don't strip):** Live BoG pipeline rows with real consent-vote snapshots per appointment; Civil officers sourced from Terms (R-30, CLK-09) rather than a hardcoded card; Department lifecycle (ESM-17) machine card
- **Notes:** Registry with co-determination seat counts (CLK-13/14), pipeline stepper, and civil officers all present and data-backed. The only divergence is composer-in-page vs deep-link-to-bill-flow — the app posture is engine-correct ('no side-door POST'); worth confirming the bill flow prefills.

### `executive/executive-actions.html` — Executive actions — orders, proposals, investigations
- **App has, spec lacks (reconcile, don't strip):** Real enabling-instrument select (delegation act/charters/live emergency powers) replacing the mockup's 'Demo: claimed scope' toggle; rejected_pre_issuance rows persisted with citation + public-record seq BEFORE the 422 (exit criterion 1); Grant applications register with disbursements and audit_seq chips; Scope banner listing live emergency powers covering the jurisdiction
- **Notes:** Conformant and beyond: the order-rejection exit surface is real (preflight persists the rejected row + record, the citation surfaces verbatim, the row lands at the top of the register). Mockup demo controls are superseded by real instruments.

### `executive/executive-home.html` — Executive home — three model variants
- **Spec has, app lacks:** Three-model aria-pressed toggle exploring non-live variants (app renders only the routed office's real model — delegated/elected-individual/elected-committee panels all exist but only the live one shows; the illustrative explorer is a teaching device, likely a Learn/tour-mode concern); Emergency scenario banner; term-sync page link on the lockstep card
- **App has, spec lacks (reconcile, don't strip):** Forming empty state (constitutional placeholder awaiting F-LEG-014); ConstituentConsentPanel over the live multi_jurisdiction_votes process with per-jurisdiction consent rows and chamber-vote links; Departments summary card (top 5 + count); can.proposeDelegationBill/proposeConversionBill R-09 deep-links
- **Notes:** All three model panels, dual supermajority meters (engine snapshots, never recomputed), ESM-16 strip and term lockstep are live. Decide whether the illustrative model toggle belongs here or in Learn/tour mode before adding it.

---

## legislature

Legislature is essentially conformant to the v3 spec: all 11 screens have existing pages, live routes (routes/web.php FE-C2..C9 block), controllers shipping rich Inertia props, and engine-backed write doors; every form the manifest names (F-LEG-*, F-SPK-*, F-CHR-*, F-JDG-007) is registered in FormRegistry with live handlers. The single partial is Bills: the registry's challenge feed is a hard-coded null so the [Challenged] chip and Art. IV §5 tracker link cannot render, F-LEG-028 (cultural institution recognition) has a full backend but no UI filing door, and the bill lifecycle legend stops at enacted where the mockup continues through published/challenged/edited/repealed — an M effort on one surface. Remaining gaps are S-level chrome: conversation and live-room links on bill-detail and session-console, endorsement chips in the committee assignment-result table, and rules-of-order/ethics-code cards on the settings page. In several places the app exceeds the mockup (economy dials in the settings register, version diffs and enactment provenance on bill detail, bicameral per-kind vote lanes computed from real seat kinds, CLK-19 as a server gate). One stale controller comment (OversightController: admin-office route "NOT YET REGISTERED") contradicts the actual registered route at web.php:661.

| Screen | In app | Route | Props | Backend | Effort | Headline |
|---|---|---|---|---|---|---|
| `legislature/legislature-home.html` Chamber — Charlotte legislature | Legislature/Chamber.vue | `GET /legislatures/{legislature}/chamber` | full | full | **none** | conformant |
| `legislature/bills.html` Bills — registry & introduction | Legislature/Bills.vue | `GET /legislatures/{legislature}/bills` | partial | full | **M** | Challenged-state chip/link on registry rows (Curfew Ordinance fixture) |
| `legislature/bill-detail.html` Bill detail — Charlotte Clean Air Act | Legislature/BillDetail.vue | `GET /bills/{bill}` | full | full | **S** | 'Join the conversation on this bill' link (shared/bill.html conversation surface) and '... |
| `legislature/committee-detail.html` Committee detail — Environment & Infrastructure | Legislature/CommitteeDetail.vue | `GET /committees/{committee}` | full | full | **none** | conformant |
| `legislature/committees.html` Committees — assignment & chairs | Legislature/Committees.vue | `GET /legislatures/{legislature}/committees` | full | full | **S** | endorsing-org chips per member in the assignment-result table (mockup renders org chips... |
| `legislature/emergency-powers.html` Emergency powers | Legislature/EmergencyPowers.vue | `GET /legislatures/{legislature}/emergency-powers` | full | full | **none** | conformant |
| `legislature/oversight.html` Ethics & removals | Legislature/Oversight.vue | `GET /legislatures/{legislature}/oversight` | full | full | **none** | conformant |
| `legislature/referendums.html` Referendums — delegation & protection | Legislature/Referendums.vue | `GET /legislatures/{legislature}/referendums` | full | full | **none** | conformant |
| `legislature/session-console.html` Session console | Legislature/SessionConsole.vue | `GET /legislatures/{legislature}/session` | full | full | **S** | 'Join the live session' banner linking the live chamber room (the mockup frames the roo... |
| `legislature/settings.html` Constitutional settings register | Legislature/Settings.vue | `GET /legislatures/{legislature}/settings` | full | full | **S** | Rules of order + Ethics code adoption cards (F-LEG-032/033 current-edition + enacting-a... |
| `legislature/speaker-tools.html` Speaker tools | Legislature/SpeakerTools.vue | `GET /legislatures/{legislature}/speaker` | full | full | **none** | conformant |

### `legislature/legislature-home.html` — Chamber — Charlotte legislature
- **App has, spec lacks (reconcile, don't strip):** bicameral by_kind stat lanes + forming/resolver empty states; live F-LEG-001 oath action wired into roster and checklist; district labels per member; successor-election link (CLK-01)
- **Notes:** Manifest suggested Legislature/Home.vue; production page is Chamber.vue — same surface, fully conformant. Seat map, roster with endorsements/vote_share_norm, term lockstep, vacancy cards, and the WF-LEG-01 checklist are all driven by real rows.

### `legislature/bills.html` — Bills — registry & introduction
- **Props missing:** challenge — hard-coded null per registry row ('Phase E feed' comment), so the [Challenged] chip linking the Art. IV §5 tracker cannot render despite the Phase-E backend existing
- **Spec has, app lacks:** Challenged-state chip/link on registry rows (Curfew Ordinance fixture); dedicated act-types & thresholds table section with the F-LEG-028 cultural-recognition card — F-LEG-028 has a registered handler + CulturalInstitutionService but NO UI door files it anywhere; post-enactment lifecycle stages in the legend: machine ends at enacted+tabled/failed/withdrawn; mockup's 12-stage legend continues published → [Challenged] → [Edited by a court] → Repealed/Superseded
- **App has, spec lacks (reconcile, don't strip):** settingKeys picker with live hardened bounds + /bills/validate pre-flight; status/act_type filters; committee assignment and enacted-law links per row
- **Notes:** Only partial verdict in the module: wire the challenge feed to the Phase-E challenge rows, add an F-LEG-028 filing door, and extend the lifecycle legend past enactment. F-LEG-003 introduction with real scale/scope selects is fully live.

### `legislature/bill-detail.html` — Bill detail — Charlotte Clean Air Act
- **Spec has, app lacks:** 'Join the conversation on this bill' link (shared/bill.html conversation surface) and 'Watch the floor session' live-room link; bicameral dual-tally PREVIEW toggle for unicameral chambers (app renders per-kind lanes only when the chamber is actually bicameral — mockup lets any viewer preview the four-meter view)
- **App has, spec lacks (reconcile, don't strip):** version history + server-computed amendment diff + full law text; enactment card with setting-change old/new and audit-chain record link; constituent dual-supermajority process panel; three-door refer action (F-LEG-007 committee/floor, F-CHR-003 chair)
- **Notes:** Four independent meters (both seat kinds at committee AND floor) are real via ChamberVotePresenter per-kind lanes. The two social links are the only mockup features absent.

### `legislature/committee-detail.html` — Committee detail — Environment & Infrastructure
- **App has, spec lacks (reconcile, don't strip):** chair/alternate/member/speaker capability gating per action; by_kind committee seat split for bicameral chambers; report → public-record audit_seq links
- **Notes:** The full hearing surface from the mockup is live: F-CHR-001 call → F-CHR-002 agenda → testimony-to-public-record (WF-LEG-08) → F-LEG-005 vote with referral gated on passage → F-CHR-003 refer → F-CHR-004 report. F-COM catalog aliases resolve through FormRegistry.

### `legislature/committees.html` — Committees — assignment & chairs
- **Spec has, app lacks:** endorsing-org chips per member in the assignment-result table (mockup renders org chips or 'no endorsements' per row; app shows names/seat_kind/status only)
- **App has, spec lacks (reconcile, don't strip):** pending F-LEG-009 creation proposals with live supermajority tallies + cast buttons; whole-chamber chair RCV balloting UI (F-LEG-011 open/cast) with auto-open after assignment; proportionality recheck notes per committee; preferencesState pending-member list
- **Notes:** Allocation formula card, keyboard-rankable F-LEG-010 ballot, F-SPK-005 run with the normalized-share tie-break table from the audit-chain snapshot, and the seat StateStrip all match the mockup.

### `legislature/emergency-powers.html` — Emergency powers
- **App has, spec lacks (reconcile, don't strip):** renewal-window gating (opens_day, fresh ceiling) on active powers; pending invocation/renewal proposals with live supermajority tallies; expired/struck history rows
- **Notes:** Two-option closed cause enum, pre-vote >90-day rejection with CLK-03 citation, day-X-of-Y countdown meter with auto-expiry date, hard-rails card, and honest empty state all match. F-JDG-007 renders as a review-status panel; the filing door correctly lives in the judiciary module.

### `legislature/oversight.html` — Ethics & removals
- **App has, spec lacks (reconcile, don't strip):** designate-presider flow for the Speaker's-own-case exception; closed removal→vacancy loop link on each proceeding; F-LEG-013 admin-office creation door when no office exists
- **Notes:** Intake docket, supermajority-of-ALL-serving proceeding meter, vacancy StateStrip with the special-election branch, and the countback-page handoff all real. Stale controller comment claims the admin-office route is 'NOT YET REGISTERED' — it is registered at routes/web.php:661.

### `legislature/referendums.html` — Referendums — delegation & protection
- **App has, spec lacks (reconcile, don't strip):** pending delegation/modification proposals with live supermajority votes; shield_expires_with (which election's certification releases the CLK-19 shield); law_text field on the delegation form (mockup collects only the question)
- **Notes:** Read-only derived threshold (never editable), queue to next jurisdiction-wide ballot, results at matching thresholds, and the disabled modify button with the CLK-19 hardened chip all match; the CLK-19 shield is a server-side validator gate, not just UI.

### `legislature/session-console.html` — Session console
- **Spec has, app lacks:** 'Join the live session' banner linking the live chamber room (the mockup frames the room as THE meeting; no K-3/live-room link exists on the page); page-top emergency-power-active banner (the app carries the active power only inside the locked agenda head)
- **App has, spec lacks (reconcile, don't strip):** speaker balloting launch + RCV cast for a speakerless chamber (F-LEG-008); per-kind attendance/quorum lanes for bicameral chambers; attendance self-registration state (F-LEG-002) distinct from the Speaker's count
- **Notes:** Locked constitutional agenda order (engine-composed head + hardened chip), F-SPK-003 published quorum against all serving, F-SPK-008 compulsion on failed quorum, motions with per-cast publication, F-LEG-006 statements, and F-SPK-009 adjourn/minutes are all wired.

### `legislature/settings.html` — Constitutional settings register
- **Spec has, app lacks:** Rules of order + Ethics code adoption cards (F-LEG-032/033 current-edition + enacting-act cards; in the app these appear only as Chamber checklist steps)
- **App has, spec lacks (reconcile, don't strip):** 11 Phase-L monetary/stipend dials in the register (mockup shows only the 17 classic keys); inherited_from chain provenance per row; setting-changes history feed
- **Notes:** All 17 amendable keys plus the economy dials render with current value, hardened bounds, and enacting-act provenance; the civil/judicial lockstep pair is a joined row; 'Propose change' pre-targets an inline panel with the live /bills/validate bounds pre-flight demonstrating the blocked-pre-vote banner.

### `legislature/speaker-tools.html` — Speaker tools
- **App has, spec lacks (reconcile, don't strip):** readOnly mode for non-Speaker members viewing the office; pre-break tally restoration on the tie-break record ('4–4 → Speaker broke the tie' reconstructed from the F-SPK-004 cast); pendingProceedings surfacing on the presiding panel
- **Notes:** All nine F-SPK form-cards render from SurfaceMeta/FormRegistry with launchpad links to the surface that actually holds each control; the F-SPK-006 priority queue files through the engine into the session's unlocked agenda tail.

---

## judiciary

Judiciary is essentially built to spec: all six mockup screens have existing Vue pages, live routes (including the /judiciary/{sub} resolver), and controllers that ship every displayed group as engine snapshots, and all 22 mockup-listed forms check out in FormRegistry (F-LEG-021 is catalog-only by design — consent votes ride F-LEG-004). Three real gaps remain. (1) The mockup docket spans the viewer's whole jurisdiction chain (county + state courts in one table) while the app docket is single-judiciary — the only M-effort item. (2) Case-detail's lifecycle has no content panels for stages 1/2/7 and its playable Back/Advance walkthrough is built into CaseLifecycle.vue but switched off, pending the Demo/Dev-mode decision (same bucket as the challenge tracker's drill/simulate buttons and judiciary-home's simulate-consent meters). (3) Judiciary-home lacks the emergency-power banner (courts-cannot-be-disrupted + judicial-review status). In the reverse direction the app is consistently ahead of the mockups: operative R-19/R-20 court-action forms on case-detail, a real F-IND-015 registration flow, per-challenge permalinks, real LawDiff/ThresholdMeter hydration, and juror screening with ownership gating and audit-chain read-back — the synthesis should port these INTO the mockup spec rather than strip them.

| Screen | In app | Route | Props | Backend | Effort | Headline |
|---|---|---|---|---|---|---|
| `judiciary/advocate-console.html` Advocate console | Judiciary/AdvocateConsole.vue | `GET /judiciary/advocate` | full | full | **none** | conformant |
| `judiciary/case-detail.html` Case detail — State v. Whitfield (playable lifecycle) | Judiciary/CaseDetail.vue | `GET /cases/{case}` | full | full | **S** | Stage content panels for stages 1 (filing facts), 2 (justiciability/severity classifica... |
| `judiciary/case-docket.html` Case docket — Mecklenburg County & Charlotte courts | Judiciary/CaseDocket.vue | `GET /judiciaries/{judiciary}/docket` | full | full | **M** | Chain-wide docket: the mockup lists cases from BOTH the county and state courts in one ... |
| `judiciary/constitutional-challenge.html` Constitutional challenge tracker — Art. IV §5 | Judiciary/ConstitutionalChallenge.vue | `GET /constitutional-challenges` | full | full | **S** | 'Today is day N of both clocks' live day counter in the window banner (app shows only t... |
| `judiciary/judiciary-home.html` Judiciary home — creation, confirmation & conversion | Judiciary/Home.vue | `GET /judiciaries/{judiciary}` | full | full | **S** | Emergency-power banner ('Courts cannot be disrupted by emergency powers' + judicial-rev... |
| `judiciary/juror-view.html` Juror view — summons & service protections | Judiciary/JurorView.vue | `GET /judiciary/jury/{summons}` | full | full | **none** | conformant |

### `judiciary/advocate-console.html` — Advocate console
- **App has, spec lacks (reconcile, don't strip):** Unregistered-viewer flow: live F-IND-015 FormCard with qualifications note plus a no-judiciary-in-chain empty state (mockup renders only the already-registered state); F-ADV-001 posts to the docket store endpoint with real client name/email resolution; F-ADV-002/003/004 post per-case under the server-side attach-window
- **Notes:** Conformant. All five forms (F-IND-015, F-ADV-001..004) registered with engine handlers; every mockup section (registration card, active cases with next-action lines, type-adaptive composer, filings log, four instrument cards) is present and server-hydrated.

### `judiciary/case-detail.html` — Case detail — State v. Whitfield (playable lifecycle)
- **Spec has, app lacks:** Stage content panels for stages 1 (filing facts), 2 (justiciability/severity classification), and 7 (arguments order + public-record note) — CaseDetail.vue supplies slots only for stages 3,4,5,6,8,9,10, so a case resting at 1/2/7 shows a bare stage heading (the facts are already in caseProps); The playable Back/Advance walkthrough: CaseLifecycle.vue has an `interactive` prop with the full Back/Advance + demo banner, but product renders it OFF — the mockup's centerpiece replay is unwired Demo/Dev-mode behavior
- **App has, spec lacks (reconcile, don't strip):** Operative R-19/R-20 court-action forms gated by live status: F-JDG-001 acceptance+severity select, F-JDG-002 jury draw (seats/alternates), F-JDG-003 opinion composer, F-JDG-009 sentencing, F-JDG-010 warrant with kind + max-hold (mockup shows reference cards only); errors.constitution rejection banner + flash status posture
- **Notes:** All 8 mockup forms registered; panel/jury/double-jeopardy are engine snapshots as specced. Gap is slot content for three stages plus deciding where the interactive replay lives (Demo mode).

### `judiciary/case-docket.html` — Case docket — Mecklenburg County & Charlotte courts
- **Spec has, app lacks:** Chain-wide docket: the mockup lists cases from BOTH the county and state courts in one table ('Every case filed in your jurisdictions'); the app docket is single-judiciary and the resolver picks only the deepest court in the chain
- **App has, spec lacks (reconcile, don't strip):** Residency CTA card for unassociated viewers (confirm residency to file); F-ADV-001 lane on the same store endpoint with real client resolution and a constitutional error on miss; Constitutional cases route to the challenge tracker while others route to case-detail (mockup hardcodes two fixture links)
- **Notes:** Filters/search/stats/filing composer/entry cards/state-strip all present; all 4 forms registered. The one real rework is widening the docket query+header to the viewer's whole jurisdiction chain. Manifest title (Mecklenburg/Charlotte) is stale — the fixture is New York.

### `judiciary/constitutional-challenge.html` — Constitutional challenge tracker — Art. IV §5
- **Spec has, app lacks:** 'Today is day N of both clocks' live day counter in the window banner (app shows only the due/close dates); Demo drill affordances — Load the Novák drill, simulate-vote, simulate-window-close, reset — deliberately not shipped per the tracker's own doc block; they belong to Demo/Dev mode
- **App has, spec lacks (reconcile, don't strip):** Per-challenge permalink route /constitutional-challenges/{challenge}; Real F-IND-016 composer with challengeable-law list, scale options from the viewer's chain, and basis select; Applied Path C renders the REAL prior-version → judicial_remedy LawDiff with the audit-chain history link (mockup hardcodes one diff)
- **Notes:** The Phase E exit-criterion surface is fully wired: F-IND-016/F-JDG-004/005/006/F-LEG-035 all registered, three-path cards with engine-snapshot ThresholdMeter (chamber_vote_tallies required_yes/serving), CLK-11/12 dates, enforcement link, empty state. Only the day-counter copy and demo-drill wiring separate it from the mockup.

### `judiciary/judiciary-home.html` — Judiciary home — creation, confirmation & conversion
- **Spec has, app lacks:** Emergency-power banner ('Courts cannot be disrupted by emergency powers' + judicial-review status of the active power) — the controller ships no emergency props and Home.vue has no banner slot for it (CLK-03 context the mockup renders under scenario.emergency)
- **App has, spec lacks (reconcile, don't strip):** ESM-18 'Judiciary lifecycle' StateStrip card (forming → creating → appointed → conversion_voted → elected | reverted | dissolved); R-09 deep-links: 'Introduce a creation/conversion bill' gated by can.proposeCreationBill/proposeConversionBill, plus forming-stub and no-nominations empty states
- **Notes:** Creation act + real VoteTally, equal-numbers nomination narrative with committee fallback, per-nominee consent DataTable with CLK-09 terms, and the dual-supermajority ConstituentConsentPanel are all live. F-LEG-021 is catalog-registered but deliberately handler-unregistered — consent votes cast via F-LEG-004, which the mockup's reference-card usage matches. Simulate-consent buttons are Demo-mode affordances.

### `judiciary/juror-view.html` — Juror view — summons & service protections
- **App has, spec lacks (reconcile, don't strip):** Summons-holder gating (only the R-22 holder may answer, 403 otherwise) and screening-window closure once voir dire moves on; Recorded answers read back from the audit chain so a screened summons renders read-only with the flagged/clean outcome; Deliberation room genuinely unlocks off the case's deliberation status (engine snapshot), timezone-aware drawn/report citations
- **Notes:** Conformant and ahead of the mockup: 6-step stepper, the five server-authored questions verbatim, both Art. II §8 protections, F-JDG-002 source card, locked room. Screening posts to a thin audit-chain endpoint by design (juror answers are a record, not a constitutional instrument).

---

## jurisdictions-sys

The system module is essentially conformant: all five surfaces (setup wizard, amendments, audit-chain, public-records, term-sync) are built on the v3 design contract with real data, and the remaining gaps are presentational — the term-sync SVG lockstep timeline, the amendments current-value cards + deliberately-deferred try-a-value checker, and two emergency banners (total effort S/M each; setup needs an M-grade flow restructure to the three-way fork with player-one-at-the-end). The jurisdictions module splits cleanly in two: the browser and district mapper are the SOURCE of their mockups (the mockups copied the built app, including the full-bleed map-split containment) and need only S-grade additions (powers table, reach block); but four of seven screens — bootstrap tracker, union formation, disintermediation, restoration — plus the citizen 'Between governments' border-settlement page have COMPLETE backend services (ActivationService, UnionService, DisintermediationService, BorderSettlementService, RestorationService, all with F-LEG-029/030 engine handlers and audit trails) and zero routes or pages: five L-grade page+controller builds, no new subsystems. Two reconciliation flags for the operator: the mockup folds a dissolved intermediary's acts into the CONSTITUENTS while DisintermediationService incorporates them into the ENCOMPASSING jurisdiction (Art. V §8 — one is wrong); and Jurisdictions/Federation.vue currently carries the operator mesh console that the v3 mockups relocated to operator/mesh.html, so the /federation path needs a citizen/operator split coordinated with the operator-module agent.

| Screen | In app | Route | Props | Backend | Effort | Headline |
|---|---|---|---|---|---|---|
| `system/setup.html` Found the instance — the founding loop | Setup/Step0_CosmicAddress.vue (+ Step1_Constants, Step2_MapData, Step3_Districts, Step4_Confirm, ModeFork, OperatorSetup, JoinHost, Bootstrap) | `/setup, /setup/step/{n}, /setup/mode, /setup/operator, /setup/join, /setup/bootstrap` | full | full | **M** | Three-way founding fork (Join / Start fresh / Restore-from-records as first-class equal... |
| `system/amendments.html` Amendments | System/Amendments.vue | `/system/amendments` | partial | full | **S** | 'Try a proposed value' interactive pre-vote bounds checker (controller comment: deliber... |
| `system/audit-chain.html` Audit chain | System/AuditChain.vue | `/system/audit-chain` | full | full | **none** | Any-viewer verify button — app gates full-chain verification to operators (deliberate: ... |
| `system/public-records.html` Public records | System/PublicRecords.vue | `/system/public-records` | full | full | **S** | Emergency-scenario banner ('record-keeping cannot be suspended' while an emergency powe... |
| `system/term-sync.html` Term lockstep | System/TermSync.vue | `/system/term-sync` | full | full | **M** | The inline SVG lockstep timeline (three elected-branch bars ending together + dashed 10... |
| `jurisdictions/bootstrap.html` How a place wakes up | — | `—` | none | partial | **L** | Dedicated bootstrap-tracker page: 7-stage grouped 30-step sequence with Complete/In pro... |
| `jurisdictions/disintermediation.html` Removing a middle layer | — | `—` | none | full | **L** | Entire surface: unanimity + encompassing-consent meters, per-constituent consent table,... |
| `jurisdictions/district-mapper.html` District mapper | Legislature/Districts.vue | `/legislatures/{legislature_id}/districts` | full | full | **none** | conformant |
| `jurisdictions/federation.html` Between governments | Jurisdictions/Federation.vue | `/federation` | none | full | **L** | The citizen 'Between governments' surface: border-settlement proposals table (affected ... |
| `jurisdictions/jurisdiction-browser.html` Jurisdiction browser | Jurisdictions/Show.vue (+ Index.vue) | `/jurisdictions/{jurisdiction:slug}` | partial | full | **S** | 'Powers at this level' joint-vs-reserved table (static content, Art. V §4–5) |
| `jurisdictions/restoration.html` Rebuilding a lost government | — | `—` | none | full | **L** | Entire surface: three activation-condition cards with met/not-detected badges, tier-cas... |
| `jurisdictions/union-formation.html` Union formation | — | `—` | none | full | **L** | Entire surface: parties table, settings/institution compatibility diff with hardened-id... |

### `system/setup.html` — Found the instance — the founding loop
- **Spec has, app lacks:** Three-way founding fork (Join / Start fresh / Restore-from-records as first-class equal choices) — app's ModeFork is two-way Solo/Join, with restore folded into Step2's 'upload an export' source; End-of-flow 'player one' federation-account creation framing — app creates the founder/operator account early (/setup/operator), before the founding steps; Step4 has a 'Ready Player One' panel but no account-creation-at-the-end moment; Restore-path version-check messaging (older snapshot refused with clear message; mid-founding backup resumes at the step it left off)
- **App has, spec lacks (reconcile, don't strip):** Zero-foreknowledge join auto-discovery (/api/setup/discover) + JoinHost resume; Step-2 data-quality review drills (population gaps, aggregation discrepancies, orphans, sovereign territories, assignment audits); Step-3 autoscale progress/halt/resume controls; Deploy-package download (per OS × solo/join); Selective-table export with async job + halt
- **Notes:** The wizard is built and richer than the mockup (economy defaults already in Step1_Constants; export-seed panel in Step4). The gap is flow shape: restructure the fork to three-way and move the player-account moment to the end.

### `system/amendments.html` — Amendments
- **Props missing:** Current amendable-value cards (door one shows live values w/ range + enacting act + effective date — controller ships only the setting_changes ledger, not current resolved values); Bounds data for the 'Try a proposed value' checker
- **Spec has, app lacks:** 'Try a proposed value' interactive pre-vote bounds checker (controller comment: deliberately deferred to Phase 7 — the same bounds check already runs server-side in the bill flow); Supermajority-floor meter visual with threshold marker (app has the card as text); Link out to the full amendable-settings register (legislature/settings)
- **App has, spec lacks (reconcile, don't strip):** Live F-LEG-031 amendment ledger — 50 newest applied setting changes with jurisdiction, old→new value, enacting act + bill link (the mockup has no ledger at all)
- **Notes:** Page is built on the v3 contract (two doors, floor, ratification table, hardened chips). Remaining gaps are props additions (SettingsResolver current values) and the deferred checker.

### `system/audit-chain.html` — Audit chain
- **Spec has, app lacks:** Any-viewer verify button — app gates full-chain verification to operators (deliberate: expensive walk); 'Head hash published to peers on the federation heartbeat' chip (checkpoints live on the federation console instead)
- **App has, spec lacks (reconcile, don't strip):** Pagination through the whole chain (mockup shows a fixed 10-entry sample); Genesis hash + chain count + real verify timing in ms, and a real CHAIN BROKEN failure path
- **Notes:** Conformant. Entries carry prev_hash/hash, rejected rows with blocked_reason render in-sequence exactly as the mockup specifies.

### `system/public-records.html` — Public records
- **Spec has, app lacks:** Emergency-scenario banner ('record-keeping cannot be suspended' while an emergency power is active); Translated/pending stat tiles (app ships total/acts/votes/statements counts instead)
- **App has, spec lacks (reconcile, don't strip):** Per-legislature filter (citizen's view into any chamber); Cursor pagination; Supersedes-chain links between records; Subject deep links (bill/petition/election/emergency/referendum); Body excerpts; Per-locale translation quality (machine vs human-reviewed)
- **Notes:** F-LEG-006 composer is live through the engine (R-09-gated, appends record + audit seq). Gaps are two small presentational blocks.

### `system/term-sync.html` — Term lockstep
- **Spec has, app lacks:** The inline SVG lockstep timeline (three elected-branch bars ending together + dashed 10-year appointed contrast bar + today marker) — app renders the same data as a table; mockup says 'production renders this timeline from the live term registry'; Emergency-scenario banner ('the lockstep is unaffected')
- **App has, spec lacks (reconcile, don't strip):** Per-legislature next-election rows off REAL armed CLK-01 clock_timers (never a recomputed date) with election status + chamber links; Real recorded engine refusals (rejected audit rows for CLK-01/09/10) rendered verbatim; Live civil-appointment counts per office kind
- **Notes:** All data the timeline needs (term starts/ends, interval, clock due_at, civil terms) is already shipped as props — the gap is building the timeline component itself.

### `jurisdictions/bootstrap.html` — How a place wakes up
- **Backend missing:** Per-step progress registry — the 30-step/7-stage tracker with per-step form chips has no materialized data source; jurisdiction_activations carries only coarse states (boundary_loaded → critical_population → bootstrapping → self_governing) plus audit events
- **Spec has, app lacks:** Dedicated bootstrap-tracker page: 7-stage grouped 30-step sequence with Complete/In progress/Pending badges and per-step form chips; CLK-06 critical-population meter with amendable value/range/act line; Jurisdiction lifecycle state strip; Temporary bootstrap-board warning banner
- **App has, spec lacks (reconcile, don't strip):** Jurisdictions/Show.vue already renders the activation state as a badge + timestamp (dormant/critical-population/bootstrapping/self-governing) — the mockup expects a whole page, the app has one line
- **Notes:** ActivationService fully implements the machinery (threshold detection, bootstrap board constitution, institution seating, WF-JUR-01 audit trail) — the page, route, and a step-registry read model are what's missing.

### `jurisdictions/disintermediation.html` — Removing a middle layer
- **Spec has, app lacks:** Entire surface: unanimity + encompassing-consent meters, per-constituent consent table, law-merge conflict table with per-row incorporate/defer/lapse selects, topology-after strip, F-LEG-030 form card + vote submit
- **Notes:** DisintermediationService is complete (MJV at BASIS_UNANIMITY + encompassing consent, LawMergeResolution, EnactmentService::amendLaw incorporation, F-LEG-030 handler, ChamberActService::proposeDisintermediation) but nothing in resources/js references it. RECONCILIATION FLAG: the mockup folds the dissolved layer's acts into the CONSTITUENTS; the service doc says acts are incorporated into the ENCOMPASSING jurisdiction — one of the two is wrong against Art. V §8 and needs an operator ruling before the page is built.

### `jurisdictions/district-mapper.html` — District mapper
- **App has, spec lacks (reconcile, don't strip):** Mass-autoseed progress/halt console, manual line-drawing (F-ELB-008) for leaf giants, split-line bisection, constitutional-flags queue — the built mapper far exceeds the mockup's static stand-in
- **Notes:** This mockup was authored FROM the shipped Legislature Browser ('the shipped Legislature Browser mounts exactly here') — versioned draft/Activate plans, Map Quality ledger, per-district Dev/CHR/Contig/Intact strips, wizard prev/up/next rows, and the full-bleed map-split containment (v3.2 item 0c) all exist in Districts.vue. Only the v3 token restyle remains, which is the shell agent's scope.

### `jurisdictions/federation.html` — Between governments
- **Spec has, app lacks:** The citizen 'Between governments' surface: border-settlement proposals table (affected population, 2/3-of-affected supermajority, deliberation→referendum→done stepper, status badges), rights-re-attach gloss, link out to the operator mesh page
- **App has, spec lacks (reconcile, don't strip):** The existing Federation.vue is the full operator/mesh console (peers, FF&C sync log, checkpoints, authority claims, cluster join, role channels) — content the v3 mockups relocated to operator/mesh.html
- **Notes:** Name collision, not conformance: the v3 screen at this path is a NEW citizen page about border settlement, which is unbuilt in UI despite BorderSettlementService being complete (CivicPopulation::forArea denominator, supermajority via ConstitutionalValidator, jurisdiction_maps versioning, resident re-association). The mesh-console content on today's Federation.vue belongs to the operator module's reconciliation, not this row.

### `jurisdictions/jurisdiction-browser.html` — Jurisdiction browser
- **Props missing:** Reach & participation gauge (verified residents / population %, tier pill, capped display — LegitimacyService data ships only to /reach, not to this page); Live verified-resident 'members' count alongside WorldPop population
- **Spec has, app lacks:** 'Powers at this level' joint-vs-reserved table (static content, Art. V §4–5); Reach & participation block with link out to the reach panel; San Marino-style honest-gap warning banner driven by a dataGap field
- **App has, spec lacks (reconcile, don't strip):** Executive + judiciary cross-link CTAs, current-election CTA, chamber-seated gate splitting the legislature CTA; Review-issue badges + orphan counters, geoboundary metadata panel, planet-scope Accept Map Data gate + repair plane
- **Notes:** The viewer already has the two-pane map-split, breadcrumb drill-down, Names/Members/Raster layer toggles, maps-accepted/apportionment record, and activation state. Gaps are one static table and one props-fed reach block (LegitimacyService already computes it for /reach).

### `jurisdictions/restoration.html` — Rebuilding a lost government
- **Spec has, app lacks:** Entire surface: three activation-condition cards with met/not-detected badges, tier-cascade stepper (constituents → encompassing → individuals), legitimacy-scoring + defensive-forces cards, lifecycle state strip, drill banner reactive to an armed restoration event
- **Notes:** RestorationService is complete (declare on countermanded/captured/destroyed, judicial confirmation required before activation, strict tier ordering, RestorationEvent model, WF-JUR-07 audit) with zero routes or UI. The page is mostly teaching content plus a read of restoration_events — the lightest of the four missing jurisdictions pages, but still page + controller.

### `jurisdictions/union-formation.html` — Union formation
- **Backend missing:** Cross-INSTANCE settings compatibility diff (comparing two federated instances' amendable settings side-by-side) — same-instance jurisdiction settings reads exist via SettingsResolver, but no service assembles the diff across peers
- **Spec has, app lacks:** Entire surface: parties table, settings/institution compatibility diff with hardened-identical rows, radio codification workspace, dual ratification meters with whole-population denominators, join/exit mirror card, bicameral type_a/type_b preview, F-LEG-029 founding-act form + submit
- **Notes:** UnionService is complete (dual ratification: applicant-population supermajority + constituent-jurisdiction supermajority via the PROTECTED MultiJurisdictionVote math; union_processes; F-LEG-029 handler; ChamberActService::proposeUnion) with no UI. Ratification meters and bicameral preview render straight off existing data; only the cross-instance compat diff needs new backend.

---

## operator

The manifest is stale for this whole module: it maps all nine screens to Jurisdictions/Federation.vue, but a dedicated mockups-v3-wiring Phase 4 operator suite exists (MeshConsoleController + MeshRolesController + six Operator/*.vue pages at /operator/*), and six of nine screens are built, routed, and near-conformant — Console, Roles, Mesh, Identity, Versioning are full-props wrappers over the real mesh services and in several places exceed the mockups with live actions the mockups only demo-announce. The two genuine holes are operator/dns.html (no page or route — the Home door detours to /operator/operations; the broker backend largely exists in services/mesh-cert-broker plus the CertGrant/BrokerAuthorization services, but budget rails, DDNS, wildcard grants, and non-Cloudflare providers are unbuilt) and operator/moderation.html (no page — F-SOC-003/004 backends are live and correctly shaped, but there is no operator surface and no below-the-flip operator-relay code path). Setup exists as Setup/OperatorSetup.vue on /setup/operator with all backend steps live, but diverges from the mockup's five-step wizard: no Path-B device-key link UI, raw channel toggles instead of the four named-role cards, and broker token entry deferred to the console. One design question needs settling: the join-a-cluster wizard and the G3c read-write petition ladder deliberately remain on legacy /federation (design flag 1) while the mockup places joining on operator/mesh. Effort to close the module: two L pages (dns, moderation), one M rework (setup), two S touch-ups (mesh join-wizard placement, identity enrol/link actions), rest conformant.

| Screen | In app | Route | Props | Backend | Effort | Headline |
|---|---|---|---|---|---|---|
| `operator/operator-home.html` Run a node — the operator plane | Operator/Home.vue | `GET /operator` | full | full | **none** | DNS & certificates door lands on /operator/operations ('Lives in the Operations console... |
| `operator/setup.html` Set up your node — first-run wizard | Setup/OperatorSetup.vue | `GET /setup/operator` | partial | full | **M** | Path B — 'link an existing mesh identity by device key' at setup time (POST /operator/l... |
| `operator/console.html` The operator console (two-tier) | Operator/Console.vue | `GET /operator/console` | full | full | **none** | tier-1 role-card CTAs act by navigating to /operator/roles rather than inline Establish... |
| `operator/roles.html` Roles & channels | Operator/Roles.vue | `GET /operator/roles` | full | full | **none** | conformant |
| `operator/mesh.html` Mesh & federation | Operator/Mesh.vue | `GET /operator/mesh` | full | full | **S** | Section 1 'Join a cluster' 4-step wizard — deliberately absent; it lives on legacy /fed... |
| `operator/dns.html` DNS & certificates (Identity Broker) | — | `—` | none | partial | **L** | the entire dedicated surface: model FSM (DNS-before-cert), domains table (zone/token/ce... |
| `operator/identity.html` Identity (G-ID) | Operator/Identity.vue | `GET /operator/identity` | full | full | **S** | 'Enrol a device' and 'Link by possession' action buttons — enrolment is CLI-driven and ... |
| `operator/moderation.html` Moderation & the legal floor | — | `—` | none | partial | **L** | the entire surface: legitimacy-flip FSM + below/above cards, four-carve-outs table with... |
| `operator/versioning.html` Versions & upgrades | Operator/Versioning.vue | `GET /operator/versioning` | full | full | **S** | the 'what can change' three-kind cards and the admissibility-filter FSM strip as their ... |

### `operator/operator-home.html` — Run a node — the operator plane
- **Spec has, app lacks:** DNS & certificates door lands on /operator/operations ('Lives in the Operations console for now'), not a dedicated DNS surface; Moderation & the legal floor door is a dead 'Planned - Phase I' placeholder card with no destination
- **App has, spec lacks (reconcile, don't strip):** 'Places on this node' authority stat card (home copies here / with peers / total); live enabled-channel manifest section; 'Between you and ready' gate checklist with per-gate remediation lines; operator sign-in gate + legacy console links (Operations, Federation)
- **Notes:** Manifest's productionPages (Jurisdictions/Federation.vue) is stale — the dedicated Phase-4 suite page exists and conforms. Its two broken doors are the dns/moderation rows' gaps, not this page's.

### `operator/setup.html` — Set up your node — first-run wizard
- **Props missing:** four named-role groupings (page ships raw channel list only); device-key registry for the Path-B link flow
- **Spec has, app lacks:** Path B — 'link an existing mesh identity by device key' at setup time (POST /operator/link exists but no setup UI targets it); step 2's four named-role picker cards with duty/consent copy (app offers per-channel toggles + 'turn on all'); step 3's inline Identity Broker token entry (app defers broker.dns/broker.tls config to the console); the explicit 5-step stepper and 'You're set' completion step
- **App has, spec lacks (reconcile, don't strip):** SOLO/JOIN mode-fork gating before this step; reach choice (solo vs open) with detected-origin self-URL suggestion; per-channel needs-setup flags surfaced after establish
- **Notes:** The backend for every wizard step is live (account create, .env profile write, founding-role establish, device-possession link); the surface shape diverges from the mockup's 5-step wizard. Page uses old setup chrome, not the v3 shell — expected for pre-shell bootstrap context.

### `operator/console.html` — The operator console (two-tier)
- **Spec has, app lacks:** tier-1 role-card CTAs act by navigating to /operator/roles rather than inline Establish/Request (documented design disposition — console is read-only by design)
- **App has, spec lacks (reconcile, don't strip):** open-proposals live counts by kind; consent-leg note (which meter holds consent for the scope) + per-channel 'why not yet' gate hints; operator sign-in gate
- **Notes:** Both tiers present: health line + gate chips, four role cards, Advanced toggle with the channel grid, three meter cards with live counts, mesh summary pointer, CLI chips. Pure wrapper over MeshGateService/PeerUpgradeAgreementService as the design contract requires.

### `operator/roles.html` — Roles & channels
- **App has, spec lacks (reconcile, don't strip):** live lifecycle actions (qualify/request/approve/revoke POSTs, verb-for-verb with the mesh:role CLI); pending-request table with live Meter A/B/C reads; founding-node one-click mode (every role self-asserts, no dual-meter); qualify-probe flash panel
- **Notes:** All four mockup sections present (role cards, nine-channel table, lifecycle FSM strip mirrored word-for-word, three meter cards) and the app exceeds the mockup with real actions. Conformant.

### `operator/mesh.html` — Mesh & federation
- **Spec has, app lacks:** Section 1 'Join a cluster' 4-step wizard — deliberately absent; it lives on legacy /federation (FederationConsoleController join wizard + SyncProgress), per design flag 1
- **App has, spec lacks (reconcile, don't strip):** peerage-gates checklist section; seq-lag computation and richer sync-result taxonomy (rejected_tamper / rejected_non_authoritative); self-URL display and relation glosses per peer
- **Notes:** Peers, FF&C ledger, becoming-a-full-peer (incl. the 3-step authority-move explainer), and transports all conform; backend fully live (MirrorService, ClusterJoinJob, TransportService). The only spec gap is where the join wizard lives — the design-flag-1 split between /operator/mesh and legacy /federation needs settling.

### `operator/dns.html` — DNS & certificates (Identity Broker)
- **Backend missing:** Let's Encrypt budget rails (50/domain and 5/name per 7 days) with pre-flight refusal ledger; wildcard-backup grant kind + per-domain approval flag (mockup marks stub/future); DDNS signed re-point flow; Route53 / DigitalOcean / Manual DNS providers (only Cloudflare via lego is live)
- **Spec has, app lacks:** the entire dedicated surface: model FSM (DNS-before-cert), domains table (zone/token/certs/wildcard/budget), per-name vs wildcard cards, DDNS section, providers table, budget-rails cards
- **App has, spec lacks (reconcile, don't strip):** write-only Cloudflare credential drop + forget (lives on Federation.vue broker-credentials section); cert expiry inventory (lives on /operator/operations)
- **Notes:** No page and no route; the Home door detours to /operator/operations. The heavy backend is real — services/mesh-cert-broker (DNS-01 via Cloudflare+lego, CSR inspection, grant verification, DNS-first ordering) plus CertClient/CertGrant/BrokerAuthorization/BrokerFailover services — so this is a new read page + controller over existing services, with budget-rail/DDNS service work on top.

### `operator/identity.html` — Identity (G-ID)
- **Spec has, app lacks:** 'Enrol a device' and 'Link by possession' action buttons — enrolment is CLI-driven and POST /operator/link has no UI form on this page (app shows honest empty-state copy instead)
- **App has, spec lacks (reconcile, don't strip):** node signing-key block (server id, Ed25519 public key, fingerprint, copy buttons, no-mint-on-read guarantee); unminted-identity and no-devices-enrolled honest empty states
- **Notes:** Both mockup halves present: operator identity + device-key registry, and the citizen-standing sections (attestation FSM, three forwarded-write checks, expiry sweep, keystone) backed by real AttestationService/AttestationGate/OperatorDevice. Gap is the two in-page enrol/link actions.

### `operator/moderation.html` — Moderation & the legal floor
- **Backend missing:** below-the-flip operator-board relay path for rights-protection removal (no code path found; F-SOC-003 is judicial R-19/R-20 only); an operator-plane read surface over the carve-out removal log and the F-SOC-004 sealed evidence trail
- **Spec has, app lacks:** the entire surface: legitimacy-flip FSM + below/above cards, four-carve-outs table with logged/never-logged column, per-user-block note, M-5 legal-floor card with the closed legal_basis chips and never-does rails
- **Notes:** No page, no route; the Home door is a dead 'Planned - Phase I' placeholder. The constitutional backends are live and correctly shaped (F-SOC-003 seals a public_records violation before soft-delete, judicial-gated; F-SOC-004 key-possession legal floor with closed enum; viewpoint removal correctly has no code path) — mostly a read/explainer page plus the operator-relay leg.

### `operator/versioning.html` — Versions & upgrades
- **Spec has, app lacks:** the 'what can change' three-kind cards and the admissibility-filter FSM strip as their own sections (app carries both as copy lines inside other cards)
- **App has, spec lacks (reconcile, don't strip):** per-proposal Meter A/B/C applies/passed detail cards; pinned-version badge with pin timestamp; operator sign-in gate
- **Notes:** Backend is fully real: PeerUpgradeAgreementService::assertAdmissible reuses the PROTECTED ConstitutionalValidator (hardened, ungateable), FederationSyncService pauses FF&C fail-closed on constitutional_version mismatch, and the freeze/pinning fields ship. Gap is presentational restructure only.

---

## organizations

Organizations is one of the strongest-conforming modules: all five screens exist, are routed, and render engine snapshots (never client recomputes) with all ten referenced forms (F-IND-012, F-ORG-001..007, F-LEG-019/026/027) registered with handlers in FormRegistry. Two screens (co-determination, cgc-detail) are essentially conformant, and org-registry needs only copy/props-level additions (endorsing toggle, club quick-form, no-faction banner, one monopoly-flag join). The two real gaps: board-elections lacks the mockup's open nomination window — an org-configurable, role-gated window-days setting with third-party nominate/accept-decline, for which no org-settings backend exists — and transfers-conversions lacks the internal-restructuring write path entirely (restructurings and structureHistory are hardcoded empty; a new form/action plus per-structure consent rules and history storage is needed). In several places the app deliberately exceeds the mockup — real administration actions, two-party transfer consent, bill-flow deep links for the legislative acts, and enacting-act provenance on CLK-13/14 — and the mockups should absorb those patterns rather than the app regressing to demo theater. One fidelity defect worth fixing in passing: CgcController::codetProps hardcodes 100/2000 thresholds instead of resolving the amendable CLK-13/14 values.

| Screen | In app | Route | Props | Backend | Effort | Headline |
|---|---|---|---|---|---|---|
| `organizations/board-elections.html` Board elections | Organizations/BoardElections.vue | `GET /organizations/{organization}/board-elections` | partial | partial | **M** | 'Before the count — open nomination window' section: phase state strip (nominations → r... |
| `organizations/cgc-detail.html` Common Good Corporation — Mecklenburg Water & Power | Organizations/CgcDetail.vue | `GET /organizations/{organization}/cgc` | full | full | **S** | Header stat cluster tiles for appointed-governor count and chartered year (both values ... |
| `organizations/co-determination.html` Worker seats on the board | Organizations/CoDetermination.vue | `GET /organizations/co-determination` | full | full | **S** | Below-threshold entities with no board (mockup's Northstar row, 'below 100 workers') ne... |
| `organizations/org-registry.html` Organization registry | Organizations/Registry.vue | `GET /organizations` | partial | full | **S** | 'Endorsing only' chip toggle (endorsement_count is already shipped — client-side filter) |
| `organizations/transfers-conversions.html` Ownership changes | Organizations/TransfersConversions.vue | `GET /organizations/transfers-conversions` | partial | partial | **L** | The entire internal-restructuring form (org + target structure + consent meter enforcin... |

### `organizations/board-elections.html` — Board elections
- **Props missing:** nomination-window phase (window dates/length, nominee accept-decline state) — no prop group exists for it
- **Backend missing:** Org-configurable nomination-window setting (mockup: a role-gated org-settings dial, 10 days; no org_settings storage exists); Third-party nomination with open accept/decline (app candidacy is self-declared via the generic election flow)
- **Spec has, app lacks:** 'Before the count — open nomination window' section: phase state strip (nominations → ranking → count) plus the note that the window length is an org-level setting linked to org settings
- **App has, spec lacks (reconcile, don't strip):** R-23 administration actions (provision board, schedule owner/worker elections, certify) via F-ORG-003/004; Live-election links to the real Phase B ballot surfaces + electorate counts; Composition-invalid warning, chair pending_reason=composition_changed banner, and no-board empty state
- **Notes:** Both STV tracks, the joint chair RCV round record, and the seated BoardStrip are real engine reads (F-ORG-003/004 registered, handlers wired); the chair two-round stepper is demo theater the app correctly replaces with the certified record. The only substantive gap is the nomination-window concept, which needs an org-settings dial that has no backend.

### `organizations/cgc-detail.html` — Common Good Corporation — Mecklenburg Water & Power
- **Spec has, app lacks:** Header stat cluster tiles for appointed-governor count and chartered year (both values exist in shipped props — codet.ownerSeats, charter.effective_at — just not surfaced as stats)
- **App has, spec lacks (reconcile, don't strip):** Append-only IP dedication FormCard (R-18/R-23, deliberately no status field) posting to /ip-register; Conversion-history LifecycleTracker from org_conversions; BoardStrip seat-level composition with CLK-09/CLK-10 term clocks; Reorg/sale bill deep link into the real bill flow
- **Notes:** Best-conforming screen in the module: charter/oversight/co-det/IP-register all real (F-LEG-019 + CgcIpRegisterService, pinned append-only). One fidelity nit: CgcController::codetProps hardcodes thresholds 100/2000 instead of resolving CLK-13/14 (acknowledged in a code comment) — stale if ever amended.

### `organizations/co-determination.html` — Worker seats on the board
- **Spec has, app lacks:** Below-threshold entities with no board (mockup's Northstar row, 'below 100 workers') never appear — the applies-equally table reads only live boards rows
- **App has, spec lacks (reconcile, don't strip):** CLK-13/14 enacting-act provenance (setting_changes ledger link vs the mockup's static 'Template default' line); composition_valid warnings with worker-track election links on applies rows; ?org= live binding of the meter to real orgs AND departments
- **Notes:** Full conformance on the essentials: interactive CoDetScale slider with the live substituted formula receipt (verified in the component), applies-equally table across all three board kinds, CLK-13/14 amendable cards resolved through SettingsResolver. Only divergence is deliberate live-data posture (no fixture rows for orgs without boards).

### `organizations/org-registry.html` — Organization registry
- **Props missing:** Per-row monopoly-acquisition-pending flag (mockup's 'monopoly finding pending' badge on Cobalt Grid; org_conversions has the data but registryRow never joins it)
- **Spec has, app lacks:** 'Endorsing only' chip toggle (endorsement_count is already shipped — client-side filter); 'Start a club' lightweight two-field quick form (F-IND-012 with type=informal covers the write; the shortcut surface is absent); Monopoly-finding-pending row badge; 'No special party privileges' info banner citing ledger #1
- **App has, spec lacks (reconcile, don't strip):** Structure and jurisdiction filters; Status column with tones + CGC tag routing to the canonical CGC page; Residency CTA replacing the form when the viewer lacks R-03; Empty-registry banner and CLK-13-resolved (not hardcoded) threshold in the stat label
- **Notes:** Registry, stats, filters, F-IND-012 FormCard, and the ESM-18 StateStrip are all live; gaps are copy/props-level (a toggle, a banner, a quick-form variant, one flag join). Registered-in shows as an AdmChip under the name rather than the mockup's dedicated column — equivalent information.

### `organizations/transfers-conversions.html` — Ownership changes
- **Props missing:** restructurings — hardcoded [] in the controller (no data source); structureHistory on OrgDetail's OwnershipPanel is likewise hardcoded []
- **Backend missing:** Internal-restructuring write path: no form/handler for private-side structure conversion (sole prop ↔ partnership ↔ equal partnership ↔ member-owned ↔ stock) with consent per the current structure's own rules, and no structure-history storage. F-ORG-005/006/007 + F-LEG-026/027 are all registered with handlers.
- **Spec has, app lacks:** The entire internal-restructuring form (org + target structure + consent meter enforcing the structure's own rule, e.g. partnership unanimity) — the app renders only an empty table with a gloss; Dissolution 'obligations settled' confirmation checkbox
- **App has, spec lacks (reconcile, don't strip):** Live transfer register with the two-step consent model (initiate = from-side consent, transferee consents via its own POST — stronger than the mockup's two checkboxes on one form) + FF&C sync column; Real acquisition register: VoteTally off chamber_votes, compensation-vs-floor readout, founding-governor-offer table; F-LEG-026/027 deep links into the actual bill flow rather than static form cards
- **Notes:** Four of the five ownership paths are fully wired end-to-end through the engine; the fifth (internal restructuring) is display-scaffolded but has no write path — closing it means a new engine form/action, per-structure consent logic, structure-history storage, and a deliberate bump of the pinned form count in AuditChainSmokeTest. The mockup's single-form dual-consent checkboxes should NOT be copied; the app's two-party consent model is the correct implementation of 'mutual consent is mandatory'.

---

## social-groups

The area's spine is real: journeys, the public square, private-room Messages, org detail, and Reach are all routed with live backends, and the groups module's pivot from "affinity groups" to "Messages" (DM/group conversations over SocialSpace + Matrix) is already followed by the app — the manifest notes for groups/* are stale against their own HTML. The two headline holes are both social: there is NO public person-profile page (the ?who= lookup with follow/message and Overview/Record/Candidacy/Office tabs — social_profiles and social_follows tables sit unused, and the candidate-profile redirect stub therefore points at nothing), and NO achievements catalog page, even though the K-2 AchievementCatalog (139 entries) + append-only ledger are fully built and the manifest's "no AchievementCatalog in code" claim is stale. Journey conforms on the personal arc (durable progress, freeze, medal) but lacks the mockup's world-live strip, SOP "understand it first" layer, and live-room links — the last deliberately parked for Phase 6. The square lacks its place-ness (halls presence, @u-handles, seat badges, tags), and OrgDetail lacks its entire economy face (listings, jobs, ledger) despite every needed table existing in the economy plane. Reach is essentially conformant — copy sections only — and in several places the app is functionally ahead of its mockups (real AV in rooms, member gating, read-degrade, earner-mode achievement discipline), which the reconciliation should preserve, not erase.

| Screen | In app | Route | Props | Backend | Effort | Headline |
|---|---|---|---|---|---|---|
| `journeys/journey.html` Journey — guided arc (13 journeys via ?id=) | Civic/Journey.vue | `/journeys/{id}` | partial | partial | **M** | world progress rail + live snapshot strip with countdown and step-into-the-room CTA |
| `social/profile.html` Personal profile | — | `—` | none | partial | **L** | the entire public look-someone-up page (?who=): head with follow/message + pseudonymity... |
| `social/org-profile.html` Organization profile | Organizations/OrgDetail.vue | `/organizations/{organization}` | partial | full | **M** | Marketplace listings section |
| `social/social-home.html` The public square | Civic/PublicSquare.vue | `/civic/square` | partial | partial | **M** | 'Who is in the halls right now' presence strip with live pills and step-into-the-room CTAs |
| `groups/groups-home.html` Informal groups — browse & create | Civic/PrivateRooms.vue | `/civic/rooms` | partial | partial | **M** | rich inbox rows: avatar initials, last-message preview (incl. attachment label), unread... |
| `groups/group-create.html` Create a group | Civic/PrivateRooms.vue | `/civic/rooms` | partial | full | **S** | people-picker chips with DM/group auto-detection by participant count |
| `groups/group-detail.html` Group home | Civic/PrivateRoom.vue | `/civic/rooms/{space}` | partial | partial | **M** | composer toolkit + attachment chips in bubbles |
| `social/achievements.html` Achievements | — | `—` | none | partial | **L** | the entire catalog page: 4 subject tiers with ACH-* codes, earned vs locked medals, in-... |
| `social/legitimacy.html` Reach | Social/Reach.vue | `/reach` | full | full | **S** | 'The fences around this number' rails card |

### `journeys/journey.html` — Journey — guided arc (13 journeys via ?id=)
- **Props missing:** world live state (journeyLive currentStep + snapshot/statusPill/countdown/roomVariant); SOP 'Understand it first' content (video + standard-procedure panel); formal-moment v1 deep-link chips (reusesV1 + tour titles); bill deep-link section for the bill journey
- **Backend missing:** world-step derivation (nothing computes where the WORLD is in an arc); SOP/video content store; Live Civic Room (Phase 6)
- **Spec has, app lacks:** world progress rail + live snapshot strip with countdown and step-into-the-room CTA; 'Understand it first' SOP video + procedure section; live-room role cards (Where you act); 'The formal moment' deep-link chips; Now/Next world sentence (app ships only Your part)
- **App has, spec lacks (reconcile, don't strip):** server-durable per-user progress with freeze-after-completion (mockup is localStorage, no freeze); medal as append-only ledger event with earned date shown; planned-journey read-only guard (steps unmarkable until live)
- **Notes:** The personal arc (steps, durable progress, medal, earns card) conforms; the whole world-side layer (live strip, rooms) and the SOP layer are deliberately skipped in the page comment until Phase 6. Closing the SOP + deep-link gaps is one-surface work; the live-room parts wait on the Phase 6 subsystem.

### `social/profile.html` — Personal profile
- **Backend missing:** profile read controller/service (social_profiles + social_follows tables and SocialProfile/SocialFollow models exist but no surface uses them); follow/unfollow write endpoints; message-this-person entry point (rooms exist but are invite-link only); Office-tab data (surgeries, constituent-request queue)
- **Spec has, app lacks:** the entire public look-someone-up page (?who=): head with follow/message + pseudonymity choice pill + follower/group/org stats; tabs Overview/Record/Candidacy/Office assembled per subject; endorsement web + endorsement-request status table; public achievements chips on another person's profile
- **App has, spec lacks (reconcile, don't strip):** candidacy has its own routed page /candidates/{candidacy} (Elections/CandidateProfile.vue) with race binding, F-CAN-001/002/003 writes and withdrawal; self-profile tabs live at /civic/record (Civic/MyRecord.vue) including a Settings tab the mockup lacks
- **Notes:** The pieces exist scattered — MyRecord covers self (overview/record/candidacy/representatives/achievements/wallet), CandidateProfile covers a candidacy, and F-CAN forms are registered with handlers — but no unifying public person profile exists, so the candidate-profile redirect stub's target is unbuilt. New page + controller over existing tables; follow/message writes are small additions.

### `social/org-profile.html` — Organization profile
- **Props missing:** marketplace listings (marketplace_listings scoped to the org); job board (work_postings + apply); org ledger balance + recent movements (economic_accounts/ledger_entries); profile-head stat strip (listings/jobs/board-seat counts); manage-actions cluster (post a job, manage listings, economics & shares)
- **Spec has, app lacks:** Marketplace listings section; Job board with apply; private org-ledger card + manage economics/shares/dues link; manage-this-organization action row; avatar/stat profile head
- **App has, spec lacks (reconcile, don't strip):** Contracts section with cosign; ownership transfer / conversion / dissolution writes (F-ORG-005/006/007); viewer's own membership + worker status (myMembership/myWorker); is_cgc redirect to the CGC detail page
- **Notes:** Governance/charter/board/endorsements/documents are the real thing and conformant; the org's ECONOMY face is absent from the page even though every table it needs (marketplace_listings, work_postings, economic_accounts, ledger_entries) shipped with the Phase L+M economy plane. Controller-side queries + new sections on one surface.

### `social/social-home.html` — The public square
- **Props missing:** halls presence rosters (who is here now, speaking flags, counts); pseudonymous @u-handles + seat badges on post rows; topic tag chips; per-post room deep-links
- **Backend missing:** presence read (MatrixCommonsController has no presence/membership fetch); follow-informed feed (social_follows table exists, unwired); seat-badge derivation for posting authors
- **Spec has, app lacks:** 'Who is in the halls right now' presence strip with live pills and step-into-the-room CTAs; @u-handle + seat-badge post rendering; tag chips; community-standards card listing the four carve-outs with the why-drawer
- **App has, spec lacks (reconcile, don't strip):** real compose filing F-SOC-001 with jurisdiction select and a residency CTA fallback (never a 403); thread/reply structure; flash + constitution-error banners
- **Notes:** The feed and the uncensorable posture are live (F-SOC-001/002 registered, carve-outs judicial-only); what is missing is the mockup's place-ness — presence, handles, seat badges, tags. Presence needs a Matrix membership read; the rest is one-surface work.

### `groups/groups-home.html` — Informal groups — browse & create
- **Props missing:** last-message previews; unread counts; live-now pill; DM vs group kind labels on rows
- **Backend missing:** unread/read-position tracking; live-presence signal for rooms
- **Spec has, app lacks:** rich inbox rows: avatar initials, last-message preview (incl. attachment label), unread badge, live pill, kind sub-line
- **App has, spec lacks (reconcile, don't strip):** the 'Bring people in' invite-link step (kind=space) — THE settled arrival mechanism (no user directory, by design); owner/member role chips; opened dates
- **Notes:** The manifest note ('browse/join affinity groups') is stale — the mockup itself is now the MESSAGES inbox (messages-v2.js), and PrivateRooms.vue was explicitly built to that contract over SocialSpace/SocialMembership. Gap is inbox richness: previews and unread need timeline reads/counters.

### `groups/group-create.html` — Create a group
- **Props missing:** add-participants-by-@handle chip picker (deliberately replaced by invite links — no user directory, pseudonymous by design)
- **Spec has, app lacks:** people-picker chips with DM/group auto-detection by participant count
- **App has, spec lacks (reconcile, don't strip):** separate DM vs group create forms; immediate post-create invite-link share step landing back on the inbox
- **Notes:** The mockup is now 'New message' (start a DM or group). The app folds creation into the Messages inbox and swaps the handle-picker for invite links — a settled, documented divergence, not a defect. Only cosmetic alignment remains (or a dedicated /civic/rooms/new surface if strict parity is wanted).

### `groups/group-detail.html` — Group home
- **Props missing:** attachment chips on messages + composer toolkit (files); live-now pill; DM-vs-group kind + 'temporary' labeling; make-this-a-standing-organization CTA target
- **Backend missing:** file/media attachments through the Matrix bridge (text-only posting today)
- **Spec has, app lacks:** composer toolkit + attachment chips in bubbles; live-now indicator; 'Make this a standing organization' link into org registration
- **App has, spec lacks (reconcile, don't strip):** member-gate locked stub for non-members; read-degrade to an empty timeline when the homeserver is down; REAL voice/video via LiveRoom + member-gated private call token; leave action; 5s visibility-aware timeline polling
- **Notes:** The mockup is now 'Conversation' (a message thread). The app room is functionally ahead on the hard parts (real Matrix timeline, real AV, gating); the missing piece with backend weight is attachments. The standing-org CTA is a one-line link — org registration already exists.

### `social/achievements.html` — Achievements
- **Backend missing:** view-time in-progress derivation (meters computed from own activity, never stored); catalog + milestone-wall read surface (jurisdiction/system milestones publish via PublicRecordService but nothing renders them)
- **Spec has, app lacks:** the entire catalog page: 4 subject tiers with ACH-* codes, earned vs locked medals, in-progress meters, global milestone wall, fence-rails card, planned banner
- **App has, spec lacks (reconcile, don't strip):** earner-mode discipline (awardSelf/awardSubject/awardState — mismatch throws); TIER_VERIFIED vs TIER_TOUR labeling requirement (journey ticks are self-reported walkthroughs); append-only DB-immutable ledger sealed to the audit chain
- **Notes:** The manifest note 'no AchievementCatalog in code' is STALE: K-2 built AchievementCatalog (139 entries, app/Domain/Achievements/AchievementCatalog.php) + AchievementService + the append-only achievements ledger, and earned medals already render at /civic/record?tab=achievements. What's missing is the browse/catalog PAGE and the derived in-progress queries — page + controller over an existing registry.

### `social/legitimacy.html` — Reach
- **Spec has, app lacks:** 'The fences around this number' rails card; 'What it is used for' card; threshold-machinery drawer (k/exponent/floor/cap param cards + sample-population table — app ships a prose summary); delta-over-N-days pill on the series
- **App has, spec lacks (reconcile, don't strip):** a distinct 'capped' state that discloses the raw over-100 figure; suppression-by-subtraction protection; planet option in the picker; honest gaps (curve stops rather than showing zero)
- **Notes:** Essentially conformant and in places more honest than the mockup (four explicit gauge states, read-only by charter). The gaps are static copy/detail sections — no controller or backend work; legitimacy_snapshots + the activation tier curve are live.

---

## learn-tr-support

Translation is the area's bright spot: both mockups are live at /system/translations and /system/translations/review/{locale} with a backend (quorum verifications, reader-gated verify rights, six-modality matrix, live run deck with operator halt/resume) that exceeds the spec — remaining gaps are the home page's verifier sign-up section, an add-a-language CTA, and Learn crosslinks. Support has only the intake: /support/report works end-to-end but with a different category taxonomy than the spec, no subject field, and no routing cards, while the entire post-filing lifecycle — queue, detail, thread, votes, severity, routing — has no page, route, or schema beyond the supports_reports row. Learn is entirely unbuilt as pages: the nav slot is a phase-7 placeholder (href:null in registry/surfaces.js), and the only shipped pieces are the per-screen Learn drawer (LearnFlyout.vue) and the journeys engine that every lesson's 'Now do it live' button targets. The multi-track video player is the area's one true XL — no player component or media subsystem exists in the app, though the design is proven on the Coalition's WP player. Lesson/SOP content itself can ship static (the flyout's own copy says 'learn ships static then'), making the three Learn pages L-grade config+page work once the player question is settled. Net: 2 of 9 screens conformant or better, 1 partial (report), 6 absent.

| Screen | In app | Route | Props | Backend | Effort | Headline |
|---|---|---|---|---|---|---|
| `shared/video-player.html` Video library — the multi-track player | — | `—` | none | none | **XL** | entire surface: multi-track player with linked audio/caption selection |
| `learn/learn-home.html` Learn — education tracks | — | `—` | none | none | **L** | six-track lesson index with done-state rows and Planned pills |
| `learn/lesson.html` Lesson — video + SOP + check | — | `—` | none | partial | **L** | lesson page: video, SOP panel, A/B/C quick check with explain-on-answer |
| `learn/guides.html` Guides & procedures — the SOP index | — | `—` | none | none | **L** | searchable SOP index grouped by module with expandable procedure panels |
| `translation/translation-home.html` Translation status — languages x modalities | System/Translations.vue | `GET /system/translations` | partial | partial | **M** | 'Become a verifier' card: language chips from account, sign-up button, awaiting-verific... |
| `translation/language.html` Language detail — review & verify | System/TranslationReview.vue | `GET+POST /system/translations/review/{locale}` | full | full | **S** | 'Watch how verification works' lesson link and page-level report-an-issue link (More se... |
| `support/report.html` Report an issue | Support/Report.vue | `GET /support/report` | partial | partial | **M** | category card grid with live route-note per selection |
| `support/tickets.html` Tickets — the queue | — | `—` | none | partial | **L** | entire queue surface: status filter (open collapses new/triaged/in_progress) + category... |
| `support/ticket.html` Ticket detail | — | `—` | none | partial | **L** | detail surface: report body, meta strip (reporter/routed-to/opened/updated/page) |

### `shared/video-player.html` — Video library — the multi-track player
- **Backend missing:** media storage/serving for silent-master MP4 + per-language .m4a audio + .vtt caption tracks; video catalog (titles, durations, track lists); remembered per-user language preference for playback; player component (link audio/captions toggle, 0.3s drift correction)
- **Spec has, app lacks:** entire surface: multi-track player with linked audio/caption selection; video library list with per-video language counts; how-the-player-works explainer; crosslink to translation status
- **Notes:** No player component or media subsystem exists anywhere in resources/js (the only 'video' hits are LiveKit voice rooms); the sole app touchpoint is TranslationReviewService declaring audio/captions modalities as unmeasurable placeholders. Design is proven on the Coalition WP player (functions/video_player.php) but in-app this is a new subsystem.

### `learn/learn-home.html` — Learn — education tracks
- **Backend missing:** lesson/track catalog (no registry anywhere; could ship static like config/cga/journeys.php); per-user lesson progress store; video tracks (see video-player row)
- **Spec has, app lacks:** six-track lesson index with done-state rows and Planned pills; progress bar (n of N lessons); featured lesson with embedded video; Tracks/Videos browse toggle; More-ways-in cards (guides, video library, translation status, external Coalition courses)
- **Notes:** Nav already carries the slot as a phase-7 placeholder (resources/js/registry/surfaces.js:34, href:null, contract 'learn/learn-home.html'), and LearnFlyout.vue shows 'Full lessons — Planned · Phase 7'. The journeys engine (/journeys, live) is the built half every lesson deep-links into.

### `learn/lesson.html` — Lesson — video + SOP + check
- **Backend missing:** lesson + SOP registry (resolves ?id= / ?sop=); knowledge-check content and grading; video tracks
- **Spec has, app lacks:** lesson page: video, SOP panel, A/B/C quick check with explain-on-answer; 'Now do it live' deep-link into the journey; up-next progression within a track
- **Notes:** The 'Now do it live' target already exists and works — /journeys/{id} (Civic/Journey.vue, JourneysController, durable per-user completion). Everything upstream of it (lesson content, check, SOP text) has no app counterpart.

### `learn/guides.html` — Guides & procedures — the SOP index
- **Backend missing:** SOP registry (steps + rule-behind-each-step + linked video + journey id); nothing SOP-shaped exists in config/ or resources/js/registry
- **Spec has, app lacks:** searchable SOP index grouped by module with expandable procedure panels; operator-scope pills; empty-search 'tell us what's missing' link into /support/report
- **Notes:** Static data + one page; config/cga/journeys.php and surfaces.php are natural seeds for the registry. The 'report an issue' escape hatch it links to is already live.

### `translation/translation-home.html` — Translation status — languages x modalities
- **Props missing:** verifier section props (my languages + fluency, pending contributions awaiting verification in my languages); add-a-language CTA wiring (mockup links /support/report?ref=Add+a+language and a how-verification-works lesson)
- **Backend missing:** audio/captions/education/help modalities are declared but unmeasured placeholders (produced outside the app, measurable:false); no explicit verifier sign-up (rights derive silently from users.locale/languages); no dedicated language-request path beyond the generic support intake
- **Spec has, app lacks:** 'Become a verifier' card: language chips from account, sign-up button, awaiting-verification list with verify/report actions; 'Add a language' section with request button; how-verification-works video link (blocked on Learn)
- **App has, spec lacks (reconcile, don't strip):** live translation run deck: per-worker table, strings/sec, ETA, in-flight strings, operator halt/resume; gate coverage artifact half: failure codes C0–C7, per-locale coverage table, namespace grid, 'not measured yet' state
- **Notes:** The matrix, legend, six-kinds cards, lifecycle states, and engine SOP all match the mockup and are fed by real data (TranslationReviewService::MODALITIES/STATES). The one missing named group is the verifier section — one surface plus one controller prop.

### `translation/language.html` — Language detail — review & verify
- **Spec has, app lacks:** 'Watch how verification works' lesson link and page-level report-an-issue link (More section) — blocked on Learn; the report link could point at /support/report today
- **App has, spec lacks (reconcile, don't strip):** constitutional terms table (settle-first workflow, n of N settled, same quorum endpoint); machine-refused quarantine badges with reasons; edit-with-your-wording flow (approve/reject/edited verdicts, edit-equals-approve collapse); cannot-verify explainer state with how-to-join instructions
- **Notes:** Reader-gating, quorum (3), RTL, per-modality rows with pct+verifiers, source-beside-draft queue, and contributors all exist and are wired; the app exceeds the mockup. Only the two Learn crosslinks are absent.

### `support/report.html` — Report an issue
- **Props missing:** category routing metadata (routesTo, per-category notes/icons, deep links) — app ships flat id+label pairs into a select, not the mockup's radio card grid; short-summary/subject field (app has body only); post-submit confirmation view with ticket number and follow-in-queue link (app flashes a public_id reference string)
- **Backend missing:** routing/assignee on SupportReport (category is stored, routing is prose); subject column; category taxonomies differ: mockup bug/translation/accessibility/content/abuse/idea vs app bug/question/conduct/legal/appeal/other — needs reconciliation to the spec; instance & version auto-attach (only ref is attached)
- **Spec has, app lacks:** category card grid with live route-note per selection; abuse card styled/routed distinctly to the moderation plane with deep link; 'Where a report goes' routing table; link to the ticket queue
- **App has, spec lacks (reconcile, don't strip):** guest-view mode (see form, prompted to log in); F-SOC-003 carve-out plain-words note on conduct/legal categories; flash reference number for follow-up
- **Notes:** The intake works end-to-end (validated store, public_id, ref sanitization) and self-describes as 'deliberately simple — restyled in a later phase'. Closing it is one-surface rework plus reconciling the category taxonomy to the spec; the queue link lands with the tickets row.

### `support/tickets.html` — Tickets — the queue
- **Backend missing:** severity; votes ('affects me too'); assignee/routed-to; open-count aggregates and a visibility policy (who may browse the queue)
- **Spec has, app lacks:** entire queue surface: status filter (open collapses new/triaged/in_progress) + category filter; severity dots; vote counts; status pills and updated-relative times; report CTA
- **Notes:** supports_reports rows exist with category, status (open/triaged/closed), reporter, ref, timestamps — a browsable queue is a new page + controller + small schema additions on top. No ticket-shaped UI exists anywhere (Operator pages have zero support mentions).

### `support/ticket.html` — Ticket detail
- **Backend missing:** ticket thread/status-history events; replies; +1 votes; routing/assignee display; linked translation-review reference (code+modality) and moderation-plane carve-out note fields
- **Spec has, app lacks:** detail surface: report body, meta strip (reporter/routed-to/opened/updated/page); status thread timeline; reply box and 'this affects me too'; route-note blocks linking the translation review queue or the moderation & legal plane
- **Notes:** Only the intake row exists; the lifecycle after filing (thread, replies, votes, crosslinks) has no tables, controller, or page. The translation crosslink target (/system/translations/review/{code}?m=) already exists, so linkage is a foreign key + render once the page is built.

---

## shared

The shared module's three public front doors — Launchpad, Atlas, and the tour directory — have no app pages at all; the Atlas is the area's one true XL, needing a public metrics-aggregation surface plus an opt-in approximate-location subsystem, while the launchpad and tour pages are cheap builds over registries that already exist (journeys engine, TOUR, room list in resources/js/registry/surfaces.js). Tour-as-a-mode is fully live (useTour.js, TourBar.vue, 21 of the mockup's 35 stops wired); only the directory page is missing. System/Clocks is the area's one conformant screen and actually exceeds the mockup (live armed timers, due-now fault indicator, playtest dry-run) — its only gap is that the mockup reads public while /system/clocks is auth-gated. The bill-conversation surface is unbuilt: the formal record (BillDetail) and auto-bound hall subforums both exist, but nothing fuses them, and clause-level redline negotiation has no backend. The QA instruments (coverage, coverage-ops, styleguide) have solid partial equivalents — registry/surfaces.js with per-entry contract keys, config/cga/surfaces.php + SurfaceMeta's enforced ids, the pinned 111-form count, and the four dev-gated component kits — but no rendered coverage instrument and no registry↔contract drift test. The two static contract pages (accessibility, constitutional-questions) are absent even though CitationLine.vue already documents the ledger's anchor scheme, so citations pointing there would 404 today.

| Screen | In app | Route | Props | Backend | Effort | Headline |
|---|---|---|---|---|---|---|
| `index.html` Launchpad — the five interaction classes + journey directory | Home.vue | `/` | none | partial | **M** | hero + four arrival doors |
| `atlas.html` The Atlas — public, game-wide heartbeat dashboard | — | `—` | none | partial | **XL** | living dotted world map with four toggleable layers (nodes/people/orgs/places) |
| `tour.html` Guided tour — the linear walkthrough | — | `—` | none | full | **M** | the tour directory PAGE (act-grouped list of every stop + start CTA) |
| `shared/live-room.html` Live Civic Room — the keystone (8 variants via ?variant=) | Legislature/SessionConsole.vue (+ Civic/MatrixCommons.vue, Judiciary/CaseDetail.vue, Organizations/BoardElections.vue) | `/legislatures/{legislature}/session · /civic/commons/square|halls · /cases/{case} · /organizations/{organization}/board-elections` | n/a | n/a | **XL** | the fused 8-variant room itself — constituent surfaces exist separately |
| `shared/bill.html` A bill — the conversation | — | `—` | none | partial | **L** | a unified conversation page: plain-words summary + stage rail |
| `shared/coverage.html` v2 coverage — meeting types × journeys × interaction classes × mechanics | — | `—` | n/a | partial | **S** | live coverage report over rooms/classes/journeys/mechanics |
| `shared/coverage-ops.html` Coverage matrix — roles × workflows × forms | — | `—` | n/a | partial | **S** | the regenerated-on-load matrix instrument |
| `shared/styleguide.html` Style guide — live component sheet | Dev/LegislatureKit.vue (+ ElectoralKit, ExecutiveOrgKit, JudiciaryKit) | `/dev/legislature-kit, /dev/electoral-kit, /dev/executive-kit, /dev/judiciary-kit` | full | n/a | **S** | one consolidated all-component sheet |
| `shared/clocks.html` Clocks & triggers | System/Clocks.vue | `/system/clocks` | full | full | **S** | public read — the app route sits in the auth-gated /system group while the mockup is a ... |
| `shared/constitutional-questions.html` Constitutional questions — the implementation ledger | — | `—` | none | n/a | **M** | the ledger page: seven entries (q1–q7) with preserved anchors, maintained-ledger banner... |
| `shared/accessibility.html` Accessibility statement | — | `—` | none | n/a | **M** | the statement page (what-is-built-in with WCAG citations, known limitations, feedback p... |

### `index.html` — Launchpad — the five interaction classes + journey directory
- **Props missing:** doors grid; live-room variant directory; journey directory with progress rails; interaction classes; learn/help links; operator door; tour CTA
- **Backend missing:** live-room fused variants (contract agent); atlas target for the Atlas door; learn module (phase 7)
- **Spec has, app lacks:** hero + four arrival doors; eight live-room variant cards; journey directory with progress rails and passes-through room chips; five interaction-class explainer grid; learn/translate/support link grid; operator-plane door; Start-the-tour bar
- **App has, spec lacks (reconcile, don't strip):** setup gating — / redirects to /setup on an unfounded instance; authenticated redirect to /civic
- **Notes:** Home.vue is a bare placeholder (one h1 + setup gate); all the launchpad's data sources already exist app-side (journeys engine at /journeys, TOUR + room registry in resources/js/registry/surfaces.js), so this is one landing surface built over existing registries.

### `atlas.html` — The Atlas — public, game-wide heartbeat dashboard
- **Backend missing:** atlas aggregation endpoint/service (no Atlas code anywhere in app/); opt-in approximate-position store for the people layer (location_pings are private, unusable as-is); node lat/long + public nodes directory; reach/legitimacy metrics (display-only, phase 7)
- **Spec has, app lacks:** living dotted world map with four toggleable layers (nodes/people/orgs/places); put-yourself-on-the-map opt-in + privacy banner; nine-domain vital-signs grid (world, reach, representation, executive, judiciary, organizations, economy, people, mesh); growth sparklines; nodes-and-operators directory; calls to action
- **Notes:** PLAYER_NAV pins it as href:null phase 7. Most vital signs are now computable from live tables (economy included since L+M shipped), but the public aggregation surface, the opt-in people layer, and node geodata are new subsystems. Screen-specific chrome: main--wide full-bleed map card.

### `tour.html` — Guided tour — the linear walkthrough
- **Spec has, app lacks:** the tour directory PAGE (act-grouped list of every stop + start CTA); the ten-stop first-visit track; stop coverage: mockup walks 35 stops, app TOUR registry wires 21 across the same 8 acts (grows as phases land)
- **Notes:** Tour-as-a-MODE is already fully live app-side (composables/useTour.js, Components/ShellV2/TourBar.vue, TOUR in registry/surfaces.js, menu special-case tour:start, shareable ?step=N) — only the directory page that lists and launches it is missing. Pure client page over the existing registry; no controller logic needed.

### `shared/live-room.html` — Live Civic Room — the keystone (8 variants via ?variant=)
- **Spec has, app lacks:** the fused 8-variant room itself — constituent surfaces exist separately
- **Notes:** Owned by the dedicated live-room contract agent — facts only here. All listed form families (F-SPK-001..009, F-CHR-001..004, F-LEG, F-JDG, F-ORG, F-SOC) verified present in FormRegistry.php.

### `shared/bill.html` — A bill — the conversation
- **Backend missing:** clause-level redline negotiation model (negotiate-v2's propose/counter flow has no server analog — only whole-version amendments + server TextDiff)
- **Spec has, app lacks:** a unified conversation page: plain-words summary + stage rail; inline public comment composer on the bill; clause redline negotiation UI; watch-the-floor live-room link
- **App has, spec lacks (reconcile, don't strip):** bill talk exists but lives in the auto-bound hall subforum (SubforumReconciler binds every live bill; F-SOC-001 posts) rather than on a bill page; the formal record (Legislature/BillDetail.vue at /bills/{bill}) carries versions, server-computed diffs, and dual-agreement meters
- **Notes:** The mockup itself splits conversation from the formal record; the app built only the formal half. A conversation page could compose existing pieces (bill lifecycle, hall subforum thread, TextDiff) — the clause negotiation backend is the genuinely new part.

### `shared/coverage.html` — v2 coverage — meeting types × journeys × interaction classes × mechanics
- **Backend missing:** no rendered coverage instrument and no automated registry↔contract drift test (SurfaceMeta::ids() exists 'for the registry cross-check test' but none found)
- **Spec has, app lacks:** live coverage report over rooms/classes/journeys/mechanics
- **App has, spec lacks (reconcile, don't strip):** registry/surfaces.js carries a contract: key per entry precisely so it can be diffed against the 107-screen manifest
- **Notes:** QA instrument — judged as equivalence, not parity. The surface registry exists (resources/js/registry/surfaces.js + config/cga/surfaces.php); what's missing is the instrument that proves coverage, cheapest as a test asserting every manifest screen has a registry entry.

### `shared/coverage-ops.html` — Coverage matrix — roles × workflows × forms
- **Backend missing:** no roles×workflows×forms matrix view or report; no dead-link/manifest-drift scan analog
- **Spec has, app lacks:** the regenerated-on-load matrix instrument
- **App has, spec lacks (reconcile, don't strip):** config/cga/surfaces.php entries carry roles/workflows/forms per surface; FormRegistry is canonical with the 111-form count pinned in AuditChainSmokeTest; SurfaceMeta throws on unknown surface ids (enforced, not logged)
- **Notes:** QA instrument. The axes and the fill both exist as machine data app-side; only the matrix renderer/test over them is absent.

### `shared/styleguide.html` — Style guide — live component sheet
- **Spec has, app lacks:** one consolidated all-component sheet; RTL/pseudo-locale canary block; flow-stepper contract stress test against the three sample workflows
- **App has, spec lacks (reconcile, don't strip):** kits render every component STATE from extracted fixtures with provenance, per phase
- **Notes:** QA instrument — the four dev-gated component kits are the app's live component sheet, split by phase instead of one page. Dev-gating is appropriate for a build-team instrument.

### `shared/clocks.html` — Clocks & triggers
- **Spec has, app lacks:** public read — the app route sits in the auth-gated /system group while the mockup is a public governance-register reference page
- **App has, spec lacks (reconcile, don't strip):** LIVE armed-timers column (count + soonest real fires_at per clock); due-now overdue-timer fault indicator; playtest advance-N-days dry-run preview (only when dev time controls enabled)
- **Notes:** The one fully conformant shared surface: all mockup columns (name/type/default/amendable/fires/basis), the three stats, family grouping, and the Reading-the-registry + About sections are shipped, and the app exceeds the spec with live scheduler state. Only open point is dropping the auth gate for public read.

### `shared/constitutional-questions.html` — Constitutional questions — the implementation ledger
- **Spec has, app lacks:** the ledger page: seven entries (q1–q7) with preserved anchors, maintained-ledger banner, status legend, how-an-entry-lands section; the site-wide 'as implemented' citation markers resolving to its anchors
- **Notes:** The app already anticipates it: Components/Ui/CitationLine.vue takes an anchor prop documented as '/system/constitutional-questions#q2', but no such route or page exists — any anchor passed today would 404. Static content page; copy is fully authored in the mockup.

### `shared/accessibility.html` — Accessibility statement
- **Spec has, app lacks:** the statement page (what-is-built-in with WCAG citations, known limitations, feedback path); footer link from every page; per-locale republication of the statement
- **Notes:** registry/surfaces.js lists it href:null phase 7 (contract shared/accessibility.html). Static content page — no controller logic beyond a SurfaceMeta entry; the per-locale republication rides the existing i18n catalog machinery.

---

