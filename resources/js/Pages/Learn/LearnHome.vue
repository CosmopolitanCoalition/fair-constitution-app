<script setup>
/**
 * Learn/LearnHome — every track, open to everyone (contract
 * mockups/v3/learn/learn-home.html; K-2, ruling A5).
 *
 * §5.0.2 rendered plainly: the page RECOMMENDS tracks by the roles you
 * hold; it never hides or refuses one. The recommendation banner is the
 * standing form of the acquisition notice — informational, gating nothing.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Card from '@/Components/Ui/Card.vue';
import Icon from '@/Components/Ui/Icon.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    tracks: { type: Array, default: () => [] },
    recommended: { type: Array, default: () => [] },
});

const { t } = useI18n({ useScope: 'global' });

const recommendedTracks = computed(() =>
    props.tracks.filter((tr) => props.recommended.includes(tr.key)));
const otherTracks = computed(() =>
    props.tracks.filter((tr) => !props.recommended.includes(tr.key)));
</script>

<template>
    <PageScaffold :surface="surface" :title="t('c_learn.ui.title')">
        <template #intro>{{ t('c_learn.ui.intro') }}</template>

        <nav class="learning-paths" :aria-label="t('c_learn.ui.learning_paths', 'Ways to learn')">
            <Link href="/explore" class="btn"><Icon name="users" size="sm" />{{ t('c_navigation.role-explorer', 'Explore civic roles') }}</Link>
            <Link href="/journeys" class="btn"><Icon name="list-checks" size="sm" />{{ t('c_learn.ui.guided_journeys', 'Guided journeys') }}</Link>
            <Link href="/videos" class="btn"><Icon name="play" size="sm" />{{ t('c_learn.ui.watch_videos', 'Watch videos') }}</Link>
        </nav>

        <section v-if="recommendedTracks.length" aria-labelledby="rec-h" class="stack">
            <h2 id="rec-h">{{ t('c_learn.ui.recommended') }}</h2>
            <Banner tone="info">{{ t('c_learn.ui.notice') }}</Banner>
            <Card v-for="track in recommendedTracks" :key="track.key">
                <h3>
                    <Link :href="`/learn/${track.key}`">{{ t(track.title) }}</Link>
                </h3>
                <ul class="lesson-list">
                    <li v-for="m in track.modules" :key="m.key" class="lesson-row">
                        <Link :href="`/learn/${track.key}/${m.key}`" class="lesson-title">{{ t(m.title) }}</Link>
                        <span v-if="m.minutes" class="lesson-meta">{{ m.minutes }} {{ t('c_learn.ui.minutes') }}</span>
                        <StatusBadge v-if="m.completed" tone="success">{{ t('c_learn.ui.completed') }}</StatusBadge>
                    </li>
                </ul>
            </Card>
        </section>

        <section aria-labelledby="tracks-h" class="stack">
            <h2 id="tracks-h">{{ t('c_learn.ui.all_tracks') }}</h2>
            <p v-if="!tracks.length" class="gloss">
                Nothing is published yet — training content arrives with the world's curriculum.
            </p>
            <Card v-for="track in otherTracks" :key="track.key">
                <h3>
                    <Link :href="`/learn/${track.key}`">{{ t(track.title) }}</Link>
                </h3>
                <ul class="lesson-list">
                    <li v-for="m in track.modules" :key="m.key" class="lesson-row">
                        <Link :href="`/learn/${track.key}/${m.key}`" class="lesson-title">{{ t(m.title) }}</Link>
                        <span v-if="m.minutes" class="lesson-meta">{{ m.minutes }} {{ t('c_learn.ui.minutes') }}</span>
                        <StatusBadge v-if="m.completed" tone="success">{{ t('c_learn.ui.completed') }}</StatusBadge>
                    </li>
                </ul>
            </Card>
        </section>

    </PageScaffold>
</template>

<style scoped>
.learning-paths { display: flex; flex-wrap: wrap; gap: .75rem; }
</style>
