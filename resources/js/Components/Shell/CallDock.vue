<script setup>
/**
 * The floating call panel. It shows the one live call (lib/callSession.js) on every page and
 * plays every remote voice, so leaving the room page never cuts the call. On the room's own page
 * the visible bar hides (the page has the full controls) but the audio keeps playing.
 * Cameras and shared screens show in the panel too, so a presentation stays in view on every page.
 */
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Mic, MicOff, PhoneOff, Video, VideoOff, Volume2 } from 'lucide-vue-next';
import RemoteAudio from '@/Components/Shell/RemoteAudio.vue';
import ParticipantTile from '@/Components/Civic/Room/ParticipantTile.vue';
import { personLabel } from '@/Components/Civic/Room/roomPresentation.js';
import { useCall } from '@/lib/callSession.js';

const { t } = useI18n();
const call = useCall();
const page = usePage();
const v = call.voice;

const session = call.session;
const onRoomPage = computed(() => {
    const here = String(page.url || '').split('?')[0];
    const there = String(session.value?.href || '').split('?')[0];
    return !!there && here === there;
});
const status = computed(() => {
    if (call.phase.value === 'lost') return 'lost';
    if (call.phase.value === 'retrying' || call.recovering.value) return 'reconnecting';
    const s = v.connectionState.value;
    return s === 'reconnecting' ? 'reconnecting' : s === 'connecting' ? 'connecting' : 'connected';
});
const remoteAudio = computed(() => v.participants.value.filter((p) => !p.isLocal).flatMap((p) => [
    { id: p.identity + ':mic', track: p.audioTrack },
    { id: p.identity + ':screen', track: p.screenAudioTrack },
]).filter((a) => a.track));
const people = computed(() => v.participants.value.length);
const showVideo = ref(true);
const presenters = computed(() => v.participants.value.filter((p) => p.screenTrack));
const cameras = computed(() => v.participants.value.filter((p) => p.videoTrack));
const hasVideo = computed(() => presenters.value.length > 0 || cameras.value.length > 0);
const rejoin = () => session.value && call.startCall({ ...session.value });
</script>

<template>
    <div v-if="session" class="call-dock-root">
        <!-- Remote voices always play here, whatever page is showing. -->
        <RemoteAudio v-for="a in remoteAudio" :key="a.id" :track="a.track" :sink="v.selectedDevices.value.speaker" />

        <section v-show="!onRoomPage" class="call-dock" :class="{ 'call-dock--wide': presenters.length }" role="region" :aria-label="t('c_dock.call_region', 'Live call')">
            <div class="call-dock__head">
                <span class="call-dock__dot" :class="'call-dock__dot--' + status" aria-hidden="true"></span>
                <Link :href="session.href || '/'" class="call-dock__title">{{ session.title || t('c_dock.call_default', 'Live call') }}</Link>
            </div>
            <p class="call-dock__status" role="status">
                <template v-if="status === 'lost'">{{ t('c_dock.lost', 'The call lost its connection.') }}</template>
                <template v-else-if="status === 'reconnecting'">{{ t('c_dock.reconnecting', 'Reconnecting…') }}</template>
                <template v-else-if="status === 'connecting'">{{ t('c_dock.connecting', 'Connecting…') }}</template>
                <template v-else>{{ t('c_dock.people', { count: people }) }}</template>
            </p>
            <div v-if="!onRoomPage && hasVideo" class="call-dock__video">
                <button type="button" class="btn btn--ghost btn--sm" :aria-expanded="showVideo" @click="showVideo = !showVideo">
                    {{ showVideo ? t('c_dock.video_hide', 'Hide video') : t('c_dock.video_show', 'Show video') }}
                </button>
                <div v-if="showVideo" class="call-dock__tiles">
                    <ParticipantTile v-for="p in presenters" :key="'screen-' + p.identity" class="call-dock__tile--screen" :identity="p.identity"
                        :display-name="personLabel(p)" :is-local="p.isLocal" :video-track="p.screenTrack" :audio-track="null" presenting />
                    <ParticipantTile v-for="p in cameras" :key="'cam-' + p.identity" :identity="p.identity" :display-name="personLabel(p)"
                        :is-local="p.isLocal" :is-speaking="p.isSpeaking" :video-track="p.videoTrack" :audio-track="p.audioTrack" />
                </div>
            </div>
            <div class="call-dock__actions">
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
        </section>
    </div>
</template>

<style scoped>
.call-dock { background: var(--gov-surface, #101827); color: var(--gov-fg, #f3f4f6); border: 1px solid var(--gov-border, #334155); border-radius: 0.75rem;
    padding: 0.6rem 0.75rem; box-shadow: 0 6px 24px rgb(0 0 0 / 0.35); max-inline-size: 22rem; inline-size: 100%; }
.call-dock--wide { max-inline-size: 28rem; }
.call-dock__video { margin-block-end: 0.5rem; }
.call-dock__tiles { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.4rem; margin-block-start: 0.35rem; }
.call-dock__tiles :deep(.call-dock__tile--screen) { grid-column: 1 / -1; }
.call-dock__head { display: flex; align-items: center; gap: 0.5rem; min-inline-size: 0; }
.call-dock__title { font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: inherit; }
.call-dock__dot { inline-size: 0.6rem; block-size: 0.6rem; border-radius: 50%; background: #22c55e; flex: none; }
.call-dock__dot--reconnecting, .call-dock__dot--connecting { background: #f59e0b; }
.call-dock__dot--lost { background: #ef4444; }
.call-dock__status { margin: 0.25rem 0 0.5rem; font-size: 0.85rem; opacity: 0.85; }
.call-dock__actions { display: flex; flex-wrap: wrap; gap: 0.4rem; align-items: center; }
</style>
