<script setup>
/**
 * In-room controls: join the call, then toggle mic / camera / screen-share, pick the
 * active camera / microphone / speaker, and leave. Pure presentation over useVoiceRoom
 * — it emits intents; the room owns the state.
 */
import { Mic, MicOff, PhoneCall, PhoneOff, ScreenShare, ScreenShareOff, Video, VideoOff, Volume2 } from 'lucide-vue-next';
import Btn from '@/Components/Ui/Btn.vue';

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
const deviceLabel = (d, i, fallback) => d.label || `${fallback} ${i + 1}`;
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
                {{ connectionState === 'connecting' ? 'Connecting…' : 'Join room' }}
            </Btn>
            <Btn v-if="connectionState === 'connecting'" variant="ghost" @click="emit('leave')">Cancel joining</Btn>

            <template v-if="connected(connectionState)">
                <Btn v-if="audioBlocked" variant="primary" :disabled="connectionState !== 'connected'" @click="emit('start-audio')">
                    <Volume2 :size="16" class="mr-1.5 inline" aria-hidden="true" />
                    Enable room audio
                </Btn>
                <Btn :variant="micEnabled ? 'secondary' : 'ghost'" :pressed="micEnabled"
                    :disabled="connectionState !== 'connected' || mediaPending.mic"
                    :aria-label="micEnabled ? 'Mute microphone' : 'Turn microphone on'" @click="emit('toggle-mic')">
                    <component :is="micEnabled ? Mic : MicOff" :size="16" class="mr-1.5 inline" aria-hidden="true" />
                    {{ mediaPending.mic ? 'Updating microphone…' : (micEnabled ? 'Microphone on' : 'Microphone off') }}
                </Btn>
                <Btn :variant="cameraEnabled ? 'secondary' : 'ghost'" :pressed="cameraEnabled"
                    :disabled="connectionState !== 'connected' || mediaPending.camera"
                    :aria-label="cameraEnabled ? 'Turn camera off' : 'Turn camera on'" @click="emit('toggle-camera')">
                    <component :is="cameraEnabled ? Video : VideoOff" :size="16" class="mr-1.5 inline" aria-hidden="true" />
                    {{ mediaPending.camera ? 'Updating camera…' : (cameraEnabled ? 'Camera on' : 'Camera off') }}
                </Btn>
                <Btn :variant="screenShareEnabled ? 'secondary' : 'ghost'" :pressed="screenShareEnabled"
                    :disabled="connectionState !== 'connected' || mediaPending.screen" @click="emit('toggle-screen')">
                    <component :is="screenShareEnabled ? ScreenShareOff : ScreenShare" :size="16" class="mr-1.5 inline" aria-hidden="true" />
                    {{ mediaPending.screen ? 'Updating screen share…' : (screenShareEnabled ? 'Stop sharing' : 'Share screen') }}
                </Btn>
                <Btn variant="danger" @click="emit('leave')">
                    <PhoneOff :size="16" class="mr-1.5 inline" aria-hidden="true" />
                    Leave call
                </Btn>
            </template>
        </div>

        <p class="text-xs text-neutral-500" role="status">
            <template v-if="connectionState === 'connecting'">Connecting to room audio. Your microphone and camera are off.</template>
            <template v-else-if="connectionState === 'reconnecting'">Connection interrupted. Reconnecting…</template>
            <template v-else-if="connectionState === 'connected' && audioBlocked">Connected. Your browser paused audio; select Enable room audio to listen.</template>
            <template v-else-if="connectionState === 'connected' && !micEnabled && !cameraEnabled && !screenShareEnabled">Connected. Listening only.</template>
            <template v-else-if="connectionState === 'connected'">Connected. You control what you share.</template>
            <template v-else>Join to listen. Your microphone and camera stay off until you turn them on.</template>
        </p>

        <!-- Device pickers — appear once connected (labels need a granted permission). -->
        <div v-if="connected(connectionState)" class="flex flex-wrap items-center gap-3 text-xs text-neutral-500">
            <label v-if="devices.camera.length" class="inline-flex items-center gap-1" title="Camera">
                <Video :size="13" class="shrink-0 opacity-70" aria-hidden="true" />
                <span class="sr-only">Camera device</span>
                <select class="form-select rounded border-black/10 bg-transparent py-1 text-xs dark:border-white/10"
                    :disabled="connectionState !== 'connected' || mediaPending.camera"
                    :value="selectedDevices.camera" @change="emit('select-device', 'camera', $event.target.value)">
                    <option value="" disabled>Default camera</option>
                    <option v-for="(d, i) in devices.camera" :key="d.deviceId" :value="d.deviceId">{{ deviceLabel(d, i, 'Camera') }}</option>
                </select>
            </label>
            <label v-if="devices.mic.length" class="inline-flex items-center gap-1" title="Microphone">
                <Mic :size="13" class="shrink-0 opacity-70" aria-hidden="true" />
                <span class="sr-only">Microphone device</span>
                <select class="form-select rounded border-black/10 bg-transparent py-1 text-xs dark:border-white/10"
                    :disabled="connectionState !== 'connected' || mediaPending.mic"
                    :value="selectedDevices.mic" @change="emit('select-device', 'mic', $event.target.value)">
                    <option value="" disabled>Default microphone</option>
                    <option v-for="(d, i) in devices.mic" :key="d.deviceId" :value="d.deviceId">{{ deviceLabel(d, i, 'Microphone') }}</option>
                </select>
            </label>
            <label v-if="devices.speaker.length" class="inline-flex items-center gap-1" title="Speaker">
                <Volume2 :size="13" class="shrink-0 opacity-70" aria-hidden="true" />
                <span class="sr-only">Speaker device</span>
                <select class="form-select rounded border-black/10 bg-transparent py-1 text-xs dark:border-white/10"
                    :disabled="connectionState !== 'connected'"
                    :value="selectedDevices.speaker" @change="emit('select-device', 'speaker', $event.target.value)">
                    <option value="" disabled>Default speaker</option>
                    <option v-for="(d, i) in devices.speaker" :key="d.deviceId" :value="d.deviceId">{{ deviceLabel(d, i, 'Speaker') }}</option>
                </select>
            </label>
        </div>
    </div>
</template>
