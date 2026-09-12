<script setup>
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Store, BriefcaseBusiness, Wallet, ArrowRight, Users, Handshake, HeartHandshake, Landmark } from 'lucide-vue-next';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import { formatMoney } from '@/lib/money.js';

defineOptions({ layout: AppShellV2 });
defineProps({
    currency: { type: Object, default: null },
    account: { type: Object, default: null },
});

// Inherit the selected language; untranslated messages fall back to English.
const { t } = useI18n({
    useScope: 'local',
    fallbackLocale: 'en',
    messages: { en: {
        title: 'Work & trade',
        intro: 'Find useful work, trade goods and services, and organize with others.',
        start: 'What would you like to do?',
        market: 'Buy or sell',
        marketHint: 'Browse goods and services, or offer something of your own.',
        marketAction: 'Open the market',
        work: 'Find work',
        workHint: 'Explore open jobs and see what organizations need.',
        workAction: 'Browse work',
        wallet: 'My wallet',
        walletHint: 'Your balance, payments, and items in one place.',
        balance: 'Your balance',
        noWallet: 'No wallet is linked to your account in this currency.',
        walletAction: 'Open my wallet',
        currencyMissing: 'A currency has not been defined yet',
        currencyMissingHint: 'You can explore organizations and work. Priced transactions become available after the currency is defined.',
        together: 'Work together',
        organizations: 'Organizations',
        organizationsHint: 'Find a group, join its work, or manage an organization.',
        agreements: 'My agreements',
        agreementsHint: 'Review terms, negotiate changes, and sign agreements.',
        assistance: 'Give or find help',
        assistanceHint: 'Browse public requests for support.',
        finance: 'Public money & shared funds',
        financeHint: 'Accounts, currency rules, and other financial tools',
        treasury: 'Public accounts',
        units: 'Currency & monetary policy',
        stipend: 'Civic stipend',
        exchange: 'Shares & instruments',
        joint: 'Shared funds',
        unit: 'Currency',
    } },
});

const sharedActions = [
    { href: '/organizations', key: 'organizations', icon: Users },
    { href: '/economy/agreements', key: 'agreements', icon: Handshake },
    { href: '/economy/market?tab=assistance', key: 'assistance', icon: HeartHandshake },
];
const financeActions = [
    { href: '/economy/treasury', key: 'treasury' },
    { href: '/economy/units', key: 'units' },
    { href: '/economy/stipend', key: 'stipend' },
    { href: '/economy/exchange', key: 'exchange' },
    { href: '/economy/joint-ledgers', key: 'joint' },
];
</script>

<template>
    <PageScaffold :title="t('title')">
        <template #intro>{{ t('intro') }}</template>
        <Banner v-if="!currency" tone="info" :title="t('currencyMissing')">
            {{ t('currencyMissingHint') }}
        </Banner>

        <section aria-labelledby="economy-start">
            <h2 id="economy-start" class="econ-section-title">{{ t('start') }}</h2>
            <div class="econ-primary">
                <Link href="/economy/market" class="econ-action">
                    <Store class="econ-icon" :size="26" aria-hidden="true" />
                    <h3>{{ t('market') }}</h3>
                    <p>{{ t('marketHint') }}</p>
                    <span class="econ-action-label">{{ t('marketAction') }} <ArrowRight :size="17" aria-hidden="true" /></span>
                </Link>
                <Link href="/economy/market?tab=work" class="econ-action">
                    <BriefcaseBusiness class="econ-icon" :size="26" aria-hidden="true" />
                    <h3>{{ t('work') }}</h3>
                    <p>{{ t('workHint') }}</p>
                    <span class="econ-action-label">{{ t('workAction') }} <ArrowRight :size="17" aria-hidden="true" /></span>
                </Link>
                <Link href="/economy/wallet" class="econ-action econ-action--wallet">
                    <Wallet class="econ-icon" :size="26" aria-hidden="true" />
                    <h3>{{ t('wallet') }}</h3>
                    <template v-if="account">
                        <span class="econ-balance-label">{{ t('balance') }}</span>
                        <strong class="econ-balance">{{ formatMoney(account.balance, currency) }}</strong>
                    </template>
                    <p v-else>{{ currency ? t('noWallet') : t('walletHint') }}</p>
                    <span class="econ-action-label">{{ t('walletAction') }} <ArrowRight :size="17" aria-hidden="true" /></span>
                </Link>
            </div>
        </section>

        <section aria-labelledby="economy-together">
            <h2 id="economy-together" class="econ-section-title">{{ t('together') }}</h2>
            <div class="econ-shared">
                <Link v-for="action in sharedActions" :key="action.key" :href="action.href" class="econ-shared-action">
                    <component :is="action.icon" :size="22" class="econ-icon" aria-hidden="true" />
                    <span><strong>{{ t(action.key) }}</strong><span class="econ-hint">{{ t(`${action.key}Hint`) }}</span></span>
                    <ArrowRight :size="16" aria-hidden="true" />
                </Link>
            </div>
        </section>

        <details class="econ-finance">
            <summary>
                <Landmark :size="22" class="econ-icon" aria-hidden="true" />
                <span><strong>{{ t('finance') }}</strong><span class="econ-hint">{{ t('financeHint') }}</span></span>
            </summary>
            <p v-if="currency" class="econ-currency">{{ t('unit') }}: {{ currency.name }} · {{ currency.code }}</p>
            <nav :aria-label="t('finance')" class="econ-finance-links">
                <Link v-for="action in financeActions" :key="action.key" :href="action.href">
                    {{ t(action.key) }} <ArrowRight :size="16" aria-hidden="true" />
                </Link>
            </nav>
        </details>
    </PageScaffold>
