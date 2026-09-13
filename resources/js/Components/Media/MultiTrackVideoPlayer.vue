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
 * Track codes are BCP-47; media filenames embed the language ENGLISH NAME and
 * the subject verbatim ("<Subject>-<Name>.<ext>"), percent-encoded PER SEGMENT.
 * Styling is the shipped .vplayer* / .vposter--* / .vtrack* set in
 * components-v2.css — this component adds none.
 */
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
/* The transport glyphs the app's 38-name Icon vocabulary does not carry
   (pause/captions/volume). lucide-vue-next is already the engine behind
   Ui/Icon; importing three glyphs directly keeps this player self-contained
   without widening the shared Icon component. */
import { Play, Pause, Captions, Volume2 } from 'lucide-vue-next';

const props = defineProps({
    /** An enriched MediaMeta record: {id, subject, master, title, summary,
     *  poster, seconds, audio[], captions[]} where audio/captions are
     *  {code, name, native, dir, locale}. */
    video: { type: Object, required: true },
    /** Media host base URL, or null for poster mode. */
    baseUrl: { type: String, default: null },
    /** The viewer's app locale — the first audio/captions choice links to it. */
    initialLocale: { type: String, default: 'en' },
});

const PREF_KEY = 'cga:video:prefs';

const videoEl = ref(null);
const audioEl = ref(null);

const hasMedia = computed(() => !!props.baseUrl);

/* Track lists (arrays of {code, name, native, dir, locale}). */
const audioTracks = computed(() => props.video.audio ?? []);
const capTracks = computed(() => props.video.captions ?? []);

function codeForLocale(tracks, locale) {
    const byLocale = tracks.find((t) => t.locale && t.locale === locale);
    if (byLocale) return byLocale.code;
    const en = tracks.find((t) => t.code === 'en');
    return en ? en.code : (tracks[0]?.code ?? 'en');
}

/* ── State (seeded from stored prefs, else the viewer's locale) ───────────── */
const stored = readPrefs();
const selAudio = ref(has(audioTracks.value, stored.audio) ? stored.audio : codeForLocale(audioTracks.value, props.initialLocale));
const selCap = ref(has(capTracks.value, stored.cap) ? stored.cap : codeForLocale(capTracks.value, props.initialLocale));
const linked = ref(stored.linked !== false);
const captionsOn = ref(stored.captionsOn !== false);

const playing = ref(false);
const currentTime = ref(0);
const duration = ref(props.video.seconds ?? 0);
const videoState = ref('loading');
const audioState = ref('loading');
const captionState = ref('loading');
let disposed = false;
let videoAttempt = 0;
let audioAttempt = 0;
let resumeAt = null;

/* ── Track metadata lookups ──────────────────────────────────────────────── */
function has(tracks, code) { return !!code && tracks.some((t) => t.code === code); }
function meta(tracks, code) { return tracks.find((t) => t.code === code) ?? null; }
const capMeta = computed(() => meta(capTracks.value, selCap.value));
const capDir = computed(() => capMeta.value?.dir ?? 'ltr');

/* ── Media URLs — per-segment encoding (never encodeURI on the whole path,
   never '+' for space: "<Subject>-<Name>.<ext>" is one file). ────────────── */
function seg(s) { return encodeURIComponent(s); }
const masterUrl = computed(() =>
    hasMedia.value ? `${props.baseUrl}/Subjects/${seg(props.video.subject)}/${seg(props.video.master)}` : null);
