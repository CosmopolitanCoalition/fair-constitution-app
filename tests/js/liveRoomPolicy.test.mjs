/* ============================================================================
   PIN — the live-room freshness policy (composables/liveRoomPolicy.js).

   The store contract (c6399aa) was ruled by the desk 2026-07-29: Q1 = uniform
   5s with a per-group-map affordance; Q2 = status-gated (full cadence
   open/recess, a heartbeat while scheduled, STOP on adjourned). This asserts the
   PURE reducer that guarantees it — the behavioural partner of the PHP
   source-scan pin (LiveRoomStoreContractTest).

   No test harness in this repo — run with plain node (lane 6's idiom):

       node tests/js/liveRoomPolicy.test.mjs

   Exit 0 = all pass; exit 1 = a pin broke. The policy is pure (no Vue/Inertia/
   DOM), so this file imports it directly. If you break Q1/Q2, THIS goes red —
   fix the policy, not the pin.
   ============================================================================ */
import {
    normalizeChannels,
    nextInterval,
    isTerminal,
    DEFAULT_CADENCE_MS,
    HEARTBEAT_MS,
} from '../../resources/js/composables/liveRoomPolicy.js';

let failed = 0;
function eq(actual, expected, label) {
    const a = JSON.stringify(actual);
    const e = JSON.stringify(expected);
    if (a === e) {
        console.log(`  ok   ${label}`);
    } else {
        failed++;
        console.log(`  FAIL ${label}\n         expected ${e}\n         actual   ${a}`);
    }
}
function truthy(v, label) {
    if (v) console.log(`  ok   ${label}`);
    else { failed++; console.log(`  FAIL ${label}`); }
}

const KEYS = ['status', 'vote', 'chat'];

/* ---- Q1: normalizeChannels ---- */

/* 1. A number ⇒ ONE uniform channel over every key (the shipped default). */
eq(normalizeChannels(5000, KEYS), [{ keys: KEYS, ms: 5000 }], 'Q1 number → one uniform channel');

/* 2. A non-number (e.g. undefined) ⇒ the default cadence, still one channel. */
eq(normalizeChannels(undefined, KEYS), [{ keys: KEYS, ms: DEFAULT_CADENCE_MS }], 'Q1 undefined → default cadence');

/* 3. A per-group MAP ⇒ one channel per group with its own keys + ms (lane 2's valve). */
eq(
    normalizeChannels({ fast: { keys: ['chat'], ms: 5000 }, slow: { keys: ['vote'], ms: 15000 } }, KEYS),
    [{ keys: ['chat'], ms: 5000 }, { keys: ['vote'], ms: 15000 }],
    'Q1 map → per-group channels',
);

/* 4. A map group missing keys falls back to all keys; missing ms → default. */
eq(
    normalizeChannels({ g: { ms: 8000 } }, KEYS),
    [{ keys: KEYS, ms: 8000 }],
    'Q1 map group without keys → all keys',
);
eq(
    normalizeChannels({ g: { keys: ['status'] } }, KEYS),
    [{ keys: ['status'], ms: DEFAULT_CADENCE_MS }],
    'Q1 map group without ms → default cadence',
);

/* ---- Q2: nextInterval (status-gated) ---- */

/* 5. open/recess ⇒ full cadence. */
eq(nextInterval('open', 5000, 30000), 5000, 'Q2 open → full cadence');
eq(nextInterval('recess', 5000, 30000), 5000, 'Q2 recess → full cadence');

/* 6. scheduled ⇒ the heartbeat (so a room that opens on schedule comes alive). */
eq(nextInterval('scheduled', 5000, 30000), 30000, 'Q2 scheduled → heartbeat');

/* 7. adjourned ⇒ null = STOP (a concluded proceeding is not live). */
eq(nextInterval('adjourned', 5000, 30000), null, 'Q2 adjourned → STOP');

/* 8. defaults apply when cadence/heartbeat are omitted. */
eq(nextInterval('open'), DEFAULT_CADENCE_MS, 'Q2 open default cadence');
eq(nextInterval('scheduled'), HEARTBEAT_MS, 'Q2 scheduled default heartbeat');

/* 9. an unknown state is treated as live (never accidentally stops a live room). */
eq(nextInterval('in_progress', 5000, 30000), 5000, 'Q2 unknown state → treated live');

/* 10. isTerminal: only adjourned stops. */
truthy(isTerminal('adjourned'), 'isTerminal: adjourned is terminal');
truthy(!isTerminal('open'), 'isTerminal: open is not terminal');
truthy(!isTerminal('scheduled'), 'isTerminal: scheduled is not terminal');

console.log(failed ? `\n${failed} FAILED` : '\nall passed');
process.exit(failed ? 1 : 0);
