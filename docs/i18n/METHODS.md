# How the app is translated and how that is checked

This page states what was done to the app's text, by what machinery, and what the machine checks do and do not prove. It is written for volunteers who read one of the languages and for observers who want to know how far to trust a screen. The companion table of grades per language is [TRANSLATION_STATUS.md](TRANSLATION_STATUS.md).

## The short version

- English is the source. Every user-visible string is catalogued: about 16,400 interface strings in 73 namespace files under `resources/js/i18n/locales/en/`, and about 2,300 server lines (error replies, validation, notices) in `lang/en.json`.
- 75 other languages carry a machine draft of every string: the six UN languages, Polish, Italian, Turkish, and every language on the Cosmopolitan Coalition website's translation programme. All 75 are enabled in the app's language switcher.
- The drafts were produced by an open model, Gemma 4 (31B), constrained by a 52-term constitutional glossary and gated by an automatic quality check on every string. A separate reviewer model then read a stratified sample of each language and graded it. No human has yet read most languages end to end.
- What the machine checks prove: placeholders, tokens and citations intact, the glossary respected, the files compile, every string present. What they do not prove: that a sentence means the right thing to a native reader. That is what the sample grades estimate and the in-app review queue settles.

## 1. Internationalization (i18n): making the text translatable

**Catalogue extraction.** Interface text lives in Vue components as `t('<namespace>.<key>', 'English default')`. `scripts/i18n/extract.mjs` walks the source and writes the English catalogues, one JSON file per namespace; a repo-wide pin refuses any raw string of four or more words that is not catalogued. Server text uses Laravel's `__('English line')`; `php artisan i18n:lang-flatten` collects every such line into `lang/en.json`.

**The registry.** `scripts/i18n/languages.py` is the single source for the language list (code, name, endonym, script, direction, plural family, enabled, target) and generates both `config/locales.php` and `resources/js/i18n/locales.generated.js`. A check refuses stale generated files.

**Locale wiring.** The switcher in the header and the setting on the resident's record persist one preference (a guest's choice is kept in the session). Numbers, dates and lists format through one composable, `useLocaleFormat`, from the active locale, so a switch changes the formatting as well as the words. The document language and direction follow the registry, so right-to-left languages (Arabic, Persian, Hebrew, Urdu, Pashto) flip the layout.

**Placeholders.** Named values reach vue-i18n under `named`; a repo-wide pin (`tests/js/i18nNamedArgs.test.mjs`) refuses the form that renders them blank.

## 2. Localization (l10n): producing the 75 languages

**The glossary.** `resources/js/i18n/glossary/term-base.json` holds 52 constitutional terms (jurisdiction, legislature, supermajority, quorum, residency, ballot, countback, veto window, and so on), each with a context line and a settled rendering in every target language. The renderings were written by the desk session, not by the translation model, and are the first thing a native reader should confirm, because a wrong glossary term repeats in every string that uses it.

**The pipeline.** `scripts/i18n/translate_catalog.py` translates one language: it sends strings to a provider in batches of 40, with the glossary and eight rules in the prompt (keep keys, keep `{placeholders}` and `:tokens`, never translate ID tokens like `F-LEG-017` or citations like `Art. II §2`, plain polite register). Before sending, placeholders, tokens and citations are masked as `[n]` markers and restored afterwards, so the model cannot damage them. A failed batch bisects so one bad string does not take its neighbours with it; a rejected string gets one retry alone. Each chunk is written to the catalogue as soon as it passes, so a run can be killed and resumed. `scripts/i18n/translate_run.py` runs many languages in parallel lanes.

**Providers.** The pipeline can use a local NLLB-200 model, a local or hosted Ollama model, or the Claude API. The 2026-09-16 pass used `gemma4:31b-cloud` through Ollama's hosted service, ten lanes, about seven hours for 74 languages and about $16 of usage credit. Hindi was drafted first as the proof language.

**The QA gate on every string** (`qa()` in `translate_catalog.py`): the reply must not be empty; placeholders, Laravel tokens, ID tokens and citations must match the source exactly (a grammatical suffix attached to a token is allowed); escaped reserved characters must survive; the length must be plausible for the script, catching runaway or truncated replies; the string must compile under the vue-i18n grammar. A string that fails is not written. It is marked for review with the reason, and the app shows the English for it until a reader supplies a translation.

**Fill-in.** After the main pass, every language was re-run for the strings it still lacked, twice. 4,595 rejected strings went down to 121, all now genuinely hard cases for a reader.

**What a draft is.** Every translated string carries `status: machine` in the meta tree (`resources/js/i18n/meta/<code>/`). Readers confirm or correct drafts on the app's translation board (`/system/translations`); confirmations are recorded per string. A language package (the complete catalogues as a zip, with a README of counts and rules) can be exported from the same board, translated anywhere, and imported back through the same QA gate.

## 3. The reading review (the grades)

A sample of each language was read by a reviewer model (Claude Opus 4.8) with a second model set to refute or confirm each finding:

- the conference languages (UN six plus Polish, Italian, Turkish): 100 strings each;
- the next 20 languages by speakers: 30 strings each;
- the other 45: 10 strings each, first reader only.

Each sample is a third short, a third medium, a third long strings, drawn across the interface namespaces and the server lines, from strings that actually changed. Readers judge meaning, glossary adherence, placeholders, untranslated text, grammar and register, and supply a corrected string for every finding. Confirmed corrections are written back to the catalogues. The grade per language is in the status table; the full per-string findings are in the audit report under `docs/audits/`.

A grade is an estimate from a sample. A C grade on a 10-string sample means the model read poorly for that language and a native reader is needed before trusting it; it does not mean the language is missing.

## 4. Accessibility (a11y): what the machine checks

Every route of the app is swept in a real browser (`tests/browser/accessibility.test.mjs`, Playwright on Edge) at desktop width and at 375 px:

- axe-core against WCAG 2.1 AA, colour contrast included;
- no horizontal overflow at 375 px;
- a Tab-order walk asserting a visible focus indicator and an accessible name on every focusable element;
- the learn drawer and the language switcher keyboard-operable; `html lang` and `dir` following the selected locale; the video player exposing captions and audio alternatives.

The route list is derived from the framework's own route table, never a hand-picked list, and pinned so an added or removed route breaks the pin. On 2026-09-15 the sweep passed 152 of 152 signed-in routes and 55 of 55 guest routes (`docs/audits/2026-09-15/`). What it does not do is a manual screen-reader walkthrough; that remains human work.

## 5. What "machine-derived and machine-tested" means here

The bound on i18n, l10n and a11y is set by automated checks that run on every string and every route. They catch the errors machines are good at catching: a missing string, a broken placeholder, a contrast failure, an unlabeled button. They do not catch a sentence that is fluent and wrong. For that the app has two mechanisms, the sampled reading review that produced the grades, and the in-app review queue where readers of each language settle strings one by one. If you read one of these languages, the queue is where your help counts.
