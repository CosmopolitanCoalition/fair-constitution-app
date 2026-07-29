<script setup>
/**
 * Home — the signed-out arrival cover (walk finding W-2; V3 synthesis S2).
 *
 * The old page was a viewport-height blank region with a lone centered
 * title — "a stranger arriving from a QR code lands on an empty dark page,"
 * and Poland is QR codes. The v3 cover (mockups/v3/index.html) is the spec:
 * the hero, then FOUR DOORS — invited / what's happening today / the Atlas /
 * step into a live room — then the guided-tour strip.
 *
 * Door targets are the app's live routes; the Atlas door renders Planned
 * (per the order — its phase hasn't landed). The invited door: a real
 * invitation is a friend's link (/i/{token}), which a cover cannot mint, so
 * the door opens account creation — the same "a name is all it takes" entry
 * the mockup's join page teaches.
 *
 * Renders on AppShellV2 via the app.js default (S1). Setup gating reads the
 * `instance.setupComplete` shared prop (WI-3) — no fetch round trip, no
 * flash of the wrong state.
 */
import { computed, onMounted } from 'vue'
import { Head, router, usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import Icon from '@/Components/Ui/Icon.vue'

const page = usePage()
const { t } = useI18n({ useScope: 'global' })

const setupComplete = computed(() => page.props.instance?.setupComplete ?? true)
const appName = computed(() => page.props.instance?.name || t('app.name'))

onMounted(() => {
    if (!setupComplete.value) {
        router.visit('/setup', { replace: true })
    }
})

/* The four doors — where a first visit actually goes (v3 cover contract). */
const doors = [
    {
        icon: 'user',
        title: 'You’re invited',
        note: 'The way most people arrive — a friend’s link into a live room. No link? A name is all it takes to start.',
        href: '/register',
    },
    {
        icon: 'home',
        title: 'What’s happening today',
        note: 'Everything live right now in the places you belong to — votes closing, rooms open, the calendar.',
        href: '/civic',
    },
    {
        icon: 'globe',
        title: 'See the living world',
        note: 'The Atlas — every place and every number on one breathing map.',
        href: null, // Planned — the Atlas is the area's one true XL, not landed
    },
    {
        icon: 'users',
        title: 'Step into a room',
        note: 'People meeting live — watching is open to everyone, always.',
        href: '/civic/commons/square',
    },
]
</script>

<template>
    <Head title="Home" />

    <div v-if="!setupComplete" class="stack" style="align-items: center; text-align: center; padding-block: var(--space-12)">
        <div class="citation">Redirecting to setup…</div>
    </div>

    <div v-else class="stack">
        <div class="v2-hero">
            <p class="kosmopolites">Kosmopolitês</p>
            <h1>{{ appName }}</h1>
            <p class="tagline" style="color: var(--cc-gold-300)">
                A world you walk into — the people in the foreground, the government working quietly underneath.
            </p>
            <p style="max-inline-size: 48rem; margin-block-start: var(--space-3)">
                Everything a society does — meeting, arguing, voting, trading, judging — happens here
                live, in rooms full of people. Play where you actually live, on a real map of Earth,
                or on any map you invent. Start anywhere below.
            </p>
        </div>

        <section aria-labelledby="doors-h">
            <h2 id="doors-h" class="visually-hidden">Ways in</h2>
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

        <div class="tour-bar" style="margin-block-end: 0">
            <div class="tour-bar-text">
                <span class="tour-step"><Icon name="map" size="sm" /> New here?</span>
                <strong class="tour-title">Take the guided tour</strong>
                <span class="tour-blurb">
                    One linear walk through the whole world, starting the way a real first visit does.
                </span>
            </div>
            <div class="tour-bar-nav">
                <!-- Lands on the /tour INDEX, not straight into stop 1: a stranger
                     should see the shape of the walk (stops, acts, the first-visit
                     track) and choose, and the index's own Start arms the mode. -->
                <a class="btn btn--primary btn--sm" href="/tour">
                    Start the tour <Icon name="arrow-right" size="sm" />
                </a>
            </div>
        </div>
    </div>
</template>
