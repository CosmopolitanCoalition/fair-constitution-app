<script setup>
import { computed } from 'vue'

const props = defineProps({
    current: { type: Number, required: true },
    completed: { type: Number, required: true },
})

const STEPS = [
    { n: 0, label: 'Cosmic Address' },
    { n: 1, label: 'Constitutional Defaults' },
    { n: 2, label: 'Map Data' },
    { n: 3, label: 'Build Districts' },
    { n: 4, label: 'Confirm & Seat Institutions' },
]

function statusOf(n) {
    if (n < props.current) return 'done'
    if (n === props.current) return 'current'
    if (n <= props.completed + 1) return 'reachable'
    return 'locked'
}

function iconFor(n) {
    const s = statusOf(n)
    if (s === 'done') return '✓'
    return String(n)
}

const steps = computed(() => STEPS.map(s => ({ ...s, status: statusOf(s.n) })))
</script>

<template>
    <ol class="flex items-center w-full gap-2 overflow-x-auto pb-2" aria-label="Setup progress">
        <li
            v-for="(s, i) in steps"
            :key="s.n"
            class="flex items-center flex-1 min-w-0"
        >
            <a
                :href="s.status === 'locked' || s.deferred ? undefined : `/setup/step/${s.n}`"
                :class="[
                    'flex items-center gap-2 px-3 py-2 rounded-md border text-sm transition-colors w-full',
                    s.status === 'current' && 'bg-blue-600 border-blue-500 text-white',
                    s.status === 'done' && 'bg-emerald-700 border-emerald-600 text-emerald-50 hover:bg-emerald-600',
                    s.status === 'reachable' && 'bg-gray-800 border-gray-700 text-gray-300 hover:bg-gray-700',
                    (s.status === 'locked' || s.deferred) && 'bg-gray-900 border-gray-800 text-gray-600 cursor-not-allowed',
                ]"
                :aria-current="s.status === 'current' ? 'step' : undefined"
                :aria-disabled="s.status === 'locked' || s.deferred ? 'true' : undefined"
                :title="s.deferred ? 'Coming in a later release' : undefined"
            >
                <span
                    :class="[
                        'w-6 h-6 rounded-full flex items-center justify-center text-xs font-semibold shrink-0',
                        s.status === 'current' && 'bg-white text-blue-600',
                        s.status === 'done' && 'bg-emerald-300 text-emerald-900',
                        s.status === 'reachable' && 'bg-gray-700 text-gray-200',
                        (s.status === 'locked' || s.deferred) && 'bg-gray-800 text-gray-600',
                    ]"
                >{{ iconFor(s.n) }}</span>
                <span class="truncate">{{ s.label }}</span>
            </a>
            <span
                v-if="i < steps.length - 1"
                class="mx-1 text-gray-700 shrink-0"
                aria-hidden="true"
            >→</span>
        </li>
    </ol>
</template>
