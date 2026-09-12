<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    active: { type: String, default: '' },
    backHref: { type: String, default: '' },
    backLabel: { type: String, default: '' },
});

const sections = [
    { key: 'market', label: 'Market & work', href: '/economy/market' },
    { key: 'agreements', label: 'My agreements', href: '/economy/agreements' },
    { key: 'wallet', label: 'My wallet', href: '/economy/wallet' },
    { key: 'shares', label: 'Shares', href: '/economy/exchange' },
];
</script>

<template>
    <nav class="trade-nav" aria-label="Work and trade">
        <Link href="/economy" class="trade-home">Work &amp; trade</Link>
        <div class="trade-sections">
            <Link v-for="section in sections" :key="section.key" :href="section.href"
                :aria-current="active === section.key ? 'page' : undefined">
                {{ section.label }}
            </Link>
        </div>
        <Link v-if="backHref" :href="backHref" class="trade-back">← {{ backLabel }}</Link>
    </nav>
</template>

<style scoped>
.trade-nav { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem 1rem; border-block-end: 1px solid var(--gov-border); padding-block-end: .75rem; }
.trade-home { font-weight: 600; }
.trade-sections { display: flex; flex-wrap: wrap; gap: .25rem; }
.trade-nav a { display: inline-flex; align-items: center; min-block-size: 44px; padding: .4rem .65rem; color: inherit; border-radius: .4rem; text-decoration: none; }
.trade-nav a[aria-current] { background: var(--gov-surface-subtle); font-weight: 600; box-shadow: inset 0 -2px var(--gov-accent); }
.trade-nav a:hover { background: var(--gov-surface-subtle); }
.trade-nav a:focus-visible { outline: 3px solid var(--gov-accent); outline-offset: 2px; }
.trade-back { flex-basis: 100%; font-size: .875rem; }
</style>
