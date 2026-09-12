<script setup>
/**
 * Surface/PageScaffold — the per-screen wrapper (DESIGN_frontend_port.md §D2).
 *
 *   <PageScaffold :surface="surface">
 *       <template #intro>One-paragraph page intro.</template>
 *       …content cards…
 *   </PageScaffold>
 *
 * Renders, in order: <Head :title>, eyebrow (module label) + the page's ONE
 * <h1>, optional intro, the collapsed AboutSurface panel (workflows with
 * readable process and action names, citation — from `surface` + an optional #about
 * slot for prose), then the default slot.
 *
 * The surface prop may be omitted — it falls back to the injection AppShell
 * provides from the page's `surface` page prop, so pages that already pass
 * `'surface' => SurfaceMeta::for(...)` need no wiring. AppShell also reads
 * the same page prop for the footer citation and sidebar aria-current —
 * pages never wire those manually.
 */
import { computed, inject } from 'vue';
import { Head } from '@inertiajs/vue3';
import AboutSurface from '@/Components/Surface/AboutSurface.vue';
import FormChip from '@/Components/Ui/FormChip.vue';
import ReferenceText from '@/Components/Ui/ReferenceText.vue';
import { useI18n } from 'vue-i18n';
import { referenceLabel } from '@/lib/referenceLabels.js';

const props = defineProps({
    /** SurfaceMeta record; falls back to AppShell's provided page prop. */
    surface: { type: Object, default: null },
    /** Override the <h1>/<Head> when the page title is dynamic. */
    title: { type: String, default: null },
});

const injected = inject('cga:surface', null);
const { t } = useI18n();
const text = (key, fallback) => t('c_references.' + key, fallback);
const label = (code, name = null) => referenceLabel(code, { name, translate: (key, fallback) => t(key, fallback) });
const meta = computed(() => props.surface ?? injected?.value ?? null);

const pageTitle = computed(() => props.title ?? meta.value?.title ?? '');
const eyebrow = computed(() => meta.value?.module ?? null);
const hasAbout = computed(
    () =>
        meta.value &&
        ((meta.value.workflows?.length ?? 0) > 0 ||
            (meta.value.forms?.length ?? 0) > 0 ||
            meta.value.citation),
);
</script>

<template>
    <Head :title="pageTitle" />

    <div class="stack">
        <header>
            <span v-if="eyebrow" class="eyebrow">{{ eyebrow }}</span>
            <h1>{{ pageTitle }}</h1>
            <p v-if="$slots.intro" class="page-intro">
                <ReferenceText><slot name="intro" /></ReferenceText>
            </p>
        </header>

        <AboutSurface v-if="hasAbout" :citation="meta.citation">
            <ReferenceText><slot name="about" /></ReferenceText>
            <p v-if="meta.workflows?.length">
                {{ text('processes', 'Related processes') }}
                <span v-for="wf in meta.workflows" :key="wf" class="form-chip" style="margin-inline-end: var(--space-1)">
                    {{ label(wf) }}
                </span>
            </p>
            <p v-if="meta.forms?.length">
                {{ text('actions', 'Related forms') }}
                <FormChip
                    v-for="f in meta.forms"
                    :key="f.id"
                    :form-id="f.id"
                    :name="f.name"
                    :alias="f.alias"
                    style="margin-inline-end: var(--space-1)"
                />
            </p>
            <details class="surface-references">
                <summary>{{ text('reference_codes', 'Reference codes') }}</summary>
                <ul>
                    <li v-for="wf in meta.workflows || []" :key="wf">{{ label(wf) }} · <code>{{ wf }}</code></li>
                    <li v-for="form in meta.forms || []" :key="form.id">
                        {{ label(form.id, form.name) }} · <code>{{ form.id }}</code>
                        <template v-if="form.alias"> · {{ text('catalog_reference', 'Catalog reference') }} <code>{{ form.alias }}</code></template>
                    </li>
                    <li v-for="clock in meta.clocks || []" :key="clock">{{ label(clock) }} · <code>{{ clock }}</code></li>
                    <li v-for="role in meta.roles || []" :key="role">{{ label(role) }} · <code>{{ role }}</code></li>
                </ul>
            </details>
        </AboutSurface>

        <slot />
    </div>
</template>

<style scoped>
.surface-references { margin-top: var(--space-3); }
.surface-references summary { cursor: pointer; }
.surface-references ul { padding-inline-start: 1.25rem; }
.surface-references li { margin-block: .35rem; }
</style>
