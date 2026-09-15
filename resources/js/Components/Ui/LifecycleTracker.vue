<script setup>
/**
 * Ui/LifecycleTracker — compact horizontal lifecycle (e.g. Bill) for list
 * rows and dashboards. Stages before `current` render as done.
 */
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    stages: { type: Array, required: true },
    current: { type: String, required: true },
    // Optional state-machine key (e.g. 'bill'). When set, each stage resolves
    // through the c_states catalog (c_states.<machine>.<token>); the raw token
    // stays the fallback, so an unset machine renders the token unchanged.
    machine: { type: String, default: '' },
});

const { t } = useI18n();

const currentIndex = computed(() => props.stages.indexOf(props.current));

// One display label per stage token. Catalog key is machine-scoped. The raw
// token is the fallback. This component rendered the raw token before i18n, so
// an unset machine keeps the display byte-identical.
function stageLabel(stage) {
    return t('c_states.' + props.machine + '.' + stage, stage);
}
</script>

<template>
    <ol class="lifecycle">
        <li
            v-for="(stage, i) in stages"
            :key="stage"
            class="lifecycle-stage"
            :class="{
                'lifecycle-stage--done': currentIndex >= 0 && i < currentIndex,
                'lifecycle-stage--current': i === currentIndex,
            }"
            :aria-current="i === currentIndex ? 'step' : undefined"
        >{{ stageLabel(stage) }}</li>
    </ol>
</template>
