<script setup>import { useLocaleFormat } from '@/composables/useLocaleFormat';
const localeFmt = useLocaleFormat();
/**
 * Legislature/Settings — FE-C5 (PHASE_C_DESIGN_frontend.md §B.11;
 * surface legislature/settings).
 *
 * The 17-key amendable register: AmendableSetting rows with hardened
 * bounds + enacting-act provenance (or "founding value · inherited from
 * {ancestor}"), the civil/judicial lockstep pair joined, the hardened-
 * floor card, per-row "Propose change" deep-link into the Bills intro
 * (pre-targeted F-LEG-031 path with the same live bounds pre-flight),
 * and the changes-history table — the Phase C exit-criterion receipt.
 *
 * Zero writes on this surface: proposals are BILLS; values mutate only
 * at enactment.
 */
import { computed, ref, watch } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import AmendableSetting from '@/Components/Ui/AmendableSetting.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import HistoryPager from '@/Components/Ui/HistoryPager.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    legislature: { type: Object, required: true },
    settings: { type: Array, required: true },
    lockstepKeys: { type: Array, default: () => [] },
    hardenedFloor: { type: Object, required: true },
    changes: { type: Array, default: () => [] },
    change_pages: { type: Object, default: () => ({ previous: null, next: null, first: '' }) },
    can: { type: Object, default: () => ({ propose: false }) },
});

const page = usePage();
const { t } = useI18n();
const flashStatus = computed(() => page.props.flash?.status ?? null);

function displayValue(setting) {
    if (setting.value === null || setting.value === undefined) return t('c_legislature_workspace.settings_page.val_default', '(default)');
    if (typeof setting.value === 'boolean') return setting.value ? 'true' : 'false';
    return String(setting.value);
}

function boundsLine(setting) {
    if (!setting.bounds) return t('c_legislature_workspace.settings_page.bounds_engine', 'engine-validated against its rule');
    if (setting.bounds.allowed) return t('c_legislature_workspace.settings_page.bounds_allowed', { list: JSON.stringify(setting.bounds.allowed) }, 'allowed: {list}');
    return t('c_legislature_workspace.settings_page.bounds_range', { min: setting.bounds.min, max: setting.bounds.max }, 'hardened range [{min}, {max}]');
}

function provenance(setting) {
    if (setting.enacted_by) {
        return t('c_legislature_workspace.settings_page.prov_set_by', { act: setting.enacted_by.act_number, date: localeFmt.date(new Date(setting.enacted_by.effective_at)) }, 'set by {act} · effective {date}');
    }
    if (setting.inherited_from) {
        return t('c_legislature_workspace.settings_page.prov_inherited', { name: setting.inherited_from.jurisdiction_name }, 'founding value · inherited from {name}');
    }
    return t('c_legislature_workspace.settings_page.prov_default', 'Template default · in force since founding');
}

/* The lockstep pair renders as one joined row. */
const rows = computed(() => {
    const out = [];
    let lockstepDone = false;
    for (const setting of props.settings) {
        if (props.lockstepKeys.includes(setting.key)) {
            if (lockstepDone) continue;
            lockstepDone = true;
            out.push({
                joined: props.settings.filter((s) => props.lockstepKeys.includes(s.key)),
                key: 'lockstep',
            });
        } else {
            out.push({ joined: null, key: setting.key, setting });
        }
    }
    return out;
});

/* ----------------------------------------- pre-targeted bill panel ----- */
const target = ref(null);
const proposedValue = ref('');
const preflight = ref(null);
let timer = null;

const targetSetting = computed(() => props.settings.find((s) => s.key === target.value) ?? null);

function propose(key) {
    target.value = key;
    const setting = props.settings.find((s) => s.key === key);
    proposedValue.value = setting?.value ?? '';
    preflight.value = null;
    document.getElementById('propose-panel')?.scrollIntoView({ block: 'nearest' });
}

