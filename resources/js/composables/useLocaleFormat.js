/* ============================================================================
   CGA — composables/useLocaleFormat.js

   Locale-aware Intl formatting. The app renders numbers, dates and times
   with the active application locale (vue-i18n) instead of the browser
   default, so grouping separators and date order follow the chosen
   language, not whatever the viewer's browser reports.

   Return shape: { number(n, opts), date(d, opts), dateTime(d, opts),
   time(d, opts), relative(d, base) }. Every method delegates to the native
   Intl / toLocale* path with the same options, so output is byte-identical
   to the prior bare call for a viewer whose locale matches the browser
   default (the English viewer: en-US grouping and date formats are
   unchanged).

   `useLocaleFormat()` reads the reactive locale from vue-i18n. Outside a
   component (unit tests, plain modules) `useI18n()` is unavailable; the
   composable falls back to the browser default locale so those callers keep
   working. `localeFormat(locale)` is the plain helper for non-component
   modules: it takes an explicit locale string (or null / undefined for the
   browser default).

   en-XA is the pseudo-locale QA tool, not a real BCP-47 tag. Intl throws or
   ignores it, so it is normalised to en for every formatter here (the same
   `locale === 'en-XA' ? 'en' : locale` guard the call sites used before).
   ============================================================================ */

import { useI18n } from 'vue-i18n';

/** Normalise an app locale for Intl. en-XA (pseudo-locale) maps to en. */
export function normalizeLocale(locale) {
    if (locale == null || locale === '') return undefined; // browser default
    return locale === 'en-XA' ? 'en' : locale;
}

function toDate(value) {
    return value instanceof Date ? value : new Date(value);
}

/* Descending unit table for relative-time selection (seconds per unit). */
const RELATIVE_UNITS = [
    ['year', 31536000],
    ['month', 2592000],
    ['week', 604800],
    ['day', 86400],
    ['hour', 3600],
    ['minute', 60],
    ['second', 1],
];

/**
 * Plain formatter factory for non-component code.
 * @param {string|null|undefined} locale resolved app locale, or null/undefined for the browser default.
 */
export function localeFormat(locale) {
    const loc = normalizeLocale(locale);
    return {
        /** Group-and-format a number with the active locale. */
        number(n, opts) {
            return Number(n).toLocaleString(loc, opts);
        },
        /** Date-only format (delegates to toLocaleDateString). */
        date(d, opts) {
            return toDate(d).toLocaleDateString(loc, opts);
        },
        /** Date-and-time format (delegates to toLocaleString). */
        dateTime(d, opts) {
            return toDate(d).toLocaleString(loc, opts);
        },
        /** Time-only format (delegates to toLocaleTimeString). */
        time(d, opts) {
            return toDate(d).toLocaleTimeString(loc, opts);
        },
        /** Relative time from `base` (default now) to `d`, e.g. "3 days ago". */
        relative(d, base) {
            const target = toDate(d).getTime();
            const from = base == null ? Date.now() : toDate(base).getTime();
            const diffSec = Math.round((target - from) / 1000);
            const abs = Math.abs(diffSec);
            const rtf = new Intl.RelativeTimeFormat(loc, { numeric: 'auto' });
            for (const [unit, secs] of RELATIVE_UNITS) {
                if (abs >= secs || unit === 'second') {
                    return rtf.format(Math.round(diffSec / secs), unit);
                }
            }
            return rtf.format(0, 'second');
        },
    };
}

/**
 * Composable: locale-aware formatters bound to the active vue-i18n locale.
 * Falls back to the browser default when i18n is not installed (tests,
 * non-component call paths). The locale is read at call time, so formatters
 * follow a live locale change.
 */
export function useLocaleFormat() {
    let localeRef = null;
    try {
        localeRef = useI18n().locale;
    } catch (e) {
        localeRef = null; // i18n not installed / called outside setup
    }
    const active = () => (localeRef && localeRef.value != null ? localeRef.value : undefined);
    return {
        number: (n, opts) => localeFormat(active()).number(n, opts),
        date: (d, opts) => localeFormat(active()).date(d, opts),
        dateTime: (d, opts) => localeFormat(active()).dateTime(d, opts),
        time: (d, opts) => localeFormat(active()).time(d, opts),
        relative: (d, base) => localeFormat(active()).relative(d, base),
    };
}

export default useLocaleFormat;
