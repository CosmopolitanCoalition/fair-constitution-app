// node --experimental-vm-modules --test tests/js/callSession.test.mjs
//
// THE ONE LIVE CALL (lib/callSession.js). Behavioural pins with the voice room, storage, timers and
// network mocked: the call outlives the page, Leave ends it, a lost connection is retried, a recent
// call is rejoined after a reload, two tabs never fight, and a call taken over elsewhere is not
// taken back in a loop. Plus structural pins for the pieces that make the call keep playing.
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';

const root = new URL('../../resources/js/', import.meta.url);
const source = await readFile(new URL('lib/callSession.js', root), 'utf8');

function moduleOf(context, values) {
    return new vm.SyntheticModule(Object.keys(values), function () {
        for (const [key, value] of Object.entries(values)) this.setExport(key, value);
    }, { context });
}

async function fixture(t, { storage = {}, serverCall = null } = {}) {
    const timers = [];
    let now = 1_000_000;
    const store = new Map(Object.entries(storage));
    const localStorage = {
        getItem: (k) => (store.has(k) ? store.get(k) : null),
        setItem: (k, v) => { store.set(k, String(v)); },
        removeItem: (k) => { store.delete(k); },
    };
    const listeners = {};
    const win = { addEventListener: (n, f) => { (listeners[n] ??= []).push(f); } };
    const doc = { visibilityState: 'visible', addEventListener: (n, f) => { (listeners['doc:' + n] ??= []).push(f); } };
    const puts = [];

    // A controllable stand-in for useVoiceRoom().
    const voiceState = {
        connectionState: Vue.ref('disconnected'), error: Vue.ref(null), degraded: Vue.ref(false), micEnabled: Vue.ref(false),
        participants: Vue.ref([]), joins: [], leaves: 0, mic: 0,
    };
    const voice = {
        ...voiceState,
        join: async (args) => {
            voiceState.joins.push(args);
            voiceState.connectionState.value = 'connecting';
            await Promise.resolve();
            if (voiceState.failNext) { voiceState.failNext = false; voiceState.error.value = 'sfu_connect_failed'; voiceState.connectionState.value = 'error'; throw new Error('x'); }
            voiceState.connectionState.value = 'connected';
        },
        leave: async () => { voiceState.leaves++; voiceState.connectionState.value = 'disconnected'; },
        toggleMic: async () => { voiceState.mic++; voice.micEnabled.value = true; },
    };

    const context = vm.createContext({
        console, localStorage, window: win, document: doc, crypto: { randomUUID: () => 'tab-' + Math.random() },
        Date: class extends Date { static now() { return now; } },
        setTimeout: (fn, ms) => { const id = timers.length + 1; timers.push({ id, fn, ms, live: true }); return id; },
        clearTimeout: (id) => { const x = timers.find((y) => y.id === id); if (x) x.live = false; },
        setInterval: () => 0, clearInterval: () => {},
        fetch: async () => ({ ok: !!serverCall, json: async () => ({ state: { call: serverCall } }) }),
        JSON, Math, String, Promise,
    });
    const mod = new vm.SourceTextModule(source, { context, identifier: 'callSession.js' });
    await mod.link(async (specifier) => {
        if (specifier === 'vue') return moduleOf(context, Vue);
        if (specifier === '@/lib/csrf.js') return moduleOf(context, { csrfFetch: async (url, opts) => { puts.push({ url, opts }); return { ok: true, json: async () => ({}) }; } });
        if (specifier === '@/lib/deviceIdentity.js') return moduleOf(context, { requestPrivateVoiceToken: async () => ({}) });
        if (specifier === '@/composables/useVoiceRoom.js') return moduleOf(context, { useVoiceRoom: () => voice, default: () => voice });
        throw new Error('unexpected import ' + specifier);
    });
    await mod.evaluate();
    const api = mod.namespace.useCall();
    const tick = async () => { for (let i = 0; i < 6; i++) await new Promise(setImmediate); };
    const runTimers = async () => { for (const x of timers.filter((y) => y.live)) { x.live = false; await x.fn(); } await tick(); };
    t.after(() => {});
    return { api, voice, voiceState, store, listeners, puts, timers, tick, runTimers, advance: (ms) => { now += ms; } };
}

const room = { mode: 'private', room: '!r:x', jurisdictionId: 'j', pseudonym: '@u-a', subjectUserId: 'u1', title: 'Friends', href: '/civic/rooms/1', variant: 'commons' };

test('a started call joins once and stays after the page that started it is gone', async (t) => {
    const f = await fixture(t);
    await f.api.startCall(room);
    await f.tick();
    assert.equal(f.voiceState.joins.length, 1);
    assert.equal(f.api.session.value.room, '!r:x');
    assert.equal(f.api.phase.value, 'active');
    assert.equal(f.voiceState.leaves, 0, 'nothing leaves the call when a page unmounts');
    assert.ok(JSON.parse(f.store.get('cga.call.session')).descriptor.room === '!r:x', 'the descriptor is kept for a reload');
});

test('Leave ends the call and forgets it everywhere', async (t) => {
    const f = await fixture(t);
    await f.api.startCall(room);
    await f.api.endCall();
    assert.equal(f.api.session.value, null);
    assert.equal(f.api.phase.value, 'idle');
    assert.equal(f.store.has('cga.call.session'), false);
    assert.ok(f.puts.some((p) => JSON.parse(p.opts.body).call === null), 'the server copy is cleared');
});

