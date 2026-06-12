<script setup>
/**
 * Shell/AppSidebar — role-aware sidebar over the NAV object (Navigation/nav.js).
 * PURE presentational: nav structure, derived roles, live phases and the
 * current item all arrive as props (the AppShell layout wires shared props).
 *
 * Port of shell.js renderSidebar():
 *  - section hidden unless visibility 'all' or roles ∩ section.roles;
 *  - item disabled with .prereq-hint unless roles ∩ item.enabledRoles;
 *  - items whose phase is not live render disabled with a
 *    "Planned · Phase X" flag — the full constitutional sitemap stays
 *    visible from day one, without dead links.
 */
import { useI18n } from 'vue-i18n';
import Icon from '@/Components/Ui/Icon.vue';

const props = defineProps({
    /** NavSection[] from Navigation/nav.js. */
    nav: { type: Array, required: true },
    /** Derived role IDs for the current user, e.g. ['R-01','R-02']. */
    roles: { type: Array, default: () => [] },
    /** Sidebar item id carrying aria-current="page". */
    currentNavId: { type: String, default: null },
    /** Phases shipped on this instance (shared prop app.phasesLive). */
    phasesLive: { type: Array, default: () => ['A'] },
});

const { t } = useI18n({ useScope: 'global' });

const intersects = (a, b) => a.some((x) => b.includes(x));

const sectionVisible = (section) =>
    section.visibility === 'all' || intersects(props.roles, section.roles ?? []);

const itemPlanned = (item) => Boolean(item.phase) && !props.phasesLive.includes(item.phase);

const itemEnabled = (item) =>
    !itemPlanned(item) && (!item.enabledRoles || intersects(props.roles, item.enabledRoles));
</script>

<template>
    <nav class="sidebar" :aria-label="t('nav.menu')">
        <details class="sidebar-toggle" open>
            <summary><Icon name="menu" size="sm" /> {{ t('nav.menu') }}</summary>
            <div class="sidebar-nav">
                <template v-for="section in nav" :key="section.key">
                    <div v-if="sectionVisible(section)" class="sidebar-section">
                        <span class="sidebar-title eyebrow">{{ t(section.titleKey) }}</span>
                        <template v-for="item in section.items" :key="item.id">
                            <a
                                v-if="itemEnabled(item)"
                                class="sidebar-link"
                                :href="item.href"
                                :aria-current="item.id === currentNavId ? 'page' : undefined"
                            >
                                <Icon :name="item.icon" size="sm" />
                                {{ t(item.labelKey) }}
                            </a>
                            <template v-else>
                                <span class="sidebar-link sidebar-link--disabled" aria-disabled="true">
                                    <Icon :name="item.icon" size="sm" />
                                    {{ t(item.labelKey) }}
                                    <span v-if="itemPlanned(item)" class="planned-flag">
                                        {{ t('nav.planned') }} · {{ t('nav.phase') }} {{ item.phase }}
                                    </span>
                                </span>
                                <span v-if="!itemPlanned(item) && item.prereq" class="prereq-hint">
                                    {{ t('nav.requires') }} {{ item.prereq }}
                                </span>
                            </template>
                        </template>
                    </div>
                </template>
            </div>
        </details>
    </nav>
</template>
