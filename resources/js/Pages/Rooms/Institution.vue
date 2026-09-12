<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import CivicFloor from '@/Components/Civic/Room/CivicFloor.vue';
import LiveRoom from '@/Components/Civic/Room/LiveRoom.vue';
import { personLabel } from '@/Components/Civic/Room/roomPresentation.js';
import { useLiveRoom } from '@/composables/useLiveRoom';

defineOptions({ layout: AppShellV2 });
const props = defineProps({
    title: String, variant: String, private: Boolean, jurisdiction: Object,
    roster: { type: Array, default: () => [] }, rosterTruncated: Boolean, rosterLimit: Number,
    displayNames: { type: Object, default: () => ({}) }, floorHolder: String, activeWitness: String,
    floorControls: { type: Object, default: () => ({}) }, rosterUrl: String,
    messages: { type: Array, default: () => [] }, timelineAvailable: Boolean,
    voice: { type: Object, default: () => ({}) }, recordHref: String, roomHref: String, messagesHref: String,
});
const { t } = useI18n();
const text = (key, fallback) => t('c_rooms.' + key, fallback);
const canJoin = computed(() => props.voice.roomId && props.voice.myMxid && props.voice.myUserId);
const compose = useForm({ body: '' });
const floorForm = useForm({ action: '', handle: null });
function floorAction(action, handle = null) {
    if (floorForm.processing) return;
    floorForm.action = action;
    floorForm.handle = handle;
    floorForm.post(props.roomHref + '/floor', { preserveScroll: true });
}
const floorName = computed(() => props.floorHolder ? personLabel({ identity: props.floorHolder, display_name: props.displayNames[props.floorHolder] }) : null);
const witnessName = computed(() => props.activeWitness ? personLabel({ identity: props.activeWitness, display_name: props.displayNames[props.activeWitness] }) : null);
const seating = computed(() => {
    const rows = props.roster.map(person => ({ ...person, display_name: props.displayNames[person.handle] || person.display_name }));
    for (const handle of [props.floorHolder, props.activeWitness].filter(Boolean)) {
        if (!rows.some(person => person.handle === handle)) rows.push({ handle, display_name: props.displayNames[handle], role: 'guest' });
    }
    return rows;
});
function send() {
    if (!compose.body.trim() || !canJoin.value) return;
    compose.post(props.messagesHref, { preserveScroll: true, onSuccess: () => compose.reset('body') });
}
const senderName = sender => personLabel({ identity: sender, display_name: props.displayNames[sender] });
const tokenRequester = computed(() => props.voice.tokenUrl ? async () => {
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    const response = await fetch(props.voice.tokenUrl, { method: 'POST', credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token ?? '' }, body: '{}' });
    if (!response.ok) throw new Error('room_not_accessible');
    return response.json();
} : null);
useLiveRoom({ keys: ['roster', 'rosterTruncated', 'displayNames', 'floorHolder', 'activeWitness', 'floorControls', 'voice', 'messages', 'timelineAvailable'],
    isLive: () => 'open', busy: () => compose.processing || floorForm.processing, cadenceMs: 5000 });
</script>

