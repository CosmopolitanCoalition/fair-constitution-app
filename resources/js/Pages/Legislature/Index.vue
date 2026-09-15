<script setup>
/**
 * Legislature/Index — WI-9 multi-legislature switcher.
 *
 * Lists every legislature on the instance (setup founds the first — the
 * root jurisdiction's; CLK-06 activations add more as jurisdictions reach
 * critical population). Each row links into that legislature's district
 * mapper at /legislatures/{slug} — no UUID memorization required.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import AdmChip from '@/Components/Ui/AdmChip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });
const { t } = useI18n();

const props = defineProps({
    surface: { type: Object, required: true },
    /** [{ id, jurisdiction, slug, adm_level, type_a_seats, type_b_seats,
     *     status, district_count, activation_state, activated_at,
     *     election: { id, status } | null, results_election_id }] */
    legislatures: { type: Array, required: true },
    total_legislatures: { type: Number, default: null },
});

const columns = computed(() => [
    { key: 'jurisdiction', label: t('c_legislature_workspace.index.col_jurisdiction', 'Jurisdiction') },
    { key: 'adm_level', label: t('c_legislature_workspace.index.col_level', 'Level') },
    { key: 'seats', label: t('c_legislature_workspace.index.col_seats', 'Seats'), align: 'right' },
    { key: 'status', label: t('c_legislature_workspace.index.col_status', 'Status') },
    { key: 'district_count', label: t('c_legislature_workspace.index.col_districts', 'Districts'), align: 'right' },
    { key: 'election', label: t('c_legislature_workspace.index.col_election', 'Election') },
    { key: 'activation', label: t('c_legislature_workspace.index.col_activation', 'Activation') },
]);

/* Election phase to badge tone. The English label is the t() fallback; the
   status key selects the catalog string (elections.status vocabulary,
   ElectionLifecycleService machine). Cancelled never reaches the page. */
const ELECTION_STATUS = {
    scheduled:       { tone: 'neutral', label: 'Scheduled' },
    approval_open:   { tone: 'info',    label: 'Approval open' },
    finalist_cutoff: { tone: 'info',    label: 'Finalist cutoff' },
    ranked_open:     { tone: 'warning', label: 'Ranked open' },
    voting_closed:   { tone: 'warning', label: 'Voting closed' },
    tabulating:      { tone: 'warning', label: 'Tabulating' },
    certified:       { tone: 'success', label: 'Certified' },
    audit_rerun:     { tone: 'warning', label: 'Audit rerun' },
    final:           { tone: 'success', label: 'Final' },
};

const electionBadge = (status) => {
    const entry = ELECTION_STATUS[status];
    if (!entry) return { tone: 'neutral', label: status };
    return { tone: entry.tone, label: t('c_legislature_workspace.index.election_' + status, entry.label) };
};

/* WF-JUR-01 state to badge tone + label. No activation row on the planet
   root = founded by the setup wizard (the activation engine never files a
   row for it); no row elsewhere = dormant boundary. */
function activationBadge(row) {
    switch (row.activation_state) {
        case 'self_governing':
            return { tone: 'success', label: t('c_legislature_workspace.index.act_self_governing', 'Self-governing') };
        case 'bootstrapping':
            return { tone: 'info', label: t('c_legislature_workspace.index.act_bootstrapping', 'Bootstrapping') };
        case 'critical_population':
            return { tone: 'warning', label: t('c_legislature_workspace.index.act_critical_population', 'Critical population') };
        case 'boundary_loaded':
            return { tone: 'neutral', label: t('c_legislature_workspace.index.act_dormant', 'Dormant') };
        default:
            return row.adm_level === 0
                ? { tone: 'success', label: t('c_legislature_workspace.index.act_founded', 'Founded at setup') }
                : { tone: 'neutral', label: t('c_legislature_workspace.index.act_dormant', 'Dormant') };
    }
}

/* Natural level labels (AdmChip's vocabulary; numeric adm levels are
   development terminology and never display). */
const ADM_NATURAL = ['Planet', 'Country', 'State / Province', 'County', 'Municipality', 'Township', 'Neighborhood'];

function admNatural(level) {
    const i = Math.min(Math.max(Math.trunc(level), 0), 6);
    return t('c_legislature_workspace.index.adm_' + i, ADM_NATURAL[i]);
}

const dateFormatter = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' });

function formatDate(iso) {
    if (!iso) return null;
    try {
        return dateFormatter.format(new Date(iso));
    } catch {
        return iso;
    }
}
</script>

