<script setup>
import { ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

const props = defineProps({
    pages: { type: Object, default: () => ({ previous: null, next: null }) },
    only: { type: Array, required: true },
    first: { type: String, required: true },
    label: { type: String, required: true },
    cursorKey: { type: String, default: '' },
});
const page = usePage();
const busy = ref(false);
const error = ref('');
function currentUrl(url) {
    if (!props.cursorKey || !page.url) return url;
    // Another independent partial visit may have changed selections/cursors
    // since this link was generated. This pager owns only its seek parameter.
    const base = typeof window === 'undefined' ? 'http://fixture.invalid' : window.location.origin;
    const target = new URL(url, base);
    const current = new URL(page.url, base);
    if (target.origin !== current.origin || target.pathname !== current.pathname) return url;
    const cursor = target.searchParams.get(props.cursorKey);
    if (cursor === null) current.searchParams.delete(props.cursorKey);
    else current.searchParams.set(props.cursorKey, cursor);
    return current.pathname + current.search + current.hash;
}
function visit(url) {
    if (busy.value) return;
    router.get(currentUrl(url), {}, {
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
