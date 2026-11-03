<script setup>
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import NodeNav from '@/Components/Node/NodeNav.vue';
import PreviewBanner from '@/Components/Node/PreviewBanner.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { useNodeChoices } from '@/lib/nodeChoices.js';

const props = defineProps({ node: Object, kits: Array, counts: Object });
const { t } = useI18n();
const choices = useNodeChoices('kits', { on: Object.fromEntries(props.kits.map(k => [k.id, k.on])) });
const reach = useNodeChoices('reach', { value: 'tunnel' });
const REACH = [
    ['own', 'Own router and equipment'],
    ['relay', 'A community relay'],
    ['tunnel', 'A cloud tunnel'],
    ['lan', 'LAN only, behind my own VPN'],
];
const cards = [
    { href: '/node/data', title: 'Datastores and permissions', body: 'Who may see each store, where copies live, what the assistant may do.' },
    { href: '/node/sharing', title: 'Sharing and peers', body: 'Links to other households and organizations, and the resources you lend.' },
    { href: '/node/bundles', title: 'Tools to add', body: 'Self-hosted tools that work with the app, and outside services to connect.' },
    { href: '/node/assistant', title: 'Assistant', body: 'Its brain, its rights, its tools and the places you can reach it.' },
];
</script>

<template>
    <PageScaffold :title="t('c_node.home.title', 'This node')">
        <template #intro>{{ t('c_node.home.intro', 'What this box hosts, where it lives, and how people reach it. Everyone starts in a household of one.') }}</template>
        <NodeNav current="home" />
        <PreviewBanner />

        <section class="app-setting-card" aria-labelledby="node-id">
            <h2 id="node-id">{{ t('c_node.home.identity', 'Identity') }}</h2>
            <dl>
                <dt>{{ t('c_node.home.name', 'Name') }}</dt><dd>{{ node.name || t('c_node.home.unnamed', 'Not named yet') }}</dd>
                <dt>{{ t('c_node.home.host', 'Served as') }}</dt><dd><code>{{ node.host }}</code></dd>
                <dt>{{ t('c_node.home.map', 'Map') }}</dt><dd>{{ node.map_mode || '-' }}</dd>
                <dt>{{ t('c_node.home.fed', 'Federation') }}</dt>
                <dd><StatusBadge :tone="node.federation ? 'success' : 'neutral'">{{ node.federation ? t('c_node.on', 'On') : t('c_node.off', 'Off') }}</StatusBadge></dd>
            </dl>
        </section>

        <section class="app-setting-card" aria-labelledby="node-kits">
            <h2 id="node-kits">{{ t('c_node.home.kits', 'Kits on this box') }}</h2>
            <p>{{ t('c_node.home.kits_body', 'One box can run any mix. The individual kit is always on.') }}</p>
            <ul>
                <li v-for="k in kits" :key="k.id">
                    <label>
                        <input type="checkbox" v-model="choices.on[k.id]" :disabled="k.locked">
                        <strong>{{ k.label }}</strong>
                    </label>
                    <span class="muted"> {{ k.note }}</span>
                </li>
            </ul>
        </section>

        <section class="app-setting-card" aria-labelledby="node-reach">
            <h2 id="node-reach">{{ t('c_node.home.reach', 'How people reach this box') }}</h2>
            <fieldset>
                <legend>{{ t('c_node.home.reach_legend', 'Choose one. You can change it later.') }}</legend>
                <label v-for="r in REACH" :key="r[0]"><input type="radio" name="reach" :value="r[0]" v-model="reach.value"> {{ r[1] }}</label>
            </fieldset>
        </section>

        <section class="app-setting-card" aria-labelledby="node-counts">
            <h2 id="node-counts">{{ t('c_node.home.yours', 'What you keep here') }}</h2>
            <ul>
                <li>{{ t('c_node.home.events', 'Calendar events') }}: {{ counts.personal_events ?? '-' }}</li>
                <li>{{ t('c_node.home.tasks', 'Tasks') }}: {{ counts.personal_tasks ?? '-' }}</li>
                <li>{{ t('c_node.home.contacts', 'Contacts') }}: {{ counts.crm_contacts ?? '-' }}</li>
                <li>{{ t('c_node.home.mail', 'Mail messages') }}: {{ counts.mail_messages ?? '-' }}</li>
                <li>{{ t('c_node.home.keys', 'Active API keys') }}: {{ counts.api_keys ?? '-' }}; {{ t('c_node.home.secrets', 'vault secrets') }}: {{ counts.user_secrets ?? '-' }}</li>
            </ul>
        </section>

        <section aria-labelledby="node-go">
            <h2 id="node-go">{{ t('c_node.home.go', 'Manage') }}</h2>
            <div class="grid">
                <article v-for="c in cards" :key="c.href" class="app-setting-card">
                    <h3><Link :href="c.href">{{ t('c_node.card.' + c.href.slice(6).replace(/\//g, '_'), c.title) }}</Link></h3>
                    <p>{{ c.body }}</p>
                </article>
            </div>
        </section>
    </PageScaffold>
</template>
