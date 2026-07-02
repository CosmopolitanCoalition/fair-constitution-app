/* ============================================================================
   CGA — lib/plain.js  (Phase 1, MASTER_PLAN)

   The plain-language helpers, ported verbatim from mockups/v3 shell-v2.js.
   The player chrome speaks plain language; codes and citations live in the
   Learn drawer and the machinery drawers only.
   ============================================================================ */

/** Strip machine codes (R-/F-/I-/CLK-/WF-, Art./§) from a label, keeping the
 *  plain-language remainder. Never feed the result to a machine — display only. */
export function plainCodes(s) {
    return String(s == null ? '' : s)
        .replace(/\bImplied by\s+/gi, '')
        .replace(/\(?\b[RIF]-[A-Z0-9]{2,3}(?:-\d{3})?\)?/g, '') /* role / form / institution codes */
        .replace(/\b(?:CLK|WF)-[A-Z0-9-]{2,7}/g, '') /* clock / workflow codes */
        .replace(/\bArt\.\s*[IVX]+/g, '') /* article numbers */
        .replace(/§\s*\d+/g, '') /* section numbers */
        .replace(/\(([^()]*)\)/g, '$1') /* unwrap a remaining plain-language gloss */
        .replace(/\(\s*\)/g, '') /* drop any empty parens */
        .replace(/\s*[;,]\s*(?=[;,]|$)/g, '') /* drop dangling separators */
        .replace(/^[\s;,/·]+|[\s;,/·]+$/g, '') /* trim stray leading/trailing separators */
        .replace(/\s{2,}/g, ' ')
        .trim();
}

/** Humanize a raw entity-state token for player display: an explicit map
 *  wins; otherwise strip machine punctuation ([Brackets], pipes,
 *  hyphen-chains). Verbatim port of shell-v2.js plainState. */
export function plainState(s, map) {
    s = String(s == null ? '' : s).trim();
    if (map && Object.prototype.hasOwnProperty.call(map, s)) return map[s];
    return s
        .replace(/[[\]]/g, '')
        .replace(/\s*\|\s*/g, ' / ')
        .replace(/(\w)-(\w)/g, '$1 $2')
        .replace(/\s{2,}/g, ' ')
        .trim();
}
