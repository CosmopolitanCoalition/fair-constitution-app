/* ============================================================================
   CGA MOCKUPS v2 — fixtures-econ.js  (the economic + social spine)
   AUGMENTS CGA.fixtures.v2 with CGA.fixtures.v2.econ — the data for the
   public-finance and market surfaces, plus the social / rep<->citizen data.
   Loads AFTER fixtures-v2.js. The surfaces that read this badge themselves
   "Planned". The model is grounded in the constitution (see mockups/v2/
   CONSTITUTION-CURRENCY-OPS.md) and the treasury / civic-stipend design docs:
     - Currency reserved to the root jurisdiction; it sets the worth and the
       measurement standards (units + subdivision) · Art. V §5
     - Joint/shared ledgers between agreeing parties · Art. V §2 + Art. I
     - Taxes/fees + borrowing · Art. V §4, never on a civic right · Art. II §8
     - Stock / fair-market on conversion · Art. III §5
     - Every contract has a constitutional floor (Supremacy of Rights) · Art. I
     - The civic stipend is a residency-floor UBI + a role differential, all
       dual-door-gated keys on the economic clock (ubi_period_days sweep)
   Units of account are ABSTRACT — no payment rails, no custody. Economic data
   syncs between nodes like all data, but individual economic data (wallets,
   receipts, transactions) is readable only by its owner — reader privacy,
   like a ballot.
   ============================================================================ */
