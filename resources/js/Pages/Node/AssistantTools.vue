<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import NodeNav from '@/Components/Node/NodeNav.vue';
import PreviewBanner from '@/Components/Node/PreviewBanner.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { useNodeChoices } from '@/lib/nodeChoices.js';

const props = defineProps({ tools: Array, classes: Array, levels: Array, inventory: Array });
const { t } = useI18n();
const choices = useNodeChoices('tools', { off: {} });
const tone = s => ({ built: 'success', partial: 'warning', planned: 'neutral' })[s] || 'neutral';
const classTone = c => ({ R: 'success', W: 'info', A: 'warning' })[c] || 'neutral';
const enabled = computed(() => props.tools.filter(x => !choices.value.off[x.id]).length);
</script>

<template>
    <PageScaffold :title="t('c_node.tools.title', 'Assistant tools')">
        <template #intro>{{ t('c_node.tools.intro', 'Every capability is one tool in a catalog. A voice, a phone call and the app all use the same tool. Each tool has a safety class and a lowest surface level.') }}</template>
        <NodeNav current="tools" />
        <PreviewBanner />

        <section class="app-setting-card" aria-labelledby="t-key">
            <h2 id="t-key">{{ t('c_node.tools.key', 'Safety classes and surface levels') }}</h2>
            <ul>
                <li v-for="c in classes" :key="c.id"><StatusBadge :tone="classTone(c.id)">{{ c.id }}</StatusBadge> <strong>{{ c.label }}</strong> <span class="muted">{{ c.note }}</span></li>
            </ul>
            <ul>
                <li v-for="l in levels" :key="l.id"><strong>{{ l.id }}</strong> {{ l.label }} <span class="muted">{{ l.note }}</span></li>
            </ul>
        </section>

        <section class="app-setting-card" aria-labelledby="t-cat">
            <h2 id="t-cat">{{ t('c_node.tools.catalog', 'Tool catalog') }} <small class="muted">{{ enabled }} / {{ tools.length }} {{ t('c_node.tools.enabled', 'enabled') }}</small></h2>
            <table>
                <thead><tr>
                    <th>{{ t('c_node.tools.on', 'On') }}</th><th>{{ t('c_node.tools.name', 'Tool') }}</th><th>{{ t('c_node.tools.class', 'Class') }}</th>
                    <th>{{ t('c_node.tools.min', 'Lowest surface') }}</th><th>{{ t('c_node.tools.phrases', 'Example phrases') }}</th><th>{{ t('c_node.tools.status', 'Status') }}</th>
                </tr></thead>
                <tbody>
                    <tr v-for="x in tools" :key="x.id">
                        <td><input type="checkbox" :checked="!choices.off[x.id]" :aria-label="x.name" @change="choices.off[x.id] = !$event.target.checked"></td>
                        <td><strong>{{ x.name }}</strong><br><span class="muted">{{ x.does }}</span></td>
                        <td><StatusBadge :tone="classTone(x.class)">{{ x.class }}</StatusBadge></td>
                        <td>{{ x.min_surface }}</td>
                        <td>{{ x.phrases.join('; ') || '-' }}</td>
                        <td><StatusBadge :tone="tone(x.status)">{{ x.status }}</StatusBadge></td>
                    </tr>
                </tbody>
            </table>
            <p class="muted">{{ t('c_node.tools.draft', 'If no tool fits, the assistant does not improvise. It drafts a new tool, inactive, tests it in dry mode, and waits for your approval to publish.') }}</p>
        </section>

        <section class="app-setting-card" aria-labelledby="t-inv">
            <h2 id="t-inv">{{ t('c_node.tools.inventory', 'From the standalone assistant app to this app') }}</h2>
            <table>
                <thead><tr><th>{{ t('c_node.tools.tab', 'Tab') }}</th><th>{{ t('c_node.tools.there', 'There') }}</th><th>{{ t('c_node.tools.here', 'Here') }}</th><th>{{ t('c_node.tools.status', 'Status') }}</th><th>{{ t('c_node.tools.item', 'Work item') }}</th></tr></thead>
                <tbody>
                    <tr v-for="i in inventory" :key="i.id">
                        <td><strong>{{ i.name }}</strong></td><td>{{ i.jarvis }}</td><td>{{ i.here }}</td>
                        <td><StatusBadge :tone="tone(i.status)">{{ i.status }}</StatusBadge></td><td>{{ i.item }}</td>
                    </tr>
                </tbody>
            </table>
        </section>
    </PageScaffold>
</template>
