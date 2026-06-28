/* ============================================================================
   CGA — composables/useVoiceRoom.js
   The AV connection core of the live civic room (Phase 5 "Deferred 2").

   Bridges the browser device-signer (Deferred 1) to LiveKit: it asks the home
   node for a token (requestVoiceToken — which device-signs the request and, in
   the mixed environment, may forward to a capable peer's SFU), connects to that
   SFU, publishes the mic (+ optionally camera), and tracks every participant's
   media as reactive state the chamber UI renders. The identity is always the
   pseudonym (@u-<handle>); the SFU url + token come from the home node.

   Safe-degrade: when no peer hosts an SFU the home node returns 503 {degrade},
   which surfaces here as `degraded` — the room stays text-only, never an error.

   E2E voice is exercised on the two-box rig (a reachable SFU + a peer); this
   module is the integration + lifecycle, framework-tested by the build + its
   reactive contract.
   ============================================================================ */

import { onScopeDispose, ref, shallowRef } from 'vue';
import { requestVoiceToken } from '../lib/deviceIdentity.js';

// livekit-client is a heavy SFU SDK — load it ONLY when a player joins voice, so it
// never weighs down the commons page for people who only read/post text.
let LK = null;

function mapState(state) {
    const C = LK.ConnectionState;
    switch (state) {
        case C.Connecting: return 'connecting';
        case C.Connected: return 'connected';
        case C.Reconnecting: return 'reconnecting';
        default: return 'disconnected';
    }
}

export function useVoiceRoom() {
    const room = shallowRef(null);
    const connectionState = ref('disconnected'); // disconnected|connecting|connected|reconnecting|degraded|error
    const degraded = ref(false); // 503: no SFU reachable → the room is text-only
    const error = ref(null);
    const micEnabled = ref(false);
    const cameraEnabled = ref(false);
    /** @type {import('vue').Ref<Array<{identity,isLocal,isSpeaking,audioTrack,videoTrack}>>} */
    const participants = ref([]);
    let joining = false; // synchronous re-entry latch for join() (see below)

    function viewModel(participant) {
        return {
            identity: participant.identity, // @u-<handle> pseudonym, never a legal name
            isLocal: participant.isLocal,
            isSpeaking: participant.isSpeaking,
            audioTrack: participant.getTrackPublication(LK.Track.Source.Microphone)?.track ?? null,
            videoTrack: participant.getTrackPublication(LK.Track.Source.Camera)?.track ?? null,
        };
    }

    function syncParticipants() {
        const r = room.value;
        if (!r) {
            participants.value = [];
            return;
        }
        // Local first (the floor / "you"), then remotes in join order.
        participants.value = [r.localParticipant, ...r.remoteParticipants.values()].map(viewModel);
    }

    function wire(r) {
        const E = LK.RoomEvent;
        r.on(E.ConnectionStateChanged, (s) => {
            connectionState.value = mapState(s);
        });
        for (const ev of [
            E.ParticipantConnected,
            E.ParticipantDisconnected,
            E.TrackSubscribed,
            E.TrackUnsubscribed,
            E.TrackMuted, // remote mute keeps the publication subscribed — without this the mic-off badge goes stale
            E.TrackUnmuted,
            E.LocalTrackPublished,
            E.LocalTrackUnpublished,
            E.ActiveSpeakersChanged,
            E.IsSpeakingChanged,
        ]) {
            r.on(ev, syncParticipants);
        }
        r.on(E.Disconnected, () => {
            connectionState.value = 'disconnected';
            participants.value = [];
            // Drop the dead Room so a later join() isn't blocked by its own corpse (an
            // involuntary disconnect would otherwise brick the rejoin button).
            r.removeAllListeners();
            if (room.value === r) room.value = null;
        });
    }

    /**
     * Join the room's voice/video. `pseudonym` + `subjectUserId` MUST be the
     * authenticated player's own (see deviceIdentity.requestVoiceToken). Resolves
     * once connected (or sets `degraded` and resolves if no SFU is reachable).
     */
    async function join({ jurisdictionId, room: roomName, pseudonym, subjectUserId, video = false }) {
        // Re-entry latch: the room.value guard alone is insufficient because room.value isn't set
        // until AFTER the async token fetch — a double-click would open two SFU connections and leak
        // one. `joining` is set synchronously before the first await.
        if (room.value || joining) return;
        joining = true;
        connectionState.value = 'connecting';
        error.value = null;
        degraded.value = false;

        try {
            let grant;
            try {
                grant = await requestVoiceToken({ jurisdictionId, room: roomName, pseudonym, subjectUserId });
            } catch (e) {
                // Any 503 is the safe text-only degrade (no SFU reachable) — never an error, regardless
                // of body shape (an upstream proxy 503 carries no {degrade} flag).
                if (e?.response?.status === 503) {
                    degraded.value = true;
                    connectionState.value = 'degraded';
                    return;
                }
                error.value = e?.response?.data?.error ?? 'voice_unavailable';
                connectionState.value = 'error';
                throw e;
            }

            if (!LK) LK = await import('livekit-client'); // lazy — the SFU SDK loads on first join

            const r = new LK.Room({ adaptiveStream: true, dynacast: true });
            room.value = r;
            wire(r);

            try {
                await r.connect(grant.sfu_url, grant.token);
                await r.localParticipant.setMicrophoneEnabled(true);
                micEnabled.value = true;
                if (video) {
                    await r.localParticipant.setCameraEnabled(true);
                    cameraEnabled.value = true;
                }
                syncParticipants();
            } catch (e) {
                error.value = 'sfu_connect_failed';
                connectionState.value = 'error';
                await leave();
                throw e;
            }
        } finally {
            joining = false;
        }
    }

    async function toggleMic() {
        const lp = room.value?.localParticipant;
        if (!lp) return;
        const next = !micEnabled.value;
        micEnabled.value = next; // optimistic
        try {
            await lp.setMicrophoneEnabled(next);
        } catch {
            micEnabled.value = !next; // device call rejected — don't let the button lie
        }
        syncParticipants();
    }

    async function toggleCamera() {
        const lp = room.value?.localParticipant;
        if (!lp) return;
        const next = !cameraEnabled.value;
        cameraEnabled.value = next; // optimistic
        try {
            await lp.setCameraEnabled(next);
        } catch {
            cameraEnabled.value = !next;
        }
        syncParticipants();
    }

    async function leave() {
        const r = room.value;
        room.value = null;
        micEnabled.value = false;
        cameraEnabled.value = false;
        participants.value = [];
        connectionState.value = 'disconnected';
        if (r) {
            r.removeAllListeners(); // drop the closures held over this composable's refs
            await r.disconnect();
        }
    }

    // Never leave a live SFU connection dangling when the room component unmounts
    // (navigation away mid-call) — disconnect with the owning scope. Fire-and-forget
    // (dispose can't await); swallow any teardown rejection.
    onScopeDispose(() => {
        room.value?.disconnect().catch(() => {});
    });

    return {
        connectionState,
        degraded,
        error,
        micEnabled,
        cameraEnabled,
        participants,
        join,
        leave,
        toggleMic,
        toggleCamera,
    };
}

export default useVoiceRoom;
