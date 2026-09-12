<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, usePage, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import AgendaStrip from '@/Components/Legislature/AgendaStrip.vue';
import VoteTally from '@/Components/Legislature/VoteTally.vue';
import CivicFloor from '@/Components/Civic/Room/CivicFloor.vue';
import LiveRoom from '@/Components/Civic/Room/LiveRoom.vue';
import { personLabel } from '@/Components/Civic/Room/roomPresentation.js';
import { useLiveRoom } from '@/composables/useLiveRoom';
import useAnnounce from '@/composables/useAnnounce';

defineOptions({ layout: AppShellV2, inheritAttrs: false });
const props = defineProps({
    surface: { type: Object, required: true },
    variant: { type: String, default: 'committee' },
    entity: { type: Object, required: true },
    title: { type: String, required: true },
    jurisdiction: { type: String, default: '' },
    status: { type: Object, required: true },
    agenda: { type: Array, default: () => [] },
    floor: { type: Object, default: () => ({}) },
    vote: { type: Object, default: null },
    presence: { type: Array, default: () => [] },
    queue: { type: Array, default: () => [] },
    floorHolder: { type: String, default: null },
    displayNames: { type: Object, default: () => ({}) },
    voice: { type: Object, default: () => ({}) },
    chat: { type: Array, default: () => [] },
    chatAvailable: { type: Boolean, default: false },
    record: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
    urls: { type: Object, default: () => ({}) },
});
const page = usePage();
const { t } = useI18n();
const text = (key, fallback) => t('c_rooms.' + key, fallback);
const busy = ref(false);
const compose = useForm({ body: '' });
function sendMessage() {
    if (!props.urls.messages || !compose.body.trim()) return;
    compose.post(props.urls.messages, { preserveScroll: true, onSuccess: () => compose.reset('body') });
}
const flashStatus = computed(() => page.props.flash?.status ?? null);
const isLive = computed(() => props.status?.state === 'open');
const labelFor = (handle) => personLabel({ identity: handle, display_name: props.displayNames[handle] });
const seating = computed(() => {
    const seats = props.presence.map((person) => ({ ...person, display_name: props.displayNames[person.handle] || person.display_name }));
    if (props.floorHolder && !seats.some((person) => person.handle === props.floorHolder)) {
        seats.push({ handle: props.floorHolder, display_name: props.displayNames[props.floorHolder], role: 'guest' });
    }
    return seats;
});
const { isStale } = useLiveRoom({
    keys: ['status', 'agenda', 'vote', 'presence', 'queue', 'floorHolder', 'displayNames', 'voice', 'chat', 'chatAvailable', 'record', 'clocks'],
    isLive: () => props.status?.state ?? 'open',
    busy: () => busy.value || compose.processing,
    cadenceMs: 5000,
});
const { announce } = useAnnounce();
watch(() => props.floorHolder, (holder) => {
    announce(holder ? t('c_rooms.floor_announcement', { name: labelFor(holder) }, '{name} now holds the floor') : text('floor_open', 'The floor is open'));
});
watch(() => props.vote?.tallies, (tallies, previous) => {
    if (tallies && !previous) announce(text('vote_called', 'The vote is called'));
});
watch(() => props.vote?.outcome, (outcome) => {
    if (outcome === 'adopted') announce(text('vote_adopted', 'Vote result: adopted'));
    else if (outcome === 'failed') announce(text('vote_failed', 'Vote result: failed'));
});
function floorAction(action) {
    if (!props.urls[action] || busy.value) return;
    router.post(props.urls[action], {}, {
        preserveScroll: true,
        onStart: () => (busy.value = true),
        onFinish: () => (busy.value = false),
    });
}
</script>

