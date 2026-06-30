<script setup>
/**
 * Civic/PrivateRoom — a user-owned PRIVATE room (group / DM): text + voice/video, member-gated. A
 * non-member only ever sees a "you need an invite" stub (the controller never sends them the room).
 * OFF the civic plane — no testimony, no public record. Reuses LiveRoom (the AV client) pointed at the
 * member-gated private token path, and InviteButton (kind=space) to invite a friend into the room.
 */
import { computed, onBeforeUnmount, onMounted } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import LiveRoom from '@/Components/Civic/Room/LiveRoom.vue';
import InviteButton from '@/Components/Invite/InviteButton.vue';
import Card from '@/Components/Ui/Card.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Field from '@/Components/Ui/Field.vue';
import { requestPrivateVoiceToken } from '@/lib/deviceIdentity.js';

const props = defineProps({
    locked: { type: Boolean, default: false },
    room: { type: Object, required: true },       // { id, title, is_owner }
    roomId: { type: String, default: null },       // the Matrix room id (the call/text channel)
    reachable: { type: Boolean, default: true },
    messages: { type: Array, default: () => [] },
    members: { type: Array, default: () => [] },
    myMxid: { type: String, default: null },
    myUserId: { type: String, default: null },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);

// The private-room token requester — member-gated + local SFU, so no device attestation (commons-only).
const tokenRequester = ({ room }) => requestPrivateVoiceToken({ room });

const compose = useForm({ body: '' });
function submit() {
    if (!props.roomId) return;
    compose.post(`/civic/rooms/${props.room.id}/post`, { preserveScroll: true, onSuccess: () => compose.reset('body') });
}

function senderLabel(sender) {
    if (!sender) return 'member';
    return String(sender).replace(/^@/, '').split(':')[0];
}
function mine(m) {
    return props.myMxid !== null && m.sender === props.myMxid;
}
function leave() {
    router.post(`/civic/rooms/${props.room.id}/leave`);
}

// Live text — poll the timeline (the commons pattern); pause on a hidden tab + during an in-flight post.
const POLL_MS = 5000;
let pollTimer = null;
function poll() {
    if (props.locked || !props.roomId) return;
    if (typeof document !== 'undefined' && document.hidden) return;
    if (compose.processing) return;
    router.reload({ only: ['messages', 'reachable'], preserveScroll: true, preserveState: true });
}
function startPolling() {
    stopPolling();
    if (!props.locked && props.roomId) pollTimer = window.setInterval(poll, POLL_MS);
}
function stopPolling() {
    if (pollTimer !== null) {
        window.clearInterval(pollTimer);
        pollTimer = null;
    }
}
function onVisibility() {
    if (document.hidden) stopPolling();
    else { poll(); startPolling(); }
}
onMounted(() => {
    startPolling();
    document.addEventListener('visibilitychange', onVisibility);
});
onBeforeUnmount(() => {
    stopPolling();
    document.removeEventListener('visibilitychange', onVisibility);
});
</script>

<template>
    <Head :title="locked ? 'Private room' : room.title" />

    <div v-if="locked" class="mx-auto max-w-lg space-y-3 py-16 text-center">
        <h1 class="text-xl font-semibold">This is a private room</h1>
        <p class="opacity-70">You need an invite to join. Ask whoever shared it to send you a fresh link.</p>
        <Link href="/civic/rooms" class="inline-block underline">Back to your rooms</Link>
    </div>

    <div v-else class="space-y-4">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">{{ room.title }}</h1>
                <p class="text-sm opacity-70">
                    A private room — only invited people can see this. Off the public record: nothing here is
                    testimony or sealed.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <InviteButton :spec="{ kind: 'space', space_id: room.id }" label="Invite a friend" />
                <Btn v-if="!room.is_owner" variant="ghost" size="sm" @click="leave">Leave</Btn>
            </div>
        </header>

        <div v-if="flashStatus" class="text-sm text-emerald-700 dark:text-emerald-400">{{ flashStatus }}</div>

        <LiveRoom
            v-if="roomId && myMxid && myUserId"
            :jurisdiction-id="room.id"
            :room="roomId"
            :pseudonym="myMxid"
            :subject-user-id="myUserId"
            :token-requester="tokenRequester"
        />

        <Card v-if="members.length">
            <h3 class="mb-2 text-sm font-semibold">In this room</h3>
            <ul class="flex flex-wrap gap-2 text-xs">
                <li v-for="(m, i) in members" :key="i" class="rounded bg-black/5 px-2 py-1 dark:bg-white/10">
                    @{{ m.handle }}<span v-if="m.role === 'owner'" class="opacity-60"> · owner</span>
                </li>
            </ul>
        </Card>

        <Card v-if="roomId">
            <h3 class="mb-3 text-base font-semibold">Messages</h3>
            <p v-if="messages.length === 0" class="py-6 text-center text-sm opacity-70">
                No messages yet{{ reachable ? '' : ' (offline)' }}.
            </p>
            <ul v-else class="space-y-3">
                <li v-for="m in messages" :key="m.event_id" class="border-b border-black/5 pb-2 last:border-0 dark:border-white/10">
                    <div class="text-sm font-medium">
                        {{ senderLabel(m.sender) }}<span v-if="mine(m)" class="text-xs opacity-60"> · you</span>
                    </div>
                    <p class="mt-1 whitespace-pre-wrap">{{ m.body }}</p>
                </li>
            </ul>
        </Card>
        <Card v-else>
            <p class="text-sm opacity-70">The live channel for this room isn’t up yet — try again shortly.</p>
        </Card>

        <Card v-if="roomId">
            <form @submit.prevent="submit" class="space-y-2">
                <Field label="Message" :error="compose.errors.body">
                    <template #control="{ id, invalid, describedBy }">
                        <textarea
                            :id="id"
                            v-model="compose.body"
                            rows="2"
                            class="form-textarea w-full"
                            maxlength="20000"
                            placeholder="Say something…"
                            :aria-invalid="invalid ? 'true' : undefined"
                            :aria-describedby="describedBy"
                        ></textarea>
                    </template>
                </Field>
                <Btn type="submit" variant="primary" :disabled="compose.processing || !compose.body.trim()">Send</Btn>
            </form>
        </Card>
    </div>
</template>
