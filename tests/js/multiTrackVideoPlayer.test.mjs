// node --experimental-vm-modules --test tests/js/multiTrackVideoPlayer.test.mjs
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';
import { parse, compileScript } from '@vue/compiler-sfc';

const source = readFileSync(new URL('../../resources/js/Components/Media/MultiTrackVideoPlayer.vue', import.meta.url), 'utf8');
// The real c_media English catalog, so the i18n stub resolves the same strings
// the app renders (W-0334): no separate copy of the visible text lives here.
const catalog = JSON.parse(readFileSync(new URL('../../resources/js/i18n/locales/en/c_media.json', import.meta.url), 'utf8'));
// A functional vue-i18n stub over the catalog: t(key, defaultStr) returns the
// catalog value; t(key, namedObj) interpolates {name} placeholders. A missing
// key falls back to the default string, so a raw key can never silently leak.
const i18nStub = {
    useI18n: () => ({
        t(key, arg) {
            const flat = String(key).replace(/^c_media\./, '');
            let value = Object.prototype.hasOwnProperty.call(catalog, flat) ? catalog[flat] : (typeof arg === 'string' ? arg : key);
            if (arg && typeof arg === 'object') for (const name of Object.keys(arg)) value = value.split(`{${name}}`).join(String(arg[name]));
            return value;
        },
        locale: Vue.ref('en'),
    }),
};
// Expose state only in this in-memory test component. Its real template,
// watchers, lifecycle hooks and media event handlers still execute.
const exposed = 'defineExpose({ selAudio, selCap, linked, captionsOn, volume, muted, toggleMute, cues, captionState, audioState, videoState, playing, playbackStatus, loadCaptions, togglePlay, retryVideo, retryAudio, onAudioChange, onCapChange, currentTime, activeVideo, hasPlaylist, hasPrev, hasNext, goPrev, goNext, goTo });';
const { descriptor } = parse(source.replace('</script>', `${exposed}\n</script>`));
const compiled = compileScript(descriptor, { id: 'player-check', inlineTemplate: true });
const tracks = [
    { code: 'en', name: 'English', native: 'English', locale: 'en' },
    { code: 'pl', name: 'Polish', native: 'Polski', locale: 'pl' },
];
const vtt = text => `WEBVTT\n\n00:00.000 --> 00:05.000\n${text}`;
const flush = async () => { await Promise.resolve(); await Vue.nextTick(); await Promise.resolve(); await Vue.nextTick(); };
const wait = ms => new Promise(r => setTimeout(r, ms));
// The player debounces the PUT at 600 ms; wait past it once.
const PUT_WAIT = 750;
const film = (id, title) => ({ id, subject: title, master: `${title}-Silent.mp4`, title, audio: tracks, captions: tracks });

