/* ============================================================================
   CGA — lib/callSession.js
   THE ONE LIVE CALL. A single voice connection that belongs to the app, not to a
   page. Pages ask it to start a call; the floating dock shows it; navigating away
   leaves it running. It ends only when the person presses Leave.

   Recovery, in the order it is tried:
     1. A brief network drop: LiveKit resumes the media itself (useVoiceRoom shows
        "reconnecting").
     2. The connection is lost for good while the app stays open: this module
        rejoins the same room with growing waits, at once when the network returns
        or the tab is shown again.
     3. The window closes or the app reloads without Leave: the call descriptor is
        kept in localStorage and on the server. The next load rejoins when the last
        heartbeat is recent. A browser cannot hold a call open with the tab closed,
        so a rejoin on reopening is the most that is possible.
   Only one tab holds the call (a short lease in localStorage). A second tab that
   starts or recovers takes it and the first lets go, so two tabs never fight over
   one identity.
   ============================================================================ */

import { computed, effectScope, ref, watch } from 'vue';
import { csrfFetch } from '@/lib/csrf.js';
import { requestPrivateVoiceToken } from '@/lib/deviceIdentity.js';
import { useVoiceRoom } from '@/composables/useVoiceRoom.js';

const STORE = 'cga.call.session';
const LEASE = 'cga.call.lease';
const RECOVER_WINDOW_MS = 10 * 60 * 1000; // a call this recent is worth rejoining
const OTHER_DEVICE_QUIET_MS = 60 * 1000; // server copy: only when no device has beaten for this long
const BEAT_MS = 15 * 1000;
const SERVER_BEAT_MS = 20 * 1000;
const LEASE_STALE_MS = 30 * 1000;
const WAITS_S = [2, 4, 8, 15, 30]; // then 30 s until the give-up point
const GIVE_UP_MS = 5 * 60 * 1000;
const FATAL = ['action_signature_invalid', 'room_not_accessible', 'peer_refused'];

const tabId = typeof crypto !== 'undefined' && crypto.randomUUID ? crypto.randomUUID() : String(Math.random());
const scope = effectScope(true); // detached: no page disposes this connection
const voice = scope.run(() => useVoiceRoom());

/** The call being held: { mode, room, jurisdictionId, pseudonym, subjectUserId, title, href, tokenUrl?, variant, mic } */
const session = ref(null);
/** idle | active | retrying | lost */
const phase = ref('idle');
const recovering = ref(false);

let retryTimer = null;
let retryCount = 0;
let retryStartedAt = 0;
let beatTimer = null;
let serverBeatAt = 0;
let bound = false;
let lastConnectedAt = 0;
let flaps = 0;

const hasStorage = () => typeof localStorage !== 'undefined';
const readJson = (key) => { try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch { return null; } };
const writeJson = (key, value) => { try { localStorage.setItem(key, JSON.stringify(value)); } catch { /* storage can be blocked */ } };
const readLease = () => (hasStorage() ? readJson(LEASE) : null);
const iHoldLease = () => readLease()?.tab === tabId;
const leaseFree = () => { const l = readLease(); return !l || l.tab === tabId || Date.now() - l.ts > LEASE_STALE_MS; };
const takeLease = () => { if (hasStorage()) writeJson(LEASE, { tab: tabId, ts: Date.now() }); };

function requesterFor(descriptor) {
    if (descriptor.mode === 'private') return ({ room }) => requestPrivateVoiceToken({ room });
    if (descriptor.mode === 'url' && descriptor.tokenUrl) {
        return async () => {
            const response = await csrfFetch(descriptor.tokenUrl, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: '{}' });
            if (!response.ok) throw new Error('room_not_accessible');
            return response.json();
        };
    }
    return null; // the commons uses the device-signed default
}

function joinArgs(d) {
    return { jurisdictionId: d.jurisdictionId, room: d.room, pseudonym: d.pseudonym, subjectUserId: d.subjectUserId, tokenRequester: requesterFor(d) };
}

function persist() {
    if (!session.value || !hasStorage()) return;
    const record = { descriptor: { ...session.value, mic: voice.micEnabled.value }, beat: Date.now() };
    writeJson(STORE, record);
    if (iHoldLease()) takeLease();
    if (Date.now() - serverBeatAt > SERVER_BEAT_MS) { serverBeatAt = Date.now(); putCall(record); }
}

function putCall(call) {
    csrfFetch('/messenger/state', { method: 'PUT', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify({ call }) }).catch(() => {});
}

function forget() {
    if (hasStorage()) { try { localStorage.removeItem(STORE); } catch { /* ignore */ } }
    putCall(null);
}

function startBeat() {
    stopBeat();
    persist();
    beatTimer = setInterval(persist, BEAT_MS);
}
function stopBeat() { if (beatTimer) { clearInterval(beatTimer); beatTimer = null; } }
function clearRetry() { if (retryTimer) { clearTimeout(retryTimer); retryTimer = null; } retryCount = 0; retryStartedAt = 0; }

