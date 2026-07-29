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
import { Link, useForm } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import { formatMoney, formatCount } from '@/lib/money.js';

defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    currency: { type: Object, default: null },
    org: { type: Object, required: true },
    dues: { type: Object, required: true },
    shares: { type: Object, required: true },
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
    <PageScaffold :title="`${org.name} — economics`">
        <template #intro>
            How this organization holds its shares and charges its dues. These are the org's own
            rules about itself — steered by its agent or a seated board member, recorded on the
            audit chain — never constitutional values.
        </template>

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
                <li><strong>No engine, no scheduler.</strong> A member pays each period themselves — a transfer with kind <code>dues</code>, on the ledger like any other.</li>
            </ul>

            <!-- write: the org's own dial (F-ORG-001 update_settings) -->
            <div class="dues-dials">
                <form class="dues-dial" @submit.prevent="saveAmount">
                    <label :for="'dues-amount'">Dues amount ({{ currency?.symbol ?? 'units' }})</label>
                    <div class="dues-dial-row">
                        <input id="dues-amount" v-model="amountForm.value" type="number" min="0" step="0.000001" inputmode="decimal" />
                        <button type="submit" :disabled="amountForm.processing">Save</button>
                    </div>
                    <p v-if="amountForm.errors.constitution" class="dues-err">{{ amountForm.errors.constitution }}</p>
                </form>

                <form class="dues-dial" @submit.prevent="savePeriod">
                    <label :for="'dues-period'">Period (days)</label>
                    <div class="dues-dial-row">
                        <input id="dues-period" v-model="periodForm.value" type="number" min="1" max="3650" step="1" inputmode="numeric" />
                        <button type="submit" :disabled="periodForm.processing">Save</button>
                    </div>
                    <p v-if="periodForm.errors.constitution" class="dues-err">{{ periodForm.errors.constitution }}</p>
                </form>
            </div>
            <p class="econ-note">
                Saving files an F-ORG-001 setting change — the org's own rule about itself, on the
                audit chain, never a constitutional value. Clear the amount to charge no dues.
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

        <p>
            <Link :href="`/organizations/${org.id}`" class="econ-back">Back to the organization</Link>
        </p>
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
</style>