async function fixture(t, options = {}) {
    const requests = [], media = {}, storage = new Map(), changes = [];
    if (options.localPrefs) storage.set('cga:video:prefs', JSON.stringify(options.localPrefs));
    const context = vm.createContext({
        console, AbortController, setTimeout, clearTimeout,
        fetch: (url, request) => new Promise((resolve, reject) => requests.push({ url, ...request, resolve, reject })),
        localStorage: { getItem: key => storage.get(key) ?? null, setItem: (key, value) => storage.set(key, value) },
    });
    const mod = new vm.SourceTextModule(compiled.content, { context });
    await mod.link(name => {
        const values = name === 'vue' ? Vue
            : name === 'vue-i18n' ? i18nStub
            : Object.fromEntries(['Play', 'Pause', 'Captions', 'Volume2', 'VolumeX', 'SkipBack', 'SkipForward'].map(name => [name, { render: () => null }]));
        return new vm.SyntheticModule(Object.keys(values), function () {
            for (const [name, value] of Object.entries(values)) this.setExport(name, value);
        }, { context });
    });
    await mod.evaluate();
    const element = tag => {
        const el = {
            tag, children: [], props: {}, text: '', parent: null,
            addEventListener() {}, removeEventListener() {},
            get options() { return this.children.filter(child => child.tag === 'option'); },
        };
        if (tag === 'video' || tag === 'audio') {
            Object.assign(el, {
                currentTime: 0, duration: 120, readyState: 4, paused: true, ended: false,
                plays: 0, pauses: 0, loads: 0,
                play() { this.plays++; this.paused = false; return this.playResult?.() ?? Promise.resolve(); },
                pause() { this.pauses++; this.paused = true; },
                load() { this.loads++; this.paused = true; },
            });
            media[tag] = el;
        }
        return el;
    };
    const renderer = Vue.createRenderer({
        createElement: element,
        createText: text => ({ tag: '#text', text, children: [], props: {} }),
        createComment: text => ({ tag: '#comment', text, children: [], props: {} }),
        insert(el, parent, anchor) {
            el.parent = parent;
            const previous = parent.children.indexOf(el);
            if (previous >= 0) parent.children.splice(previous, 1);
            const index = anchor ? parent.children.indexOf(anchor) : -1;
            index < 0 ? parent.children.push(el) : parent.children.splice(index, 0, el);
        },
        remove(el) { const siblings = el.parent?.children; if (siblings) siblings.splice(siblings.indexOf(el), 1); },
        patchProp: (el, key, previous, value) => { el.props[key] = value; if (['value', 'type', 'multiple', 'checked'].includes(key)) el[key] = value; },
        setText: (el, text) => { el.text = text; },
        setElementText: (el, text) => { el.text = text; el.children = []; },
        parentNode: el => el.parent, nextSibling: () => null,
    });
    const root = element('root');
    const app = renderer.createApp(mod.namespace.default, {
        video: options.video ?? { id: 'test', subject: 'Test Lesson', master: 'Test Lesson-Silent.mp4', title: 'Test lesson', audio: tracks, captions: tracks },
        baseUrl: options.placeholder ? null : 'https://media.invalid', initialLocale: 'en',
        playlist: options.playlist ?? null,
        serverPrefs: options.serverPrefs ?? null,
        prefsEndpoint: options.prefsEndpoint ?? null,
        onChange: id => changes.push(id),
    });
    const player = app.mount(root);
    let mounted = true;
    const unmount = () => { if (mounted) { app.unmount(); mounted = false; } };
    t.after(unmount);
    const nodes = (el = root) => [el, ...el.children.flatMap(child => nodes(child))];
    const text = el => el.tag === '#comment' ? '' : [el.text, ...el.children.map(text)].join(' ');
    return {
        player, requests, media, storage, changes, unmount,
        text: () => text(root),
        nodes,
        alerts: () => nodes().filter(el => el.props.role === 'alert').map(text),
        button: label => nodes().find(el => el.tag === 'button' && text(el).trim() === label),
        playButton: () => nodes().find(el => el.props['aria-label'] === 'Play or pause'),
        async fire(kind, event) { media[kind].props[event]?.(); await flush(); },
        async captions(request = requests.at(-1), content = 'English') {
            request.resolve({ ok: true, text: async () => vtt(content) }); await flush();
        },
    };
}

test('late caption text cannot replace a newer selected language', async t => {
    const f = await fixture(t);
    const old = f.requests[0];
    f.player.selCap = 'pl'; await flush();
    assert.equal(old.signal.aborted, true);
    await f.captions(f.requests[1], 'Polski');
    await f.captions(old, 'English');
    assert.equal(f.player.selCap, 'pl');
    assert.equal(f.player.cues[0].text, 'Polski');
    assert.equal(f.player.captionState, 'ready');
});

test('a stale failed caption request cannot hide the current captions', async t => {
    const f = await fixture(t);
    const old = f.requests[0];
    f.player.selCap = 'pl'; await flush();
    await f.captions(undefined, 'Polski');
    old.reject(new Error('Old request failed')); await flush();
    assert.equal(f.player.cues[0].text, 'Polski');
    assert.equal(f.alerts().length, 0);
});

test('caption failures have an accessible retry without reloading video or audio', async t => {
    const f = await fixture(t);
    f.requests[0].resolve({ ok: false, status: 503 }); await flush();
    assert.match(f.alerts().join(' '), /captions could not load/);
    f.button('Retry captions').props.onClick(); await flush();
    await f.captions(undefined, 'Retried captions');
    assert.equal(f.player.cues[0].text, 'Retried captions');
    assert.equal(f.alerts().length, 0);
    assert.equal(f.media.video.loads, 0);
    assert.equal(f.media.audio.loads, 0);
});

