<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import HostNav from '@/Components/Operator/HostNav.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';

defineOptions({ layout: AppShellV2 });

const props = defineProps({
    authed: { type: Boolean, default: false },
    operator: { type: String, default: null },
    profile: { type: Object, default: null },
    kits: { type: Array, default: () => [] },
});

const { t } = useI18n();

const KIT_TEXT = {
    individual: ['Individual', 'One person and their own data. Always on. Every person starts in a household of one.'],
    household: ['Household', 'An organization type for people who share a home, with presets for permissions and succession.'],
    organization: ['Organization', 'Members, boards, departments and ledgers for clubs, firms and nonprofits.'],
    community: ['Community', 'The elected institutions of a place. Run it as a simulation or as a real community.'],
};

const form = useForm({
    hosted: props.profile?.hosted ?? ['individual'],
    scopes: Object.fromEntries(
        Object.keys(KIT_TEXT).map((k) => [k, {
            scope: props.profile?.scopes?.[k]?.scope ?? '',
            mode: props.profile?.scopes?.[k]?.mode ?? 'simulation',
        }]),
    ),
});

const isOn = (id) => form.hosted.includes(id);
const toggle = (id) => {
    if (id === 'individual') return;
    form.hosted = isOn(id) ? form.hosted.filter((k) => k !== id) : [...form.hosted, id];
};
const error = computed(() => form.errors.node_profile || Object.values(form.errors)[0] || '');
const save = () => form.post('/operator/node', { preserveScroll: true });
</script>

<template>
    <PageScaffold :title="t('c_host.node.title', 'Node profile')">
        <template #intro>{{ t('c_host.node.intro', 'Choose which kinds of node this box runs and what each one covers. The Individual kit is always on.') }}</template>
        <HostNav current="node" />

        <Banner v-if="!authed" tone="info">
            {{ t('c_host.node.signin', 'Sign in as the host operator to see and change the node profile.') }}
            <Btn as="a" href="/operator/login" variant="primary">{{ t('c_host.node.signin_btn', 'Operator sign-in') }}</Btn>
        </Banner>

        <form v-else @submit.prevent="save" class="node-profile">
            <Banner v-if="error" tone="danger">{{ error }}</Banner>
            <Banner v-else-if="form.recentlySuccessful" tone="success">{{ t('c_host.node.saved', 'Node profile saved.') }}</Banner>

            <section v-for="k in kits" :key="k.id" class="app-setting-card" :aria-labelledby="'kit-' + k.id">
                <h2 :id="'kit-' + k.id">{{ t('c_host.node.kit.' + k.id, KIT_TEXT[k.id][0]) }}</h2>
                <p>{{ t('c_host.node.kit.' + k.id + '.note', KIT_TEXT[k.id][1]) }}</p>
                <label class="row">
                    <input type="checkbox" :checked="isOn(k.id)" :disabled="k.locked" @change="toggle(k.id)" />
                    {{ k.locked ? t('c_host.node.always', 'Always on') : t('c_host.node.runs', 'This box runs this kit') }}
                </label>
                <template v-if="isOn(k.id)">
                    <label v-if="k.id === 'community'" class="field">{{ t('c_host.node.mode', 'Community mode') }}
                        <select v-model="form.scopes.community.mode">
                            <option value="simulation">{{ t('c_host.node.mode.simulation', 'Simulation') }}</option>
                            <option value="real">{{ t('c_host.node.mode.real', 'Real community') }}</option>
                        </select>
                    </label>
                    <label class="field">{{ t('c_host.node.scope', 'Scope') }}
                        <textarea v-model="form.scopes[k.id].scope" rows="2" maxlength="500"
                            :placeholder="t('c_host.node.scope_ph', 'Who and what this kit covers on this box. Free text.')"></textarea>
                    </label>
                </template>
            </section>

            <Btn type="submit" variant="primary" :disabled="form.processing">{{ t('c_host.node.save', 'Save node profile') }}</Btn>
        </form>
    </PageScaffold>
</template>

<style scoped>
.node-profile { display: grid; gap: var(--space-4); }
.row { display: flex; align-items: center; gap: .5rem; }
.field { display: grid; gap: .25rem; margin-block-start: var(--space-2); }
textarea, select { font: inherit; padding: .4rem .5rem; border: 1px solid var(--gov-border); border-radius: var(--radius-sm); background: var(--gov-surface); color: var(--gov-fg); }
</style>
