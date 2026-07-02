/* ============================================================================
   CGA MOCKUPS v2 — fixtures-v2.js  (the game layer)
   AUGMENTS v1's CGA.fixtures (registry / world / byId) — never reshapes it.
   Adds CGA.fixtures.v2 = { interactionClasses, journeys, rooms, handles,
   groups, legitimacy, live, journeyLive, achievements, bills }.

   Load order on a v2 page:
     <head>  ../assets/js/demo-state.js
     </body> ../assets/js/fixtures.js   (v1 world — loads CGA.fixtures)
             assets/js/fixtures-v2.js   (this file — augments)
             manifest.js                (v2 manifest)
             ../assets/js/icons.js
             ../assets/js/i18n.js
             assets/js/shell-v2.js

   Reuses the v1 NYC demo world verbatim: the New York County chamber (9 seats,
   seat 4 vacant), the Environment & Infrastructure committee, the Clean Air
   Act (bill-2031-07), Bluefin Logistics (740 workers, co-determination), the
   approval-phase race. Personas keep their v1 ids; v2 only adds a pseudonymous
   handle for the Matrix layer (never a legal name on the wire).
   ============================================================================ */
(function () {
  'use strict';
  var F = window.CGA && window.CGA.fixtures;
  if (!F) { if (window.console) console.error('fixtures-v2: v1 fixtures.js must load first'); return; }
  var W = F.world;

  /* ---- pseudonymous handles: persona id → @u-<handle> (Matrix display) ---- */
  var HANDLES = {
    'amara-okafor': 'amara', 'diego-ramos': 'diego', 'fatima-al-rashid': 'fatima',
    'yuki-tanaka': 'yuki', 'marcus-chen': 'marcus', 'kwame-mensah': 'kwame',
    'ingrid-solberg': 'ingrid', 'lena-novak': 'judge-ln', 'sofia-petrova': 'sofia',
    'priya-sharma': 'priya', 'tomas-ferreira': 'tomas', 'halima-diallo': 'halima',
    'asha-okonkwo': 'asha', 'omar-farouk': 'omar'
  };
  function handleFor(personaId, fallback) {
    return HANDLES[personaId] || fallback || 'resident';
  }
  function nameFor(personaId, fallback) {
    var p = F.byId.personas[personaId];
    return p ? p.name : (fallback || personaId);
  }

  /* ====================================================== INTERACTION CLASSES
     The honest map of who is acting with whom (§7). Launchpad top-level nav. */
  var interactionClasses = [
    { id: 'people', n: 1, title: 'People, together',
      blurb: 'Individuals among each other — the public square, an informal group, mutual aid, a peer trade, co-signing a petition, sitting on a jury together.',
      icon: 'users', accent: 'adm-5',
      journeys: ['form-a-group', 'mutual-aid'] },
    { id: 'orgs-people', n: 2, title: 'Organizations & people',
      blurb: 'Organizations and individuals together — joining an org as a worker or member, a board meeting, labor postings, buying from or selling as an org, a co-determination threshold crossing.',
      icon: 'building', accent: 'adm-4',
      journeys: ['start-org', 'board-meeting'] },
    { id: 'gov-itself', n: 3, title: 'A government with itself',
      blurb: 'A government acting internally — an election end to end, a committee session, deliberation on a bill, an executive-committee meeting, enacting a budget, a court case.',
      icon: 'landmark', accent: 'adm-2',
      journeys: ['election', 'committee-session', 'bill', 'court-case', 'budget'] },
    { id: 'gov-gov', n: 4, title: 'Governments with each other',
      blurb: 'Governments interacting — recognizing each other’s records, trade and treaty talks, union formation, border settlement, the shared public square.',
      icon: 'globe', accent: 'adm-1',
      journeys: ['two-governments'] },
    { id: 'gov-orgs-people', n: 5, title: 'Government with organizations & people',
      blurb: 'A government interacting with orgs and people — chartering a public service, regulating a market, the civic stipend, taxation, a public hearing, a petition and a referendum.',
      icon: 'scale', accent: 'adm-0',
      journeys: ['petition-to-referendum', 'public-service', 'stipend-and-tax'] }
  ];

  /* =============================================================== JOURNEYS
     Each is a guided lesson you can complete (§8). status: 'built-layer' is
     live in this world; 'planned-layer' is not live yet, badged "Coming soon".
     yourPart: the player's own role in this journey (falls back to the generic
     gallery line only for spectator journeys that have a room). */
  var journeys = [
    { id: 'election', cls: 'gov-itself', flagship: true, status: 'built-layer',
      title: 'An election, end to end',
      now: 'Candidates are gathering approvals in Manhattan; the forum is tonight.',
      yourPart: 'you vote this one — approve the candidates you trust, then rank them when the window opens',
      rail: ['Approval', 'Candidate forum', 'Finalist cutoff', 'Ranked vote', 'Count', 'Seated', 'First session'],
      rooms: ['forum'], reusesV1: ['electoral/open-ballot.html', 'electoral/ranked-ballot.html', 'electoral/results.html', 'electoral/election-detail.html'] },
    { id: 'committee-session', cls: 'gov-itself', status: 'built-layer',
      title: 'A committee session, live',
      now: 'The Environment & Infrastructure committee is hearing the Clean Air Act.',
      rail: ['Convene', 'Quorum', 'Agenda', 'Testimony', 'Motion', 'Committee vote', 'Report'],
      rooms: ['committee'], reusesV1: ['legislature/committee-detail.html', 'legislature/session-console.html'] },
    { id: 'bill', cls: 'gov-itself', status: 'built-layer',
      title: 'A bill becomes law',
      now: 'The Clean Air Act has cleared committee and is on the floor.',
      rail: ['Introduced', 'Committee', 'Floor reading', 'Floor vote', 'Enacted', 'Published'],
      rooms: ['legislative'], reusesV1: ['legislature/bill-detail.html', 'legislature/bills.html', 'legislature/session-console.html'] },
    { id: 'court-case', cls: 'gov-itself', status: 'built-layer',
      title: 'A court case, end to end',
      now: 'Tenant association v. Crown Ridge is in its evidence hearing.',
      rail: ['Filed', 'Panel', 'Hearings', 'Evidence', 'Jury', 'Arguments', 'Deliberation', 'Judgment', 'Opinion'],
      rooms: ['court'], reusesV1: ['judiciary/case-detail.html', 'judiciary/constitutional-challenge.html', 'judiciary/case-docket.html'] },
    { id: 'budget', cls: 'gov-itself', status: 'planned-layer', phase: 'Phase L',
      title: 'Enacting a budget',
      now: 'Revenue, the budget bill, appropriations, and the public ledger.',
      rail: ['Revenue', 'Budget bill', 'Appropriations', 'Disbursement', 'Ledger'],
      rooms: ['legislative'], reusesV1: ['legislature/session-console.html'] },
    { id: 'start-org', cls: 'orgs-people', status: 'built-layer',
      title: 'Starting an organization',
      now: 'Register, charter, seat a first board, onboard members and workers.',
      yourPart: 'you do this one — register the organization, write the charter, and seat the first board',
      rail: ['Register', 'Charter', 'First board', 'Onboard', 'Market (opt.)'],
      rooms: ['board'], reusesV1: ['organizations/org-registry.html', 'social/org-profile.html', 'organizations/board-elections.html'] },
    { id: 'board-meeting', cls: 'orgs-people', status: 'built-layer',
      title: 'Holding a board meeting',
      now: 'Bluefin Logistics — worker and owner seats deliberate together.',
      rail: ['Convene', 'Composition', 'Motions', 'Board vote', 'Minutes'],
      rooms: ['board'], reusesV1: ['organizations/board-elections.html', 'organizations/co-determination.html', 'social/org-profile.html'] },
    { id: 'form-a-group', cls: 'people', status: 'built-layer',
      title: 'An informal group forms and meets',
      now: 'Neighbors start a harbor-cleanup crew and call their first meeting.',
      yourPart: 'you do this one — start the group, invite your neighbours, and call the first meeting',
      rail: ['Create', 'Discuss', 'Call a meeting', 'Decide', 'Next steps (opt.)'],
      rooms: ['group'], reusesV1: ['groups/group-create.html'] },
    { id: 'mutual-aid', cls: 'people', status: 'planned-layer', phase: 'Phase M',
      title: 'Asking for and giving help',
      now: 'Post an assistance request; a neighbor responds; coordinate in a room.',
      yourPart: 'you do this one — post a request for help, or answer a neighbour’s',
      rail: ['Post request', 'A neighbor responds', 'Coordinate', 'Resolved'],
      rooms: ['group'], reusesV1: [] },
    { id: 'petition-to-referendum', cls: 'gov-orgs-people', status: 'built-layer',
      title: 'From a petition to a referendum',
      now: 'A participatory-budget petition is gathering signatures toward the threshold.',
      yourPart: 'you do this one — sign (or start) the petition, then vote when the referendum opens',
      rail: ['Petition', 'Signatures', 'Reaches legislature', 'Referendum', 'Town hall', 'Vote', 'Result'],
      rooms: ['townhall'], reusesV1: ['civic/petitions.html', 'civic/petition-detail.html', 'legislature/referendums.html', 'electoral/ranked-ballot.html'] },
    { id: 'public-service', cls: 'gov-orgs-people', status: 'built-layer',
      title: 'A government creates a public service',
      now: 'Charter a Common Good Corporation that trades on identical terms.',
      rail: ['Charter CGC', 'Board of Governors', 'Serves the public', 'Monopoly path (opt.)'],
      rooms: ['legislative'], reusesV1: ['organizations/cgc-detail.html', 'organizations/transfers-conversions.html', 'executive/departments.html'] },
    { id: 'stipend-and-tax', cls: 'gov-orgs-people', status: 'planned-layer', phase: 'Phase L/M',
      title: 'The money between a person and their government',
      now: 'Receive the civic stipend and file tax — the two directions of the fiscal tie.',
      yourPart: 'this one comes to you — the stipend lands in your wallet, and you file the tax side yourself',
      rail: ['Stipend run', 'Your receipt', 'Tax filing', 'Public ledger'],
      rooms: [], reusesV1: [] },
    { id: 'two-governments', cls: 'gov-gov', status: 'built-layer',
      title: 'Two governments meet, trade, and merge',
      now: 'Discover a peer, trust each other’s records, talk trade, then unite.',
      rail: ['Discover a peer', 'Trust each other’s records', 'Trade talks', 'Union or border'],
      rooms: ['townhall'], reusesV1: ['jurisdictions/federation.html', 'jurisdictions/union-formation.html', 'jurisdictions/disintermediation.html'] }
  ];

  /* ================================================ LIVE CIVIC ROOM CONFIGS
     One keystone component (shared/live-room.html) instantiated by ?variant=.
     The config-object contract is documented in mockups/v2/MANIFEST.md §Live
     Room. Every meeting type in §4 is a config here.

     Fields: variant, title, jurisdiction(slug), status{state,label}, chairRole,
     chair{handle,name,seat?}, clocks{agendaItem,speaking}(seconds, static),
     agenda[]{position,locked,kind,title,status,current}, floor{kind,title,body,
     form,citation,deepLink}, vote(null|{...}), presence[]{handle,name,seat?,
     role,online,speaking?}, queue[]{handle,name,seat?,reason}, floorHolder,
     chat[]{handle,name,seat?,body,testimony?}, voice{enabled,participants[],
     residencyGated}, translation{from,to,isPrivate,rail}, record[]{handle,body,
     sealState}, residencyGated(bool), galleryNote, forms[], chairControls[],
     reusesV1[], productionPages[], constitutionalOrder(bool). */

  function P(personaId, seat, role, extra) {
    return Object.assign({ handle: handleFor(personaId), name: nameFor(personaId), persona: personaId, seat: seat || null, role: role || 'member', online: true }, extra || {});
  }
  /* a few non-persona residents (gallery / queue) — pseudonymous only */
  function G(handle, role, extra) {
    return Object.assign({ handle: handle, name: null, seat: null, role: role || 'gallery', online: true }, extra || {});
  }

  var rooms = {
    /* ---------------------------------------------- COMMITTEE HEARING ---- */
    committee: {
      variant: 'committee',
      title: 'Environment & Infrastructure — hearing on the New York County Clean Air Act',
      jurisdiction: 'usa-3-new-york-county',
      status: { state: 'open', label: 'Open · in session' },
      chairRole: 'chair', chair: P('marcus-chen', 2, 'chair'),
      clocks: { agendaItem: 1140, speaking: 180 },
      constitutionalOrder: false,
      agenda: [
        { position: 1, locked: false, kind: 'committee_report', title: 'Call to order & last minutes', status: 'done' },
        { position: 2, locked: false, kind: 'statement', title: 'Public testimony — Clean Air Act', status: 'in_progress', current: true },
        { position: 3, locked: false, kind: 'motion', title: 'Motion to refer to the floor', status: 'pending' }
      ],
      floor: { kind: 'testimony', title: 'Public testimony on the Clean Air Act',
        body: 'Residents of New York County may speak on bill-2031-07. The committee hears testimony into the public record, then decides whether to refer the bill to the floor.',
        form: 'F-LEG-006', citation: 'Statements entered verbatim into the immutable public record',
        deepLink: 'legislature/committee-detail.html' },
      vote: { question: 'Refer the Clean Air Act to the floor?',
        method: { label: 'Passes at a majority of all 3 committee members — not those present', citation: 'Committee majority' },
        mode: 'unicameral', thresholdClass: 'committee_majority',
        serving: 3, requiredYes: 2, quorum: { present: 3, required: 2 },
        tallies: null, outcome: 'pending',
        gloss: 'The deciding vote opens the moment the motion is moved.' },
      presence: [
        P('marcus-chen', 2, 'chair'),
        P('asha-okonkwo', 5, 'member'),
        G('u-msantos', 'member', { name: null, seat: 7, handleName: 'Maribel Santos' }),
        P('amara-okafor', null, 'floor', { speaking: true }),
        G('u-tamb', 'gallery'), G('u-greenwood', 'gallery'), G('u-pier7', 'gallery')
      ],
      queue: [
        { handle: 'u-pier7', name: null, reason: 'Harbor air-quality readings' },
        { handle: 'u-greenwood', name: null, reason: 'Asthma rates near the depot' }
      ],
      floorHolder: 'amara',
      chat: [
        { handle: 'marcus', name: 'Marcus Chen', seat: 'legislature_member', body: 'The chair recognizes Amara Okafor for three minutes.' },
        { handle: 'amara', name: 'Amara Okafor', body: 'Thank you, chair. The depot readings exceed the planetary baseline on still days — I will file these as testimony.', testimony: true },
        { handle: 'u-tamb', name: null, body: 'Watching from the gallery — can non-residents speak?' }
      ],
      voice: { enabled: true, participants: ['marcus', 'amara', 'asha'], residencyGated: true },
      translation: { from: 'English', to: 'English', isPrivate: false, rail: 'server-local' },
      record: [
        { handle: 'amara', body: 'Depot readings exceed the planetary baseline on still days.', sealState: 'recorded', recordHref: 'system/public-records.html' },
        { handle: 'marcus', body: 'Committee called to order; quorum of 3 present.', sealState: 'recorded', recordHref: 'system/public-records.html' }
      ],
      residencyGated: true,
      galleryNote: 'You can watch this hearing. Only residents of New York County may take the floor or testify.',
      forms: ['F-CHR-001', 'F-CHR-002', 'F-LEG-006', 'F-LEG-007', 'F-CHR-003', 'F-CHR-004', 'F-SOC-002'],
      chairControls: ['Call to order', 'Recognize the next speaker', 'Start the speaking clock', 'Take the motion', 'Call the vote', 'File the report'],
      reusesV1: ['legislature/committee-detail.html'],
      productionPages: ['resources/js/Pages/Legislature/CommitteeDetail.vue', 'resources/js/Pages/Civic/MatrixCommons.vue']
    },

    /* --------------------------------------------- LEGISLATIVE SESSION ---- */
    legislative: {
      variant: 'legislative',
      title: 'New York County legislature — floor session',
      jurisdiction: 'usa-3-new-york-county',
      status: { state: 'open', label: 'Open · in session' },
      chairRole: 'speaker', chair: P('yuki-tanaka', 1, 'chair'),
      clocks: { agendaItem: 900, speaking: 300 },
      constitutionalOrder: true,
      agenda: [
        { position: 1, locked: true, kind: 'emergency_powers', title: 'No outstanding emergency powers', status: 'none' },
        { position: 2, locked: true, kind: 'constitutional_matters', title: 'Novák finding on Curfew Ordinance §3 — legislative response', status: 'in_progress', current: true },
        { position: 3, locked: false, kind: 'committee_report', title: 'Clean Air Act — reported from committee', status: 'pending' },
        { position: 4, locked: false, kind: 'motion', title: 'Floor reading & vote — Clean Air Act', status: 'pending' }
      ],
      floor: { kind: 'bill_reading', title: 'Floor reading — New York County Clean Air Act (bill-2031-07)',
        body: 'The bill reported from the Environment & Infrastructure committee. The floor reads, deliberates, and votes. An absent member counts the same as a no; the denominator never shrinks.',
        form: 'F-LEG-004', citation: 'Floor vote · ordinary majority of all serving',
        deepLink: 'legislature/bill-detail.html' },
      vote: { question: 'Pass the New York County Clean Air Act on the floor?',
        method: { label: 'Passes at a majority of all 8 serving members — present or not', citation: 'Majority of all serving' },
        mode: 'unicameral', thresholdClass: 'majority',
        serving: 8, requiredYes: 5, quorum: { present: 7, required: 5 },
        tallies: { yes: 4, no: 2, abstain: 1 }, outcome: 'pending',
        gloss: 'The count is measured against every serving seat — an absent member counts the same as a no.' },
      presence: [
        P('yuki-tanaka', 1, 'chair', { tenure: 9, perf: 96 }),
        P('marcus-chen', 2, 'floor', { speaking: true, tenure: 6, perf: 91 }),
        P('kwame-mensah', 3, 'member', { tenure: 5, perf: 84 }),
        { handle: 'u-seat4', name: null, seat: 4, role: 'vacant', online: false, vacant: true, tenure: 0, perf: 0 },
        P('asha-okonkwo', 5, 'member', { tenure: 4, perf: 88 }),
        G('u-jpetersen', 'member', { seat: 6, tenure: 3, perf: 72 }), G('u-msantos', 'member', { seat: 7, tenure: 2, perf: 78 }),
        G('u-anwosu', 'member', { seat: 8, tenure: 2, perf: 66 }), G('u-laronov', 'member', { seat: 9, tenure: 1, perf: 61 }),
        G('u-harborwatch', 'gallery'), G('u-tamb', 'gallery')
      ],
      queue: [
        { handle: 'u-anwosu', name: null, seat: 8, reason: 'Speak in favor' },
        { handle: 'u-jpetersen', name: null, seat: 6, reason: 'Cost amendment' }
      ],
      floorHolder: 'marcus',
      chat: [
        { handle: 'yuki', name: 'Yuki Tanaka', seat: 'speaker', body: 'Slot 2 is constitutional — the Novák response is before us before any general business.' },
        { handle: 'marcus', name: 'Marcus Chen', seat: 'legislature_member', body: 'The Clean Air Act is reported favorably. I move it to a floor vote.' },
        { handle: 'u-harborwatch', name: null, body: 'Gallery here — glad to see the readings on the record.' }
      ],
      voice: { enabled: true, participants: ['yuki', 'marcus', 'kwame', 'asha'], residencyGated: true },
      translation: { from: 'English', to: 'Español', isPrivate: false, rail: 'server-local' },
      record: [
        { handle: 'marcus', body: 'I move the Clean Air Act to a floor vote.', sealState: 'recorded', recordHref: 'system/public-records.html' },
        { handle: 'yuki', body: 'Constitutional matters take slot 2 by order.', sealState: 'sealing', recordHref: 'system/public-records.html' }
      ],
      residencyGated: true,
      galleryNote: 'You can watch this session. Only seated members vote; only residents of New York County may speak from the floor.',
      forms: ['F-SPK-001', 'F-SPK-002', 'F-SPK-003', 'F-LEG-002', 'F-LEG-007', 'F-LEG-004', 'F-SPK-004', 'F-LEG-006', 'F-SPK-009'],
      chairControls: ['Call & open the session', 'Publish the quorum count', 'Advance the agenda item', 'Recognize the next speaker', 'Call the floor vote', 'Break a tie (tie only)', 'Adjourn & seal the minutes'],
      reusesV1: ['legislature/session-console.html'],
      productionPages: ['resources/js/Pages/Legislature/SessionConsole.vue', 'resources/js/Components/Legislature/VoteTally.vue', 'resources/js/Components/Legislature/AgendaStrip.vue']
    },

    /* --------------------------------------- EXECUTIVE-COMMITTEE MEETING -- */
    exec: {
      variant: 'exec',
      title: 'New York County executive committee — deliberation',
      jurisdiction: 'usa-3-new-york-county',
      status: { state: 'open', label: 'Open · in session' },
      chairRole: 'facilitator', chair: P('kwame-mensah', null, 'chair'),
      clocks: { agendaItem: 720, speaking: 240 },
      constitutionalOrder: false,
      agenda: [
        { position: 1, locked: false, kind: 'committee_report', title: 'Department reports', status: 'done' },
        { position: 2, locked: false, kind: 'statement', title: 'Emergency-shelter hours — deliberation', status: 'in_progress', current: true },
        { position: 3, locked: false, kind: 'motion', title: 'Decision — equal-power vote', status: 'pending' }
      ],
      floor: { kind: 'statement', title: 'Equal-power deliberation — extend emergency-shelter hours',
        body: 'A committee executive: 5+ members of equal voting power deliberate and decide together (the UK model). No single chief; the chair facilitates and votes as an equal.',
        form: 'F-LEG-007', citation: 'Committee executive — equal voting power',
        deepLink: 'executive/executive-home.html' },
      vote: { question: 'Extend emergency-shelter operating hours?',
        method: { label: 'Passes at a majority of all 5 equal members', citation: 'Equal-power executive' },
        mode: 'unicameral', thresholdClass: 'majority',
        serving: 5, requiredYes: 3, quorum: { present: 5, required: 3 },
        tallies: { yes: 3, no: 1, abstain: 1 }, outcome: 'adopted',
        gloss: 'Every member of a committee executive votes with equal power.' },
      presence: [
        P('kwame-mensah', null, 'chair'),
        G('u-execb', 'member'), G('u-execc', 'member'), G('u-execd', 'member'), G('u-exece', 'member'),
        G('u-shelters', 'gallery')
      ],
      queue: [],
      floorHolder: 'kwame',
      chat: [
        { handle: 'kwame', name: 'Kwame Mensah', seat: 'exec_seat', body: 'We have five equal voices. I will call the question once everyone has spoken.' },
        { handle: 'u-execb', name: null, body: 'In favor — the cold snap warrants it.' }
      ],
      voice: { enabled: true, participants: ['kwame', 'u-execb', 'u-execc'], residencyGated: true },
      translation: { from: 'English', to: 'English', isPrivate: false, rail: 'server-local' },
      record: [
        { handle: 'kwame', body: 'Motion adopted 3–1 (1 abstain): shelter hours extended.', sealState: 'recorded', recordHref: 'system/public-records.html' }
      ],
      residencyGated: true,
      galleryNote: 'You can watch. Only the seated executive committee deliberates and decides here.',
      forms: ['F-LEG-007', 'F-LEG-006'],
      chairControls: ['Open the meeting', 'Recognize the next member', 'Call the question', 'Declare the result', 'Adjourn'],
      reusesV1: ['executive/executive-home.html'],
      productionPages: ['resources/js/Pages/Executive/Home.vue']
    },

    /* ------------------------------------------------- ORG BOARD MEETING -- */
    board: {
      variant: 'board',
      title: 'Bluefin Logistics — board meeting',
      jurisdiction: 'usa-3-queens-county',
      status: { state: 'open', label: 'Open · in session' },
      chairRole: 'chair', chair: P('tomas-ferreira', null, 'chair', { jointChair: true }),
      clocks: { agendaItem: 900, speaking: 300 },
      constitutionalOrder: false,
      composition: { workerSeats: 2, ownerSeats: 3, total: 5, workers: 740, threshold: 100, parity: 2000 },
      agenda: [
        { position: 1, locked: false, kind: 'committee_report', title: 'Quarter in review', status: 'done' },
        { position: 2, locked: false, kind: 'statement', title: 'New depot hires — co-determination', status: 'in_progress', current: true },
        { position: 3, locked: false, kind: 'motion', title: 'Board motion — approve the hiring plan', status: 'pending' }
      ],
      floor: { kind: 'motion', title: 'A board meeting is the org’s own rules of order',
        body: 'Bluefin has 740 workers — above the 100-worker first-seat threshold, below the 2,000 parity threshold. The board seats 2 worker representatives and 3 owner representatives; the chair is elected jointly by the whole board. This is not a government, so there is no constitutional agenda lock.',
        form: 'F-ORG-004', citation: 'Worker representation from 100 employees; parity at 2,000',
        deepLink: 'organizations/board-elections.html' },
      vote: { question: 'Approve the depot hiring plan?',
        method: { label: 'Passes at a majority of the full 5-seat board', citation: 'The org’s own rules of order' },
        mode: 'unicameral', thresholdClass: 'majority',
        serving: 5, requiredYes: 3, quorum: { present: 5, required: 3 },
        tallies: { yes: 4, no: 1, abstain: 0 }, outcome: 'adopted',
        gloss: 'Worker and owner seats vote together; a new hire can move the org toward worker–owner parity.' },
      presence: [
        P('tomas-ferreira', null, 'chair', { track: 'worker' }),
        G('u-worker2', 'member', { track: 'worker' }),
        G('u-owner1', 'member', { track: 'owner' }), G('u-owner2', 'member', { track: 'owner' }), G('u-owner3', 'member', { track: 'owner' })
      ],
      queue: [],
      floorHolder: 'tomas',
      chat: [
        { handle: 'tomas', name: 'Tomás Ferreira', seat: 'worker', body: 'Forty new recurring hires would push us toward parity sooner — worth noting for the seat math.' },
        { handle: 'u-owner1', name: null, body: 'Agreed on the plan; let’s vote.' }
      ],
      voice: { enabled: true, participants: ['tomas', 'u-owner1', 'u-owner2'], residencyGated: false },
      translation: { from: 'English', to: 'English', isPrivate: true, rail: 'server-local' },
      record: [
        { handle: 'tomas', body: 'Hiring plan approved 4–1.', sealState: 'live', recordHref: null }
      ],
      residencyGated: false,
      galleryNote: 'A board meeting is private to the organization — it follows the org’s own rules of order, not a jurisdiction’s.',
      forms: ['F-ORG-003', 'F-ORG-004', 'F-IND-014'],
      chairControls: ['Open the meeting', 'Recognize a member', 'Take the board motion', 'Call the board vote', 'Record the minutes'],
      reusesV1: ['organizations/board-elections.html', 'organizations/co-determination.html'],
      productionPages: ['resources/js/Pages/Organizations/BoardElections.vue', 'resources/js/Pages/Organizations/CoDetermination.vue']
    },

    /* ------------------------------------------------------ COURT HEARING -- */
    court: {
      variant: 'court',
      title: 'Tenant association v. Crown Ridge LLC — evidence hearing',
      jurisdiction: 'usa-3-new-york-county',
      status: { state: 'open', label: 'Open · in session' },
      chairRole: 'presiding_judge', chair: P('lena-novak', null, 'chair'),
      clocks: { agendaItem: 1800, speaking: 600 },
      constitutionalOrder: false,
      agenda: [
        { position: 1, locked: false, kind: 'committee_report', title: 'Appearances & panel seated (3 judges)', status: 'done' },
        { position: 2, locked: false, kind: 'statement', title: 'Examination of evidence', status: 'in_progress', current: true },
        { position: 3, locked: false, kind: 'motion', title: 'Cross-examination', status: 'pending' }
      ],
      floor: { kind: 'argument', title: 'Advocate examining a witness — on the record',
        body: 'The presiding judge chairs; advocates hold the floor in turn to examine and cross-examine. The gallery watches; a jury, if empaneled, is a separate protected presence. The record strip is the court record.',
        form: 'F-JDG-002', citation: 'Proceedings are public record',
        deepLink: 'judiciary/case-detail.html' },
      vote: null,
      presence: [
        P('lena-novak', null, 'chair', { gavel: true }),
        G('u-judge2', 'member', { role: 'member' }), G('u-judge3', 'member', { role: 'member' }),
        P('sofia-petrova', null, 'floor', { advocate: true, speaking: true, side: 'plaintiff' }),
        G('u-advocate-def', 'floor', { advocate: true, side: 'defense' }),
        G('u-galleryx', 'gallery'), G('u-galleryy', 'gallery')
      ],
      queue: [
        { handle: 'u-advocate-def', name: null, reason: 'Cross-examination' }
      ],
      floorHolder: 'sofia',
      chat: [
        { handle: 'judge-ln', name: 'Dr. Lena Novák', seat: 'judicial', body: 'The court recognizes counsel for the tenant association. You may examine the witness.' },
        { handle: 'sofia', name: 'Sofia Petrova', seat: 'judicial', body: 'Thank you, your honor. Exhibit C — the maintenance log — is entered.' }
      ],
      voice: { enabled: true, participants: ['judge-ln', 'sofia', 'u-advocate-def'], residencyGated: false },
      translation: { from: 'English', to: 'English', isPrivate: false, rail: 'server-local' },
      record: [
        { handle: 'sofia', body: 'Exhibit C (maintenance log) entered into evidence.', sealState: 'recorded', recordHref: 'system/public-records.html' },
        { handle: 'judge-ln', body: 'Counsel for the tenant association recognized.', sealState: 'recorded', recordHref: 'system/public-records.html' }
      ],
      residencyGated: false,
      galleryNote: 'Court proceedings are public — anyone may watch. Only the seated advocates and the bench take the floor; the jury, when empaneled, is protected and separate.',
      forms: ['F-JDG-001', 'F-JDG-002', 'F-JDG-003'],
      chairControls: ['Open the hearing', 'Recognize counsel', 'Admit evidence', 'Order the jury draw', 'Recess to chambers', 'Publish the opinion'],
      reusesV1: ['judiciary/case-detail.html'],
      productionPages: ['resources/js/Pages/Judiciary/CaseDetail.vue']
    },

    /* ----------------------------------------------------- CANDIDATE FORUM - */
    forum: {
      variant: 'forum',
      title: 'Manhattan candidate forum — approval phase',
      jurisdiction: 'usa-3-new-york-county',
      status: { state: 'open', label: 'Open · forum in progress' },
      chairRole: 'facilitator', chair: P('fatima-al-rashid', null, 'chair'),
      clocks: { agendaItem: 5400, speaking: 120 },
      constitutionalOrder: false,
      agenda: [
        { position: 1, locked: false, kind: 'statement', title: 'Opening statements (2 min each)', status: 'in_progress', current: true },
        { position: 2, locked: false, kind: 'statement', title: 'Resident questions', status: 'pending' },
        { position: 3, locked: false, kind: 'committee_report', title: 'Closing & where to approve', status: 'pending' }
      ],
      floor: { kind: 'statement', title: 'Candidates hold the floor in turn',
        body: 'An election runs in two phases. First the approval phase: anyone — a resident or an organization — may endorse a candidate, for any reason, and in an election that endorsement is your approval. Then the ranking window opens, where you rank the approved field. Here in the forum, candidates each get the floor for a fixed time; approving is a separate, secret act, and nothing here changes your approvals.',
        form: null, citation: 'Voting and candidacy require nothing beyond residency',
        deepLink: 'electoral/open-ballot.html' },
      vote: null,
      presence: [
        P('fatima-al-rashid', null, 'chair'),
        P('diego-ramos', null, 'floor', { candidate: true, speaking: true }),
        G('u-keisha', 'member', { candidate: true, handleName: 'Keisha Boyd' }),
        G('u-linh', 'member', { candidate: true, handleName: 'Linh Pham' }),
        G('u-wren', 'member', { candidate: true, handleName: 'Wren Ashby' }),
        G('u-voter1', 'gallery'), G('u-voter2', 'gallery'), G('u-voter3', 'gallery'), G('u-voter4', 'gallery')
      ],
      queue: [
        { handle: 'u-voter1', name: null, reason: 'Question on transit' },
        { handle: 'u-voter3', name: null, reason: 'Question on housing' }
      ],
      floorHolder: 'diego',
      chat: [
        { handle: 'fatima', name: 'Fatima Al-Rashid', seat: null, body: 'Each candidate has two minutes. Diego Ramos, you have the floor.' },
        { handle: 'diego', name: 'Diego Ramos', body: 'Frequent buses, fewer evictions, open books. Approve whoever you trust — approve as many as you like.' },
        { handle: 'u-voter2', name: null, body: 'Reminder: approvals are secret and you can approve more than one.' }
      ],
      voice: { enabled: true, participants: ['fatima', 'diego'], residencyGated: true },
      translation: { from: 'English', to: 'हिन्दी', isPrivate: false, rail: 'server-local' },
      record: [
        { handle: 'diego', body: 'Opening statement — transit, housing, transparency.', sealState: 'live', recordHref: null }
      ],
      residencyGated: true,
      galleryNote: 'Anyone may watch the forum. Only residents may ask from the floor; endorsing — your approval — happens on the ballot and is always secret. After approval comes the ranking window.',
      forms: [],
      chairControls: ['Open the forum', 'Recognize the next candidate', 'Start the speaking clock', 'Open resident questions', 'Close & link the ballot'],
      reusesV1: ['electoral/open-ballot.html', 'social/profile.html (Candidacy tab)'],
      productionPages: ['resources/js/Pages/Elections/OpenBallot.vue']
    },

    /* --------------------------------------------------- REFERENDUM TOWN HALL */
    townhall: {
      variant: 'townhall',
      title: 'Town hall — participatory-budget referendum',
      jurisdiction: 'usa-3-new-york-county',
      status: { state: 'open', label: 'Open · deliberation' },
      chairRole: 'facilitator', chair: P('halima-diallo', null, 'chair'),
      clocks: { agendaItem: 3600, speaking: 180 },
      constitutionalOrder: false,
      agenda: [
        { position: 1, locked: false, kind: 'statement', title: 'The question on the ballot', status: 'done' },
        { position: 2, locked: false, kind: 'statement', title: 'Open deliberation', status: 'in_progress', current: true },
        { position: 3, locked: false, kind: 'committee_report', title: 'Where & when to vote', status: 'pending' }
      ],
      floor: { kind: 'statement', title: 'Open deliberation before the vote window',
        body: 'A referendum delegated from a petition. Residents deliberate the question openly before the jurisdiction-wide vote. The vote itself happens on the ballot at the matching threshold — this room is for persuasion, not counting.',
        form: 'F-LEG-006', citation: 'Referendum question',
        deepLink: 'legislature/referendums.html' },
      vote: null,
      presence: [
        P('halima-diallo', null, 'chair'),
        P('omar-farouk', null, 'floor', { speaking: true }),
        G('u-resident-a', 'member'), G('u-resident-b', 'member'),
        G('u-watch1', 'gallery'), G('u-watch2', 'gallery'), G('u-watch3', 'gallery')
      ],
      queue: [
        { handle: 'u-resident-a', name: null, reason: 'Support — 2% pilot' },
        { handle: 'u-resident-b', name: null, reason: 'Concern — admin cost' }
      ],
      floorHolder: 'omar',
      chat: [
        { handle: 'halima', name: 'Halima Diallo', seat: null, body: 'This is deliberation only — the vote is on the ballot. Omar Farouk has the floor.' },
        { handle: 'omar', name: 'Omar Farouk', body: 'A 2% participatory pilot puts the budget in residents’ hands once a year. I’ll file my reasoning as testimony.', testimony: true }
      ],
      voice: { enabled: true, participants: ['halima', 'omar'], residencyGated: true },
      translation: { from: 'English', to: 'العربية', isPrivate: false, rail: 'server-local' },
      record: [
        { handle: 'omar', body: 'Reasoning in support of the 2% participatory-budget pilot.', sealState: 'sealing', recordHref: 'system/public-records.html' }
      ],
      residencyGated: true,
      galleryNote: 'Anyone may watch. Only residents of New York County may take the floor; the referendum is decided on the ballot, not here.',
      forms: ['F-LEG-006', 'F-SOC-002', 'F-LEG-023'],
      chairControls: ['Open the town hall', 'Recognize the next speaker', 'Start the speaking clock', 'Summarize & link the ballot', 'Close'],
      reusesV1: ['legislature/referendums.html', 'civic/petition-detail.html'],
      productionPages: ['resources/js/Pages/Legislature/Referendums.vue']
    },

    /* ------------------------------------------ INFORMAL-GROUP FORMAL MEETING */
    group: {
      variant: 'group',
      title: 'Harbor Cleanup Crew — first meeting',
      jurisdiction: 'usa-3-new-york-county',
      status: { state: 'open', label: 'Open · facilitated' },
      chairRole: 'facilitator', chair: P('amara-okafor', null, 'chair'),
      clocks: { agendaItem: 1800, speaking: 180 },
      constitutionalOrder: false,
      meetingType: { kind: 'facilitated', label: 'Facilitated discussion — a chair and a speaker queue, no binding vote', options: ['casual', 'facilitated', 'formal'] },
      agenda: [
        { position: 1, locked: false, kind: 'statement', title: 'Why we’re here', status: 'done' },
        { position: 2, locked: false, kind: 'statement', title: 'Pick a first cleanup date', status: 'in_progress', current: true },
        { position: 3, locked: false, kind: 'committee_report', title: 'Next steps (optional)', status: 'pending' }
      ],
      floor: { kind: 'statement', title: 'A voluntary group, meeting on its own terms',
        body: 'This is an informal affinity group — neighbors who chose to associate. The group picked a facilitated meeting: a chair and a speaker queue, but no binding vote. Whatever the group decides binds only the group, never a jurisdiction.',
        form: null, citation: 'Freedom of association — voluntary, no constitutional force',
        deepLink: null },
      vote: null,
      decisionNote: 'This is your group’s own decision, not a law.',
      presence: [
        P('amara-okafor', null, 'chair'),
        G('u-neighbor1', 'member'), G('u-neighbor2', 'member', { speaking: true }), G('u-neighbor3', 'member'),
        G('u-curious1', 'gallery')
      ],
      queue: [
        { handle: 'u-neighbor1', name: null, reason: 'Saturday works' },
        { handle: 'u-neighbor3', name: null, reason: 'Suggest Pier 7' }
      ],
      floorHolder: 'u-neighbor2',
      chat: [
        { handle: 'amara', name: 'Amara Okafor', body: 'No rules of order tonight beyond taking turns. Who has a date in mind?' },
        { handle: 'u-neighbor2', name: null, body: 'First Saturday next month, Pier 7?' }
      ],
      voice: { enabled: true, participants: ['amara', 'u-neighbor2'], residencyGated: false },
      translation: { from: 'English', to: 'English', isPrivate: true, rail: 'server-local' },
      record: [],
      residencyGated: false,
      galleryNote: 'A voluntary group sets its own rules. Joining confers no governance power — membership is private to each member.',
      forms: [],
      chairControls: ['Open the meeting', 'Recognize the next person', 'Pick a meeting type', 'Summarize the decision', 'Suggest next steps'],
      nextSteps: ['Register as an organization', 'File a petition'],
      reusesV1: ['groups/groups-home.html'],
      productionPages: ['resources/js/Pages/Civic/MatrixCommons.vue']
    }
  };

  /* ==================================================== GROUPS / LEGITIMACY
     Light fixtures consumed by Stages 3–5. (The economy fixtures live in
     fixtures-econ.js — pages read F.v2.econ.) */
  var groups = {
    spaces: [
      { id: 'grp-harbor', name: 'Harbor Cleanup Crew', purpose: 'Keep the Manhattan waterfront clean', members: 23, privacy: 'open', room: 'group', meeting: 'group' },
      { id: 'grp-reading', name: 'Riverside Reading Circle', purpose: 'Monthly book discussion', members: 11, privacy: 'invite', room: null },
      { id: 'grp-transit', name: 'Better Buses Now', purpose: 'Advocate for frequent transit', members: 64, privacy: 'open', room: null }
    ],
    meetingTypes: [
      { kind: 'casual', label: 'Casual open discussion', binding: false, machinery: 'no rules' },
      { kind: 'facilitated', label: 'Facilitated discussion', binding: false, machinery: 'a chair + a speaker queue + timers' },
      { kind: 'formal', label: 'Formal meeting', binding: 'the group only', machinery: 'chair + agenda + motions + a recorded internal vote by the group’s own rule' }
    ],
    /* ---- EPHEMERAL CONVERSATIONS (v3) — direct messages + parties.
       A "group" here is a transient conversation, like an MMO party/raid: text,
       files, voice, and video, just like a standing room but temporary. The same
       toolkit serves a 1:1 DM and a multi-person party. A party that wants to
       last can coalesce into a standing organization (a guild / community). */
    toolkit: [
      { key: 'text', label: 'Message', icon: 'message-square' },
      { key: 'file', label: 'File', icon: 'file-text' },
      { key: 'voice', label: 'Voice', icon: 'volume' },
      { key: 'video', label: 'Video', icon: 'play' },
      { key: 'share', label: 'Share', icon: 'external-link' }
    ],
    conversations: [
      { id: 'dm-marcus', kind: 'dm', with: 'marcus', title: 'Marcus Chen', when: '2m', unread: 1, live: false,
        participants: ['amara', 'marcus'],
        messages: [
          { from: 'marcus', when: '9:02', text: 'Got your depot readings — bringing them to the committee tonight.' },
          { from: 'amara', when: '9:04', text: 'Thank you. Attached the still-day numbers.', attach: { type: 'file', label: 'depot-air-readings.csv' } },
          { from: 'marcus', when: '9:05', text: 'Perfect. Want to call in for two minutes during testimony?' }
        ] },
      { id: 'dm-pier7', kind: 'dm', with: 'u-pier7', title: '@u-pier7', when: '1h', unread: 0, live: false,
        participants: ['amara', 'u-pier7'],
        messages: [
          { from: 'u-pier7', when: '8:10', text: 'Pier 7 looks great after Saturday. Same time next week?' },
          { from: 'amara', when: '8:15', text: 'Yes! Shared the cleanup map.', attach: { type: 'share', label: 'Harbor cleanup map' } }
        ] },
      { id: 'party-saturday', kind: 'party', title: 'Saturday crew (planning)', members: 5, when: '12m', unread: 3, live: true, ephemeral: true,
        participants: ['amara', 'u-pier7', 'noor', 'diego'],
        messages: [
          { from: 'noor', when: '6:30', text: 'Who’s in for the early start?' },
          { from: 'diego', when: '6:45', text: 'Me. I’ll record a clip of the route.', attach: { type: 'video', label: 'Route walkthrough · 1:12' } },
          { from: 'amara', when: '9:00', text: 'Calling a quick voice huddle at 7 — jump in. This group can dissolve after Saturday.', attach: { type: 'voice', label: 'Voice huddle · 0:42' } }
        ] },
      { id: 'party-harbor', kind: 'party', linkSpace: 'grp-harbor', title: 'Harbor Cleanup Crew', members: 23, when: '3h', unread: 0, live: false,
        participants: ['amara', 'u-pier7', 'u-harborwatch', 'u-greenwood'],
        messages: [
          { from: 'u-harborwatch', when: '8:40', text: 'Gloves and bags sorted for Saturday.' },
          { from: 'u-greenwood', when: '8:51', text: 'I can bring the cargo bike for hauling.', attach: { type: 'file', label: 'route.gpx' } },
          { from: 'amara', when: '8:55', text: 'This crew keeps happening — I’ve set us up as a standing group so the schedule sticks.' }
        ] }
    ],
    note: 'A group message is a temporary conversation — talk, files, voice, and video, just like a standing room but passing. It confers no governance power, and it’s private — like a ballot, only the people in it can read it. A group that wants to last can become a standing group or an organization.'
  };

  /* ---- LIVE FEED (today.html / my-civic-life.html) ----------------------
     ISO targets are computed relative to load so countdowns always read live
     without a backend. forFootprint() returns rows the active persona can see
     (watch is universal; act is residency-gated — the page enforces that copy).
     'rows' carry a scenarioFlag honored by the demo bar. */
  var nowMs = Date.now();
  function inMin(m) { return new Date(nowMs + m * 60000).toISOString(); }
  var live = {
    rail: 'You watch everything; you act where you reside. A ballot row shows only that voting is OPEN — never how you voted.',
    rows: [
      { id: 'lv-session', kind: 'session', icon: 'landmark', title: 'Council — regular session', what: 'The chamber is in session; the locked agenda is on item 2.', part: 'Watch from the gallery, or take the floor if you reside here.', jurisdiction: 'usa-3-new-york-county', status: 'live', pill: { tone: 'live', label: 'Live now', tip: 'In session · decisions need a majority of ALL members, present or not' }, form: { name: 'Session', id: 'F-SPK-001' }, target: { kind: 'closesAt', iso: inMin(74) }, to: { rel: 'shared/live-room.html?variant=legislative' }, scenarioFlag: 'liveSession' },
      { id: 'lv-committee', kind: 'committee', icon: 'landmark', title: 'Environment & Infrastructure — hearing', what: 'Public testimony on the Clean Air Act is open.', part: 'Add testimony to the record if you reside in the district.', jurisdiction: 'usa-3-new-york-county', status: 'live', pill: { tone: 'live', label: 'Taking testimony' }, form: { name: 'Testimony', id: 'F-SOC-002' }, target: { kind: 'closesAt', iso: inMin(38) }, to: { rel: 'shared/live-room.html?variant=committee' }, scenarioFlag: 'liveSession' },
      { id: 'lv-ballot', kind: 'election', icon: 'vote', title: 'Manhattan election — approval phase', what: 'Endorse the candidates you approve of; the ranking window opens next.', part: 'Endorse who you support — it’s your approval, and it stays secret.', jurisdiction: 'usa-3-new-york-county', status: 'open', pill: { tone: 'vote', label: 'Approval open' }, form: { name: 'Open ballot', id: null }, target: { kind: 'closesAt', iso: inMin(220) }, to: { rel: 'electoral/open-ballot.html', v1: true } },
      { id: 'lv-forum', kind: 'forum', icon: 'vote', title: 'Candidate forum — tonight', what: 'Candidates speak in turn before the vote window opens.', part: 'Listen, ask, decide.', jurisdiction: 'usa-3-new-york-county', status: 'soon', pill: { tone: 'wait', label: 'Starts soon' }, form: null, target: { kind: 'opensAt', iso: inMin(95) }, to: { rel: 'shared/live-room.html?variant=forum' } },
      { id: 'lv-veto', kind: 'challenge', icon: 'scale', title: 'A signed bill is in its veto window', what: 'The executive may sign or veto.', part: 'Track the clock; an override needs a supermajority of ALL serving.', jurisdiction: 'usa-3-new-york-county', status: 'window', pill: { tone: 'wait', label: 'Window open' }, form: null, target: { kind: 'dayOf', day: 4, max: 90 }, to: { rel: 'legislature/bills.html', v1: true } },
      { id: 'lv-group', kind: 'group', icon: 'users', title: 'Harbor Cleanup Crew — meeting', what: 'The group is calling a facilitated meeting.', part: 'Join if you are a member.', jurisdiction: 'usa-3-new-york-county', status: 'soon', pill: { tone: 'wait', label: 'Meeting soon' }, form: null, target: { kind: 'opensAt', iso: inMin(150) }, to: { rel: 'shared/live-room.html?variant=group' }, scenarioFlag: 'groupForming' },
      { id: 'lv-stipend', kind: 'stipend', icon: 'refresh-cw', title: 'Civic-stipend run posted', what: 'The economic clock posted this period’s stipend.', part: 'See your private receipt in your wallet.', jurisdiction: 'usa-3-new-york-county', status: 'open', pill: { tone: 'planned', label: 'Coming soon' }, form: { name: 'Stipend run', id: 'F-TRE-004' }, target: null, to: { rel: 'economy/stipend.html' }, planned: true, scenarioFlag: 'ubiRun' },
      { id: 'lv-trade', kind: 'trade', icon: 'globe', title: 'Cross-government trade talk', what: 'Two governments are negotiating terms.', part: 'Observe the talks; only the governments act.', jurisdiction: 'earth', status: 'soon', pill: { tone: 'planned', label: 'Coming soon' }, form: null, target: { kind: 'opensAt', iso: inMin(300) }, to: { rel: 'economy/economy-home.html' }, planned: true, scenarioFlag: 'tradeTalk' }
    ],
    forFootprint: function () { return live.rows; },
    /* ---- COMMUNITY CALENDAR (today.html) — what's coming up, across the three
       kinds of host: the jurisdiction itself, organizations, and residents. */
    calendar: [
      { day: 'Tomorrow', kind: 'jurisdiction', title: 'Council — regular session', where: 'New York County · the chamber', to: { rel: 'shared/live-room.html?variant=legislative' } },
      { day: 'Tomorrow', kind: 'org', title: 'Bluefin Logistics — board meeting', where: 'Five-borough depot', to: { rel: 'shared/live-room.html?variant=board' } },
      { day: 'Saturday', kind: 'citizen', title: 'Harbor Cleanup Crew — Pier 7', where: 'Pier 7 · bring gloves', to: { rel: 'groups/group-detail.html?id=party-harbor' } },
      { day: 'Saturday', kind: 'org', title: 'Hudson Mutual Aid — volunteer day', where: 'Lower East Side' },
      { day: 'Next week', kind: 'jurisdiction', title: 'Participatory-budget town hall', where: 'New York County · town hall', to: { rel: 'shared/live-room.html?variant=townhall' } },
      { day: 'Next week', kind: 'citizen', title: 'Riverside Reading Circle', where: 'A member’s home' }
    ]
  };

  /* ---- JOURNEY LIVE STATE (journey.html) — the true current step + a snapshot,
     so the rail marker, the arc marker, and the now/next sentence all agree. */
  var journeyLive = {
    election: { currentStep: 1, snapshot: { now: 'The candidate forum is on tonight', statusPill: { tone: 'wait', label: 'Forum tonight' }, target: { iso: inMin(95) }, roomVariant: 'forum' } },
    'committee-session': { currentStep: 3, snapshot: { now: 'Public testimony is open', statusPill: { tone: 'live', label: 'Live now' }, target: { iso: inMin(38) }, roomVariant: 'committee' } },
    bill: { currentStep: 2, snapshot: { now: 'Floor reading in progress', statusPill: { tone: 'live', label: 'On the floor' }, target: { iso: inMin(74) }, roomVariant: 'legislative' } },
    'court-case': { currentStep: 3, snapshot: { now: 'Evidence is being heard', statusPill: { tone: 'live', label: 'In session' }, target: { iso: inMin(50) }, roomVariant: 'court' } },
    budget: { currentStep: 0, snapshot: { statusPill: { tone: 'planned', label: 'Coming soon' } } },
    'start-org': { currentStep: 2, snapshot: { now: 'The first board meeting is forming', statusPill: { tone: 'wait', label: 'Board forming' }, target: { iso: inMin(180) }, roomVariant: 'board' } },
    'board-meeting': { currentStep: 1, snapshot: { now: 'The board is in session', statusPill: { tone: 'live', label: 'Live now' }, target: { iso: inMin(40) }, roomVariant: 'board' } },
    'form-a-group': { currentStep: 2, snapshot: { now: 'Calling a meeting', statusPill: { tone: 'wait', label: 'Meeting soon' }, target: { iso: inMin(150) }, roomVariant: 'group' } },
    'mutual-aid': { currentStep: 0, snapshot: { statusPill: { tone: 'planned', label: 'Coming soon' } } },
    'petition-to-referendum': { currentStep: 1, snapshot: { now: 'Gathering signatures to the threshold', statusPill: { tone: 'wait', label: 'Signatures' }, target: null } },
    'public-service': { currentStep: 0, snapshot: { now: 'A charter is being drafted', statusPill: { tone: 'wait', label: 'Charter in draft' }, target: null } },
    'stipend-and-tax': { currentStep: 0, snapshot: { statusPill: { tone: 'planned', label: 'Coming soon' } } },
    'two-governments': { currentStep: 2, snapshot: { now: 'Trade talks are underway', statusPill: { tone: 'wait', label: 'In talks' }, target: null } }
  };

  /* ---- LEGITIMACY / REACH (legitimacy.html) — time-series + tier console.
     Display-only (CI-1). k-anon suppresses small counts; 'unmeasurable' (never
     0%) when the denominator is null; raw stored, display-capped at 100%. */
  function legitSnaps(endPct, n, fromPct) {
    var out = [];
    for (var i = 0; i < n; i++) {
      var t = i / (n - 1), v = fromPct + (endPct - fromPct) * t, wob = ((i * 37) % 7 - 3) * 0.01 * endPct;
      out.push({ day: i - (n - 1), reachPct: +(Math.max(0, v + wob)).toFixed(2) });
    }
    return out;
  }
  var legitJurs = [
    { slug: 'usa-3-new-york-county', name: 'New York County', verifiedResidents: 41200, populationEstimate: 1694251, populationYear: 2020, provenance: 'WorldPop', reachPct: 2.43, tier: 'Active', lifecycleState: 'active', suppressed: false, snapshots: legitSnaps(2.43, 30, 0.4) },
    { slug: 'usa-queens', name: 'Queens County', verifiedResidents: 28800, populationEstimate: 2405464, populationYear: 2020, provenance: 'WorldPop', reachPct: 1.20, tier: 'Active', lifecycleState: 'active', suppressed: false, snapshots: legitSnaps(1.20, 30, 0.2) },
    { slug: 'greenwood-hamlet', name: 'Greenwood (hamlet)', verifiedResidents: 480, populationEstimate: 460, populationYear: 2020, provenance: 'WorldPop', reachPct: 104.3, displayCap: 100, tier: 'Saturated', lifecycleState: 'active', suppressed: false, snapshots: legitSnaps(100, 30, 38) },
    { slug: 'manhattan-nbhd', name: 'A Manhattan neighborhood', verifiedResidents: null, populationEstimate: 3100, populationYear: 2020, provenance: 'WorldPop', reachPct: null, tier: null, lifecycleState: 'activating', suppressed: true, snapshots: [] },
    { slug: 'antarctica-base', name: 'Antarctic research base', verifiedResidents: 120, populationEstimate: null, provenance: 'no estimate', reachPct: null, tier: null, lifecycleState: 'unmeasurable', suppressed: false, snapshots: [] }
  ];
  var legitimacy = {
    planned: true, phase: 'Phase I',
    note: 'Reach is a gauge, never a lever. It changes no vote, no moderation, no right — ever.',
    rails: ['A gauge, never a lever — it changes no vote, no seat, no right', 'No per-person score, ever', 'No leaderboard of people — only places are measured', 'Counted from turnout envelopes, never from anyone’s ballot', 'Small counts are hidden to protect people'],
    jurisdictions: legitJurs,
    byJur: (function () { var m = {}; legitJurs.forEach(function (j) { m[j.slug] = j; }); return m; })(),
    tierParams: { enabled: false, k: 1, exponent: 3, floor: 5, cap: 9, devDefaultNote: 'While tiers are switched off, a single verified resident is enough to start a government.', samplePops: [27, 216, 343, 512, 729, 1000000], rail: 'It gates only when a government may start — never anyone’s vote or right to stand. The numbers are policy a legislature can amend, not a hardcoded ceiling.' },
    sample: legitJurs[0]
  };

  /* ---- ACHIEVEMENTS (social/achievements.html) — earned records of journeys
     completed and civic firsts, kept on your profile. The one constitutional
     fence: never a governance advantage. The ACH-* codes are data keys only —
     never rendered. Proposed — founder/legislature-authored policy. */
  var achievements = {
    proposed: true,
    rails: ['Never a governance advantage — no vote, no seat, no role, no moderation, no eligibility', 'No per-person score or rank, and no leaderboard of people', 'Small counts are hidden to protect people', 'A “first ballot” medal shows you took part — never how you voted', 'Private by default — you choose which medals to show'],
    inProgress: [{ code: 'ACH-IND-VERIFIED', label: 'Resident → Verified', have: 21, need: 30, unit: 'days' }],
    tiers: [
      { key: 'individual', label: 'Individual', prefix: 'ACH-IND', visibility: 'Default private · opt-in to show', items: [
        { code: 'ACH-IND-VERIFIED', name: 'Verified resident', earned: true, note: '30 days of qualifying pings' },
        { code: 'ACH-IND-FIRST-BALLOT', name: 'First ballot cast', earned: true, note: 'from the envelope, never the ballot' },
        { code: 'ACH-IND-FIRST-STAND', name: 'Stood for office', earned: false },
        { code: 'ACH-IND-PETITIONER', name: 'Filed a petition', earned: false },
        { code: 'ACH-IND-JUROR', name: 'Served on a jury', earned: false },
        { code: 'ACH-IND-SEATED', name: 'Held a seat', earned: false } ] },
      { key: 'organization', label: 'Organization', prefix: 'ACH-ORG', visibility: 'Public · k-anonymous floor', items: [
        { code: 'ACH-ORG-FOUNDED', name: 'Founded an organization', earned: true },
        { code: 'ACH-ORG-FIRST-ENDORSEMENT', name: 'First endorsement', earned: true },
        { code: 'ACH-ORG-100-MEMBERS', name: '100 members', earned: true, note: 'first worker seat' },
        { code: 'ACH-ORG-PARITY', name: 'Worker–owner parity', earned: false, note: '2000 employees' },
        { code: 'ACH-ORG-MULTI-JURISDICTION', name: 'Operates across jurisdictions', earned: false } ] },
      { key: 'jurisdiction', label: 'Jurisdiction', prefix: 'ACH-JUR', visibility: 'Public · k-anonymous floor', items: [
        { code: 'ACH-JUR-CRITICAL-POP', name: 'Reached critical population', earned: true },
        { code: 'ACH-JUR-FIRST-LEGISLATURE', name: 'Seated a legislature', earned: true },
        { code: 'ACH-JUR-FIRST-ELECTION', name: 'Held an election', earned: true },
        { code: 'ACH-JUR-LEGIT-25', name: '25% reach', earned: false },
        { code: 'ACH-JUR-LEGIT-50', name: '50% reach', earned: false },
        { code: 'ACH-JUR-LEGIT-75', name: '75% reach', earned: false },
        { code: 'ACH-JUR-LEGIT-100', name: 'Full reach', earned: false },
        { code: 'ACH-JUR-FULL-INSTITUTIONS', name: 'All institutions seated', earned: false },
        { code: 'ACH-JUR-SUBDIVIDED', name: 'Subdivided at the seat ceiling', earned: false } ] },
      { key: 'system', label: 'Global milestones', prefix: 'ACH-SYS', visibility: 'Public · celebratory', items: [
        { code: 'ACH-SYS-FIRST-ACTIVATION', name: 'First government booted', earned: true },
        { code: 'ACH-SYS-JUR-COVERAGE', name: '1 in 10 jurisdictions active', earned: true, note: 'a share of mapped jurisdictions, never a raw count' },
        { code: 'ACH-SYS-EARTH-REACH', name: '1% of Earth verified', earned: false, note: 'a share of the world’s people, never a raw count' },
        { code: 'ACH-SYS-FIRST-UNION', name: 'First union of governments', earned: false } ] }
    ]
  };

  /* --------------------------------------------------------------- expose */
  /* ---- BILLS (shared/bill.html) — a bill you can OPEN and watch: its progress
     through the stages, a comment thread, and the negotiation interface over its
     own clauses (the same interface used for instruments of agreement). */
  var bills = {
    'bill-2031-07': {
      id: 'bill-2031-07',
      title: 'New York County Clean Air Act',
      jurisdiction: 'usa-3-new-york-county',
      sponsor: 'Marcus Chen',
      summary: 'Sets depot-area air-quality limits and a retrofit timeline for diesel freight yards.',
      stages: [
        { key: 'drafted', label: 'Drafted', status: 'done' },
        { key: 'committee', label: 'Committee', status: 'done' },
        { key: 'floor', label: 'Floor reading', status: 'current' },
        { key: 'vote', label: 'Floor vote', status: 'pending' },
        { key: 'law', label: 'Becomes law', status: 'pending' }
      ],
      comments: [
        { by: '@u-harborwatch', when: '2h', text: 'The still-day limits matter most near Pier 7 — keep them.' },
        { by: 'Asha Okonkwo', when: '1h', text: 'Supportive. I moved it favourably out of committee.' },
        { by: '@u-tamb', when: '40m', text: 'What happens to the smallest operators on the retrofit timeline?' }
      ],
      neg: {
        kind: 'bill', title: 'New York County Clean Air Act',
        parties: [ { name: 'Sponsor · Marcus Chen', side: 'a', role: 'sponsor' }, { name: 'The floor', side: 'b', role: 'amenders' } ],
        youSide: 'a',
        clauses: [
          { id: 'c1', heading: 'Section 1 — Scope', text: 'This Act applies to diesel depots and freight yards within New York County.' },
          { id: 'c2', heading: 'Section 2 — Limits', text: 'Particulate levels near a depot may not exceed the planetary baseline on still-air days.' },
          { id: 'c3', heading: 'Section 3 — Retrofit timeline', text: 'Operators must complete a retrofit within 24 months of enactment.' }
        ],
        redlines: [
          { id: 1, by: '@u-jpetersen', side: 'b', clauseId: 'c3', kind: 'edit', text: 'Operators must complete a retrofit within 36 months, with a hardship extension for the smallest operators.', rationale: '24 months is not feasible for the smallest depots.', status: 'pending' }
        ],
        comments: [
          { by: 'The floor', side: 'b', when: '1h', text: 'I will offer a cost amendment to Section 3.' }
        ],
        status: 'negotiating'
      }
    }
  };

  F.v2 = {
    interactionClasses: interactionClasses,
    journeys: journeys,
    rooms: rooms,
    handleFor: handleFor,
    groups: groups,
    bills: bills,
    legitimacy: legitimacy,
    live: live,
    journeyLive: journeyLive,
    achievements: achievements,
    byJourney: (function () { var m = {}; journeys.forEach(function (j) { m[j.id] = j; }); return m; })(),
    byClass: (function () { var m = {}; interactionClasses.forEach(function (c) { m[c.id] = c; }); return m; })()
  };
})();