test('joining a different room leaves the first one', async (t) => {
    const f = await fixture(t);
    await f.api.startCall(room);
    await f.api.startCall({ ...room, room: '!other:x', title: 'Other' });
    assert.equal(f.voiceState.leaves, 1);
    assert.equal(f.api.session.value.room, '!other:x');
});

test('a lost connection is retried with a wait, and rejoined when it works', async (t) => {
    const f = await fixture(t);
    await f.api.startCall(room);
    await f.tick();
    f.voiceState.connectionState.value = 'error';
    f.voiceState.error.value = 'room_disconnected';
    await f.tick();
    assert.equal(f.api.phase.value, 'retrying');
    assert.ok(f.timers.some((x) => x.live && x.ms === 2000), 'the first wait is two seconds');
    await f.runTimers();
    assert.equal(f.voiceState.joins.length, 2, 'it joined the same room again');
    assert.equal(f.api.phase.value, 'active');
});

test('a refused room is not retried', async (t) => {
    const f = await fixture(t);
    await f.api.startCall(room);
    await f.tick();
    f.voiceState.error.value = 'room_not_accessible';
    f.voiceState.connectionState.value = 'error';
    await f.tick();
    assert.equal(f.api.phase.value, 'lost');
    assert.equal(f.timers.filter((x) => x.live).length, 0);
});

test('a call taken over again and again from elsewhere stops instead of looping', async (t) => {
    const f = await fixture(t);
    await f.api.startCall(room);
    await f.tick();
    for (let i = 0; i < 4; i++) {
        f.advance(1000); // dropped one second after connecting
        f.voiceState.connectionState.value = 'error';
        f.voiceState.error.value = 'room_disconnected';
        await f.tick();
        if (f.api.phase.value === 'lost') break;
        await f.runTimers();
    }
    assert.equal(f.api.phase.value, 'lost');
});

test('a recent call is rejoined after a reload, an old one is not', async (t) => {
    const recent = { descriptor: { ...room, mic: true }, beat: 1_000_000 - 60_000 };
    const f = await fixture(t, { storage: { 'cga.call.session': JSON.stringify(recent) } });
    await f.api.recover();
    await f.tick();
    assert.equal(f.voiceState.joins.length, 1);
    assert.equal(f.voiceState.mic, 1, 'the microphone is restored when it was on');

    const old = { descriptor: room, beat: 1_000_000 - 11 * 60_000 };
    const g = await fixture(t, { storage: { 'cga.call.session': JSON.stringify(old) } });
    await g.api.recover();
    assert.equal(g.voiceState.joins.length, 0);
});

test('another tab that holds the call blocks a second recovery', async (t) => {
    const f = await fixture(t, {
        storage: {
            'cga.call.session': JSON.stringify({ descriptor: room, beat: 1_000_000 - 1000 }),
            'cga.call.lease': JSON.stringify({ tab: 'someone-else', ts: 1_000_000 - 1000 }),
        },
    });
    await f.api.recover();
    assert.equal(f.voiceState.joins.length, 0);
});

test('another device is only taken over from the server copy after it has gone quiet', async (t) => {
    const live = await fixture(t, { serverCall: { descriptor: room, beat: 1_000_000 - 10_000 } });
    await live.api.recover();
    assert.equal(live.voiceState.joins.length, 0, 'a device beating 10 seconds ago still has the call');
    const quiet = await fixture(t, { serverCall: { descriptor: room, beat: 1_000_000 - 120_000 } });
    await quiet.api.recover();
    await quiet.tick();
    assert.equal(quiet.voiceState.joins.length, 1);
});

test('structure: the call lives in a detached scope, the dock plays the audio, the shells mount the dock', async () => {
    const read = (p) => readFile(new URL(p, root), 'utf8');
    assert.match(source, /effectScope\(true\)/, 'detached from every page');
    const room = await read('Components/Civic/Room/LiveRoom.vue');
    assert.doesNotMatch(room, /useVoiceRoom/, 'the page no longer owns the connection');
    assert.match(room, /useCall\(\)/);
    const tile = await read('Components/Civic/Room/ParticipantTile.vue');
    assert.doesNotMatch(tile, /<audio/, 'the tile never plays audio, so leaving the page cannot cut it');
    const dock = await read('Components/Shell/CallDock.vue');
    assert.match(dock, /RemoteAudio/);
    assert.match(dock, /v-for="a in remoteAudio"/);
    assert.match(dock, /presenting/, 'a shared screen shows in the panel');
    assert.match(dock, /v-for="p in cameras"/, 'cameras show in the panel');
    assert.match(dock, /cga\.call\.minimized/, 'the panel can be minimized and remembers it');
    assert.match(dock, /<template v-if="!minimized">/, 'minimizing drops the video tiles');
    assert.match(dock, /v-if="!onRoomPage && hasVideo"/, 'video is not decoded twice while the room page shows it');
    for (const shell of ['Layouts/AppShellV2.vue', 'Layouts/AppShell.vue']) assert.match(await read(shell), /<DockHost \/>/);
});
