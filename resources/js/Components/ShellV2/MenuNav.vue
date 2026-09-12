<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Icon from '@/Components/Ui/Icon.vue';
import { PLAYER_NAV, SITEMAP } from '@/registry/surfaces.js';
import { useTour } from '@/composables/useTour.js';

const props = defineProps({
    roles: { type: Array, default: () => [] },
    currentNavId: { type: String, default: null },
    sandbox: { type: Boolean, default: false },
    setupIncomplete: { type: Boolean, default: false },
});
const page = usePage();
const instance = computed(() => page.props.shellInstance ?? page.props.instance ?? {});
const { t } = useI18n();
const { active: tourActive, toggle: toggleTour } = useTour();
const sections = computed(() => SITEMAP.filter(s => !['node', 'build-team'].includes(s.key)).map(s => ({
    ...s,
    // Primary destinations and shortcuts already have a home above. Unbuilt
    // routes remain in the development inventory, outside player navigation.
    items: s.items.filter(i => i.href && i.href !== 'tour:start' && !PLAYER_NAV.some(p => p.id === i.id)
        && !['legislatures', 'role-explorer'].includes(i.id)),
})).filter(s => s.items.length));
const hostSections = computed(() => SITEMAP.filter(s => ['node', 'build-team'].includes(s.key)).map(s => ({
    ...s, items: s.items.filter(i => i.href && (!i.sandbox || instance.value.localTools === true)),
})));
const shortcuts = [
    { id: 'legislatures', label: 'Legislative maps', href: '/legislatures', icon: 'map' },
    { id: 'role-explorer', label: 'Explore civic roles', href: '/explore', icon: 'users' },
];
const label = item => t('c_navigation.' + item.id, item.label);
function target(item) {
    const place = page.props.jurisdictionContext?.current?.id;
    const scoped = ['/explore', '/civic/square', '/civic/halls', '/civic/petitions', '/civic/commons/square', '/civic/commons/halls'];
    return place && scoped.includes(item.href) ? item.href + '?jurisdiction=' + encodeURIComponent(place) : item.href;
}
function allowed(item) {
    return !props.setupIncomplete || ['/setup', '/jurisdictions', '/learn', '/operator', '/support', '/explore']
        .some(p => item.href === p || item.href.startsWith(p + '/') || item.href.startsWith(p + '?'));
}
</script>

<template>
    <nav class="sidebar-nav" :aria-label="t('c_navigation.primary', 'Primary')">
        <div class="sidebar-section player-destinations">
            <template v-for="item in PLAYER_NAV" :key="item.id">
                <Link v-if="allowed(item)" class="sidebar-link" :href="target(item)"
                      :aria-current="currentNavId === item.id ? 'page' : undefined">
                    <Icon :name="item.icon" size="sm" />{{ label(item) }}
                </Link>
                <span v-else class="sidebar-link sidebar-link--disabled" aria-disabled="true">
                    <Icon :name="item.icon" size="sm" />{{ label(item) }}
                    <small>{{ t('c_navigation.after_setup', 'Available after setup') }}</small>
                </span>
            </template>
        </div>
        <div class="sidebar-section">
            <Link v-for="item in shortcuts.filter(allowed)" :key="item.id" class="sidebar-link" :href="target(item)">
                <Icon :name="item.icon" size="sm" />{{ label(item) }}
            </Link>
            <button type="button" class="sidebar-link sidebar-link--btn" :aria-pressed="tourActive" @click="toggleTour">
                <Icon name="graduation-cap" size="sm" />
                {{ tourActive ? t('c_navigation.end_tour', 'End guided tour') : t('c_navigation.tour', 'Guided tour') }}
            </button>
        </div>
        <details class="sidebar-more">
            <summary class="sidebar-title eyebrow">{{ t('c_navigation.browse', 'Browse activities') }}<Icon name="chevron-down" size="sm" /></summary>
            <details v-for="section in sections" :key="section.key" class="activity-section">
                <summary class="sidebar-title">{{ t('c_navigation.section_' + section.key, section.title) }}<Icon name="chevron-down" size="sm" /></summary>
                <Link v-for="item in section.items.filter(allowed)" :key="item.id" class="sidebar-link" :href="target(item)">
                    <Icon :name="item.icon" size="sm" />{{ label(item) }}
                </Link>
            </details>
        </details>
        <details class="sidebar-more">
            <summary class="sidebar-title eyebrow">{{ t('c_navigation.host', 'Host & development') }}<Icon name="chevron-down" size="sm" /></summary>
            <div v-for="section in hostSections" :key="section.key" class="sidebar-section">
                <span class="sidebar-title">{{ t('c_navigation.section_' + section.key, section.title) }}</span>
                <Link v-for="item in section.items" :key="item.id" class="sidebar-link" :href="item.href">
                    <Icon :name="item.icon" size="sm" />{{ label(item) }}
                </Link>
            </div>
            <Link v-if="page.props.auth?.user?.is_operator" class="sidebar-link" href="/jurisdictions?view=operations">
                {{ t('c_navigation.place_operations', 'Place activation & operations') }}
            </Link>
        </details>
    </nav>
</template>

<style scoped>
.player-destinations { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .25rem; }
.player-destinations .sidebar-link { min-height: 3rem; }
.activity-section { margin-inline-start: .75rem; }
summary { min-height: 44px; cursor: pointer; padding-block: .65rem; }
@media (max-width: 380px) { .player-destinations { grid-template-columns: 1fr; } }
</style>