<template>
    <PageScaffold :surface="surface" :title="title">
        <template #intro>{{ text('hearing_intro', 'Follow the hearing, see who has the floor, and read the public record.') }}</template>
        <header class="room-heading">
            <div class="cluster">
                <StatusBadge :tone="isLive ? 'success' : 'neutral'">{{ text('status.' + status.state, status.label) }}</StatusBadge>
                <span v-if="jurisdiction" class="citation">{{ jurisdiction }}</span>
                <StatusBadge v-if="isStale" tone="warning">{{ text('reconnecting', 'Reconnecting') }}</StatusBadge>
            </div>
            <Link v-if="urls.rooms" :href="urls.rooms" class="btn btn--secondary btn--sm">Browse rooms</Link>
            <Link v-if="urls.chamber" :href="urls.chamber" class="btn btn--secondary btn--sm">{{ text('committee_workspace', 'Committee workspace') }}</Link>
        </header>
        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>

        <LiveRoom v-if="voice.enabled && voice.roomId && voice.myMxid && voice.myUserId"
            :key="voice.roomId" :jurisdiction-id="voice.jurisdictionId" :room="voice.roomId"
            :pseudonym="voice.myMxid" :subject-user-id="voice.myUserId"
            :variant="variant" :roster="seating" :floor-holder="floorHolder" :display-names="displayNames" />
        <CivicFloor v-else :variant="variant" :roster="seating" :floor-holder="floorHolder" />

        <div class="room-panels">
            <div class="stack">
                <Card as="section" :title="text('on_floor', 'On the floor')">
                    <h2 class="room-subtitle">{{ floor.title || text('hearing', 'Committee hearing') }}</h2>
                    <p v-if="floorHolder">{{ t('c_rooms.recognized_name', { name: labelFor(floorHolder) }, '{name} has been recognized to speak.') }}</p>
                    <p v-else class="gloss">{{ text('floor_open', 'The floor is open') }}</p>
                    <div class="cluster" style="margin-top: var(--space-3)">
                        <Btn v-if="can.recognize" variant="secondary" size="sm" :disabled="busy || !queue.length" @click="floorAction('recognize')">{{ text('recognize_next', 'Recognize next speaker') }}</Btn>
                        <Btn v-if="can.advance" variant="secondary" size="sm" :disabled="busy || !floorHolder" @click="floorAction('advance')">{{ text('yield_floor', 'Yield the floor') }}</Btn>
                        <Link :href="'/explore?role=chair' + (voice.jurisdictionId ? '&jurisdiction=' + encodeURIComponent(voice.jurisdictionId) : '')" class="btn btn--ghost btn--sm">{{ text('explore_chair', 'Explore the chair’s role') }}</Link>
                    </div>
                </Card>
                <Card as="section" :title="text('agenda', 'Agenda')">
                    <AgendaStrip v-if="agenda.length" :items="agenda" :editable="false" />
                    <p v-else class="gloss">{{ text('no_agenda', 'No agenda has been set.') }}</p>
                </Card>
                <Card v-if="vote" as="section" :title="text('committee_vote', 'Committee vote')">
                    <VoteTally v-bind="vote" stage="committee" :can-cast="false" basis="Art. II §4" />
                </Card>
                <Card as="section" :title="text('public_record', 'Public record')">
                    <ul v-if="record.length" class="room-list">
                        <li v-for="(item, index) in record" :key="index">
                            <a v-if="item.recordHref" :href="item.recordHref">{{ item.body }}</a>
                            <span v-else>{{ item.body }}</span>
                        </li>
                    </ul>
                    <p v-else class="gloss">{{ text('no_record', 'No statements or reports have been sealed to this committee’s record.') }}</p>
                    <Link v-if="urls.chamber" :href="urls.chamber" class="btn btn--secondary btn--sm">{{ text('testimony_votes_reports', 'Testimony, votes and reports') }}</Link>
                </Card>
            </div>
            <aside class="stack">
                <Card as="section" :title="text('hands_raised', 'Hands raised') + ' (' + queue.length + ')'">
                    <ol v-if="queue.length" class="room-list">
                        <li v-for="person in queue" :key="person.handle">
                            <strong>{{ labelFor(person.handle) }}</strong>
                            <span v-if="person.reason" class="gloss"> · {{ person.reason }}</span>
                        </li>
                    </ol>
                    <p v-else class="gloss">{{ text('no_queue', 'No one is waiting to speak.') }}</p>
                    <Btn v-if="can.raiseHand" variant="primary" size="sm" :disabled="busy" @click="floorAction('raiseHand')">{{ text('raise_hand', 'Raise my hand') }}</Btn>
                    <Link v-else href="/login" class="btn btn--secondary btn--sm">{{ text('sign_in_hand', 'Sign in to raise your hand') }}</Link>
                </Card>
                <Card as="section" :title="text('conversation_call', 'Conversation and call')">
                    <p v-if="voice.enabled && voice.myUserId" class="gloss">{{ text('hearing_call_hint', 'Join the hearing’s call above. Your camera or avatar appears in your assigned position. Use the halls for the jurisdiction’s wider conversation.') }}</p>
                    <p v-else-if="voice.enabled" class="gloss">{{ text('sign_in_call', 'Sign in to join the hearing’s call. The seating and public record remain open to visitors.') }}</p>
                    <p v-else class="gloss">{{ text('call_not_ready', 'The hearing’s call is not available yet. You can follow the floor and speaking queue here.') }}</p>
                    <Link v-if="urls.commons" :href="urls.commons" class="btn btn--secondary btn--sm">{{ text('open_halls', 'Open the halls') }}</Link>
                    <p v-if="!chatAvailable" role="status">Room messages are temporarily unavailable.</p>
                    <p v-else-if="!chat.length">No messages in this hearing yet.</p>
                    <ul v-if="chat.length" class="room-list">
                        <li v-for="(message, index) in chat" :key="message.event_id || index">
                            <strong>{{ labelFor(message.sender || message.handle) }}</strong>
                            <p style="white-space: pre-wrap">{{ message.body }}</p>
                        </li>
                    </ul>
                    <form v-if="voice.myUserId && voice.roomId" @submit.prevent="sendMessage" class="stack">
                        <label for="hearing-message">Message to this hearing</label>
                        <textarea id="hearing-message" v-model="compose.body" rows="3" maxlength="20000" :aria-invalid="!!compose.errors.body" :aria-describedby="compose.errors.body ? 'hearing-message-note hearing-message-error' : 'hearing-message-note'" />
                        <small id="hearing-message-note">Discussion only. File formal testimony through the committee workspace.</small>
                        <p id="hearing-message-error" v-if="compose.errors.body" role="alert">{{ compose.errors.body }}</p>
                        <Btn type="submit" :disabled="compose.processing || !compose.body.trim()">Send message</Btn>
                    </form>
                </Card>
            </aside>
        </div>
    </PageScaffold>
</template>

<style scoped>
.room-heading { display: flex; justify-content: space-between; align-items: center; gap: var(--space-2); flex-wrap: wrap; margin-bottom: var(--space-3); }
.room-panels { display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); gap: var(--space-3); margin-top: var(--space-3); }
.room-panels .stack { gap: var(--space-3); }
.room-subtitle { font-size: var(--text-base); }
.room-list { margin: 0 0 var(--space-3); padding-inline-start: 1.25rem; }
.room-list li + li { margin-top: var(--space-2); }
@media (max-width: 900px) { .room-panels { grid-template-columns: minmax(0, 1fr); } }
</style>