test('unmount aborts caption requests and ignores late completion', async t => {
    const f = await fixture(t);
    const request = f.requests[0];
    f.unmount();
    assert.equal(request.signal.aborted, true);
    await f.captions(request);
    assert.equal(f.player.cues.length, 0);
    assert.equal(f.media.video.paused, true);
    assert.equal(f.media.audio.paused, true);
});

test('video play refusal shows a retry and keeps audio stopped', async t => {
    const f = await fixture(t);
    f.media.video.playResult = () => Promise.reject(new Error('Playback denied'));
    f.player.togglePlay(); await flush();
    assert.equal(f.player.videoState, 'error');
    assert.match(f.alerts().join(' '), /video could not play/);
    assert.equal(f.playButton().props.disabled, true);
    assert.equal(f.media.audio.paused, true);
    assert.doesNotMatch(f.text(), /In sync/);
});

test('retry video preserves position and does not refetch working captions', async t => {
    const f = await fixture(t);
    await f.captions();
    f.media.video.currentTime = 42;
    await f.fire('video', 'onTimeupdate');
    await f.fire('video', 'onError');
    f.button('Retry video').props.onClick(); await flush();
    f.media.video.currentTime = 0;
    await f.fire('video', 'onLoadedmetadata');
    await f.fire('video', 'onPlaying');
    assert.equal(f.media.video.currentTime, 42);
    assert.equal(f.media.audio.currentTime, 42);
    assert.equal(f.media.video.loads, 1);
    assert.equal(f.requests.length, 1);
    assert.equal(f.alerts().length, 0);
});

test('audio failure preserves video and captions and offers an independent retry', async t => {
    const f = await fixture(t);
    await f.captions();
    f.player.togglePlay(); await flush();
    await f.fire('video', 'onPlaying');
    await f.fire('audio', 'onError');
    assert.equal(f.media.video.paused, false);
    assert.equal(f.player.cues[0].text, 'English');
    assert.equal(f.player.playbackStatus, 'Playing without audio');
    assert.match(f.alerts().join(' '), /selected audio could not play/);
    f.button('Retry audio').props.onClick(); await flush();
    await f.fire('audio', 'onLoadeddata');
    await f.fire('audio', 'onPlaying');
    assert.equal(f.media.audio.loads, 1);
    assert.equal(f.media.video.loads, 0);
    assert.equal(f.player.playbackStatus, 'Playing');
    assert.equal(f.alerts().length, 0);
});

test('audio play refusal is visible and an old refusal cannot break a new language', async t => {
    const f = await fixture(t);
    let rejectAudio;
    f.media.audio.playResult = () => new Promise((resolve, reject) => { rejectAudio = reject; });
    f.player.togglePlay(); await flush();
    f.player.selAudio = 'pl'; await flush();
    rejectAudio(new Error('Previous dub failed')); await flush();
    assert.notEqual(f.player.audioState, 'error');
    f.media.audio.playResult = () => Promise.reject(new Error('Current dub failed'));
    await f.fire('video', 'onPlaying');
    assert.equal(f.player.audioState, 'error');
    assert.match(f.alerts().join(' '), /selected audio could not play/);
    assert.equal(f.media.video.paused, false);
});

test('pause invalidates a pending play refusal', async t => {
    const f = await fixture(t);
    let rejectPlay;
    f.media.video.playResult = () => new Promise((resolve, reject) => { rejectPlay = reject; });
    f.player.togglePlay(); await flush();
    f.player.togglePlay(); await flush();
    rejectPlay(new Error('Play interrupted by pause')); await flush();
    assert.notEqual(f.player.videoState, 'error');
    assert.equal(f.alerts().length, 0);
});

