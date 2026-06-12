<script setup>
/**
 * Ui/OrgChip — organization chip: name + mono organization_type
 * (political_party | business | nonprofit | common_good_corp | informal).
 */
defineProps({
    name: { type: String, required: true },
    orgType: { type: String, default: null },
});
</script>

<template>
    <span class="org-chip">
        {{ name }}
        <span v-if="orgType" class="org-type">{{ orgType }}</span>
    </span>
</template>
