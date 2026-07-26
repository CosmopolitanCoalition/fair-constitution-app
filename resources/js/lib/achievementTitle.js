/**
 * Readable titles for achievements whose translation hasn't landed yet.
 *
 * WHY THIS EXISTS. The achievements ledger stores an i18n KEY, not English —
 * `achievement.tour.election` rather than "An election, end to end". That was
 * the right call and shouldn't be undone: the table is write-once and it
 * federates, so an English string written today could never be translated
 * later, on any instance, forever. A cosmetic problem now beats a permanent
 * one.
 *
 * The cost is that until the catalogue is authored, an earned achievement
 * renders as a raw dotted key. Nothing had been earned on either box, so the
 * first person to ever see it would have been someone walking the app — which
 * is the worst possible audience for it.
 *
 * So: resolve the key if a translation exists; otherwise turn it into
 * something a person can read. This covers every key that will ever exist
 * rather than the ones that exist today, which is why it's the safety net
 * even once real titles are authored — nobody has to remember this again when
 * the catalogue grows.
 *
 * Deliberately NOT in resources/js/i18n — that tree belongs to the
 * translation lane. This is a render-site fallback and lives with the other
 * formatters.
 */

/**
 * Does this look like an i18n key rather than a human title?
 *
 * Hyphens are in here deliberately: the journey arcs are named `court-case`,
 * `petition-to-referendum`, `become-a-resident` and so on, so a pattern that
 * only allowed underscores let `achievement.tour.court-case` through as if it
 * were already a human title — and it would have rendered raw on screen.
 * Caught by testing the function rather than trusting it.
 */
function looksLikeKey(value) {
    return typeof value === 'string' && /^[a-z0-9_-]+(\.[a-z0-9_-]+)+$/.test(value.trim());
}

/**
 * Turn `achievement.civ.residency_confirmed` into "Residency confirmed".
 * Drops the leading namespace and any short grouping segment, keeps the part
 * that carries the meaning, and sentence-cases it.
 */
function humanise(key) {
    const parts = key.trim().split('.').filter(Boolean);
    // Drop a leading 'achievement'/'ach' namespace if present.
    if (parts.length > 1 && /^(achievement|ach|achievements)$/.test(parts[0])) parts.shift();
    // The last segment carries the specific meaning; earlier ones group it.
    const last = parts[parts.length - 1] ?? key;
    const words = last.replace(/[_-]+/g, ' ').trim();
    if (!words) return key;
    return words.charAt(0).toUpperCase() + words.slice(1);
}

/**
 * @param {string|null|undefined} raw   the stored title — a key, or legacy English
 * @param {Function|null} t             vue-i18n's t, if the caller has it
 * @returns {string} always something printable
 */
export function achievementTitle(raw, t = null) {
    if (raw === null || raw === undefined || raw === '') return 'Achievement';

    const value = String(raw).trim();
    if (!looksLikeKey(value)) return value; // already a human title

    if (typeof t === 'function') {
        try {
            const translated = t(value);
            // vue-i18n returns the key itself when nothing is registered.
            if (typeof translated === 'string' && translated && translated !== value) {
                return translated;
            }
        } catch {
            /* fall through to humanising — a missing catalogue must never
               throw inside render, which is how the v3 mapper froze. */
        }
    }

    return humanise(value);
}
