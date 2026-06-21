/* ============================================================================
   CGA MOCKUPS v2 — fixtures-v2.js  (the game layer)
   AUGMENTS v1's CGA.fixtures (registry / world / byId) — never reshapes it.
   Adds CGA.fixtures.v2 = { interactionClasses, journeys, rooms, handles,
   marketplace, requests, stipend, treasury, groups, legitimacy }.

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
      blurb: 'Governments interacting — federation and Full Faith & Credit, trade and treaty talks, union formation, border settlement, the cross-instance public square.',
      icon: 'globe', accent: 'adm-1',
      journeys: ['two-governments'] },
    { id: 'gov-orgs-people', n: 5, title: 'Government with organizations & people',
      blurb: 'A government interacting with orgs and people — chartering a public service, regulating a market, the civic stipend, taxation, a public hearing, a petition and a referendum.',
      icon: 'scale', accent: 'adm-0',
      journeys: ['petition-to-referendum', 'public-service', 'stipend-and-tax'] }
  ];

  /* =============================================================== JOURNEYS
     Each is a guided arc (§8). status: 'built-layer' rides built surfaces;
     'planned-layer' is design-ahead (Phase I/J/L/M) and badged "Planned". */
  var journeys = [
    { id: 'election', cls: 'gov-itself', flagship: true, status: 'built-layer',
      title: 'An election, end to end',
      now: 'Candidates are gathering approvals in Manhattan; the forum is tonight.',
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
      rail: ['Register', 'Charter', 'First board', 'Onboard', 'Market (opt.)'],
      rooms: ['board'], reusesV1: ['organizations/org-registry.html', 'organizations/org-detail.html', 'organizations/board-elections.html'] },
    { id: 'board-meeting', cls: 'orgs-people', status: 'built-layer',
      title: 'Holding a board meeting',
      now: 'Bluefin Logistics — worker and owner seats deliberate together.',
      rail: ['Convene', 'Composition', 'Motions', 'Board vote', 'Minutes'],
      rooms: ['board'], reusesV1: ['organizations/board-elections.html', 'organizations/co-determination.html', 'organizations/org-detail.html'] },
    { id: 'form-a-group', cls: 'people', status: 'built-layer',
      title: 'An informal group forms and meets',
      now: 'Neighbors start a harbor-cleanup crew and call their first meeting.',
      rail: ['Create', 'Discuss', 'Call a meeting', 'Decide', 'Next steps (opt.)'],
      rooms: ['group'], reusesV1: ['civic/civic-home.html'] },
    { id: 'mutual-aid', cls: 'people', status: 'planned-layer', phase: 'Phase M',
      title: 'Asking for and giving help',
      now: 'Post an assistance request; a neighbor responds; coordinate in a room.',
      rail: ['Post request', 'A neighbor responds', 'Coordinate', 'Resolved'],
      rooms: ['group'], reusesV1: [] },
    { id: 'petition-to-referendum', cls: 'gov-orgs-people', status: 'built-layer',
      title: 'From a petition to a referendum',
      now: 'A participatory-budget petition is gathering signatures toward the threshold.',
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
      rail: ['Stipend run', 'Your receipt', 'Tax filing', 'Public ledger'],
      rooms: [], reusesV1: [] },
    { id: 'two-governments', cls: 'gov-gov', status: 'built-layer',
      title: 'Two governments meet, trade, and merge',
      now: 'Discover a peer, establish Full Faith & Credit, talk trade, then unite.',
      rail: ['Discover peer', 'Full Faith & Credit', 'Trade talks', 'Union or border'],
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
        form: 'F-LEG-006', citation: 'Statements entered verbatim into the immutable public record · WF-SYS-03',
        deepLink: 'legislature/committee-detail.html' },
      vote: { question: 'Refer the Clean Air Act to the floor?',
        method: { label: 'Passes at a majority of all 3 committee members — not those present', citation: 'Art. II §4 · committee majority' },
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
        form: 'F-LEG-004', citation: 'Floor vote · ordinary majority of all serving · Art. II §2',
        deepLink: 'legislature/bill-detail.html' },
      vote: { question: 'Pass the New York County Clean Air Act on the floor?',
        method: { label: 'Passes at a majority of all 8 serving members — peg quorum, never those present', citation: 'Peg quorum · Art. II §2' },
        mode: 'unicameral', thresholdClass: 'majority',
        serving: 8, requiredYes: 5, quorum: { present: 7, required: 5 },
        tallies: { yes: 4, no: 2, abstain: 1 }, outcome: 'pending',
        gloss: 'Peg quorum: the denominator is every serving seat. An absent member counts the same as a no.' },
      presence: [
        P('yuki-tanaka', 1, 'chair'),
        P('marcus-chen', 2, 'floor', { speaking: true }),
        P('kwame-mensah', 3, 'member'),
        { handle: 'u-seat4', name: null, seat: 4, role: 'vacant', online: false, vacant: true },
        P('asha-okonkwo', 5, 'member'),
        G('u-jpetersen', 'member', { seat: 6 }), G('u-msantos', 'member', { seat: 7 }),
        G('u-anwosu', 'member', { seat: 8 }), G('u-laronov', 'member', { seat: 9 }),
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
        { handle: 'yuki', body: 'Constitutional matters take slot 2 by order — Art. II §2.', sealState: 'sealing', recordHref: 'system/public-records.html' }
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
        form: 'F-LEG-007', citation: 'Committee executive — equal voting power · Art. III',
        deepLink: 'executive/executive-home.html' },
      vote: { question: 'Extend emergency-shelter operating hours?',
        method: { label: 'Passes at a majority of all 5 equal members', citation: 'Equal-power executive · Art. III' },
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
        form: 'F-ORG-004', citation: 'Worker representation from 100 employees; parity at 2,000 · Art. III §6',
        deepLink: 'organizations/board-elections.html' },
      vote: { question: 'Approve the depot hiring plan?',
        method: { label: 'Passes at a majority of the full 5-seat board', citation: 'The org’s own rules of order · Art. III §6' },
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
        form: 'F-JDG-002', citation: 'Proceedings are public record · Art. II §2',
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
        body: 'During the approval phase candidates each get the floor for a fixed time; residents watch from the gallery and ask questions. Approving a candidate is a separate, secret act on the open ballot — nothing here changes your approvals.',
        form: null, citation: 'Voting and candidacy require nothing beyond residency · Art. I',
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
      galleryNote: 'Anyone may watch the forum. Only residents may ask from the floor; approving candidates happens on the open ballot and is always secret.',
      forms: [],
      chairControls: ['Open the forum', 'Recognize the next candidate', 'Start the speaking clock', 'Open resident questions', 'Close & link the ballot'],
      reusesV1: ['electoral/open-ballot.html', 'electoral/candidate-profile.html'],
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
        form: 'F-LEG-006', citation: 'Referendum question · Art. II §2',
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
        form: null, citation: 'Freedom of association — voluntary, no constitutional force · Art. I',
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
      reusesV1: ['civic/civic-home.html'],
      productionPages: ['resources/js/Pages/Civic/MatrixCommons.vue']
    }
  };

  /* ============================================ ECONOMY / GROUPS / LEGITIMACY
     Light fixtures consumed by Stages 3–5. Defined here so the launchpad's
     journey directory and the demo bar can reference real numbers; the full
     surfaces are built in their stages. All economy data is PLANNED + the
     individual rows are PRIVATE / never federated. */
  var economy = {
    marketplace: {
      planned: true, unit: 'abstract units of account',
      privacy: 'Your purchases are private and never leave this server.',
      listings: [
        { id: 'lst-1', title: 'Repaired cargo bikes', kind: 'good', qty: 6, price: 240, seller: 'Bluefin Logistics', sellerKind: 'business', form: 'F-IND-021' },
        { id: 'lst-2', title: 'Rooftop garden consultation', kind: 'service', qty: 'by appointment', price: 35, seller: 'Hudson Mutual Aid', sellerKind: 'nonprofit', form: 'F-IND-021' },
        { id: 'lst-3', title: 'Water-quality testing kits', kind: 'good', qty: 40, price: 18, seller: 'Manhattan Water & Power', sellerKind: 'common_good_corp', cgc: true, form: 'F-IND-021', note: 'A CGC sells on identical terms to any private seller · Art. III §5' }
      ]
    },
    requests: {
      planned: true,
      work: [
        { id: 'wk-1', title: 'Recurring depot loader (40 hires)', org: 'Bluefin Logistics', form: 'F-IND-019', triggers: 'F-IND-014 → org_contracts(labor_recurring) → co-determination headcount' }
      ],
      assistance: [
        { id: 'aid-1', title: 'Help moving a wheelchair-accessible ramp', kind: 'mutual_aid', privacy: 'private by default' }
      ]
    },
    stipend: {
      planned: true, eligibility: 'Active residency association only — the same gate as voting, no means test, no application · Art. I',
      lastRun: { id: 'ubi-2031-06', recipients: 1690000, total: '1,690,000 units (public aggregate)', form: 'F-TRE-004', perReceiptPrivate: true },
      bumps: { enabled: false, keys: ['civic_stipend_enabled', 'stipend_bump_operator', 'stipend_bump_moderator', 'stipend_bump_officeholder'], gate: 'dual-door (F-LEG-031): chamber supermajority + constituent consent' }
    },
    treasury: {
      planned: true,
      ledger: 'Double-entry, append-only, hash-chained · Σdebits = Σcredits per currency',
      accountsPublic: 'Jurisdiction & department accounts are PUBLIC', accountsPrivate: 'User & org accounts are PRIVATE',
      cycle: [
        { step: 'Revenue', form: 'F-LEG-037' }, { step: 'Budget', form: 'F-LEG-038', note: 'enactment spawns appropriations' },
        { step: 'Disbursement', form: 'F-TRE-001…003', actor: 'Board of Governors' }, { step: 'Public ledger', form: null }
      ],
      gated: { borrowing: 'F-LEG-039', currency: 'F-LEG-040 (root jurisdiction only — hardened)' },
      rail: 'No fee may attach to a civic-rights form — rejected with Art. II §8.'
    }
  };

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
    note: 'Groups are voluntary associations (Art. I). No governance power is conferred; membership is private to each member and never federates.'
  };

  var legitimacy = {
    planned: true, phase: 'Phase I',
    note: 'Reach is a display-only transparency gauge. It is NEVER a governance input — it changes no vote, no moderation, no right.',
    rails: ['No per-person score', 'No individual leaderboard', 'Measured from the envelope, not the ballot', 'k-anonymous floor suppresses small counts'],
    sample: { jurisdiction: 'usa-3-new-york-county', verifiedResidents: 41200, populationEstimate: 1694251, reachPct: 2.43, tier: 'Active government' }
  };

  /* --------------------------------------------------------------- expose */
  F.v2 = {
    interactionClasses: interactionClasses,
    journeys: journeys,
    rooms: rooms,
    handleFor: handleFor,
    economy: economy,
    groups: groups,
    legitimacy: legitimacy,
    byJourney: (function () { var m = {}; journeys.forEach(function (j) { m[j.id] = j; }); return m; })(),
    byClass: (function () { var m = {}; interactionClasses.forEach(function (c) { m[c.id] = c; }); return m; })()
  };
})();
