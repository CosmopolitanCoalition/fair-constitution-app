<script setup>
/**
 * Learn/Guides — the walkthrough index (contract mockups/v3/learn/guides.html;
 * K-2). Guides stand on their own; the live half is the journeys engine, so
 * every row door-links there.
 */
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import Icon from '@/Components/Ui/Icon.vue';

defineOptions({ layout: AppShellV2 });

defineProps({
    surface: { type: Object, required: true },
    journeys: { type: Array, default: () => [] },
});

const { t } = useI18n({ useScope: 'global' });
</script>

<template>
    <PageScaffold :surface="surface" :title="t('c_education.learn.ui.guides_title')">
        <template #intro>{{ t('c_education.learn.ui.guides_intro') }}</template>

        <div class="stack">
            <Card v-for="j in journeys" :key="j.key">
                <h3><Link :href="j.href">{{ j.title }}</Link></h3>
                <p>
                    <Link :href="j.href" class="btn">
                        Do it live <Icon name="arrow-right" size="sm" />
                    </Link>
                </p>
            </Card>
        </div>

        <p>
            <Link href="/learn" class="btn">
                <Icon name="graduation-cap" size="sm" /> {{ t('c_education.learn.ui.back_to_learn') }}
            </Link>
        </p>
    </PageScaffold>
</template>
