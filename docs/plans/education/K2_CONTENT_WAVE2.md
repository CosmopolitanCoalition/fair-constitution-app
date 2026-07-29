# K2 — Per-Screen Education Content, Wave 2 (authored ahead of the pages)

**Owner:** Lane 15 (Phase K-2) · **Deliverable:** V3 synthesis plan §4 item L3 · **Authored:** 2026-07-28
**status: authored-ahead** — every surface in this file ships its PAGE in Wave 2 or later; the copy
is written now so it lands WITH the page, not after it (the operator's rule: his first walk must
never land on a placeholder). The build pipeline (`scripts/education/build_education_payload.mjs`)
emits these entries alongside the settled corpus; ids not yet in `config/cga/surfaces.php` are
logged as *authored-ahead* and light up in the Learn flyout the moment the page's controller
serves `SurfaceMeta::for('<id>')`.

**Authoring provenance:** 7 module-family writers, each required to read its mockup html and
verify every cite against `FormRegistry` before writing; 7 adversarial verifiers behind them
(38 defects found — false form cites, promises the backend cannot keep, mockup contradictions —
all applied or explicitly skipped where the verifier refuted itself); surgical fix pass; final
assembly read. Voice and format are the operator-approved template from `K2_CONTENT_SURFACES.md`.

---

## ⚑ THE SURFACE-ID CONTRACT (for @lane-13, @lane-2, @lane-6, @lane-3 — Wave 2 builders)

The Learn copy below is keyed to these ids. **When you build the page, register this exact id in
`config/cga/surfaces.php` and pass `SurfaceMeta::for('<id>')`** — the education payload, the flow
context, and the machinery block all join on it for free. Renames are fine but tell lane 15 so
the corpus and the generated payload move with you.

| Future surface id | Mockup (the spec) | Page owner (plan §11) |
|---|---|---|
| `economy/home` | economy/economy-home.html | 13 |
| `economy/marketplace` | economy/marketplace.html | 13 |
| `economy/listing-detail` | economy/listing-detail.html | 13 |
| `economy/request-detail` | economy/request-detail.html | 13 |
| `economy/wallet` | economy/wallet.html | 13 |
| `economy/exchange` | economy/exchange.html | 13 (design-gated) |
| `economy/joint-ledgers` | economy/joint-ledgers.html | 13 |
| `economy/units` | economy/units.html | 13 |
| `economy/stipend` | economy/stipend.html | 13 |
| `economy/treasury` | economy/treasury.html | 13 |
| `economy/agreements` | economy/agreements.html | 13 |
| `economy/agreement-detail` | economy/agreement-detail.html | 13 |
| `economy/org-settings` | economy/org-settings.html | 13 (design-gated) |
| `support/report` | support/report.html | 6 (page LIVE, id unregistered) |
| `support/tickets` | support/tickets.html | 6 |
| `support/ticket` | support/ticket.html | 6 |
| `learn/home` | learn/learn-home.html | 15 (engine build) |
| `learn/lesson` | learn/lesson.html | 15 (engine build) |
| `learn/guides` | learn/guides.html | 15 (engine build) |
| `civic/rooms` | groups/groups-home.html | 3/6 (page LIVE, id unregistered) |
| `groups/create` ⚠ provisional | groups/group-create.html | 3 (groups model awaits ratification) |
| `groups/detail` ⚠ provisional | groups/group-detail.html | 3 (same) |
| `social/profile` | social/profile.html | 15 + 6 (Wave 2) |
| `social/achievements` | social/achievements.html | 15 (Wave 2) |
| `jurisdictions/bootstrap` | jurisdictions/bootstrap.html | 2 |
| `jurisdictions/union-formation` | jurisdictions/union-formation.html | 2 |
| `jurisdictions/disintermediation` | jurisdictions/disintermediation.html | 2 |
| `jurisdictions/restoration` | jurisdictions/restoration.html | 2 |
| `jurisdictions/federation` | jurisdictions/federation.html | 2 (ruling 9: citizen view) |
| `operator/setup` | operator/setup.html | 2 |
| `operator/dns` | operator/dns.html | 2 |
| `operator/moderation` | operator/moderation.html | 2 |
| `shared/launchpad` | index.html | 6 |
| `shared/atlas` | atlas.html | 3/4 (Wave 3, XL) |
| `shared/tour` | tour.html | 6 |
| `civic/live-room` ⚠ provisional | shared/live-room.html | 3 + desk (slice 6 keystone) |
| `legislature/bill-conversation` ⚠ provisional | shared/bill.html | 3 |
| `shared/constitutional-questions` | shared/constitutional-questions.html | 6 |
| `shared/accessibility` | shared/accessibility.html | 6 |
| `invite/landing` | civic/join.html | 6 (page LIVE, bare layout — copy waits for the shell) |
| `system/translation-review` | translation/language.html | 5 (surface REGISTERED — lights up at once) |

**Excluded by design** (same rule as the dev kits): `shared/coverage.html`,
`shared/coverage-ops.html`, `shared/styleguide.html` — build-team surfaces, not civic life.
`shared/video-player.html` is parked with plan item L6 (no media exists).
Six more manifest rows were already covered by the settled corpus under app ids
(journeys/journey→`civic/journey`, social/social-home→`civic/public-square`,
social/org-profile→`organizations/org-detail`, operator/operator-home→`operator/home`,
translation/translation-home→`system/translations`, shared/clocks→`system/clocks`).

---

## Wave 8 — economy (market half)

### 71. `economy/home` — The economy
**learn:** Everything you trade, agree, earn, and owe — in one place. The unit you see on every price is an abstract unit of account: it counts value inside the game and never touches outside money — no payment rails, no custody.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Pick a door | The cards route you to the open market, the exchange, agreements, your wallet, joint ledgers, units & money, the civic stipend, public finance, and org economics. The square is for talk; the market is for trade. | — |
| 2 | Check the economic clock | The recurring economy run fires on an interval set by law — changing the cycle takes the same two doors as any money rule. It is a law, not an admin knob. | — |
| 3 | Read the rails that hold everywhere | Your economic data is private to you, the way a ballot is. The currency is reserved to the most-encompassing jurisdiction. And money rules move only through two doors — a supermajority of the chamber **and** the consent of the people it governs. | Art. VII |

> **The why:** No paywall on civic rights. No tax, fee, or cost may ever attach to voting, candidacy, residency, or petitioning — you can go broke here and lose nothing that matters. Wealth buys goods; it never buys governance. (Art. I)