function audioUrl(code) {
    const m = meta(audioTracks.value, code);
    if (!hasMedia.value || !m) return null;
    return `${props.baseUrl}/Subjects/${seg(props.video.subject)}/audio/${seg(`${props.video.subject}-${m.name}`)}.m4a`;
}
function capUrl(code) {
    const m = meta(capTracks.value, code);
    if (!hasMedia.value || !m) return null;
    return `${props.baseUrl}/Subjects/${seg(props.video.subject)}/captions/${seg(`${props.video.subject}-${m.name}`)}.vtt`;
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
    return `Subtitles shown in ${m?.native ?? code}.`;
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
function onVideoReady() { if (videoState.value !== 'error') videoState.value = playing.value ? 'playing' : 'ready'; }
function onPlay() { playing.value = true; }
function onPlaying() {
    playing.value = true;
    videoState.value = 'playing';
    syncAudio();
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
function onEnded() { onPause(); videoState.value = 'ended'; }
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

/* The chosen dub finished (re)loading: put it back on the master's clock and
   resume if the film is playing, so an audio- or linked-caption switch never
   drops to silence or a wrong position. */
function onAudioLoaded() {
    audioState.value = 'ready';
    syncAudio();
    if (playing.value && videoState.value !== 'buffering') playAudio();
}

watch([selAudio, selCap, linked, captionsOn], () => {
    writePrefs({ audio: selAudio.value, cap: selCap.value, linked: linked.value, captionsOn: captionsOn.value });
});
watch(selCap, loadCaptions);
watch(selAudio, () => { ++audioAttempt; audioState.value = 'loading'; });

function readPrefs() {
    try { return JSON.parse(localStorage.getItem(PREF_KEY) || '{}') || {}; }
    catch { return {}; }
}
function writePrefs(p) {
    try { localStorage.setItem(PREF_KEY, JSON.stringify(p)); } catch { /* private mode */ }
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
    if (!hasMedia.value) return 'Video not available yet';
    if (videoState.value === 'error') return 'Video unavailable';
    if (videoState.value === 'loading') return 'Loading video';
    if (videoState.value === 'buffering') return 'Buffering video';
    if (videoState.value === 'ended') return 'Video finished';
    if (!playing.value) return 'Ready to play';
    if (audioState.value === 'error' || !audioTracks.value.length) return 'Playing without audio';
    if (audioState.value === 'loading') return 'Video playing; audio loading';
    if (audioState.value === 'buffering') return 'Video playing; audio buffering';
    return 'Playing';
});

onMounted(loadCaptions);
onBeforeUnmount(() => {
    disposed = true;
    ++captionRequest;
    ++videoAttempt;
    ++audioAttempt;
    captionController?.abort();
    videoEl.value?.pause();
    audioEl.value?.pause();
});
</script>

<template>
    <figure class="vplayer" :data-vplayer="video.id">
        <!-- Stage: real <video> when media is configured, else the poster. -->
        <div v-if="hasMedia" class="vplayer-stage vplayer-stage--live">
            <video
                ref="videoEl"
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
            <audio ref="audioEl" :src="audioUrl(selAudio)" preload="metadata"
                @loadeddata="onAudioLoaded" @playing="audioState = 'playing'"
                @waiting="audioState = 'buffering'" @error="onAudioError"></audio>
            <div v-if="captionsOn && activeCue" class="vplayer-cc" :dir="capDir">{{ activeCue }}</div>
        </div>

        <div
            v-else
            class="vplayer-stage"
            :class="`vposter--${video.poster}`"
            role="img"
            :aria-label="`Video guide: ${video.title}. Video not available yet.`"
        >
            <span class="vplayer-play" aria-hidden="true"><Play :size="28" /></span>
            <span class="vplayer-dur">{{ fmt(duration) }}</span>
            <div v-if="captionsOn" class="vplayer-cc" :dir="capDir">{{ activeCue }}</div>
        </div>

        <!-- Transport -->
        <div class="vplayer-transport">
            <button type="button" class="iconbtn" :disabled="!hasMedia || videoState === 'error'" aria-label="Play or pause" @click="togglePlay">
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
                aria-label="Seek"
                @input="onSeek"
            />
            <button
                type="button"
                class="iconbtn"
                :class="{ 'iconbtn--on': captionsOn }"
                :aria-pressed="String(captionsOn)"
                :aria-label="captionsOn ? 'Captions on' : 'Captions off'"
                @click="captionsOn = !captionsOn"
            >
                <Captions :size="18" />
            </button>
        </div>

        <div v-if="videoState === 'error'" class="vplayer-status" role="alert">
            The video could not play. <button type="button" class="form-chip" @click="retryVideo">Retry video</button>
        </div>
        <div v-if="audioState === 'error'" class="vplayer-status" role="alert">
            The selected audio could not play. You can keep watching, choose another language or
            <button type="button" class="form-chip" @click="retryAudio">Retry audio</button>.
        </div>
        <div v-if="captionsOn && captionState === 'error'" class="vplayer-status" role="alert">
            The selected captions could not load. You can choose another language or
            <button type="button" class="form-chip" @click="loadCaptions">Retry captions</button>.
        </div>

        <!-- Track pickers -->
        <div class="vplayer-tracks">
            <label class="vtrack">
                <span class="vtrack-lbl"><Volume2 :size="14" /> Audio</span>
                <select class="select" v-model="selAudio" @change="onAudioChange">
                    <option v-for="t in audioTracks" :key="t.code" :value="t.code">{{ t.name }} · {{ t.native }}</option>
                </select>
            </label>
            <label class="vtrack">
                <span class="vtrack-lbl"><Captions :size="14" /> Captions</span>
                <select class="select" v-model="selCap" @change="onCapChange">
                    <option v-for="t in capTracks" :key="t.code" :value="t.code">{{ t.name }} · {{ t.native }}</option>
                </select>
            </label>
            <label class="vtrack vtrack--link">
                <input type="checkbox" v-model="linked" @change="onLinkChange" />
                <span>Link audio &amp; subtitles</span>
            </label>
        </div>

        <div class="vplayer-status" role="status" aria-live="polite">
            {{ playbackStatus }} · audio <strong>{{ statusLine.audio }}</strong>
            · captions <template v-if="statusLine.cap"><strong>{{ statusLine.cap }}</strong></template><span v-else class="gloss">off</span>
            <span v-if="hasMedia && captionsOn && captionState === 'loading'"> · loading captions</span>
            · <span class="gloss">{{ statusLine.linked ? 'linked' : 'unlinked' }}</span>
        </div>

        <figcaption class="vplayer-cap">
            <strong style="color: var(--gov-fg)">{{ video.title }}</strong>
            <template v-if="video.summary"> · {{ video.summary }}</template>
            <span
                class="vplayer-meta citation"
                :title="`One silent master + per-language audio and caption tracks (${video.subject}-{Language}.{ext}), drift-corrected past 0.3s`"
            >
                Narrated in {{ audioTracks.length }} languages · captions in {{ capTracks.length }} · your language choice is remembered
            </span>
            <span v-if="!hasMedia" class="citation">A faithful port of the Coalition's multi-track player. No media in this build — the stage is a labelled placeholder; it plays for real once a media host is configured.</span>
        </figcaption>
    </figure>
</template>
