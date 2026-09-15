<script setup>
/**
 * Shell/DevBar — dev-only impersonation bar (the demo bar's successor, §C5).
 * Gold-dashed purple .dev-bar chrome: visually non-product, collapsible.
 * PURE presentational: the server decides whether it renders (shared prop
 * devBar), and the controls (impersonation select, view-context jurisdiction,
 * RTL flip, pseudo-locale) mount via the default slot in the layout WI.
 */
import { useI18n } from 'vue-i18n';
import Icon from '@/Components/Ui/Icon.vue';

const { t } = useI18n();

defineProps({
    label: { type: String, default: 'Dev controls — not part of the application' },
    open: { type: Boolean, default: false },
    /** { name } of the impersonated user, or null. */
    impersonating: { type: Object, default: null },
    /** { name } of the real (impersonating) user. */
    realUser: { type: Object, default: null },
});
</script>

<template>
    <div class="dev-bar">
        <details class="dev-details" :open="open">
            <summary>
                <span class="dev-bar-label">
                    <Icon name="alert-triangle" size="sm" />
                    {{ label }}
                    <span v-if="impersonating">
                        {{ t('c_shell_components.dev_bar.impersonating', { name: impersonating.name }) }}<template v-if="realUser"> {{ t('c_shell_components.dev_bar.return_to', { name: realUser.name }) }}</template>
                    </span>
                </span>
                <Icon name="chevron-down" size="sm" class="dev-caret" />
            </summary>
            <div class="dev-controls">
                <!-- Static dev-tool pointer (no props — the residency page
                     hosts the actual dev grant/simulate controls). -->
                <a class="dev-control" href="/civic/residency">{{ t('c_shell_components.dev_bar.residency_tool', 'Residency tool → /civic/residency') }}</a>
                <slot />
            </div>
        </details>
    </div>
</template>
