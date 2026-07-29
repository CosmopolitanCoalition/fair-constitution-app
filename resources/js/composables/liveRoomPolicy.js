/**
 * Pure policy for the live-room freshness store (useLiveRoom) — the Q1 cadence
 * normalisation and the Q2 status-gated interval decision, extracted so the
 * state machine is BEHAVIOURALLY testable under plain node (no Vue/Inertia/DOM),
 * the repo's JS-test idiom (tests/js/*.test.mjs). It is the belt-and-braces
 * partner of the PHP source-scan pin.
 *
 * DO NOT add Vue/Inertia imports to this file — the node pin loads it directly,
 * and any framework import would break that. useLiveRoom composes these.
 *
 * Desk-ruled 2026-07-29 (store contract c6399aa): Q1 = uniform 5s + a per-group
 * map affordance; Q2 = status-gated — full cadence open/recess, a heartbeat while
 * scheduled, STOP on adjourned.
 */

export const DEFAULT_CADENCE_MS = 5000;  // Q1: the proven MatrixCommons default.
export const HEARTBEAT_MS = 30000;       // Q2: the slow watch while a room is merely scheduled.

/**
 * Q1 — normalise cadence into channels. A number ⇒ ONE uniform channel over all
 * keys (the shipped default). A per-group map `{ group: { keys, ms } }` ⇒ one
 * channel per group with its own keys + interval (lane 2's load valve — tier
 * under load with no page/composition change). This is the ONLY place the two
 * cadence shapes diverge.
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

/**
 * Q2 — given the room's live state, the ms until the next tick, or NULL to STOP.
 * open/recess ⇒ full cadence; scheduled ⇒ heartbeat; adjourned (terminal) ⇒ null.
 *
 * Called BOTH before a poll (skip if terminal) and AFTER the merge (the desk
 * pin: the stop is decided on the JUST-MERGED state, so the closing snapshot
 * always lands before the transport halts).
 */
export function nextInterval(state, cadenceMs = DEFAULT_CADENCE_MS, heartbeatMs = HEARTBEAT_MS) {
    if (state === 'adjourned') {
        return null;
    }
    if (state === 'scheduled') {
        return heartbeatMs;
    }

    return cadenceMs; // open, recess, or any non-terminal state
}

/** A terminal (adjourned) room never arms the transport. */
export function isTerminal(state) {
    return nextInterval(state) === null;
}
