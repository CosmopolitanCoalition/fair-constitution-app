/* ============================================================================
   CGA MOCKUPS v2 — fixtures-econ.js  (the economic + social spine)
   AUGMENTS CGA.fixtures.v2 with CGA.fixtures.v2.econ — the data for the Phase
   L (public finance) and Phase M (market economy) surfaces, plus the social /
   rep<->citizen data. Loads AFTER fixtures-v2.js.

   Everything here is DESIGN-AHEAD of code (Phases L & M are unbuilt; the forms
   F-LEG-037..040, F-IND-018..023, F-TRE-001..004, F-ORG-008 are reserved, not
   registered) — the surfaces that read this badge themselves "Planned". The
   model is grounded in the constitution (see mockups/v2/CONSTITUTION-CURRENCY-
   OPS.md) and the treasury / civic-stipend design docs:
     - Currency reserved to the root jurisdiction; it sets the worth and the
       measurement standards (units + subdivision) · Art. V §5
     - Joint/shared ledgers between agreeing parties · Art. V §2 + Art. I
     - Taxes/fees + borrowing · Art. V §4, never on a civic right · Art. II §8
     - Stock / fair-market on conversion · Art. III §5
     - Every contract has a constitutional floor (Supremacy of Rights) · Art. I
     - The civic stipend is a residency-floor UBI + a role differential, all
       dual-door-gated keys on the economic clock (ubi_period_days sweep)
   Units of account are ABSTRACT — no payment rails, no custody. Individual
   economic data (wallets, receipts, transactions) is PRIVATE and never federated.
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
    worthBasis: 'set and regulated by the root legislature · Art. V §5 "determine its worth"',
    standardsBasis: 'measurement standards (the unit and its subdivisions) defined at root · Art. V §5',
    citation: 'Currency Production and Regulation · Art. V §5 (reserved to the root jurisdiction, HARDENED)',
    abstractNote: 'An abstract unit of account — no payment rails, no custody, never touches external finance.'
  };

  /* ----------------------------------------------- MONETARY LEVERS (dual-door)
     Root-scoped constitutional_settings keys, changed ONLY through F-LEG-031
     (chamber supermajority + constituent consent). Never an admin knob. */
  var monetaryKeys = [
    { key: 'currency_worth_basis', label: 'Currency worth basis', value: 'labor-hour reference basket', kind: 'policy', gate: 'dual-door', enactingAct: 'Act 2031-12', cite: 'Art. V §5' },
    { key: 'monetary_issuance_rate_bps', label: 'Issuance rate', value: '120 bps / period', kind: 'monetary', gate: 'dual-door', enactingAct: 'Act 2031-12', cite: 'Art. V §5' },
    { key: 'inflation_target_bps', label: 'Inflation target', value: '200 bps / yr', kind: 'monetary', gate: 'dual-door', enactingAct: 'Act 2031-12', cite: 'Art. V §5' },
    { key: 'ubi_amount_per_period', label: 'Civic stipend base (UBI floor)', value: '50 ç', kind: 'monetary', gate: 'dual-door', enactingAct: 'Act 2031-14', cite: 'Art. I (residency floor)' },
    { key: 'ubi_period_days', label: 'Stipend interval (the economic clock)', value: '30 days', kind: 'monetary', gate: 'dual-door', enactingAct: 'Act 2031-14', cite: 'cadence — F-TRE-004 sweep' },
    { key: 'civic_stipend_enabled', label: 'Role differential enabled', value: 'on', kind: 'policy', gate: 'dual-door', enactingAct: 'Act 2031-15', cite: '[POLICY]' },
    { key: 'stipend_bump_operator', label: 'Node-operator bump', value: '8 ç', kind: 'policy', gate: 'dual-door', enactingAct: 'Act 2031-15', cite: 'mesh fact, not a role' },
    { key: 'stipend_bump_moderator', label: 'Social-moderator bump', value: '5 ç', kind: 'policy', gate: 'dual-door', enactingAct: 'Act 2031-15', cite: 'Phase K role' },
    { key: 'stipend_bump_officeholder', label: 'Office-holder bump', value: '12 ç', kind: 'policy', gate: 'dual-door', enactingAct: 'Act 2031-15', cite: 'derived office' },
    { key: 'stipend_bump_cap', label: 'Bump cap (anti-capture)', value: '20 ç / period', kind: 'policy', gate: 'dual-door', enactingAct: 'Act 2031-15', cite: 'sum ceiling' },
    { key: 'stipend_officeholder_roles', label: 'Which offices qualify', value: 'R-09, R-18, R-19, R-20', kind: 'policy', gate: 'dual-door', enactingAct: 'Act 2031-15', cite: '[POLICY] choice' }
  ];

  /* --------------------------------------------------------- THE ECONOMIC CLOCK
     The per-interval dispersal cadence. NOT a new CLK code — it is the
     ubi_period_days sweep that fires the stipend run (F-TRE-004, system actor). */
  var economicClock = {
    key: 'ubi_period_days', periodDays: 30,
    label: 'Civic stipend run — every 30 days',
    lastRun: 'ubi-2031-06', lastRunDate: '2031-06-01', nextRun: '2031-07-01',
    form: 'F-TRE-004', actor: 'system', cite: 'cadence is the ubi_period_days sweep — no new CLK code',
    note: 'The interval is itself a dual-door monetary key; the legislature can lengthen or shorten the cycle.'
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
    eligibilityFloor: 'Active residency association ONLY — the same gate as voting; no means test, no application · Art. I',
    classes: [
      { key: 'operator', label: 'Node operators', bump: 8, basis: 'A government-granted node-operator grant (the mesh fact) — authority is granted, never self-claimed. Not a constitutional role.', who: 'the people who run a server keeping the mesh alive' },
      { key: 'moderator', label: 'Social moderators', bump: 5, basis: 'An active Phase K social-moderator assignment. Not a constitutional role.', who: 'public-square / halls moderators' },
      { key: 'officeholder', label: 'Civic office-holders', bump: 12, basis: 'Derived from an active office row (R-09 legislator, R-18 BoG, R-19/R-20 judge). The set of paid offices is a [POLICY] choice.', who: 'elected & appointed constitutional officers' }
    ],
    rails: [
      'A DIFFERENTIAL, not a salary — pay can never become a qualification for office · Art. I (Right to Stand)',
      'Bumps are add-only (≥ 0) — they never reduce or withhold the residency floor',
      'Capped sum — stacking operator + office + moderator can never exceed the cap',
      'No governance advantage — a stipend writes only the private ledger, never a role/seat/vote',
      'Dual-door — the constituents whose money is spent must consent (anti-self-dealing)',
      'Never a paywall — the stipend is a payment TO, never a payment required OF · Art. II §8',
      'k-anonymous — small recipient classes are folded into the general aggregate, never published'
    ],
    examples: [
      { persona: 'amara-okafor', roles: ['residency'], amount: 50, breakdown: 'base 50' },
      { persona: 'yuki-tanaka', roles: ['residency', 'R-09 legislator', 'R-10 speaker'], amount: 62, breakdown: 'base 50 + office 12' },
      { persona: 'fatima-al-rashid', roles: ['residency', 'operator', 'R-08 election board'], amount: 58, breakdown: 'base 50 + operator 8 (R-08 not in the paid set)' },
      { persona: 'lena-novak', roles: ['residency', 'R-19 judge', 'moderator'], amount: 67, breakdown: 'base 50 + office 12 + moderator 5' }
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
    { id: 'jl-harbor', name: 'Hudson Harbor shared-waters fund', purpose: 'A resource that flows indivisibly between New York and New Jersey counties — Art. V §2',
      coOwners: [ { party: 'New York County', kind: 'jurisdiction', role: 'signer' }, { party: 'Hudson County (NJ)', kind: 'jurisdiction', role: 'signer' } ],
      approvalRule: 'all signers must agree', balance: '620,000 ç', public: true,
      pending: [ { movement: 'Pier 7 cleanup — 80,000 ç to Manhattan Water & Power', approvals: ['New York County'], needs: ['Hudson County (NJ)'] } ] },
    { id: 'jl-coop', name: 'Five-Boroughs maker co-op escrow', purpose: 'A joint account between agreeing organizations — Art. I freedom to contract',
      coOwners: [ { party: orgName('bluefin-logistics'), kind: 'org', role: 'signer' }, { party: orgName('hudson-mutual-aid'), kind: 'org', role: 'signer' }, { party: orgName('northstar-equal-partners', 'Northstar Equal Partners'), kind: 'org', role: 'signer' } ],
      approvalRule: 'a majority of signers', balance: '45,200 ç', public: false,
      pending: [] }
  ];

  /* ----------------------------------------------------------------- WALLET
     A personal account — PRIVATE, never federated (like a ballot). */
  var wallet = {
    owner: 'amara-okafor', balance: '312.40 ç', currency: 'CVU', private: true,
    neverFederated: 'Your balance, receipts, and transactions live only on this server — never federated, like a ballot.',
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
    { id: 'lst-1', title: 'Repaired cargo bikes', kind: 'good', qty: 6, price: '240 ç', seller: orgName('bluefin-logistics'), sellerKind: 'business', form: 'F-IND-021', tags: ['transit'] },
    { id: 'lst-2', title: 'Rooftop-garden consultation', kind: 'service', qty: 'by appointment', price: '35 ç / visit', seller: orgName('hudson-mutual-aid'), sellerKind: 'nonprofit', form: 'F-IND-021', tags: ['food', 'climate'] },
    { id: 'lst-3', title: 'Water-quality testing kits', kind: 'good', qty: 40, price: '18 ç', seller: orgName('manhattan-water-power'), sellerKind: 'common_good_corp', cgc: true, form: 'F-IND-021', tags: ['water'], note: 'A CGC sells on identical terms to any private seller · Art. III §5' },
    { id: 'lst-4', title: 'Bicycle repair lessons', kind: 'service', qty: '8 seats', price: '12 ç', seller: nm('diego-ramos'), sellerKind: 'individual', form: 'F-IND-021', tags: ['skills'] },
    { id: 'lst-5', title: 'Surplus depot pallets', kind: 'good', qty: 120, price: '2 ç each', seller: orgName('bluefin-logistics'), sellerKind: 'business', form: 'F-IND-021', tags: ['materials'] }
  ];

  /* ---------------------------------------------- REQUEST BOARD (the mirror)
     Offer <-> Request. Two kinds share the surface: the work board (labor) and
     mutual-aid / assistance requests (non-market, private by default). */
  var requests = {
    work: [
      { id: 'wk-1', title: 'Recurring depot loaders (40 hires)', org: orgName('bluefin-logistics'), rate: '22 ç / shift', form: 'F-IND-019',
        triggers: 'Accepting an application runs F-IND-014 → org_contracts(labor_recurring) → feeds co-determination headcount (a hire can cross the 100-worker first-seat threshold).' },
      { id: 'wk-2', title: 'Rooftop-garden installers (seasonal)', org: orgName('hudson-mutual-aid'), rate: '18 ç / hr', form: 'F-IND-019', triggers: 'F-IND-014 on acceptance' }
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
      floor: 'Both parties must sign; the engine rejects a single-sided contract. No clause may waive a constitutional right · Art. I Supremacy of Rights.' },
    { id: 'agr-2', kind: 'ownership_transfer', title: 'Transfer of a maker stall', parties: [ { name: nm('priya-sharma'), role: 'transferor' }, { name: nm('tomas-ferreira'), role: 'transferee' } ],
      terms: 'Transfer of a marketplace stall and its goodwill for 300 ç.', status: 'proposed', form: 'F-ORG-005', signedBoth: false,
      floor: 'Transferring owners and the receiving party each consent on the record · Art. I' },
    { id: 'agr-3', kind: 'joint_ledger', title: 'Five-Boroughs maker co-op escrow', parties: [ { name: orgName('bluefin-logistics'), role: 'signer' }, { name: orgName('hudson-mutual-aid'), role: 'signer' }, { name: 'Northstar Equal Partners', role: 'signer' } ],
      terms: 'A joint account; movements require a majority of signers.', status: 'active', form: 'F-IND-023', signedBoth: true,
      floor: 'Joint control by agreement · Art. V §2 (shared resources) + Art. I' },
    { id: 'agr-4', kind: 'sale', title: 'Surplus pallets — bulk sale', parties: [ { name: orgName('bluefin-logistics'), role: 'seller' }, { name: orgName('hudson-mutual-aid'), role: 'buyer' } ],
      terms: '120 pallets at 2 ç each.', status: 'completed', form: 'F-IND-022', signedBoth: true, floor: 'On the public Open Market · Art. III §5 / Art. V §5' }
  ];

  /* ------------------------------------------------------ TREASURY / PUBLIC FINANCE
     Budget cycle as a journey: revenue → budget → appropriations → disbursement
     → the public ledger. Borrowing and currency are gated. */
  var treasury = {
    cycle: [
      { step: 'Revenue', form: 'F-LEG-037', detail: 'Resource levies & fees — never on a civic right · Art. V §4 / Art. II §8' },
      { step: 'Budget', form: 'F-LEG-038', detail: 'Enacting the budget spawns the appropriations' },
      { step: 'Appropriations', form: null, detail: 'Department spending authority' },
      { step: 'Disbursement', form: 'F-TRE-001…003', detail: 'The Board of Governors execute · Art. III §4' },
      { step: 'Public ledger', form: null, detail: 'Double-entry, append-only, hash-chained' }
    ],
    revenue: [ { name: 'Resource levy (harbor)', rate: '0.4% of assessed value', base: 'apportioned via population records · Art. II §2', civicExempt: true } ],
    borrowing: [ { name: 'Infrastructure facility', amount: '1,000,000 ç', basis: 'borrowed on the jurisdiction’s credit · Art. V §4', form: 'F-LEG-039', status: 'drawn 1.0M of 2.5M' } ],
    budget: { year: 2031, total: '6,200,000 ç', lines: [ { name: 'Public Works', amount: '1,800,000 ç' }, { name: 'Justice administration', amount: '640,000 ç' }, { name: 'Emergency management', amount: '410,000 ç' }, { name: 'Civic stipend (root transfer)', amount: '2,950,000 ç' } ] },
    rail: 'No fee may attach to a civic-rights form — a budget line that does so is rejected with Art. II §8.'
  };

  /* ------------------------------------------------------------ STOCK / SHARES
     Private enterprises have shareholders; conversion to a CGC pays at least the
     fair market price (Art. III §5). Equal-partnership orgs have no shares. */
  var stock = {
    org: 'cobalt-grid', orgName: orgName('cobalt-grid', 'Cobalt Grid Co.'),
    total: 100000, classes: [ { cls: 'common', count: 80000, holders: 'private' }, { cls: 'worker-allocated', count: 20000, holders: 'employee pool' } ],
    fairMarket: '3.20 ç / share', flags: ['monopoly_target'],
    conversion: 'If acquired as a monopoly, shareholders are paid AT LEAST the fair market price; the board may join the founding Board of Governors · Art. III §5',
    note: 'Equal-partnership and member-owned orgs carry no shares — ownership is per the org’s charter.'
  };

  /* ------------------------------------------------------------ DUES / TAXES */
  var dues = {
    examples: [ { org: orgName('commons-party', 'The Commons Party'), amount: '5 ç / month', kind: 'membership dues', voluntary: true } ],
    rail: 'Dues are a private agreement between a member and a voluntary organization — never a gate on any civic right · Art. II §8.'
  };
  var taxes = {
    levies: [ { name: 'Resource levy', base: 'assessed harbor-front value, apportioned via population records · Art. II §2', rate: '0.4%', civicExempt: true } ],
    filing: { private: true, note: 'A tax filing is private, like a ballot — never federated.' },
    rail: 'No tax, fee, lien, or cost may ever be attached to exercising a civic right or obligation · Art. II §8 (HARDENED).'
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
      record: 'civic/my-record.html'
    },
    org: {
      org: 'bluefin-logistics', type: 'business', ownership: 'stock', workers: 740,
      charter: 'Move goods across the five boroughs; repair and resell.',
      board: { workerSeats: 2, ownerSeats: 3, chair: 'tomas-ferreira', jointChair: true },
      coDetermination: { threshold: 100, parity: 2000, status: '740 workers — above first-seat, below parity' },
      listings: ['lst-1', 'lst-5'], contracts: ['agr-1'], settingsHref: 'economy/org-settings.html'
    }
  };

  V2.econ = {
    currency: currency, monetaryKeys: monetaryKeys, economicClock: economicClock, stipend: stipend,
    accounts: accounts, publicLedger: publicLedger, jointLedgers: jointLedgers, wallet: wallet,
    marketplace: marketplace, requests: requests, agreements: agreements, treasury: treasury,
    stock: stock, dues: dues, taxes: taxes, reps: reps, profiles: profiles,
    byRep: (function () { var m = {}; reps.forEach(function (r) { m[r.persona] = r; }); return m; })()
  };
})();
