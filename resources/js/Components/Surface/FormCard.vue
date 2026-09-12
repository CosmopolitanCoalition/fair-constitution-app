<script setup>
/**
 * Surface/FormCard — the canonical form pattern (DESIGN_frontend_port.md §D3).
 * Every state-changing form in the app goes through a FormCard with a
 * canonical F-ID; the ID travels in the request payload as `form_id`
 * (asserted server-side by the constitutional-engine middleware).
 *
 *   <FormCard
 *       :form="surface.forms.find((f) => f.id === 'F-IND-003')"
 *       :inertia-form="form"
 *       @submit="submit"
 *   >
 *       <Field label="Jurisdiction" :error="form.errors.jurisdiction_id">…</Field>
 *   </FormCard>
 *
 * Renders: Card → h2 with the canonical action name, a readable role and
 * citation line, the default slot (Fields), and a submit
 * Btn bound to the Inertia form's `processing`.
 */
import { computed, onMounted, useId } from 'vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import ReferenceText from '@/Components/Ui/ReferenceText.vue';
import { useI18n } from 'vue-i18n';
import { referenceLabel } from '@/lib/referenceLabels.js';

const props = defineProps({
    /** SurfaceMeta form record: { id, name, alias, availableTo, citation }. */
    form: { type: Object, required: true },
    /** The page's Inertia useForm() — drives processing/disabled state. */
    inertiaForm: { type: Object, default: null },
    submitLabel: { type: String, default: 'Submit' },
    processingLabel: { type: String, default: 'Submitting…' },
    /**
     * Window-closed UX (FE-B3): disables the submit Btn. ALWAYS paired
     * with engine-side enforcement — UI disabling is UX, never the
     * boundary (PHASE_B_DESIGN_frontend.md §B.2).
     */
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['submit']);

const headingId = useId();
const { t } = useI18n();
const roleLabel = (id) => referenceLabel(id, { translate: (key, fallback) => t(key, fallback) });
const formLabel = computed(() => referenceLabel(props.form.id, { name: props.form.name, translate: (key, fallback) => t(key, fallback) }));

/* The canonical form_id rides every submission of this Inertia form.
   transform() merges it at serialization time, so pages keep their useForm
   field lists untouched; the hidden input mirrors it in the markup. */
onMounted(() => {
    if (props.inertiaForm?.transform) {
        props.inertiaForm.transform((data) => ({ ...data, form_id: props.form.id }));
    }
});

function onSubmit() {
    emit('submit');
}
</script>

<template>
    <Card as="section" :aria-labelledby="headingId">
        <template #title>
            <h2 :id="headingId">
                {{ formLabel }}
            </h2>
        </template>

        <p v-if="form.availableTo?.length || form.citation" class="citation" style="margin-block-end: var(--space-3)">
            <template v-if="form.availableTo?.length">{{ t('c_references.filed_by', 'Filed by') }} {{ form.availableTo.map(roleLabel).join(', ') }}</template>
            <template v-if="form.availableTo?.length && form.citation"> · </template>
            <ReferenceText v-if="form.citation">{{ form.citation }}</ReferenceText>
        </p>

        <form novalidate @submit.prevent="onSubmit">
            <input type="hidden" name="form_id" :value="form.id" />

            <slot />

            <div class="cluster">
                <Btn type="submit" variant="primary" :disabled="disabled || (inertiaForm?.processing ?? false)">
                    {{ inertiaForm?.processing ? processingLabel : submitLabel }}
                </Btn>
                <slot name="actions" />
            </div>
        </form>
    </Card>
</template>
