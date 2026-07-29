/* ============================================================================
   CGA — composables/useDemoMode.js  (V3 synthesis §3, D6 — the mode plumbing)

   THE one client-side answer to "is this world in Demo mode?", so every
   walkthrough affordance flips on the same switch instead of growing its
   own. First consumer: CaseLifecycle's Back/Advance walkthrough cursor
   (`:interactive="isDemoMode"` at its page call-sites); D4/D5's panels
   join it as they land.

   WORLD-KEYED, NEVER BUILD-KEYED. Demo mode is the founding choice
   (instance_settings.game_mode = sandbox), shared to every page as
   `instance.sandbox` by HandleInertiaRequests. It is NOT import.meta.env.DEV
   — that leaks dev affordances into any dev build of a production world
   (the exact pattern nav.js's registry comment warns about, and the same
   axis the server rules with: RULED 2026-07-28 §10 item 4, the sandbox
   setup choice is the switch).

   A CLARITY gate, not a security one: everything an affordance unlocks
   must still talk to server endpoints that carry their own server gates
   (DevToolsEnabled / DevTimeControlsEnabled). This composable only decides
   what is WORTH SHOWING; the 404s remain the boundary.
   ============================================================================ */
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

export function useDemoMode() {
    const page = usePage();

    /** True only when THIS WORLD was founded in Demo (sandbox) mode. */
    const isDemoMode = computed(() => page.props?.instance?.sandbox === true);

    return { isDemoMode };
}
