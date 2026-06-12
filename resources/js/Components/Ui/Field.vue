<script setup>
/**
 * Ui/Field — .field / .field-label / .field-hint / .field-error / .field--invalid.
 * The `control` scoped slot receives { id, invalid, describedBy } for wiring
 * to <input class="field-input">, <select class="select"> or a textarea.
 * `error` wires straight to Inertia `form.errors.x`.
 */
import { computed, useId } from 'vue';
import Icon from '@/Components/Ui/Icon.vue';

const props = defineProps({
    label: { type: String, required: true },
    hint: { type: String, default: null },
    error: { type: String, default: null },
    id: { type: String, default: null },
    required: { type: Boolean, default: false },
});

const uid = useId();
const fieldId = computed(() => props.id ?? `field-${uid}`);
const invalid = computed(() => Boolean(props.error));
const describedBy = computed(() => {
    const ids = [];
    if (props.hint) ids.push(`${fieldId.value}-hint`);
    if (props.error) ids.push(`${fieldId.value}-error`);
    return ids.length ? ids.join(' ') : undefined;
});
</script>

<template>
    <div class="field" :class="{ 'field--invalid': invalid }">
        <label class="field-label" :for="fieldId">
            {{ label }}<span v-if="required" aria-hidden="true"> *</span>
        </label>
        <slot name="control" :id="fieldId" :invalid="invalid" :described-by="describedBy">
            <input
                :id="fieldId"
                class="field-input"
                :required="required"
                :aria-invalid="invalid ? 'true' : undefined"
                :aria-describedby="describedBy"
            />
        </slot>
        <span v-if="hint" :id="`${fieldId}-hint`" class="field-hint">{{ hint }}</span>
        <span v-if="error" :id="`${fieldId}-error`" class="field-error">
            <Icon name="alert-triangle" size="sm" /> {{ error }}
        </span>
    </div>
</template>