---

### 72. `economy/marketplace` — The open market
**learn:** One market, two directions: **offers** are what people will give, **requests** are what people need. It is open to every resident — no licence, no membership, no permission needed, and the app deliberately checks for none.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Browse offers, or switch to requests | Offers are goods and services for sale. Requests are work postings and neighbors asking for a hand. Same market, opposite directions. | — |
| 2 | List something of your own | One form puts a good or a service on the market. A person, a business, a nonprofit, and a common-good corporation all list on identical terms — nobody gets a better counter. | F-IND-022 · Art. I |
| 3 | Place an order on an offer | Ordering opens a purchase against the seller's listing. Nothing binds until the seller accepts — a one-sided contract never takes effect. | F-IND-022 |
| 4 | Ask or answer under mutual aid | No price, no contract — a neighbor asking for help is not a trade, so the app puts no market machinery on it. Private by default — it is shared only as far as the asker chooses. | — |

---

### 73. `economy/listing-detail` — Listing
**learn:** One offer, end to end — what it is, who is selling it, and exactly what happens when you order. Your receipt is private: like a ballot, only buyer and seller can read it.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Read the listing like the contract offer it is | The price, the quantity, the seller and what kind of seller they are. A Common Good Corp badge means identical terms to any private seller — and work that stays public domain forever. | Art. III §5 |
| 2 | Place your order | Your order files against the listing. You are committing to buy at the listed price; nothing binds until the seller accepts. | F-IND-022 |
| 3 | Wait for the seller to accept | Only the seller settles an order — a buyer can never complete their own purchase. On acceptance the order becomes a two-sided agreement, and no clause in it may waive a right. | F-IND-022 |
| 4 | Settlement posts privately | The units move account to account — a debit to yours, a credit to the seller's. No payment rail, no middleman, and nobody else can read the movement. | — |

---

### 74. `economy/request-detail` — Work posting
**learn:** One job on the board, end to end: the rate, the organization, and what an accepted application sets in motion — down to the board seat it can help earn.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Apply | Any resident may apply — there is no means test and no qualification gate, and applying never touches a civic right. Your application is visible only to you and the organization. | Art. I |
| 2 | The organization accepts | Acceptance is the trigger. Nothing binds until both sides act — a one-sided contract never takes effect. | — |
| 3 | Your work agreement goes on the record | On acceptance, **you** file the worker registration — the record is yours, not the employer's. It captures the recurring work agreement both of you sign. | F-IND-014 |
| 4 | Your hire counts | The new contract raises the organization's worker headcount. At 100 workers the board gains its first worker seat; at 2,000, workers and owners hold equal board power. | Art. III §6 |

> **The why:** The headcount math is hardened — no labor contract can bargain away representation. The hire that crosses 100 changes the boardroom, and no clause the employer writes can undo it. (Art. III §6)

---

### 75. `economy/wallet` — My wallet
**learn:** Your balance and every receipt, private the way a ballot is private — only you can read them. Nothing here holds or moves real money; the unit is an abstract measure of value inside the game.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Read your balance and activity | Credits in, debits out, each with its counterpart account and memo. This list is yours alone — it is never published, shared, or federated. | — |
| 2 | Watch the stipend arrive | The civic stipend is always on, for everyone — a floor everyone shares plus a small bump for civic work, under a cap. It can only credit you, never withhold, and its size changes no eligibility for anything. | — |
| 3 | Send a transfer — to an account | A transfer names the recipient's **account**, never a person. Your own account is resolved from your identity, and the form can never be used to discover who owns an account. An overdraft is refused; so is paying yourself. | F-IND-023 |

> **The why:** Transfers address accounts because ledgers record value, not identity. If transfers took names, every ledger would slowly become a map of people — addressing accounts keeps who-you-are permanently separate from what-you-hold, the same separation a ballot enjoys.

---

### 76. `economy/exchange` — The exchange
**learn:** The trading floor for organization shares — a live tape, an order book, and the recent trades, all in the open. Single items sell on the open market; only shares trade here.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Watch the tape, tap a symbol | Tapping brings that share into focus: last price, change, volume, and its recent trend. A CGC tag means a common-good corporation quoting on identical terms — its work stays public domain forever. | Art. III §5 |
| 2 | Read the order book | Bids are what buyers will pay; asks are what sellers want; the gap between them is the spread. Price discovery happens in the open — everyone sees the same book. | — |
| 3 | Understand what a matched trade becomes | A matched order forms an agreement between the two parties and settles to their private wallets. No payment rails, no custody, no shared pot — the floor discovers the price, the parties own the trade. | — |
| 4 | Buy goods on the open market instead | Single items sell at one place and one price on the open market — this floor is only for shares. | F-IND-022 |

---

### 77. `economy/joint-ledgers` — Joint ledgers
**learn:** A joint ledger is a co-owned account: its balance belongs to more than one party, and no movement leaves it until every required co-owner agrees. One signer can never move shared money alone.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Read the rule before the balance | Every ledger names its co-owners and its approval rule up front — all signers, or a majority, as agreed when it opened. Every co-owner must accept before the ledger exists at all. | — |
| 2 | Propose a movement | Any co-owner can propose a payment, a transfer, a drawdown. The parties set their own rule and purpose freely — but no clause in the agreement may waive a constitutional right. | Art. I |
| 3 | Approve — or don't | Your signature is one of several. The movement waits, showing who has agreed and who is still needed, and settles only when the rule is met. | — |
| 4 | Know which ledgers are public | A jurisdiction-owned shared fund posts to the public record, where every entry is chained to the one before so history cannot be quietly rewritten. A ledger between people or organizations is readable only by its co-owners. | — |

---

## Wave 8 — economy (public-money half)

### 78. `economy/units` — Units & money
**learn:** This is the money itself — the unit of account, what it is worth, how it subdivides — and every lever that sets those things. No lever here is an admin knob: each one moves only by a legislative act, and the currency is produced by the most-encompassing jurisdiction alone.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Read the unit | Name, symbol, precision, and the stack of subdivisions. Defining the unit and its subdivisions is the power to set measurement standards — one shared yardstick for the whole federation, so it lives at the root, and no lower jurisdiction may mint. | Art. V §5 |
| 2 | Inspect each monetary lever | Every value shown — issuance rate, inflation target, the stipend dials — was set by a legislative act and carries the act that set it. There is no settings screen behind this page; the only write path is a bill. | F-LEG-031 |
| 3 | Understand the two-door rule | A monetary lever moves only when two doors open: a supermajority of the chamber, and then the consent of the constituent jurisdictions whose money it is. Where a jurisdiction has no constituents, the second door is satisfied by itself — but it is never skipped where they exist. | Art. VII; Art. IV §3 |
| 4 | Notice the clock is a lever too | How often the economy pays out is itself one of the settings, changed by the same two-door act as every other lever. Nothing about the money's rhythm belongs to an operator. | F-LEG-031 |

