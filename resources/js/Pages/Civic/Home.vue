<script setup>
/**
 * Civic/Home — the live "today" feed (mockups-v3-wiring Phase 3b; design
 * contract mockups/v3/civic/today.html).
 *
 * Everything renders from server facts via the `feed` prop
 * (TodayFeedService): live proceedings in the viewer's footprint, the
 * community calendar of dated future events, and the latest public-record
 * heads. The residency claim card (top when a claim is open or the user
 * has no association), the rights badges + association chips, and the
 * my-record stats carry over from the dashboard era. The elections and
 * petitions props remain wired (tests + the feed already presents them) —
 * they no longer render as separate sections.
 */
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import AdmChip from '@/Components/Ui/AdmChip.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';
import Icon from '@/Components/Ui/Icon.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StateStrip from '@/Components/Ui/StateStrip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import ThresholdMeter from '@/Components/Ui/ThresholdMeter.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    /** TodayFeedService — {rows, total, calendar, record}. */
    feed: {
        type: Object,
        default: () => ({ rows: [], total: 0, calendar: [], record: [] }),
    },
    claim: { type: Object, default: null },
    machine: { type: Array, default: () => [] },
    associations: { type: Array, default: () => [] },
    stats: { type: Object, required: true },
    /** Presented inside the feed now — props stay wired (Phase 3b). */
    elections: { type: Array, default: () => [] },
    petitions: { type: Array, default: () => [] },
    /** Emergency banner slot — dormant (shell-wide banner is the live one). */
    emergency: { type: Object, default: null },
});

const page = usePage();
const roles = computed(() => page.props.auth?.roles ?? []);
const flash = computed(() => page.props.flash?.status ?? null);

const isVoter = computed(() => roles.value.includes('R-04'));
const hasClaim = computed(() => props.claim !== null);
const claimActive = computed(() => props.claim?.status === 'active');

/** The residency card leads when a claim is open or nothing associates yet. */
const showResidencyCard = computed(() => hasClaim.value || props.associations.length === 0);

const total = computed(() => props.feed?.total ?? 0);
const pageTitle = computed(() =>
    total.value > 0
        ? `Home — ${total.value} thing${total.value === 1 ? '' : 's'} happening now`
        : 'Home',
);

/* Feed row icon by kind (the today.html vocabulary). */
const KIND_ICONS = {
    election: 'vote',
    session: 'landmark',
    petition: 'file-text',
    referendum: 'refresh-cw',
};
const kindIcon = (kind) => KIND_ICONS[kind] ?? 'landmark';

/**
 * Countdown text for a row target — computed once at render (mount), no
 * intervals: 'closes in 2d 4h' / 'opens in 38m' / 'closing now'.
 */
const countdown = (target) => {
    if (!target?.iso) return null;
    const ms = new Date(target.iso).getTime() - Date.now();
    const verb = target.kind === 'opensAt' ? 'opens' : 'closes';
    if (ms <= 0) return target.kind === 'opensAt' ? 'opening now' : 'closing now';
    const totalMin = Math.floor(ms / 60000);
    const d = Math.floor(totalMin / 1440);
    const h = Math.floor((totalMin % 1440) / 60);
    const m = totalMin % 60;
    const parts = [];
    if (d) parts.push(`${d}d`);
    if (h) parts.push(`${h}h`);
    if (m || !parts.length) parts.push(`${m}m`);
    return `${verb} in ${parts.join(' ')}`;
};

/** Calendar rows grouped into their server-computed day buckets, in order. */
const calendarDays = computed(() => {
    const days = [];
    for (const event of props.feed?.calendar ?? []) {
        let bucket = days.find((d) => d.day === event.day);
        if (!bucket) {
            bucket = { day: event.day, events: [] };
            days.push(bucket);
        }
        bucket.events.push(event);
    }
    return days;
});

