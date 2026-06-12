<script setup>
/**
 * Ui/LifecycleTracker — compact horizontal lifecycle (e.g. Bill) for list
 * rows and dashboards. Stages before `current` render as done.
 */
import { computed } from 'vue';

const props = defineProps({
    stages: { type: Array, required: true },
    current: { type: String, required: true },
});

const currentIndex = computed(() => props.stages.indexOf(props.current));
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
        >{{ stage }}</li>
    </ol>
</template>