> **The why:** The demo runs a social-credit unit, but nothing here depends on that. The constitution fixes *who decides* and *how* — never *which* currency. The machinery takes no position on how much money should exist; that question belongs entirely to the people whose money it is.

---

### 79. `economy/stipend` — Civic stipend
**learn:** Every resident receives the civic stipend — a shared floor, plus a small capped bump for people carrying certain duties. It is a differential, not a salary: it grants no seat, no vote, no advantage of any kind.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Confirm residency — that is the whole test | The only gate is an active residency, the same absolute gate as voting. There is no means test, no application, no age line, no good-standing check — and none can ever be added. | Art. I |
| 2 | Read the formula | Your amount is the floor plus the sum of your duty bumps, capped. A bump attaches to a fact about what you are doing — running a node, moderating, holding office — and it ends the day the duty ends. Stacking can never exceed the cap. | F-LEG-031 |
| 3 | Check the public total against your private receipt | Each run posts one public line: the total paid and a head-count. Your own amount writes only to your private wallet, and any bump class small enough that a published total would give someone's amount away is folded into the general figure instead. | — |
| 4 | Propose a change through the chamber | The floor, the cap, and every bump move only by a two-door act — chamber supermajority plus constituent consent. Zeroing the stipend changes nobody's eligibility for anything. | F-LEG-031 · Art. IV §3 |

> **The why:** If a treasury-funded run cannot cover everyone, the system short-pays *everyone* by the same fraction and says so publicly. Paying the first names on the list and stopping would draw an arbitrary line between people the constitution treats identically — so the code refuses to be able to.

---

### 80. `economy/treasury` — Public finance
**learn:** How your jurisdiction raises, budgets, and spends money — entirely in the open. Public money is public record: anyone may read this page, resident or not, because a ledger you cannot inspect is not a public ledger.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Follow the budget cycle | The legislature enacts the budget as an ordinary act, and enactment itself spawns the spending authority for each line — a budget and its appropriations are born together, never one without the other. | F-LEG-003 · F-LEG-004 |
| 2 | Read the public ledger | Every row is double-entry: it names where value left and where it landed, so the books always balance — except issuance, the one lawful entry where money comes into existence at the root (Art. V §5) with no source to name. Rows are append-only and hash-chained — each row carries the fingerprint of the one before it, so nothing is ever edited or deleted without breaking the chain; a correction is a new balancing entry with its own line in history. | — |
| 3 | Tell public accounts from private ones | Jurisdiction and department accounts are public — balance and every movement. Individual and organization accounts are private, readable only by their owner, like a ballot. Transfers name accounts, never people. | — |
| 4 | Know what only the legislature can do | Levies and borrowing happen only by act — the executive disburses what was appropriated and authorizes nothing. And no tax, fee, or cost may ever attach to voting, candidacy, residency, or petitioning. | Art. III §4 · Art. II §8 |

> **The why:** Exactly one piece of code in the entire system can move value, and the test suite scans the source to keep it that way. With one writer and no side door, "the books balance" is a property of the system itself — not a report somebody has to run and everybody has to trust.

---

### 81. `economy/agreements` — Agreements
**learn:** Any two or more parties may freely set terms between themselves — that is the freedom to contract. But every agreement sits on a floor: no clause may waive, sell, or sign away a constitutional right, and nothing binds until every party signs.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Draft the kind you need | Recurring work, an ownership transfer, a sale, a shared account, or anything custom — each kind files as its own record. A recurring work agreement also registers you as a worker of that organization. | F-IND-014; F-ORG-005; F-IND-022; F-IND-023 |
| 2 | Gather every signature | A one-sided contract never takes effect. Until each named party has signed, the agreement does not exist and binds no one — there is no provisional or default-on state. | — |
| 3 | Know the floor | A clause that touches voting, candidacy, residency, petitioning, or due process is void — the rest of the agreement stands. And no agreement may put a price on exercising a civic right. | Art. II §8 |
| 4 | Keep the terms private | An agreement's terms are readable only by its parties, like a ballot. What becomes public is only what the agreement causes — a worker headcount, a movement between accounts — never the terms themselves. | — |

> **The why:** Both-must-sign is not a policy the app checks — it is a rule the data store itself enforces. The record physically cannot be marked in force until every named party's signature timestamp exists, so no bug, admin, or clever code path can ever activate a one-sided contract.

---

### 82. `economy/agreement-detail` — Agreement
**learn:** One agreement, in full: its parties, its terms, where it stands. You are reading it because you are named in it — nobody else can.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Read the terms and the parties | Each party appears in their own role, identified by the residency-confirmed record — never by a paid account or a verification tier. The terms are private to the people named here. | Art. I |
| 2 | Sign — or decline | Your decision is recorded against your own identity only. Signing binds you once everyone has signed; declining means the agreement simply never exists. The absence of one signature is the absence of the agreement. | — |
| 3 | Check the floor beneath it | No clause here can waive a right — an offending clause is void while the rest stands — and work terms can never sign away the workers' right to board representation. | Art. II §8 · Art. III §6 |
| 4 | If it is recurring work, know what signing sets in motion | Once every party has signed, the contract adds one worker to the organization's headcount. That count — computed from the contracts, never declared by the owners — is what earns workers their first board seat at 100 and equal seats at 2,000. | F-IND-014 · Art. III §6 |

---

### 83. `economy/org-settings` — Org economics
**learn:** The steward's view of an organization's economic life — its board split, shares, dues, ledger, and taxes — each fenced by a floor no charter or rule-change can cross. Most players never need this page.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Review the board and worker seats | The worker/owner split is computed from the labor agreements on record, never negotiated or declared: the first worker seat arrives at 100 workers, parity at 2,000. One hire can change the board. | Art. III §6 |
| 2 | Read the share register | Stock-held organizations list their share classes and holders; equal-partnership and member-owned organizations carry no shares at all — ownership follows the charter. An ownership stake moves only by a transfer both sides sign. A common-good corporation's intellectual property stays public domain forever. | F-ORG-005 · Art. III §5 |
| 3 | Understand dues | Dues are a voluntary agreement between a member and an organization that chooses to charge them. Let them lapse and the membership ends — nothing else does. No due, fee, or cost may ever gate a civic right. | Art. II §8 |
| 4 | Know what stays private and what is apportioned | The organization's own ledger — balances, receipts, movements — is private to the organization, like a ballot. The levies it owes are apportioned from the population records, never assessed against anyone's civic rights. | — |

