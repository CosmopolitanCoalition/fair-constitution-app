<script setup>
/**
 * Jurisdictions/UnionFormation — "check differences, agree one rulebook,
 * then vote" (design contract: mockups/v3/jurisdictions/union-formation.html).
 *
 * Art. V §7: two or more independent jurisdictions form a union by checking
 * where their settings differ, agreeing one shared value per difference, and
 * ratifying by supermajority of the applicant population AND of the union's
 * constituent jurisdictions. Exit mirrors entry. The list renders real
 * union_processes with both ratification meters; the F-LEG-029 door files a
 * real chamber proposal (a seat is required — the service re-checks
 * everything, this page only reads capability).
 */
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import FormChip from '@/Components/Ui/FormChip.vue';
import HistoryPager from '@/Components/Ui/HistoryPager.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import ThresholdMeter from '@/Components/Ui/ThresholdMeter.vue';
import { plainState } from '@/lib/plain.js';

defineOptions({ layout: AppShellV2 });

const { t } = useI18n();

const props = defineProps({
    surface: { type: Object, required: true },
    processes: { type: Array, default: () => [] },
    door: { type: Object, default: () => ({ seat: null, siblings: [] }) },
    pagination: { type: Object, default: () => ({ previous: null, next: null, first: '/jurisdictions/union-formation' }) },
});

const chosenSiblings = ref([]);
const submitting = ref(false);
const busyId = ref('');
const referendumVotes = ref({});
const error = ref('');
const notice = ref('');

function propose() {
    if (!props.door.seat || chosenSiblings.value.length === 0) return;
    submitting.value = true;
    router.post('/jurisdictions/union-formation/propose', {
        kind: 'formation',
        applicant_ids: [props.door.seat.jurisdiction_id, ...chosenSiblings.value],
    }, {
        preserveScroll: true,
        onFinish: () => { submitting.value = false; },
    });
}

function act(id, url, data) {
    if (busyId.value) return;
    router.post(url, data || {}, {
        preserveScroll: true,
        onStart: () => { busyId.value = id; error.value = ''; notice.value = ''; },
        onFinish: () => { busyId.value = ''; },
        onError: (errors) => { error.value = Object.values(errors)[0] || t('c_jurisdictions.union_formation.act_error', 'That action could not be completed. Please retry.'); },
        onSuccess: () => { notice.value = t('c_jurisdictions.union_formation.act_done', 'Done. The process meters below reflect the change.'); },
    });
}
const applicantReferendum = (p) => act(p.id, `/jurisdictions/union-formation/${p.id}/applicant-referendum`, { yes_votes: Number(referendumVotes.value[p.id] ?? 0) });
const consent = (p) => act(p.id, `/jurisdictions/union-formation/${p.id}/consent`);
const finalize = (p) => act(p.id, `/jurisdictions/union-formation/${p.id}/finalize`);

const statusTone = (s) =>
    s === 'passed' ? 'success' : s === 'failed' || s === 'expired' ? 'warning' : 'info';

const diffRows = (diff) =>
    Object.entries(diff || {}).map(([setting, d]) => ({
        setting,
        values: Object.values(d?.values ?? {}).join(' vs '),
        aligned: Boolean(d?.aligned),
    }));
</script>

