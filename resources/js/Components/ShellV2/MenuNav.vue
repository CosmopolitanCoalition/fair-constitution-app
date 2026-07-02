<script setup>
/**
 * ShellV2/MenuNav — the two-tier player menu (ported from mockups/v3
 * shell-v2.js sidebarNavInner). Fills the Menu flyout in the bottom command
 * bar: the player tier ("Go") on top, the full design-contract sitemap
 * collapsed beneath ("All screens — the full map").
 *
 * Everything renders from registry/surfaces.js — THE single machine source.
 *   href === null            → "Planned · Phase N" (disabled, never a dead link)
 *   href === 'tour:start'    → enters tour mode at stop 1
 *   item.roles ∌ user roles  → disabled with a "Requires R-xx" hint
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import Icon from '@/Components/Ui/Icon.vue';
import { PLAYER_NAV, SITEMAP, tourStartHref } from '@/registry/surfaces.js';

const props = defineProps({
    roles: { type: Array, default: () => ['R-00'] },
    currentNavId: { type: String, default: null },
});

const roleSet = computed(() => new Set(props.roles));

function hrefFor(item) {
    return item.href === 'tour:start' ? tourStartHref() : item.href;
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
                <Link
                    v-if="item.href"
                    class="sidebar-link"
                    :href="hrefFor(item)"
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
            <div v-for="section in SITEMAP" :key="section.key" class="sidebar-section">
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
