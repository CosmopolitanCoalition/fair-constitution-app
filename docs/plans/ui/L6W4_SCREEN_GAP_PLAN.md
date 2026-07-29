# L6W4 — screen gap analysis & build plan

*Lane 6 (UI Design + A11y), Wave 4, 2026-07-29. Produced by a 13-agent read-only
sweep (one agent per screen: read the v3 mockup, the live Vue page, the controller
that supplies its props, and the tables/services behind each gap), then synthesised
into batches. Every gap is classified frontend-only / controller-prop /
new-service-method / needs-schema / honest-empty / deliberate-drop, and names the
actual table or service that holds the data.*

**Standing constraints baked into the sweep:** zero migrations (the single slot is
held by lane 1 this wave), and **never fabricate data to fill a panel** — a
genuinely deferred capability gets a truthful empty state with explicit copy.

## How to read this — provenance and its limits

This is an INPUT, not a verdict. It is machine-produced and has been spot-checked,
not wholesale verified. Two of its findings I confirmed directly against the code,
and **both corrected my own earlier reading**, which is the reason the file is worth
keeping:

- **`shared/bill`** — the mockup's per-party Accept/Reject on clauses is
  unconstitutional for a bill, and the code already refuses it:
  `RedlineService::acceptForAgreement` throws
  `ConstitutionalViolation('A bill amendment is adopted by a vote of the chamber,
  not accepted by a party.', 'Art. V §3')`. The real path is `motions`
  `kind='amendment'` + a chamber vote. I had wrongly concluded the redline
  party-accept path was reusable for bills. **Build the constitutional path, not
  the mockup.**
- **Bill comments need no schema.** 8 bill-bound `social_subforums` already exist
  (`SubforumReconciler` auto-binds one per live bill). I had wrongly concluded this
  needed a new table.

Conversely, treat unverified claims here as leads. Anything that contradicts a
settled ruling is a flag to raise, never a reason to reverse one.

---

# LANE 6 — WAVE 4 BUILD PLAN
**Constraint honoured: zero migrations, zero fabricated data. Every "missing" panel either wires to a verified live column or ships truthful copy.**

---

## 1. ORDERED BATCHES

Ordered by green-per-effort descending. Each batch = one atomic commit. The grouping rule enforced: **no shared (other-lane) file appears in two batches.**

### BATCH 1 — Registry & copy truth (S) — `2 screens, 5 files, no backend`
**Screens:** `journeys/journey` (frontend half), `civic/advocate-registration` (discoverability only)

| File | Change |
|---|---|
`resources/js/registry/journeys.js` | add 14th arc `become-a-resident` (transcribe `config/cga/journeys.php:38-49`); flip `budget` / `mutual-aid` / `stipend-and-tax` to `status:'live'`, drop their dead `phase`; add `formalMoments[]` (id-free routes only) and `track` keys for the 5 arcs with real modules
`resources/js/Pages/Civic/Journey.vue` | render the `.form-chip` "formal moment" cluster + "Understand it first" link card; kill the two stale claims (Phase 6 live-rooms, "Phase 8" stipend bonus); bill → `/legislature/bills` link
`resources/js/Pages/Legislature/CommitteeDetail.vue` | one-line "Open the hearing room" link to `/rooms/committee/{meeting.id}` using the `meeting` prop it already holds
`resources/js/registry/surfaces.js` | drop `roles:['R-21']` on `courts → advocate-console`; relabel "Advocates — register & file"
`resources/js/Navigation/nav.js` | drop `enabledRoles:['R-21']` / `prereq:'R-21'` on the same entry

**Why grouped:** every file is lane-6-owned registry/page code. No controller, no service, no CSS, no other lane. This batch alone converts the two worst honesty defects in the fleet (an arc rendering its own id as a label; a registration form gated behind the role it grants).

### BATCH 2 — Messaging trio (M) — `3 screens`
**Screens:** `groups/groups-home`, `groups/group-create`, `groups/group-detail`

| File | Change |
|---|---|
`app/Services/Social/PrivateRoomService.php` **(shared)** | new `latestPreviews(iterable $spaces): array` — one `whereIn` over `matrix_rooms`, then per-room `getMessages(limit=1)` in its own try/catch, `Cache::remember("room-preview:{$roomId}", 15)`, capped at 20 rooms
`app/Http/Controllers/Civic/PrivateRoomController.php` **(shared)** | `index()`: `rooms[].lastMessage{body,at,sender,fromYou}` + `rooms[].channel` + order by `lastMessage.at desc`. `mapMessages()`: **one line** — `'msgtype' => (string)($content['msgtype'] ?? 'm.text')`
`resources/js/Pages/Civic/PrivateRooms.vue` | rebuild the list as `.msg-inbox`/`.conv-row` (CSS already at `resources/css/cga/components-v2.css:1023-1048`); device-local "New" dot from `localStorage['cga.room.seen.<spaceId>']`; size-truthful sub-line; org-escalation link; honest leave-semantics copy
`resources/js/Pages/Civic/PrivateRoom.vue` | adopt the shipped bubble chrome `.msg-thread`/`.msg-bubble--mine`/`.msg-when` (`components-v2.css:1023-1078`, currently zero consumers); render `.msg-attach` for non-`m.text`; derived DM-vs-group header; toolkit row with File disabled; "Start a standing organization" CTA
`resources/js/Components/Ui/Icon.vue` | add the one missing lucide glyph for voice
`resources/js/Pages/Organizations/Registry.vue` | `id="register"` anchor on the register Card
`app/Console/Commands/RoomsDemoCommand.php` **(new)** | `rooms:demo` under `App\Console\Concerns\GuardsSyntheticData` — 1 DM + 1 four-person group via `PrivateRoomService`, real messages via `MatrixPostingGateService::postToPrivateRoom`, `--offline` degrade. Doubles as the missing parity CLI
`app/Console/Commands/RoomsCreateCommand.php` **(new)** | `rooms:create {user} {name}` → `PrivateRoomService::create`
`docs/plans/ui/UI_CLI_PARITY_INVENTORY.md` | add the two `/civic/rooms` rows; correct line 171 (`PrivateRoomService::create` never enters the ConstitutionalEngine, so `engine:file` does not cover it)

