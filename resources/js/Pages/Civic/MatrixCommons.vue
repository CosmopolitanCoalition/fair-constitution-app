<script setup>
/**
 * Civic/MatrixCommons — Phase K-3 (K3-L), the embedded client for the LIVE commons over the Matrix
 * mesh (Plane B), the counterpart to the K-1 Plane-A record views. Reads the appservice-backed
 * timeline; posting is residency-only + pseudonymous; in the halls you may file your OWN message as
 * testimony (the Plane B → Plane A seal). Senders are pseudonymous @u-<handle> mxids by construction —
 * never a legal name. A down homeserver degrades to an empty timeline (a notice, never a broken page).
 * Everything here is the SELF-HOSTED in-app client — there is no external Matrix client in this system.
 */
import { computed, onBeforeUnmount, onMounted } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import FormCard from '@/Components/Surface/FormCard.vue';
import LiveRoom from '@/Components/Civic/Room/LiveRoom.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import Field from '@/Components/Ui/Field.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

const props = defineProps({
    surface: { type: Object, required: true },
    spaceType: { type: String, required: true },
    isHalls: { type: Boolean, default: false },
    jurisdictionId: { type: String, default: '' },
    roomId: { type: String, default: null },
    reachable: { type: Boolean, default: true },
    messages: { type: Array, default: () => [] },
    jurisdictions: { type: Array, default: () => [] },
    isAssociated: { type: Boolean, default: false },
    myMxid: { type: String, default: null },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);
// The player's OWN id — the device signs the voice request over it (deviceIdentity).
const myUserId = computed(() => page.props.auth?.user?.id ?? null);

const basePath = computed(() => (props.isHalls ? '/civic/commons/halls' : '/civic/commons/square'));

function switchJurisdiction(id) {
    router.get(basePath.value, { jurisdiction: id }, { preserveState: false, preserveScroll: true });
}

const compose = useForm({ jurisdiction_id: props.jurisdictionId, room_id: props.roomId, body: '' });
function submit() {
    if (!props.roomId) return;
    compose.post('/civic/commons/post', { preserveScroll: true, onSuccess: () => compose.reset('body') });
}

function fileTestimony(message) {
    router.post('/civic/commons/testimony', {
        room_id: props.roomId,
        event_id: message.event_id,
    }, { preserveScroll: true });
}

// The pseudonymous localpart for display (@u-handle:domain -> u-handle). Never a legal name.
function senderLabel(sender) {
    if (!sender) return 'resident';
    return String(sender).replace(/^@/, '').split(':')[0];
}
function mine(message) {
    return props.myMxid !== null && message.sender === props.myMxid;
}

// Live timeline. The page renders a server snapshot of the room; to make it a LIVE commons we poll
// for new messages via an Inertia partial reload — it re-runs ONLY the `messages`/`reachable` props
// server-side (reusing the appservice read + mapping) and patches them in without touching the
// compose form or the AV call. Dependency-free and works through mesh-reach; pauses on a hidden tab.
const POLL_MS = 5000;
let pollTimer = null;
function pollTimeline() {
    if (typeof document !== 'undefined' && document.hidden) return;
    if (!props.roomId) return;
    if (compose.processing) return; // don't let a poll cancel the player's in-flight post
    router.reload({ only: ['messages', 'reachable'], preserveScroll: true, preserveState: true });
}
function startPolling() {
    stopPolling();
    if (props.roomId) pollTimer = window.setInterval(pollTimeline, POLL_MS);
}
function stopPolling() {
    if (pollTimer !== null) {
        window.clearInterval(pollTimer);
        pollTimer = null;
    }
}
function onVisibility() {
    if (document.hidden) {
        stopPolling();
    } else {
        pollTimeline();
        startPolling();
    }
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
    <PageScaffold :surface="surface">
        <template #intro>
            The <strong>live commons</strong> runs over the Matrix mesh (Plane B) and is <strong>open</strong> —
            any player may read, speak, and join the call (Art. I free movement &amp; equal treatment), always
            under a pseudonymous handle, never a legal name. Residency unlocks the governance powers, not the
            doorway: voting, candidacy, and sealing a statement as testimony.
            <template v-if="isHalls">
                In the halls, you can file your own message as <em>testimony</em> to seal it into the
                append-only record (Art. II §2) — that seal is residency-gated.
            </template>
        </template>

        <Banner v-if="flashStatus" tone="success" class="mb-4">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="danger" class="mb-4">{{ constitutionError }}</Banner>

        <Card v-if="jurisdictions.length > 1" class="mb-4">
            <label class="block text-sm font-medium mb-1">Jurisdiction</label>
            <select class="form-select w-full" :value="jurisdictionId" @change="switchJurisdiction($event.target.value)">
                <option v-for="j in jurisdictions" :key="j.id" :value="j.id">{{ j.name }}</option>
            </select>
        </Card>

        <Banner v-if="!isAssociated" tone="info" class="mb-4">
            You have no residency association yet — confirm residency to enter a jurisdiction's commons.
            Voting, candidacy, and the testimony seal unlock with residency.
        </Banner>
        <Banner v-else-if="!roomId" tone="info" class="mb-4">
            This jurisdiction has no live {{ isHalls ? 'halls' : 'square' }} room yet (it provisions when the
            jurisdiction activates{{ isHalls ? ' and seats a legislature' : '' }}).
        </Banner>
        <Banner v-else-if="!reachable" tone="warning" class="mb-4">
            The homeserver is unreachable right now — the live timeline is temporarily empty. Your posts and
            the record plane are unaffected.
        </Banner>

        <LiveRoom
            v-if="roomId && myMxid && myUserId"
            :jurisdiction-id="jurisdictionId"
            :room="roomId"
            :pseudonym="myMxid"
            :subject-user-id="myUserId"
            class="mb-4"
        />

        <Card v-if="roomId" class="mb-4">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-base font-semibold">Timeline</h3>
            </div>

            <p v-if="messages.length === 0" class="text-sm opacity-70 py-6 text-center">
                No messages yet{{ reachable ? '' : ' (homeserver offline)' }}.
            </p>

            <ul v-else class="space-y-3">
                <li v-for="m in messages" :key="m.event_id" class="border-b last:border-0 pb-3">
                    <div class="flex items-center gap-2 text-sm">
                        <span class="font-medium">{{ senderLabel(m.sender) }}</span>
                        <StatusBadge v-if="m.seat" tone="info">{{ m.seat }}</StatusBadge>
                        <span v-if="mine(m)" class="text-xs opacity-60">you</span>
                    </div>
                    <p class="mt-1 whitespace-pre-wrap">{{ m.body }}</p>
                    <div v-if="isHalls && mine(m)" class="mt-1">
                        <Btn size="sm" variant="ghost" @click="fileTestimony(m)">File as testimony</Btn>
                    </div>
                </li>
            </ul>
        </Card>

        <FormCard v-if="roomId" title="Post to the live commons">
            <form @submit.prevent="submit" class="space-y-3">
                <Field label="Message" :error="compose.errors.body">
                    <textarea v-model="compose.body" rows="3" class="form-textarea w-full" maxlength="20000"
                        placeholder="Speak in the commons…"></textarea>
                </Field>
                <Btn type="submit" :disabled="compose.processing || !compose.body.trim()">Post</Btn>
            </form>
        </FormCard>
    </PageScaffold>
</template>
