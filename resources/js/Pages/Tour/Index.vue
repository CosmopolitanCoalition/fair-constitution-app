<script setup>
/**
 * Tour/Index — the guided-tour overview (design contract: mockups/v3/tour.html).
 *
 * The tour MODE is already delivered (composables/useTour.js + ShellV2/TourBar).
 * This is the doorway to it: "Start the tour" arms the mode at stop 1, the
 * "First visit — ten stops" track is a stranger's short arc, and the complete
 * walkthrough lists every stop grouped by act. Every link uses tourHref(i) so
 * the index and the bar can never drift on step numbers.
 *
 * Bare on AppShellV2 (like Home/Launchpad) — no surface prop; it is a cover
 * page, not a registry/ledger surface. Public route (/tour) so a first visit
 * can preview the walk before signing in.
 */
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import Icon from '@/Components/Ui/Icon.vue';
import { TOUR, FIRST_VISIT, tourStartHref } from '@/registry/surfaces.js';
import { tourHref } from '@/composables/useTour.js';

defineOptions({ layout: AppShellV2 });

/* Group TOUR by `act`, preserving order + each stop's global index (the index
   is what tourHref needs so ?step=N is the true position). */
const acts = computed(() => {
    const order = [];
    const groups = {};
    TOUR.forEach((stop, i) => {
        if (!groups[stop.act]) {
            groups[stop.act] = [];
            order.push(stop.act);
        }
        groups[stop.act].push({ ...stop, i });
    });
    return order.map((act) => ({ act, stops: groups[act] }));
});

/* The first-visit arc — resolve each href to its TOUR stop (by href); skip any
   that are not (yet) wired stops so the track never points at a dead step. */
const firstVisit = computed(() => {
    const byHref = {};
    TOUR.forEach((stop, i) => {
        byHref[stop.href] = { ...stop, i };
    });
    return FIRST_VISIT.map((href) => byHref[href]).filter(Boolean);
});

const actId = (act) => 'act-' + act.replace(/\s+/g, '-').toLowerCase();
</script>

<template>
    <Head title="Guided tour" />

    <div class="stack">
        <header>
            <span class="eyebrow"><Icon name="map" size="sm" /> Guided tour</span>
            <h1>Walk the whole thing, in order</h1>
            <p class="page-intro">
                One linear path through the whole world — {{ TOUR.length }} stops across
                {{ acts.length }} acts. Start at the top and a <strong>Back / Next</strong> bar
                rides along at the top of every screen, so you can step through the whole
                experience and iterate as you go. Jump in anywhere.
            </p>
            <p class="citation">A linear walkthrough · Back / Next on every screen</p>
            <div class="cluster" style="margin-block-start: var(--space-3)">
                <a class="btn btn--primary" :href="tourStartHref()">
                    Start the tour <Icon name="arrow-right" size="sm" />
                </a>
                <span class="citation">or pick any step below</span>
            </div>
        </header>

        <!-- ───────────────────────── first-visit track (gold top border) ── -->
        <section
            class="tour-act"
            aria-labelledby="act-first-visit"
            style="border-block-start: 3px solid var(--cc-gold-300)"
        >
            <h2 id="act-first-visit">First visit — ten stops</h2>
            <p class="cc-small">
                The short version, the way a real arrival goes: a friend's invite, a live room,
                home, the living world, and your first ballot. About ten minutes.
            </p>
            <Link
                v-for="(o, n) in firstVisit"
                :key="`fv-${o.i}`"
                class="tour-stop"
                :href="tourHref(o.i)"
            >
                <span class="tour-stop-n">{{ n + 1 }}</span>
                <span class="tour-stop-title">{{ o.title }}</span>
                <span class="tour-stop-blurb">{{ o.blurb }}</span>
                <span class="lesson-meta"><Icon name="arrow-right" size="sm" /></span>
            </Link>
        </section>

        <!-- ───────────────────────────────── the complete walkthrough ── -->
        <h2 style="margin-block-start: var(--space-6)">The complete walkthrough</h2>

        <section v-for="g in acts" :key="g.act" class="tour-act" :aria-labelledby="actId(g.act)">
            <h2 :id="actId(g.act)">{{ g.act }}</h2>
            <Link
                v-for="o in g.stops"
                :key="`stop-${o.i}`"
                class="tour-stop"
                :href="tourHref(o.i)"
            >
                <span class="tour-stop-n">{{ o.i + 1 }}</span>
                <span class="tour-stop-title">{{ o.title }}</span>
                <span class="tour-stop-blurb">{{ o.blurb }}</span>
                <span class="lesson-meta"><Icon name="arrow-right" size="sm" /></span>
            </Link>
        </section>

        <div class="lr-note">
            <Icon name="info" size="sm" />
            <div>
                The tour never traps you — each screen is the real page, fully usable, with the
                Back / Next bar added on top. Leave the tour any time from the normal menu, or come
                back here for the full list.
            </div>
        </div>
    </div>
</template>
