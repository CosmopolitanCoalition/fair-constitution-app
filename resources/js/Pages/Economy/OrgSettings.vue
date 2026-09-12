<script setup>
/**
 * Economy/OrgSettings — an organization's own economic control panel
 * (design contract: mockups/v3/economy/org-settings.html; Design Round 2 ②).
 *
 * PIECE 1 — DUES. Dues are a membership subscription obligation: NOT a tax,
 * NOT a share system. The org publishes a dues POLICY (amount + period); a
 * member's obligation derives from active membership + that policy; a payment
 * is an ordinary transfer (F-IND-023, kind='dues') from the member's own
 * wallet. There is no dues engine and no scheduler. ABSENCE IS HONEST — an
 * org that has set no amount charges no dues, and this page says exactly that.
 * A due can never gate a civic right: it is voluntary, and a lapse ends
 * membership without withholding any right (Art. I · Art. II §8).
 *
 * The dues policy is set through F-ORG-001 'update_settings' — an org's own
 * rule about itself, on the audit chain, never a constitutional value. Piece 4
 * (F-ORG-008 share issuance) grows the Shares section; it is honest-absence
 * until then.
 */
import { useForm } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import OrganizationNav from '@/Components/Organizations/OrganizationNav.vue';
import { formatMoney, formatCount, formatWhen, shortId } from '@/lib/money.js';

defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    currency: { type: Object, default: null },
    org: { type: Object, required: true },
    can_steer: { type: Boolean, default: false },
    dues: { type: Object, required: true },
    shares: { type: Object, required: true },
    /** The org's own ledger (money plane — counterparties are accounts). */
    ledger: { type: Object, default: () => ({ has_account: false, balance: '0.000000', movements: [] }) },
    /** Levies filed (Art. V §4); [] when the org has filed none. */
    taxes: { type: Array, default: () => [] },
    /** Fair-market conversions on the org's equity (named ownership plane). */
    conversions: { type: Array, default: () => [] },
});

const settingsPath = `/organizations/${props.org.id}/settings`;

// Two independent dials, each a single-key F-ORG-001 'update_settings' filing
// — the same shape as the board-nomination-window dial. Seeded from the
// current policy so a save is an edit, not a reset.
const amountForm = useForm({ key: 'dues_amount', value: props.dues.amount ?? '' });
const periodForm = useForm({ key: 'dues_period_days', value: props.dues.period_days ?? '' });

const saveAmount = () => amountForm.post(settingsPath, { preserveScroll: true });
const savePeriod = () => periodForm.post(settingsPath, { preserveScroll: true });
</script>