**Why grouped:** `PrivateRooms.vue`, `PrivateRoom.vue` and `PrivateRoomController` are each wanted by two of the three screens. Splitting them guarantees a merge conflict on a hostile index. The whole batch is one Matrix read + already-ported CSS.

### BATCH 3 — Onboarding pair (M) — `2 screens`
**Screens:** `civic/identity-verification`, `civic/relocation`

| File | Change |
|---|---|
`resources/js/Components/Ui/StateStrip.vue` | additive optional `labels:{type:Object,default:null}`, passed to `plainState(state, labels)` (`resources/js/lib/plain.js:34` already accepts a map). `default:null` keeps every existing consumer byte-identical
`resources/js/Components/Ui/Stepper.vue` | additive optional `href` per step → renders an Inertia `<Link class="stepper-step">`; CSS for `a.stepper-step` already at `components.css:832`
`config/cga/state_machines.php` **(shared)** | new `individual_onboarding` key: `registered, identity_verified, residency_declared, jurisdictionally_associated`
`app/Http/Controllers/Civic/IdentityVerificationController.php` **(shared)** | ship `machine` from the new config key + derived `journeyStatus` (R-03 → associated, else open claim → declared, else `$user->status`)
`app/Http/Controllers/Civic/RelocationController.php` **(shared)** | `homeClaim.jurisdiction.id` + reshape `newClaim.jurisdiction` to `['id','name']` (relations already eager-loaded with id — zero extra queries)
`resources/js/Pages/Civic/IdentityVerification.vue` | 4-node strip with plain labels, 3-item Stepper, `title="Link a government ID (optional)"`, officer-console truth gloss, retitled bridge card
`resources/js/Pages/Civic/Relocation.vue` | plain-label strip, always-visible held-office card, home-vs-new two-boundary Leaflet card (lift the pattern from `resources/js/Pages/Civic/Residency.vue:413-460`), corrected Phase-6 citation, travelling-button gloss branch
`app/Console/Commands/CivicTravellingCommand.php` **(new)** | `civic:travelling {--user=}` — same `AuditService::append` (module `residency`, event `relocation.travelling_declared`, ref `WF-CIV-03`)

**Why grouped:** both screens need the **same** additive `StateStrip.labels` prop. One batch = one commit = one version of that component. Both controllers are additive-only and touched exactly once.

### BATCH 4 — Single-controller prop passes (M) — `3 screens`
**Screens:** `civic/join`, `civic/advocate-registration` (body), `social/org-profile`

| File | Change |
|---|---|
`app/Services/Invites/InviteService.php` **(shared)** | one array entry: `'rooms/committee'` into `PROCEEDING_PREFIXES` (the route is already a public gallery read, so this grants nothing new)
`app/Http/Controllers/Invites/InviteController.php` **(shared)** | `preview()`: `place` (jurisdiction name, both branches), `isArchived` (`SocialSpace::STATUS_ARCHIVED`), `status{state,label}` for whitelisted proceeding paths (`legislatures/{uuid}/session` → `LegislatureSession`, `rooms/committee/{uuid}` → `CommitteeMeeting`) — all inside the existing try/catch
`resources/js/Pages/Invite/Landing.vue` | kind-aware promise + CTA row; `pill--live`+`dotlive` **only** when `status.state==='open'`; `memberCount` reworded to "members"; place fact; archived state; card renders from `kindNoun` when `preview` is null
`app/Http/Controllers/Judiciary/AdvocateController.php` **(shared)** | widen `publicRegistrationJudiciaryId` from `->value('j.id')` to select `j.id, j.court_name, j.type, j.status, ju.name, ju.adm_level` → ship `prerequisites{judiciary,residency}` + `practiceOptions[]` (ordered `ju.adm_level DESC`, excluding already-registered) + `operating` flag
`resources/js/Pages/Judiciary/AdvocateConsole.vue` | Prerequisites Card (3 rows), court-of-practice select bound to the existing `regForm.judiciary_id`, non-blocking qualifications gloss, instant-registration truth line, self-representation line
`app/Http/Controllers/Organizations/OrganizationController.php` **(shared)** | `jobs[]` (`work_postings` where org, `whereNull deleted_at`, open first, limit 25, + applications count), `listings` (null when `AccountService::accountIdFor('organizations',…)` is null), `can.steerEconomy` (agent OR seated `board_seats` — same two clauses as `OrgEconomyController::maySteer`)
`resources/js/Pages/Organizations/OrgDetail.vue` | `.profile-head` + `.profile-stats` (CSS at `components-v2.css:533-537`), Charter card, Job board linking `/economy/requests/{id}`, manage cluster (4 real actions only), friendly type/structure labels, use-or-delete the dead `Stat` import

**Why grouped:** three disjoint controllers, three disjoint Vue pages, one service one-liner. Every shared file is touched exactly once in the whole wave. No new service classes.

### BATCH 5 — Offices/seat extraction + profiles (M-L) — `2 screens`
**Screens:** `civic/my-civic-life`, `social/social-home`

