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
    devices: { type: Object, default: () => ({ camera: [], mic: [], speaker: [] }) },
    selectedDevices: { type: Object, default: () => ({ camera: '', mic: '', speaker: '' }) },
});

const emit = defineEmits(['join', 'leave', 'toggle-mic', 'toggle-camera', 'toggle-screen', 'select-device']);

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
                <PhoneCall :size="16" class="mr-1.5 inline" />
                {{ connectionState === 'connecting' ? 'Connecting…' : 'Join voice' }}
            </Btn>

            <template v-else>
                <Btn :variant="micEnabled ? 'secondary' : 'danger'" :pressed="micEnabled" @click="emit('toggle-mic')">
                    <component :is="micEnabled ? Mic : MicOff" :size="16" class="mr-1.5 inline" />
                    {{ micEnabled ? 'Mic on' : 'Mic off' }}
                </Btn>
                <Btn :variant="cameraEnabled ? 'secondary' : 'ghost'" :pressed="cameraEnabled" @click="emit('toggle-camera')">
                    <component :is="cameraEnabled ? Video : VideoOff" :size="16" class="mr-1.5 inline" />
                    {{ cameraEnabled ? 'Camera on' : 'Camera off' }}
                </Btn>
                <Btn :variant="screenShareEnabled ? 'secondary' : 'ghost'" :pressed="screenShareEnabled" @click="emit('toggle-screen')">
                    <component :is="screenShareEnabled ? ScreenShareOff : ScreenShare" :size="16" class="mr-1.5 inline" />
                    {{ screenShareEnabled ? 'Stop share' : 'Share screen' }}
                </Btn>
                <Btn variant="danger" @click="emit('leave')">
                    <PhoneOff :size="16" class="mr-1.5 inline" />
                    Leave
                </Btn>
            </template>
        </div>

        <!-- Device pickers — appear once connected (labels need a granted permission). -->
        <div v-if="connected(connectionState)" class="flex flex-wrap items-center gap-3 text-xs text-neutral-500">
            <label v-if="devices.camera.length" class="inline-flex items-center gap-1" title="Camera">
                <Video :size="13" class="shrink-0 opacity-70" />
                <select class="form-select rounded border-black/10 bg-transparent py-1 text-xs dark:border-white/10"
                    :value="selectedDevices.camera" @change="emit('select-device', 'camera', $event.target.value)">
                    <option v-for="(d, i) in devices.camera" :key="d.deviceId" :value="d.deviceId">{{ deviceLabel(d, i, 'Camera') }}</option>
                </select>
            </label>
            <label v-if="devices.mic.length" class="inline-flex items-center gap-1" title="Microphone">
                <Mic :size="13" class="shrink-0 opacity-70" />
                <select class="form-select rounded border-black/10 bg-transparent py-1 text-xs dark:border-white/10"
                    :value="selectedDevices.mic" @change="emit('select-device', 'mic', $event.target.value)">
                    <option v-for="(d, i) in devices.mic" :key="d.deviceId" :value="d.deviceId">{{ deviceLabel(d, i, 'Microphone') }}</option>
                </select>
            </label>
            <label v-if="devices.speaker.length" class="inline-flex items-center gap-1" title="Speaker">
                <Volume2 :size="13" class="shrink-0 opacity-70" />
                <select class="form-select rounded border-black/10 bg-transparent py-1 text-xs dark:border-white/10"
                    :value="selectedDevices.speaker" @change="emit('select-device', 'speaker', $event.target.value)">
                    <option v-for="(d, i) in devices.speaker" :key="d.deviceId" :value="d.deviceId">{{ deviceLabel(d, i, 'Speaker') }}</option>
                </select>
            </label>
        </div>
    </div>
</template>
