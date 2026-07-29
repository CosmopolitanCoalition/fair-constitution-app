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
 *              controls stay live, and the caption bar shows a sample line. The
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
import { Play, Pause, Captions, Volume2, Check } from 'lucide-vue-next';

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
const mediaError = ref(false);

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

async function loadCaptions() {
    cues.value = [];
    const url = capUrl(selCap.value);
    if (!url) return;
    try {
        const res = await fetch(url);
        if (!res.ok) throw new Error(String(res.status));
        cues.value = parseVtt(await res.text());
    } catch {
        cues.value = []; // fall back to no live cues; the bar shows nothing
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
function togglePlay() {
    if (!hasMedia.value) { playing.value = !playing.value; return; }
    const v = videoEl.value;
    if (!v) return;
    if (v.paused) { v.play(); audioEl.value?.play(); }
    else { v.pause(); audioEl.value?.pause(); }
}

function onTimeUpdate() {
    const v = videoEl.value;
    if (!v) return;
    currentTime.value = v.currentTime;
    const a = audioEl.value;
    if (a && Math.abs(a.currentTime - v.currentTime) > 0.3) {
        a.currentTime = v.currentTime; // the hidden audio corrected back to the master
    }
}
function onLoaded() { if (videoEl.value) duration.value = videoEl.value.duration || duration.value; }
function onPlay() { playing.value = true; }
function onPause() { playing.value = false; }

function onSeek(ev) {
    const pct = Number(ev.target.value) / 100;
    const at = (duration.value || 0) * pct;
    currentTime.value = at;
    if (videoEl.value) videoEl.value.currentTime = at;
    if (audioEl.value) audioEl.value.currentTime = at;
}

const seekPct = computed(() => (duration.value ? Math.round((currentTime.value / duration.value) * 100) : 0));

function fmt(s) {
    s = Math.max(0, Math.round(s || 0));
    const m = Math.floor(s / 60);
    const r = s % 60;
    return `${m}:${r < 10 ? '0' : ''}${r}`;
}

/* ── Linking + persistence ───────────────────────────────────────────────── */
function onAudioChange() {
    if (linked.value && has(capTracks.value, selAudio.value)) selCap.value = selAudio.value;
    swapAudio();
}
function onCapChange() {
    if (linked.value && has(audioTracks.value, selCap.value)) selAudio.value = selCap.value;
}
function onLinkChange() {
    if (linked.value && has(capTracks.value, selAudio.value)) selCap.value = selAudio.value;
}

function swapAudio() {
    const a = audioEl.value;
    if (!a || !hasMedia.value) return;
    const at = videoEl.value?.currentTime ?? 0;
    a.src = audioUrl(selAudio.value) ?? '';
    a.currentTime = at;
    if (playing.value) a.play().catch(() => {});
}

watch([selAudio, selCap, linked, captionsOn], () => {
    writePrefs({ audio: selAudio.value, cap: selCap.value, linked: linked.value, captionsOn: captionsOn.value });
});
watch(selCap, loadCaptions);

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

onMounted(loadCaptions);
onBeforeUnmount(() => { videoEl.value?.pause(); audioEl.value?.pause(); });
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
                @play="onPlay"
                @pause="onPause"
                @error="mediaError = true"
                style="inline-size: 100%; block-size: 100%; object-fit: contain; background: #000"
            ></video>
            <audio ref="audioEl" :src="audioUrl(selAudio)" preload="metadata"></audio>
            <div v-if="captionsOn && activeCue" class="vplayer-cc" :dir="capDir">{{ activeCue }}</div>
        </div>

        <div
            v-else
            class="vplayer-stage"
            :class="`vposter--${video.poster}`"
            role="img"
            :aria-label="`Video guide: ${video.title} (no media in this build — labelled placeholder)`"
        >
            <button type="button" class="vplayer-play" aria-label="Play (placeholder — no media in this build)" @click="togglePlay">
                <component :is="playing ? Pause : Play" :size="28" />
            </button>
            <span class="vplayer-dur">{{ fmt(duration) }}</span>
            <div v-if="captionsOn" class="vplayer-cc" :dir="capDir">{{ activeCue }}</div>
        </div>

        <!-- Transport -->
        <div class="vplayer-transport">
            <button type="button" class="iconbtn" aria-label="Play or pause" @click="togglePlay">
                <component :is="playing ? Pause : Play" :size="18" />
            </button>
            <span class="vplayer-time">{{ fmt(currentTime) }} / {{ fmt(duration) }}</span>
            <input
                type="range"
                class="vplayer-seek"
                min="0"
                max="100"
                :value="seekPct"
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

        <div class="vplayer-status">
            <Check :size="14" />
            In sync · audio <strong>{{ statusLine.audio }}</strong>
            · captions <template v-if="statusLine.cap"><strong>{{ statusLine.cap }}</strong></template><span v-else class="gloss">off</span>
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
