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
 * The registered surface connects playback guidance to the Learn flyout.
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
            Choose a film and the audio and subtitle languages you prefer.
        </template>

        <template #about>
            <p>Audio and subtitles can use the same language or different languages. Your choices are remembered in this browser.</p>
            <p>To review language coverage, open the <Link href="/system/translations">translation workspace</Link>.</p>
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

        <!-- Library list -->
        <section aria-labelledby="lib-h">
            <h2 id="lib-h">Films</h2>
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

    </PageScaffold>
</template>
