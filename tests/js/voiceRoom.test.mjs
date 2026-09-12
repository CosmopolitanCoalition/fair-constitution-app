// Existing runtime only: node --experimental-vm-modules --test tests/js/voiceRoom.test.mjs
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { EventEmitter } from 'node:events';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';
import { renderToString } from '@vue/server-renderer';
import { parse, compileScript } from '@vue/compiler-sfc';
import { ConnectionState, RoomEvent, Track } from 'livekit-client';

const source = await readFile(new URL('../../resources/js/composables/useVoiceRoom.js', import.meta.url), 'utf8');
const grant = { sfu_url: 'ws://fixture.invalid', token: 'fixture-token' };
const joinArgs = { jurisdictionId: 'place', room: 'commons', pseudonym: '@public-label', subjectUserId: 'viewer' };
const deferred = () => {
    let resolve, reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return { promise, resolve, reject };
};
const settleUntil = async (predicate) => {
    for (let i = 0; i < 20 && !predicate(); i++) await new Promise(setImmediate);
    assert.ok(predicate(), 'async fixture reached the expected await');
};
const failure = (name) => Object.assign(new Error('fixture failure'), { name });

function moduleOf(context, values) {
    return new vm.SyntheticModule(Object.keys(values), function () {
        for (const [key, value] of Object.entries(values)) this.setExport(key, value);
    }, { context });
}

async function fixture(t, options = {}) {
    const rooms = [], tokenCalls = [], deviceListeners = new Set();
    class Room extends EventEmitter {
        constructor() {
            super();
            this.index = rooms.length;
            this.alive = false;
            this.canPlaybackAudio = options.playback !== false;
            this.disconnects = 0;
            this.mediaCalls = [];
            this.remoteParticipants = new Map();
            this.localParticipant = {
                identity: '@public-label', name: 'Public chosen name', isLocal: true, isSpeaking: false,
                isMicrophoneEnabled: false, isCameraEnabled: false, isScreenShareEnabled: false,
                trackPublications: new Map(),
                getTrackPublication: () => null,
            };
            for (const [method, flag] of [
                ['setMicrophoneEnabled', 'isMicrophoneEnabled'],
                ['setCameraEnabled', 'isCameraEnabled'],
                ['setScreenShareEnabled', 'isScreenShareEnabled'],
            ]) {
                this.localParticipant[method] = async (enabled) => {
                    this.mediaCalls.push([method, enabled]);
                    await options.media?.(method, enabled);
                    this.localParticipant[flag] = enabled;
                    this.localParticipant.trackPublications.set(method, { track: {
                        stop: () => { this.localParticipant[flag] = false; },
                    } });
                    this.emit(RoomEvent.LocalTrackPublished);
                };
            }
            rooms.push(this);
        }
        async connect(url, token) {
            this.connecting = true;
            assert.equal(url, grant.sfu_url);
            assert.equal(token, grant.token);
            await options.connect?.(this.index);
            this.connecting = false;
            this.alive = true;
            this.emit(RoomEvent.ConnectionStateChanged, ConnectionState.Connected);
        }
        async disconnect() {
            ++this.disconnects;
            // Match LiveKit: a second disconnect is a no-op, including tracks.
            if (!this.alive && !this.connecting) return;
            this.connecting = false;
            this.alive = false;
            for (const flag of ['isMicrophoneEnabled', 'isCameraEnabled', 'isScreenShareEnabled']) this.localParticipant[flag] = false;
            this.emit(RoomEvent.Disconnected);
            if (options.disconnectFails) throw failure('DisconnectFailure');
        }
        getActiveDevice() { return ''; }
        async startAudio() {
            await options.startAudio?.();
            this.canPlaybackAudio = true;
            this.emit(RoomEvent.AudioPlaybackStatusChanged);
        }
        async switchActiveDevice(kind, id) { await options.switchDevice?.(kind, id); }
    }
    const context = vm.createContext({
        navigator: { mediaDevices: {
            enumerateDevices: async () => options.devices ?? [],
            addEventListener: (event, handler) => deviceListeners.add(handler),
            removeEventListener: (event, handler) => deviceListeners.delete(handler),
        } },
    });
    const sdk = moduleOf(context, { Room, ConnectionState, RoomEvent, Track });
    await sdk.link(() => {});
    await sdk.evaluate();
    const code = new vm.SourceTextModule(source, {
        context,
        importModuleDynamically: async (name) => {
            assert.equal(name, 'livekit-client');
            await options.importSDK?.();
            return sdk;
        },
    });
    await code.link(name => {
        if (name === 'vue') return moduleOf(context, Vue);
        assert.equal(name, '../lib/deviceIdentity.js');
        return moduleOf(context, { requestVoiceToken: async (args) => {
            tokenCalls.push(args);
            return options.token ? options.token(tokenCalls.length) : grant;
        } });
    });
    await code.evaluate();
    const scope = Vue.effectScope();
    const voice = scope.run(() => code.namespace.useVoiceRoom());
    t.after(() => scope.stop());
    return { voice, rooms, tokenCalls, scope, deviceListeners };
}

