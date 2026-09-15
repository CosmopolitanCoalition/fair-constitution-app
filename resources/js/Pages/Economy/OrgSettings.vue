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
 * Dues use F-ORG-001 and share issuance uses F-ORG-008. Their current
 * handlers require the exact agent; private ledger access also includes
 * seated board members. Recipient searches and ownership pages load alone.
 */
import { computed, ref, watch } from 'vue';
import { router, useForm, usePage, useRemember } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import HistoryPager from '@/Components/Ui/HistoryPager.vue';
import Banner from '@/Components/Ui/Banner.vue';
import OrganizationNav from '@/Components/Organizations/OrganizationNav.vue';
import SelectionIdentity from '@/Components/Ui/SelectionIdentity.vue';
import { formatMoney, formatQuantity, formatCount, formatWhen, shortId } from '@/lib/money.js';

defineOptions({ layout: AppShellV2 });
const { t } = useI18n();

const props = defineProps({
    surface: { type: Object, required: true },
    currency: { type: Object, default: null },
    org: { type: Object, required: true },
    can_steer: { type: Boolean, default: false },
    can_update_dues: { type: Boolean, default: false },
    can_issue_shares: { type: Boolean, default: false },
    compose: { type: Boolean, default: false },
    dues: { type: Object, required: true },
    shares: { type: Object, required: true },
    recipient_directory: { type: Object, default: () => ({ query: '', type: 'users', candidates: [], searched: false, previous: null, next: null }) },
    /** The org's own ledger (money plane — counterparties are accounts). */
    ledger: { type: Object, default: () => ({ has_account: false, balance: '0.000000', movements: [] }) },
    /** Levies filed (Art. V §4); [] when the org has filed none. */
    taxes: { type: Array, default: () => [] },
    tax_pages: { type: Object, default: () => ({ previous: null, next: null }) },
    /** Fair-market conversions on the org's equity (named ownership plane). */
    conversions: { type: Array, default: () => [] },
    conversion_pages: { type: Object, default: () => ({ previous: null, next: null }) },
});

const settingsPath = `/organizations/${props.org.id}/settings`;

// Two independent dials, each a single-key F-ORG-001 'update_settings' filing
// — the same shape as the board-nomination-window dial. Seeded from the
// current policy so a save is an edit, not a reset.
const amountForm = useForm({ key: 'dues_amount', value: props.dues.amount ?? '' });
const periodForm = useForm({ key: 'dues_period_days', value: props.dues.period_days ?? '' });

const saveAmount = () => amountForm.post(settingsPath, { preserveScroll: true });
const savePeriod = () => periodForm.post(settingsPath, { preserveScroll: true });

const economyPath = `/organizations/${props.org.id}/economy`;
const page = usePage();
const draftKey = `share-issue:${page.props.auth?.user?.id ?? 'guest'}:${props.org.id}`;
const issueOpen = useRemember(ref(props.compose), `${draftKey}:open`);
const shareForm = useForm(draftKey, { holder_type: 'users', holder_id: '', units: '' });
const chosenName = useRemember(ref(''), `${draftKey}:holder`);
const chosenContext = useRemember(ref(null), `${draftKey}:holder-context`);
const chosenRecipient = computed(() => ({
    ...(chosenContext.value?.id === shareForm.holder_id && chosenContext.value?.type === shareForm.holder_type ? chosenContext.value : {}),
    id: shareForm.holder_id, type: shareForm.holder_type, name: chosenName.value || t('c_economy.org_settings.selected_recipient', 'Selected recipient'),
}));
const recipientQuery = ref(props.recipient_directory.query);
const recipientType = ref(props.recipient_directory.type);
const searching = ref(false);
const pagingShares = ref(false);
const searchError = ref('');
const sharePageError = ref('');
watch(() => props.recipient_directory.query, value => { recipientQuery.value = value; });
watch(() => props.recipient_directory.type, value => { recipientType.value = value; });
watch(() => props.recipient_directory.candidates, candidates => {
    const selected = candidates.find(person => person.id === shareForm.holder_id && person.type === shareForm.holder_type);
    if (selected) { chosenName.value = selected.name; chosenContext.value = { ...selected }; }
}, { immediate: true });

