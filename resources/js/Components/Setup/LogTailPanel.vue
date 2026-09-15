<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
    lines:        { type: Array,   default: () => [] },
    includeDebug: { type: Boolean, default: false },
})

const emit = defineEmits(['update:includeDebug'])

const text = computed(() => (props.lines || []).join('\n'))

function onToggle(e) {
    emit('update:includeDebug', !!e.target.checked)
}
</script>

<template>
    <div>
        <div class="flex items-center justify-between mb-2">
            <div class="text-gray-400 text-xs uppercase tracking-wider">{{ t('c_setup_components.log_tail_panel.log_tail', 'Log tail') }}</div>
            <label class="flex items-center gap-2 text-xs text-gray-400 cursor-pointer select-none">
                <input
                    type="checkbox"
                    :checked="includeDebug"
                    @change="onToggle"
                />
                {{ t('c_setup_components.log_tail_panel.show_debug', 'Show DEBUG') }}
            </label>
        </div>
        <div class="bg-black border border-gray-800 rounded p-3 max-h-80 overflow-y-auto">
            <div v-if="!lines || lines.length === 0" class="text-gray-400 text-xs">
                {{ t('c_setup_components.log_tail_panel.no_log_output', 'No log output yet.') }}
            </div>
            <pre
                v-else
                class="text-xs font-mono text-gray-300 whitespace-pre-wrap leading-relaxed"
            >{{ text }}</pre>
        </div>
    </div>
</template>
