/* ============================================================================
   CGA — composables/useTour.js  (Phase 1, MASTER_PLAN)

   Tour-as-a-MODE (operator-settled 2026-07-02, ported from mockups/v3
   shell-v2.js): the guided tour is a session state, not a set of pages.

     • Entering any URL with ?step=N arms the mode for the session
       (sessionStorage 'cga:tour-step') and pins the position to stop N.
     • Navigating anywhere WITHOUT ?step keeps the mode on: if the new page
       IS a registered stop, the tour follows you there (your position moves);
       if it isn't a stop, you keep your place and the bar stays.
     • Exit clears the mode and strips ?step from the URL.

   Stops come from registry/surfaces.js TOUR — the single machine source the
   menu and coverage also read. Back/Next are plain links carrying ?step=N so
   a tour position is always shareable/bookmarkable.
   ============================================================================ */
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { TOUR } from '@/registry/surfaces.js';

const KEY = 'cga:tour-step';

/* Module-level so every consumer (bar, menu, pages) shares one mode state. */
const index = ref(-1);

function ssGet() {
    try { return parseInt(sessionStorage.getItem(KEY) || '', 10); } catch { return NaN; }
}
function ssSet(oneBased) {
    try { sessionStorage.setItem(KEY, String(oneBased)); } catch { /* private mode */ }
}
function ssClear() {
    try { sessionStorage.removeItem(KEY); } catch { /* private mode */ }
}

function stopMatchesUrl(stop, url) {
    const [path, query = ''] = String(url).split('?');
    const [stopPath, stopQuery = ''] = stop.href.split('?');
    if (path !== stopPath) return false;
    const want = new URLSearchParams(stopQuery);
    const have = new URLSearchParams(query);
    for (const [k, v] of want) if (have.get(k) !== v) return false;
    return true;
}

/* Resolve the current tour index for a given Inertia URL (path + query). */
function resolveIndex(url) {
    const query = String(url).split('?')[1] || '';
    const step = new URLSearchParams(query).get('step');
    if (step) {
        const i = parseInt(step, 10) - 1;
        if (i >= 0 && i < TOUR.length) { ssSet(i + 1); return i; }
        return -1;
    }
    const stored = ssGet();
    if (!(stored >= 1 && stored <= TOUR.length)) return -1;
    /* wandered onto another screen: if it's a stop, the tour follows you */
    for (let k = 0; k < TOUR.length; k++) {
        if (stopMatchesUrl(TOUR[k], url)) { ssSet(k + 1); return k; }
    }
    return stored - 1; /* not a stop — keep your place */
}

export function tourHref(i) {
    const stop = TOUR[i];
    if (!stop) return '/';
    return stop.href + (stop.href.includes('?') ? '&' : '?') + 'step=' + (i + 1);
}

export function useTour() {
    const page = usePage();

    const sync = () => { index.value = resolveIndex(page.url ?? '/'); };
    sync();
    /* Inertia keeps one page object alive across visits; re-resolve on every
       successful navigation (fires for the initial visit too in SSR-less apps). */
    router.on('navigate', sync);

    const active = computed(() => index.value >= 0);
    const stop = computed(() => (index.value >= 0 ? TOUR[index.value] : null));
    const stepNumber = computed(() => index.value + 1);
    const total = TOUR.length;
    const progressPct = computed(() => (active.value ? Math.round(stepNumber.value / total * 100) : 0));
    const backHref = computed(() => (index.value > 0 ? tourHref(index.value - 1) : null));
    const nextHref = computed(() => (index.value >= 0 && index.value < total - 1 ? tourHref(index.value + 1) : null));

    function exit() {
        ssClear();
        index.value = -1;
        /* strip ?step from the current URL without a page reload */
        const [path, query = ''] = String(page.url ?? '/').split('?');
        const params = new URLSearchParams(query);
        if (params.has('step')) {
            params.delete('step');
            const qs = params.toString();
            router.visit(path + (qs ? '?' + qs : ''), { replace: true, preserveScroll: true, preserveState: true });
        }
    }

    return { active, stop, stepNumber, total, progressPct, backHref, nextHref, exit, tourHref };
}