<template>
    <PageScaffold :surface="surface" :title="t('c_jurisdictions.union_formation.title', 'Union formation')">
        <template #intro>
            {{ t('c_jurisdictions.union_formation.intro', 'Two or more independent places form a union by checking where their settings and institutions differ, agreeing one shared value for each difference, and ratifying by supermajority — of the applicant population and of the union\'s constituent jurisdictions. Exit mirrors entry: no one-way doors.') }}
        </template>

        <Banner v-if="processes.length === 0" tone="info" role="status">
            <strong>{{ t('c_jurisdictions.union_formation.none_strong', 'No live case.') }}</strong> {{ t('c_jurisdictions.union_formation.none_before', 'Earth starts united, so no union is currently forming. The process below is real and waiting — a chamber proposal (') }}<span class="citation">F-LEG-029</span>{{ t('c_jurisdictions.union_formation.none_after', ') opens it.') }}
        </Banner>

        <Card v-for="p in processes" :key="p.id" as="section">
            <template #title>
                {{ p.kind === 'formation' ? t('c_jurisdictions.union_formation.kind_formation', 'Founding union') : p.kind === 'join' ? t('c_jurisdictions.union_formation.kind_join', 'Joining a union') : t('c_jurisdictions.union_formation.kind_leave', 'Leaving a union') }}
                — {{ p.applicants.map(a => a.name).join(', ') }}
                <template v-if="p.union_name"> · {{ p.union_name }}</template>
            </template>

            <p>
                <StatusBadge :tone="statusTone(p.status)">{{ plainState(p.status) }}</StatusBadge>
                <span class="citation" data-no-i18n>opened {{ new Date(p.opened_at).toLocaleDateString() }}</span>
            </p>

            <DataTable
                v-if="diffRows(p.compatibility_diff).length"
                :columns="[
                    { key: 'setting', label: t('c_jurisdictions.union_formation.col_setting', 'Setting') },
                    { key: 'values', label: t('c_jurisdictions.union_formation.col_values', 'Values') },
                    { key: 'aligned', label: t('c_jurisdictions.union_formation.col_status', 'Status') },
                ]"
                :rows="diffRows(p.compatibility_diff)"
                row-key="setting"
            >
                <template #cell-setting="{ row }"><span data-no-i18n>{{ plainState(row.setting) }}</span></template>
                <template #cell-aligned="{ row }">
                    <StatusBadge v-if="row.aligned" tone="success" icon="check">{{ t('c_jurisdictions.union_formation.aligned', 'Aligned') }}</StatusBadge>
                    <StatusBadge v-else tone="warning">{{ t('c_jurisdictions.union_formation.needs_value', 'Needs one shared value') }}</StatusBadge>
                </template>
            </DataTable>

            <h4>{{ t('c_jurisdictions.union_formation.ratification_meters', 'Ratification meters') }}</h4>
            <p>{{ t('c_jurisdictions.union_formation.denominators', 'Denominators are whole populations, never just those voting.') }}</p>
            <ThresholdMeter
                :value="p.applicant_supermajority_met ? 1 : 0"
                :max="1"
                :threshold="1"
                :label="t('c_jurisdictions.union_formation.applicant_label', 'Applicant population referendum')"
            >
                {{ t('c_jurisdictions.union_formation.applicant_body', 'Applicant population referendum') }}
                <template #note>{{ p.applicant_supermajority_met ? t('c_jurisdictions.union_formation.supermajority_met', 'supermajority met') : t('c_jurisdictions.union_formation.not_yet_met', 'not yet met') }}</template>
            </ThresholdMeter>
            <ThresholdMeter
                v-if="p.constituent_vote"
                :value="p.constituent_vote.yes"
                :max="p.constituent_vote.total"
                :threshold="p.constituent_vote.required"
                :label="t('c_jurisdictions.union_formation.constituent_label', 'Constituent jurisdictions')"
            >
                {{ t('c_jurisdictions.union_formation.constituent_body', { yes: p.constituent_vote.yes, required: p.constituent_vote.required, total: p.constituent_vote.total }) }}
                <template #note>{{ plainState(p.constituent_vote.status) }}</template>
            </ThresholdMeter>

            <DataTable
                v-if="p.constituent_vote && p.constituent_vote.consents.length"
                :columns="[
                    { key: 'jurisdiction', label: t('c_jurisdictions.union_formation.col_constituent', 'Constituent') },
                    { key: 'result', label: t('c_jurisdictions.union_formation.col_decision', 'Decision') },
                ]"
                :rows="p.constituent_vote.consents"
                row-key="jurisdiction"
            >
                <template #cell-result="{ row }">
                    <StatusBadge v-if="row.result === 'yes'" tone="success" icon="check">{{ t('c_jurisdictions.union_formation.res_consented', 'Consented') }}</StatusBadge>
                    <StatusBadge v-else-if="row.result === 'no'" tone="warning">{{ t('c_jurisdictions.union_formation.res_declined', 'Declined') }}</StatusBadge>
                    <StatusBadge v-else tone="neutral" icon="clock">{{ t('c_jurisdictions.union_formation.res_pending', 'Pending') }}</StatusBadge>
                </template>
            </DataTable>

            <div v-if="door.seat" class="door-actions">
                <h4>{{ t('c_jurisdictions.union_formation.move_forward', 'Move this process forward') }}</h4>
                <form class="door-row" @submit.prevent="applicantReferendum(p)">
                    <label :for="`ar-${p.id}`">{{ t('c_jurisdictions.union_formation.ar_label', 'Applicant population — yes votes recorded') }}</label>
                    <input :id="`ar-${p.id}`" v-model="referendumVotes[p.id]" type="number" min="0" inputmode="numeric" />
                    <button type="submit" :disabled="busyId === p.id || p.status !== 'open'">{{ t('c_jurisdictions.union_formation.record_referendum', 'Record applicant referendum') }}</button>
                </form>
                <button
                    v-if="p.consentable_by_viewer"
                    type="button"
                    :disabled="busyId === p.id"
                    @click="consent(p)"
                >
                    {{ t('c_jurisdictions.union_formation.open_consent', 'Open my chamber\'s consent vote') }}
                </button>
                <button type="button" :disabled="busyId === p.id || p.status !== 'open'" @click="finalize(p)">
                    {{ t('c_jurisdictions.union_formation.finalize', 'Finalize the union') }}
                </button>
                <p v-if="p.status !== 'open'" class="hint" role="status">{{ t('c_jurisdictions.union_formation.process_closed', { state: plainState(p.status) }) }}</p>
                <p v-if="busyId === p.id" role="status">{{ t('c_jurisdictions.union_formation.working', 'Working…') }}</p>
                <p v-if="error && busyId === ''" role="alert">{{ error }}</p>
                <p v-if="notice && busyId === ''" role="status">{{ notice }}</p>
            </div>
            <p v-else class="hint">{{ t('c_jurisdictions.union_formation.signin_hint', 'Sign in with a current legislative seat to record the referendum, open your chamber\'s consent vote, or finalize.') }}</p>
        </Card>

        <HistoryPager
            v-if="processes.length"
            :pages="pagination"
            :first="pagination.first"
            :only="['processes', 'pagination']"
            cursor-key="union_cursor"
            :label="t('c_jurisdictions.union_formation.history_label', 'Union process history pages')"
        />

        <Card as="section">
            <template #title>{{ t('c_jurisdictions.union_formation.mirror_title', 'Join and exit mirror each other') }}</template>
            <p>
                {{ t('c_jurisdictions.union_formation.mirror_body', 'Joining an existing union runs the same entrance-clause process, and departure requires the same supermajorities. The entrance process and the exit process mirror each other — no one-way doors.') }}
            </p>
        </Card>

        <Card as="section">
            <template #title>{{ t('c_jurisdictions.union_formation.founding_title', 'The founding act') }}</template>
            <p class="union-note">
                {{ t('c_jurisdictions.union_formation.founding_note', 'This world is already unioned under Earth, so no union is forming now. This door is lawful and stands ready for future worlds and sub-unions.') }}
            </p>
            <p>
                <FormChip form-id="F-LEG-029" />
                {{ t('c_jurisdictions.union_formation.founding_body', 'A legislative representative proposes the union in their own chamber; the chamber\'s supermajority opens the ratification vote tracked above.') }}
            </p>

            <template v-if="door.seat">
                <p>
                    {{ t('c_jurisdictions.union_formation.proposing_1', 'Proposing as a member of the ') }}<strong>{{ door.seat.jurisdiction_name }}</strong>{{ t('c_jurisdictions.union_formation.proposing_2', ' legislature. Pick the partner place(s):') }}
                </p>
                <div v-if="door.siblings.length" class="stack">
                    <label v-for="s in door.siblings" :key="s.id" class="cluster">
                        <input v-model="chosenSiblings" type="checkbox" :value="s.id" />
                        {{ s.name }}
                    </label>
                    <Btn
                        :disabled="submitting || chosenSiblings.length === 0"
                        @click="propose"
                    >
                        {{ t('c_jurisdictions.union_formation.open_proposal', 'Open the chamber proposal') }}
                    </Btn>
                </div>
                <p v-else>
                    {{ t('c_jurisdictions.union_formation.no_siblings', 'No neighbouring places share a parent with yours — a founding union needs at least two independent partners.') }}
                </p>
            </template>
            <p v-else>
                {{ t('c_jurisdictions.union_formation.proposing_requires', 'Proposing requires a current legislative seat. Anyone may watch the process; only a seated representative may open it.') }}
            </p>
        </Card>

        <template #about>
            <p>
                {{ t('c_jurisdictions.union_formation.about_1', 'Once ratified, the new encompassing jurisdiction is born self-governing, with the applicants as its constituents. Boundary questions between neighbours live on ') }}<a href="/federation">{{ t('c_jurisdictions.union_formation.about_link_fed', 'Between governments') }}</a>{{ t('c_jurisdictions.union_formation.about_2', '; removing a middle layer is the mirror-image path: ') }}<a href="/jurisdictions/disintermediation">{{ t('c_jurisdictions.union_formation.about_link_disinter', 'disintermediation') }}</a>.
            </p>
        </template>
    </PageScaffold>
</template>

<style scoped>
.door-actions { border-block-start: 1px solid var(--border, #344054); margin-block-start: 1rem; padding-block-start: 1rem; display: grid; gap: .65rem; }
.door-row { display: grid; gap: .5rem; }
.door-actions button, .door-row input { min-block-size: 44px; font: inherit; }
.door-row input { inline-size: 100%; max-inline-size: 20rem; }
.door-actions button { inline-size: fit-content; }
.hint { color: var(--gov-muted, #94a3b8); }
</style>
