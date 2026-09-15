<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const props = defineProps({ person: { type: Object, required: true } });
const organization = computed(() => props.person.type === 'organizations');
const href = computed(() => organization.value ? `/organizations/${props.person.id}` : `/people?who=${encodeURIComponent(props.person.id)}`);

const { t } = useI18n();
</script>

<template>
    <div class="selection-identity">
        <strong>{{ person.name }}</strong>
        <span v-if="person.public_handle">{{ person.public_handle }}</span>
        <Link :href="href" :aria-label="organization ? t('c_ui_b.selection_identity.aria_org', { name: person.name, id: person.id }) : t('c_ui_b.selection_identity.aria_profile', { name: person.name, id: person.id })">
            {{ organization ? t('c_ui_b.selection_identity.organization_record', 'Organization record') : t('c_ui_b.selection_identity.public_profile', 'Public profile') }}
        </Link>
        <small class="selection-reference">{{ organization ? t('c_ui_b.selection_identity.org_reference', 'Organization reference:') : t('c_ui_b.selection_identity.profile_reference', 'Profile reference:') }} <code>{{ person.id }}</code></small>
    </div>
</template>

<style scoped>
.selection-identity { display: grid; gap: .2rem; min-inline-size: 0; overflow-wrap: anywhere; }
.selection-identity a { inline-size: fit-content; }
.selection-reference { color: var(--gov-fg-muted); }
</style>