---

## Wave 9 — support + learn

### 84. `support/report` — Report an issue
**learn:** When something is wrong — a broken page, a mistranslation, an accessibility barrier, abuse — this is the one door. You pick the subject; the report finds the right people on its own.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Pick one of the six categories | Something is broken, wording or translation, an accessibility barrier, wrong information, abuse or illegal content, or an idea. A complaint about someone's conduct files under abuse. Your choice is the routing — bugs and barriers go to the operators, wording to the translators, ideas to the backlog. | — |
| 2 | Describe what happened | What you expected, and what you saw instead. The page you were on and the software version attach themselves — you never have to explain where you were. You file from your account, but you don't have to use your real name, and where you live is never attached. | — |
| 3 | Submit, then follow it | The report becomes a numbered ticket you can watch move from new to resolved in the ticket queue. | — |
| 4 | Know what support cannot do | An abuse or illegal-content report skips the tech-support queue and goes straight to the moderation and legal team — and even they never judge viewpoint. Content comes down only through the logged removal paths — a judicial order, protecting another's rights, your own block, or content-neutral anti-spam. Support staff never decide what people may say. | F-SOC-003 · F-SOC-004 · Art. I |

> **The why:** Notice the subject that is not on the list: appealing a decision. Challenging a ruling or an official act is constitutional business — it belongs before the courts (Art. IV), never a help desk. Support fixes the software; it holds no power over what anyone did with it.

---

### 85. `support/tickets` — Tickets
**learn:** Every report filed and where it stands — nothing vanishes into a private inbox. Filter it, follow your own, and add weight to the problems that hit you too.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Filter by status and category | "Open" covers new, triaged, and in progress; resolved, closed, and won't fix are the finished states. Subject narrows the list to bugs, wording, accessibility, wrong information, abuse, or ideas. | — |
| 2 | Read a row at a glance | The number, a severity dot, the title, its status, the category it routed under, how many people said it affects them, and when it last moved. | — |
| 3 | Open a ticket — or file a new one | Each row opens the full report with its history thread. The report button stays in reach for anything you hit that isn't listed yet. | — |

---

### 86. `support/ticket` — A ticket
**learn:** One report from filing to resolution: what was said, where it routed, and every status change since — a living thread you can add to.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Read the routing header | The number, status, severity, and category, plus who reported it, where it routed, and the page it happened on. When a report isn't tech-support business the ticket says so plainly — abuse sits with the moderation and legal team, and a translation issue lives in that language's review queue, where readers of the language verify the fix. | — |
| 2 | Follow the status and history thread | Every event carries who acted, when, and what changed — new, triaged, in progress, resolved. The routing happens on the record, not behind a desk. | — |
| 3 | Add what you know | A reply with more detail or a way to reproduce the problem can be the difference between "can't reproduce" and fixed. | — |
| 4 | Say "this affects me too" | Your +1 tells the people triaging how widespread the problem is. It is a signal of reach, not a vote — nothing here is decided by popularity. | — |

---

### 87. `learn/home` — Learn
**learn:** Every tool comes with a short lesson: a video in your language, the standard procedure, a quick check, and a door straight into the live activity. All of it is open to every user — and none of it is ever a requirement for anything.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Start anywhere | Six tracks run from getting started to running a node, and every lesson stands on its own. There is no prerequisite, no enrollment, and no role you must hold or seek — every training is open to you. | — |
| 2 | Watch your progress build | The bar counts lessons finished. It is for you — finishing lessons grants no vote, no seat, and no capability you didn't already have. | — |
| 3 | Finish a training — it counts once | Finishing files through the constitutional engine like any other civic act and pays the one-time training stipend. Once means once: the achievement ledger remembers each training you've completed, so a retake never pays twice. | F-EDU-001 |
| 4 | Go deeper, freely | The Coalition's free courses cover the principles, the voting math, and the full constitution. Optional, always. | — |

> **The why:** Learning is never a gate. Your right to vote and to stand for office comes from residency alone (Art. I) — no training, test, or certificate can ever be placed in front of it. These lessons exist to make you good at power, not to decide who gets it.

---

### 88. `learn/lesson` — A lesson
**learn:** One lesson is a short video, the standard procedure it teaches, a quick check, and a "now do it live" door into the real activity. The check teaches — it never judges, and it never remembers.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Watch, then read the procedure | The film shows the task in your language; the standard procedure beneath it lists the numbered steps with the rule behind each one. | — |
| 2 | Take the quick check | Pick an answer and read the explanation either way. Your answers are never recorded — not which one you chose, not how many tries it took — and the answer key stays on the server, never sent to your device. Retake as often as you like. | — |
| 3 | Finish — and completion files | Finishing files a training completion through the constitutional engine, recording one fact only: that you completed. That same filing pays the one-time training stipend — once ever per training, remembered by the achievement ledger. | F-EDU-001 |
| 4 | Now do it live | The door at the bottom drops you into the real journey the lesson taught. That is the point — practice ends here, participation starts there. | — |

> **The why:** What files is completion, never answers — the record exists to pay you once and mark the achievement, not to grade you. And completing grants nothing beyond that: no vote, no seat, no standing you didn't already hold from residency alone (Art. I).

---

### 89. `learn/guides` — Guides & procedures
**learn:** Every how-to on one searchable page — the standard procedure for everything you can do, grouped by the part of the app it belongs to. The manual lives inside the app, not somewhere you have to go find.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Search for the task | Type what you are trying to do — matching procedures open in place, and everything else steps aside. | — |
| 2 | Expand a procedure | Numbered steps, the rule behind each step, the film that shows it, and a way to report a problem right from the panel. Procedures for node operators are labeled, so you always know which ones are about running the system rather than governing with it. | — |
| 3 | Hit a dead end? Say so | If nothing matches, the empty state hands you the report form — a missing guide is a ticket, and filing it is how the next person finds the answer. | — |

---

## Wave 10 — groups + social

