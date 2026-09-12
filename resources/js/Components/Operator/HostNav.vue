<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { HOST_PAGES, HOST_SECTIONS, hostSubpages } from './hostNavigation.js';

const props = defineProps({ current: { type: String, required: true } });
const { t } = useI18n();
const subpages = computed(() => hostSubpages(props.current));
const group = computed(() => HOST_PAGES[props.current]?.group);
const label = (key, fallback) => t('c_host.nav.' + key, fallback);
</script>

<template>
    <div class="host-navigation">
        <nav :aria-label="label('host', 'Host workspace')" class="host-nav">
            <Link v-for="section in HOST_SECTIONS" :key="section.key" :href="HOST_PAGES[section.page].href"
                :class="{ active: group === section.key }" :aria-current="current === section.page ? 'page' : undefined">
                {{ label(section.key, section.label) }}
            </Link>
        </nav>
        <nav v-if="subpages.length" :aria-label="label('section', 'Host section')" class="host-nav host-nav--context">
            <Link v-for="item in subpages" :key="item.key" :href="item.href"
                :class="{ active: current === item.key }" :aria-current="current === item.key ? 'page' : undefined">
                {{ label(item.key, item.label) }}
            </Link>
        </nav>
    </div>
</template>

<style scoped>
.host-navigation { margin-block: var(--space-4); }
.host-nav { display: flex; flex-wrap: wrap; gap: .35rem; border-block-end: 1px solid var(--gov-border); padding-block-end: .5rem; }
.host-nav a { padding: .5rem .7rem; border-radius: var(--radius-sm); color: var(--gov-fg); text-decoration: none; }
.host-nav a.active { background: var(--gov-surface-2); font-weight: var(--weight-semibold); box-shadow: inset 0 -2px var(--gov-primary); }
.host-nav a:focus-visible { outline: 2px solid var(--gov-primary); outline-offset: 2px; }
.host-nav--context { border: 0; margin-block-start: .35rem; font-size: var(--text-sm); }
</style>
