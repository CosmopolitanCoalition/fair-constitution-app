<script setup>
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import NodeNav from '@/Components/Node/NodeNav.vue';
import PreviewBanner from '@/Components/Node/PreviewBanner.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { useNodeChoices } from '@/lib/nodeChoices.js';
import { useAssistant } from '@/composables/useAssistant.js';

const props = defineProps({ brains: Array, tiers: Array, stores: Array, rights: Array, vault: Object });
const { t } = useI18n();
const { open } = useAssistant();
const choices = useNodeChoices('assistant', { brain: 'self_hosted', quota: { subkeys: [] }, draftSub: { name: '', quota: 100 } });
const tone = s => (s === 'running' ? 'success' : 'neutral');
const rightsOf = s => (s.assistant.length ? s.assistant.join(', ') : t('c_node.assistant.none', 'none'));
function addSubkey() {
    const d = choices.value.draftSub;
    if (!d.name.trim()) return;
    choices.value.quota.subkeys.push({ id: Date.now().toString(36), name: d.name.trim(), quota: Number(d.quota) || 0 });
    choices.value.draftSub = { name: '', quota: 100 };
}
const dropSub = id => { choices.value.quota.subkeys = choices.value.quota.subkeys.filter(s => s.id !== id); };
</script>

<template>
    <PageScaffold :title="t('c_node.assistant.title', 'Assistant')">
        <template #intro>{{ t('c_node.assistant.intro', 'The assistant is your own. It can do what you may do right now, and nothing more. Its tools follow your current roles. Its memory belongs to you. Its compute comes from a key you choose.') }}</template>
        <NodeNav current="assistant" />
        <PreviewBanner />

        <p><button type="button" class="btn btn--primary" @click="open()">{{ t('c_node.assistant.open', 'Open the assistant drawer') }}</button></p>

        <section class="app-setting-card" aria-labelledby="a-brain">
            <h2 id="a-brain">{{ t('c_node.assistant.brain', 'Where its compute comes from') }}</h2>
            <p>{{ t('c_node.assistant.brain_body', 'A key lends compute only. The permission check still governs your data.') }}</p>
            <fieldset>
                <legend>{{ t('c_node.assistant.brain_legend', 'Choose one. You can switch at any time.') }}</legend>
                <label v-for="b in brains" :key="b.id" class="block">
                    <input type="radio" name="brain" :value="b.id" v-model="choices.brain">
                    <strong>{{ b.label }}</strong> <StatusBadge :tone="tone(b.status)">{{ b.status }}</StatusBadge>
                    <span class="muted"> {{ b.source }}</span>
                </label>
            </fieldset>
            <p class="muted">{{ t('c_node.assistant.vault', 'Vault today') }}: {{ vault.keys ?? '-' }} {{ t('c_node.assistant.keys', 'active API keys') }}, {{ vault.secrets ?? '-' }} {{ t('c_node.assistant.secrets', 'stored secrets') }}. <Link href="/connections">{{ t('c_node.assistant.manage_keys', 'Manage keys and secrets') }}</Link></p>
        </section>

        <section class="app-setting-card" aria-labelledby="a-sub">
            <h2 id="a-sub">{{ t('c_node.assistant.subkeys', 'Subkeys you issue') }}</h2>
            <p>{{ t('c_node.assistant.subkeys_body', 'A household, organization or community can issue subkeys that relay to models through its own keys. The quota belongs to each subkey relationship, not to a person.') }}</p>
            <form @submit.prevent="addSubkey">
                <label>{{ t('c_node.assistant.sub_name', 'Relationship') }} <input v-model="choices.draftSub.name" maxlength="80"></label>
                <label>{{ t('c_node.assistant.sub_quota', 'Quota (requests per day)') }} <input v-model.number="choices.draftSub.quota" type="number" min="0"></label>
                <button class="btn btn--secondary">{{ t('c_node.sharing.add', 'Add to the list') }}</button>
            </form>
            <ul>
                <li v-for="s in choices.quota.subkeys" :key="s.id">{{ s.name }} · {{ s.quota }} / {{ t('c_node.assistant.day', 'day') }}
                    <button type="button" class="btn btn--secondary btn--sm" @click="dropSub(s.id)">{{ t('c_node.remove', 'Remove') }}</button></li>
            </ul>
        </section>

        <section class="app-setting-card" aria-labelledby="a-rights">
            <h2 id="a-rights">{{ t('c_node.assistant.rights', 'What it may do with each store') }}</h2>
            <p>{{ t('c_node.assistant.rights_body', 'Set on the datastores page by each data owner. A toolkit for a role appears only while you hold the role.') }}
                <Link href="/node/data">{{ t('c_node.assistant.edit', 'Edit rights') }}</Link></p>
            <table>
                <thead><tr><th>{{ t('c_node.sharing.store', 'Store') }}</th><th>{{ t('c_node.assistant.rights_col', 'Rights') }}</th></tr></thead>
                <tbody><tr v-for="s in stores" :key="s.id"><td>{{ s.name }}</td><td>{{ rightsOf(s) }}</td></tr></tbody>
            </table>
        </section>

        <section class="app-setting-card" aria-labelledby="a-tiers">
            <h2 id="a-tiers">{{ t('c_node.assistant.tiers', 'How a request is answered, cheapest first') }}</h2>
            <ol>
                <li v-for="x in tiers" :key="x.id"><strong>{{ x.label }}</strong> · {{ x.where }}
                    <span class="muted"> {{ x.model ? t('c_node.assistant.uses_model', 'uses a model') : t('c_node.assistant.no_model', 'no model') }} · {{ x.example }}</span></li>
            </ol>
        </section>
    </PageScaffold>
</template>
