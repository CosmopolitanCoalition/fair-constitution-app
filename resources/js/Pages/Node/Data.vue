<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import NodeNav from '@/Components/Node/NodeNav.vue';
import PreviewBanner from '@/Components/Node/PreviewBanner.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { useNodeChoices } from '@/lib/nodeChoices.js';

const props = defineProps({ stores: Array, audiences: Array, rights: Array, after: Array, replication: Array });
const { t } = useI18n();
const defaults = Object.fromEntries(props.stores.map(s => [s.id, { audience: s.audience, replication: s.replication, assistant: [...s.assistant], after: s.after }]));
const choices = useNodeChoices('stores', { stores: defaults });
const owner = ref('all');
const OWNERS = ['all', 'individual', 'household', 'organization', 'community'];
const shown = computed(() => props.stores.filter(s => owner.value === 'all' || s.owner === owner.value));
const tone = s => ({ built: 'success', partial: 'warning', planned: 'neutral' })[s] || 'neutral';
const cfg = id => choices.value.stores[id] ?? (choices.value.stores[id] = JSON.parse(JSON.stringify(defaults[id])));
function toggleRight(id, r) {
    const list = cfg(id).assistant;
    const i = list.indexOf(r);
    if (i >= 0) list.splice(i, 1); else list.push(r);
}
const reset = id => { choices.value.stores[id] = JSON.parse(JSON.stringify(defaults[id])); };
</script>

<template>
    <PageScaffold :title="t('c_node.data.title', 'Datastores and permissions')">
        <template #intro>{{ t('c_node.data.intro', 'Each store has an owner, an audience, a place for its copies and a set of rights for the assistant. The data owner sets the policy, including what happens when a relationship ends.') }}</template>
        <NodeNav current="data" />
        <PreviewBanner />

        <div role="group" :aria-label="t('c_node.data.filter', 'Filter by owner')" class="chips">
            <button v-for="o in OWNERS" :key="o" type="button" class="btn btn--secondary btn--sm" :aria-pressed="owner === o" @click="owner = o">
                {{ t('c_node.owner.' + o, o) }}
            </button>
        </div>

        <article v-for="s in shown" :key="s.id" class="app-setting-card" :aria-labelledby="'s-' + s.id">
            <h2 :id="'s-' + s.id">{{ s.name }}
                <StatusBadge :tone="tone(s.status)">{{ s.status }}</StatusBadge>
                <small class="muted"> {{ s.owner }}<template v-if="s.count !== null"> · {{ s.count }} {{ t('c_node.data.items', 'items') }}</template></small>
            </h2>
            <p>{{ s.note }}</p>
            <div class="grid">
                <label>{{ t('c_node.data.audience', 'Who may see it') }}
                    <select v-model="cfg(s.id).audience">
                        <option v-for="a in audiences" :key="a.id" :value="a.id">{{ a.label }}</option>
                    </select>
                </label>
                <label>{{ t('c_node.data.replication', 'Where copies live') }}
                    <select v-model="cfg(s.id).replication">
                        <option v-for="r in replication" :key="r.id" :value="r.id">{{ r.label }}</option>
                    </select>
                </label>
                <label>{{ t('c_node.data.after', 'When the relationship ends') }}
                    <select v-model="cfg(s.id).after">
                        <option v-for="a in after" :key="a.id" :value="a.id">{{ a.label }}</option>
                    </select>
                </label>
            </div>
            <fieldset>
                <legend>{{ t('c_node.data.assistant', 'Assistant rights on this store') }}</legend>
                <label v-for="r in rights" :key="r.id">
                    <input type="checkbox" :checked="cfg(s.id).assistant.includes(r.id)" @change="toggleRight(s.id, r.id)"> {{ r.label }}
                </label>
                <p v-if="s.id === 'vault'" class="muted">{{ t('c_node.data.vault_note', 'The assistant never reads vault values. A tool uses a scoped key instead.') }}</p>
            </fieldset>
            <button type="button" class="btn btn--secondary btn--sm" @click="reset(s.id)">{{ t('c_node.reset', 'Reset to default') }}</button>
        </article>
    </PageScaffold>
</template>
