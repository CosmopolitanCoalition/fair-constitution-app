/* ============================================================================
   CGA MOCKUPS v2 — shell-v2.js  (the game-layer chrome)
   The sole chrome renderer for v2 pages. Consumes the SHARED v1 foundation
   (CGA.state, CGA.fixtures, CGA.fixtures.v2, CGA.icons, CGA.i18n) and the v1
   token + component CSS — it never forks them. Renders a v2 header, the
   interaction-class / journey / live-room sidebar, a footer, and the v1 demo
   bar extended with the five v2 game scenarios.

   A v2 page is a complete HTML doc with <main id="main"> + window.CGA_PAGE,
   loading (in this order):
     <head>  ../assets/css/colors_and_type.css  ../assets/css/fonts.css
             ../assets/css/mockup.css            assets/css/v2.css
             ../assets/js/demo-state.js
     </body> ../assets/js/fixtures.js   assets/js/fixtures-v2.js
             manifest.js                ../assets/js/icons.js
             ../assets/js/i18n.js       assets/js/shell-v2.js

   Deep links: hrefV2(rel) stays inside mockups/v2/; hrefV1(rel) crosses back
   to the v1 operations site (mockups/) — both carry demo state.
   ============================================================================ */
(function () {
  'use strict';
  var CGA = window.CGA = window.CGA || {};

  var SRC = (document.currentScript && document.currentScript.src) || '';
  /* …/mockups/v2/assets/js/shell-v2.js → ROOT_V1 = …/mockups/ ; ROOT_V2 = …/mockups/v2/ */
  var ROOT_V2 = SRC.replace(/assets\/js\/shell-v2\.js.*$/, '');
  var ROOT_V1 = ROOT_V2.replace(/v2\/$/, '');

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

  /* ------------------------------------------------------------ NAV (v2) */
  var ROOM_VARIANTS = [
    ['committee', 'Committee hearing', 'landmark'],
    ['legislative', 'Legislative session', 'landmark'],
    ['exec', 'Executive committee', 'briefcase'],
    ['board', 'Board meeting', 'building'],
    ['court', 'Court hearing', 'scale'],
    ['forum', 'Candidate forum', 'vote'],
    ['townhall', 'Referendum town hall', 'users'],
    ['group', 'Informal-group meeting', 'users']
  ];

  function renderSidebar() {
    var html = '<details class="sidebar-toggle" open><summary>' + icon('menu', { size: 'sm' }) + ' Menu</summary><div class="sidebar-nav">';

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
      html += '<a class="sidebar-link" href="' + hrefV1(rel) + '">' + icon(iconName, { size: 'sm' }) + esc(label) + ' <span class="v1-tag" title="opens the v1 operations site">v1</span></a>';
    }

    section('Start here');
    linkV2('launchpad', 'Launchpad', 'globe', 'index.html');
    linkV2('today', 'Today', 'home', 'civic/today.html');
    linkV2('my-civic-life', 'My civic life', 'file-text', 'civic/my-civic-life.html');
    endSection();

    section('The Live Civic Room');
    ROOM_VARIANTS.forEach(function (v) {
      var id = 'room-' + v[0];
      var current = PAGE.nav === id;
      html += '<a class="sidebar-link" href="' + hrefV2('shared/live-room.html?variant=' + v[0]) + '"' + (current ? ' aria-current="page"' : '') + '>' + icon(v[2], { size: 'sm' }) + esc(v[1]) + '</a>';
    });
    endSection();

    section('Journeys');
    V2.journeys.forEach(function (j) {
      linkV2('journey-' + j.id, j.title.replace(/^An? /, ''), j.flagship ? 'vote' : 'file-text', 'journeys/journey.html?id=' + j.id);
    });
    endSection();

    section('The economy · Planned');
    linkV2('economy-home', 'The exchange', 'bar-chart', 'economy/economy-home.html');
    linkV2('marketplace', 'Marketplace', 'building', 'economy/marketplace.html');
    linkV2('requests', 'Request board', 'users', 'economy/requests.html');
    linkV2('agreements', 'Agreements', 'file-text', 'economy/agreements.html');
    linkV2('wallet', 'My wallet', 'lock', 'economy/wallet.html');
    linkV2('joint-ledgers', 'Joint ledgers', 'users', 'economy/joint-ledgers.html');
    linkV2('units', 'Units & money', 'sliders', 'economy/units.html');
    linkV2('stipend', 'Civic stipend', 'refresh-cw', 'economy/stipend.html');
    linkV2('treasury', 'Public finance', 'bar-chart', 'economy/treasury.html');
    linkV2('org-settings', 'Org economics', 'building', 'economy/org-settings.html');
    endSection();

    section('People & social');
    linkV2('profile', 'My profile', 'user', 'social/profile.html');
    linkV2('org-profile', 'Organizations', 'building', 'social/org-profile.html');
    linkV2('rep', 'My representative', 'landmark', 'social/rep.html');
    linkV2('social-home', 'Social', 'users', 'social/social-home.html');
    linkV2('groups', 'Informal groups', 'users', 'groups/groups-home.html', 'Stage 3');
    linkV2('legitimacy', 'Reach & legitimacy', 'bar-chart', 'social/legitimacy.html', 'Phase I');
    endSection();

    section('Learn & support');
    linkV2('learn-home', 'Learn', 'graduation-cap', 'learn/learn-home.html');
    linkV2('guides', 'Guides & procedures', 'list-checks', 'learn/guides.html');
    linkV2('video-library', 'Video library', 'play', 'shared/video-player.html');
    linkV2('translation-home', 'Translation status', 'languages', 'translation/translation-home.html');
    linkV2('support-home', 'Help & support', 'life-buoy', 'support/support-home.html');
    linkV2('tickets', 'Tickets', 'ticket', 'support/tickets.html');
    linkV2('report', 'Report an issue', 'flag', 'support/report.html');
    endSection();

    section('Run a node · operator plane');
    linkV2('operator-home', 'Operator home', 'sliders', 'operator/operator-home.html');
    linkV2('operator-setup', 'Set up your node', 'sliders', 'operator/setup.html');
    linkV2('operator-console', 'Operator console', 'landmark', 'operator/console.html');
    linkV2('operator-roles', 'Roles & channels', 'users', 'operator/roles.html');
    linkV2('operator-mesh', 'Mesh & federation', 'globe', 'operator/mesh.html');
    linkV2('operator-dns', 'DNS & certificates', 'globe', 'operator/dns.html');
    linkV2('operator-identity', 'Identity (G-ID)', 'lock', 'operator/identity.html');
    linkV2('operator-moderation', 'Moderation & legal', 'shield', 'operator/moderation.html');
    linkV2('operator-versioning', 'Versions & upgrades', 'refresh-cw', 'operator/versioning.html');
    endSection();

    section('Operations (v1)');
    linkV1('v1 launchpad', 'globe', 'index.html');
    linkV1('Open ballot', 'vote', 'electoral/open-ballot.html');
    linkV1('Session console', 'landmark', 'legislature/session-console.html');
    linkV1('Public records', 'file-text', 'system/public-records.html');
    endSection();

    section('Design contract');
    linkV2('coverage', 'v2 coverage', 'check', 'shared/coverage.html');
    endSection();

    return html + '</div></details>';
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
    return '<a class="wordmark" href="' + hrefV2('index.html') + '">' +
      '<img src="' + ROOT_V1 + 'assets/img/social-square-purple.png" alt="" style="border-radius:var(--radius-sm)" /> ' +
      '<span>World of Statecraft</span> <span class="wordmark-tag">the game layer</span></a>' +
      renderJurSwitcher() + '<span class="header-spacer"></span>' +
      '<label class="demo-control"><span class="visually-hidden">Language</span>' + icon('languages', { size: 'sm' }) +
      '<select class="select" style="inline-size:auto" data-set-locale>' + locales + '</select></label>' +
      '<span class="role-badge" title="Active persona and role">' +
      '<span class="avatar" aria-hidden="true">' + esc(p.initials) + '</span><span>' + esc(p.name) + '</span>' +
      '<span class="citation">' + esc(role.id) + ' · ' + esc(role.shortName) + '</span></span>';
  }

  /* ------------------------------------------------------------- footer */
  function renderFooter() {
    var authJur = BY.jurisdictions[W.instance.authoritativeFor];
    var instanceLine = 'Instance: ' + W.instance.host + ' · authoritative for ' + (authJur ? authJur.name : W.instance.authoritativeFor);
    return '<span class="footer-citation">' + esc(PAGE.citation || 'CGA mockups v2 · the game layer') + '</span>' +
      '<span class="header-spacer"></span>' +
      '<a href="' + hrefV1('shared/accessibility.html') + '">Accessibility</a>' +
      '<a href="' + hrefV2('support/report.html', { ref: PAGE.id || PAGE.nav || 'page' }) + '">' + icon('flag', { size: 'sm' }) + ' Report an issue</a>' +
      '<span class="footer-instance">' + esc(instanceLine) + '</span>' +
      '<span class="audit-chip">Audit #' + W.instance.auditSeq.toLocaleString('en-US') + ' · chained ' + icon('check', { size: 'sm', label: 'verified' }) + '</span>';
  }

  /* ------------------------------------------------------------ demo bar */
  function renderDemoBar() {
    var s = CGA.state.getAll();
    var prior = demoBarEl && demoBarEl.querySelector('.demo-details');
    var open = prior ? prior.open
      : (window.matchMedia ? window.matchMedia('(min-width: 64rem) and (min-height: 34rem)').matches : true);

    var personaOpts = W.personas.map(function (p) {
      return '<option value="' + p.id + '"' + (s.persona === p.id ? ' selected' : '') + '>' + esc(p.name) + (p.standIn ? ' *' : '') + '</option>';
    }).join('');
    var roleOpts = R.roles.map(function (r) {
      return '<option value="' + r.id + '"' + (s.role === r.id ? ' selected' : '') + '>' + r.id + ' · ' + esc(r.name) + '</option>';
    }).join('');
    var jurOpts = W.jurisdictions.map(function (j) {
      return '<option value="' + j.slug + '"' + (s.jurisdiction === j.slug ? ' selected' : '') + '>' + esc(j.name) + ' (' + esc(admLabel(j.admLevel)) + ')</option>';
    }).join('');

    function toggle(key, label) {
      return '<label class="demo-control"><input type="checkbox" data-scenario-flag="' + key + '"' + (s.scenario[key] ? ' checked' : '') + ' /> ' + esc(label) + '</label>';
    }

    return '<details class="demo-details"' + (open ? ' open' : '') + '>' +
      '<summary><span class="demo-bar-label">' + icon('sliders', { size: 'sm' }) + ' Mockup controls — demo only, not part of the application</span>' + icon('chevron-down', { size: 'sm', cls: 'demo-caret' }) + '</summary>' +
      '<div class="demo-controls">' +
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
      '<button type="button" class="btn btn--ghost btn--sm" data-demo-reset style="color:var(--cc-purple-200)">Reset</button>' +
      '</div></details>';
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
  var headerEl, sidebarEl, footerEl, demoBarEl;
  function buildShell() {
    document.body.classList.add('app-shell');
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
    sidebarEl = document.createElement('nav'); sidebarEl.className = 'sidebar'; sidebarEl.setAttribute('aria-label', 'Primary');
    footerEl = document.createElement('footer'); footerEl.className = 'app-footer';
    demoBarEl = document.createElement('section'); demoBarEl.className = 'demo-bar'; demoBarEl.setAttribute('aria-label', 'Mockup controls — demo only');

    var main = document.getElementById('main');
    if (!main) fail('page is missing <main id="main">');
    main.classList.add('main-content');

    document.body.insertBefore(headerEl, main);
    document.body.insertBefore(sidebarEl, main);
    document.body.appendChild(footerEl);
    document.body.appendChild(demoBarEl);

    renderChrome(); wireEvents(); applyI18nAttrs(document); rewriteMainLinks(); wrapTables(); pseudoTransformMain();
  }
  function renderChrome() {
    if (!headerEl) return;
    headerEl.innerHTML = renderHeader();
    sidebarEl.innerHTML = renderSidebar();
    footerEl.innerHTML = renderFooter();
    demoBarEl.innerHTML = renderDemoBar();
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
      var open = document.querySelectorAll('details.popover[open]');
      for (var i = 0; i < open.length; i++) {
        open[i].removeAttribute('open');
        var sum = open[i].querySelector('summary');
        if (sum) sum.focus();
      }
    });
    document.addEventListener('click', function (ev) {
      var open = document.querySelectorAll('details.popover[open]');
      for (var i = 0; i < open.length; i++) if (!open[i].contains(ev.target)) open[i].removeAttribute('open');
    });
    CGA.state.subscribe(function () {
      renderChrome(); applyI18nAttrs(document); rewriteMainLinks(); wrapTables(); pseudoTransformMain();
      document.dispatchEvent(new CustomEvent('cga:v2:rerender'));
    });
  }

  /* -------------------------------------------------------------- expose */
  CGA.shellV2 = {
    ROOT_V1: ROOT_V1, ROOT_V2: ROOT_V2,
    icon: icon, esc: esc, badge: badge, pill: pill, formatPop: formatPop, admLabel: admLabel,
    hrefV1: hrefV1, hrefV2: hrefV2, isBuiltV2: isBuiltV2, plannedFlag: plannedFlag,
    announce: announce, activePersona: activePersona, jurisdictionChain: jurisdictionChain,
    refresh: function () { renderChrome(); applyI18nAttrs(document); rewriteMainLinks(); wrapTables(); pseudoTransformMain(); }
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', buildShell);
  else buildShell();
})();
