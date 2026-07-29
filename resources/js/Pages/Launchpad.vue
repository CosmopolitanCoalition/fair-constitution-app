<script setup>
/**
 * Launchpad — the full arrival hub (design contract: mockups/v3/index.html).
 *
 * Home.vue is the compact signed-out cover at "/" (hero + four doors + tour
 * strip). This is the complete launchpad the mockup index.html specifies:
 * the same cover, then "step into a live room", the five interaction classes
 * (the §7 honest map, sourced from registry/journeys.js), and the learn /
 * run-a-node links. Reachable by everyone at /launchpad (public), so a
 * signed-in player has a single "everything you can do" hub, not only the
 * guest cover.
 *
 * Bare on AppShellV2 (like Home) — no surface prop, no PageScaffold: it is a
 * cover page. Data comes entirely from client registries (journeys.js +
 * surfaces.js), so there is no backend query — the plan's "registries exist".
 */
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import Icon from '@/Components/Ui/Icon.vue';
import { CLASSES } from '@/registry/journeys.js';
import { tourStartHref } from '@/registry/surfaces.js';

defineOptions({ layout: AppShellV2 });

const page = usePage();
const { t } = useI18n({ useScope: 'global' });
const appName = computed(() => page.props.instance?.name || t('app.name'));

/* The four doors — the v3 cover contract, same as Home.vue's arrival cover. */
const doors = [
    { icon: 'user', title: 'You’re invited', href: '/register',
      note: 'The way most people arrive — a friend’s link into a live room. No link? A name is all it takes to start.' },
    { icon: 'home', title: 'What’s happening today', href: '/civic',
      note: 'Everything live right now in the places you belong to — votes closing, rooms open, the calendar.' },
    { icon: 'globe', title: 'See the living world', href: null,
      note: 'The Atlas — every place and every number on one breathing map.' },
    { icon: 'users', title: 'Step into a room', href: '/civic/commons/square',
      note: 'People meeting live — watching is open to everyone, always.' },
];

/* Reachable live rooms today — every href a real landing (session/court/board
   rooms are per-instance and open from their own surfaces, not linked here). */
const liveRooms = [
    { icon: 'message-square', title: 'The live square', href: '/civic/commons/square',
      note: 'Open conversation in the commons, in real time.' },
    { icon: 'landmark', title: 'Live halls', href: '/civic/commons/halls',
      note: 'Committee-style rooms — gather, speak, and be heard on the record.' },
    { icon: 'landmark', title: 'The halls (testimony)', href: '/civic/halls',
      note: 'Give testimony that seals to the public record.' },
    { icon: 'users', title: 'The public square', href: '/civic/square',
      note: 'Open speech on the permanent public record — no one can quietly remove it.' },
];

/* The five interaction classes (§7 honest map) — grouping labels come from the
   journeys registry; each opens the journeys directory, which groups by class. */
const CLASS_NOTES = {
    people: 'The square, messages, and petitions — how people meet, speak, and organize.',
    'orgs-people': 'Parties, businesses, nonprofits — joining, endorsing, and working together.',
    'gov-itself': 'Elections, chambers, and courts — how a place governs itself, end to end.',
    'gov-gov': 'Unions, borders, and federation — how places relate across the map.',
    'gov-orgs-people': 'Residency, the economy, and public records — where people meet the state.',
};
const classCards = computed(() =>
    CLASSES.map((c) => ({ ...c, note: CLASS_NOTES[c.id] ?? '' })),
);

/* Learn & help + run-a-node — quick entry cards (all real routes). */
const helpLinks = [
    { icon: 'list-checks', title: 'Journeys', href: '/journeys',
      note: 'Learn by doing — each journey walks one real process, start to finish.' },
    { icon: 'graduation-cap', title: 'The guided tour', href: '/tour',
      note: 'One linear walk through the whole world — the ten-stop first visit, or every step.' },
    { icon: 'shield', title: 'Accessibility', href: '/system/accessibility',
      note: 'The interface contract — every one of us can operate it.' },
    { icon: 'flag', title: 'Report an issue', href: '/support/report',
      note: 'A bug, a question, or something that needs review — file it here.' },
    { icon: 'sliders', title: 'Run a node', href: '/operator',
      note: 'The volunteer servers the world runs on. Keeping it online buys no vote and no seat.' },
];
</script>