### 90. `civic/rooms` — Messages
**learn:** Your direct and group messages live here — private conversations for talk, files, voice, and video. This is the private half of your civic life: only the people in a conversation can read it.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Open a conversation from the inbox | A conversation is gated by membership and nothing else — no office, no role, no residency lets anyone else in. If you are not in it, you never see it, and there is no directory of rooms to browse. | Art. I |
| 2 | Start a new one | One person makes a direct message; a few make a group message. Either way it is just people talking — nothing is filed, and nothing enters the public record. | — |
| 3 | Say it however it needs saying | Text, files, voice, and video share one toolkit, the same for two people or ten. A live group can open a voice and video room in one tap. | — |
| 4 | Know which half you are standing in | A post in the public square is on the record and open to anyone signed in. A message here is private like a ballot. The same Article that keeps the square open keeps this room closed. | F-SOC-001 · Art. I |

---

### 91. `groups/create` — New message
**learn:** Start a conversation — one person for a direct message, a few for a group. There is no registration and no filing: a group here is informal by design.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Add the people it is with | You choose who is in — that choice is the only membership rule there is. Nobody approves the group, and nobody outside it can read it. | Art. I |
| 2 | Name it if you want to | The name is for the people in it. It appears in no register and no public list — an unnamed group is exactly as real as a named one. | — |
| 3 | Start the conversation | Nothing files anywhere when you press start. The absence of a form is the point: your private associations are not the government's to record. | — |
| 4 | Let it outgrow itself — if it ever wants to | A group that finds a lasting purpose can register as an organization and gain a public home, durable membership, and elections of its own. That step is always the group's choice, never a requirement. | F-IND-012 · Art. III |

---

### 92. `groups/detail` — A conversation
**learn:** One conversation: the messages, the people in it, and a live voice and video room one tap away. The group decides its own visibility — nobody outside it can look in.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Talk, and attach what the talk needs | Messages, files, voice, and video all live in the one thread. None of it is testimony and none of it is on the record — this is conversation, not governance. | — |
| 2 | Step into the live room | A group conversation carries its own voice and video room. It is the group's room: joining needs an invitation to the group, nothing more and nothing less. | — |
| 3 | Add people, or leave — freely, at any time | Anyone in the group can bring someone in, and anyone can walk out. No exit process, no approval. When the last person leaves, the conversation is gone. | Art. I |
| 4 | Make it a standing organization — only if it wants one | The group can register as an organization to gain a public page and lasting membership. Registered or not, it holds no governance power; until it registers it appears on no public surface — both by design. | F-IND-012 |

---

### 93. `social/profile` — Profile
**learn:** Every person — you, a neighbour, a candidate, the holder of your seat — is shown by this same tabbed profile. Which tabs are public is decided by the constitution, not by rank: there is no separate kind of profile for officials.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Choose how you appear | A handle is the default; showing your legal name is a choice you make, and either way you hold exactly the same rights. A pseudonym is a first-class way to live a civic life. | F-IND-002 · Art. I |
| 2 | Read the public record — anyone's | The Record tab is the audited civic history: residency, participation, offices, filings. It shows *that* a person voted, never *how* — and it can only ever be added to, never quietly edited. | Art. II |
| 3 | Meet candidacies and offices as tabs, not identities | A person standing for election gains a Candidacy tab; a person holding a seat gains an Office tab. Those tabs are public because seeking and holding public power is public — the rest of their life is not. | F-IND-011 · Art. II |
| 4 | Follow, message, reach | Following someone, messaging them, or reaching a representative never costs anything and never requires joining anything. Endorsements you see here are public only because each endorser chose to say so. | — |

> **The why:** The public/private line on this page is drawn by the constitution, not by the person's importance. Candidacy statements are public and every edit lands on the record; office records and every act taken in office are public and uneditable; groups, wallet, and whom you follow stay private. And the public parts inform — an endorsement, however grand, never gates a single thing.

---

### 94. `social/achievements` — Achievements
**learn:** Your earned records — civic firsts and finished journeys — kept as a list, never a score. Nothing on this page grants anything: no vote, no seat, no role, no eligibility, ever.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Earn by doing, not by claiming | Verified achievements are written by the system when the permanent record shows the act — a filing, a seating, a confirmation. Achievements about voting read only the sealed envelope that proves you took part; the ballot inside is never opened. | Art. II |
| 2 | Read the tier label on each one | Verified entries are proven by the server's own records. Walkthrough entries come from guided journeys and are self-reported ticks — they are labeled as walkthroughs precisely so they cannot borrow the verified tier's credibility. | — |
| 3 | Decide what to show | Your achievements are private until you choose to show them, and your progress meters are worked out from your own activity when you look — never kept as a stored score. Shown or hidden, nothing changes. | — |
| 4 | Take the one-time completion bonus when it applies | Finishing a training pays a stipend bonus exactly once. The "once" is enforced right here: this ledger can only ever gain an entry, so an achievement is earned once — and the bonus that reads it can never pay twice. | — |

> **The why:** The service that writes this page refuses to count. It returns *which* achievements you hold, never *how many* — a completion percentage is a per-person score wearing a different hat. Jurisdictions may celebrate milestones together; people are never ranked against each other. And no gate anywhere consults this list: standing for office needs residency, and nothing else.

---

## Wave 11 — jurisdiction lifecycle

### 95. `jurisdictions/bootstrap` — How a place wakes up
**learn:** Every place on the map starts dormant — its boundary already loaded, waiting for residents. Governing begins wherever enough verified people actually live: a county can wake before its state, and the first election fires itself the moment the threshold is crossed.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Watch the resident count climb | The count is **verified residencies**, not raw signups — every confirmation the system files adds one to the live census, and the threshold itself is amendable per jurisdiction tier. | F-IND-006 · WF-JUR-01 |
| 2 | Cross the threshold — the first election schedules itself | Until a legislature exists, the system itself acts as the election board using the constitution's defaults: the standard proportional ranked vote, 5-year terms, districts of 5–9 seats. Nobody has to ask for this election; it is triggered, not requested. | F-ELB-001 · Art. II §2 |
| 3 | Follow the 30 steps through their seven stages | Steps run automatically where the constitution allows; elected humans take over from stage 4 — the legislature constitutes, then the executive, then the judiciary, until full governance is achieved. | WF-JUR-01 · WF-ELE-02 |
| 4 | Watch the temporary board retire itself | Step 14 creates the proper independent election board and the system-as-board steps down. Bootstrap scaffolding never outlives its purpose. | R-08 |

