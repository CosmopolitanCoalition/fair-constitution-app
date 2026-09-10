<script setup>
/**
 * Shell/JurisdictionRail — the general jurisdiction tools for the place on
 * screen (operator 2026-09-10: "the side bar like the mapper to have general
 * jurisdiction tools"). Modelled on the mapper sidebar's header: the place's
 * identity, then every tool in a fixed order. A tool whose institution exists
 * is a link; one that does not is a muted row with a plain-words reason,
 * never hidden (complete lists). The server builds the list once
 * (App\Support\JurisdictionContext::tools) from the gates the place page and
 * the map viewer already resolve, so there is one source.
 *
 * Page-level: rendered inside .main-content by the pages that carry a place,
 * never by the shell grid (a phantom grid column once left-pinned the app —
 * components-v2.css notes it).
 */
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Icon from '@/Components/Ui/Icon.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

const props = defineProps({
    /** { name, kind, population, activation: { label, tone } | null } */
    place: { type: Object, required: true },
    /** [{ key, group, label, href, state: 'link' | 'muted' | 'current', hint, icon }] */
    tools: { type: Array, default: () => [] },
});

const page = usePage();
const path = computed(() => String(page.url ?? '/').split('?')[0]);

const groups = computed(() => {
    const order = ['This place', 'Its government', 'Take part'];
    const by = {};
    for (const t of props.tools) (by[t.group] ??= []).push(t);
    return order.filter((g) => by[g]).map((g) => ({ name: g, items: by[g] }));
});

const isCurrent = (t) => t.href && (path.value === t.href || (t.href !== '/' && path.value.startsWith(`${t.href}/`)));
const people = computed(() => (Number(props.place.population ?? 0) > 0 ? Number(props.place.population).toLocaleString() : null));
</script>

<template>
    <nav class="jur-rail" aria-label="Jurisdiction tools">
        <header class="jur-rail__head">
            <span class="eyebrow">{{ place.kind }}</span>
            <h2 class="jur-rail__name">{{ place.name }}</h2>
            <p v-if="people" class="gloss">About {{ people }} people</p>
            <StatusBadge v-if="place.activation" :tone="place.activation.tone">{{ place.activation.label }}</StatusBadge>
        </header>

        <section v-for="g in groups" :key="g.name" class="jur-rail__group">
            <h3 class="eyebrow">{{ g.name }}</h3>
            <ul>
                <li v-for="t in g.items" :key="t.key">
                    <Link
                        v-if="t.state !== 'muted' && t.href"
                        :href="t.href"
                        class="jur-rail__link"
                        :aria-current="isCurrent(t) ? 'page' : undefined"
                    >
                        <Icon v-if="t.icon" :name="t.icon" size="sm" />
                        <span class="jur-rail__label">{{ t.label }}</span>
                        <span v-if="t.hint" class="jur-rail__hint">{{ t.hint }}</span>
                    </Link>
                    <span v-else class="jur-rail__link jur-rail__link--muted" :title="t.hint || undefined">
                        <Icon v-if="t.icon" :name="t.icon" size="sm" />
                        <span class="jur-rail__label">{{ t.label }}</span>
                        <span v-if="t.hint" class="jur-rail__hint">{{ t.hint }}</span>
                    </span>
                </li>
            </ul>
        </section>
    </nav>
</template>

<style scoped>
.jur-rail {
    position: sticky;
    inset-block-start: calc(var(--space-8) + 3.5rem);
    align-self: start;
    display: grid;
    gap: var(--space-4);
    padding: var(--space-4);
    background: var(--gov-surface);
    border: 1px solid var(--gov-border);
    border-radius: var(--radius-lg, 0.75rem);
}
.jur-rail__head { display: grid; gap: var(--space-1); }
.jur-rail__name { margin: 0; font-size: var(--text-lg); line-height: 1.2; }
.jur-rail__group { display: grid; gap: var(--space-1); }
.jur-rail__group ul { list-style: none; margin: 0; padding: 0; display: grid; gap: 2px; }
.jur-rail__link {
    display: grid;
    grid-template-columns: 1rem minmax(0, 1fr);
    column-gap: var(--space-2);
    align-items: center;
    padding: var(--space-1) var(--space-2);
    border-radius: var(--radius-md, 0.5rem);
    color: inherit;
    text-decoration: none;
}
.jur-rail__link:hover { background: var(--gov-surface-raised, rgba(255, 255, 255, 0.05)); }
.jur-rail__link[aria-current='page'] { background: var(--gov-primary-soft, rgba(124, 58, 237, 0.18)); font-weight: 600; }
.jur-rail__link--muted { color: var(--gov-fg-subtle); cursor: default; }
.jur-rail__hint { grid-column: 2; font-size: var(--text-xs); color: var(--gov-fg-muted); }
.jur-rail__label { min-inline-size: 0; }
@media (max-width: 63.99rem) {
    .jur-rail { position: static; }
}
</style>
