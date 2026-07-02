/* ============================================================================
   CGA MOCKUPS v2 — fixtures-translation.js
   The translation-support spine: the canonical language registry, the
   modalities a language must cover, the AI-first-round -> community-verified
   workflow, and the per-language coverage matrix. Attaches to
   CGA.fixtures.v2.tr. Loaded after fixtures-v2.js.

   Grounding:
   - languages.py maps 115 official languages across the planet; the marketing
     site (cosmopolitancoalition.org) shipped 77 narration/caption tracks. This
     matrix shows a curated 24 with status; the rest are queued.
   - First round is machine translation (a Haiku tier-1 model + an NLLB tail,
     pluggable), exactly as the WordPress site did. The interface is then
     extensible and community-verified BY THE PEOPLE WHO READ IT in that
     language — verification is gated to interface-language users.
   - Files are keyed {Subject}-{Language}.{ext}; the privacy rail means private
     records never enter the translation pipeline.
   ============================================================================ */
(function () {
  'use strict';
  var CGA = window.CGA = window.CGA || {};
  if (!CGA.fixtures || !CGA.fixtures.v2) throw new Error('fixtures-translation.js: fixtures-v2.js must load first');
  var V2 = CGA.fixtures.v2;

  /* --- the canonical language registry (curated 24 of 115) ------------------ */
  var langs = [
    { code: 'en', name: 'English', native: 'English', dir: 'ltr', source: true },
    { code: 'es', name: 'Spanish', native: 'Español', dir: 'ltr' },
    { code: 'ar', name: 'Arabic', native: 'العربية', dir: 'rtl' },
    { code: 'zh', name: 'Chinese', native: '中文', dir: 'ltr' },
    { code: 'hi', name: 'Hindi', native: 'हिन्दी', dir: 'ltr' },
    { code: 'fr', name: 'French', native: 'Français', dir: 'ltr' },
    { code: 'pt', name: 'Portuguese', native: 'Português', dir: 'ltr' },
    { code: 'ru', name: 'Russian', native: 'Русский', dir: 'ltr' },
    { code: 'ja', name: 'Japanese', native: '日本語', dir: 'ltr' },
    { code: 'de', name: 'German', native: 'Deutsch', dir: 'ltr' },
    { code: 'sw', name: 'Swahili', native: 'Kiswahili', dir: 'ltr' },
    { code: 'bn', name: 'Bengali', native: 'বাংলা', dir: 'ltr' },
    { code: 'id', name: 'Indonesian', native: 'Bahasa Indonesia', dir: 'ltr' },
    { code: 'ur', name: 'Urdu', native: 'اردو', dir: 'rtl' },
    { code: 'fa', name: 'Persian', native: 'فارسی', dir: 'rtl' },
    { code: 'ko', name: 'Korean', native: '한국어', dir: 'ltr' },
    { code: 'tr', name: 'Turkish', native: 'Türkçe', dir: 'ltr' },
    { code: 'vi', name: 'Vietnamese', native: 'Tiếng Việt', dir: 'ltr' },
    { code: 'it', name: 'Italian', native: 'Italiano', dir: 'ltr' },
    { code: 'pl', name: 'Polish', native: 'Polski', dir: 'ltr' },
    { code: 'uk', name: 'Ukrainian', native: 'Українська', dir: 'ltr' },
    { code: 'ta', name: 'Tamil', native: 'தமிழ்', dir: 'ltr' },
    { code: 'am', name: 'Amharic', native: 'አማርኛ', dir: 'ltr' },
    { code: 'ht', name: 'Haitian Creole', native: 'Kreyòl ayisyen', dir: 'ltr' }
  ];
  var byLang = {}; langs.forEach(function (l) { byLang[l.code] = l; });
  function langName(code) { return byLang[code] ? byLang[code].name : code; }
  function langNative(code) { return byLang[code] ? byLang[code].native : code; }

  /* --- the modalities a language must cover -------------------------------- */
  var modalities = [
    { id: 'ui', label: 'Interface', icon: 'sliders', basis: 'Keyed chrome strings — menus, buttons, labels.', source: 'i18n string dictionary' },
    { id: 'pages', label: 'Page copy', icon: 'file-text', basis: 'The body text on every screen.', source: 'page-folder namespaces' },
    { id: 'audio', label: 'Video audio', icon: 'volume', basis: 'Narration dub per video (.m4a track).', source: '{Subject}-{Language}.m4a' },
    { id: 'captions', label: 'Captions', icon: 'captions', basis: 'Subtitles per video (.vtt track).', source: '{Subject}-{Language}.vtt' },
    { id: 'education', label: 'Education', icon: 'graduation-cap', basis: 'Lesson scripts and knowledge checks.', source: 'learn module content' },
    { id: 'help', label: 'Help & guides', icon: 'help-circle', basis: 'SOPs, guides, and ticket templates.', source: 'sop + support copy' }
  ];

  /* --- the lifecycle a translation moves through --------------------------- */
  var states = [
    { id: 'none', label: 'Not started', tone: 'idle', order: 0, desc: 'No translation exists yet.' },
    { id: 'ai_draft', label: 'AI draft', tone: 'info', order: 1, desc: 'Machine first round — awaiting human eyes.' },
    { id: 'in_review', label: 'In review', tone: 'warn', order: 2, desc: 'Community is reviewing and correcting.' },
    { id: 'verified', label: 'Community-verified', tone: 'good', order: 3, desc: 'Verified by readers of this language.' },
    { id: 'published', label: 'Published', tone: 'live', order: 4, desc: 'Live in the interface.' }
  ];
  var byState = {}; states.forEach(function (s) { byState[s.id] = s; });

  /* --- per-language coverage profiles --------------------------------------
     A profile assigns a state + a completion % per modality, so the matrix
     tells an honest story: mature languages are mostly published; new ones are
     a fresh AI draft. Deterministic — stable for QA. */
  var PROFILES = {
    source:  { ui: ['published', 100], pages: ['published', 100], audio: ['published', 100], captions: ['published', 100], education: ['published', 100], help: ['published', 100] },
    mature:  { ui: ['published', 100], pages: ['published', 96], audio: ['verified', 88], captions: ['published', 98], education: ['verified', 90], help: ['verified', 84] },
    active:  { ui: ['published', 100], pages: ['verified', 82], audio: ['in_review', 54], captions: ['verified', 86], education: ['in_review', 60], help: ['in_review', 58] },
    growing: { ui: ['verified', 88], pages: ['in_review', 46], audio: ['ai_draft', 20], captions: ['in_review', 52], education: ['ai_draft', 28], help: ['ai_draft', 24] },
    seeded:  { ui: ['in_review', 40], pages: ['ai_draft', 18], audio: ['none', 0], captions: ['ai_draft', 22], education: ['none', 0], help: ['ai_draft', 12] },
    fresh:   { ui: ['ai_draft', 15], pages: ['ai_draft', 8], audio: ['none', 0], captions: ['none', 0], education: ['none', 0], help: ['none', 0] }
  };
  var LANG_PROFILE = {
    en: 'source',
    es: 'mature', fr: 'mature', ar: 'mature', zh: 'mature', pt: 'mature', hi: 'mature',
    ru: 'active', de: 'active', ja: 'active', sw: 'active', id: 'active', ko: 'active',
    tr: 'growing', vi: 'growing', it: 'growing', bn: 'growing', uk: 'growing',
    ur: 'seeded', fa: 'seeded', pl: 'seeded', ta: 'seeded',
    am: 'fresh', ht: 'fresh'
  };
  var VERIFIERS = { source: 0, mature: 31, active: 14, growing: 6, seeded: 2, fresh: 1 };

  function rowFor(code) {
    var prof = LANG_PROFILE[code] || 'fresh';
    var cells = {}, sum = 0;
    modalities.forEach(function (m) {
      var spec = PROFILES[prof][m.id];
      cells[m.id] = { state: spec[0], pct: spec[1], verifiers: spec[0] === 'none' ? 0 : VERIFIERS[prof] };
      sum += spec[1];
    });
    return { code: code, profile: prof, cells: cells, overall: Math.round(sum / modalities.length), verifiers: VERIFIERS[prof] };
  }
  var matrix = langs.map(function (l) { return rowFor(l.code); });
  var byRow = {}; matrix.forEach(function (r) { byRow[r.code] = r; });

  /* --- sample review queue (the language-detail workflow demo) -------------- */
  /* A handful of UI strings with their machine first draft, for a few languages;
     other languages fall back to a templated placeholder so the workflow reads
     the same everywhere. */
  var DRAFTS = {
    'nav.elections':   { es: 'Elecciones', fr: 'Élections', ar: 'الانتخابات', zh: '选举', de: 'Wahlen' },
    'header.search':   { es: 'Buscar', fr: 'Rechercher', ar: 'بحث', zh: '搜索', de: 'Suchen' },
    'nav.legislature': { es: 'Legislatura', fr: 'Législature', ar: 'الهيئة التشريعية', zh: '立法机构', de: 'Legislative' },
    'demo.role':       { es: 'Rol', fr: 'Rôle', ar: 'الدور', zh: '角色', de: 'Rolle' }
  };
  var SOURCE_STRINGS = {
    'nav.elections': 'Elections', 'header.search': 'Search', 'nav.legislature': 'Legislature', 'demo.role': 'Role'
  };
  function reviewQueue(code) {
    return Object.keys(SOURCE_STRINGS).map(function (key, i) {
      var d = DRAFTS[key] && DRAFTS[key][code];
      var stateSeq = ['verified', 'in_review', 'ai_draft', 'ai_draft'];
      return {
        key: key, source: SOURCE_STRINGS[key],
        draft: d || (code === 'en' ? SOURCE_STRINGS[key] : '[' + langName(code) + ' machine draft pending review]'),
        state: code === 'en' ? 'published' : stateSeq[i % stateSeq.length],
        verifiers: code === 'en' ? 0 : (i % 3), needed: 3
      };
    });
  }

  /* --- contributors (language-detail leaderboard) --------------------------- */
  var contributors = [
    { handle: 'pier7', verified: 412, role: 'Lead verifier' },
    { handle: 'amaru', verified: 168, role: 'Verifier' },
    { handle: 'noor', verified: 91, role: 'Verifier' },
    { handle: 'kenji', verified: 47, role: 'Reviewer' }
  ];

  /* --- the AI engine + the add-a-language flow ------------------------------ */
  var engine = {
    label: 'Hybrid machine engine — pluggable',
    tier1: 'Claude Haiku (tier-1 languages)',
    tail: 'NLLB-200 (the long tail)',
    note: 'The first round is always machine. People never start from a blank box — they correct a draft.',
    privacy: 'Private records never enter the pipeline — a database check forbids it.',
    naming: '{Subject}-{Language}.{ext} — the same convention as the existing toolchain.'
  };
  var addLanguageSteps = [
    { do: 'Pick the language', detail: 'Choose from the 115 mapped languages, or request a new one.' },
    { do: 'Generate the first round', detail: 'The machine writes a first draft of everything at once — people never start from a blank box.' },
    { do: 'Open it for review', detail: 'Readers of that language see the drafts and begin verifying.' },
    { do: 'Publish on quorum', detail: 'Strings auto-publish once enough verifications land and QA is clean.' }
  ];

  /* the languages a person knows — collected at account creation, they default
     the interface and weight that person's verifications. */
  var myLangs = [
    { code: 'en', native: 'English', fluency: 'Native' },
    { code: 'es', native: 'Español', fluency: 'Fluent' }
  ];
  /* contributions awaiting a verifier who reads the language */
  var pendingContributions = [
    { id: 'tc1', lang: 'es', surface: 'The public square', original: 'Report an issue', machine: 'Reportar un problema', by: 'machine' },
    { id: 'tc2', lang: 'es', surface: 'Your wallet', original: 'Send a transfer', machine: 'Enviar una transferencia', by: '@u-greenwood' },
    { id: 'tc3', lang: 'fr', surface: 'A live room', original: 'Take the floor', machine: 'Prendre la parole', by: 'machine' }
  ];

  V2.tr = {
    langs: langs, byLang: byLang, langName: langName, langNative: langNative,
    modalities: modalities, states: states, byState: byState,
    matrix: matrix, byRow: byRow, rowFor: rowFor,
    reviewQueue: reviewQueue, contributors: contributors,
    engine: engine, addLanguageSteps: addLanguageSteps,
    myLangs: myLangs, pendingContributions: pendingContributions,
    totals: { mapped: 115, shipped: 77, curated: langs.length },
    /* shared so the video player can name tracks without loading the full matrix */
    expose: function () { V2.langName = langName; V2.langNative = langNative; V2.byLang = byLang; }
  };
  V2.tr.expose();
})();
