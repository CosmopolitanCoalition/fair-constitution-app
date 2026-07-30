<script setup>
/**
 * Civic/PrivateRoom — a user-owned PRIVATE room (group / DM): text + voice/video, member-gated, in
 * the v3 conversation chrome (.msg-thread / .msg-bubble). A non-member only ever sees a "you need an
 * invite" stub (the controller never sends them the room). OFF the civic plane — no testimony, no
 * public record. Reuses LiveRoom (the REAL AV client) pointed at the member-gated private token path,
 * and InviteButton (kind=space) to invite a friend into the room.
 *
 * Honest-empty: there is no live-presence signal, so no "live now" dot is shown; file attachments are
 * not wired through the Matrix bridge yet, so the composer is text (voice/video are the real thing
 * above) and no attachment chips are faked. The DM-vs-group distinction is not persisted — the real
 * member count carries it.
 */
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { useLiveRoom } from '@/composables/useLiveRoom';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import LiveRoom from '@/Components/Civic/Room/LiveRoom.vue';
import InviteButton from '@/Components/Invite/InviteButton.vue';
import Card from '@/Components/Ui/Card.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Field from '@/Components/Ui/Field.vue';
import Icon from '@/Components/Ui/Icon.vue';
import { requestPrivateVoiceToken } from '@/lib/deviceIdentity.js';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });

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

// A group (more than two people) shows each other speaker's handle above their bubble; a two-person
// DM does not (it would be noise). Member count is REAL; there is no persisted DM-vs-group kind.
const isGroup = computed(() => props.members.length > 2);
const memberLine = computed(() =>
    props.members.length <= 1 ? 'Just you' : `${props.members.length} people`,
);

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
function msgWhen(m) {
    if (!m || m.at == null) return '';
    const d = new Date(Number(m.at));
    if (Number.isNaN(d.getTime())) return '';
    return d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
}
function initials(title) {
    const t = (title || '?').trim();
    const parts = t.split(/\s+/).filter(Boolean);
    if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase();
    return t.slice(0, 2).toUpperCase();
}
function leave() {
    router.post(`/civic/rooms/${props.room.id}/leave`);
}

// Keep the thread pinned to its newest message (bubbles render oldest-first).
const threadEl = ref(null);
function scrollToEnd() {
    const el = threadEl.value;
    if (el) el.scrollTop = el.scrollHeight;
}
onMounted(() => nextTick(scrollToEnd));
watch(() => props.messages, () => nextTick(scrollToEnd));

// Live text — keep `messages`/`reachable` fresh via useLiveRoom (the shared store). A locked or
// room-less page never polls; a hidden tab pauses; an in-flight post skips the tick.
useLiveRoom({
    keys: ['messages', 'reachable'],
    isLive: () => (props.locked || !props.roomId ? 'adjourned' : 'open'),
    busy: () => compose.processing,
    cadenceMs: 5000,
});
</script>

