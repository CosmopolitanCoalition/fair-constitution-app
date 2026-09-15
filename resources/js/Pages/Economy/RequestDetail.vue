<script setup>
import { computed } from 'vue';
import { useForm, usePage, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Field from '@/Components/Ui/Field.vue';
import WorkTradeNav from '@/Components/Economy/WorkTradeNav.vue';
import { formatMoney, formatCount, formatWhen as formatWhenRaw } from '@/lib/money.js';

defineOptions({ layout: AppShellV2 });
const { t, locale } = useI18n();
const formatWhen = (iso) => formatWhenRaw(iso, locale.value);
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
        <template #intro>{{ t('c_economy.request_detail.intro', 'Review the work and send a private application to the organization.') }}</template>
        <WorkTradeNav active="market" back-href="/economy/market?tab=work" :back-label="t('c_economy.request_detail.back_label', 'Work opportunities')" />
        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <Card as="section" :title="t('c_economy.request_detail.the_work', 'The work')">
            <div class="work-heading">
                <Link v-if="posting.org_id" :href="posting.org_href ?? `/organizations/${posting.org_id}`">{{ posting.org_name }}</Link>
                <span v-else>{{ posting.org_name }}</span>
                <strong>{{ posting.rate ? formatMoney(posting.rate, currency) : t('c_economy.request_detail.rate_on_agreement', 'Rate on agreement') }}</strong>
            </div>
            <p class="work-meta">{{ posting.status === 'open' ? t('c_economy.request_detail.hiring_now', 'Hiring now') : t('c_economy.request_detail.closed', 'Closed') }} · {{ t('c_economy.request_detail.applications_meta', { count: formatCount(posting.applications) }) }} · {{ t('c_economy.request_detail.posted_meta', { when: formatWhen(posting.at) }) }}</p>
            <p class="work-terms">{{ posting.terms }}</p>
        </Card>

        <Card v-if="can_apply" as="section" :title="t('c_economy.request_detail.apply_title', 'Apply for this work')">
            <form @submit.prevent="submitApply">
                <Field :label="t('c_economy.request_detail.note_label', 'A note to the organization (optional)')" :error="apply.errors.note">
                    <template #control="{ id, describedBy }">
                        <textarea :id="id" v-model="apply.note" :aria-describedby="describedBy" rows="3" maxlength="500" />
                    </template>
                </Field>
                <p class="work-meta">{{ t('c_economy.request_detail.apply_note', 'Only you and the organization can read your application. Applying does not create a contract.') }}</p>
                <Btn type="submit" :disabled="apply.processing">{{ apply.processing ? t('c_economy.request_detail.applying', 'Applying…') : t('c_economy.request_detail.send_application', 'Send application') }}</Btn>
            </form>
        </Card>
        <Card v-else-if="has_applied" as="section" :title="t('c_economy.request_detail.recorded_title', 'Your application is recorded')">
            <p>{{ t('c_economy.request_detail.check_prefix', 'Check') }} <Link href="/economy/work">{{ t('c_economy.request_detail.my_applications', 'My applications') }}</Link> {{ t('c_economy.request_detail.recorded_after', 'for its status, any offered terms and the resulting agreement.') }}</p>
        </Card>
        <Card v-else-if="posting.status !== 'open'" as="section" :title="t('c_economy.request_detail.closed_title', 'This posting is closed')">
            <p>{{ t('c_economy.request_detail.closed_body', 'This organization is no longer taking applications for this work.') }}</p>
        </Card>
        <Card v-else as="section" :title="t('c_economy.request_detail.before_apply_title', 'Before you apply')">
            <p>{{ t('c_economy.request_detail.before_apply_prefix', 'You need an account in this world\'s currency to apply. Check') }} <Link href="/economy/wallet">{{ t('c_economy.request_detail.my_wallet', 'My wallet') }}</Link>.</p>
        </Card>

        <details class="work-about">
            <summary>{{ t('c_economy.request_detail.after_applying_summary', 'What happens after applying?') }}</summary>
            <p>{{ t('c_economy.request_detail.after_applying_body', 'The organization reviews your application and offers terms. You accept and sign as the worker, then the organization countersigns to activate the work agreement. The terms remain private.') }}</p>
            <p>{{ t('c_economy.request_detail.codetermination_note', { first: formatCount(codetermination.first_seat_at), parity: formatCount(codetermination.parity_at) }) }}</p>
            <p>{{ t('c_economy.request_detail.headcount_note', { count: formatCount(codetermination.headcount) }) }}</p>
            <Link v-if="posting.org_id" :href="`/organizations/co-determination?org=${posting.org_id}`">{{ t('c_economy.request_detail.view_worker_rep', 'View this organization\'s worker representation') }}</Link>
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
