<script setup>import { useLocaleFormat } from '@/composables/useLocaleFormat';
const localeFmt = useLocaleFormat();
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
import HostNav from '@/Components/Operator/HostNav.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { plainState } from '@/lib/plain.js';
import { useI18n } from 'vue-i18n';

defineOptions({ layout: AppShellV2 });

const { t } = useI18n();

const props = defineProps({
    surface: { type: Object, default: null },
    authed: { type: Boolean, default: false },
    operator: { type: String, default: null },
    /** null for a citizen session — see the controller's gate. */
    moderation: { type: Object, default: null },
});

const carveouts = [
    { key: 'm1_judicial', name: t('c_operator_pages.moderation.carve_m1_name', 'Judicial order'), who: t('c_operator_pages.moderation.carve_m1_who', 'A judge — once a government is seated'), logged: true },
    { key: 'm2_rights', name: t('c_operator_pages.moderation.carve_m2_name', 'Rights protection'), who: t('c_operator_pages.moderation.carve_m2_who', 'Operator relay below the flip → judicial above it'), logged: true },
    { key: 'm3_per_user', name: t('c_operator_pages.moderation.carve_m3_name', 'Per-user block'), who: t('c_operator_pages.moderation.carve_m3_who', 'Each person, their own screen only'), logged: false },
    { key: 'm4_antispam', name: t('c_operator_pages.moderation.carve_m4_name', 'Anti-spam'), who: t('c_operator_pages.moderation.carve_m4_who', 'The system — behaviour, never viewpoint'), logged: true },
];

const legalBases = [
    { key: 'csam_hashmatch', name: t('c_operator_pages.moderation.legal_csam_name', 'Illegal-image match'), effect: t('c_operator_pages.moderation.legal_csam_effect', 'Purges the bytes — delete, not quarantine') },
    { key: 'court_order_specific', name: t('c_operator_pages.moderation.legal_court_name', 'Specific court order'), effect: t('c_operator_pages.moderation.legal_court_effect', 'Redacts the named event') },
    { key: 'true_threat', name: t('c_operator_pages.moderation.legal_threat_name', 'True threat'), effect: t('c_operator_pages.moderation.legal_threat_effect', 'Redacts the named event') },
];
</script>

