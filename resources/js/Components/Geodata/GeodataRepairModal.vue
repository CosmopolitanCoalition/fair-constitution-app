<template>
    <!-- Full-screen overlay — z above the Leaflet stack (which tops out at
         z-[1001] in the viewer) so the form never fights the map. -->
    <div class="fixed inset-0 z-[2000] flex items-center justify-center bg-gray-950/80 px-4"
         @click.self="$emit('close')">
        <div class="w-full max-w-lg bg-gray-900 border border-gray-700 rounded-lg shadow-2xl
                    max-h-[85vh] overflow-y-auto">
            <!-- Header -->
            <div class="flex items-center justify-between px-4 py-3 border-b border-gray-800">
                <div>
                    <div class="text-sm font-semibold text-white">{{ title }}</div>
                    <div v-if="flag" class="text-[11px] text-gray-500 mt-0.5">{{ flag.title }}</div>
                </div>
                <button type="button" @click="$emit('close')"
                        class="text-gray-500 hover:text-white text-lg leading-none px-1">×</button>
            </div>

            <div class="px-4 py-3 space-y-3 text-sm">

                <!-- ── accept_flag ── -->
                <template v-if="mode === 'accept_flag'">
                    <p class="text-gray-400 text-xs">
                        Accept this flag as-is: the condition stays in the data,
                        recorded as reviewed and acceptable. No rows change.
                    </p>
                </template>

                <!-- ── reparent ── -->
                <template v-else-if="mode === 'reparent'">
                    <p class="text-gray-400 text-xs">
                        Move a jurisdiction under a different parent. The move is
                        recorded as a manual repair and can be reverted.
                    </p>
                    <label class="block">
                        <span class="text-gray-300 text-xs">Jurisdiction to move (slug)</span>
                        <input type="text" v-model.trim="targetSlug" placeholder="usa-2-some-county"
                               class="mt-1 w-full px-2 py-1.5 rounded bg-gray-950 border border-gray-700
                                      text-gray-200 text-xs font-mono focus:border-blue-500 focus:outline-none" />
                    </label>
                    <div v-if="candidateParents.length > 0">
                        <span class="text-gray-300 text-xs">Suggested parents</span>
                        <div class="mt-1 flex flex-wrap gap-1.5">
                            <button v-for="cand in candidateParents" :key="cand.slug"
                                    type="button"
                                    @click="newParentSlug = cand.slug"
                                    class="px-2 py-1 rounded text-[11px] font-mono border transition-colors"
                                    :class="newParentSlug === cand.slug
                                        ? 'bg-blue-900/60 border-blue-600 text-blue-200'
                                        : 'bg-gray-950 border-gray-700 text-gray-300 hover:border-gray-500'">
                                {{ cand.name || cand.slug }}
                            </button>
                        </div>
                    </div>
                    <label class="block">
                        <span class="text-gray-300 text-xs">New parent (slug)</span>
                        <input type="text" v-model.trim="newParentSlug" placeholder="usa-1-california"
                               class="mt-1 w-full px-2 py-1.5 rounded bg-gray-950 border border-gray-700
                                      text-gray-200 text-xs font-mono focus:border-blue-500 focus:outline-none" />
                    </label>
                </template>

                <!-- ── synthesize_anchor ── -->
                <template v-else-if="mode === 'synthesize_anchor'">
                    <p class="text-gray-400 text-xs">
                        Create a new intermediate jurisdiction under the parent —
                        its geometry is the union of the listed children, its
                        population their sum — then reparent the children to it.
                    </p>
                    <label class="block">
                        <span class="text-gray-300 text-xs">Parent (slug)</span>
                        <input type="text" v-model.trim="parentSlug" placeholder="usa-0-united-states"
                               class="mt-1 w-full px-2 py-1.5 rounded bg-gray-950 border border-gray-700
                                      text-gray-200 text-xs font-mono focus:border-blue-500 focus:outline-none" />
                    </label>
                    <label class="block">
                        <span class="text-gray-300 text-xs">Name of the new jurisdiction</span>
                        <input type="text" v-model.trim="anchorName" placeholder="Northern Cluster"
                               class="mt-1 w-full px-2 py-1.5 rounded bg-gray-950 border border-gray-700
                                      text-gray-200 text-xs focus:border-blue-500 focus:outline-none" />
                    </label>
                    <label class="block">
                        <span class="text-gray-300 text-xs">Children to gather (one slug per line)</span>
                        <textarea v-model="childSlugsText" rows="5"
                                  class="mt-1 w-full px-2 py-1.5 rounded bg-gray-950 border border-gray-700
                                         text-gray-200 text-xs font-mono focus:border-blue-500 focus:outline-none"></textarea>
                        <span class="text-[10px] text-gray-500">{{ childSlugs.length }} child(ren)</span>
                    </label>
                </template>

                <!-- ── merge_chain ── -->
                <template v-else-if="mode === 'merge_chain'">
                    <p class="text-gray-400 text-xs">
                        Collapse a single-child chain of same-space rows. The
                        <strong class="text-gray-200">topmost</strong> member survives;
                        every lower member's children move to it and the lower
                        members are soft-deleted (recorded, revertible).
                    </p>
                    <label class="block">
                        <span class="text-gray-300 text-xs">Chain — topmost first (one slug per line)</span>
                        <textarea v-model="chainSlugsText" rows="5"
                                  class="mt-1 w-full px-2 py-1.5 rounded bg-gray-950 border border-gray-700
                                         text-gray-200 text-xs font-mono focus:border-blue-500 focus:outline-none"></textarea>
                    </label>
                    <div v-if="chainSlugs.length > 0" class="rounded border border-gray-800 bg-gray-950/60 p-2 space-y-1">
                        <div v-for="(slug, i) in chainSlugs" :key="slug"
                             class="flex items-center gap-2 text-[11px] font-mono"
                             :style="{ paddingLeft: `${i * 12}px` }">
                            <span :class="i === 0 ? 'text-emerald-300' : 'text-gray-400'">{{ slug }}</span>
                            <span v-if="i === 0"
                                  class="px-1.5 py-0 rounded text-[10px] bg-emerald-900 text-emerald-200 border border-emerald-700">
                                survivor
                            </span>
                            <span v-else class="text-[10px] text-gray-600">merged away</span>
                        </div>
                    </div>
                </template>

                <!-- ── recompute_population ── -->
                <template v-else-if="mode === 'recompute_population'">
                    <p class="text-gray-400 text-xs">
                        Replace the stored population. The prior value is recorded
                        so the repair can be reverted.
                    </p>
                    <label class="block">
                        <span class="text-gray-300 text-xs">Jurisdiction (slug)</span>
                        <input type="text" v-model.trim="targetSlug" placeholder="usa-1-california"
                               class="mt-1 w-full px-2 py-1.5 rounded bg-gray-950 border border-gray-700
                                      text-gray-200 text-xs font-mono focus:border-blue-500 focus:outline-none" />
                    </label>
                    <div>
                        <span class="text-gray-300 text-xs">Method</span>
                        <label class="mt-1 flex items-start gap-2 text-gray-200 text-xs">
                            <input type="radio" value="children_sum" v-model="method" class="mt-0.5" />
                            <span>
                                Sum of live children
                                <span class="block text-gray-500">Adds up the direct children's populations.</span>
                            </span>
                        </label>
                        <label class="mt-1 flex items-start gap-2 text-gray-200 text-xs">
                            <input type="radio" value="raster_total" v-model="method" class="mt-0.5" />
                            <span>
                                WorldPop raster total
                                <span class="block text-gray-500">Sums the country's population raster (whole-ISO total).</span>
                            </span>
                        </label>
                    </div>
                </template>

                <!-- ── prune ── -->
                <template v-else-if="mode === 'prune'">
                    <p class="text-gray-400 text-xs">
                        Soft-delete this jurisdiction. Every removed row is
                        recorded, so the prune can be reverted.
                    </p>
                    <label class="block">
                        <span class="text-gray-300 text-xs">Jurisdiction to prune (slug)</span>
                        <input type="text" v-model.trim="targetSlug" placeholder="usa-2-ghost-row"
                               class="mt-1 w-full px-2 py-1.5 rounded bg-gray-950 border border-gray-700
                                      text-gray-200 text-xs font-mono focus:border-blue-500 focus:outline-none" />
                    </label>
                    <label class="flex items-start gap-2 text-gray-200 text-xs">
                        <input type="checkbox" v-model="cascade" class="mt-0.5" />
                        <span>
                            Cascade — also delete the whole subtree beneath it
                            <span class="block text-gray-500">
                                Without this, pruning a row that still has live children is refused.
                            </span>
                        </span>
                    </label>
                    <div class="rounded border border-red-900/70 bg-red-950/40 p-2">
                        <label class="block">
                            <span class="text-red-200 text-xs">
                                Type the slug to confirm{{ cascade ? ' (subtree included)' : '' }}
                            </span>
                            <input type="text" v-model.trim="confirmText" :placeholder="targetSlug"
                                   class="mt-1 w-full px-2 py-1.5 rounded bg-gray-950 border border-red-800
                                          text-gray-200 text-xs font-mono focus:border-red-500 focus:outline-none" />
                        </label>
                    </div>
                </template>

                <!-- Note — every action takes one -->
                <label class="block">
                    <span class="text-gray-300 text-xs">Note (optional)</span>
                    <textarea v-model="note" rows="2" placeholder="Why this repair?"
                              class="mt-1 w-full px-2 py-1.5 rounded bg-gray-950 border border-gray-700
                                     text-gray-200 text-xs focus:border-blue-500 focus:outline-none"></textarea>
                </label>

                <div v-if="error" class="text-xs text-red-400">{{ error }}</div>
            </div>

            <!-- Footer -->
            <div class="flex items-center justify-end gap-2 px-4 py-3 border-t border-gray-800">
                <button type="button" @click="$emit('close')"
                        class="px-3 py-1.5 rounded text-xs font-medium text-gray-300 hover:text-white transition-colors">
                    Cancel
                </button>
                <button type="button" @click="submit" :disabled="busy || !canSubmit"
                        class="px-4 py-1.5 rounded text-xs font-semibold text-white transition-colors
                               disabled:bg-gray-700 disabled:cursor-not-allowed"
                        :class="mode === 'prune' ? 'bg-red-700 hover:bg-red-600' : 'bg-blue-700 hover:bg-blue-600'">
                    {{ busy ? 'Applying…' : submitLabel }}
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { csrfFetch } from '@/lib/csrf'

