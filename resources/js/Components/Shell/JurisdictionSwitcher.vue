<script setup>
/**
 * Shell/JurisdictionSwitcher — cosmic prefix + ancestor AdmChip chain in a
 * keyboard-native <details> popover. PURE presentational: the panel lists
 * the provided chain only; sibling/children lookups are search-driven and
 * arrive via the default slot (951k rows — never enumerate).
 *
 * Emits `switch(jurisdictionId)` when a chain entry is chosen.
 */
import { useI18n } from 'vue-i18n';
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import AdmChip from '@/Components/Ui/AdmChip.vue';
import Icon from '@/Components/Ui/Icon.vue';

/* Natural level labels — numeric adm levels never display in product UI. */
const ADM_LABELS = ['Planet', 'Country', 'State / Province', 'County', 'Municipality', 'Township', 'Neighborhood'];

defineProps({
    /** { id, name, admLevel, slug } */
    current: { type: Object, required: true },
    /** Ancestor chain, root → current: [{ id, name, admLevel, slug }] */
    chain: { type: Array, default: () => [] },
    /** e.g. '… · Solar System · Earth' */
    cosmicPrefix: { type: String, default: '' },
});

const emit = defineEmits(['switch']);
const popover = ref(null);
const close = () => { if (popover.value) popover.value.open = false; };
const choose = id => { close(); emit('switch', id); };

const { t } = useI18n({ useScope: 'global' });

const admLabel = (level) => ADM_LABELS[Math.min(level, 6)];
</script>

<template>
    <details ref="popover" class="popover jur-switcher">
        <summary :aria-label="t('header.jurisdiction')">
            <span v-if="cosmicPrefix" class="cosmic-prefix">{{ cosmicPrefix }}</span>
            <template v-for="(jur, i) in chain" :key="jur.id">
                <span v-if="i > 0" class="adm-sep" aria-hidden="true">›</span>
                <AdmChip :level="jur.admLevel" :label="jur.name" />
            </template>
            <Icon name="chevron-down" size="sm" />
        </summary>
        <div class="popover-panel">
            <span class="eyebrow">{{ t('header.jurisdiction') }}</span>
            <p v-if="cosmicPrefix" class="cosmic-prefix">{{ cosmicPrefix }}</p>
            <ul style="list-style: none; padding: 0; margin: 0">
                <li v-for="jur in chain" :key="jur.id">
                    <button
                        type="button"
                        class="btn btn--ghost btn--sm"
                        style="inline-size: 100%; justify-content: flex-start"
                        :aria-current="jur.id === current.id ? 'true' : undefined"
                        @click="choose(jur.id)"
                    >
                        <AdmChip :level="jur.admLevel" dot-only />
                        {{ jur.name }}
                        <span class="citation">{{ admLabel(jur.admLevel) }}</span>
                    </button>
                </li>
            </ul>
            <div class="jur-switcher__browse">
                <Link v-if="current.slug" :href="`/jurisdictions?parent=${encodeURIComponent(current.slug)}`" class="btn btn--secondary btn--sm" @click="close">{{ t('places.places_inside') }}</Link>
                <Link href="/jurisdictions" class="btn btn--ghost btn--sm" @click="close">{{ t('places.browse_world') }}</Link>
            </div>
            <!-- Search-driven sibling/children lookup mounts here (layout WI). -->
            <slot />
        </div>
    </details>
</template>

<style scoped>
.jur-switcher__browse { display: flex; flex-wrap: wrap; gap: var(--space-2); border-block-start: 1px solid var(--gov-border); margin-block-start: var(--space-2); padding-block-start: var(--space-3); }
</style>
