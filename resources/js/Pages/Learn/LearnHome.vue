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
    <PageScaffold :surface="surface" :title="t('c_education.learn.ui.title')">
        <template #intro>{{ t('c_education.learn.ui.intro') }}</template>

        <section v-if="recommendedTracks.length" aria-labelledby="rec-h" class="stack">
            <h2 id="rec-h">{{ t('c_education.learn.ui.recommended') }}</h2>
            <Banner tone="info">{{ t('c_education.learn.ui.notice') }}</Banner>
            <Card v-for="track in recommendedTracks" :key="track.key">
                <h3>
                    <Link :href="`/learn/${track.key}`">{{ t(track.title) }}</Link>
                </h3>
                <ul class="lesson-list">
                    <li v-for="m in track.modules" :key="m.key" class="lesson-row">
                        <Link :href="`/learn/${track.key}/${m.key}`" class="lesson-title">{{ t(m.title) }}</Link>
                        <span v-if="m.minutes" class="lesson-meta">{{ m.minutes }} {{ t('c_education.learn.ui.minutes') }}</span>
                        <StatusBadge v-if="m.completed" tone="success">{{ t('c_education.learn.ui.completed') }}</StatusBadge>
                    </li>
                </ul>
            </Card>
        </section>

        <section aria-labelledby="tracks-h" class="stack">
            <h2 id="tracks-h">{{ t('c_education.learn.ui.all_tracks') }}</h2>
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
                        <span v-if="m.minutes" class="lesson-meta">{{ m.minutes }} {{ t('c_education.learn.ui.minutes') }}</span>
                        <StatusBadge v-if="m.completed" tone="success">{{ t('c_education.learn.ui.completed') }}</StatusBadge>
                    </li>
                </ul>
            </Card>
        </section>

        <section aria-labelledby="more-h" class="stack">
            <h2 id="more-h">{{ t('c_education.learn.ui.open_journeys') }}</h2>
            <p>
                <Link href="/learn/guides" class="btn"><Icon name="list-checks" size="sm" /> {{ t('c_education.learn.ui.guides_title') }}</Link>
                <Link href="/journeys" class="btn">Journeys <Icon name="arrow-right" size="sm" /></Link>
            </p>
        </section>
    </PageScaffold>
</template>