// One modal, six shapes — the form for whichever repair action the operator
// picked off a flag. Fields prefill from the flag's payload evidence but stay
// editable (the scan's suggestion is input, not a decision). On success emits
// 'applied' with the backend response (which carries refreshed flag counts).
const props = defineProps({
    // accept_flag | reparent | synthesize_anchor | merge_chain |
    // recompute_population | prune
    mode: { type: String, required: true },
    flag: { type: Object, default: null },
})

const emit = defineEmits(['close', 'applied'])

const payload = props.flag?.payload || {}

// Prefill helpers — the scanner (builder A) writes evidence under these keys;
// unknown payload shapes just leave the field blank for the operator to fill.
function firstString(...vals) {
    for (const v of vals) if (typeof v === 'string' && v !== '') return v
    return ''
}
function slugList(...vals) {
    for (const v of vals) {
        if (Array.isArray(v) && v.length > 0) {
            return v
                .map(item => (typeof item === 'string' ? item : item?.slug))
                .filter(s => typeof s === 'string' && s !== '')
        }
    }
    return []
}

const targetSlug = ref(firstString(payload.target_slug, payload.slug, payload.jurisdiction_slug))
const note       = ref('')

// reparent
const newParentSlug = ref(firstString(payload.suggested_parent_slug, payload.new_parent_slug))
const candidateParents = computed(() => {
    const raw = payload.candidate_parents
    if (!Array.isArray(raw)) return []
    return raw
        .map(c => (typeof c === 'string' ? { slug: c } : (c && typeof c.slug === 'string' ? c : null)))
        .filter(Boolean)
})

