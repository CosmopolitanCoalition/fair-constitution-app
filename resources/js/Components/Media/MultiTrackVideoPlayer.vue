<script setup>
/**
 * Media/MultiTrackVideoPlayer — the app port of the operator's Coalition
 * multi-track player (cosmopolitancoalition.org · functions/video_player.php),
 * app-shaped from the v3 mockup (components-v2.js videoPlayer/initVideo). ONE
 * silent master video + a per-language audio track kept in sync + per-language
 * captions, so one film speaks many languages without rendering many films.
 *
 * Two modes, one component:
 *   • REAL   — a media base URL is configured: the <video> plays the silent
 *              master, a hidden <audio> plays the chosen language dub and is
 *              drift-corrected back to the master whenever it slips past 0.3s,
 *              and captions are the chosen language's .vtt cues.
 *   • POSTER — no base URL (dev/demo): the labelled poster stage stands in, the
 *              language controls stay live, and the caption bar shows a sample line. The
 *              same component lights up with real playback the moment the
 *              operator points it at the media host.
 *
 * W-0430 playlist: an optional `playlist` prop (MediaMeta records). When it
 * holds more than one film the player renders previous / a film <select> / next
 * and auto-advances on ended, keeping the same language choices, and emits
 * `change` with the new id so the page URL can follow.
 * W-0431 volume: a mute button and a 0..1 slider act on the dub <audio> only;
 * the master <video> stays muted by design.
 * W-0432 account prefs: `serverPrefs` seeds the remembered choices at mount
 * (server wins over localStorage) and, when `prefsEndpoint` is set (signed-in),
 * changes PUT there debounced; localStorage stays the fallback and cache.
 * W-0334 strings: every visible and aria string resolves through vue-i18n
 * (namespace c_media), with an English fallback when no i18n is installed.
 *
 * Track codes are BCP-47; media filenames embed the language ENGLISH NAME and
 * the subject verbatim ("<Subject>-<Name>.<ext>"), percent-encoded PER SEGMENT.
 * Styling is the shipped .vplayer* / .vposter--* / .vtrack* set in
 * components-v2.css — this component adds none.
 */
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { useI18n } from 'vue-i18n';
/* The transport glyphs the app's 38-name Icon vocabulary does not carry
   (pause/captions/volume/skip). lucide-vue-next is already the engine behind
   Ui/Icon; importing these glyphs directly keeps this player self-contained
   without widening the shared Icon component. */
import { Play, Pause, Captions, Volume2, VolumeX, SkipBack, SkipForward } from 'lucide-vue-next';

const props = defineProps({
    /** An enriched MediaMeta record: {id, subject, master, title, summary,
     *  poster, seconds, audio[], captions[]} where audio/captions are
     *  {code, name, native, dir, locale}. */
    video: { type: Object, required: true },
    /** Media host base URL, or null for poster mode. */
    baseUrl: { type: String, default: null },
    /** The viewer's app locale — the first audio/captions choice links to it. */
    initialLocale: { type: String, default: 'en' },
    /** W-0430: the whole catalog (MediaMeta records). More than one film shows
     *  the previous / select / next controls and enables auto-advance. */
    playlist: { type: Array, default: null },
    /** W-0432: the signed-in viewer's stored prefs document, or null. Seeds the
     *  remembered choices at mount; the server value wins over localStorage. */
    serverPrefs: { type: Object, default: null },
    /** W-0432: the PUT endpoint for the viewer's prefs, or null for a guest
     *  (localStorage-only). When set, changes are written here debounced. */
    prefsEndpoint: { type: String, default: null },
});

const emit = defineEmits(['change']);

/* W-0334: translate through vue-i18n when it is installed (the app), else fall
   back to the inline English (the standalone browser harness / synthetic
   tests). The catalog carries the same English, so both paths render alike. */
let i18n = null;
try { i18n = useI18n({ useScope: 'global' }); } catch { i18n = null; }
function tr(key, fallback, named) {
    if (i18n) {
        const out = named ? i18n.t(key, named) : i18n.t(key, fallback);
        return typeof out === 'string' ? out : fallback;
    }
    let out = fallback;
    if (named) for (const name in named) out = out.split('{' + name + '}').join(String(named[name]));
    return out;
}

