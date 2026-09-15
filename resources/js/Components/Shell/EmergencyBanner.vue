<script setup>
/**
 * Shell/EmergencyBanner — cross-surface emergency banner (FE-C1;
 * PHASE_C_DESIGN_frontend.md §A.8). Wired in Layouts/AppShell.vue above
 * the page slot from the shared prop app.activeEmergencies —
 * HandleInertiaRequests shares active powers whose area covers any
 * jurisdiction in the viewer's association chain ("the county sees the
 * state's power because it lies inside the area of effect").
 *
 * Renders NOTHING when empty — every page gets the banner for free.
 * Copy from mockups/legislature/session-console.html emergency-banner
 * (Art. II §7 · CLK-03); the civic-process protection line is verbatim
 * and hardened.
 */
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Banner from '@/Components/Ui/Banner.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

const { t } = useI18n();

defineProps({
    /**
     * [{ id, label, cause, jurisdiction_name, day, max_days, expires_at,
     *    declared_by_legislature, under_review: bool, href }]
     */
    emergencies: { type: Array, required: true },
});

function expiresDate(iso) {
    return iso ? new Date(iso).toLocaleDateString() : '—';
}
</script>

<template>
    <template v-if="emergencies.length > 0">
        <Banner
            v-for="power in emergencies"
            :key="power.id"
            tone="emergency"
            role="alert"
            :title="t('c_shell_components.emergency_banner.title', { label: power.label, day: power.day, max: power.max_days, expires: expiresDate(power.expires_at) })"
        >
            {{ t('c_shell_components.emergency_banner.active', { jurisdiction: power.jurisdiction_name, legislature: power.declared_by_legislature }) }}
            <template v-if="power.under_review">
                {{ ' ' }}
                <StatusBadge tone="info" icon="scale">{{ t('c_shell_components.emergency_banner.judicial_review', 'Judicial review pending') }} · F-JDG-007</StatusBadge>
            </template>
            {{ ' ' }}
            <Link :href="power.href">{{ t('c_shell_components.emergency_banner.open_dashboard', 'Open the emergency powers dashboard') }}</Link>
            <span class="citation" data-no-i18n> · Art. II §7 · CLK-03</span>
        </Banner>
    </template>
</template>
