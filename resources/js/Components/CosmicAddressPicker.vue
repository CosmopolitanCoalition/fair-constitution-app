<script setup>
import { onMounted, ref, watch } from 'vue'

const props = defineProps({
    modelValue: { type: String, default: null },
})
const emit = defineEmits(['update:modelValue', 'pathChange'])

const LEVEL_LABELS = {
    observable_universe: 'Universe',
    supercluster: 'Supercluster',
    galaxy_group: 'Galaxy Group',
    galaxy: 'Galaxy',
    galactic_region: 'Galactic Region',
    star_system: 'Star System',
    world: 'World',
}

const LEVEL_ORDER = [
    'observable_universe',
    'supercluster',
    'galaxy_group',
    'galaxy',
    'galactic_region',
    'star_system',
    'world',
]

const levels = ref([])
const loadingPath = ref(true)
const error = ref(null)

function labelFor(row) {
    if (row.type === 'world' && row.subtype) {
        return `${row.label} (${row.subtype})`
    }
    return row.label
}

async function fetchChildren(parentId) {
    const res = await fetch(`/api/cosmic-addresses/${parentId}/children`, {
        headers: { 'Accept': 'application/json' },
    })
    if (!res.ok) throw new Error(`children fetch failed (${res.status})`)
    const data = await res.json()
    return data.children ?? []
}

async function loadDefaultPath() {
    loadingPath.value = true
    error.value = null
    try {
        const res = await fetch('/api/cosmic-addresses/default-path', {
            headers: { 'Accept': 'application/json' },
        })
        if (!res.ok) throw new Error(`default-path failed (${res.status})`)
        const data = await res.json()
        const path = data.path ?? []

        const builtLevels = []
        for (let i = 0; i < path.length; i++) {
            const node = path[i]
            const parentId = i === 0 ? null : path[i - 1].id
            const options = parentId
                ? await fetchChildren(parentId)
                : await fetchObservableRoots()
            builtLevels.push({
                type: node.type,
                label: LEVEL_LABELS[node.type] ?? node.type,
                options,
                selectedId: node.id,
            })
        }
        levels.value = builtLevels
        emitSelection()
    } catch (e) {
        error.value = e.message || 'Failed to load cosmic address'
    } finally {
        loadingPath.value = false
    }
}

async function fetchObservableRoots() {
    // The first selectable level is the Universe — children of the implicit Multiverse root.
    const res = await fetch('/api/cosmic-addresses/default-path', {
        headers: { 'Accept': 'application/json' },
    })
    const data = await res.json()
    const firstNode = (data.path ?? [])[0]
    if (!firstNode || !firstNode.parent_id) return firstNode ? [firstNode] : []
    return await fetchChildren(firstNode.parent_id)
}

async function onLevelChange(levelIndex, newId) {
    levels.value[levelIndex].selectedId = newId
    // Reset deeper levels — load each level's options by walking down the new branch.
    const truncated = levels.value.slice(0, levelIndex + 1)
    let cursorId = newId
    for (let i = levelIndex + 1; i < LEVEL_ORDER.length; i++) {
        const childType = LEVEL_ORDER[i]
        const options = await fetchChildren(cursorId)
        if (!options.length) break
        const firstEnabled = options.find(o => o.enabled) ?? options[0]
        truncated.push({
            type: childType,
            label: LEVEL_LABELS[childType] ?? childType,
            options,
            selectedId: firstEnabled.id,
        })
        cursorId = firstEnabled.id
    }
    levels.value = truncated
    emitSelection()
}

function emitSelection() {
    const leaf = levels.value[levels.value.length - 1]
    if (!leaf) return
    emit('update:modelValue', leaf.selectedId)
    emit('pathChange', levels.value.map(l => ({
        type: l.type,
        label: l.label,
        selectedId: l.selectedId,
        selectedLabel: labelFor(l.options.find(o => o.id === l.selectedId) ?? {}),
    })))
}

onMounted(loadDefaultPath)

watch(() => props.modelValue, (v) => {
    // Allow parent to externally override — ignored if it matches current leaf.
    const leaf = levels.value[levels.value.length - 1]
    if (leaf && leaf.selectedId !== v && v) {
        // External override is not a v1 feature — log and ignore.
        // eslint-disable-next-line no-console
        console.warn('CosmicAddressPicker: external modelValue overrides are not supported in v1')
    }
})
</script>

<template>
    <div class="space-y-3">
        <div class="text-xs text-gray-400 pl-1">Multiverse ▸</div>

        <div v-if="loadingPath" class="text-sm text-gray-400">Loading cosmic address…</div>
        <div v-else-if="error" class="text-sm text-red-400">{{ error }}</div>

        <div v-else class="space-y-2">
            <div
                v-for="(lvl, i) in levels"
                :key="lvl.type"
                class="flex items-center gap-3"
            >
                <label
                    class="text-xs uppercase tracking-wide text-gray-400 w-32 shrink-0"
                    :for="`cosmic-${lvl.type}`"
                >{{ lvl.label }}</label>
                <select
                    :id="`cosmic-${lvl.type}`"
                    :value="lvl.selectedId"
                    @change="onLevelChange(i, $event.target.value)"
                    class="flex-1 bg-gray-900 border border-gray-700 rounded-md px-3 py-2 text-sm text-gray-100 focus:border-blue-500 focus:outline-none"
                >
                    <option
                        v-for="opt in lvl.options"
                        :key="opt.id"
                        :value="opt.id"
                        :disabled="!opt.enabled"
                    >
                        {{ labelFor(opt) }}{{ opt.enabled ? '' : ' — coming soon' }}
                    </option>
                </select>
            </div>
        </div>
    </div>
</template>