async function attempt() {
    const d = session.value;
    if (!d || phase.value === 'idle') return;
    if (['connecting', 'connected', 'reconnecting'].includes(voice.connectionState.value)) return;
    try { await voice.join(joinArgs(d)); } catch { /* the error state drives the next retry */ }
}

function scheduleRetry(immediate = false) {
    if (!session.value || phase.value === 'idle') return;
    if (retryTimer) { if (!immediate) return; clearTimeout(retryTimer); retryTimer = null; }
    if (!retryStartedAt) retryStartedAt = Date.now();
    if (Date.now() - retryStartedAt > GIVE_UP_MS) { phase.value = 'lost'; stopBeat(); return; }
    phase.value = 'retrying';
    const wait = immediate ? 0 : (WAITS_S[Math.min(retryCount, WAITS_S.length - 1)] * 1000);
    retryCount += 1;
    retryTimer = setTimeout(async () => {
        retryTimer = null;
        if (!iHoldLease() && !leaseFree()) { yieldCall(); return; } // another tab has the call
        takeLease();
        await attempt();
    }, wait);
}

/** Let go without ending the call for the tab that now owns it. */
function yieldCall() {
    clearRetry(); stopBeat();
    session.value = null; phase.value = 'idle'; recovering.value = false;
    void voice.leave();
}

function onVoiceState(state) {
    if (!session.value || phase.value === 'idle') return;
    if (state === 'connected') {
        lastConnectedAt = Date.now();
        clearRetry(); phase.value = 'active'; recovering.value = false;
        startBeat();
        const wantMic = session.value.mic;
        if (wantMic && !voice.micEnabled.value) void voice.toggleMic(); // quietly; a refused permission just leaves it muted
        return;
    }
    if (state === 'error' || state === 'degraded') {
        const code = String(voice.error.value || '').split(':')[0].trim();
        if (FATAL.includes(code)) { phase.value = 'lost'; stopBeat(); return; }
        // Dropped again within seconds of connecting, several times over: the same identity joined from
        // another device or tab and keeps taking the call. Stop instead of taking it back in a loop.
        flaps = lastConnectedAt && Date.now() - lastConnectedAt < 8000 ? flaps + 1 : 0;
        lastConnectedAt = 0;
        if (flaps >= 3) { flaps = 0; phase.value = 'lost'; stopBeat(); return; }
        scheduleRetry();
    }
}

function bindOnce() {
    if (bound || typeof window === 'undefined') return;
    bound = true;
    scope.run(() => watch(voice.connectionState, onVoiceState));
    window.addEventListener('online', () => { if (phase.value === 'retrying' || phase.value === 'lost') { retryCount = 0; retryStartedAt = 0; phase.value = 'retrying'; scheduleRetry(true); } });
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState !== 'visible' || !session.value) return;
        if (phase.value === 'retrying') scheduleRetry(true);
        if (phase.value === 'active') persist();
    });
    window.addEventListener('pagehide', () => { if (session.value) persist(); });
    window.addEventListener('storage', (e) => {
        if (e.key !== LEASE || !session.value) return;
        const l = readLease();
        if (l && l.tab !== tabId) yieldCall(); // another tab took the call
    });
}

/** Start (or switch to) a call. One call at a time: joining a different room leaves the old one. */
async function startCall(descriptor) {
    bindOnce();
    if (session.value && session.value.room !== descriptor.room) await endCall();
    clearRetry();
    takeLease();
    session.value = { ...descriptor };
    phase.value = 'active';
    recovering.value = false;
    persist();
    await attempt();
}

/** The person pressed Leave. */
async function endCall() {
    clearRetry(); stopBeat();
    session.value = null; phase.value = 'idle'; recovering.value = false;
    forget();
    if (hasStorage()) { try { localStorage.removeItem(LEASE); } catch { /* ignore */ } }
    await voice.leave();
}

/** A fresh load: rejoin a recent call this browser (or, when quiet, another device) left running. */
async function recover() {
    bindOnce();
    if (session.value || !hasStorage() || !leaseFree()) return;
    let record = readJson(STORE);
    if (!record) {
        try {
            const r = await fetch('/messenger/state', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            const call = r.ok ? (await r.json())?.state?.call : null;
            // The server copy may belong to a device still on the call: use it only after it went quiet.
            if (call?.descriptor && Date.now() - (call.beat || 0) > OTHER_DEVICE_QUIET_MS) record = call;
        } catch { /* no server copy */ }
    }
    if (!record?.descriptor || Date.now() - (record.beat || 0) > RECOVER_WINDOW_MS) return;
    if (session.value || !leaseFree()) return;
    takeLease();
    session.value = { ...record.descriptor };
    phase.value = 'active';
    recovering.value = true;
    await attempt();
}

const peopleCount = computed(() => voice.participants.value.length);

export function useCall() {
    return { session, phase, recovering, voice, peopleCount, startCall, endCall, recover, isFor: (room) => session.value?.room === room };
}

export default useCall;
