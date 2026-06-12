<script setup>
/**
 * Legislature/Index — WI-9 multi-legislature switcher.
 *
 * Lists every legislature on the instance (setup founds the first — the
 * root jurisdiction's; CLK-06 activations add more as jurisdictions reach
 * critical population). Each row links into that legislature's district
 * mapper at /legislatures/{slug} — no UUID memorization required.
 */
import { Link } from '@inertiajs/vue3';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import AdmChip from '@/Components/Ui/AdmChip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

const props = defineProps({
    surface: { type: Object, required: true },
    /** [{ id, jurisdiction, slug, adm_level, type_a_seats, type_b_seats,
     *     status, district_count, activation_state, activated_at }] */
    legislatures: { type: Array, required: true },
});

const columns = [
    { key: 'jurisdiction', label: 'Jurisdiction' },
    { key: 'adm_level', label: 'Level' },
    { key: 'seats', label: 'Seats', align: 'right' },
    { key: 'status', label: 'Status' },
    { key: 'district_count', label: 'Districts', align: 'right' },
    { key: 'activation', label: 'Activation' },
];

/* WF-JUR-01 state → badge tone + label. No activation row on the planet
   root = founded by the setup wizard (the activation engine never files a
   row for it); no row elsewhere = dormant boundary. */
function activationBadge(row) {
    switch (row.activation_state) {
        case 'self_governing':
            return { tone: 'success', label: 'Self-governing' };
        case 'bootstrapping':
            return { tone: 'info', label: 'Bootstrapping' };
        case 'critical_population':
            return { tone: 'warning', label: 'Critical population' };
        case 'boundary_loaded':
            return { tone: 'neutral', label: 'Dormant' };
        default:
            return row.adm_level === 0
                ? { tone: 'success', label: 'Founded at setup' }
                : { tone: 'neutral', label: 'Dormant' };
    }
}

/* Natural level labels (AdmChip's vocabulary — numeric adm levels are
   development terminology and never display). */
const ADM_NATURAL = ['Planet', 'Country', 'State / Province', 'County', 'Municipality', 'Township', 'Neighborhood'];

function admNatural(level) {
    return ADM_NATURAL[Math.min(Math.max(Math.trunc(level), 0), 6)];
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
            Every legislature on this instance. Setup founds the first — the root
            jurisdiction's; additional legislatures activate as jurisdictions reach
            critical population (CLK-06). Open a row to view its districts in the mapper.
        </template>
        <template #about>
            <p>
                WF-JUR-01 — jurisdictions bootstrap from dormant boundary to
                self-governing. Each activation seats a cube-root-sized legislature
                (bicameral where constituent jurisdictions exist, Art. V §3) plus
                executive and judiciary stubs.
            </p>
        </template>

        <Card as="section">
            <p v-if="legislatures.length === 0" class="gloss">
                No legislatures yet — complete setup (apportionment) to found the first one.
            </p>

            <DataTable
                v-else
                :columns="columns"
                :rows="legislatures"
                row-key="id"
                caption="All legislatures, by jurisdiction"
            >
                <template #cell-jurisdiction="{ row }">
                    <Link :href="`/legislatures/${row.slug}`">{{ row.jurisdiction }}</Link>
                    <span class="cc-small mono" style="margin-inline-start: var(--space-2)">{{ row.slug }}</span>
                </template>

                <template #cell-adm_level="{ row }">
                    <AdmChip :level="row.adm_level" :label="admNatural(row.adm_level)" />
                </template>

                <template #cell-seats="{ row }">
                    <span class="mono">
                        {{ (row.type_a_seats + row.type_b_seats).toLocaleString() }}
                        <template v-if="row.type_b_seats > 0">
                            ({{ row.type_a_seats.toLocaleString() }} A + {{ row.type_b_seats.toLocaleString() }} B)
                        </template>
                    </span>
                </template>

                <template #cell-status="{ row }">
                    <StatusBadge :tone="row.status === 'active' ? 'success' : 'neutral'">
                        {{ row.status }}
                    </StatusBadge>
                </template>

                <template #cell-district_count="{ row }">
                    <span class="mono">{{ row.district_count.toLocaleString() }}</span>
                </template>

                <template #cell-activation="{ row }">
                    <StatusBadge :tone="activationBadge(row).tone">
                        {{ activationBadge(row).label }}
                    </StatusBadge>
                    <span
                        v-if="row.activation_state === 'self_governing' && formatDate(row.activated_at)"
                        class="cc-small"
                        style="margin-inline-start: var(--space-2)"
                    >since {{ formatDate(row.activated_at) }}</span>
                </template>
            </DataTable>
        </Card>
    </PageScaffold>
</template>
