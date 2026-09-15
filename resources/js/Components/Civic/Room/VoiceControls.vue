<script setup>
/**
 * In-room controls: join the call, then toggle mic / camera / screen-share, pick the
 * active camera / microphone / speaker, and leave. Pure presentation over useVoiceRoom
 * — it emits intents; the room owns the state.
 */
import { Mic, MicOff, PhoneCall, PhoneOff, ScreenShare, ScreenShareOff, Video, VideoOff, Volume2 } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import Btn from '@/Components/Ui/Btn.vue';

const { t } = useI18n();

defineProps({
    connectionState: { type: String, default: 'disconnected' },
    micEnabled: { type: Boolean, default: false },
    cameraEnabled: { type: Boolean, default: false },
    screenShareEnabled: { type: Boolean, default: false },
    mediaPending: { type: Object, default: () => ({ mic: false, camera: false, screen: false }) },
    audioBlocked: { type: Boolean, default: false },
    devices: { type: Object, default: () => ({ camera: [], mic: [], speaker: [] }) },
    selectedDevices: { type: Object, default: () => ({ camera: '', mic: '', speaker: '' }) },
});

const emit = defineEmits(['join', 'leave', 'toggle-mic', 'toggle-camera', 'toggle-screen', 'select-device', 'start-audio']);

const connected = (s) => s === 'connected' || s === 'reconnecting';
// The browser device label wins. The fallback is a resolved, translated string.
const deviceLabel = (d, resolved) => d.label || resolved;
</script>