test('join is listen-only, preserves public labels, and keeps explicit token authority', async t => {
    const f = await fixture(t);
    let privateCalls = 0;
    await f.voice.join({ ...joinArgs, video: true, tokenRequester: async args => {
        ++privateCalls;
        assert.equal(args.subjectUserId, 'viewer');
        return grant;
    } });
    assert.equal(privateCalls, 1);
    assert.equal(f.tokenCalls.length, 0);
    assert.equal(f.voice.connectionState.value, 'connected');
    assert.equal(f.voice.micEnabled.value, false);
    assert.equal(f.voice.cameraEnabled.value, false);
    assert.equal(f.voice.participants.value[0].display_name, 'Public chosen name');
    assert.deepEqual(f.rooms[0].mediaCalls, []);
    assert.equal(f.deviceListeners.size, 1);
    await f.voice.leave();
    assert.equal(f.rooms[0].alive, false);
    assert.equal(f.deviceListeners.size, 0);
});

test('denied microphone/camera permissions leave the call connected with truthful device state', async t => {
    const f = await fixture(t, { media: async () => { throw failure('NotAllowedError'); } });
    await f.voice.join(joinArgs);
    for (const [method, key] of [['toggleMic', 'mic'], ['toggleCamera', 'camera']]) {
        await f.voice[method]();
        assert.equal(f.voice.error.value, key + '_permission_denied');
        assert.equal(f.voice.connectionState.value, 'connected');
        assert.equal(f.voice[key === 'mic' ? 'micEnabled' : 'cameraEnabled'].value, false);
        assert.equal(f.voice.mediaPending.value[key], false);
        assert.equal(f.rooms[0].disconnects, 0);
    }
});

test('explicit controls wait for the device and ignore duplicate clicks', async t => {
    const gate = deferred();
    const f = await fixture(t, { media: () => gate.promise });
    await f.voice.join(joinArgs);
    const enable = f.voice.toggleMic();
    await f.voice.toggleMic();
    assert.equal(f.rooms[0].mediaCalls.length, 1);
    assert.equal(f.voice.mediaPending.value.mic, true);
    assert.equal(f.voice.micEnabled.value, false);
    gate.resolve();
    await enable;
    assert.equal(f.voice.micEnabled.value, true);
    assert.equal(f.voice.mediaPending.value.mic, false);
    await f.voice.toggleMic();
    assert.equal(f.voice.micEnabled.value, false);
});

test('leave invalidates a pending token without unlocking a newer join attempt', async t => {
    const oldToken = deferred(), newToken = deferred();
    const f = await fixture(t, { token: n => (n === 1 ? oldToken : newToken).promise });
    const oldJoin = f.voice.join(joinArgs);
    await f.voice.join(joinArgs);
    assert.equal(f.tokenCalls.length, 1);
    await f.voice.leave();
    const newJoin = f.voice.join(joinArgs);
    oldToken.resolve(grant);
    await oldJoin;
    await f.voice.join(joinArgs);
    assert.equal(f.tokenCalls.length, 2);
    assert.equal(f.rooms.length, 0);
    newToken.resolve(grant);
    await newJoin;
    assert.equal(f.rooms.length, 1);
    assert.equal(f.voice.connectionState.value, 'connected');
});

test('unmount while a token is pending prevents later connections and future joins', async t => {
    const token = deferred();
    const f = await fixture(t, { token: () => token.promise });
    const join = f.voice.join(joinArgs);
    f.scope.stop();
    token.resolve(grant);
    await join;
    await f.voice.join(joinArgs);
    assert.equal(f.rooms.length, 0);
    assert.equal(f.voice.connectionState.value, 'disconnected');
});

