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
 * WF-ids, canonical forms, citation — from `surface` + an optional #about
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

const props = defineProps({
    /** SurfaceMeta record; falls back to AppShell's provided page prop. */
    surface: { type: Object, default: null },
    /** Override the <h1>/<Head> when the page title is dynamic. */
    title: { type: String, default: null },
});

const injected = inject('cga:surface', null);
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
                <slot name="intro" />
            </p>
        </header>

        <AboutSurface v-if="hasAbout" :citation="meta.citation">
            <slot name="about" />
            <p v-if="meta.workflows?.length">
                Workflows:
                <span v-for="wf in meta.workflows" :key="wf" class="form-chip" style="margin-inline-end: var(--space-1)">
                    <span class="form-id">{{ wf }}</span>
                </span>
            </p>
            <p v-if="meta.forms?.length">
                Canonical forms:
                <FormChip
                    v-for="f in meta.forms"
                    :key="f.id"
                    :form-id="f.id"
                    :name="f.name"
                    :alias="f.alias"
                    style="margin-inline-end: var(--space-1)"
                />
            </p>
        </AboutSurface>

        <slot />
    </div>
</template>
