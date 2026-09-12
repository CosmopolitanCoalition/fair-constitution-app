<script setup>
import { computed } from 'vue';
import { useForm, usePage, Link } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Field from '@/Components/Ui/Field.vue';
import WorkTradeNav from '@/Components/Economy/WorkTradeNav.vue';
import { formatMoney, formatCount, formatWhen } from '@/lib/money.js';

defineOptions({ layout: AppShellV2 });
const props = defineProps({
    currency: { type: Object, default: null },
    posting: { type: Object, required: true },
    codetermination: { type: Object, required: true },
    can_apply: { type: Boolean, default: false },
    has_applied: { type: Boolean, default: false },
});
const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);
const apply = useForm({ note: '' });
function submitApply() {
    apply.post(`/economy/requests/${props.posting.id}/apply`, {
        preserveScroll: true,
        onSuccess: () => apply.reset(),
    });
}
</script>

<template>
    <PageScaffold :title="posting.title">
        <template #intro>Review the work and send a private application to the organization.</template>
        <WorkTradeNav active="market" back-href="/economy/market?tab=work" back-label="Work opportunities" />
        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <Card as="section" title="The work">
            <div class="work-heading">
                <Link v-if="posting.org_id" :href="posting.org_href ?? `/organizations/${posting.org_id}`">{{ posting.org_name }}</Link>
                <span v-else>{{ posting.org_name }}</span>
                <strong>{{ posting.rate ? formatMoney(posting.rate, currency) : 'Rate on agreement' }}</strong>
            </div>
            <p class="work-meta">{{ posting.status === 'open' ? 'Hiring now' : 'Closed' }} · {{ formatCount(posting.applications) }} applications · Posted {{ formatWhen(posting.at) }}</p>
            <p class="work-terms">{{ posting.terms }}</p>
        </Card>

        <Card v-if="can_apply" as="section" title="Apply for this work">
            <form @submit.prevent="submitApply">
                <Field label="A note to the organization (optional)" :error="apply.errors.note">
                    <template #control="{ id, describedBy }">
                        <textarea :id="id" v-model="apply.note" :aria-describedby="describedBy" rows="3" maxlength="500" />
                    </template>
                </Field>
                <p class="work-meta">Only you and the organization can read your application. Applying does not create a contract.</p>
                <Btn type="submit" :disabled="apply.processing">{{ apply.processing ? 'Applying…' : 'Send application' }}</Btn>
            </form>
        </Card>
        <Card v-else-if="has_applied" as="section" title="Application sent">
            <p>The organization can review your application. Recorded work agreements appear in <Link href="/economy/agreements">My agreements</Link>.</p>
        </Card>
        <Card v-else-if="posting.status !== 'open'" as="section" title="This posting is closed">
            <p>This organization is no longer taking applications for this work.</p>
        </Card>
        <Card v-else as="section" title="Before you apply">
            <p>You need an account in this world's currency to apply. Check <Link href="/economy/wallet">My wallet</Link>.</p>
        </Card>

        <details class="work-about">
            <summary>What happens after applying?</summary>
            <p>The organization reviews your application. An accepted hire records the work agreement with both parties' consent. The terms remain private.</p>
            <p>Ongoing work also counts toward worker representation. This organization's current rules provide the first worker seat at {{ formatCount(codetermination.first_seat_at) }} workers and equal worker and owner representation at {{ formatCount(codetermination.parity_at) }} workers.</p>
            <p>{{ formatCount(codetermination.headcount) }} active workers are recorded here.</p>
            <Link v-if="posting.org_id" :href="`/organizations/co-determination?org=${posting.org_id}`">View this organization's worker representation</Link>
        </details>
    </PageScaffold>
</template>

<style scoped>
.work-heading { display: flex; flex-wrap: wrap; justify-content: space-between; gap: .75rem; }
.work-meta { color: var(--gov-fg-muted); font-size: .875rem; }
.work-terms { white-space: pre-wrap; }
.work-about { border-block-start: 1px solid var(--gov-border); padding-block-start: .75rem; }
.work-about summary { cursor: pointer; display: flex; align-items: center; min-block-size: 44px; font-weight: 600; }
.work-about p { line-height: 1.6; }
.work-about summary:focus-visible, a:focus-visible { outline: 3px solid var(--gov-accent); outline-offset: 3px; }
</style>
