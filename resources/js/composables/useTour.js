/* ============================================================================
   CGA — composables/useTour.js  (Phase 1, MASTER_PLAN)

   Tour-as-a-MODE (operator-settled 2026-07-02; A2 ruling 2026-07-29): the
   guided tour is a session state, not a set of pages, and EVERY page is a
   valid stop. Two independent facts drive the chrome:

     • armed  — is the tour MODE on? (session flag 'cga:tour-on')
     • index  — is the CURRENT url a registered stop, and which one? (-1 = no)

   Arming is DECOUPLED from position, which is what makes "the current page is
   always a valid stop" true:

     • The nav "Guided tour" control TOGGLES the mode ARMED IN PLACE — it does
       not navigate. arm() flips the flag on the page you are already on; if
       that page is a registered stop the bar names it, if it isn't the bar
       still rides (you are exploring off the marked trail, Back/Next return
       you to it). Toggle again to exit.
     • Entering any URL with ?step=N arms the mode AND pins the position to
       stop N (shareable/bookmarkable deep-link; Back/Next carry ?step=N).
     • Navigating while armed WITHOUT ?step keeps the mode on: a registered
       stop moves your position there; a non-stop keeps your last position as
       the Back/Next anchor and shows the page's own title.
     • Exit clears the mode and strips ?step from the URL.

   All decisions live in the pure reducer composables/tourMode.js (pinned by
   tests/js/tourMode.test.mjs). This file is only the reactive + sessionStorage
   shell. Stops come from registry/surfaces.js TOUR — the single machine source
   the menu and coverage also read.
   ============================================================================ */
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { TOUR } from '@/registry/surfaces.js';
import { resolveTour, tourHrefFor } from '@/composables/tourMode.js';

const KEY = 'cga:tour-step'; /* 1-based anchor — the last registered stop */
const FLAG = 'cga:tour-on'; /* '1' while the mode is armed (position-independent) */

/* Module-level so every consumer (bar, menu, pages) shares one mode state. */
const armed = ref(false);
const index = ref(-1); /* the current url's registered-stop index, or -1 */
const anchor = ref(0); /* last registered stop reached — the Back/Next base off-trail */

function ssSet(k, v) {
    try { sessionStorage.setItem(k, String(v)); } catch { /* private mode */ }
}
function ssDel(k) {
    try { sessionStorage.removeItem(k); } catch { /* private mode */ }
}

/* Hydrate module state from the session on first load. */
armed.value = (() => { try { return sessionStorage.getItem(FLAG) === '1'; } catch { return false; } })();
anchor.value = (() => {
    let a = NaN;
    try { a = parseInt(sessionStorage.getItem(KEY) || '', 10); } catch { /* private mode */ }
    return a >= 1 && a <= TOUR.length ? a - 1 : 0;
})();

/* Single writer for the module state: run the pure reducer, mirror to the
   session so the mode + anchor survive navigation and reload. */
function apply(url) {
    const next = resolveTour({ armed: armed.value, anchor: anchor.value }, url, TOUR);
    armed.value = next.armed;
    index.value = next.index;
    anchor.value = next.anchor;
    if (next.armed) { ssSet(FLAG, '1'); ssSet(KEY, next.anchor + 1); }
    else { ssSet(FLAG, '0'); ssDel(KEY); }
}

/* Bind the navigate listener ONCE at module scope, reading the url from the
   event payload (no usePage needed, and no per-consumer listener pile-up). */
let navBound = false;
function bindNav() {
    if (navBound) return;
    navBound = true;
    router.on('navigate', (event) => apply(event?.detail?.page?.url ?? '/'));
}

export function tourHref(i) {
    return tourHrefFor(TOUR, i);
}

export function useTour() {
    const page = usePage();

    bindNav();
    /* Inertia keeps one page object alive across visits; resolve now so the
       initial (pre-navigate) paint is correct too. */
    apply(page.url ?? '/');

    const active = computed(() => armed.value);
    /* The Back/Next base: the real position on a stop, else the last anchor. */
    const effective = computed(() => (index.value >= 0 ? index.value : anchor.value));
    const onPath = computed(() => index.value >= 0);
    const stop = computed(() => (index.value >= 0 ? TOUR[index.value] : null));
    /* The current page's own name, for the bar when you are off the trail. */
    const currentTitle = computed(() => page.props?.surface?.title ?? null);
    const stepNumber = computed(() => effective.value + 1);
    const total = TOUR.length;
    const progressPct = computed(() => (armed.value ? Math.round(stepNumber.value / total * 100) : 0));
    const backHref = computed(() => (effective.value > 0 ? tourHref(effective.value - 1) : null));
    const nextHref = computed(() => (effective.value < total - 1 ? tourHref(effective.value + 1) : null));

    /* Arm the mode IN PLACE — no navigation. The page you are on becomes the
       position if it is a registered stop, otherwise you ride off-trail. */
    function arm() {
        armed.value = true; /* seed the reducer's prev.armed so apply() commits it */
        apply(page.url ?? '/');
    }

    function exit() {
        /* Force-disarm directly — NOT via apply(): the current url may still
           carry ?step=N, which the reducer always treats as an arming
           deep-link. Any subsequent navigate (incl. the ?step strip below)
           re-runs apply() with armed already false, so the mode stays off. */
        armed.value = false; index.value = -1;
        ssSet(FLAG, '0'); ssDel(KEY);
        /* strip ?step from the current URL without a page reload */
        const [path, query = ''] = String(page.url ?? '/').split('?');
        const params = new URLSearchParams(query);
        if (params.has('step')) {
            params.delete('step');
            const qs = params.toString();
            router.visit(path + (qs ? '?' + qs : ''), { replace: true, preserveScroll: true, preserveState: true });
        }
    }

    function toggle() {
        if (armed.value) exit();
        else arm();
    }

    return {
        active, onPath, stop, currentTitle, stepNumber, total, progressPct,
        backHref, nextHref, arm, exit, toggle, tourHref,
    };
}
