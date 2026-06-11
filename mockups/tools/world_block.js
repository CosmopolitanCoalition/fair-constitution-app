
  /* ------------------------------------------------------------------ WORLD */
  var world = {
    instance: {
      host: 'charlotte.cga.example',
      authoritativeFor: 'usa-4-charlotte',
      auditSeq: 84113,
      timezoneHint: 'UTC−5 · America/New_York'
    },

    /* Cosmic address chain (mirrors the cosmic_addresses migration; the
       collapsed prefix shown in the jurisdiction switcher). Other worlds are
       future scope — the cascader's existence is the hook. */
    cosmic: [
      { id: 'multiverse', name: 'Multiverse', type: 'multiverse' },
      { id: 'observable-universe', name: 'Observable Universe', type: 'observable_universe' },
      { id: 'laniakea', name: 'Laniakea Supercluster', type: 'supercluster' },
      { id: 'local-group', name: 'Local Group', type: 'galaxy_group' },
      { id: 'milky-way', name: 'Milky Way', type: 'galaxy' },
      { id: 'orion-arm', name: 'Orion Arm', type: 'galactic_region' },
      { id: 'solar-system', name: 'Solar System', type: 'star_system' },
      { id: 'earth', name: 'Earth', type: 'world', subtype: 'planet' }
    ],

    /* Jurisdictions — REAL geography; provenance fields mirror the shipped
       migration. Default demo chain Earth → Plaza Midwood, plus an honest-gap
       country whose chain stops at adm 2 (geoBoundaries ships ADM0–2; deeper
       levels come from OSM and are sparse in places). */
    jurisdictions: [
      { slug: 'earth-0-earth', name: 'Earth', admLevel: 0, parent: null, isoCode: null,
        population: 8045311000, populationYear: 2023, source: 'user_defined',
        officialLanguages: ['en'], timezone: 'UTC', isCivicActive: true, authoritativeServer: null },
      { slug: 'usa-1-united-states', name: 'United States', admLevel: 1, parent: 'earth-0-earth', isoCode: 'USA',
        population: 331449281, populationYear: 2020, source: 'geoboundaries', geoboundariesId: 'USA-ADM0',
        officialLanguages: ['en'], timezone: 'America/New_York', isCivicActive: true, authoritativeServer: null },
      { slug: 'usa-2-north-carolina', name: 'North Carolina', admLevel: 2, parent: 'usa-1-united-states', isoCode: 'USA',
        population: 10439388, populationYear: 2020, source: 'geoboundaries', geoboundariesId: 'USA-ADM1-NC',
        officialLanguages: ['en'], timezone: 'America/New_York', isCivicActive: true, authoritativeServer: null },
      { slug: 'usa-3-mecklenburg-county', name: 'Mecklenburg County', admLevel: 3, parent: 'usa-2-north-carolina', isoCode: 'USA',
        population: 1115482, populationYear: 2020, source: 'geoboundaries', geoboundariesId: 'USA-ADM2-MECK',
        officialLanguages: ['en'], timezone: 'America/New_York', isCivicActive: true, authoritativeServer: null },
      { slug: 'usa-4-charlotte', name: 'Charlotte', admLevel: 4, parent: 'usa-3-mecklenburg-county', isoCode: 'USA',
        population: 874579, populationYear: 2020, source: 'osm', osmRelationId: '177415',
        officialLanguages: ['en'], timezone: 'America/New_York', isCivicActive: true, authoritativeServer: null },
      { slug: 'usa-5-plaza-midwood', name: 'Plaza Midwood', admLevel: 5, parent: 'usa-4-charlotte', isoCode: 'USA',
        population: 9463, populationYear: 2024, source: 'osm', osmRelationId: '11324559',
        officialLanguages: ['en'], timezone: 'America/New_York', isCivicActive: true, authoritativeServer: null },
      /* Honest gap: chain stops at adm 2 — no OSM-supplemented levels below. */
      { slug: 'smr-1-san-marino', name: 'San Marino', admLevel: 1, parent: 'earth-0-earth', isoCode: 'SMR',
        population: 33745, populationYear: 2023, source: 'geoboundaries', geoboundariesId: 'SMR-ADM0',
        officialLanguages: ['it'], timezone: 'Europe/Rome', isCivicActive: true, authoritativeServer: null,
        dataGap: 'OSM coverage sparse below adm 2 — chain ends here' },
      { slug: 'smr-2-serravalle', name: 'Serravalle', admLevel: 2, parent: 'smr-1-san-marino', isoCode: 'SMR',
        population: 10878, populationYear: 2023, source: 'geoboundaries', geoboundariesId: 'SMR-ADM1-SER',
        officialLanguages: ['it'], timezone: 'Europe/Rome', isCivicActive: true, authoritativeServer: null,
        dataGap: 'No adm 3+ subdivisions in source data' }
    ],
    defaultChain: ['earth-0-earth', 'usa-1-united-states', 'usa-2-north-carolina',
                   'usa-3-mecklenburg-county', 'usa-4-charlotte', 'usa-5-plaza-midwood'],

    /* Personas — ALL FICTIONAL. Default home: Plaza Midwood, Charlotte.
       standIn personas exist so every one of the 30 roles is assumable. */
    personas: [
      { id: 'amara-okafor', name: 'Amara Okafor', initials: 'AO', roles: ['R-01', 'R-02', 'R-03', 'R-04', 'R-05'], home: 'usa-5-plaza-midwood', bio: 'Civic journey persona — onboarding through full association.' },
      { id: 'diego-ramos', name: 'Diego Ramos', initials: 'DR', roles: ['R-03', 'R-04', 'R-06', 'R-07'], home: 'usa-5-plaza-midwood', bio: 'Endorsed candidate in the Charlotte approval phase.' },
      { id: 'fatima-al-rashid', name: 'Fatima Al-Rashid', initials: 'FA', roles: ['R-03', 'R-04', 'R-08'], home: 'usa-4-charlotte', bio: 'Election board member — politically neutral officer.' },
      { id: 'yuki-tanaka', name: 'Yuki Tanaka', initials: 'YT', roles: ['R-09', 'R-10'], home: 'usa-5-plaza-midwood', bio: 'Speaker of the Charlotte legislature; also a seated representative.' },
      { id: 'marcus-chen', name: 'Marcus Chen', initials: 'MC', roles: ['R-09', 'R-11', 'R-12'], home: 'usa-4-charlotte', bio: 'Representative and committee chair; sponsor of the Clean Air Act.' },
      { id: 'kwame-mensah', name: 'Kwame Mensah', initials: 'KM', roles: ['R-09', 'R-14'], home: 'usa-4-charlotte', bio: 'Executive-committee member (delegated model).' },
      { id: 'ingrid-solberg', name: 'Ingrid Solberg', initials: 'IS', roles: ['R-16'], home: 'usa-3-mecklenburg-county', bio: 'Individually elected executive (RCV winner).' },
      { id: 'lena-novak', name: 'Dr. Lena Novák', initials: 'LN', roles: ['R-19'], home: 'usa-3-mecklenburg-county', bio: 'Appointed judge — author of the Curfew Ordinance finding.' },
      { id: 'sofia-petrova', name: 'Sofia Petrova', initials: 'SP', roles: ['R-03', 'R-21'], home: 'usa-4-charlotte', bio: 'Registered advocate.' },
      { id: 'priya-sharma', name: 'Priya Sharma', initials: 'PS', roles: ['R-03', 'R-23', 'R-24'], home: 'usa-4-charlotte', bio: 'Organization agent for The Commons Party.' },
      { id: 'tomas-ferreira', name: 'Tomás Ferreira', initials: 'TF', roles: ['R-25', 'R-27'], home: 'usa-4-charlotte', bio: 'Bluefin Logistics worker; worker-elected board member.' },
      { id: 'halima-diallo', name: 'Halima Diallo', initials: 'HD', roles: ['R-29'], home: 'usa-4-charlotte', bio: 'Administrative office — parliamentary procedure and ethics.' },
      /* R-17 advisors: the top-4 runners-up in the individual executive RCV. */
      { id: 'noor-haddad', name: 'Noor Haddad', initials: 'NH', roles: ['R-17'], home: 'usa-3-mecklenburg-county', advisorRank: 1 },
      { id: 'mateo-rossi', name: 'Mateo Rossi', initials: 'MR', roles: ['R-17'], home: 'usa-3-mecklenburg-county', advisorRank: 2 },
      { id: 'aicha-traore', name: 'Aïcha Traoré', initials: 'AT', roles: ['R-17'], home: 'usa-3-mecklenburg-county', advisorRank: 3 },
      { id: 'elias-virtanen', name: 'Elias Virtanen', initials: 'EV', roles: ['R-17'], home: 'usa-3-mecklenburg-county', advisorRank: 4 },
      /* Stand-ins so the demo bar can assume every role. */
      { id: 'asha-okonkwo', name: 'Asha Okonkwo', initials: 'AO', roles: ['R-09', 'R-11', 'R-13'], home: 'usa-4-charlotte', standIn: true },
      { id: 'mei-lin-zhou', name: 'Mei-Lin Zhou', initials: 'MZ', roles: ['R-15'], home: 'usa-3-mecklenburg-county', standIn: true },
      { id: 'samuel-adeyemi', name: 'Dr. Samuel Adeyemi', initials: 'SA', roles: ['R-18'], home: 'usa-3-mecklenburg-county', standIn: true },
      { id: 'rosa-delgado', name: 'Rosa Delgado', initials: 'RD', roles: ['R-20'], home: 'usa-2-north-carolina', standIn: true },
      { id: 'omar-farouk', name: 'Omar Farouk', initials: 'OF', roles: ['R-03', 'R-22'], home: 'usa-5-plaza-midwood', standIn: true },
      { id: 'helena-brandt', name: 'Helena Brandt', initials: 'HB', roles: ['R-24', 'R-26'], home: 'usa-4-charlotte', standIn: true },
      { id: 'cyrus-tehrani', name: 'Cyrus Tehrani', initials: 'CT', roles: ['R-26', 'R-28'], home: 'usa-4-charlotte', standIn: true },
      { id: 'grace-mwangi', name: 'Grace Mwangi', initials: 'GM', roles: ['R-30'], home: 'usa-4-charlotte', standIn: true }
    ],

    /* Endorsing organizations — there is NO faction layer (§2 item 7).
       Any organization OR individual can endorse any candidate. */
    organizations: [
      { id: 'commons-party', name: 'The Commons Party', type: 'political_party', endorsementCount: 1284 },
      { id: 'green-horizon', name: 'Green Horizon Alliance', type: 'political_party', endorsementCount: 962 },
      { id: 'queen-city-chamber', name: 'Queen City Chamber of Commerce', type: 'business', endorsementCount: 312 },
      { id: 'piedmont-mutual-aid', name: 'Piedmont Mutual Aid', type: 'nonprofit', endorsementCount: 178 },
      { id: 'plaza-midwood-neighbors', name: 'Plaza Midwood Neighbors', type: 'informal', endorsementCount: 64 },
      { id: 'bluefin-logistics', name: 'Bluefin Logistics', type: 'business', ownership: 'stock', workers: 740,
        note: 'Mid-scale co-determination: 740 workers between CLK-13 (100) and CLK-14 (2,000).' },
      { id: 'northstar-equal-partners', name: 'Northstar Equal Partners', type: 'business', ownership: 'equal_partnership', workers: 38 },
      { id: 'mecklenburg-water-power', name: 'Mecklenburg Water & Power', type: 'common_good_corp', workers: 1450,
        note: 'IP perpetually public domain · Art. III §5.' },
      { id: 'cobalt-grid', name: 'Cobalt Grid Co.', type: 'business', ownership: 'stock', workers: 510,
        flags: ['monopoly_target'], note: 'Monopoly-acquisition scenario target (F-LEG-026).' }
    ],

    /* Candidates in the Charlotte approval phase. Zero-endorsement candidates
       are first-class throughout. */
    candidates: [
      { id: 'diego-ramos', name: 'Diego Ramos', election: 'elec-charlotte-2031', endorsedBy: ['commons-party'], individualEndorsements: 41, approvals: 4182, deltaDay: 3 },
      { id: 'keisha-boyd', name: 'Keisha Boyd', election: 'elec-charlotte-2031', endorsedBy: ['green-horizon'], individualEndorsements: 28, approvals: 3974, deltaDay: -1 },
      { id: 'linh-pham', name: 'Linh Pham', election: 'elec-charlotte-2031', endorsedBy: [], individualEndorsements: 12, approvals: 3551, deltaDay: 5 },
      { id: 'robert-hale', name: 'Robert Hale', election: 'elec-charlotte-2031', endorsedBy: ['queen-city-chamber', 'plaza-midwood-neighbors'], individualEndorsements: 19, approvals: 2870, deltaDay: 0 },
      { id: 'fatou-ndiaye', name: 'Fatou Ndiaye', election: 'elec-charlotte-2031', endorsedBy: ['piedmont-mutual-aid'], individualEndorsements: 33, approvals: 2641, deltaDay: -2 }
    ],

    /* One election per phase, pinned to real places (§7). */
    elections: [
      { id: 'elec-charlotte-2031', jurisdiction: 'usa-4-charlotte', kind: 'general', phase: 'approval',
        seats: 7, finalistCount: 21, finalistRule: 'X = f(seats) · CLK-21', clocks: ['CLK-18', 'CLK-21'] },
      { id: 'elec-plaza-midwood-2031', jurisdiction: 'usa-5-plaza-midwood', kind: 'general', phase: 'ranked',
        seats: 5, finalistCount: 15, ranksClose: '2031-05-30T23:59:00Z', clocks: ['CLK-21'] },
      { id: 'elec-mecklenburg-2031', jurisdiction: 'usa-3-mecklenburg-county', kind: 'general', phase: 'certifying',
        seats: 9, finalistCount: 27, certifyingSince: '2031-05-12T14:00:00Z', clocks: ['CLK-07'] }
    ],

    /* District-mapper scenario: the United States legislature scoped to North
       Carolina. Vocabulary mirrors the shipped Legislature browser. */
    districtScenario: {
      legislature: 'usa-1-united-states',
      scope: 'usa-2-north-carolina',
      sizingLaw: 'cube_root',
      sizingGloss: 'total seats = max(5, round(∛ population)) · Art. II §2 · as implemented',
      totalSeatsUS: 692,
      scopeBudget: 22,
      quota: 474517,
      allocation: 'webster',
      maps: [
        { id: 'nc-2030', name: 'NC Plan 2030', status: 'active', districts: [
          { name: 'Piedmont', seats: 9, population: 4270650, deviationPct: 0.0, contiguous: true, integrity: 'intact', chr: 0.74 },
          { name: 'Coastal Plain', seats: 8, population: 3796140, deviationPct: 0.0, contiguous: true, integrity: 'intact', chr: 0.61 },
          { name: 'Mountains & Foothills', seats: 5, population: 2372598, deviationPct: 0.0, contiguous: true, integrity: 'intact', chr: 0.68 }
        ] },
        { id: 'nc-2031-draft', name: 'NC Plan 2031 (draft)', status: 'draft', districts: [
          { name: 'Charlotte Metro', seats: 6, population: 2849000, deviationPct: 0.1, contiguous: true, integrity: 'intact', chr: 0.71 },
          { name: 'Triangle', seats: 6, population: 2843000, deviationPct: -0.1, contiguous: true, integrity: 'intact', chr: 0.66 },
          { name: 'East', seats: 5, population: 2374000, deviationPct: 0.1, contiguous: true, integrity: 'intact', chr: 0.58 },
          { name: 'West', seats: 5, population: 2373388, deviationPct: 0.0, contiguous: true, integrity: 'intact', chr: 0.63 }
        ] }
      ],
      giantExpanded: {
        slug: 'usa-2-north-carolina', fractionalSeats: 21.79,
        note: 'Giant child at United States scope — expands into its own sub-district budget (recursing down the chain).'
      },
      leafGiant: {
        name: 'Fujian (China)', scope: 'Earth-scope dataset', fractionalSeats: 9.42,
        flag: 'requires manual line-drawing',
        note: 'Leaf-giant example from the Earth-scope data — fractional seats above the constitutional ceiling with no child subdivisions. No US-scope instance exists; shown for the UI state.'
      },
      grouping: { optimal: 218, suboptimal: 41, current: 15 }
    },

    bills: [
      { id: 'bill-2031-07', title: 'Charlotte Clean Air Act', jurisdiction: 'usa-4-charlotte',
        state: 'In committee', committee: 'Environment & Infrastructure', sponsor: 'marcus-chen',
        scale: 'Charlotte (usa-4-charlotte)', scope: 'Municipal judiciary hears disputes; binds Charlotte only',
        introduced: '2031-04-02' }
    ],

    emergency: {
      jurisdiction: 'usa-3-mecklenburg-county', cause: 'natural disaster',
      label: 'Hurricane Dorinda landfall', day: 41, maxDays: 90, clock: 'CLK-03',
      invokedVia: 'F-LEG-024', renewalForm: 'F-LEG-025', judicialReview: 'pending',
      protections: 'Elections, sessions, and courts cannot be disrupted — enforced in code · Art. II §7'
    },

    challenge: {
      name: 'Novák finding on Curfew Ordinance §3',
      law: 'Curfew Ordinance §3 (Charlotte)', judge: 'lena-novak',
      finding: 'Conflicts with Art. I freedom-of-movement guarantees outside declared emergencies',
      remedy: 'Narrow §3 to declared emergencies only', timeframeDays: 60, vetoWindowDays: 30,
      vetoCloses: '2031-06-20', paths: { A: 'open', B: 'open', C: 'pending-window' },
      basis: 'Art. IV §5', clockTimeframe: 'CLK-12', clockVeto: 'CLK-11'
    },

    vacancy: {
      office: 'Charlotte legislature · seat 4', member: 'Renata Silva (resigned)',
      declaredVia: 'F-LEG-036', status: 'countback-running',
      gloss: 'Prior ballots re-run with the vacated member removed · Art. II §5'
    },
    specialElection: {
      trigger: 'countback-exhausted', windowDays: [90, 180], clock: 'CLK-04',
      scheduled: '2031-09-14', jurisdiction: 'usa-5-plaza-midwood',
      office: 'Plaza Midwood legislature · seat 2'
    },

    restorationDrill: {
      badge: 'Drill — Art. VI restoration mode', condition: 'captured',
      tier: 1, tierLabel: 'Tier 1: constituent jurisdictions elect',
      note: 'Activation conditions: countermanded / captured / destroyed. Distinct status-danger framing, still calm.'
    },
    unionDrill: {
      badge: 'Edge-case walkthrough — no live case: Earth starts united',
      instances: [
        { name: 'Aurelia (hypothetical)', host: 'aurelia.example' },
        { name: 'Meridia (hypothetical)', host: 'meridia.example' }
      ],
      basis: 'Art. V §7'
    },

    notifications: [
      { icon: 'vote', text: 'Approval phase open in Charlotte — finalist line updates daily', href: 'electoral/open-ballot.html' },
      { icon: 'alert-triangle', text: 'Emergency power active in Mecklenburg County — day 41 of 90 · judicial review pending', href: 'legislature/emergency-powers.html' },
      { icon: 'scale', text: 'Novák finding on Curfew Ordinance §3 — veto window closes 2031-06-20', href: 'judiciary/constitutional-challenge.html' }
    ]
  };

  /* --------------------------------------------------- FLOW SAMPLES (Stage 0)
     Three workflows transcribed from catalog Sheet 2 in the FROZEN flowData
     shape consumed by CGA.shell.renderFlowStepper(). They stress-test the
     contract before 80 flow pages depend on it: WF-CIV-02 (linear),
     WF-ELE-03 (failure branch + sub-workflow handoff), WF-JUD-05 (three
     parallel constitutional paths). Form IDs are canonical (§2 name-matching
     applied; the catalog cells cite drifted IDs — see MANIFEST.md). */
  var flowSamples = {
    'WF-CIV-02': {
      id: 'WF-CIV-02', name: 'Residency Establishment',
      timeScale: 'Long (residency threshold days)', trigger: 'Individual files Residency Declaration',
      actors: ['R-01', 'R-02', 'R-03'], institutions: ['I-JUR'],
      terminal: 'Jurisdictional association at every nesting level; voting & candidacy unlocked',
      basis: 'Art. I; Art. V §1', entity: 'Residency Claim',
      steps: [
        { n: 1, actor: 'R-01', action: 'Declare residency intent in a jurisdiction', form: 'F-IND-003',
          outcome: 'Residency tracking record opens; GPS ping collection starts',
          screen: { href: 'civic/residency.html', params: { role: 'R-01' } } },
        { n: 2, actor: 'System', action: 'Collect periodic location pings; evaluate long-term residential pattern', form: 'F-IND-005',
          outcome: 'Encrypted ping log accumulates toward threshold (CLK-05)',
          screen: { href: 'civic/residency.html', params: { role: 'R-01' } } },
        { n: 3, actor: 'System', action: 'Threshold days met inside declared boundary', form: 'F-IND-006',
          outcome: 'Verification record created (R-02)',
          screen: { href: 'civic/residency.html', params: { role: 'R-02' } } },
        { n: 4, actor: 'System', action: 'Resolve full nesting stack via point-in-polygon (local → Earth)', engine: 'PostGIS / Jurisdictions module',
          outcome: 'Associations created at every level (R-03)',
          screen: { href: 'civic/residency.html', params: { role: 'R-03' } } },
        { n: 5, actor: 'System', action: 'Unlock civic rights', engine: 'Constitutional Engine',
          outcome: 'Voting + candidacy unlocked automatically, no other requirements; population records updated (WF-JUR-09)',
          screen: { href: 'civic/civic-home.html', params: { role: 'R-03' } },
          branches: [{ label: 'Population records update', goto: { wf: 'WF-JUR-09', step: 1 } }] }
      ]
    },
    'WF-ELE-03': {
      id: 'WF-ELE-03', name: 'Vacancy Countback',
      timeScale: 'Short (automatic)', trigger: 'Vacancy declared for any elected office',
      actors: ['System', 'R-08'], institutions: ['I-ELB', 'I-ELE'],
      terminal: 'Replacement winner from prior ballots, or countback-failure flag',
      basis: 'Art. II §5', entity: 'Vacancy',
      steps: [
        { n: 1, actor: 'R-10', action: 'Vacancy declared', form: 'F-LEG-036',
          outcome: 'Countback engine invoked',
          screen: { href: 'legislature/oversight.html', params: { role: 'R-10' } } },
        { n: 2, actor: 'System', action: 'Re-run prior election ballots with vacated member removed as candidate', engine: 'Countback engine (hardened)',
          outcome: 'New winner found, or ballots exhausted',
          screen: { href: 'electoral/vacancy-countback.html', params: { role: 'R-08' } },
          branches: [
            { label: 'New winner found → certify', goto: 3 },
            { label: 'Exhausted → countback-failed · special election (90–180 d · CLK-04)', goto: { wf: 'WF-ELE-04', step: 1 } }
          ] },
        { n: 3, actor: 'R-08', action: 'Certify countback winner', form: 'F-ELB-004',
          outcome: 'Winner seated via F-LEG-001; committee proportionality re-checked (WF-LEG-13)',
          screen: { href: 'electoral/vacancy-countback.html', params: { role: 'R-08' } },
          branches: [{ label: 'Committee proportionality re-check', goto: { wf: 'WF-LEG-13', step: 1 } }] }
      ]
    },
    'WF-JUD-05': {
      id: 'WF-JUD-05', name: 'Constitutional Challenge & Law Remedy (Art. IV §5)',
      timeScale: 'Long', trigger: 'Challenge filed by any inhabitant',
      actors: ['R-03', 'R-19', 'R-09'], institutions: ['I-JUD', 'I-LEG'],
      terminal: 'Law amended by legislature, judgement overruled by supermajority, or law edited by the judiciary; executives enforce the outcome',
      basis: 'Art. IV §5', entity: 'Constitutional Challenge',
      steps: [
        { n: 1, actor: 'R-03', action: 'Any inhabitant files challenge: law unjustly impedes rights under the Constitution or other valid law', form: 'F-IND-016',
          outcome: 'Challenge docketed',
          screen: { href: 'judiciary/constitutional-challenge.html', params: { sc: { challenge: true } } } },
        { n: 2, actor: 'R-19', action: 'Hear (full court for significant constitutional questions · CLK-16, hardened)', engine: 'WF-JUD-03 machinery',
          outcome: 'Contradiction found, or law stands',
          screen: { href: 'judiciary/case-detail.html', params: { role: 'R-19' } },
          branches: [
            { label: 'No contradiction → law stands; opinion published', goto: 'terminal:Law stands · opinion published as commentary' },
            { label: 'Contradiction → finding issued', goto: 3 }
          ] },
        { n: 3, actor: 'R-19', action: 'Inform legislature which laws err + recommended remedy + reasonable timeframe (CLK-12) + veto window (CLK-11)', form: 'F-JDG-004',
          outcome: 'Legislative-response state opens; appears as mandatory session priority (WF-LEG-05)',
          screen: { href: 'judiciary/constitutional-challenge.html', params: { sc: { challenge: true } } },
          branches: [
            { label: 'PATH A — legislature modifies/removes within timeframe', goto: 4 },
            { label: 'PATH B — supermajority override within veto window', goto: 5 },
            { label: 'PATH C — window closes without action', goto: 6 }
          ] },
        { n: 4, actor: 'R-09', action: 'PATH A: modify/remove offending law within timeframe', engine: 'WF-LEG-06 (bill flow)',
          outcome: 'Resolved; opinions remain commentary on the law as edited',
          screen: { href: 'legislature/bills.html', params: { role: 'R-09' } },
          branches: [{ label: 'Executives enforce outcome', goto: 7 }] },
        { n: 5, actor: 'R-09', action: 'PATH B: supermajority override within veto window', form: 'F-LEG-035',
          outcome: 'Judgement overruled; law stands; all recorded',
          screen: { href: 'legislature/session-console.html', params: { role: 'R-09' } },
          branches: [{ label: 'Executives enforce outcome', goto: 7 }] },
        { n: 6, actor: 'R-19', action: 'PATH C: neither occurs by window close → judiciary applies its remedy directly', engine: 'Law-edit engine',
          outcome: 'Law text edited to be non-contradictory; adjustable settings updated if needed; version history preserved',
          screen: { href: 'judiciary/constitutional-challenge.html', params: { sc: { challenge: true } } },
          branches: [{ label: 'Executives enforce outcome', goto: 7 }] },
        { n: 7, actor: 'R-14', action: 'Executives uphold constitutional order and the outcome', engine: 'WF-EXE-07 context',
          outcome: 'Enforcement aligned to final state',
          screen: { href: 'executive/executive-actions.html', params: { role: 'R-14' } } }
      ]
    }
  };

  window.CGA.fixtures = { registry: registry, world: world, flowSamples: flowSamples };

  /* Convenience lookups */
  function indexBy(arr, key) {
    var m = {};
    (arr || []).forEach(function (x) { m[x[key]] = x; });
    return m;
  }
  window.CGA.fixtures.byId = {
    roles: indexBy(registry.roles, 'id'),
    institutions: indexBy(registry.institutions, 'id'),
    forms: indexBy(registry.forms, 'id'),
    workflows: indexBy(registry.workflows, 'id'),
    clocks: indexBy(registry.clocks, 'id'),
    entities: indexBy(registry.entities, 'id'),
    jurisdictions: indexBy(world.jurisdictions, 'slug'),
    personas: indexBy(world.personas, 'id'),
    organizations: indexBy(world.organizations, 'id')
  };
})();
