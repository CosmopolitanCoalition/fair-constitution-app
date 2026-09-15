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
import { computed, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import WorkTradeNav from '@/Components/Economy/WorkTradeNav.vue';
import Card from '@/Components/Ui/Card.vue';
import Stat from '@/Components/Ui/Stat.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Field from '@/Components/Ui/Field.vue';
import FormChip from '@/Components/Ui/FormChip.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import HistoryPager from '@/Components/Ui/HistoryPager.vue';
import { formatMoney, formatQuantity, formatWhen as formatWhenRaw, shortId } from '@/lib/money.js';

defineOptions({ layout: AppShellV2 });
const { t, locale } = useI18n();
const formatWhen = (iso) => formatWhenRaw(iso, locale.value);

const props = defineProps({
    currency: { type: Object, default: null },
    /** null when they have no wallet yet — a normal state, not an error. */
    account: { type: Object, default: null },
    transactions: { type: Array, default: () => [] },
    transaction_pages: { type: Object, default: () => ({ previous: null, next: null }) },
    receipts: { type: Array, default: () => [] },
    receipt_pages: { type: Object, default: () => ({ previous: null, next: null }) },
    /** Things you hold — physical and virtual alike, one flag apart. */
    assets: { type: Array, default: () => [] },
    asset_directory: { type: Object, default: () => ({ query: '', previous: null, next: null, available: false }) },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);
const assetSearch = ref(props.asset_directory.query ?? '');
const assetSearching = ref(false);
const assetSearchError = ref('');
watch(() => props.asset_directory.query, query => { assetSearch.value = query ?? ''; });
const assetVisitOptions = () => ({
    only: ['assets', 'asset_directory'],
    preserveState: true,
    preserveScroll: true,
    onStart: () => { assetSearching.value = true; assetSearchError.value = ''; },
    onFinish: () => { assetSearching.value = false; },
    onError: errors => { assetSearchError.value = errors.asset_q ?? errors.asset_cursor ?? t('c_economy.wallet.search_failed', 'The item search could not be completed. Try again.'); },
});
function searchAssets() {
    router.get('/economy/wallet', { asset_q: assetSearch.value.trim() }, assetVisitOptions());
}

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
    { key: 'when', label: t('c_economy.wallet.col_when', 'When') },
    { key: 'direction', label: t('c_economy.wallet.col_in_out', 'In / out') },
    { key: 'amount', label: t('c_economy.wallet.col_amount', 'Amount') },
    { key: 'kind', label: t('c_economy.wallet.col_kind', 'Kind') },
    { key: 'memo', label: t('c_economy.wallet.col_note', 'Note') },
    { key: 'counterparty', label: t('c_economy.wallet.col_other_account', 'Other account'), mono: true },
];

const txRows = () =>
    (props.transactions ?? []).map((tx) => ({
        id: tx.id,
        when: formatWhen(tx.at),
        direction: tx.direction === 'in' ? t('c_economy.wallet.row_in', 'In') : t('c_economy.wallet.row_out', 'Out'),
        amount: (tx.direction === 'out' ? '−' : '+') + formatMoney(tx.amount, props.currency),
        kind: tx.kind ?? '—',
        memo: tx.memo ?? '—',
        counterparty: shortId(tx.counterparty_account_id),
    }));

const receiptColumns = [
    { key: 'when', label: t('c_economy.wallet.col_when', 'When') },
    { key: 'base', label: t('c_economy.wallet.col_base', 'Base') },
    { key: 'bump', label: t('c_economy.wallet.col_extra', 'Extra for serving') },
    { key: 'amount', label: t('c_economy.wallet.col_paid', 'Paid') },
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
    { key: 'name', label: t('c_economy.wallet.col_what', 'What it is') },
    { key: 'kind', label: t('c_economy.wallet.col_physical_digital', 'Physical / digital') },
    { key: 'quantity', label: t('c_economy.wallet.col_how_many', 'How many') },
    { key: 'origin', label: t('c_economy.wallet.col_origin', 'Where it came from') },
    { key: 'when', label: t('c_economy.wallet.col_registered', 'Registered') },
];

const assetRows = () =>
    (props.assets ?? []).map((a) => ({
        id: a.id,
        name: a.name,
        kind: a.kind === 'virtual' ? t('c_economy.wallet.row_digital', 'Digital') : t('c_economy.wallet.row_physical', 'Physical'),
        quantity: formatQuantity(a.quantity),
        origin: a.origin,
        when: formatWhen(a.at),
    }));
</script>

<template>
    <PageScaffold :title="t('c_economy.wallet.title', 'My wallet')">
        <WorkTradeNav active="wallet" />
        <template #intro>
            {{ t('c_economy.wallet.intro', 'What you hold, and where it came from. This page is yours alone — balances are private in the same way a ballot is, and no one else can look yours up.') }}
        </template>

        <Banner v-if="!currency" tone="info" :title="t('c_economy.wallet.no_currency_title', 'No currency yet')">
            {{ t('c_economy.wallet.no_currency_body', 'This world\'s root legislature hasn\'t defined one, so there is nothing to hold.') }}
        </Banner>

        <Banner v-else-if="!account" tone="info" :title="t('c_economy.wallet.no_wallet_title', 'You don\'t have a wallet yet')">
            {{ t('c_economy.wallet.no_wallet_body', 'A wallet opens once your residency is confirmed. If you\'ve just declared where you live, it arrives when the confirmation does.') }}
        </Banner>

        <template v-else>
            <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
            <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

            <Card as="section" :title="t('c_economy.wallet.balance_title', 'Balance')">
                <div class="econ-stats">
                    <Stat :value="formatMoney(account.balance, currency)" :label="t('c_economy.wallet.stat_available', 'Available')" accent />
                    <Stat :value="account.status || '—'" :label="t('c_economy.wallet.stat_account', 'Account')" />
                </div>
                <p class="econ-note">
                    {{ t('c_economy.wallet.your_account_before', 'Your own account is') }} <span class="mono">{{ shortId(account.id) }}</span> {{ t('c_economy.wallet.your_account_after', '— the id someone else needs to send you money.') }}
                </p>
                <p class="econ-note">
                    {{ t('c_economy.wallet.you_hold', { name: currency.name, symbol: currency.symbol }) }}
                    <template v-if="currency.subdivisions?.length">{{ t('c_economy.wallet.divides_into', 'It divides into') }}
                        <template v-for="(s, i) in currency.subdivisions" :key="s.name ?? i">
                            <template v-if="i > 0"> · </template>{{ s.name ?? s }}
                        </template>
                        {{ t('c_economy.wallet.divides_after', '— display conventions on the same ledger, never a second currency.') }}
                    </template>
                    <template v-else>{{ t('c_economy.wallet.no_subdivisions', 'No subdivisions are defined yet — how the unit divides is the legislature\'s measurement-standards power.') }}</template>
                </p>
                <p v-if="currency.worth_basis" class="econ-note">
                    {{ t('c_economy.wallet.worth_basis', { name: currency.name, basis: currency.worth_basis }) }}
                </p>
            </Card>

            <Card as="section">
                <template #title>
                    <h2>{{ t('c_economy.wallet.send_money', 'Send money') }} <FormChip form-id="F-IND-023" name="Funds Transfer" /></h2>
                </template>

                <form @submit.prevent="submitSend">
                    <Field :label="t('c_economy.wallet.to_account_label', 'To which account')" :error="send.errors.to_account_id" required>
                        <template #control="{ id, invalid, describedBy }">
                            <input :id="id" v-model="send.to_account_id" class="field-input mono" type="text"
                                :placeholder="t('c_economy.wallet.account_id_placeholder', 'account id')"
                                :aria-invalid="invalid ? 'true' : undefined" :aria-describedby="describedBy" />
                        </template>
                    </Field>

                    <Field :label="t('c_economy.wallet.how_much_label', 'How much')" :error="send.errors.amount" required>
                        <template #control="{ id, invalid, describedBy }">
                            <input :id="id" v-model="send.amount" class="field-input" type="text"
                                inputmode="decimal" placeholder="0.00"
                                :aria-invalid="invalid ? 'true' : undefined" :aria-describedby="describedBy" />
                        </template>
                    </Field>

                    <Field :label="t('c_economy.wallet.note_label', 'Note (optional)')" :error="send.errors.memo">
                        <template #control="{ id, invalid, describedBy }">
                            <input :id="id" v-model="send.memo" class="field-input" type="text"
                                :aria-invalid="invalid ? 'true' : undefined" :aria-describedby="describedBy" />
                        </template>
                    </Field>

                    <Btn type="submit" variant="primary" :disabled="send.processing">
                        {{ send.processing ? t('c_economy.wallet.sending', 'Sending…') : t('c_economy.wallet.send', 'Send') }}
                    </Btn>
                </form>

                <p class="econ-note">
                    {{ t('c_economy.wallet.send_note', 'You send to an account, not to a name — the same way a ballot is separated from a voter. There is no overdraft: sending more than you hold is refused, not borrowed.') }}
                </p>
            </Card>

            <Card as="section">
                <template #title>
                    <h2>{{ t('c_economy.wallet.make_something', 'Make something') }} <FormChip form-id="F-IND-024" name="Asset Registration" /></h2>
                </template>

                <form @submit.prevent="submitMake">
                    <Field :label="t('c_economy.wallet.what_is_it_label', 'What is it')" :error="make.errors.name" required>
                        <template #control="{ id, invalid, describedBy }">
                            <input :id="id" v-model="make.name" class="field-input" type="text"
                                :placeholder="t('c_economy.wallet.what_is_it_placeholder', 'Hand-woven wool blanket')"
                                :aria-invalid="invalid ? 'true' : undefined" :aria-describedby="describedBy" />
                        </template>
                    </Field>

                    <Field :label="t('c_economy.wallet.physical_or_digital_label', 'Physical or digital')" :error="make.errors.kind" required>
                        <template #control="{ id }">
                            <select :id="id" v-model="make.kind" class="select">
                                <option value="physical">{{ t('c_economy.wallet.opt_physical', 'A physical thing') }}</option>
                                <option value="virtual">{{ t('c_economy.wallet.opt_digital', 'A digital thing') }}</option>
                            </select>
                        </template>
                    </Field>

                    <Field :label="t('c_economy.wallet.describe_label', 'Describe it (optional)')" :error="make.errors.description">
                        <template #control="{ id, invalid, describedBy }">
                            <textarea :id="id" v-model="make.description" class="field-input" rows="2"
                                :aria-invalid="invalid ? 'true' : undefined" :aria-describedby="describedBy"></textarea>
                        </template>
                    </Field>

                    <Field :label="t('c_economy.wallet.how_many_label', 'How many')" :error="make.errors.quantity">
                        <template #control="{ id }">
                            <input :id="id" v-model="make.quantity" class="field-input" type="text" inputmode="decimal" />
                        </template>
                    </Field>

                    <Btn type="submit" variant="primary" :disabled="make.processing">
                        {{ make.processing ? t('c_economy.wallet.registering', 'Registering…') : t('c_economy.wallet.register_it', 'Register it') }}
                    </Btn>
                </form>

                <p class="econ-note">
                    {{ t('c_economy.wallet.make_note', 'Registering a thing gives it its own identity and a provenance record — every hand it passes through is kept. Then you can offer it on the market.') }}
                </p>
            </Card>

            <Card as="section" :title="t('c_economy.wallet.things_you_hold', 'Things you hold')">
                <form class="wallet-asset-search" @submit.prevent="searchAssets">
                    <label for="wallet-asset-search">{{ t('c_economy.wallet.find_item_label', 'Find an item by the beginning of its name') }}</label>
                    <div>
                        <input id="wallet-asset-search" v-model="assetSearch" type="search" maxlength="120" />
                        <button type="submit" :disabled="assetSearching">{{ assetSearching ? t('c_economy.wallet.searching', 'Searching…') : t('c_economy.wallet.search_items', 'Search items') }}</button>
                    </div>
                </form>
                <p v-if="assetSearchError" class="wallet-asset-error" role="alert">{{ assetSearchError }}</p>
                <p v-if="assets.length" class="econ-note" role="status">{{ t('c_economy.wallet.items_on_page', { count: assets.length }) }}</p>
                <DataTable
                    v-if="assets.length"
                    :columns="assetColumns"
                    :rows="assetRows()"
                    row-key="id"
                    :caption="t('c_economy.wallet.caption_assets', 'Items in your account on this page, in name order')"
                    :aria-busy="assetSearching"
                />
                <p v-else class="econ-note" role="status">{{ asset_directory.query ? t('c_economy.wallet.no_items_start', { query: asset_directory.query }) : t('c_economy.wallet.no_items_held', 'You are not holding any registered items.') }}</p>
                <nav v-if="asset_directory.previous || asset_directory.next" class="wallet-asset-pages" :aria-label="t('c_economy.wallet.item_pages', 'Your item pages')">
                    <Link v-if="asset_directory.previous" :href="asset_directory.previous" v-bind="assetVisitOptions()">{{ t('c_economy.wallet.previous_items', 'Previous items') }}</Link>
                    <Link v-if="asset_directory.next" :href="asset_directory.next" v-bind="assetVisitOptions()">{{ t('c_economy.wallet.more_items', 'More items') }}</Link>
                </nav>
            </Card>

            <Card as="section" :title="t('c_economy.wallet.activity_title', 'Activity')">
                <DataTable
                    v-if="transactions.length"
                    :columns="txColumns"
                    :rows="txRows()"
                    row-key="id"
                    :caption="t('c_economy.wallet.caption_tx', 'Your transactions, newest first — 20 per page')"
                />
                <p v-else class="econ-note">{{ t('c_economy.wallet.no_transactions', 'No transactions on this page.') }}</p>
                <HistoryPager cursor-key="transactions_cursor" :pages="transaction_pages" :only="['transactions', 'transaction_pages']" first="/economy/wallet" :label="t('c_economy.wallet.tx_history_label', 'Transaction history pages')" />
            </Card>

            <Card as="section" :title="t('c_economy.wallet.receipts_title', 'Stipend receipts')">
                <DataTable
                    v-if="receipts.length"
                    :columns="receiptColumns"
                    :rows="receiptRows()"
                    row-key="id"
                    :caption="t('c_economy.wallet.caption_receipts', 'Your recorded civic stipend payments')"
                />
                <p v-else class="econ-note">{{ t('c_economy.wallet.no_receipts', 'No stipend receipts on this page.') }}</p>
                <nav class="wallet-asset-pages" :aria-label="t('c_economy.wallet.receipt_pages', 'Stipend receipt pages')">
                    <Link v-if="receipt_pages.previous" :href="receipt_pages.previous" :only="['receipts', 'receipt_pages']" preserve-state preserve-scroll rel="prev">{{ t('c_economy.wallet.previous_receipts', 'Previous receipts') }}</Link>
                    <Link v-if="receipt_pages.next" :href="receipt_pages.next" :only="['receipts', 'receipt_pages']" preserve-state preserve-scroll rel="next">{{ t('c_economy.wallet.next_receipts', 'Next receipts') }}</Link>
                </nav>
            </Card>
        </template>
    </PageScaffold>
</template>

<style scoped>
.wallet-asset-search label { display: block; margin-block-end: .4rem; }
.wallet-asset-search > div, .wallet-asset-pages { display: flex; flex-wrap: wrap; gap: .5rem 1rem; align-items: center; }
.wallet-asset-search input { flex: 1; min-inline-size: 12rem; }
.wallet-asset-search button, .wallet-asset-pages a { min-block-size: 44px; padding: .5rem .75rem; }
.wallet-asset-pages { margin-block-start: .75rem; }
.wallet-asset-error { color: var(--gov-danger); font-size: .875rem; }
.wallet-asset-search button:focus-visible, .wallet-asset-search input:focus-visible, .wallet-asset-pages a:focus-visible { outline: 3px solid var(--gov-accent); outline-offset: 3px; }
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
