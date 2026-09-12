<script setup>
/**
 * Civic/MatrixCommons — Phase K-3 (K3-L), the embedded client for the LIVE commons over the Matrix
 * mesh (Plane B), the counterpart to the K-1 Plane-A record views. Reads the appservice-backed
 * timeline; posting is open to signed-in players + pseudonymous; in the halls you may file your OWN message as
 * testimony (the Plane B → Plane A seal). Senders are pseudonymous @u-<handle> mxids by construction —
 * never a legal name. A down homeserver degrades to an empty timeline (a notice, never a broken page).
 * Everything here is the SELF-HOSTED in-app client — there is no external Matrix client in this system.
 */
import { computed, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useLiveRoom } from '@/composables/useLiveRoom';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';

import LiveRoom from '@/Components/Civic/Room/LiveRoom.vue';
import InviteButton from '@/Components/Invite/InviteButton.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import Field from '@/Components/Ui/Field.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { personLabel } from '@/Components/Civic/Room/roomPresentation.js';
import CommunityNav from '@/Components/Civic/CommunityNav.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    spaceType: { type: String, required: true },
    isHalls: { type: Boolean, default: false },
    jurisdictionId: { type: String, default: '' },
    selectedPlace: { type: Object, default: null },
    roomState: { type: String, default: 'not_ready' },
    roomId: { type: String, default: null },
    reachable: { type: Boolean, default: true },
    messages: { type: Array, default: () => [] },
    jurisdictions: { type: Array, default: () => [] },
    isAssociated: { type: Boolean, default: false },
    myMxid: { type: String, default: null },
    displayNames: { type: Object, default: () => ({}) },
});

const page = usePage();
const { t } = useI18n();
const text = (key, values = {}) => t('c_live_commons.' + key, values);
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? page.props.errors?.room ?? null);
// The player's OWN id — the device signs the voice request over it (deviceIdentity).
const myUserId = computed(() => page.props.auth?.user?.id ?? null);

const basePath = computed(() => (props.isHalls ? '/civic/commons/halls' : '/civic/commons/square'));
const roomHref = computed(() => basePath.value + (props.jurisdictionId ? '?jurisdiction=' + encodeURIComponent(props.jurisdictionId) : ''));
const roomsHref = computed(() => '/rooms' + (props.jurisdictionId ? '?jurisdiction=' + encodeURIComponent(props.jurisdictionId) : ''));

function switchJurisdiction(id) {
    if (!id) return;
    router.get(basePath.value, { jurisdiction: id }, { preserveState: false, preserveScroll: true });
}

const compose = useForm({ jurisdiction_id: props.jurisdictionId, room_id: props.roomId, body: '' });
watch(() => [props.jurisdictionId, props.roomId], ([jurisdictionId, roomId]) => {
    compose.jurisdiction_id = jurisdictionId;
    compose.room_id = roomId;
});
function submit() {
    if (!props.roomId || !myUserId.value) return;
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
    return personLabel({ identity: sender, display_name: props.displayNames[sender] });
}
function mine(message) {
    return props.myMxid !== null && message.sender === props.myMxid;
}

// Live timeline. The page renders a server snapshot of the room; to make it a
// LIVE commons we keep the `messages`/`reachable` props fresh via useLiveRoom —
// a 5s Inertia partial reload that patches them in without touching the compose
// form or the AV call, pauses on a hidden tab, and skips a tick while a post is
// in flight. Consolidated onto the shared store (W4 ⑦) — this IS the pattern
// useLiveRoom was extracted from, so behaviour is unchanged.
useLiveRoom({
    keys: ['messages', 'reachable', 'displayNames', 'roomId', 'roomState'],
    // The mount guard: no room ⇒ nothing to poll ('adjourned' never arms).
    isLive: () => (props.roomId ? 'open' : 'adjourned'),
    busy: () => compose.processing,
    cadenceMs: 5000,
});
</script>

