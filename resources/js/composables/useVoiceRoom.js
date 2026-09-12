/* ============================================================================
   CGA — composables/useVoiceRoom.js
   The AV connection core of the live civic room (Phase 5 "Deferred 2").

   Bridges the browser device-signer (Deferred 1) to LiveKit: it asks the home
   node for a token (requestVoiceToken — which device-signs the request and, in
   the mixed environment, may forward to a capable peer's SFU), connects to that
   SFU in listen-only mode, and tracks every participant's
   media as reactive state the chamber UI renders. The identity is always the
   pseudonym (@u-<handle>); the SFU url + token come from the home node.

   Self-hosted only — there is NO external Matrix client; this in-app room is the
   client. Players can pick their camera / microphone / speaker, and share a
   screen, all over the same self-hosted SFU.

   Safe-degrade: when no peer hosts an SFU the home node returns 503 {degrade},
   which surfaces here as `degraded` — the room stays text-only, never an error.
   ============================================================================ */

import { onScopeDispose, ref, shallowRef } from 'vue';
import { requestVoiceToken } from '../lib/deviceIdentity.js';

// livekit-client is a heavy SFU SDK — load it ONLY when a player joins voice, so it
// never weighs down the commons page for people who only read/post text.
let LK = null;

// UI device kind → the MediaDeviceInfo.kind / LiveKit switchActiveDevice kind.
const KINDS = { camera: 'videoinput', mic: 'audioinput', speaker: 'audiooutput' };

