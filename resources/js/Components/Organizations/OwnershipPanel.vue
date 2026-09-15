<script setup>
/**
 * Org/OwnershipPanel — ownership structure display (FE-D1;
 * PHASE_D_DESIGN_frontend.md §A.4). Structure chip + the structure's own
 * one-line consent rule (transfers-conversions.html internal-restructuring
 * copy), the stakes table, and the member/worker stat cluster.
 *
 * CGC variant swaps the stakes table for the owner-ruling card (owner
 * ruling #12): in a Common Good Corporation the Board of Governors stands
 * where shareholders would.
 *
 * Restructuring history renders as append-only LogRows — structure
 * history is preserved on the public record (WF-ORG-06 internal path).
 */
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import DataTable from '@/Components/Ui/DataTable.vue';
import LogRow from '@/Components/Ui/LogRow.vue';
import Stat from '@/Components/Ui/Stat.vue';
import TagChip from '@/Components/Ui/TagChip.vue';

const { t } = useI18n();

const props = defineProps({
    /** 'stock'|'partnership'|'equal_partnership'|'member_owned'|'worker_owned'|'nonprofit' */
    structure: { type: String, default: null },
    isCgc: { type: Boolean, default: false },
    /** org_ownership_stakes: [{ holder:{type,name,href}, units, pct }] */
    stakes: { type: Array, default: () => [] },
    /** { members?, shareholders?, partners?, workers? } — active org_memberships/org_workers. */
    memberCounts: { type: Object, default: () => ({}) },
    /** Restructuring events (preserved per WF-ORG-06 internal path). */
    structureHistory: { type: Array, default: () => [] },
});

/* The current structure's own consent rule — internal restructuring
   proceeds by owner consent under these rules, never the legislature
   (transfers-conversions.html §restructure). */
const STRUCTURE_RULES = () => ({
    stock: t('c_institution_components.ownership_panel.rule_stock', 'Owner consent counts voting shares — the share register decides.'),
    partnership: t('c_institution_components.ownership_panel.rule_partnership', 'Changes proceed by the partnership agreement’s own consent rule.'),
    equal_partnership: t('c_institution_components.ownership_panel.rule_equal_partnership', 'Partnership changes require unanimity of partners.'),
    member_owned: t('c_institution_components.ownership_panel.rule_member_owned', 'Member-owned — changes follow the membership’s own adopted rules.'),
    worker_owned: t('c_institution_components.ownership_panel.rule_worker_owned', 'Worker-owned — the worker-members are the owner side.'),
    nonprofit: t('c_institution_components.ownership_panel.rule_nonprofit', 'Nonprofit — no ownership stakes; the board governs per its charter.'),
});

const structureLabel = computed(() =>
    props.isCgc ? t('c_institution_components.ownership_panel.cgc_structure', 'Common Good Corporation') : (props.structure ?? '—').replaceAll('_', ' '),
);
const rule = computed(() => (props.isCgc ? null : STRUCTURE_RULES()[props.structure] ?? null));

const COUNT_LABELS = () => ({
    members: t('c_institution_components.ownership_panel.count_members', 'members'),
    shareholders: t('c_institution_components.ownership_panel.count_shareholders', 'shareholders'),
    partners: t('c_institution_components.ownership_panel.count_partners', 'partners'),
    workers: t('c_institution_components.ownership_panel.count_workers', 'workers'),
});
const counts = computed(() =>
    Object.entries(props.memberCounts ?? {})
        .filter(([, v]) => v !== null && v !== undefined)
        .map(([key, value]) => ({ key, value, label: COUNT_LABELS()[key] ?? key })),
);

const fmt = (n) => Number(n).toLocaleString();
</script>

<template>
    <div class="stack" style="gap: var(--space-3)">
        <p class="cluster" style="gap: var(--space-2); margin: 0">
            <TagChip data-no-i18n>structure: {{ structureLabel }}</TagChip>
            <span v-if="rule" class="cc-small">{{ rule }}</span>
        </p>

        <div v-if="counts.length" class="cluster" style="gap: var(--space-6)">
            <Stat
                v-for="count in counts"
                :key="count.key"
                :value="fmt(count.value)"
                :label="count.label"
                :accent="count.key === 'workers'"
            />
        </div>

        <!-- CGC variant: the owner-ruling card stands where the stakes table would -->
        <div v-if="isCgc" class="card card--inset">
            <p style="margin: 0">
                {{ t('c_institution_components.ownership_panel.cgc_body', 'In a Common Good Corporation the Board of Governors stands where shareholders would — the owner side runs on the share system everywhere else.') }}
            </p>
            <p class="citation" style="margin-block-start: var(--space-1)">
                {{ t('c_institution_components.ownership_panel.cgc_cite', 'Art. III §5–6 · as implemented (ledger #12)') }}
            </p>
        </div>

        <DataTable
            v-else-if="stakes.length"
            :columns="[
                { key: 'holder', label: t('c_institution_components.ownership_panel.col_holder', 'Holder') },
                { key: 'units', label: t('c_institution_components.ownership_panel.col_units', 'Units'), mono: true, align: 'right' },
                { key: 'pct', label: '%', mono: true, align: 'right' },
            ]"
            :rows="stakes"
            :caption="t('c_institution_components.ownership_panel.stakes_caption', 'Ownership stakes')"
        >
            <template #cell-holder="{ row }">
                <a v-if="row.holder.href" :href="row.holder.href">{{ row.holder.name }}</a>
                <template v-else>{{ row.holder.name }}</template>
                <span class="citation" style="display: block">{{ row.holder.type }}</span>
            </template>
            <template #cell-units="{ row }">{{ fmt(row.units) }}</template>
            <template #cell-pct="{ row }">{{ row.pct }}%</template>
        </DataTable>
        <p v-else class="gloss">{{ t('c_institution_components.ownership_panel.no_stakes', 'No ownership stakes on record for this structure.') }}</p>

        <template v-if="structureHistory.length">
            <p class="citation" style="margin: 0">{{ t('c_institution_components.ownership_panel.history_preserved', 'structure history preserved — append-only record') }}</p>
            <div>
                <LogRow v-for="(event, i) in structureHistory" :key="event.seq ?? i" :seq="event.seq ?? i + 1">
                    <span style="flex: 1 1 16rem; min-inline-size: 0" data-no-i18n>
                        {{ (event.from_structure ?? '—').replaceAll('_', ' ') }} →
                        <strong>{{ (event.to_structure ?? '—').replaceAll('_', ' ') }}</strong>
                        <span class="citation" style="display: block">
                            {{ event.rule_applied }} · {{ event.at }}
                        </span>
                    </span>
                </LogRow>
            </div>
        </template>
    </div>
</template>
