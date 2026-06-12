<script setup>
/**
 * Shell/AppHeader — .app-header chrome bar. PURE presentational: everything
 * arrives via props/slots; the AppShell layout (follow-up work item) wires
 * shared props (auth, jurisdiction, instance) into it.
 *
 * Slots: emblem (wordmark image), switcher (JurisdictionSwitcher), search,
 * actions (notifications / locale select), auth (overrides the role badge).
 *
 * Requires vue-i18n to be installed on the app (chrome-only i18n, §C6).
 */
import { useI18n } from 'vue-i18n';
import Avatar from '@/Components/Ui/Avatar.vue';

defineProps({
    appName: { type: String, default: 'World of Statecraft' },
    homeHref: { type: String, default: '/' },
    /** { name, initials } or null when logged out. */
    user: { type: Object, default: null },
    /** Highest derived role, e.g. { id: 'R-04', label: 'Voter' }. */
    role: { type: Object, default: null },
});

const { t } = useI18n({ useScope: 'global' });
</script>

<template>
    <header class="app-header">
        <a class="wordmark" :href="homeHref">
            <slot name="emblem" />
            <span>{{ appName }}</span>
        </a>

        <slot name="switcher" />

        <span class="header-spacer"></span>

        <slot name="search" />
        <slot name="actions" />

        <slot name="auth">
            <span v-if="user" class="role-badge" :title="t('header.persona')">
                <Avatar :initials="user.initials ?? ''" />
                <span>{{ user.name }}</span>
                <span v-if="role" class="citation">{{ role.id }} · {{ role.label }}</span>
            </span>
        </slot>
    </header>
</template>