| File | Change |
|---|---|
`app/Services/OfficesHeldResolver.php` **(new, shared layer)** | lift `PersonProfileController::officesFor()` (`:317-382`) verbatim → `forUser(User)`, plus `forUsers(array $ids)` (batch, three `whereIn` queries over `legislature_members` / `executive_members` / `judicial_seats`) and `meetingsFor(User)` (upcoming/open `legislature_sessions` for held seats ∪ `committee_meetings` via `committee_seats.member_id`)
`app/Http/Controllers/Social/PersonProfileController.php` **(lane 15)** | call the resolver instead of the inlined queries; extract the endorsements-given query alongside it
`app/Http/Controllers/Civic/MyRecordController.php` **(shared)** | `offices`, `meetings`, `affiliations{groups,organizations,follows}`, `openVotes` (filter `TodayFeedService::forUser` to election/petition/referendum), `endorsementsGiven` (**without** the `is_public` filter, carrying `is_public` through), `wallet{currency,balance,status,lastStipend}` via `AccountService::accountIdFor` — money as **string**; `stats.ballots_cast` = `DB::table('ballot_envelopes')->where('user_id',$id)->count()`; add `office` + `groups` to `TABS`
`app/Http/Controllers/Civic/HomeController.php` **(shared)** | same `ballot_envelopes` count replacing the hardcoded `0` at `:124`
`resources/js/Pages/Civic/MyRecord.vue` | Office tab (reuse the panel markup from `Social/PersonProfile.vue:587-611`), Groups & orgs tab (2 DataTables), Overview "Open votes" card, endorsements-given grid with Public/Private pills, wallet balance + last stipend, two honest-empty panels
`resources/js/composables/useCountdown.js` **(new)** | one ticking `now` ref, 1000ms interval, `clearInterval` on unmount, **skipped** under `prefers-reduced-motion`; `dayOf` branch (`day {n} of {max}` — max always from payload)
`app/Http/Controllers/Civic/PublicSquareController.php` **(shared, co-owned lane 15)** | `halls[]` (≤4 rooms in `$chainIds`, from `legislature_sessions` + `session_attendance` and `committee_meetings` + `committee_seats` + `LiveFloorService::state()`); per-row `handle` (batched `matrix_identities.matrix_localpart` → `social_profiles.handle` → `'@u-'.hash8`); seat badge via `OfficesHeldResolver::forUsers`; `->with(['subforum.space'])` for `jurisdiction_id`
`resources/js/Pages/Civic/PublicSquare.vue` + `resources/js/Components/Civic/Room/HallsNowStrip.vue` **(new)** | halls-now strip using existing `.presence-*` CSS, refreshed by `useLiveRoom` (`keys:['halls']`); handle-first row identity; derived seat badge; per-thread "talk about this live" → `/civic/commons/square?jurisdiction={id}`; community-standards card built from **the code's** taxonomy
`tests/Feature/MyProfileTabsTest.php` | one case: `?tab=office` no longer falls back to overview

**Why grouped:** `officesFor()` is the single extraction both screens depend on. Extract once, in one commit, with all three consumers updated together — otherwise the public and self profiles drift, which is exactly the defect the extraction prevents. **`acting_seat` is never written** — badges are derived at read time, so a vacated seat drops the badge from historic posts automatically.

### BATCH 6 — Live state + the absent screen (L) — `2 screens`
**Screens:** `civic/today`, `shared/bill` (the one absent screen this wave builds)

| File | Change |
|---|---|
`app/Services/TodayFeedService.php` **(shared)** | `committeeRows()` (copy `sessionRows()`'s join shape), `challengeRows()` (`constitutional_challenges` ⨝ latest `remedy_recommendations` — **no `status` column on that table**), `stipendRows()` (latest `ubi_disbursements` ≤7d, **aggregate only, never `ubi_receipts`**); `STATUS_RANK` extended to `live,open,soon,window,planned,closed`; org host resolution via `elections.board_id → boards.boardable_id → organizations.name`; humanised election kinds; open session href → `/legislatures/{id}/session` (scheduled stays `/chamber` — `tests/Feature/TodayFeedTest.php:100` pins only the scheduled case)
`resources/js/Pages/Civic/Home.vue` | `happening` count (live+open only) drives the title; `useCountdown` from batch 5; `KIND_ICONS` += `committee:'landmark'`, `challenge:'scale'`, `stipend:'refresh-cw'`; host badge off `event.kind`; three honest-empty foot lines
`app/Services/JourneyService.php` **(shared)** | `worldState(User,string): ?array` → `{currentStep,now,next,pill,target,href}` for the 5 unambiguous arcs (election, committee-session, bill, court-case, petition-to-referendum); `null` for the rest
`app/Http/Controllers/Civic/JourneysController.php` **(shared)** | one prop: `world`
`resources/js/Pages/Civic/Journey.vue` | `.live-row` + `.journey-rail` (CSS at `components-v2.css:716-720`, `:86-97`), `useCountdown`, honest line when `world` is null
`routes/web.php` **(shared)** | `GET /bills/{bill}/conversation` → `bills.conversation`, `->whereUuid('bill')->withoutMiddleware('auth')`, beside `:678`
`config/cga/surfaces.php` **(shared)** | `legislature/bill-conversation` entry, forms `F-LEG-007`, `F-SOC-001`, `F-SOC-002`
`app/Http/Controllers/Legislature/BillController.php` **(shared)** | `conversation()` reusing `show()`'s helpers; new props `amendmentMotions[]` (`motions` where `kind='amendment'` ⨝ `chamber_votes` via `vote_id`), `discussion` (bill's `social_subforums` + threads/posts), `can.proposeAmendment`; `firstOrCreate` halls + idempotent `SubforumReconciler::reconcile` when no subforum exists
`app/Http/Controllers/Civic/HallsController.php` **(shared)** | **two lines**: `'subforum_id' => ['nullable','uuid']` in the validator + pass it into the `F-SOC-001` payload. Today **every** object-bound subforum in the app is write-unreachable
`resources/js/Pages/Legislature/BillConversation.vue` **(new)** | clause rail (client-side split of `lawText` on blank lines), `.rl-item` redline list mapping `Motion::STATUS_VOTED/ADOPTED/FAILED` → `.rl-pending/.rl-accepted/.rl-rejected`, propose-amendment composer posting to the **existing** `POST /sessions/{session}/motions`, discussion thread, links to `/legislatures/{id}/session` and `/bills/{id}`
`resources/js/Pages/Legislature/BillDetail.vue`, `resources/js/Pages/Legislature/Bills.vue` | reciprocal "Join the conversation" links

**Why grouped:** the theme is "read a real institution's live status". Files are fully disjoint from batches 1-5 except lane-6-owned `Journey.vue` (batch 1 touched copy/registry; batch 6 adds the strip — sequential commits, same lane, no other owner). `shared/bill` is the only batch that touches `routes/web.php` and `config/cga/surfaces.php`, so those two hostile files are claimed exactly once.

---

