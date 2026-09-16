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
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Card from '@/Components/Ui/Card.vue';
import Icon from '@/Components/Ui/Icon.vue';
import MultiTrackVideoPlayer from '@/Components/Media/MultiTrackVideoPlayer.vue';

defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    videos: { type: Array, default: () => [] },
    baseUrl: { type: String, default: null },
    // LE-3: a catalog id to open on (from ?v=), validated server-side. Null
    // opens the first film.
    preselect: { type: String, default: null },
    // W-0432: the signed-in viewer's saved player prefs + the PUT endpoint.
    // Null for a guest (the player uses localStorage only).
    videoPrefs: { type: Object, default: null },
    prefsEndpoint: { type: String, default: null },
});

const { t } = useI18n();

const page = usePage();
const locale = computed(() => page.props.locale || 'en');

const initialId = props.preselect && props.videos.some((v) => v.id === props.preselect)
    ? props.preselect
    : (props.videos[0]?.id ?? null);
const currentId = ref(initialId);
const current = computed(() => props.videos.find((v) => v.id === currentId.value) ?? null);

function fmt(s) {
    if (!s) return '';
    s = Math.round(s);
    const m = Math.floor(s / 60);
    const r = s % 60;
    return `${m}:${r < 10 ? '0' : ''}${r}`;
}

// W-0430: keep the ?v= query on the current film without a full reload, so a
// deep link and the browser back/forward stay in step with the playlist.
function syncUrl(id) {
    if (typeof window === 'undefined' || !id) return;
    const url = new URL(window.location.href);
    url.searchParams.set('v', id);
    window.history.replaceState(window.history.state, '', url);
}

// The player advanced itself (next button or auto-advance on ended).
function onPlayerChange(id) {
    currentId.value = id;
    syncUrl(id);
}

// A library row selected a film — drive the player through the video prop.
function pick(id) {
    currentId.value = id;
    syncUrl(id);
    document.getElementById('featured')?.scrollIntoView({ block: 'nearest' });
}
</script>

<template>
    <Head :title="t('c_front.video_library.head_title', 'Video library')" />
    <PageScaffold :surface="surface">
        <template #intro>
            {{ t('c_front.video_library.intro', 'Choose a film and the audio and subtitle languages you prefer.') }}
        </template>

        <template #about>
            <p>{{ t('c_front.video_library.about_1', 'Audio and subtitles can use the same language or different languages. Your choices are remembered in this browser.') }}</p>
            <p>{{ t('c_front.video_library.about_2_before', 'To review language coverage, open the') }} <Link href="/system/translations">{{ t('c_front.video_library.translation_workspace', 'translation workspace') }}</Link>{{ t('c_front.video_library.about_2_after', '.') }}</p>
        </template>

        <!-- The sample-library banner (operator, 2026-09-16): these films come
             from the Coalition website and stand in until the app's own films
             are recorded; the banner says so on every visit. -->
        <Banner tone="info" :title="t('c_front.video_library.sample_banner_title', 'Sample videos')">
            {{ t('c_front.video_library.sample_banner', 'These films are samples from the Cosmopolitan Coalition website. They show how the player works: one film, narrated and captioned in many languages. The app\'s own films will replace them.') }}
        </Banner>

        <!-- Featured player -->
        <div id="featured">
            <MultiTrackVideoPlayer
                v-if="current"
                :video="current"
                :playlist="videos"
                :base-url="current.available ? baseUrl : null"
                :initial-locale="locale"
                :server-prefs="videoPrefs"
                :prefs-endpoint="prefsEndpoint"
                @change="onPlayerChange"
            />
            <Card v-else><p class="gloss">{{ t('c_front.video_library.empty', 'The video catalog is empty.') }}</p></Card>
        </div>

        <!-- Library list -->
        <section aria-labelledby="lib-h">
            <h2 id="lib-h">{{ t('c_front.video_library.films_h', 'Films') }}</h2>
            <p class="page-intro">{{ t('c_front.video_library.count_line', { n: videos.length }) }}</p>
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
                        {{ t(v.title_key, v.title) }}
                        <Icon v-if="v.id === currentId" name="play" size="sm" />
                        <!-- W-0449: an honest "not available" tag when a media
                             host is configured but this film's master is not on
                             the server yet. Hidden in poster mode (no baseUrl). -->
                        <span
                            v-if="baseUrl && v.available === false"
                            class="tk-unavailable"
                            :title="t('c_front.video_library.unavailable_hint', 'This film is not on this server yet.')"
                        >{{ t('c_front.video_library.unavailable', 'Not available yet') }}</span>
                    </span>
                    <span class="tk-meta">{{ t('c_front.video_library.track_meta', { audio: v.audio.length, captions: v.captions.length }) }}</span>
                </button>
            </div>
        </section>

    </PageScaffold>
</template>

<style scoped>
/* W-0449: the honest "not available yet" tag in the film list. */
.tk-unavailable {
    margin-inline-start: var(--space-2, .5rem);
    font-size: var(--text-xs, .75rem);
    color: var(--gov-fg-subtle, #94a3b8);
    border: 1px solid var(--gov-border, #344054);
    border-radius: var(--radius-sm, .25rem);
    padding-inline: .35rem;
}
</style>
