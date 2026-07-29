<script setup>
/**
 * Economy/Units — the currency and its levers (design contract:
 * mockups/v3/economy/units.html).
 *
 * READ-ONLY BY DESIGN, and that is the teaching point of the page rather than
 * a limitation of it. Every monetary lever moves through F-LEG-031's dual
 * door — an act of a legislature, within bounds the constitution fixes. There
 * is deliberately no admin knob for any of them, so this page shows values and
 * their citation and offers no controls at all.
 *
 * The same posture as System/Amendments: show what can change, and by what
 * route, without pretending a screen can do it.
 */
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import Stat from '@/Components/Ui/Stat.vue';
import Banner from '@/Components/Ui/Banner.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { formatMoney } from '@/lib/money.js';

defineOptions({ layout: AppShellV2 });

const props = defineProps({
    currency: { type: Object, default: null },
    levers: { type: Array, default: () => [] },
    supply: { type: String, default: '0.000000' },
    issuance_rate_bps: { type: Number, default: null },
    inflation_target_bps: { type: Number, default: null },
    /** Account-clean distribution telemetry (Design Round 2 ④); null pre-currency. */
    telemetry: { type: Object, default: null },
});

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
            What the money is, and the few things about it a legislature is allowed to change.
            Nothing on this page is adjustable here — that is the design, not a gap.
        </template>

        <Banner v-if="!currency" tone="info" title="No currency yet">
            This world's root legislature hasn't defined one.
        </Banner>

        <Card v-else as="section" title="The currency">
            <div class="econ-stats">
                <Stat :value="currency.name" label="Name" />
                <Stat :value="currency.code" label="Code" />
                <Stat :value="currency.symbol" label="Symbol" />
                <Stat :value="formatMoney(supply, currency)" label="In circulation" accent />
            </div>
            <p class="econ-note">
                Shown to {{ currency.precision }} decimal places. The ledger keeps more than that
                internally, so nothing is lost to rounding — only hidden from the display.
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

        <Card v-if="telemetry" as="section" title="Where the money is">
            <p class="econ-desc">
                A read-only picture of how the currency is distributed. Every figure is counted over
                <strong>accounts, never people</strong> — a spread of balances says nothing about
                who holds them. Nothing here adjusts a rate: the levers above still move only by act.
            </p>
            <div class="econ-stats">
                <Stat :value="formatMoney(telemetry.in_circulation, currency)" label="In wallets" accent />
                <Stat :value="formatMoney(telemetry.treasury_held, currency)" label="In treasuries" />
                <Stat :value="String(telemetry.funded_wallets) + ' / ' + String(telemetry.wallets)" label="Wallets funded / total" />
                <Stat :value="telemetry.top_decile_share_pct ? telemetry.top_decile_share_pct + '%' : '—'" label="Held by the top tenth" />
                <Stat :value="telemetry.velocity_30d ? telemetry.velocity_30d + '×' : '—'" label="Turned over (30 days)" />
            </div>
            <p class="econ-note">
                <strong>Not shown, and why.</strong> There is no per-jurisdiction or per-person
                holdings map: an account carries no location, and the only path to one would cross
                the privacy wall. There is no supply-over-time chart yet either — that needs a
                periodic snapshot. Auto-managing a rate from these numbers is a separate design round
                the operator reserved; today the reading informs a human, who still acts by the dual
                door.
            </p>
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
                    </p>
                </li>
            </ul>
            <p v-else class="econ-note">No monetary levers are defined in this world.</p>
        </Card>
    </PageScaffold>
</template>

<style scoped>
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
</style>
