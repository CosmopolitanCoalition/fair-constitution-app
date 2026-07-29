/* ============================================================================
   CGA — composables/tourMode.js

   The PURE tour-mode reducer — no Vue, no Inertia, no sessionStorage, no
   registry alias. useTour.js is the reactive/session shell around this; this
   file holds the decisions so the A2 "arm in place" ruling can be pinned by a
   plain-node assertion (tests/js/tourMode.test.mjs) with no test harness.

   The reducer is the single source of truth for the two facts that drive the
   tour chrome:
     • armed  — is the mode on?
     • index  — is THIS url a registered stop, and which one? (-1 = no)
   plus `anchor`, the last registered stop reached (the Back/Next base when the
   player wanders off the marked trail).
   ============================================================================ */

/** Does `url` (path + query) satisfy a stop's href (path + every stop param)? */
export function stopMatchesUrl(stop, url) {
    const [path, query = ''] = String(url).split('?');
    const [stopPath, stopQuery = ''] = String(stop.href).split('?');
    if (path !== stopPath) return false;
    const want = new URLSearchParams(stopQuery);
    const have = new URLSearchParams(query);
    for (const [k, v] of want) if (have.get(k) !== v) return false;
    return true;
}

/**
 * Resolve the tour state for a url, given the previous state.
 *
 * @param {{armed: boolean, anchor: number}} prev
 * @param {string} url                 the Inertia url (path + query)
 * @param {Array<{href: string}>} stops the TOUR array
 * @returns {{armed: boolean, index: number, anchor: number}}
 */
export function resolveTour(prev, url, stops) {
    const query = String(url).split('?')[1] || '';
    const step = new URLSearchParams(query).get('step');
    if (step) {
        const i = parseInt(step, 10) - 1;
        if (i >= 0 && i < stops.length) {
            /* a valid ?step deep-link arms AND pins */
            return { armed: true, index: i, anchor: i };
        }
        /* an out-of-range ?step falls through to armed-state resolution */
    }
    if (!prev.armed) return { armed: false, index: -1, anchor: prev.anchor };
    for (let k = 0; k < stops.length; k++) {
        if (stopMatchesUrl(stops[k], url)) {
            return { armed: true, index: k, anchor: k };
        }
    }
    /* armed but off the marked trail — mode stays on, anchor is kept so the
       bar's Back/Next still lead back to the walkthrough. THIS is what makes
       "every page is a stop / arm in place" true: arming never depends on the
       current page being registered. */
    return { armed: true, index: -1, anchor: prev.anchor };
}

/** The deep-link href for stop `i` (arms + pins via ?step=N). */
export function tourHrefFor(stops, i) {
    const stop = stops[i];
    if (!stop) return '/';
    return stop.href + (stop.href.includes('?') ? '&' : '?') + 'step=' + (i + 1);
}
