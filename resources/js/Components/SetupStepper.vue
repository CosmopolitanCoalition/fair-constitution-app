<script setup>
import { computed } from 'vue'

// THE WIZARD LADDER (Wave 6): Steps 0 to 6. The server describes every step
// (`settings.ladder`: n, label, applies, status) so a step that does not apply
// to this instance renders as skipped, never as pending. Without a ladder the
// stepper falls back to the counter convention: completed = n means steps
// 0..n-1 are done and the next step is n.
const props = defineProps({
    current: { type: Number, required: true },
    completed: { type: Number, required: true },
    steps: { type: Array, default: null },
})

const FALLBACK = [
    { n: 0, label: 'Cosmic Address' },
    { n: 1, label: 'Constitutional Defaults' },
    { n: 2, label: 'Map Data' },
    { n: 3, label: 'Build Districts' },
    { n: 4, label: 'Scale Up Institutions' },
    { n: 5, label: 'Simulate' },
    { n: 6, label: 'Confirm & Close' },
]

function fallbackStatus(n) {
    if (n === props.current) return 'current'
    if (n < props.completed) return 'done'
    if (n === props.completed) return 'reachable'
    return 'locked'
}

const steps = computed(() => {
    if (Array.isArray(props.steps) && props.steps.length) {
        return props.steps.map(s => ({
            ...s,
            status: s.n === props.current ? 'current' : s.status,
        }))
    }
    return FALLBACK.map(s => ({ ...s, applies: true, status: fallbackStatus(s.n) }))
})

function iconFor(s) {
    if (s.status === 'done') return '✓'
    if (s.status === 'skipped') return '–'
    return String(s.n)
}

function clickable(s) {
    return s.status === 'done' || s.status === 'current' || s.status === 'reachable'
}
</script>

<template>
    <ol class="flex items-center w-full gap-2 overflow-x-auto pb-2" aria-label="Setup progress">
        <li
            v-for="(s, i) in steps"
            :key="s.n"
            class="flex items-center flex-1 min-w-0"
        >
            <a
                :href="clickable(s) ? `/setup/step/${s.n}` : undefined"
                :class="[
                    'flex items-center gap-2 px-3 py-2 rounded-md border text-sm transition-colors w-full',
                    s.status === 'current' && 'bg-blue-600 border-blue-500 text-white',
                    s.status === 'done' && 'bg-emerald-700 border-emerald-600 text-emerald-50 hover:bg-emerald-600',
                    s.status === 'reachable' && 'bg-gray-800 border-gray-700 text-gray-300 hover:bg-gray-700',
                    s.status === 'locked' && 'bg-gray-900 border-gray-800 text-gray-600 cursor-not-allowed',
                    s.status === 'skipped' && 'bg-gray-900 border-dashed border-gray-800 text-gray-600 cursor-not-allowed',
                ]"
                :aria-current="s.status === 'current' ? 'step' : undefined"
                :aria-disabled="clickable(s) ? undefined : 'true'"
                :title="s.status === 'skipped' ? 'Not part of this setup: the choices at map acceptance skip this step' : undefined"
            >
                <span
                    :class="[
                        'w-6 h-6 rounded-full flex items-center justify-center text-xs font-semibold shrink-0',
                        s.status === 'current' && 'bg-white text-blue-600',
                        s.status === 'done' && 'bg-emerald-300 text-emerald-900',
                        s.status === 'reachable' && 'bg-gray-700 text-gray-200',
                        (s.status === 'locked' || s.status === 'skipped') && 'bg-gray-800 text-gray-600',
                    ]"
                >{{ iconFor(s) }}</span>
                <span class="truncate">{{ s.label }}<span v-if="s.status === 'skipped'" class="text-gray-600"> (skipped)</span></span>
            </a>
            <span
                v-if="i < steps.length - 1"
                class="mx-1 text-gray-700 shrink-0"
                aria-hidden="true"
            >→</span>
        </li>
    </ol>
</template>
