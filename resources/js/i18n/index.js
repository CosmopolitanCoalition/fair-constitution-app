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
    const merged = { en: { ...base.en }, es: { ...base.es }, ar: { ...base.ar }, 'zh-Hans': { ...base['zh-Hans'] }, hi: { ...base.hi } };
    for (const path in NS_MODULES) {
        const m = path.match(/\.\/locales\/([^/]+)\/([^/]+)\.json$/);
        if (!m) continue;
        const [, code, ns] = m;
        if (!merged[code]) merged[code] = {};
        merged[code][ns] = { ...(merged[code][ns] || {}), ...(NS_MODULES[path].default ?? NS_MODULES[path]) };
    }
    return merged;
}

export const LOCALES = [
    { code: 'en', name: 'English', dir: 'ltr' },
    { code: 'es', name: 'Español', dir: 'ltr' },
    { code: 'ar', name: 'العربية', dir: 'rtl' },
    { code: 'zh-Hans', name: '中文（简体）', dir: 'ltr' },
    { code: 'hi', name: 'हिन्दी', dir: 'ltr' },
    /* en-XA intentionally not listed: it is a QA tool surfaced in the dev
       bar, not a product language. */
];

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
    /* en-XA carries no dict of its own — everything falls back to en, then
       the postTranslation hook pseudo-localizes the resolved string. */
    fallbackLocale: { 'en-XA': ['en'], default: ['en'] },
    messages: {
        ...mergeNamespaces({ en, es, ar, 'zh-Hans': zhHans, hi }),
        'en-XA': {},
    },
    missingWarn: false,
    fallbackWarn: false,
    postTranslation: (translated) =>
        i18n.global.locale.value === 'en-XA' && typeof translated === 'string'
            ? pseudo(translated)
            : translated,
});

export default i18n;
