// node --experimental-vm-modules --test tests/js/useLiveRoom.test.mjs
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';
import * as policy from '../../resources/js/composables/liveRoomPolicy.js';

const source = await readFile(new URL('../../resources/js/composables/useLiveRoom.js', import.meta.url), 'utf8');

async function fixture(t, options = {}) {
    let now = 0, sequence = 0, state = options.state ?? 'open', writing = false;
    const timers = new Map(), requests = [], listeners = new Set();
    const document = {
        hidden: false,
        addEventListener: (event, fn) => listeners.add(fn),
        removeEventListener: (event, fn) => listeners.delete(fn),
    };
    const context = vm.createContext({
        Date: { now: () => now }, document,
        window: {
            setTimeout: (fn, delay) => { const id = ++sequence; timers.set(id, { fn, at: now + delay }); return id; },
            clearTimeout: id => timers.delete(id),
        },
    });
    const mod = new vm.SourceTextModule(source, { context });
    await mod.link(name => {
        const values = name === 'vue' ? Vue : name === './liveRoomPolicy' ? policy : {
            router: { reload: request => {
                requests.push(request);
                if (options.throws) throw new Error('fixture synchronous transport failure');
            } },
        };
        return new vm.SyntheticModule(Object.keys(values), function () {
            for (const [key, value] of Object.entries(values)) this.setExport(key, value);
        }, { context });
    });
    await mod.evaluate();
    const renderer = Vue.createRenderer({
        createElement: () => ({}), createText: () => ({}), createComment: () => ({}),
        insert() {}, remove() {}, patchProp() {}, setText() {}, setElementText() {},
        parentNode: () => null, nextSibling: () => null,
    });
    let room;
    const app = renderer.createApp({ setup() {
        room = mod.namespace.useLiveRoom({
            keys: ['status', 'chat'], isLive: () => state, busy: () => writing,
            ...(options.cadenceMs === undefined ? {} : { cadenceMs: options.cadenceMs }),
        });
        return () => Vue.h('div');
    } });
    app.mount({});
    let mounted = true;
    const unmount = () => { if (mounted) { app.unmount(); mounted = false; } };
    t.after(unmount);
    return {
        room, timers, requests, listeners, unmount,
        state: value => { state = value; }, busy: value => { writing = value; },
        advance(ms) {
            now += ms;
            for (const [id, timer] of [...timers]) {
                if (timer.at <= now && timers.has(id)) { timers.delete(id); timer.fn(); }
            }
        },
        visible(value) { document.hidden = !value; for (const fn of listeners) fn(); },
        succeed(request = requests.at(-1)) { request.onSuccess(); request.onFinish(); },
    };
}

test('failed and cancelled reloads retry; only merged snapshots advance freshness', async t => {
    const f = await fixture(t);
    f.advance(5000);
    assert.equal(f.requests.length, 1);
    f.requests[0].onFinish(); // network failure: no successful snapshot
    assert.equal(f.timers.size, 1);
    assert.equal(f.room.lastSyncedAt.value, null);
    f.advance(5000);
    f.requests[1].onFinish({ cancelled: true });
    assert.equal(f.timers.size, 1);
    assert.equal(f.room.lastSyncedAt.value, null);
    f.advance(5000);
    f.succeed();
    assert.equal(f.room.lastSyncedAt.value, 15000);
    assert.equal(f.timers.size, 1);
    assert.equal(f.requests[2].preserveState, true);
    assert.equal(f.requests[2].preserveScroll, true);
    assert.deepEqual(Array.from(f.requests[2].only), ['status', 'chat']);
});

test('stop/unmount invalidate pending callbacks and stale callbacks cannot replace newer timers', async t => {
    const f = await fixture(t);
    f.advance(5000);
    const old = f.requests[0];
    f.room.stop();
    assert.equal(f.room.isPolling.value, false);
    f.succeed(old);
    assert.equal(f.timers.size, 0);
    assert.equal(f.room.lastSyncedAt.value, null);
    f.room.start();
    f.advance(5000);
    const pending = f.requests[1];
    f.room.stop();
    f.room.start();
    const freshTimer = [...f.timers.keys()];
    f.succeed(pending);
    assert.deepEqual([...f.timers.keys()], freshTimer);
    f.advance(5000);
    const unmounted = f.requests[2];
    f.unmount();
    f.succeed(unmounted);
    f.room.start();
    f.room.refresh();
    assert.equal(f.requests.length, 3);
    assert.equal(f.timers.size, 0);
    assert.equal(f.listeners.size, 0);
    assert.equal(f.room.isPolling.value, false);
});

test('the closing snapshot lands before adjourned polling stops; manual refresh stays one-shot', async t => {
    const f = await fixture(t);
    f.advance(5000);
    assert.equal(f.room.isPolling.value, true);
    f.state('adjourned'); // Inertia merges props before invoking onSuccess.
    f.requests[0].onSuccess();
    assert.equal(f.room.lastSyncedAt.value, 5000);
    f.requests[0].onFinish();
    assert.equal(f.room.isPolling.value, false);
    assert.equal(f.timers.size, 0);
    f.advance(60000);
    assert.equal(f.requests.length, 1);
    f.room.refresh();
    assert.equal(f.requests.length, 2);
    f.succeed();
    assert.equal(f.room.lastSyncedAt.value, 65000);
    assert.equal(f.timers.size, 0);
});

test('busy writes skip ticks and visibility pauses invalidate old requests before resuming', async t => {
    const f = await fixture(t);
    f.busy(true);
    f.advance(5000);
    assert.equal(f.requests.length, 0);
    assert.equal(f.timers.size, 1);
    f.busy(false);
    f.advance(5000);
    const old = f.requests[0];
    f.visible(false);
    f.succeed(old);
    f.room.refresh();
    assert.equal(f.timers.size, 0);
    assert.equal(f.requests.length, 1);
    f.visible(true);
    assert.equal(f.timers.size, 1);
    assert.equal(f.requests.length, 2, 'visibility regain still fetches an immediate snapshot');
    f.succeed();
    assert.equal(f.room.lastSyncedAt.value, 10000);
    assert.equal(f.timers.size, 1);
});

test('scheduled heartbeat and per-channel cadence survive retries without doubling timers', async t => {
    const f = await fixture(t, {
        state: 'scheduled',
        cadenceMs: { chat: { keys: ['chat'], ms: 2000 }, state: { keys: ['status'], ms: 5000 } },
    });
    assert.equal(f.timers.size, 2);
    f.advance(29999);
    assert.equal(f.requests.length, 0);
    f.advance(1);
    assert.equal(f.requests.length, 2);
    f.state('open');
    for (const request of f.requests) { request.onFinish(); request.onFinish(); }
    assert.equal(f.timers.size, 2);
    f.advance(2000);
    assert.equal(f.requests.length, 3);
    assert.deepEqual(Array.from(f.requests[2].only), ['chat']);
    f.succeed();
    f.room.stop();
    assert.equal(f.timers.size, 0);
});

test('a synchronous transport failure retries and initially adjourned rooms never start timers', async t => {
    const f = await fixture(t, { throws: true });
    f.advance(5000);
    assert.equal(f.requests.length, 1);
    assert.equal(f.timers.size, 1);
    f.advance(5000);
    assert.equal(f.requests.length, 2);
    const closed = await fixture(t, { state: 'adjourned' });
    assert.equal(closed.timers.size, 0);
    closed.room.refresh();
    closed.succeed();
    assert.equal(closed.requests.length, 1);
    assert.equal(closed.timers.size, 0);
});