<template>
    <PageScaffold :surface="surface">
        <CommunityNav :jurisdiction-id="jurisdictionId" />
        <template #intro>
            {{ text('intro') }}
            <template v-if="isHalls">{{ text('halls_intro') }}</template>
        </template>

        <nav v-if="selectedPlace" class="commons-context" :aria-label="text('place_navigation')">
            <strong>{{ selectedPlace.name }}</strong>
            <Link :href="roomsHref">{{ text('back_rooms') }}</Link>
            <Link :href="`/jurisdictions/${selectedPlace.slug}`">{{ text('place_overview') }}</Link>
            <Link href="/jurisdictions">{{ text('browse_world') }}</Link>
        </nav>

        <Banner v-if="flashStatus" tone="success" class="mb-4">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="danger" class="mb-4">{{ constitutionError }}</Banner>

        <Card v-if="jurisdictions.length" class="mb-4">
            <label for="commons-residence" class="block text-sm font-medium mb-1">{{ text('residence_shortcut') }}</label>
            <select id="commons-residence" class="form-select w-full" value="" @change="switchJurisdiction($event.target.value)">
                <option value="">{{ text('choose_residence') }}</option>
                <option v-for="j in jurisdictions" :key="j.id" :value="j.id">{{ j.name }}</option>
            </select>
        </Card>

        <Banner v-if="!selectedPlace" tone="info" class="mb-4">
            {{ text('choose_place') }}
            <Link href="/jurisdictions">{{ text('browse_world') }}</Link>
        </Banner>
        <Banner v-else-if="roomState === 'waiting_for_government'" tone="info" class="mb-4">
            {{ text('waiting_for_government') }}
            <Link :href="`/civic/commons/square?jurisdiction=${encodeURIComponent(jurisdictionId)}`">{{ text('open_square') }}</Link>
        </Banner>
        <Banner v-else-if="!reachable" tone="warning" class="mb-4">
            {{ text('unavailable') }}
            <Link :href="roomHref" preserve-state preserve-scroll>{{ text('retry') }}</Link>
        </Banner>
        <Banner v-else-if="!roomId" tone="info" class="mb-4">
            {{ text('not_ready') }}
            <Link :href="roomHref" preserve-state preserve-scroll>{{ text('retry') }}</Link>
        </Banner>
        <Banner v-if="selectedPlace && !myUserId" tone="info" class="mb-4">
            {{ text('guest') }}
            <Link href="/login">{{ text('sign_in') }}</Link>
        </Banner>

        <Card v-if="roomId && myUserId" class="mb-4">
            <div class="flex items-center justify-between gap-3 flex-wrap">
                <div>
                    <h3 class="text-base font-semibold">Invite someone to join you</h3>
                    <p class="text-sm opacity-70">
                        Share a link to this {{ isHalls ? 'hall' : 'square' }} and its live call — they can sign up and land right here.
                    </p>
                </div>
                <InviteButton
                    :spec="{ kind: 'call', jurisdiction_id: jurisdictionId, space: isHalls ? 'halls' : 'square' }"
                    label="Invite a friend"
                />
            </div>
        </Card>

        <LiveRoom
            v-if="roomId && myMxid && myUserId"
            :jurisdiction-id="jurisdictionId"
            :room="roomId"
            :pseudonym="myMxid"
            :subject-user-id="myUserId"
            :display-names="displayNames"
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

        <Card as="section" v-if="roomId && myUserId" title="Post to the live commons">
            <form @submit.prevent="submit" class="space-y-3">
                <Field label="Message" :error="compose.errors.body">
                    <template #control="{ id, invalid, describedBy }">
                        <textarea
                            :id="id"
                            v-model="compose.body"
                            rows="3"
                            class="form-textarea w-full"
                            maxlength="20000"
                            placeholder="Speak in the commons…"
                            :aria-invalid="invalid ? 'true' : undefined"
                            :aria-describedby="describedBy"
                        ></textarea>
                    </template>
                </Field>
                <Btn type="submit" :disabled="compose.processing || !compose.body.trim()">Post</Btn>
            </form>
        </Card>
    </PageScaffold>
</template>

<style scoped>
.commons-context { display: flex; align-items: center; flex-wrap: wrap; gap: .5rem 1rem; margin-block-end: 1rem; }
.commons-context a { display: inline-flex; align-items: center; min-height: 44px; }
.commons-context a:focus-visible { outline: 2px solid var(--gov-primary); outline-offset: 3px; }
</style>
