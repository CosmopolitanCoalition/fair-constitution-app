<script setup>
/**
 * ShellV2/MenuNav — the two-tier player menu (ported from mockups/v3
 * shell-v2.js sidebarNavInner). Fills the Menu flyout in the bottom command
 * bar: the player tier ("Go") on top, the full design-contract sitemap
 * collapsed beneath ("All screens — the full map").
 *
 * Everything renders from registry/surfaces.js — THE single machine source.
 *   href === null            → "Planned · Phase N" (disabled, never a dead link)
 *   href === 'tour:start'    → the tour TOGGLE — arms the mode IN PLACE on the
 *                              current page (A2 ruling); no navigation, toggle
 *                              again to exit. Every page is a valid stop.
 *   item.roles ∌ user roles  → disabled with a "Requires R-xx" hint
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import Icon from '@/Components/Ui/Icon.vue';
import { PLAYER_NAV, SITEMAP } from '@/registry/surfaces.js';
import { useTour } from '@/composables/useTour.js';

const props = defineProps({
    roles: { type: Array, default: () => ['R-00'] },
    currentNavId: { type: String, default: null },
    /** Demo/sandbox world — items flagged `sandbox: true` (the dev kits, whose
     *  routes only register there) are hidden elsewhere, never dead links. */
    sandbox: { type: Boolean, default: false },
});

const roleSet = computed(() => new Set(props.roles));

/* The tour is a MODE toggled in place (A2) — the menu owns its control. */
const { active: tourActive, toggle: toggleTour } = useTour();

const sitemap = computed(() =>
    SITEMAP.map((section) => ({
        ...section,
        items: section.items.filter((item) => !item.sandbox || props.sandbox),
    })).filter((section) => section.items.length),
);

function isTour(item) {
    return item.href === 'tour:start';
}
function allowed(item) {
    if (!item.roles) return true;
    return item.roles.some((r) => roleSet.value.has(r));
}
function prereq(item) {
    return item.roles ? item.roles[item.roles.length - 1] : null;
}
</script>

<template>
    <nav class="sidebar-nav" aria-label="Primary">
        <!-- TIER 1 · the player tier — where you actually go -->
        <div class="sidebar-section">
            <span class="sidebar-title eyebrow">Go</span>
            <template v-for="item in PLAYER_NAV" :key="item.id">
                <!-- the tour is a MODE toggled in place — a button, never a link -->
                <button
                    v-if="isTour(item)"
                    type="button"
                    class="sidebar-link sidebar-link--btn"
                    :aria-pressed="tourActive"
                    @click="toggleTour"
                >
                    <Icon :name="item.icon" size="sm" /> {{ tourActive ? 'End guided tour' : item.label }}
                </button>
                <Link
                    v-else-if="item.href"
                    class="sidebar-link"
                    :href="item.href"
                    :aria-current="currentNavId === item.id ? 'page' : undefined"
                >
                    <Icon :name="item.icon" size="sm" /> {{ item.label }}
                </Link>
                <span v-else class="sidebar-link sidebar-link--disabled" aria-disabled="true">
                    <Icon :name="item.icon" size="sm" /> {{ item.label }}
                    <span class="planned-flag">Planned<template v-if="item.phase"> · Phase {{ item.phase }}</template></span>
                </span>
            </template>
        </div>

        <!-- TIER 2 · every screen — the full design-contract sitemap -->
        <details class="sidebar-more">
            <summary class="sidebar-title eyebrow">
                All screens — the full map <Icon name="chevron-down" size="sm" />
            </summary>
            <div v-for="section in sitemap" :key="section.key" class="sidebar-section">
                <span class="sidebar-title eyebrow">{{ section.title }}</span>
                <template v-for="item in section.items" :key="section.key + ':' + item.id">
                    <Link
                        v-if="item.href && allowed(item)"
                        class="sidebar-link"
                        :href="item.href"
                        :aria-current="currentNavId === item.id ? 'page' : undefined"
                    >
                        <Icon :name="item.icon" size="sm" /> {{ item.label }}
                    </Link>
                    <span
                        v-else-if="item.href"
                        class="sidebar-link sidebar-link--disabled"
                        aria-disabled="true"
                        :title="'Requires ' + prereq(item)"
                    >
                        <Icon :name="item.icon" size="sm" /> {{ item.label }}
                        <span class="prereq-hint">Requires {{ prereq(item) }}</span>
                    </span>
                    <span v-else class="sidebar-link sidebar-link--disabled" aria-disabled="true">
                        <Icon :name="item.icon" size="sm" /> {{ item.label }}
                        <span class="planned-flag">Planned<template v-if="item.phase"> · Phase {{ item.phase }}</template></span>
                    </span>
                </template>
            </div>
        </details>
    </nav>
</template>