</template>

<style scoped>
.econ-section-title { margin: 0 0 var(--space-3); font-size: 1.05rem; }
.econ-primary { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--space-4); }
.econ-action { display: flex; flex-direction: column; align-items: flex-start; gap: var(--space-3); padding: var(--space-5, 1.25rem); border: 1px solid var(--gov-border); border-radius: var(--radius-md, .5rem); background: var(--gov-surface, #fff); color: inherit; text-decoration: none; }
.econ-action h3 { margin: 0; font-size: 1.25rem; }
.econ-action p { margin: 0; color: var(--gov-fg-muted); font-size: .9rem; line-height: 1.6; }
.econ-icon { flex-shrink: 0; color: var(--gov-accent, #2456b3); }
.econ-action-label { display: flex; align-items: center; gap: .5rem; margin-block-start: auto; padding-block-start: var(--space-3); font-weight: 600; color: var(--gov-accent, #2456b3); }
.econ-action--wallet { background: color-mix(in oklch, var(--gov-accent) 8%, var(--gov-surface)); }
.econ-balance-label { color: var(--gov-fg-muted); font-size: .8rem; }
.econ-balance { font-size: clamp(1.25rem, 2.4vw, 2rem); overflow-wrap: anywhere; font-variant-numeric: tabular-nums; }
.econ-shared { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--space-3); }
.econ-shared-action { display: flex; align-items: flex-start; gap: var(--space-3); padding: var(--space-4); color: inherit; text-decoration: none; border: 1px solid var(--gov-border); border-radius: var(--radius-md, .5rem); }
.econ-shared-action > span { flex: 1; }
.econ-hint { display: block; margin-block-start: .3rem; color: var(--gov-fg-muted); font-size: .8125rem; line-height: 1.5; font-weight: 400; }
.econ-action:hover, .econ-shared-action:hover { border-color: var(--gov-accent, #2456b3); }
.econ-action:focus-visible, .econ-shared-action:focus-visible, .econ-finance summary:focus-visible, .econ-finance a:focus-visible { outline: 3px solid var(--gov-accent, #2456b3); outline-offset: 3px; }
.econ-finance { border-block-start: 1px solid var(--gov-border); padding-block-start: var(--space-4); }
.econ-finance summary { display: flex; align-items: center; gap: var(--space-3); cursor: pointer; }
.econ-finance summary::after { content: '+'; margin-inline-start: auto; font-size: 1.5rem; }
.econ-finance[open] summary::after { content: '−'; }
.econ-finance-links { display: flex; flex-wrap: wrap; gap: var(--space-3); margin-block-start: var(--space-4); }
.econ-finance-links a { display: inline-flex; align-items: center; gap: .5rem; padding: var(--space-2) var(--space-3); border: 1px solid var(--gov-border); border-radius: var(--radius-md, .5rem); text-decoration: none; }
.econ-currency { margin-block: var(--space-4) 0; color: var(--gov-fg-muted); font-size: .875rem; }
@media (max-width: 900px) { .econ-shared { grid-template-columns: 1fr; } }
@media (max-width: 650px) { .econ-primary { grid-template-columns: 1fr; } .econ-action { gap: var(--space-2); } }
</style>