const PREF_KEY = 'cga:video:prefs';
const PUT_DEBOUNCE_MS = 600;

const videoEl = ref(null);
const audioEl = ref(null);

const hasMedia = computed(() => !!props.baseUrl);

/* ── Playlist (W-0430) ────────────────────────────────────────────────────
   The player owns the active selection so it can auto-advance without a
   remount. activeVideo defaults to the `video` prop; the playlist, when it has
   more than one film, drives the previous / select / next controls. */
const list = computed(() => (Array.isArray(props.playlist) ? props.playlist : []));
const hasPlaylist = computed(() => list.value.length > 1);
const activeId = ref(props.video.id);
const activeVideo = computed(() => list.value.find((v) => v.id === activeId.value) ?? props.video);
const activeIndex = computed(() => list.value.findIndex((v) => v.id === activeId.value));
const hasPrev = computed(() => hasPlaylist.value && activeIndex.value > 0);
const hasNext = computed(() => hasPlaylist.value && activeIndex.value >= 0 && activeIndex.value < list.value.length - 1);
let autoAdvancePlay = false;

function goTo(id) {
    if (!id || id === activeId.value) return;
    activeId.value = id;
    emit('change', id);
}
function goPrev() { if (hasPrev.value) goTo(list.value[activeIndex.value - 1].id); }
function goNext() { if (hasNext.value) goTo(list.value[activeIndex.value + 1].id); }
const selectedFilmId = computed({ get: () => activeId.value, set: (id) => goTo(id) });

/* Track lists (arrays of {code, name, native, dir, locale}). */
const audioTracks = computed(() => activeVideo.value.audio ?? []);
const capTracks = computed(() => activeVideo.value.captions ?? []);

function codeForLocale(tracks, locale) {
    const byLocale = tracks.find((t) => t.locale && t.locale === locale);
    if (byLocale) return byLocale.code;
    const en = tracks.find((t) => t.code === 'en');
    return en ? en.code : (tracks[0]?.code ?? 'en');
}

/* ── State (seeded from stored prefs, else the viewer's locale) ───────────── */
const stored = readPrefs();
const seedPrefs = props.serverPrefs && typeof props.serverPrefs === 'object' ? props.serverPrefs : null;
/* W-0432: the server document wins over localStorage at mount. */
const seed = { ...stored, ...(seedPrefs ?? {}) };
const selAudio = ref(has(audioTracks.value, seed.audio) ? seed.audio : codeForLocale(audioTracks.value, props.initialLocale));
const selCap = ref(has(capTracks.value, seed.cap) ? seed.cap : codeForLocale(capTracks.value, props.initialLocale));
const linked = ref(seed.linked !== false);
const captionsOn = ref(seed.captionsOn !== false);
const volume = ref(clampVol(typeof seed.volume === 'number' ? seed.volume : 1));
const muted = ref(seed.muted === true);

const playing = ref(false);
const currentTime = ref(0);
const duration = ref(activeVideo.value.seconds ?? 0);
const videoState = ref('loading');
const audioState = ref('loading');
const captionState = ref('loading');
let disposed = false;
let mounted = false;
let videoAttempt = 0;
let audioAttempt = 0;
let resumeAt = null;

function clampVol(v) { return Math.min(1, Math.max(0, Number.isFinite(v) ? v : 1)); }

/* ── Track metadata lookups ──────────────────────────────────────────────── */
function has(tracks, code) { return !!code && tracks.some((t) => t.code === code); }
function meta(tracks, code) { return tracks.find((t) => t.code === code) ?? null; }
const capMeta = computed(() => meta(capTracks.value, selCap.value));
const capDir = computed(() => capMeta.value?.dir ?? 'ltr');

/* ── Media URLs — per-segment encoding (never encodeURI on the whole path,
   never '+' for space: "<Subject>-<Name>.<ext>" is one file). ────────────── */
function seg(s) { return encodeURIComponent(s); }
const masterUrl = computed(() =>
    hasMedia.value ? `${props.baseUrl}/Subjects/${seg(activeVideo.value.subject)}/${seg(activeVideo.value.master)}` : null);
