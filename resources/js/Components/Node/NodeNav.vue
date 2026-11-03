<script setup>
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { NODE_PAGES } from './nodeNavigation.js';

defineProps({ current: { type: String, required: true } });
const { t } = useI18n();
</script>

<template>
    <nav :aria-label="t('c_node.nav.label', 'Node sections')" class="node-nav">
        <Link v-for="p in NODE_PAGES" :key="p.key" :href="p.href"
              :class="{ active: current === p.key }" :aria-current="current === p.key ? 'page' : undefined">
            {{ t('c_node.nav.' + p.key, p.label) }}
        </Link>
    </nav>
</template>

<style scoped>
.node-nav { display: flex; flex-wrap: wrap; gap: .35rem; border-block-end: 1px solid var(--gov-border); padding-block-end: .5rem; }
.node-nav a { padding: .5rem .7rem; border-radius: var(--radius-sm); color: var(--gov-fg); text-decoration: none; }
.node-nav a.active { background: var(--gov-surface-2); font-weight: var(--weight-semibold); box-shadow: inset 0 -2px var(--gov-primary); }
.node-nav a:focus-visible { outline: 2px solid var(--gov-primary); outline-offset: 2px; }
</style>
