/* ============================================================================
   CGA MOCKUPS v2 — fixtures-support.js
   The report-an-issue + ticket spine. Attaches to CGA.fixtures.v2.support.
   Loaded after fixtures-v2.js.

   A report routes itself by category:
   - bug / accessibility / content -> the operators (Record Keeper, Archivist)
   - translation -> the translation-support interface
   - abuse / illegal -> OFF the support queue, to the moderation & legal team
     (internally: the content-neutral carve-outs + the M-5 legal floor) —
     support staff never decide what people may say. Rendered copy stays
     plain; the constitutional detail lives in learn/.
   - idea -> the product backlog
   ============================================================================ */
(function () {
  'use strict';
  var CGA = window.CGA = window.CGA || {};
  if (!CGA.fixtures || !CGA.fixtures.v2) throw new Error('fixtures-support.js: fixtures-v2.js must load first');
  var V2 = CGA.fixtures.v2;

  var categories = [
    { id: 'bug', label: 'Something is broken', icon: 'alert-triangle', routesTo: 'The operators (Record Keeper)', note: 'A page errors, a button does nothing, data looks wrong.' },
    { id: 'translation', label: 'Wording or translation', icon: 'languages', routesTo: 'Translation support', note: 'A label reads wrong in your language, or is still in English.', deep: 'translation/translation-home.html' },
    { id: 'accessibility', label: 'Accessibility barrier', icon: 'users', routesTo: 'The operators (design)', note: 'Hard to read, hard to use with a keyboard or screen reader.' },
    { id: 'content', label: 'Wrong information', icon: 'file-text', routesTo: 'The operators (Archivist)', note: 'A rule, citation, or record is stated incorrectly.' },
    { id: 'abuse', label: 'Abuse or illegal content', icon: 'shield', routesTo: 'Moderation & legal team', note: 'Goes straight to the moderation & legal team, not the tech-support queue. Support staff never decide what people may say.', deep: 'operator/moderation.html' },
    { id: 'idea', label: 'An idea', icon: 'plus', routesTo: 'Product backlog', note: 'Something that would make the system better.' }
  ];
  var byCategory = {}; categories.forEach(function (c) { byCategory[c.id] = c; });

  var statuses = [
    { id: 'new', label: 'New', tone: 'info', order: 0 },
    { id: 'triaged', label: 'Triaged', tone: 'warn', order: 1 },
    { id: 'in_progress', label: 'In progress', tone: 'live', order: 2 },
    { id: 'resolved', label: 'Resolved', tone: 'good', order: 3 },
    { id: 'closed', label: 'Closed', tone: 'idle', order: 4 },
    { id: 'wont_fix', label: 'Won’t fix', tone: 'idle', order: 4 }
  ];
  var byStatus = {}; statuses.forEach(function (s) { byStatus[s.id] = s; });

  var severities = [
    { id: 'low', label: 'Low', tone: 'idle' },
    { id: 'normal', label: 'Normal', tone: 'info' },
    { id: 'high', label: 'High', tone: 'warn' },
    { id: 'critical', label: 'Critical', tone: 'danger' }
  ];

  function ev(who, rel, kind, text) { return { who: who, rel: rel, kind: kind, text: text }; }

  var tickets = [
    { id: 'ticket-ballot-confirm', n: 1042, title: 'Ballot receipt does not say which election it was for', category: 'bug', status: 'in_progress', severity: 'normal',
      reporter: 'u-pier7', assignee: 'Record Keeper', created: '2 days ago', updated: '4 hours ago', page: 'electoral/open-ballot.html', votes: 7,
      body: 'After committing my ballot the receipt shows a hash and a time, but not the name of the election. With two votes open at once I cannot tell them apart.',
      thread: [ ev('u-pier7', '2 days ago', 'open', 'Reported from the open-ballot screen.'),
                ev('Record Keeper', '1 day ago', 'status', 'Triaged — confirmed, the receipt template omits the election title.'),
                ev('Record Keeper', '4 hours ago', 'status', 'In progress — adding the title without exposing ballot contents.') ] },

    { id: 'ticket-floor-queue', n: 1039, title: 'Hand-raise queue order jumps when someone leaves the room', category: 'bug', status: 'triaged', severity: 'high',
      reporter: 'u-amaru', assignee: 'Record Keeper', created: '3 days ago', updated: '1 day ago', page: 'shared/live-room.html', votes: 12,
      body: 'In a committee hearing I was third in the queue to speak. Someone ahead of me dropped off and the whole order reshuffled — I lost my place.',
      thread: [ ev('u-amaru', '3 days ago', 'open', 'Happens every time a queued speaker disconnects.'),
                ev('Record Keeper', '1 day ago', 'status', 'Triaged — high, this affects fairness of the floor. Queue must be stable on departure.') ] },

    { id: 'ticket-org-codetermination', n: 1031, title: 'Worker seat did not appear at 100 employees', category: 'bug', status: 'resolved', severity: 'high',
      reporter: 'u-noor', assignee: 'Archivist', created: '6 days ago', updated: '2 days ago', page: 'social/org-profile.html', votes: 5,
      body: 'Our cooperative crossed 100 employees last cycle but no worker seat appeared on the board.',
      thread: [ ev('u-noor', '6 days ago', 'open', 'Headcount is 104, board still shows owner seats only.'),
                ev('Archivist', '4 days ago', 'status', 'Reproduced — the threshold check used the prior snapshot, not the live headcount.'),
                ev('Archivist', '2 days ago', 'status', 'Resolved — worker seat now seats on the next board cycle.') ] },

    { id: 'ticket-square-flood', n: 1048, title: 'Repeated identical posts flooding a hall', category: 'abuse', status: 'triaged', severity: 'high',
      reporter: 'u-kenji', assignee: 'Moderation & legal team', created: '1 day ago', updated: '6 hours ago', page: 'social/social-home.html', votes: 3,
      body: 'One account is posting the same message hundreds of times in the budget hall. Not a viewpoint problem — it is drowning the room.',
      routedNote: 'It went straight to the moderation & legal team. Flooding is judged by volume, not by what was said — no one’s viewpoint is ever the basis for removal.',
      thread: [ ev('u-kenji', '1 day ago', 'open', 'Same text, 300+ times in an hour.'),
                ev('Moderation & legal team', '6 hours ago', 'status', 'Taken up by the moderation & legal team — judged on volume, not content. What was said is never the basis.') ] },

    { id: 'ticket-market-units', n: 1044, title: 'Unit subdivision shows three decimals but accepts four', category: 'bug', status: 'new', severity: 'normal',
      reporter: 'u-pier7', assignee: null, created: '8 hours ago', updated: '8 hours ago', page: 'economy/units.html', votes: 1,
      body: 'The price field accepts 4 decimal places but the display rounds to milliunits (3). A 0.0001 offer shows as 0.000.',
      thread: [ ev('u-pier7', '8 hours ago', 'open', 'Planned-layer surface, but flagging for the wiring pass.') ] },

    { id: 'ticket-node-cert', n: 1019, title: 'Let’s Encrypt budget pre-flight blocks a valid per-name cert', category: 'bug', status: 'in_progress', severity: 'normal',
      reporter: 'u-box-c', assignee: 'Identity Broker', created: '5 days ago', updated: '12 hours ago', page: 'operator/dns.html', votes: 4,
      body: 'The pre-flight counted a wildcard backup against the 50/domain budget twice, so a legitimate per-name request was refused.',
      thread: [ ev('u-box-c', '5 days ago', 'open', 'Per-name primary refused with budget exhausted, but only 12 names are issued.'),
                ev('Identity Broker', '12 hours ago', 'status', 'In progress — the backup wildcard was double-counted. Fixing the pre-flight tally.') ] },

    { id: 'ticket-translation-term', n: 1036, title: 'Spanish term for “co-determination” reads as “co-decision”', category: 'translation', status: 'triaged', severity: 'low',
      reporter: 'u-amaru', assignee: 'Translation support', created: '4 days ago', updated: '2 days ago', page: 'social/org-profile.html', votes: 9,
      body: 'The machine draft rendered “co-determination” as “co-decisión”. The accepted civic term is “cogestión”. Several readers agree.',
      linkedTranslation: { code: 'es', modality: 'pages' },
      thread: [ ev('u-amaru', '4 days ago', 'open', 'Proposing “cogestión” — it is the established labor term.'),
                ev('Translation support', '2 days ago', 'status', 'Triaged — open for verification on the Spanish review queue. Needs 3 verifiers.') ] },

    { id: 'ticket-a11y-contrast', n: 1040, title: 'Proposed-flag gold is hard to read on the gallery cards', category: 'accessibility', status: 'resolved', severity: 'normal',
      reporter: 'u-noor', assignee: 'design', created: '7 days ago', updated: '3 days ago', page: 'social/legitimacy.html', votes: 6,
      body: 'The gold “Proposed” flag on a dark card sits near the contrast floor for me.',
      thread: [ ev('u-noor', '7 days ago', 'open', 'Measured ~3.6:1.'),
                ev('design', '3 days ago', 'status', 'Resolved — raised to gold-500, now 4.9:1. Verified against the a11y battery.') ] },

    { id: 'ticket-idea-digest', n: 1051, title: 'A weekly digest of what my representatives did', category: 'idea', status: 'new', severity: 'low',
      reporter: 'u-kenji', assignee: null, created: '5 hours ago', updated: '5 hours ago', page: 'civic/my-civic-life.html?tab=representatives', votes: 18,
      body: 'It would help to get a short weekly summary of every vote, statement, and office-hour from the seats I belong to.',
      thread: [ ev('u-kenji', '5 hours ago', 'open', 'Built from public records, so no privacy cost.') ] },

    { id: 'ticket-content-citation', n: 1027, title: 'Emergency-powers max duration cited as 60 days, should be 90', category: 'content', status: 'closed', severity: 'normal',
      reporter: 'u-pier7', assignee: 'Archivist', created: '9 days ago', updated: '6 days ago', page: 'legislature/emergency-powers.html', votes: 2,
      body: 'A tooltip said emergency powers last at most 60 days. The constitutional ceiling is 90.',
      thread: [ ev('u-pier7', '9 days ago', 'open', 'The constitution says 90.'),
                ev('Archivist', '6 days ago', 'status', 'Closed — corrected to 90 days and added the citation.') ] },

    { id: 'ticket-captions-ko', n: 1047, title: 'Korean captions missing on “From a bill to a law”', category: 'translation', status: 'in_progress', severity: 'low',
      reporter: 'u-kenji', assignee: 'Translation support', created: '2 days ago', updated: '1 day ago', page: 'learn/lesson.html', votes: 4,
      body: 'The lesson video has Korean audio but no Korean captions track — accessibility gap for that lesson.',
      linkedTranslation: { code: 'ko', modality: 'captions' },
      thread: [ ev('u-kenji', '2 days ago', 'open', 'Audio dub exists; captions file is absent.'),
                ev('Translation support', '1 day ago', 'status', 'In progress — generating the .vtt first round for review.') ] },

    { id: 'ticket-wontfix-theme', n: 1009, title: 'Add a light theme for the governance register', category: 'idea', status: 'wont_fix', severity: 'low',
      reporter: 'u-amaru', assignee: 'design', created: '14 days ago', updated: '10 days ago', page: null, votes: 1,
      body: 'Could the dark governance register get a light option?',
      thread: [ ev('u-amaru', '14 days ago', 'open', 'Preference.'),
                ev('design', '10 days ago', 'status', 'Won’t fix for now — the governance register is intentionally dark; the brand register stays for the launchpad and Learn. Forced-colors mode is fully supported.') ] }
  ];
  var byTicket = {}; tickets.forEach(function (t) { byTicket[t.id] = t; });

  function countBy(field, value) { return tickets.filter(function (t) { return t[field] === value; }).length; }
  var openCount = tickets.filter(function (t) { return ['new', 'triaged', 'in_progress'].indexOf(t.status) >= 0; }).length;

  V2.support = {
    categories: categories, byCategory: byCategory,
    statuses: statuses, byStatus: byStatus, severities: severities,
    tickets: tickets, byTicket: byTicket,
    counts: { open: openCount, total: tickets.length, byStatus: function (id) { return countBy('status', id); }, byCategory: function (id) { return countBy('category', id); } }
  };
})();
