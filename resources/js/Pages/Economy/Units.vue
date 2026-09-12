<script setup>
/**
 * Economy/Units — the currency and its levers (design contract:
 * mockups/v3/economy/units.html).
 *
 * Monetary rules remain read-only: each lever moves through the existing
 * legislative process. The report control collects account observations in
 * bounded background chunks; it does not change balances or monetary rules.
 *
 * The same posture as System/Amendments: show what can change, and by what
 * route, without pretending a screen can do it.
 */
import { computed, onMounted, onBeforeUnmount, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import WorkTradeNav from '@/Components/Economy/WorkTradeNav.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import Stat from '@/Components/Ui/Stat.vue';
import Banner from '@/Components/Ui/Banner.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { formatMoney, formatWhen } from '@/lib/money.js';

defineOptions({ layout: AppShellV2 });

const props = defineProps({
    currency: { type: Object, default: null },
    levers: { type: Array, default: () => [] },
    supply: { type: String, default: null },
    report: { type: Object, default: null },
    issuance_rate_bps: { type: Number, default: null },
    inflation_target_bps: { type: Number, default: null },
    /** The issuing authority (root jurisdiction, by name); null pre-currency. */
    issuer: { type: String, default: null },
    /** The economic clock — the stipend cycle, derived; next_run null pre-first-run. */
    clock: { type: Object, default: () => ({}) },
    /** Account-clean distribution telemetry (Design Round 2 ④); null pre-currency. */
    telemetry: { type: Object, default: null },
});

const requesting = ref(false);
let poll;
let polling = false;
const phases = { issuance: 'Counting issued money', wallets: 'Collecting account balances', treasuries: 'Collecting treasury balances', transactions: 'Counting recorded transfers', concentration: 'Calculating distribution', cleanup: 'Finishing the report' };
const reportStatus = computed(() => props.report?.status ?? 'not_started');
function refreshReport() {
    router.post('/economy/units/report', {}, { preserveScroll: true, onStart: () => { requesting.value = true; }, onFinish: () => { requesting.value = false; } });
}
onMounted(() => {
    poll = setInterval(() => {
        if (reportStatus.value !== 'running' || polling || document.hidden) return;
        polling = true;
        router.reload({ only: ['report', 'telemetry', 'supply'], async: true, showProgress: false, onFinish: () => { polling = false; } });
    }, 5000);
});
onBeforeUnmount(() => clearInterval(poll));

/* An enacting act, as a short label: "Act 12" when numbered, else the title. */
const actLabel = (a) => (!a ? null : a.act_number ? `Act ${a.act_number}` : a.title);

/* Basis points are a specialist unit; nobody outside finance reads "120 bps".
   The mockup's v3 copy pass made the same call — show a percentage. */
const asPercent = (bps) => (bps === null || bps === undefined ? '—' : `${(bps / 100).toFixed(2)}%`);

/* Values arrive as string | number | boolean | null. Render each honestly
   rather than stringifying a boolean into "true". */
const showValue = (v) => {
    if (v === null || v === undefined) return 'not set';
    if (typeof v === 'boolean') return v ? 'on' : 'off';
    return String(v);
};

/* Citations arrive as "Art. II §9 · [POLICY]". The bracketed token is an
   internal classification, not something a reader can use — and the v3 pass
   moved codes out of player chrome into the Learn layer on purpose. Keep the
   article reference, drop the token. Flagged to lane 13 in case it is meant
   to mean something on screen, in which case it needs words, not brackets. */
const citation = (c) => (c ? String(c).replace(/\s*·?\s*\[[^\]]*\]\s*$/, '').trim() : '');

const bounds = (b) => {
    if (!b) return null;
    if (Array.isArray(b.allowed) && b.allowed.length) return `one of: ${b.allowed.join(', ')}`;
    const parts = [];
    if (b.min !== undefined && b.min !== null) parts.push(`at least ${b.min}`);
    if (b.max !== undefined && b.max !== null) parts.push(`at most ${b.max}`);
    return parts.length ? parts.join(', ') : null;
};
</script>