## 2. FRONTEND-ONLY WINS (cheapest green — `resources/js` only)

| Screen | File | Win |
|---|---|---|
journeys/journey | `registry/journeys.js` | **arc 14 `become-a-resident` is missing entirely** — first arc a new player walks renders `cls` id "people" as its label; 3 stale `status:'planned'` entries |
journeys/journey | `registry/journeys.js`, `Pages/Civic/Journey.vue` | `formalMoments[]` chips using only id-free routes (`/elections/open-ballot`, `/legislature/bills`, `/judiciary/docket`, `/civic/petitions`, `/organizations`, `/economy/stipend`, `/rooms`) — all have built honest-empty states. **Never hardcode an entity UUID** (`surfaces.js:258` does; do not copy) |
journeys/journey | `Pages/Civic/Journey.vue` | delete two false claims: "live rooms arrive in Phase 6" (the committee room shipped) and "Planned · Phase 8" (no such phase; no journey bonus is ever paid — only `TrainingStipendService` pays, for `F-EDU-001`) |
advocate-registration | `registry/surfaces.js`, `Navigation/nav.js` | remove the `R-21` gate on the door to the form that **grants** `R-21` — a catch-22; contrast `surfaces.js:63` "Stand for office", correctly ungated |
group-detail | `Pages/Civic/PrivateRoom.vue` | adopt `.msg-bubble`/`.msg-when`/`.msg-thread` — lane 6 ported the whole v3 messaging CSS block and **nothing consumes it**; `at` (`origin_server_ts`) is already in props and thrown away |
groups-home | `Pages/Civic/PrivateRooms.vue` | `.conv-row` chrome + device-local "New" dot (a genuine read position, browser-scoped, labelled as such) |
group-create | `Pages/Civic/PrivateRooms.vue` | org-escalation sentence; **replace** the mockup's false "when everyone leaves, it is gone" with "Leaving a room removes you from it; the room itself stays for whoever remains" |
identity-verification | `Ui/StateStrip.vue`, `Ui/Stepper.vue` | two additive optional props (`labels`, `href`) unlock plain-language strips and the 3-step onboarding stepper across three screens |
identity-verification | `Pages/Civic/IdentityVerification.vue` | `title="Link a government ID (optional)"` on `PageScaffold` — do **not** edit `config/cga/surfaces.php:75` (it also feeds nav + About drawer) |
relocation | `Pages/Civic/Relocation.vue` | drop the `v-if` on the held-office card; correct "Phase F" → "Phase 6 (mobile app)" |
civic/today | `Pages/Civic/Home.vue` | header counts live+open only (today it counts scheduled-next-week as "happening now"); countdowns tick instead of freezing at render |
org-profile | `Pages/Organizations/OrgDetail.vue` | `.profile-head`/`.profile-stats` already shipped in CSS and used by two other pages; friendly type/structure labels; delete-or-use the dead `Stat` import |
social-home | `Pages/Civic/PublicSquare.vue` | community-standards card built from `ConstitutionalValidator::checkSocialRemoval`'s **two** grounds + `MatrixCarveoutLog` M-3/M-4 — **not** the mockup's four content categories, which appear nowhere in the code |
bill (reciprocal) | `Pages/Legislature/BillDetail.vue`, `Bills.vue` | "Join the conversation" links, closing the matching S-sized bill-detail gap |

---

## 3. CONTROLLER PROP WIRING (one pass per controller)

**`app/Http/Controllers/Invites/InviteController.php`** — batch 4
- `preview.place` ← `Jurisdiction::query()->find($space->jurisdiction_id)?->name`, or `invite.destination['jurisdiction_id']` for call/commons. Null when unresolvable. Do **not** add a `jurisdiction()` relation to `SocialSpace`.
- `preview.isArchived` ← `$space->status === SocialSpace::STATUS_ARCHIVED`
- `preview.status` ← `{state,label}` for whitelisted paths only: `legislature_sessions.status`, `committee_meetings.status` (`LiveRoomController::statusOf()` already produces this exact shape). Null everywhere else.

**`app/Http/Controllers/Judiciary/AdvocateController.php`** — batch 4
- `prerequisites` ← widened `publicRegistrationJudiciaryId` join (`judiciaries` ⨝ `residency_confirmations` ⨝ `jurisdictions`) + `RoleService::associationsFor($user)`
- `practiceOptions[]` ← same join, `ORDER BY ju.adm_level DESC`, minus already-registered rows
- `operating` ← `in_array(status, Judiciary::OPERATING_STATUSES)`. Keep `registerTargetId` as the pre-selected default.

**`app/Http/Controllers/Organizations/OrganizationController.php`** — batch 4
- `jobs[]` ← `work_postings` (org, `deleted_at` null, open first, limit 25) + `work_applications` count
- `listings` ← null when `AccountService::accountIdFor('organizations',$id,$currencyId)` is null (which is **always**, today), else open `marketplace_listings`
- `can.steerEconomy` ← agent OR seated `board_seats` row (mirror `OrgEconomyController::maySteer:136`)

**`app/Http/Controllers/Civic/IdentityVerificationController.php`** — batch 3
- `machine` ← `config('cga.state_machines.individual_onboarding')`
- `journeyStatus` ← R-03 → `jurisdictionally_associated`; open claim → `residency_declared`; else `$user->status`

**`app/Http/Controllers/Civic/RelocationController.php`** — batch 3
- `homeClaim.jurisdiction.id`, `newClaim.jurisdiction{id,name}` ← already eager-loaded relations. Keep `detection` **null**.

**`app/Http/Controllers/Civic/MyRecordController.php`** — batch 5
- `offices`, `meetings` ← `OfficesHeldResolver`
- `affiliations` ← `social_memberships`⨝`social_spaces` (pattern at `PrivateRoomController::index:38-49`), `org_memberships`/`org_workers`⨝`organizations` (pattern at `RoleService:526-546`), `social_follows` counts
- `openVotes` ← `TodayFeedService::forUser` filtered to `election|petition|referendum`
- `endorsementsGiven` ← the `PersonProfileController::recordFor:432-454` query **minus** the `is_public` filter, carrying `is_public`
- `wallet` ← `AccountService::accountIdFor('users',…)` + `Currency` + `ubi_receipts`. **Money is a string** (`numeric(24,6)`); `"0.000000"` for zero, `[]` for empty
- `stats.ballots_cast` ← `ballot_envelopes` count (never `ballots` — `AchievementCatalog:41` PI-2)