<template>
    <Head :title="locked ? 'Private room' : room.title" />

    <div v-if="locked" class="mx-auto max-w-lg space-y-3 py-16 text-center">
        <h1 class="text-xl font-semibold">This is a private room</h1>
        <p class="opacity-70">You need an invite to join. Ask whoever shared it to send you a fresh link.</p>
        <Link href="/civic/rooms" class="inline-block underline">Back to your messages</Link>
    </div>

    <div v-else class="stack" style="gap: var(--space-4)">
        <header>
            <p class="eyebrow">People, together · a conversation</p>
        </header>

        <p v-if="flashStatus" class="gloss" role="status" style="color: var(--status-success-fg)">{{ flashStatus }}</p>

        <section class="card msg-thread-card">
            <div class="cluster" style="justify-content: space-between; align-items: flex-start; gap: var(--space-3)">
                <div class="cluster" style="gap: var(--space-3); align-items: center">
                    <span class="conv-avatar" aria-hidden="true">{{ initials(room.title) }}</span>
                    <div class="stack" style="gap: 0">
                        <h1 style="margin: 0; font-size: var(--text-lg)">{{ room.title }}</h1>
                        <span class="gloss">
                            <Icon :name="isGroup ? 'users' : 'user'" size="sm" /> {{ memberLine }}
                        </span>
                    </div>
                </div>
                <div class="cluster" style="gap: var(--space-2)">
                    <InviteButton :spec="{ kind: 'space', space_id: room.id }" label="Invite a friend" />
                    <Btn v-if="!room.is_owner" variant="ghost" size="sm" @click="leave">Leave</Btn>
                </div>
            </div>

            <!-- REAL voice/video — the same member-gated live-room client every room carries. -->
            <LiveRoom
                v-if="roomId && myMxid && myUserId"
                :jurisdiction-id="room.id"
                :room="roomId"
                :pseudonym="myMxid"
                :subject-user-id="myUserId"
                :token-requester="tokenRequester"
            />

            <div v-if="roomId" ref="threadEl" class="msg-thread" aria-label="Conversation" aria-live="polite">
                <p v-if="messages.length === 0" class="gloss" style="text-align: center; padding: var(--space-4)">
                    No messages yet{{ reachable ? '' : ' — this room is offline right now' }}.
                </p>
                <div
                    v-for="m in messages"
                    :key="m.event_id"
                    class="msg-bubble"
                    :class="{ 'msg-bubble--mine': mine(m) }"
                >
                    <span v-if="isGroup && !mine(m)" class="msg-from">{{ senderLabel(m.sender) }}</span>
                    <span class="msg-text">{{ m.body }}</span>
                    <span class="msg-when">{{ msgWhen(m) }}</span>
                </div>
            </div>
            <p v-else class="gloss">The live channel for this room isn’t up yet — try again shortly.</p>

            <div v-if="roomId" class="msg-composer">
                <form class="stack" style="gap: var(--space-2)" @submit.prevent="submit">
                    <Field label="Message" :error="compose.errors.body">
                        <template #control="{ id, invalid, describedBy }">
                            <textarea
                                :id="id"
                                v-model="compose.body"
                                rows="2"
                                class="field-input"
                                style="inline-size: 100%"
                                maxlength="20000"
                                placeholder="Write a message…"
                                :aria-invalid="invalid ? 'true' : undefined"
                                :aria-describedby="describedBy"
                            ></textarea>
                        </template>
                    </Field>
                    <div class="cluster" style="justify-content: space-between; gap: var(--space-2)">
                        <Btn type="submit" variant="primary" size="sm" :disabled="compose.processing || !compose.body.trim()">
                            <Icon name="arrow-right" size="sm" /> Send
                        </Btn>
                        <span class="gloss" style="margin: 0">Text and voice/video are live here; file attachments aren’t wired yet.</span>
                    </div>
                </form>
            </div>
        </section>

        <Card v-if="members.length" as="section" inset>
            <h2 style="font-size: var(--text-base); margin: 0 0 var(--space-2)">
                <Icon name="users" size="sm" /> In this room
            </h2>
            <div class="cluster" style="gap: var(--space-2); flex-wrap: wrap">
                <span v-for="(m, i) in members" :key="i" class="party-chip">
                    @{{ m.handle }}<span v-if="m.role === 'owner'" class="gloss"> · owner</span>
                </span>
            </div>
            <p class="gloss" style="margin-block-start: var(--space-2)">
                Anyone here can add or leave at any time. When the last person leaves, the conversation is gone.
            </p>
            <p style="margin-block-start: var(--space-2)">
                <Link href="/organizations" class="btn btn--ghost btn--sm">
                    <Icon name="building" size="sm" /> Make this a standing organization
                </Link>
            </p>
        </Card>

        <p><Link href="/civic/rooms" class="underline">All messages</Link></p>
    </div>
</template>
