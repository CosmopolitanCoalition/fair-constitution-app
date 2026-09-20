<script setup>
import { ref, watch } from 'vue'
import { csrfFetch } from '@/lib/csrf'
const props = defineProps({ run: Object, allowed: Boolean })
const report = ref(null), busy = ref(false), error = ref(''), scopes = ref('')
async function load(after = null) {
    if (!props.allowed || !props.run?.id) return
    busy.value = true; error.value = ''
    try {
        const response = await csrfFetch(`/api/simworld/repair/${props.run.id}${after ? `?after=${encodeURIComponent(after)}` : ''}`)
        if (!response.ok) throw new Error('Could not read the repair inventory.')
        report.value = await response.json()
    } catch (e) { error.value = e.message } finally { busy.value = false }
}
async function submit(apply = false) {
    busy.value = true; error.value = ''
    try {
        const response = await csrfFetch('/api/simworld/repair', { method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(apply ? { apply: props.run.id } : { source: report.value?.source_run_id || props.run.id, scopes: scopes.value.split(/[\s,]+/).filter(Boolean) }) })
        const result = await response.json()
        if (!response.ok || !result.ok) throw new Error(result.reason || result.message || 'Repair request failed.')
        if (apply) await load()
    } catch (e) { error.value = e.message } finally { busy.value = false }
}
watch(() => [props.run?.id, props.run?.phase, props.run?.status, props.allowed], () => load(), { immediate: true })
</script>

<template>
    <section v-if="allowed && run && (run.status === 'done' || report?.source_run_id)" class="rounded-lg border border-amber-700/50 bg-gray-900 p-4 space-y-3">
        <h2 class="font-semibold text-white">Repair this world in place</h2>
        <p class="text-sm text-gray-300">Inspection pauses before any repairs. Review the inventory, then apply it. Existing run history and civic stipend payments are preserved.</p>
        <p v-if="report?.pilot" class="text-sm text-amber-200">Pilot only: success here does not certify the whole world.</p>
        <table v-if="report?.summary?.complete" class="text-sm w-full text-left">
            <caption class="text-left pb-2">Inspected scopes: {{ report.summary.scopes }}</caption>
            <thead><tr><th scope="col">Repair category</th><th scope="col">Scopes</th></tr></thead>
            <tbody><tr v-for="(count, category) in report.summary.categories" :key="category"><th scope="row" class="font-normal">{{ category.replaceAll('_', ' ') }}</th><td>{{ count }}</td></tr></tbody>
        </table>
        <div v-if="run.status === 'done'" class="space-y-2">
            <label class="block text-sm text-gray-300">Pilot jurisdiction UUIDs (optional, separated by spaces or commas)
                <textarea v-model="scopes" rows="2" class="mt-1 block w-full bg-gray-950 rounded border border-gray-600 p-2" />
            </label>
            <p class="text-xs text-gray-400">Leave blank to inspect the original run's complete verification worklist.</p>
            <button :disabled="busy" class="rounded bg-amber-700 px-3 py-2 disabled:opacity-50" @click="submit(false)">Create repair inventory</button>
        </div>
        <div v-if="report?.source_run_id" class="flex flex-wrap gap-2">
            <button :disabled="busy" class="rounded border border-gray-500 px-3 py-2" @click="load()">Refresh inventory</button>
            <button v-if="report.plan_complete && !report.authorized" :disabled="busy" class="rounded bg-emerald-700 px-3 py-2" @click="submit(true)">Apply this repair plan</button>
        </div>
        <p v-if="busy" role="status" class="text-sm text-gray-300">Loading…</p>
        <p v-if="error" role="alert" class="text-sm text-red-300">{{ error }}</p>
        <details v-for="item in report?.items || []" :key="item.unit_key" class="border-t border-gray-700 pt-2 text-sm">
            <summary class="cursor-pointer">{{ item.jurisdiction_id }} · {{ item.status }} <span v-if="item.reason">· {{ item.reason }}</span></summary>
            <pre class="mt-2 overflow-auto whitespace-pre-wrap text-xs text-gray-300">{{ JSON.stringify(item.metrics, null, 2) }}</pre>
        </details>
        <button v-if="report?.next" :disabled="busy" class="rounded border border-gray-500 px-3 py-2" @click="load(report.next)">Next 50 scopes</button>
    </section>
</template>
