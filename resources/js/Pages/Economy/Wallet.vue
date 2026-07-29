<script setup>
/**
 * Economy/Wallet — your own money (design contract:
 * mockups/v3/economy/wallet.html).
 *
 * PRIVATE BY CONSTRUCTION. This is the one economy surface that is nobody
 * else's business: the aggregate is public, an individual's balance is not.
 * Nothing here names a counterparty as a person — the contract carries account
 * ids only, and resolving an account back to a human is deliberately not
 * something any page can do.
 *
 * WRITABLE since F-IND-023 / F-IND-024. Both controls file through the
 * ConstitutionalEngine — there is no economy write API — so what the page can
 * do is exactly what the constitution permits, no more.
 *
 * A REFUSAL IS AN ANSWER, NOT AN ERROR. Sending more than you hold comes back
 * as a constitutional rejection carrying its citation, because the individual
 * economy has no overdraft. The page renders that message rather than
 * treating it as a failed request.
 */
import { computed } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import Stat from '@/Components/Ui/Stat.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Field from '@/Components/Ui/Field.vue';
import FormChip from '@/Components/Ui/FormChip.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import { formatMoney, formatQuantity, formatWhen, shortId } from '@/lib/money.js';

defineOptions({ layout: AppShellV2 });

const props = defineProps({
    currency: { type: Object, default: null },
    /** null when they have no wallet yet — a normal state, not an error. */
    account: { type: Object, default: null },
    transactions: { type: Array, default: () => [] },
    receipts: { type: Array, default: () => [] },
    /** Things you hold — physical and virtual alike, one flag apart. */
    assets: { type: Array, default: () => [] },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

// F-IND-023. The recipient is an ACCOUNT — there is no person picker here and
// there must not be one: resolving an account to a human is deliberately
// outside what any page can do.
const send = useForm({ to_account_id: '', amount: '', memo: '' });

function submitSend() {
    send.post('/economy/transfer', {
        preserveScroll: true,
        onSuccess: () => send.reset(),
    });
}

// F-IND-024. `kind` is one flag: a hand-woven blanket and a map-maker's
// compass are the same kind of record, which is what lets one market carry
// both — and what the fantasy-map worlds are built on.
const make = useForm({ name: '', kind: 'physical', description: '', quantity: '1' });

function submitMake() {
    make.post('/economy/assets', {
        preserveScroll: true,
        onSuccess: () => make.reset(),
    });
}

const txColumns = [
    { key: 'when', label: 'When' },
    { key: 'direction', label: 'In / out' },
    { key: 'amount', label: 'Amount' },
    { key: 'kind', label: 'Kind' },
    { key: 'memo', label: 'Note' },
    { key: 'counterparty', label: 'Other account', mono: true },
];

const txRows = () =>
    (props.transactions ?? []).map((t) => ({
        id: t.id,
        when: formatWhen(t.at),
        direction: t.direction === 'in' ? 'In' : 'Out',
        amount: (t.direction === 'out' ? '−' : '+') + formatMoney(t.amount, props.currency),
        kind: t.kind ?? '—',
        memo: t.memo ?? '—',
        counterparty: shortId(t.counterparty_account_id),
    }));

const receiptColumns = [
    { key: 'when', label: 'When' },
    { key: 'base', label: 'Base' },
    { key: 'bump', label: 'Extra for serving' },
    { key: 'amount', label: 'Paid' },
];

const receiptRows = () =>
    (props.receipts ?? []).map((r) => ({
        id: r.id,
        when: formatWhen(r.at),
        base: formatMoney(r.base, props.currency),
        bump: formatMoney(r.bump, props.currency),
        amount: formatMoney(r.amount, props.currency),
    }));

const assetColumns = [
    { key: 'name', label: 'What it is' },
    { key: 'kind', label: 'Physical / digital' },
    { key: 'quantity', label: 'How many' },
    { key: 'origin', label: 'Where it came from' },
    { key: 'when', label: 'Registered' },
];

const assetRows = () =>
    (props.assets ?? []).map((a) => ({
        id: a.id,
        name: a.name,
        kind: a.kind === 'virtual' ? 'Digital' : 'Physical',
        quantity: formatQuantity(a.quantity),
        origin: a.origin,
        when: formatWhen(a.at),
    }));
</script>

<template>
    <PageScaffold title="My wallet">
        <template #intro>
            What you hold, and where it came from. This page is yours alone — balances are private
            in the same way a ballot is, and no one else can look yours up.
        </template>

        <Banner v-if="!currency" tone="info" title="No currency yet">
            This world's root legislature hasn't defined one, so there is nothing to hold.
        </Banner>

        <Banner v-else-if="!account" tone="info" title="You don't have a wallet yet">
            A wallet opens once your residency is confirmed. If you've just declared where you live,
            it arrives when the confirmation does.
        </Banner>

        <template v-else>
            <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
            <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

            <Card as="section" title="Balance">
                <div class="econ-stats">
                    <Stat :value="formatMoney(account.balance, currency)" label="Available" accent />
                    <Stat :value="account.status || '—'" label="Account" />
                </div>
                <p class="econ-note">
                    Your own account is <span class="mono">{{ shortId(account.id) }}</span> — the id
                    someone else needs to send you money.
                </p>
                <p class="econ-note">
                    You hold {{ currency.name }} ({{ currency.symbol }}).
                    <template v-if="currency.subdivisions?.length">
                        It divides into
                        <template v-for="(s, i) in currency.subdivisions" :key="s.name ?? i">
                            <template v-if="i > 0"> · </template>{{ s.name ?? s }}
                        </template>
                        — display conventions on the same ledger, never a second currency.
                    </template>
                    <template v-else>
                        No subdivisions are defined yet — how the unit divides is the
                        legislature's measurement-standards power.
                    </template>
                </p>
            </Card>

            <Card as="section">
                <template #title>
                    <h2>Send money <FormChip form-id="F-IND-023" name="Funds Transfer" /></h2>
                </template>

                <form @submit.prevent="submitSend">
                    <Field label="To which account" :error="send.errors.to_account_id" required>
                        <template #control="{ id, invalid, describedBy }">
                            <input :id="id" v-model="send.to_account_id" class="field-input mono" type="text"
                                placeholder="account id"
                                :aria-invalid="invalid ? 'true' : undefined" :aria-describedby="describedBy" />
                        </template>
                    </Field>

                    <Field label="How much" :error="send.errors.amount" required>
                        <template #control="{ id, invalid, describedBy }">
                            <input :id="id" v-model="send.amount" class="field-input" type="text"
                                inputmode="decimal" placeholder="0.00"
                                :aria-invalid="invalid ? 'true' : undefined" :aria-describedby="describedBy" />
                        </template>
                    </Field>

                    <Field label="Note (optional)" :error="send.errors.memo">
                        <template #control="{ id, invalid, describedBy }">
                            <input :id="id" v-model="send.memo" class="field-input" type="text"
                                :aria-invalid="invalid ? 'true' : undefined" :aria-describedby="describedBy" />
                        </template>
                    </Field>

                    <Btn type="submit" variant="primary" :disabled="send.processing">
                        {{ send.processing ? 'Sending…' : 'Send' }}
                    </Btn>
                </form>

                <p class="econ-note">
                    You send to an account, not to a name — the same way a ballot is separated from a
                    voter. There is no overdraft: sending more than you hold is refused, not borrowed.
                </p>
            </Card>

            <Card as="section">
                <template #title>
                    <h2>Make something <FormChip form-id="F-IND-024" name="Asset Registration" /></h2>
                </template>

                <form @submit.prevent="submitMake">
                    <Field label="What is it" :error="make.errors.name" required>
                        <template #control="{ id, invalid, describedBy }">
                            <input :id="id" v-model="make.name" class="field-input" type="text"
                                placeholder="Hand-woven wool blanket"
                                :aria-invalid="invalid ? 'true' : undefined" :aria-describedby="describedBy" />
                        </template>
                    </Field>

                    <Field label="Physical or digital" :error="make.errors.kind" required>
                        <template #control="{ id }">
                            <select :id="id" v-model="make.kind" class="select">
                                <option value="physical">A physical thing</option>
                                <option value="virtual">A digital thing</option>
                            </select>
                        </template>
                    </Field>

                    <Field label="Describe it (optional)" :error="make.errors.description">
                        <template #control="{ id, invalid, describedBy }">
                            <textarea :id="id" v-model="make.description" class="field-input" rows="2"
                                :aria-invalid="invalid ? 'true' : undefined" :aria-describedby="describedBy"></textarea>
                        </template>
                    </Field>

                    <Field label="How many" :error="make.errors.quantity">
                        <template #control="{ id }">
                            <input :id="id" v-model="make.quantity" class="field-input" type="text" inputmode="decimal" />
                        </template>
                    </Field>

                    <Btn type="submit" variant="primary" :disabled="make.processing">
                        {{ make.processing ? 'Registering…' : 'Register it' }}
                    </Btn>
                </form>

                <p class="econ-note">
                    Registering a thing gives it its own identity and a provenance record — every hand
                    it passes through is kept. Then you can offer it on the market.
                </p>
            </Card>

            <Card as="section" title="Things you hold">
                <DataTable
                    v-if="assets.length"
                    :columns="assetColumns"
                    :rows="assetRows()"
                    row-key="id"
                    caption="Everything registered to your account, newest first"
                />
                <p v-else class="econ-note">You haven't registered anything yet.</p>
            </Card>

            <Card as="section" title="Activity">
                <DataTable
                    v-if="transactions.length"
                    :columns="txColumns"
                    :rows="txRows()"
                    row-key="id"
                    caption="Your most recent transactions, newest first"
                />
                <p v-else class="econ-note">Nothing has moved in or out yet.</p>
            </Card>

            <Card as="section" title="Stipend receipts">
                <DataTable
                    v-if="receipts.length"
                    :columns="receiptColumns"
                    :rows="receiptRows()"
                    row-key="id"
                    caption="Civic stipend payments you have received, newest first"
                />
                <p v-else class="econ-note">You haven't received a stipend payment yet.</p>
            </Card>
        </template>
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
.mono {
    font-family: var(--font-mono, ui-monospace, monospace);
}
form :deep(.field) {
    margin-block-end: var(--space-3);
}
</style>