<template>
    <PageScaffold title="Units &amp; money">
        <template #intro>
            Review the currency, its recorded rules and the latest completed money report.
        </template>
        <WorkTradeNav />

        <Banner v-if="!currency" tone="info" title="No currency yet">
            This world's root legislature hasn't defined one.
        </Banner>

        <Card v-else as="section" title="The currency">
            <div class="econ-stats">
                <Stat :value="currency.name" label="Name" />
                <Stat :value="currency.code" label="Code" />
                <Stat :value="currency.symbol" label="Symbol" />
                <Stat :value="supply === null ? 'Not reported yet' : formatMoney(supply, currency)" label="Issued supply in latest report" accent />
            </div>
            <p class="econ-note">
                Shown to {{ currency.precision }} decimal places. The ledger keeps more than that
                internally, so nothing is lost to rounding — only hidden from the display.
            </p>
            <p class="econ-note">
                <template v-if="currency.unit_kind">What the unit is: {{ currency.unit_kind }}. </template>
                <template v-if="issuer">Issued by {{ issuer }} — currency is root-reserved (Art. V §5).</template>
            </p>
            <p v-if="clock.next_run" class="econ-note">
                The economic clock runs on a {{ clock.interval }} cycle; the next disbursement is due
                {{ formatWhen(clock.next_run) }}.
            </p>
        </Card>

        <Card v-if="currency" as="section" title="Subdivisions — the measurement-standards power">
            <p class="econ-desc">
                How the unit divides is the legislature's to define, the same way weights and
                measures are. A named subdivision is a display convention on the same ledger —
                never a second currency.
            </p>
            <ul v-if="currency.subdivisions?.length" class="econ-list">
                <li v-for="s in currency.subdivisions" :key="s.name ?? s">
                    <template v-if="s.name">
                        <strong>{{ s.name }}</strong>
                        <span class="econ-note"> · {{ s.per ?? s.ratio ?? '' }} to the {{ currency.name }}</span>
                    </template>
                    <template v-else>{{ s }}</template>
                </li>
            </ul>
            <p v-else class="econ-note">
                No subdivisions are defined — the whole {{ currency.name }} is the only named
                measure. A legislature defines one by act, like any monetary lever.
            </p>
            <p v-if="currency.worth_basis" class="econ-note">
                What the unit is worth, as enacted: {{ currency.worth_basis }}
            </p>
        </Card>

        <Card as="section" title="Rates">
            <div class="econ-stats">
                <Stat :value="asPercent(issuance_rate_bps)" label="New money each period" />
                <Stat :value="asPercent(inflation_target_bps)" label="Inflation aimed at" />
            </div>
            <p class="econ-note">
                Both are targets a legislature sets, not forces of nature. Either can be changed by
                an act, within bounds it cannot exceed.
            </p>
        </Card>

        <Card v-if="currency" as="section" title="Money report">
            <p v-if="reportStatus === 'not_started'" role="status">No report has been collected yet.</p>
            <p v-else-if="reportStatus === 'running'" role="status" aria-live="polite">{{ phases[report.phase] ?? 'Collecting the report' }} · {{ Number(report.rows ?? 0).toLocaleString() }} records processed.</p>
            <p v-else-if="reportStatus === 'failed'" role="alert">Collection stopped. Resume to continue from the last saved step.</p>
            <p v-if="report?.completed_at" class="econ-note">Collected from {{ formatWhen(report.started_at) }} to {{ formatWhen(report.completed_at) }}. A refresh keeps this completed report visible until the replacement is ready.</p>
            <button type="button" class="report-refresh" :disabled="requesting || reportStatus === 'running'" @click="refreshReport">{{ requesting ? 'Requesting…' : reportStatus === 'running' ? 'Collecting…' : reportStatus === 'failed' ? 'Resume report' : telemetry ? 'Refresh report' : 'Collect report' }}</button>
            <p class="econ-desc">
                These figures describe accounts. Balances are collected over time while transactions can continue; they are not an instant ledger reconciliation. The report does not change money or rates.
            </p>
            <div v-if="telemetry" class="econ-stats">
                <Stat :value="formatMoney(telemetry.in_circulation, currency)" label="In wallets" accent />
                <Stat :value="formatMoney(telemetry.treasury_held, currency)" label="In treasuries" />
                <Stat :value="String(telemetry.funded_wallets) + ' / ' + String(telemetry.wallets)" label="Wallets funded / total" />
                <Stat :value="telemetry.top_decile_share_pct ? telemetry.top_decile_share_pct + '%' : '—'" label="Held by the top tenth" />
                <Stat :value="telemetry.velocity_30d ? telemetry.velocity_30d + '×' : '—'" label="Turned over (30 days)" />
            </div>
            <p class="econ-note">The top tenth uses the funded balances collected for this report. Turnover uses recorded transfers in the 30 days ending when collection began. No account holder or location is disclosed.</p>
        </Card>

        <Card as="section" title="The levers">
            <p class="econ-note">
                Each of these can be changed only by a passed act, recorded publicly, and only
                within the bounds shown. There is no way to set one directly — not for an operator,
                not for an administrator, not from this page.
            </p>

            <ul v-if="levers.length" class="lever-list">
                <li v-for="l in levers" :key="l.key" class="lever">
                    <div class="lever-head">
                        <span class="lever-label">{{ l.label }}</span>
                        <span class="lever-value">{{ showValue(l.value) }}</span>
                    </div>
                    <p class="lever-meta">
                        <StatusBadge v-if="l.dual_door">Needs an act</StatusBadge>
                        <span v-if="bounds(l.bounds)">{{ bounds(l.bounds) }}</span>
                        <span v-if="citation(l.citation)" class="lever-cite">{{ citation(l.citation) }}</span>
                        <span v-if="actLabel(l.enacting_act)" class="lever-act">set by {{ actLabel(l.enacting_act) }}</span>
                        <span v-else class="lever-act lever-act--default">at its constitutional default</span>
                    </p>
                </li>
            </ul>
            <p v-else class="econ-note">No monetary levers are defined in this world.</p>
        </Card>
    </PageScaffold>
</template>

<style scoped>
.report-refresh { min-block-size: 44px; padding: .5rem 1rem; border: 1px solid var(--gov-accent); border-radius: .4rem; background: var(--gov-surface); color: var(--gov-accent); }
.report-refresh:focus-visible { outline: 3px solid var(--gov-accent); outline-offset: 3px; }
.econ-stats {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-4);
}
.econ-note {
    font-size: 0.875rem;
    color: var(--gov-text-muted);
}
.lever-list {
    list-style: none;
    margin: var(--space-3) 0 0;
    padding: 0;
}
.lever {
    padding-block: var(--space-3);
    border-block-start: 1px solid var(--gov-border);
}
.lever-head {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: var(--space-3);
    flex-wrap: wrap;
}
.lever-label {
    font-weight: 600;
}
.lever-value {
    font-family: var(--font-mono, ui-monospace, monospace);
}
.lever-meta {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3);
    align-items: center;
    margin: var(--space-2) 0 0;
    font-size: 0.8125rem;
    color: var(--gov-text-muted);
}
.lever-cite {
    font-family: var(--font-mono, ui-monospace, monospace);
}
.lever-act {
    font-style: italic;
}
.lever-act--default {
    opacity: 0.7;
}
</style>
