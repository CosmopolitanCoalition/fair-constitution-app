<script setup>
/**
 * System/Amendments — mockups-v3-wiring Phase 2 (design contract:
 * mockups/v3/system/amendments.html).
 *
 * READ-ONLY BY DESIGN — the two doors the constitution changes through:
 * door one is the LIVE F-LEG-031 setting_changes ledger (append-only,
 * newest first); door two is the hardened layer (changes only by a public
 * release passing every constitutional check).
 *
 * The mockup's "Try a proposed value" pre-vote validator is deliberately
 * NOT built this phase — Phase-7 follow-up (it belongs with the bill-flow
 * validation surfaces, which already run the same bounds check server-side).
 */
import { Link } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });

defineProps({
    surface: { type: Object, required: true },
    /** The latest 50 setting_changes rows, newest first. */
    changes: { type: Array, default: () => [] },
});

const HARDENED_RULES = [
    'Proportional voting with the Droop quota — never first-past-the-post or plurality',
    'Quorum & supermajority counted against all serving members',
    '5–9 seats with mandatory subdivision above 9',
    'Ballot secrecy — identity cryptographically separated',
    'Voting & candidacy: residency only, no other requirements',
    'Common-good-corporation intellectual property perpetually public domain',
];

const ledgerColumns = [
    { key: 'applied_at', label: 'When' },
    { key: 'where', label: 'Where' },
    { key: 'setting_key', label: 'Setting', mono: true },
    { key: 'change', label: 'Old → new' },
    { key: 'act_number', label: 'Act', mono: true },
];

const RATIFICATION_ROWS = [
    {
        change: 'Amendable setting within bounds',
        threshold: 'Valid legislative act (majority or supermajority per the setting)',
        basis: 'Amendable setting change',
    },
    {
        change: 'Amend additional constitutional articles',
        threshold:
            'Supermajority of constituent jurisdictions — or supermajority of the legislature where no constituents exist',
        basis: 'Constitutional amendment',
    },
    {
        change: 'Hardened-layer change',
        threshold: 'A public software release with every constitutional check passing',
        basis: 'Hardened-layer release',
    },
];

const ratificationColumns = [
    { key: 'change', label: 'Change' },
    { key: 'threshold', label: 'Threshold' },
    { key: 'basis', label: 'Basis', mono: true },
];

function dateOf(iso) {
    return iso ? new Date(iso).toLocaleDateString() : '—';
}

function valueOf(value) {
    if (value === null || value === undefined) return '—';
    return typeof value === 'object' ? JSON.stringify(value) : String(value);
}
</script>

<template>
    <PageScaffold :surface="surface">
        <template #intro>
            The constitution changes through exactly two doors. The settings it leaves open move by
            ordinary legislative acts, inside locked bounds. The locked core itself (the hardened
            layer) moves only by a new release of the software, made in the open with every
            constitutional check passing publicly. Nothing changes silently, and nothing changes
            any other way.
        </template>

        <p>
            <a href="https://cosmopolitancoalition.org/cosmopolitan-template/">
                Read the Template — <em>A Fair Constitution</em> (Cosmopolitan Template) →
            </a>
        </p>

        <div class="grid-2">
            <!-- ================================ door one ================== -->
            <Card as="section" title="Door one — amendable variables">
                <p class="cc-small">
                    Settings the constitution leaves to each jurisdiction, changed by a valid
                    legislative act (an amendable setting change) through the ordinary bill flow.
                    The engine blocks out-of-range values before the vote is even scheduled. Every
                    applied change lands here, on the live ledger — appended, never edited.
                </p>
                <DataTable
                    v-if="changes.length"
                    :columns="ledgerColumns"
                    :rows="changes"
                    row-key="id"
                    caption="Applied amendable-setting changes, newest first"
                >
                    <template #cell-applied_at="{ row }">
                        <span data-no-i18n>{{ dateOf(row.applied_at) }}</span>
                    </template>
                    <template #cell-setting_key="{ row }">
                        <span data-no-i18n>{{ row.setting_key }}</span>
                    </template>
                    <template #cell-change="{ row }">
                        <span data-no-i18n>{{ valueOf(row.old_value) }} → {{ valueOf(row.new_value) }}</span>
                    </template>
                    <template #cell-act_number="{ row }">
                        <Link v-if="row.bill_href" :href="row.bill_href" data-no-i18n>
                            {{ row.act_number ?? 'act' }}
                        </Link>
                        <span v-else data-no-i18n>{{ row.act_number ?? '—' }}</span>
                    </template>
                </DataTable>
                <p v-else class="cc-small gloss">
                    No amendments yet — every change will appear here, appended, never edited.
                </p>
            </Card>

            <!-- ================================ door two ================== -->
            <Card as="section" title="Door two — the hardened layer">
                <p class="cc-small">
                    Mechanics no act, admin panel, or office can touch. A change here is a new
                    release of the software that must pass every automated constitutional check
                    before it ships — <strong>softening exists only through this door</strong>, in
                    the open, with the change and its checks on the public record.
                </p>
                <div class="stack" style="gap: var(--space-2)">
                    <HardenedChip v-for="(rule, ri) in HARDENED_RULES" :key="ri">{{ rule }}</HardenedChip>
                </div>
                <p class="citation" style="margin-block-start: var(--space-4)">
                    <Link href="/system/audit-chain">Releases are sealed in the audit chain</Link>
                </p>
            </Card>
        </div>

        <!-- ==================================== the floor ================ -->
        <Card as="section" title="The supermajority floor">
            <p>
                The supermajority fraction is amendable, but its definition has a hardened floor:
                no redefinition may produce a threshold below <strong>majority + 1</strong> of all
                serving members. The default is two thirds, computed as
                <code data-no-i18n>ceil(serving_members × 2/3)</code> — against members
                <em>serving</em>, never members present.
            </p>
            <p class="gloss">
                Supermajority, glossed: the share of every seated member — vacant seats still count
                in the denominator until refilled — so absence can never lower the bar.
            </p>
        </Card>

        <!-- ==================================== ratification ============= -->
        <Card as="section" title="Ratification thresholds">
            <DataTable
                :columns="ratificationColumns"
                :rows="RATIFICATION_ROWS"
                caption="Ratification thresholds by kind of change"
            />
        </Card>

        <template #about>
            <p>
                This screen shows the two ways the constitution can change — the settings door runs
                through the amendable setting change and the ordinary bill flow, and every change
                is sealed in the audit chain. Amendable settings are scoped per jurisdiction;
                ratification climbs the nesting chain through constituent supermajorities. In
                engineering terms, the hardened layer is a set of protected files guarded by the
                constitutional test suite — a release deploys only when the full suite passes.
            </p>
        </template>
    </PageScaffold>
</template>
