<script setup>import { useLocaleFormat } from '@/composables/useLocaleFormat';
const localeFmt = useLocaleFormat();
/**
 * Jurisdictions/BetweenGovernments — the read-only CITIZEN view at
 * /federation (design contract: mockups/v3/jurisdictions/federation.html;
 * ruling §10 item 9 — the operator console moved to /operator/federation).
 *
 * When two self-governing places share an edge — or a disagreement about
 * where it sits — the constitution gives a peaceful way through. The
 * everyday case is a boundary change: the people inside the moving boundary
 * deliberate, vote, and the map updates (Art. V §2 — a 2/3 supermajority of
 * everyone affected, not just those voting). Bigger moves link out to union
 * formation and disintermediation; the server plumbing lives on the
 * operator's mesh pages.
 */
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import HistoryPager from '@/Components/Ui/HistoryPager.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import Stepper from '@/Components/Ui/Stepper.vue';
import { plainState } from '@/lib/plain.js';

defineOptions({ layout: AppShellV2 });

const { t } = useI18n();

const props = defineProps({
    surface: { type: Object, required: true },
    settlements: { type: Array, default: () => [] },
    viewer: { type: Object, default: () => null },
    pagination: { type: Object, default: () => ({ previous: null, next: null, first: '/federation' }) },
});

const steps = [
    { label: t('c_jurisdictions.between_governments.step_deliberation', 'Deliberation — the proposal is drafted and discussed in the open'), state: 'done' },
    { label: t('c_jurisdictions.between_governments.step_referendum', 'Referendum — everyone inside the moving boundary votes'), state: 'active' },
    { label: t('c_jurisdictions.between_governments.step_done', 'Done — the map updates and everyone\'s records follow'), state: 'pending' },
];

const statusBadge = (s) =>
    s.status === 'adopted' ? { tone: 'success', icon: 'check', label: t('c_jurisdictions.between_governments.badge_ratified', 'Ratified') }
        : s.status === 'rejected' ? { tone: 'warning', label: t('c_jurisdictions.between_governments.badge_rejected', 'Rejected — supermajority not reached') }
            : s.status === 'expired' ? { tone: 'neutral', label: t('c_jurisdictions.between_governments.badge_expired', 'Expired') }
                : s.supermajority_met ? { tone: 'info', label: t('c_jurisdictions.between_governments.badge_met', 'Supermajority met — adoption pending') }
                    : { tone: 'neutral', label: t('c_jurisdictions.between_governments.badge_open', 'Proposal open') };

const jurisdictionA = ref('');
const jurisdictionB = ref('');
const affectedIds = ref('');
const referendumVotes = ref({});
const busyId = ref('');
const error = ref('');
const notice = ref('');

function act(id, url, data) {
    if (busyId.value) return;
    router.post(url, data || {}, {
        preserveScroll: true,
        onStart: () => { busyId.value = id; error.value = ''; notice.value = ''; },
        onFinish: () => { busyId.value = ''; },
        onError: (errors) => { error.value = Object.values(errors)[0] || t('c_jurisdictions.between_governments.act_error', 'That action could not be completed. Please retry.'); },
        onSuccess: () => { notice.value = t('c_jurisdictions.between_governments.act_done', 'Done. The settlement list below reflects the change.'); },
    });
}
function proposeBorder() {
    const affected = affectedIds.value.split(/[\s,]+/).map((s) => s.trim()).filter(Boolean);
    act('propose', '/federation/border/propose', {
        jurisdiction_a_id: jurisdictionA.value.trim(),
        jurisdiction_b_id: jurisdictionB.value.trim(),
        affected_ids: affected,
    });
}
const referendum = (s) => act(s.id, `/federation/border/${s.id}/referendum`, { yes_votes: Number(referendumVotes.value[s.id] ?? 0) });
const adopt = (s) => act(s.id, `/federation/border/${s.id}/adopt`);
</script>