// synthesize_anchor — prefill ONLY the scanner's suggested anchor parent (the
// cluster's own country row). payload.parent_slug is the MIS-ANCHORING parent
// the cluster must escape; prefilling it would rebuild the defect one level up.
const parentSlug    = ref(firstString(payload.suggested_anchor_parent, payload.suggested_parent, payload.anchor_parent_slug))
const anchorName    = ref(firstString(payload.suggested_name, payload.name))
const childSlugsText = ref(slugList(payload.child_slugs, payload.children, payload.cluster_slugs, payload.member_slugs).join('\n'))
const childSlugs = computed(() =>
    childSlugsText.value.split(/[\s,]+/).map(s => s.trim()).filter(Boolean))

// merge_chain
const chainSlugsText = ref(slugList(payload.chain_slugs, payload.chain).join('\n'))
const chainSlugs = computed(() =>
    chainSlugsText.value.split(/[\s,]+/).map(s => s.trim()).filter(Boolean))

// recompute_population
const method = ref(payload.suggested_method === 'raster_total' ? 'raster_total' : 'children_sum')

// prune
const cascade     = ref(false)
const confirmText = ref('')

const busy  = ref(false)
const error = ref('')

const TITLES = {
    accept_flag:          'Accept flag',
    reparent:             'Reparent jurisdiction',
    synthesize_anchor:    'Synthesize anchor jurisdiction',
    merge_chain:          'Merge same-space chain',
    recompute_population: 'Recompute population',
    prune:                'Prune jurisdiction',
}
const title = computed(() => TITLES[props.mode] || props.mode)

