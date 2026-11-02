<script setup>
/**
 * THE floating window: one box for the live call and its text chat. While a call is on and the person is
 * off the room's page, the window shows the call (state, video, controls) with the call room's chat
 * underneath, and a Back button to every other conversation. With no call it is the messenger alone.
 * Minimizing collapses it to a pill that keeps the call's mic and Leave in reach, plus the unread count.
 * Call state is in lib/callSession.js and chat state in lib/messengerStore.js, so both persist across pages.
 */
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ChevronDown, ChevronUp, MessageSquare, Mic, MicOff, PhoneOff, Video, VideoOff, Volume2, X } from 'lucide-vue-next';
import ParticipantTile from '@/Components/Civic/Room/ParticipantTile.vue';
import { personLabel } from '@/Components/Civic/Room/roomPresentation.js';
import { useCall } from '@/lib/callSession.js';
import * as store from '@/lib/messengerStore.js';

const { t, locale } = useI18n();
const call = useCall();
const page = usePage();
const v = call.voice;
const session = call.session;
const { panelOpen, rows, active, following, reachable, sendError } = store;
const thread = store.activeThread;

/* ------------------------------------------------------------------ call */
const onRoomPage = computed(() => {
    const here = String(page.url || '').split('?')[0];
    const there = String(session.value?.href || '').split('?')[0];
    return !!there && here === there;
});
// The call section shows only when a call is on and its room page is not already showing it.
const callOn = computed(() => !!session.value && !onRoomPage.value);
const status = computed(() => {
    if (call.phase.value === 'lost') return 'lost';
    if (call.phase.value === 'retrying' || call.recovering.value) return 'reconnecting';
    const s = v.connectionState.value;
    return s === 'reconnecting' ? 'reconnecting' : s === 'connecting' ? 'connecting' : 'connected';
});
const people = computed(() => v.participants.value.length);
const showVideo = ref(true);
const presenters = computed(() => v.participants.value.filter((p) => p.screenTrack));
const cameras = computed(() => v.participants.value.filter((p) => p.videoTrack));
const hasVideo = computed(() => presenters.value.length > 0 || cameras.value.length > 0);
const rejoin = () => session.value && call.startCall({ ...session.value });

// Minimized: a pill. Remembered on this device.
const MIN_KEY = 'cga.call.minimized';
const minimized = ref(false);
try { minimized.value = localStorage.getItem(MIN_KEY) === '1'; } catch { /* storage can be blocked */ }
function setMinimized(next) {
    minimized.value = next;
    try { localStorage.setItem(MIN_KEY, next ? '1' : '0'); } catch { /* ignore */ }
}
// With a call on, the window is open unless minimized; with no call it opens from the Messages button.
const expanded = computed(() => (callOn.value ? !minimized.value : panelOpen.value));
function closeWindow() { if (callOn.value) setMinimized(true); else store.togglePanel(); }
function openWindow() { if (callOn.value) setMinimized(false); else store.togglePanel(); }

/* ------------------------------------------------------------------ chat */
const draft = ref('');
const threadEl = ref(null);
const options = ref([]);
const adding = ref(false);
const current = computed(() => store.rowFor(active.value));
const messages = computed(() => thread.value?.messages ?? []);
const label = (sender) => String(sender || '').replace(/^@/, '').split(':')[0];
const mine = (m) => !!thread.value && m.sender === thread.value.my_mxid;
const when = (ms) => (ms ? new Date(Number(ms)).toLocaleTimeString(locale.value, { hour: '2-digit', minute: '2-digit' }) : '');
const ago = (ms) => (ms ? new Date(Number(ms)).toLocaleDateString(locale.value, { month: 'short', day: 'numeric' }) : '');
const unfollowable = (row) => row.kind === 'commons' && following.value.includes(row.key);
const addable = computed(() => options.value.filter((o) => !following.value.includes(o.key)));
const title = computed(() => (active.value ? (current.value?.title || thread.value?.conversation?.title) : (callOn.value ? (session.value?.title || t('c_dock.call_default', 'Live call')) : t('c_dock.messages', 'Messages'))));

