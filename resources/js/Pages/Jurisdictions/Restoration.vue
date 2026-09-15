<script setup>
/**
 * Jurisdictions/Restoration — "Rebuilding a lost government" (design
 * contract: mockups/v3/jurisdictions/restoration.html).
 *
 * Art. VI §2–3: when a fair government is countermanded, captured, or
 * destroyed, restoration activates — evidence-based and judicially
 * reviewable, never a unilateral switch — and rebuilding elections cascade
 * down three tiers: constituent jurisdictions first, then the encompassing
 * jurisdiction, then individuals organizing themselves. This page is the
 * standing drill (teaching structure) plus a real read of restoration
 * events; a world with none says so plainly.
 */
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import HistoryPager from '@/Components/Ui/HistoryPager.vue';
import Icon from '@/Components/Ui/Icon.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { plainState } from '@/lib/plain.js';

defineOptions({ layout: AppShellV2 });

const { t } = useI18n();

const props = defineProps({
    surface: { type: Object, required: true },
    events: { type: Array, default: () => [] },
    conditions: { type: Array, default: () => [] },
    viewer: { type: Object, default: () => null },
    pagination: { type: Object, default: () => ({ previous: null, next: null, first: '/jurisdictions/restoration' }) },
});

// Reactive: HistoryPager reloads the events prop with preserveState, so setup
// never re-runs — a plain const would leave the banner, condition badges and
// tier cascade showing the first page's events after paging.
const active = computed(() => props.events.filter((e) => ['declared', 'confirmed', 'restoring'].includes(e.status)));

const declareJurisdiction = ref('');
const declareCondition = ref('countermanded');
const declareReviewCase = ref('');
const busyId = ref('');
const error = ref('');
const notice = ref('');

function act(id, url, data) {
    if (busyId.value) return;
    router.post(url, data || {}, {
        preserveScroll: true,
        onStart: () => { busyId.value = id; error.value = ''; notice.value = ''; },
        onFinish: () => { busyId.value = ''; },
        onError: (errors) => { error.value = Object.values(errors)[0] || t('c_jurisdictions.restoration.act_error', 'That action could not be completed. Please retry.'); },
        onSuccess: () => { notice.value = t('c_jurisdictions.restoration.act_done', 'Done. The event list below reflects the change.'); },
    });
}
function declare() {
    act('declare', '/jurisdictions/restoration/declare', {
        jurisdiction_id: declareJurisdiction.value.trim(),
        condition: declareCondition.value,
        review_case_id: declareReviewCase.value.trim() || null,
    });
}
const confirm = (e) => act(e.id, `/jurisdictions/restoration/${e.id}/confirm`);
const enterTier = (e) => act(e.id, `/jurisdictions/restoration/${e.id}/tier`, { tier: (e.tier || 0) + 1 });
const complete = (e) => act(e.id, `/jurisdictions/restoration/${e.id}/complete`);
const abandon = (e) => act(e.id, `/jurisdictions/restoration/${e.id}/abandon`);
const isTerminal = (e) => ['restored', 'abandoned'].includes(e.status);

const conditionCopy = {
    countermanded: {
        icon: 'alert-triangle',
        title: t('c_jurisdictions.restoration.cond_countermanded_title', 'Countermanded'),
        body: t('c_jurisdictions.restoration.cond_countermanded_body', 'Lawful acts are blocked or replaced by an authority with no constitutional basis — the government is overridden contrary to the constitution.'),
    },
    captured: {
        icon: 'shield',
        title: t('c_jurisdictions.restoration.cond_captured_title', 'Captured or disabled'),
        body: t('c_jurisdictions.restoration.cond_captured_body', 'The institutions exist but no longer answer to their constituents — seized, coerced, or procedurally locked.'),
    },
    destroyed: {
        icon: 'x',
        title: t('c_jurisdictions.restoration.cond_destroyed_title', 'Destroyed'),
        body: t('c_jurisdictions.restoration.cond_destroyed_body', 'The institutions no longer exist — disaster, war, or collapse.'),
    },
};

const conditionMet = (condition) => active.value.some((e) => e.condition === condition);

