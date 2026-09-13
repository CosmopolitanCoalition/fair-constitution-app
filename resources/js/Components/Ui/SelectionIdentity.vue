<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({ person: { type: Object, required: true } });
const organization = computed(() => props.person.type === 'organizations');
const href = computed(() => organization.value ? `/organizations/${props.person.id}` : `/people?who=${encodeURIComponent(props.person.id)}`);
</script>

<template>
    <div class="selection-identity">
        <strong>{{ person.name }}</strong>
        <span v-if="person.public_handle">{{ person.public_handle }}</span>
        <Link :href="href" :aria-label="`${organization ? 'Organization record' : 'Public profile'} for ${person.name}, reference ${person.id}`">
            {{ organization ? 'Organization record' : 'Public profile' }}
        </Link>
        <small class="selection-reference">{{ organization ? 'Organization' : 'Profile' }} reference: <code>{{ person.id }}</code></small>
    </div>
</template>

<style scoped>
.selection-identity { display: grid; gap: .2rem; min-inline-size: 0; overflow-wrap: anywhere; }
.selection-identity a { inline-size: fit-content; }
.selection-reference { color: var(--gov-fg-muted); }
</style>
