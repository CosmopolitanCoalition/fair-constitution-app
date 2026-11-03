<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import NodeNav from '@/Components/Node/NodeNav.vue';
import PreviewBanner from '@/Components/Node/PreviewBanner.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { useNodeChoices } from '@/lib/nodeChoices.js';

const props = defineProps({ bundles: Array, external: Array, host: Object });
const { t } = useI18n();
const picked = useNodeChoices('bundles', { on: Object.fromEntries(props.bundles.map(b => [b.id, false])), ext: {} });
const tone = s => ({ running: 'success', built: 'success', partial: 'warning', planned: 'neutral' })[s] || 'neutral';
const ram = computed(() => props.bundles.filter(b => picked.value.on[b.id]).reduce((n, b) => n + b.ram_mb, 0));
const disk = computed(() => props.bundles.filter(b => picked.value.on[b.id]).reduce((n, b) => n + b.disk_gb, 0));
const ramOver = computed(() => props.host.memory_mb !== null && ram.value > props.host.memory_mb);
const diskOver = computed(() => props.host.disk_free_gb !== null && disk.value > props.host.disk_free_gb);
</script>

<template>
    <PageScaffold :title="t('c_node.bundles.title', 'Tools to add')">
        <template #intro>{{ t('c_node.bundles.intro', 'Native tools come with the app. Bundles add self-hosted tools that work with it. A tool joins through a scoped key or a pipeline, never by sharing your password.') }}</template>
        <NodeNav current="bundles" />
        <PreviewBanner />

        <section class="app-setting-card" aria-labelledby="b-cost" role="status">
            <h2 id="b-cost">{{ t('c_node.bundles.cost', 'What your picks need') }}</h2>
            <p>
                {{ t('c_node.bundles.mem', 'Memory') }}: {{ ram }} MB
                <template v-if="host.memory_mb !== null">{{ t('c_node.bundles.of', 'of') }} {{ host.memory_mb }} MB {{ t('c_node.bundles.limit', 'on this box') }}</template>.
                {{ t('c_node.bundles.disk', 'Disk') }}: {{ disk }} GB
                <template v-if="host.disk_free_gb !== null">{{ t('c_node.bundles.of', 'of') }} {{ host.disk_free_gb }} GB {{ t('c_node.bundles.free', 'free') }}</template>.
            </p>
            <p v-if="ramOver || diskOver"><StatusBadge tone="danger">{{ t('c_node.bundles.warn', 'These picks are larger than this box reports. Remove one or accept the risk.') }}</StatusBadge></p>
            <p class="muted">{{ t('c_node.bundles.measure', 'Sizes are estimates until the container measurement work is done. A bundle marked running is running somewhere in the fleet, not necessarily on this box. Tick the ones you want on this box.') }}</p>
        </section>

        <article v-for="b in bundles" :key="b.id" class="app-setting-card" :aria-labelledby="'b-' + b.id">
            <h2 :id="'b-' + b.id">
                <label><input type="checkbox" v-model="picked.on[b.id]"> {{ b.name }}</label>
                <StatusBadge :tone="tone(b.status)">{{ b.status }}</StatusBadge>
            </h2>
            <p>{{ b.note }}</p>
            <ul><li v-for="p in b.parts" :key="p">{{ p }}</li></ul>
            <p class="muted">{{ b.ram_mb }} MB · {{ b.disk_gb }} GB · {{ t('c_node.bundles.connects', 'Connects to') }}: {{ b.connects }}</p>
        </article>

        <section class="app-setting-card" aria-labelledby="b-ext">
            <h2 id="b-ext">{{ t('c_node.bundles.external', 'Suggested outside connections') }}</h2>
            <p>{{ t('c_node.bundles.external_body', 'Credentials stay in your vault. The wizard prepares the steps and never handles the values.') }}</p>
            <ul>
                <li v-for="e in external" :key="e.id">
                    <label><input type="checkbox" v-model="picked.ext[e.id]"> <strong>{{ e.name }}</strong></label>
                    <span class="muted"> {{ e.kind }} · {{ e.how }}</span>
                    <StatusBadge :tone="tone(e.status)">{{ e.status }}</StatusBadge>
                </li>
            </ul>
        </section>
    </PageScaffold>
</template>
