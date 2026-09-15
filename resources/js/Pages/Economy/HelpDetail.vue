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
import { formatWhen as formatWhenRaw } from '@/lib/money.js';

defineOptions({ layout: AppShellV2 });
const { t, locale } = useI18n();
const formatWhen = (iso) => formatWhenRaw(iso, locale.value);
const props = defineProps({
    surface: { type: Object, default: null },
    assistance: { type: Object, required: true },
    isOwner: { type: Boolean, default: false },
    canParticipate: { type: Boolean, default: false },
    participationNotice: { type: String, default: null },
    canPublish: { type: Boolean, default: false },
    canWithdraw: { type: Boolean, default: false },
    canResolve: { type: Boolean, default: false },
    canRespond: { type: Boolean, default: false },
    responses: { type: Object, default: () => ({ data: [], previous: null, next: null }) },
});
const page = usePage();
const reply = useForm(`help-response:${page.props.auth?.user?.id ?? 'session'}:${props.assistance.id}`, { message: '' });
const action = useForm({});
const busy = computed(() => action.processing || reply.processing);
const errors = computed(() => [...new Set(Object.values(page.props.errors ?? {}))]);
const status = computed(() => ({ open: t('c_economy.help_detail.status_open', 'Open for help'), matched: t('c_economy.help_detail.status_matched', 'Helper selected'), resolved: t('c_economy.help_detail.status_resolved', 'Completed'), withdrawn: t('c_economy.help_detail.status_withdrawn', 'Withdrawn'), closed: t('c_economy.help_detail.status_closed', 'Closed') }[props.assistance.status] ?? t('c_economy.help_detail.status_recorded', 'Recorded')));
const responseStatus = value => ({ offered: t('c_economy.help_detail.resp_offered', 'Offer of help'), pending: t('c_economy.help_detail.resp_offered', 'Offer of help'), accepted: t('c_economy.help_detail.resp_accepted', 'Selected helper'), selected: t('c_economy.help_detail.resp_accepted', 'Selected helper'), withdrawn: t('c_economy.help_detail.resp_withdrawn', 'Offer withdrawn'), declined: t('c_economy.help_detail.resp_declined', 'Not selected') }[value] ?? t('c_economy.help_detail.resp_recorded', 'Response recorded'));
function change(kind, response = null) {
    if (busy.value) return;
    const base = `/economy/help/${props.assistance.id}`;
    action.post(response ? `${base}/responses/${response.id}/${kind}` : `${base}/${kind}`, { preserveScroll: true });
}
function respond() {
    if (busy.value || !props.canRespond) return;
    reply.post(`/economy/help/${props.assistance.id}/responses`, { preserveScroll: true, onSuccess: () => reply.reset() });
}
</script>

