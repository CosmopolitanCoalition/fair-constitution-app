<script setup>
import { computed, ref, watch } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import WorkTradeNav from '@/Components/Economy/WorkTradeNav.vue';
import Card from '@/Components/Ui/Card.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Field from '@/Components/Ui/Field.vue';
import { formatWhen } from '@/lib/money.js';

defineOptions({ layout: AppShellV2 });
const props = defineProps({
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
        if (application.posting_status !== 'open') return 'Posting closed';
        return application.offered_at ? 'Offer ready' : 'Awaiting review';
    }
    return { accepted: 'Offer accepted — review the agreement', declined: 'Application declined', withdrawn: 'Application withdrawn' }[application.status] ?? 'Application recorded';
}
</script>

<template>
    <PageScaffold title="My work & hiring">
        <template #intro>Review applications, agree on terms and follow each work agreement through both signatures.</template>
        <WorkTradeNav active="work" back-href="/economy/market?tab=work" back-label="Find work" />
        <Banner v-if="page.props.flash?.status" tone="info" role="status">{{ page.props.flash.status }}</Banner>
        <Banner v-if="errors.length" tone="emergency" role="alert"><p v-for="error in errors" :key="error">{{ error }}</p></Banner>
        <p v-if="busy" role="status" class="work-muted">Saving…</p>
        <nav class="work-actions work-tabs" aria-label="Work workspace">
            <Link href="/economy/work" :aria-current="tab === 'applications' ? 'page' : undefined">My applications</Link>
            <Link href="/economy/work?tab=hiring" :aria-current="tab === 'hiring' ? 'page' : undefined">Hire for an organization</Link>
        </nav>

        <template v-if="tab === 'hiring'">
            <Card as="section" title="Choose an organization">
                <p class="work-muted">You can manage hiring for organizations where you are the current agent.</p>
                <ul class="work-list">
                    <li v-for="org in organizations.data" :key="org.id"><Link :href="org.href" :aria-current="organization?.id === org.id ? 'true' : undefined">{{ org.name }}</Link></li>
                </ul>
                <p v-if="!organizations.data.length">No organizations on this page. Hiring requires a current organization agent role.</p>
                <nav class="work-actions" aria-label="Organization pages">
                    <Link v-if="organizations.previous" :href="organizations.previous" rel="prev">Previous organizations</Link>
                    <Link v-if="organizations.next" :href="organizations.next" rel="next">Next organizations</Link>
                </nav>
            </Card>
            <template v-if="organization">
                <Card as="section" :title="`Hiring for ${organization.name}`">
                    <Link :href="`/organizations/${organization.id}`">View organization</Link>
                    <details class="work-compose">
                        <summary>Post a work opportunity</summary>
                        <form @submit.prevent="publish">
                            <Field label="Job title" required :error="job.errors.title">
                                <template #control="{ id, describedBy, invalid }"><input :id="id" v-model="job.title" class="field-input" :aria-describedby="describedBy" :aria-invalid="invalid" maxlength="160" required /></template>
                            </Field>
                            <Field label="Work and terms" required :error="job.errors.terms" hint="Include duties, pay and its currency (or say unpaid), schedule and any other conditions. These details appear on the public posting.">
                                <template #control="{ id, describedBy, invalid }"><textarea :id="id" v-model="job.terms" :aria-describedby="describedBy" :aria-invalid="invalid" rows="5" maxlength="10000" required /></template>
                            </Field>
                            <Btn type="submit" :disabled="busy">{{ job.processing ? 'Publishing…' : 'Publish opportunity' }}</Btn>
                        </form>
                    </details>
                    <h3>Work postings</h3>
                    <ul class="work-list">
                        <li v-for="item in postings.data" :key="item.id"><Link :href="item.href" :aria-current="posting?.id === item.id ? 'true' : undefined">{{ item.title }}</Link> <span class="work-muted">· {{ item.status === 'open' ? 'Open' : item.status === 'filled' ? 'Offer accepted' : 'Closed' }}</span></li>
                    </ul>
                    <p v-if="!postings.data.length">No postings on this page.</p>
                    <nav class="work-actions" aria-label="Posting pages">
                        <Link v-if="postings.previous" :href="postings.previous" rel="prev">Previous postings</Link>
                        <Link v-if="postings.next" :href="postings.next" rel="next">Next postings</Link>
                    </nav>
                </Card>
                <Card v-if="posting" as="section" :title="posting.title">
                    <p class="work-terms">{{ posting.terms }}</p>
                    <div class="work-actions">
                        <Link :href="`/economy/requests/${posting.id}`">View public posting</Link>
                        <Btn v-if="posting.status === 'open'" variant="secondary" :disabled="busy" @click="closePosting">Close posting</Btn>
                    </div>
                    <p v-if="posting.status === 'open'" class="work-muted">Closing stops new applications and acceptance of outstanding offers.</p>
                </Card>
                <p v-else class="work-muted">Choose a posting to review its applications.</p>
            </template>
        </template>

        <section v-if="tab === 'applications' || posting" aria-labelledby="work-applications-title">
            <h2 id="work-applications-title">{{ tab === 'hiring' ? 'Private applications' : 'My applications' }}</h2>
            <p v-if="tab === 'hiring'" class="work-muted">Review the applicant's note and offer complete terms. The applicant must accept before you can countersign the agreement.</p>
            <p v-if="!applications.data.length">No applications on this page.</p>
            <Card v-for="application in applications.data" :key="application.id" as="article" class="work-application">
                <h3>{{ application.title }}</h3>
                <p v-if="application.organization_name">{{ application.organization_name }}</p>
                <p class="work-muted">{{ statusLabel(application) }}<template v-if="application.created_at"> · Applied {{ formatWhen(application.created_at) }}</template></p>
                <p v-if="application.note" class="work-terms">{{ application.note }}</p>
                <p v-else class="work-muted">No application note provided.</p>
                <template v-if="application.offered_at">
                    <h4>Offered terms</h4>
                    <p class="work-terms">{{ application.offer_terms }}</p>
                </template>
                <p v-if="application.canAccept" class="work-muted">Accepting signs these terms as the worker. Work becomes active after the organization countersigns.</p>
                <div class="work-actions">
                    <Btn v-if="application.canAccept" :disabled="busy" @click="decide(application, 'accept')">Accept offer and sign as worker</Btn>
                    <Btn v-if="application.canWithdraw" variant="secondary" :disabled="busy" @click="decide(application, 'withdraw')">Withdraw application</Btn>
                    <Btn v-if="application.canOffer && offerFor !== application.id" :disabled="busy" @click="startOffer(application)">Prepare offer</Btn>
                    <Btn v-if="application.canDecline" variant="secondary" :disabled="busy" @click="decide(application, 'decline')">Decline application</Btn>
                    <Link v-if="application.agreementHref" :href="application.agreementHref">{{ tab === 'hiring' ? 'Review and countersign agreement' : 'Review work agreement' }}</Link>
                </div>
                <form v-if="offerFor === application.id && application.canOffer" class="work-compose" @submit.prevent="sendOffer">
                    <Field label="Complete offer terms" required :error="offer.errors.offer_terms" hint="Include pay, currency, duties and schedule. Sent terms cannot be edited; the worker chooses whether to accept them.">
                        <template #control="{ id, describedBy, invalid }"><textarea :id="id" v-model="offer.offer_terms" :aria-describedby="describedBy" :aria-invalid="invalid" rows="6" maxlength="10000" required /></template>
                    </Field>
                    <div class="work-actions"><Btn type="submit" :disabled="busy">{{ offer.processing ? 'Sending…' : 'Send offer' }}</Btn><Btn variant="secondary" :disabled="busy" @click="offerFor = null">Cancel</Btn></div>
                </form>
            </Card>
            <nav class="work-actions" aria-label="Application pages">
                <Link v-if="applications.previous" :href="applications.previous" rel="prev">Previous applications</Link>
                <Link v-if="applications.next" :href="applications.next" rel="next">Next applications</Link>
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
