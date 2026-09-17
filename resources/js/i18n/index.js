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

/* THE CATALOGS ARE FETCHED, NEVER BUNDLED (WoS beta, 2026-09-17). This file
   once inlined every locale through an eager import.meta.glob: with 76
   languages that was 160 MB of JSON in the client bundle, the production build
   ran out of heap and every visitor would have downloaded the whole world's
   catalogs to read one. scripts/i18n/bundle_locales.mjs now writes ONE static
   file per locale, public/i18n/<code>.json (the root chrome dict with every
   namespace merged on top, the same shape mergeNamespaces used to build), and
   loadLocale() fetches it the first time a locale is needed. Every registered
   locale is still seeded as an empty message set, so availableLocales gates
   exactly as before; the messages arrive when the bundle lands.

   The Vite plugin in vite.config.js runs the bundler at build and dev-server
   start (and on catalog changes in dev), so nothing here depends on the build
   tool: this module stays importable outside Vite. */
const BUNDLE_BASE = '/i18n';

const loaded = new Set();
const loading = new Map();

/** The bundle url for a locale. en-XA carries no dict: it reads en. */
export function localeBundleUrl(code) {
    const c = code === 'en-XA' ? 'en' : code;
    return `${BUNDLE_BASE}/${encodeURIComponent(c)}.json`;
}

/* Early seeded curricula used c_education.learn.*. Keep those stored display
   keys readable without rewriting an existing world's records. */
function withLegacyAliases(messages) {
    if (messages && messages.c_learn) {
        messages.c_education ??= {};
        for (const [key, value] of Object.entries(messages.c_learn)) {
            messages.c_education['learn.' + key] ??= value;
        }
    }
    return messages;
}

/** True once a locale's bundle has been merged into the i18n instance. */
export function localeLoaded(code) {
    return loaded.has(code === 'en-XA' ? 'en' : code);
}

/**
 * Fetch and install a locale's bundle (idempotent, one request per locale).
 * Resolves false when the bundle cannot be fetched: the locale then renders
 * through the English fallback, exactly as an untranslated locale always did,
 * and the failure is logged, never thrown, so a page still mounts.
 */
export async function loadLocale(code, { fetcher } = {}) {
    const c = code === 'en-XA' ? 'en' : code;
    if (!c || loaded.has(c)) return true;
    if (loading.has(c)) return loading.get(c);
    const doFetch = fetcher ?? (typeof fetch === 'function' ? fetch : null);
    if (!doFetch) return false;
    const task = (async () => {
        try {
            const res = await doFetch(localeBundleUrl(c), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const messages = withLegacyAliases(await res.json());
            i18n.global.setLocaleMessage(c, messages);
            loaded.add(c);
            return true;
        } catch (error) {
            console.error(`i18n: could not load the ${c} catalog`, error);
            return false;
        } finally {
            loading.delete(c);
        }
    })();
    loading.set(c, task);
    return task;
}

/** Switch the active locale, loading its bundle first. */
export async function setLocale(code) {
    await loadLocale(code);
    i18n.global.locale.value = code;
    return code;
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
    /* Every registered locale is seeded EMPTY so availableLocales lists it
       (app.js gates the initial locale on that list); loadLocale() fills it. */
    messages: Object.fromEntries([...REGISTRY.map((l) => [l.code, {}]), ['en-XA', {}]]),
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

/* Persist a locale choice the way the F-IND-002 settings panel does. An
   authenticated viewer files it through POST /civic/record/profile (the SAME
   endpoint MyRecord.vue posts to), so the user row updates and SetLocale
   resolves that locale on the next request. Server-rendered PHP strings then
   follow the choice, which a runtime-only i18n.locale change never did.

   A guest records the choice server-side through POST /locale (LocaleController),
   which stores it in the session under the key SetLocale reads ('locale'). The
   next request then resolves the guest into that locale, so the choice survives
   a full reload — the read-back that used to be missing. The choice is also
   mirrored to localStorage for an instant client boot. The `router` is injected
   so both paths stay unit-testable without a live Inertia router; when it is
   absent (a pure unit test or a headless boot) only localStorage is written.
   The `storage` is injected for the same reason. Returns the branch taken. */
export const LOCALE_STORAGE_KEY = 'cga.locale';
export function persistLocale(code, { authenticated = false, router = null, storage } = {}) {
    if (authenticated && router && typeof router.post === 'function') {
        router.post('/civic/record/profile', { locale: code }, { preserveScroll: true });
        return 'endpoint';
    }
    /* Guest: record the choice server-side so SetLocale resolves it on the next
       request (survives a full reload), then mirror it to localStorage. */
    if (router && typeof router.post === 'function') {
        router.post('/locale', { locale: code }, { preserveScroll: true, preserveState: true });
    }
    const store = storage !== undefined ? storage : (typeof localStorage !== 'undefined' ? localStorage : null);
    try {
        store?.setItem(LOCALE_STORAGE_KEY, code);
        return 'storage';
    } catch {
        return 'noop';
    }
}

export default i18n;
