<script setup>
/**
 * Operator/Moderation — "Moderation & the legal floor" (design contract:
 * mockups/v3/operator/moderation.html). A READ/explainer surface — the
 * mockup carries zero forms and zero buttons, deliberately: the operator
 * holds no power to remove on viewpoint, the legitimacy flip is a pure
 * function of facts, and the M-5 legal floor is a closed list grown only
 * by code release. The teaching structure renders over the REAL sealed
 * trail (matrix_carveout_log + legal_compliance_removals), so "everything
 * is logged" is shown, not asserted.
 */
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { plainState } from '@/lib/plain.js';

defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, default: null },
    authed: { type: Boolean, default: false },
    operator: { type: String, default: null },
    /** null for a citizen session — see the controller's gate. */
    moderation: { type: Object, default: null },
});

const carveouts = [
    { key: 'm1_judicial', name: 'Judicial order', who: 'A judge — once a government is seated', logged: true },
    { key: 'm2_rights', name: 'Rights protection', who: 'Operator relay below the flip → judicial above it', logged: true },
    { key: 'm3_per_user', name: 'Per-user block', who: 'Each person, their own screen only', logged: false },
    { key: 'm4_antispam', name: 'Anti-spam', who: 'The system — behaviour, never viewpoint', logged: true },
];

const legalBases = [
    { key: 'csam_hashmatch', name: 'Illegal-image match', effect: 'Purges the bytes — delete, not quarantine' },
    { key: 'court_order_specific', name: 'Specific court order', effect: 'Redacts the named event' },
    { key: 'true_threat', name: 'True threat', effect: 'Redacts the named event' },
];
</script>