**`app/Http/Controllers/Civic/HomeController.php`** — batch 5: same `ballot_envelopes` count at `:124`. Fix with MyRecord or the two surfaces disagree.

**`app/Http/Controllers/Social/PersonProfileController.php`** — batch 5: refactor-only, calls `OfficesHeldResolver`. **Do not** add the Groups/orgs tab here — org membership is private association under Art. I.

**`app/Http/Controllers/Civic/PublicSquareController.php`** — batch 5
- `halls[]` (≤4, scoped to `$chainIds`), `threads[].handle`, `threads[].seatBadge`, `threads[].jurisdiction_id/name`. Never emit `social_posts.author_user_id`; profile links ride the handle and only when `social_profiles.handle` exists **and** `visibility='public'`.

**`app/Http/Controllers/Civic/PrivateRoomController.php`** — batch 2: `rooms[].lastMessage`, `rooms[].channel`, activity ordering; `mapMessages()` += `msgtype`.

**`app/Http/Controllers/Civic/JourneysController.php`** — batch 6: `world` ← `JourneyService::worldState`.

**`app/Http/Controllers/Legislature/BillController.php`** — batch 6: new `conversation()`; `amendmentMotions[]`, `discussion`, `can.proposeAmendment` (`$viewer !== null && $openSession !== null`).

**`app/Http/Controllers/Civic/HallsController.php`** — batch 6: `subforum_id` through the validator into the `F-SOC-001` payload. `SocialSpaceService::resolveSubforum:96` already honours it.

**`app/Services/TodayFeedService.php`** (service, not controller) — batch 6: `committeeRows`, `challengeRows`, `stipendRows`, org host resolution, `STATUS_RANK`, session href. All additions ride **inside the existing `feed` prop**, so `HomeController` needs no change — which is the argument for doing it this way.

---

## 4. HONEST-EMPTY DECISIONS

Each of these is genuinely unbuilt. Verified absence, then exact copy.

**1. Online presence / "N people inside now"** (`Invite/Landing.vue`, `PublicSquare.vue`, `PrivateRooms.vue`, `PrivateRoom.vue`)
*Not built:* `social_memberships` has no `last_seen_at`; `LiveFloorService` holds only `{floorHolder,queue,speaking}`; `LiveRoomController:257` hard-codes `'online' => false`; `LiveKitTokenService` mints JWTs and nothing else; `MatrixClientService` has **no** joined-members method; `useLiveRoom.js` is a freshness poller that computes nothing.
- Landing: `"1 member"` / `"{n} members"`, then `"Who's in the room right now isn't shown until you're inside."` For call/commons/proceeding invites `memberCount` is null → render **nothing** (never "0 people").
- Public square: pills read `"{n} marked present"` (`session_attendance`) and `"{n} seated"` (`committee_seats`); keep "has the floor" (genuinely live). Footnote: *"This is the roll and the seated roster, not a live connection — who is actually online is not tracked yet. Only the speaker the chair has recognised is live. Being in a room confers no governance power."* Use `.presence-dot--off` for rows with no live signal.
- Inbox: no live pill. `"active 4m ago"` in `.conv-when`, plus *"We can't show who's on a call from here yet — open a conversation to see who's in the room."*
- Room, not connected: *"You'll see who's in the call once you open it — a room can't report that from the outside yet."* Connected: the real pill from `ChamberStage.vue:19-20` (`connectionState` + `participants.length`).

**2. The invite "live" pill demoted** — never emit "Meeting now" from a privacy flag. Public → `pill--info` "Anyone may watch"; private → `pill--info` "Invite only — they saved you a seat". `pill--live`+`dotlive` reserved for real `status.state==='open'`.

**3. Group meetings / resident-hosted events / candidate forums / trade talks** (`Civic/Home.vue`)
*Not built:* `committee_meetings` is the **only** meeting table in the schema; `ScenarioPresetService::unbacked()` records both `groupForming` and `tradeTalk` as unbacked; no forum scheduling column anywhere.
- Calendar foot: *"Only jurisdiction and organization dates show here. Groups and residents cannot put an event on this calendar yet — the app has nowhere to store one."*
- Zero-state: *"Nothing on the calendar yet — scheduled hearings, chamber sessions and election dates appear here the moment they are set."*
- `#about`: *"Two things are deliberately not on this feed. There is no schedule for a candidate forum yet. And a live voice room in the commons runs on the mesh, not in this server's records — open the commons to see who is actually there."*
- Foot: *"Cross-government trade talks are not built — nothing on this server can hold one yet."* (Omit the kind entirely; a permanent "Coming soon" row is dead weight on the screen that must mean *now*.)
- Foot: *"Money here is practice units — the ledger is real, the currency is not."*

**4. Member-hosted office hours / town halls** (`Civic/MyRecord.vue`) — *not built:* no member-hosted-event table.
*"Scheduled office hours and town halls are not built yet — a member cannot yet post an open-door session, so none can be listed. The sessions above are the ones the chamber and its committees actually scheduled, and every one of them is open to watch."*

**5. Constituent requests** (`Civic/MyRecord.vue`) — *not built:* `constituent_consents` is the Art. V consent vote; `support_reports` is app support; `read_write_requests` is federation. No contact queue exists.
*"Constituent requests are not built yet. There is no queue behind this panel, so nothing is being held back or lost. Until it exists, constituents reach you where the record already runs — the halls and the public square, both open to every resident and both permanent."*

