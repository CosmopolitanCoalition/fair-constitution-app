import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * useLiveRoom — the transport-agnostic freshness layer for the Live Civic Room
 * (Slice 6; store contract docs/plans/scaling/LIVE_ROOM_STORE_CONTRACT.md, desk-
 * ruled 2026-07-29: Q1 = A uniform 5s with a per-group-map affordance; Q2 = B
 * status-gated with the final-snapshot-before-stop pin).
 *
 * THE PRINCIPLE (why the transport is swappable): server snapshots are the
 * truth; this store only sets freshness; correctness never rides the transport.
 * It asks the server (via an Inertia partial reload) for a fresh snapshot of the
 * named props and merges it in. It NEVER computes, writes, or decrypts — so how
 * the refresh arrives (a 5s poll today, a Reverb/SSE nudge tomorrow) cannot
 * change what is shown. The transport seam is `strategy` (only 'poll' this wave).
 *
 * Q1 (cadence). `cadenceMs` is EITHER a number — one uniform channel over all
 * `keys`, the shipped proven default — OR a per-group map
 * `{ group: { keys:[...], ms:N } }` giving each group its own interval and key
 * set. The map is lane 2's load valve: tier under real load with NO page or
 * composition change (the pages always call the same API).
 *
 * Q2 (liveness gate). Full cadence while `open`/`recess`; a slow HEARTBEAT while
 * `scheduled` (so a room that opens on schedule comes alive without a reload);
 * STOP entirely on `adjourned` (a concluded proceeding's transcript is static —
 * `refresh()` is the manual check). THE DESK PIN: the stop on `adjourned` is
 * evaluated ONLY AFTER the merge of the snapshot that announced it, never
 * before — so the closing snapshot (final tallies, the adjourned banner) is
 * always shown before the transport halts.
 *
 * @param {object}   options
 * @param {string[]} options.keys      the volatile props to keep fresh (MANIFEST §1).
 * @param {Function} options.isLive    () => 'open'|'recess'|'scheduled'|'adjourned'.
 * @param {Function} [options.busy]    () => bool — skip a tick while a write is in flight.
 * @param {number|object} [options.cadenceMs] uniform ms, or a per-group map (Q1).
 * @param {string}   [options.strategy] transport seam; 'poll' only this wave.
 */
const DEFAULT_CADENCE_MS = 5000; // Q1: the proven MatrixCommons default.
const HEARTBEAT_MS = 30000;      // Q2: the slow watch while a room is merely scheduled.

export function useLiveRoom(options = {}) {
    const {
        keys = [],
        isLive = () => 'open',
        busy = () => false,
        cadenceMs = DEFAULT_CADENCE_MS,
        strategy = 'poll',
    } = options;

    const lastSyncedAt = ref(null);
    const isPolling = ref(false);
    const timers = new Map(); // channel index -> setTimeout handle

    // Q1: normalise into channels. A number ⇒ one uniform channel over all keys
    // (the default we ship). A map ⇒ one channel per group with its own keys/ms.
    const channels = normalizeChannels(cadenceMs, keys);
    const maxCadence = Math.max(DEFAULT_CADENCE_MS, ...channels.map((c) => c.ms));

    // Stale ⇒ the transport hasn't landed a snapshot in > 2× its slowest cadence.
    const isStale = computed(
        () => lastSyncedAt.value !== null && (Date.now() - lastSyncedAt.value) > maxCadence * 2,
    );

    function state() {
        const s = isLive();
        return s === undefined || s === null ? 'open' : s;
    }

    // The ONLY transport this wave: an Inertia partial reload that MERGES a fresh
    // server snapshot of the given keys. Read-only — never .post/.put/.delete,
    // never a decrypt. A future Reverb/SSE strategy replaces this one function
    // with the same merge (see the store contract's transport seam).
    function merge(reloadKeys, onMerged) {
        if (strategy !== 'poll') return; // seam: other strategies wire in here
        router.reload({
            only: reloadKeys,
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                lastSyncedAt.value = Date.now();
                if (typeof onMerged === 'function') onMerged();
            },
        });
    }

    function tick(index) {
        const channel = channels[index];
        if (typeof document !== 'undefined' && document.hidden) return; // paused; the visibility handler re-arms
        const s = state();
        if (s === 'adjourned') { stopChannel(index); return; } // already closed before this tick — nothing to poll
        const interval = s === 'scheduled' ? HEARTBEAT_MS : channel.ms;
        if (busy()) { schedule(index, interval); return; } // never cancel an in-flight write — retry next interval

        merge(channel.keys, () => {
            // POST-MERGE (the desk pin): the closing snapshot that announced
            // adjournment is now merged. ONLY here do we evaluate the stop — the
            // final state is therefore always shown before the transport halts.
            const after = state();
            if (after === 'adjourned') { stopChannel(index); return; }
            schedule(index, after === 'scheduled' ? HEARTBEAT_MS : channel.ms);
        });
    }

    function schedule(index, ms) {
        stopChannel(index);
        if (typeof window === 'undefined') return;
        timers.set(index, window.setTimeout(() => tick(index), ms));
        isPolling.value = timers.size > 0;
    }

    function stopChannel(index) {
        const handle = timers.get(index);
        if (handle !== undefined) {
            window.clearTimeout(handle);
            timers.delete(index);
        }
        isPolling.value = timers.size > 0;
    }

    function start() {
        if (typeof window === 'undefined') return;
        const s = state();
        if (s === 'adjourned') return; // a concluded room never arms — refresh() is the manual check
        const initial = s === 'scheduled' ? HEARTBEAT_MS : null;
        channels.forEach((c, i) => schedule(i, initial ?? c.ms));
    }

    function stop() {
        channels.forEach((_, i) => stopChannel(i));
    }

    // A one-shot merge of ALL keys — used on visibility-regain, after a local
    // write, and as the manual "check for updates" on an adjourned room. It never
    // (re)arms the transport by itself.
    function refresh() {
        if (typeof document !== 'undefined' && document.hidden) return;
        merge(keys);
    }

    function onVisibility() {
        if (document.hidden) { stop(); return; }
        refresh();
        start();
    }

    onMounted(() => {
        start();
        if (typeof document !== 'undefined') {
            document.addEventListener('visibilitychange', onVisibility);
        }
    });
    onBeforeUnmount(() => {
        stop();
        if (typeof document !== 'undefined') {
            document.removeEventListener('visibilitychange', onVisibility);
        }
    });

    return { lastSyncedAt, isStale, isPolling, refresh, start, stop };
}

/**
 * Q1 normalisation. A number ⇒ a single uniform channel over every key. A
 * per-group map `{ group: { keys, ms } }` ⇒ one channel per group. This is the
 * only place the two cadence shapes diverge — the rest of the store is identical
 * either way, which is what lets lane 2 go tiered with no page change.
 */
export function normalizeChannels(cadenceMs, keys) {
    if (cadenceMs !== null && typeof cadenceMs === 'object') {
        return Object.values(cadenceMs).map((group) => ({
            keys: Array.isArray(group.keys) ? group.keys : keys,
            ms: typeof group.ms === 'number' ? group.ms : DEFAULT_CADENCE_MS,
        }));
    }

    return [{ keys, ms: typeof cadenceMs === 'number' ? cadenceMs : DEFAULT_CADENCE_MS }];
}