<template>
    <PageScaffold :surface="surface" :title="assistance.title">
        <template #intro>{{ status }}</template>
        <WorkTradeNav active="help" :back-href="isOwner ? '/economy/help?tab=mine' : '/economy/help'" :back-label="isOwner ? t('c_economy.help_detail.my_requests', 'My requests') : t('c_economy.help_detail.requests_for_help', 'Requests for help')" />
        <Banner v-if="page.props.flash?.status" tone="info" role="status">{{ page.props.flash.status }}</Banner>
        <Banner v-if="errors.length" tone="emergency" role="alert"><p v-for="error in errors" :key="error">{{ error }}</p></Banner>
        <p v-if="busy" role="status">{{ t('c_economy.help_detail.saving', 'Saving…') }}</p>
        <Banner v-if="participationNotice" tone="info">{{ participationNotice }} <Link href="/economy/wallet" class="prose-link">{{ t('c_economy.help_detail.open_my_wallet', 'Open my wallet') }}</Link></Banner>
        <Card as="section" :title="t('c_economy.help_detail.the_request', 'The request')">
            <p class="help-note">{{ assistance.privacy === 'public' ? t('c_economy.help_detail.vis_public', 'Public request') : assistance.privacy === 'private' ? t('c_economy.help_detail.vis_private', 'Private request') : t('c_economy.help_detail.vis_restricted', 'Restricted request') }}<template v-if="assistance.created_at"> · {{ formatWhen(assistance.created_at) }}</template></p>
            <p class="help-body">{{ assistance.need }}</p>
            <p v-if="canPublish" class="help-note">{{ t('c_economy.help_detail.publish_note', 'This draft is visible only to you. Publishing makes its title and description visible in the public market so people can offer help.') }}</p>
            <p v-if="canResolve" class="help-note">{{ t('c_economy.help_detail.resolve_note', 'Mark completed when the help has been provided. This records the outcome; it does not transfer money or sign an agreement.') }}</p>
            <div class="help-links">
                <Btn v-if="canPublish" :disabled="busy" @click="change('publish')">{{ t('c_economy.help_detail.publish_publicly', 'Publish request publicly') }}</Btn>
                <Btn v-if="canResolve" :disabled="busy" @click="change('resolve')">{{ t('c_economy.help_detail.mark_completed', 'Mark completed') }}</Btn>
                <Btn v-if="canWithdraw" variant="secondary" :disabled="busy" @click="change('withdraw')">{{ t('c_economy.help_detail.withdraw_request', 'Withdraw request') }}</Btn>
            </div>
        </Card>
        <Card v-if="canRespond" as="section" :title="t('c_economy.help_detail.offer_to_help', 'Offer to help')">
            <form @submit.prevent="respond">
                <Field :label="t('c_economy.help_detail.offer_label', 'Your offer of help')" required :error="reply.errors.message" :hint="t('c_economy.help_detail.offer_hint', 'Describe what you can do and when. Only you and the requester can read this reply.')">
                    <template #control="{ id, describedBy, invalid }"><textarea :id="id" v-model="reply.message" rows="4" maxlength="5000" required :aria-describedby="describedBy" :aria-invalid="invalid" /></template>
                </Field>
                <Btn type="submit" :disabled="busy">{{ reply.processing ? t('c_economy.help_detail.sending', 'Sending…') : t('c_economy.help_detail.send_offer', 'Send offer of help') }}</Btn>
            </form>
        </Card>
        <section aria-labelledby="help-responses-title">
            <h2 id="help-responses-title">{{ isOwner ? t('c_economy.help_detail.offers_of_help', 'Offers of help') : t('c_economy.help_detail.my_response', 'My response') }}</h2>
            <p v-if="!responses.data.length">{{ isOwner ? t('c_economy.help_detail.no_responses', 'No responses on this page.') : t('c_economy.help_detail.no_response_you', 'You have no response on this page.') }}</p>
            <Card v-for="response in responses.data" :key="response.id" as="article" class="help-card">
                <h3>{{ responseStatus(response.status) }}</h3>
                <p v-if="response.created_at" class="help-note">{{ formatWhen(response.created_at) }}</p>
                <p class="help-body">{{ response.message }}</p>
                <p v-if="response.canAccept" class="help-note">{{ t('c_economy.help_detail.accept_note', 'Selecting this offer closes the request to other helpers until it is completed or the selected helper withdraws.') }}</p>
                <div class="help-links">
                    <Btn v-if="response.canAccept" :disabled="busy" @click="change('accept', response)">{{ t('c_economy.help_detail.choose_helper', 'Choose this helper') }}</Btn>
                    <Btn v-if="response.canWithdraw" variant="secondary" :disabled="busy" @click="change('withdraw', response)">{{ t('c_economy.help_detail.withdraw_my_offer', 'Withdraw my offer') }}</Btn>
                </div>
            </Card>
            <nav class="help-links" :aria-label="t('c_economy.help_detail.response_pages', 'Response pages')">
                <Link v-if="responses.previous" :href="responses.previous" rel="prev">{{ t('c_economy.help_detail.previous_responses', 'Previous responses') }}</Link>
                <Link v-if="responses.next" :href="responses.next" rel="next">{{ t('c_economy.help_detail.next_responses', 'Next responses') }}</Link>
            </nav>
        </section>
    </PageScaffold>
</template>

<style scoped>
.help-body { white-space: pre-wrap; overflow-wrap: anywhere; }
.help-note { color: var(--gov-text-muted); font-size: .875rem; }
.help-links { display: flex; flex-wrap: wrap; gap: .5rem 1rem; margin-block: .75rem; }
.help-links a { display: inline-flex; align-items: center; min-block-size: 44px; padding: .4rem .6rem; }
.help-card { margin-block: 1rem; }
a:focus-visible { outline: 3px solid var(--gov-accent); outline-offset: 3px; }
</style>