const tiers = [
    { n: 1, label: t('c_jurisdictions.restoration.tier1_label', 'Constituent jurisdictions elect'), actor: t('c_jurisdictions.restoration.tier1_actor', 'Constituent legislatures and populations') },
    { n: 2, label: t('c_jurisdictions.restoration.tier2_label', 'The encompassing jurisdiction calls elections'), actor: t('c_jurisdictions.restoration.tier2_actor', 'Encompassing legislature and election board') },
    { n: 3, label: t('c_jurisdictions.restoration.tier3_label', 'Individuals self-organize'), actor: t('c_jurisdictions.restoration.tier3_actor', 'Individuals — re-entering bootstrap from a dormant boundary') },
];

const tierState = (n) => {
    const activeTiers = active.value.map((e) => e.tier).filter((t) => t > 0);
    if (activeTiers.length === 0) return 'standby';
    const current = Math.max(...activeTiers);
    return n === current ? 'active' : n < current ? 'bypassed' : 'standby';
};
</script>

<template>
    <PageScaffold :surface="surface" :title="t('c_jurisdictions.restoration.title', 'Rebuilding a lost government')">
        <template #intro>
            {{ t('c_jurisdictions.restoration.intro', 'When a fair government is countermanded, captured, or destroyed, restoration activates — and rebuilding elections cascade down three tiers, each activating only when the one above cannot act. Detection triggers a cascade of rebuilding elections. This page is the standing drill.') }}
        </template>

        <Banner v-if="active.length === 0" tone="info" role="status">
            <strong>{{ t('c_jurisdictions.restoration.dormant_strong', 'Restoration mode dormant — no activation condition detected.') }}</strong>
            {{ t('c_jurisdictions.restoration.dormant_rest', 'The walkthrough below is the standing drill; a declared and judicially confirmed event arms it.') }}
        </Banner>
        <Banner v-else tone="emergency" role="alert">
            <strong>{{ t('c_jurisdictions.restoration.active_strong', 'Restoration active.') }}</strong>
            {{ t('c_jurisdictions.restoration.active_body', { count: active.length }) }}
        </Banner>

        <Card as="section">
            <template #title>{{ t('c_jurisdictions.restoration.conditions_title', 'Activation conditions') }}</template>
            <p>
                {{ t('c_jurisdictions.restoration.conditions_body', 'Detection is evidence-based and judicially reviewable — never a unilateral switch. A declared condition activates nothing until a court confirms it.') }}
            </p>
            <div class="grid-2">
                <div v-for="c in conditions" :key="c">
                    <h4><Icon :name="conditionCopy[c]?.icon || 'alert-triangle'" size="sm" /> {{ conditionCopy[c]?.title || plainState(c) }}</h4>
                    <p>{{ conditionCopy[c]?.body }}</p>
                    <StatusBadge v-if="conditionMet(c)" tone="danger">{{ t('c_jurisdictions.restoration.condition_met', 'Condition met') }}</StatusBadge>
                    <StatusBadge v-else tone="neutral">{{ t('c_jurisdictions.restoration.not_detected', 'Not detected') }}</StatusBadge>
                </div>
            </div>
        </Card>

        <Card as="section">
            <template #title>{{ t('c_jurisdictions.restoration.cascade_title', 'The restoration cascade') }}</template>
            <ol class="flow-steps">
                <li v-for="tier in tiers" :key="tier.n" :aria-current="tierState(tier.n) === 'active' ? 'step' : undefined">
                    <strong>{{ t('c_jurisdictions.restoration.tier_heading', { n: tier.n, label: tier.label }) }}</strong>
                    <StatusBadge v-if="tierState(tier.n) === 'active'" tone="danger">{{ t('c_jurisdictions.restoration.tier_active', 'Active') }}</StatusBadge>
                    <StatusBadge v-else-if="tierState(tier.n) === 'bypassed'" tone="neutral">{{ t('c_jurisdictions.restoration.tier_bypassed', 'Bypassed') }}</StatusBadge>
                    <StatusBadge v-else tone="neutral" icon="clock">{{ t('c_jurisdictions.restoration.tier_standby', 'Standby') }}</StatusBadge>
                    <p>{{ tier.actor }}</p>
                </li>
            </ol>
            <p>
                {{ t('c_jurisdictions.restoration.cascade_reuse', 'Rebuilding elections reuse the first-election machinery — ') }}<a href="/jurisdictions/bootstrap">{{ t('c_jurisdictions.restoration.cascade_link', 'how a place wakes up') }}</a>.
            </p>
        </Card>

        <Card v-if="events.length" as="section">
            <template #title>{{ t('c_jurisdictions.restoration.events_title', 'Restoration events') }}</template>
            <DataTable
                :columns="[
                    { key: 'jurisdiction', label: t('c_jurisdictions.restoration.col_place', 'Place') },
                    { key: 'condition', label: t('c_jurisdictions.restoration.col_condition', 'Condition') },
                    { key: 'confirmed', label: t('c_jurisdictions.restoration.col_review', 'Judicial review') },
                    { key: 'tier', label: t('c_jurisdictions.restoration.col_tier', 'Tier') },
                    { key: 'status', label: t('c_jurisdictions.restoration.col_status', 'Status') },
                ]"
                :rows="events"
                row-key="id"
            >
                <template #cell-condition="{ row }">{{ conditionCopy[row.condition]?.title || plainState(row.condition) }}</template>
                <template #cell-confirmed="{ row }">
                    <StatusBadge v-if="row.judicially_confirmed" tone="success" icon="check">{{ t('c_jurisdictions.restoration.ev_confirmed', 'Confirmed') }}</StatusBadge>
                    <StatusBadge v-else tone="neutral" icon="clock">{{ t('c_jurisdictions.restoration.ev_awaiting', 'Awaiting review') }}</StatusBadge>
                </template>
                <template #cell-tier="{ row }">
                    <span data-no-i18n>{{ row.tier > 0 ? `Tier ${row.tier}` : '—' }}</span>
                </template>
                <template #cell-status="{ row }">
                    <StatusBadge :tone="row.status === 'restored' ? 'success' : ['declared', 'confirmed', 'restoring'].includes(row.status) ? 'danger' : 'neutral'">
                        {{ plainState(row.status) }}
                    </StatusBadge>
                </template>
            </DataTable>

            <div v-if="viewer" class="door-actions">
                <h4>{{ t('c_jurisdictions.restoration.move_forward', 'Move an event forward') }}</h4>
                <div v-for="e in events" :key="e.id" class="door-block">
                    <p><strong>{{ e.jurisdiction }}</strong> — {{ plainState(e.status) }}<span v-if="e.tier > 0" data-no-i18n> · tier {{ e.tier }}</span></p>
                    <template v-if="!isTerminal(e)">
                        <button v-if="e.status === 'declared'" type="button" :disabled="busyId === e.id" @click="confirm(e)">{{ t('c_jurisdictions.restoration.btn_confirm', 'Confirm on the judicial finding') }}</button>
                        <p v-if="e.status === 'declared' && !e.judicial_finding" class="hint" role="status">{{ t('c_jurisdictions.restoration.no_finding', 'The tied review case has no constitutional finding yet — confirmation will be refused until it does.') }}</p>
                        <button v-if="e.judicially_confirmed && e.tier < 3" type="button" :disabled="busyId === e.id" @click="enterTier(e)">{{ t('c_jurisdictions.restoration.enter_tier', { n: (e.tier || 0) + 1 }) }}</button>
                        <button v-if="e.tier === 3" type="button" :disabled="busyId === e.id" @click="complete(e)">{{ t('c_jurisdictions.restoration.mark_restored', 'Mark restored') }}</button>
                        <button type="button" :disabled="busyId === e.id" @click="abandon(e)">{{ t('c_jurisdictions.restoration.abandon', 'Abandon') }}</button>
                    </template>
                    <p v-else class="hint" role="status">{{ t('c_jurisdictions.restoration.event_terminal', { state: plainState(e.status) }) }}</p>
                </div>
                <p v-if="busyId" role="status">{{ t('c_jurisdictions.restoration.working', 'Working…') }}</p>
                <p v-if="error && !busyId" role="alert">{{ error }}</p>
                <p v-if="notice && !busyId" role="status">{{ notice }}</p>
            </div>

            <HistoryPager
                v-if="events.length"
                :pages="pagination"
                :first="pagination.first"
                :only="['events', 'pagination']"
                cursor-key="restoration_cursor"
                :label="t('c_jurisdictions.restoration.history_label', 'Restoration event history pages')"
            />
        </Card>

        <Card v-if="viewer" as="section">
            <template #title>{{ t('c_jurisdictions.restoration.declare_title', 'Declare a restoration condition') }}</template>
            <p>
                {{ t('c_jurisdictions.restoration.declare_body', 'Declaration arms nothing on its own — a court must confirm the condition on the tied review case before the cascade runs (Art. VI §2).') }}
            </p>
            <form class="door-actions" @submit.prevent="declare">
                <label for="restore-jurisdiction">{{ t('c_jurisdictions.restoration.lbl_jurisdiction', 'Jurisdiction') }}</label>
                <input id="restore-jurisdiction" v-model="declareJurisdiction" :placeholder="t('c_jurisdictions.restoration.uuid_ph', 'UUID')" />
                <label for="restore-condition">{{ t('c_jurisdictions.restoration.lbl_condition', 'Condition') }}</label>
                <select id="restore-condition" v-model="declareCondition">
                    <option v-for="c in conditions" :key="c" :value="c">{{ plainState(c) }}</option>
                </select>
                <label for="restore-case">{{ t('c_jurisdictions.restoration.lbl_case', 'Tied review case (optional)') }}</label>
                <input id="restore-case" v-model="declareReviewCase" :placeholder="t('c_jurisdictions.restoration.case_ph', 'Review case UUID')" />
                <button type="submit" :disabled="busyId === 'declare' || !declareJurisdiction.trim()">{{ t('c_jurisdictions.restoration.declare_btn', 'Declare the condition') }}</button>
            </form>
        </Card>
        <p v-else class="hint">{{ t('c_jurisdictions.restoration.signin_hint', 'Sign in with a current legislative seat to declare a condition or move an event forward.') }}</p>

        <div class="grid-2">
            <Card as="section">
                <template #title>{{ t('c_jurisdictions.restoration.legit_title', 'Legitimacy scoring') }}</template>
                <p>
                    {{ t('c_jurisdictions.restoration.legit_body', 'When more than one body claims to be the government, three criteria decide: minimize consent violations, balance interests uniformly, govern effectively.') }}
                </p>
                <p class="citation">{{ t('c_jurisdictions.restoration.legit_citation', 'Legitimacy criteria — authority by consent.') }}</p>
            </Card>
            <Card as="section">
                <template #title>{{ t('c_jurisdictions.restoration.def_title', 'Defensive forces') }}</template>
                <p>
                    {{ t('c_jurisdictions.restoration.def_body_1', 'Forces are bound to protect the ') }}<strong>{{ t('c_jurisdictions.restoration.def_most_legit', 'most legitimate') }}</strong>{{ t('c_jurisdictions.restoration.def_body_2', ' government — not the incumbent, not the strongest, not their own chain of command\'s preference. During restoration, elections, sessions, and courts that still function cannot be disrupted.') }}
                </p>
                <p class="citation">{{ t('c_jurisdictions.restoration.def_citation', 'Defensive forces protect the most legitimate government.') }}</p>
            </Card>
        </div>

        <template #about>
            <p>
                {{ t('c_jurisdictions.restoration.about', 'Restoration is also a founding path — standing a world up from existing records is the same act as rebuilding one. Tier elections hand off to the bootstrap first election; tier 3 re-enters the jurisdiction bootstrap. Rebuilding is a branch, not an end state: the place returns to self-governing the moment a restored government is seated and certified.') }}
            </p>
        </template>
    </PageScaffold>
</template>

<style scoped>
.door-actions { border-block-start: 1px solid var(--border, #344054); margin-block-start: 1rem; padding-block-start: 1rem; display: grid; gap: .65rem; }
.door-block { display: grid; gap: .5rem; padding-block: .5rem; }
.door-actions button, .door-actions input, .door-actions select { min-block-size: 44px; font: inherit; }
.door-actions input, .door-actions select { inline-size: 100%; max-inline-size: 26rem; }
.door-actions button { inline-size: fit-content; }
.hint { color: var(--gov-muted, #94a3b8); }
</style>