test('leave while the SDK loads does not construct an abandoned room', async t => {
    const gate = deferred();
    let importing = false;
    const f = await fixture(t, { importSDK: () => { importing = true; return gate.promise; } });
    const join = f.voice.join(joinArgs);
    await settleUntil(() => importing);
    await f.voice.leave();
    gate.resolve();
    await join;
    assert.equal(f.rooms.length, 0);
});

test('a late connect closes itself and cannot overwrite a newer connected room', async t => {
    const connection = deferred();
    const f = await fixture(t, { connect: n => n === 0 ? connection.promise : undefined });
    const oldJoin = f.voice.join(joinArgs);
    await settleUntil(() => f.rooms.length === 1);
    await f.voice.leave();
    await f.voice.join(joinArgs);
    connection.resolve();
    await oldJoin;
    assert.equal(f.rooms[0].alive, false);
    assert.equal(f.rooms[1].alive, true);
    assert.equal(f.voice.connectionState.value, 'connected');
    f.rooms[0].emit(RoomEvent.Disconnected);
    assert.equal(f.voice.connectionState.value, 'connected');
});

test('scope disposal during connect closes even a late successful connection', async t => {
    const gate = deferred();
    const f = await fixture(t, { connect: () => gate.promise });
    const join = f.voice.join(joinArgs);
    await settleUntil(() => f.rooms.length === 1);
    f.scope.stop();
    gate.resolve();
    await join;
    assert.equal(f.rooms[0].alive, false);
    assert.equal(f.voice.connectionState.value, 'disconnected');
    assert.equal(f.deviceListeners.size, 0);
});

test('connect failures remain visible even when cleanup rejects, and a retry works', async t => {
    const f = await fixture(t, { connect: n => { if (n === 0) throw failure('ConnectionError'); }, disconnectFails: true });
    await assert.rejects(f.voice.join(joinArgs));
    assert.equal(f.voice.connectionState.value, 'error');
    assert.equal(f.voice.error.value, 'sfu_connect_failed');
    assert.equal(f.rooms[0].alive, false);
    await f.voice.join(joinArgs);
    assert.equal(f.voice.connectionState.value, 'connected');
    assert.equal(f.voice.error.value, null);
});

test('unavailable hosts degrade to text while denied tokens remain explicit errors', async t => {
    const f = await fixture(t, { token: n => { throw { response: { status: n === 1 ? 503 : 403, data: { error: 'room_not_accessible' } } }; } });
    await f.voice.join(joinArgs);
    assert.equal(f.voice.connectionState.value, 'degraded');
    assert.equal(f.voice.degraded.value, true);
    await assert.rejects(f.voice.join(joinArgs));
    assert.equal(f.voice.connectionState.value, 'error');
    assert.equal(f.voice.degraded.value, false);
    assert.equal(f.voice.error.value, 'room_not_accessible');
    assert.equal(f.rooms.length, 0);
});

test('reconnection blocks device changes; a final disconnect clears media and permits rejoin', async t => {
    const f = await fixture(t);
    await f.voice.join(joinArgs);
    await f.voice.toggleMic();
    f.rooms[0].emit(RoomEvent.ConnectionStateChanged, ConnectionState.Reconnecting);
    assert.equal(f.voice.connectionState.value, 'reconnecting');
    await f.voice.toggleCamera();
    assert.equal(f.rooms[0].mediaCalls.length, 1);
    f.rooms[0].emit(RoomEvent.ConnectionStateChanged, ConnectionState.Connected);
    assert.equal(f.voice.connectionState.value, 'connected');
    f.rooms[0].emit(RoomEvent.Disconnected);
    assert.equal(f.voice.connectionState.value, 'error');
    assert.equal(f.voice.error.value, 'room_disconnected');
    assert.equal(f.voice.micEnabled.value, false);
    assert.equal(f.voice.participants.value.length, 0);
    assert.equal(f.deviceListeners.size, 0);
    await f.voice.join(joinArgs);
    assert.equal(f.rooms.length, 2);
});

test('permission granted after leaving stops newly acquired media and does not touch the new room', async t => {
    const permission = deferred();
    const f = await fixture(t, { media: () => permission.promise });
    await f.voice.join(joinArgs);
    const enabling = f.voice.toggleCamera();
    await f.voice.leave();
    await f.voice.join(joinArgs);
    permission.resolve();
    await enabling;
    assert.equal(f.rooms[0].localParticipant.isCameraEnabled, false);
    assert.equal(f.rooms[0].alive, false);
    assert.equal(f.rooms[1].alive, true);
    assert.equal(f.voice.cameraEnabled.value, false);
    assert.equal(f.voice.connectionState.value, 'connected');
});

