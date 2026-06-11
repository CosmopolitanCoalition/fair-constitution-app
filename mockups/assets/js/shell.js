/* ============================================================================
   CGA MOCKUPS — shell.js
   Shared chrome renderer. Each page is a complete HTML document containing
   only its <main id="main"> plus a window.CGA_PAGE config block; this script
   renders the header, role-aware sidebar, footer, and demo bar around it from
   ONE nav-config object — a single source of truth for navigation.

   Load order (every page copies this):
     <head>   assets/js/demo-state.js          (lang/dir before paint)
     </body>  assets/js/fixtures.js
              ../manifest.js                   (window.CGA_MANIFEST)
              assets/js/icons.js
              assets/js/i18n.js
              assets/js/shell.js

   Page-config contract:
     window.CGA_PAGE = {
       id: 'shared/styleguide',     // manifest "file" minus .html
       title: 'Style guide',
       module: 'shared',
       nav: 'styleguide',           // sidebar item id for aria-current
       roles: [], workflows: [], forms: [],
       citation: 'Art. — · …',     // footer citation line (mono)
       flow: null,                  // WF-ID for the demo bar's flow jump
       register: 'governance'       // 'brand' only on index / learn
     };
   ============================================================================ */
(function () {
  'use strict';
  var CGA = window.CGA = window.CGA || {};

  /* ----------------------------------------------------------- bootstrap */
  var SRC = (document.currentScript && document.currentScript.src) || '';
  var ROOT = SRC.replace(/assets\/js\/shell\.js.*$/, ''); // absolute base for all internal hrefs

  function fail(msg) {
    var b = document.createElement('div');
    b.setAttribute('role', 'alert');
    b.style.cssText = 'padding:1rem;font-family:monospace;background:var(--gov-bg,Canvas);color:var(--gov-fg,CanvasText);'; /* token-or-system colors so the banner shows even if CSS failed */
    b.textContent = 'CGA shell error: ' + msg;
    document.body.insertBefore(b, document.body.firstChild);
    throw new Error(msg);
  }
  if (!CGA.state) fail('demo-state.js must load in <head> before shell.js');
  if (!CGA.fixtures) fail('fixtures.js must load before shell.js');
  if (!CGA.icons) fail('icons.js must load before shell.js');
  if (!CGA.i18n) fail('i18n.js must load before shell.js');

  var F = CGA.fixtures, R = F.registry, W = F.world, BY = F.byId;
  var t = CGA.i18n.t;
  var PAGE = window.CGA_PAGE || { id: null, title: document.title, module: null, nav: null, roles: [], workflows: [], forms: [], citation: '', flow: null, register: 'governance' };

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

  function formatPop(n) {
    if (n == null) return '—';
    if (n >= 1e9) return (n / 1e9).toFixed(1) + 'B';
    if (n >= 1e6) return (n / 1e6).toFixed(1) + 'M';
    return Number(n).toLocaleString('en-US');
  }

  function citation(text, opts) {
    opts = opts || {};
    var html = '<span class="citation">' + esc(text);
    if (opts.implemented) {
      html += ' · <a href="' + href('shared/constitutional-questions.html' + (opts.anchor ? '#' + opts.anchor : '')) + '">' + esc(t('common.asImplemented')) + '</a>';
    }
    return html + '</span>';
  }

  function badge(tone, label, iconName) {
    return '<span class="badge badge--' + tone + '">' + (iconName ? icon(iconName, { size: 'sm' }) : '') + esc(label) + '</span>';
  }

  /* Convert a step-screen params object ({role, sc}) into demo-state link overrides. */
  function paramsToOverrides(params) {
    var o = {};
    if (!params) return o;
    if (params.role) {
      o.role = params.role;
      var role = BY.roles[params.role];
      if (role && role.defaultPersona) o.persona = role.defaultPersona;
    }
    if (params.persona) o.persona = params.persona;
    if (params.jur) o.jurisdiction = params.jur;
    if (params.sc) o.scenario = params.sc;
    return o;
  }

  /* Internal link carrying demo state. All hrefs route through here. */
  function href(rel, overrides) {
    return CGA.state.link(ROOT + rel, overrides || {});
  }

  /* ----------------------------------------------- built-file detection */
  var builtFiles = {};
  (window.CGA_MANIFEST || []).forEach(function (rec) { builtFiles[rec.file] = true; });

  var FOLDER_STAGE = { civic: 1, electoral: 2, legislature: 3, executive: 4, organizations: 4, judiciary: 5, jurisdictions: 6, system: 6, shared: 0, flows: null };

  function stageOf(rel) {
    var file = rel.split('?')[0];
    if (file.indexOf('flows/') === 0) {
      var wf = BY.workflows[file.replace('flows/', '').replace('.html', '')];
      return wf ? wf.stage : null;
    }
    if (file === 'shared/clocks.html') return 6; /* §9.8 deliverable despite living in shared/ */
    var folder = file.split('/')[0];
    return FOLDER_STAGE.hasOwnProperty(folder) ? FOLDER_STAGE[folder] : null;
  }

  function isBuilt(rel) { return !!builtFiles[rel.split('?')[0]]; }

  function plannedFlag(rel) {
    var s = stageOf(rel);
    return '<span class="planned-flag">' + esc(t('nav.planned')) + (s ? ' · ' + esc(t('nav.stage')) + ' ' + s : '') + '</span>';
  }

  /* ------------------------------------------------------------ NAV config
     The single source of truth. visibility 'all' or 'role' (+roles list).
     Items: enabledRoles gates interaction for the active persona's role set;
     prereq names the role cited in the disabled hint. */
  var NAV = [
    { key: 'home', titleKey: 'nav.home', visibility: 'all', items: [
      { id: 'civic-home', labelKey: 'nav.civicHome', icon: 'home', rel: 'civic/civic-home.html' },
      { id: 'my-record', labelKey: 'nav.myRecord', icon: 'file-text', rel: 'civic/my-record.html' },
      { id: 'learn', labelKey: 'nav.learn', icon: 'book-open', rel: 'civic/learn.html' }
    ] },
    { key: 'elections', titleKey: 'nav.elections', visibility: 'all', items: [
      { id: 'open-ballot', labelKey: 'nav.openBallot', icon: 'vote', rel: 'electoral/open-ballot.html' },
      { id: 'ranked-ballot', labelKey: 'nav.rankedBallot', icon: 'check', rel: 'electoral/ranked-ballot.html' },
      { id: 'election-detail', labelKey: 'nav.electionDetail', icon: 'clock', rel: 'electoral/election-detail.html' },
      { id: 'results', labelKey: 'nav.results', icon: 'bar-chart', rel: 'electoral/results.html' },
      { id: 'candidacy', labelKey: 'nav.candidacy', icon: 'user', rel: 'electoral/candidacy-registration.html' }
    ] },
    { key: 'petitions', titleKey: 'nav.petitions', visibility: 'all', items: [
      { id: 'petitions', labelKey: 'nav.petitions', icon: 'file-text', rel: 'civic/petitions.html' }
    ] },
    { key: 'organizations', titleKey: 'nav.organizations', visibility: 'all', items: [
      { id: 'org-registry', labelKey: 'nav.orgRegistry', icon: 'building', rel: 'organizations/org-registry.html' },
      { id: 'co-determination', labelKey: 'nav.coDetermination', icon: 'users', rel: 'organizations/co-determination.html' }
    ] },
    { key: 'jurisdictions', titleKey: 'nav.jurisdictions', visibility: 'all', items: [
      { id: 'jurisdiction-browser', labelKey: 'nav.jurisdictionBrowser', icon: 'globe', rel: 'jurisdictions/jurisdiction-browser.html' },
      { id: 'district-mapper', labelKey: 'nav.districtMapper', icon: 'map', rel: 'jurisdictions/district-mapper.html' }
    ] },
    { key: 'legislature', titleKey: 'nav.legislature', visibility: 'role', roles: ['R-09', 'R-10', 'R-11', 'R-12', 'R-13', 'R-29'], items: [
      { id: 'legislature-home', labelKey: 'nav.chamber', icon: 'landmark', rel: 'legislature/legislature-home.html', enabledRoles: ['R-09', 'R-10', 'R-11', 'R-12', 'R-13', 'R-29'], prereq: 'R-09' },
      { id: 'session-console', labelKey: 'nav.session', icon: 'users', rel: 'legislature/session-console.html', enabledRoles: ['R-09', 'R-10'], prereq: 'R-09' },
      { id: 'bills', labelKey: 'nav.bills', icon: 'file-text', rel: 'legislature/bills.html', enabledRoles: ['R-09', 'R-10', 'R-11', 'R-12', 'R-13'], prereq: 'R-09' },
      { id: 'committees', labelKey: 'nav.committees', icon: 'users', rel: 'legislature/committees.html', enabledRoles: ['R-09', 'R-10', 'R-11', 'R-12', 'R-13'], prereq: 'R-09' },
      { id: 'referendums', labelKey: 'nav.referendums', icon: 'vote', rel: 'legislature/referendums.html', enabledRoles: ['R-09', 'R-10'], prereq: 'R-09' },
      { id: 'emergency-powers', labelKey: 'nav.emergencyPowers', icon: 'alert-triangle', rel: 'legislature/emergency-powers.html', enabledRoles: ['R-09', 'R-10'], prereq: 'R-09' },
      { id: 'oversight', labelKey: 'nav.oversight', icon: 'shield', rel: 'legislature/oversight.html', enabledRoles: ['R-09', 'R-10', 'R-29'], prereq: 'R-29' },
      { id: 'settings', labelKey: 'nav.settings', icon: 'sliders', rel: 'legislature/settings.html', enabledRoles: ['R-09', 'R-10'], prereq: 'R-09' }
    ] },
    { key: 'speaker', titleKey: 'nav.speakerTools', visibility: 'role', roles: ['R-09', 'R-10'], items: [
      { id: 'speaker-tools', labelKey: 'nav.speakerTools', icon: 'landmark', rel: 'legislature/speaker-tools.html', enabledRoles: ['R-10'], prereq: 'R-10' }
    ] },
    { key: 'electionBoard', titleKey: 'nav.electionBoard', visibility: 'role', roles: ['R-08'], items: [
      { id: 'election-board-console', labelKey: 'nav.boardConsole', icon: 'shield', rel: 'electoral/election-board-console.html', enabledRoles: ['R-08'], prereq: 'R-08' },
      { id: 'vacancy-countback', labelKey: 'nav.countback', icon: 'refresh-cw', rel: 'electoral/vacancy-countback.html', enabledRoles: ['R-08'], prereq: 'R-08' }
    ] },
    { key: 'executive', titleKey: 'nav.executive', visibility: 'role', roles: ['R-14', 'R-15', 'R-16', 'R-17', 'R-18', 'R-30'], items: [
      { id: 'executive-home', labelKey: 'nav.executiveHome', icon: 'briefcase', rel: 'executive/executive-home.html', enabledRoles: ['R-14', 'R-15', 'R-16', 'R-17'], prereq: 'R-14' },
      { id: 'departments', labelKey: 'nav.departments', icon: 'building', rel: 'executive/departments.html', enabledRoles: ['R-14', 'R-15', 'R-16', 'R-30'], prereq: 'R-14' },
      { id: 'executive-actions', labelKey: 'nav.executiveActions', icon: 'file-text', rel: 'executive/executive-actions.html', enabledRoles: ['R-14', 'R-15', 'R-16'], prereq: 'R-14' },
      { id: 'department-reporting', labelKey: 'nav.departmentReporting', icon: 'bar-chart', rel: 'executive/department-reporting.html', enabledRoles: ['R-18'], prereq: 'R-18' }
    ] },
    { key: 'court', titleKey: 'nav.court', visibility: 'role', roles: ['R-19', 'R-20', 'R-21', 'R-22'], items: [
      { id: 'judiciary-home', labelKey: 'nav.judiciaryHome', icon: 'scale', rel: 'judiciary/judiciary-home.html', enabledRoles: ['R-19', 'R-20', 'R-21', 'R-22'], prereq: 'R-19' },
      { id: 'case-docket', labelKey: 'nav.caseDocket', icon: 'file-text', rel: 'judiciary/case-docket.html', enabledRoles: ['R-19', 'R-20', 'R-21'], prereq: 'R-19' },
      { id: 'constitutional-challenge', labelKey: 'nav.challenges', icon: 'scale', rel: 'judiciary/constitutional-challenge.html', enabledRoles: ['R-19', 'R-20', 'R-21'], prereq: 'R-19' },
      { id: 'advocate-console', labelKey: 'nav.advocateConsole', icon: 'briefcase', rel: 'judiciary/advocate-console.html', enabledRoles: ['R-21'], prereq: 'R-21' },
      { id: 'juror-view', labelKey: 'nav.jurorView', icon: 'users', rel: 'judiciary/juror-view.html', enabledRoles: ['R-22'], prereq: 'R-22' }
    ] },
    { key: 'system', titleKey: 'nav.system', visibility: 'all', items: [
      { id: 'setup-wizard', labelKey: 'nav.setupWizard', icon: 'globe', rel: 'system/setup-wizard.html' },
      { id: 'public-records', labelKey: 'nav.publicRecords', icon: 'file-text', rel: 'system/public-records.html' },
      { id: 'audit-chain', labelKey: 'nav.auditChain', icon: 'lock', rel: 'system/audit-chain.html' },
      { id: 'clocks', labelKey: 'nav.clocks', icon: 'clock', rel: 'shared/clocks.html' },
      { id: 'term-sync', labelKey: 'nav.termSync', icon: 'refresh-cw', rel: 'system/term-sync.html' },
      { id: 'amendments', labelKey: 'nav.amendments', icon: 'file-text', rel: 'system/amendments.html' }
    ] },
    { key: 'designContract', titleKey: 'nav.designContract', visibility: 'all', items: [
      { id: 'launchpad', labelKey: 'nav.launchpad', icon: 'globe', rel: 'index.html' },
      { id: 'styleguide', labelKey: 'nav.styleguide', icon: 'sliders', rel: 'shared/styleguide.html' },
      { id: 'coverage', labelKey: 'nav.coverage', icon: 'check', rel: 'shared/coverage.html' },
      { id: 'ledger', labelKey: 'nav.ledger', icon: 'scale', rel: 'shared/constitutional-questions.html' }
    ] }
  ];

  /* ------------------------------------------------------------ persona */
  function activePersona() {
    var s = CGA.state.getAll();
    return BY.personas[s.persona] || W.personas[0];
  }
  function personaRoles() { return activePersona().roles || []; }
  function intersects(a, b) {
    for (var i = 0; i < a.length; i++) if (b.indexOf(a[i]) >= 0) return true;
    return false;
  }

  /* ------------------------------------------------------------- header */
  function jurisdictionChain(slug) {
    var chain = [];
    var j = BY.jurisdictions[slug];
    while (j) { chain.unshift(j); j = j.parent ? BY.jurisdictions[j.parent] : null; }
    return chain;
  }

  /* Natural level labels (the ETL repo's vocabulary). Numeric adm levels are
     development terminology and never display in product UI. */
  var ADM_LABELS = ['Planet', 'Country', 'State / Province', 'County', 'Municipality', 'Township', 'Neighborhood'];
  function admLabel(level) { return ADM_LABELS[Math.min(level, 6)]; }

  function renderChainChips(chain) {
    return chain.map(function (j) {
      var lvl = Math.min(j.admLevel, 5);
      return '<span class="adm-chip adm-chip--' + lvl + '" title="' + esc(admLabel(j.admLevel)) + '">' + esc(j.name) + '</span>';
    }).join('<span class="adm-sep" aria-hidden="true">›</span>');
  }

  function renderJurSwitcher() {
    var s = CGA.state.getAll();
    var chain = jurisdictionChain(s.jurisdiction);
    var roots = W.jurisdictions.filter(function (j) { return j.admLevel === 1; });
    var panel = '<div class="popover-panel">' +
      '<span class="eyebrow">' + esc(t('header.jurisdiction')) + '</span>' +
      '<p class="cosmic-prefix">' + esc('Multiverse · … · Solar System · Earth') + '</p>' +
      '<ul style="list-style:none;padding:0;margin:0">' +
      W.jurisdictions.map(function (j) {
        var lvl = Math.min(j.admLevel, 5);
        return '<li><button type="button" class="btn btn--ghost btn--sm" data-set-jur="' + esc(j.slug) + '" style="inline-size:100%;justify-content:flex-start">' +
          '<span class="tier-dot tier-dot--' + lvl + '" aria-hidden="true"></span> ' + esc(j.name) +
          ' <span class="citation">' + esc(admLabel(j.admLevel)) + (j.note ? ' · ' + esc(j.note) : '') + (j.dataGap ? ' · data gap' : '') + '</span></button></li>';
      }).join('') +
      '</ul>' +
      '<p class="gloss">The full dataset spans planet to neighborhood (~1M jurisdictions); depth varies honestly by country — for the United States the chain ends at the county level.</p>' +
      '</div>';
    return '<details class="popover jur-switcher">' +
      '<summary aria-label="' + esc(t('header.jurisdiction')) + '">' +
      '<span class="cosmic-prefix">… · Solar System · Earth</span>' +
      renderChainChips(chain) +
      icon('chevron-down', { size: 'sm' }) +
      '</summary>' + panel + '</details>';
  }

  function renderHeader() {
    var s = CGA.state.getAll();
    var p = activePersona();
    var role = BY.roles[s.role] || R.roles[0];
    var notifs = W.notifications.map(function (n) {
      var target = isBuilt(n.href)
        ? '<a href="' + href(n.href) + '">' + esc(n.text) + '</a>'
        : esc(n.text) + ' ' + plannedFlag(n.href);
      return '<div class="notif-item">' + icon(n.icon, { size: 'sm' }) + '<span>' + target + '</span></div>';
    }).join('');

    var locales = CGA.i18n.LOCALES.map(function (l) {
      return '<option value="' + l.code + '"' + (s.locale === l.code ? ' selected' : '') + '>' + esc(l.name) + '</option>';
    }).join('') + (s.locale === 'en-XA' ? '<option value="en-XA" selected>Pseudo (en-XA)</option>' : '');

    return '<a class="wordmark" href="' + href('index.html') + '">' +
      '<img src="' + ROOT + 'assets/img/social-square-purple.png" alt="" style="border-radius:var(--radius-sm)" /> ' +
      '<span data-i18n="app.name">' + esc(t('app.name')) + '</span></a>' +
      renderJurSwitcher() +
      '<span class="header-spacer"></span>' +
      '<form class="global-search" role="search" data-search-stub>' +
      icon('search', { size: 'sm' }) +
      '<input type="search" aria-label="' + esc(t('header.searchHint')) + '" placeholder="' + esc(t('header.search')) + '" />' +
      '</form>' +
      '<details class="popover"><summary aria-label="' + esc(t('header.notifications')) + '" style="position:relative">' +
      icon('bell') + '<span class="notif-dot" aria-hidden="true"></span></summary>' +
      '<div class="popover-panel"><span class="eyebrow">' + esc(t('header.notifications')) + '</span>' + (notifs || '<p>' + esc(t('common.notifications.empty')) + '</p>') + '</div></details>' +
      '<label class="demo-control"><span class="visually-hidden">' + esc(t('header.language')) + '</span>' +
      icon('languages', { size: 'sm' }) +
      '<select class="select" style="inline-size:auto" data-set-locale>' + locales + '</select></label>' +
      '<span class="role-badge" title="' + esc(t('header.persona')) + '">' +
      '<span class="avatar" aria-hidden="true">' + esc(p.initials) + '</span>' +
      '<span>' + esc(p.name) + '</span>' +
      '<span class="citation">' + esc(role.id) + ' · ' + esc(role.shortName) + '</span></span>';
  }

  /* ------------------------------------------------------------ sidebar */
  function renderSidebar() {
    var roles = personaRoles();
    var html = '<details class="sidebar-toggle" open><summary>' + icon('menu', { size: 'sm' }) + ' Menu</summary><div class="sidebar-nav">';
    NAV.forEach(function (section) {
      if (section.visibility === 'role' && !intersects(roles, section.roles || [])) return;
      html += '<div class="sidebar-section"><span class="sidebar-title eyebrow" data-i18n="' + section.titleKey + '">' + esc(t(section.titleKey)) + '</span>';
      section.items.forEach(function (item) {
        var label = esc(t(item.labelKey));
        var enabled = !item.enabledRoles || intersects(roles, item.enabledRoles);
        var built = isBuilt(item.rel);
        var current = PAGE.nav === item.id;
        if (!enabled) {
          var prereqRole = BY.roles[item.prereq];
          html += '<span class="sidebar-link sidebar-link--disabled" aria-disabled="true">' + icon(item.icon, { size: 'sm' }) + label + '</span>' +
            '<span class="prereq-hint">' + esc(t('nav.requires')) + ' ' + esc(item.prereq) + (prereqRole ? ' · ' + esc(prereqRole.shortName) : '') + '</span>';
        } else if (!built) {
          html += '<span class="sidebar-link sidebar-link--disabled" aria-disabled="true">' + icon(item.icon, { size: 'sm' }) + label + ' ' + plannedFlag(item.rel) + '</span>';
        } else {
          html += '<a class="sidebar-link" href="' + href(item.rel) + '"' + (current ? ' aria-current="page"' : '') + '>' + icon(item.icon, { size: 'sm' }) + label + '</a>';
        }
      });
      html += '</div>';
    });
    return html + '</div></details>';
  }

  /* ------------------------------------------------------------- footer */
  function renderFooter() {
    var authJur = BY.jurisdictions[W.instance.authoritativeFor];
    var instanceLine = 'Instance: ' + W.instance.host + ' · authoritative for ' +
      (authJur ? authJur.name : W.instance.authoritativeFor) + ' (' + W.instance.authoritativeFor + ')';
    return '<span class="footer-citation">' + esc(PAGE.citation || '') + '</span>' +
      '<span class="header-spacer"></span>' +
      '<span class="footer-instance">' + esc(instanceLine) + '</span>' +
      '<span class="audit-chip">' + esc(t('footer.audit', { n: W.instance.auditSeq.toLocaleString('en-US') })) + ' ' + icon('check', { size: 'sm', label: 'verified' }) + '</span>';
  }

  /* ------------------------------------------------------------ demo bar */
  function renderDemoBar() {
    var s = CGA.state.getAll();

    var personaOpts = W.personas.map(function (p) {
      return '<option value="' + p.id + '"' + (s.persona === p.id ? ' selected' : '') + '>' + esc(p.name) + (p.standIn ? ' *' : '') + '</option>';
    }).join('');

    var roleOpts = R.roles.map(function (r) {
      return '<option value="' + r.id + '"' + (s.role === r.id ? ' selected' : '') + '>' + r.id + ' · ' + esc(r.name) + '</option>';
    }).join('');

    var jurOpts = W.jurisdictions.map(function (j) {
      return '<option value="' + j.slug + '"' + (s.jurisdiction === j.slug ? ' selected' : '') + '>' + esc(j.name) + ' (' + esc(admLabel(j.admLevel)) + ')</option>';
    }).join('');

    var phases = [['approval', t('demo.electionApproval')], ['ranked', t('demo.electionRanked')], ['certifying', t('demo.electionCertifying')]];
    var phaseOpts = phases.map(function (ph) {
      return '<option value="' + ph[0] + '"' + (s.scenario.election === ph[0] ? ' selected' : '') + '>' + esc(ph[1]) + '</option>';
    }).join('');

    function toggle(key, labelKey) {
      return '<label class="demo-control"><input type="checkbox" data-scenario-flag="' + key + '"' + (s.scenario[key] ? ' checked' : '') + ' /> ' + esc(t(labelKey)) + '</label>';
    }

    var flowLink = '';
    if (PAGE.flow) {
      var wf = BY.workflows[PAGE.flow];
      if (wf) {
        flowLink = isBuilt(wf.flowPage)
          ? '<a class="demo-flow-link" href="' + href(wf.flowPage) + '">' + esc(t('demo.openFlow')) + ' · ' + wf.id + '</a>'
          : '<span class="demo-control">' + esc(t('demo.openFlow')) + ' · ' + wf.id + ' ' + plannedFlag(wf.flowPage) + '</span>';
      }
    }

    return '<span class="demo-bar-label">' + esc(t('demo.label')) + '</span>' +
      '<label class="demo-control">' + esc(t('demo.persona')) + ' <select data-set-persona>' + personaOpts + '</select></label>' +
      '<label class="demo-control">' + esc(t('demo.role')) + ' <select data-set-role>' + roleOpts + '</select></label>' +
      '<label class="demo-control">' + esc(t('demo.jurisdiction')) + ' <select data-set-jur-select>' + jurOpts + '</select></label>' +
      '<label class="demo-control">' + esc(t('demo.election')) + ' <select data-scenario-election>' + phaseOpts + '</select></label>' +
      toggle('emergency', 'demo.emergency') +
      toggle('challenge', 'demo.challenge') +
      toggle('quorumFails', 'demo.quorumFails') +
      toggle('bicameral', 'demo.bicameral') +
      toggle('countbackFailed', 'demo.countbackFailed') +
      toggle('restoration', 'demo.restoration') +
      toggle('unionDrill', 'demo.unionDrill') +
      '<label class="demo-control"><input type="checkbox" data-rtl-flip' + (s.dir === 'rtl' ? ' checked' : '') + ' /> ' + esc(t('demo.rtl')) + '</label>' +
      '<label class="demo-control"><input type="checkbox" data-pseudo-toggle' + (s.locale === 'en-XA' ? ' checked' : '') + ' /> ' + esc(t('demo.pseudo')) + '</label>' +
      flowLink +
      '<button type="button" class="btn btn--ghost btn--sm" data-demo-reset style="color:var(--cc-purple-200)">' + esc(t('demo.reset')) + '</button>';
  }

  /* -------------------------------------------------- data-bind + pseudo */
  function resolvePath(ctx, path) {
    var cur = ctx;
    var parts = String(path).split('.');
    for (var i = 0; i < parts.length; i++) {
      if (cur == null) return undefined;
      cur = cur[parts[i]];
    }
    return cur;
  }

  function applyBindings() {
    var s = CGA.state.getAll();
    var ctx = {
      state: s,
      persona: activePersona(),
      role: BY.roles[s.role],
      jurisdiction: BY.jurisdictions[s.jurisdiction],
      instance: W.instance,
      world: W
    };
    var nodes = document.querySelectorAll('[data-bind]');
    for (var i = 0; i < nodes.length; i++) {
      var v = resolvePath(ctx, nodes[i].getAttribute('data-bind'));
      if (v !== undefined) nodes[i].textContent = typeof v === 'number' ? v.toLocaleString('en-US') : String(v);
    }
  }

  var ID_TOKEN = /^(R|WF|F|I|CLK)-[\dA-Z-]*$/;
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
      } else {
        node.nodeValue = node.__cgaOrig;
      }
    }
  }

  function applyI18nAttrs(rootEl) {
    var nodes = (rootEl || document).querySelectorAll('[data-i18n]');
    for (var i = 0; i < nodes.length; i++) nodes[i].textContent = t(nodes[i].getAttribute('data-i18n'));
  }

  /* Rewrite internal links inside <main> so demo state travels on file://. */
  function rewriteMainLinks() {
    var main = document.getElementById('main');
    if (!main) return;
    var anchors = main.querySelectorAll('a[href]');
    for (var i = 0; i < anchors.length; i++) {
      var a = anchors[i];
      var orig = a.getAttribute('data-orig-href') || a.getAttribute('href');
      /* Skip anything with a scheme (http:, https:, FILE:, mailto:, …) or a
         fragment — only bare relative paths get the demo-state rewrite. Links
         already built absolute via CGA.shell.href() carry their state and must
         never be re-prefixed (file:// double-path bug). */
      if (/^([a-z][a-z0-9+.-]*:|#)/i.test(orig)) continue;
      a.setAttribute('data-orig-href', orig);
      /* Resolve against the PAGE's own URL (not the site root) so same-folder
         links like "results.html" inside electoral/ keep their directory. */
      var abs;
      try { abs = new URL(orig, document.baseURI).href; } catch (e) { continue; }
      a.setAttribute('href', CGA.state.link(abs));
    }
  }

  /* ------------------------------------------------------- flow stepper */
  function renderFlowStepper(flow, mount) {
    if (!mount) return;
    var current = 1;
    var terminalNote = null;

    function formChipHtml(step) {
      if (step.form) {
        var f = BY.forms[step.form];
        var label = f ? f.name : step.form;
        return '<span class="form-chip">' + esc(label) + ' <span class="form-id">' + esc(step.form) + '</span></span>';
      }
      if (step.engine) return '<span class="engine-chip">' + esc(step.engine) + '</span>';
      return '';
    }

    function branchHtml(step) {
      if (!step.branches || !step.branches.length) return '';
      return '<div class="flow-branches" role="group" aria-label="Branches">' + step.branches.map(function (b, bi) {
        if (typeof b.goto === 'number') {
          return '<button type="button" class="branch-btn" data-goto-step="' + b.goto + '">' + esc(b.label) + '</button>';
        }
        if (typeof b.goto === 'string' && b.goto.indexOf('terminal:') === 0) {
          return '<button type="button" class="branch-btn" data-goto-terminal="' + esc(b.goto.slice(9)) + '">' + esc(b.label) + '</button>';
        }
        if (b.goto && b.goto.wf) {
          var rel = 'flows/' + b.goto.wf + '.html';
          if (isBuilt(rel)) {
            return '<a class="branch-btn" href="' + href(rel) + '#step-' + (b.goto.step || 1) + '">' + esc(b.label) + ' ' + icon('arrow-right', { size: 'sm' }) + '</a>';
          }
          return '<span class="branch-btn" aria-disabled="true">' + esc(b.label) + ' ' + plannedFlag(rel) + '</span>';
        }
        return '';
      }).join('') + '</div>';
    }

    function render() {
      var entity = BY.entities[flow.entity];
      var head =
        '<div class="card" style="margin-block-end:var(--space-5)">' +
        '<span class="eyebrow">Flow walkthrough</span>' +
        '<h2 style="margin-block:var(--space-1) var(--space-2)">' + esc(flow.name) + ' <span class="citation">' + esc(flow.id) + '</span></h2>' +
        '<div class="cluster">' +
        '<span class="citation">Time scale: ' + esc(flow.timeScale) + '</span>' +
        '<span class="citation">Trigger: ' + esc(flow.trigger) + '</span>' +
        '</div><div class="cluster" style="margin-block-start:var(--space-2)">' +
        (flow.actors || []).map(function (a) { return '<span class="flow-actor">' + esc(a) + '</span>'; }).join('') +
        (flow.institutions || []).map(function (i2) { return '<span class="form-chip"><span class="form-id">' + esc(i2) + '</span></span>'; }).join('') +
        '</div>' +
        '<p style="margin-block-start:var(--space-3)">Terminal: ' + esc(flow.terminal) + '</p>' +
        '<span class="citation">' + esc(flow.basis) + '</span>' +
        '</div>';

      var strip = '';
      if (entity) {
        strip = '<div class="card card--inset" style="margin-block-end:var(--space-5)">' +
          '<span class="eyebrow">' + esc(entity.id) + ' state machine</span>' +
          '<div class="state-strip" style="margin-block-start:var(--space-2)">' +
          entity.states.map(function (st, si) {
            var stepObj = flow.steps[current - 1];
            var isCur = stepObj && stepObj.entityState ? stepObj.entityState === st : false;
            return (si > 0 ? '<span class="state-arrow" aria-hidden="true">→</span>' : '') +
              '<span class="state-node' + (isCur ? ' state-node--current' : '') + '">' + esc(st) + '</span>';
          }).join('') +
          '</div></div>';
      }

      var steps = '<ol class="flow-steps">' + flow.steps.map(function (step) {
        var openLink = '';
        if (step.screen) {
          var rel = step.screen.href;
          var ov = paramsToOverrides(step.screen.params);
          openLink = isBuilt(rel)
            ? '<a class="citation" href="' + href(rel, ov) + '">Open in app ' + icon('arrow-right', { size: 'sm' }) + '</a>'
            : '<span class="citation">Open in app · ' + plannedFlag(rel) + '</span>';
        }
        return '<li class="flow-step' + (step.n === current ? ' flow-step--current' : '') + '" id="step-' + step.n + '">' +
          '<div class="flow-step-head">' +
          '<button type="button" class="btn btn--ghost btn--sm" data-goto-step="' + step.n + '" aria-label="Go to step ' + step.n + '"><span class="flow-step-n">' + step.n + '</span></button>' +
          '<span class="flow-actor">' + esc(step.actor) + '</span>' +
          formChipHtml(step) +
          '</div>' +
          '<p class="flow-action">' + esc(step.action) + '</p>' +
          '<p class="flow-outcome">' + esc(step.outcome) + '</p>' +
          branchHtml(step) +
          (openLink ? '<p style="margin-block-start:var(--space-2)">' + openLink + '</p>' : '') +
          '</li>';
      }).join('') + '</ol>';

      var terminal = terminalNote
        ? '<div class="banner banner--info" role="status">' + icon('check') + '<div><span class="banner-title">Terminal state reached</span>' + esc(terminalNote) + '</div></div>'
        : '';

      mount.innerHTML = head + strip + steps + terminal;
      rewriteMainLinks();
    }

    if (mount.__cgaFlowHandler) mount.removeEventListener('click', mount.__cgaFlowHandler);
    mount.__cgaFlowHandler = function (ev) {
      var el = ev.target.closest ? ev.target.closest('[data-goto-step],[data-goto-terminal]') : null;
      if (!el) return;
      if (el.hasAttribute('data-goto-step')) {
        current = parseInt(el.getAttribute('data-goto-step'), 10) || 1;
        terminalNote = null;
      } else {
        terminalNote = el.getAttribute('data-goto-terminal');
      }
      render();
      var target = document.getElementById('step-' + current);
      if (target) target.scrollIntoView({ block: 'nearest' });
    };
    mount.addEventListener('click', mount.__cgaFlowHandler);

    render();
  }

  /* ------------------------------------------------------------- render */
  var headerEl, sidebarEl, footerEl, demoBarEl;

  function buildShell() {
    document.body.classList.add('app-shell');
    if (PAGE.register === 'brand') document.body.classList.add('register-brand');

    /* icon sprite + skip link first */
    var spriteWrap = document.createElement('div');
    spriteWrap.innerHTML = CGA.icons.spriteMarkup();
    document.body.insertBefore(spriteWrap.firstChild, document.body.firstChild);

    var skip = document.createElement('a');
    skip.className = 'skip-link';
    skip.href = '#main';
    skip.textContent = t('app.skip');
    document.body.insertBefore(skip, document.body.firstChild);

    headerEl = document.createElement('header');
    headerEl.className = 'app-header';

    sidebarEl = document.createElement('nav');
    sidebarEl.className = 'sidebar';
    sidebarEl.setAttribute('aria-label', 'Primary');

    footerEl = document.createElement('footer');
    footerEl.className = 'app-footer';

    demoBarEl = document.createElement('section');
    demoBarEl.className = 'demo-bar';
    demoBarEl.setAttribute('aria-label', t('demo.label'));

    var main = document.getElementById('main');
    if (!main) fail('page is missing <main id="main">');
    main.classList.add('main-content');

    document.body.insertBefore(headerEl, main);
    document.body.insertBefore(sidebarEl, main);
    document.body.appendChild(footerEl);
    document.body.appendChild(demoBarEl);

    renderChrome();
    wireEvents();
    applyBindings();
    applyI18nAttrs(document);
    rewriteMainLinks();
    pseudoTransformMain();
    crossCheckManifest();
  }

  function renderChrome() {
    if (!headerEl) return; /* page scripts may call refresh() before buildShell (DOMContentLoaded) — safe no-op */
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
      }
      else if (el.matches('[data-set-role]')) {
        var role = BY.roles[el.value];
        CGA.state.set({ role: el.value, persona: role && role.defaultPersona ? role.defaultPersona : CGA.state.get('persona') });
      }
      else if (el.matches('[data-set-jur-select]')) CGA.state.set({ jurisdiction: el.value });
      else if (el.matches('[data-scenario-election]')) CGA.state.set({ scenario: { election: el.value } });
      else if (el.matches('[data-scenario-flag]')) {
        var patch = {}; patch[el.getAttribute('data-scenario-flag')] = el.checked;
        CGA.state.set({ scenario: patch });
      }
      else if (el.matches('[data-rtl-flip]')) CGA.state.set({ dir: el.checked ? 'rtl' : 'auto' });
      else if (el.matches('[data-pseudo-toggle]')) CGA.state.set({ locale: el.checked ? 'en-XA' : 'en', dir: 'auto' });
    });

    document.body.addEventListener('click', function (ev) {
      var jurBtn = ev.target.closest ? ev.target.closest('[data-set-jur]') : null;
      if (jurBtn) { CGA.state.set({ jurisdiction: jurBtn.getAttribute('data-set-jur') }); return; }
      if (ev.target.closest && ev.target.closest('[data-demo-reset]')) { CGA.state.reset(); return; }
    });

    document.body.addEventListener('submit', function (ev) {
      if (ev.target.matches && ev.target.matches('[data-search-stub]')) ev.preventDefault();
    });

    CGA.state.subscribe(function () {
      renderChrome();
      applyBindings();
      applyI18nAttrs(document);
      rewriteMainLinks();
      pseudoTransformMain();
    });
  }

  function crossCheckManifest() {
    if (!window.CGA_MANIFEST || !PAGE.id) return;
    var file = PAGE.id + '.html';
    var rec = null;
    for (var i = 0; i < window.CGA_MANIFEST.length; i++) {
      if (window.CGA_MANIFEST[i].file === file) { rec = window.CGA_MANIFEST[i]; break; }
    }
    if (!rec && window.console) console.warn('CGA: page "' + file + '" has no manifest record — add it to manifest.json + manifest.js (QA §15).');
  }

  /* -------------------------------------------------------------- expose */
  CGA.shell = {
    ROOT: ROOT,
    icon: icon,
    t: t,
    esc: esc,
    href: href,
    citation: citation,
    badge: badge,
    formatPop: formatPop,
    admLabel: admLabel,
    isBuilt: isBuilt,
    stageOf: stageOf,
    plannedFlag: plannedFlag,
    jurisdictionChain: jurisdictionChain,
    activePersona: activePersona,
    renderFlowStepper: renderFlowStepper,
    refresh: function () { renderChrome(); applyBindings(); applyI18nAttrs(document); rewriteMainLinks(); pseudoTransformMain(); }
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', buildShell);
  else buildShell();
})();
