<script setup>
/**
 * LiveCivicRoom — Slice 6, THE KEYSTONE. One page renders every meeting type
 * from the MANIFEST §1 config (variant-agnostic); the controller builds the
 * config from the real domain. The room FUSES the governance form (agenda, the
 * vote tile, the record) with the live social floor (presence, the hands-raised
 * queue, the timeline, the call).
 *
 * Freshness is poll-first (store contract c6399aa, desk-ruled Q1=A/Q2=B): this
 * page mounts useLiveRoom, which re-requests the volatile props on a cadence —
 * server snapshots stay the truth, and it stops when the room adjourns. The four
 * required aria-live announcements fire through useAnnounce.
 *
 * Step 3 (this landing): the composed shell over real committee data + the store
 * + the announcer. The live VOICE call mount, the recognition write-path, and
 * the Matrix timeline read land in step 4; the room already live-refreshes.
 */
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';
import AgendaStrip from '@/Components/Legislature/AgendaStrip.vue';
import VoteTally from '@/Components/Legislature/VoteTally.vue';
import { useLiveRoom } from '@/composables/useLiveRoom';
import useAnnounce from '@/composables/useAnnounce';

defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    variant: { type: String, default: 'committee' },
    entity: { type: Object, required: true },
    title: { type: String, required: true },
    jurisdiction: { type: String, default: '' },
    status: { type: Object, required: true }, // { state, label }
    chairRole: { type: String, default: 'chair' },
    chair: { type: Object, default: () => ({}) },
    clocks: { type: Object, default: () => ({ agendaItem: 0, speaking: 120 }) },
    constitutionalOrder: { type: Boolean, default: false },
    agenda: { type: Array, default: () => [] },
    floor: { type: Object, default: () => ({}) },
    vote: { type: Object, default: null },
    presence: { type: Array, default: () => [] },
    queue: { type: Array, default: () => [] },
    floorHolder: { type: String, default: null },
    chat: { type: Array, default: () => [] },
    voice: { type: Object, default: () => ({ enabled: false, participants: [], residencyGated: true }) },
    translation: { type: Object, default: () => ({}) },
    record: { type: Array, default: () => [] },
    residencyGated: { type: Boolean, default: true },
    galleryNote: { type: String, default: '' },
    forms: { type: Array, default: () => [] },
    chairControls: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
    urls: { type: Object, default: () => ({}) },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);

/* ---- the freshness store (poll-first; stops on adjourned) ---- */
const busy = ref(false); // a floor write is in flight — the poll skips a tick
const { isStale } = useLiveRoom({
    keys: ['status', 'agenda', 'vote', 'presence', 'queue', 'floorHolder', 'chat', 'record', 'clocks'],
    isLive: () => props.status?.state ?? 'open',
    busy: () => busy.value,
    cadenceMs: 5000,
});

/* ---- the four required aria-live announcements ---- */
const { announce } = useAnnounce();
watch(() => props.floorHolder, (h) => {
    if (h) announce(`${h} now holds the floor`);
});
watch(() => props.vote?.tallies, (t, prev) => {
    if (t && !prev) announce('The vote is called');
});
watch(() => props.vote?.outcome, (o) => {
    if (o === 'adopted') announce('Vote result — adopted');
    else if (o === 'failed') announce('Vote result — failed');
});

const CHAIR_LABEL = {
    speaker: 'Speaker', chair: 'Chair', presiding_judge: 'Presiding judge',
    facilitator: 'Facilitator', moderator: 'Moderator',
};
const chairLabel = computed(() => CHAIR_LABEL[props.chairRole] ?? 'Chair');
const isGallery = computed(() => props.can?.isGallery ?? false);
const isLive = computed(() => props.status?.state === 'open');
const mmss = (s) => `${Math.floor((s ?? 0) / 60)}:${String((s ?? 0) % 60).padStart(2, '0')}`;
const floorHolderRow = computed(() => props.presence.find((p) => p.handle === props.floorHolder) ?? null);

/* ---- the floor write-path (ephemeral recognition; the poll refreshes it) ---- */
function raiseHand() {
    router.post(props.urls.raiseHand, {}, { preserveScroll: true, onStart: () => (busy.value = true), onFinish: () => (busy.value = false) });
}
function recognize() {
    router.post(props.urls.recognize, {}, { preserveScroll: true, onStart: () => (busy.value = true), onFinish: () => (busy.value = false) });
}
function advance() {
    router.post(props.urls.advance, {}, { preserveScroll: true, onStart: () => (busy.value = true), onFinish: () => (busy.value = false) });
}
</script>