function audioUrl(code) {
    const m = meta(audioTracks.value, code);
    if (!hasMedia.value || !m) return null;
    return `${props.baseUrl}/Subjects/${seg(activeVideo.value.subject)}/audio/${seg(`${activeVideo.value.subject}-${m.name}`)}.m4a`;
}
function capUrl(code) {
    const m = meta(capTracks.value, code);
    if (!hasMedia.value || !m) return null;
    return `${props.baseUrl}/Subjects/${seg(activeVideo.value.subject)}/captions/${seg(`${activeVideo.value.subject}-${m.name}`)}.vtt`;
}

/* ── Captions: fetch + parse the chosen .vtt, show the active cue in the bar
   (custom render so it works in both modes and honors RTL). ───────────────── */
const cues = ref([]);
const activeCue = computed(() => {
    if (!captionsOn.value) return '';
    const c = cues.value.find((q) => currentTime.value >= q.start && currentTime.value < q.end);
    if (c) return c.text;
    return hasMedia.value ? '' : sampleLine(selCap.value);
});

function sampleLine(code) {
    const m = meta(capTracks.value, code);
    return tr('c_media.caption_sample', 'Subtitles shown in {native}.', { native: m?.native ?? code });
}

let captionRequest = 0;
let captionController;
async function loadCaptions() {
    const request = ++captionRequest;
    captionController?.abort();
    cues.value = [];
    captionState.value = 'loading';
    const url = capUrl(selCap.value);
    if (!url) { captionState.value = 'unavailable'; return; }
    captionController = new AbortController();
    try {
        const res = await fetch(url, { signal: captionController.signal });
        if (!res.ok) throw new Error(String(res.status));
        const text = await res.text();
        if (disposed || request !== captionRequest) return;
        cues.value = parseVtt(text);
        captionState.value = 'ready';
    } catch {
        if (disposed || request !== captionRequest) return;
        cues.value = [];
        captionState.value = 'error';
    }
}

function parseVtt(text) {
    const out = [];
    for (const block of text.replace(/\r/g, '').split('\n\n')) {
        const line = block.split('\n').find((l) => l.includes('-->'));
        if (!line) continue;
        const [a, b] = line.split('-->').map((s) => s.trim().split(' ')[0]);
        const body = block.split('\n').slice(block.split('\n').indexOf(line) + 1).join(' ').trim();
        if (body) out.push({ start: toSecs(a), end: toSecs(b), text: body });
    }
    return out;
}
function toSecs(ts) {
    const p = ts.split(':').map(Number);
    return p.length === 3 ? p[0] * 3600 + p[1] * 60 + p[2] : p[0] * 60 + (p[1] || 0);
}

/* ── Transport ───────────────────────────────────────────────────────────── */
function playAudio() {
    const a = audioEl.value;
    const v = videoEl.value;
    if (!a || !v || v.paused || v.ended || !audioUrl(selAudio.value) || audioState.value === 'error') return;
    const attempt = ++audioAttempt;
    Promise.resolve(a.play()).catch(() => {
        if (!disposed && attempt === audioAttempt && !v.paused && !v.ended) onAudioError();
    });
}

function startPlayback() {
    const v = videoEl.value;
    if (!v) return;
    const attempt = ++videoAttempt;
    Promise.resolve(v.play()).catch(() => {
        if (!disposed && attempt === videoAttempt) onVideoError();
    });
    playAudio();
}

function togglePlay() {
    if (!hasMedia.value || videoState.value === 'error') return;
    const v = videoEl.value;
    if (!v) return;
    if (v.paused) startPlayback();
    else { v.pause(); onPause(); }
}

