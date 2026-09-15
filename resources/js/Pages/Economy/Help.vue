<script setup>
import { computed } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import WorkTradeNav from '@/Components/Economy/WorkTradeNav.vue';
import Card from '@/Components/Ui/Card.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Field from '@/Components/Ui/Field.vue';
import Btn from '@/Components/Ui/Btn.vue';
import { formatWhen } from '@/lib/money.js';

defineOptions({ layout: AppShellV2 });
const { t } = useI18n();
const props = defineProps({
    surface: { type: Object, default: null },
    tab: { type: String, default: 'public' },
    requests: { type: Object, default: () => ({ data: [], previous: null, next: null }) },
    canParticipate: { type: Boolean, default: false },
    participationNotice: { type: String, default: null },
});
const page = usePage();
const form = useForm(`help-request:${page.props.auth?.user?.id ?? 'session'}`, { title: '', need: '', privacy: 'private' });
const tabs = computed(() => [{ key: 'public', label: t('c_economy.help.tab_public', 'Find a request') }, { key: 'mine', label: t('c_economy.help.tab_mine', 'My requests') }, { key: 'responding', label: t('c_economy.help.tab_responding', 'My offers of help') }]);
const errors = computed(() => [...new Set(Object.values(page.props.errors ?? {}))]);
const statusLabel = value => ({ open: t('c_economy.help.status_open', 'Open'), matched: t('c_economy.help.status_matched', 'Helper selected'), resolved: t('c_economy.help.status_resolved', 'Completed'), withdrawn: t('c_economy.help.status_withdrawn', 'Withdrawn'), closed: t('c_economy.help.status_closed', 'Closed') }[value] ?? t('c_economy.help.status_recorded', 'Recorded'));
function create() {
    if (!props.canParticipate || form.processing) return;
    form.post('/economy/help', { onSuccess: () => form.reset() });
}
</script>

<template>
    <PageScaffold :surface="surface" :title="t('c_economy.help.title', 'Give & find help')">
        <template #intro>{{ t('c_economy.help.intro', 'Ask for support, offer your time or skills, and agree with someone on the help they need.') }}</template>
        <WorkTradeNav active="help" back-href="/economy/market?tab=assistance" :back-label="t('c_economy.help.back_label', 'Market requests')" />
        <Banner v-if="page.props.flash?.status" tone="info" role="status">{{ page.props.flash.status }}</Banner>
        <Banner v-if="errors.length" tone="emergency" role="alert"><p v-for="error in errors" :key="error">{{ error }}</p></Banner>
        <Banner v-if="participationNotice" tone="info">{{ participationNotice }} <Link href="/economy/wallet" class="prose-link">{{ t('c_economy.help.open_my_wallet', 'Open my wallet') }}</Link></Banner>
        <Card v-if="canParticipate" as="section" :title="t('c_economy.help.ask_for_help', 'Ask for help')">
            <details><summary>{{ t('c_economy.help.write_a_request', 'Write a request') }}</summary>
                <form @submit.prevent="create">
                    <Field :label="t('c_economy.help.request_title_label', 'Request title')" required :error="form.errors.title">
                        <template #control="{ id, describedBy, invalid }"><input :id="id" v-model="form.title" class="field-input" maxlength="160" required :aria-describedby="describedBy" :aria-invalid="invalid" /></template>
                    </Field>
                    <Field :label="t('c_economy.help.need_label', 'What help do you need?')" required :error="form.errors.need" :hint="t('c_economy.help.need_hint', 'Describe the task, timing and what a helper should know.')">
                        <template #control="{ id, describedBy, invalid }"><textarea :id="id" v-model="form.need" rows="5" maxlength="10000" required :aria-describedby="describedBy" :aria-invalid="invalid" /></template>
                    </Field>
                    <Field :label="t('c_economy.help.privacy_label', 'Who can see this request?')" :error="form.errors.privacy">
                        <template #control="{ id, describedBy }"><select :id="id" v-model="form.privacy" :aria-describedby="describedBy"><option value="private">{{ t('c_economy.help.privacy_private', 'Only me — save a draft') }}</option><option value="public">{{ t('c_economy.help.privacy_public', 'Public — people can offer help') }}</option></select></template>
                    </Field>
                    <p class="help-note">{{ t('c_economy.help.privacy_note', 'Public requests are visible in the market. Replies are private between the requester and each person offering help. You can publish a draft later.') }}</p>
                    <Btn type="submit" :disabled="form.processing">{{ form.processing ? t('c_economy.help.saving', 'Saving…') : form.privacy === 'public' ? t('c_economy.help.publish_request', 'Publish request') : t('c_economy.help.save_private_draft', 'Save private draft') }}</Btn>
                </form>
            </details>
        </Card>
        <nav class="help-links" :aria-label="t('c_economy.help.workspace_nav', 'Help workspace')">
            <Link v-for="item in tabs" :key="item.key" :href="`/economy/help?tab=${item.key}`" :aria-current="tab === item.key ? 'page' : undefined">{{ item.label }}</Link>
        </nav>
        <section :aria-label="tabs.find(item => item.key === tab)?.label ?? t('c_economy.help.requests_fallback', 'Requests')">
            <p v-if="!requests.data.length">{{ t('c_economy.help.no_requests', 'No requests on this page.') }}<template v-if="tab === 'responding'">{{ t('c_economy.help.responding_hint', ' Open a public request to offer help.') }}</template></p>
            <Card v-for="request in requests.data" :key="request.id" as="article" class="help-card">
                <h2><Link :href="request.href">{{ request.title }}</Link></h2>
                <p class="help-note">{{ statusLabel(request.status) }} · {{ request.privacy === 'public' ? t('c_economy.help.vis_public', 'Public') : request.privacy === 'private' ? t('c_economy.help.vis_private', 'Private') : t('c_economy.help.vis_restricted', 'Restricted') }}<template v-if="request.created_at"> · {{ formatWhen(request.created_at) }}</template></p>
                <p class="help-body">{{ request.need }}</p>
            </Card>
        </section>
        <nav class="help-links" :aria-label="t('c_economy.help.request_pages', 'Request pages')">
            <Link v-if="requests.previous" :href="requests.previous" rel="prev">{{ t('c_economy.help.previous_requests', 'Previous requests') }}</Link>
            <Link v-if="requests.next" :href="requests.next" rel="next">{{ t('c_economy.help.next_requests', 'Next requests') }}</Link>
        </nav>
    </PageScaffold>
</template>

<style scoped>
.help-links { display: flex; flex-wrap: wrap; gap: .5rem 1rem; margin-block: 1rem; }
.help-links a, summary { display: inline-flex; align-items: center; min-block-size: 44px; padding: .5rem .65rem; }
.help-links a[aria-current] { font-weight: 600; background: var(--gov-surface-subtle); box-shadow: inset 0 -2px var(--gov-accent); }
.help-note { color: var(--gov-text-muted); font-size: .875rem; }
.help-body { white-space: pre-wrap; overflow-wrap: anywhere; }
.help-card { margin-block: 1rem; }
summary { cursor: pointer; font-weight: 600; }
a:focus-visible, summary:focus-visible { outline: 3px solid var(--gov-accent); outline-offset: 3px; }
</style>
