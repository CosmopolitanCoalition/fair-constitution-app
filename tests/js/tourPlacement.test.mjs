/* ============================================================================
   PIN — tour ENTRY PLACEMENT (L6W4 item ④).

   A stranger who clicks "Start the tour" must land on the /tour INDEX, where
   they can see the shape of the walk — stops, acts, the first-visit track —
   and choose. They must NOT be dropped straight into stop 1 with the mode
   already armed. The one place that legitimately arms stop 1 is the index's
   OWN Start button, because by then the player has seen what they are starting.

   This is the placement half of the A2 tour ruling; [tourMode.test.mjs] pins
   the reducer half (the nav item is a TOGGLE that arms in place and never
   navigates — that is MenuNav's `tour:start` sentinel, deliberately NOT a
   /tour link, and this pin does not touch it).

   The invariant that carries all of it: `tourStartHref()` — the "stop 1, mode
   armed" deep-link builder — is imported by EXACTLY ONE page, Tour/Index.vue.
   Any other page importing it is a page that jumps the index.

   No test harness in this repo — run with plain node:

       node tests/js/tourPlacement.test.mjs

   Exit 0 = all pass; exit 1 = a pin broke.
   ============================================================================ */
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = fileURLToPath(new URL('../../', import.meta.url));
const PAGES = join(ROOT, 'resources', 'js', 'Pages');

/* The ONE page allowed to build the armed stop-1 deep link. */
const ARMS_STOP_ONE = join('Tour', 'Index.vue');

let failed = 0;
function ok(cond, label) {
    if (cond) {
        console.log(`  ok   ${label}`);
    } else {
        console.log(`  FAIL ${label}`);
        failed += 1;
    }
}

function vueFiles(dir) {
    const out = [];
    for (const entry of readdirSync(dir)) {
        const full = join(dir, entry);
        if (statSync(full).isDirectory()) out.push(...vueFiles(full));
        else if (entry.endsWith('.vue')) out.push(full);
    }
    return out;
}

const files = vueFiles(PAGES);
ok(files.length > 50, `found the Pages tree (${files.length} .vue files)`);

/* 1. tourStartHref is imported by exactly one page — the /tour index. */
const importers = files
    .filter((f) => /\btourStartHref\b/.test(readFileSync(f, 'utf8')))
    .map((f) => relative(PAGES, f));

ok(
    importers.length === 1 && importers[0].split(sep).join(sep) === ARMS_STOP_ONE,
    `only Tour/Index.vue builds the armed stop-1 link (found: ${importers.join(', ') || 'none'})`,
);

/* 2. The index really does still arm stop 1 — so pin 1 cannot be satisfied by
      deleting the builder outright and leaving no way into the tour at all. */
const indexSrc = readFileSync(join(PAGES, 'Tour', 'Index.vue'), 'utf8');
ok(/:href="tourStartHref\(\)"/.test(indexSrc), 'the /tour index Start button arms stop 1');

/* 3. The two cover pages send their tour CTA to the index. */
for (const page of ['Home.vue', join('Launchpad.vue')]) {
    const src = readFileSync(join(PAGES, page), 'utf8');
    ok(/href="\/tour"/.test(src), `${page} tour CTA points at the /tour index`);
    ok(!/step=1/.test(src), `${page} carries no stop-1 deep link`);
}

/* 4. No page anywhere hard-codes an armed stop-1 URL to sneak past pin 1. */
const hardCoders = files
    .filter((f) => /href="\/[^"]*[?&]step=1"/.test(readFileSync(f, 'utf8')))
    .map((f) => relative(PAGES, f));
ok(hardCoders.length === 0, `no page hard-codes an armed ?step=1 link (found: ${hardCoders.join(', ') || 'none'})`);

console.log(failed ? `\n${failed} FAILED` : '\nall passed');
process.exit(failed ? 1 : 0);