**6. Advocate qualification checkboxes** (`AdvocateConsole.vue`) — *not built:* only `advocates.qualifications_note` free text; the mockup itself labels its acts "fictional". Shipping ticks would invent data **and** invent an eligibility gate on a right the engine deliberately does not gate.
*"Qualifications under this jurisdiction's law — The constitution requires advocates to be zealous and competent but leaves the qualification bar to each jurisdiction's own law. This app does not yet carry a structured, per-jurisdiction qualification catalog, so there is nothing to tick off here. Describe your qualifications below in your own words — the note is recorded with your registration. Competence is a property of the bar, never a gate on your client's right to representation (Art. I · Art. IV §4)."*
Plus, replacing the mockup's pending banner: *"Registration takes effect the moment you submit — there is no review queue. The constitution keeps the bar zealous and competent by keeping it open, not by gating who may enter it; a court that doubts your competence answers it in the case, not at the door (Art. I · Art. IV §4)."*
And when the resolved court is not in `OPERATING_STATUSES`: *"The {court} is still forming — you can register now, but it cannot hear a case until its bench is seated."*

**7. Automatic ID checks** (`IdentityVerification.vue`) — *not built:* no bridge table, no per-jurisdiction accepted-ID register; `users.identity_verified_via` exists and **nothing writes it**; `IdentityVerificationSubmission:46-55` strips document fields on purpose.
Retitle "Automatic ID checks — not available in any jurisdiction yet": *"Some jurisdictions will be able to check a government ID automatically… That needs two things that are not built: the federation layer (Phase 6) and a per-jurisdiction register of which IDs each legislature accepts. No jurisdiction on this instance can check an ID automatically today, so nothing is being hidden from you here — the in-person request above is the only path that exists."*
Plus the completion truth (no officer console exists, so **no request can ever complete**): *"What happens when you submit: your request is written to your public record on the audit chain, and nothing else. The officer-side console that would let an administrative officer record a simple 'confirmed' is not built yet, so no request can be completed on this instance today — the filing stands as the record of your asking. Either way your rights are untouched: they come from residency alone."*
And under the strip: *"The optional ID step cannot be completed on this instance yet… Every step after it is reachable now; skipping the ID step costs you nothing."*

**8. Away-detection meter** (`Relocation.vue`) — *not built, and structurally impossible today:* `ResidencyService::recordPing:142-153` throws unless the claim is in `MONITORING_STATUSES`; a settled resident's only claim is `active`; `confirmVerification:337` and `declare:104` purge every ping. `location_pings` holds **zero rows** for this exact population.
*"Nothing is watching your movements yet. Automatic away-detection needs continuous background check-ins from a phone, which arrives with the mobile app. Until then this page never detects a move on its own — if you have moved, say so yourself by declaring your new address on the residency screen. Your current residency stays fully active until the new one verifies."* + *"When detection does arrive it will use the same encrypted check-in log as your home verification — only day-counts are ever visible, and check-ins stay pausable in personal settings."*
Travelling button, when `detection === null`: *"No pattern has been detected, so there is nothing to reset — this records a standing note on your record that you travel and should not be moved. It changes no residency and no association."*
Held-office card when empty: *"You hold no office tied to your home jurisdiction — nothing to hand over"* + the grace-period explanation + *"This covers elected chamber seats. Appointed civil and judicial officers are not seat-footprint-bound the same way, and how a move affects a 10-year appointment is not yet settled."*

**9. Org marketplace listings / org ledger** (`OrgDetail.vue`) — *not built:* the only `AccountService::open()` callers are `TreasuryDemoCommand:120` (`'users'`) and `JointLedgerService:104`. **No organization-owned account is ever opened**, and `MarketplaceListingOrder:78` hard-scopes listing to the actor's own user account.
*"This organization has no wallet of its own, so it has nothing on the Open Market. In this build only people hold wallets and trade (F-IND-022) — organization-side trading is not built yet. Nothing is hidden here; there is nothing to show."*
Manage cluster: *"Posting work and listing on the market are not built for organizations yet — the work board can be read and applied to (F-IND-019), and only people hold market wallets today (F-IND-022)."* Render **no** disabled buttons for the unbuilt actions.
Wallet empty on MyRecord: *"No wallet account yet — one opens the first time value moves to you, which for most people is their first stipend run. Nothing is missing; there is simply nothing to hold yet."*

**10. Message attachments** (`PrivateRoom.vue`) — *not built:* `MatrixPostingGateService:58-65` hardcodes `m.text`; `MatrixClientService` has no upload method; `grep hasFile|UploadedFile` over `app/Http/Controllers` returns **zero hits app-wide**.
Disabled chip title: *"Files aren't carried yet"*. Composer gloss: *"This room carries text, voice, and video. Files and photos aren't built yet — paste a link instead."* Inbound non-text events: *"{body} — attachment, not viewable here yet"*.

**11. Room permanence** (`PrivateRoom.vue`) — the mockup's "when the last person leaves, it is gone" is **false**: `PrivateRoomService::leave:95-105` returns early for the owner and no reaper exists.
*"Anyone in this room can bring someone else in with a link. If you leave, you stop seeing it — the room itself stays until its owner removes it, and removing a room isn't built yet."* Keep the true rail: *"A group message is just people talking. It grants no governance power and it's private to its members — nobody else can read it."* Never render "· temporary".

**12. Topic tag chips** (`PublicSquare.vue`) — *not built:* no tags table, no tag column; every square post lands in the null-object "General Discussion" subforum. Omit the chip row; build the row so the slot renders only when a non-null `thread.topic` arrives (free win on Halls later, where `social_subforums.governing_object_type/_id` **is** populated).

**13. Per-journey SOP + video** (`Journey.vue`) — *not built:* no videos table, no player, no asset (`grep -il video` finds only LiveKit files). For the 9 arcs with no authored track: *"No written guide for this journey has been authored yet — the arc below is the procedure, and the Learn panel in the top bar carries the constitutional why for this screen."* When `world` is null: *"Where the world is in this arc is not computed yet — your own progress below is real and saved."* Stipend bullet: *"A one-time stipend bonus — not wired yet. Completing an arc earns the medal; no journey bonus is paid today."*

