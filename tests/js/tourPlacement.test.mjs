/* ============================================================================
   PIN — tour ENTRY PLACEMENT (L6W4 item ④).

   A stranger who clicks "Start the tour" must land on the /tour INDEX, where
   they can see the shape of the walk — stops, acts, the first-visit track —
   and choose. They must NOT be dropped straight into stop 1 with the mode
   already armed. The one place that legitimately arms stop 1 is the index's
   OWN Start button, because by then the player has seen what they are starting.

   This is the placement half of the A2 tour ruling; [tourMode.test.mjs] pins
   the reducer half (the nav item is a TOGGLE that arms in place and never
   navigates — MenuNav's toggle is deliberately NOT a /tour link).

   Home and Launchpad now share ArrivalHub, whose learning entry is /learn.
   Guided journeys live there; the shell's in-place tour toggle and TourBar's
   All steps link retain the separate path to the /tour index.

   The invariant that carries all of it: `tourStartHref()` — the "stop 1, mode
   armed" deep-link builder — is used by EXACTLY ONE page, Tour/Index.vue.
   Neither another page nor a shared component may jump the index.

   No test harness in this repo — run with plain node:

       node tests/js/tourPlacement.test.mjs

   Exit 0 = all pass; exit 1 = a pin broke.
   ============================================================================ */
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = fileURLToPath(new URL('../../', import.meta.url));
const PAGES = join(ROOT, 'resources', 'js', 'Pages');
const COMPONENTS = join(ROOT, 'resources', 'js', 'Components');
const JS = join(ROOT, 'resources', 'js');

/* The ONE page allowed to build the armed stop-1 deep link. */
const ARMS_STOP_ONE = join('Pages', 'Tour', 'Index.vue');

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

const pages = vueFiles(PAGES);
const files = [...pages, ...vueFiles(COMPONENTS)];
ok(pages.length > 50, `found the Pages tree (${pages.length} .vue files)`);

/* 1. tourStartHref is imported by exactly one page — the /tour index. */
const importers = files
    .filter((f) => /\btourStartHref\b/.test(readFileSync(f, 'utf8')))
    .map((f) => relative(JS, f));

ok(
    importers.length === 1 && importers[0].split(sep).join(sep) === ARMS_STOP_ONE,
    `only Tour/Index.vue builds the armed stop-1 link (found: ${importers.join(', ') || 'none'})`,
);

/* 2. The index really does still arm stop 1 — so pin 1 cannot be satisfied by
      deleting the builder outright and leaving no way into the tour at all. */
const indexSrc = readFileSync(join(PAGES, 'Tour', 'Index.vue'), 'utf8');
ok(/:href="tourStartHref\(\)"/.test(indexSrc), 'the /tour index Start button arms stop 1');

/* 3. Both arrival routes render the same learning entrance, without arming a stop. */
for (const page of ['Home.vue', 'Launchpad.vue']) {
    const src = readFileSync(join(PAGES, page), 'utf8');
    ok(/import ArrivalHub from ['"]@\/Components\/Arrival\/ArrivalHub\.vue['"]/.test(src)
        && /<ArrivalHub\s*\/>/.test(src), `${page} renders the shared ArrivalHub`);
    ok(!/step=1/.test(src), `${page} carries no stop-1 deep link`);
}
const arrivalSrc = readFileSync(join(COMPONENTS, 'Arrival', 'ArrivalHub.vue'), 'utf8');
const arrivalHubs = arrivalSrc.match(/const everydayHubs\s*=\s*\[([\s\S]*?)\];/)?.[1] ?? '';
ok(/\{\s*key:\s*'learn',[^}]*href:\s*'\/learn'\s*\}/.test(arrivalHubs)
    && /<Link\s+v-for="hub in everydayHubs"[^>]*:href="destination\(hub\.href\)"/.test(arrivalSrc),
    'ArrivalHub renders its shared Learn entry at /learn');
const learnSrc = readFileSync(join(PAGES, 'Learn', 'LearnHome.vue'), 'utf8');
ok(/<Link\s+href="\/journeys"/.test(learnSrc), 'Learn links to the canonical /journeys directory');

/* The separate guided-tour path remains real: toggle in place, then All steps. */
const menuSrc = readFileSync(join(COMPONENTS, 'ShellV2', 'MenuNav.vue'), 'utf8');
const barSrc = readFileSync(join(COMPONENTS, 'ShellV2', 'TourBar.vue'), 'utf8');
const shellSrc = readFileSync(join(JS, 'Layouts', 'AppShellV2.vue'), 'utf8');
ok(/toggle:\s*toggleTour/.test(menuSrc) && /<button[^>]*@click="toggleTour"/.test(menuSrc),
    'the menu retains the in-place guided-tour toggle');
ok(/<TourBar\s*\/>/.test(shellSrc) && /v-if="active"/.test(barSrc)
    && /<Link[^>]*href="\/tour"/.test(barSrc),
    'the mounted tour bar links All steps to /tour');

/* 4. Neither pages nor shared components may hard-code an armed stop-1 URL. */
const hardCoders = files
    .filter((f) => /href\s*=\s*["'][^"']*[?&]step=1(?:[&#"'])/.test(readFileSync(f, 'utf8')))
    .map((f) => relative(JS, f));
ok(hardCoders.length === 0, `no page or shared component hard-codes an armed ?step=1 link (found: ${hardCoders.join(', ') || 'none'})`);

console.log(failed ? `\n${failed} FAILED` : '\nall passed');
process.exit(failed ? 1 : 0);
