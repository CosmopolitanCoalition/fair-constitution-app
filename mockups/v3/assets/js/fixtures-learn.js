/* ============================================================================
   CGA MOCKUPS v2 — fixtures-learn.js
   The teaching spine: standard operating procedures (SOPs), the multi-track
   video catalog, and the education tracks + lessons. Attaches to
   CGA.fixtures.v2.learn. Loaded after fixtures-v2.js.

   Design grounding:
   - Every user journey, tool, and workflow has an SOP — a short, numbered
     "how it actually goes" — so the procedure is legible in the interface
     itself (the SOP-in-interface once-over).
   - Every SOP has a video. The video is the multi-track player modeled on the
     cosmopolitancoalition.org WordPress player (functions/video_player.php):
     ONE silent master MP4 + per-language audio (.m4a) + per-language captions
     (.vtt), keyed by {Subject}-{Language}.{ext}. See components-v2.js.
   - Education modules wrap a video + its SOP + a knowledge check + the live
     journey, grouped into tracks.
   ============================================================================ */
(function () {
  'use strict';
  var CGA = window.CGA = window.CGA || {};
  if (!CGA.fixtures || !CGA.fixtures.v2) throw new Error('fixtures-learn.js: fixtures-v2.js must load first');
  var V2 = CGA.fixtures.v2;

  /* --- track-coverage shorthands (which languages a video carries) ----------
     AUDIO_FULL / CAP_FULL = the 24 curated languages with tracks; the marketing
     site shipped 77 and languages.py maps 115 — the rest are queued. Audio
     dubbing lags captions, so some videos carry captions broadly but audio in a
     narrower set, exactly as the real pipeline does. */
  var CAP_FULL = ['en', 'es', 'ar', 'zh', 'hi', 'fr', 'pt', 'ru', 'ja', 'de', 'sw', 'bn', 'id', 'ur', 'fa', 'ko', 'tr', 'vi', 'it', 'pl', 'uk', 'ta', 'am', 'ht'];
  var AUDIO_FULL = CAP_FULL.slice();
  var AUDIO_CORE = ['en', 'es', 'ar', 'zh', 'hi', 'fr', 'pt', 'ru', 'ja', 'de', 'sw', 'id'];
  var AUDIO_SEED = ['en', 'es', 'ar', 'zh', 'fr'];

  /* ----------------------------------------------------------------- videos */
  var videos = [
    { id: 'v-welcome', subject: 'Welcome-to-World-of-Statecraft', title: 'Welcome to World of Statecraft', seconds: 142,
      poster: 'brand', summary: 'The two-minute orientation: governance as a live activity you play with the people around you.',
      audio: AUDIO_FULL, captions: CAP_FULL },
    { id: 'v-civic-record', subject: 'Your-Civic-Record', title: 'Your civic record', seconds: 121,
      poster: 'civic', summary: 'Residency is the one gate. What it unlocks, and what stays private.', audio: AUDIO_FULL, captions: CAP_FULL },
    { id: 'v-find-live', subject: 'Find-Whats-Live', title: 'Finding what is live now', seconds: 96,
      poster: 'civic', summary: 'Today shows every session, hearing, vote, and forum open in your jurisdictions.', audio: AUDIO_CORE, captions: CAP_FULL },
    { id: 'v-stv', subject: 'How-Proportional-Voting-Works', title: 'How proportional voting works', seconds: 233,
      poster: 'elections', summary: 'STV with a Droop quota, in plain terms — why every vote moves and nothing is wasted.', audio: AUDIO_FULL, captions: CAP_FULL },
    { id: 'v-cast-ballot', subject: 'Cast-Your-Ballot', title: 'Casting your ballot', seconds: 158,
      poster: 'elections', summary: 'The two-phase open ballot, the commitment, and ballot secrecy.', audio: AUDIO_FULL, captions: CAP_FULL },
    { id: 'v-stand', subject: 'Stand-for-Office', title: 'Standing for office', seconds: 187,
      poster: 'elections', summary: 'Declaring candidacy — an absolute right that needs only your residency.', audio: AUDIO_CORE, captions: CAP_FULL },
    { id: 'v-floor', subject: 'Take-the-Floor', title: 'Taking the floor in a live session', seconds: 175,
      poster: 'chamber', summary: 'Raise a hand, be recognized, your avatar steps to the well, you speak to the record.', audio: AUDIO_CORE, captions: CAP_FULL },
    { id: 'v-bill', subject: 'From-Bill-to-Law', title: 'From a bill to a law', seconds: 204,
      poster: 'chamber', summary: 'A reading, a committee, the floor vote at peg quorum, and the versioned law.', audio: AUDIO_SEED, captions: CAP_FULL },
    { id: 'v-petition', subject: 'Petitions-and-Referendums', title: 'Petitions and referendums', seconds: 169,
      poster: 'chamber', summary: 'Gather signatures to the threshold; carry a question to a town hall and a vote.', audio: AUDIO_SEED, captions: CAP_FULL },
    { id: 'v-case', subject: 'Bring-a-Case', title: 'Bringing a case to court', seconds: 196,
      poster: 'court', summary: 'File, the panel forms, advocates hold the floor, and double jeopardy holds.', audio: AUDIO_SEED, captions: CAP_FULL },
    { id: 'v-challenge', subject: 'The-Three-Path-Challenge', title: 'The constitutional challenge', seconds: 221,
      poster: 'court', summary: 'The Art. IV §5 three-path challenge that can end in editing a law directly.', audio: AUDIO_SEED, captions: AUDIO_CORE },
    { id: 'v-org', subject: 'Found-an-Organization', title: 'Founding an organization', seconds: 183,
      poster: 'org', summary: 'Register a party, business, nonprofit, or common-good corp — and what co-determination means.', audio: AUDIO_CORE, captions: CAP_FULL },
    { id: 'v-square', subject: 'Post-in-the-Square', title: 'Posting in the public square', seconds: 134,
      poster: 'social', summary: 'The square cannot be censored for viewpoint. The four carve-outs, and the legal floor.', audio: AUDIO_CORE, captions: CAP_FULL },
    { id: 'v-rep', subject: 'Meet-Your-Representative', title: 'Meeting your representative', seconds: 128,
      poster: 'social', summary: 'Office hours, a request for a meeting, a constituent message — fair representation in practice.', audio: AUDIO_SEED, captions: CAP_FULL },
    { id: 'v-units', subject: 'Units-and-the-Stipend', title: 'Units and the civic stipend', seconds: 211,
      poster: 'econ', summary: 'What a unit is, how it subdivides, and the residency floor plus capped role differential.', audio: AUDIO_SEED, captions: CAP_FULL },
    { id: 'v-market', subject: 'The-Market-and-Agreements', title: 'The market and agreements', seconds: 198,
      poster: 'econ', summary: 'Offers and requests, the order that becomes an agreement, and the Supremacy-of-Rights floor.', audio: AUDIO_SEED, captions: AUDIO_CORE },
    { id: 'v-joint', subject: 'Open-a-Joint-Ledger', title: 'Opening a joint ledger', seconds: 152,
      poster: 'econ', summary: 'A co-owned account where every signer must agree before money moves.', audio: AUDIO_SEED, captions: AUDIO_CORE },
    { id: 'v-node', subject: 'Set-Up-a-Node', title: 'Setting up a node', seconds: 246,
      poster: 'operator', summary: 'Claim an operator account, name the instance, pick a role, and join the mesh.', audio: AUDIO_CORE, captions: CAP_FULL },
    { id: 'v-channels', subject: 'Roles-and-Channels', title: 'Operator roles and channels', seconds: 218,
      poster: 'operator', summary: 'The four named roles over the nine capability channels, and the dual-meter consent.', audio: AUDIO_SEED, captions: AUDIO_CORE },
    { id: 'v-translate', subject: 'Translate-and-Verify', title: 'Translating and verifying', seconds: 164,
      poster: 'learn', summary: 'How a string goes from an AI first draft to community-verified in your language.', audio: AUDIO_SEED, captions: CAP_FULL }
  ];

  /* ------------------------------------------------------------------- SOPs */
  /* Each SOP: a short numbered procedure (do + detail [+ cite]); the video; the
     live journey it teaches; the v1 formal screen; and the issues people hit. */
  var sops = [
    { id: 'cast-ballot', title: 'Cast your ballot', icon: 'vote', scope: 'citizen', module: 'Elections',
      videoId: 'v-cast-ballot', journeyId: 'election', v1: 'electoral/open-ballot.html',
      summary: 'Vote in an open election. Your identity is cryptographically separated from your ballot — secrecy is guaranteed, not promised.',
      steps: [
        { do: 'Open the live election', detail: 'From Today or the election notice. You can vote any time the window is open.' },
        { do: 'Rank the candidates', detail: 'Drag to order as many as you like. Ranking more never hurts your top choice.', cite: 'STV · Droop quota · Art. II §2' },
        { do: 'Commit your ballot', detail: 'A commitment hash is recorded; the contents stay sealed until counting.', cite: 'Ballot secrecy · Art. II' },
        { do: 'Keep your receipt', detail: 'You can confirm your ballot was counted without revealing how you voted.' }
      ],
      issues: ['ticket-ballot-confirm'] },

    { id: 'stand-for-office', title: 'Stand for office', icon: 'award', scope: 'citizen', module: 'Elections',
      videoId: 'v-stand', journeyId: 'election', v1: 'electoral/candidacy-registration.html',
      summary: 'Declare your candidacy. The only requirement is residency in the jurisdiction — there is no other gate of any kind.',
      steps: [
        { do: 'Confirm you reside here', detail: 'Candidacy is an absolute right tied to residency alone.', cite: 'Art. I · no requirement beyond residency' },
        { do: 'Declare candidacy', detail: 'During the approval phase, add yourself to the race.' },
        { do: 'Gather endorsements', detail: 'Any person or organization may endorse you — endorsements never gate the ballot.' },
        { do: 'Speak at the forum', detail: 'Take your turn at the candidate forum — a Live Civic Room.' }
      ], issues: [] },

    { id: 'take-the-floor', title: 'Take the floor', icon: 'landmark', scope: 'citizen', module: 'Legislature',
      videoId: 'v-floor', journeyId: 'committee-session', v1: 'legislature/session-console.html',
      summary: 'Speak in a live session. Raise a hand, get recognized by the chair, and your avatar steps to the well to address the room.',
      steps: [
        { do: 'Enter the room', detail: 'Open the live session from Today. You start in the gallery.' },
        { do: 'Raise your hand', detail: 'Join the queue. The chair sees the order and who is waiting.' },
        { do: 'Be recognized', detail: 'When it is your turn your avatar moves to the floor and your border lights up.' },
        { do: 'Speak to the record', detail: 'Your testimony is bridged to the public record.', cite: 'TestimonyBridge · F-SOC-002' }
      ], issues: ['ticket-floor-queue'] },

    { id: 'file-petition', title: 'File a petition', icon: 'file-text', scope: 'citizen', module: 'Legislature',
      videoId: 'v-petition', journeyId: 'petition-to-referendum', v1: 'legislature/referendums.html',
      summary: 'Put a question to your jurisdiction. Gather signatures to the threshold and it carries to a town hall and a referendum.',
      steps: [
        { do: 'Write the question', detail: 'State a single, clear measure.' },
        { do: 'Gather signatures', detail: 'Reach the petition threshold for your population.', cite: 'initiative_petition_threshold_pct · 5%' },
        { do: 'Hold the town hall', detail: 'Open deliberation in a Live Civic Room before the vote window.' },
        { do: 'Go to referendum', detail: 'The measure goes to a jurisdiction-wide vote.' }
      ], issues: [] },

    { id: 'start-org', title: 'Found an organization', icon: 'building', scope: 'citizen', module: 'Organizations',
      videoId: 'v-org', journeyId: 'start-org', v1: 'organizations/org-registry.html',
      summary: 'Register a party, business, nonprofit, or common-good corp. Crossing 100 employees brings worker representation onto the board.',
      steps: [
        { do: 'Pick a type', detail: 'Party, business, nonprofit, common-good corp, or informal.' },
        { do: 'Write the charter', detail: 'Name, purpose, and the org’s own rules of order.' },
        { do: 'Register', detail: 'The org appears in the public registry.' },
        { do: 'Mind co-determination', detail: 'At 100 employees a worker seat appears; parity arrives at 2000.', cite: 'Art. III §6 · worker representation' }
      ], issues: ['ticket-org-codetermination'] },

    { id: 'bring-case', title: 'Bring a case', icon: 'scale', scope: 'citizen', module: 'Judiciary',
      videoId: 'v-case', journeyId: 'court-case', v1: 'judiciary/case-detail.html',
      summary: 'File a matter with the court. A panel forms, advocates hold the floor, and a person cannot be tried twice for the same matter.',
      steps: [
        { do: 'File the matter', detail: 'Open a case on the docket.' },
        { do: 'The panel forms', detail: 'Judges are seated; a jury is drawn where required.', cite: 'Min 5 judges per race · Art. IV §1' },
        { do: 'Advocates argue', detail: 'Each side holds the floor in a Live Civic Room — the courtroom.' },
        { do: 'The ruling is recorded', detail: 'Double jeopardy holds; an Art. IV §5 challenge may follow.' }
      ], issues: [] },

    { id: 'post-square', title: 'Post in the square', icon: 'message-square', scope: 'citizen', module: 'Social',
      videoId: 'v-square', journeyId: 'mutual-aid', v1: 'system/public-records.html',
      summary: 'Speak in the public square. It cannot be censored for viewpoint — only four narrow content-neutral carve-outs apply.',
      steps: [
        { do: 'Write your post', detail: 'Pseudonymity is allowed; your residency is never revealed.' },
        { do: 'Post to the square', detail: 'No viewpoint can be removed — that path does not exist in the code.', cite: 'Art. I · uncensorable square' },
        { do: 'Know the four carve-outs', detail: 'Content-neutral only: imminent-harm, others’ private data, off-topic flooding, and the legal floor.' },
        { do: 'Report illegal content', detail: 'The M-5 legal floor handles physical-law-illegal material off the constitutional plane.', cite: 'M-5 · §2258A' }
      ], issues: ['ticket-square-flood'] },

    { id: 'meet-rep', title: 'Meet your representative', icon: 'user', scope: 'citizen', module: 'Social',
      videoId: 'v-rep', journeyId: 'public-service', v1: null,
      summary: 'Reach the person who holds your seat: office hours, a meeting request, or a constituent message — fair representation in practice.',
      steps: [
        { do: 'Open their page', detail: 'Every seat-holder has a public record and a forum.' },
        { do: 'Pick a channel', detail: 'Attend office hours, request a one-to-one, or send a message.' },
        { do: 'Make the request', detail: 'Requests enter their public constituent queue.' },
        { do: 'Track the reply', detail: 'You see status as it moves; the thread is yours to keep.' }
      ], issues: [] },

    { id: 'market-offer', title: 'Place a market offer', icon: 'bar-chart', scope: 'citizen', module: 'Economy',
      videoId: 'v-market', journeyId: 'public-service', v1: null, planned: true,
      summary: 'List a good or service, or answer a request. The order forms an agreement that carries a Supremacy-of-Rights floor.',
      steps: [
        { do: 'Post the offer', detail: 'Describe what you provide and the price in units.' },
        { do: 'Or answer a request', detail: 'Offers and requests are two sides of the same board.' },
        { do: 'Agree on terms', detail: 'An accepted order becomes a signed agreement.', cite: 'Supremacy of Rights · Art. I' },
        { do: 'Settle privately', detail: 'Settlement writes only the private wallets, never federated.' }
      ], issues: ['ticket-market-units'] },

    { id: 'joint-ledger', title: 'Open a joint ledger', icon: 'lock', scope: 'citizen', module: 'Economy',
      videoId: 'v-joint', journeyId: 'public-service', v1: null, planned: true,
      summary: 'Co-own an account with others. Nothing moves unless every signer agrees — the rule for any shared, indivisible resource.',
      steps: [
        { do: 'Name the signers', detail: 'Add the people or organizations who co-own the ledger.' },
        { do: 'Set the agreement', detail: 'Define what moves require which signatures.', cite: 'Art. V §2 · shared resources' },
        { do: 'Every signer agrees', detail: 'A movement waits until all required signers consent.' },
        { do: 'Watch the ledger', detail: 'Every signer sees every line; disputes go to court, not an admin.' }
      ], issues: [] },

    { id: 'setup-node', title: 'Set up a node', icon: 'sliders', scope: 'operator', module: 'Operator plane',
      videoId: 'v-node', journeyId: null, v1: 'jurisdictions/federation.html',
      summary: 'Stand up a server. Claim an operator account, name the instance, pick a role, and join the mesh. Operators answer to the seated government.',
      steps: [
        { do: 'Claim an operator account', detail: 'A local password, or link an existing mesh identity by device-key possession.' },
        { do: 'Name the instance', detail: 'Give the node a host name and the jurisdiction it serves.' },
        { do: 'Pick a role', detail: 'Record Keeper is one click; the other three are requested.', cite: 'capability, not role · off the plane' },
        { do: 'Join the mesh', detail: 'Discover peers, handshake, and cold-sync the public record.' }
      ], issues: ['ticket-node-cert'] },

    { id: 'verify-translation', title: 'Verify a translation', icon: 'languages', scope: 'citizen', module: 'Learn & support',
      videoId: 'v-translate', journeyId: null, v1: null,
      summary: 'Help your language reach published. The AI writes the first draft; people who read in that language review and verify it.',
      steps: [
        { do: 'Choose your language', detail: 'You can verify only the language you actually read the interface in.', cite: 'community-verified by interface-language users' },
        { do: 'Review the AI draft', detail: 'Each string shows the source and the machine first draft.' },
        { do: 'Accept or correct', detail: 'Approve a good draft, or propose a better wording.' },
        { do: 'Reach a quorum', detail: 'Enough verifications move a string to community-verified, then published.' }
      ], issues: ['ticket-translation-term'] },

    { id: 'report-issue', title: 'Report an issue', icon: 'flag', scope: 'citizen', module: 'Learn & support',
      videoId: null, journeyId: null, v1: null,
      summary: 'Tell us when something is wrong. The report routes itself: bugs to operators, wording to the translators, abuse to the legal floor.',
      steps: [
        { do: 'Open the report', detail: 'The Report an issue link sits in the footer of every page.' },
        { do: 'Pick a category', detail: 'Bug, translation, accessibility, content, abuse, or an idea.' },
        { do: 'Describe it', detail: 'The page you were on is attached automatically.' },
        { do: 'Follow the ticket', detail: 'You get a ticket you can watch as it moves to resolved.' }
      ], issues: [] }
  ];

  /* --------------------------------------------------------- tracks + lessons */
  var tracks = [
    { id: 'getting-started', title: 'Getting started', icon: 'graduation-cap', level: 'Start here',
      summary: 'What World of Statecraft is, your civic record, and how to find what is happening right now.' },
    { id: 'elections-track', title: 'Elections & representation', icon: 'vote', level: 'Core',
      summary: 'Proportional voting, casting a ballot, and standing for office.' },
    { id: 'lawmaking', title: 'Lawmaking & the chamber', icon: 'landmark', level: 'Core',
      summary: 'Taking the floor, moving a bill to law, and petitions and referendums.' },
    { id: 'justice', title: 'Justice & the courts', icon: 'scale', level: 'Core',
      summary: 'Bringing a case, and the three-path constitutional challenge.' },
    { id: 'economy-track', title: 'The economy', icon: 'bar-chart', level: 'Planned',
      summary: 'Units and the civic stipend, the market and agreements, and joint ledgers.' },
    { id: 'running-a-node', title: 'Running a node', icon: 'sliders', level: 'Operator',
      summary: 'Standing up a server, the capability roles, and supporting community translation.' }
  ];

  var lessons = [
    { id: 'what-is-wos', track: 'getting-started', title: 'What is World of Statecraft?', videoId: 'v-welcome', seconds: 142,
      summary: 'Governance as a live, social activity — the human in the foreground.',
      check: { q: 'What makes a person able to vote and stand for office?', options: ['A fee', 'Residency in the jurisdiction, and nothing else', 'An invitation from a representative'], answer: 1, explain: 'Voting and candidacy are absolute rights tied to residency alone — Art. I.' } },
    { id: 'your-civic-record', track: 'getting-started', title: 'Your civic record', videoId: 'v-civic-record', sopId: null, seconds: 121,
      summary: 'What residency unlocks, and what stays private to you.',
      check: { q: 'Where does your ballot choice get written?', options: ['To the public record', 'Nowhere that can be linked to you', 'To your representative'], answer: 1, explain: 'Ballot secrecy cryptographically separates your identity from your ballot — Art. II.' } },
    { id: 'find-whats-live', track: 'getting-started', title: 'Finding what is live', videoId: 'v-find-live', seconds: 96,
      summary: 'Today is your one-tap entry to every open session, hearing, vote, and forum.',
      check: { q: 'What does the Today screen show?', options: ['Only national news', 'Everything live in your jurisdictions right now', 'A list of laws'], answer: 1, explain: 'Today surfaces what is open across all jurisdictions you belong to.' } },

    { id: 'how-stv-works', track: 'elections-track', title: 'How proportional voting works', videoId: 'v-stv', seconds: 233,
      summary: 'STV with a Droop quota — why no vote is wasted.',
      check: { q: 'What is the smallest a legislature may be?', options: ['1 seat', '3 seats', '5 seats'], answer: 2, explain: 'The minimum is 5 seats; above 9 the body must subdivide — Art. II §2.' } },
    { id: 'cast-your-ballot', track: 'elections-track', title: 'Casting your ballot', videoId: 'v-cast-ballot', sopId: 'cast-ballot', journeyId: 'election', seconds: 158,
      summary: 'The two-phase open ballot and the commitment scheme.',
      check: { q: 'Does ranking more candidates hurt your first choice?', options: ['Yes', 'No — your top choice is counted first regardless', 'Only in small races'], answer: 1, explain: 'Under STV your vote transfers only after your higher choices are decided.' } },
    { id: 'stand-for-office', track: 'elections-track', title: 'Standing for office', videoId: 'v-stand', sopId: 'stand-for-office', journeyId: 'election', seconds: 187,
      summary: 'Declaring candidacy as an absolute right.',
      check: { q: 'What can a jurisdiction add as a requirement to stand?', options: ['A property qualification', 'A loyalty oath', 'Nothing beyond residency'], answer: 2, explain: 'No requirement may be added beyond jurisdictional residency — Art. I.' } },

    { id: 'the-live-chamber', track: 'lawmaking', title: 'Taking the floor', videoId: 'v-floor', sopId: 'take-the-floor', journeyId: 'committee-session', seconds: 175,
      summary: 'From the gallery to the well — speaking to the record.',
      check: { q: 'What happens when the chair recognizes you?', options: ['Your avatar steps to the floor and your border lights up', 'You are muted', 'Your vote is cast automatically'], answer: 0, explain: 'Recognition moves your actor to the well; you hold the floor until you yield.' } },
    { id: 'from-bill-to-law', track: 'lawmaking', title: 'From a bill to a law', videoId: 'v-bill', journeyId: 'bill', seconds: 204,
      summary: 'A reading, a committee, the floor vote, and the versioned law.',
      check: { q: 'A floor vote needs a quorum of what?', options: ['Any three members', 'A majority of all serving members', 'Whoever shows up'], answer: 1, explain: 'Quorum is a majority of all serving members — not just those present — Art. II §2.' } },
    { id: 'petitions-referendums', track: 'lawmaking', title: 'Petitions & referendums', videoId: 'v-petition', sopId: 'file-petition', journeyId: 'petition-to-referendum', seconds: 169,
      summary: 'Carrying a question from a signature sheet to a vote.',
      check: { q: 'What carries a petition to a referendum?', options: ['A representative’s approval', 'Reaching the signature threshold', 'A court order'], answer: 1, explain: 'Reaching the population threshold moves the measure to a jurisdiction-wide vote.' } },

    { id: 'bring-a-case', track: 'justice', title: 'Bringing a case', videoId: 'v-case', sopId: 'bring-case', journeyId: 'court-case', seconds: 196,
      summary: 'Filing, the panel, the advocates, and double jeopardy.',
      check: { q: 'How many judges sit on a race at minimum?', options: ['1', '3', '5'], answer: 2, explain: 'A judiciary seats a minimum of 5 judges per race — Art. IV §1.' } },
    { id: 'the-three-path-challenge', track: 'justice', title: 'The constitutional challenge', videoId: 'v-challenge', journeyId: 'court-case', seconds: 221,
      summary: 'The Art. IV §5 challenge that can end in editing a law directly.',
      check: { q: 'What can the Art. IV §5 challenge ultimately do?', options: ['Nothing binding', 'Edit a law directly, with full history preserved', 'Dissolve the legislature'], answer: 1, explain: 'The third path ends in a judicial remedy law version — the edit is recorded, history kept.' } },

    { id: 'units-and-stipend', track: 'economy-track', title: 'Units & the civic stipend', videoId: 'v-units', planned: true, seconds: 211,
      summary: 'What a unit is and the residency floor plus capped role differential.',
      check: { q: 'The civic stipend is best described as…', options: ['A salary for office', 'A residency floor plus a small capped role differential', 'A loan'], answer: 1, explain: 'Pay can never become a qualification for office; the differential is add-only and capped.' } },
    { id: 'market-and-agreements', track: 'economy-track', title: 'The market & agreements', videoId: 'v-market', sopId: 'market-offer', planned: true, seconds: 198,
      summary: 'Offers, requests, and the Supremacy-of-Rights floor on every agreement.',
      check: { q: 'What floor sits under every agreement?', options: ['Whatever the parties write', 'The Supremacy of Rights — no agreement can waive a constitutional right', 'The operator’s terms'], answer: 1, explain: 'Every instrument carries a constitutional floor — Art. I.' } },
    { id: 'joint-ledgers', track: 'economy-track', title: 'Joint ledgers', videoId: 'v-joint', sopId: 'joint-ledger', planned: true, seconds: 152,
      summary: 'Co-owned money where every signer must agree.',
      check: { q: 'What moves money out of a joint ledger?', options: ['Any one signer', 'Agreement of every required signer', 'The server operator'], answer: 1, explain: 'A shared, indivisible resource moves only with the consent of all required signers — Art. V §2.' } },

    { id: 'operator-basics', track: 'running-a-node', title: 'Setting up a node', videoId: 'v-node', sopId: 'setup-node', seconds: 246,
      summary: 'Account, instance, role, mesh — and answering to the government.',
      check: { q: 'Operators are best understood as…', options: ['The owners of the government', 'A de-facto board answerable to the seated government', 'Above the constitution'], answer: 1, explain: 'The operator plane is capability, not role; Meter B (the seated government) supersedes Meter A.' } },
    { id: 'roles-and-channels', track: 'running-a-node', title: 'Roles & channels', videoId: 'v-channels', seconds: 218,
      summary: 'The four named roles over the nine capability channels.',
      check: { q: 'Which role can a fresh node take in one click?', options: ['Identity Broker', 'Social Moderator', 'Record Keeper'], answer: 2, explain: 'Record Keeper is self-asserted; the other three are governed and must be requested.' } },
    { id: 'translation-and-community', track: 'running-a-node', title: 'Translation & community', videoId: 'v-translate', sopId: 'verify-translation', seconds: 164,
      summary: 'How AI drafts and community verification get a language to published.',
      check: { q: 'Who can verify a translation into a language?', options: ['Anyone', 'People who actually read the interface in that language', 'Only operators'], answer: 1, explain: 'Verification is gated to the people who chose that interface language.' } }
  ];

  /* --------------------------------------------------------------- indexes */
  function index(arr) { var m = {}; arr.forEach(function (x) { m[x.id] = x; }); return m; }
  var byVideo = index(videos), bySop = index(sops), byTrack = index(tracks), byLesson = index(lessons);
  tracks.forEach(function (t) { t.lessons = lessons.filter(function (l) { return l.track === t.id; }).map(function (l) { return l.id; }); });

  V2.learn = {
    videos: videos, byVideo: byVideo,
    sops: sops, bySop: bySop,
    tracks: tracks, byTrack: byTrack,
    lessons: lessons, byLesson: byLesson,
    trackCoverage: { capFull: CAP_FULL, audioFull: AUDIO_FULL, audioCore: AUDIO_CORE, audioSeed: AUDIO_SEED },
    totals: { videos: videos.length, sops: sops.length, tracks: tracks.length, lessons: lessons.length, languagesMapped: 115, languagesShipped: 77 },
    fmtDuration: function (s) { var m = Math.floor(s / 60), r = s % 60; return m + ':' + (r < 10 ? '0' : '') + r; }
  };
})();