function onTimeUpdate() {
    const v = videoEl.value;
    if (!v) return;
    currentTime.value = v.currentTime;
    const a = audioEl.value;
    if (a && a.readyState > 0 && audioState.value !== 'error' && Math.abs(a.currentTime - v.currentTime) > 0.3) {
        a.currentTime = v.currentTime; // the hidden audio corrected back to the master
    }
}
function onLoaded() {
    const v = videoEl.value;
    if (!v) return;
    duration.value = Number.isFinite(v.duration) ? v.duration : duration.value;
    if (resumeAt !== null) { v.currentTime = Math.min(resumeAt, duration.value || resumeAt); resumeAt = null; }
}
function onVideoReady() {
    if (videoState.value !== 'error') videoState.value = playing.value ? 'playing' : 'ready';
    // W-0430: an auto-advance queued the next film to start once it decodes.
    if (autoAdvancePlay) { autoAdvancePlay = false; startPlayback(); }
}
function onPlay() { playing.value = true; }
function onPlaying() {
    playing.value = true;
    videoState.value = 'playing';
    syncAudio();
    applyAudioVolume();
    playAudio();
}
function onPause() {
    playing.value = false;
    ++videoAttempt;
    ++audioAttempt;
    audioEl.value?.pause();
    if (!['error', 'ended'].includes(videoState.value)) videoState.value = 'ready';
}
function onWaiting() { videoState.value = 'buffering'; ++audioAttempt; audioEl.value?.pause(); }
function onEnded() {
    onPause();
    videoState.value = 'ended';
    // W-0430: advance to the next film with the same language choices.
    if (hasNext.value) {
        autoAdvancePlay = true;
        goNext();
    }
}
function onVideoError() { onPause(); videoState.value = 'error'; }
function onAudioError() { ++audioAttempt; audioState.value = 'error'; audioEl.value?.pause(); }
function syncAudio() {
    if (audioEl.value?.readyState > 0 && videoEl.value) audioEl.value.currentTime = videoEl.value.currentTime;
}
function retryVideo() {
    if (!videoEl.value) return;
    resumeAt = currentTime.value;
    videoState.value = 'loading';
    videoEl.value.load();
    startPlayback();
}
function retryAudio() {
    if (!audioEl.value) return;
    audioState.value = 'loading';
    audioEl.value.load();
    playAudio();
}

function onSeek(ev) {
    const pct = Number(ev.target.value) / 100;
    const at = (duration.value || 0) * pct;
    currentTime.value = at;
    if (videoEl.value) videoEl.value.currentTime = at;
    syncAudio();
}

const seekPct = computed(() => (duration.value ? Math.round((currentTime.value / duration.value) * 100) : 0));

function fmt(s) {
    s = Math.max(0, Math.round(s || 0));
    const m = Math.floor(s / 60);
    const r = s % 60;
    return `${m}:${r < 10 ? '0' : ''}${r}`;
}

/* ── Volume + mute (W-0431) — the dub <audio> only. The master <video> stays
   muted by design; its own audio is never unmuted. ───────────────────────── */
function applyAudioVolume() {
    const a = audioEl.value;
    if (!a) return;
    a.volume = clampVol(volume.value);
    a.muted = muted.value;
}
function toggleMute() { muted.value = !muted.value; }
function onVolumeInput(ev) {
    volume.value = clampVol(Number(ev.target.value));
    if (muted.value && volume.value > 0) muted.value = false;
}

/* ── Linking + persistence ───────────────────────────────────────────────────
   Audio swapping is driven ENTIRELY by the reactive <audio :src>: changing
   selAudio (directly, or via the link from a caption change) re-points the
   element, the browser's media-load algorithm resets it to 0/paused, and
   onAudioLoaded re-syncs it to the master and resumes. Doing it imperatively
   here would fight that reactive re-load (Vue re-patches :src on the same flush,
   aborting an imperative play()), and a linked caption change would never
   resume the dub at all — both were real defects. One path now: reactive src +
   the load handler. */
function onAudioChange() {
    if (linked.value && has(capTracks.value, selAudio.value)) selCap.value = selAudio.value;
}
function onCapChange() {
    if (linked.value && has(audioTracks.value, selCap.value)) selAudio.value = selCap.value;
}
function onLinkChange() {
    if (linked.value && has(capTracks.value, selAudio.value)) selCap.value = selAudio.value;
}

/* The chosen dub finished (re)loading: put it back on the master's clock,
   reapply the volume/mute state, and resume if the film is playing, so an
   audio- or linked-caption switch never drops to silence or a wrong position. */
function onAudioLoaded() {
    audioState.value = 'ready';
    applyAudioVolume();
    syncAudio();
    if (playing.value && videoState.value !== 'buffering') playAudio();
}

/* Carry the language choice across a film change; fall back to the viewer's
   locale only when the new film lacks that language. */
