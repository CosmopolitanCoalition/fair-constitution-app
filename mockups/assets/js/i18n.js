/* ============================================================================
   CGA MOCKUPS — i18n.js
   Chrome string dictionaries. `en` is complete; es / ar / zh-Hans / hi are
   stubs (switcher entries + sampled chrome) that fall back to en, per the
   build instructions §13. `en-XA` is the pseudo-locale: accented Latin,
   ~35% expansion, bracket markers — demo-bar only, used to catch hardcoded
   strings and truncation. Page body copy ships in English.
   ============================================================================ */
(function () {
  'use strict';
  window.CGA = window.CGA || {};

  var LOCALES = [
    { code: 'en', name: 'English', dir: 'ltr' },
    { code: 'es', name: 'Español', dir: 'ltr' },
    { code: 'ar', name: 'العربية', dir: 'rtl' },
    { code: 'zh-Hans', name: '中文（简体）', dir: 'ltr' },
    { code: 'hi', name: 'हिन्दी', dir: 'ltr' }
    /* en-XA intentionally not listed here: it is a QA tool surfaced in the
       demo bar, not a product language (OPEN_QUESTIONS.md #7). */
  ];

  var STRINGS = {
    en: {
      'app.name': 'A Fair Constitution',
      'app.skip': 'Skip to main content',

      'header.search': 'Search',
      'header.searchHint': 'Global search (mockup — not functional)',
      'header.notifications': 'Notifications',
      'header.language': 'Language',
      'header.jurisdiction': 'Jurisdiction context',
      'header.persona': 'Active persona and role',

      'nav.home': 'Home',
      'nav.civicHome': 'Home',
      'nav.myRecord': 'My record',
      'nav.learn': 'Learn',
      'nav.elections': 'Elections',
      'nav.openBallot': 'Open ballot',
      'nav.rankedBallot': 'Ranked ballot',
      'nav.electionDetail': 'Current elections',
      'nav.results': 'Results',
      'nav.candidacy': 'Stand for office',
      'nav.petitions': 'Petitions',
      'nav.organizations': 'Organizations',
      'nav.orgRegistry': 'Registry',
      'nav.coDetermination': 'Co-determination',
      'nav.jurisdictions': 'Jurisdictions',
      'nav.jurisdictionBrowser': 'Browser',
      'nav.districtMapper': 'District mapper',
      'nav.legislature': 'Legislature',
      'nav.chamber': 'Chamber',
      'nav.session': 'Session',
      'nav.bills': 'Bills',
      'nav.committees': 'Committees',
      'nav.referendums': 'Referendums',
      'nav.emergencyPowers': 'Emergency powers',
      'nav.oversight': 'Oversight',
      'nav.settings': 'Amendable settings',
      'nav.speakerTools': 'Speaker tools',
      'nav.electionBoard': 'Election board',
      'nav.boardConsole': 'Board console',
      'nav.countback': 'Vacancy countback',
      'nav.executive': 'Executive',
      'nav.executiveHome': 'Executive',
      'nav.departments': 'Departments',
      'nav.executiveActions': 'Orders & actions',
      'nav.departmentReporting': 'Department reporting',
      'nav.court': 'Judiciary',
      'nav.judiciaryHome': 'Courts',
      'nav.caseDocket': 'Case docket',
      'nav.challenges': 'Constitutional challenges',
      'nav.advocateConsole': 'Advocate console',
      'nav.jurorView': 'Jury service',
      'nav.system': 'System',
      'nav.publicRecords': 'Public records',
      'nav.auditChain': 'Audit chain',
      'nav.clocks': 'Clocks & triggers',
      'nav.termSync': 'Term lockstep',
      'nav.amendments': 'Amendments',
      'nav.designContract': 'Design contract',
      'nav.launchpad': 'Launchpad',
      'nav.styleguide': 'Style guide',
      'nav.coverage': 'Coverage matrix',
      'nav.ledger': 'Constitutional questions',

      'nav.requires': 'Requires',
      'nav.planned': 'Planned',
      'nav.stage': 'Stage',

      'footer.instance': 'Instance: charlotte.cga.example · authoritative for Charlotte (usa-4-charlotte)',
      'footer.audit': 'Audit #{n} · chained',

      'demo.label': 'Mockup controls — not part of the product',
      'demo.persona': 'Persona',
      'demo.role': 'Role',
      'demo.jurisdiction': 'Jurisdiction',
      'demo.scenario': 'Scenario',
      'demo.election': 'Election',
      'demo.electionApproval': 'approval phase',
      'demo.electionRanked': 'ranked window',
      'demo.electionCertifying': 'certifying',
      'demo.emergency': 'Emergency power active',
      'demo.challenge': 'Challenge window open',
      'demo.quorumFails': 'Quorum fails',
      'demo.bicameral': 'Bicameral mode',
      'demo.countbackFailed': 'Countback failed',
      'demo.restoration': 'Restoration drill',
      'demo.unionDrill': 'Union drill',
      'demo.locale': 'Locale',
      'demo.rtl': 'RTL flip',
      'demo.pseudo': 'Pseudo-locale',
      'demo.openFlow': 'Open this screen’s flow',
      'demo.reset': 'Reset',

      'common.timezoneHint': 'Shown in your timezone (UTC−5 · America/New_York). Stored values are UTC.',
      'common.hardened': 'Hardened · protected by the constitutional test suite',
      'common.asImplemented': 'as implemented',
      'common.notifications.empty': 'No notifications'
    },

    /* --- Stubs: switcher entries + sampled chrome; everything else falls back to en. --- */
    es: {
      'app.name': 'Una Constitución Justa',
      'app.skip': 'Saltar al contenido principal',
      'header.search': 'Buscar',
      'header.notifications': 'Notificaciones',
      'header.language': 'Idioma',
      'nav.home': 'Inicio', 'nav.civicHome': 'Inicio',
      'nav.elections': 'Elecciones', 'nav.openBallot': 'Boleta abierta', 'nav.rankedBallot': 'Boleta preferencial',
      'nav.petitions': 'Peticiones', 'nav.organizations': 'Organizaciones',
      'nav.jurisdictions': 'Jurisdicciones', 'nav.legislature': 'Legislatura',
      'nav.myRecord': 'Mi registro', 'nav.learn': 'Aprender',
      'demo.persona': 'Persona', 'demo.role': 'Rol', 'demo.jurisdiction': 'Jurisdicción', 'demo.locale': 'Idioma'
    },
    ar: {
      'app.name': 'دستور عادل',
      'app.skip': 'تخطّ إلى المحتوى الرئيسي',
      'header.search': 'بحث',
      'header.notifications': 'الإشعارات',
      'header.language': 'اللغة',
      'nav.home': 'الرئيسية', 'nav.civicHome': 'الرئيسية',
      'nav.elections': 'الانتخابات', 'nav.openBallot': 'الاقتراع المفتوح', 'nav.rankedBallot': 'الاقتراع التفضيلي',
      'nav.petitions': 'العرائض', 'nav.organizations': 'المنظمات',
      'nav.jurisdictions': 'الولايات', 'nav.legislature': 'الهيئة التشريعية',
      'nav.myRecord': 'سجلي', 'nav.learn': 'تعلّم',
      'demo.persona': 'الشخصية', 'demo.role': 'الدور', 'demo.jurisdiction': 'الولاية', 'demo.locale': 'اللغة'
    },
    'zh-Hans': {
      'app.name': '公平宪法',
      'app.skip': '跳转到主要内容',
      'header.search': '搜索',
      'header.notifications': '通知',
      'header.language': '语言',
      'nav.home': '首页', 'nav.civicHome': '首页',
      'nav.elections': '选举', 'nav.openBallot': '开放选票', 'nav.rankedBallot': '排序选票',
      'nav.petitions': '请愿', 'nav.organizations': '组织',
      'nav.jurisdictions': '辖区', 'nav.legislature': '立法机构',
      'nav.myRecord': '我的记录', 'nav.learn': '学习',
      'demo.persona': '角色扮演', 'demo.role': '角色', 'demo.jurisdiction': '辖区', 'demo.locale': '语言'
    },
    hi: {
      'app.name': 'एक न्यायसंगत संविधान',
      'app.skip': 'मुख्य सामग्री पर जाएँ',
      'header.search': 'खोजें',
      'header.notifications': 'सूचनाएँ',
      'header.language': 'भाषा',
      'nav.home': 'मुखपृष्ठ', 'nav.civicHome': 'मुखपृष्ठ',
      'nav.elections': 'चुनाव', 'nav.openBallot': 'खुला मतपत्र', 'nav.rankedBallot': 'वरीयता मतपत्र',
      'nav.petitions': 'याचिकाएँ', 'nav.organizations': 'संगठन',
      'nav.jurisdictions': 'क्षेत्राधिकार', 'nav.legislature': 'विधायिका',
      'nav.myRecord': 'मेरा अभिलेख', 'nav.learn': 'सीखें',
      'demo.persona': 'पर्सोना', 'demo.role': 'भूमिका', 'demo.jurisdiction': 'क्षेत्राधिकार', 'demo.locale': 'भाषा'
    }
  };

  /* en-XA pseudo-locale: accent + ~35% pad + bracket markers. Deterministic
     and reversible at the call site (originals cached in data attributes by
     shell.js). ID tokens (R-/WF-/F-/I-/CLK-) and {placeholders} survive. */
  var ACCENT = {
    a: 'á', e: 'é', i: 'í', o: 'ó', u: 'ú', y: 'ý', c: 'ç', n: 'ñ', s: 'š', z: 'ž', g: 'ğ', r: 'ř',
    A: 'Á', E: 'É', I: 'Í', O: 'Ó', U: 'Ú', Y: 'Ý', C: 'Ç', N: 'Ñ', S: 'Š', Z: 'Ž', G: 'Ğ', R: 'Ř'
  };
  var ID_TOKEN = /^(R|WF|F|I|CLK)-[\dA-Z-]*$/;

  function pseudo(str) {
    if (!str) return str;
    var out = String(str).split(/(\{[^}]*\}|\s+)/).map(function (tok) {
      if (!tok || /^\s+$/.test(tok) || tok.charAt(0) === '{' || ID_TOKEN.test(tok)) return tok;
      return tok.split('').map(function (ch) { return ACCENT[ch] || ch; }).join('');
    }).join('');
    var padLen = Math.ceil(out.replace(/\s/g, '').length * 0.35);
    var pad = '';
    while (pad.length < padLen) pad += '·~';
    return '⟦' + out + ' ' + pad.slice(0, padLen) + '⟧';
  }

  function t(key, vars) {
    var locale = (window.CGA.state && window.CGA.state.get('locale')) || 'en';
    var isPseudo = locale === 'en-XA';
    var dict = STRINGS[isPseudo ? 'en' : locale] || STRINGS.en;
    var s = dict[key];
    if (s === undefined) s = STRINGS.en[key];
    if (s === undefined) s = key;
    if (vars) {
      Object.keys(vars).forEach(function (k) { s = s.split('{' + k + '}').join(String(vars[k])); });
    }
    return isPseudo ? pseudo(s) : s;
  }

  window.CGA.i18n = {
    LOCALES: LOCALES,
    strings: STRINGS,
    t: t,
    pseudo: pseudo,
    isPseudoLocale: function () { return (window.CGA.state && window.CGA.state.get('locale')) === 'en-XA'; },
    localeName: function (code) {
      if (code === 'en-XA') return 'Pseudo (en-XA)';
      for (var i = 0; i < LOCALES.length; i++) if (LOCALES[i].code === code) return LOCALES[i].name;
      return code;
    }
  };
})();
