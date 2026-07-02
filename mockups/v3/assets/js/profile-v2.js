/* profile-v2.js — the UNIFIED profile (v3).
   ----------------------------------------------------------------------------
   One person, any role. There is no separate "user profile" vs "representative
   profile": every person — yourself, a neighbour, the people who hold your
   seats — is shown by the SAME tabbed profile. If a person holds an office, an
   extra "Office" tab appears with their office record; nothing else changes.

   Public surface:
     CGA.profileV2.render(opts)   -> innerHTML string for the page root
     CGA.profileV2.wire(rootEl)   -> wires tabs + simulated actions + countdowns

   opts: { who: <personaId|null>, self: <bool> }
     self / no who / who === you  -> your own full profile (wallet, your reps…)
     who = someone else           -> their public profile (+ Office if they hold one)

   Authored jargon-light: plain language in the chrome; the constitutional "why"
   lives in the embedded Learn drawer, not here. */
(function () {
  'use strict';
  window.CGA = window.CGA || {};

  function S() { return CGA.shellV2; }
  function F() { return CGA.fixtures; }
  function V2() { return CGA.fixtures.v2; }
  function E() { return CGA.fixtures.v2.econ; }
  function esc(s) { return S().esc(s); }
  function icon(n, o) { return S().icon(n, o); }
  function pill(t, l, tip) { return S().pill(t, l, tip); }
  function badge(t, l, ic) { return S().badge(t, l, ic); }
  function hrefV1(r) { return S().hrefV1(r); }
  function hrefV2(r, q) { return S().hrefV2(r, q); }

  function byId() { return CGA.fixtures.byId; }
  function personaOf(id) { return byId().personas[id] || { id: id, name: id, initials: '?' }; }
  function nm(id, fb) { var p = byId().personas[id]; return p ? p.name : (fb || id); }
  function orgName(id, fb) { var o = byId().organizations[id]; return o ? o.name : (fb || id); }
  function jurName(slug) { var j = byId().jurisdictions[slug]; return j ? j.name : slug; }

  function param(name) {
    /* URLSearchParams, not a regex — demo-state re-serializes the URL with
       '+' for spaces, which decodeURIComponent would leave in place */
    try { return new URLSearchParams(location.search).get(name); } catch (e) { return null; }
  }

  /* ---- the subject of this profile -------------------------------------- */
  function candidateOf(id) {
    var hit = null;
    ((F().world && F().world.candidates) || []).forEach(function (c) { if (c.id === id) hit = c; });
    return hit;
  }
  function resolve(opts) {
    opts = opts || {};
    var self = E().profiles.personal;
    var who = opts.who || param('who');
    var isSelf = !!opts.self || !who || who === self.persona;
    var personaId = isSelf ? self.persona : who;
    var prof = isSelf ? self : (who === self.persona ? self : null);
    var rep = (E().reps || []).filter(function (r) { return r.persona === personaId; })[0] || null;

    /* One person = one profile. If this person is standing in a race, the
       candidacy is a TAB here — never a separate page. Count surfaces deep-link
       non-fixture names with ?who=<slug>&name=<display>&race=<election id>;
       those render the same profile with a generic candidacy. */
    var persona = personaOf(personaId);
    var cand = candidateOf(personaId);
    var nameParam = param('name');
    function initialsOf(n2) {
      return n2.split(/\s+/).map(function (w) { return w.charAt(0); }).slice(0, 2).join('').toUpperCase() || '?';
    }
    if (!byId().personas[personaId]) {
      if (cand) {
        /* a fixture candidate without a persona record — the candidacy carries the name */
        persona = { id: personaId, name: cand.name, initials: initialsOf(cand.name) };
      } else if (nameParam) {
        var display = nameParam.replace(' (write-in)', '');
        persona = { id: personaId, name: display, initials: initialsOf(display) };
        cand = { id: personaId, name: display, election: param('race'), endorsedBy: [], individualEndorsements: 0, generic: true };
      }
    }

    return {
      isSelf: isSelf,
      personaId: personaId,
      persona: persona,
      prof: prof,
      rep: rep,
      cand: cand,
      myReps: isSelf ? (E().reps || []) : []
    };
  }

  /* ---- live countdowns (browser-only; Date.now is fine on a page) -------- */
  var tick = null;
  function remaining(ms) {
    if (ms <= 0) return null;
    var t = Math.floor(ms / 60000), d = Math.floor(t / 1440), h = Math.floor((t % 1440) / 60), m = t % 60, p = [];
    if (d) p.push(d + 'd'); if (h) p.push(h + 'h'); if (m || !p.length) p.push(m + 'm');
    return p.join(' ');
  }
  function countdownText(target) {
    if (!target) return null;
    if (target.kind === 'dayOf') return 'day ' + target.day + ' of ' + target.max;
    var rem = remaining(new Date(target.iso).getTime() - Date.now());
    if (target.kind === 'opensAt') return rem ? 'opens in ' + rem : 'opening now';
    return rem ? 'closes in ' + rem : 'closing now';
  }
  function countdownHtml(target) {
    if (!target) return '';
    if (target.kind === 'dayOf') return '<span class="countdown">' + esc(countdownText(target)) + '</span>';
    return '<span class="countdown" data-countdown="' + esc(target.iso) + '" data-kind="' + esc(target.kind) + '">' +
      esc(countdownText(target)) + '</span>';
  }
  function ballotRows() {
    var L = V2().live, flags;
    try { flags = CGA.state.get('scenario') || {}; } catch (e) { flags = {}; }
    var kinds = { election: 1, petition: 1, referendum: 1 };
    return L.forFootprint().filter(function (r) { return kinds[r.kind] && (!r.scenarioFlag || flags[r.scenarioFlag]); });
  }

  /* ---- the head (avatar / name / handle / stats) ------------------------ */
  function head(sub) {
    var p = sub.persona, prof = sub.prof, rep = sub.rep;
    var sub2 = prof && prof.handle ? '@' + esc(prof.handle)
      : rep ? esc(rep.office)
      : p.home ? 'Resident of ' + esc(jurName(p.home))
      : 'A public profile';
    var stats = [];
    if (p.home) stats.push('<span class="profile-stat">' + icon('landmark', { size: 'sm' }) + ' <b>' + esc(jurName(p.home)) + '</b> home</span>');
    if (prof) {
      stats.push('<span class="profile-stat"><b>' + esc(prof.followersCount) + '</b> followers</span>');
      stats.push('<span class="profile-stat"><b>' + esc(prof.followsCount) + '</b> following</span>');
      stats.push('<span class="profile-stat"><b>' + esc((prof.groups || []).length) + '</b> groups</span>');
      stats.push('<span class="profile-stat"><b>' + esc((prof.orgs || []).length) + '</b> organizations</span>');
    }
    if (rep) stats.push('<span class="profile-stat">' + icon('users', { size: 'sm' }) + ' Serves every resident equally</span>');

    var actions = sub.isSelf
      ? ''
      : '<div class="cluster" style="gap:var(--space-2);align-self:flex-start">' +
        '<button type="button" class="btn btn--primary btn--sm" data-act="follow" data-name="' + esc(p.name) + '">' + icon('user', { size: 'sm' }) + ' Follow</button>' +
        '<button type="button" class="btn btn--secondary btn--sm" data-act="message" data-name="' + esc(p.name) + '">' + icon('file-text', { size: 'sm' }) + ' Message</button>' +
        '</div>';

    return '<div class="card profile-head">' +
      '<span class="profile-avatar" aria-hidden="true">' + esc(p.initials) + '</span>' +
      '<div class="stack" style="gap:var(--space-1);flex:1 1 16rem">' +
      '<div class="cluster" style="align-items:baseline;gap:var(--space-2)">' +
      '<h1 style="font-size:var(--text-xl);color:var(--gov-fg);margin:0">' + esc(p.name) + '</h1>' +
      '<span class="gloss">' + sub2 + '</span>' +
      (prof ? pill('info', 'Your choice to show', 'Your legal name shows only because you chose to. A handle is the default — and the choice never changes a single right.') : '') +
      '</div>' +
      (p.bio ? '<p class="gloss" style="margin:0">' + esc(p.bio) + '</p>' : '') +
      '<div class="profile-stats" style="margin-block-start:var(--space-2)">' + stats.join('') + '</div>' +
      '</div>' + actions + '</div>';
  }

  /* ---- TAB: overview ---------------------------------------------------- */
  function overviewPanel(sub) {
    var html = '';
    if (sub.isSelf) {
      var ballots = ballotRows();
      var open = ballots.filter(function (r) { return r.status === 'open'; }).length;
      var w = E().wallet;
      var phrase = open
        ? open + ' ballot' + (open === 1 ? ' is' : 's are') + ' open for you'
        : 'nothing needs your vote this minute';
      html += '<div class="banner banner--info">' + icon('clock', { size: 'sm' }) +
        '<div><strong>Right now</strong> — ' + esc(phrase) + ', and your stipend last reached your wallet on ' +
        esc(w.transactions[0].date) + '. Your ballot only ever shows that voting is open — never how you voted.</div></div>';

      var rows = ballots.map(function (r) {
        return '<li class="cluster" style="justify-content:space-between;padding-block:var(--space-2);border-block-end:1px solid var(--gov-border)">' +
          '<span><strong style="color:var(--gov-fg)">' + esc(r.title) + '</strong><span class="gloss"> · ' + esc(r.what) + '</span></span>' +
          '<span class="cluster" style="gap:var(--space-2);align-items:center">' +
          (r.pill ? pill(r.pill.tone, r.pill.label, r.pill.tip) : '') + countdownHtml(r.target) + '</span></li>';
      }).join('');
      html += '<section class="card" aria-labelledby="ov-votes"><h2 id="ov-votes">' + icon('vote', { size: 'sm' }) + ' Open votes</h2>' +
        '<p class="gloss">Everything waiting for your ballot. You can vote on anything here just by living in the jurisdiction — nothing else is ever required.</p>' +
        (rows ? '<ul style="list-style:none;padding:0;margin:0">' + rows + '</ul>' : '<p class="gloss">No ballot is open for you right now.</p>') +
        '<div class="cluster" style="margin-block-start:var(--space-3)">' +
        '<a class="btn btn--primary btn--sm" href="' + hrefV1('electoral/open-ballot.html') + '">' + icon('vote', { size: 'sm' }) + ' Open ballot</a>' +
        '<a class="btn btn--secondary btn--sm" href="' + hrefV1('electoral/ranked-ballot.html') + '">' + icon('vote', { size: 'sm' }) + ' Ranked ballot</a>' +
        '</div></section>';
    } else {
      html += '<div class="banner banner--info">' + icon('user', { size: 'sm' }) +
        '<div>This is ' + esc(sub.persona.name) + '’s public profile' + (sub.rep ? ' — one of the people who may hold a seat that serves you.' : '.') +
        ' What you can do here — follow, message, read their public record — never depends on paying or joining anything.</div></div>';
    }
    return html;
  }

  /* ---- TAB: record ------------------------------------------------------ */
  function recordPanel(sub) {
    var prof = sub.prof, rep = sub.rep;
    var acts = '';
    if (sub.isSelf) {
      acts = '<p>' + icon('file-text', { size: 'sm' }) + ' Most recent: <strong style="color:var(--gov-fg)">testimony filed</strong> on the Clean Air Act, ' +
        'and a public endorsement of ' + esc(nm('diego-ramos', 'a candidate')) + '.</p>';
    } else if (rep) {
      acts = '<p>' + esc(sub.persona.name) + ' holds public office: <strong style="color:var(--gov-fg)">' + esc(rep.office) + '</strong>. ' +
        'Every act they take in that role is written to the public record and cannot be quietly changed.</p>';
    } else {
      acts = '<p class="gloss">' + esc(sub.persona.name) + '’s public acts appear here — testimony, sponsorships, endorsements — each written to the permanent record.</p>';
    }

    var endChips = '';
    if (prof && (prof.endorsementsGiven || []).length) {
      endChips = '<h3 style="margin-block-start:var(--space-4)">Endorsements given</h3>' +
        '<p class="gloss">An endorsement is a public choice to back someone. It is completely separate from a secret ballot — showing one here never reveals how anyone voted.</p>' +
        '<div class="role-grid">' + prof.endorsementsGiven.map(function (en) {
          return '<a class="role-card" href="' + hrefV2('social/profile.html?who=' + encodeURIComponent(en.to) + '&tab=candidacy') + '" style="text-decoration:none">' +
            icon('vote') + '<span class="role-name">' + esc(nm(en.to)) + '</span>' +
            '<span>' + (en.public ? 'Backed publicly, by choice.' : 'Backed privately.') + '</span>' +
            '<span class="enter-as">' + pill(en.public ? 'pass' : 'closed', en.public ? 'Public' : 'Private') + '</span></a>';
        }).join('') + '</div>';
    }

    /* the full record lives on the public-records surface — never back here */
    var recHref = sub.isSelf ? 'system/public-records.html' : ((prof && prof.record) ? prof.record : 'system/public-records.html');
    return '<section class="card" aria-labelledby="rec-h"><h2 id="rec-h">' + icon('file-text', { size: 'sm' }) + ' Public record</h2>' +
      '<p class="gloss">The complete, audited civic history — residency, votes cast (counts only, never how), offices, filings. The receipt for a civic life; it cannot be quietly edited.</p>' +
      acts + endChips +
      '<div class="cluster" style="margin-block-start:var(--space-3)">' +
      '<a class="btn btn--secondary btn--sm" href="' + hrefV1(recHref) + '">' + icon('file-text', { size: 'sm' }) +
      ' ' + (sub.isSelf ? 'Open my full record' : 'Open the full record') + ' ' + icon('arrow-right', { size: 'sm' }) + '</a></div>' +
      '</section>';
  }

  /* ---- TAB: candidacy (when this person is standing in a race) ----------
     Folded in from the retired electoral/candidate-profile page: a candidacy
     is not a separate identity — it is this same profile, carried onto the
     ballot. Statement, approval standing, stages, endorsements; the "record
     that rides along" IS the Record tab. */
  var CAND_LABELS = {
    'Registered (any time after prior certification)': 'Registered',
    'Validated': 'Validated',
    'In-Approval-Pool': 'In the approval pool',
    '[Endorsed]': 'Endorsed (optional)',
    'Finalist | Non-finalist (write-in eligible)': 'Made the final ballot — or staying write-in eligible',
    'On-Ranked-Ballot / Written-In': 'On the ballot',
    'Elected | Not elected | Withdrawn': 'Elected, not elected, or withdrew'
  };
  var PHASE_LABEL = { approval: 'approval phase', ranked: 'ranked window open', certifying: 'certifying' };
  /* Public individual endorsers — the public web. Endorsements are public only
     by the endorser's choice; approval votes are always secret. */
  var ENDORSER_WEB = [
    { name: 'Omar Farouk', endorses: ['diego-ramos', 'fatou-ndiaye', 'linh-pham'] },
    { name: 'Wren Ashby', candidate: 'wren-ashby', endorses: ['diego-ramos', 'nadia-haq'] },
    { name: 'Dalia Mansour', candidate: 'dalia-mansour', endorses: ['diego-ramos', 'fatou-ndiaye', 'yara-haddad'] },
    { name: 'Amara Okafor', endorses: ['diego-ramos', 'keisha-boyd', 'linh-pham'] },
    { name: 'Priya Sharma', endorses: ['keisha-boyd', 'nadia-haq'] },
    { name: 'Tomás Ferreira', endorses: ['linh-pham', 'june-okada'] }
  ];
  var CAND_REQUESTS = {
    'diego-ramos': [
      { org: 'commons-party', date: '2031-02-14', status: 'granted' },
      { org: 'green-horizon', date: '2031-04-30', status: 'pending' },
      { org: 'hudson-mutual-aid', date: '2031-03-22', status: 'declined' }
    ]
  };
  function candProfileHref(id) {
    var oc = candidateOf(id);
    return hrefV2('social/profile.html?who=' + encodeURIComponent(id) + '&tab=candidacy' +
      (oc ? '' : '&name=' + encodeURIComponent(id))) ;
  }
  function candidacyPanel(sub) {
    var c = sub.cand, W = F().world;
    var generic = !!c.generic;
    var flags; try { flags = CGA.state.get('scenario') || {}; } catch (e) { flags = {}; }

    var election = null;
    (W.elections || []).forEach(function (e) { if (e.id === c.election) election = e; });
    if (!election && !generic) election = W.elections[0];
    var raceJur = election ? byId().jurisdictions[election.jurisdiction] : null;
    var raceLabel = election
      ? (raceJur ? raceJur.name : election.jurisdiction) + ' general election · ' + election.seats.toLocaleString('en-US') + ' seats'
      : 'Race not in the demo fixture set';
    var phase = generic ? (election ? election.phase : null) : flags.election;
    var phaseOpen = phase === 'approval';

    var byApprovals = W.candidates.slice().sort(function (a, b) { return b.approvals - a.approvals; });
    var rank = generic ? 0 : byApprovals.indexOf(c) + 1;
    var FINALISTS = election ? election.finalistCount : 0;
    var isFinalist = rank > 0 && rank <= FINALISTS;

    /* candidacy stages — raw registry tokens stay as keys, plain labels shown */
    var states = byId().entities['Candidacy'].states;
    var current = generic
      ? (!phase ? 'Validated' : phaseOpen ? 'In-Approval-Pool' : 'On-Ranked-Ballot / Written-In')
      : !phaseOpen ? 'Finalist | Non-finalist (write-in eligible)'
      : (c.endorsedBy || []).length ? '[Endorsed]' : 'In-Approval-Pool';
    var strip = '<div class="state-strip" aria-label="Candidacy stages" style="margin-block-start:var(--space-3)">' +
      states.map(function (s2, i) {
        return (i ? '<span class="state-arrow" aria-hidden="true">→</span>' : '') +
          '<span class="state-node' + (s2 === current ? ' state-node--current' : '') + '"' + (s2 === current ? ' aria-current="step"' : '') + '>' +
          esc(S().plainState(s2, CAND_LABELS)) + '</span>';
      }).join('') + '</div>';

    var html = '<div class="banner banner--info">' + icon('vote', { size: 'sm' }) +
      '<div><strong>' + esc(c.name) + ' is standing for election</strong> — ' + esc(raceLabel) +
      (phase ? ' · ' + esc(PHASE_LABEL[phase] || phase) : '') +
      '. A candidacy is not a separate identity: it is this same profile, carried onto the ballot.</div></div>';

    /* platform statement */
    html += '<section class="card" aria-labelledby="cd-stmt"><h2 id="cd-stmt">' + icon('file-text', { size: 'sm' }) + ' Platform statement</h2>' +
      '<p style="font-size:var(--text-lg);color:var(--gov-fg)">' + esc(c.statement || 'No platform statement published yet.') + '</p>' +
      '<p class="citation">Shown on the open ballot and the ranked ballot. Every edit is appended to the public record.</p></section>';

    /* approval standing (fixture) or the count record (generic) */
    if (!generic) {
      var topApprovals = byApprovals[0].approvals;
      var lineApprovals = (byApprovals[FINALISTS - 1] || byApprovals[byApprovals.length - 1]).approvals;
      html += '<section class="card" aria-labelledby="cd-standing"><h2 id="cd-standing">Approval standing</h2>' +
        (phaseOpen ? '' : '<div class="banner banner--warning">' + icon('clock', { size: 'sm' }) +
          '<div>The approval phase has closed — this standing is frozen at the finalist cutoff. ' +
          (isFinalist ? 'A finalist on the ranked ballot.' : 'Below the finalist line — still write-in eligible.') + '</div></div>') +
        '<div class="cluster" style="gap:var(--space-6)">' +
        '<div class="stat stat--accent"><span class="stat-number" data-no-i18n>#' + rank + '</span><span class="stat-label">rank of ' + W.candidates.length + ' in the race</span></div>' +
        '<div class="stat"><span class="stat-number" data-no-i18n>' + esc(c.approvals.toLocaleString('en-US')) + '</span><span class="stat-label">approvals (aggregate · updated daily)</span></div>' +
        '<div class="stat"><span class="stat-number" data-no-i18n>' + FINALISTS + '</span><span class="stat-label">places on the final ballot</span></div></div>' +
        '<div class="meter-block" style="margin-block-start:var(--space-3)">' +
        '<div class="meter"><span class="meter-fill' + (isFinalist ? ' meter-fill--met' : '') + '" style="inline-size:' + Math.round(c.approvals / topApprovals * 100) + '%"></span>' +
        '<span class="meter-threshold" style="inset-inline-start:' + Math.round(lineApprovals / topApprovals * 100) + '%"></span></div>' +
        '<div class="meter-caption"><span>' + (isFinalist ? 'inside the top ' + FINALISTS + ' — finalist track' : 'below the finalist line · write-in eligible') + '</span><span>gold tick = finalist line</span></div></div>' +
        '<p class="gloss">The top ' + FINALISTS + ' by approvals when the phase closes make the final ballot; everyone else stays write-in eligible. Approval votes are always secret — only aggregates ever show.</p>' +
        strip + '</section>';
    } else {
      html += '<section class="card" aria-labelledby="cd-standing"><h2 id="cd-standing">Standing</h2>' +
        '<p class="gloss">Approval standing applies only while the approval phase runs. For this race, standing lives in the public count record — every round, every transfer, fully auditable.</p>' +
        '<p><a class="btn btn--secondary btn--sm" href="' + hrefV1('electoral/results.html') + '">Standing: see the count ' + icon('arrow-right', { size: 'sm' }) + '</a></p>' +
        strip + '</section>';
    }

    /* endorsements — org chips, the public/private split, the public web */
    var named = ENDORSER_WEB.filter(function (e2) { return e2.endorses.indexOf(c.id) >= 0; });
    var pub = Math.max(named.length, Math.round((c.individualEndorsements || 0) * 0.3));
    var priv = Math.max(0, (c.individualEndorsements || 0) - pub);
    var chips = (c.endorsedBy || []).map(function (oid) {
      var o = byId().organizations[oid];
      return '<span class="org-chip">' + esc(o.name) + ' <span class="org-type">' + esc(o.type) + '</span></span>';
    }).join('');
    chips += generic
      ? '<span class="tag-chip">no public endorsements recorded</span>'
      : '<span class="tag-chip">' + (c.individualEndorsements || 0) + ' individual · ' + pub + ' public, ' + priv + ' private</span>' +
        ((c.endorsedBy || []).length ? '' : '<span class="tag-chip">running without endorsements</span>');
    var web = named.map(function (e2) {
      var others = e2.endorses.filter(function (id) { return id !== c.id; });
      var links = others.map(function (id) {
        var oc = candidateOf(id);
        return '<a href="' + candProfileHref(id) + '">' + esc(oc ? oc.name : id) + '</a>';
      }).join(' · ');
      var tag = e2.candidate ? ' <span class="tag-chip">also a candidate in this race</span>' : '';
      return '<details class="about-surface"><summary>' + icon('chevron-right', { size: 'sm' }) + ' ' + esc(e2.name) + tag + '</summary>' +
        '<div class="about-surface-body"><p class="cc-small" style="margin-block-end:var(--space-1)">' +
        (others.length ? 'Also publicly endorses: ' + links : 'Publicly endorses only this candidate.') + '</p>' +
        (e2.candidate ? '<p class="cc-small" style="margin-block-end:0"><a href="' + candProfileHref(e2.candidate) + '">Open ' + esc(e2.name) + '’s own profile</a></p>' : '') +
        '</div></details>';
    }).join('');
    if (!generic && pub > named.length) {
      var more = pub - named.length;
      web += '<p class="gloss" style="margin-block-end:0">+ ' + more + ' more public ' + (more === 1 ? 'endorser' : 'endorsers') +
        ' — the live game shows every public endorser; this mockup names the web for the leading candidates.</p>';
    }
    var reqs = generic ? [] : (CAND_REQUESTS[c.id] || (c.endorsedBy || []).map(function (oid) { return { org: oid, date: '2031-03-01', status: 'granted' }; }));
    var tone = { granted: ['success', 'check'], pending: ['warning', 'clock'], declined: ['neutral', 'x'] };
    var reqRows = reqs.length ? reqs.map(function (r) {
      var o = byId().organizations[r.org];
      return '<tr><td>' + esc(o ? o.name : r.org) + '</td><td class="mono" data-no-i18n>' + esc(r.date) + '</td>' +
        '<td>' + badge(tone[r.status][0], r.status + (r.status === 'granted' ? ' · endorsed candidate' : ''), tone[r.status][1]) + '</td></tr>';
    }).join('') : '<tr><td colspan="3" class="gloss">' + (generic
      ? 'No endorsement requests on the public record.'
      : 'No requests filed — this candidacy runs on individual endorsements only.') + '</td></tr>';

    html += '<section class="card" aria-labelledby="cd-end"><h2 id="cd-end">' + icon('users', { size: 'sm' }) + ' Endorsements</h2>' +
      '<p class="cc-small">People <strong>and</strong> organizations endorse candidates directly — there’s no party machinery to join. ' +
      'Disclosure is always the endorser’s choice; approval votes are always secret.</p>' +
      '<div class="cluster" style="margin-block-start:var(--space-2)">' + chips + '</div>' +
      (web ? '<h3 style="margin-block-start:var(--space-4)">Public individual endorsers — the public web</h3><div class="stack" style="gap:var(--space-2)">' + web + '</div>' : '') +
      '<h3 style="margin-block-start:var(--space-4)">Endorsement requests</h3>' +
      '<div class="table-wrap"><table class="table"><caption class="visually-hidden">Endorsement request status</caption>' +
      '<thead><tr><th>Organization</th><th>Requested</th><th>Status</th></tr></thead><tbody>' + reqRows + '</tbody></table></div>' +
      '<p class="gloss">A granted organizational endorsement marks the candidacy as endorsed. Candidates with no endorsements appear and count exactly the same as everyone else.</p>' +
      '</section>';

    /* the record rides along — it IS the Record tab of this same profile */
    html += '<section class="card card--inset" aria-labelledby="cd-rec"><h2 id="cd-rec">' + icon('file-text', { size: 'sm' }) + ' The record rides along</h2>' +
      '<p class="gloss">Every candidacy carries the person’s full public record with it — auto-attached, never editable by the candidate. ' +
      'It is the <strong>Record tab of this same profile</strong>: voters compare records, not advertising.</p>' +
      '<p><button type="button" class="btn btn--ghost btn--sm" data-goto-tab="record">' + icon('arrow-right', { size: 'sm' }) + ' Open the Record tab</button></p></section>';

    /* manage — only on your own profile */
    if (sub.isSelf) {
      html += '<section class="card" aria-labelledby="cd-manage"><h2 id="cd-manage">Manage this candidacy <span class="citation">visible only to you</span></h2>' +
        '<div class="field"><label class="field-label" for="cd-statement">Platform statement</label>' +
        '<textarea class="field-input" id="cd-statement" rows="2">' + esc(c.statement || '') + '</textarea>' +
        '<span class="field-hint">Shown on the open ballot and the ranked ballot.</span></div>' +
        '<div class="cluster"><button type="button" class="btn btn--primary btn--sm" data-cand-save>Save statement</button>' +
        '<span class="citation">modifies the candidate record · simulated</span></div>' +
        '<hr /><h3>Withdraw candidacy</h3>' +
        '<p class="cc-small">Withdrawal is allowed until ballot lock at the finalist cutoff. It is recorded permanently on the public record.</p>' +
        '<div class="cluster" data-withdraw-area>' +
        (phaseOpen
          ? '<button type="button" class="btn btn--secondary btn--sm" data-cand-withdraw>Withdraw candidacy</button>'
          : '<button type="button" class="btn btn--secondary btn--sm" disabled>Withdraw candidacy</button>' +
            '<span class="citation">ballot locked at the finalist cutoff — withdrawal closed</span>') +
        '</div></section>';
    } else {
      html += '<p class="citation">Statement edits, endorsement requests, and withdrawal appear on this tab only when ' +
        esc(c.name.split(' ')[0]) + ' opens their own profile — every change lands on the public record.</p>';
    }

    return html;
  }

  /* ---- TAB: office (only when this person holds a seat) ----------------- */
  function statusPill(status) {
    var map = { accepted: ['pass', 'Accepted'], open: ['wait', 'Open'], declined: ['closed', 'Declined'] };
    var m = map[status] || ['info', status]; return pill(m[0], m[1], '');
  }
  function officePanel(sub) {
    var rep = sub.rep, p = sub.persona;
    var connect = '<div class="cluster" style="gap:var(--space-3)">' +
      '<a class="btn btn--secondary btn--sm" href="' + hrefV1(rep.record) + '">' + icon('file-text', { size: 'sm' }) + ' Their public record</a>' +
      '<a class="btn btn--secondary btn--sm" href="' + hrefV2('shared/live-room.html?variant=' + encodeURIComponent(rep.forum)) + '">' + icon('users', { size: 'sm' }) + ' Their forum — open a live room ' + icon('arrow-right', { size: 'sm' }) + '</a>' +
      '</div>';

    var surgeries = '<section style="margin-block-start:var(--space-4)"><h3>Office hours &amp; open meetings</h3>' +
      '<p class="gloss">Every session is open to watch. Each is one tap into the live room — only residents of ' + esc(jurName(rep.jurisdiction)) + ' may take the floor.</p>' +
      '<div class="role-grid">' + rep.surgeries.map(function (sg) {
        return '<a class="role-card" href="' + hrefV2('shared/live-room.html?variant=' + encodeURIComponent(rep.forum)) + '">' +
          icon('clock') + '<span class="role-name">' + esc(sg.kind) + '</span>' +
          '<span>' + esc(sg.date) + ' · ' + esc(sg.where) + '</span>' +
          '<span class="enter-as">Enter the room ' + icon('arrow-right', { size: 'sm' }) + '</span></a>';
      }).join('') + '</div></section>';

    var reach = '';
    if (!sub.isSelf) {
      var msgId = 'msg-' + esc(rep.persona);
      reach = '<section class="card card--inset" style="margin-block-start:var(--space-4)"><h3>Reach this representative</h3>' +
        '<p class="gloss">You can actually reach the person who holds your seat — not a form that disappears. No payment, membership, or status is ever required.</p>' +
        '<div class="cluster" style="gap:var(--space-3)">' +
        '<button type="button" class="btn btn--primary btn--sm" data-meeting="' + esc(p.name) + '">' + icon('clock', { size: 'sm' }) + ' Request a meeting</button></div>' +
        '<div class="stack" style="gap:var(--space-2);margin-block-start:var(--space-3)">' +
        '<label for="' + msgId + '"><strong>Send a constituent message</strong></label>' +
        '<textarea id="' + msgId + '" class="field-input" rows="3" data-msg-for="' + esc(p.name) + '" placeholder="Tell ' + esc(p.name) + ' what matters to you."></textarea>' +
        '<div class="cluster" style="gap:var(--space-3)">' +
        '<button type="button" class="btn btn--secondary btn--sm" data-send-msg="' + esc(rep.persona) + '">' + icon('arrow-right', { size: 'sm' }) + ' Send message</button>' +
        '<span class="gloss">Simulated — nothing leaves this mockup.</span></div></div></section>';
    }

    var queue = '<section style="margin-block-start:var(--space-4)"><h3>Constituent requests</h3>' +
      '<p class="gloss">Requests reach the floor in their turn. The chair orders the queue — never decides whose request has merit.</p>' +
      '<div class="table-wrap"><table class="table"><thead><tr><th>From</th><th>Kind</th><th>Topic</th><th>Status</th></tr></thead><tbody>' +
      rep.requests.map(function (r) {
        return '<tr><td><code>' + esc(r.from) + '</code></td><td>' + esc(r.kind) + '</td><td>' + esc(r.topic) + '</td><td>' + statusPill(r.status) + '</td></tr>';
      }).join('') + '</tbody></table></div></section>';

    return '<section class="card" aria-labelledby="off-h">' +
      '<h2 id="off-h">' + icon('landmark', { size: 'sm' }) + ' Office record — ' + esc(rep.office) + '</h2>' +
      '<p class="gloss">Seats here are won in multi-winner rounds (proportional STV), so this member answers to <strong>every</strong> resident of ' + esc(jurName(rep.jurisdiction)) + ' — not only those who ranked them.</p>' +
      connect + surgeries + reach + queue + '</section>';
  }

  /* ---- TAB: representatives (self) — the people who hold your seats ------ */
  function repsPanel(sub) {
    var reps = sub.myReps;
    var jur = reps.length ? jurName(reps[0].jurisdiction) : 'your jurisdiction';
    var cards = reps.map(function (rep) {
      var p = personaOf(rep.persona);
      var nextHours = (rep.surgeries || []).map(function (sg) { return esc(sg.kind) + ' (' + esc(sg.date) + ')'; }).join(' · ');
      return '<a class="role-card" href="' + hrefV2('social/profile.html?who=' + encodeURIComponent(rep.persona) + '&tab=office') + '" style="text-decoration:none">' +
        '<span class="profile-avatar profile-avatar--sm" aria-hidden="true">' + esc(p.initials) + '</span>' +
        '<span class="role-name">' + esc(p.name) + '</span>' +
        '<span>' + esc(rep.office) + '</span>' +
        (nextHours ? '<span class="gloss">' + icon('clock', { size: 'sm' }) + ' Next: ' + nextHours + '</span>' : '') +
        '<span class="enter-as">Open their profile &amp; reach them ' + icon('arrow-right', { size: 'sm' }) + '</span></a>';
    }).join('');
    return '<section class="card" aria-labelledby="reps-h">' +
      '<h2 id="reps-h">' + icon('landmark', { size: 'sm' }) + ' Your representatives</h2>' +
      '<p class="gloss">Seats are elected in multi-winner rounds, so <strong>several people</strong> represent you at once — not just one. Every one of them answers to you, including the ones you didn’t rank. Open anyone to read their record, walk into their open meetings, and send a message that lands in their queue.</p>' +
      '<div class="banner banner--info">' + icon('info', { size: 'sm' }) +
      '<div><strong>Reaching a representative is always free.</strong> No payment, membership, or status is ever required to contact the people who serve you.</div></div>' +
      '<div class="role-grid" style="margin-block-start:var(--space-3)">' + (cards || '<p class="gloss">No representatives seated yet.</p>') + '</div>' +
      '<section aria-labelledby="reach-how-h" style="margin-block-start:var(--space-4)"><h3 id="reach-how-h">How reaching a representative works</h3><ul>' +
      '<li><strong>Open meetings are genuinely open.</strong> Office hours and town halls run in the live room — anyone may watch; residents of ' + esc(jur) + ' may take the floor.</li>' +
      '<li><strong>The chair orders the queue, not the politics.</strong> A request reaches the floor in its turn — the chair never decides whose request has merit.</li>' +
      '<li><strong>Your message is yours.</strong> A constituent message is a private channel to your representative — like a ballot, only the two of you can read it.</li>' +
      '<li><strong>Representation does not depend on your vote.</strong> Multi-winner seats answer to every resident, including those who ranked someone else.</li>' +
      '</ul></section></section>';
  }

  /* ---- TAB: wallet (self) ----------------------------------------------- */
  function walletPanel(sub) {
    var w = E().wallet, c = E().currency, st = E().stipend;
    var mine = (st.examples || []).filter(function (x) { return x.persona === sub.personaId; })[0] || { amount: st.base, breakdown: 'base ' + st.base };
    return '<section class="card" aria-labelledby="wal-h">' +
      '<div class="cluster" style="justify-content:space-between;align-items:flex-start">' +
      '<h2 id="wal-h">' + icon('lock', { size: 'sm' }) + ' Wallet</h2>' + badge('warning', 'Planned', 'clock') + '</div>' +
      '<p class="never-federated">' + icon('lock', { size: 'sm' }) + '<span>Private — like a ballot, only you can read it.</span></p>' +
      '<p class="wallet-balance">' + esc(w.balance) + '</p>' +
      '<p class="gloss">In ' + esc(c.name) + ' (<span class="unit-symbol">' + esc(c.symbol) + '</span> ' + esc(c.code) + '). ' + esc(c.abstractNote) + '</p>' +
      '<p>Your civic stipend this period: <strong style="color:var(--gov-fg)">' + esc(mine.amount) + ' ' + esc(c.symbol) + '</strong> ' +
      '<span class="gloss">(' + esc(mine.breakdown) + ')</span>. It is a payment <em>to</em> you, never required <em>of</em> you, and never a condition for any office.</p>' +
      '<div class="cluster" style="margin-block-start:var(--space-3)">' +
      '<a class="btn btn--primary btn--sm" href="' + hrefV2('economy/wallet.html') + '">' + icon('lock', { size: 'sm' }) + ' Open my wallet ' + icon('arrow-right', { size: 'sm' }) + '</a>' +
      '<a class="btn btn--secondary btn--sm" href="' + hrefV2('economy/stipend.html') + '">' + icon('refresh-cw', { size: 'sm' }) + ' My stipend</a>' +
      '</div></section>';
  }

  /* ---- TAB: groups & organizations (self) ------------------------------- */
  function groupsPanel(sub) {
    var prof = sub.prof;
    var grpRows = (prof.groups || []).map(function (gid) {
      var g = (V2().groups.spaces || []).filter(function (s2) { return s2.id === gid; })[0] || { name: gid, purpose: '', members: null, privacy: 'invite' };
      return '<tr><th scope="row">' + esc(g.name) + '</th><td>' + esc(g.purpose || '') + '</td>' +
        '<td>' + (g.members != null ? esc(g.members) + ' members' : '—') + '</td>' +
        '<td>' + pill('info', g.privacy === 'open' ? 'Open' : 'Invite', 'A voluntary association you can leave at any time.') + '</td></tr>';
    }).join('');
    var orgRows = (prof.orgs || []).map(function (oid) {
      var o = byId().organizations[oid] || {};
      return '<tr><th scope="row">' + esc(orgName(oid)) + '</th><td>' + esc((o.type || 'organization')) + '</td>' +
        '<td><a href="' + hrefV2('social/org-profile.html?org=' + encodeURIComponent(oid)) + '">View organization ' + icon('arrow-right', { size: 'sm' }) + '</a></td></tr>';
    }).join('');
    return '<section class="card" aria-labelledby="grp-h"><h2 id="grp-h">' + icon('users', { size: 'sm' }) + ' Groups</h2>' +
      '<p class="gloss">Voluntary groups you joined. Membership is private to you, and joining or leaving never changes a right.</p>' +
      '<div class="table-wrap"><table class="table"><thead><tr><th>Group</th><th>Purpose</th><th>Size</th><th>Privacy</th></tr></thead>' +
      '<tbody>' + (grpRows || '<tr><td colspan="4" class="gloss">No groups.</td></tr>') + '</tbody></table></div>' +
      '<p style="margin-block-start:var(--space-3)"><a class="btn btn--ghost btn--sm" href="' + hrefV2('groups/groups-home.html') + '">' + icon('users', { size: 'sm' }) + ' Browse all groups ' + icon('arrow-right', { size: 'sm' }) + '</a></p>' +
      '</section>' +
      '<section class="card" aria-labelledby="org-h"><h2 id="org-h">' + icon('building', { size: 'sm' }) + ' Organizations</h2>' +
      '<p class="gloss">Organizations you belong to as a worker or member. Their pages are public; your membership is yours to disclose.</p>' +
      '<div class="table-wrap"><table class="table"><thead><tr><th>Organization</th><th>Type</th><th></th></tr></thead>' +
      '<tbody>' + (orgRows || '<tr><td colspan="3" class="gloss">No memberships shown.</td></tr>') + '</tbody></table></div>' +
      '</section>';
  }

  /* ---- TAB: achievements (self / anyone with a profile) ----------------- */
  function achievementsPanel(sub) {
    var prof = sub.prof || {};
    var chips = (prof.achievements || []).map(function (a) {
      return '<a class="achievement-chip" href="' + hrefV2('social/achievements.html') + '" title="' + esc(a.note || 'An earned record of taking part') + '">' +
        icon('check', { size: 'sm' }) + esc(a.name) + '</a>';
    }).join(' ');
    var empty = '<span class="gloss">Nothing here yet — finish your first <a href="' + hrefV2('index.html') + '">journey</a> to earn one.</span>';
    return '<section class="card card--inset" aria-labelledby="ach-h">' +
      '<div class="cluster" style="justify-content:space-between;align-items:flex-start">' +
      '<h2 id="ach-h">' + icon('award', { size: 'sm' }) + ' Achievements</h2>' + badge('neutral', 'Proposed') + '</div>' +
      '<p class="gloss">Earned records of the journeys you complete and your civic firsts. Each carries a one-time stipend bonus when the economy goes live — and none ever changes a vote, a seat, or what you are allowed to do.</p>' +
      '<div class="cluster" style="margin-block-start:var(--space-3)">' + (chips || empty) + '</div>' +
      '<div class="cluster" style="margin-block-start:var(--space-3)">' +
      '<a class="btn btn--secondary btn--sm" href="' + hrefV2('social/achievements.html') + '">' + icon('award', { size: 'sm' }) + ' The achievement catalog ' + icon('arrow-right', { size: 'sm' }) + '</a></div>' +
      '</section>';
  }

  /* ---- assemble the tabs ------------------------------------------------ */
  function tabsFor(sub) {
    var t = [];
    t.push({ key: 'overview', label: 'Overview', icon: 'user', html: overviewPanel(sub) });
    t.push({ key: 'record', label: 'Record', icon: 'file-text', html: recordPanel(sub) });
    if (sub.cand) t.push({ key: 'candidacy', label: 'Candidacy', icon: 'vote', html: candidacyPanel(sub) });
    if (sub.rep) t.push({ key: 'office', label: 'Office', icon: 'landmark', html: officePanel(sub) });
    if (sub.isSelf) t.push({ key: 'representatives', label: 'Representatives', icon: 'landmark', html: repsPanel(sub) });
    if (sub.isSelf) t.push({ key: 'wallet', label: 'Wallet', icon: 'lock', html: walletPanel(sub) });
    if (sub.prof && ((sub.prof.groups || []).length || (sub.prof.orgs || []).length)) t.push({ key: 'groups', label: 'Groups & orgs', icon: 'users', html: groupsPanel(sub) });
    /* Achievements always shows for yourself — the empty state is the invitation */
    if (sub.isSelf || (sub.prof && (sub.prof.achievements || []).length)) t.push({ key: 'achievements', label: 'Achievements', icon: 'award', html: achievementsPanel(sub) });
    return t;
  }

  function render(opts) {
    var sub = resolve(opts);
    var tabs = tabsFor(sub);
    var want = (opts && opts.tab) || param('tab');
    var active = 0;
    for (var i = 0; i < tabs.length; i++) { if (tabs[i].key === want) { active = i; break; } }

    var eyebrow = sub.isSelf ? 'Your profile · one place, every role'
      : (sub.rep ? 'A profile · the person who holds your seat' : 'A profile · a fellow resident');
    var intro = sub.isSelf
      ? 'Everything about your civic life in one place — who you are, your record, your wallet, the people who represent you, and (if you ever hold office) your office record. Public threads are on the record; private threads are private — like a ballot, only the people named can read them.'
      : 'One person, shown the same way everyone is. If they hold an office, their office record is just another tab — there is no separate kind of profile for officials.';

    var tablist = '<div class="profile-tabs" role="tablist" aria-label="Profile sections">' +
      tabs.map(function (tb, idx) {
        return '<button type="button" role="tab" id="ptab-' + tb.key + '" class="profile-tab' + (idx === active ? ' is-active' : '') + '" ' +
          'aria-selected="' + (idx === active ? 'true' : 'false') + '" aria-controls="ppanel-' + tb.key + '" tabindex="' + (idx === active ? '0' : '-1') + '" data-tab="' + tb.key + '">' +
          icon(tb.icon, { size: 'sm' }) + ' <span>' + esc(tb.label) + '</span></button>';
      }).join('') + '</div>';

    var panels = tabs.map(function (tb, idx) {
      return '<div role="tabpanel" id="ppanel-' + tb.key + '" aria-labelledby="ptab-' + tb.key + '" class="stack" style="gap:var(--space-4)"' + (idx === active ? '' : ' hidden') + '>' + tb.html + '</div>';
    }).join('');

    return '<header><span class="eyebrow">' + eyebrow + '</span>' +
      '<p class="page-intro" style="margin-block-start:var(--space-2)">' + esc(intro) + '</p></header>' +
      head(sub) + tablist + '<div class="profile-panels">' + panels + '</div>';
  }

  /* ---- wiring ----------------------------------------------------------- */
  function wire(root) {
    root = root || document;
    if (tick) { clearInterval(tick); tick = null; }

    var tabsEl = root.querySelector('.profile-tabs');
    if (tabsEl) {
      var btns = Array.prototype.slice.call(tabsEl.querySelectorAll('[role="tab"]'));
      function activate(key, focus) {
        btns.forEach(function (b) {
          var on = b.getAttribute('data-tab') === key;
          b.classList.toggle('is-active', on);
          b.setAttribute('aria-selected', on ? 'true' : 'false');
          b.setAttribute('tabindex', on ? '0' : '-1');
          if (on && focus) b.focus();
          var panel = root.querySelector('#ppanel-' + b.getAttribute('data-tab'));
          if (panel) { if (on) panel.removeAttribute('hidden'); else panel.setAttribute('hidden', ''); }
        });
      }
      tabsEl.addEventListener('click', function (ev) {
        var b = ev.target.closest ? ev.target.closest('[data-tab]') : null;
        if (b) activate(b.getAttribute('data-tab'), false);
      });
      tabsEl.addEventListener('keydown', function (ev) {
        var i = btns.indexOf(document.activeElement);
        if (i < 0) return;
        if (ev.key === 'ArrowRight' || ev.key === 'ArrowLeft') {
          ev.preventDefault();
          var n = (i + (ev.key === 'ArrowRight' ? 1 : btns.length - 1)) % btns.length;
          activate(btns[n].getAttribute('data-tab'), true);
        }
      });
      /* in-panel jumps (e.g. Candidacy → Record) */
      root.addEventListener('click', function (ev) {
        var g = ev.target.closest ? ev.target.closest('[data-goto-tab]') : null;
        if (g) activate(g.getAttribute('data-goto-tab'), false);
      });
    }

    /* simulated social / constituent actions */
    root.addEventListener('click', function (ev) {
      var f = ev.target.closest ? ev.target.closest('[data-act]') : null;
      if (f) { var nme = f.getAttribute('data-name'); S().announce(f.getAttribute('data-act') === 'follow' ? 'You now follow ' + nme + ' (demo only).' : 'Message composer opened (demo only).'); return; }
      var mb = ev.target.closest ? ev.target.closest('[data-meeting]') : null;
      if (mb) { S().announce('Meeting request drafted for ' + mb.getAttribute('data-meeting') + '. (Simulated.)'); return; }
      var sb = ev.target.closest ? ev.target.closest('[data-send-msg]') : null;
      if (sb) {
        var ta = root.querySelector('#msg-' + sb.getAttribute('data-send-msg'));
        var to = ta ? ta.getAttribute('data-msg-for') : 'your representative';
        S().announce('Message sent to ' + to + ' (simulated).');
        if (ta) ta.value = '';
        return;
      }
      /* candidacy manage — statement save + two-step withdraw (simulated) */
      if (ev.target.closest && ev.target.closest('[data-cand-save]')) {
        S().announce('Statement saved — the change is appended to the public record (simulated).');
        return;
      }
      var wa = root.querySelector('[data-withdraw-area]');
      if (wa && ev.target.closest && ev.target.closest('[data-cand-withdraw]')) {
        wa.innerHTML = '<span class="cc-small" style="color:var(--gov-fg-strong)">Withdraw from this race? This cannot be undone.</span>' +
          '<button type="button" class="btn btn--danger btn--sm" data-withdraw-yes>Yes, withdraw</button>' +
          '<button type="button" class="btn btn--ghost btn--sm" data-withdraw-no>Keep running</button>';
        return;
      }
      if (wa && ev.target.closest && ev.target.closest('[data-withdraw-yes]')) {
        wa.innerHTML = S().badge('danger', 'Withdrawn — recorded on the public record', 'x') +
          '<span class="citation">withdrawal committed · simulated</span>';
        S().announce('Candidacy withdrawn — recorded permanently on the public record (simulated).');
        return;
      }
      if (wa && ev.target.closest && ev.target.closest('[data-withdraw-no]')) {
        wa.innerHTML = '<button type="button" class="btn btn--secondary btn--sm" data-cand-withdraw>Withdraw candidacy</button>';
        return;
      }
    });

    /* live countdowns inside the Overview panel */
    var reduce = false;
    try { reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) {}
    function update() {
      Array.prototype.forEach.call(root.querySelectorAll('[data-countdown]'), function (n) {
        var txt = countdownText({ kind: n.getAttribute('data-kind'), iso: n.getAttribute('data-countdown') });
        if (txt != null) n.textContent = txt;
      });
    }
    if (!reduce && root.querySelector('[data-countdown]')) tick = setInterval(update, 1000);
  }

  CGA.profileV2 = { render: render, wire: wire };
})();
