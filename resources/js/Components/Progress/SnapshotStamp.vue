<script setup>
import { useI18n } from 'vue-i18n'
import { useLocaleFormat } from '@/composables/useLocaleFormat'

defineProps({ snapshot: { type: Object, default: null } })
const { t } = useI18n()
const format = useLocaleFormat()
</script>

<template>
    <div v-if="snapshot" class="mt-1 text-xs text-gray-400" role="status">
        <template v-if="snapshot.snapshot_at">
            {{ t('c_setup.progress_snapshot.updated', { time: format.time(snapshot.snapshot_at) }) }}
            <span v-if="snapshot.snapshot_stale"> · {{ t('c_setup.progress_snapshot.refreshing') }}</span>
        </template>
        <span v-else>{{ t('c_setup.progress_snapshot.computing') }}</span>
    </div>
</template>
