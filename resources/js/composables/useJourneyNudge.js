/* ============================================================================
   CGA — composables/useJourneyNudge.js  (mockups-v3-wiring Phase 3c)

   The SOFT-GATE rule, as code: a journey NUDGES a first-time visitor toward
   the guided arc — it NEVER blocks the real surface. The banner this drives
   must always be dismissible, and the page behind it must work identically
   whether the journey was taken, dismissed, or ignored. Nothing here (and
   nothing anywhere in the journeys engine) gates a capability: a medal never
   changes a vote, a seat, or what you are allowed to do.

   show === true when BOTH hold:
     - localStorage 'cga-journey-nudge:{id}' is unset (never dismissed here), and
     - the optional shared page prop `journeysCompleted` (array of journey ids;
       read defensively, defaults to []) does not contain the id.

   dismiss() sets the localStorage key (per-browser, deliberately not synced —
   a nudge is UI state, not civic state).
   ============================================================================ */
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';

export function useJourneyNudge(journeyId) {
    const key = `cga-journey-nudge:${journeyId}`;

    const dismissed = ref(false);
    try {
        dismissed.value = localStorage.getItem(key) !== null;
    } catch {
        /* private mode — the nudge just shows each visit */
    }

    const page = usePage();
    const completed = computed(() => {
        const list = page.props.journeysCompleted;
        return Array.isArray(list) ? list : [];
    });

    const show = computed(() => !dismissed.value && !completed.value.includes(journeyId));

    function dismiss() {
        try {
            localStorage.setItem(key, '1');
        } catch {
            /* private mode — dismiss still hides it for this page view */
        }
        dismissed.value = true;
    }

    return { show, dismiss, href: `/journeys/${journeyId}` };
}
