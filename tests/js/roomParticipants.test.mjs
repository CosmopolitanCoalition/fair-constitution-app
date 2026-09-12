import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';

const source = await readFile(new URL('../../resources/js/composables/useRoomParticipants.js', import.meta.url), 'utf8');
async function fixture(t, count = 1) {
    const state = Vue.reactive({ url: '/room/a/participants', room: '!a:fixture', participants: Array.from({ length: count }, (_, n) => ({ identity: `@p${n}:fixture` })), preview: [{ handle: '@p0:fixture', role: 'speaker', display_name: 'Old public name' }] });
    const pending = [], timers = new Map();
    let seq = 0;
    const context = vm.createContext({ AbortController, setTimeout: (fn, ms) => { const id = ++seq; timers.set(id, { fn, ms }); return id; }, clearTimeout: id => timers.delete(id) });
    const mod = new vm.SourceTextModule(source, { context });
    await mod.link(name => {
        const values = name === 'vue' ? Vue : { default: { post: (url, data, options) => new Promise((resolve, reject) => pending.push({ url, data, options, resolve, reject })) } };
        return new vm.SyntheticModule(Object.keys(values), function () { for (const [key, value] of Object.entries(values)) this.setExport(key, value); }, { context });
    });
    await mod.evaluate();
    const scope = Vue.effectScope();
    let lookup;
    scope.run(() => { lookup = mod.namespace.useRoomParticipants({ url: () => state.url, room: () => state.room, participants: () => state.participants, preview: () => state.preview }); });
    t.after(() => scope.stop());
    const flush = async () => { await Vue.nextTick(); await Promise.resolve(); await Promise.resolve(); };
    const reply = async (request, rows, roomId = state.room) => { request.resolve({ data: { roomId, roster: rows } }); await flush(); };
    const tick = async () => { const [id, timer] = timers.entries().next().value; timers.delete(id); timer.fn(); await flush(); return timer.ms; };
    return { state, pending, timers, lookup, scope, flush, reply, tick };
}

test('connected participants beyond preview are batched and refreshed guests replace stale seats', async t => {
    const f = await fixture(t, 205);
    assert.equal(f.pending[0].data.handles.length, 100);
    await f.reply(f.pending[0], f.pending[0].data.handles.map(handle => ({ handle, role: handle === '@p0:fixture' ? 'guest' : 'legislator' })));
    assert.equal(f.pending[1].data.handles.length, 100);
    await f.reply(f.pending[1], f.pending[1].data.handles.map(handle => ({ handle, role: 'legislator' })));
    assert.equal(f.pending[2].data.handles.length, 5);
    await f.reply(f.pending[2], f.pending[2].data.handles.map(handle => ({ handle, role: 'legislator' })));
    assert.equal(f.lookup.roster.value.length, 205);
    assert.equal(f.lookup.roster.value.find(row => row.handle === '@p0:fixture').role, 'guest');
    assert.equal(await f.tick(), 30000);
    assert.equal(f.pending.length, 4);
});

test('cross-room and unmounted responses cannot assign seats and pending requests are aborted', async t => {
    const f = await fixture(t);
    const old = f.pending[0];
    f.state.room = '!b:fixture'; f.state.url = '/room/b/participants'; f.state.preview = [];
    await f.flush();
    assert.equal(old.options.signal.aborted, true);
    await f.reply(old, [{ handle: '@p0:fixture', role: 'speaker' }], '!a:fixture');
    assert.equal(f.lookup.roster.value.length, 0);
    const next = f.pending[1];
    f.scope.stop();
    assert.equal(next.options.signal.aborted, true);
    await f.reply(next, [{ handle: '@p0:fixture', role: 'speaker' }], '!b:fixture');
    assert.equal(f.lookup.roster.value.length, 0);
    assert.equal(f.timers.size, 0);
});

test('transport failures retry without AV coupling and denied refresh clears the office', async t => {
    const f = await fixture(t);
    f.pending[0].reject(new Error('transport down'));
    await f.flush();
    assert.equal(await f.tick(), 5000);
    await f.reply(f.pending[1], [{ handle: '@p0:fixture', role: 'legislator' }]);
    assert.equal(f.lookup.roster.value[0].role, 'legislator');
    await f.tick();
    f.pending[2].reject({ response: { status: 403 } });
    await f.flush();
    assert.equal(f.lookup.roster.value[0].role, 'guest');
    assert.equal(f.timers.size, 1);
});

test('wrong room payload is rejected and departed handles do not retain resolved roles', async t => {
    const f = await fixture(t);
    await f.reply(f.pending[0], [{ handle: '@p0:fixture', role: 'chair' }], '!wrong:fixture');
    assert.equal(f.lookup.roster.value[0].role, 'speaker');
    assert.equal(await f.tick(), 5000);
    await f.reply(f.pending[1], [{ handle: '@p0:fixture', role: 'guest' }]);
    f.state.participants = [];
    await f.flush();
    assert.equal(f.timers.size, 0);
    assert.equal(f.lookup.roster.value[0].role, 'speaker'); // preview remains for offline seats only
});