function mapState(state) {
    const C = LK.ConnectionState;
    switch (state) {
        case C.Connecting: return 'connecting';
        case C.Connected: return 'connected';
        case C.Reconnecting:
        case C.SignalReconnecting: return 'reconnecting';
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
    const screenShareEnabled = ref(false);
    const mediaPending = ref({ mic: false, camera: false, screen: false });
    const audioBlocked = ref(false);
    /** @type {import('vue').Ref<Array<{identity,isLocal,isSpeaking,audioTrack,videoTrack,screenTrack}>>} */
    const participants = ref([]);
    /** Selectable input/output devices, populated after join (labels need a granted permission). */
    const devices = ref({ camera: [], mic: [], speaker: [] });
    const selectedDevices = ref({ camera: '', mic: '', speaker: '' });
    let joining = false; // synchronous re-entry latch for join() (see below)
    let generation = 0; // leave/dispose invalidates every pending await in a join
    let disposed = false;
    let deviceChangeBound = false;
    const isCurrent = (r) => !disposed && room.value === r;

    function resetMedia() {
        micEnabled.value = false;
        cameraEnabled.value = false;
        screenShareEnabled.value = false;
        mediaPending.value = { mic: false, camera: false, screen: false };
        audioBlocked.value = false;
        participants.value = [];
    }

    async function closeRoom(r) {
        if (!r) return;
        try {
            await r.disconnect();
        } catch {
            // Cleanup must not erase the failure which caused it.
        } finally {
            // LiveKit disconnect() returns early if already disconnected. A
            // device permission resolved later may have left new local tracks.
            r.localParticipant.trackPublications?.forEach(publication => {
                try { publication.track?.stop(); } catch { /* continue closing other tracks */ }
            });
            r.removeAllListeners();
        }
    }

    function viewModel(participant) {
        return {
            identity: participant.identity, // @u-<handle> pseudonym, never a legal name
            display_name: participant.name || '', // public label carried by the room token
            isLocal: participant.isLocal,
            isSpeaking: participant.isSpeaking,
            audioTrack: participant.getTrackPublication(LK.Track.Source.Microphone)?.track ?? null,
            videoTrack: participant.getTrackPublication(LK.Track.Source.Camera)?.track ?? null,
            screenTrack: participant.getTrackPublication(LK.Track.Source.ScreenShare)?.track ?? null,
            // A screen share may carry tab/system audio on a separate track — play it on the presenter tile.
            screenAudioTrack: participant.getTrackPublication(LK.Track.Source.ScreenShareAudio)?.track ?? null,
        };
    }

    function syncParticipants() {
        const r = room.value;
        if (!r) {
            participants.value = [];
            return;
        }
        micEnabled.value = r.localParticipant.isMicrophoneEnabled ?? false;
        cameraEnabled.value = r.localParticipant.isCameraEnabled ?? false;
        screenShareEnabled.value = r.localParticipant.isScreenShareEnabled ?? false;
        // Local first (the floor / "you"), then remotes in join order.
        participants.value = [r.localParticipant, ...r.remoteParticipants.values()].map(viewModel);
    }

    function wire(r) {
        const E = LK.RoomEvent;
        r.on(E.AudioPlaybackStatusChanged, () => {
            if (isCurrent(r)) audioBlocked.value = !r.canPlaybackAudio;
        });
        r.on(E.ConnectionStateChanged, (s) => {
            if (!isCurrent(r)) return;
            const state = mapState(s);
            // Disconnected carries its reason in the separate final event.
            if (state !== 'disconnected') connectionState.value = state;
        });
        for (const ev of [
            E.ParticipantConnected,
            E.ParticipantDisconnected,
            E.ParticipantNameChanged,
            E.TrackSubscribed,
            E.TrackUnsubscribed,
            E.TrackUnpublished, // a REMOTE participant stopping screen-share — clears their presenter tile
            E.TrackMuted, // remote mute keeps the publication subscribed — without this the mic-off badge goes stale
            E.TrackUnmuted,
            E.LocalTrackPublished,
            E.LocalTrackUnpublished,
            E.ActiveSpeakersChanged,
            E.IsSpeakingChanged,
        ]) {
            r.on(ev, () => { if (isCurrent(r)) syncParticipants(); });
        }
        // A browser-side "stop sharing" (the OS bar) unpublishes the screen track — reflect it.
        r.on(E.LocalTrackUnpublished, (pub) => {
            if (isCurrent(r) && pub?.source === LK.Track.Source.ScreenShare) screenShareEnabled.value = false;
        });
        r.on(E.Disconnected, () => {
            if (!isCurrent(r)) return;
            ++generation;
            joining = false;
            connectionState.value = 'error';
            error.value = 'room_disconnected';
            resetMedia();
            unbindDeviceChange();
            // Drop the dead Room so a later join() isn't blocked by its own corpse (an
            // involuntary disconnect would otherwise brick the rejoin button).
            r.removeAllListeners();
            if (room.value === r) room.value = null;
        });
    }

    /** Enumerate selectable devices + reflect the room's active ones. Labels appear post-permission. */
    async function refreshDevices() {
        if (typeof navigator === 'undefined' || !navigator.mediaDevices?.enumerateDevices) return;
        const r = room.value;
        if (!r) return;
        try {
            const list = await navigator.mediaDevices.enumerateDevices();
            if (!isCurrent(r)) return;
            devices.value = {
                camera: list.filter((d) => d.kind === 'videoinput'),
                mic: list.filter((d) => d.kind === 'audioinput'),
                speaker: list.filter((d) => d.kind === 'audiooutput'),
            };
            if (r?.getActiveDevice) {
                // `||` not `??`: LiveKit returns '' (not null) for an unset output device — keep the prior pick.
                const next = {
                    camera: r.getActiveDevice('videoinput') || selectedDevices.value.camera,
                    mic: r.getActiveDevice('audioinput') || selectedDevices.value.mic,
                    speaker: r.getActiveDevice('audiooutput') || selectedDevices.value.speaker,
                };
                // Drop a selection whose device was unplugged (else the <select> shows a phantom value).
                for (const [k, list] of [['camera', devices.value.camera], ['mic', devices.value.mic], ['speaker', devices.value.speaker]]) {
                    if (next[k] && !list.some((d) => d.deviceId === next[k])) next[k] = '';
                }
                selectedDevices.value = next;
            }
        } catch {
            /* enumeration can throw in a locked-down context — non-fatal */
        }
    }

    /** Switch the active camera / mic / speaker. `kind` is a UI key (camera|mic|speaker). */
    async function selectDevice(kind, deviceId) {
        const r = room.value;
        if (!r || connectionState.value !== 'connected' || !KINDS[kind] || !deviceId) return;
        error.value = null;
        try {
            await r.switchActiveDevice(KINDS[kind], deviceId);
            if (!isCurrent(r)) { await closeRoom(r); return; }
            selectedDevices.value = { ...selectedDevices.value, [kind]: deviceId };
        } catch (e) {
            // Output-device switching (setSinkId) isn't supported everywhere (e.g. iOS Safari);
            // a yanked device can also reject. Leave the prior selection; don't crash the room.
            if (isCurrent(r)) error.value = kind === 'speaker' ? 'speaker_failed' : deviceError(kind, e);
        }
        if (isCurrent(r)) syncParticipants();
    }

    function deviceError(kind, failure) {
        if (['NotAllowedError', 'PermissionDeniedError', 'SecurityError'].includes(failure?.name)) return kind + '_permission_denied';
        if (['NotFoundError', 'DevicesNotFoundError'].includes(failure?.name)) return kind + '_unavailable';
        if (['NotReadableError', 'TrackStartError'].includes(failure?.name)) return kind + '_in_use';
        return kind + '_failed';
    }

    async function toggleMedia(kind, state, method) {
        const r = room.value;
        if (!r || connectionState.value !== 'connected' || mediaPending.value[kind]) return;
        mediaPending.value = { ...mediaPending.value, [kind]: true };
        error.value = null;
        try {
            await r.localParticipant[method](!state.value);
            // A permission dialog can complete after leave: stop any tracks it
            // acquired instead of publishing into an abandoned room.
            if (!isCurrent(r)) { await closeRoom(r); return; }
            syncParticipants();
            await refreshDevices();
        } catch (e) {
            if (isCurrent(r)) {
                error.value = deviceError(kind, e);
                syncParticipants();
            } else {
                await closeRoom(r);
            }
        } finally {
            if (isCurrent(r)) mediaPending.value = { ...mediaPending.value, [kind]: false };
        }
    }

    const toggleMic = () => toggleMedia('mic', micEnabled, 'setMicrophoneEnabled');
    const toggleCamera = () => toggleMedia('camera', cameraEnabled, 'setCameraEnabled');
    const toggleScreenShare = () => toggleMedia('screen', screenShareEnabled, 'setScreenShareEnabled');

    // Called directly from a click so browsers can unlock audio without
    // requiring microphone access merely to listen (especially on iOS).
    async function startAudio() {
        const r = room.value;
        if (!r || connectionState.value !== 'connected') return;
        try {
            await r.startAudio();
            if (isCurrent(r)) {
                audioBlocked.value = !r.canPlaybackAudio;
                if (error.value === 'audio_playback_failed') error.value = null;
            }
        } catch {
            if (isCurrent(r)) {
                audioBlocked.value = true;
                error.value = 'audio_playback_failed';
            }
        }
    }

    /**
     * Join the room's voice/video. `pseudonym` + `subjectUserId` MUST be the
     * authenticated player's own (see deviceIdentity.requestVoiceToken). Resolves
     * once connected (or sets `degraded` and resolves if no SFU is reachable).
     */
    async function join({ jurisdictionId, room: roomName, pseudonym, subjectUserId, tokenRequester = null }) {
        // Re-entry latch: the room.value guard alone is insufficient because room.value isn't set
        // until AFTER the async token fetch — a double-click would open two SFU connections and leak
        // one. `joining` is set synchronously before the first await.
        if (disposed || room.value || joining) return;
        joining = true;
        const attempt = ++generation;
        const cancelled = () => disposed || attempt !== generation;
        let r = null;
        connectionState.value = 'connecting';
        error.value = null;
        degraded.value = false;

        try {
            let grant;
            try {
                // A private room passes its own member-gated requester; the commons uses the device-signed default.
                grant = await (tokenRequester ?? requestVoiceToken)({ jurisdictionId, room: roomName, pseudonym, subjectUserId });
            } catch (e) {
                if (cancelled()) return;
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

            if (cancelled()) return;
            if (!LK) LK = await import('livekit-client'); // lazy — the SFU SDK loads on first join
            if (cancelled()) return;

            r = new LK.Room({ adaptiveStream: true, dynacast: true });
            room.value = r;
            wire(r);

            await r.connect(grant.sfu_url, grant.token);
            if (cancelled() || !isCurrent(r)) { await closeRoom(r); return; }
            // Joining never asks for capture permission. Publishing is an
            // explicit microphone/camera/screen control after a successful join.
            connectionState.value = 'connected';
            audioBlocked.value = !r.canPlaybackAudio;
            syncParticipants();
            await refreshDevices();
            if (cancelled() || !isCurrent(r)) return;
            if (!deviceChangeBound && typeof navigator !== 'undefined' && navigator.mediaDevices?.addEventListener) {
                navigator.mediaDevices.addEventListener('devicechange', refreshDevices);
                deviceChangeBound = true;
            }
        } catch (e) {
            if (cancelled()) { await closeRoom(r); return; }
            if (room.value === r) room.value = null;
            resetMedia();
            unbindDeviceChange();
            error.value = r ? 'sfu_connect_failed' : (error.value ?? 'voice_unavailable');
            connectionState.value = 'error';
            await closeRoom(r);
            throw e;
        } finally {
            if (attempt === generation) joining = false;
        }
    }

    function unbindDeviceChange() {
        if (deviceChangeBound && typeof navigator !== 'undefined' && navigator.mediaDevices?.removeEventListener) {
            navigator.mediaDevices.removeEventListener('devicechange', refreshDevices);
            deviceChangeBound = false;
        }
    }

    async function leave() {
        ++generation;
        joining = false;
        const r = room.value;
        room.value = null;
        resetMedia();
        connectionState.value = 'disconnected';
        degraded.value = false;
        error.value = null;
        unbindDeviceChange();
        await closeRoom(r);
    }

    // Never leave a live SFU connection dangling when the room component unmounts
    // (navigation away mid-call) — disconnect with the owning scope. Fire-and-forget
    // (dispose can't await); swallow any teardown rejection.
    onScopeDispose(() => {
        disposed = true;
        void leave();
    });

    return {
        connectionState,
        degraded,
        error,
        micEnabled,
        cameraEnabled,
        screenShareEnabled,
        mediaPending,
        audioBlocked,
        participants,
        devices,
        selectedDevices,
        join,
        leave,
        toggleMic,
        toggleCamera,
        toggleScreenShare,
        selectDevice,
        startAudio,
    };
}

export default useVoiceRoom;