const eventTime = (iso) => {
    if (!iso) return '';
    return new Date(iso).toLocaleString([], {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const recordDate = (iso) => {
    if (!iso) return '';
    return new Date(iso).toLocaleDateString([], { year: 'numeric', month: 'short', day: 'numeric' });
};
</script>

<template>
    <PageScaffold :surface="surface" :title="pageTitle">
        <template #intro>
            Everything live in the places you live, each one a tap away.
        </template>
        <template #about>
            <p>
                The post-login home (WF-CIV-02): every live proceeding in your association
                chain — elections, chamber sessions, petitions, referendums — plus the
                community calendar and the public record. You can watch every public
                proceeding; you speak, testify, and vote where you live.
            </p>
        </template>

        <!-- Emergency banner slot — dormant (shell-wide banner is live, Art. II §7). -->
        <Banner v-if="emergency" tone="emergency" :title="emergency.title">
            {{ emergency.body }}
            <span class="citation">Art. II §7 · CLK-03</span>
        </Banner>

        <Banner v-if="flash" tone="info">{{ flash }}</Banner>

        <!-- ────────────────────────────── Residency (top card while unsettled) -->
        <Card v-if="showResidencyCard" as="section" title="Residency">
            <template v-if="!hasClaim">
                <p>
                    You have not said where you live yet. Declare the smallest boundary you
                    live inside; every enclosing level associates automatically once your
                    presence pattern verifies — and voting and candidacy unlock with it.
                </p>
                <div class="cluster" style="margin-block-start: var(--space-3)">
                    <Btn :as="Link" href="/civic/residency" variant="primary" icon="map-pin">
                        Say where you live
                    </Btn>
                </div>
            </template>
            <template v-else>
                <StateStrip :states="machine" :current="claim.status" />
                <p class="gloss" style="margin-block-start: var(--space-2)">
                    Declared boundary: <strong>{{ claim.jurisdiction?.name ?? '—' }}</strong>
                </p>
                <ThresholdMeter
                    v-if="!claimActive"
                    :value="claim.qualifying_days"
                    :max="claim.threshold"
                    :threshold="claim.threshold"
                    label="Qualifying days toward the residency threshold"
                >
                    {{ claim.qualifying_days }} of {{ claim.threshold }} qualifying days
                    <template #note>residency_confirmation_days · CLK-05</template>
                </ThresholdMeter>
                <p v-else>
                    Residency verified — you are associated at {{ associations.length }}
                    nesting level{{ associations.length === 1 ? '' : 's' }}.
                </p>
                <div class="cluster" style="margin-block-start: var(--space-3)">
                    <Btn :as="Link" href="/civic/residency" variant="secondary" size="sm">
                        Open residency
                        <Icon name="arrow-right" size="sm" />
                    </Btn>
                </div>
            </template>
            <p class="citation">Residency verified → all associations → rights unlocked · Art. I · CLK-05</p>
        </Card>

        <!-- ─────────────────────────────────────────────────────── The rail -->
        <Banner tone="info">
            You watch everything; you act where you reside. A ballot row shows only that
            voting is open — never how you voted.
        </Banner>

        <!-- ──────────────────────────────────────────── Live in your places -->
        <section aria-labelledby="now-h" class="stack" style="gap: var(--space-2)">
            <h2 id="now-h">Live in your places</h2>

            <template v-if="feed.rows.length">
                <div
                    v-for="row in feed.rows"
                    :key="row.id"
                    class="live-row"
                    :class="{ 'live-row--live': row.status === 'live' }"
                >
                    <span class="live-icon" aria-hidden="true">
                        <Icon :name="kindIcon(row.kind)" size="sm" />
                    </span>
                    <span class="live-title">{{ row.title }}</span>
                    <span class="live-what">{{ row.what }}</span>
                    <span class="gloss" style="flex: 1 1 12rem; min-inline-size: 0">{{ row.part }}</span>
                    <span class="cluster" style="gap: var(--space-2); align-items: center">
                        <span class="pill" :class="`pill--${row.pill.tone}`">{{ row.pill.label }}</span>
                        <span v-if="countdown(row.target)" class="countdown">{{ countdown(row.target) }}</span>
                    </span>
                    <Btn :as="Link" :href="row.href" variant="secondary" size="sm">
                        Open
                        <Icon name="arrow-right" size="sm" />
                    </Btn>
                </div>
            </template>
            <Card v-else as="div" inset>
                <strong>Nothing is live in your places right now</strong>
                <p class="gloss" style="margin-block-start: var(--space-2)">
                    The galleries stay open, and anything that starts will appear here.
                </p>
            </Card>
        </section>

        <!-- ──────────────────────────────────────────── Community calendar -->
        <Card as="section" title="Community calendar">
            <p class="gloss">What's coming up in the places you belong to.</p>
            <p v-if="calendarDays.length === 0" class="gloss">
                Nothing on the calendar yet — scheduled sessions and election dates appear
                here the moment they are set.
            </p>
            <div v-for="bucket in calendarDays" :key="bucket.day" class="cal-day">
                <h3 class="cal-day-head">{{ bucket.day }}</h3>
                <Link
                    v-for="event in bucket.events"
                    :key="`${event.title}-${event.at}`"
                    class="cal-row"
                    :href="event.href"
                >
                    <span class="cal-when"><Icon name="clock" size="sm" /></span>
                    <span class="cal-main">
                        <strong>{{ event.title }}</strong>
                        <span class="gloss">{{ event.where }} · {{ eventTime(event.at) }}</span>
                    </span>
                    <span class="enter-as">
                        Open
                        <Icon name="arrow-right" size="sm" />
                    </span>
                </Link>
            </div>
        </Card>

        <!-- ─────────────────────────────────────────────────── On the record -->
        <Card as="section" title="On the record">
            <p v-if="feed.record.length === 0" class="gloss">
                No public-record entries in your places yet — every act, vote, and minute
                publishes here the moment it happens.
            </p>
            <ul v-else class="stack" style="gap: var(--space-2); list-style: none; margin: 0; padding: 0">
                <li v-for="entry in feed.record" :key="entry.seq" class="cluster" style="align-items: baseline">
                    <StatusBadge tone="neutral">{{ entry.kind }}</StatusBadge>
                    <Link href="/system/public-records">{{ entry.title }}</Link>
                    <span class="gloss">{{ recordDate(entry.published_at) }}</span>
                </li>
            </ul>
            <p style="margin-block-start: var(--space-3)">
                <Link href="/system/public-records">
                    Open the public record
                    <Icon name="arrow-right" size="sm" />
                </Link>
            </p>
        </Card>

        <!-- ────────────────────────────────────────────────── Your rights here -->
        <Card as="section" title="Your rights here">
            <div class="cluster" style="gap: var(--space-4)">
                <template v-if="isVoter">
                    <StatusBadge tone="success" icon="check">Voting unlocked</StatusBadge>
                    <StatusBadge tone="success" icon="check">Candidacy unlocked</StatusBadge>
                </template>
                <template v-else>
                    <StatusBadge tone="neutral" icon="clock">Voting — unlocks on residency verification</StatusBadge>
                    <StatusBadge tone="neutral" icon="clock">Candidacy — unlocks on residency verification</StatusBadge>
                </template>
                <HardenedChip />
            </div>
            <p style="margin-block-start: var(--space-3)">
                <template v-if="isVoter">
                    Living here is the only requirement — these unlocked the moment your
                    residency was confirmed, in every place that contains your home.
                </template>
                <template v-else>
                    Voting and candidacy unlock automatically the moment residency verifies —
                    jurisdictional residency is the only requirement, ever. No identity check,
                    course, or fee can be added between you and your rights.
                </template>
            </p>
            <p class="citation">Voting and candidacy — no other requirements · Art. I; Art. V §1</p>
            <div v-if="associations.length" class="cluster" style="margin-block-start: var(--space-3)">
                <AdmChip
                    v-for="assoc in associations"
                    :key="assoc.id"
                    :level="assoc.adm_level"
                    :label="assoc.name"
                />
            </div>
        </Card>

        <!-- ──────────────────────────────────────────────────────── My record -->
        <Card as="section" title="My record">
            <p class="cc-small">Your public civic record — it travels with any future candidacy.</p>
            <div class="cluster" style="gap: var(--space-6)">
                <Stat :value="stats.record_entries" label="record entries" />
                <Stat :value="stats.associations" label="associations" />
                <Stat :value="stats.ballots_cast" label="ballots cast" />
            </div>
            <p style="margin-block-start: var(--space-3)">
                <Link href="/civic/record">
                    Open my record
                    <Icon name="arrow-right" size="sm" />
                </Link>
            </p>
        </Card>
    </PageScaffold>
</template>
