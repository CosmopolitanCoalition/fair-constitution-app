<script setup>
/**
 * Learn/VideoLibrary — the app surface for the multi-track video library
 * (design contract: mockups/v3/shared/video-player.html). A short film for
 * every guide, tool, and workflow, narrated and captioned in many languages
 * from ONE silent master — the operator's Coalition player, app-ported.
 *
 * The catalog comes from MediaMeta (config/cga/media.php, generated from lane
 * 11's subjects.json + languages.json). No media ships in the repo: when no
 * media host is configured the player shows the labelled poster placeholder,
 * and lights up with real playback the moment CGA_MEDIA_BASE_URL is set.
 *
 * A new lane-5 surface (never an edit to a lane-6 page). Bare inline surface —
 * no config/cga/surfaces.php entry needed.
 */
import { ref, computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import Icon from '@/Components/Ui/Icon.vue';
import MultiTrackVideoPlayer from '@/Components/Media/MultiTrackVideoPlayer.vue';

defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    videos: { type: Array, default: () => [] },
    baseUrl: { type: String, default: null },
});

const page = usePage();
const locale = computed(() => page.props.locale || 'en');

const currentId = ref(props.videos[0]?.id ?? null);
const current = computed(() => props.videos.find((v) => v.id === currentId.value) ?? null);

/* The language coverage headline — how many the library speaks. */
const langCount = computed(() => current.value?.captions?.length ?? 0);

function fmt(s) {
    if (!s) return '';
    s = Math.round(s);
    const m = Math.floor(s / 60);
    const r = s % 60;
    return `${m}:${r < 10 ? '0' : ''}${r}`;
}

function pick(id) {
    currentId.value = id;
    document.getElementById('featured')?.scrollIntoView({ block: 'nearest' });
}
</script>

<template>
    <Head title="Video library" />
    <PageScaffold :surface="surface">
        <template #intro>
            A short film for every journey, tool, and workflow — narrated and captioned in many
            languages from one master recording. Your language choice follows you from video to video.
        </template>

        <!-- Featured player -->
        <div id="featured">
            <MultiTrackVideoPlayer
                v-if="current"
                :key="current.id"
                :video="current"
                :base-url="baseUrl"
                :initial-locale="locale"
            />
            <Card v-else><p class="gloss">The video catalog is empty.</p></Card>
        </div>

        <!-- How the player works -->
        <Card>
            <h2>How the player works</h2>
            <p class="gloss">
                Every guide has a short video — and every video speaks {{ langCount }} languages without
                rendering {{ langCount }} separate films. One <strong style="color: var(--gov-fg)">silent
                master</strong> plus a cheap audio and caption track per language.
            </p>
            <div class="grid-2">
                <Card inset>
                    <span class="eyebrow">The pieces</span>
                    <ul>
                        <li><strong style="color: var(--gov-fg)">One silent master</strong> — <code>{Subject}-Silent.mp4</code>, muted, shared by every language.</li>
                        <li><strong style="color: var(--gov-fg)">Audio tracks</strong> — <code>{Subject}-{Language}.m4a</code>, time-synced to the master.</li>
                        <li><strong style="color: var(--gov-fg)">Caption tracks</strong> — <code>{Subject}-{Language}.vtt</code>, the searchable, translatable transcript.</li>
                    </ul>
                </Card>
                <Card inset>
                    <span class="eyebrow">What it does for you</span>
                    <ul>
                        <li><strong style="color: var(--gov-fg)">Link audio &amp; subtitles</strong> — one control sets both to your language; unlink to mix.</li>
                        <li><strong style="color: var(--gov-fg)">Stays in sync</strong> — the hidden audio is corrected back to the master whenever it drifts past 0.3 seconds.</li>
                        <li><strong style="color: var(--gov-fg)">Remembers you</strong> — your language choice carries to every other video.</li>
                    </ul>
                </Card>
            </div>
        </Card>

        <!-- Library list -->
        <section aria-labelledby="lib-h">
            <h2 id="lib-h">Every guide</h2>
            <p class="page-intro">{{ videos.length }} videos so far. Pick one to load it above — your language choice follows you.</p>
            <div class="lesson-list">
                <button
                    v-for="v in videos"
                    :key="v.id"
                    type="button"
                    class="ticket-row"
                    :aria-current="v.id === currentId ? 'true' : undefined"
                    style="inline-size: 100%; text-align: start; cursor: pointer"
                    @click="pick(v.id)"
                >
                    <span class="tk-n">{{ v.seconds ? fmt(v.seconds) : '—' }}</span>
                    <span class="tk-title">
                        {{ v.title }}
                        <Icon v-if="v.id === currentId" name="play" size="sm" />
                    </span>
                    <span class="tk-meta">{{ v.audio.length }} audio · {{ v.captions.length }} captions</span>
                </button>
            </div>
        </section>

        <!-- Cross-link to the translation interface -->
        <Card inset>
            <h2>Many languages, tracked</h2>
            <p>
                Audio and captions are two of the kinds of content the
                <Link href="/system/translations">translation interface</Link> tracks per language —
                a language is only offered here once its tracks exist, so nothing ever plays to a 404.
            </p>
        </Card>
    </PageScaffold>
</template>
