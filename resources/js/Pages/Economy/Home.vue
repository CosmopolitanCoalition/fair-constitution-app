<script setup>
/**
 * Economy/Home — the economy's front door (design contract:
 * mockups/v3/economy/economy-home.html).
 *
 * READ-ONLY. Every lever on the money supply moves through F-LEG-031's dual
 * door, never from a page — see Economy/Units.
 *
 * Props are lane 13's published contract
 * (docs/plans/economy/ECONOMY_PROP_CONTRACT.md, pinned by
 * EconomyPropContractTest). Money arrives as STRINGS and is formatted, never
 * computed. `currency` is null on a world whose root has not defined one —
 * a normal state this page renders rather than assumes away.
 */
import { Link } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import Stat from '@/Components/Ui/Stat.vue';
import Banner from '@/Components/Ui/Banner.vue';
import { formatMoney, formatCount, formatWhen, isZeroMoney } from '@/lib/money.js';

defineOptions({ layout: AppShellV2 });

const props = defineProps({
    currency: { type: Object, default: null },
    supply: { type: String, default: '0.000000' },
    ledger: { type: Object, default: () => ({ entries: 0, verified: false, residual: '0.000000' }) },
    counts: { type: Object, default: () => ({}) },
    stipend: { type: Object, default: () => ({}) },
});

/* A healthy ledger sits at exactly zero: issuance is the only lawful way for
   value to enter, and every movement after that conserves. */
const ledgerHealthy = () => props.ledger?.verified === true && isZeroMoney(props.ledger?.residual);
</script>

<template>
    <PageScaffold title="The economy">
        <template #intro>
            Money here is public where it should be and private where it must be. The ledger below
            is open to everyone; what any one person holds is theirs alone to see.
        </template>

        <Banner v-if="!currency" tone="info" title="No currency yet">
            This world's root legislature hasn't defined one. Everything below stays empty until it
            does — that's the expected state, not a fault.
        </Banner>

        <Card v-if="currency" as="section" title="The currency">
            <dl class="econ-grid">
                <div><dt>Name</dt><dd>{{ currency.name }}</dd></div>
                <div><dt>Code</dt><dd>{{ currency.code }}</dd></div>
                <div><dt>Symbol</dt><dd>{{ currency.symbol }}</dd></div>
                <div><dt>In circulation</dt><dd>{{ formatMoney(supply, currency) }}</dd></div>
            </dl>
            <p class="econ-note">
                Only the root legislature can issue currency, and only by an act on the record.
            </p>
        </Card>

        <Card as="section" title="The public ledger">
            <div class="econ-stats">
                <Stat :value="formatCount(ledger.entries)" label="Entries" />
                <Stat :value="ledger.verified ? 'Verified' : 'Not verified'" label="Chain" :accent="ledger.verified === true" />
                <Stat :value="formatMoney(ledger.residual, currency)" label="Residual" :accent="isZeroMoney(ledger.residual)" />
            </div>
            <p class="econ-note" :class="{ 'econ-note--warn': !ledgerHealthy() }">
                <template v-if="ledgerHealthy()">
                    Every entry balances and the chain is intact. A healthy ledger reads exactly zero
                    here — issuance is the only way value enters, and everything after it conserves.
                </template>
                <template v-else>
                    This should read zero against a verified chain. It doesn't — worth reporting.
                </template>
            </p>
            <p><Link href="/economy/treasury">Open the public accounts →</Link></p>
        </Card>

        <Card as="section" title="What's happening">
            <div class="econ-stats">
                <Stat :value="formatCount(counts.wallets)" label="Wallets" />
                <Stat :value="formatCount(counts.listings)" label="For sale" />
                <Stat :value="formatCount(counts.postings)" label="Jobs offered" />
                <Stat :value="formatCount(counts.assistance)" label="Requests for help" />
                <Stat :value="formatCount(counts.assets)" label="Registered items" />
            </div>
            <p><Link href="/economy/market">Go to the market →</Link></p>
        </Card>

        <Card as="section" title="The civic stipend">
            <p v-if="stipend.enabled === false" class="econ-note">
                The stipend is switched off in this world.
            </p>
            <template v-else>
                <dl class="econ-grid">
                    <div><dt>Everyone receives at least</dt><dd>{{ formatMoney(stipend.floor, currency) }}</dd></div>
                    <div><dt>Extra for serving roles, up to</dt><dd>{{ formatMoney(stipend.cap, currency) }}</dd></div>
                    <div><dt>Paid</dt><dd>{{ stipend.interval || '—' }}</dd></div>
                    <div>
                        <dt>Funded by</dt>
                        <dd>{{ stipend.funding_source === 'treasury_draw' ? 'the treasury' : 'new issuance' }}</dd>
                    </div>
                </dl>
                <div v-if="stipend.last_run" class="econ-run">
                    <p>
                        Last paid {{ formatWhen(stipend.last_run.ran_at) }} —
                        <strong>{{ formatCount(stipend.last_run.recipients) }}</strong> people,
                        <strong>{{ formatMoney(stipend.last_run.total, currency) }}</strong> in total.
                    </p>
                    <p v-if="stipend.last_run.short_paid" class="econ-note econ-note--warn">
                        The treasury couldn't cover it in full, so everyone was paid the same reduced
                        share. Nobody was skipped — the constitution treats them identically, so a
                        shortfall is shared rather than handed to whoever came first.
                    </p>
                </div>
                <p v-else class="econ-note">No payment run yet.</p>
            </template>
        </Card>
    </PageScaffold>
</template>

<style scoped>
.econ-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
    gap: var(--space-3);
    margin: 0;
}
.econ-grid dt {
    font-size: 0.8125rem;
    color: var(--gov-text-muted);
}
.econ-grid dd {
    margin: 0;
    font-weight: 600;
}
.econ-stats {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-4);
}
.econ-note {
    font-size: 0.875rem;
    color: var(--gov-text-muted);
}
.econ-note--warn {
    color: var(--gov-danger, #b3261e);
    font-weight: 600;
}
.econ-run {
    margin-block-start: var(--space-3);
}
</style>