(function () {
  'use strict';
  var F = window.CGA && window.CGA.fixtures, V2 = F && F.v2;
  if (!V2) { if (window.console) console.error('fixtures-econ: fixtures-v2.js must load first'); return; }
  function nm(id, fb) { var p = F.byId.personas[id]; return p ? p.name : (fb || id); }
  function orgName(id, fb) { var o = F.byId.organizations[id]; return o ? o.name : (fb || id); }

  /* ---------------------------------------------------------------- CURRENCY
     Reserved to the most-encompassing jurisdiction (Art. V §5). The root sets
     its WORTH and the MEASUREMENT STANDARDS — that is where the unit and its
     subdivisions come from. Currency-agnostic: the demo instance runs a
     social-credit unit, but the same surfaces serve fiat / commodity / peg. */
  var currency = {
    name: 'Civic Unit', code: 'CVU', symbol: 'ç', unitKind: 'social_credit',
    issuer: 'earth-0-earth', issuerLabel: 'Earth (the most-encompassing jurisdiction)',
    precision: 4,
    subdivisions: [
      { name: 'unit', factor: 1, symbol: 'ç' },
      { name: 'centunit', factor: 100, symbol: 'çc', note: '1 unit = 100 centunits' },
      { name: 'milliunit', factor: 1000, symbol: 'çm', note: 'internal accounting precision' }
    ],
    worthBasis: 'set and regulated by the root legislature, which determines its worth',
    standardsBasis: 'measurement standards (the unit and its subdivisions) defined at root',
    citation: 'Currency production and regulation — reserved to the root jurisdiction',
    abstractNote: 'An abstract unit of account — no payment rails, no custody, never touches external finance.'
  };

  /* ----------------------------------------------- MONETARY LEVERS (dual-door)
     Root-scoped constitutional_settings keys, changed ONLY through F-LEG-031
     (chamber supermajority + constituent consent). Never an admin knob. */
  var monetaryKeys = [
    { key: 'currency_worth_basis', label: 'Currency worth basis', value: 'labor-hour reference basket', kind: 'policy', gate: 'dual-door', enactingAct: 'Act 2031-12', cite: 'set at root' },
    { key: 'monetary_issuance_rate_bps', label: 'Issuance rate', value: '1.2% per period', kind: 'monetary', gate: 'dual-door', enactingAct: 'Act 2031-12', cite: 'set at root' },
    { key: 'inflation_target_bps', label: 'Inflation target', value: '2% per year', kind: 'monetary', gate: 'dual-door', enactingAct: 'Act 2031-12', cite: 'set at root' },
    { key: 'ubi_amount_per_period', label: 'Civic stipend base (UBI floor)', value: '50 ç', kind: 'monetary', gate: 'dual-door', enactingAct: 'Act 2031-14', cite: 'residency floor' },
    { key: 'ubi_period_days', label: 'Stipend interval (the economic clock)', value: '30 days', kind: 'monetary', gate: 'dual-door', enactingAct: 'Act 2031-14', cite: 'cadence — the stipend sweep' },
    { key: 'civic_stipend_enabled', label: 'Role differential enabled', value: 'on', kind: 'policy', gate: 'dual-door', enactingAct: 'Act 2031-15', cite: 'policy' },
    { key: 'stipend_bump_operator', label: 'Node-operator bump', value: '8 ç', kind: 'policy', gate: 'dual-door', enactingAct: 'Act 2031-15', cite: 'mesh fact, not a role' },
    { key: 'stipend_bump_moderator', label: 'Social-moderator bump', value: '5 ç', kind: 'policy', gate: 'dual-door', enactingAct: 'Act 2031-15', cite: 'moderator role' },
    { key: 'stipend_bump_officeholder', label: 'Office-holder bump', value: '12 ç', kind: 'policy', gate: 'dual-door', enactingAct: 'Act 2031-15', cite: 'derived office' },
    { key: 'stipend_bump_cap', label: 'Bump cap (anti-capture)', value: '20 ç / period', kind: 'policy', gate: 'dual-door', enactingAct: 'Act 2031-15', cite: 'sum ceiling' },
    { key: 'stipend_officeholder_roles', label: 'Which offices qualify', value: 'legislators, governors, judges', kind: 'policy', gate: 'dual-door', enactingAct: 'Act 2031-15', cite: 'policy choice' }
  ];

  /* --------------------------------------------------------- THE ECONOMIC CLOCK
     The per-interval dispersal cadence. NOT a new CLK code — it is the
     ubi_period_days sweep that fires the stipend run (F-TRE-004, system actor). */
  var economicClock = {
    key: 'ubi_period_days', periodDays: 30,
    label: 'Civic stipend run — every 30 days',
    lastRun: 'ubi-2031-06', lastRunDate: '2031-06-01', nextRun: '2031-07-01',
    form: 'F-TRE-004', actor: 'system', cite: 'cadence is the stipend interval sweep',
    note: 'The interval is itself a lever set by law; the legislature can lengthen or shorten the cycle.'
  };

  /* ----------------------------------------------- THE CIVIC STIPEND (UBI + diff)
     amount = ubi_base + min( Σ over active eligible roles ( bump[role] ), cap ).
     The base is the residency floor (everyone associated). The bumps are a
     recognition differential — never a salary, never a qualification for office. */
  var stipend = {
    enabled: true, base: 50, cap: 20,
    bumps: { operator: 8, moderator: 5, officeholder: 12 },
    officeholderRoles: ['R-09', 'R-18', 'R-19', 'R-20'],
    formula: 'amount = base (residency floor) + min( Σ eligible-role bumps, cap )',
    eligibilityFloor: 'Active residency association ONLY — the same gate as voting; no means test, no application.',
    classes: [
      { key: 'operator', label: 'Node operators', bump: 8, basis: 'A government-granted node-operator grant — authority is granted, never self-claimed. Not a constitutional role.', who: 'the people who run a server keeping the world online' },
      { key: 'moderator', label: 'Social moderators', bump: 5, basis: 'An active social-moderator assignment. Not a constitutional role.', who: 'public-square / halls moderators' },
      { key: 'officeholder', label: 'Civic office-holders', bump: 12, basis: 'Derived from an active office (legislator, governor, judge). The set of paid offices is a policy choice.', who: 'elected & appointed constitutional officers' }
    ],
    rails: [
      'A DIFFERENTIAL, not a salary — pay can never become a qualification for office.',
      'Bumps are add-only (≥ 0) — they never reduce or withhold the residency floor',
      'Capped sum — stacking operator + office + moderator can never exceed the cap',
      'No governance advantage — a stipend writes only the private ledger, never a role/seat/vote',
      'Changed only by a two-door act — a supermajority of the chamber AND the consent of the people whose money is spent (anti-self-dealing)',
      'Never a paywall — the stipend is a payment TO, never a payment required OF',
      'k-anonymous — small recipient classes are folded into the general aggregate, never published'
    ],
    examples: [
      { persona: 'amara-okafor', roles: ['residency'], amount: 50, breakdown: 'base 50' },
      { persona: 'yuki-tanaka', roles: ['residency', 'legislator', 'speaker'], amount: 62, breakdown: 'base 50 + office 12' },
      { persona: 'fatima-al-rashid', roles: ['residency', 'operator', 'election board'], amount: 58, breakdown: 'base 50 + operator 8 (election board not in the paid set)' },
      { persona: 'lena-novak', roles: ['residency', 'judge', 'moderator'], amount: 67, breakdown: 'base 50 + office 12 + moderator 5' }
    ],
    lastRun: { id: 'ubi-2031-06', recipientsAggregate: 1690000, totalMinted: '86,420,000 ç', date: '2031-06-01', perReceiptPrivate: true }
  };

  /* ---------------------------------------------------- ACCOUNTS & THE LEDGER
     economic_accounts: jurisdiction/department accounts are PUBLIC; user/org
     accounts are PRIVATE. The public ledger is double-entry, append-only,
     hash-chained — Σdebits = Σcredits per currency (same audit-chain as the rest). */
  var accounts = [
    { id: 'acc-nyc-treasury', owner: 'New York County treasury', kind: 'jurisdiction', balance: '4,182,900 ç', public: true },
    { id: 'acc-nyc-works', owner: 'Public Works & Utilities (dept)', kind: 'department', balance: '1,044,500 ç', public: true },
    { id: 'acc-amara', owner: nm('amara-okafor'), kind: 'user', balance: 'private', public: false },
    { id: 'acc-bluefin', owner: orgName('bluefin-logistics'), kind: 'org', balance: 'private', public: false }
  ];
  var publicLedger = [
    { seq: 88412, date: '2031-06-01', debit: 'Treasury · issuance', credit: 'Civic stipend run ubi-2031-06', amount: '86,420,000 ç', kind: 'ubi', hash: '0x9f3a…c12' },
    { seq: 88408, date: '2031-05-28', debit: 'Revenue · resource levy', credit: 'Treasury', amount: '512,000 ç', kind: 'revenue', hash: '0x71b2…8de' },
    { seq: 88401, date: '2031-05-20', debit: 'Treasury', credit: 'Public Works · depot retrofit (appropriation)', amount: '240,000 ç', kind: 'appropriation', hash: '0x4ac9…f07' },
    { seq: 88399, date: '2031-05-18', debit: 'Treasury', credit: 'Borrowing facility (drawdown)', amount: '1,000,000 ç', kind: 'borrowing', hash: '0x2d51…a93' }
  ];

  /* ------------------------------------------ JOINT-CONTROLLED LEDGERS
     Co-owned accounts whose movements require the AGREEMENT of the co-owners.
     Grounded in shared/indivisible resources between jurisdictions (Art. V §2)
     and the freedom to contract (Art. I). A movement waits in `pending` until
     every required signer has approved. */
  var jointLedgers = [
    { id: 'jl-harbor', name: 'Hudson Harbor shared-waters fund', purpose: 'A resource that flows indivisibly between New York and New Jersey counties',
      coOwners: [ { party: 'New York County', kind: 'jurisdiction', role: 'signer' }, { party: 'Hudson County (NJ)', kind: 'jurisdiction', role: 'signer' } ],
      approvalRule: 'all signers must agree', balance: '620,000 ç', public: true,
      pending: [ { movement: 'Pier 7 cleanup — 80,000 ç to Manhattan Water & Power', approvals: ['New York County'], needs: ['Hudson County (NJ)'] } ] },
    { id: 'jl-coop', name: 'Five-Boroughs maker co-op escrow', purpose: 'A joint account between agreeing organizations — the freedom to contract',
      coOwners: [ { party: orgName('bluefin-logistics'), kind: 'org', role: 'signer' }, { party: orgName('hudson-mutual-aid'), kind: 'org', role: 'signer' }, { party: orgName('northstar-equal-partners', 'Northstar Equal Partners'), kind: 'org', role: 'signer' } ],
      approvalRule: 'a majority of signers', balance: '45,200 ç', public: false,
      pending: [] }
  ];

  /* ----------------------------------------------------------------- WALLET
     A personal account — private like a ballot: only the owner can read it. */
  var wallet = {
    owner: 'amara-okafor', balance: '312.40 ç', currency: 'CVU', private: true,
    neverFederated: 'Your balance, receipts, and transactions are private — like a ballot, only you can read them.',
    transactions: [
      { date: '2031-06-01', kind: 'civic stipend', amount: '+50.00 ç', counterparty: 'Treasury (stipend run)', memo: 'residency floor' },
      { date: '2031-05-27', kind: 'purchase', amount: '−18.00 ç', counterparty: orgName('manhattan-water-power'), memo: 'water-testing kit' },
      { date: '2031-05-22', kind: 'sale', amount: '+24.00 ç', counterparty: '@u-greenwood', memo: 'repaired bike' },
      { date: '2031-05-15', kind: 'transfer', amount: '−10.00 ç', counterparty: '@u-neighbor2', memo: 'split — cleanup supplies' }
    ]
  };

  /* ---------------------------------------------------------- MARKETPLACE (offers)
     marketplace_listings → orders. Listers are individuals or orgs. A CGC sells
     on identical terms to a private seller (Art. III §5). */
  var marketplace = [
    { id: 'lst-1', title: 'Repaired cargo bikes', kind: 'good', qty: 6, price: '240 ç', seller: orgName('bluefin-logistics'), sellerKind: 'business', form: 'F-IND-021', tags: ['transit'],
      desc: 'Repaired and roadworthy cargo bikes, reconditioned at the depot and resold across the five boroughs. Each unit is checked, given a fresh chain and brakes, and listed at a flat price — buy one or the full lot.' },
    { id: 'lst-2', title: 'Rooftop-garden consultation', kind: 'service', qty: 'by appointment', price: '35 ç / visit', seller: orgName('hudson-mutual-aid'), sellerKind: 'nonprofit', form: 'F-IND-021', tags: ['food', 'climate'],
      desc: 'A visit from an experienced rooftop grower: soil, sun, drainage, and a planting plan for your building. Book a single visit or a season of follow-ups.' },
    { id: 'lst-3', title: 'Water-quality testing kits', kind: 'good', qty: 40, price: '18 ç', seller: orgName('manhattan-water-power'), sellerKind: 'common_good_corp', cgc: true, form: 'F-IND-021', tags: ['water'], note: 'A common-good corp sells on identical terms to any private seller.',
      desc: 'Field kits for testing tap and harbor water — strips, a color chart, and a mail-in vial for the lab. The same kit the harbor-cleanup crews use.' },
    { id: 'lst-4', title: 'Bicycle repair lessons', kind: 'service', qty: '8 seats', price: '12 ç', seller: nm('diego-ramos'), sellerKind: 'individual', form: 'F-IND-021', tags: ['skills'],
      desc: 'A hands-on evening class: flats, brakes, chains, and a full tune-up on your own bike. Tools provided; eight seats per session.' },
    { id: 'lst-5', title: 'Surplus depot pallets', kind: 'good', qty: 120, price: '2 ç each', seller: orgName('bluefin-logistics'), sellerKind: 'business', form: 'F-IND-021', tags: ['materials'],
      desc: 'Clean, intact shipping pallets surplus to the depot — good for furniture builds, garden beds, and staging. Take a few or the whole stack.' }
  ];

  /* ---------------------------------------------- REQUEST BOARD (the mirror)
     Offer <-> Request. Two kinds share the surface: the work board (labor) and
     mutual-aid / assistance requests (non-market, private by default). */
  var requests = {
    work: [
      { id: 'wk-1', title: 'Recurring depot loaders (40 hires)', org: orgName('bluefin-logistics'), rate: '22 ç / shift', form: 'F-IND-019',
        triggers: 'Accepting an application creates a recurring labor contract that feeds the worker-representation headcount (a hire can cross the 100-worker first-seat threshold).' },
      { id: 'wk-2', title: 'Rooftop-garden installers (seasonal)', org: orgName('hudson-mutual-aid'), rate: '18 ç / hr', form: 'F-IND-019', triggers: 'A labor contract is created on acceptance' }
    ],
    assistance: [
      { id: 'aid-1', title: 'Help moving a wheelchair-accessible ramp', kind: 'mutual_aid', by: '@u-greenwood', privacy: 'private by default', note: 'Non-market — a neighbor asking for help.' },
      { id: 'aid-2', title: 'Spare folding tables for a cleanup day', kind: 'mutual_aid', by: '@u-neighbor1', privacy: 'private by default' }
    ]
  };

  /* ---------------------------------------- INSTRUMENTS OF AGREEMENT (contracts)
     The freedom to contract (Art. I) with a constitutional FLOOR: no clause may
     disempower a constitutional right (Supremacy of Rights, Art. I). */
  var agreements = [
    { id: 'agr-1', kind: 'labor_recurring', title: 'Depot loader — recurring labor', parties: [ { name: nm('diego-ramos'), role: 'worker' }, { name: orgName('bluefin-logistics'), role: 'organization' } ],
      terms: 'Recurring shifts at 22 ç/shift; counts toward co-determination headcount.', status: 'agreed', form: 'F-IND-014', signedBoth: true,
      floor: 'Both parties must sign — a one-sided contract never takes effect. No clause may waive a constitutional right.' },
    { id: 'agr-2', kind: 'ownership_transfer', title: 'Transfer of a maker stall', parties: [ { name: nm('priya-sharma'), role: 'transferor' }, { name: nm('tomas-ferreira'), role: 'transferee' } ],
      terms: 'Transfer of a marketplace stall and its goodwill for 300 ç.', status: 'proposed', form: 'F-ORG-005', signedBoth: false,
      floor: 'Transferring owners and the receiving party each consent on the record.' },
    { id: 'agr-3', kind: 'joint_ledger', title: 'Five-Boroughs maker co-op escrow', parties: [ { name: orgName('bluefin-logistics'), role: 'signer' }, { name: orgName('hudson-mutual-aid'), role: 'signer' }, { name: 'Northstar Equal Partners', role: 'signer' } ],
      terms: 'A joint account; movements require a majority of signers.', status: 'active', form: 'F-IND-023', signedBoth: true,
      floor: 'Joint control by agreement over shared resources.' },
    { id: 'agr-4', kind: 'sale', title: 'Surplus pallets — bulk sale', parties: [ { name: orgName('bluefin-logistics'), role: 'seller' }, { name: orgName('hudson-mutual-aid'), role: 'buyer' } ],
      terms: '120 pallets at 2 ç each.', status: 'completed', form: 'F-IND-022', signedBoth: true, floor: 'On the public Open Market.' }
  ];

  /* ------------------------------------------------------ TREASURY / PUBLIC FINANCE
     Budget cycle as a journey: revenue → budget → appropriations → disbursement
     → the public ledger. Borrowing and currency are gated. */
  var treasury = {
    cycle: [
      { step: 'Revenue', form: 'F-LEG-037', chipLabel: 'Set a revenue levy', detail: 'Resource levies & fees — never on a civic right' },
      { step: 'Budget', form: 'F-LEG-038', chipLabel: 'Enact the budget', detail: 'Enacting the budget spawns the appropriations' },
      { step: 'Appropriations', form: null, detail: 'Department spending authority' },
      { step: 'Disbursement', form: 'F-TRE-001…003', chipLabel: 'Governors disburse', detail: 'The Board of Governors execute' },
      { step: 'Public ledger', form: null, detail: 'Double-entry, append-only, hash-chained' }
    ],
    revenue: [ { name: 'Resource levy (harbor)', rate: '0.4% of assessed value', base: 'apportioned via population records', civicExempt: true } ],
    borrowing: [ { name: 'Infrastructure facility', amount: '1,000,000 ç', basis: 'borrowed on the jurisdiction’s credit', form: 'F-LEG-039', status: 'drawn 1.0M of 2.5M' } ],
    budget: { year: 2031, total: '6,200,000 ç', lines: [ { name: 'Public Works', amount: '1,800,000 ç' }, { name: 'Justice administration', amount: '640,000 ç' }, { name: 'Emergency management', amount: '410,000 ç' }, { name: 'Civic stipend (root transfer)', amount: '2,950,000 ç' } ] },
    rail: 'No fee may attach to a civic-rights form — a budget line that does so is rejected.'
  };

  /* ------------------------------------------------------------ STOCK / SHARES
     Private enterprises have shareholders; conversion to a CGC pays at least the
     fair market price (Art. III §5). Equal-partnership orgs have no shares. */
  var stock = {
    org: 'cobalt-grid', orgName: orgName('cobalt-grid', 'Cobalt Grid Co.'),
    total: 100000, classes: [ { cls: 'common', count: 80000, holders: 'private' }, { cls: 'worker-allocated', count: 20000, holders: 'employee pool' } ],
    fairMarket: '3.20 ç / share', flags: ['monopoly_target'],
    conversion: 'If acquired as a monopoly, shareholders are paid AT LEAST the fair market price; the board may join the founding Board of Governors.',
    note: 'Equal-partnership and member-owned orgs carry no shares — ownership is per the org’s charter.'
  };

  /* ------------------------------------------------------------ DUES / TAXES */
  var dues = {
    examples: [ { org: orgName('commons-party', 'The Commons Party'), amount: '5 ç / month', kind: 'membership dues', voluntary: true } ],
    rail: 'Dues are a private agreement between a member and a voluntary organization — never a gate on any civic right.'
  };
  var taxes = {
    levies: [ { name: 'Resource levy', base: 'assessed harbor-front value, apportioned via population records', rate: '0.4%', civicExempt: true } ],
    filing: { private: true, note: 'A tax filing is private — like a ballot, only the filer can read it.' },
    rail: 'No tax, fee, lien, or cost may ever be attached to exercising a civic right or obligation.'
  };

  /* ------------------------------------------------ REPRESENTATIVES <-> CITIZENS
     The interaction between a seated representative and the people they serve:
     a public page, office hours / open meetings, constituent requests, the forum. */
  var reps = [
    { persona: 'yuki-tanaka', office: 'Speaker · New York County legislature', jurisdiction: 'usa-3-new-york-county',
      record: 'legislature/session-console.html', forum: 'townhall',
      surgeries: [ { date: '2031-06-25', kind: 'Open office hours', where: 'the halls (live room)' }, { date: '2031-07-02', kind: 'Town hall — participatory budget', where: 'town-hall room' } ],
      requests: [ { from: '@u-harborwatch', kind: 'Meeting request', topic: 'Harbor air quality', status: 'accepted' }, { from: '@u-greenwood', kind: 'Constituent message', topic: 'Bus frequency', status: 'open' } ] },
    { persona: 'marcus-chen', office: 'Representative & committee chair · Environment & Infrastructure', jurisdiction: 'usa-3-new-york-county',
      record: 'legislature/committee-detail.html', forum: 'committee',
      surgeries: [ { date: '2031-06-21', kind: 'Committee hearing — Clean Air Act', where: 'committee room' } ],
      requests: [ { from: '@u-tamb', kind: 'Meeting request', topic: 'Depot retrofit', status: 'open' } ] }
  ];

  /* ----------------------------------------------- PROFILES (personal + org)
     Mostly reuses v1 personas/orgs + the K social model. Pseudonymous handle on
     the social wire; the legal name is the operator's own choice to show. */
  var profiles = {
    personal: {
      persona: 'amara-okafor', handle: 'amara',
      bio: 'New Yorker, harbor-cleanup organizer, occasional bike-fixer.',
      followsCount: 84, followersCount: 212,
      endorsementsGiven: [ { to: 'diego-ramos', public: true } ],
      groups: ['grp-harbor'], orgs: ['hudson-mutual-aid'],
      achievements: [ { name: 'First ballot cast', note: 'decorative — confers no power', proposed: true }, { name: 'Founded a group', proposed: true } ],
      record: 'system/public-records.html'
    },
    org: {
      org: 'bluefin-logistics', type: 'business', ownership: 'stock', workers: 740,
      charter: 'Move goods across the five boroughs; repair and resell.',
      board: { workerSeats: 2, ownerSeats: 3, chair: 'tomas-ferreira', jointChair: true },
      coDetermination: { threshold: 100, parity: 2000, status: '740 workers — above first-seat, below parity' },
      listings: ['lst-1', 'lst-5'], contracts: ['agr-1'], settingsHref: 'economy/org-settings.html',
      /* the org's public job board — co-determined pay lives behind each posting */
      jobs: [
        { title: 'Bike mechanic', kind: 'Full-time', where: 'Five-borough depot', note: 'Repair and recondition cargo bikes. Pay set by the co-determined package.' },
        { title: 'Dispatch coordinator', kind: 'Part-time', where: 'Remote + depot', note: 'Coordinate cross-borough runs and schedules.' },
        { title: 'Weekend loader', kind: 'Shift', where: 'Pier 7', note: 'Load and haul on Saturday mornings.' }
      ]
    }
  };

  /* --------------------------------------------------- THE EXCHANGE (trading floor)
     Price discovery on the public Open Market (Art. V §5). Organization SHARES
     trade here on a fair market (Art. III §5) — single items are bought and
     sold on the open market, each at exactly one place and one price. Units are
     ABSTRACT — no payment rails, no custody; a fill writes the parties' private
     wallets, readable only by them. A CGC quotes on identical terms to any
     private seller. Liveness here is simulated in-page (Planned). */
  var exchange = {
    venue: 'The Open Market',
    rail: 'Price discovery in the open. Organization shares trade on a fair market; a fill settles to the parties’ private wallets — like ballots, readable only by them. Units are abstract; there are no payment rails or custody. A common-good corp quotes on identical terms to any private seller.',
    session: { label: 'Open', open: true, volumeToday: 38420, hours: 'Continuous while the jurisdiction is active', note: 'Simulated in-page — Planned.' },
    instruments: [
      { sym: 'BLU', name: 'Bluefin Logistics', kind: 'share', issuer: orgName('bluefin-logistics'), last: 12.40, change: 2.1, volume: 5120, spark: [11.8, 11.9, 12.0, 11.95, 12.1, 12.2, 12.15, 12.3, 12.35, 12.28, 12.4, 12.4] },
      { sym: 'NSP', name: 'Northstar Equal Partners', kind: 'share', issuer: 'Northstar Equal Partners', last: 8.10, change: -1.2, volume: 2240, spark: [8.3, 8.25, 8.2, 8.28, 8.15, 8.1, 8.12, 8.05, 8.08, 8.1, 8.12, 8.1] }
    ],
    /* single items trade on the open market, not here — each links to its listing */
    marketGoods: [
      { name: 'Water-quality testing kits', listing: 'lst-3', seller: orgName('manhattan-water-power') },
      { name: 'Repaired cargo bikes', listing: 'lst-1', seller: orgName('bluefin-logistics') },
      { name: 'Surplus depot pallets', listing: 'lst-5', seller: orgName('bluefin-logistics') }
    ],
    /* a seeded order book + tape for the default focus (BLU); other instruments
       derive a synthetic book around `last` in the page (deterministic). */
    seedBook: {
      bids: [ { price: 12.39, size: 140 }, { price: 12.38, size: 90 }, { price: 12.35, size: 320 }, { price: 12.30, size: 210 }, { price: 12.25, size: 500 } ],
      asks: [ { price: 12.41, size: 120 }, { price: 12.42, size: 80 }, { price: 12.45, size: 260 }, { price: 12.50, size: 300 }, { price: 12.55, size: 420 } ]
    },
    seedTape: [
      { t: '14:32:08', price: 12.40, size: 40, side: 'buy' }, { t: '14:31:55', price: 12.39, size: 120, side: 'sell' },
      { t: '14:31:40', price: 12.40, size: 25, side: 'buy' }, { t: '14:31:22', price: 12.38, size: 60, side: 'sell' },
      { t: '14:30:59', price: 12.41, size: 200, side: 'buy' }, { t: '14:30:31', price: 12.40, size: 35, side: 'buy' }
    ],
    /* the pool the in-page streamer cycles through to fake a live tape */
    streamPool: [
      { price: 12.41, size: 25, side: 'buy' }, { price: 12.39, size: 60, side: 'sell' }, { price: 12.40, size: 110, side: 'buy' },
      { price: 12.42, size: 40, side: 'buy' }, { price: 12.38, size: 80, side: 'sell' }, { price: 12.40, size: 15, side: 'buy' },
      { price: 12.43, size: 200, side: 'buy' }, { price: 12.37, size: 95, side: 'sell' }
    ],
    traders: [
      { handle: 'amara', role: 'resident' }, { handle: 'tomas', role: 'org agent · Bluefin' },
      { handle: 'pier7', role: 'resident' }, { handle: 'noor', role: 'resident' }, { handle: 'diego', role: 'resident' }
    ]
  };

  V2.econ = {
    currency: currency, monetaryKeys: monetaryKeys, economicClock: economicClock, stipend: stipend,
    exchange: exchange,
    accounts: accounts, publicLedger: publicLedger, jointLedgers: jointLedgers, wallet: wallet,
    marketplace: marketplace, requests: requests, agreements: agreements, treasury: treasury,
    stock: stock, dues: dues, taxes: taxes, reps: reps, profiles: profiles,
    byRep: (function () { var m = {}; reps.forEach(function (r) { m[r.persona] = r; }); return m; })()
  };
})();