<template>
    <div class="space-y-2">
        <div class="flex flex-wrap items-center gap-2">
            <Btn
                v-if="!connected(connectionState)"
                variant="primary"
                :disabled="connectionState === 'connecting'"
                @click="emit('join')"
            >
                <PhoneCall :size="16" class="mr-1.5 inline" aria-hidden="true" />
                {{ connectionState === 'connecting' ? t('c_civic_components.voice_controls.connecting', 'Connecting…') : t('c_civic_components.voice_controls.join_room', 'Join room') }}
            </Btn>
            <Btn v-if="connectionState === 'connecting'" variant="ghost" @click="emit('leave')">{{ t('c_civic_components.voice_controls.cancel_joining', 'Cancel joining') }}</Btn>

            <template v-if="connected(connectionState)">
                <Btn v-if="audioBlocked" variant="primary" :disabled="connectionState !== 'connected'" @click="emit('start-audio')">
                    <Volume2 :size="16" class="mr-1.5 inline" aria-hidden="true" />
                    {{ t('c_civic_components.voice_controls.enable_room_audio', 'Enable room audio') }}
                </Btn>
                <Btn :variant="micEnabled ? 'secondary' : 'ghost'" :pressed="micEnabled"
                    :disabled="connectionState !== 'connected' || mediaPending.mic"
                    :aria-label="micEnabled ? t('c_civic_components.voice_controls.mic_mute', 'Mute microphone') : t('c_civic_components.voice_controls.mic_turn_on', 'Turn microphone on')" @click="emit('toggle-mic')">
                    <component :is="micEnabled ? Mic : MicOff" :size="16" class="mr-1.5 inline" aria-hidden="true" />
                    {{ mediaPending.mic ? t('c_civic_components.voice_controls.mic_updating', 'Updating microphone…') : (micEnabled ? t('c_civic_components.voice_controls.mic_on', 'Microphone on') : t('c_civic_components.voice_controls.mic_off', 'Microphone off')) }}
                </Btn>
                <Btn :variant="cameraEnabled ? 'secondary' : 'ghost'" :pressed="cameraEnabled"
                    :disabled="connectionState !== 'connected' || mediaPending.camera"
                    :aria-label="cameraEnabled ? t('c_civic_components.voice_controls.camera_turn_off', 'Turn camera off') : t('c_civic_components.voice_controls.camera_turn_on', 'Turn camera on')" @click="emit('toggle-camera')">
                    <component :is="cameraEnabled ? Video : VideoOff" :size="16" class="mr-1.5 inline" aria-hidden="true" />
                    {{ mediaPending.camera ? t('c_civic_components.voice_controls.camera_updating', 'Updating camera…') : (cameraEnabled ? t('c_civic_components.voice_controls.camera_on', 'Camera on') : t('c_civic_components.voice_controls.camera_off', 'Camera off')) }}
                </Btn>
                <Btn :variant="screenShareEnabled ? 'secondary' : 'ghost'" :pressed="screenShareEnabled"
                    :disabled="connectionState !== 'connected' || mediaPending.screen" @click="emit('toggle-screen')">
                    <component :is="screenShareEnabled ? ScreenShareOff : ScreenShare" :size="16" class="mr-1.5 inline" aria-hidden="true" />
                    {{ mediaPending.screen ? t('c_civic_components.voice_controls.screen_updating', 'Updating screen share…') : (screenShareEnabled ? t('c_civic_components.voice_controls.screen_stop', 'Stop sharing') : t('c_civic_components.voice_controls.screen_share', 'Share screen')) }}
                </Btn>
                <Btn variant="danger" @click="emit('leave')">
                    <PhoneOff :size="16" class="mr-1.5 inline" aria-hidden="true" />
                    {{ t('c_civic_components.voice_controls.leave_call', 'Leave call') }}
                </Btn>
            </template>
        </div>

        <p class="text-xs text-gray-300" role="status">
            <template v-if="connectionState === 'connecting'">{{ t('c_civic_components.voice_controls.status_connecting', 'Connecting to room audio. Your microphone and camera are off.') }}</template>
            <template v-else-if="connectionState === 'reconnecting'">{{ t('c_civic_components.voice_controls.status_reconnecting', 'Connection interrupted. Reconnecting…') }}</template>
            <template v-else-if="connectionState === 'connected' && audioBlocked">{{ t('c_civic_components.voice_controls.status_audio_blocked', 'Connected. Your browser paused audio; select Enable room audio to listen.') }}</template>
            <template v-else-if="connectionState === 'connected' && !micEnabled && !cameraEnabled && !screenShareEnabled">{{ t('c_civic_components.voice_controls.status_listening_only', 'Connected. Listening only.') }}</template>
            <template v-else-if="connectionState === 'connected'">{{ t('c_civic_components.voice_controls.status_connected', 'Connected. You control what you share.') }}</template>
            <template v-else>{{ t('c_civic_components.voice_controls.status_join', 'Join to listen. Your microphone and camera stay off until you turn them on.') }}</template>
        </p>

        <!-- Device pickers — appear once connected (labels need a granted permission). -->
        <div v-if="connected(connectionState)" class="flex flex-wrap items-center gap-3 text-xs text-gray-300">
            <label v-if="devices.camera.length" class="inline-flex items-center gap-1" :title="t('c_civic_components.voice_controls.camera', 'Camera')">
                <Video :size="13" class="shrink-0 opacity-70" aria-hidden="true" />
                <span class="sr-only">{{ t('c_civic_components.voice_controls.camera_device', 'Camera device') }}</span>
                <select class="form-select rounded border-black/10 bg-transparent py-1 text-xs dark:border-white/10"
                    :disabled="connectionState !== 'connected' || mediaPending.camera"
                    :value="selectedDevices.camera" @change="emit('select-device', 'camera', $event.target.value)">
                    <option value="" disabled>{{ t('c_civic_components.voice_controls.default_camera', 'Default camera') }}</option>
                    <option v-for="(d, i) in devices.camera" :key="d.deviceId" :value="d.deviceId">{{ deviceLabel(d, t('c_civic_components.voice_controls.device_camera', { n: i + 1 })) }}</option>
                </select>
            </label>
            <label v-if="devices.mic.length" class="inline-flex items-center gap-1" :title="t('c_civic_components.voice_controls.microphone', 'Microphone')">
                <Mic :size="13" class="shrink-0 opacity-70" aria-hidden="true" />
                <span class="sr-only">{{ t('c_civic_components.voice_controls.microphone_device', 'Microphone device') }}</span>
                <select class="form-select rounded border-black/10 bg-transparent py-1 text-xs dark:border-white/10"
                    :disabled="connectionState !== 'connected' || mediaPending.mic"
                    :value="selectedDevices.mic" @change="emit('select-device', 'mic', $event.target.value)">
                    <option value="" disabled>{{ t('c_civic_components.voice_controls.default_microphone', 'Default microphone') }}</option>
                    <option v-for="(d, i) in devices.mic" :key="d.deviceId" :value="d.deviceId">{{ deviceLabel(d, t('c_civic_components.voice_controls.device_microphone', { n: i + 1 })) }}</option>
                </select>
            </label>
            <label v-if="devices.speaker.length" class="inline-flex items-center gap-1" :title="t('c_civic_components.voice_controls.speaker', 'Speaker')">
                <Volume2 :size="13" class="shrink-0 opacity-70" aria-hidden="true" />
                <span class="sr-only">{{ t('c_civic_components.voice_controls.speaker_device', 'Speaker device') }}</span>
                <select class="form-select rounded border-black/10 bg-transparent py-1 text-xs dark:border-white/10"
                    :disabled="connectionState !== 'connected'"
                    :value="selectedDevices.speaker" @change="emit('select-device', 'speaker', $event.target.value)">
                    <option value="" disabled>{{ t('c_civic_components.voice_controls.default_speaker', 'Default speaker') }}</option>
                    <option v-for="(d, i) in devices.speaker" :key="d.deviceId" :value="d.deviceId">{{ deviceLabel(d, t('c_civic_components.voice_controls.device_speaker', { n: i + 1 })) }}</option>
                </select>
            </label>
        </div>
    </div>
</template>
