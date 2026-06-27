/* GENERATED from manifest.json - merged v1 (operations) + v2 (game layer). */
window.CGA_MANIFEST = [
 {
  "file": "index.html",
  "title": "Launchpad — the five interaction classes + journey directory",
  "interactionClass": "all",
  "journeys": [],
  "reusesV1": [
   "index.html"
  ],
  "productionPages": [],
  "matrix": [],
  "forms": [],
  "status": "built-layer",
  "stage": 0,
  "notes": "v2 entry point. The five interaction classes as cards, the journey directory with progress rails, the eight Live-Room variants, and handoff links. Governance register; reuses v1 tokens/shell foundation via fixtures-v2 + shell-v2."
 },
 {
  "file": "system/setup.html",
  "title": "Instance setup — the founding loop",
  "module": "system",
  "interactionClass": "all",
  "journeys": [],
  "reusesV1": [
   "system/setup-wizard.html"
  ],
  "productionPages": [
   "resources/js/Pages/Setup/Step0_CosmicAddress.vue",
   "resources/js/Pages/Setup/Step1_Constants.vue",
   "resources/js/Pages/Setup/Step2_MapData.vue",
   "resources/js/Pages/Setup/Step3_Districts.vue",
   "resources/js/Pages/Setup/Step4_Confirm.vue"
  ],
  "matrix": [],
  "forms": [],
  "status": "built-layer",
  "stage": 0,
  "notes": "The five-step constitutional founding wizard (cosmic address -> 19 reference-default constitution settings -> map data/ETL -> districts -> seat institutions), ported 1:1 from the v1 setup-wizard into the v2 shell, shown as its completed end-state. Reference defaults not locks (Art. VII); apportionment fires at the planet-scope Accept Map Data button (1,999 seats / 393 districts / 951,636 rows / 11.30 GB seed). DISTINCT from operator/setup.html (node/mesh provisioning). First stop of the guided tour."
 },
 {
  "file": "tour.html",
  "title": "Guided tour — the linear walkthrough",
  "module": "shared",
  "interactionClass": "all",
  "journeys": [],
  "reusesV1": [],
  "productionPages": [],
  "matrix": [],
  "forms": [],
  "status": "built-layer",
  "stage": 0,
  "notes": "One linear path through the whole game layer (35 stops, 8 acts). A page enters tour mode via ?step=N; shell-v2 renders a Back/Next follow-along bar at the top of <main>. The order is exposed as CGA.shellV2.tour and is the spine of this page."
 },
 {
  "file": "shared/live-room.html",
  "title": "Live Civic Room — the keystone (8 variants via ?variant=)",
  "interactionClass": "all",
  "journeys": [
   "election",
   "committee-session",
   "bill",
   "court-case",
   "budget",
   "start-org",
   "board-meeting",
   "form-a-group",
   "mutual-aid",
   "petition-to-referendum",
   "public-service",
   "stipend-and-tax",
   "two-governments"
  ],
  "reusesV1": [
   "legislature/session-console.html",
   "legislature/committee-detail.html",
   "judiciary/case-detail.html",
   "organizations/board-elections.html",
   "electoral/open-ballot.html",
   "legislature/referendums.html"
  ],
  "productionPages": [
   "resources/js/Pages/Legislature/SessionConsole.vue",
   "resources/js/Components/Legislature/VoteTally.vue",
   "resources/js/Components/Legislature/AgendaStrip.vue",
   "resources/js/Pages/Civic/MatrixCommons.vue",
   "resources/js/Pages/Judiciary/CaseDetail.vue",
   "resources/js/Pages/Organizations/BoardElections.vue"
  ],
  "matrix": [
   "social_spaces(halls)",
   "matrix_rooms",
   "LiveKitTokenService",
   "TestimonyBridgeService",
   "TranslationGate",
   "MatrixPostingGateService"
  ],
  "forms": [
   "F-SPK-001..009",
   "F-CHR-001..004",
   "F-LEG-002..007",
   "F-JDG-001..003",
   "F-ORG-003..004",
   "F-SOC-001..002"
  ],
  "status": "built-layer",
  "stage": 1,
  "configContract": "MANIFEST.md §Live-Room config contract",
  "notes": "THE keystone. The fused form+social surface: header band + countdown clocks, locked/unlocked agenda, center-stage floor with How-this-works drawer + v1 deep-link, the vote tile (denominator always visible), the speaker rail (presence, hands-raised queue, Matrix chat with seat badges + testimony, voice, the translation privacy rail), the testimony-bridge record strip. Chair controls go live when you assume the chair persona; aria-live announces floor/vote/result. Eight variants: committee, legislative, exec, board, court, forum, townhall, group."
 },
 {
  "file": "shared/coverage.html",
  "title": "v2 coverage — meeting types × journeys × interaction classes × mechanics",
  "interactionClass": "all",
  "journeys": [],
  "reusesV1": [],
  "productionPages": [],
  "matrix": [],
  "forms": [],
  "status": "built-layer",
  "stage": 1,
  "notes": "Generated from the v2 manifest + fixtures.v2. Proves §13 definition-of-done as stages land."
 },
 {
  "file": "journeys/journey.html",
  "title": "Journey — guided arc (13 journeys via ?id=)",
  "module": "journeys",
  "interactionClass": "all",
  "journeys": [],
  "reusesV1": [],
  "productionPages": [],
  "matrix": [],
  "forms": [],
  "status": "built-layer",
  "stage": 2,
  "notes": "One page renders every journey from ?id=: the progress rail, now/your-part/next, the live rooms it passes through, and the v1 deep-links for the formal steps."
 },
 {
  "file": "economy/economy-home.html",
  "title": "The exchange — the economy home",
  "module": "economy",
  "interactionClass": "all",
  "journeys": [],
  "reusesV1": [],
  "productionPages": [],
  "matrix": [],
  "forms": [],
  "status": "planned-layer",
  "stage": 2,
  "notes": "Entry to the Open Market: marketplace, requests, agreements, wallet, joint ledgers, units/monetary policy, stipend, treasury, org economics. The economic clock + the rails."
 },
 {
  "file": "economy/exchange.html",
  "title": "The exchange — the trading floor",
  "module": "economy",
  "interactionClass": "all",
  "journeys": [],
  "reusesV1": [],
  "productionPages": [],
  "matrix": [],
  "forms": [],
  "status": "planned-layer",
  "stage": "M",
  "notes": "The Open Market trading floor: a scrolling live ticker, an order-book ladder with depth bars, a streaming trade tape (reduced-motion-safe), price/volume sparklines, and trader presence. Click a symbol to focus. Grounded: shares fair-market (Art. III §5), abstract units, private settlement (Art. V §5), CGC identical terms. Liveness simulated in-page; F-IND-018..023 reserved."
 },
 {
  "file": "economy/marketplace.html",
  "title": "Marketplace — offers on the Open Market",
  "module": "economy",
  "interactionClass": "all",
  "journeys": [],
  "reusesV1": [],
  "productionPages": [],
  "matrix": [],
  "forms": [],
  "status": "planned-layer",
  "stage": 2,
  "notes": "Listings (goods/services); CGC identical-terms; place an order; offer<->request switch. F-IND-021/022."
 },
 {
  "file": "economy/listing-detail.html",
  "title": "Listing detail",
  "module": "economy",
  "interactionClass": "all",
  "journeys": [],
  "reusesV1": [],
  "productionPages": [],
  "matrix": [],
  "forms": [],
  "status": "planned-layer",
  "stage": 2,
  "notes": "One listing; the order flow forms an agreement; private settlement."
 },
 {
  "file": "economy/requests.html",
  "title": "Request board — the mirror",
  "module": "economy",
  "interactionClass": "all",
  "journeys": [],
  "reusesV1": [],
  "productionPages": [],
  "matrix": [],
  "forms": [],
  "status": "planned-layer",
  "stage": 2,
  "notes": "Work board (F-IND-019 -> F-IND-014 -> co-determination) + mutual-aid/assistance (private). Offer<->Request."
 },
 {
  "file": "economy/request-detail.html",
  "title": "Work posting detail",
  "module": "economy",
  "interactionClass": "all",
  "journeys": [],
  "reusesV1": [],
  "productionPages": [],
  "matrix": [],
  "forms": [],
  "status": "planned-layer",
  "stage": 2,
  "notes": "Application -> F-IND-014 -> org_contract -> crosses the worker-seat threshold."
 },
 {
  "file": "economy/wallet.html",
  "title": "My wallet — private",
  "module": "economy",
  "interactionClass": "all",
  "journeys": [],
  "reusesV1": [],
  "productionPages": [],
  "matrix": [],
  "forms": [],
  "status": "planned-layer",
  "stage": 2,
  "notes": "Personal balance + transactions; never federated, like a ballot; abstract units; F-IND-023 transfer."
 },
 {
  "file": "economy/joint-ledgers.html",
  "title": "Joint ledgers — agreement-gated",
  "module": "economy",
  "interactionClass": "all",
  "journeys": [],
  "reusesV1": [],
  "productionPages": [],
  "matrix": [],
  "forms": [],
  "status": "planned-layer",
  "stage": 2,
  "notes": "Co-owned accounts; movements need every signer to agree. Art. V §2 + Art. I."
 },
 {
  "file": "economy/units.html",
  "title": "Units & monetary policy",
  "module": "economy",
  "interactionClass": "all",
  "journeys": [],
  "reusesV1": [],
  "productionPages": [],
  "matrix": [],
  "forms": [],
  "status": "planned-layer",
  "stage": 2,
  "notes": "The unit + subdivisions (measurement standards), worth, and the dual-door monetary levers (F-LEG-031). Art. V §5."
 },
 {
  "file": "economy/stipend.html",
  "title": "Civic stipend — UBI + role differential",
  "module": "economy",
  "interactionClass": "all",
  "journeys": [],
  "reusesV1": [],
  "productionPages": [],
  "matrix": [],
  "forms": [],
  "status": "planned-layer",
  "stage": 2,
  "notes": "base (residency floor) + capped role bumps (operator/moderator/officeholder) on the economic clock; dual-door; k-anon. F-TRE-004."
 },
 {
  "file": "economy/treasury.html",
  "title": "Public finance — the public ledger",
  "module": "economy",
  "interactionClass": "all",
  "journeys": [],
  "reusesV1": [],
  "productionPages": [],
  "matrix": [],
  "forms": [],
  "status": "planned-layer",
  "stage": 2,
  "notes": "Budget cycle (F-LEG-037/038, F-TRE-001..003), public double-entry hash-chained ledger, revenue, borrowing. Art. II §9, III §4, V §4."
 },
 {
  "file": "economy/agreements.html",
  "title": "Instruments of agreement",
  "module": "economy",
  "interactionClass": "all",
  "journeys": [],
  "reusesV1": [],
  "productionPages": [],
  "matrix": [],
  "forms": [],
  "status": "planned-layer",
  "stage": 2,
  "notes": "Contracts (labor/ownership/joint/sale) each with the Supremacy-of-Rights floor. Art. I."
 },
 {
  "file": "economy/agreement-detail.html",
  "title": "Agreement detail",
  "module": "economy",
  "interactionClass": "all",
  "journeys": [],
  "reusesV1": [],
  "productionPages": [],
  "matrix": [],
  "forms": [],
  "status": "planned-layer",
  "stage": 2,
  "notes": "One agreement; both-parties-sign; the constitutional floor; co-determination consequence."
 },
 {
  "file": "economy/org-settings.html",
  "title": "Organization economics",
  "module": "economy",
  "interactionClass": "all",
  "journeys": [],
  "reusesV1": [],
  "productionPages": [],
  "matrix": [],
  "forms": [],
  "status": "planned-layer",
  "stage": 2,
  "notes": "Org settings, shares + fair-market (Art. III §5), dues, the org ledger, taxes. Inter-org units."
 },
 {
  "file": "social/profile.html",
  "title": "Personal profile",
  "module": "social",
  "interactionClass": "all",
  "journeys": [],
  "reusesV1": [],
  "productionPages": [],
  "matrix": [],
  "forms": [],
  "status": "built-layer",
  "stage": 2,
  "notes": "Social profile: bio, endorsements (public by choice), groups, orgs, achievements (Proposed), follow/message. Pseudonymity. Art. I."
 },
 {
  "file": "social/org-profile.html",
  "title": "Organization profile",
  "module": "social",
  "interactionClass": "all",
  "journeys": [],
  "reusesV1": [],
  "productionPages": [],
  "matrix": [],
  "forms": [],
  "status": "built-layer",
  "stage": 2,
  "notes": "Org public page: type/charter/workers/board/co-determination/listings/contracts/ledger."
 },
 {
  "file": "social/rep.html",
  "title": "Your representative (rep<->citizen)",
  "module": "social",
  "interactionClass": "all",
  "journeys": [],
  "reusesV1": [],
  "productionPages": [],
  "matrix": [],
  "forms": [],
  "status": "built-layer",
  "stage": 2,
  "notes": "A rep public page: record, forum, office hours, request a meeting, constituent message, the requests queue. Art. I Fair Representation."
 },
 {
  "file": "social/social-home.html",
  "title": "Social — the network home",
  "module": "social",
  "interactionClass": "all",
  "journeys": [],
  "reusesV1": [],
  "productionPages": [],
  "matrix": [],
  "forms": [],
  "status": "built-layer",
  "stage": 2,
  "notes": "Public square + halls entry, a feed, follows; uncensorable + pseudonymity. Art. I."
 },
 {
  "file": "civic/today.html",
  "title": "Today — what is live now",
  "module": "civic",
  "interactionClass": "all",
  "journeys": [],
  "reusesV1": [],
  "productionPages": [],
  "matrix": [],
  "forms": [],
  "status": "built-layer",
  "stage": 2,
  "notes": "One-tap entries to live sessions/hearings/votes/forums/stipend/group meetings in my jurisdictions."
 },
 {
  "file": "civic/my-civic-life.html",
  "title": "My civic life",
  "module": "civic",
  "interactionClass": "all",
  "journeys": [],
  "reusesV1": [],
  "productionPages": [],
  "matrix": [],
  "forms": [],
  "status": "built-layer",
  "stage": 2,
  "notes": "Personal home base: groups, orgs, open votes, record, rep, wallet (Planned), stipend (Planned), achievements (Proposed)."
 },
 {
  "file": "groups/groups-home.html",
  "title": "Informal groups — browse & create",
  "module": "groups",
  "interactionClass": "all",
  "journeys": [],
  "reusesV1": [],
  "productionPages": [],
  "matrix": [],
  "forms": [],
  "status": "built-layer",
  "stage": 3,
  "notes": "Voluntary affinity groups (Art. I); browse/join + create. No governance power; membership private."
 },
 {
  "file": "groups/group-create.html",
  "title": "Create a group",
  "module": "groups",
  "interactionClass": "all",
  "journeys": [],
  "reusesV1": [],
  "productionPages": [],
  "matrix": [],
  "forms": [],
  "status": "built-layer",
  "stage": 3,
  "notes": "Two-step create: name, purpose, open/invite, spin up a room. No charter, no power conferred."
 },
 {
  "file": "groups/group-detail.html",
  "title": "Group home",
  "module": "groups",
  "interactionClass": "all",
  "journeys": [],
  "reusesV1": [],
  "productionPages": [],
  "matrix": [],
  "forms": [],
  "status": "built-layer",
  "stage": 3,
  "notes": "A group: discussion room, members, pinned, call a meeting (casual/facilitated/formal via the live room), public-square carve-outs vs private self-moderation, optional next steps (org/petition)."
 },
 {
  "file": "social/achievements.html",
  "title": "Achievements — a fenced catalog",
  "module": "social",
  "interactionClass": "all",
  "journeys": [],
  "reusesV1": [],
  "productionPages": [],
  "matrix": [],
  "forms": [],
  "status": "planned-layer",
  "stage": "I",
  "notes": "A decorative achievements catalog grouped by the 4 subject tiers (Individual/Organization/Jurisdiction/Global) with the real ACH-* codes, earned vs locked medals, a derived in-progress meter, and a global milestone wall. Hard-fenced: CI-1 no governance advantage, PI-6 no per-person score, PI-1 no individual leaderboard, PI-3 k-anon, PI-2 from-the-envelope, PI-4 default-private. Proposed; no AchievementCatalog in code."
 },
 {
  "file": "social/legitimacy.html",
  "title": "Reach & legitimacy gauge",
  "module": "social",
  "interactionClass": "all",
  "journeys": [],
  "reusesV1": [],
  "productionPages": [],
  "matrix": [],
  "forms": [],
  "status": "planned-layer",
  "stage": 3,
  "notes": "Phase I reach ratio (verified/population), k-anon, display-only — NEVER a governance input (CI-1). Activation tiers."
 },
 {
  "file": "operator/operator-home.html",
  "title": "Run a node — the operator plane",
  "module": "operator",
  "interactionClass": "operator",
  "journeys": [],
  "reusesV1": [
   "jurisdictions/federation.html"
  ],
  "productionPages": [
   "resources/js/Pages/Jurisdictions/Federation.vue"
  ],
  "matrix": [],
  "forms": [],
  "status": "built-layer",
  "stage": "G",
  "notes": "Off the constitutional plane (capability, not role). The plane wall, the 4 named roles, the health line, entry to every operator surface. Operators = de-facto board answerable to the seated government."
 },
 {
  "file": "operator/setup.html",
  "title": "Set up your node — first-run wizard",
  "module": "operator",
  "interactionClass": "operator",
  "journeys": [],
  "reusesV1": [
   "jurisdictions/federation.html"
  ],
  "productionPages": [
   "resources/js/Pages/Jurisdictions/Federation.vue"
  ],
  "matrix": [],
  "forms": [],
  "status": "built-layer",
  "stage": "G",
  "notes": "Claim an operator account (local password OR device-key link), name the instance, pick a role (Record Keeper recommended), role-specific setup, done. Governed roles can only be requested → dual-meter approval."
 },
 {
  "file": "operator/console.html",
  "title": "The operator console (two-tier)",
  "module": "operator",
  "interactionClass": "operator",
  "journeys": [],
  "reusesV1": [
   "jurisdictions/federation.html"
  ],
  "productionPages": [
   "resources/js/Pages/Jurisdictions/Federation.vue"
  ],
  "matrix": [],
  "forms": [],
  "status": "built-layer",
  "stage": "G",
  "notes": "Tier 1: 4 named-role cards + the peers/sync health line. Tier 2 (Advanced): the raw 9-channel grid, Meters A/B/C, peers, sync ledger, transports, CLI. Federation.vue redesign."
 },
 {
  "file": "operator/roles.html",
  "title": "Roles & channels",
  "module": "operator",
  "interactionClass": "operator",
  "journeys": [],
  "reusesV1": [
   "jurisdictions/federation.html"
  ],
  "productionPages": [
   "resources/js/Pages/Jurisdictions/Federation.vue"
  ],
  "matrix": [],
  "forms": [],
  "status": "built-layer",
  "stage": "G",
  "notes": "The 4 named roles over the 9 capability channels (3 self-asserted / 6 governed); the qualify→request→approve→join lifecycle; the three meters (B supersedes A; C future)."
 },
 {
  "file": "operator/mesh.html",
  "title": "Mesh & federation",
  "module": "operator",
  "interactionClass": "operator",
  "journeys": [],
  "reusesV1": [
   "jurisdictions/federation.html"
  ],
  "productionPages": [
   "resources/js/Pages/Jurisdictions/Federation.vue"
  ],
  "matrix": [],
  "forms": [],
  "status": "built-layer",
  "stage": "G",
  "notes": "Join a cluster (4-step wizard), peers (ESM-20), Full Faith & Credit sync, authority + the Art. V §7 read-write petition (government decides, not the operator), transports."
 },
 {
  "file": "operator/dns.html",
  "title": "DNS & certificates (Identity Broker)",
  "module": "operator",
  "interactionClass": "operator",
  "journeys": [],
  "reusesV1": [
   "jurisdictions/federation.html"
  ],
  "productionPages": [
   "resources/js/Pages/Jurisdictions/Federation.vue"
  ],
  "matrix": [],
  "forms": [],
  "status": "built-layer",
  "stage": "G",
  "notes": "DNS-before-cert; per-name primary + wildcard backup (gated/stub); DDNS; DNS providers (Cloudflare live, stubs); the LE budget pre-flight. Token write-only, never federates. services/mesh-cert-broker."
 },
 {
  "file": "operator/identity.html",
  "title": "Identity (G-ID)",
  "module": "operator",
  "interactionClass": "operator",
  "journeys": [],
  "reusesV1": [
   "jurisdictions/federation.html"
  ],
  "productionPages": [
   "resources/js/Pages/Jurisdictions/Federation.vue"
  ],
  "matrix": [],
  "forms": [],
  "status": "built-layer",
  "stage": "G",
  "notes": "Operator devices + mesh identity (link by possession, never password replay); citizen attestation + AttestedForwardedActor (attestation + action-signature + subject checks); the expiry sweep. Plane wall."
 },
 {
  "file": "operator/moderation.html",
  "title": "Moderation & the legal floor",
  "module": "operator",
  "interactionClass": "operator",
  "journeys": [],
  "reusesV1": [
   "jurisdictions/federation.html"
  ],
  "productionPages": [
   "resources/js/Pages/Jurisdictions/Federation.vue"
  ],
  "matrix": [],
  "forms": [],
  "status": "built-layer",
  "stage": "G",
  "notes": "The legitimacy flip (operator relay below → judicial above, automatic), the four carve-outs (viewpoint removal has no code path), M-5 legal floor (key-possession, closed legal_basis enum, never server_acl, F-SOC-004)."
 },
 {
  "file": "operator/versioning.html",
  "title": "Versions & upgrades",
  "module": "operator",
  "interactionClass": "operator",
  "journeys": [],
  "reusesV1": [
   "jurisdictions/federation.html"
  ],
  "productionPages": [
   "resources/js/Pages/Jurisdictions/Federation.vue"
  ],
  "matrix": [],
  "forms": [],
  "status": "built-layer",
  "stage": "G",
  "notes": "The hardened admissibility filter (Art. VII), the dual-meter upgrade agreement, the game-in-progress freeze (Art. II §7), peer version match/refusal (fail-closed)."
 },
 {
  "file": "shared/video-player.html",
  "title": "Video library — the multi-track player",
  "module": "learn",
  "interactionClass": "all",
  "journeys": [],
  "reusesV1": [],
  "productionPages": [],
  "matrix": [],
  "forms": [],
  "status": "built-layer",
  "stage": "K",
  "notes": "The reusable multi-track player modeled on functions/video_player.php: one silent master MP4 + N audio (.m4a) + N caption (.vtt) tracks, the link toggle, drift-correction, remembered prefs. The library of every guide video. components-v2.js videoPlayer/initVideo."
 },
 {
  "file": "learn/learn-home.html",
  "title": "Learn — education tracks",
  "module": "learn",
  "interactionClass": "all",
  "journeys": [],
  "reusesV1": [],
  "productionPages": [],
  "matrix": [],
  "forms": [],
  "status": "built-layer",
  "stage": "K",
  "notes": "The education home: six tracks (getting started, elections, lawmaking, justice, economy, running a node), a featured lesson with the video player, and progress. Every lesson wraps a video + its SOP + a knowledge check + the live journey."
 },
 {
  "file": "learn/lesson.html",
  "title": "Lesson — video + SOP + check",
  "module": "learn",
  "interactionClass": "all",
  "journeys": [],
  "reusesV1": [],
  "productionPages": [],
  "matrix": [],
  "forms": [],
  "status": "built-layer",
  "stage": "K",
  "notes": "One lesson via ?id= (or ?sop=): the multi-track video, the standard procedure, a knowledge check, and a deep-link to the live journey and the v1 formal screen."
 },
 {
  "file": "learn/guides.html",
  "title": "Guides & procedures — the SOP index",
  "module": "learn",
  "interactionClass": "all",
  "journeys": [],
  "reusesV1": [],
  "productionPages": [],
  "matrix": [],
  "forms": [],
  "status": "built-layer",
  "stage": "K",
  "notes": "The SOP-in-interface once-over surface: every journey/tool/workflow's standard operating procedure, searchable by module, each with its video and report-an-issue."
 },
 {
  "file": "translation/translation-home.html",
  "title": "Translation status — languages x modalities",
  "module": "translation",
  "interactionClass": "all",
  "journeys": [],
  "reusesV1": [],
  "productionPages": [],
  "matrix": [],
  "forms": [],
  "status": "built-layer",
  "stage": "N",
  "notes": "The coverage matrix: 24 curated of 115 mapped languages (77 shipped), six modalities (interface, page copy, video audio, captions, education, help), the AI-first-round -> in-review -> community-verified -> published lifecycle, the engine, and add-a-language."
 },
 {
  "file": "translation/language.html",
  "title": "Language detail — review & verify",
  "module": "translation",
  "interactionClass": "all",
  "journeys": [],
  "reusesV1": [],
  "productionPages": [],
  "matrix": [],
  "forms": [],
  "status": "built-layer",
  "stage": "N",
  "notes": "One language via ?code=: per-modality breakdown, the review queue (source + AI draft -> accept/correct -> quorum), the contributor leaderboard, and the rail that verification is gated to people who read the interface in that language."
 },
 {
  "file": "support/support-home.html",
  "title": "Help & support — the hub",
  "module": "support",
  "interactionClass": "all",
  "journeys": [],
  "reusesV1": [],
  "productionPages": [],
  "matrix": [],
  "forms": [],
  "status": "built-layer",
  "stage": "ops",
  "notes": "The help hub: report an issue, the ticket queue, the guides/SOPs, the translation interface, and how a report routes itself by category."
 },
 {
  "file": "support/report.html",
  "title": "Report an issue",
  "module": "support",
  "interactionClass": "all",
  "journeys": [],
  "reusesV1": [],
  "productionPages": [],
  "matrix": [],
  "forms": [],
  "status": "built-layer",
  "stage": "ops",
  "notes": "The site-wide report form (linked from every footer): pick a category (bug/translation/accessibility/content/abuse/idea), the page is attached automatically, and the report routes itself - abuse/illegal goes OFF to the moderation & legal plane, never adjudicated here."
 },
 {
  "file": "support/tickets.html",
  "title": "Tickets — the queue",
  "module": "support",
  "interactionClass": "all",
  "journeys": [],
  "reusesV1": [],
  "productionPages": [],
  "matrix": [],
  "forms": [],
  "status": "built-layer",
  "stage": "ops",
  "notes": "The ticket queue: filter by status/category, severity dots, routing (bugs->operators, translation->translators, abuse->moderation plane). Open counts and the lifecycle."
 },
 {
  "file": "support/ticket.html",
  "title": "Ticket detail",
  "module": "support",
  "interactionClass": "all",
  "journeys": [],
  "reusesV1": [],
  "productionPages": [],
  "matrix": [],
  "forms": [],
  "status": "built-layer",
  "stage": "ops",
  "notes": "One ticket via ?id=: the report, the routing, the status thread, linked translation review or moderation carve-out where relevant."
 },
 {
  "file": "operations.html",
  "title": "Launchpad — role picker & workflow directory (operations index)",
  "module": "shared",
  "roles": [],
  "workflows": [],
  "forms": [],
  "entities": [],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Mockup-only surface (not a product screen). Brand register hero; role picker for all 30 roles in 7 tiers; 80-workflow directory; unbuilt targets render as Planned cards so dead links are structurally impossible.",
  "stage": 0
 },
 {
  "file": "shared/coverage-ops.html",
  "title": "Coverage matrix — roles × workflows × forms",
  "module": "shared",
  "roles": [],
  "workflows": [],
  "forms": [],
  "entities": [],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated at load by coverage.js from fixtures.registry × manifest.js. Includes manifest.json drift check and dead-link scan (http only). QA §15 instrument.",
  "stage": 0
 },
 {
  "file": "shared/styleguide.html",
  "title": "Style guide — live component sheet",
  "module": "shared",
  "roles": [],
  "workflows": [
   "WF-CIV-02",
   "WF-ELE-03",
   "WF-JUD-05"
  ],
  "forms": [],
  "entities": [
   "Residency Claim",
   "Vacancy",
   "Constitutional Challenge"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Every mockup.css component rendered inside the real shell; flow-stepper contract stress-tested live against the three sample workflows; RTL/pseudo-locale canary block. Component inventory source for MANIFEST.md.",
  "stage": 0
 },
 {
  "file": "civic/advocate-registration.html",
  "title": "Advocate registration",
  "module": "civic",
  "roles": [
   "R-03",
   "R-21"
  ],
  "workflows": [
   "WF-CIV-07"
  ],
  "forms": [
   "F-IND-015"
  ],
  "entities": [
   "Individual"
  ],
  "clocks": [],
  "suggestedVuePage": "resources/js/Pages/Civic/AdvocateRegistration.vue",
  "notes": "Prerequisite checklist pattern (judiciary-exists I-JUD met / R-03 met / qualifications declared); jurisdiction-law qualifications shown as fictional county act with 'set by jurisdiction law' framing; submit blocks until all three qualification checkboxes confirmed → pending-judiciary-review state; R-21/R-22 catalog drift noted.",
  "stage": 1
 },
 {
  "file": "civic/civic-home.html",
  "title": "Civic home — R-03/R-04 dashboard",
  "module": "civic",
  "roles": [
   "R-03",
   "R-04",
   "R-05"
  ],
  "workflows": [
   "WF-CIV-02",
   "WF-CIV-04",
   "WF-CIV-06",
   "WF-CIV-08"
  ],
  "forms": [],
  "entities": [
   "Individual"
  ],
  "clocks": [
   "CLK-03",
   "CLK-17",
   "CLK-18",
   "CLK-21"
  ],
  "suggestedVuePage": "resources/js/Pages/Civic/Home.vue",
  "notes": "Rights-unlocked card with all six tier chips; phase-aware election cards; emergency banner pattern; civic-journey card (Proposed).",
  "stage": 1
 },
 {
  "file": "civic/identity-verification.html",
  "title": "Identity verification",
  "module": "civic",
  "roles": [
   "R-01"
  ],
  "workflows": [
   "WF-CIV-01"
  ],
  "forms": [
   "F-IND-004"
  ],
  "entities": [
   "Individual"
  ],
  "clocks": [],
  "suggestedVuePage": "resources/js/Pages/Civic/IdentityVerification.vue",
  "notes": "Branch-preview pattern: two aria-pressed buttons toggle supported-bridge vs manual-path panels; F-IND-004/005 catalog-swap alias rendered on the form card; copy stresses verification is never a rights requirement.",
  "stage": 1
 },
 {
  "file": "civic/my-record.html",
  "title": "My record",
  "module": "civic",
  "roles": [
   "R-03",
   "R-04"
  ],
  "workflows": [
   "WF-SYS-03",
   "WF-SYS-04"
  ],
  "forms": [],
  "entities": [
   "Individual"
  ],
  "clocks": [],
  "suggestedVuePage": "resources/js/Pages/Civic/MyRecord.vue",
  "notes": "Append-only log-row pattern (seq + UTC-hinted date + type badge + text + chained hash, data-no-i18n on hashes); stats match civic-home (14 ballots / 2 petitions / 1 statement); explicit ballot-secrecy banner — participation public, choices never recorded; feeds WF-CIV-05 candidate profiles.",
  "stage": 1
 },
 {
  "file": "civic/onboarding.html",
  "title": "Onboarding",
  "module": "civic",
  "roles": [
   "R-01"
  ],
  "workflows": [
   "WF-CIV-01"
  ],
  "forms": [
   "F-IND-001",
   "F-IND-002"
  ],
  "entities": [
   "Individual"
  ],
  "clocks": [],
  "suggestedVuePage": "resources/js/Pages/Civic/Onboarding.vue",
  "notes": "Three-step onboarding stepper (account → identity → residency) reused across all step-1..3 pages; terms checkbox with inline field-error validation; Individual state-strip advances on simulated submit.",
  "stage": 1
 },
 {
  "file": "civic/petition-detail.html",
  "title": "Petition detail",
  "module": "civic",
  "roles": [
   "R-03",
   "R-05",
   "R-08",
   "R-19"
  ],
  "workflows": [
   "WF-CIV-06",
   "WF-JUD-09"
  ],
  "forms": [
   "F-IND-010",
   "F-ELB-005",
   "F-JDG-008"
  ],
  "entities": [
   "Petition"
  ],
  "clocks": [
   "CLK-17"
  ],
  "suggestedVuePage": "resources/js/Pages/Civic/PetitionDetail.vue",
  "notes": "pet-2030-11 deep-dive: Petition lifecycle tracker pinned at Constitutional-Review; formCard(fid) helper reading BY.forms renders F-ELB-005 and F-JDG-008 as institutional-gate cards with status badges; full law text as blockquote + scale/scope grid; sign action stays open during review.",
  "stage": 1
 },
 {
  "file": "civic/petitions.html",
  "title": "Petitions",
  "module": "civic",
  "roles": [
   "R-03",
   "R-05"
  ],
  "workflows": [
   "WF-CIV-06",
   "WF-JUD-09"
  ],
  "forms": [
   "F-IND-009",
   "F-IND-010"
  ],
  "entities": [
   "Petition"
  ],
  "clocks": [
   "CLK-17"
  ],
  "suggestedVuePage": "resources/js/Pages/Civic/Petitions.vue",
  "notes": "All three fixture petitions with signature meters + state badges + revocable sign switches; F-IND-009 create form computes a live threshold preview (5% of selected scale's population, Math.round matches all three fixture thresholds); scale select built from the viewer's association chain.",
  "stage": 1
 },
 {
  "file": "civic/relocation.html",
  "title": "Relocation",
  "module": "civic",
  "roles": [
   "R-03"
  ],
  "workflows": [
   "WF-CIV-03"
  ],
  "forms": [],
  "entities": [
   "Individual",
   "Residency Claim"
  ],
  "clocks": [
   "CLK-05",
   "CLK-04"
  ],
  "suggestedVuePage": "resources/js/Pages/Civic/Relocation.vue",
  "notes": "Travel-vs-move confirm rendered as two aria-pressed buttons with three outcome panels; move path shows a vertical flow-steps preview of WF-CIV-03; held R-09 seat card with grace-countdown meter is persona-aware (badges 'worked example' unless active persona holds R-09); countback handoff cited (Art. II §5).",
  "stage": 1
 },
 {
  "file": "civic/residency.html",
  "title": "Residency",
  "module": "civic",
  "roles": [
   "R-01",
   "R-02",
   "R-03"
  ],
  "workflows": [
   "WF-CIV-02"
  ],
  "forms": [
   "F-IND-003",
   "F-IND-005",
   "F-IND-006"
  ],
  "entities": [
   "Individual",
   "Residency Claim"
  ],
  "clocks": [
   "CLK-05"
  ],
  "suggestedVuePage": "resources/js/Pages/Civic/Residency.vue",
  "notes": "Stage derives from demo-state role (R-01 monitoring 22/30 → R-02 confirm-pending → R-03 fully associated) so flow deep-links land on the right state; six adm-chip rights-chain moment + hardened unlock chip; CLK-05 rendered as .amendable (30 days · residency_confirmation_days); static SVG Plaza Midwood boundary with ping cluster (wong fills).",
  "stage": 1
 },
 {
  "file": "electoral/candidacy-registration.html",
  "title": "Candidacy registration",
  "module": "electoral",
  "roles": [
   "R-03",
   "R-06"
  ],
  "workflows": [
   "WF-CIV-05"
  ],
  "forms": [
   "F-IND-011",
   "F-ELB-002"
  ],
  "entities": [
   "Candidacy"
  ],
  "clocks": [
   "CLK-18",
   "CLK-21"
  ],
  "suggestedVuePage": "resources/js/Pages/Elections/CandidacyRegistration.vue",
  "notes": "Real F-IND-011 form (office select scoped to associated jurisdictions, position-tag chips, residency attestation); one result card toggles between the validated path (→ approval pool, R-06) and the rejection path (residency-only ground, court appeal to ../judiciary/case-docket.html citing Pham v. Charlotte election board, case-2031-101); phase banner reacts to scenario.election per CLK-18.",
  "stage": 2
 },
 {
  "file": "electoral/candidate-profile.html",
  "title": "Candidate profile",
  "module": "electoral",
  "roles": [
   "R-03",
   "R-04",
   "R-06",
   "R-07"
  ],
  "workflows": [
   "WF-CIV-05",
   "WF-CIV-08"
  ],
  "forms": [
   "F-CAN-001",
   "F-CAN-002",
   "F-CAN-003"
  ],
  "entities": [
   "Candidacy",
   "Approval Standing"
  ],
  "clocks": [
   "CLK-21"
  ],
  "suggestedVuePage": "resources/js/Pages/Elections/CandidateProfile.vue",
  "notes": "Renders diego-ramos by default and honors ?candidate= (captured in head before demo-state strips it) plus a #candidate= hash fallback; reusable patterns: SVG endorsement graph (orgs + individuals, ledger #1), auto-attached public record (legislative votes only for incumbents), approval-standing meter with the finalist line positioned at the 21st candidate's approvals, withdraw-with-confirm locked after the finalist cutoff.",
  "stage": 2
 },
 {
  "file": "electoral/election-board-console.html",
  "title": "Election board console",
  "module": "electoral",
  "roles": [
   "R-08"
  ],
  "workflows": [
   "WF-ELE-01",
   "WF-ELE-02",
   "WF-ELE-03",
   "WF-ELE-04",
   "WF-ELE-05",
   "WF-ELE-06",
   "WF-ELE-07",
   "WF-ELE-08",
   "WF-ELE-09",
   "WF-ELE-10"
  ],
  "forms": [
   "F-ELB-001",
   "F-ELB-002",
   "F-ELB-003",
   "F-ELB-004",
   "F-ELB-005",
   "F-ELB-006"
  ],
  "entities": [
   "Election",
   "Candidacy",
   "Vacancy"
  ],
  "clocks": [
   "CLK-18",
   "CLK-21",
   "CLK-17",
   "CLK-03"
  ],
  "suggestedVuePage": "resources/js/Pages/Elections/BoardConsole.vue",
  "notes": "R-08 home rendering all six F-ELB forms as live surfaces: scheduling form with X pre-publication, simulated validate/reject queue (rejection opens the court-appeal path), district-map oversight from W.districtScenario ('draft maps published for observation' → ../jurisdictions/district-mapper.html), certify→recount gating, petition signature audit at CLK-17 threshold; bootstrap-board variant toggles a persistent 'temporary — replacement queued' banner; emergency banner asserts elections cannot be disrupted.",
  "stage": 2
 },
 {
  "file": "electoral/election-detail.html",
  "title": "Election detail",
  "module": "electoral",
  "roles": [
   "R-03",
   "R-04",
   "R-08"
  ],
  "workflows": [
   "WF-ELE-01"
  ],
  "forms": [
   "F-ELB-001",
   "F-ELB-004",
   "F-ELB-006"
  ],
  "entities": [
   "Election"
  ],
  "clocks": [
   "CLK-18",
   "CLK-21",
   "CLK-07",
   "CLK-03"
  ],
  "suggestedVuePage": "resources/js/Pages/Elections/ElectionDetail.vue",
  "notes": "One page walks the entire Election state machine: scenario.election drives approval/ranked phases and a page-local sub-state inside 'certifying' covers Tabulating → Certified → [Recount] via certify/recount buttons; schedule table with done/current/upcoming badges + timezone hint, pre-published X per race with amendable finalist-multiplier line, SVG race-boundary stand-in noting the 5–9 band (CLK-07).",
  "stage": 2
 },
 {
  "file": "electoral/open-ballot.html",
  "title": "Open ballot — approval phase",
  "module": "electoral",
  "roles": [
   "R-03",
   "R-04",
   "R-06"
  ],
  "workflows": [
   "WF-CIV-08",
   "WF-ELE-01",
   "WF-CIV-05"
  ],
  "forms": [],
  "entities": [
   "Approval Standing",
   "Candidacy",
   "Election"
  ],
  "clocks": [
   "CLK-18",
   "CLK-21"
  ],
  "suggestedVuePage": "resources/js/Pages/Elections/OpenBallot.vue",
  "notes": "FLAGSHIP. Filterable candidate marketplace; finalist line component reusable in results + candidate profile; revocable approval switch; jockeying deltas; alignment questionnaire flagged future.",
  "stage": 2
 },
 {
  "file": "electoral/ranked-ballot.html",
  "title": "Ranked ballot — F-IND-007/008",
  "module": "electoral",
  "roles": [
   "R-04"
  ],
  "workflows": [
   "WF-CIV-04",
   "WF-ELE-01"
  ],
  "forms": [
   "F-IND-007",
   "F-IND-008"
  ],
  "entities": [
   "Ballot (Ranked)"
  ],
  "clocks": [
   "CLK-21"
  ],
  "suggestedVuePage": "resources/js/Pages/Elections/RankedBallot.vue",
  "notes": "Click-to-rank (keyboard ↑/↓), always-available write-in, review→commit→receipt hash, referendum variant on the same surface, first-ballot achievement toast (Proposed).",
  "stage": 2
 },
 {
  "file": "electoral/results.html",
  "title": "Results — round-by-round STV",
  "module": "electoral",
  "roles": [
   "R-03",
   "R-04",
   "R-08"
  ],
  "workflows": [
   "WF-ELE-01",
   "WF-ELE-05"
  ],
  "forms": [
   "F-ELB-004",
   "F-ELB-006"
  ],
  "entities": [
   "Election"
  ],
  "clocks": [],
  "suggestedVuePage": "resources/js/Pages/Elections/Results.vue",
  "notes": "Embeds a REAL Gregory-method STV count (412,383 ballots, Droop quota 41,239, 27 rounds); write-in tabulated identically; certification + observer standing block; RCV individual-executive variant with top-4 advisors.",
  "stage": 2
 },
 {
  "file": "electoral/vacancy-countback.html",
  "title": "Vacancy countback",
  "module": "electoral",
  "roles": [
   "R-08"
  ],
  "workflows": [
   "WF-ELE-03",
   "WF-ELE-04"
  ],
  "forms": [
   "F-LEG-036",
   "F-ELB-004",
   "F-ELB-001"
  ],
  "entities": [
   "Vacancy"
  ],
  "clocks": [
   "CLK-04"
  ],
  "suggestedVuePage": "resources/js/Pages/Elections/VacancyCountback.vue",
  "notes": "Countback engine view for W.vacancy (Renata Silva, Charlotte seat 4): STV-bar re-run with the vacated member struck through and exhausted-ballot track; reacts to scenario.countbackFailed — found → certify branch (F-ELB-004), failed → special-election scheduler that client-validates the 90–180 day window (CLK-04) and rejects out-of-window dates; F-LEG-036 trigger form-card shows its F-LEG-030 catalog alias; W.specialElection (Plaza Midwood seat 2) rendered as the live failed-branch example; knock-on WF-LEG-13 proportionality re-check card.",
  "stage": 2
 },
 {
  "file": "legislature/bill-detail.html",
  "title": "Bill detail — Charlotte Clean Air Act",
  "module": "legislature",
  "roles": [
   "R-09",
   "R-10",
   "R-11",
   "R-12"
  ],
  "workflows": [
   "WF-LEG-06",
   "WF-LEG-07",
   "WF-LEG-08"
  ],
  "forms": [
   "F-LEG-004",
   "F-LEG-005"
  ],
  "entities": [
   "Bill"
  ],
  "clocks": [],
  "suggestedVuePage": "resources/js/Pages/Legislature/BillDetail.vue",
  "notes": "Lifecycle tracker at In-Committee; floor tile shows peg-quorum math explicitly (majority 5 of 9, supermajority ceil(9×2/3)=6 of 9) via meters with threshold ticks; bicameral view (own toggle OR scenario.bicameral) renders FOUR independent meters — both seat kinds at committee AND floor (Art. V §3) — dualMeter() helper reusable.",
  "stage": 3
 },
 {
  "file": "legislature/bills.html",
  "title": "Bills — registry & introduction",
  "module": "legislature",
  "roles": [
   "R-09",
   "R-10",
   "R-11",
   "R-12",
   "R-13"
  ],
  "workflows": [
   "WF-LEG-06",
   "WF-LEG-07",
   "WF-LEG-14"
  ],
  "forms": [
   "F-LEG-003",
   "F-LEG-028"
  ],
  "entities": [
   "Bill"
  ],
  "clocks": [],
  "suggestedVuePage": "resources/js/Pages/Legislature/Bills.vue",
  "notes": "Full 12-stage Bill lifecycle legend from registry entity; registry = W.bills fixture + 4 page-local bills in varied states incl. Curfew Ordinance at [Challenged] linking the Art. IV §5 tracker; F-LEG-003 with real scale (jurisdictions bound) and scope (judiciary level) selects; act-type/threshold table with F-LEG-028 dual-supermajority card.",
  "stage": 3
 },
 {
  "file": "legislature/committee-detail.html",
  "title": "Committee detail — Environment & Infrastructure",
  "module": "legislature",
  "roles": [
   "R-11",
   "R-12",
   "R-13"
  ],
  "workflows": [
   "WF-LEG-08",
   "WF-LEG-13",
   "WF-LEG-06"
  ],
  "forms": [
   "F-CHR-001",
   "F-CHR-002",
   "F-CHR-003",
   "F-CHR-004",
   "F-LEG-005"
  ],
  "entities": [
   "Bill",
   "Committee Seat"
  ],
  "clocks": [],
  "suggestedVuePage": "resources/js/Pages/Legislature/CommitteeDetail.vue",
  "notes": "R-12 entry point. Full hearing surface: call → agenda → testimony-to-public-record → interactive F-LEG-005 vote (majority of ALL members 2 of 3, referral button gated on passage) → F-CHR-003 referral → F-CHR-004 report. F-COM catalog aliases shown on all four F-CHR form-cards.",
  "stage": 3
 },
 {
  "file": "legislature/committees.html",
  "title": "Committees — assignment & chairs",
  "module": "legislature",
  "roles": [
   "R-09",
   "R-10",
   "R-11",
   "R-13"
  ],
  "workflows": [
   "WF-LEG-03",
   "WF-LEG-04",
   "WF-LEG-13"
  ],
  "forms": [
   "F-LEG-009",
   "F-LEG-010",
   "F-SPK-005",
   "F-LEG-011"
  ],
  "entities": [
   "Committee Seat"
  ],
  "clocks": [],
  "suggestedVuePage": "resources/js/Pages/Legislature/Committees.vue",
  "notes": "R-11/R-13 entry point. Allocation card with the 9 ÷ (3×3) = 1 formula; F-LEG-010 keyboard-operable rank list (arrow buttons, no drag); tie-break table renders W.chamber voteShareNorm values (Chen 1.08 beats Okonkwo 0.99) cited 'Art. II §4 · as implemented' → ledger #q2; result table shows endorsing orgs or 'no endorsements' per member.",
  "stage": 3
 },
 {
  "file": "legislature/emergency-powers.html",
  "title": "Emergency powers",
  "module": "legislature",
  "roles": [
   "R-09",
   "R-10"
  ],
  "workflows": [
   "WF-LEG-11",
   "WF-JUD-06",
   "WF-LEG-05"
  ],
  "forms": [
   "F-LEG-024",
   "F-LEG-025",
   "F-JDG-007"
  ],
  "entities": [
   "Emergency Powers"
  ],
  "clocks": [
   "CLK-03"
  ],
  "suggestedVuePage": "resources/js/Pages/Legislature/EmergencyPowers.vue",
  "notes": "F-LEG-024 cause select has exactly 2 options (natural disaster / actual invasion) with live engine validation — duration > 90 shows a field-error 'rejected pre-vote · CLK-03'; W.emergency dashboard (day 41/90 countdown meter, civic-process-protection banner, auto-expiry 2031-07-31) is scenario.emergency-reactive with an honest empty state; F-JDG-007 review pending.",
  "stage": 3
 },
 {
  "file": "legislature/legislature-home.html",
  "title": "Chamber — Charlotte legislature",
  "module": "legislature",
  "roles": [
   "R-09",
   "R-10",
   "R-11",
   "R-12",
   "R-13",
   "R-29"
  ],
  "workflows": [
   "WF-LEG-01",
   "WF-LEG-02",
   "WF-LEG-12"
  ],
  "forms": [
   "F-LEG-001",
   "F-LEG-008",
   "F-LEG-032",
   "F-LEG-033",
   "F-LEG-013",
   "F-LEG-012",
   "F-LEG-009"
  ],
  "entities": [
   "Vacancy",
   "Committee Seat"
  ],
  "clocks": [
   "CLK-01",
   "CLK-10"
  ],
  "suggestedVuePage": "resources/js/Pages/Legislature/Home.vue",
  "notes": "R-09 entry point. Roster with multi-org + endorsement-less members (no faction layer), seat-map with speaker ring + vacant seat 4, term-lockstep countdown vs fixed demo today (2031-06-11), WF-LEG-01 checklist rendered as numbered full form-cards — formCard(fid) helper pattern reusable across the module.",
  "stage": 3
 },
 {
  "file": "legislature/oversight.html",
  "title": "Oversight & ethics — admin office",
  "module": "legislature",
  "roles": [
   "R-29",
   "R-09",
   "R-10"
  ],
  "workflows": [
   "WF-LEG-16",
   "WF-LEG-17",
   "WF-LEG-12"
  ],
  "forms": [
   "F-LEG-022",
   "F-LEG-036"
  ],
  "entities": [
   "Vacancy"
  ],
  "clocks": [
   "CLK-04"
  ],
  "suggestedVuePage": "resources/js/Pages/Legislature/Oversight.vue",
  "notes": "R-29 entry point (halima-diallo). Interactive misconduct intake docket (WF-LEG-16); F-LEG-022 proceeding with supermajority meter against ALL serving (ceil(8×2/3)=6 of 8) + removal-parity note + Speaker except-own-case chip; W.vacancy card with Vacancy state strip that flips to the special-election branch on scenario.countbackFailed; handoff link to electoral/vacancy-countback.html.",
  "stage": 3
 },
 {
  "file": "legislature/referendums.html",
  "title": "Referendums — delegation & protection",
  "module": "legislature",
  "roles": [
   "R-09",
   "R-10"
  ],
  "workflows": [
   "WF-LEG-10",
   "WF-LEG-19",
   "WF-ELE-07"
  ],
  "forms": [
   "F-LEG-023",
   "F-LEG-034"
  ],
  "entities": [
   "Referendum Question"
  ],
  "clocks": [
   "CLK-19"
  ],
  "suggestedVuePage": "resources/js/Pages/Legislature/Referendums.vue",
  "notes": "Act-type select drives a read-only derived threshold display (threshold fixed to act type, never editable); participatory-budget question queued to next jurisdiction-wide ballot; results meters at matching thresholds; CLK-19 protection chip on the population-supermajority act with the F-LEG-034 gate disabled this term and conversion-to-ordinary-law note.",
  "stage": 3
 },
 {
  "file": "legislature/session-console.html",
  "title": "Session console",
  "module": "legislature",
  "roles": [
   "R-09",
   "R-10"
  ],
  "workflows": [
   "WF-LEG-05",
   "WF-LEG-09",
   "WF-LEG-20",
   "WF-SYS-02"
  ],
  "forms": [
   "F-SPK-001",
   "F-SPK-002",
   "F-SPK-003",
   "F-SPK-008",
   "F-SPK-009",
   "F-LEG-002",
   "F-LEG-004",
   "F-LEG-006",
   "F-LEG-007"
  ],
  "entities": [
   "Motion",
   "Emergency Powers"
  ],
  "clocks": [
   "CLK-02",
   "CLK-03"
  ],
  "suggestedVuePage": "resources/js/Pages/Legislature/SessionConsole.vue",
  "notes": "Locked constitutional agenda order (emergency powers → constitutional matters → general) with hardened chip; scenario.quorumFails pins attendance to 4, flips quorum banner to danger and surfaces F-SPK-008 compulsion; scenario.emergency/challenge populate agenda items 1–2; session-due banner (WF-SYS-02); peg-quorum meter with explicit denominators.",
  "stage": 3
 },
 {
  "file": "legislature/settings.html",
  "title": "Constitutional settings register",
  "module": "legislature",
  "roles": [
   "R-09",
   "R-10"
  ],
  "workflows": [
   "WF-LEG-14",
   "WF-LEG-15"
  ],
  "forms": [
   "F-LEG-031",
   "F-LEG-032",
   "F-LEG-033"
  ],
  "entities": [
   "Bill"
  ],
  "clocks": [
   "CLK-01",
   "CLK-02",
   "CLK-03",
   "CLK-04",
   "CLK-05",
   "CLK-07",
   "CLK-08",
   "CLK-09",
   "CLK-13",
   "CLK-14",
   "CLK-17"
  ],
  "suggestedVuePage": "resources/js/Pages/Legislature/Settings.vue",
  "notes": "All 17 amendable keys from the CLAUDE.md table (both supermajority components as rows), each with current value, hardened bounds, enacting act + effective date in the .amendable pattern (civil/judicial years in lockstep, set by the same act); 'Propose change' opens an inline pre-targeted bill panel whose numeric validation demonstrates the out-of-range blocked-pre-vote banner (try legislature_max_seats=12).",
  "stage": 3
 },
 {
  "file": "legislature/speaker-tools.html",
  "title": "Speaker tools",
  "module": "legislature",
  "roles": [
   "R-10"
  ],
  "workflows": [
   "WF-LEG-02",
   "WF-LEG-05",
   "WF-LEG-17",
   "WF-LEG-20"
  ],
  "forms": [
   "F-SPK-001",
   "F-SPK-002",
   "F-SPK-003",
   "F-SPK-004",
   "F-SPK-005",
   "F-SPK-006",
   "F-SPK-007",
   "F-SPK-008",
   "F-SPK-009"
  ],
  "entities": [
   "Motion"
  ],
  "clocks": [],
  "suggestedVuePage": "resources/js/Pages/Legislature/SpeakerTools.vue",
  "notes": "R-10 entry point. All nine F-SPK forms as a form-card grid with per-form context links; 'politically neutral · votes only to break ties · Art. II §3' framing with hardened chip; tie-break log row (F-SPK-004); F-SPK-006 interactive member-priority queue; F-SPK-007 presiding panel with except-own-case rule.",
  "stage": 3
 },
 {
  "file": "executive/department-detail.html",
  "title": "Public Works & Utilities — department detail",
  "module": "executive",
  "roles": [
   "R-14",
   "R-15",
   "R-16",
   "R-18",
   "R-30"
  ],
  "workflows": [
   "WF-EXE-04",
   "WF-EXE-05",
   "WF-EXE-06"
  ],
  "forms": [
   "F-EXE-001",
   "F-EXE-003"
  ],
  "entities": [
   "Department / Board"
  ],
  "clocks": [
   "CLK-09",
   "CLK-13",
   "CLK-14"
  ],
  "suggestedVuePage": "resources/js/Pages/Executive/DepartmentDetail.vue",
  "notes": "Deep-dive pattern: live Department/Board state strip that shifts to [Member Removal Requested when removal is simulated; 11-member roster (7 governors incl. joint-elected chair Samuel Adeyemi + 4 worker-elected, terms split 10-yr vs legislative-term); oversight line to Mecklenburg Water & Power CGC; F-EXE-001 dossier and F-EXE-003 removal cards with simulated state transitions.",
  "stage": 4
 },
 {
  "file": "executive/department-reporting.html",
  "title": "Department reporting — R-18 governor surface",
  "module": "executive",
  "roles": [
   "R-18"
  ],
  "workflows": [
   "WF-EXE-09"
  ],
  "forms": [
   "F-BOG-001",
   "F-BOG-002"
  ],
  "entities": [
   "Department / Board"
  ],
  "clocks": [
   "CLK-09",
   "CLK-03"
  ],
  "suggestedVuePage": "resources/js/Pages/Executive/DepartmentReporting.vue",
  "notes": "Rule register with enabling-act links (bill-detail, emergency-powers) and in-force/draft/superseded badges; report table with due dates + timezone hint and inline file-report simulation; F-GOV-001/002 catalog aliases rendered on both form cards straight from fixtures aliases; appointment card repeats the 10-yr CLK-09 term with .amendable pattern.",
  "stage": 4
 },
 {
  "file": "executive/departments.html",
  "title": "Department registry — Charlotte",
  "module": "executive",
  "roles": [
   "R-14",
   "R-15",
   "R-16",
   "R-30"
  ],
  "workflows": [
   "WF-EXE-04",
   "WF-EXE-05",
   "WF-EXE-06"
  ],
  "forms": [
   "F-LEG-016",
   "F-EXE-001",
   "F-LEG-020",
   "F-EXE-003"
  ],
  "entities": [
   "Department / Board"
  ],
  "clocks": [
   "CLK-09",
   "CLK-13",
   "CLK-14"
  ],
  "suggestedVuePage": "resources/js/Pages/Executive/Departments.vue",
  "notes": "Registry table from W.departments with per-row co-determination check (Treasury 152→1 seat, Public Works 1240→4 seats · CLK-13/14); F-LEG-016 creation form with constitutional type select incl. Defense/State; BOG pipeline stepper (F-EXE-001 → F-LEG-020 → seated R-18) with seated + pending dossiers; R-30 civil-officer card (grace-mwangi) with .amendable term pattern.",
  "stage": 4
 },
 {
  "file": "executive/executive-actions.html",
  "title": "Executive actions — orders, proposals, investigations",
  "module": "executive",
  "roles": [
   "R-14",
   "R-15",
   "R-16"
  ],
  "workflows": [
   "WF-EXE-07",
   "WF-EXE-08"
  ],
  "forms": [
   "F-EXE-005",
   "F-EXE-002",
   "F-EXE-004"
  ],
  "entities": [
   "Executive Office"
  ],
  "clocks": [
   "CLK-03"
  ],
  "suggestedVuePage": "resources/js/Pages/Executive/Actions.vue",
  "notes": "Order composer with demo scope select rendering BOTH validation states — in-scope → issued (with 'judicially reviewable · Art. IV §5' chip) and out-of-scope → rejected pre-issuance (log-row--rejected, dual citation Art. III §2 + Art. II §7); pre-seeded register includes a rejected election-deferral attempt; emergency scenario banner narrows the framing.",
  "stage": 4
 },
 {
  "file": "executive/executive-home.html",
  "title": "Executive home — three model variants",
  "module": "executive",
  "roles": [
   "R-14",
   "R-15",
   "R-16",
   "R-17"
  ],
  "workflows": [
   "WF-EXE-01",
   "WF-EXE-02",
   "WF-EXE-03"
  ],
  "forms": [
   "F-LEG-014",
   "F-LEG-015"
  ],
  "entities": [
   "Executive Office"
  ],
  "clocks": [
   "CLK-10",
   "CLK-03"
  ],
  "suggestedVuePage": "resources/js/Pages/Executive/Home.vue",
  "notes": "Tab-style aria-pressed model toggle (delegated live in Charlotte / elected committee illustrative / elected individual live in Mecklenburg with ranked R-17 advisors + step-in rule); dual supermajority meters with .meter-threshold marks for F-LEG-015; Executive Office state strip highlights live state per model.",
  "stage": 4
 },
 {
  "file": "organizations/board-elections.html",
  "title": "Board elections",
  "module": "organizations",
  "roles": [
   "R-23",
   "R-24",
   "R-25",
   "R-26",
   "R-27",
   "R-28"
  ],
  "workflows": [
   "WF-ORG-05",
   "WF-ORG-04"
  ],
  "forms": [
   "F-ORG-003",
   "F-ORG-004"
  ],
  "entities": [
   "Organization"
  ],
  "clocks": [
   "CLK-13",
   "CLK-14"
  ],
  "suggestedVuePage": "resources/js/Pages/Organizations/BoardElections.vue",
  "notes": "Three counts on one surface: owner-track STV (12 cands, 9 seats, Droop 121), worker-track STV (5 cands, 3 seats, Droop 174, vote totals sum to 692 ballots), joint chair RCV by the full 12-member board as a two-round stepper (Tehrani 5→7 of 12) using the stv-cand component; Brandt/Ferreira/Tehrani persona chips seat R-26/R-27/R-28.",
  "stage": 4
 },
 {
  "file": "organizations/cgc-detail.html",
  "title": "Common Good Corporation — Mecklenburg Water & Power",
  "module": "organizations",
  "roles": [
   "R-09",
   "R-18",
   "R-25",
   "R-27"
  ],
  "workflows": [
   "WF-ORG-08",
   "WF-ORG-09",
   "WF-ORG-04"
  ],
  "forms": [
   "F-LEG-019"
  ],
  "entities": [
   "Organization"
  ],
  "clocks": [
   "CLK-13",
   "CLK-14"
  ],
  "suggestedVuePage": "resources/js/Pages/Organizations/CgcDetail.vue",
  "notes": "Dual hardened chips (regulated identically to private peers / IP perpetually public domain · Art. III §5); executive oversight line links Public Works & Utilities department-detail; co-determination state computed from the canonical formula (1,450 → 5 worker seats beside 7 governors); public-domain IP register table with per-asset badges.",
  "stage": 4
 },
 {
  "file": "organizations/co-determination.html",
  "title": "Co-determination scaling",
  "module": "organizations",
  "roles": [
   "R-25",
   "R-27",
   "R-23"
  ],
  "workflows": [
   "WF-ORG-04",
   "WF-ORG-05"
  ],
  "forms": [
   "F-ORG-004"
  ],
  "entities": [
   "Organization"
  ],
  "clocks": [
   "CLK-13",
   "CLK-14"
  ],
  "suggestedVuePage": "resources/js/Pages/Organizations/CoDetermination.vue",
  "notes": "Keyboard-operable range slider drives the scaling meter with live formula block — worker_seats = max(1, round((W−100)÷1900×owner_seats)) → Bluefin 740 = 3 of 9, labeled 'uniform interpolation · as implemented (CGA spec)'; applies-equally table spans private orgs, the CGC, and executive departments (matches dep fixtures: 1240→4/7, 152→1/5); CLK-13/14 rendered as amendable cards.",
  "stage": 4
 },
 {
  "file": "organizations/org-detail.html",
  "title": "Organization profile",
  "module": "organizations",
  "roles": [
   "R-23",
   "R-24",
   "R-06",
   "R-07"
  ],
  "workflows": [
   "WF-ORG-02",
   "WF-ORG-03"
  ],
  "forms": [
   "F-ORG-001",
   "F-CAN-002",
   "F-ORG-002",
   "F-IND-013",
   "F-IND-014"
  ],
  "entities": [
   "Organization"
  ],
  "clocks": [
   "CLK-13",
   "CLK-14"
  ],
  "suggestedVuePage": "resources/js/Pages/Organizations/Detail.vue",
  "notes": "Tab-switch pattern (Commons Party party-tab vs Bluefin business-tab, same entity type); endorsement panel pairs F-CAN-002 request queue with F-ORG-002 grant action conferring R-07 (ledger #1 cited); endorsed candidates pulled live from W.candidates; join forms F-IND-013/014 with simulated confirmations.",
  "stage": 4
 },
 {
  "file": "organizations/org-registry.html",
  "title": "Organization registry",
  "module": "organizations",
  "roles": [
   "R-03",
   "R-23"
  ],
  "workflows": [
   "WF-ORG-01"
  ],
  "forms": [
   "F-IND-012",
   "F-ORG-001"
  ],
  "entities": [
   "Organization"
  ],
  "clocks": [
   "CLK-13",
   "CLK-14"
  ],
  "suggestedVuePage": "resources/js/Pages/Organizations/Registry.vue",
  "notes": "Filterable public registry of all 9 fixture orgs (type/structure/workers/endorsement counts + co-determination badge); interactive F-IND-012 registration with the six-structure type select; no-faction banner cites ledger #1; Organization state strip.",
  "stage": 4
 },
 {
  "file": "organizations/transfers-conversions.html",
  "title": "Transfers and conversions",
  "module": "organizations",
  "roles": [
   "R-23",
   "R-24",
   "R-09"
  ],
  "workflows": [
   "WF-ORG-06",
   "WF-ORG-07",
   "WF-ORG-09",
   "WF-ORG-10"
  ],
  "forms": [
   "F-ORG-005",
   "F-LEG-026",
   "F-ORG-006",
   "F-LEG-027",
   "F-ORG-007"
  ],
  "entities": [
   "Organization"
  ],
  "clocks": [],
  "suggestedVuePage": "resources/js/Pages/Organizations/TransfersConversions.vue",
  "notes": "F-ORG-005 form blocks submission unless both consent checkboxes are set ('mutual consent is mandatory'); Cobalt Grid monopoly path as a 5-stage lifecycle with peg-quorum vote meter (7 of 9 serving, threshold 5) and hardened ≥-fair-market compensation chip; public↔private pair F-ORG-006/F-LEG-027 with the irreversible public-domain-IP warning banner; dissolution confirm.",
  "stage": 4
 },
 {
  "file": "judiciary/advocate-console.html",
  "title": "Advocate console",
  "module": "judiciary",
  "roles": [
   "R-21"
  ],
  "workflows": [
   "WF-CIV-07",
   "WF-JUD-03"
  ],
  "forms": [
   "F-IND-015",
   "F-ADV-001",
   "F-ADV-002",
   "F-ADV-003",
   "F-ADV-004"
  ],
  "entities": [
   "Case"
  ],
  "clocks": [],
  "suggestedVuePage": "resources/js/Pages/Judiciary/AdvocateConsole.vue",
  "notes": "Single filing composer covering F-ADV-001…004 with type-adaptive fields (case selector vs client field) and a log-row outbox; active case list filtered by filedVia === 'F-ADV-001'; F-IND-015 granted status card.",
  "stage": 5
 },
 {
  "file": "judiciary/case-detail.html",
  "title": "Case detail — State v. Whitfield (playable lifecycle)",
  "module": "judiciary",
  "roles": [
   "R-19",
   "R-20",
   "R-21",
   "R-22"
  ],
  "workflows": [
   "WF-JUD-03",
   "WF-JUD-04"
  ],
  "forms": [
   "F-IND-017",
   "F-JDG-001",
   "F-ADV-002",
   "F-ADV-003",
   "F-JDG-002",
   "F-JDG-003",
   "F-JDG-009",
   "F-JDG-010"
  ],
  "entities": [
   "Case"
  ],
  "clocks": [
   "CLK-16"
  ],
  "suggestedVuePage": "resources/js/Pages/Judiciary/CaseDetail.vue",
  "notes": "10-stage playable sequence (Back/Advance + passive .lifecycle track) mapped onto the Case entity strip; opens at the fixture's live stage (jury selection); double-jeopardy banner at judgement; conflict-screening table at panel assignment.",
  "stage": 5
 },
 {
  "file": "judiciary/case-docket.html",
  "title": "Case docket — Mecklenburg County & Charlotte courts",
  "module": "judiciary",
  "roles": [
   "R-19",
   "R-20",
   "R-21",
   "R-03"
  ],
  "workflows": [
   "WF-JUD-03"
  ],
  "forms": [
   "F-IND-016",
   "F-IND-017",
   "F-ADV-001",
   "F-JDG-001"
  ],
  "entities": [
   "Case"
  ],
  "clocks": [
   "CLK-16"
  ],
  "suggestedVuePage": "resources/js/Pages/Judiciary/CaseDocket.vue",
  "notes": "Filterable docket over W.cases; simulated F-IND-017 filing with claimed scale & severity appends rows page-locally; reusable formCard(fid) helper renders name/ID/available-to/basis/aliases.",
  "stage": 5
 },
 {
  "file": "judiciary/constitutional-challenge.html",
  "title": "Constitutional challenge tracker — Art. IV §5",
  "module": "judiciary",
  "roles": [
   "R-03",
   "R-09",
   "R-19",
   "R-20"
  ],
  "workflows": [
   "WF-JUD-05"
  ],
  "forms": [
   "F-IND-016",
   "F-JDG-004",
   "F-JDG-005",
   "F-JDG-006",
   "F-LEG-035"
  ],
  "entities": [
   "Constitutional Challenge"
  ],
  "clocks": [
   "CLK-11",
   "CLK-12",
   "CLK-16"
  ],
  "suggestedVuePage": "resources/js/Pages/Judiciary/ConstitutionalChallenge.vue",
  "notes": "Three Art. IV §5 paths as side-by-side cards with mutually exclusive page-local resolution state (override meter 6 of 9, window-close .law-diff with del/ins); reacts to scenario.challenge with an empty state + drill button that sets the flag.",
  "stage": 5
 },
 {
  "file": "judiciary/judiciary-home.html",
  "title": "Judiciary home — creation, confirmation & conversion",
  "module": "judiciary",
  "roles": [
   "R-19",
   "R-20"
  ],
  "workflows": [
   "WF-JUD-01",
   "WF-JUD-02"
  ],
  "forms": [
   "F-LEG-017",
   "F-LEG-018",
   "F-LEG-021"
  ],
  "entities": [],
  "clocks": [
   "CLK-03",
   "CLK-09",
   "CLK-10",
   "CLK-15",
   "CLK-16"
  ],
  "suggestedVuePage": "resources/js/Pages/Judiciary/Home.vue",
  "notes": "Dual-supermajority meter pattern (legislature + constituent jurisdictions) with simulate buttons; static 7-nominee consent-vote table shows equal-numbers nominations, judicial-committee fallback, and two failed confirmations.",
  "stage": 5
 },
 {
  "file": "judiciary/juror-view.html",
  "title": "Juror view — summons & service protections",
  "module": "judiciary",
  "roles": [
   "R-22"
  ],
  "workflows": [
   "WF-JUD-04"
  ],
  "forms": [
   "F-JDG-002"
  ],
  "entities": [
   "Case"
  ],
  "clocks": [],
  "suggestedVuePage": "resources/js/Pages/Judiciary/JurorView.vue",
  "notes": "Radio-group conflict-screening questionnaire with flagged/clear inline outcomes; protections card cites the exact Art. II §8 subsection labels; locked deliberation-room entry tied to the case's current stage.",
  "stage": 5
 },
 {
  "file": "civic/learn.html",
  "title": "Learn",
  "module": "civic",
  "roles": [
   "R-01"
  ],
  "workflows": [],
  "forms": [],
  "entities": [],
  "clocks": [],
  "suggestedVuePage": "resources/js/Pages/Civic/Learn.vue",
  "notes": "Brand register (modest launchpad-hero strip, purple+gold); 12 Topic_Knowledge course cards with question counts (max(minutes/5,3) rounded) + video length + external https links with external-link icon; 'learning is never a gate · Art. I' banner; suggested-path stepper; gamification card flagged Proposed.",
  "stage": 6
 },
 {
  "file": "jurisdictions/bootstrap.html",
  "title": "Jurisdiction bootstrap",
  "module": "jurisdictions",
  "roles": [
   "R-01",
   "R-03",
   "R-08"
  ],
  "workflows": [
   "WF-JUR-01",
   "WF-ELE-02"
  ],
  "forms": [],
  "entities": [
   "Jurisdiction"
  ],
  "clocks": [
   "CLK-06"
  ],
  "suggestedVuePage": "resources/js/Pages/Jurisdictions/Bootstrap.vue",
  "notes": "30-step registry.bootstrap rendered as a 7-stage grouped tracker (Charlotte at step 19; per-stage Complete/In progress/Pending badges, per-step form chips parsed from the forms string); temporary bootstrap-board warning banner; CLK-06 critical-population meter with .amendable value/range/act line; Jurisdiction state strip with Bootstrapping highlighted.",
  "stage": 6
 },
 {
  "file": "jurisdictions/disintermediation.html",
  "title": "Disintermediation",
  "module": "jurisdictions",
  "roles": [
   "R-09"
  ],
  "workflows": [
   "WF-JUR-04"
  ],
  "forms": [
   "F-LEG-030"
  ],
  "entities": [
   "Jurisdiction"
  ],
  "clocks": [],
  "suggestedVuePage": "resources/js/Pages/Jurisdictions/Disintermediation.vue",
  "notes": "Worked example dissolving Mecklenburg County (7 real municipalities): unanimity meter (5 of 7, threshold mark at 100%) + encompassing-consent meter; law-merge conflict table with per-row resolution selects (incorporate/defer/lapse) and a 214-acts-clean row; topology-after strip with struck-through intermediary chip; F-LEG-030 form card with the catalog-alias confusion note rendered live from F-LEG-036's fixture aliases. Cites Art. V §8.",
  "stage": 6
 },
 {
  "file": "jurisdictions/district-mapper.html",
  "title": "District mapper",
  "module": "jurisdictions",
  "roles": [
   "R-03",
   "R-08"
  ],
  "workflows": [
   "WF-ELE-06",
   "WF-JUR-09"
  ],
  "forms": [
   "F-ELB-003"
  ],
  "entities": [
   "Jurisdiction"
  ],
  "clocks": [
   "CLK-07",
   "CLK-08"
  ],
  "suggestedVuePage": "resources/js/Pages/Jurisdictions/DistrictMapper.vue",
  "notes": "Mirrors the shipped Legislature browser vocabulary: versioned maps (Activate flips draft→active and archives the old plan, page-locally), cube-root budget stats (692/22/474,517) citing ledger #q3, Webster district tables with contiguity/integrity/CHR/deviation badges, expandable giant (NC 21.79) + leaf giant Fujian 'requires manual line-drawing' citing ledger #q4, optimal/suboptimal/current grouping stats with floor-override gloss, accept-maps form (F-ELB-003 card + simulated submit) under election-board observation; per-plan SVG stand-in.",
  "stage": 6
 },
 {
  "file": "jurisdictions/federation.html",
  "title": "Federation",
  "module": "jurisdictions",
  "roles": [
   "R-09",
   "R-04"
  ],
  "workflows": [
   "WF-JUR-05",
   "WF-JUR-06",
   "WF-JUR-08"
  ],
  "forms": [],
  "entities": [
   "Federation Peer",
   "Jurisdiction"
  ],
  "clocks": [
   "CLK-20"
  ],
  "suggestedVuePage": "resources/js/Pages/Jurisdictions/Federation.vue",
  "notes": "Peer table (charlotte.cga.example authoritative + 2 fictional peers in Syncing/Handshake states) with Federation Peer state strip; authority-claims table incl. the dual-most-encompassing WF-JUR-05 trigger case; full faith & credit sync log (.log-row with data-no-i18n hashes, 'authoritative instance wins' conflict entry, one rejected-write row); 3-step migration stepper (partition export → authority flip → re-peer) with advance/reset; border-settlement table at 2/3 of affected population (Art. V §2).",
  "stage": 6
 },
 {
  "file": "jurisdictions/jurisdiction-browser.html",
  "title": "Jurisdiction browser",
  "module": "jurisdictions",
  "roles": [
   "R-01",
   "R-03"
  ],
  "workflows": [
   "WF-JUR-09"
  ],
  "forms": [],
  "entities": [
   "Jurisdiction"
  ],
  "clocks": [
   "CLK-06"
  ],
  "suggestedVuePage": "resources/js/Pages/Jurisdictions/Browser.vue",
  "notes": "Drill-down with clickable ancestor-chip breadcrumb + dynamic Wong-fill children SVG; profile card (adm chip, WorldPop year + live-census note, provenance, civic-active, languages/timezone, authoritative_server NULL line) re-renders per selection and follows the demo-bar jurisdiction; powers table (joint vs reserved · Art. V §4–5); San Marino honest-gap warning banner renders from the fixture dataGap field.",
  "stage": 6
 },
 {
  "file": "jurisdictions/restoration.html",
  "title": "Constitutional restoration",
  "module": "jurisdictions",
  "roles": [
   "R-03",
   "R-09"
  ],
  "workflows": [
   "WF-JUR-07"
  ],
  "forms": [],
  "entities": [
   "Jurisdiction"
  ],
  "clocks": [],
  "suggestedVuePage": "resources/js/Pages/Jurisdictions/Restoration.vue",
  "notes": "Fully reactive to scenario.restoration: dormant info banner vs calm banner--emergency drill (condition 'captured', tier 1) from W.restorationDrill; three activation-condition cards with per-condition met/not-detected badges; vertical tier cascade stepper (constituents → encompassing → individuals) with active/bypassed/standby states; legitimacy-scoring card (3 criteria) + defensive-forces note; Jurisdiction state strip highlighting [Restoration-Mode] only while armed.",
  "stage": 6
 },
 {
  "file": "jurisdictions/union-formation.html",
  "title": "Union formation",
  "module": "jurisdictions",
  "roles": [
   "R-09",
   "R-04"
  ],
  "workflows": [
   "WF-JUR-02",
   "WF-JUR-03"
  ],
  "forms": [
   "F-LEG-029"
  ],
  "entities": [
   "Jurisdiction"
  ],
  "clocks": [],
  "suggestedVuePage": "resources/js/Pages/Jurisdictions/UnionFormation.vue",
  "notes": "banner--demo edge-case badge from W.unionDrill; compatibility diff table (amendable variables + institutional alignment, hardened rows marked identical-by-construction); radio-based codification workspace; ratification meters with explicit whole-population denominators that react to scenario.unionDrill (dormant vs in-progress); join/exit mirror card; bicameral type_a/type_b preview (192 cube-root seats Webster-split + 1 equal seat per constituent); F-LEG-029 form card + simulated founding-act submit.",
  "stage": 6
 },
 {
  "file": "shared/clocks.html",
  "title": "Clocks & triggers",
  "module": "shared",
  "roles": [],
  "workflows": [
   "WF-SYS-01",
   "WF-SYS-02"
  ],
  "forms": [],
  "entities": [],
  "clocks": [
   "CLK-01",
   "CLK-02",
   "CLK-03",
   "CLK-04",
   "CLK-05",
   "CLK-06",
   "CLK-07",
   "CLK-08",
   "CLK-09",
   "CLK-10",
   "CLK-11",
   "CLK-12",
   "CLK-13",
   "CLK-14",
   "CLK-15",
   "CLK-16",
   "CLK-17",
   "CLK-18",
   "CLK-19",
   "CLK-20",
   "CLK-21"
  ],
  "suggestedVuePage": "resources/js/Pages/System/Clocks.vue",
  "notes": "Renders all 21 registry.clocks grouped into 4 scheduler families (raw type kept per row); amendable column as badges (lock icon for hardened/structural); WF ids in 'fires' auto-linked to ../flows/ pages via regex; explicit 'this page doubles as the scheduler spec' banner.",
  "stage": 6
 },
 {
  "file": "shared/constitutional-questions.html",
  "title": "Constitutional questions — the implementation ledger",
  "module": "shared",
  "roles": [],
  "workflows": [
   "WF-SYS-05"
  ],
  "forms": [],
  "entities": [],
  "clocks": [],
  "suggestedVuePage": "resources/js/Pages/System/ConstitutionalQuestions.vue",
  "notes": "UPDATES the existing Stage-0 record: stub banner replaced with 'Maintained ledger' note; each entry (#q1–#q5 anchors preserved) gained a 'Where to see it' surface-link paragraph; added closing 'How an entry lands here' section with status legend and amendments cross-link.",
  "stage": 6
 },
 {
  "file": "shared/accessibility.html",
  "title": "Accessibility statement",
  "module": "shared",
  "roles": [],
  "workflows": [],
  "forms": [],
  "entities": [],
  "clocks": [],
  "suggestedVuePage": "resources/js/Pages/Shared/Accessibility.vue",
  "notes": "Accessibility statement (WCAG 2.2 AA + selected AAA target, EN 301 549) linked from every footer — what is built in, known limitations, feedback path. The production build inherits this contract and republishes the statement per supported locale.",
  "stage": 7
 },
 {
  "file": "system/amendments.html",
  "title": "Amendments",
  "module": "system",
  "roles": [
   "R-09"
  ],
  "workflows": [
   "WF-SYS-05"
  ],
  "forms": [],
  "entities": [
   "Jurisdiction"
  ],
  "clocks": [
   "CLK-01",
   "CLK-03"
  ],
  "suggestedVuePage": "resources/js/Pages/System/Amendments.vue",
  "notes": "Two-door grid (amendable variables via F-LEG-031 → links legislature/settings.html vs hardened code-release path); supermajority floor meter (9 serving → 6, floor majority+1); proportionality ratchet with ledger #1 marker; interactive 'try a proposed value' bounds-checker mirroring pre-vote engine validation, rejecting to audit chain.",
  "stage": 6
 },
 {
  "file": "system/audit-chain.html",
  "title": "Audit chain",
  "module": "system",
  "roles": [],
  "workflows": [
   "WF-SYS-04"
  ],
  "forms": [],
  "entities": [
   "Jurisdiction",
   "Election"
  ],
  "clocks": [
   "CLK-20"
  ],
  "suggestedVuePage": "resources/js/Pages/System/AuditChain.vue",
  "notes": "10 chained entries (84,104–84,113 continuing instance auditSeq) with prev→this pseudo-hashes (data-no-i18n); one .log-row--rejected (max_seats 9→12 blocked: exceeds hardened ceiling 9 · Art. II §2, rejection itself appended); verify-chain button → simulated success via check icon.",
  "stage": 6
 },
 {
  "file": "system/public-records.html",
  "title": "Public records",
  "module": "system",
  "roles": [
   "R-03",
   "R-09"
  ],
  "workflows": [
   "WF-SYS-03"
  ],
  "forms": [
   "F-LEG-006"
  ],
  "entities": [
   "Bill",
   "Case",
   "Election"
  ],
  "clocks": [
   "CLK-20"
  ],
  "suggestedVuePage": "resources/js/Pages/System/PublicRecords.vue",
  "notes": "log-row list with per-record translation badge (translated N/5 vs pending) + client-side kind/search filter; F-LEG-006 rendered as form-card AND working simulated submit that appends entry #84,114; append-only banner links audit chain.",
  "stage": 6
 },
 {
  "file": "system/term-sync.html",
  "title": "Term lockstep",
  "module": "system",
  "roles": [],
  "workflows": [
   "WF-SYS-01"
  ],
  "forms": [],
  "entities": [
   "Election"
  ],
  "clocks": [
   "CLK-01",
   "CLK-10",
   "CLK-03",
   "CLK-04",
   "CLK-09"
  ],
  "suggestedVuePage": "resources/js/Pages/System/TermSync.vue",
  "notes": "Inline SVG lockstep timeline (2030→2036): three elected-branch bars ending together at 2035-11-01 plus dashed 10-year appointed-clock contrast bar; hardened no-skip chip; 1,604-day countdown vs demo date 2031-06-11; vacancies-never-reset-the-clock card; emergency scenario reactive.",
  "stage": 6
 },
 {
  "file": "system/setup-wizard.html",
  "title": "Instance setup — the founding loop",
  "module": "system",
  "roles": [],
  "workflows": [
   "WF-JUR-01"
  ],
  "forms": [],
  "entities": [
   "Jurisdiction"
  ],
  "clocks": [
   "CLK-01",
   "CLK-05",
   "CLK-06"
  ],
  "suggestedVuePage": "resources/js/Pages/Setup/* (SHIPPED — Steps 0–4 exist; they adopt this design system)",
  "notes": "Placement contract for the developed setup wizard: Step 0 cosmic address + restore-from-backup, Step 1 constitutional defaults (defaults-of-defaults as reference), Step 2 ETL run with live layer progress + jurisdiction-viewer review, Step 3 apportionment + district-mapper handoff, Step 4 confirm/seat + instance export (the federation seed). Marked with a developed-component slot strip.",
  "stage": 6
 },
 {
  "file": "flows/WF-CIV-01.html",
  "title": "Flow — Onboarding & Identity Verification",
  "module": "flows",
  "roles": [
   "R-01"
  ],
  "workflows": [
   "WF-CIV-01"
  ],
  "forms": [],
  "entities": [
   "Individual"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 1
 },
 {
  "file": "flows/WF-CIV-02.html",
  "title": "Flow — Residency Establishment",
  "module": "flows",
  "roles": [
   "R-01",
   "R-02",
   "R-03"
  ],
  "workflows": [
   "WF-CIV-02"
  ],
  "forms": [],
  "entities": [
   "Residency Claim"
  ],
  "clocks": [
   "CLK-05"
  ],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py (uses hand-authored fixtures.flowSamples data)",
  "stage": 1
 },
 {
  "file": "flows/WF-CIV-03.html",
  "title": "Flow — Relocation & Re-association",
  "module": "flows",
  "roles": [
   "R-03"
  ],
  "workflows": [
   "WF-CIV-03"
  ],
  "forms": [],
  "entities": [
   "Residency Claim"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 1
 },
 {
  "file": "flows/WF-CIV-04.html",
  "title": "Flow — Ranked Ballot Cast & Verify",
  "module": "flows",
  "roles": [
   "R-04"
  ],
  "workflows": [
   "WF-CIV-04"
  ],
  "forms": [],
  "entities": [
   "Ballot (Ranked)"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 2
 },
 {
  "file": "flows/WF-CIV-05.html",
  "title": "Flow — Candidacy Lifecycle",
  "module": "flows",
  "roles": [
   "R-03",
   "R-06",
   "R-07"
  ],
  "workflows": [
   "WF-CIV-05"
  ],
  "forms": [],
  "entities": [
   "Candidacy"
  ],
  "clocks": [
   "CLK-21"
  ],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 2
 },
 {
  "file": "flows/WF-CIV-06.html",
  "title": "Flow — Petition Lifecycle (Law by Petition)",
  "module": "flows",
  "roles": [
   "R-05",
   "R-03",
   "R-08"
  ],
  "workflows": [
   "WF-CIV-06"
  ],
  "forms": [],
  "entities": [
   "Petition"
  ],
  "clocks": [
   "CLK-17"
  ],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 1
 },
 {
  "file": "flows/WF-CIV-07.html",
  "title": "Flow — Advocate Registration",
  "module": "flows",
  "roles": [
   "R-03",
   "R-22"
  ],
  "workflows": [
   "WF-CIV-07"
  ],
  "forms": [],
  "entities": [
   "Individual"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 1
 },
 {
  "file": "flows/WF-CIV-08.html",
  "title": "Flow — Open Ballot — Approval Phase & Candidate Discovery",
  "module": "flows",
  "roles": [
   "R-03",
   "R-04",
   "R-06"
  ],
  "workflows": [
   "WF-CIV-08"
  ],
  "forms": [],
  "entities": [
   "Approval Standing"
  ],
  "clocks": [
   "CLK-21"
  ],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 2
 },
 {
  "file": "flows/WF-ELE-01.html",
  "title": "Flow — General Election Cycle",
  "module": "flows",
  "roles": [
   "R-08",
   "R-06",
   "R-04",
   "R-03"
  ],
  "workflows": [
   "WF-ELE-01"
  ],
  "forms": [],
  "entities": [
   "Election"
  ],
  "clocks": [
   "CLK-21"
  ],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 2
 },
 {
  "file": "flows/WF-ELE-02.html",
  "title": "Flow — Bootstrap First Election",
  "module": "flows",
  "roles": [
   "R-06",
   "R-04"
  ],
  "workflows": [
   "WF-ELE-02"
  ],
  "forms": [],
  "entities": [
   "Election"
  ],
  "clocks": [
   "CLK-06"
  ],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 2
 },
 {
  "file": "flows/WF-ELE-03.html",
  "title": "Flow — Vacancy Countback",
  "module": "flows",
  "roles": [
   "R-08"
  ],
  "workflows": [
   "WF-ELE-03"
  ],
  "forms": [],
  "entities": [
   "Vacancy"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py (uses hand-authored fixtures.flowSamples data)",
  "stage": 2
 },
 {
  "file": "flows/WF-ELE-04.html",
  "title": "Flow — Special Election",
  "module": "flows",
  "roles": [
   "R-08",
   "R-06",
   "R-04"
  ],
  "workflows": [
   "WF-ELE-04"
  ],
  "forms": [],
  "entities": [
   "Election"
  ],
  "clocks": [
   "CLK-04"
  ],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 2
 },
 {
  "file": "flows/WF-ELE-05.html",
  "title": "Flow — Recount / Audit",
  "module": "flows",
  "roles": [
   "R-08"
  ],
  "workflows": [
   "WF-ELE-05"
  ],
  "forms": [],
  "entities": [
   "Election"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 2
 },
 {
  "file": "flows/WF-ELE-06.html",
  "title": "Flow — Subdivision & Boundary Drawing",
  "module": "flows",
  "roles": [
   "R-08"
  ],
  "workflows": [
   "WF-ELE-06"
  ],
  "forms": [],
  "entities": [
   "Jurisdiction"
  ],
  "clocks": [
   "CLK-07"
  ],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 2
 },
 {
  "file": "flows/WF-ELE-07.html",
  "title": "Flow — Referendum Execution",
  "module": "flows",
  "roles": [
   "R-04",
   "R-08"
  ],
  "workflows": [
   "WF-ELE-07"
  ],
  "forms": [],
  "entities": [
   "Referendum Question"
  ],
  "clocks": [
   "CLK-19"
  ],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 2
 },
 {
  "file": "flows/WF-ELE-08.html",
  "title": "Flow — Executive Election",
  "module": "flows",
  "roles": [
   "R-06",
   "R-04",
   "R-08"
  ],
  "workflows": [
   "WF-ELE-08"
  ],
  "forms": [],
  "entities": [
   "Election"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 2
 },
 {
  "file": "flows/WF-ELE-09.html",
  "title": "Flow — Judicial Election",
  "module": "flows",
  "roles": [
   "R-06",
   "R-04",
   "R-08"
  ],
  "workflows": [
   "WF-ELE-09"
  ],
  "forms": [],
  "entities": [
   "Election"
  ],
  "clocks": [
   "CLK-15"
  ],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 2
 },
 {
  "file": "flows/WF-ELE-10.html",
  "title": "Flow — Election Board Constitution",
  "module": "flows",
  "roles": [
   "R-09",
   "R-08"
  ],
  "workflows": [
   "WF-ELE-10"
  ],
  "forms": [],
  "entities": [
   "Election"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 2
 },
 {
  "file": "flows/WF-EXE-01.html",
  "title": "Flow — Executive Committee Delegation",
  "module": "flows",
  "roles": [
   "R-09",
   "R-14"
  ],
  "workflows": [
   "WF-EXE-01"
  ],
  "forms": [],
  "entities": [
   "Executive Office"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 4
 },
 {
  "file": "flows/WF-EXE-02.html",
  "title": "Flow — Conversion to Elected Executive Office",
  "module": "flows",
  "roles": [
   "R-09"
  ],
  "workflows": [
   "WF-EXE-02"
  ],
  "forms": [],
  "entities": [
   "Executive Office"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 4
 },
 {
  "file": "flows/WF-EXE-03.html",
  "title": "Flow — Executive Office Modification",
  "module": "flows",
  "roles": [],
  "workflows": [
   "WF-EXE-03"
  ],
  "forms": [],
  "entities": [
   "Executive Office"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 4
 },
 {
  "file": "flows/WF-EXE-04.html",
  "title": "Flow — Department Creation",
  "module": "flows",
  "roles": [
   "R-09",
   "R-14"
  ],
  "workflows": [
   "WF-EXE-04"
  ],
  "forms": [],
  "entities": [
   "Department / Board"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 4
 },
 {
  "file": "flows/WF-EXE-05.html",
  "title": "Flow — Board of Governors Appointment",
  "module": "flows",
  "roles": [
   "R-14",
   "R-09"
  ],
  "workflows": [
   "WF-EXE-05"
  ],
  "forms": [],
  "entities": [
   "Department / Board"
  ],
  "clocks": [
   "CLK-09"
  ],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 4
 },
 {
  "file": "flows/WF-EXE-06.html",
  "title": "Flow — Governor Removal",
  "module": "flows",
  "roles": [
   "R-14",
   "R-09"
  ],
  "workflows": [
   "WF-EXE-06"
  ],
  "forms": [],
  "entities": [
   "Department / Board"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 4
 },
 {
  "file": "flows/WF-EXE-07.html",
  "title": "Flow — Executive Order / Policy Proposal",
  "module": "flows",
  "roles": [
   "R-14"
  ],
  "workflows": [
   "WF-EXE-07"
  ],
  "forms": [],
  "entities": [],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 4
 },
 {
  "file": "flows/WF-EXE-08.html",
  "title": "Flow — Investigation Order",
  "module": "flows",
  "roles": [
   "R-14"
  ],
  "workflows": [
   "WF-EXE-08"
  ],
  "forms": [],
  "entities": [],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 4
 },
 {
  "file": "flows/WF-EXE-09.html",
  "title": "Flow — Department Reporting Cycle",
  "module": "flows",
  "roles": [
   "R-18"
  ],
  "workflows": [
   "WF-EXE-09"
  ],
  "forms": [],
  "entities": [
   "Department / Board"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 4
 },
 {
  "file": "flows/WF-JUD-01.html",
  "title": "Flow — Appointed Judiciary Creation & Confirmation",
  "module": "flows",
  "roles": [
   "R-09",
   "R-19"
  ],
  "workflows": [
   "WF-JUD-01"
  ],
  "forms": [],
  "entities": [],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 5
 },
 {
  "file": "flows/WF-JUD-02.html",
  "title": "Flow — Conversion to Elected Judiciary",
  "module": "flows",
  "roles": [
   "R-09"
  ],
  "workflows": [
   "WF-JUD-02"
  ],
  "forms": [],
  "entities": [],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 5
 },
 {
  "file": "flows/WF-JUD-03.html",
  "title": "Flow — Case Lifecycle",
  "module": "flows",
  "roles": [
   "R-03",
   "R-22",
   "R-19",
   "R-21"
  ],
  "workflows": [
   "WF-JUD-03"
  ],
  "forms": [],
  "entities": [
   "Case"
  ],
  "clocks": [
   "CLK-16"
  ],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 5
 },
 {
  "file": "flows/WF-JUD-04.html",
  "title": "Flow — Jury Paneling",
  "module": "flows",
  "roles": [
   "R-19",
   "R-21"
  ],
  "workflows": [
   "WF-JUD-04"
  ],
  "forms": [],
  "entities": [
   "Case"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 5
 },
 {
  "file": "flows/WF-JUD-05.html",
  "title": "Flow — Constitutional Challenge & Law Remedy (Art. IV §5)",
  "module": "flows",
  "roles": [
   "R-03",
   "R-09"
  ],
  "workflows": [
   "WF-JUD-05"
  ],
  "forms": [],
  "entities": [
   "Constitutional Challenge"
  ],
  "clocks": [
   "CLK-11",
   "CLK-12"
  ],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py (uses hand-authored fixtures.flowSamples data)",
  "stage": 5
 },
 {
  "file": "flows/WF-JUD-06.html",
  "title": "Flow — Emergency Powers Judicial Review",
  "module": "flows",
  "roles": [
   "R-09"
  ],
  "workflows": [
   "WF-JUD-06"
  ],
  "forms": [],
  "entities": [
   "Emergency Powers"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 5
 },
 {
  "file": "flows/WF-JUD-07.html",
  "title": "Flow — Judicial Vacancy Handling",
  "module": "flows",
  "roles": [
   "R-09",
   "R-08"
  ],
  "workflows": [
   "WF-JUD-07"
  ],
  "forms": [],
  "entities": [
   "Vacancy"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 5
 },
 {
  "file": "flows/WF-JUD-08.html",
  "title": "Flow — Judge Removal",
  "module": "flows",
  "roles": [
   "R-09",
   "R-10"
  ],
  "workflows": [
   "WF-JUD-08"
  ],
  "forms": [],
  "entities": [
   "Case"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 5
 },
 {
  "file": "flows/WF-JUD-09.html",
  "title": "Flow — Petition Constitutionality Review",
  "module": "flows",
  "roles": [],
  "workflows": [
   "WF-JUD-09"
  ],
  "forms": [],
  "entities": [
   "Petition"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 5
 },
 {
  "file": "flows/WF-JUR-01.html",
  "title": "Flow — Jurisdiction Bootstrap",
  "module": "flows",
  "roles": [],
  "workflows": [
   "WF-JUR-01"
  ],
  "forms": [],
  "entities": [
   "Jurisdiction"
  ],
  "clocks": [
   "CLK-06"
  ],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 6
 },
 {
  "file": "flows/WF-JUR-02.html",
  "title": "Flow — Union Formation",
  "module": "flows",
  "roles": [],
  "workflows": [
   "WF-JUR-02"
  ],
  "forms": [],
  "entities": [
   "Jurisdiction"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 6
 },
 {
  "file": "flows/WF-JUR-03.html",
  "title": "Flow — Join Existing Union",
  "module": "flows",
  "roles": [],
  "workflows": [
   "WF-JUR-03"
  ],
  "forms": [],
  "entities": [
   "Jurisdiction"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 6
 },
 {
  "file": "flows/WF-JUR-04.html",
  "title": "Flow — Disintermediation",
  "module": "flows",
  "roles": [],
  "workflows": [
   "WF-JUR-04"
  ],
  "forms": [],
  "entities": [
   "Jurisdiction"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 6
 },
 {
  "file": "flows/WF-JUR-05.html",
  "title": "Flow — Peer Discovery & Border Settlement",
  "module": "flows",
  "roles": [],
  "workflows": [
   "WF-JUR-05"
  ],
  "forms": [],
  "entities": [
   "Federation Peer"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 6
 },
 {
  "file": "flows/WF-JUR-06.html",
  "title": "Flow — Full Faith & Credit Record Sync",
  "module": "flows",
  "roles": [],
  "workflows": [
   "WF-JUR-06"
  ],
  "forms": [],
  "entities": [
   "Federation Peer"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 6
 },
 {
  "file": "flows/WF-JUR-07.html",
  "title": "Flow — Constitutional Restoration (Art. VI)",
  "module": "flows",
  "roles": [],
  "workflows": [
   "WF-JUR-07"
  ],
  "forms": [],
  "entities": [
   "Jurisdiction"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 6
 },
 {
  "file": "flows/WF-JUR-08.html",
  "title": "Flow — Authoritative Instance Migration",
  "module": "flows",
  "roles": [],
  "workflows": [
   "WF-JUR-08"
  ],
  "forms": [],
  "entities": [
   "Federation Peer"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 6
 },
 {
  "file": "flows/WF-JUR-09.html",
  "title": "Flow — Population Records & Apportionment",
  "module": "flows",
  "roles": [
   "R-08"
  ],
  "workflows": [
   "WF-JUR-09"
  ],
  "forms": [],
  "entities": [
   "Jurisdiction"
  ],
  "clocks": [
   "CLK-07"
  ],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 6
 },
 {
  "file": "flows/WF-LEG-01.html",
  "title": "Flow — Legislature Constitution",
  "module": "flows",
  "roles": [
   "R-09",
   "R-10"
  ],
  "workflows": [
   "WF-LEG-01"
  ],
  "forms": [],
  "entities": [],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 3
 },
 {
  "file": "flows/WF-LEG-02.html",
  "title": "Flow — Speaker Election / Replacement",
  "module": "flows",
  "roles": [
   "R-09"
  ],
  "workflows": [
   "WF-LEG-02"
  ],
  "forms": [],
  "entities": [],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 3
 },
 {
  "file": "flows/WF-LEG-03.html",
  "title": "Flow — Committee Establishment & Seat Assignment",
  "module": "flows",
  "roles": [
   "R-09",
   "R-10",
   "R-11"
  ],
  "workflows": [
   "WF-LEG-03"
  ],
  "forms": [],
  "entities": [
   "Committee Seat"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 3
 },
 {
  "file": "flows/WF-LEG-04.html",
  "title": "Flow — Committee Chair & Alternate Election",
  "module": "flows",
  "roles": [
   "R-09"
  ],
  "workflows": [
   "WF-LEG-04"
  ],
  "forms": [],
  "entities": [
   "Committee Seat"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 3
 },
 {
  "file": "flows/WF-LEG-05.html",
  "title": "Flow — Regular Session (Daily Order of Business)",
  "module": "flows",
  "roles": [
   "R-10",
   "R-09"
  ],
  "workflows": [
   "WF-LEG-05"
  ],
  "forms": [],
  "entities": [
   "Motion"
  ],
  "clocks": [
   "CLK-02"
  ],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 3
 },
 {
  "file": "flows/WF-LEG-06.html",
  "title": "Flow — Bill Lifecycle (Unicameral)",
  "module": "flows",
  "roles": [
   "R-09",
   "R-11",
   "R-12",
   "R-10"
  ],
  "workflows": [
   "WF-LEG-06"
  ],
  "forms": [],
  "entities": [
   "Bill"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 3
 },
 {
  "file": "flows/WF-LEG-07.html",
  "title": "Flow — Bicameral Dual-Agreement Bill Lifecycle",
  "module": "flows",
  "roles": [
   "R-09"
  ],
  "workflows": [
   "WF-LEG-07"
  ],
  "forms": [],
  "entities": [
   "Bill"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 3
 },
 {
  "file": "flows/WF-LEG-08.html",
  "title": "Flow — Committee Hearing",
  "module": "flows",
  "roles": [
   "R-12",
   "R-11"
  ],
  "workflows": [
   "WF-LEG-08"
  ],
  "forms": [],
  "entities": [
   "Bill"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 3
 },
 {
  "file": "flows/WF-LEG-09.html",
  "title": "Flow — Motion Handling",
  "module": "flows",
  "roles": [
   "R-09",
   "R-10"
  ],
  "workflows": [
   "WF-LEG-09"
  ],
  "forms": [],
  "entities": [
   "Motion"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 3
 },
 {
  "file": "flows/WF-LEG-10.html",
  "title": "Flow — Referendum Delegation",
  "module": "flows",
  "roles": [
   "R-09"
  ],
  "workflows": [
   "WF-LEG-10"
  ],
  "forms": [],
  "entities": [
   "Referendum Question"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 3
 },
 {
  "file": "flows/WF-LEG-11.html",
  "title": "Flow — Emergency Powers Lifecycle",
  "module": "flows",
  "roles": [
   "R-09"
  ],
  "workflows": [
   "WF-LEG-11"
  ],
  "forms": [],
  "entities": [
   "Emergency Powers"
  ],
  "clocks": [
   "CLK-03"
  ],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 3
 },
 {
  "file": "flows/WF-LEG-12.html",
  "title": "Flow — Legislative Vacancy Handling",
  "module": "flows",
  "roles": [
   "R-10",
   "R-08"
  ],
  "workflows": [
   "WF-LEG-12"
  ],
  "forms": [],
  "entities": [
   "Vacancy"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 3
 },
 {
  "file": "flows/WF-LEG-13.html",
  "title": "Flow — Committee Vacancy / New Committee Fill",
  "module": "flows",
  "roles": [
   "R-09"
  ],
  "workflows": [
   "WF-LEG-13"
  ],
  "forms": [],
  "entities": [
   "Committee Seat"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 3
 },
 {
  "file": "flows/WF-LEG-14.html",
  "title": "Flow — Amendable Setting Change",
  "module": "flows",
  "roles": [
   "R-09"
  ],
  "workflows": [
   "WF-LEG-14"
  ],
  "forms": [],
  "entities": [],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 3
 },
 {
  "file": "flows/WF-LEG-15.html",
  "title": "Flow — Rules of Order & Ethics Adoption",
  "module": "flows",
  "roles": [
   "R-09",
   "R-10"
  ],
  "workflows": [
   "WF-LEG-15"
  ],
  "forms": [],
  "entities": [],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 3
 },
 {
  "file": "flows/WF-LEG-16.html",
  "title": "Flow — Misconduct Investigation (Admin Office)",
  "module": "flows",
  "roles": [
   "R-10"
  ],
  "workflows": [
   "WF-LEG-16"
  ],
  "forms": [],
  "entities": [],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 3
 },
 {
  "file": "flows/WF-LEG-17.html",
  "title": "Flow — Impeachment / Censure / Expulsion",
  "module": "flows",
  "roles": [
   "R-10",
   "R-09"
  ],
  "workflows": [
   "WF-LEG-17"
  ],
  "forms": [],
  "entities": [],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 3
 },
 {
  "file": "flows/WF-LEG-18.html",
  "title": "Flow — Term Expiration & Renewal",
  "module": "flows",
  "roles": [
   "R-08"
  ],
  "workflows": [
   "WF-LEG-18"
  ],
  "forms": [],
  "entities": [
   "Election"
  ],
  "clocks": [
   "CLK-01",
   "CLK-19"
  ],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 3
 },
 {
  "file": "flows/WF-LEG-19.html",
  "title": "Flow — Modify / Repeal Referendum Act",
  "module": "flows",
  "roles": [
   "R-09"
  ],
  "workflows": [
   "WF-LEG-19"
  ],
  "forms": [],
  "entities": [
   "Referendum Question"
  ],
  "clocks": [
   "CLK-19"
  ],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 3
 },
 {
  "file": "flows/WF-LEG-20.html",
  "title": "Flow — Quorum Failure & Attendance Compulsion",
  "module": "flows",
  "roles": [
   "R-10",
   "R-09"
  ],
  "workflows": [
   "WF-LEG-20"
  ],
  "forms": [],
  "entities": [
   "Motion"
  ],
  "clocks": [
   "CLK-02"
  ],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 3
 },
 {
  "file": "flows/WF-ORG-01.html",
  "title": "Flow — Organization Registration",
  "module": "flows",
  "roles": [
   "R-03",
   "R-23"
  ],
  "workflows": [
   "WF-ORG-01"
  ],
  "forms": [],
  "entities": [
   "Organization"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 4
 },
 {
  "file": "flows/WF-ORG-02.html",
  "title": "Flow — Candidate Endorsement",
  "module": "flows",
  "roles": [
   "R-06"
  ],
  "workflows": [
   "WF-ORG-02"
  ],
  "forms": [],
  "entities": [
   "Organization"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 4
 },
 {
  "file": "flows/WF-ORG-03.html",
  "title": "Flow — Membership / Worker Joining",
  "module": "flows",
  "roles": [
   "R-03"
  ],
  "workflows": [
   "WF-ORG-03"
  ],
  "forms": [],
  "entities": [
   "Organization"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 4
 },
 {
  "file": "flows/WF-ORG-04.html",
  "title": "Flow — Co-determination Scaling Event",
  "module": "flows",
  "roles": [],
  "workflows": [
   "WF-ORG-04"
  ],
  "forms": [],
  "entities": [
   "Organization"
  ],
  "clocks": [
   "CLK-13",
   "CLK-14"
  ],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 4
 },
 {
  "file": "flows/WF-ORG-05.html",
  "title": "Flow — Board Elections (Owner & Worker Tracks)",
  "module": "flows",
  "roles": [],
  "workflows": [
   "WF-ORG-05"
  ],
  "forms": [],
  "entities": [
   "Organization"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 4
 },
 {
  "file": "flows/WF-ORG-06.html",
  "title": "Flow — Ownership Transfer (Mutual)",
  "module": "flows",
  "roles": [],
  "workflows": [
   "WF-ORG-06"
  ],
  "forms": [],
  "entities": [
   "Organization"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 4
 },
 {
  "file": "flows/WF-ORG-07.html",
  "title": "Flow — Monopoly / Open-Market Acquisition",
  "module": "flows",
  "roles": [
   "R-09"
  ],
  "workflows": [
   "WF-ORG-07"
  ],
  "forms": [],
  "entities": [
   "Organization"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 4
 },
 {
  "file": "flows/WF-ORG-08.html",
  "title": "Flow — Common Good Corporation Creation",
  "module": "flows",
  "roles": [
   "R-09",
   "R-14"
  ],
  "workflows": [
   "WF-ORG-08"
  ],
  "forms": [],
  "entities": [
   "Organization"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 4
 },
 {
  "file": "flows/WF-ORG-09.html",
  "title": "Flow — CGC Reorganization / Sale / Dissolution",
  "module": "flows",
  "roles": [
   "R-09"
  ],
  "workflows": [
   "WF-ORG-09"
  ],
  "forms": [],
  "entities": [
   "Organization"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 4
 },
 {
  "file": "flows/WF-ORG-10.html",
  "title": "Flow — Private Organization Dissolution",
  "module": "flows",
  "roles": [],
  "workflows": [
   "WF-ORG-10"
  ],
  "forms": [],
  "entities": [
   "Organization"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 4
 },
 {
  "file": "flows/WF-SYS-01.html",
  "title": "Flow — Term Synchronization Engine",
  "module": "flows",
  "roles": [],
  "workflows": [
   "WF-SYS-01"
  ],
  "forms": [],
  "entities": [
   "Election"
  ],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 6
 },
 {
  "file": "flows/WF-SYS-02.html",
  "title": "Flow — 90-Day Meeting Enforcement",
  "module": "flows",
  "roles": [
   "R-10"
  ],
  "workflows": [
   "WF-SYS-02"
  ],
  "forms": [],
  "entities": [],
  "clocks": [
   "CLK-02"
  ],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 6
 },
 {
  "file": "flows/WF-SYS-03.html",
  "title": "Flow — Public Records Publication",
  "module": "flows",
  "roles": [],
  "workflows": [
   "WF-SYS-03"
  ],
  "forms": [],
  "entities": [],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 6
 },
 {
  "file": "flows/WF-SYS-04.html",
  "title": "Flow — Constitutional Validation & Audit Chain",
  "module": "flows",
  "roles": [],
  "workflows": [
   "WF-SYS-04"
  ],
  "forms": [],
  "entities": [],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 6
 },
 {
  "file": "flows/WF-SYS-05.html",
  "title": "Flow — Constitutional Amendment (Art. VII)",
  "module": "flows",
  "roles": [
   "R-09"
  ],
  "workflows": [
   "WF-SYS-05"
  ],
  "forms": [],
  "entities": [],
  "clocks": [],
  "suggestedVuePage": null,
  "notes": "Generated from catalog Sheet 2 by tools/gen_flows.py",
  "stage": 6
 }
];
