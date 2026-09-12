<script setup>
/**
 * Ui/AmendableSetting — renders an amendable constitutional setting: current
 * value plus a readable label. Technical references are optional details.
 * Values come from `constitutional_settings` payloads, never literals.
 */
import { computed, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { settingLabel } from '@/lib/referenceLabels.js';

const props = defineProps({
    value: { type: [String, Number], required: true },
    settingKey: { type: String, required: true },
    label: { type: String, default: null },
    citation: { type: String, default: null },
    /** Constitutional default, shown for honest comparison. */
    defaultValue: { type: [String, Number], default: null },
});

const { t } = useI18n();
const text = (key, fallback) => t('c_references.' + key, fallback);
const expanded = ref(false);
const detailsId = useId();
const readableLabel = computed(() => settingLabel(props.settingKey, {
    name: props.label,
    translate: (key, fallback) => t(key, fallback),
}));
</script>

<template>
    <span class="amendable">
        <span class="amendable-value"><slot>{{ value }}</slot></span>
        <span class="amendable-meta">{{ readableLabel }} · {{ text('amendable_rule', 'Amendable rule') }}</span>
        <button type="button" class="setting-reference-toggle" :aria-expanded="expanded" :aria-controls="detailsId" @click="expanded = !expanded">
            {{ text('reference_details', 'Reference details') }}
        </button>
        <span v-if="expanded" :id="detailsId" class="amendable-meta" role="note">
            <code>{{ settingKey }}</code>
            <template v-if="defaultValue !== null"> · {{ text('configured_default', 'Reference default') }} {{ defaultValue }}</template>
            <template v-if="citation"> · {{ citation }}</template>
        </span>
    </span>
</template>

<style scoped>
.setting-reference-toggle { background: none; border: 0; padding: .25rem 0; color: var(--gov-accent); cursor: pointer; text-align: start; font: inherit; font-size: .8rem; text-decoration: underline; }
.setting-reference-toggle:focus-visible { outline: 2px solid currentColor; outline-offset: 3px; }
</style>