function reconcileTracks() {
    if (!has(audioTracks.value, selAudio.value)) selAudio.value = codeForLocale(audioTracks.value, props.initialLocale);
    if (!has(capTracks.value, selCap.value)) selCap.value = codeForLocale(capTracks.value, props.initialLocale);
}

/* W-0430: the active film changed (nav, auto-advance, or an external selection
   through the `video` prop). Reset the transport and reload the tracks. */
watch(activeId, () => {
    currentTime.value = 0;
    duration.value = activeVideo.value.seconds ?? 0;
    ++videoAttempt;
    ++audioAttempt;
    videoState.value = 'loading';
    audioState.value = 'loading';
    reconcileTracks();
    loadCaptions();
});

/* The parent can drive the selection by changing the `video` prop (a library
   list click). Mirror it into activeId without re-emitting. */
watch(() => props.video?.id, (id) => {
    if (id && id !== activeId.value) activeId.value = id;
});

watch([selAudio, selCap, linked, captionsOn, volume, muted], () => {
    pushPrefs({
        audio: selAudio.value,
        cap: selCap.value,
        linked: linked.value,
        captionsOn: captionsOn.value,
        volume: clampVol(volume.value),
        muted: muted.value,
    });
});
watch(selCap, loadCaptions);
watch(selAudio, () => { ++audioAttempt; audioState.value = 'loading'; });
watch([volume, muted], applyAudioVolume);

function readPrefs() {
    try { return JSON.parse(localStorage.getItem(PREF_KEY) || '{}') || {}; }
    catch { return {}; }
}
function writePrefs(p) {
    try { localStorage.setItem(PREF_KEY, JSON.stringify(p)); } catch { /* private mode */ }
}

/* W-0432: localStorage is the always-on cache/fallback; the debounced PUT
   reaches the server only for a signed-in viewer (prefsEndpoint set) and only
   after mount, so seeding never writes back. */