**14. Bill plain-words summary** (`BillConversation.vue`) — no `bills.summary` column. Drop the separate line: *"What it says — the bill's own opening section. A bill carries no separate plain-language summary; this is its text."*
Clause rail label: *"The bill as it stands — version {n}, section by section. Per-clause negotiation is stored for agreements; for a bill the section you target is carried in the amendment you move."*
No amendments: *"No amendments have been moved on this bill. An amendment is a motion — a seated member moves it and the chamber votes it up or down."*
No discussion: *"No one has spoken on this bill yet. Anyone may open the discussion — residency is not required to talk, only to vote."*
Instead of party accept/reject: *"On a bill there is no private accept. An amendment is moved as a motion and the chamber votes it up or down — Art. V §3."*

**15. Deliberate drops — leave alone, do not re-open:** the invite page's "what should people call you?" input (the name is asked **once**, on register); the identity-verification branch-preview toggle (mockup demo device); real display names in rooms (pseudonym only, Art. I); unread **counts** and read receipts; the `@handle` people-picker on group-create (no user directory, by design, documented verbatim in `PrivateRoomController.php:82` and `PrivateRooms.vue:6-9`).

---

## 5. BLOCKED / FLAG TO DESK

### Needs-schema (migration slot held — flag, never design around)
| Want | Column/table | Screen |
|---|---|---|
Exact unread count | `social_memberships.last_read_event_ts` (bigint nullable) + `POST /civic/rooms/{space}/read` | groups-home |
Persisted DM-vs-group | `social_spaces.room_kind` CHECK `('dm','group')` — **reject** overloading the unused `slug` | groups-home, group-create, group-detail |
Scheduled events for groups/orgs/residents | new table keyed to jurisdiction / organization / social_space | civic/today |
Candidate forums | forum scheduling table | civic/today |
Member-hosted office hours | `member_hosted_events(host_member_id, kind, scheduled_for, where)` | my-civic-life |
Constituent requests | `constituent_requests(from_user_id, member_id, kind, topic, status)` | my-civic-life |
Advocate review lifecycle | rewrite `advocates_status_check` to admit `pending` — **and an Art. IV rule change** | advocate-registration |
Per-jurisdiction accepted-ID register + bridge results | new tables | identity-verification |
Free-form topic tags | `social_thread_tags` or `social_threads.tags jsonb` | social-home |
Plain-words bill summary | `bills.summary` | shared/bill |
Room→org conversion | `organizations.origin_space_id` | group-detail |

### Cross-lane collisions
- **Lane 3** (`app/Services/Rooms/LiveFloorService.php`, `app/Http/Controllers/Rooms/LiveRoomController.php`, `app/Services/Matrix/MatrixClientService.php`, `resources/js/Components/Civic/Room/LiveRoom.vue`, `ChamberStage.vue`): all real presence/occupancy work. Lane 6 **reads only** (`LiveFloorService::state()`), copies patterns, adds no presence write path. Exposing `connectionState`/`participants.length` upward from `LiveRoom.vue` for the connected-state pill is a lane 3 surface change — coordinate; ship the honest-empty half alone if it slips.
- **Lane 3** (`app/Services/Invites/InviteService.php::spaceDestination`, `app/Http/Controllers/Invites/InviteController.php::land`): **security defect, hand off.** `spaceDestination` never checks the minter's membership, so a member who leaves a room, keeps the `/civic/rooms/{uuid}` URL, mints their own `kind=space` invite and opens it is **silently re-admitted** — defeating `leave()`. The guard already exists: `PrivateRoomService::isMember`. Add a case beside `tests/Constitutional/PrivateRoomTest.php::test_a_space_invite_admits_the_redeemer_as_a_member`.
- **Lane 3 / policy**: `routes/web.php:995` `Route::middleware('auth')->prefix('civic')` gates `/civic/commons/square|halls` and `/civic/rooms/{space}`, so a guest on a `call`/`commons`/`space` invite **cannot watch anything** while the page promises they can. Lane 6's fix is the copy; whether a guest may read the commons is a route/policy call above this lane.
- **Lane 13** (`app/Services/Economy/AccountService.php`, `LaborBoardService.php` — `post()` has no route or form id, `MarketplaceService.php`, `app/Domain/Forms/Handlers/MarketplaceListingOrder.php`, `app/Http/Controllers/Organizations/OrgEconomyController.php`, `resources/js/Pages/Economy/OrgSettings.vue`): the org-account writer, org-scoped listing, "Post a job" door, and **the org-ledger card** (their Wave 4 item ① per `docs/plans/ui/WAVE4_STANDING_ORDERS.md:53`). Lane 6 ships a steer-gated link to `/organizations/{id}/economy` instead of a second ledger panel. Wallet reads go **through `AccountService`**, honouring `ECONOMY_PROP_CONTRACT.md` (money is a string; nothing is absent).
- **Lane 13** (`app/Services/Redlines/RedlineService.php`, `app/Domain/Forms/Handlers/ResidentAgreement.php`): half-open door — `EconomyActionController:97` validates `subject_type in:resident,org_contract,bill` but `assertParty()` has no `bill` arm, so every bill redline is refused. `composeBillText` has **zero callers**; `seedClauses` is called only for `subject_type='resident'`. Not a prerequisite for the bill conversation screen. Flag, don't fix.
- **Lane 15** (`app/Http/Controllers/Social/PersonProfileController.php`): the `officesFor()` + endorsements extraction touches their closed Wave 2 work. Also `resources/js/Pages/Social/PersonProfile.vue` — the dead-ending "Message" button collides with their Wave 4 item ② (p2p DM). **Desk must split before either lane edits `PersonProfile.vue`.** `resources/js/registry/education.js` is generated by `scripts/education/build_education_payload.mjs` — never hand-edit.
- **Lane 15** (`resources/js/Pages/Civic/Journey.vue`, `resources/js/Components/ShellV2/LearnFlyout.vue`): journey partials are co-owned (their item ③). `LearnFlyout.vue:18,142` still renders "Full lessons — Planned · Phase 7" on **every** page although `/learn/{track}/{module}` is built — same staleness class, their file.
- **Lane 5** (`resources/js/i18n/**`): every copy change above orphans or contradicts extracted keys — `civic_relocation.*` (6 locales), `civic_identityverification.*` (`locales/en/civic.json:50-65`), `civic_journey.*` (`:66-87`, including `planned_phase_8` which enshrines a phase that does not exist), `invite_landing.*`. **Hand lane 5 a key delta at wave close; do not edit the locale tree from this lane.** The nav relabel "Private rooms" → "Messages" (`nav.js:60` + `i18n/en.json:32`) needs the same key across six locales — coordinate, do not land unilaterally. Note the project posture is deliberate: page body copy ships literal English inline.