function searchOptions() {
    return {
        only: ['recipient_directory'], preserveState: true, preserveScroll: true,
        onStart: () => { searching.value = true; searchError.value = ''; },
        onFinish: () => { searching.value = false; },
        onError: errors => { searchError.value = Object.values(errors)[0] ?? t('c_economy.org_settings.search_failed', 'The search could not be completed.'); },
    };
}
function directoryUrl(keys, url = null, values = {}) {
    // Each partial directory owns only its query fields. Its returned links
    // may predate another list's navigation, so merge into the current URL.
    const current = new URL(page.url || economyPath, 'http://fixture.invalid');
    const target = url ? new URL(url, current) : null;
    for (const key of keys) {
        current.searchParams.delete(key);
        const value = target ? target.searchParams.get(key) : values[key];
        if (value !== null && value !== undefined) current.searchParams.set(key, value);
    }
    return economyPath + current.search + current.hash;
}
function searchRecipients(url = null) {
    if (searching.value) return;
    router.get(directoryUrl(['issue', 'recipient_q', 'recipient_type', 'recipient_cursor'], url,
        { issue: 1, recipient_q: recipientQuery.value.trim(), recipient_type: recipientType.value }), {}, searchOptions());
}
function chooseRecipient(person) {
    shareForm.holder_type = person.type;
    shareForm.holder_id = person.id;
    chosenName.value = person.name;
    chosenContext.value = { ...person };
    shareForm.clearErrors('holder_id', 'holder_type');
}
function clearRecipient() {
    shareForm.holder_id = '';
    chosenName.value = '';
    chosenContext.value = null;
}
function pageShares(url) {
    if (pagingShares.value) return;
    router.get(directoryUrl(['share_cursor'], url), {}, {
        only: ['shares'], preserveState: true, preserveScroll: true,
        onStart: () => { pagingShares.value = true; sharePageError.value = ''; },
        onFinish: () => { pagingShares.value = false; },
        onError: errors => { sharePageError.value = Object.values(errors)[0] ?? t('c_economy.org_settings.ownership_failed', 'The ownership records could not be loaded.'); },
    });
}
function issueShares() {
    shareForm.post(`/organizations/${props.org.id}/shares`, {
        preserveScroll: true,
        onSuccess: () => { shareForm.reset(); chosenName.value = ''; chosenContext.value = null; issueOpen.value = false; },
    });
}
</script>

