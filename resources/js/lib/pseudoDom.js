/* ============================================================================
   CGA — lib/pseudoDom.js  (V3 synthesis S9)

   Whole-DOM pseudo-localization parity with the mockup shell
   (shell-v2.js pseudoTransformMain).

   WHY. The en-XA pseudo-locale exercises truncation/overflow before a real
   translation exists — but vue-i18n's postTranslation hook only reaches
   strings routed through t(). Server data (names, titles, statuses shipped
   as props) rendered straight into templates escapes the padding, so a
   pseudo-locale pass reports "fits" on exactly the strings most likely to
   grow. The mockup shell walks EVERY text node in <main>; this is that walk
   for the live app.

   HOW IT COEXISTS WITH VUE. Text nodes carry two expandos: __cgaOrig (what
   the app rendered) and __cgaOut (what we wrote). On each walk, a node whose
   current value differs from __cgaOut was re-rendered by Vue since we last
   touched it — its current value becomes the new original. That makes the
   walk idempotent and safe to re-run on every mutation; Vue clobbering our
   text is expected and simply re-transformed on the next pass.

   Skips (mockup parity): .citation/.cc-citation (constitutional citations
   stay verbatim), code/kbd, [data-no-i18n], script/style, machine ID tokens
   (R-/WF-/F-/I-/CLK-/M-), and anything already carrying the ⟦…⟧ markers —
   t()-routed strings arrive pre-transformed and must not be double-wrapped.
   ============================================================================ */

import { pseudo } from '@/i18n/index.js';

const SKIP_SELECTOR = '.citation, .cc-citation, code, kbd, .kbd, [data-no-i18n], script, style';
const ID_TOKEN = /^(R|WF|F|I|CLK|M)-?[\dA-Z-]*$/;

/* True while WE are writing — the MutationObserver ignores its own echo. */
let applying = false;

/** One walk over #main: transform every eligible text node (on=true) or
 *  restore the app-rendered originals (on=false). Idempotent. */
export function syncPseudoDom(on) {
    if (typeof document === 'undefined') return;
    const main = document.getElementById('main');
    if (!main) return;

    applying = true;
    try {
        const walker = document.createTreeWalker(main, NodeFilter.SHOW_TEXT, {
            acceptNode(node) {
                if (!node.nodeValue || !node.nodeValue.trim()) return NodeFilter.FILTER_REJECT;
                let el = node.parentElement;
                while (el && el !== main) {
                    if (el.matches(SKIP_SELECTOR)) return NodeFilter.FILTER_REJECT;
                    el = el.parentElement;
                }
                return NodeFilter.FILTER_ACCEPT;
            },
        });

        let node;
        while ((node = walker.nextNode())) {
            /* Vue re-rendered this node since our last pass → new original. */
            if (node.__cgaOut === undefined || node.nodeValue !== node.__cgaOut) {
                node.__cgaOrig = node.nodeValue;
            }

            if (on) {
                const orig = node.__cgaOrig;
                const out =
                    orig.includes('⟦') || ID_TOKEN.test(orig.trim()) ? orig : pseudo(orig);
                node.__cgaOut = out;
                if (node.nodeValue !== out) node.nodeValue = out;
            } else {
                if (node.__cgaOrig !== undefined && node.nodeValue !== node.__cgaOrig) {
                    node.nodeValue = node.__cgaOrig;
                }
                node.__cgaOut = undefined;
            }
        }
    } finally {
        applying = false;
    }
}

/** Keep the transform alive across renders and Inertia navigations: observe
 *  #main and re-walk (rAF-debounced) whenever the DOM changes while the
 *  pseudo-locale is active. Returns a teardown function.
 *  @param {() => boolean} isOn  reads the live locale state */
export function observePseudoDom(isOn) {
    if (typeof document === 'undefined') return () => {};
    const main = document.getElementById('main');
    if (!main || typeof MutationObserver === 'undefined') return () => {};

    let scheduled = false;
    const observer = new MutationObserver(() => {
        if (applying || !isOn() || scheduled) return;
        scheduled = true;
        requestAnimationFrame(() => {
            scheduled = false;
            if (isOn()) syncPseudoDom(true);
        });
    });
    observer.observe(main, { childList: true, subtree: true, characterData: true });
    return () => observer.disconnect();
}
