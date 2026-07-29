/* ============================================================================
   CGA — lib/roles.js

   Plain-language labels for the derived roles (R-01..R-04 are never stored —
   they are derived server-side per request; the rest are seated/appointed).
   Extracted from Layouts/AppShell.vue (v1) when the V2 shell restored the
   role label on the user chip (V3 synthesis S4) so the two shells share one
   map instead of drifting copies.

   The v3 idiom (plain-language chrome): the CHIP shows the plain label only
   ("Voter"); the R-xx code belongs in the tooltip / Learn layer, not in the
   player chrome. Unknown ids fall back to the id itself — never invent a
   label for a role this file does not know.
   ============================================================================ */

/* Names follow the constitutional roles chart (docs/ roles_forms_chart)
   verbatim; only the judges drop the chart's "(Appointed)/(Elected)"
   parenthetical — the chip is plain chrome, the distinction lives in the
   Learn layer. */
export const ROLE_LABELS = {
    'R-00': 'Visitor',
    'R-01': 'Individual',
    'R-02': 'Resident',
    'R-03': 'Jurisdictionally Associated',
    'R-04': 'Voter',
    'R-05': 'Petitioner',
    'R-08': 'Election Board Member',
    'R-09': 'Legislative Representative',
    'R-10': 'Speaker of the Legislature',
    'R-14': 'Executive Committee Member',
    'R-19': 'Judge',
    'R-20': 'Judge',
    'R-21': 'Advocate',
    'R-22': 'Juror',
};

/** Highest-numbered role the user holds, with its plain label.
 *  @param {string[]} roles  e.g. ['R-00','R-02','R-04']
 *  @returns {{ id: string, label: string }} */
export function highestRole(roles) {
    const sorted = [...(roles ?? [])].sort(
        (a, b) => (parseInt(a.slice(2), 10) || 0) - (parseInt(b.slice(2), 10) || 0),
    );
    const id = sorted[sorted.length - 1] ?? 'R-00';
    return { id, label: ROLE_LABELS[id] ?? id };
}