<template>
    <PageScaffold :title="title || text('courtroom', 'Courtroom')">
        <template #intro>{{ private ? text('private_board_intro', 'Meet with the current seated members of this board.') : text('institution_intro', 'Watch the room, join the conversation, and follow its official record.') }}</template>
        <nav class="room-links" :aria-label="text('room_navigation', 'Room navigation')">
            <Link :href="jurisdiction ? '/rooms?jurisdiction=' + jurisdiction.id : '/rooms'" class="btn">{{ text('all_rooms', 'Browse rooms') }}</Link>
            <Link :href="recordHref" class="btn">{{ text('official_workspace', 'Open official workspace') }}</Link>
            <Link v-if="jurisdiction" :href="'/jurisdictions/' + jurisdiction.slug" class="btn">{{ jurisdiction.name }}</Link>
        </nav>
        <p v-if="!voice.myUserId" class="room-note">{{ text('sign_in_to_join', 'You can explore this public room. Sign in to join its voice and video conversation.') }} <Link href="/login">{{ text('sign_in', 'Sign in') }}</Link></p>
        <p v-if="voice.myUserId" class="room-note"><Link href="/people">{{ text('edit_public_profile', 'Edit public profile') }}</Link> · {{ text('public_name_hint', 'Choose the public name other people see in rooms.') }}</p>
        <p v-if="!voice.roomId" class="room-note" role="status">{{ text('room_unavailable_retry', 'The call room is not available yet. Its seats and official workspace remain available.') }} <a :href="roomHref">{{ text('retry_room', 'Retry room') }}</a></p>
        <LiveRoom v-if="canJoin" :key="voice.roomId" :jurisdiction-id="voice.jurisdictionId || ''"
            :room="voice.roomId" :pseudonym="voice.myMxid" :subject-user-id="voice.myUserId"
            :token-requester="tokenRequester" :variant="variant" :roster="seating" :roster-url="rosterUrl"
            :floor-holder="floorHolder" :active-witness="activeWitness" :display-names="displayNames" />
        <CivicFloor v-else :variant="variant" :roster="seating" :floor-holder="floorHolder" :active-witness="activeWitness" />
        <p v-if="rosterTruncated" class="room-note">{{ t('c_rooms.roster_preview', { count: rosterLimit }, 'This view shows up to {count} assigned seats. Open the official workspace for the full membership. Other callers appear as they join.') }}</p>
        <section class="room-floor-controls" aria-labelledby="room-floor-heading">
            <h2 id="room-floor-heading">{{ text('floor_heading', 'Speaking floor') }}</h2>
            <p role="status">{{ floorName ? text('recognized_speaker', 'Recognized to speak: ') + floorName : text('floor_open', 'The floor is open.') }}</p>
            <p v-if="witnessName" role="status">{{ text('active_witness', 'At the witness stand: ') + witnessName }}</p>
            <p v-if="variant === 'court'" class="room-note">{{ text('witness_position_note', 'The presiding judge can invite a waiting participant to the witness stand. Formal testimony is recorded through the case workspace.') }}</p>
            <div class="room-links">
                <button v-if="floorControls.canRequest" type="button" class="btn btn--secondary" :disabled="floorForm.processing" @click="floorAction(floorControls.myHandRaised ? 'lower' : 'raise')">{{ floorControls.myHandRaised ? text('lower_hand', 'Lower my hand') : text('raise_hand', 'Raise my hand') }}</button>
                <template v-if="floorControls.canPreside">
                    <button type="button" class="btn btn--primary" :disabled="floorForm.processing || !floorControls.queue?.length" @click="floorAction('recognize')">{{ text('recognize_next', 'Recognize next speaker') }}</button>
                    <button type="button" class="btn btn--secondary" :disabled="floorForm.processing || !floorHolder" @click="floorAction('yield')">{{ text('yield_floor', 'Yield the floor') }}</button>
                </template>
            </div>
            <p v-for="(error, key) in floorForm.errors" :key="key" role="alert">{{ error }}</p>
            <ol v-if="floorControls.queue?.length" class="room-messages" aria-label="Waiting to speak">
                <li v-for="person in floorControls.queue" :key="person.handle">
                    <strong>{{ person.display_name || senderName(person.handle) }}</strong>
                    <div v-if="floorControls.canPreside" class="room-links">
                        <button type="button" class="btn btn--secondary" :disabled="floorForm.processing" :aria-label="'Recognize ' + (person.display_name || senderName(person.handle))" @click="floorAction('recognize', person.handle)">{{ text('recognize_person', 'Recognize') }}</button>
                        <button v-if="variant === 'court'" type="button" class="btn btn--secondary" :disabled="floorForm.processing" :aria-label="'Invite ' + (person.display_name || senderName(person.handle)) + ' to witness stand'" @click="floorAction('witness', person.handle)">{{ text('call_witness', 'Invite to witness stand') }}</button>
                    </div>
                </li>
            </ol>
            <p v-else class="room-note">{{ text('no_queue', 'No one is waiting to speak.') }}</p>
        </section>
        <section class="room-timeline" aria-labelledby="room-timeline-heading">
            <h2 id="room-timeline-heading">{{ text('room_discussion', 'Room discussion') }}</h2>
            <p class="room-note">{{ text('timeline_recent', 'The latest text messages from this room. Formal actions and filings belong in the official workspace.') }}</p>
            <p v-if="!timelineAvailable" role="status">{{ text('timeline_unavailable', 'The room conversation is not available right now.') }}</p>
            <p v-else-if="!messages.length">{{ text('timeline_empty', 'No text messages in this room yet.') }}</p>
            <ol v-else class="room-messages">
                <li v-for="message in messages" :key="message.id"><strong>{{ senderName(message.sender) }}</strong><p>{{ message.body }}</p></li>
            </ol>
            <form v-if="canJoin" class="room-compose" @submit.prevent="send">
                <label for="room-message">{{ text('discussion_message', 'Message to this room') }}</label>
                <textarea id="room-message" v-model="compose.body" rows="3" maxlength="4000" required :aria-invalid="Boolean(compose.errors.body)" :aria-describedby="compose.errors.body ? 'room-message-error' : undefined" />
                <p v-if="compose.errors.body" id="room-message-error" role="alert">{{ compose.errors.body }}</p>
                <button type="submit" class="btn btn--primary" :disabled="compose.processing || !compose.body.trim()">{{ text('send_message', 'Send message') }}</button>
            </form>
        </section>
    </PageScaffold>
</template>

<style scoped>
.room-links { display: flex; flex-wrap: wrap; gap: .65rem; }
.room-note { color: var(--gov-text-muted); max-width: 70ch; }
.room-timeline { border-block-start: 1px solid var(--gov-border); padding-block-start: 1rem; }
.room-messages { display: grid; gap: 1rem; list-style: none; padding: 0; }
.room-messages li { padding: .85rem; border: 1px solid var(--gov-border); border-radius: .5rem; overflow-wrap: anywhere; }
.room-messages p { white-space: pre-wrap; margin-block: .4rem 0; }
.room-compose { display: grid; gap: .5rem; margin-block-start: 1rem; }
.room-compose textarea { width: 100%; min-height: 6rem; padding: .7rem; font: inherit; color: inherit; background: transparent; border: 1px solid var(--gov-border); border-radius: .4rem; }
.room-compose button { justify-self: start; }
</style>