---

### 96. `jurisdictions/union-formation` — Union formation
**learn:** Two or more independent places merge into a union only by their own consent — check where their rules differ, agree one shared value for each difference, then ratify on both sides. There is no live case today (Earth starts united), but the process stands ready for any future independent jurisdictions.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Check the differences | A side-by-side compares each party's settings and institutions. The locked-in mechanics — the voting method, the 5–9 seat range for districts, the two-thirds rule — are identical everywhere by design, so only the amendable values need reconciling. | — |
| 2 | Agree one shared value for each difference | The founding act records one union value per divergence. Constituents keep their own values for their own scope where the power is joint — joining a union does not flatten local law. | F-LEG-029 · Art. V §7 |
| 3 | Ratify on both meters | Union formation needs a two-thirds supermajority of individuals in **each** applicant population *and* a supermajority of the union's constituent jurisdictions (a founding union has none yet; that meter binds from the first join onward). Denominators are whole populations — never just those who vote. | F-LEG-029 · Art. V §7 |
| 4 | Know the door swings both ways | Leaving runs the same process with the same supermajorities as joining. There are no one-way doors. | Art. V §7 |

> **The why:** The union is born self-governing with a legislature that seats both kinds of members — seats by population and equal seats per constituent — and **both kinds must independently agree** for any act to pass. A small partner joins knowing it can never simply be outvoted out of existence.

---

### 97. `jurisdictions/disintermediation` — Removing a middle layer
**learn:** A middle layer of government — a state sitting between its counties and the country — can be dissolved so the places inside it answer directly to the level above. Nobody imposes this from outside: every constituent must agree, and the level above must consent.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Open the dissolution vote | A legislature adopts the disintermediation vote, which opens a consent process across every constituent chamber and the encompassing legislature. | F-LEG-030 · Art. V §8 |
| 2 | Reach unanimity — every constituent, plus the level above | Unanimity, not a two-thirds vote: each constituent passes its own act, and **one holdout stops the dissolution**. The level above must also consent. | Art. V §8 |
| 3 | Record the merge plan | The dissolved layer's acts do not vanish — they fold into each former constituent's own law with full version history preserved. Conflicts with existing local law are surfaced and a resolution recorded before anything takes effect, and open legal challenges travel with the merged law. | F-LEG-030 |
| 4 | Let the chain update itself | When dissolution takes effect, each former constituent answers directly to the level above, and every resident's chain of places re-resolves automatically. | — |

> **The why:** The constituents *inherit* the removed layer's rules — and from that moment the rules are theirs. Each place can amend or repeal them like any law it passed itself. Dissolving a government never dissolves the law people were living under; it hands the pen to the people closest to it.

---

### 98. `jurisdictions/restoration` — Rebuilding a lost government
**learn:** If a government is overthrown, captured, or destroyed, the constitution does not pause — rebuilding cascades through three tiers until a legitimate government stands again. This page is the standing drill, dormant until evidence says otherwise.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Know the three trigger conditions | Countermanded, captured or disabled, or destroyed. Detection is evidence-based and confirmed by a judicial finding — no government can ever flip this switch unilaterally. | Art. VI §2 |
| 2 | Follow the cascade | Tier 1: the constituents jointly elect a new legislature. Tier 2: the level above calls the elections for them. Tier 3: individuals self-organize new jurisdictions from a dormant boundary. Each tier activates only when the one above it cannot act. | Art. VI §3 · WF-JUR-07 |
| 3 | Trust the machinery | Rebuilding elections reuse the first-election machinery — run by the system with the constitution's defaults, exactly like a place waking up for the first time. | WF-ELE-02 |
| 4 | If rivals claim the government, legitimacy is scored | Fewest people governed without consent, no constituency privileged over another, actual ability to govern. Defensive forces are bound to the **most legitimate** claimant — not the incumbent, not the strongest. | Art. VI |

> **The why:** Whatever still functions — elections, sessions, courts — cannot be disrupted while restoration runs, the same hard guarantee that bounds emergency powers. And restoration doubles as a founding path: standing a world up from surviving records is the same act as rebuilding a lost government.

---

### 99. `jurisdictions/federation` — Between governments
**learn:** When two self-governing neighbors disagree about where their shared edge sits, the people who live inside the moving boundary decide — not the legislatures around them. This screen is your window on what happens between governments; the server plumbing behind them lives in the operator area.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Follow a boundary change through its three steps | The proposal is drafted and deliberated in the open, everyone inside the moving boundary votes in a referendum, then the map updates as a new versioned boundary — history preserved. | Art. V §2 |
| 2 | Check the denominator | Passing takes two-thirds of the **whole affected population** — never just those who happen to vote, and never either whole jurisdiction. The affected area is the electorate and the denominator. | Art. V §2 |
| 3 | Let your rights follow you | Once ratified, every affected resident's home association is re-checked against the new boundary. Rights re-attach automatically on the new side of the line — nobody has to re-register. | — |
| 4 | Know what travels between servers | Different governments can run on different servers that keep each place's records in sync. Public records and achievements travel across that mesh; your ballot contents and private location pings never do, and your lesson progress stays on your own node and never federates — though a training completion you file is a constitutional act and travels like any other public record. Running a server grants zero governance power — the mesh moves records, never authority. | — |

---

## Wave 12 — operator

### 100. `operator/setup` — Set up your node
**learn:** Five steps take a bare box to a live node on the mesh: claim an operator account, name the instance, pick what the node will do, run its setup, go. **None of it buys a vote, a seat, or any say** — running a node is infrastructure, not power.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Claim your operator account | Your password works on this box only. Other boxes recognise you by possession of a device key — only the public half ever enrols, and no password is ever replayed between boxes. | — |
| 2 | Name the instance | The name is a friendly label; the self-URL is the address peers dial to sync with you. A stable server id is minted on first run and survives any rename. | — |
| 3 | Pick what your node will do | Record Keeper — holding a copy of the public record — is the one role you switch on yourself, one click, no approval. Once a mesh or a seated government exists, everything else this wizard can only **request**: approval comes from the operator board while no legislature is seated, from the seated government itself the moment one exists — and peers whose own area is touched must consent too. | — |
| 4 | Know what this buys you: nothing | Operator accounts have no link to citizen accounts, and nothing you enable here changes what any citizen can do. Voting and candidacy flow from residency alone — a server room grants neither. | Art. I |

