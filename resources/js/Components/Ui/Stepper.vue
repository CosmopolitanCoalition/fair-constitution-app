<script setup>
/**
 * Ui/Stepper — generic horizontal pipeline stepper (FE-D1;
 * PHASE_D_DESIGN_frontend.md §A.7). Tiny wrapper over the already-ported
 * .stepper/.stepper-step(--done/--active) classes (components.css §
 * "Horizontal stepper").
 *
 * First consumer: the Board of Governors pipeline
 * (Nomination dossier · F-EXE-001 → Consent vote · F-LEG-020 → Seated ·
 * R-18 — mockups/executive/departments.html lines 90–93). The Phase A
 * backlog item #6 (SetupStepper re-skin) can migrate to it later — noted,
 * not in scope here.
 */
import Icon from '@/Components/Ui/Icon.vue';

defineProps({
    /** [{ label, icon?, state: 'done'|'active'|'pending' }] */
    steps: { type: Array, required: true },
});
</script>

<template>
    <ol class="stepper">
        <li
            v-for="(step, i) in steps"
            :key="i"
            class="stepper-step"
            :class="{
                'stepper-step--done': step.state === 'done',
                'stepper-step--active': step.state === 'active',
            }"
            :aria-current="step.state === 'active' ? 'step' : undefined"
        >
            <Icon v-if="step.icon" :name="step.icon" size="sm" />
            {{ step.label }}
        </li>
    </ol>
</template>
