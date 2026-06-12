<script setup>
/**
 * Ui/AmendableSetting — renders an amendable constitutional setting: current
 * value plus a mono meta line (key · amendable · default · citation).
 * Values come from `constitutional_settings` payloads, never literals.
 */
import { computed } from 'vue';

const props = defineProps({
    value: { type: [String, Number], required: true },
    settingKey: { type: String, required: true },
    citation: { type: String, default: null },
    /** Constitutional default, shown for honest comparison. */
    defaultValue: { type: [String, Number], default: null },
});

const meta = computed(() => {
    const parts = [props.settingKey, 'amendable'];
    if (props.defaultValue !== null) parts.push(`default ${props.defaultValue}`);
    if (props.citation) parts.push(props.citation);
    return parts.join(' · ');
});
</script>

<template>
    <span class="amendable">
        <span class="amendable-value"><slot>{{ value }}</slot></span>
        <span class="amendable-meta">{{ meta }}</span>
    </span>
</template>
