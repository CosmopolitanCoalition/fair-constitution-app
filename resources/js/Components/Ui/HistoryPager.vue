<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    pages: { type: Object, default: () => ({ previous: null, next: null }) },
    only: { type: Array, required: true },
    first: { type: String, required: true },
    label: { type: String, required: true },
});
const busy = ref(false);
const error = ref('');
function visit(url) {
    if (busy.value) return;
    router.get(url, {}, {
        only: props.only, preserveState: true, preserveScroll: true,
        onStart: () => { busy.value = true; error.value = ''; },
        onFinish: () => { busy.value = false; },
        onError: errors => { error.value = Object.values(errors)[0] || 'This page could not be loaded. Try again.'; },
    });
}
</script>

<template>
    <nav class="history-pages" :aria-label="label" :aria-busy="busy">
        <button v-if="pages.previous" type="button" :disabled="busy" @click="visit(pages.previous)">Previous</button>
        <button v-if="pages.next" type="button" :disabled="busy" @click="visit(pages.next)">Next</button>
        <button v-if="pages.previous || error" type="button" :disabled="busy" @click="visit(first)">First page</button>
        <span role="status">{{ busy ? 'Loading records…' : '' }}</span>
        <p v-if="error" role="alert">{{ error }}</p>
    </nav>
</template>

<style scoped>
.history-pages { display: flex; align-items: center; flex-wrap: wrap; gap: 0.75rem; margin-block: 1rem; }
.history-pages p { color: var(--gov-danger, #d55); }
</style>