<template>
    <PageScaffold :surface="surface" :title="t('c_jurisdictions.between_governments.title', 'Between governments')">
        <template #intro>
            {{ t('c_jurisdictions.between_governments.intro_before', 'When two self-governing places share an edge — or a disagreement about where that edge sits — the constitution gives a peaceful way through. The everyday case is a boundary change: the people who live inside the moving boundary deliberate, vote, and the map updates. Bigger moves are ') }}<a href="/jurisdictions/union-formation">{{ t('c_jurisdictions.between_governments.intro_link_union', 'merging into a union') }}</a>{{ t('c_jurisdictions.between_governments.intro_mid', ' and ') }}<a href="/jurisdictions/disintermediation">{{ t('c_jurisdictions.between_governments.intro_link_disinter', 'removing a middle layer') }}</a>.
        </template>

        <Card as="section">
            <template #title>{{ t('c_jurisdictions.between_governments.border_title', 'Border settlement') }}</template>
            <p>
                {{ t('c_jurisdictions.between_governments.border_intro', 'Boundary changes between places pass by a supermajority of the affected population — the people inside the moving boundary decide, not the legislatures around them.') }}
            </p>
            <Stepper :steps="steps" />

            <Banner v-if="settlements.length === 0" tone="info" role="status">
                <strong>{{ t('c_jurisdictions.between_governments.none_strong', 'No boundary changes proposed.') }}</strong> {{ t('c_jurisdictions.between_governments.none_rest', 'Any two neighbouring places can open one; it appears here the moment it exists.') }}
            </Banner>

            <DataTable
                v-else
                :columns="[
                    { key: 'change', label: t('c_jurisdictions.between_governments.col_change', 'Boundary change') },
                    { key: 'affected', label: t('c_jurisdictions.between_governments.col_affected', 'Affected population') },
                    { key: 'required', label: t('c_jurisdictions.between_governments.col_required', 'Supermajority needed') },
                    { key: 'status', label: t('c_jurisdictions.between_governments.col_status', 'Status') },
                ]"
                :rows="settlements"
                row-key="id"
            >
                <template #cell-change="{ row }">{{ row.a }} ↔ {{ row.b }}</template>
                <template #cell-affected="{ row }">
                    <span data-no-i18n>{{ localeFmt.number(row.affected_population) }}</span>
                </template>
                <template #cell-required="{ row }">
                    <span data-no-i18n>{{ localeFmt.number(row.required) }}</span> {{ t('c_jurisdictions.between_governments.required_note', '(2/3 of all affected)') }}
                </template>
                <template #cell-status="{ row }">
                    <StatusBadge :tone="statusBadge(row).tone" :icon="statusBadge(row).icon || null">
                        {{ statusBadge(row).label }}
                    </StatusBadge>
                </template>
            </DataTable>

            <div v-if="settlements.length && viewer" class="door-actions">
                <h4>{{ t('c_jurisdictions.between_governments.move_forward', 'Move a settlement forward') }}</h4>
                <div v-for="s in settlements" :key="s.id" class="door-block">
                    <p><strong>{{ s.a }} ↔ {{ s.b }}</strong> — {{ statusBadge(s).label }}</p>
                    <form class="door-row" @submit.prevent="referendum(s)">
                        <label :for="`ref-${s.id}`">{{ t('c_jurisdictions.between_governments.yes_votes', 'Affected-area yes votes') }}</label>
                        <input :id="`ref-${s.id}`" v-model="referendumVotes[s.id]" type="number" min="0" inputmode="numeric" />
                        <button type="submit" :disabled="busyId === s.id || s.status !== 'open'">{{ t('c_jurisdictions.between_governments.record_referendum', 'Record referendum') }}</button>
                    </form>
                    <button type="button" :disabled="busyId === s.id || s.status !== 'open'" @click="adopt(s)">{{ t('c_jurisdictions.between_governments.adopt_boundary', 'Adopt the boundary') }}</button>
                    <p v-if="s.status !== 'open'" class="hint" role="status">{{ t('c_jurisdictions.between_governments.settlement_closed', { state: plainState(s.status) }) }}</p>
                </div>
                <p v-if="busyId" role="status">{{ t('c_jurisdictions.between_governments.working', 'Working…') }}</p>
                <p v-if="error && !busyId" role="alert">{{ error }}</p>
                <p v-if="notice && !busyId" role="status">{{ notice }}</p>
            </div>

            <HistoryPager
                v-if="settlements.length"
                :pages="pagination"
                :first="pagination.first"
                :only="['settlements', 'pagination']"
                cursor-key="border_cursor"
                :label="t('c_jurisdictions.between_governments.history_label', 'Border settlement history pages')"
            />

            <div v-if="viewer" class="door-actions">
                <h4>{{ t('c_jurisdictions.between_governments.open_title', 'Open a border settlement') }}</h4>
                <p>{{ t('c_jurisdictions.between_governments.open_body', 'A between-governments act. Name the two neighbouring places and the affected sub-jurisdictions whose residents decide.') }}</p>
                <form class="door-row" @submit.prevent="proposeBorder">
                    <label for="border-a">{{ t('c_jurisdictions.between_governments.jurisdiction_a', 'Jurisdiction A') }}</label>
                    <input id="border-a" v-model="jurisdictionA" :placeholder="t('c_jurisdictions.between_governments.uuid_placeholder', 'UUID')" />
                    <label for="border-b">{{ t('c_jurisdictions.between_governments.jurisdiction_b', 'Jurisdiction B') }}</label>
                    <input id="border-b" v-model="jurisdictionB" :placeholder="t('c_jurisdictions.between_governments.uuid_placeholder', 'UUID')" />
                    <label for="border-affected">{{ t('c_jurisdictions.between_governments.affected_subs', 'Affected sub-jurisdictions') }}</label>
                    <textarea id="border-affected" v-model="affectedIds" rows="2" :placeholder="t('c_jurisdictions.between_governments.affected_placeholder', 'One or more UUIDs, comma or space separated')" />
                    <button type="submit" :disabled="busyId === 'propose' || !jurisdictionA.trim() || !jurisdictionB.trim() || !affectedIds.trim()">{{ t('c_jurisdictions.between_governments.open_settlement', 'Open the settlement') }}</button>
                </form>
            </div>
            <p v-else class="hint">{{ t('c_jurisdictions.between_governments.signin_hint', 'Sign in with a current legislative seat to open a settlement, record a referendum, or adopt a boundary.') }}</p>

            <p>
                {{ t('c_jurisdictions.between_governments.ratified_note', 'Once a settlement is ratified, every affected resident\'s home association is re-checked against the new boundary — rights re-attach automatically on the new side of the line. Nobody has to re-register.') }}
            </p>
            <p class="citation">{{ t('c_jurisdictions.between_governments.citation', 'Boundary changes pass by a two-thirds supermajority of the affected population · Art. V §2') }}</p>
        </Card>

        <Card as="section">
            <template #title>{{ t('c_jurisdictions.between_governments.servers_title', 'The servers behind this') }}</template>
            <p>
                {{ t('c_jurisdictions.between_governments.servers_before', 'Different governments can run on different servers that find each other, agree who holds the master copy, and stay in sync. That plumbing has its own pages in the operator area: ') }}<a href="/operator/mesh">{{ t('c_jurisdictions.between_governments.servers_link', 'the server mesh') }}</a>.
            </p>
        </Card>

        <template #about>
            <p>
                {{ t('c_jurisdictions.between_governments.about_before', 'A proposal goes through open deliberation, then a referendum of the affected population — a two-thirds supermajority of everyone affected, not just those voting. Recognized peers who want to go further continue in ') }}<a href="/jurisdictions/union-formation">{{ t('c_jurisdictions.between_governments.about_link', 'union formation') }}</a>{{ t('c_jurisdictions.between_governments.about_after', '. The peer-discovery, record-sync and server-authority machinery that used to share this page now lives on the operator\'s mesh pages.') }}
            </p>
        </template>
    </PageScaffold>
</template>

<style scoped>
.door-actions { border-block-start: 1px solid var(--border, #344054); margin-block-start: 1rem; padding-block-start: 1rem; display: grid; gap: .65rem; }
.door-block { display: grid; gap: .5rem; padding-block: .5rem; }
.door-row { display: grid; gap: .5rem; }
.door-actions button, .door-row input, .door-row textarea { min-block-size: 44px; font: inherit; }
.door-row input, .door-row textarea { inline-size: 100%; max-inline-size: 26rem; }
.door-actions button { inline-size: fit-content; }
.hint { color: var(--gov-muted, #94a3b8); }
</style>
