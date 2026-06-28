<script setup>
/**
 * In-room controls: join the call, then toggle mic / camera and leave. Pure
 * presentation over useVoiceRoom — it emits intents; the room owns the state.
 */
import { Mic, MicOff, PhoneCall, PhoneOff, Video, VideoOff } from 'lucide-vue-next';
import Btn from '@/Components/Ui/Btn.vue';

defineProps({
    connectionState: { type: String, default: 'disconnected' },
    micEnabled: { type: Boolean, default: false },
    cameraEnabled: { type: Boolean, default: false },
});

const emit = defineEmits(['join', 'leave', 'toggle-mic', 'toggle-camera']);

const connected = (s) => s === 'connected' || s === 'reconnecting';
</script>

<template>
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
            <Btn variant="danger" @click="emit('leave')">
                <PhoneOff :size="16" class="mr-1.5 inline" />
                Leave
            </Btn>
        </template>
    </div>
</template>