<template>
    <PageScaffold :surface="surface" title="Moderation & the legal floor">
        <template #intro>
            The operator holds no power to remove on viewpoint — hosting buys no say. Below
            the flip an operator can only relay narrow protections, everything logged; the
            moment a government seats itself, removal authority passes to judges and the
            operator's relay leg closes automatically.
        </template>

        <Card v-if="!authed" as="section" title="Operator sign-in required">
            <p>The sealed moderation trail is shown only to a signed-in operator of this box.</p>
            <p>
                <Btn as="a" href="/operator/login" variant="primary" icon="arrow-right">
                    Sign in as an operator
                </Btn>
            </p>
        </Card>

        <template v-else>
            <Card as="section" title="The legitimacy flip">
                <p>
                    The flip is automatic — a pure function of facts, never a manual mode
                    change. Below: the operator board relays narrow protections. Above: only
                    a live judicial attestation removes anything.
                </p>
                <div class="cluster">
                    <Stat label="Seated legislatures on this box" :value="moderation?.seated_legislatures ?? 0" />
                </div>
                <div class="grid-2">
                    <div>
                        <h4>Below the flip</h4>
                        <StatusBadge tone="neutral">No seated government</StatusBadge>
                        <p>
                            The operator may relay a rights-protection removal — logged with
                            basis <span data-no-i18n>operator_relay</span>, so it can never be
                            mistaken for a judge's ruling.
                        </p>
                    </div>
                    <div>
                        <h4>Above the flip</h4>
                        <StatusBadge tone="success" icon="check">A legislature is seated</StatusBadge>
                        <p>
                            Only a live, key-pinned judicial attestation (R-19/R-20) removes
                            anything. The operator is no longer honoured — by code, not policy.
                        </p>
                    </div>
                </div>
            </Card>

            <Card as="section" title="The four carve-outs">
                <DataTable
                    :columns="[
                        { key: 'name', label: 'Carve-out' },
                        { key: 'who', label: 'Who may invoke' },
                        { key: 'logged', label: 'Logged?' },
                        { key: 'count', label: 'On this box' },
                    ]"
                    :rows="carveouts"
                    row-key="key"
                >
                    <template #cell-logged="{ row }">
                        <StatusBadge v-if="row.logged" tone="info">Logged</StatusBadge>
                        <StatusBadge v-else tone="neutral">Never logged</StatusBadge>
                    </template>
                    <template #cell-count="{ row }">
                        <span v-if="row.logged" data-no-i18n>{{ moderation?.carveout_counts?.[row.key] ?? 0 }}</span>
                        <span v-else>— (yours alone)</span>
                    </template>
                </DataTable>
                <p>
                    <strong>There is no "remove for content" control.</strong> It does not
                    exist to be granted, delegated, or seized — a per-user block affects only
                    the blocker's own screen and is never recorded anywhere.
                </p>
            </Card>

            <Card as="section" title="The legal floor (M-5)">
                <p>
                    An operator account by key-possession — not a standing attestation, off
                    the constitutional plane. The list of legal bases is closed, grown only
                    by code release; the list SOURCE is recorded, never the hash; the trail
                    is append-only.
                </p>
                <DataTable
                    :columns="[
                        { key: 'name', label: 'Legal basis' },
                        { key: 'effect', label: 'What it does' },
                        { key: 'count', label: 'On this box' },
                    ]"
                    :rows="legalBases"
                    row-key="key"
                >
                    <template #cell-count="{ row }">
                        <span data-no-i18n>{{ moderation?.legal_counts?.[row.key] ?? 0 }}</span>
                    </template>
                </DataTable>
                <p class="citation">Key-possession · closed list · append-only sealed trail · F-SOC-004</p>
            </Card>

            <Card v-if="(moderation?.recent_carveouts ?? []).length || (moderation?.recent_legal ?? []).length" as="section" title="The sealed trail — most recent">
                <DataTable
                    v-if="(moderation?.recent_carveouts ?? []).length"
                    caption="Recent carve-out log rows"
                    :columns="[
                        { key: 'carve_out', label: 'Carve-out' },
                        { key: 'action', label: 'Action' },
                        { key: 'judicial', label: 'Authority' },
                        { key: 'at', label: 'When' },
                    ]"
                    :rows="moderation?.recent_carveouts ?? []"
                >
                    <template #cell-carve_out="{ row }"><span data-no-i18n>{{ plainState(row.carve_out) }}</span></template>
                    <template #cell-action="{ row }"><span data-no-i18n>{{ plainState(row.action) }}</span></template>
                    <template #cell-judicial="{ row }">
                        <StatusBadge v-if="row.judicial" tone="info">Judicial attestation</StatusBadge>
                        <StatusBadge v-else tone="neutral">{{ row.seated_at_time ? 'System' : 'Operator relay' }}</StatusBadge>
                    </template>
                    <template #cell-at="{ row }"><span data-no-i18n>{{ new Date(row.at).toLocaleString() }}</span></template>
                </DataTable>
                <DataTable
                    v-if="(moderation?.recent_legal ?? []).length"
                    caption="Recent legal-floor rows"
                    :columns="[
                        { key: 'legal_basis', label: 'Basis' },
                        { key: 'action', label: 'Action' },
                        { key: 'physical_removal_status', label: 'Physical removal' },
                        { key: 'at', label: 'When' },
                    ]"
                    :rows="moderation?.recent_legal ?? []"
                >
                    <template #cell-legal_basis="{ row }"><span data-no-i18n>{{ plainState(row.legal_basis) }}</span></template>
                    <template #cell-physical_removal_status="{ row }">
                        <StatusBadge :tone="row.physical_removal_status === 'done' ? 'success' : row.physical_removal_status === 'failed' ? 'danger' : 'neutral'">
                            {{ plainState(row.physical_removal_status) }}
                        </StatusBadge>
                    </template>
                    <template #cell-at="{ row }"><span data-no-i18n>{{ new Date(row.at).toLocaleString() }}</span></template>
                </DataTable>
            </Card>
            <Banner v-else tone="info" role="status">
                <strong>The trail is empty.</strong> No carve-out has ever been invoked and
                no legal-floor removal has ever been filed on this box.
            </Banner>
        </template>

        <template #about>
            <p>
                Preserve → report → purge is the M-5 sequence; a removal never server-ACLs a
                peer and never records a hash. See it from the citizen side on
                <a href="/civic/square">the public square</a>.
            </p>
        </template>
    </PageScaffold>
</template>
