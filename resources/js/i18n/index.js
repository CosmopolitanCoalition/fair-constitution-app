/* ============================================================================
   CGA — i18n/index.js
   vue-i18n instance (legacy: false), chrome-only scope in Phase A (§C6):
   nav, header, footer, and shared-component labels. Page body copy ships
   literal English — same posture as the mockups.

   NOT registered in app.js yet — the AppShell layout work item does
   `app.use(i18n)` when the shell lands.

   `en-XA` is the pseudo-locale QA tool (dev bar only, never a product
   language): accented Latin, ~35% word-chunk expansion, bracket markers —
   catches hardcoded strings and truncation. Implemented as a postTranslation
   hook over the en dict, ported verbatim from mockups/assets/js/i18n.js.
   ============================================================================ */

import { createI18n } from 'vue-i18n';
import en from './en.json';
import es from './es.json';
import ar from './ar.json';
import zhHans from './zh-Hans.json';
import hi from './hi.json';

/* Phase F — per-namespace, per-locale message files (locales/<code>/<ns>.json)
   merged ON TOP of the monolithic chrome dicts above. Page/body translations
   live here (one file per surface namespace) so they extend without colliding
   with the chrome files. The monolithic files remain the chrome base
   (app/nav/header/footer/demo/common). */
const NS_MODULES = import.meta.glob('./locales/*/*.json', { eager: true });

function mergeNamespaces(base) {
    /* Seed EVERY registered code, not the five this once hardcoded. app.js gates
       the initial locale on `availableLocales.includes(...)`, so a locale absent
       here is silently discarded and the user renders English regardless of their
       stored preference — invisibly, because missingWarn/fallbackWarn are false.
       An empty object is enough to make the locale "available"; its messages fall
       back to en until a catalog exists. */
    const merged = {};
    for (const l of ALL_LOCALES) merged[l.code] = { ...(base[l.code] ?? {}) };
    for (const path in NS_MODULES) {
        const m = path.match(/\.\/locales\/([^/]+)\/([^/]+)\.json$/);
        if (!m) continue;
        const [, code, ns] = m;
        if (!merged[code]) merged[code] = {};
        merged[code][ns] = { ...(merged[code][ns] || {}), ...(NS_MODULES[path].default ?? NS_MODULES[path]) };
    }
    return merged;
}

/* THE locale registry now lives in ONE generated place — scripts/i18n/languages.py
   emits both this and config/locales.php, which is what ends the five-way drift
   (this file, SetLocale::SUPPORTED, MyRecordController, app.blade.php's RTL set,
   and Register.vue's hardcoded array).

   LOCALES keeps its historical shape — {code, name, dir} — so every existing
   consumer (the switcher, applyDir) works untouched; `name` is the ENDONYM,
   because a language picker that lists languages in English is useless to the
   person who needs it. `enabled` is the switcher's filter: a registered locale
   with no catalog yet is known to the app but not offered as a choice.

   en-XA is still deliberately absent: it is the pseudo-locale QA tool surfaced
   in the dev bar, never a product language. */
import { LOCALES as REGISTRY, pluralRules, ENABLED } from './locales.generated.js';

export const LOCALES = REGISTRY
    .filter((l) => l.enabled)
    .map((l) => ({ code: l.code, name: l.endonym, dir: l.dir }));

/** Every registered locale, including display-only ones (name + direction). */
export const ALL_LOCALES = REGISTRY;
export { pluralRules, ENABLED };

/* en-XA pseudo-locale: accent + ~35% pad + bracket markers. Deterministic.
   ID tokens (R-/WF-/F-/I-/CLK-) and {placeholders} survive untouched. */
const ACCENT = {
    a: 'á', e: 'é', i: 'í', o: 'ó', u: 'ú', y: 'ý', c: 'ç', n: 'ñ', s: 'š', z: 'ž', g: 'ğ', r: 'ř',
    A: 'Á', E: 'É', I: 'Í', O: 'Ó', U: 'Ú', Y: 'Ý', C: 'Ç', N: 'Ñ', S: 'Š', Z: 'Ž', G: 'Ğ', R: 'Ř',
};
const ID_TOKEN = /^(R|WF|F|I|CLK)-[\dA-Z-]*$/;

export function pseudo(str) {
    if (!str) return str;
    const out = String(str)
        .split(/(\{[^}]*\}|\s+)/)
        .map((tok) => {
            if (!tok || /^\s+$/.test(tok) || tok.charAt(0) === '{' || ID_TOKEN.test(tok)) return tok;
            return tok
                .split('')
                .map((ch) => ACCENT[ch] || ch)
                .join('');
        })
        .join('');
    const padLen = Math.ceil(out.replace(/\s/g, '').length * 0.35);
    let pad = '';
    /* pad in word-sized chunks (space-separated) so the expansion wraps the
       way real translations do — an unbreakable pad run would manufacture
       fake overflow instead of testing real truncation */
    while (pad.replace(/\s/g, '').length < padLen) pad += '·~·~ ';
    return '⟦' + out + ' ' + pad.trim() + '⟧';
}

export const i18n = createI18n({
    legacy: false,
    locale: 'en',
    /* The per-namespace catalogs (locales/<code>/<ns>.json) are FLAT maps of
       `group.snake_key` -> string. vue-i18n's default resolver walks nested
       objects only, so WITHOUT this flag every dotted key inside a namespace
       resolves to nothing and t() returns the raw key — proven empirically
       against vue-i18n 11 (K-2 wiring, 2026-07-28: all three test lookups
       came back unresolved until flatJson). Nobody had noticed because no
       component consumed a namespace catalog until the Learn flyout did. */
    flatJson: true,
    /* en-XA carries no dict of its own — everything falls back to en, then
       the postTranslation hook pseudo-localizes the resolved string. */
    fallbackLocale: { 'en-XA': ['en'], default: ['en'] },
    messages: {
        ...mergeNamespaces({ en, es, ar, 'zh-Hans': zhHans, hi }),
        'en-XA': {},
    },
    /* CLDR plural selectors, generated per locale. vue-i18n's built-in selector
       resolves at most three forms; Arabic has six. Without this an Arabic
       plural renders the wrong branch at runtime while a form-counting check
       reports it as correct — silent, because the warnings below are off. */
    pluralRules,
    missingWarn: false,
    fallbackWarn: false,
    postTranslation: (translated) =>
        i18n.global.locale.value === 'en-XA' && typeof translated === 'string'
            ? pseudo(translated)
            : translated,
});

export default i18n;