<template>
    <PageScaffold :title="t('c_economy.org_settings.title', { name: org.name })">
        <template #intro>
            {{ t('c_economy.org_settings.intro', 'Review this organization\'s dues, shares, and financial records. Its agent can update dues and issue shares for a stock organization.') }}
        </template>

        <OrganizationNav :organization="org" current="finances" />
        <Banner v-if="page.props.flash?.status" tone="info" role="status">{{ page.props.flash.status }}</Banner>

        <!-- ------------------------------------------------------- dues -->
        <Card as="section" :title="t('c_economy.org_settings.dues_title', 'Dues')">
            <p class="econ-desc">
                {{ t('c_economy.org_settings.dues_desc', 'Voluntary membership dues are a private subscription between a member and an organization that chooses to charge them. A due is a membership obligation — never a tax, never a share. It is paid as an ordinary transfer from the member\'s own wallet.') }}
            </p>

            <div v-if="dues.has_dues" class="dues-current">
                <dl class="econ-facts">
                    <div>
                        <dt>{{ t('c_economy.org_settings.amount_label', 'Amount') }}</dt>
                        <dd>{{ formatMoney(dues.amount, currency) }}</dd>
                    </div>
                    <div>
                        <dt>{{ t('c_economy.org_settings.every_label', 'Every') }}</dt>
                        <dd><template v-if="dues.period_days">{{ t('c_economy.org_settings.days_suffix', { count: formatCount(dues.period_days) }) }}</template><template v-else>—</template></dd>
                    </div>
                </dl>
            </div>
            <p v-else class="econ-absent">
                {{ t('c_economy.org_settings.no_dues', 'This organization charges no dues.') }}
            </p>

            <ul class="dues-rails">
                <li><strong>{{ t('c_economy.org_settings.rail_optin_strong', 'Always opt-in.') }}</strong>{{ t('c_economy.org_settings.rail_optin_body', ' A member joins and leaves freely; if dues lapse the membership ends — no right is ever withheld.') }}</li>
                <li><strong>{{ t('c_economy.org_settings.rail_gate_strong', 'Never a gate on a right.') }}</strong>{{ t('c_economy.org_settings.rail_gate_body', ' No due may attach to voting, candidacy, residency, or petitioning (Art. I · Art. II §8).') }}</li>
                <li><strong>{{ t('c_economy.org_settings.rail_paid_strong', 'Paid by the member.') }}</strong>{{ t('c_economy.org_settings.rail_paid_body', ' Members make a dues payment each period. Payments are not automatic.') }}</li>
            </ul>

            <!-- write: the org's own dial (F-ORG-001 update_settings) -->
            <div class="dues-dials">
                <form class="dues-dial" @submit.prevent="saveAmount">
                    <label for="dues-amount">{{ t('c_economy.org_settings.dues_amount_label', { symbol: currency?.symbol ?? t('c_economy.org_settings.units_fallback', 'units') }) }}</label>
                    <div class="dues-dial-row">
                        <input id="dues-amount" v-model="amountForm.value" type="number" min="0" step="0.000001" inputmode="decimal" />
                        <button type="submit" :disabled="!can_update_dues || amountForm.processing">{{ amountForm.processing ? t('c_economy.org_settings.saving', 'Saving…') : t('c_economy.org_settings.save', 'Save') }}</button>
                    </div>
                    <p v-if="amountForm.errors.constitution" class="dues-err">{{ amountForm.errors.constitution }}</p>
                    <p v-if="amountForm.errors.value" class="dues-err" role="alert">{{ amountForm.errors.value }}</p>
                </form>

                <form class="dues-dial" @submit.prevent="savePeriod">
                    <label for="dues-period">{{ t('c_economy.org_settings.period_label', 'Period (days)') }}</label>
                    <div class="dues-dial-row">
                        <input id="dues-period" v-model="periodForm.value" type="number" min="1" max="3650" step="1" inputmode="numeric" />
                        <button type="submit" :disabled="!can_update_dues || periodForm.processing">{{ periodForm.processing ? t('c_economy.org_settings.saving', 'Saving…') : t('c_economy.org_settings.save', 'Save') }}</button>
                    </div>
                    <p v-if="periodForm.errors.constitution" class="dues-err">{{ periodForm.errors.constitution }}</p>
                    <p v-if="periodForm.errors.value" class="dues-err" role="alert">{{ periodForm.errors.value }}</p>
                </form>
            </div>
            <p class="econ-note">
                {{ t('c_economy.org_settings.dues_changes_note', 'Changes are recorded in this organization\'s history. Set the amount to zero to charge no dues.') }}
            </p>
        </Card>

        <!-- ------------------------------------------------------ shares -->
        <Card as="section" :title="t('c_economy.org_settings.shares_title', 'Shares')">
            <p v-if="!shares.issued" class="econ-absent">
                {{ shares.issuable ? t('c_economy.org_settings.no_shares_issued', 'No shares issued yet.') : shares.note }}
            </p>
            <template v-else>
                <p class="econ-note">{{ t('c_economy.org_settings.shares_entry_note', 'Each entry is one recorded ownership stake. A holder may have several entries.') }}</p>
                <table class="cap-table">
                    <thead>
                        <tr><th scope="col">{{ t('c_economy.org_settings.th_holder', 'Holder') }}</th><th scope="col">{{ t('c_economy.org_settings.th_units', 'Units') }}</th><th scope="col">{{ t('c_economy.org_settings.th_share', 'Share') }}</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="h in shares.holders" :key="h.id">
                            <td>{{ h.holder }}</td>
                            <td>{{ formatQuantity(h.units) }}</td>
                            <td>{{ h.pct !== null ? h.pct + '%' : '—' }}</td>
                        </tr>
                    </tbody>
                </table>
                <p v-if="!shares.holders.length" class="econ-note">{{ t('c_economy.org_settings.no_ownership_entries', 'No ownership entries on this page. Return to the first page to refresh.') }}</p>
                <nav class="share-pages" :aria-label="t('c_economy.org_settings.ownership_pages', 'Ownership pages')" :aria-busy="pagingShares">
                    <button v-if="shares.previous" :disabled="pagingShares" @click="pageShares(shares.previous)">{{ t('c_economy.org_settings.previous', 'Previous') }}</button>
                    <button v-if="shares.next" :disabled="pagingShares" @click="pageShares(shares.next)">{{ t('c_economy.org_settings.next', 'Next') }}</button>
                    <button v-if="shares.previous || !shares.holders.length" :disabled="pagingShares" @click="pageShares(economyPath)">{{ t('c_economy.org_settings.first_page', 'First page') }}</button>
                    <span role="status">{{ pagingShares ? t('c_economy.org_settings.loading_ownership', 'Loading ownership…') : t('c_economy.org_settings.entries_on_page', { count: shares.holders.length }) }}</span>
                </nav>
            </template>
            <p v-if="sharePageError" class="dues-err" role="alert">{{ sharePageError }}</p>
            <p class="econ-note">
                {{ t('c_economy.org_settings.shares_note_before', 'An organization may issue equity') }} <strong>{{ t('c_economy.org_settings.shares_note_strong', 'shares') }}</strong> {{ t('c_economy.org_settings.shares_note_after', '— never a currency (that is reserved to the most-encompassing jurisdiction, Art. V §5). Ownership is a public fact recorded by name; the money that changes hands when a share trades stays on the private wallet ledger.') }}
            </p>

            <button v-if="can_issue_shares" type="button" :aria-expanded="issueOpen" aria-controls="share-composer" @click="issueOpen = !issueOpen">
                {{ issueOpen ? t('c_economy.org_settings.close_share_form', 'Close share form') : t('c_economy.org_settings.issue_shares', 'Issue shares') }}
            </button>
            <div v-if="can_issue_shares && issueOpen" id="share-composer" class="share-composer">
                <h3>{{ t('c_economy.org_settings.issue_shares_heading', 'Issue shares') }}</h3>
                <p class="econ-note">{{ t('c_economy.org_settings.issue_shares_note', 'Select a recipient and enter the new units. This adds public ownership and changes existing ownership percentages.') }}</p>
                <form class="recipient-search" @submit.prevent="searchRecipients()">
                    <label for="share-recipient-type">{{ t('c_economy.org_settings.recipient_type_label', 'Recipient type') }}</label>
                    <select id="share-recipient-type" v-model="recipientType">
                        <option value="users">{{ t('c_economy.org_settings.opt_person', 'Person') }}</option><option value="organizations">{{ t('c_economy.org_settings.opt_organization', 'Organization') }}</option>
                    </select>
                    <label for="share-recipient-query">{{ t('c_economy.org_settings.name_starts_with', 'Name starts with') }}</label>
                    <input id="share-recipient-query" v-model="recipientQuery" maxlength="120" autocomplete="off" />
                    <button type="submit" :disabled="searching">{{ searching ? t('c_economy.org_settings.searching', 'Searching…') : t('c_economy.org_settings.search', 'Search') }}</button>
                </form>
                <div :aria-busy="searching">
                    <p v-if="searchError" class="dues-err" role="alert">{{ searchError }}</p>
                    <p v-if="!recipient_directory.searched" class="econ-note">{{ t('c_economy.org_settings.search_by_name', 'Search by public name to select a person or organization.') }}</p>
                    <p v-else-if="!recipient_directory.candidates.length" role="status">{{ t('c_economy.org_settings.no_recipients', 'No matching recipients on this page.') }}</p>
                    <ul v-if="recipient_directory.candidates.length" class="recipient-results">
                        <li v-for="person in recipient_directory.candidates" :key="`${person.type}:${person.id}`">
                            <SelectionIdentity :person="person" />
                            <button type="button" :disabled="shareForm.holder_id === person.id && shareForm.holder_type === person.type" :aria-label="t('c_economy.org_settings.select_person_aria', { name: person.name, id: person.id })" @click="chooseRecipient(person)">{{ t('c_economy.org_settings.select', 'Select') }}</button>
                        </li>
                    </ul>
                    <nav class="share-pages" :aria-label="t('c_economy.org_settings.recipient_pages', 'Recipient search pages')">
                        <button v-if="recipient_directory.previous" :disabled="searching" @click="searchRecipients(recipient_directory.previous)">{{ t('c_economy.org_settings.previous', 'Previous') }}</button>
                        <button v-if="recipient_directory.next" :disabled="searching" @click="searchRecipients(recipient_directory.next)">{{ t('c_economy.org_settings.next', 'Next') }}</button>
                    </nav>
                </div>
                <form class="share-issue" @submit.prevent="issueShares">
                    <div v-if="shareForm.holder_id" class="selected-recipient"><p>{{ t('c_economy.org_settings.selected_recipient', 'Selected recipient') }}</p><SelectionIdentity :person="chosenRecipient" /><button type="button" @click="clearRecipient">{{ t('c_economy.org_settings.change', 'Change') }}</button></div>
                    <label for="share-units">{{ t('c_economy.org_settings.new_share_units', 'New share units') }}</label>
                    <input id="share-units" v-model="shareForm.units" type="text" inputmode="decimal" maxlength="21" required pattern="[0-9]{1,14}(\.[0-9]{1,6})?" aria-describedby="share-units-help" />
                    <p id="share-units-help" class="econ-note">{{ t('c_economy.org_settings.share_units_help', 'A positive amount with up to six decimal places. This action does not collect payment.') }}</p>
                    <p v-for="(error, key) in shareForm.errors" :key="key" class="dues-err" role="alert">{{ error }}</p>
                    <button type="submit" :disabled="!shareForm.holder_id || shareForm.processing">{{ shareForm.processing ? t('c_economy.org_settings.issuing', 'Issuing…') : t('c_economy.org_settings.issue_to_recipient', 'Issue shares to selected recipient') }}</button>
                </form>
            </div>
        </Card>

        <!-- ------------------------------------------------- org ledger -->
        <Card as="section" :title="t('c_economy.org_settings.ledger_title', 'This organization\'s ledger')">
            <p class="econ-desc">
                {{ t('c_economy.org_settings.ledger_desc', 'Review the organization\'s balance and payment history. Other parties are identified by account to protect their financial privacy.') }}
            </p>
            <p v-if="ledger.restricted" class="muted">{{ t('c_economy.org_settings.ledger_restricted', 'The wallet ledger and levies are visible to the organization\'s agent and its seated board.') }}</p>
            <template v-if="ledger.has_account">
                <dl class="econ-facts">
                    <div><dt>{{ t('c_economy.org_settings.balance_label', 'Balance') }}</dt><dd>{{ formatMoney(ledger.balance, currency) }}</dd></div>
                    <div><dt>{{ t('c_economy.org_settings.movements_on_page', 'Movements on this page') }}</dt><dd>{{ formatCount(ledger.movements.length) }}</dd></div>
                </dl>
                <table v-if="ledger.movements.length" class="cap-table">
                    <thead>
                        <tr><th scope="col">{{ t('c_economy.org_settings.th_when', 'When') }}</th><th scope="col">{{ t('c_economy.org_settings.th_in_out', 'In / out') }}</th><th scope="col">{{ t('c_economy.org_settings.th_amount', 'Amount') }}</th><th scope="col">{{ t('c_economy.org_settings.th_kind', 'Kind') }}</th><th scope="col">{{ t('c_economy.org_settings.th_other_account', 'Other account') }}</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="m in ledger.movements" :key="m.id">
                            <td>{{ formatWhen(m.at) }}</td>
                            <td>{{ m.direction === 'out' ? t('c_economy.org_settings.dir_out', 'Out') : t('c_economy.org_settings.dir_in', 'In') }}</td>
                            <td>{{ (m.direction === 'out' ? '−' : '+') + formatMoney(m.amount, currency) }}</td>
                            <td>{{ m.kind || '—' }}</td>
                            <td class="mono">{{ m.counterparty_account_id ? shortId(m.counterparty_account_id) : '—' }}</td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="econ-note">{{ t('c_economy.org_settings.no_movements', 'No movements on this page.') }}</p>
                <HistoryPager cursor-key="transactions_cursor" :pages="ledger.pagination" :only="['ledger']" :first="economyPath" :label="t('c_economy.org_settings.org_payment_pages', 'Organization payment pages')" />
            </template>
            <p v-else-if="!ledger.restricted" class="econ-absent">
                {{ t('c_economy.org_settings.no_account', 'This organization holds no economic account yet — it opens when the org first transacts.') }}
            </p>
        </Card>

        <!-- --------------------------------------------------- taxes -->
        <Card as="section" :title="t('c_economy.org_settings.taxes_title', 'Taxes & levies')">
            <p class="econ-desc">
                {{ t('c_economy.org_settings.taxes_desc', 'What the organization has declared and been assessed under public revenue law (Art. V §4). How a levy is computed — its base and rate — is public.') }}
            </p>
            <table v-if="taxes.length" class="cap-table">
                <thead>
                    <tr><th scope="col">{{ t('c_economy.org_settings.th_period', 'Period') }}</th><th scope="col">{{ t('c_economy.org_settings.th_levy', 'Levy') }}</th><th scope="col">{{ t('c_economy.org_settings.th_base_rate', 'Base · rate') }}</th><th scope="col">{{ t('c_economy.org_settings.th_declared', 'Declared') }}</th><th scope="col">{{ t('c_economy.org_settings.th_assessed', 'Assessed') }}</th><th scope="col">{{ t('c_economy.org_settings.th_status', 'Status') }}</th></tr>
                </thead>
                <tbody>
                    <tr v-for="tx in taxes" :key="tx.id">
                        <td>{{ tx.period }}</td>
                        <td>{{ tx.stream || '—' }}</td>
                        <td>
                            <template v-if="tx.rate">{{ t('c_economy.org_settings.rate_on_base', { rate: tx.rate, base: tx.base }) }}<template v-if="tx.civic_exempt">{{ t('c_economy.org_settings.civic_exempt', ' · civic-exempt') }}</template></template>
                            <template v-else>—</template>
                        </td>
                        <td>{{ tx.declared !== null ? formatMoney(tx.declared, currency) : '—' }}</td>
                        <td>{{ tx.assessed !== null ? formatMoney(tx.assessed, currency) : '—' }}</td>
                        <td>{{ tx.status }}</td>
                    </tr>
                </tbody>
            </table>
            <p v-else-if="ledger.restricted" class="econ-absent">
                {{ t('c_economy.org_settings.levies_restricted', 'Levy filings are visible to the organization\'s agent and its seated board.') }}
            </p>
            <p v-else class="econ-absent">
                {{ t('c_economy.org_settings.no_levy_filings', 'No levy filings on this page.') }}
            </p>
            <HistoryPager v-if="!ledger.restricted" cursor-key="taxes_cursor" :pages="tax_pages" :only="['taxes', 'tax_pages']" :first="economyPath" :label="t('c_economy.org_settings.levy_filing_pages', 'Levy filing pages')" />
        </Card>

        <!-- ------------------------------------- fair-market / conversions -->
        <Card as="section" :title="t('c_economy.org_settings.conversions_title', 'Fair-market & conversions')">
            <p class="econ-desc">
                {{ t('c_economy.org_settings.conversions_desc', 'When ownership changes form, the authorizing act records the minimum fair-market value and how it was calculated (Art. III §5). Those records appear here.') }}
            </p>
            <table v-if="conversions.length" class="cap-table">
                <thead>
                    <tr><th scope="col">{{ t('c_economy.org_settings.th_direction', 'Direction') }}</th><th scope="col">{{ t('c_economy.org_settings.th_via', 'Via') }}</th><th scope="col">{{ t('c_economy.org_settings.th_fair_market_floor', 'Fair-market floor') }}</th><th scope="col">{{ t('c_economy.org_settings.th_basis', 'Basis') }}</th><th scope="col">{{ t('c_economy.org_settings.th_status', 'Status') }}</th></tr>
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
                {{ t('c_economy.org_settings.no_conversions', 'No ownership conversions on this page.') }}
            </p>
            <HistoryPager cursor-key="conversions_cursor" :pages="conversion_pages" :only="['conversions', 'conversion_pages']" :first="economyPath" :label="t('c_economy.org_settings.conversion_pages', 'Ownership conversion pages')" />
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
.share-pages { display: flex; align-items: center; flex-wrap: wrap; gap: 0.75rem; margin-block: 1rem; }
.share-composer { margin-block-start: 1rem; padding: 1rem; border: 1px solid var(--gov-border, #445); border-radius: 0.5rem; }
.recipient-search, .share-issue { display: grid; gap: 0.5rem; }
.recipient-search input, .recipient-search select, .share-issue input { inline-size: 100%; max-inline-size: 30rem; min-inline-size: 0; }
.recipient-search button, .share-issue > button { justify-self: start; }
.recipient-results { list-style: none; padding: 0; }
.recipient-results li { display: flex; justify-content: space-between; align-items: center; gap: 1rem; padding-block: 0.5rem; border-block-end: 1px solid var(--gov-border, #445); }
.recipient-results span, .selected-recipient { overflow-wrap: anywhere; }
.share-issue { margin-block-start: 1.5rem; }
.dues-dial-row input { min-inline-size: 0; }
</style>