test('buffering stops the dub, recovery aligns it, and ending stops both', async t => {
    const f = await fixture(t);
    f.player.togglePlay(); await flush();
    await f.fire('video', 'onPlaying');
    await f.fire('video', 'onWaiting');
    assert.equal(f.player.playbackStatus, 'Buffering video');
    assert.equal(f.media.audio.paused, true);
    f.media.video.currentTime = 12;
    await f.fire('video', 'onPlaying');
    assert.equal(f.media.audio.currentTime, 12);
    assert.equal(f.media.audio.paused, false);
    await f.fire('audio', 'onWaiting');
    assert.equal(f.player.playbackStatus, 'Video playing; audio buffering');
    f.media.video.ended = true; f.media.video.paused = true;
    await f.fire('video', 'onEnded');
    assert.equal(f.player.playbackStatus, 'Video finished');
    assert.equal(f.media.audio.paused, true);
});

test('language selectors remain linked or independent and store their choices', async t => {
    const f = await fixture(t);
    f.player.selAudio = 'pl'; f.player.onAudioChange(); await flush();
    assert.equal(f.player.selCap, 'pl');
    f.player.selCap = 'en'; f.player.onCapChange(); await flush();
    assert.equal(f.player.selAudio, 'en');
    f.player.linked = false;
    f.player.selCap = 'pl'; f.player.onCapChange(); await flush();
    assert.equal(f.player.selAudio, 'en');
    assert.deepEqual(JSON.parse(f.storage.get('cga:video:prefs')), { audio: 'en', cap: 'pl', linked: false, captionsOn: true, volume: 1, muted: false });
});

test('unconfigured videos show availability honestly and do not pretend to play', async t => {
    const f = await fixture(t, { placeholder: true });
    assert.equal(f.requests.length, 0);
    assert.equal(f.playButton().props.disabled, true);
    f.player.togglePlay(); await flush();
    assert.equal(f.player.playing, false);
    assert.match(f.text(), /Video not available yet/);
    assert.doesNotMatch(f.text(), /In sync/);
});

// ── W-0431 volume + mute (the dub audio only) ────────────────────────────────
test('the volume slider sets the dub audio volume and mute toggles the dub audio', async t => {
    const f = await fixture(t);
    // The master <video> is muted by design and never unmuted.
    assert.equal(f.media.video.props.muted, '');
    assert.equal(f.media.audio.volume, 1);
    assert.equal(f.media.audio.muted, false);
    f.player.volume = 0.5; await flush();
    assert.equal(f.media.audio.volume, 0.5);
    f.player.toggleMute(); await flush();
    assert.equal(f.media.audio.muted, true);
    assert.equal(f.media.video.props.muted, ''); // the master stays muted, unchanged
    f.player.toggleMute(); await flush();
    assert.equal(f.media.audio.muted, false);
    // Volume and mute join the remembered preferences.
    assert.deepEqual(JSON.parse(f.storage.get('cga:video:prefs')), { audio: 'en', cap: 'en', linked: true, captionsOn: true, volume: 0.5, muted: false });
});

// ── W-0432 server-seeded preferences ─────────────────────────────────────────
test('the server prefs document wins over localStorage at mount', async t => {
    const f = await fixture(t, {
        localPrefs: { audio: 'en', cap: 'en', volume: 0.2, muted: true },
        serverPrefs: { audio: 'pl', cap: 'pl', volume: 0.9, muted: false, linked: false, captionsOn: false },
    });
    assert.equal(f.player.selAudio, 'pl');
    assert.equal(f.player.selCap, 'pl');
    assert.equal(f.player.volume, 0.9);
    assert.equal(f.player.muted, false);
    assert.equal(f.player.linked, false);
    assert.equal(f.player.captionsOn, false);
});

test('a change PUTs once per debounce window to the prefs endpoint', async t => {
    const f = await fixture(t, { prefsEndpoint: '/api/me/video-prefs' });
    const puts = () => f.requests.filter(r => r.method === 'PUT');
    f.player.volume = 0.4; await flush();
    f.player.volume = 0.6; await flush();
    assert.equal(puts().length, 0, 'the PUT is debounced, not sent per change');
    await wait(PUT_WAIT);
    assert.equal(puts().length, 1, 'exactly one PUT lands after the window');
    assert.equal(JSON.parse(puts()[0].body).volume, 0.6, 'the PUT carries the last value');
    assert.equal(puts()[0].headers['Content-Type'], 'application/json');
});