<template>
    <Head title="Launchpad" />

    <div class="stack">
        <div class="v2-hero">
            <p class="kosmopolites">Kosmopolitês</p>
            <h1>{{ appName }}</h1>
            <p class="tagline" style="color: var(--cc-gold-300)">
                A world you walk into — the people in the foreground, the government working quietly
                underneath.
            </p>
            <p style="max-inline-size: 48rem; margin-block-start: var(--space-3)">
                Everything a society does — meeting, arguing, voting, trading, judging — happens
                here live, in rooms full of people. Start anywhere below.
            </p>
        </div>

        <!-- ──────────────────────────────────────────────── the doors ── -->
        <section aria-labelledby="doors-h">
            <h2 id="doors-h">Ways in</h2>
            <div class="role-grid">
                <template v-for="door in doors" :key="door.title">
                    <a v-if="door.href" class="role-card" :href="door.href">
                        <Icon :name="door.icon" />
                        <span class="role-name">{{ door.title }}</span>
                        <span>{{ door.note }}</span>
                        <span class="enter-as">Go <Icon name="arrow-right" size="sm" /></span>
                    </a>
                    <div v-else class="role-card role-card--planned">
                        <Icon :name="door.icon" />
                        <span class="role-name">{{ door.title }}</span>
                        <span>{{ door.note }}</span>
                        <span class="planned-flag">Planned</span>
                    </div>
                </template>
            </div>
        </section>

        <!-- ───────────────────────────────────── step into a live room ── -->
        <section aria-labelledby="rooms-h">
            <h2 id="rooms-h">Step into a live room</h2>
            <p class="cc-small">Watching is open to everyone, always — no residency, no account needed to look.</p>
            <div class="role-grid">
                <a v-for="room in liveRooms" :key="room.title" class="role-card" :href="room.href">
                    <Icon :name="room.icon" />
                    <span class="role-name">{{ room.title }}</span>
                    <span>{{ room.note }}</span>
                    <span class="enter-as">Watch <Icon name="arrow-right" size="sm" /></span>
                </a>
            </div>
        </section>

        <!-- ─────────────────────────────────── the interaction classes ── -->
        <section aria-labelledby="classes-h">
            <h2 id="classes-h">Find your way in</h2>
            <p class="cc-small">
                Everything here is one of five kinds of interaction — the honest map of how it all
                fits together. Each opens the journeys that walk it.
            </p>
            <div class="role-grid">
                <a v-for="c in classCards" :key="c.id" class="role-card" href="/journeys">
                    <Icon name="list-checks" />
                    <span class="role-name">{{ c.label }}</span>
                    <span>{{ c.note }}</span>
                    <span class="enter-as">See journeys <Icon name="arrow-right" size="sm" /></span>
                </a>
            </div>
        </section>

        <!-- ──────────────────────────────────────── learn & run a node ── -->
        <section aria-labelledby="learn-h">
            <h2 id="learn-h">Learn &amp; help</h2>
            <div class="role-grid">
                <a v-for="link in helpLinks" :key="link.title" class="role-card" :href="link.href">
                    <Icon :name="link.icon" />
                    <span class="role-name">{{ link.title }}</span>
                    <span>{{ link.note }}</span>
                    <span class="enter-as">Open <Icon name="arrow-right" size="sm" /></span>
                </a>
            </div>
        </section>

        <!-- ────────────────────────────────────────────── tour strip ── -->
        <div class="tour-bar" style="margin-block-end: 0">
            <div class="tour-bar-text">
                <span class="tour-step"><Icon name="map" size="sm" /> New here?</span>
                <strong class="tour-title">Take the guided tour</strong>
                <span class="tour-blurb">
                    One linear walk through the whole world, starting the way a real first visit does.
                </span>
            </div>
            <div class="tour-bar-nav">
                <a class="btn btn--primary btn--sm" :href="tourStartHref()">
                    Start the tour <Icon name="arrow-right" size="sm" />
                </a>
                <a class="btn btn--ghost btn--sm" href="/tour">
                    <Icon name="list-checks" size="sm" /> All steps
                </a>
            </div>
        </div>
    </div>
</template>