<template>
    <PageScaffold :surface="surface">
        <template #intro>
            {{ t('c_legislature_workspace.index.intro', 'Every legislature on this instance. Setup founds the first — the root jurisdiction\'s; additional legislatures activate as jurisdictions reach critical population (CLK-06). Open a row to view its districts in the mapper; the Election column jumps straight to each chamber\'s current election and results.') }}
        </template>
        <template #about>
            <p>
                {{ t('c_legislature_workspace.index.about', 'WF-JUR-01 — jurisdictions bootstrap from dormant boundary to self-governing. Each activation seats a cube-root-sized legislature (bicameral where constituent jurisdictions exist, Art. V §3) plus executive and judiciary stubs.') }}
            </p>
        </template>

        <Card as="section">
            <p v-if="legislatures.length === 0" class="gloss">
                {{ t('c_legislature_workspace.index.empty', 'No legislatures yet — complete setup (apportionment) to found the first one.') }}
            </p>

            <p v-if="total_legislatures && total_legislatures > legislatures.length" class="gloss">
                {{ t('c_legislature_workspace.index.showing_largest', { shown: legislatures.length.toLocaleString(), total: total_legislatures.toLocaleString() }) }}
            </p>

            <DataTable
                v-if="legislatures.length > 0"
                :columns="columns"
                :rows="legislatures"
                row-key="id"
                :caption="t('c_legislature_workspace.index.caption', 'Legislatures, by jurisdiction')"
            >
                <template #cell-jurisdiction="{ row }">
                    <Link :href="`/legislatures/${row.slug}`" class="prose-link">{{ row.jurisdiction }}</Link>
                    <span class="cc-small mono" style="margin-inline-start: var(--space-2)">{{ row.slug }}</span>
                </template>

                <template #cell-adm_level="{ row }">
                    <AdmChip :level="row.adm_level" :label="admNatural(row.adm_level)" />
                </template>

                <template #cell-seats="{ row }">
                    <span class="mono">
                        {{ (row.type_a_seats + row.type_b_seats).toLocaleString() }}
                        <template v-if="row.type_b_seats > 0">
                            {{ t('c_legislature_workspace.index.seats_ab', { a: row.type_a_seats.toLocaleString(), b: row.type_b_seats.toLocaleString() }) }}
                        </template>
                    </span>
                </template>

                <!-- FE-C2 — status gains the seated/forming chamber badge;
                     seated chambers (members_count > 0) link to the Chamber
                     surface alongside the mapper link. -->
                <template #cell-status="{ row }">
                    <StatusBadge :tone="row.status === 'active' ? 'success' : 'neutral'">
                        {{ row.status }}
                    </StatusBadge>
                    <StatusBadge
                        :tone="row.members_count > 0 ? 'info' : 'neutral'"
                        style="margin-inline-start: var(--space-1)"
                    >{{ row.members_count > 0 ? t('c_legislature_workspace.index.seated', 'seated') : t('c_legislature_workspace.index.forming', 'forming') }}</StatusBadge>
                    <Link
                        v-if="row.members_count > 0"
                        :href="`/legislatures/${row.id}/chamber`"
                        class="prose-link"
                        style="margin-inline-start: var(--space-2)"
                    >{{ t('c_legislature_workspace.index.chamber', 'Chamber') }}</Link>
                </template>

                <template #cell-district_count="{ row }">
                    <span class="mono">{{ row.district_count.toLocaleString() }}</span>
                </template>

                <!-- Per-chamber election affordances: current election link +
                     phase badge, and a Results link once certified. -->
                <template #cell-election="{ row }">
                    <template v-if="row.election">
                        <Link :href="`/elections/${row.election.id}`" class="prose-link">{{ t('c_legislature_workspace.index.election', 'Election') }}</Link>
                        <StatusBadge
                            :tone="electionBadge(row.election.status).tone"
                            style="margin-inline-start: var(--space-2)"
                        >{{ electionBadge(row.election.status).label }}</StatusBadge>
                        <Link
                            v-if="row.results_election_id"
                            :href="`/elections/${row.results_election_id}/results`"
                            class="prose-link"
                            style="margin-inline-start: var(--space-2)"
                        >{{ t('c_legislature_workspace.index.results', 'Results') }}</Link>
                    </template>
                    <span v-else class="gloss">—</span>
                </template>

                <template #cell-activation="{ row }">
                    <StatusBadge :tone="activationBadge(row).tone">
                        {{ activationBadge(row).label }}
                    </StatusBadge>
                    <span
                        v-if="row.activation_state === 'self_governing' && formatDate(row.activated_at)"
                        class="cc-small"
                        style="margin-inline-start: var(--space-2)"
                    >{{ t('c_legislature_workspace.index.since', { date: formatDate(row.activated_at) }) }}</span>
                </template>
            </DataTable>
        </Card>
    </PageScaffold>
</template>
