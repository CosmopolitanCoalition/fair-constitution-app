<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import NodeNav from '@/Components/Node/NodeNav.vue';
import PreviewBanner from '@/Components/Node/PreviewBanner.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { useNodeChoices } from '@/lib/nodeChoices.js';

const props = defineProps({ sharing: Object, peers: Array, audiences: Array, stores: Array });
const { t } = useI18n();
const state = useNodeChoices('sharing', {
    resources: Object.fromEntries(props.sharing.resources.map(r => [r.id, { on: false, amount: 0, audience: 'community' }])),
    links: [],
    draft: { name: '', kind: props.sharing.link_kinds[0], scopes: [], days: '' },
});
const canAdd = computed(() => state.value.draft.name.trim() !== '' && state.value.draft.scopes.length > 0);
function addLink() {
    const d = state.value.draft;
    state.value.links.push({ id: Date.now().toString(36), name: d.name.trim(), kind: d.kind, scopes: [...d.scopes], days: d.days || null, state: 'proposed' });
    state.value.draft = { name: '', kind: props.sharing.link_kinds[0], scopes: [], days: '' };
}
const removeLink = id => { state.value.links = state.value.links.filter(l => l.id !== id); };
</script>

<template>
    <PageScaffold :title="t('c_node.sharing.title', 'Sharing and peers')">
        <template #intro>{{ t('c_node.sharing.intro', 'Households and organizations link to each other directly. Data stays local and takes the shortest path. A link is a signed grant that either side can end.') }}</template>
        <NodeNav current="sharing" />
        <PreviewBanner />

        <section class="app-setting-card" aria-labelledby="sh-paths">
            <h2 id="sh-paths">{{ t('c_node.sharing.paths', 'The path a message takes') }}</h2>
            <ol>
                <li v-for="p in sharing.paths" :key="p.id"><strong>{{ p.label }}</strong> <span class="muted">{{ p.note }}</span></li>
            </ol>
            <p class="muted">{{ t('c_node.sharing.paths_note', 'The client tries each step in order and shows which one it used.') }}</p>
        </section>

        <section class="app-setting-card" aria-labelledby="sh-links">
            <h2 id="sh-links">{{ t('c_node.sharing.links', 'Peer circles') }}</h2>
            <p v-if="!peers.length" class="muted">{{ t('c_node.sharing.no_peers', 'No federation peers are known to this box yet.') }}</p>
            <ul v-if="peers.length">
                <li v-for="p in peers" :key="p.id">{{ p.name }} <StatusBadge tone="info">{{ p.status }}</StatusBadge></li>
            </ul>
            <form @submit.prevent="addLink">
                <h3>{{ t('c_node.sharing.new_link', 'Propose a link') }}</h3>
                <label>{{ t('c_node.sharing.who', 'Household or organization address') }}
                    <input v-model="state.draft.name" maxlength="120" :placeholder="t('c_node.sharing.who_hint', 'name@place.chain')">
                </label>
                <label>{{ t('c_node.sharing.kind', 'Kind of link') }}
                    <select v-model="state.draft.kind"><option v-for="k in sharing.link_kinds" :key="k">{{ k }}</option></select>
                </label>
                <fieldset>
                    <legend>{{ t('c_node.sharing.scopes', 'What the grant allows') }}</legend>
                    <label v-for="s in sharing.grant_scopes" :key="s"><input type="checkbox" :value="s" v-model="state.draft.scopes"> {{ s }}</label>
                </fieldset>
                <label>{{ t('c_node.sharing.days', 'Ends after (days, optional)') }}
                    <input v-model="state.draft.days" type="number" min="1" max="3650">
                </label>
                <button class="btn btn--primary" :disabled="!canAdd">{{ t('c_node.sharing.add', 'Add to the list') }}</button>
            </form>
            <ul>
                <li v-for="l in state.links" :key="l.id">
                    <strong>{{ l.name }}</strong> · {{ l.kind }} · {{ l.scopes.join(', ') }}
                    <StatusBadge tone="warning">{{ t('c_node.sharing.proposed', 'Proposed, not sent') }}</StatusBadge>
                    <button type="button" class="btn btn--secondary btn--sm" @click="removeLink(l.id)">{{ t('c_node.remove', 'Remove') }}</button>
                </li>
            </ul>
        </section>

        <section class="app-setting-card" aria-labelledby="sh-res">
            <h2 id="sh-res">{{ t('c_node.sharing.resources', 'Resources you lend') }}</h2>
            <p>{{ t('c_node.sharing.resources_body', 'Nothing is offered by default. Each offer names who may use it.') }}</p>
            <div v-for="r in sharing.resources" :key="r.id" class="grid">
                <label><input type="checkbox" v-model="state.resources[r.id].on"> <strong>{{ r.label }}</strong> <span class="muted">{{ r.note }}</span></label>
                <label>{{ r.unit }} <input type="number" min="0" v-model.number="state.resources[r.id].amount" :disabled="!state.resources[r.id].on"></label>
                <label>{{ t('c_node.sharing.for', 'For') }}
                    <select v-model="state.resources[r.id].audience" :disabled="!state.resources[r.id].on">
                        <option v-for="a in audiences" :key="a.id" :value="a.id">{{ a.label }}</option>
                    </select>
                </label>
            </div>
        </section>

        <section class="app-setting-card" aria-labelledby="sh-chart">
            <h2 id="sh-chart">{{ t('c_node.sharing.chart', 'Who holds what') }}</h2>
            <table>
                <thead><tr><th>{{ t('c_node.sharing.store', 'Store') }}</th><th>{{ t('c_node.sharing.owner', 'Owner') }}</th></tr></thead>
                <tbody><tr v-for="s in stores" :key="s.id"><td>{{ s.name }}</td><td>{{ s.owner }}</td></tr></tbody>
            </table>
            <p class="muted">{{ t('c_node.sharing.chart_note', 'The explainable relationship chart, showing which box holds each copy and why, comes with the replication work.') }}</p>
        </section>
    </PageScaffold>
</template>