test('no prefs endpoint means localStorage only, never a PUT', async t => {
    const f = await fixture(t);
    f.player.volume = 0.3; await flush();
    await wait(PUT_WAIT);
    assert.equal(f.requests.filter(r => r.method === 'PUT').length, 0);
    assert.equal(JSON.parse(f.storage.get('cga:video:prefs')).volume, 0.3);
});

// ── W-0430 playlist ──────────────────────────────────────────────────────────
test('a single-film playlist shows no navigation controls', async t => {
    const one = [film('a', 'Alpha')];
    const f = await fixture(t, { video: one[0], playlist: one });
    assert.equal(f.player.hasPlaylist, false);
    assert.equal(f.nodes().find(el => el.props['aria-label'] === 'Previous film'), undefined);
    assert.equal(f.nodes().find(el => el.props['aria-label'] === 'Next film'), undefined);
    assert.equal(f.nodes().find(el => el.props['aria-label'] === 'Select film'), undefined);
});

test('previous, select and next move through a multi-film playlist and emit change', async t => {
    const three = [film('a', 'Alpha'), film('b', 'Bravo'), film('c', 'Charlie')];
    const f = await fixture(t, { video: three[0], playlist: three });
    assert.equal(f.player.hasPlaylist, true);
    assert.equal(f.player.activeVideo.id, 'a');
    assert.equal(f.player.hasPrev, false);
    f.player.goNext(); await flush();
    assert.equal(f.player.activeVideo.id, 'b');
    assert.deepEqual(f.changes, ['b']);
    f.player.goTo('c'); await flush();
    assert.equal(f.player.activeVideo.id, 'c');
    assert.equal(f.player.hasNext, false);
    f.player.goPrev(); await flush();
    assert.equal(f.player.activeVideo.id, 'b');
    assert.deepEqual(f.changes, ['b', 'c', 'b']);
});

test('a film that ends auto-advances to the next with the same language choices', async t => {
    const two = [film('a', 'Alpha'), film('b', 'Bravo')];
    const f = await fixture(t, { video: two[0], playlist: two });
    f.player.selAudio = 'pl'; await flush();
    f.media.video.ended = true; f.media.video.paused = true;
    await f.fire('video', 'onEnded');
    assert.equal(f.player.activeVideo.id, 'b', 'advanced to the next film');
    assert.equal(f.player.selAudio, 'pl', 'the language choice carries over');
    // The queued auto-play starts once the next film decodes.
    const playsBefore = f.media.video.plays;
    await f.fire('video', 'onLoadeddata');
    assert.ok(f.media.video.plays > playsBefore, 'the next film starts playing');
});

test('the last film does not auto-advance past the end', async t => {
    const two = [film('a', 'Alpha'), film('b', 'Bravo')];
    const f = await fixture(t, { video: two[1], playlist: two });
    f.media.video.ended = true; f.media.video.paused = true;
    await f.fire('video', 'onEnded');
    assert.equal(f.player.activeVideo.id, 'b');
    assert.equal(f.player.playbackStatus, 'Video finished');
});

// ── W-0334 strings ───────────────────────────────────────────────────────────
test('every visible and aria string resolves through i18n with no raw key leak', async t => {
    const three = [film('a', 'Alpha'), film('b', 'Bravo')];
    const f = await fixture(t, { video: three[0], playlist: three });
    await f.captions();
    // No node text carries a raw namespace key.
    assert.doesNotMatch(f.text(), /c_media\./);
    // No aria/title/label attribute carries a raw namespace key either.
    const leaks = f.nodes().filter(el => Object.values(el.props).some(v => typeof v === 'string' && v.includes('c_media.')));
    assert.deepEqual(leaks, [], 'no attribute leaks a raw i18n key');
    // The resolved labels are the catalog English.
    assert.ok(f.nodes().some(el => el.props['aria-label'] === 'Play or pause'));
    assert.ok(f.nodes().some(el => el.props['aria-label'] === 'Previous film'));
    assert.ok(f.nodes().some(el => el.props['aria-label'] === 'Mute audio'));
});