### Constitutional-rule changes — operator's word, not code fixes
1. **Advocate pending→approved lifecycle.** Converts the bar from a competence register into a merits gate on a client's Art. I right to representation. `AdvocateService::register` rejects only on association + duplicate **by design** (`PHASE_E_DESIGN_cases_juries.md:270`). Do not add an engine-side status gate.
2. **Relaxing `ResidencyService::recordPing`'s monitoring guard** to admit settled-resident pings. That changes what the ping-purge privacy invariant requires.
3. **Per-party accept/reject on a bill redline.** `RedlineService:144-149` already throws `ConstitutionalViolation(… 'Art. V §3')`. Do not add a `bill` arm to `assertParty`.
4. **Guest read access to `/civic/commons`.** The commons is resident discourse, not a government proceeding, so the public-by-default ruling does not obviously reach it.

### Doc mismatch to settle
`docs/plans/education/K2_CONTENT_WAVE2.md:64` reserves surface id `legislature/bill-conversation` and attributes the screen to **lane 3**, while `docs/plans/ui/tools/assign_badges.py:29` assigns `shared/bill.html` to **lane 6**. The Wave 4 standing order (lane 6) is the live authority — flag so lane 15's learn-copy keys land on the same surface id.

---

## 6. RUBRIC CORRECTIONS

1. **`groups/group-create` is not partial — it is `props:full` / `backend:full`.** Its single `propsMissing` item (the `@handle` chip picker) is a documented deliberate drop quoted verbatim in two files (`PrivateRoomController.php:82`; `PrivateRooms.vue:6-9`), the invite-link replacement is fully built **and pinned** (`tests/Constitutional/PrivateRoomTest.php`, `tests/Feature/MessagesInboxTest.php`), and the mockup's own submit handler was `(simulated)`. Move the row from `propsMissing` to `specHas-as-documented-drop`. No dedicated create page is needed.
2. **`social_follows` is wired, not "exists, unwired".** `PersonProfileController::follow/unfollow` write it (`:147-172`), routes exist (`routes/web.php:262-265`), `isFollowing`/`followCounts` read it (`:127-131`, `:255-264`). Correct the line; a follow must stay local-only (never audited, never federated — `SocialFollow` docblock `:10-11`).
3. **`resources/js/composables/useLiveRoom.js` is not a presence source.** The rubric points at it for presence; it is a transport-agnostic freshness layer calling `router.reload({only: keys})` on a cadence. It re-fetches props and computes nothing. There is nothing to reuse.
4. **Client journey registry has drifted from config.** `resources/js/registry/journeys.js` holds 13 entries; `config/cga/journeys.php` holds 14. `tests/Feature/JourneysTest.php:46-47` asserts `count(config('cga.journeys'))` and never asserts the mirror — which is why it went unnoticed. Three entries also still say `status:'planned'` for arcs that went live 2026-07-26.
5. **Message attachments: the rubric is correct, not stale.** Verified there is **no user-upload path anywhere in the codebase** — `grep hasFile|UploadedFile` over `app/Http/Controllers` returns zero hits; even org "documents" take a text string.
6. **`civic/today` is ahead of the mockup on the stipend.** The mockup pills it "Coming soon"; `ubi_disbursements` is real and written by `StipendService::run`. Do not carry the mockup's `planned` pill.
7. **Object-bound subforums are write-unreachable app-wide.** `HallsController::store` never passes `subforum_id`, so every bill/petition/referendum/candidacy subforum `SubforumReconciler` creates can be read but never written to. Two-line fix, batch 6 — but it is a fleet-wide finding, not a bill-screen finding.
8. **`journeys/journey` claims two things that shipped.** "Live rooms arrive in Phase 6" (the committee live room is routed and public at `routes/web.php:760`) and "Phase 8" for the economy (`/economy/wallet|stipend|treasury` are live; "Phase 8" is not a phase that exists in the roadmap).
9. **`registry/surfaces.js:258` hardcodes a demo-committee UUID** for the tour stop. That is one box's row. No new code may copy it.

---

## 7. THE ONE THING TO DO FIRST

**Add the 14th arc to `resources/js/registry/journeys.js`.**

```js
{ id:'become-a-resident', cls:'people', clsLabel:CLASS_LABELS.people, flagship:false, status:'live',
  title:'Becoming a resident',
  yourPart:'you do this one — register, declare where you live, and let your presence confirm it',
  rail:['Register','Declare where you live','Presence confirms','Residency confirmed',
        'You appear at every level','Rights switch on'],
  rooms:[], earn:'the places this journey touches will greet you as someone who knows the ropes' }
```

Why this and nothing else first:

- It is the **first arc a new player ever walks**, and today it renders its interaction-class id — the literal string `people` — in the eyebrow, then falls through to the generic "follow the arc below", the generic earn line, and an irrelevant "live rooms arrive in Phase 6" gloss. Every display value on `Journey.vue:43-49` reads `JOURNEYS_BY_ID[props.journey.id]` and that lookup returns `null`.
- The arc is **already live server-side** (`config/cga/journeys.php:38-49`, six steps, `status: live`) and its award key is already in `AchievementCatalog.php:79`. The world works; only the label registry is missing. This is the purest possible green-per-effort in the wave: one object literal, one file, zero backend, zero CSS, zero coordination, zero tests to touch.
- It is a **truth fix on the onboarding path**, which is where the game is judged. The rest of the wave adds panels; this one stops the app from talking to a brand-new player in machine grammar on their very first journey.

Land it, then the rest of Batch 1 in the same commit.