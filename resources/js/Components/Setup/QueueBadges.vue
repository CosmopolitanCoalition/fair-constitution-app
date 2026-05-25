<script setup>
defineProps({
    isos: { type: Array, default: () => [] },
})
</script>

<template>
    <div
        v-if="isos && isos.length > 0"
        class="flex items-center gap-2 text-xs text-gray-400 flex-wrap"
    >
        <span class="uppercase tracking-wider text-gray-500">Next</span>
        <span
            v-for="iso in isos"
            :key="iso"
            class="font-mono bg-gray-950 border border-gray-800 rounded px-2 py-0.5 text-gray-300"
        >
            {{ iso }}
        </span>
    </div>
</template>