watch(() => [messages.value.length, active.value, expanded.value], () => nextTick(() => { if (threadEl.value) threadEl.value.scrollTop = threadEl.value.scrollHeight; }));

async function submit() {
    const body = draft.value.trim();
    if (!body || !active.value) return;
    if (await store.send(active.value, body)) draft.value = '';
}
async function showAdd() {
    adding.value = !adding.value;
    if (adding.value) options.value = await store.publicOptions();
}
onMounted(() => { void store.start(); });
</script>

<template>
    <div class="dock-win">
        <section v-if="expanded" class="dock-win__window" :class="{ 'dock-win__window--wide': callOn && presenters.length }" role="dialog" :aria-label="title">
            <header class="dock-win__head">
                <span v-if="callOn" class="dock-win__dot" :class="'dock-win__dot--' + status" aria-hidden="true"></span>
                <button v-if="active" type="button" class="btn btn--ghost btn--sm" @click="store.showList()">{{ t('c_dock.back', 'Back') }}</button>
                <strong class="dock-win__title">{{ title }}</strong>
                <Link v-if="active && (current?.href || thread?.conversation?.href)" :href="current?.href || thread.conversation.href" class="btn btn--ghost btn--sm">{{ t('c_dock.open_page', 'Open page') }}</Link>
                <button type="button" class="btn btn--ghost btn--sm" :aria-expanded="true"
                    :aria-label="callOn ? t('c_dock.minimize', 'Minimize call panel') : t('c_dock.close', 'Close messages')" @click="closeWindow">
                    <component :is="callOn ? ChevronDown : X" :size="16" aria-hidden="true" />
                </button>
            </header>

            <!-- The call -->
            <div v-if="callOn" class="dock-win__call">
                <p class="dock-win__status" role="status">
                    <template v-if="status === 'lost'">{{ t('c_dock.lost', 'The call lost its connection.') }}</template>
                    <template v-else-if="status === 'reconnecting'">{{ t('c_dock.reconnecting', 'Reconnecting…') }}</template>
                    <template v-else-if="status === 'connecting'">{{ t('c_dock.connecting', 'Connecting…') }}</template>
                    <template v-else>{{ t('c_dock.people', { count: people }) }}</template>
                </p>
                <div v-if="hasVideo" class="dock-win__video">
                    <button type="button" class="btn btn--ghost btn--sm" :aria-expanded="showVideo" @click="showVideo = !showVideo">
                        {{ showVideo ? t('c_dock.video_hide', 'Hide video') : t('c_dock.video_show', 'Show video') }}
                    </button>
                    <div v-if="showVideo" class="dock-win__tiles">
                        <ParticipantTile v-for="p in presenters" :key="'screen-' + p.identity" class="dock-win__tile--screen" :identity="p.identity"
                            :display-name="personLabel(p)" :is-local="p.isLocal" :video-track="p.screenTrack" :audio-track="null" presenting />
                        <ParticipantTile v-for="p in cameras" :key="'cam-' + p.identity" :identity="p.identity" :display-name="personLabel(p)"
                            :is-local="p.isLocal" :is-speaking="p.isSpeaking" :video-track="p.videoTrack" :audio-track="p.audioTrack" />
                    </div>
                </div>
                <div class="dock-win__actions">
                    <template v-if="status === 'lost'">
                        <button type="button" class="btn btn--primary btn--sm" @click="rejoin">{{ t('c_dock.rejoin', 'Rejoin') }}</button>
                    </template>
                    <template v-else>
                        <button v-if="v.audioBlocked.value" type="button" class="btn btn--primary btn--sm" @click="v.startAudio()">
                            <Volume2 :size="14" aria-hidden="true" /> {{ t('c_dock.enable_audio', 'Enable audio') }}
                        </button>
                        <button type="button" class="btn btn--secondary btn--sm" :aria-pressed="v.micEnabled.value" :disabled="status !== 'connected' || v.mediaPending.value.mic"
                            :aria-label="v.micEnabled.value ? t('c_dock.mute', 'Mute microphone') : t('c_dock.unmute', 'Turn microphone on')" @click="v.toggleMic()">
                            <component :is="v.micEnabled.value ? Mic : MicOff" :size="14" aria-hidden="true" />
                        </button>
                        <button type="button" class="btn btn--secondary btn--sm" :aria-pressed="v.cameraEnabled.value" :disabled="status !== 'connected' || v.mediaPending.value.camera"
                            :aria-label="v.cameraEnabled.value ? t('c_dock.camera_off', 'Turn camera off') : t('c_dock.camera_on', 'Turn camera on')" @click="v.toggleCamera()">
                            <component :is="v.cameraEnabled.value ? Video : VideoOff" :size="14" aria-hidden="true" />
                        </button>
                    </template>
                    <Link :href="session.href || '/'" class="btn btn--ghost btn--sm">{{ t('c_dock.return', 'Return to room') }}</Link>
                    <button type="button" class="btn btn--danger btn--sm" :aria-label="t('c_dock.leave', 'Leave call')" @click="call.endCall()">
                        <PhoneOff :size="14" aria-hidden="true" />
                    </button>
                </div>
            </div>

            <p v-if="!reachable" class="dock-win__note" role="status">{{ t('c_dock.offline', 'Messages are not reachable right now. They will refresh when the connection returns.') }}</p>

            <!-- The chat: the list, or one conversation (the call's own room by default) -->
            <template v-if="!active">
                <p v-if="!rows.length" class="dock-win__note">{{ t('c_dock.none', 'No conversations yet. Start one from Messages, or follow a public room below.') }}</p>
                <ul class="dock-win__list">
                    <li v-for="row in rows" :key="row.key">
                        <button type="button" class="dock-win__row" @click="store.openConversation(row.key)">
                            <span class="dock-win__row-top">
                                <strong>{{ row.title }}</strong>
                                <span v-if="row.kind === 'commons'" class="dock-win__tag">{{ t('c_dock.public', 'Public') }}</span>
                                <span v-if="row.unread" class="dock-win__badge" :aria-label="t('c_dock.unread', { count: row.unread })">{{ row.unread }}</span>
                                <span class="dock-win__when">{{ ago(row.last_at) }}</span>
                            </span>
                            <span class="dock-win__preview">{{ row.preview || t('c_dock.no_messages', 'No messages yet') }}</span>
                        </button>
                        <button v-if="unfollowable(row)" type="button" class="btn btn--ghost btn--sm" @click="store.unfollow(row.key)">{{ t('c_dock.unfollow', 'Unfollow') }}</button>
                    </li>
                </ul>
                <div class="dock-win__foot">
                    <button type="button" class="btn btn--secondary btn--sm" :aria-expanded="adding" @click="showAdd">{{ t('c_dock.follow_public', 'Follow a public room') }}</button>
                    <Link href="/civic/rooms/new" class="btn btn--ghost btn--sm">{{ t('c_dock.new_message', 'New message') }}</Link>
                    <ul v-if="adding" class="dock-win__options">
                        <li v-if="!addable.length" class="dock-win__note">{{ t('c_dock.no_public', 'No other public rooms to follow.') }}</li>
                        <li v-for="o in addable" :key="o.key"><button type="button" class="btn btn--ghost btn--sm" @click="store.follow(o.key)">{{ o.title }}</button></li>
                    </ul>
                </div>
            </template>

            <template v-else>
                <div ref="threadEl" class="dock-win__thread" aria-live="polite">
                    <p v-if="!messages.length" class="dock-win__note">{{ t('c_dock.empty_thread', 'No messages yet.') }}</p>
                    <div v-for="m in messages" :key="m.event_id" class="dock-win__bubble" :class="{ 'dock-win__bubble--mine': mine(m) }">
                        <span v-if="!mine(m)" class="dock-win__from">{{ label(m.sender) }}</span>
                        <span class="dock-win__text">{{ m.body }}</span>
                        <span class="dock-win__when">{{ when(m.at) }}</span>
                    </div>
                </div>
                <form class="dock-win__compose" @submit.prevent="submit">
                    <label class="sr-only" for="dock-draft">{{ t('c_dock.message', 'Message') }}</label>
                    <textarea id="dock-draft" v-model="draft" rows="2" maxlength="20000" :placeholder="t('c_dock.placeholder', 'Write a message…')"
                        @keydown.enter.exact.prevent="submit"></textarea>
                    <button type="submit" class="btn btn--primary btn--sm" :disabled="store.isSending.value || !draft.trim()">{{ t('c_dock.send', 'Send') }}</button>
                </form>
                <p v-if="sendError" class="dock-win__note field-error" role="alert">{{ sendError }}</p>
            </template>
        </section>

        <!-- Collapsed: one pill. It carries the call's mic and Leave when a call is on. -->
        <div v-else class="dock-win__pill">
            <template v-if="callOn">
                <span class="dock-win__dot" :class="'dock-win__dot--' + status" aria-hidden="true"></span>
                <span class="dock-win__pill-title">{{ session.title || t('c_dock.call_default', 'Live call') }}</span>
                <button v-if="status === 'connected'" type="button" class="btn btn--secondary btn--sm" :aria-pressed="v.micEnabled.value" :disabled="v.mediaPending.value.mic"
                    :aria-label="v.micEnabled.value ? t('c_dock.mute', 'Mute microphone') : t('c_dock.unmute', 'Turn microphone on')" @click="v.toggleMic()">
                    <component :is="v.micEnabled.value ? Mic : MicOff" :size="14" aria-hidden="true" />
                </button>
                <button v-else-if="status === 'lost'" type="button" class="btn btn--primary btn--sm" @click="rejoin">{{ t('c_dock.rejoin', 'Rejoin') }}</button>
                <span v-else class="dock-win__note-inline" role="status">{{ t('c_dock.reconnecting', 'Reconnecting…') }}</span>
                <button type="button" class="btn btn--danger btn--sm" :aria-label="t('c_dock.leave', 'Leave call')" @click="call.endCall()">
                    <PhoneOff :size="14" aria-hidden="true" />
                </button>
            </template>
            <button type="button" class="btn btn--secondary btn--sm dock-win__launch" :aria-expanded="false" :aria-label="callOn ? t('c_dock.expand', 'Expand call panel') : t('c_dock.messages', 'Messages')" @click="openWindow">
                <component :is="callOn ? ChevronUp : MessageSquare" :size="16" aria-hidden="true" />
                <template v-if="!callOn">{{ t('c_dock.messages', 'Messages') }}</template>
                <span v-if="store.unreadTotal.value" class="dock-win__badge" :aria-label="t('c_dock.unread', { count: store.unreadTotal.value })">{{ store.unreadTotal.value }}</span>
            </button>
        </div>
    </div>
</template>

<style scoped>
.dock-win { display: flex; flex-direction: column; align-items: flex-end; max-inline-size: 100%; }
.dock-win__window { background: var(--gov-surface, #101827); color: var(--gov-fg, #f3f4f6); border: 1px solid var(--gov-border, #334155); border-radius: 0.75rem;
    box-shadow: 0 8px 28px rgb(0 0 0 / 0.4); inline-size: min(24rem, calc(100vw - 1.5rem)); max-block-size: min(40rem, 82vh); display: flex; flex-direction: column; overflow: hidden; }
.dock-win__window--wide { inline-size: min(30rem, calc(100vw - 1.5rem)); }
.dock-win__head { display: flex; align-items: center; gap: 0.4rem; padding: 0.5rem 0.6rem; border-block-end: 1px solid var(--gov-border, #334155); }
.dock-win__title { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.dock-win__dot { inline-size: 0.6rem; block-size: 0.6rem; border-radius: 50%; background: #22c55e; flex: none; }
.dock-win__dot--reconnecting, .dock-win__dot--connecting { background: #f59e0b; }
.dock-win__dot--lost { background: #ef4444; }
.dock-win__call { padding: 0.5rem 0.6rem; border-block-end: 1px solid var(--gov-border, #334155); overflow-y: auto; flex: none; max-block-size: 55%; }
.dock-win__status { margin: 0 0 0.4rem; font-size: 0.85rem; opacity: 0.85; }
.dock-win__video { margin-block-end: 0.5rem; }
.dock-win__tiles { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.4rem; margin-block-start: 0.35rem; }
.dock-win__tiles :deep(.dock-win__tile--screen) { grid-column: 1 / -1; }
.dock-win__actions { display: flex; flex-wrap: wrap; gap: 0.4rem; align-items: center; }
.dock-win__note { margin: 0; padding: 0.6rem 0.75rem; font-size: 0.85rem; opacity: 0.85; }
.dock-win__note-inline { font-size: 0.8rem; opacity: 0.85; white-space: nowrap; }
.dock-win__list { list-style: none; margin: 0; padding: 0; overflow-y: auto; }
.dock-win__list li { display: flex; align-items: center; border-block-end: 1px solid var(--gov-border, #334155); }
.dock-win__row { flex: 1; min-inline-size: 0; text-align: start; background: none; border: 0; color: inherit; padding: 0.6rem 0.75rem; cursor: pointer; display: grid; gap: 0.15rem; }
.dock-win__row:hover, .dock-win__row:focus-visible { background: rgb(255 255 255 / 0.06); }
.dock-win__row-top { display: flex; align-items: center; gap: 0.4rem; min-inline-size: 0; }
.dock-win__row-top strong { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.dock-win__preview { font-size: 0.85rem; opacity: 0.75; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.dock-win__tag { font-size: 0.7rem; border: 1px solid currentColor; border-radius: 999px; padding: 0 0.4rem; opacity: 0.8; }
.dock-win__badge { background: #2563eb; color: #fff; border-radius: 999px; font-size: 0.72rem; padding: 0 0.4rem; min-inline-size: 1.2rem; text-align: center; }
.dock-win__when { margin-inline-start: auto; font-size: 0.72rem; opacity: 0.6; }
.dock-win__foot { padding: 0.5rem 0.6rem; display: flex; flex-wrap: wrap; gap: 0.4rem; }
.dock-win__options { list-style: none; margin: 0; padding: 0; inline-size: 100%; display: flex; flex-wrap: wrap; gap: 0.3rem; }
.dock-win__thread { flex: 1; overflow-y: auto; padding: 0.6rem; display: flex; flex-direction: column; gap: 0.4rem; min-block-size: 8rem; }
.dock-win__bubble { align-self: flex-start; max-inline-size: 85%; background: rgb(255 255 255 / 0.08); border-radius: 0.75rem; padding: 0.35rem 0.6rem; display: grid; gap: 0.1rem; overflow-wrap: anywhere; }
.dock-win__bubble--mine { align-self: flex-end; background: #1d4ed8; color: #fff; }
.dock-win__from { font-size: 0.72rem; opacity: 0.7; }
.dock-win__text { white-space: pre-wrap; }
.dock-win__compose { display: flex; gap: 0.4rem; padding: 0.5rem 0.6rem; border-block-start: 1px solid var(--gov-border, #334155); align-items: flex-end; }
.dock-win__compose textarea { flex: 1; resize: none; background: rgb(255 255 255 / 0.06); color: inherit; border: 1px solid var(--gov-border, #334155); border-radius: 0.5rem; padding: 0.4rem; }
.dock-win__pill { display: inline-flex; align-items: center; gap: 0.4rem; max-inline-size: 100%; background: var(--gov-surface, #101827); color: var(--gov-fg, #f3f4f6);
    border: 1px solid var(--gov-border, #334155); border-radius: 999px; padding: 0.3rem 0.4rem 0.3rem 0.7rem; box-shadow: 0 4px 16px rgb(0 0 0 / 0.35); }
.dock-win__pill-title { max-inline-size: 9rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 600; }
.dock-win__launch { display: inline-flex; align-items: center; gap: 0.4rem; }
@media (max-width: 40rem) { .dock-win__window { inline-size: calc(100vw - 1rem); max-block-size: 75vh; } }
</style>
