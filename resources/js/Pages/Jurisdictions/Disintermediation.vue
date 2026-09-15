<script setup>
/**
 * Jurisdictions/Disintermediation — "Removing a middle layer" (design
 * contract: mockups/v3/jurisdictions/disintermediation.html).
 *
 * Art. V §8: an intermediary jurisdiction dissolves only when EVERY
 * constituent agrees (unanimity — one holdout stops it) AND the encompassing
 * jurisdiction consents. The dissolved layer's acts do not vanish — each
 * former CONSTITUENT inherits its own copy, full history preserved (the
 * ruled direction, 2026-07-28). The F-LEG-030 door files a real chamber
 * proposal; the parties are DERIVED from the proposing chamber's own place
 * in the hierarchy, never picked.
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
    door: { type: Object, default: () => ({ seat: null, proposable: false }) },
    pagination: { type: Object, default: () => ({ previous: null, next: null, first: '/jurisdictions/disintermediation' }) },
});

const submitting = ref(false);
const busyId = ref('');
const error = ref('');
const notice = ref('');

function propose() {
    submitting.value = true;
    router.post('/jurisdictions/disintermediation/propose', {}, {
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
        onError: (errors) => { error.value = Object.values(errors)[0] || t('c_jurisdictions.disintermediation.act_error', 'That action could not be completed. Please retry.'); },
        onSuccess: () => { notice.value = t('c_jurisdictions.disintermediation.act_done', 'Done. The consent meters below reflect the change.'); },
    });
}
const encompassingConsent = (p, consented) => act(p.id, `/jurisdictions/disintermediation/${p.id}/encompassing-consent`, { consented });
const consent = (p) => act(p.id, `/jurisdictions/disintermediation/${p.id}/consent`);
const finalize = (p) => act(p.id, `/jurisdictions/disintermediation/${p.id}/finalize`);

const statusTone = (s) =>
    s === 'merged' ? 'success' : s === 'failed' || s === 'expired' ? 'warning' : 'info';
</script>

<template>
    <PageScaffold :surface="surface" :title="t('c_jurisdictions.disintermediation.title', 'Removing a middle layer')">
        <template #intro>
            {{ t('c_jurisdictions.disintermediation.intro', 'A middle layer of government dissolves — and its constituents answer directly to the level above — only when every constituent agrees and the encompassing jurisdiction consents. The dissolved layer\'s laws do not vanish: they fold into each remaining place\'s own law. Everyone inside agrees, the level above agrees, and only then do the laws fold down.') }}
        </template>

        <Banner v-if="processes.length === 0" tone="info" role="status">
            <strong>{{ t('c_jurisdictions.disintermediation.none_strong', 'No live case.') }}</strong> {{ t('c_jurisdictions.disintermediation.none_before', 'No middle layer is currently being dissolved. The process below is real and waiting — a chamber proposal (') }}<span class="citation">F-LEG-030</span>{{ t('c_jurisdictions.disintermediation.none_after', ') opens it.') }}
        </Banner>

        <Card v-for="p in processes" :key="p.id" as="section">
            <template #title>{{ t('c_jurisdictions.disintermediation.process_title', { intermediary: p.intermediary, encompassing: p.encompassing }) }}</template>

            <p>
                <StatusBadge :tone="statusTone(p.status)">{{ plainState(p.status) }}</StatusBadge>
                <span class="citation" data-no-i18n>opened {{ new Date(p.opened_at).toLocaleDateString() }}</span>
            </p>

            <h4>{{ t('c_jurisdictions.disintermediation.consent_meters', 'Consent meters') }}</h4>
            <p><strong>{{ t('c_jurisdictions.disintermediation.unanimity_strong', 'Unanimity of constituents — not a supermajority.') }}</strong> {{ t('c_jurisdictions.disintermediation.unanimity_rest', 'One holdout stops the dissolution.') }}</p>
            <ThresholdMeter
                v-if="p.unanimity"
                :value="p.unanimity.yes"
                :max="p.unanimity.total"
                :threshold="p.unanimity.required"
                :label="t('c_jurisdictions.disintermediation.unanimity_label', 'Constituent unanimity')"
            >
                {{ t('c_jurisdictions.disintermediation.unanimity_body', { yes: p.unanimity.yes, total: p.unanimity.total, required: p.unanimity.required }) }}
                <template #note>{{ t('c_jurisdictions.disintermediation.unanimity_note', 'unanimity, not a supermajority') }}</template>
            </ThresholdMeter>
            <ThresholdMeter
                :value="p.encompassing_consent === true ? 1 : 0"
                :max="1"
                :threshold="1"
                :label="t('c_jurisdictions.disintermediation.encompassing_label', 'Encompassing consent')"
            >
                {{ t('c_jurisdictions.disintermediation.encompassing_body', { name: p.encompassing }) }}
                <template #note>
                    {{ p.encompassing_consent === true ? t('c_jurisdictions.disintermediation.encompassing_consented', 'consented') : p.encompassing_consent === false ? t('c_jurisdictions.disintermediation.encompassing_declined', 'declined') : t('c_jurisdictions.disintermediation.encompassing_must_agree', 'the encompassing jurisdiction must agree') }}
                </template>
            </ThresholdMeter>

            <DataTable
                v-if="p.unanimity && p.unanimity.consents.length"
                :columns="[
                    { key: 'jurisdiction', label: t('c_jurisdictions.disintermediation.col_constituent', 'Constituent') },
                    { key: 'result', label: t('c_jurisdictions.disintermediation.col_status', 'Status') },
                ]"
                :rows="p.unanimity.consents"
                row-key="jurisdiction"
            >
                <template #cell-result="{ row }">
                    <StatusBadge v-if="row.result === 'yes'" tone="success" icon="check">{{ t('c_jurisdictions.disintermediation.result_passed', 'Dissolution act passed') }}</StatusBadge>
                    <StatusBadge v-else-if="row.result === 'no'" tone="warning">{{ t('c_jurisdictions.disintermediation.result_declined', 'Declined — dissolution stopped') }}</StatusBadge>
                    <StatusBadge v-else tone="neutral" icon="clock">{{ t('c_jurisdictions.disintermediation.result_pending', 'Pending') }}</StatusBadge>
                </template>
            </DataTable>

            <template v-if="p.folded_acts.length">
                <h4>{{ t('c_jurisdictions.disintermediation.folded_title', 'The folded law') }}</h4>
                <p>
                    {{ t('c_jurisdictions.disintermediation.folded_body', 'Each former constituent inherited its own copy of every act, full version history preserved — so each can amend or repeal independently from now on.') }}
                </p>
                <DataTable
                    :columns="[
                        { key: 'law', label: t('c_jurisdictions.disintermediation.col_act', 'Act') },
                        { key: 'inherited_by', label: t('c_jurisdictions.disintermediation.col_inherited', 'Inherited by') },
                        { key: 'decision', label: t('c_jurisdictions.disintermediation.col_resolution', 'Resolution') },
                    ]"
                    :rows="p.folded_acts"
                >
                    <template #cell-decision="{ row }">
                        <StatusBadge tone="success" icon="check">{{ plainState(row.decision) }}</StatusBadge>
                    </template>
                </DataTable>
                <p class="citation">{{ t('c_jurisdictions.disintermediation.folded_citation', 'Acts are incorporated into the former constituents, then published.') }}</p>
            </template>

            <div v-if="door.seat" class="door-actions">
                <h4>{{ t('c_jurisdictions.disintermediation.move_forward', 'Move this process forward') }}</h4>
                <div v-if="p.viewer_is_encompassing" class="door-row">
                    <span>{{ t('c_jurisdictions.disintermediation.encompassing_decision', 'Encompassing jurisdiction\'s decision') }}</span>
                    <button type="button" :disabled="busyId === p.id || p.status !== 'open'" @click="encompassingConsent(p, true)">{{ t('c_jurisdictions.disintermediation.encompassing_consents', 'Encompassing consents') }}</button>
                    <button type="button" :disabled="busyId === p.id || p.status !== 'open'" @click="encompassingConsent(p, false)">{{ t('c_jurisdictions.disintermediation.encompassing_declines', 'Encompassing declines') }}</button>
                </div>
                <button
                    v-if="p.consentable_by_viewer"
                    type="button"
                    :disabled="busyId === p.id"
                    @click="consent(p)"
                >
                    {{ t('c_jurisdictions.disintermediation.open_consent_vote', 'Open my chamber\'s consent vote') }}
                </button>
                <button type="button" :disabled="busyId === p.id || p.status !== 'open'" @click="finalize(p)">
                    {{ t('c_jurisdictions.disintermediation.finalize', 'Finalize the dissolution') }}
                </button>
                <p v-if="p.status !== 'open'" class="hint" role="status">{{ t('c_jurisdictions.disintermediation.process_closed', { state: plainState(p.status) }) }}</p>
                <p v-if="busyId === p.id" role="status">{{ t('c_jurisdictions.disintermediation.working', 'Working…') }}</p>
                <p v-if="error && busyId === ''" role="alert">{{ error }}</p>
                <p v-if="notice && busyId === ''" role="status">{{ notice }}</p>
            </div>
            <p v-else class="hint">{{ t('c_jurisdictions.disintermediation.signin_hint', 'Sign in with a current legislative seat to record consent or finalize.') }}</p>
        </Card>

        <HistoryPager
            v-if="processes.length"
            :pages="pagination"
            :first="pagination.first"
            :only="['processes', 'pagination']"
            cursor-key="disinter_cursor"
            :label="t('c_jurisdictions.disintermediation.history_label', 'Disintermediation process history pages')"
        />

        <Card as="section">
            <template #title>{{ t('c_jurisdictions.disintermediation.chain_title', 'The chain after dissolution') }}</template>
            <p>
                {{ t('c_jurisdictions.disintermediation.chain_body', 'Each former constituent answers directly to the level above, and every resident\'s chain of places re-resolves automatically — you belong to every remaining level at once. The chain updates the moment the dissolution takes effect; nobody re-registers anything.') }}
            </p>
        </Card>

        <Card as="section">
            <template #title>{{ t('c_jurisdictions.disintermediation.vote_title', 'The dissolution vote') }}</template>
            <p>
                <FormChip form-id="F-LEG-030" />
                {{ t('c_jurisdictions.disintermediation.vote_body', 'The vote is held in each constituent legislature and in the encompassing legislature. A representative proposes it in their own chamber; the parties follow from the chamber\'s place in the hierarchy.') }}
            </p>

            <template v-if="door.seat">
                <p v-if="door.proposable">
                    {{ t('c_jurisdictions.disintermediation.proposing_1', 'Proposing as a member of the ') }}<strong>{{ door.seat.jurisdiction_name }}</strong>{{ t('c_jurisdictions.disintermediation.proposing_2', ' legislature — the middle layer to dissolve is ') }}<strong>{{ door.seat.jurisdiction_name }}</strong>{{ t('c_jurisdictions.disintermediation.proposing_3', ' itself (') }}{{ door.seat.child_count }}{{ t('c_jurisdictions.disintermediation.proposing_4', ' constituent places fold to its parent\'s level).') }}
                </p>
                <Btn v-if="door.proposable" :disabled="submitting" @click="propose">
                    {{ t('c_jurisdictions.disintermediation.open_proposal', 'Open the chamber proposal') }}
                </Btn>
                <p v-else>
                    {{ t('c_jurisdictions.disintermediation.not_middle', { name: door.seat.jurisdiction_name }) }}
                </p>
            </template>
            <p v-else>
                {{ t('c_jurisdictions.disintermediation.proposing_requires', 'Proposing requires a current legislative seat. Anyone may watch the process; only a seated representative may open it.') }}
            </p>
        </Card>

        <template #about>
            <p>
                {{ t('c_jurisdictions.disintermediation.about_before', 'Every constituent and the encompassing jurisdiction vote; the intermediary\'s acts are inherited by each former constituent; the chain of places updates. The mirror-image growth path is ') }}<a href="/jurisdictions/union-formation">{{ t('c_jurisdictions.disintermediation.about_link', 'union formation') }}</a>{{ t('c_jurisdictions.disintermediation.about_after', '. Once dissolved, the middle layer is gone; its former constituents remain self-governing throughout.') }}
            </p>
        </template>
    </PageScaffold>
</template>

<style scoped>
.door-actions { border-block-start: 1px solid var(--border, #344054); margin-block-start: 1rem; padding-block-start: 1rem; display: grid; gap: .65rem; }
.door-row { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; }
.door-actions button { min-block-size: 44px; font: inherit; inline-size: fit-content; }
.hint { color: var(--gov-muted, #94a3b8); }
</style>