<template>
    <PageScaffold :surface="surface" :title="title">
        <template #intro>
            The <strong>live civic room</strong> — the governance form and the live social floor, fused.
            The agenda, the vote, and the record are the proceeding; presence, the queue, the timeline,
            and the call are the room. What is said here is public (Art. II §2), under a pseudonymous
            handle — never a legal name.
        </template>

        <!-- band: identity + status + clocks -->
        <div class="cluster" style="justify-content: space-between; align-items: baseline; gap: var(--space-3)">
            <div class="cluster" style="gap: var(--space-2)">
                <StatusBadge :tone="isLive ? 'success' : 'neutral'">{{ status.label }}</StatusBadge>
                <span v-if="jurisdiction" class="citation">{{ jurisdiction }}</span>
            </div>
            <div class="cluster" style="gap: var(--space-3)">
                <span class="citation">Item {{ mmss(clocks.agendaItem) }}</span>
                <span class="citation">Speaking {{ mmss(clocks.speaking) }}</span>
                <StatusBadge v-if="isStale" tone="warning" title="reconnecting…">reconnecting…</StatusBadge>
            </div>
        </div>

        <Banner v-if="isGallery" tone="neutral" role="status">{{ galleryNote }}</Banner>
        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>

        <!-- agenda -->
        <Card as="section" :title="`Agenda — ${constitutionalOrder ? 'constitutional order' : 'order of business'}`">
            <p class="citation">
                {{ constitutionalOrder
                    ? 'slots 1–2 are locked: outstanding emergency powers first, constitutional matters second — cannot be reordered'
                    : 'the chair sets the order; a committee carries no constitutional lock (Art. II §4)' }}
            </p>
            <AgendaStrip v-if="agenda.length" :items="agenda" :editable="false" />
            <p v-else class="gloss">No agenda set yet.</p>
        </Card>

        <div class="lr-body">
            <!-- stage: floor + vote + chair controls -->
            <div class="lr-stage stack" style="gap: var(--space-3)">
                <section class="card">
                    <span class="eyebrow">On the floor · {{ (floor.kind || 'business').replace(/_/g, ' ') }}</span>
                    <h2 style="margin-block: var(--space-1)">{{ floor.title }}</h2>
                    <p v-if="floorHolderRow" class="cc-small">
                        <StatusBadge tone="success">{{ floorHolder }} holds the floor</StatusBadge>
                    </p>
                    <p v-else class="gloss">No one holds the floor right now.</p>
                    <details class="about-surface" style="margin-block-start: var(--space-2)">
                        <summary>How this works</summary>
                        <div class="about-surface-body">
                            <p>{{ floor.body }}</p>
                            <p class="citation">{{ floor.citation }}</p>
                            <p v-if="urls.chamber">
                                <a class="btn btn--secondary btn--sm" :href="urls.chamber">Open the formal screen →</a>
                            </p>
                        </div>
                    </details>
                </section>

                <!-- the vote tile (only when a vote is in the room) -->
                <section v-if="vote" class="card">
                    <span class="eyebrow">The committee vote</span>
                    <VoteTally
                        v-bind="vote"
                        stage="committee"
                        :can-cast="false"
                        basis="Art. II §4"
                    />
                    <p class="citation">The vote numbers come from the counting engine — this page never does its own math.</p>
                </section>
                <section v-else class="card">
                    <span class="eyebrow">No vote in this room yet</span>
                    <p class="gloss">The chair calls the committee vote when the hearing is ready to decide.</p>
                </section>

                <!-- chair controls (wired to the write-path in step 4) -->
                <section class="card">
                    <div class="cluster" style="justify-content: space-between">
                        <span class="eyebrow">{{ chairLabel }} controls</span>
                        <StatusBadge :tone="can.recognize ? 'success' : 'info'">
                            {{ can.recognize ? 'You hold the gavel' : 'Observing' }}
                        </StatusBadge>
                    </div>
                    <div class="cluster" style="gap: var(--space-1); margin-block-start: var(--space-2)">
                        <Btn variant="secondary" size="sm" :disabled="!can.recognize || busy" @click="recognize">Recognize the next speaker</Btn>
                        <Btn variant="secondary" size="sm" :disabled="!can.advance || busy" @click="advance">Open the floor / advance</Btn>
                        <Btn variant="secondary" size="sm" disabled title="a client-side speaking timer wires next">Start the speaking clock</Btn>
                        <Btn variant="secondary" size="sm" disabled title="the referral vote wires with the exit test">Call the committee vote</Btn>
                    </div>
                    <p class="citation" style="margin-block-start: var(--space-2)">
                        The chair runs the room (recognize, timers, call the question); quorum checks, tabulation,
                        and record-sealing are automatic. <HardenedChip>chair acts · F-CHR / F-SPK</HardenedChip>
                    </p>
                </section>
            </div>

            <!-- rail: presence + queue + timeline + voice -->
            <div class="lr-rail stack" style="gap: var(--space-3)">
                <Card as="section" :title="`Who's here (${presence.length})`">
                    <div v-for="(p, i) in presence" :key="i" class="cluster" style="gap: var(--space-1); justify-content: space-between">
                        <span class="handle">{{ p.handle }}</span>
                        <span class="citation">
                            <template v-if="p.role === 'chair'">{{ chairLabel }}</template>
                            <template v-else-if="p.seat">seat · {{ p.seat }}</template>
                            <template v-if="p.speaking"> · speaking</template>
                        </span>
                    </div>
                    <p v-if="!presence.length" class="gloss">No one seated yet.</p>
                </Card>

                <Card as="section" :title="`Hands raised (${queue.length})`">
                    <div v-for="(q, i) in queue" :key="i" class="cluster" style="gap: var(--space-1)">
                        <span class="citation">{{ i + 1 }}</span>
                        <span class="handle">{{ q.handle }}</span>
                        <span v-if="q.reason" class="gloss">{{ q.reason }}</span>
                    </div>
                    <p v-if="!queue.length" class="gloss">No hands raised right now.</p>
                    <Btn v-if="can.raiseHand" variant="primary" size="sm" :disabled="busy" style="margin-block-start: var(--space-2)" @click="raiseHand">
                        Raise my hand
                    </Btn>
                    <p v-else class="citation" style="margin-block-start: var(--space-2)">Sign in as a resident to raise your hand.</p>
                </Card>

                <Card as="section" :title="`Conversation (${chat.length})`">
                    <div v-for="(m, i) in chat" :key="m.event_id || i" class="stack" style="gap: 2px; margin-block-end: var(--space-2)">
                        <div class="cluster" style="gap: var(--space-1)">
                            <span class="handle">{{ m.sender || m.handle }}</span>
                            <StatusBadge v-if="m.seat" tone="info">{{ m.seat }}</StatusBadge>
                        </div>
                        <p class="cc-small" style="white-space: pre-wrap">{{ m.body }}</p>
                    </div>
                    <p v-if="!chat.length" class="gloss">The room is quiet. The live timeline appears here.</p>
                </Card>

                <Card as="section" title="The call">
                    <p class="cc-small">
                        <StatusBadge tone="neutral">Voice</StatusBadge>
                        {{ voice.residencyGated ? 'Residents may take the floor; anyone may listen.' : 'Open to members.' }}
                    </p>
                    <p class="citation">The wired room runs a real call on your node's own media server (LiveKit); nothing routes through an outside service.</p>
                </Card>
            </div>
        </div>

        <!-- the record -->
        <Card as="section" title="The record">
            <p class="citation">Statements sealed here go to the permanent public record — under your handle, never your legal name.</p>
            <div v-for="(r, i) in record" :key="i" class="cluster" style="gap: var(--space-1); justify-content: space-between">
                <span class="cc-small">{{ r.body }}</span>
                <a v-if="r.recordHref" class="citation" :href="r.recordHref">view in the public record →</a>
            </div>
            <p v-if="!record.length" class="gloss">Nothing sealed to the record yet.</p>
        </Card>
    </PageScaffold>
</template>

<style scoped>
.lr-body {
    display: grid;
    grid-template-columns: minmax(0, 2fr) minmax(0, 1fr);
    gap: var(--space-3);
    margin-block-start: var(--space-3);
}
@media (max-width: 900px) {
    .lr-body { grid-template-columns: minmax(0, 1fr); }
}
.handle {
    font-family: var(--font-mono, monospace);
    font-size: var(--text-sm);
}
</style>
