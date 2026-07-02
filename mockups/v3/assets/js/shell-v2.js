/* ============================================================================
   WORLD OF STATECRAFT MOCKUPS v3 — shell-v2.js  (the shared chrome)
   The sole chrome renderer for every page in mockups/v3, a SELF-CONTAINED
   static environment. Renders the floating header, the two-tier Menu (a short
   player tier over the full design-contract sitemap), the guided tour, the
   Learn/Report drawer, the footer, and the demo controls.

   A page is a complete HTML doc with <main id="main"> + window.CGA_PAGE,
   loading (in this order):
     <head>  assets/css/colors_and_type.css   assets/css/fonts.css
             assets/css/mockup.css            assets/css/v2.css
             assets/js/demo-state.js
     </body> assets/js/fixtures.js  assets/js/fixtures-v2.js  [domain spines]
             manifest.js  assets/js/icons.js  assets/js/i18n.js  shell-v2.js

   All links resolve inside mockups/v3 via href()/hrefV2() and carry the demo
   state. hrefV1()/ROOT_V1 survive only as aliases for older inline scripts.
   ============================================================================ */
(function () {
  'use strict';
  var CGA = window.CGA = window.CGA || {};

  var SRC = (document.currentScript && document.currentScript.src) || '';
  /* v3 is SELF-CONTAINED: one root. ROOT_V1 is an alias kept only so older
     inline page scripts calling hrefV1() keep working. */
  var ROOT_V2 = SRC.replace(/assets\/js\/shell-v2\.js.*$/, '');
  var ROOT_V1 = ROOT_V2;

  function fail(msg) {
    var b = document.createElement('div');
    b.setAttribute('role', 'alert');
    b.style.cssText = 'padding:1rem;font-family:monospace;background:var(--gov-bg,Canvas);color:var(--gov-fg,CanvasText)';
    b.textContent = 'CGA v2 shell error: ' + msg;
    document.body.insertBefore(b, document.body.firstChild);
    throw new Error(msg);
  }
  if (!CGA.state) fail('demo-state.js must load in <head> before shell-v2.js');
  if (!CGA.fixtures) fail('fixtures.js must load before shell-v2.js');
  if (!CGA.fixtures.v2) fail('fixtures-v2.js must load before shell-v2.js');
  if (!CGA.icons) fail('icons.js must load before shell-v2.js');
  if (!CGA.i18n) fail('i18n.js must load before shell-v2.js');

  var F = CGA.fixtures, R = F.registry, W = F.world, BY = F.byId, V2 = F.v2;
  var t = CGA.i18n.t;
  var PAGE = window.CGA_PAGE || { id: null, title: document.title, nav: null, register: 'governance' };

  /* --------------------------------------------------------------- utils */
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  /* strip constitutional codes out of a display string built from v1 data
     (role/form refs in availableTo / creates / prereq fields). Plain language
     for the player; the data keys themselves are untouched. */
  function plainCodes(s) {
    return String(s == null ? '' : s)
      .replace(/\bImplied by\s+/gi, '')
      .replace(/\(?\b[RIF]-[A-Z0-9]{2,3}(?:-\d{3})?\)?/g, '')   /* role / form / institution codes */
      .replace(/\b(?:CLK|WF)-[A-Z0-9-]{2,7}/g, '')              /* clock / workflow codes */
      .replace(/\bArt\.\s*[IVX]+/g, '')                          /* article numbers */
      .replace(/§\s*\d+/g, '')                                   /* section numbers */
      .replace(/\(([^()]*)\)/g, '$1')                            /* unwrap a remaining plain-language gloss */
      .replace(/\(\s*\)/g, '')                                   /* drop any empty parens */
      .replace(/\s*[;,]\s*(?=[;,]|$)/g, '')                      /* drop dangling separators */
      .replace(/^[\s;,/·]+|[\s;,/·]+$/g, '')                     /* trim stray leading/trailing separators */
      .replace(/\s{2,}/g, ' ')
      .trim();
  }
  function icon(name, opts) {
    opts = opts || {};
    if (!CGA.icons.has(name)) name = 'info';
    var cls = 'icon' + (opts.size === 'sm' ? ' icon--sm' : '') +
      (CGA.icons.directional.indexOf(name) >= 0 ? ' icon--directional' : '') +
      (opts.cls ? ' ' + opts.cls : '');
    var aria = opts.label ? ' role="img" aria-label="' + esc(opts.label) + '"' : ' aria-hidden="true"';
    return '<svg class="' + cls + '"' + aria + '><use href="#i-' + name + '"></use></svg>';
  }
  function badge(tone, label, iconName) {
    return '<span class="badge badge--' + tone + '">' + (iconName ? icon(iconName, { size: 'sm' }) : '') + esc(label) + '</span>';
  }
  function formatPop(n) {
    if (n == null) return '—';
    if (n >= 1e9) return (n / 1e9).toFixed(1) + 'B';
    if (n >= 1e6) return (n / 1e6).toFixed(1) + 'M';
    return Number(n).toLocaleString('en-US');
  }
  var ADM_LABELS = ['Planet', 'Country', 'State / Province', 'County', 'Municipality', 'Township', 'Neighborhood'];
  function admLabel(level) { return ADM_LABELS[Math.min(level, 6)]; }

  /* Plain-language pill (operator-console simplification pattern): a human
     label up front, the precise term + citation in the tooltip. */
  function pill(tone, label, tip) {
    return '<span class="pill pill--' + tone + '"' + (tip ? ' title="' + esc(tip) + '"' : '') + '>' + esc(label) + '</span>';
  }

  function hrefV2(rel, overrides) { return CGA.state.link(ROOT_V2 + rel, overrides || {}); }
  function hrefV1(rel, overrides) { return CGA.state.link(ROOT_V1 + rel, overrides || {}); }

  /* ----------------------------------------------- built-file detection */
  var builtFiles = {};
  (window.CGA_MANIFEST || []).forEach(function (rec) { builtFiles[rec.file] = true; });
  function isBuiltV2(rel) { return !!builtFiles[rel.split('?')[0].split('#')[0]]; }
  function plannedFlag(stage) {
    return '<span class="planned-flag">' + (stage ? esc(stage) : 'Planned') + '</span>';
  }

  /* ----------------------------------------------------------- live region */
  var liveEl;
  function announce(text) {
    if (!liveEl) return;
    liveEl.textContent = '';
    setTimeout(function () { liveEl.textContent = text; }, 30);
  }

  /* --------------------------------------------------- the live-room variants
     THE single source for the eight room configurations — id, label, icon, and
     the one-line blurb. index.html and any picker render from this list. */
  var ROOM_VARIANTS = [
    { id: 'committee', label: 'Committee hearing', icon: 'landmark', blurb: 'Testimony to the record; a committee vote.' },
    { id: 'legislative', label: 'Legislative session', icon: 'landmark', blurb: 'The whole chamber, live — every vote counted against all serving seats.' },
    { id: 'exec', label: 'Executive committee', icon: 'briefcase', blurb: 'Equal-power members deliberate and decide.' },
    { id: 'board', label: 'Board meeting', icon: 'building', blurb: 'Worker and owner seats; the org’s own rules of order.' },
    { id: 'court', label: 'Court hearing', icon: 'scale', blurb: 'The judge chairs; advocates hold the floor.' },
    { id: 'forum', label: 'Candidate forum', icon: 'vote', blurb: 'Candidates speak in turn during the approval phase.' },
    { id: 'townhall', label: 'Referendum town hall', icon: 'users', blurb: 'Open deliberation before the vote window.' },
    { id: 'group', label: 'Group meeting', icon: 'users', blurb: 'A voluntary group, meeting on its own terms.' }
  ];

  /* The standard "Planned" banner — ONE wording for every design-ahead surface.
     Pages call S.plannedBanner() instead of hand-rolling their own copy. */
  function plannedBanner(extra) {
    return '<div class="banner banner--demo planned-banner"><div>' +
      '<span class="banner-title">Planned — a preview.</span> ' +
      esc(extra || 'This part of the world is designed ahead of the build. Nothing here is live yet, and no real money is anywhere.') +
      '</div></div>';
  }

  /* Humanize a raw entity-state token for player display: an explicit map wins;
     otherwise strip machine punctuation ([Brackets], pipes, hyphen-chains). */
  function plainState(s, map) {
    s = String(s == null ? '' : s).trim();
    if (map && Object.prototype.hasOwnProperty.call(map, s)) return map[s];
    return s.replace(/[\[\]]/g, '')
      .replace(/\s*\|\s*/g, ' / ')
      .replace(/(\w)-(\w)/g, '$1 $2')
      .replace(/\s{2,}/g, ' ')
      .trim();
  }

  /* ------------------------------------------------------ the guided tour
     One linear path through the whole game layer. A page enters "tour mode"
     when its URL carries ?step=N (1-based); shell renders a follow-along bar
     at the top of <main> with Back / Next that walk this order. The order is
     also the spine of tour.html. */
  /* The complete ordered walk — EVERY mocked-up screen, grouped by act, in a
     logical narrative order. The single source of truth for both the follow-
     along bar (?step=N) and tour.html. */
  var TOUR = [
    { act: 'Arrive', rel: 'civic/join.html', title: 'You’re invited', blurb: 'A friend’s invite link lands here — see the room, pick a name, step in. Nothing else required.' },
    { act: 'Arrive', rel: 'shared/live-room.html?variant=group', title: 'Your first room', blurb: 'People talking, meeting, and deciding — live. Watching is open to everyone.' },
    { act: 'Arrive', rel: 'civic/today.html', title: 'Home — what’s happening', blurb: 'Everything live right now in the places you belong to, plus the community calendar.' },
    { act: 'Arrive', rel: 'atlas.html', title: 'The Atlas', blurb: 'A live heartbeat of the whole game — every place and every number on one map.' },
    { act: 'Arrive', rel: 'index.html', title: 'The launchpad', blurb: 'Every door into the world on one screen.' },
    { act: 'Arrive', rel: 'civic/my-civic-life.html', title: 'My profile', blurb: 'Your record, your wallet, your representatives, and your achievements in one place.' },

    { act: 'Become a resident', rel: 'civic/onboarding.html', title: 'Create your account', blurb: 'A name is all it takes to start.' },
    { act: 'Become a resident', rel: 'civic/residency.html', title: 'Say where you live', blurb: 'Residency is the one key — it unlocks voting and standing for office.' },
    { act: 'Become a resident', rel: 'civic/identity-verification.html', title: 'Link an ID (optional)', blurb: 'Optional — link a real-world ID where your place supports it. Your rights never depend on this step.' },
    { act: 'Become a resident', rel: 'civic/relocation.html', title: 'Move somewhere new', blurb: 'Take your residency with you when life moves.' },
    { act: 'Become a resident', rel: 'civic/advocate-registration.html', title: 'Become an advocate', blurb: 'Sign up to argue cases for others.' },

    { act: 'Speak & gather', rel: 'social/social-home.html', title: 'The public square', blurb: 'The open feed, and who’s in the halls right now.' },
    { act: 'Speak & gather', rel: 'social/profile.html?who=marcus-chen&tab=office', title: 'Anyone’s profile', blurb: 'The same profile, for a neighbour who holds a seat — one person, any role.' },
    { act: 'Speak & gather', rel: 'groups/groups-home.html', title: 'Messages & parties', blurb: 'Direct messages and temporary crews — talk, files, voice, video.' },
    { act: 'Speak & gather', rel: 'groups/group-detail.html', title: 'A conversation', blurb: 'A party thread with the shared toolkit.' },
    { act: 'Speak & gather', rel: 'groups/group-create.html', title: 'Start a party', blurb: 'Start a DM or a temporary crew.' },
    { act: 'Speak & gather', rel: 'civic/petitions.html', title: 'Petitions', blurb: 'Gather signatures to put a question to everyone.' },
    { act: 'Speak & gather', rel: 'civic/petition-detail.html', title: 'A petition', blurb: 'One petition — its progress and signatures.' },

    { act: 'An election', rel: 'journeys/journey.html?id=election', title: 'An election, end to end', blurb: 'The flagship journey — now, your part, next.' },
    { act: 'An election', rel: 'electoral/candidacy-registration.html', title: 'Stand for office', blurb: 'If you live there, you can run — that’s the whole requirement.' },
    { act: 'An election', rel: 'electoral/candidate-profile.html', title: 'A candidate', blurb: 'Who they are, who endorses them.' },
    { act: 'An election', rel: 'electoral/election-detail.html', title: 'An election', blurb: 'The race, its phase, and the clock.' },
    { act: 'An election', rel: 'shared/live-room.html?variant=forum', title: 'The candidate forum', blurb: 'Candidates take the floor in turn — a Live Civic Room.' },
    { act: 'An election', rel: 'electoral/open-ballot.html', title: 'Open ballot', blurb: 'The approval phase — quietly approve the people you’d want on the ballot.' },
    { act: 'An election', rel: 'electoral/ranked-ballot.html', title: 'Ranked ballot', blurb: 'Rank your choices — seats go out in fair shares, and no vote is wasted.' },
    { act: 'An election', rel: 'electoral/results.html', title: 'Results', blurb: 'The count, round by round — watch votes move until every seat is filled.' },
    { act: 'An election', rel: 'electoral/vacancy-countback.html', title: 'Filling an empty seat', blurb: 'A seat falls vacant — the same ballots decide, no new election needed (a countback).' },
    { act: 'An election', rel: 'electoral/election-board-console.html', title: 'The election board', blurb: 'The neutral officers who run the vote.' },

    { act: 'Lawmaking', rel: 'journeys/journey.html?id=bill', title: 'A bill becomes law', blurb: 'A reading, a committee, the floor vote, the versioned law.' },
    { act: 'Lawmaking', rel: 'legislature/legislature-home.html', title: 'The chamber', blurb: 'The seated legislature for your jurisdiction.' },
    { act: 'Lawmaking', rel: 'shared/live-room.html?variant=legislative', title: 'The legislative session', blurb: 'The chamber in the round — votes landing live.' },
    { act: 'Lawmaking', rel: 'legislature/session-console.html', title: 'Run a session', blurb: 'The Speaker’s console — open, run, and adjourn a session.' },
    { act: 'Lawmaking', rel: 'legislature/bills.html', title: 'Bills', blurb: 'Everything moving through the chamber.' },
    { act: 'Lawmaking', rel: 'shared/bill.html', title: 'A bill — the conversation', blurb: 'Follow a bill, comment on it, and watch the redlines land.' },
    { act: 'Lawmaking', rel: 'legislature/bill-detail.html', title: 'A bill — the record', blurb: 'The formal record — lifecycle and the vote math.' },
    { act: 'Lawmaking', rel: 'legislature/committees.html', title: 'Committees', blurb: 'Where bills are shaped before the floor.' },
    { act: 'Lawmaking', rel: 'legislature/committee-detail.html', title: 'A committee', blurb: 'Members, reports, and referred bills — the standing record.' },
    { act: 'Lawmaking', rel: 'shared/live-room.html?variant=committee', title: 'A committee hearing', blurb: 'Testimony to the record; a committee vote.' },
    { act: 'Lawmaking', rel: 'legislature/referendums.html', title: 'Referendums', blurb: 'Questions put to the whole jurisdiction.' },
    { act: 'Lawmaking', rel: 'shared/live-room.html?variant=townhall', title: 'A referendum town hall', blurb: 'Residents deliberate before the vote.' },
    { act: 'Lawmaking', rel: 'legislature/emergency-powers.html', title: 'Emergency powers', blurb: 'Bounded, clock-limited, and reviewed.' },
    { act: 'Lawmaking', rel: 'legislature/oversight.html', title: 'Ethics & removals', blurb: 'Complaints, removal votes, and vacant seats.' },
    { act: 'Lawmaking', rel: 'legislature/speaker-tools.html', title: 'The Speaker', blurb: 'The neutral chair — ties, priorities, and presiding over removals.' },
    { act: 'Lawmaking', rel: 'legislature/settings.html', title: 'The chamber’s rules', blurb: 'The rules this chamber can change — each inside limits no law can override.' },

    { act: 'The executive', rel: 'executive/executive-home.html', title: 'The executive', blurb: 'The government’s doing arm — it carries out the laws and runs the departments.' },
    { act: 'The executive', rel: 'shared/live-room.html?variant=exec', title: 'The executive committee', blurb: 'Equal seats around the table, deliberating.' },
    { act: 'The executive', rel: 'executive/departments.html', title: 'Departments', blurb: 'The standing machinery the executive runs.' },
    { act: 'The executive', rel: 'executive/department-detail.html', title: 'A department', blurb: 'Its board of governors and its work.' },
    { act: 'The executive', rel: 'executive/executive-actions.html', title: 'Executive actions', blurb: 'Orders, scope-checked before they issue.' },
    { act: 'The executive', rel: 'executive/department-reporting.html', title: 'Department reporting', blurb: 'What a department must report, and to whom.' },

    { act: 'The judiciary', rel: 'journeys/journey.html?id=court-case', title: 'A court case', blurb: 'File, the panel forms, advocates argue, the ruling stands.' },
    { act: 'The judiciary', rel: 'judiciary/judiciary-home.html', title: 'The judiciary', blurb: 'Appointed by default; the courts of your jurisdiction.' },
    { act: 'The judiciary', rel: 'judiciary/case-docket.html', title: 'The case docket', blurb: 'Cases filed, pending, and decided.' },
    { act: 'The judiciary', rel: 'judiciary/case-detail.html', title: 'A case', blurb: 'The panel, the advocates, the evidence.' },
    { act: 'The judiciary', rel: 'shared/live-room.html?variant=court', title: 'The courtroom', blurb: 'The judge chairs; advocates hold the floor.' },
    { act: 'The judiciary', rel: 'judiciary/constitutional-challenge.html', title: 'A constitutional challenge', blurb: 'The three-path challenge ending in direct law-editing.' },
    { act: 'The judiciary', rel: 'judiciary/advocate-console.html', title: 'The advocate console', blurb: 'An advocate’s cases and filings.' },
    { act: 'The judiciary', rel: 'judiciary/juror-view.html', title: 'A juror’s view', blurb: 'Serving on a jury.' },

    { act: 'Organizations', rel: 'journeys/journey.html?id=start-org', title: 'Found an organization', blurb: 'Register a party, business, nonprofit, or common-good corp.' },
    { act: 'Organizations', rel: 'organizations/org-registry.html', title: 'The org registry', blurb: 'Every registered organization, in one open list.' },
    { act: 'Organizations', rel: 'social/org-profile.html', title: 'An organization', blurb: 'One page — its people, board, listings, jobs, and record.' },
    { act: 'Organizations', rel: 'organizations/co-determination.html', title: 'Worker seats on the board', blurb: 'From 100 employees, workers hold board seats; at 2,000 they hold half.' },
    { act: 'Organizations', rel: 'organizations/board-elections.html', title: 'Board elections', blurb: 'Workers and owners elect the board.' },
    { act: 'Organizations', rel: 'shared/live-room.html?variant=board', title: 'A board meeting', blurb: 'Worker and owner seats, in the same room.' },
    { act: 'Organizations', rel: 'organizations/cgc-detail.html', title: 'A common-good corporation', blurb: 'Everything it makes is public domain, forever.' },
    { act: 'Organizations', rel: 'organizations/transfers-conversions.html', title: 'Ownership changes', blurb: 'Sold, taken public, restructured, or wound down.' },

    { act: 'The economy', rel: 'economy/economy-home.html', title: 'The economy', blurb: 'The hub — the open market, the live tape, and the economic clock.' },
    { act: 'The economy', rel: 'economy/exchange.html', title: 'The exchange floor', blurb: 'Organization shares trade on a live order book.' },
    { act: 'The economy', rel: 'economy/marketplace.html', title: 'The open market', blurb: 'Offers and requests, side by side — goods, work, and mutual aid.' },
    { act: 'The economy', rel: 'economy/listing-detail.html', title: 'A listing', blurb: 'One offer in full.' },
    { act: 'The economy', rel: 'economy/request-detail.html', title: 'A request', blurb: 'One ask, and how it’s met.' },
    { act: 'The economy', rel: 'economy/agreements.html', title: 'Agreements', blurb: 'Contracts that can never override anyone’s rights.' },
    { act: 'The economy', rel: 'economy/agreement-detail.html', title: 'An agreement', blurb: 'Draft, redline, and sign — the negotiation interface.' },
    { act: 'The economy', rel: 'economy/wallet.html', title: 'My wallet', blurb: 'A private balance with transfers — like a ballot, only you can read it.' },
    { act: 'The economy', rel: 'economy/joint-ledgers.html', title: 'Joint ledgers', blurb: 'Co-owned accounts that move only by agreement.' },
    { act: 'The economy', rel: 'economy/units.html', title: 'Units & money', blurb: 'The currency, its subdivisions, and the levers both chambers must pull.' },
    { act: 'The economy', rel: 'economy/stipend.html', title: 'The civic stipend', blurb: 'A floor everyone shares, plus a small bump for people doing civic work.' },
    { act: 'The economy', rel: 'economy/treasury.html', title: 'Public finance', blurb: 'Revenue, budget, disbursement, and the public ledger.' },
    { act: 'The economy', rel: 'economy/org-settings.html', title: 'Org economics', blurb: 'Shares, dues, and the organization’s books.' },

    { act: 'Recognition & reach', rel: 'social/achievements.html', title: 'Achievements', blurb: 'The record of what you’ve done, on your profile — never a vote, a seat, or an advantage.' },
    { act: 'Recognition & reach', rel: 'social/legitimacy.html', title: 'Reach', blurb: 'How many of a place’s people are actually here — a gauge, never a lever.' },

    { act: 'Places & their processes', rel: 'jurisdictions/jurisdiction-browser.html', title: 'Every place on Earth', blurb: 'Planet to neighborhood — boundaries, people, and who represents them.' },
    { act: 'Places & their processes', rel: 'jurisdictions/district-mapper.html', title: 'The district mapper', blurb: 'How a legislature’s seats map onto real ground.' },
    { act: 'Places & their processes', rel: 'jurisdictions/bootstrap.html', title: 'Wake a place up', blurb: 'A place reaches critical mass and elects its first government (bootstrap).' },
    { act: 'Places & their processes', rel: 'jurisdictions/union-formation.html', title: 'Merge places into a union', blurb: 'Check the differences, agree one rulebook, then everyone votes.' },
    { act: 'Places & their processes', rel: 'jurisdictions/disintermediation.html', title: 'Remove a middle layer', blurb: 'A layer of government dissolves — its places answer directly to the level above (disintermediation).' },
    { act: 'Places & their processes', rel: 'jurisdictions/restoration.html', title: 'Rebuild a lost government', blurb: 'When a government is captured or destroyed, elections rebuild it — the three-tier cascade (restoration).' },
    { act: 'Places & their processes', rel: 'jurisdictions/federation.html', title: 'Between governments', blurb: 'Neighboring places settle a shared border — deliberation, referendum, done.' },

    { act: 'Run a node', rel: 'operator/operator-home.html', title: 'The operator plane', blurb: 'The volunteer servers the world runs on — keeping it online buys no vote and no seat.' },
    { act: 'Run a node', rel: 'system/setup.html', title: 'Found an instance', blurb: 'From first boot to a seated constitution — start a world, or join one.' },
    { act: 'Run a node', rel: 'operator/setup.html', title: 'Set up your node', blurb: 'The technical node setup — DNS, certs, mesh keys.' },
    { act: 'Run a node', rel: 'operator/console.html', title: 'The operator console', blurb: 'Your roles at a glance; everything advanced behind a toggle.' },
    { act: 'Run a node', rel: 'operator/roles.html', title: 'Roles & channels', blurb: 'The trust channels a node can adopt.' },
    { act: 'Run a node', rel: 'operator/mesh.html', title: 'Mesh & peers', blurb: 'Join the mesh, sync the record, and become a full peer.' },
    { act: 'Run a node', rel: 'operator/dns.html', title: 'DNS & certificates', blurb: 'Names and certs for your node.' },
    { act: 'Run a node', rel: 'operator/identity.html', title: 'Identity', blurb: 'How players stay themselves on any node.' },
    { act: 'Run a node', rel: 'operator/moderation.html', title: 'Moderation & legal', blurb: 'The reactive, content-neutral legal duties of a node.' },
    { act: 'Run a node', rel: 'operator/versioning.html', title: 'Versions & upgrades', blurb: 'Constitutional version and peer-upgrade agreement.' },

    { act: 'Learn & get help', rel: 'learn/learn-home.html', title: 'Learn', blurb: 'Short lessons — video, procedure, a check.' },
    { act: 'Learn & get help', rel: 'learn/lesson.html?id=cast-your-ballot', title: 'A lesson', blurb: 'Video + the standard procedure + a knowledge check.' },
    { act: 'Learn & get help', rel: 'learn/guides.html', title: 'Guides & procedures', blurb: 'Every process, step by step, searchable.' },
    { act: 'Learn & get help', rel: 'shared/video-player.html', title: 'The video library', blurb: 'Every guide video, narrated and captioned in many languages.' },
    { act: 'Learn & get help', rel: 'translation/translation-home.html', title: 'Help translate', blurb: 'Getting the world into every language its people speak.' },
    { act: 'Learn & get help', rel: 'translation/language.html?code=es', title: 'A language', blurb: 'One language’s coverage, piece by piece.' },
    { act: 'Learn & get help', rel: 'support/report.html', title: 'Report an issue', blurb: 'It routes itself — to operators, translators, or the moderation & legal team.' },
    { act: 'Learn & get help', rel: 'support/tickets.html', title: 'Tickets', blurb: 'Everything reported, and where it stands.' },
    { act: 'Learn & get help', rel: 'support/ticket.html', title: 'A ticket', blurb: 'One issue, its thread, and its status.' },
    { act: 'Learn & get help', rel: 'shared/constitutional-questions.html', title: 'Open constitutional questions', blurb: 'The “why” debates this design surfaced — kept in the open.' },

    { act: 'Records & the clock', rel: 'system/public-records.html', title: 'Public records', blurb: 'The permanent public record — nothing in it can be quietly changed.' },
    { act: 'Records & the clock', rel: 'system/audit-chain.html', title: 'The audit chain', blurb: 'How every act is sealed and verifiable.' },
    { act: 'Records & the clock', rel: 'system/amendments.html', title: 'Amendments', blurb: 'Changing the changeable rules, within limits nothing can override.' },
    { act: 'Records & the clock', rel: 'system/term-sync.html', title: 'Terms end together', blurb: 'Every elected term ends on the same day; the next election is scheduled the moment the last one certifies.' },
    { act: 'Records & the clock', rel: 'shared/clocks.html', title: 'The clocks', blurb: 'The scheduled sweeps that drive the world.' },

    { act: 'For the build team', rel: 'shared/coverage.html', title: 'Coverage', blurb: 'What’s mocked, and the contract behind it.' },
    { act: 'For the build team', rel: 'shared/coverage-ops.html', title: 'Coverage — the ops matrix', blurb: 'Roles × processes × forms — the definition-of-done instrument.' },
    { act: 'For the build team', rel: 'shared/styleguide.html', title: 'Style guide', blurb: 'The design system — tokens, type, components.' },
    { act: 'For the build team', rel: 'shared/accessibility.html', title: 'Accessibility', blurb: 'The accessibility commitments.' }
  ];

  function currentTourIndex() {
    try {
      var s = new URLSearchParams(location.search).get('step');
      if (!s) return -1;
      var i = parseInt(s, 10) - 1;
      return (i >= 0 && i < TOUR.length) ? i : -1;
    } catch (e) { return -1; }
  }
  function tourHref(i) {
    var abs = CGA.state.link(ROOT_V2 + TOUR[i].rel);
    try { var u = new URL(abs, document.baseURI); u.searchParams.set('step', String(i + 1)); return u.href; }
    catch (e) { return abs + (abs.indexOf('?') < 0 ? '?' : '&') + 'step=' + (i + 1); }
  }
  function renderTourBar() {
    var i = currentTourIndex();
    if (i < 0) return '';
    var stop = TOUR[i], pct = Math.round((i + 1) / TOUR.length * 100);
    var back = i > 0
      ? '<a class="btn btn--ghost btn--sm" href="' + tourHref(i - 1) + '">' + icon('chevron-left', { size: 'sm' }) + ' Back</a>'
      : '<a class="btn btn--ghost btn--sm" href="' + hrefV2('tour.html') + '">' + icon('chevron-left', { size: 'sm' }) + ' Start</a>';
    var next = i < TOUR.length - 1
      ? '<a class="btn btn--primary btn--sm" href="' + tourHref(i + 1) + '">Next ' + icon('chevron-right', { size: 'sm' }) + '</a>'
      : '<a class="btn btn--primary btn--sm" href="' + hrefV2('tour.html') + '">Finish ' + icon('check', { size: 'sm' }) + '</a>';
    return '<div class="tour-bar" role="navigation" aria-label="Guided tour">' +
      '<div class="tour-bar-text"><span class="tour-step">' + icon('map', { size: 'sm' }) + ' Guided tour · step ' + (i + 1) + ' of ' + TOUR.length + '</span>' +
      '<strong class="tour-title">' + esc(stop.title) + '</strong>' +
      '<span class="tour-blurb">' + esc(stop.blurb) + '</span></div>' +
      '<div class="tour-bar-nav">' + back + next +
      '<a class="tour-exit" href="' + hrefV2('tour.html') + '">All steps</a></div>' +
      '<div class="tour-prog" aria-hidden="true"><i style="inline-size:' + pct + '%"></i></div></div>';
  }

  /* ----------------------------------------------- the Learn + Report drawer
     v3 keystone: a collapsible "learn about this screen + report an issue" panel
     at the foot of every page. The constitutional "why" and the deep references
     live HERE (the learning layer), never in the plain player chrome. The
     multi-track guide video lazy-loads when the drawer is opened. */
  var LEARN_BY_MODULE = {
    civic: { video: 'v-find-live', about: 'Your civic home — what’s happening now, and what’s yours to act on.' },
    journeys: { video: 'v-welcome', about: 'How this plays out — what’s happening now and where you fit in.' },
    economy: { video: 'v-market', about: 'How the economy works — trading, agreements, and money, in plain terms.' },
    social: { video: 'v-square', about: 'How the social layer works — the square, groups, and reaching people.' },
    groups: { video: 'v-square', about: 'Groups — talking, meeting, and gathering with other people.' },
    electoral: { video: 'v-welcome', about: 'How elections work here — anyone who lives in a place can vote and stand for office. Approvals pick the ballot; ranking fills the seats in fair shares, so no vote is wasted.' },
    legislature: { video: 'v-floor', about: 'How lawmaking works — bills are shaped in committee, debated on the floor, and every vote is counted against all serving seats, so absence never shrinks the bar.' },
    executive: { video: 'v-welcome', about: 'The executive is the doing arm — it carries out the laws and runs the departments, and it answers to the legislature that created it.' },
    judiciary: { video: 'v-welcome', about: 'How the courts work — panels of judges, advocates open to anyone, juries of residents, and rulings on the public record.' },
    organizations: { video: 'v-welcome', about: 'Organizations — parties, businesses, nonprofits, and common-good corporations share one open registry. Anyone, person or organization, can endorse any candidate.' },
    jurisdictions: { video: 'v-welcome', about: 'Places — every jurisdiction from a neighborhood to the planet: how they wake up, merge, split, and govern themselves.' },
    operator: { video: 'v-node', about: 'Running a node — the volunteer servers the world runs on. Keeping it online buys no vote and no seat.' },
    system: { video: 'v-node', about: 'The permanent public record, the clocks that drive the world, and how an instance is founded.' },
    learn: { video: 'v-welcome', about: 'Learning your way around World of Statecraft.' },
    translation: { video: 'v-translate', about: 'How the interface gets into your language — and how to help.' },
    support: { video: 'v-welcome', about: 'Getting help and reporting anything that’s wrong.' }
  };
  var LEARN_BY_ID = {
    'atlas': { video: 'v-welcome', about: 'What the Atlas shows — a live, public heartbeat of every node, place, and number across the whole game. Appearing on the map is opt-in and approximate; reach and growth are display-only, never a lever on anyone’s rights.' },
    'shared/live-room': { video: 'v-floor', about: 'How a live room works — raising a hand, taking the floor, and the vote.' },
    'economy/exchange': { video: 'v-market', about: 'How the exchange works — the order book and the live tape.' },
    'economy/stipend': { video: 'v-units', about: 'How the civic stipend works — a floor everyone shares plus a small role bump.' },
    'social/legitimacy': { video: 'v-welcome', about: 'What reach means — a transparency gauge, never a lever on anyone’s rights.' },
    'journeys/journey': { video: 'v-welcome', about: 'Learn by doing — each journey walks you through one real process, step by step. Finishing one earns an achievement on your profile.' }
  };
  function learnFor() {
    return LEARN_BY_ID[PAGE.id] || LEARN_BY_MODULE[PAGE.module] || { video: 'v-welcome', about: 'A quick guide to this screen.' };
  }
  function renderLearnDrawer() {
    return '<details class="learn-drawer" id="learn-drawer">' +
      '<summary>' + icon('graduation-cap', { size: 'sm' }) + ' <span>Learn about this screen</span>' +
      '<span class="ld-dot" aria-hidden="true">·</span>' + icon('flag', { size: 'sm' }) + ' <span>Report an issue</span>' +
      icon('chevron-down', { size: 'sm', cls: 'ld-caret' }) + '</summary>' +
      '<div class="ld-body" data-ld-body><p class="gloss">Open to load the guide…</p></div></details>';
  }
  function loadScript(rel, done) {
    var s = document.createElement('script'); s.src = ROOT_V2 + rel;
    s.onload = done; s.onerror = function () { done(); };
    document.head.appendChild(s);
  }
  function ensureLearnDeps(cb) {
    function s0() { if (CGA.fixtures.flows) return s1(); loadScript('assets/js/fixtures-flows.js', s1); }
    function s1() { if (CGA.fixtures.v2.learn) return s2(); loadScript('assets/js/fixtures-learn.js', s2); }
    function s2() { if (CGA.fixtures.v2.tr) return s3(); loadScript('assets/js/fixtures-translation.js', s3); }
    function s3() { if (CGA.v2c) return cb(true); loadScript('assets/js/components-v2.js', function () { cb(!!CGA.v2c); }); }
    s0();
  }
  /* "Where this fits" — the (now-absorbed) flow walkthroughs, inverted per screen.
     For the current page, which process(es) it takes part in and where the player
     sits in each: their step, what it does, what comes next, and — collapsed — the
     whole process end to end. This is where the removed flows/WF-*.html content lives. */
  function screenContextHtml() {
    var F = CGA.fixtures.flows;
    if (!F || !F.byScreen) return '';
    var rows = F.byScreen[PAGE.id] || [];
    if (!rows.length) return '';
    var CAP = 6, shown = rows.slice(0, CAP), more = rows.length - shown.length;
    var cards = shown.map(function (r) {
      var ns = r.steps.map(function (s) { return s.n; });
      var stepLabel = ns.length > 1 ? 'steps ' + ns[0] + '–' + ns[ns.length - 1] : 'step ' + ns[0];
      var first = r.steps[0];
      var nextLine = first.next
        ? '<div class="ld-flow-next">' + icon('arrow-right', { size: 'sm' }) + ' Next — ' + esc(plainCodes(first.next)) + '</div>' : '';
      var full = (F.byWorkflow[r.wf] || {}).steps || [];
      var fullList = full.length
        ? '<details class="ld-flow-full"><summary>The full process — ' + full.length + ' steps</summary><ol>' +
          full.map(function (s) { return '<li>' + esc(plainCodes(s.action)) + '</li>'; }).join('') + '</ol>' +
          (r.terminal ? '<p class="citation">Ends: ' + esc(plainCodes(r.terminal)) + '</p>' : '') + '</details>'
        : '';
      return '<div class="ld-flow">' +
        '<div class="ld-flow-head"><strong>' + esc(r.wfName) + '</strong> ' +
        '<span class="citation">' + esc(r.familyLabel) + ' · ' + stepLabel + ' of ' + r.total + '</span></div>' +
        '<div class="ld-flow-action">' + esc(plainCodes(first.action)) + '.</div>' + nextLine + fullList + '</div>';
    }).join('');
    return '<div class="ld-context"><span class="ld-context-h">' + icon('map', { size: 'sm' }) +
      ' Where this fits — the process' + (rows.length > 1 ? 'es' : '') + ' this screen is part of</span>' +
      cards + (more > 0 ? '<p class="citation">+ ' + more + ' more process' + (more > 1 ? 'es' : '') + ' touch this screen</p>' : '') +
      '</div>';
  }
  function hydrateLearnDrawer(d) {
    if (!d || d.getAttribute('data-hydrated')) return;
    d.setAttribute('data-hydrated', '1');
    var lf = learnFor(), body = d.querySelector('[data-ld-body]');
    body.innerHTML =
      '<p class="gloss">' + esc(lf.about) + '</p>' +
      '<div class="ld-video" data-ld-video><p class="gloss">Loading the guide…</p></div>' +
      '<div data-ld-context></div>' +
      '<div class="cluster" style="gap:var(--space-1)">' +
      '<a class="form-chip" href="' + hrefV2('learn/learn-home.html') + '">' + icon('graduation-cap', { size: 'sm' }) + ' Full lessons</a>' +
      '<a class="form-chip form-chip--report" href="' + hrefV2('support/report.html?ref=' + encodeURIComponent(PAGE.id || 'page')) + '">' + icon('flag', { size: 'sm' }) + ' Report an issue</a></div>';
    ensureLearnDeps(function (ok) {
      var slot = body.querySelector('[data-ld-video]');
      if (slot) {
        if (ok && CGA.v2c && CGA.fixtures.v2.learn.byVideo[lf.video]) {
          slot.innerHTML = CGA.v2c.videoPlayer(lf.video);
          CGA.v2c.initVideo(slot);
        } else {
          slot.innerHTML = '<a class="form-chip" href="' + hrefV2('shared/video-player.html') + '">' + icon('play', { size: 'sm' }) + ' Open the video library</a>';
        }
      }
      var cslot = body.querySelector('[data-ld-context]');
      if (cslot) cslot.innerHTML = screenContextHtml();
    });
  }

  /* The nav sections — used to be the sidebar; now they fill the Menu flyout in
     the harmonized command bar. Returns just the section markup. */
  function sidebarNavInner() {
    var html = '';

    function section(title) { html += '<div class="sidebar-section"><span class="sidebar-title eyebrow">' + esc(title) + '</span>'; }
    function endSection() { html += '</div>'; }
    function linkV2(id, label, iconName, rel, stage) {
      var built = isBuiltV2(rel);
      var current = PAGE.nav === id;
      if (built) {
        html += '<a class="sidebar-link" href="' + hrefV2(rel) + '"' + (current ? ' aria-current="page"' : '') + '>' + icon(iconName, { size: 'sm' }) + esc(label) + '</a>';
      } else {
        html += '<span class="sidebar-link sidebar-link--disabled" aria-disabled="true">' + icon(iconName, { size: 'sm' }) + esc(label) + ' ' + plannedFlag(stage) + '</span>';
      }
    }
    function linkV1(label, iconName, rel) {
      html += '<a class="sidebar-link" href="' + hrefV1(rel) + '">' + icon(iconName, { size: 'sm' }) + esc(label) + '</a>';
    }

    /* -------- TIER 1 · the player tier — where you actually go ---------- */
    section('Go');
    linkV2('today', 'Home', 'home', 'civic/today.html');
    linkV2('atlas', 'The Atlas', 'globe', 'atlas.html');
    linkV2('social-home', 'The square', 'users', 'social/social-home.html');
    linkV2('groups', 'Messages', 'message-square', 'groups/groups-home.html');
    linkV2('live-room', 'Live rooms', 'landmark', 'shared/live-room.html?variant=group');
    linkV2('jurisdiction-browser', 'Places', 'map', 'jurisdictions/jurisdiction-browser.html');
    linkV2('economy-home', 'Market', 'bar-chart', 'economy/economy-home.html');
    linkV2('my-civic-life', 'My profile', 'user', 'civic/my-civic-life.html');
    linkV2('journeys', 'Journeys', 'list-checks', 'index.html#journeys-h');
    linkV2('learn-home', 'Learn & help', 'graduation-cap', 'learn/learn-home.html');
    linkV2('tour', 'Guided tour', 'map', 'tour.html');
    endSection();

    /* -------- TIER 2 · every screen — the full design-contract sitemap -- */
    html += '<details class="sidebar-more"><summary class="sidebar-title eyebrow">All screens — the full map ' +
      icon('chevron-down', { size: 'sm' }) + '</summary>';

    section('Rooms & the square');
    ROOM_VARIANTS.forEach(function (v) {
      var id = 'room-' + v.id;
      var current = PAGE.nav === id;
      html += '<a class="sidebar-link" href="' + hrefV2('shared/live-room.html?variant=' + v.id) + '"' + (current ? ' aria-current="page"' : '') + '>' + icon(v.icon, { size: 'sm' }) + esc(v.label) + '</a>';
    });
    linkV2('petitions', 'Petitions', 'file-text', 'civic/petitions.html');
    endSection();

    section('Me & my account');
    linkV2('join', 'You’re invited (arrival)', 'user', 'civic/join.html');
    linkV2('onboarding', 'Create your account', 'user', 'civic/onboarding.html');
    linkV2('residency', 'Say where you live', 'map-pin', 'civic/residency.html');
    linkV2('identity-verification', 'Link an ID (optional)', 'lock', 'civic/identity-verification.html');
    linkV2('relocation', 'Move somewhere new', 'map', 'civic/relocation.html');
    linkV2('advocate-registration', 'Become an advocate', 'briefcase', 'civic/advocate-registration.html');
    linkV2('achievements', 'Achievements', 'award', 'social/achievements.html');
    endSection();

    section('A place’s elections');
    linkV2('candidacy', 'Stand for office', 'user', 'electoral/candidacy-registration.html');
    linkV2('election-detail', 'An election', 'clock', 'electoral/election-detail.html');
    linkV2('open-ballot', 'Open ballot', 'vote', 'electoral/open-ballot.html');
    linkV2('ranked-ballot', 'Ranked ballot', 'check', 'electoral/ranked-ballot.html');
    linkV2('results', 'Results', 'bar-chart', 'electoral/results.html');
    linkV2('vacancy-countback', 'Filling an empty seat', 'refresh-cw', 'electoral/vacancy-countback.html');
    linkV2('election-board-console', 'The election board', 'shield', 'electoral/election-board-console.html');
    endSection();

    section('A place’s chamber');
    linkV2('legislature-home', 'The chamber', 'landmark', 'legislature/legislature-home.html');
    linkV2('bills', 'Bills', 'file-text', 'legislature/bills.html');
    linkV2('committees', 'Committees', 'users', 'legislature/committees.html');
    linkV2('referendums', 'Referendums', 'vote', 'legislature/referendums.html');
    linkV2('emergency-powers', 'Emergency powers', 'alert-triangle', 'legislature/emergency-powers.html');
    linkV2('oversight', 'Ethics & removals', 'shield', 'legislature/oversight.html');
    linkV2('session-console', 'Run a session (Speaker)', 'users', 'legislature/session-console.html');
    linkV2('speaker-tools', 'The Speaker', 'landmark', 'legislature/speaker-tools.html');
    linkV2('leg-settings', 'The chamber’s rules', 'sliders', 'legislature/settings.html');
    endSection();

    section('A place’s executive');
    linkV2('executive-home', 'The executive', 'briefcase', 'executive/executive-home.html');
    linkV2('departments', 'Departments', 'building', 'executive/departments.html');
    linkV2('executive-actions', 'Executive actions', 'file-text', 'executive/executive-actions.html');
    linkV2('department-reporting', 'Department reporting', 'bar-chart', 'executive/department-reporting.html');
    endSection();

    section('A place’s courts');
    linkV2('judiciary-home', 'The courts', 'scale', 'judiciary/judiciary-home.html');
    linkV2('case-docket', 'The docket', 'file-text', 'judiciary/case-docket.html');
    linkV2('constitutional-challenge', 'Challenge a law', 'scale', 'judiciary/constitutional-challenge.html');
    linkV2('advocate-console', 'The advocate console', 'briefcase', 'judiciary/advocate-console.html');
    linkV2('juror-view', 'A juror’s view', 'users', 'judiciary/juror-view.html');
    endSection();

    section('Organizations');
    linkV2('org-registry', 'The registry', 'building', 'organizations/org-registry.html');
    linkV2('org-profile', 'An organization', 'building', 'social/org-profile.html');
    linkV2('co-determination', 'Worker seats on the board', 'users', 'organizations/co-determination.html');
    linkV2('board-elections', 'Board elections', 'vote', 'organizations/board-elections.html');
    linkV2('cgc-detail', 'A common-good corporation', 'building', 'organizations/cgc-detail.html');
    linkV2('transfers-conversions', 'Ownership changes', 'refresh-cw', 'organizations/transfers-conversions.html');
    endSection();

    section('Places & their processes');
    linkV2('district-mapper', 'The district mapper', 'map', 'jurisdictions/district-mapper.html');
    linkV2('legitimacy', 'Reach', 'bar-chart', 'social/legitimacy.html');
    linkV2('bootstrap', 'Wake a place up', 'globe', 'jurisdictions/bootstrap.html');
    linkV2('union-formation', 'Merge places into a union', 'users', 'jurisdictions/union-formation.html');
    linkV2('disintermediation', 'Remove a middle layer', 'globe', 'jurisdictions/disintermediation.html');
    linkV2('restoration', 'Rebuild a lost government', 'refresh-cw', 'jurisdictions/restoration.html');
    linkV2('federation', 'Between governments', 'globe', 'jurisdictions/federation.html');
    endSection();

    section('Market · planned');
    linkV2('exchange', 'The exchange', 'bar-chart', 'economy/exchange.html');
    linkV2('marketplace', 'The open market', 'building', 'economy/marketplace.html');
    linkV2('agreements', 'Agreements', 'file-text', 'economy/agreements.html');
    linkV2('wallet', 'My wallet', 'lock', 'economy/wallet.html');
    linkV2('joint-ledgers', 'Joint ledgers', 'users', 'economy/joint-ledgers.html');
    linkV2('units', 'Units & money', 'sliders', 'economy/units.html');
    linkV2('stipend', 'The civic stipend', 'refresh-cw', 'economy/stipend.html');
    linkV2('treasury', 'Public finance', 'bar-chart', 'economy/treasury.html');
    linkV2('org-settings', 'Org economics', 'building', 'economy/org-settings.html');
    endSection();

    section('Journeys');
    V2.journeys.forEach(function (j) {
      linkV2('journey-' + j.id, j.title.replace(/^An? /, ''), j.flagship ? 'vote' : 'file-text', 'journeys/journey.html?id=' + j.id);
    });
    endSection();

    section('Learn & help');
    linkV2('guides', 'Guides & procedures', 'list-checks', 'learn/guides.html');
    linkV2('video-library', 'The video library', 'play', 'shared/video-player.html');
    linkV2('translation-home', 'Help translate', 'languages', 'translation/translation-home.html');
    linkV2('report', 'Report an issue', 'flag', 'support/report.html');
    linkV2('tickets', 'Tickets', 'ticket', 'support/tickets.html');
    linkV2('constitutional-questions', 'Open constitutional questions', 'scale', 'shared/constitutional-questions.html');
    linkV2('accessibility', 'Accessibility', 'shield', 'shared/accessibility.html');
    endSection();

    section('Records & the clock');
    linkV2('public-records', 'Public records', 'file-text', 'system/public-records.html');
    linkV2('audit-chain', 'The audit chain', 'lock', 'system/audit-chain.html');
    linkV2('amendments', 'Amendments', 'file-text', 'system/amendments.html');
    linkV2('term-sync', 'Terms end together', 'refresh-cw', 'system/term-sync.html');
    linkV2('clocks', 'The clocks', 'clock', 'shared/clocks.html');
    endSection();

    section('Run a node');
    linkV2('operator-home', 'The operator plane', 'sliders', 'operator/operator-home.html');
    linkV2('founding', 'Found an instance', 'sliders', 'system/setup.html');
    linkV2('operator-setup', 'Set up your node', 'sliders', 'operator/setup.html');
    linkV2('operator-console', 'The console', 'landmark', 'operator/console.html');
    linkV2('operator-roles', 'Roles & channels', 'users', 'operator/roles.html');
    linkV2('operator-mesh', 'Mesh & peers', 'globe', 'operator/mesh.html');
    linkV2('operator-dns', 'DNS & certificates', 'globe', 'operator/dns.html');
    linkV2('operator-identity', 'Identity', 'lock', 'operator/identity.html');
    linkV2('operator-moderation', 'Moderation & legal', 'shield', 'operator/moderation.html');
    linkV2('operator-versioning', 'Versions & upgrades', 'refresh-cw', 'operator/versioning.html');
    endSection();

    section('For the build team');
    linkV2('launchpad', 'The launchpad (cover)', 'globe', 'index.html');
    linkV2('coverage', 'Coverage', 'check', 'shared/coverage.html');
    linkV2('coverage-ops', 'Coverage — ops matrix', 'check', 'shared/coverage-ops.html');
    linkV2('styleguide', 'Style guide', 'sliders', 'shared/styleguide.html');
    endSection();

    html += '</details>';

    return html;
  }

  /* ------------------------------------------------------------- header */
  function jurisdictionChain(slug) {
    var chain = [], j = BY.jurisdictions[slug];
    while (j) { chain.unshift(j); j = j.parent ? BY.jurisdictions[j.parent] : null; }
    return chain;
  }
  function renderChainChips(chain) {
    return chain.map(function (j) {
      var lvl = Math.min(j.admLevel, 5);
      return '<span class="adm-chip adm-chip--' + lvl + '" title="' + esc(admLabel(j.admLevel)) + '">' + esc(j.name) + '</span>';
    }).join('<span class="adm-sep" aria-hidden="true">›</span>');
  }
  function renderJurSwitcher() {
    var s = CGA.state.getAll();
    var chain = jurisdictionChain(s.jurisdiction);
    var panel = '<div class="popover-panel"><span class="eyebrow">Jurisdiction context</span>' +
      '<p class="cosmic-prefix">Multiverse · … · Solar System · Earth</p><ul style="list-style:none;padding:0;margin:0">' +
      W.jurisdictions.map(function (j) {
        var lvl = Math.min(j.admLevel, 5);
        return '<li><button type="button" class="btn btn--ghost btn--sm" data-set-jur="' + esc(j.slug) + '" style="inline-size:100%;justify-content:flex-start">' +
          '<span class="tier-dot tier-dot--' + lvl + '" aria-hidden="true"></span> ' + esc(j.name) +
          ' <span class="citation">' + esc(admLabel(j.admLevel)) + '</span></button></li>';
      }).join('') + '</ul></div>';
    return '<details class="popover jur-switcher"><summary aria-label="Jurisdiction context">' +
      '<span class="cosmic-prefix">… · Solar System · Earth</span>' + renderChainChips(chain) +
      icon('chevron-down', { size: 'sm' }) + '</summary>' + panel + '</details>';
  }
  function activePersona() { return BY.personas[CGA.state.get('persona')] || W.personas[0]; }
  function renderHeader() {
    var s = CGA.state.getAll();
    var p = activePersona();
    var role = BY.roles[s.role] || R.roles[0];
    var locales = CGA.i18n.LOCALES.map(function (l) {
      return '<option value="' + l.code + '"' + (s.locale === l.code ? ' selected' : '') + '>' + esc(l.name) + '</option>';
    }).join('') + (s.locale === 'en-XA' ? '<option value="en-XA" selected>Pseudo (en-XA)</option>' : '');
    return '<a class="wordmark" href="' + hrefV2('civic/today.html') + '">' +
      '<img src="' + ROOT_V2 + 'assets/img/social-square-purple.png" alt="" style="border-radius:var(--radius-sm)" /> ' +
      '<span>World of Statecraft</span> <span class="wordmark-tag">v3 mockups</span></a>' +
      renderJurSwitcher() + '<span class="header-spacer"></span>' +
      '<label class="demo-control"><span class="visually-hidden">Language</span>' + icon('languages', { size: 'sm' }) +
      '<select class="select" style="inline-size:auto" data-set-locale>' + locales + '</select></label>' +
      '<span class="role-badge" title="Active persona and role">' +
      '<span class="avatar" aria-hidden="true">' + esc(p.initials) + '</span><span>' + esc(p.name) + '</span>' +
      '<span class="citation">' + esc(role.shortName || role.name) + '</span></span>';
  }

  /* ------------------------------------------------------------- footer */
  function renderFooter() {
    var authJur = BY.jurisdictions[W.instance.authoritativeFor];
    var instanceLine = 'Instance: ' + W.instance.host + ' · authoritative for ' + (authJur ? authJur.name : W.instance.authoritativeFor);
    return '<span class="footer-citation">' + esc(PAGE.citation || 'World of Statecraft mockups · v3') + '</span>' +
      '<span class="header-spacer"></span>' +
      '<a href="' + hrefV2('shared/accessibility.html') + '">Accessibility</a>' +
      '<a href="' + hrefV2('support/report.html?ref=' + encodeURIComponent(PAGE.id || PAGE.nav || 'page')) + '">' + icon('flag', { size: 'sm' }) + ' Report an issue</a>' +
      '<span class="footer-instance">' + esc(instanceLine) + '</span>' +
      '<span class="audit-chip">Audit #' + W.instance.auditSeq.toLocaleString('en-US') + ' · chained ' + icon('check', { size: 'sm', label: 'verified' }) + '</span>';
  }

  /* ------------------------------------------------------ demo controls */
  /* The mockup-only controls — now the Demo flyout in the command bar. */
  function demoControlsInner() {
    var s = CGA.state.getAll();
    var personaOpts = W.personas.map(function (p) {
      return '<option value="' + p.id + '"' + (s.persona === p.id ? ' selected' : '') + '>' + esc(p.name) + (p.standIn ? ' *' : '') + '</option>';
    }).join('');
    var roleOpts = R.roles.map(function (r) {
      return '<option value="' + r.id + '"' + (s.role === r.id ? ' selected' : '') + '>' + esc(r.name) + '</option>';
    }).join('');
    var jurOpts = W.jurisdictions.map(function (j) {
      return '<option value="' + j.slug + '"' + (s.jurisdiction === j.slug ? ' selected' : '') + '>' + esc(j.name) + ' (' + esc(admLabel(j.admLevel)) + ')</option>';
    }).join('');
    function toggle(key, label) {
      return '<label class="demo-control"><input type="checkbox" data-scenario-flag="' + key + '"' + (s.scenario[key] ? ' checked' : '') + ' /> ' + esc(label) + '</label>';
    }
    return '<p class="cmdbar-panel-title eyebrow">' + icon('sliders', { size: 'sm' }) + ' Mockup controls — demo only, not part of the application</p>' +
      '<label class="demo-control">Persona <select data-set-persona>' + personaOpts + '</select></label>' +
      '<label class="demo-control">Role <select data-set-role>' + roleOpts + '</select></label>' +
      '<label class="demo-control">Jurisdiction <select data-set-jur-select>' + jurOpts + '</select></label>' +
      '<span class="demo-sep" aria-hidden="true">·</span>' +
      toggle('liveSession', 'Live session in progress') +
      toggle('marketplace', 'Marketplace listings') +
      toggle('ubiRun', 'Civic-stipend run posted') +
      toggle('groupForming', 'Informal group forming') +
      toggle('tradeTalk', 'Cross-government trade talk') +
      '<span class="demo-sep" aria-hidden="true">·</span>' +
      '<label class="demo-control"><input type="checkbox" data-rtl-flip' + (s.dir === 'rtl' ? ' checked' : '') + ' /> RTL flip</label>' +
      '<label class="demo-control"><input type="checkbox" data-pseudo-toggle' + (s.locale === 'en-XA' ? ' checked' : '') + ' /> Pseudo-locale</label>' +
      '<button type="button" class="btn btn--ghost btn--sm" data-demo-reset style="color:var(--cc-purple-200)">Reset</button>';
  }

  /* ---------------------------------------------- the floating command bar
     v3: one always-present bar — Menu, the guided-tour Back/Next, the Learn
     flyout (video + about + report), and the mockup controls — so navigation
     and the tour are reachable from anywhere without scrolling. */
  function renderCommandBar() {
    var openId = null;
    if (cmdBarEl) { var o = cmdBarEl.querySelector('.cmdbar-fly[open]'); if (o) openId = o.id; }

    function fly(id, iconName, label, panelCls, panelHtml) {
      return '<details class="cmdbar-fly" id="' + id + '"' + (id === openId ? ' open' : '') + '>' +
        '<summary class="cmdbar-btn">' + icon(iconName, { size: 'sm' }) + '<span class="cmdbar-lbl">' + esc(label) + '</span>' + icon('chevron-down', { size: 'sm', cls: 'cmdbar-caret' }) + '</summary>' +
        '<div class="cmdbar-panel ' + panelCls + '">' + panelHtml + '</div></details>';
    }

    /* the bottom bar holds ONLY the three flyouts; the guided-tour controls live
       up in the floating header (renderChrome appends them) so they aren't
       squished alongside these. */
    return '<div class="cmdbar-flies">' +
      fly('cmd-menu', 'menu', 'Menu', 'cmdbar-panel--menu', '<nav class="sidebar-nav" aria-label="Primary">' + sidebarNavInner() + '</nav>') +
      fly('cmd-learn', 'graduation-cap', 'Learn', 'cmdbar-panel--learn', '<div class="ld-body" data-ld-body><p class="gloss">Loading the guide…</p></div>') +
      fly('cmd-demo', 'sliders', 'Demo', 'cmdbar-panel--demo demo-controls', demoControlsInner()) +
      '</div>';
  }

  /* -------------------------------------------------- i18n / pseudo / links */
  function applyI18nAttrs(rootEl) {
    var nodes = (rootEl || document).querySelectorAll('[data-i18n]');
    for (var i = 0; i < nodes.length; i++) nodes[i].textContent = t(nodes[i].getAttribute('data-i18n'));
  }
  var ID_TOKEN = /^(R|WF|F|I|CLK|M)-?[\dA-Z-]*$/;
  function pseudoTransformMain() {
    var main = document.getElementById('main');
    if (!main) return;
    var pseudoOn = CGA.i18n.isPseudoLocale();
    var walker = document.createTreeWalker(main, NodeFilter.SHOW_TEXT, {
      acceptNode: function (node) {
        if (!node.nodeValue || !node.nodeValue.trim()) return NodeFilter.FILTER_REJECT;
        var el = node.parentElement;
        while (el && el !== main) {
          if (el.matches('.citation, .cc-citation, code, kbd, .kbd, [data-no-i18n], script, style')) return NodeFilter.FILTER_REJECT;
          el = el.parentElement;
        }
        return NodeFilter.FILTER_ACCEPT;
      }
    });
    var node;
    while ((node = walker.nextNode())) {
      if (node.__cgaOrig === undefined) node.__cgaOrig = node.nodeValue;
      if (pseudoOn) {
        if (ID_TOKEN.test(node.__cgaOrig.trim())) { node.nodeValue = node.__cgaOrig; continue; }
        node.nodeValue = CGA.i18n.pseudo(node.__cgaOrig);
      } else { node.nodeValue = node.__cgaOrig; }
    }
  }
  function rewriteMainLinks() {
    var main = document.getElementById('main');
    if (!main) return;
    var anchors = main.querySelectorAll('a[href]');
    for (var i = 0; i < anchors.length; i++) {
      var a = anchors[i];
      var orig = a.getAttribute('data-orig-href') || a.getAttribute('href');
      if (/^([a-z][a-z0-9+.-]*:|#)/i.test(orig)) continue;
      a.setAttribute('data-orig-href', orig);
      var abs;
      try { abs = new URL(orig, document.baseURI).href; } catch (e) { continue; }
      a.setAttribute('href', CGA.state.link(abs));
    }
  }
  function wrapTables() {
    var tables = document.querySelectorAll('#main table.table');
    for (var i = 0; i < tables.length; i++) {
      var tb = tables[i];
      if (tb.parentElement && tb.parentElement.classList.contains('table-wrap')) continue;
      var wrap = document.createElement('div'); wrap.className = 'table-wrap';
      tb.parentNode.insertBefore(wrap, tb); wrap.appendChild(tb);
    }
  }

  /* ------------------------------------------------------------- render */
  var headerEl, footerEl, cmdBarEl;
  function buildShell() {
    document.body.classList.add('app-shell', 'app-shell--v2');
    if (PAGE.register === 'brand') document.body.classList.add('register-brand');

    var spriteWrap = document.createElement('div');
    spriteWrap.innerHTML = CGA.icons.spriteMarkup();
    document.body.insertBefore(spriteWrap.firstChild, document.body.firstChild);

    var skip = document.createElement('a');
    skip.className = 'skip-link'; skip.href = '#main'; skip.textContent = 'Skip to main content';
    document.body.insertBefore(skip, document.body.firstChild);

    liveEl = document.createElement('div');
    liveEl.id = 'cga-live'; liveEl.className = 'visually-hidden';
    liveEl.setAttribute('role', 'status'); liveEl.setAttribute('aria-live', 'polite');
    document.body.appendChild(liveEl);

    headerEl = document.createElement('header'); headerEl.className = 'app-header';
    footerEl = document.createElement('footer'); footerEl.className = 'app-footer';

    var main = document.getElementById('main');
    if (!main) fail('page is missing <main id="main">');
    main.classList.add('main-content');

    /* the harmonized floating command bar — Menu, the guided-tour Back/Next, the
       Learn flyout, and the mockup controls, in one always-present bar (v3). */
    cmdBarEl = document.createElement('div'); cmdBarEl.className = 'cmdbar';
    cmdBarEl.setAttribute('aria-label', 'Navigation, guided tour, learn, and mockup controls');

    document.body.insertBefore(headerEl, main);
    document.body.appendChild(footerEl);
    document.body.appendChild(cmdBarEl);

    renderChrome(); wireEvents(); initHeaderScroll(); applyBindings(); applyI18nAttrs(document); rewriteMainLinks(); wrapTables(); pseudoTransformMain();
  }
  function renderChrome() {
    if (!headerEl) return;
    /* the floating header: the chrome row, plus the guided-tour strip beneath it
       when on a tour step — both ride the hide-on-scroll-down header together */
    headerEl.innerHTML = '<div class="hdr-row">' + renderHeader() + '</div>' +
      (currentTourIndex() >= 0 ? renderTourBar() : '');
    footerEl.innerHTML = renderFooter();
    cmdBarEl.innerHTML = renderCommandBar();
    /* re-hydrate the Learn flyout if it was open across a re-render */
    var learn = document.getElementById('cmd-learn');
    if (learn && learn.open) hydrateLearnDrawer(learn);
  }

  /* the header floats: it slides away as you scroll DOWN and reappears the
     moment you scroll UP (and is always shown near the top of the page). */
  function initHeaderScroll() {
    if (!headerEl) return;
    var lastY = window.scrollY || window.pageYOffset || 0;
    window.addEventListener('scroll', function () {
      var y = window.scrollY || window.pageYOffset || 0, h = headerEl.offsetHeight || 0;
      if (y <= h || y < lastY - 2) headerEl.classList.remove('app-header--hidden');
      else if (y > lastY + 2) headerEl.classList.add('app-header--hidden');
      lastY = y;
    }, { passive: true });
  }
  function wireEvents() {
    document.body.addEventListener('change', function (ev) {
      var el = ev.target;
      if (el.matches('[data-set-locale]')) CGA.state.set({ locale: el.value, dir: 'auto' });
      else if (el.matches('[data-set-persona]')) {
        var p = BY.personas[el.value];
        CGA.state.set({ persona: el.value, role: p && p.roles.length ? p.roles[p.roles.length - 1] : CGA.state.get('role') });
      } else if (el.matches('[data-set-role]')) {
        var role = BY.roles[el.value];
        CGA.state.set({ role: el.value, persona: role && role.defaultPersona ? role.defaultPersona : CGA.state.get('persona') });
      } else if (el.matches('[data-set-jur-select]')) CGA.state.set({ jurisdiction: el.value });
      else if (el.matches('[data-scenario-flag]')) {
        var patch = {}; patch[el.getAttribute('data-scenario-flag')] = el.checked;
        CGA.state.set({ scenario: patch });
      } else if (el.matches('[data-rtl-flip]')) CGA.state.set({ dir: el.checked ? 'rtl' : 'auto' });
      else if (el.matches('[data-pseudo-toggle]')) CGA.state.set({ locale: el.checked ? 'en-XA' : 'en', dir: 'auto' });
    });
    document.body.addEventListener('click', function (ev) {
      var jurBtn = ev.target.closest ? ev.target.closest('[data-set-jur]') : null;
      if (jurBtn) { CGA.state.set({ jurisdiction: jurBtn.getAttribute('data-set-jur') }); return; }
      if (ev.target.closest && ev.target.closest('[data-demo-reset]')) { CGA.state.reset(); return; }
    });
    document.addEventListener('keydown', function (ev) {
      if (ev.key !== 'Escape') return;
      var open = document.querySelectorAll('details.popover[open], .cmdbar-fly[open]');
      for (var i = 0; i < open.length; i++) {
        open[i].removeAttribute('open');
        var sum = open[i].querySelector('summary');
        if (sum) sum.focus();
      }
    });
    document.addEventListener('click', function (ev) {
      var open = document.querySelectorAll('details.popover[open], .cmdbar-fly[open]');
      for (var i = 0; i < open.length; i++) if (!open[i].contains(ev.target)) open[i].removeAttribute('open');
    });
    /* command-bar flyouts: keep one open at a time, and lazy-hydrate the Learn
       flyout (its video) on open. toggle does not bubble, so capture it. */
    document.addEventListener('toggle', function (ev) {
      var t = ev.target;
      if (!t || !t.classList || !t.classList.contains('cmdbar-fly') || !t.open) return;
      var all = document.querySelectorAll('.cmdbar-fly[open]');
      for (var i = 0; i < all.length; i++) if (all[i] !== t) all[i].removeAttribute('open');
      if (t.id === 'cmd-learn') hydrateLearnDrawer(t);
    }, true);
    CGA.state.subscribe(function () {
      renderChrome(); applyI18nAttrs(document); rewriteMainLinks(); wrapTables(); pseudoTransformMain();
      document.dispatchEvent(new CustomEvent('cga:v2:rerender'));
    });
  }

  /* -------------------------------------------------------------- expose */
  /* [data-bind] text binding — ported from the v1 shell so converted operations
     pages keep working under the harmonized shell. */
  function resolvePath(ctx, path) {
    var cur = ctx, parts = String(path).split('.');
    for (var i = 0; i < parts.length; i++) { if (cur == null) return undefined; cur = cur[parts[i]]; }
    return cur;
  }
  function applyBindings() {
    var s = CGA.state.getAll();
    var ctx = { state: s, persona: activePersona(), role: BY.roles[s.role], jurisdiction: BY.jurisdictions[s.jurisdiction], instance: W.instance, world: W };
    var nodes = document.querySelectorAll('[data-bind]');
    for (var i = 0; i < nodes.length; i++) {
      var v = resolvePath(ctx, nodes[i].getAttribute('data-bind'));
      if (v !== undefined) nodes[i].textContent = typeof v === 'number' ? v.toLocaleString('en-US') : String(v);
    }
  }

  CGA.shellV2 = {
    ROOT_V1: ROOT_V1, ROOT_V2: ROOT_V2, ROOT: ROOT_V2,
    icon: icon, esc: esc, plainCodes: plainCodes, plainState: plainState, badge: badge, pill: pill, formatPop: formatPop, admLabel: admLabel,
    hrefV1: hrefV1, hrefV2: hrefV2, href: hrefV2, isBuiltV2: isBuiltV2, isBuilt: isBuiltV2, plannedFlag: plannedFlag, plannedBanner: plannedBanner,
    announce: announce, activePersona: activePersona, jurisdictionChain: jurisdictionChain, t: t,
    tour: TOUR, tourHref: tourHref, roomVariants: ROOM_VARIANTS,
    refresh: function () { renderChrome(); applyBindings(); applyI18nAttrs(document); rewriteMainLinks(); wrapTables(); pseudoTransformMain(); }
  };
  /* one harmonized shell: operations pages that boot against `CGA.shell` get the
     v3 chrome with no inline-script changes (cga:statechange still fires from
     demo-state.js). */
  CGA.shell = CGA.shellV2;

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', buildShell);
  else buildShell();
})();
