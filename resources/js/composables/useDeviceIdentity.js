/* ============================================================================
   CGA — composables/useDeviceIdentity.js
   Vue entry point for the browser device-signing layer (lib/deviceIdentity.js).

   The device secret is generated + kept in the browser (no escrow); the public
   key is enrolled once. Components use this to request a cross-node voice token
   or to sign a traveling write, without touching the crypto directly.

   Usage:
     const { devicePublicKey, enroll, requestVoiceToken } = useDeviceIdentity();
     await enroll();                                  // once, idempotent
     const voice = await requestVoiceToken({ jurisdictionId, room, pseudonym, subjectUserId });
   ============================================================================ */

import { ref } from 'vue';
import {
    devicePublicKey as readDevicePublicKey,
    enrollDevice,
    requestVoiceToken as reachVoiceToken,
    signTravelingWrite,
} from '../lib/deviceIdentity.js';

export function useDeviceIdentity() {
    const devicePublicKey = ref(null);
    const enrolling = ref(false);

    async function enroll(label = null) {
        enrolling.value = true;
        try {
            const result = await enrollDevice(label);
            devicePublicKey.value = await readDevicePublicKey();
            return result;
        } finally {
            enrolling.value = false;
        }
    }

    async function requestVoiceToken(args) {
        return reachVoiceToken(args);
    }

    return { devicePublicKey, enrolling, enroll, requestVoiceToken, signTravelingWrite };
}

export default useDeviceIdentity;
