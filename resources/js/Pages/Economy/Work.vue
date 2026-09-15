<script setup>
import { computed, ref, watch } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import WorkTradeNav from '@/Components/Economy/WorkTradeNav.vue';
import Card from '@/Components/Ui/Card.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Field from '@/Components/Ui/Field.vue';
import { formatWhen } from '@/lib/money.js';

defineOptions({ layout: AppShellV2 });
const { t } = useI18n();
const props = defineProps({
    surface: { type: Object, default: null },
    tab: { type: String, default: 'applications' },
    organizations: { type: Object, default: () => ({ data: [], previous: null, next: null }) },
    organization: { type: Object, default: null },
    postings: { type: Object, default: () => ({ data: [], previous: null, next: null }) },
    posting: { type: Object, default: null },
    applications: { type: Object, default: () => ({ data: [], previous: null, next: null }) },
});
const page = usePage();
const job = useForm(`work-post:${page.props.auth?.user?.id ?? 'session'}:${props.organization?.id ?? 'none'}`, { title: '', terms: '' });
const offer = useForm({ offer_terms: '' });
const action = useForm({});
const offerFor = ref(null);
const busy = computed(() => action.processing || offer.processing || job.processing);
const errors = computed(() => [...new Set(Object.values(page.props.errors ?? {}))]);
watch(() => [props.organization?.id, props.posting?.id, props.tab], () => {
    offer.reset(); offerFor.value = null;
});
function publish() {
    if (busy.value || !props.organization) return;
    job.post(`/economy/work/organizations/${props.organization.id}/postings`, {
        preserveScroll: true, onSuccess: () => job.reset(),
    });
}
function startOffer(application) {
    offer.clearErrors();
    offer.offer_terms = props.posting?.terms ?? '';
    offerFor.value = application.id;
}
function sendOffer() {
    if (busy.value || !offerFor.value) return;
    offer.post(`/economy/work/applications/${offerFor.value}/offer`, {
        preserveScroll: true, onSuccess: () => { offerFor.value = null; offer.reset(); },
    });
}
function decide(application, decision) {
    if (busy.value) return;
    action.post(`/economy/work/applications/${application.id}/${decision}`, { preserveScroll: true });
}
function closePosting() {
    if (busy.value || !props.posting) return;
    action.post(`/economy/work/postings/${props.posting.id}/close`, { preserveScroll: true });
}
function statusLabel(application) {
    if (application.status === 'applied') {
        if (application.posting_status !== 'open') return t('c_economy.work.status_posting_closed', 'Posting closed');
        return application.offered_at ? t('c_economy.work.status_offer_ready', 'Offer ready') : t('c_economy.work.status_awaiting_review', 'Awaiting review');
    }
    return { accepted: t('c_economy.work.status_accepted', 'Offer accepted — review the agreement'), declined: t('c_economy.work.status_declined', 'Application declined'), withdrawn: t('c_economy.work.status_withdrawn', 'Application withdrawn') }[application.status] ?? t('c_economy.work.status_recorded', 'Application recorded');
}
</script>