<template>
    <PageScaffold :title="`${org.name} — finances`">
        <template #intro>
            Review this organization's dues, shares, and financial records. Its agent and seated
            board members can update the dues policy.
        </template>

        <OrganizationNav :organization="org" current="finances" />

        <!-- ------------------------------------------------------- dues -->
        <Card as="section" title="Dues">
            <p class="econ-desc">
                Voluntary membership dues are a private subscription between a member and an
                organization that chooses to charge them. A due is a membership obligation — never a
                tax, never a share. It is paid as an ordinary transfer from the member's own wallet.
            </p>

            <div v-if="dues.has_dues" class="dues-current">
                <dl class="econ-facts">
                    <div>
                        <dt>Amount</dt>
                        <dd>{{ formatMoney(dues.amount, currency) }}</dd>
                    </div>
                    <div>
                        <dt>Every</dt>
                        <dd><template v-if="dues.period_days">{{ formatCount(dues.period_days) }} days</template><template v-else>—</template></dd>
                    </div>
                </dl>
            </div>
            <p v-else class="econ-absent">
                This organization charges no dues.
            </p>

            <ul class="dues-rails">
                <li><strong>Always opt-in.</strong> A member joins and leaves freely; if dues lapse the membership ends — no right is ever withheld.</li>
                <li><strong>Never a gate on a right.</strong> No due may attach to voting, candidacy, residency, or petitioning (Art. I · Art. II §8).</li>
                <li><strong>Paid by the member.</strong> Members make a dues payment each period. Payments are not automatic.</li>
            </ul>

            <!-- write: the org's own dial (F-ORG-001 update_settings) -->
            <div class="dues-dials">
                <form class="dues-dial" @submit.prevent="saveAmount">
                    <label :for="'dues-amount'">Dues amount ({{ currency?.symbol ?? 'units' }})</label>
                    <div class="dues-dial-row">
                        <input id="dues-amount" v-model="amountForm.value" type="number" min="0" step="0.000001" inputmode="decimal" />
                        <button type="submit" :disabled="!can_steer || amountForm.processing">Save</button>
                    </div>
                    <p v-if="amountForm.errors.constitution" class="dues-err">{{ amountForm.errors.constitution }}</p>
                </form>

                <form class="dues-dial" @submit.prevent="savePeriod">
                    <label :for="'dues-period'">Period (days)</label>
                    <div class="dues-dial-row">
                        <input id="dues-period" v-model="periodForm.value" type="number" min="1" max="3650" step="1" inputmode="numeric" />
                        <button type="submit" :disabled="!can_steer || periodForm.processing">Save</button>
                    </div>
                    <p v-if="periodForm.errors.constitution" class="dues-err">{{ periodForm.errors.constitution }}</p>
                </form>
            </div>
            <p class="econ-note">
                Changes are recorded in this organization's history. Clear the amount to charge no dues.
            </p>
        </Card>

        <!-- ------------------------------------------------------ shares -->
        <Card as="section" title="Shares">
            <p v-if="!shares.issued" class="econ-absent">
                {{ shares.issuable ? 'No shares issued yet.' : shares.note }}
            </p>
            <template v-else>
                <dl class="econ-facts">
                    <div><dt>Total issued</dt><dd>{{ formatMoney(shares.total_units, null) }} units</dd></div>
                    <div><dt>Holders</dt><dd>{{ shares.holders.length }}</dd></div>
                </dl>
                <table class="cap-table">
                    <thead>
                        <tr><th scope="col">Holder</th><th scope="col">Units</th><th scope="col">Share</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="(h, i) in shares.holders" :key="i">
                            <td>{{ h.holder }}</td>
                            <td>{{ formatMoney(h.units, null) }}</td>
                            <td>{{ h.pct !== null ? h.pct + '%' : '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </template>
            <p class="econ-note">
                An organization may issue equity <strong>shares</strong> — never a currency (that is
                reserved to the most-encompassing jurisdiction, Art. V §5). Ownership is a public
                fact recorded by name; the money that changes hands when a share trades stays on the
                private wallet ledger.
            </p>
        </Card>

        <!-- ------------------------------------------------- org ledger -->
        <Card as="section" title="This organization's ledger">
            <p class="econ-desc">
                Review the organization's balance and recent payments. Other parties are identified
                by account to protect their financial privacy.
            </p>
            <p v-if="ledger.restricted" class="muted">The wallet ledger and levies are visible to the organization's agent and its seated board.</p>
            <template v-if="ledger.has_account">
                <dl class="econ-facts">
                    <div><dt>Balance</dt><dd>{{ formatMoney(ledger.balance, currency) }}</dd></div>
                    <div><dt>Recent movements</dt><dd>{{ formatCount(ledger.movements.length) }}</dd></div>
                </dl>
                <table v-if="ledger.movements.length" class="cap-table">
                    <thead>
                        <tr><th scope="col">When</th><th scope="col">In / out</th><th scope="col">Amount</th><th scope="col">Kind</th><th scope="col">Other account</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="m in ledger.movements" :key="m.id">
                            <td>{{ formatWhen(m.at) }}</td>
                            <td>{{ m.direction === 'out' ? 'Out' : 'In' }}</td>
                            <td>{{ (m.direction === 'out' ? '−' : '+') + formatMoney(m.amount, currency) }}</td>
                            <td>{{ m.kind || '—' }}</td>
                            <td class="mono">{{ m.counterparty_account_id ? shortId(m.counterparty_account_id) : '—' }}</td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="econ-note">No movements yet.</p>
            </template>
            <p v-else-if="!ledger.restricted" class="econ-absent">
                This organization holds no economic account yet — it opens when the org first
                transacts.
            </p>
        </Card>

        <!-- --------------------------------------------------- taxes -->
        <Card as="section" title="Taxes &amp; levies">
            <p class="econ-desc">
                What the organization has declared and been assessed under public revenue law
                (Art. V §4). How a levy is computed — its base and rate — is public.
            </p>
            <table v-if="taxes.length" class="cap-table">
                <thead>
                    <tr><th scope="col">Period</th><th scope="col">Levy</th><th scope="col">Base · rate</th><th scope="col">Declared</th><th scope="col">Assessed</th><th scope="col">Status</th></tr>
                </thead>
                <tbody>
                    <tr v-for="t in taxes" :key="t.id">
                        <td>{{ t.period }}</td>
                        <td>{{ t.stream || '—' }}</td>
                        <td>
                            <template v-if="t.rate">{{ t.rate }} on {{ t.base }}<template v-if="t.civic_exempt"> · civic-exempt</template></template>
                            <template v-else>—</template>
                        </td>
                        <td>{{ t.declared !== null ? formatMoney(t.declared, currency) : '—' }}</td>
                        <td>{{ t.assessed !== null ? formatMoney(t.assessed, currency) : '—' }}</td>
                        <td>{{ t.status }}</td>
                    </tr>
                </tbody>
            </table>
            <p v-else-if="ledger.restricted" class="econ-absent">
                Levy filings are visible to the organization's agent and its seated board.
            </p>
            <p v-else class="econ-absent">
                This organization has no levy filings on record.
            </p>
        </Card>

        <!-- ------------------------------------- fair-market / conversions -->
        <Card as="section" title="Fair-market &amp; conversions">
            <p class="econ-desc">
                When ownership changes form, the authorizing act records the minimum fair-market
                value and how it was calculated (Art. III §5). Those records appear here.
            </p>
            <table v-if="conversions.length" class="cap-table">
                <thead>
                    <tr><th scope="col">Direction</th><th scope="col">Via</th><th scope="col">Fair-market floor</th><th scope="col">Basis</th><th scope="col">Status</th></tr>
                </thead>
                <tbody>
                    <tr v-for="c in conversions" :key="c.id">
                        <td>{{ c.direction }}</td>
                        <td>{{ c.via }}</td>
                        <td>{{ c.fair_market_floor !== null ? formatMoney(c.fair_market_floor, currency) : '—' }}</td>
                        <td>{{ c.fair_market_basis || '—' }}</td>
                        <td>{{ c.status }}</td>
                    </tr>
                </tbody>
            </table>
            <p v-else class="econ-absent">
                No conversion has fixed a fair-market price for this organization's equity.
            </p>
        </Card>

    </PageScaffold>
</template>

<style scoped>
.econ-desc { color: var(--gov-fg-muted, #667); }
.econ-absent {
    color: var(--gov-fg-muted, #667);
    font-style: italic;
    padding: var(--space-3, 1rem);
    background: var(--gov-surface-subtle, #eef);
    border-radius: 0.5rem;
}
.econ-facts { display: flex; flex-wrap: wrap; gap: var(--space-3, 1rem); margin: 0; }
.econ-facts > div { flex: 1 1 10rem; }
.econ-facts dt { font-size: var(--text-sm, 0.875rem); color: var(--gov-fg-muted, #667); }
.econ-facts dd { margin: 0; font-size: var(--text-lg, 1.25rem); color: var(--gov-fg, #223); }
.dues-rails { color: var(--gov-fg-muted, #556); margin-block: var(--space-3, 1rem); }
.dues-rails li { margin-block-end: var(--space-2, 0.5rem); }
.dues-dials { display: grid; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); gap: var(--space-3, 1rem); }
.dues-dial label { display: block; font-size: var(--text-sm, 0.875rem); color: var(--gov-fg-muted, #667); }
.dues-dial-row { display: flex; gap: var(--space-2, 0.5rem); }
.dues-dial-row input { flex: 1 1 auto; }
.dues-err { color: var(--gov-danger, #b00); font-size: var(--text-sm, 0.875rem); }
.econ-note { font-size: var(--text-sm, 0.875rem); color: var(--gov-fg-muted, #778); }
.cap-table { inline-size: 100%; border-collapse: collapse; margin-block: var(--space-3, 1rem); }
.cap-table th, .cap-table td { text-align: start; padding: var(--space-2, 0.5rem); border-block-end: 1px solid var(--gov-border, #dde); }
.mono { font-family: var(--font-mono, ui-monospace, monospace); }
</style>