watch(proposedValue, () => {
    preflight.value = null;
    if (!target.value || proposedValue.value === '') return;
    clearTimeout(timer);
    timer = setTimeout(async () => {
        try {
            const response = await fetch(`/legislatures/${props.legislature.id}/bills/validate`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                body: JSON.stringify({ setting_key: target.value, value: proposedValue.value }),
            });
            preflight.value = await response.json();
        } catch {
            preflight.value = null;
        }
    }, 350);
});

const deepLink = computed(() =>
    target.value
        ? `/legislatures/${props.legislature.id}/bills?intro=1&setting=${target.value}`
        : null,
);

/* R-C — the direct F-LEG-031 amendment door: file the setting-change bill from
   the register itself, without a detour through the full Bills intro. */
const amendForm = useForm({ setting_key: '', value: '' });
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

function submitAmendment() {
    if (!target.value || proposedValue.value === '') return;
    amendForm.setting_key = target.value;
    amendForm.value = proposedValue.value;
    amendForm.post(`/legislatures/${props.legislature.id}/settings/amend`, {
        preserveScroll: true,
        onSuccess: () => { preflight.value = null; },
    });
}

function fmt(iso) {
    return iso ? localeFmt.dateTime(new Date(iso)) : '—';
}
</script>

<template>
    <PageScaffold :surface="surface" :title="t('c_legislature_workspace.settings_page.title', { name: legislature.name }, 'Constitutional settings register — {name}')">
        <template #intro>
            {{ t('c_legislature_workspace.settings_page.intro', 'These are the rules this legislature can change by passing a law (its "constitutional settings") — each one inside limits that no law can override. Out-of-range proposals are rejected before any vote is taken.') }}
        </template>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>

        <!-- ============================================== register ====== -->
        <Card as="section" :title="t('c_legislature_workspace.settings_page.register_title', 'The amendable register')">
            <div class="table-wrap">
                <table class="table">
                    <caption class="visually-hidden">{{ t('c_legislature_workspace.settings_page.register_caption', 'Amendable constitutional settings') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ t('c_legislature_workspace.settings_page.col_setting', 'Setting') }}</th>
                            <th scope="col">{{ t('c_legislature_workspace.settings_page.col_value', 'Value') }}</th>
                            <th scope="col">{{ t('c_legislature_workspace.settings_page.col_bounds', 'Hardened bounds') }}</th>
                            <th scope="col">{{ t('c_legislature_workspace.settings_page.col_set_by', 'Set by') }}</th>
                            <th v-if="can.propose" scope="col"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="row in rows" :key="row.key">
                            <!-- The civil/judicial lockstep pair: one joined row. -->
                            <tr v-if="row.joined">
                                <td>
                                    <span v-for="s in row.joined" :key="s.key" class="mono" data-no-i18n style="display: block">{{ s.key }}</span>
                                    <span class="citation">{{ t('c_legislature_workspace.settings_page.lockstep_note', 'must stay in lockstep · CLK-09 / CLK-10') }}</span>
                                </td>
                                <td>
                                    <AmendableSetting
                                        v-for="s in row.joined"
                                        :key="s.key"
                                        :value="displayValue(s)"
                                        :setting-key="s.key"
                                        :citation="s.meta"
                                        style="display: block; margin-block-end: var(--space-1)"
                                    />
                                </td>
                                <td>
                                    <span class="cc-small">{{ boundsLine(row.joined[0]) }}</span>
                                    <span class="citation" style="display: block">{{ row.joined[0].basis }}</span>
                                </td>
                                <td><span class="citation">{{ provenance(row.joined[0]) }}</span></td>
                                <td v-if="can.propose">
                                    <Btn
                                        v-for="s in row.joined"
                                        :key="s.key"
                                        variant="secondary"
                                        size="sm"
                                        style="display: block; margin-block-end: var(--space-1)"
                                        @click="propose(s.key)"
                                    >{{ t('c_legislature_workspace.settings_page.propose_change', 'Propose change') }}</Btn>
                                </td>
                            </tr>
                            <tr v-else>
                                <td class="mono" data-no-i18n>{{ row.setting.key }}</td>
                                <td>
                                    <AmendableSetting
                                        :value="displayValue(row.setting)"
                                        :setting-key="row.setting.key"
                                        :citation="row.setting.meta"
                                    />
                                </td>
                                <td>
                                    <span class="cc-small">{{ boundsLine(row.setting) }}</span>
                                    <span class="citation" style="display: block">{{ row.setting.basis }}</span>
                                </td>
                                <td>
                                    <span class="citation">{{ provenance(row.setting) }}</span>
                                    <Link
                                        v-if="row.setting.enacted_by"
                                        :href="row.setting.enacted_by.href"
                                        style="display: block"
                                    >{{ row.setting.enacted_by.act_number }} →</Link>
                                </td>
                                <td v-if="can.propose">
                                    <Btn variant="secondary" size="sm" @click="propose(row.setting.key)">{{ t('c_legislature_workspace.settings_page.propose_change', 'Propose change') }}</Btn>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </Card>

        <!-- ======================================== hardened floor ====== -->
        <Card as="section" :title="t('c_legislature_workspace.settings_page.floor_title', 'The hardened floor — what no act can change')">
            <div class="stack" style="gap: var(--space-2)">
                <p><HardenedChip>{{ t('c_legislature_workspace.settings_page.floor_supermajority', 'supermajority can never fall below majority + 1 · Art. VII') }}</HardenedChip></p>
                <p><HardenedChip>{{ t('c_legislature_workspace.settings_page.floor_voting_method', 'voting_method — only a MORE proportional method, never FPTP or plurality · Art. II §2') }}</HardenedChip></p>
                <p class="cc-small">{{ hardenedFloor.note }}</p>
            </div>
        </Card>

        <!-- ======================================= propose panel ======== -->
        <Card v-if="can.propose" id="propose-panel" as="section" :title="t('c_legislature_workspace.settings_page.propose_title', 'Propose a change — pre-targeted bill')">
            <p v-if="!target" class="gloss">
                {{ t('c_legislature_workspace.settings_page.propose_hint', 'Pick "Propose change" on any row above to pre-target a bill at that setting.') }}
            </p>
            <template v-else>
                <Card inset>
                    <p style="margin-block-end: var(--space-2)">
                        {{ t('c_legislature_workspace.settings_page.pretargeted_at', 'Bill pre-targeted at') }} <span class="kbd" data-no-i18n>{{ target }}</span> {{ t('c_legislature_workspace.settings_page.current_value', '— current value') }}
                        <strong data-no-i18n>{{ targetSetting ? displayValue(targetSetting) : '—' }}</strong>.
                        <span class="citation" data-no-i18n>{{ targetSetting ? `${boundsLine(targetSetting)} · ${targetSetting.basis}` : '' }}</span>
                    </p>
                    <div class="cluster">
                        <label class="field-label" for="prop-value" style="margin-block-end: 0">{{ t('c_legislature_workspace.settings_page.proposed_value', 'Proposed value') }}</label>
                        <input id="prop-value" v-model="proposedValue" class="field-input" style="inline-size: 10rem" />
                        <Btn
                            variant="primary"
                            size="sm"
                            :disabled="(preflight !== null && preflight.ok === false) || amendForm.processing || proposedValue === ''"
                            @click="submitAmendment"
                        >
                            {{ t('c_legislature_workspace.settings_page.propose_amendment', 'Propose amendment (F-LEG-031)') }}
                        </Btn>
                        <Link :href="`${deepLink}`">
                            <Btn variant="secondary" size="sm" :disabled="preflight !== null && preflight.ok === false">
                                {{ t('c_legislature_workspace.settings_page.draft_full_bill', 'or draft the full bill →') }}
                            </Btn>
                        </Link>
                    </div>
                </Card>
                <Banner v-if="constitutionError" tone="emergency" role="alert" :title="t('c_legislature_workspace.settings_page.rejected_title', 'Rejected — the engine blocked this amendment before any vote.')">
                    {{ constitutionError }}
                </Banner>
                <Banner v-if="preflight && preflight.ok" tone="info" role="status" :title="t('c_legislature_workspace.settings_page.in_range_title', 'In range — the amendment may proceed to a vote.')">
                    {{ t('c_legislature_workspace.settings_page.in_range_body', 'A direct filing lands an F-LEG-031 amendment bill; the value applies only when the chamber enacts it at a peg-quorum floor vote. · WF-LEG-14 · Art. VII.') }}
                </Banner>
                <Banner v-else-if="preflight && !preflight.ok" tone="emergency" :title="t('c_legislature_workspace.settings_page.rejected_prevote_title', 'Rejected pre-vote — outside hardened bounds.')">
                    {{ preflight.message }}
                    {{ t('c_legislature_workspace.settings_page.rejected_prevote_body', 'The Constitutional Engine blocks the bill before any vote is taken — no UI, admin panel, or legislative act can carry an out-of-range value; an actual filing of this value would land as a rejected=true audit-chain entry.') }}
                    <span class="citation" data-no-i18n>{{ preflight.citation }} · hardened · WF-LEG-14</span>
                </Banner>
            </template>
        </Card>

        <!-- ======================================= changes history ====== -->
        <Card as="section" :title="t('c_legislature_workspace.settings_page.history_title', 'Changes history — the enactment receipts')">
            <p v-if="!changes.length" class="gloss">
                {{ t('c_legislature_workspace.settings_page.no_changes', 'No setting changes on this page.') }}
            </p>
            <DataTable
                v-else
                :columns="[
                    { key: 'setting_key', label: t('c_legislature_workspace.settings_page.col_change_setting', 'Setting'), mono: true },
                    { key: 'change', label: t('c_legislature_workspace.settings_page.col_change', 'Change') },
                    { key: 'act_number', label: t('c_legislature_workspace.settings_page.col_act', 'Act') },
                    { key: 'applied_at', label: t('c_legislature_workspace.settings_page.col_effective', 'Effective') },
                ]"
                :rows="changes" row-key="id"
                :caption="t('c_legislature_workspace.settings_page.history_caption', 'Setting changes — enacting acts')"
            >
                <template #cell-change="{ row }">
                    <span class="mono" data-no-i18n>{{ row.old_value }} → {{ row.new_value }}</span>
                    <span class="citation" style="display: block">
                        {{ t('c_legislature_workspace.settings_page.clocks_rederived', 'dependent clocks re-derived ·') }}
                        <Link :href="`/system/term-sync?legislature=${legislature.id}`">{{ t('c_term_sync.title') }}</Link>
                    </span>
                </template>
                <template #cell-act_number="{ row }">
                    <Link v-if="row.bill_href" :href="row.bill_href">{{ row.act_number }}</Link>
                    <span v-else>{{ row.act_number ?? '—' }}</span>
                </template>
                <template #cell-applied_at="{ row }">{{ fmt(row.applied_at) }}</template>
            </DataTable>
            <HistoryPager cursor-key="changes_cursor" :pages="change_pages" :only="['changes', 'change_pages']" :first="change_pages.first || `/legislatures/${legislature.id}/settings`" :label="t('c_legislature_workspace.settings_page.history_pages', 'Setting change history pages')" />
        </Card>

        <template #about>
            <p>
                {{ t('c_legislature_workspace.settings_page.about', 'A setting change is a bill whose enactment writes the constitutional_settings record (WF-LEG-14 wraps the ordinary WF-LEG-06 bill flow). Hardened-layer changes have only one door: constitutional amendment (WF-SYS-05).') }}
            </p>
        </template>
    </PageScaffold>
</template>
