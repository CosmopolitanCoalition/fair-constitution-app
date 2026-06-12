/* ============================================================================
   CGA — composables/useAnnounce.js
   Production successor of the mockups' CGA.shell.announce (shell.js):
   writes to the persistent polite live region (#cga-live) that AppShell
   renders before any page content (WCAG 4.1.3 Status Messages).

   Usage:
     const { announce } = useAnnounce();
     announce('Step 2 of 5');

   Plain DOM (not a ref) on purpose: the live region is shell-owned and
   persists across Inertia visits; any component — including ones rendered
   outside the layout tree — may announce.
   ============================================================================ */

export function useAnnounce() {
    function announce(text) {
        const el = typeof document !== 'undefined' && document.getElementById('cga-live');
        if (!el) return;
        el.textContent = '';
        /* swap on the next tick so identical messages re-announce */
        setTimeout(() => {
            el.textContent = String(text ?? '');
        }, 30);
    }

    return { announce };
}

export default useAnnounce;