let putTimer = null;
function xsrfToken() {
    if (typeof document === 'undefined' || !document.cookie) return null;
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : null;
}
function pushPrefs(p) {
    writePrefs(p);
    if (!mounted || !props.prefsEndpoint) return;
    if (putTimer) clearTimeout(putTimer);
    putTimer = setTimeout(() => {
        putTimer = null;
        const headers = { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
        const token = xsrfToken();
        if (token) headers['X-XSRF-TOKEN'] = token;
        Promise.resolve(fetch(props.prefsEndpoint, {
            method: 'PUT',
            headers,
            credentials: 'same-origin',
            body: JSON.stringify(p),
        })).catch(() => { /* offline: localStorage still holds it */ });
    }, PUT_DEBOUNCE_MS);
}

const statusLine = computed(() => {
    const a = meta(audioTracks.value, selAudio.value);
    const c = meta(capTracks.value, selCap.value);
    return {
        audio: a?.native ?? selAudio.value,
        cap: captionsOn.value ? (c?.native ?? selCap.value) : null,
        linked: linked.value,
    };
});

const playbackStatus = computed(() => {
    if (!hasMedia.value) return tr('c_media.status.unavailable', 'Video not available yet');
    if (videoState.value === 'error') return tr('c_media.status.video_error', 'Video unavailable');
    if (videoState.value === 'loading') return tr('c_media.status.loading', 'Loading video');
    if (videoState.value === 'buffering') return tr('c_media.status.buffering', 'Buffering video');
    if (videoState.value === 'ended') return tr('c_media.status.ended', 'Video finished');
    if (!playing.value) return tr('c_media.status.ready', 'Ready to play');
    if (audioState.value === 'error' || !audioTracks.value.length) return tr('c_media.status.playing_no_audio', 'Playing without audio');
    if (audioState.value === 'loading') return tr('c_media.status.audio_loading', 'Video playing; audio loading');
    if (audioState.value === 'buffering') return tr('c_media.status.audio_buffering', 'Video playing; audio buffering');
    return tr('c_media.status.playing', 'Playing');
});

const posterLabel = computed(() => tr('c_media.poster_label', 'Video guide: {title}. Video not available yet.', { title: activeVideo.value.title }));
const narratedLine = computed(() => tr(
    'c_media.narrated',
    'Narrated in {count} languages · captions in {captions} · your language choice is remembered',
    { count: audioTracks.value.length, captions: capTracks.value.length },
));

onMounted(() => {
    mounted = true;
    applyAudioVolume();
    loadCaptions();
});
onBeforeUnmount(() => {
    disposed = true;
    ++captionRequest;
    ++videoAttempt;
    ++audioAttempt;
    if (putTimer) clearTimeout(putTimer);
    captionController?.abort();
    videoEl.value?.pause();
    audioEl.value?.pause();
});
</script>

<template>
    <figure class="vplayer" :data-vplayer="activeVideo.id">
        <!-- W-0430: previous / film select / next — only with more than one film. -->
        <div v-if="hasPlaylist" class="vplayer-playlist">
            <button
                type="button"
                class="iconbtn"
                :disabled="!hasPrev"
                :aria-label="tr('c_media.previous_film', 'Previous film')"
                @click="goPrev"
            >
                <SkipBack :size="18" />
            </button>
            <select
                class="select"
                v-model="selectedFilmId"
                :aria-label="tr('c_media.select_film', 'Select film')"
            >
                <option v-for="v in list" :key="v.id" :value="v.id">{{ v.title }}</option>
            </select>
            <button
                type="button"
                class="iconbtn"
                :disabled="!hasNext"
                :aria-label="tr('c_media.next_film', 'Next film')"
                @click="goNext"
            >
                <SkipForward :size="18" />
            </button>
        </div>

        <!-- Stage: real <video> when media is configured, else the poster. -->
        <div v-if="hasMedia" class="vplayer-stage vplayer-stage--live">
            <video
                ref="videoEl"
                :aria-label="activeVideo.title"
                :poster="undefined"
                muted
                playsinline
                preload="metadata"
                :src="masterUrl"
                @timeupdate="onTimeUpdate"
                @loadedmetadata="onLoaded"
                @loadeddata="onVideoReady"
                @play="onPlay"
                @playing="onPlaying"
                @pause="onPause"
                @waiting="onWaiting"
                @ended="onEnded"
                @error="onVideoError"
                style="inline-size: 100%; block-size: 100%; object-fit: contain; background: #000"
            ></video>
            <audio ref="audioEl" :src="audioUrl(selAudio)" preload="metadata" aria-hidden="true"
                @loadeddata="onAudioLoaded" @playing="audioState = 'playing'"
                @waiting="audioState = 'buffering'" @error="onAudioError"></audio>
            <div v-if="captionsOn && activeCue" class="vplayer-cc" :dir="capDir">{{ activeCue }}</div>
        </div>

        <div
            v-else
            class="vplayer-stage"
            :class="`vposter--${activeVideo.poster}`"
            role="img"
            :aria-label="posterLabel"
        >
            <span class="vplayer-play" aria-hidden="true"><Play :size="28" /></span>
            <span class="vplayer-dur">{{ fmt(duration) }}</span>
            <div v-if="captionsOn" class="vplayer-cc" :dir="capDir">{{ activeCue }}</div>
        </div>

        <!-- Transport -->
        <div class="vplayer-transport">
            <button type="button" class="iconbtn" :disabled="!hasMedia || videoState === 'error'" :aria-label="tr('c_media.play_pause', 'Play or pause')" @click="togglePlay">
                <component :is="playing ? Pause : Play" :size="18" />
            </button>
            <span class="vplayer-time">{{ fmt(currentTime) }} / {{ fmt(duration) }}</span>
            <input
                type="range"
                class="vplayer-seek"
                min="0"
                max="100"
                :value="seekPct"
                :disabled="!hasMedia || videoState === 'error'"
                :aria-label="tr('c_media.seek', 'Seek')"
                @input="onSeek"
            />
            <button
                type="button"
                class="iconbtn"
                :class="{ 'iconbtn--on': captionsOn }"
                :aria-pressed="String(captionsOn)"
                :aria-label="captionsOn ? tr('c_media.captions_on', 'Captions on') : tr('c_media.captions_off', 'Captions off')"
                @click="captionsOn = !captionsOn"
            >
                <Captions :size="18" />
            </button>
            <!-- W-0431: mute + volume act on the dub audio only. -->
            <button
                type="button"
                class="iconbtn"
                :disabled="!audioTracks.length"
                :aria-pressed="String(muted)"
                :aria-label="muted ? tr('c_media.unmute', 'Unmute audio') : tr('c_media.mute', 'Mute audio')"
                @click="toggleMute"
            >
                <component :is="muted ? VolumeX : Volume2" :size="18" />
            </button>
            <input
                type="range"
                class="vplayer-volume"
                min="0"
                max="1"
                step="0.01"
                :value="volume"
                :disabled="!audioTracks.length"
                :aria-label="tr('c_media.volume', 'Audio volume')"
                @input="onVolumeInput"
            />
        </div>

        <div v-if="videoState === 'error'" class="vplayer-status" role="alert">
            {{ tr('c_media.error.video', 'The video could not play.') }} <button type="button" class="form-chip" @click="retryVideo">{{ tr('c_media.error.retry_video', 'Retry video') }}</button>
        </div>
        <div v-if="audioState === 'error'" class="vplayer-status" role="alert">
            {{ tr('c_media.error.audio', 'The selected audio could not play. You can keep watching, choose another language or') }}
            <button type="button" class="form-chip" @click="retryAudio">{{ tr('c_media.error.retry_audio', 'Retry audio') }}</button>.
        </div>
        <div v-if="captionsOn && captionState === 'error'" class="vplayer-status" role="alert">
            {{ tr('c_media.error.captions', 'The selected captions could not load. You can choose another language or') }}
            <button type="button" class="form-chip" @click="loadCaptions">{{ tr('c_media.error.retry_captions', 'Retry captions') }}</button>.
        </div>

        <!-- Track pickers -->
        <div class="vplayer-tracks">
            <label class="vtrack">
                <span class="vtrack-lbl"><Volume2 :size="14" /> {{ tr('c_media.audio_label', 'Audio') }}</span>
                <select class="select" v-model="selAudio" @change="onAudioChange">
                    <option v-for="t in audioTracks" :key="t.code" :value="t.code">{{ t.name }} · {{ t.native }}</option>
                </select>
            </label>
            <label class="vtrack">
                <span class="vtrack-lbl"><Captions :size="14" /> {{ tr('c_media.captions_label', 'Captions') }}</span>
                <select class="select" v-model="selCap" @change="onCapChange">
                    <option v-for="t in capTracks" :key="t.code" :value="t.code">{{ t.name }} · {{ t.native }}</option>
                </select>
            </label>
            <label class="vtrack vtrack--link">
                <input type="checkbox" v-model="linked" @change="onLinkChange" />
                <span>{{ tr('c_media.link_label', 'Link audio and subtitles') }}</span>
            </label>
        </div>

        <div class="vplayer-status" role="status" aria-live="polite">
            {{ playbackStatus }} · {{ tr('c_media.line.audio', 'audio') }} <strong>{{ statusLine.audio }}</strong>
            · {{ tr('c_media.line.captions', 'captions') }} <template v-if="statusLine.cap"><strong>{{ statusLine.cap }}</strong></template><span v-else class="gloss">{{ tr('c_media.line.off', 'off') }}</span>
            <span v-if="hasMedia && captionsOn && captionState === 'loading'"> · {{ tr('c_media.line.loading_captions', 'loading captions') }}</span>
            · <span class="gloss">{{ statusLine.linked ? tr('c_media.line.linked', 'linked') : tr('c_media.line.unlinked', 'unlinked') }}</span>
        </div>

        <figcaption class="vplayer-cap">
            <strong style="color: var(--gov-fg)">{{ activeVideo.title }}</strong>
            <template v-if="activeVideo.summary"> · {{ activeVideo.summary }}</template>
            <span
                class="vplayer-meta citation"
                :title="tr('c_media.meta_title', 'One silent master plus per-language audio and caption tracks, drift-corrected past 0.3 seconds.')"
            >
                {{ narratedLine }}
            </span>
            <span v-if="!hasMedia" class="citation">{{ tr('c_media.port_note', 'A faithful port of the Coalition\'s multi-track player. No media ships in this build. The stage is a labelled placeholder. It plays for real once a media host is configured.') }}</span>
        </figcaption>
    </figure>
</template>