<template>
    <PageScaffold :surface="surface" :title="t('c_operator_pages.moderation.page_title', 'Moderation & the legal floor')">
        <HostNav current="moderation" />
        <template #intro>
            {{ t('c_operator_pages.moderation.intro', "The operator holds no power to remove on viewpoint — hosting buys no say. Below the flip an operator can only relay narrow protections, everything logged; the moment a government seats itself, removal authority passes to judges and the operator's relay leg closes automatically.") }}
        </template>

        <Card v-if="!authed" as="section" :title="t('c_operator_pages.moderation.signin_title', 'Operator sign-in required')">
            <p>{{ t('c_operator_pages.moderation.signin_body', 'The sealed moderation trail is shown only to a signed-in operator of this box.') }}</p>
            <p>
                <Btn as="a" href="/operator/login" variant="primary" icon="arrow-right">
                    {{ t('c_operator_pages.moderation.signin_btn', 'Sign in as an operator') }}
                </Btn>
            </p>
        </Card>

        <template v-else>
            <Card as="section" :title="t('c_operator_pages.moderation.flip_title', 'The legitimacy flip')">
                <p>
                    {{ t('c_operator_pages.moderation.flip_body', 'The flip is automatic — a pure function of facts, never a manual mode change. Below: the operator board relays narrow protections. Above: only a live judicial attestation removes anything.') }}
                </p>
                <div class="cluster">
                    <Stat :label="t('c_operator_pages.moderation.stat_seated', 'Seated legislatures on this box')" :value="moderation?.seated_legislatures ?? 0" />
                </div>
                <div class="grid-2">
                    <div>
                        <h4>{{ t('c_operator_pages.moderation.below_flip', 'Below the flip') }}</h4>
                        <StatusBadge tone="neutral">{{ t('c_operator_pages.moderation.no_seated_gov', 'No seated government') }}</StatusBadge>
                        <p>
                            {{ t('c_operator_pages.moderation.below_body_before', 'The operator may relay a rights-protection removal — logged with basis') }} <span data-no-i18n>operator_relay</span>{{ t('c_operator_pages.moderation.below_body_after', ", so it can never be mistaken for a judge's ruling.") }}
                        </p>
                    </div>
                    <div>
                        <h4>{{ t('c_operator_pages.moderation.above_flip', 'Above the flip') }}</h4>
                        <StatusBadge tone="success" icon="check">{{ t('c_operator_pages.moderation.leg_seated', 'A legislature is seated') }}</StatusBadge>
                        <p>
                            {{ t('c_operator_pages.moderation.above_body', 'Only a live, key-pinned judicial attestation (R-19/R-20) removes anything. The operator is no longer honoured — by code, not policy.') }}
                        </p>
                    </div>
                </div>
            </Card>

            <Card as="section" :title="t('c_operator_pages.moderation.carveouts_title', 'The four carve-outs')">
                <DataTable
                    :columns="[
                        { key: 'name', label: t('c_operator_pages.moderation.col_carveout', 'Carve-out') },
                        { key: 'who', label: t('c_operator_pages.moderation.col_who', 'Who may invoke') },
                        { key: 'logged', label: t('c_operator_pages.moderation.col_logged_q', 'Logged?') },
                        { key: 'count', label: t('c_operator_pages.moderation.col_on_box', 'On this box') },
                    ]"
                    :rows="carveouts"
                    row-key="key"
                >
                    <template #cell-logged="{ row }">
                        <StatusBadge v-if="row.logged" tone="info">{{ t('c_operator_pages.moderation.logged', 'Logged') }}</StatusBadge>
                        <StatusBadge v-else tone="neutral">{{ t('c_operator_pages.moderation.never_logged', 'Never logged') }}</StatusBadge>
                    </template>
                    <template #cell-count="{ row }">
                        <span v-if="row.logged" data-no-i18n>{{ moderation?.carveout_counts?.[row.key] ?? 0 }}</span>
                        <span v-else>{{ t('c_operator_pages.moderation.yours_alone', '— (yours alone)') }}</span>
                    </template>
                </DataTable>
                <p>
                    <strong>{{ t('c_operator_pages.moderation.no_content_control', 'There is no "remove for content" control.') }}</strong> {{ t('c_operator_pages.moderation.no_content_control_after', "It does not exist to be granted, delegated, or seized — a per-user block affects only the blocker's own screen and is never recorded anywhere.") }}
                </p>
            </Card>

            <Card as="section" :title="t('c_operator_pages.moderation.legal_floor_title', 'The legal floor (M-5)')">
                <p>
                    {{ t('c_operator_pages.moderation.legal_floor_body', 'An operator account by key-possession — not a standing attestation, off the constitutional plane. The list of legal bases is closed, grown only by code release; the list SOURCE is recorded, never the hash; the trail is append-only.') }}
                </p>
                <DataTable
                    :columns="[
                        { key: 'name', label: t('c_operator_pages.moderation.col_legal_basis', 'Legal basis') },
                        { key: 'effect', label: t('c_operator_pages.moderation.col_what', 'What it does') },
                        { key: 'count', label: t('c_operator_pages.moderation.col_on_box', 'On this box') },
                    ]"
                    :rows="legalBases"
                    row-key="key"
                >
                    <template #cell-count="{ row }">
                        <span data-no-i18n>{{ moderation?.legal_counts?.[row.key] ?? 0 }}</span>
                    </template>
                </DataTable>
                <p class="citation">{{ t('c_operator_pages.moderation.legal_floor_cite', 'Key-possession · closed list · append-only sealed trail · F-SOC-004') }}</p>
            </Card>

            <Card v-if="(moderation?.recent_carveouts ?? []).length || (moderation?.recent_legal ?? []).length" as="section" :title="t('c_operator_pages.moderation.sealed_trail_title', 'The sealed trail — most recent')">
                <DataTable
                    v-if="(moderation?.recent_carveouts ?? []).length"
                    :caption="t('c_operator_pages.moderation.cap_carveout_rows', 'Recent carve-out log rows')"
                    :columns="[
                        { key: 'carve_out', label: t('c_operator_pages.moderation.col_carveout', 'Carve-out') },
                        { key: 'action', label: t('c_operator_pages.moderation.col_action', 'Action') },
                        { key: 'judicial', label: t('c_operator_pages.moderation.col_authority', 'Authority') },
                        { key: 'at', label: t('c_operator_pages.moderation.col_when', 'When') },
                    ]"
                    :rows="moderation?.recent_carveouts ?? []"
                >
                    <template #cell-carve_out="{ row }"><span data-no-i18n>{{ plainState(row.carve_out) }}</span></template>
                    <template #cell-action="{ row }"><span data-no-i18n>{{ plainState(row.action) }}</span></template>
                    <template #cell-judicial="{ row }">
                        <StatusBadge v-if="row.judicial" tone="info">{{ t('c_operator_pages.moderation.judicial_attestation', 'Judicial attestation') }}</StatusBadge>
                        <StatusBadge v-else tone="neutral">{{ row.seated_at_time ? t('c_operator_pages.moderation.system', 'System') : t('c_operator_pages.moderation.operator_relay', 'Operator relay') }}</StatusBadge>
                    </template>
                    <template #cell-at="{ row }"><span data-no-i18n>{{ localeFmt.dateTime(new Date(row.at)) }}</span></template>
                </DataTable>
                <DataTable
                    v-if="(moderation?.recent_legal ?? []).length"
                    :caption="t('c_operator_pages.moderation.cap_legal_rows', 'Recent legal-floor rows')"
                    :columns="[
                        { key: 'legal_basis', label: t('c_operator_pages.moderation.col_basis', 'Basis') },
                        { key: 'action', label: t('c_operator_pages.moderation.col_action', 'Action') },
                        { key: 'physical_removal_status', label: t('c_operator_pages.moderation.col_physical', 'Physical removal') },
                        { key: 'at', label: t('c_operator_pages.moderation.col_when', 'When') },
                    ]"
                    :rows="moderation?.recent_legal ?? []"
                >
                    <template #cell-legal_basis="{ row }"><span data-no-i18n>{{ plainState(row.legal_basis) }}</span></template>
                    <template #cell-physical_removal_status="{ row }">
                        <StatusBadge :tone="row.physical_removal_status === 'done' ? 'success' : row.physical_removal_status === 'failed' ? 'danger' : 'neutral'">
                            {{ plainState(row.physical_removal_status) }}
                        </StatusBadge>
                    </template>
                    <template #cell-at="{ row }"><span data-no-i18n>{{ localeFmt.dateTime(new Date(row.at)) }}</span></template>
                </DataTable>
            </Card>
            <Banner v-else tone="info" role="status">
                <strong>{{ t('c_operator_pages.moderation.trail_empty', 'The trail is empty.') }}</strong> {{ t('c_operator_pages.moderation.trail_empty_after', 'No carve-out has ever been invoked and no legal-floor removal has ever been filed on this box.') }}
            </Banner>
        </template>

        <template #about>
            <p>
                {{ t('c_operator_pages.moderation.about_before', 'Preserve → report → purge is the M-5 sequence; a removal never server-ACLs a peer and never records a hash. See it from the citizen side on') }}
                <a href="/civic/square">{{ t('c_operator_pages.moderation.about_link', 'the public square') }}</a>{{ t('c_operator_pages.moderation.about_after', '.') }}
            </p>
        </template>
    </PageScaffold>
</template>