<template>
    <PageScaffold :surface="surface" :title="t('c_economy.work.title', 'My work & hiring')">
        <template #intro>{{ t('c_economy.work.intro', 'Review applications, agree on terms and follow each work agreement through both signatures.') }}</template>
        <WorkTradeNav active="work" back-href="/economy/market?tab=work" :back-label="t('c_economy.work.back_label', 'Find work')" />
        <Banner v-if="page.props.flash?.status" tone="info" role="status">{{ page.props.flash.status }}</Banner>
        <Banner v-if="errors.length" tone="emergency" role="alert"><p v-for="error in errors" :key="error">{{ error }}</p></Banner>
        <p v-if="busy" role="status" class="work-muted">{{ t('c_economy.work.saving', 'Saving…') }}</p>
        <nav class="work-actions work-tabs" :aria-label="t('c_economy.work.workspace_nav', 'Work workspace')">
            <Link href="/economy/work" :aria-current="tab === 'applications' ? 'page' : undefined">{{ t('c_economy.work.my_applications', 'My applications') }}</Link>
            <Link href="/economy/work?tab=hiring" :aria-current="tab === 'hiring' ? 'page' : undefined">{{ t('c_economy.work.hire_for_org', 'Hire for an organization') }}</Link>
        </nav>

        <template v-if="tab === 'hiring'">
            <Card as="section" :title="t('c_economy.work.choose_org', 'Choose an organization')">
                <p class="work-muted">{{ t('c_economy.work.manage_hiring_note', 'You can manage hiring for organizations where you are the current agent.') }}</p>
                <ul class="work-list">
                    <li v-for="org in organizations.data" :key="org.id"><Link :href="org.href" :aria-current="organization?.id === org.id ? 'true' : undefined">{{ org.name }}</Link></li>
                </ul>
                <p v-if="!organizations.data.length">{{ t('c_economy.work.no_orgs', 'No organizations on this page. Hiring requires a current organization agent role.') }}</p>
                <nav class="work-actions" :aria-label="t('c_economy.work.org_pages', 'Organization pages')">
                    <Link v-if="organizations.previous" :href="organizations.previous" rel="prev">{{ t('c_economy.work.previous_orgs', 'Previous organizations') }}</Link>
                    <Link v-if="organizations.next" :href="organizations.next" rel="next">{{ t('c_economy.work.next_orgs', 'Next organizations') }}</Link>
                </nav>
            </Card>
            <template v-if="organization">
                <Card as="section" :title="t('c_economy.work.hiring_for', { name: organization.name })">
                    <Link :href="`/organizations/${organization.id}`">{{ t('c_economy.work.view_organization', 'View organization') }}</Link>
                    <details class="work-compose">
                        <summary>{{ t('c_economy.work.post_opportunity_summary', 'Post a work opportunity') }}</summary>
                        <form @submit.prevent="publish">
                            <Field :label="t('c_economy.work.job_title_label', 'Job title')" required :error="job.errors.title">
                                <template #control="{ id, describedBy, invalid }"><input :id="id" v-model="job.title" class="field-input" :aria-describedby="describedBy" :aria-invalid="invalid" maxlength="160" required /></template>
                            </Field>
                            <Field :label="t('c_economy.work.work_terms_label', 'Work and terms')" required :error="job.errors.terms" :hint="t('c_economy.work.work_terms_hint', 'Include duties, pay and its currency (or say unpaid), schedule and any other conditions. These details appear on the public posting.')">
                                <template #control="{ id, describedBy, invalid }"><textarea :id="id" v-model="job.terms" :aria-describedby="describedBy" :aria-invalid="invalid" rows="5" maxlength="10000" required /></template>
                            </Field>
                            <Btn type="submit" :disabled="busy">{{ job.processing ? t('c_economy.work.publishing', 'Publishing…') : t('c_economy.work.publish_opportunity', 'Publish opportunity') }}</Btn>
                        </form>
                    </details>
                    <h3>{{ t('c_economy.work.work_postings', 'Work postings') }}</h3>
                    <ul class="work-list">
                        <li v-for="item in postings.data" :key="item.id"><Link :href="item.href" :aria-current="posting?.id === item.id ? 'true' : undefined">{{ item.title }}</Link> <span class="work-muted">· {{ item.status === 'open' ? t('c_economy.work.posting_open', 'Open') : item.status === 'filled' ? t('c_economy.work.posting_filled', 'Offer accepted') : t('c_economy.work.posting_closed', 'Closed') }}</span></li>
                    </ul>
                    <p v-if="!postings.data.length">{{ t('c_economy.work.no_postings', 'No postings on this page.') }}</p>
                    <nav class="work-actions" :aria-label="t('c_economy.work.posting_pages', 'Posting pages')">
                        <Link v-if="postings.previous" :href="postings.previous" rel="prev">{{ t('c_economy.work.previous_postings', 'Previous postings') }}</Link>
                        <Link v-if="postings.next" :href="postings.next" rel="next">{{ t('c_economy.work.next_postings', 'Next postings') }}</Link>
                    </nav>
                </Card>
                <Card v-if="posting" as="section" :title="posting.title">
                    <p class="work-terms">{{ posting.terms }}</p>
                    <div class="work-actions">
                        <Link :href="`/economy/requests/${posting.id}`">{{ t('c_economy.work.view_public_posting', 'View public posting') }}</Link>
                        <Btn v-if="posting.status === 'open'" variant="secondary" :disabled="busy" @click="closePosting">{{ t('c_economy.work.close_posting', 'Close posting') }}</Btn>
                    </div>
                    <p v-if="posting.status === 'open'" class="work-muted">{{ t('c_economy.work.closing_note', 'Closing stops new applications and acceptance of outstanding offers.') }}</p>
                </Card>
                <p v-else class="work-muted">{{ t('c_economy.work.choose_posting', 'Choose a posting to review its applications.') }}</p>
            </template>
        </template>

        <section v-if="tab === 'applications' || posting" aria-labelledby="work-applications-title">
            <h2 id="work-applications-title">{{ tab === 'hiring' ? t('c_economy.work.private_applications', 'Private applications') : t('c_economy.work.my_applications', 'My applications') }}</h2>
            <p v-if="tab === 'hiring'" class="work-muted">{{ t('c_economy.work.applications_hint', 'Review the applicant\'s note and offer complete terms. The applicant must accept before you can countersign the agreement.') }}</p>
            <p v-if="!applications.data.length">{{ t('c_economy.work.no_applications', 'No applications on this page.') }}</p>
            <Card v-for="application in applications.data" :key="application.id" as="article" class="work-application">
                <h3>{{ application.title }}</h3>
                <p v-if="application.organization_name">{{ application.organization_name }}</p>
                <p class="work-muted">{{ statusLabel(application) }}<template v-if="application.created_at"> {{ t('c_economy.work.applied_when', { when: formatWhen(application.created_at) }) }}</template></p>
                <p v-if="application.note" class="work-terms">{{ application.note }}</p>
                <p v-else class="work-muted">{{ t('c_economy.work.no_note', 'No application note provided.') }}</p>
                <template v-if="application.offered_at">
                    <h4>{{ t('c_economy.work.offered_terms', 'Offered terms') }}</h4>
                    <p class="work-terms">{{ application.offer_terms }}</p>
                </template>
                <p v-if="application.canAccept" class="work-muted">{{ t('c_economy.work.accepting_note', 'Accepting signs these terms as the worker. Work becomes active after the organization countersigns.') }}</p>
                <div class="work-actions">
                    <Btn v-if="application.canAccept" :disabled="busy" @click="decide(application, 'accept')">{{ t('c_economy.work.accept_sign', 'Accept offer and sign as worker') }}</Btn>
                    <Btn v-if="application.canWithdraw" variant="secondary" :disabled="busy" @click="decide(application, 'withdraw')">{{ t('c_economy.work.withdraw_application', 'Withdraw application') }}</Btn>
                    <Btn v-if="application.canOffer && offerFor !== application.id" :disabled="busy" @click="startOffer(application)">{{ t('c_economy.work.prepare_offer', 'Prepare offer') }}</Btn>
                    <Btn v-if="application.canDecline" variant="secondary" :disabled="busy" @click="decide(application, 'decline')">{{ t('c_economy.work.decline_application', 'Decline application') }}</Btn>
                    <Link v-if="application.agreementHref" :href="application.agreementHref">{{ tab === 'hiring' ? t('c_economy.work.review_countersign', 'Review and countersign agreement') : t('c_economy.work.review_agreement', 'Review work agreement') }}</Link>
                </div>
                <form v-if="offerFor === application.id && application.canOffer" class="work-compose" @submit.prevent="sendOffer">
                    <Field :label="t('c_economy.work.complete_offer_label', 'Complete offer terms')" required :error="offer.errors.offer_terms" :hint="t('c_economy.work.complete_offer_hint', 'Include pay, currency, duties and schedule. Sent terms cannot be edited; the worker chooses whether to accept them.')">
                        <template #control="{ id, describedBy, invalid }"><textarea :id="id" v-model="offer.offer_terms" :aria-describedby="describedBy" :aria-invalid="invalid" rows="6" maxlength="10000" required /></template>
                    </Field>
                    <div class="work-actions"><Btn type="submit" :disabled="busy">{{ offer.processing ? t('c_economy.work.sending', 'Sending…') : t('c_economy.work.send_offer', 'Send offer') }}</Btn><Btn variant="secondary" :disabled="busy" @click="offerFor = null">{{ t('c_economy.work.cancel', 'Cancel') }}</Btn></div>
                </form>
            </Card>
            <nav class="work-actions" :aria-label="t('c_economy.work.application_pages', 'Application pages')">
                <Link v-if="applications.previous" :href="applications.previous" rel="prev">{{ t('c_economy.work.previous_applications', 'Previous applications') }}</Link>
                <Link v-if="applications.next" :href="applications.next" rel="next">{{ t('c_economy.work.next_applications', 'Next applications') }}</Link>
            </nav>
        </section>
    </PageScaffold>
</template>

<style scoped>
.work-actions { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem 1rem; margin-block: .75rem; }
.work-actions a, .work-list a { display: inline-flex; align-items: center; min-block-size: 44px; padding: .35rem .5rem; }
.work-tabs a[aria-current], .work-list a[aria-current] { background: var(--gov-surface-subtle); font-weight: 600; box-shadow: inset 0 -2px var(--gov-accent); }
.work-list { list-style: none; padding: 0; }
.work-muted { color: var(--gov-fg-muted); font-size: .875rem; }
.work-terms { white-space: pre-wrap; overflow-wrap: anywhere; }
.work-compose { margin-block: 1rem; }
.work-compose summary { min-block-size: 44px; cursor: pointer; padding-block: .75rem; font-weight: 600; }
.work-application { margin-block: 1rem; }
a:focus-visible, summary:focus-visible { outline: 3px solid var(--gov-accent); outline-offset: 3px; }
</style>