const submitLabel = computed(() => {
    if (props.mode === 'accept_flag') return 'Accept'
    if (props.mode === 'prune')       return cascade.value ? 'Prune subtree' : 'Prune'
    if (props.mode === 'merge_chain') return `Merge ${Math.max(chainSlugs.value.length - 1, 0)} into survivor`
    return 'Apply repair'
})

const canSubmit = computed(() => {
    switch (props.mode) {
        case 'accept_flag':
            return !!props.flag
        case 'reparent':
            return targetSlug.value !== '' && newParentSlug.value !== ''
        case 'synthesize_anchor':
            return parentSlug.value !== '' && anchorName.value !== '' && childSlugs.value.length > 0
        case 'merge_chain':
            return chainSlugs.value.length >= 2
        case 'recompute_population':
            return targetSlug.value !== '' && (method.value === 'children_sum' || method.value === 'raster_total')
        case 'prune':
            return targetSlug.value !== '' && confirmText.value === targetSlug.value
        default:
            return false
    }
})

async function submit() {
    if (busy.value || !canSubmit.value) return
    busy.value = true
    error.value = ''

    let url; let body
    const flagId = props.flag?.id ?? null
    switch (props.mode) {
        case 'accept_flag':
            url = `/api/geodata/flags/${flagId}/accept`
            body = { note: note.value || null }
            break
        case 'reparent':
            url = '/api/geodata/repairs/reparent'
            body = { target_slug: targetSlug.value, new_parent_slug: newParentSlug.value, note: note.value || null, flag_id: flagId }
            break
        case 'synthesize_anchor':
            url = '/api/geodata/repairs/synthesize-anchor'
            body = { parent_slug: parentSlug.value, name: anchorName.value, child_slugs: childSlugs.value, note: note.value || null, flag_id: flagId }
            break
        case 'merge_chain':
            url = '/api/geodata/repairs/merge-chain'
            body = { chain_slugs: chainSlugs.value, note: note.value || null, flag_id: flagId }
            break
        case 'recompute_population':
            url = '/api/geodata/repairs/recompute-population'
            body = { target_slug: targetSlug.value, method: method.value, note: note.value || null, flag_id: flagId }
            break
        case 'prune':
            url = '/api/geodata/repairs/prune'
            body = { target_slug: targetSlug.value, cascade: cascade.value, note: note.value || null, flag_id: flagId }
            break
        default:
            busy.value = false
            return
    }

    try {
        const res = await csrfFetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        })
        const data = await res.json().catch(() => ({}))
        if (!res.ok) {
            error.value = data.error || data.message || `Repair failed (HTTP ${res.status}).`
            return
        }
        emit('applied', data)
    } catch (e) {
        error.value = String(e?.message || e)
    } finally {
        busy.value = false
    }
}
</script>