> **The why:** the one-click role is the one that gives you no power over anyone — keeping a copy of a record every peer already holds. Every capability that touches other people (serving them, naming them, hosting their rooms) must be granted from outside the box. Once a mesh or a seated government exists, the machine's owner cannot self-grant.

---

### 101. `operator/dns` — DNS & certificates
**learn:** The Identity Broker gives peers their names and the certificates that prove those names — how a node proves it is itself. It is the heaviest thing an operator can do, because naming a peer touches that peer's own corner of the mesh.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Register a domain and set its write token once | The token that lets the broker write name records is stored encrypted and write-only — set once, never shown again, never logged, and leaves this box only as a sealed copy for a failover broker you designate. The broker uses it; nothing ever reads it back. | — |
| 2 | Write the name first, the certificate second | The broker points the name at your address **before** it asks the free certificate authority (Let's Encrypt) for the proof, with a budget check in between — the authority allows only so many attempts a week (50 per domain, 5 per exact name), so a refusal arrives with a fix and costs nothing. | — |
| 3 | Stay per-name | One certificate for exactly one name is the default and the only ungated path today. Wildcard certificates and the non-Cloudflare automated providers are **not built yet**; the Manual provider always works — it hands you the exact record to set by hand. | — |
| 4 | Note when a peer must say yes | Writing names under a domain, or minting grants, can act inside another peer's area. Those channels wait for the affected peers' own consent — on top of any approval vote. Naming is a capability, never a rank: it buys no vote, seat, or say. | — |

---

### 102. `operator/moderation` — Moderation & the legal floor
**learn:** You host the rooms; you never judge the speech. This screen shows the only four constitutional reasons a post can ever be removed — plus the separate real-world legal floor, who may act on each — and how that authority moves to a judge, automatically, the moment a government exists.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Learn the only four reasons | A judicial order, protecting another person's rights, your own personal block, and content-neutral anti-spam. There is no fifth carve-out — a "remove for content" control has **no code path**, so no one can be pressured into using one. Every server-side removal is logged; your personal block is not a removal at all — it filters only your own screen and is never recorded. | F-SOC-003 · Art. I |
| 2 | Relay while there is no government | Before any legislature is seated, the operator board relays a rights-protection removal — neutral, logged, and marked so it can never be mistaken for a judge's ruling. The board relays; it never rules. | — |
| 3 | Let the flip happen on its own | The moment a legislature seats itself, only a seated judge may order a judicial or rights-protection removal, and the operator relay stops being honored. Nobody flips a switch — the change is a pure function of the facts. | F-SOC-003 · R-19; R-20 |
| 4 | Apply the legal floor when real-world law compels it | Already-posted illegal material — a known illegal-image match, a specific court order, a true threat — is removed under the law of the country the server physically sits in. The list is closed and grows only by code release; each act is per item, never a standing filter; the sealed evidence trail is append-only. | F-SOC-004 |

> **The why:** an operator could always pull the plug — that is physics. So the code gives the operator no verb for censorship: the removal form exists only for the two office-gated reasons — your block and anti-spam never touch a form at all, every use lands in the permanent record, and the moment a real government exists even the relay authority evaporates. The guarantee is not the operator's virtue — it is the absence of the control.

---

## Wave 13 — shared & misc

### 103. `shared/launchpad` — The launchpad
**learn:** The front door of the whole world — four ways in, eight kinds of live room, and a directory of journeys that teach by doing. Nothing at this door checks who you are; every path on this page is open to whoever walks up.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Pick a door | The four doors are the real arrivals: a friend's invite, what's happening today where you live, the living Atlas, or straight into a live room. None of them asks for an account first. | — |
| 2 | Step into a live room | Eight kinds of meeting share one familiar door — committee, chamber, an executive's table, courtroom, board, forum, town hall, a circle of friends. Public bodies are always open to watch; a private group decides its own visibility. | Art. II §2 |
| 3 | Open a journey | Each journey walks you through one real thing this world does — what's happening now, what's your part, what happens next — passing through real rooms along the way. Finish one and you earn an achievement you can show on your profile; it grants nothing else — no vote, no seat, no capability. | — |
| 4 | See how it fits together | Underneath, every journey is one of five kinds of interaction. That map is for later, once you've been here a while — it is never a prerequisite for anything. | — |

---

### 104. `shared/atlas` — The Atlas
**learn:** The Atlas is the public heartbeat of the whole game — one breathing map plus the vital signs of representation, justice, the economy, and the servers carrying it all. Everything on it is an aggregate; it never shows a person who has not chosen to appear.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Read the living map | Nodes, places, organizations, and opt-in residents — at city-level, approximate positions, with layers you can switch off. This is orientation, not surveillance. | — |
| 2 | Put yourself on the map — or don't | Appearing is strictly opt-in: one approximate dot, snapped to a grid, no name attached, removable. Your residency pings are a different thing entirely — private, never published, and never on this map. | — |
| 3 | Read the vital signs | Seats filled and open, elections and candidates, cases and juries, stipends and market volume — every number comes off the engine, aggregated. Reach is a display-only transparency gauge: never a governance input, never a per-person score. | — |
| 4 | Answer a call to action | Open seats and open elections list what needs people right now. Anyone resident may stand — the Atlas shows you the door and adds no gate in front of it. | F-IND-011 · Art. I |

> **The why:** The map draws the line the whole system keeps: participation is celebrated in aggregate, but no individual is ever ranked, scored, or located. A jurisdiction may have a leaderboard; a person never does.

---

### 105. `shared/tour` — The guided tour
**learn:** The tour is a mode, not a place — one linear path through every screen, with a Back / Next bar riding on top of the real thing. Enter anywhere, leave any time; walking it changes nothing about your rights or your standing.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Take the ten-stop first visit | The short track follows the way a real arrival goes: a friend's invite, a live room, home, the living world, your first ballot. About ten minutes. | — |
| 2 | Or jump in anywhere | Every stop is the real screen, fully usable — not a slideshow copy of it. The tour just adds Back / Next on top, so you can wander off inside a stop and still find your way. | — |
| 3 | Leave whenever you like | Use the normal menu at any moment and the tour simply falls away; come back to this index for the full list. Finishing the tour grants nothing — no role, no vote, no capability — and skipping it costs nothing either. | — |

---

### 106. `civic/live-room` — The Live Civic Room
**learn:** Every meeting in this world — committee, chamber, an executive's table, courtroom, board, forum, town hall, or a circle of friends — is one room you can walk into. For public bodies the gallery is always open, resident or not; private groups set their own door.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Watch from the gallery | Sessions, committees, courts, and referendum town halls are public to watch for anyone — you never need residency just to see government work. Organization and group rooms decide their own visibility; that freedom is theirs. | Art. II §2 |
| 2 | Follow the agenda | In a governed body the first two slots are locked by constitutional order — outstanding emergency powers first, constitutional matters second — and the chair orders the rest. A group of friends has no such lock; its own rules of order apply. | F-SPK-002 |
| 3 | Speak — and seal it if you mean it | Residents raise a hand for the floor; the chair recognizes speakers and runs the clock. Chat runs under your pseudonymous handle, never a legal name — and in the halls you may file your own message as testimony, sealed to the permanent public record. | F-SOC-002 · Art. I |
| 4 | Watch the vote count itself | When the chair calls the question, both bars count every serving seat — quorum is a majority of ALL serving, so an absent member counts the same as a no and the bar never shrinks when people stay home. The page never does its own math; the numbers come from the counting engine. | F-LEG-004 · Art. II §2 |

> **The why:** This room cannot be censored. No operator, legislator, or judge may remove a post for its viewpoint — the only removals are four narrow carve-outs (a judicial order, protecting another's rights, your own block, content-neutral anti-spam), and every removal is itself a public record.

---

### 107. `legislature/bill-conversation` — A bill as a conversation
**learn:** A bill here is a conversation you can open while it moves, not a document you find afterward — its progress, its clauses under negotiation, and the public talk around it, all on one page.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Check the progress rail | Drafted → committee → floor reading → floor vote → law. The stages update as the bill moves, and one click puts you in the gallery of the floor session deciding it. | F-LEG-003 |
| 2 | Read the clauses and the redlines | Proposed changes appear as redlines against specific sections, each with its rationale, so what would change — and why — stays legible to anyone. Comments and redlines are public talk on the bill, kept separate from the formal record that holds the lifecycle and the vote math. | — |
| 3 | Say your piece — twice if you mean it | The comment thread is open talk. To put your words on the formal record, file them as testimony in the hall where the bill is heard — sealed permanently under your handle, never your legal name. | F-SOC-002 |
| 4 | Know the challenge path | Once a bill becomes law, any resident may challenge it as unconstitutional. If the judges agree, the remedy can edit the law's text directly — with the full history of every version preserved. | F-IND-016 · Art. IV §5 |

---

### 108. `shared/constitutional-questions` — Constitutional questions
**learn:** Building a constitution into working software forces decisions the text never made — and this ledger keeps every one of them on the record: the question, the answer the build chose, and where to watch that answer working.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Read an entry | Each names the constitutional text it touches, what the attempt to build it surfaced, and the implemented answer in one paragraph — with links to the screens where the answer is visible, so you can judge it in action. | WF-SYS-05 |
| 2 | Follow an "as implemented" marker | Every such marker across the app resolves to its anchor here. An interpretive choice is never silent — you can always trace a behavior back to the question that produced it. | — |
| 3 | Watch the ledger grow — never shrink | Entries are appended as new questions surface; existing ones are only ever extended, never rewritten. Changing the running system itself goes through the amendment doors — this page is the honest margin note beside them, and it seeds the next draft of the constitution. | Art. VII |

> **The why:** Software that implements a constitution holds interpretive power, and the honest response is to write every use of it down in public. The ledger is that confession — permanent, appended-only, and addressed to the people who will redraft the text.

---

### 109. `shared/accessibility` — Accessibility statement
**learn:** Representation for every one of us means an interface every one of us can operate — across ability, language, script direction, device, and connection. This statement records what is built in, what is honestly unfinished, and where to report the difference.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Hold the interface to the contract | Keyboard for every interaction, visible focus everywhere, AA contrast, reflow to small screens and 400% zoom, reduced-motion support, right-to-left mirroring. If a screen falls short of this list, that is a defect — not a preference. | — |
| 2 | Read the known limitations | What is not done yet is listed rather than hidden — so you know the difference between a gap that is scheduled and one that nobody has noticed. | — |
| 3 | Report a barrier | Accessibility is one of the six report subjects, and a report routes itself to the people who fix design defects. You need no status, role, or account standing to file one. | — |

> **The why:** Voting and candidacy require nothing beyond living in the place you vote in. An interface a person cannot operate would quietly add a requirement the constitution forbids — which is why an accessibility defect here is a constitutional defect, not polish. | Art. I

---

### 110. `invite/landing` — You're invited
**learn:** This is how most people arrive — a friend's link. An invite is a doorway and nothing more: it shows you honestly where it leads, asks at most what to call you, and grants no power to anyone on either side of it.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Open the link | You see a truthful preview of the destination — the real room, who's inside, whether it's live now. An expired or invalid link still opens the front door; it never dead-ends you. | — |
| 2 | Step in and watch | Public rooms are open to watch without an account — no email, no ID, nothing to fill in. When you want a voice — to speak on the record, vote, or stand for office — you create an account and say where you live. Living somewhere is the only requirement there is. | Art. I |
| 3 | Land where you were headed | If you do sign in, the app remembers the destination and carries you there afterward. The link's secret is proven once at the door and never stored. | — |

> **The why:** An invite carries attribution, never privilege. Nobody gains a vote, a seat, or an advantage by inviting you — the growth of this world is deliberately disconnected from power inside it, so bringing friends can never become a way to accumulate anything but company.

---

### 111. `system/translation-review` — Verify translations
**learn:** One language, one page: how much of the world reads in it, what the machine has drafted, and what its own readers have verified. Machine and human work are never confused — a draft stays marked as a draft until people who read the language agree it is right.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Pick a content type | Interface text, page copy, guide audio, captions — each shows its own progress bar and verifier count for this language, so you can put your effort where it moves the most. | WF-SYS-03 |
| 2 | Review a string | Each one shows the English source beside the machine's first draft, worst-off first — including strings the machine itself refused to ship. Accept a good draft or suggest a better wording. | — |
| 3 | Verify to publish | A string becomes community-verified only when enough readers of the language agree. Verification is gated to the people who actually read it — never a central team, and never the machine grading itself. | — |
| 4 | Leave the ID tokens alone | Form, role, clock, and workflow codes (F-…, R-…, CLK-…, WF-…) are never translated. They stay identical in every language, so a citation means exactly the same thing everywhere on Earth. | — |

---