test('screen/device failures are readable and do not disconnect listening', async t => {
    const f = await fixture(t, {
        media: () => { throw failure('NotFoundError'); },
        switchDevice: () => { throw failure('NotSupportedError'); },
    });
    await f.voice.join(joinArgs);
    await f.voice.toggleScreenShare();
    assert.equal(f.voice.error.value, 'screen_unavailable');
    await f.voice.selectDevice('speaker', 'missing');
    assert.equal(f.voice.error.value, 'speaker_failed');
    assert.equal(f.voice.selectedDevices.value.speaker, '');
    assert.equal(f.voice.connectionState.value, 'connected');
});

test('browser-blocked audio can be enabled without capture and can recover from a failed attempt', async t => {
    let tries = 0;
    const f = await fixture(t, { playback: false, startAudio: () => {
        if (++tries === 1) throw failure('NotAllowedError');
    } });
    await f.voice.join(joinArgs);
    assert.equal(f.voice.audioBlocked.value, true);
    await f.voice.startAudio();
    assert.equal(f.voice.error.value, 'audio_playback_failed');
    assert.equal(f.voice.connectionState.value, 'connected');
    await f.voice.startAudio();
    assert.equal(f.voice.audioBlocked.value, false);
    assert.equal(f.voice.error.value, null);
    assert.deepEqual(f.rooms[0].mediaCalls, []);
    f.rooms[0].canPlaybackAudio = false;
    f.rooms[0].emit(RoomEvent.AudioPlaybackStatusChanged);
    assert.equal(f.voice.audioBlocked.value, true);
});

async function component(file, context, imports) {
    const text = await readFile(new URL('../../resources/js/' + file, import.meta.url), 'utf8');
    const { descriptor, errors } = parse(text, { filename: file });
    assert.deepEqual(errors, []);
    const { content } = compileScript(descriptor, { id: file, inlineTemplate: true });
    const mod = new vm.SourceTextModule(content, { context });
    if (!imports) return mod; // Parsing compiled LiveRoom also checks its template.
    await mod.link(name => name === 'vue' ? moduleOf(context, Vue) : imports(name));
    await mod.evaluate();
    return mod;
}

test('room controls render join/cancel, accessible capture controls, and honest reconnect state', async () => {
    const context = vm.createContext({});
    const icon = moduleOf(context, { default: { render: () => null } });
    const button = await component('Components/Ui/Btn.vue', context, () => icon);
    const icons = moduleOf(context, Object.fromEntries(['Mic', 'MicOff', 'PhoneCall', 'PhoneOff', 'ScreenShare', 'ScreenShareOff', 'Video', 'VideoOff', 'Volume2'].map(name => [name, { render: () => null }])));
    const controls = await component('Components/Civic/Room/VoiceControls.vue', context, name => name === 'lucide-vue-next' ? icons : button);
    const render = props => renderToString(Vue.createSSRApp(controls.namespace.default, props));
    const initial = await render({});
    assert.match(initial, /Join room/);
    assert.doesNotMatch(initial, /aria-label="Turn microphone on"/);
    assert.match(initial, /microphone and camera stay off/);
    const pending = await render({ connectionState: 'connecting' });
    assert.match(pending, /Cancel joining/);
    assert.doesNotMatch(pending, /Leave call/);
    const connected = await render({ connectionState: 'connected' });
    assert.match(connected, /aria-label="Turn microphone on"/);
    assert.match(connected, /aria-label="Turn camera on"/);
    assert.match(connected, /aria-pressed="false"/);
    assert.match(connected, /Listening only/);
    const blocked = await render({ connectionState: 'connected', audioBlocked: true });
    assert.match(blocked, /Enable room audio/);
    assert.match(blocked, /browser paused audio/);
    assert.doesNotMatch(blocked, /Listening only/);
    const reconnecting = await render({ connectionState: 'reconnecting' });
    assert.match(reconnecting, /Reconnecting/);
    assert.match(reconnecting, /disabled[^>]*aria-pressed="false"/);
    assert.match(reconnecting, /Leave call/);
    await component('Components/Civic/Room/LiveRoom.vue', context);
});
