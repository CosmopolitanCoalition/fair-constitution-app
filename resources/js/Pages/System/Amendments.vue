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
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    /** Current resolved amendable values at the instance root: {key, value, bounds, basis, enacted_by}. */
    settings: { type: Array, default: () => [] },
    /** The latest 50 setting_changes rows, newest first. */
    changes: { type: Array, default: () => [] },
});

const HARDENED_RULES = [
    'Proportional voting with the Droop quota — never first-past-the-post or plurality',
    'Quorum & supermajority counted against all serving members',
    'Districts of 5–9 seats — a chamber above 9 subdivides into districts; the chamber total itself has no ceiling',
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
    if (typeof value === 'boolean') return value ? 'true' : 'false';
    return typeof value === 'object' ? JSON.stringify(value) : String(value);
}

/** A human range label for a bounds object: an allowed set, or a min–max span. */
function rangeLabel(bounds) {
    if (!bounds) return 'no bounded range';
    if (Array.isArray(bounds.allowed)) {
        return 'one of ' + bounds.allowed.map((v) => valueOf(v)).join(', ');
    }
    if (bounds.min !== undefined && bounds.max !== undefined) {
        return `${bounds.min} – ${bounds.max}`;
    }
    return '—';
}

// ── "Try a proposed value" — a CLIENT-SIDE pre-vote hint against the shipped
// bounds. The authoritative check runs server-side in the PROTECTED validator
// when the F-LEG-031 bill is filed; this never enacts anything.
const checkable = computed(() => props.settings.filter((s) => s.bounds));
const selectedKey = ref(checkable.value[0]?.key ?? '');
const proposed = ref('');

const selectedSetting = computed(
    () => props.settings.find((s) => s.key === selectedKey.value) ?? null,
);

const checkResult = computed(() => {
    const s = selectedSetting.value;
    const raw = proposed.value.trim();
    if (!s || !s.bounds || raw === '') return null;

    const b = s.bounds;
    if (Array.isArray(b.allowed)) {
        const ok = b.allowed.some((v) => String(v) === raw);
        return {
            ok,
            message: ok
                ? `“${raw}” is an allowed value.`
                : `“${raw}” is not in the allowed set (${b.allowed.map((v) => valueOf(v)).join(', ')}).`,
        };
    }

    const n = Number(raw);
    if (Number.isNaN(n)) {
        return { ok: false, message: `“${raw}” is not a number — this setting takes ${rangeLabel(b)}.` };
    }
    const ok = n >= b.min && n <= b.max;
    return {
        ok,
        message: ok
            ? `${n} is within the hardened range (${b.min} – ${b.max}).`
            : `${n} is out of range — the engine would refuse it pre-vote (allowed ${b.min} – ${b.max}).`,
    };
});
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

        <!-- ============================ current values =================== -->
        <Card as="section" title="Current amendable values">
            <p class="cc-small">
                What every amendable setting is set to right now at the instance root, with the
                hardened range it must stay inside, and the act that last changed it. Values are
                scoped per jurisdiction — this is the instance-wide snapshot; the full
                inheritance-aware register is on the
                <Link href="/legislature/settings">settings register</Link>.
            </p>

            <div v-if="settings.length" class="value-grid">
                <div v-for="s in settings" :key="s.key" class="value-tile">
                    <div class="value-tile__key" data-no-i18n>{{ s.key }}</div>
                    <div class="value-tile__value" data-no-i18n>{{ valueOf(s.value) }}</div>
                    <div class="value-tile__range" data-no-i18n>range: {{ rangeLabel(s.bounds) }}</div>
                    <div class="value-tile__basis citation" data-no-i18n>{{ s.basis }}</div>
                    <div v-if="s.enacted_by" class="value-tile__act cc-small">
                        <Link v-if="s.enacted_by.href" :href="s.enacted_by.href" data-no-i18n>
                            {{ s.enacted_by.act_number ?? 'act' }}
                        </Link>
                        <span data-no-i18n> · {{ dateOf(s.enacted_by.effective_at) }}</span>
                    </div>
                    <div v-else class="value-tile__act cc-small gloss">founding value — unamended</div>
                </div>
            </div>
            <p v-else class="cc-small gloss">No amendable settings resolved yet.</p>

            <!-- ==================== try a proposed value ================= -->
            <div v-if="checkable.length" class="checker">
                <h4 class="checker__title">Try a proposed value</h4>
                <p class="cc-small gloss">
                    A pre-vote hint only. The engine runs the same bounds check server-side when the
                    amendment bill is filed — this screen enacts nothing.
                </p>
                <div class="checker__controls">
                    <label class="checker__field">
                        <span class="cc-small">Setting</span>
                        <select v-model="selectedKey" data-no-i18n>
                            <option v-for="s in checkable" :key="s.key" :value="s.key">{{ s.key }}</option>
                        </select>
                    </label>
                    <label class="checker__field">
                        <span class="cc-small">Proposed value</span>
                        <input v-model="proposed" type="text" data-no-i18n
                            :placeholder="rangeLabel(selectedSetting?.bounds)" />
                    </label>
                </div>
                <p v-if="checkResult" class="checker__result" :class="checkResult.ok ? 'is-ok' : 'is-bad'"
                    data-no-i18n>
                    {{ checkResult.ok ? '✓ ' : '✗ ' }}{{ checkResult.message }}
                </p>
            </div>
        </Card>

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

<style scoped>
.value-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(15rem, 1fr));
    gap: var(--space-2);
    margin-block: var(--space-3);
}

.value-tile {
    border: 1px solid var(--gov-border);
    border-radius: var(--radius-md);
    background: var(--gov-surface-subtle);
    padding: var(--space-2) var(--space-3);
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.value-tile__key {
    font-size: 0.8rem;
    color: var(--gov-fg-muted);
    word-break: break-word;
}

.value-tile__value {
    font-size: 1.35rem;
    font-weight: 600;
    color: var(--gov-fg-strong);
}

.value-tile__range {
    font-size: 0.8rem;
    color: var(--gov-fg-muted);
}

.value-tile__act {
    margin-block-start: 2px;
}

.checker {
    margin-block-start: var(--space-4);
    padding-block-start: var(--space-3);
    border-top: 1px solid var(--gov-border);
}

.checker__title {
    margin: 0 0 var(--space-1);
}

.checker__controls {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3);
    margin-block: var(--space-2);
}

.checker__field {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 12rem;
    flex: 1 1 12rem;
}

.checker__field select,
.checker__field input {
    padding: var(--space-1) var(--space-2);
    border: 1px solid var(--gov-border);
    border-radius: var(--radius-sm);
    background: var(--gov-surface);
    color: var(--gov-fg);
}

.checker__result {
    margin-block-start: var(--space-1);
    padding: var(--space-2) var(--space-3);
    border-radius: var(--radius-sm);
    border-left: 3px solid var(--gov-border);
    background: var(--gov-surface-subtle);
}

.checker__result.is-ok {
    border-left-color: var(--gov-primary);
}

.checker__result.is-bad {
    border-left-color: var(--danger);
}
</style>
