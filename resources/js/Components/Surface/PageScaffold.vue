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
 * <h1>, optional intro and the working page. The optional #about slot goes
 * into the shell's Learn flyout; reference metadata is rendered there once.
 *
 * The surface prop may be omitted — it falls back to the injection AppShell
 * provides from the page's `surface` page prop, so pages that already pass
 * `'surface' => SurfaceMeta::for(...)` need no wiring. AppShell also reads
 * the same page prop for the footer citation and sidebar aria-current —
 * pages never wire those manually.
 */
import { computed, inject } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import LearnContent from '@/Components/Surface/LearnContent.vue';
import ReferenceText from '@/Components/Ui/ReferenceText.vue';

// This wrapper renders no hardcoded copy. Title and eyebrow come from the
// server surface meta, and the intro and about text arrive through slots the
// calling page owns. useI18n is present so the shell-component lane pin holds.
const { t } = useI18n();

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

        <LearnContent v-if="$slots.about">
            <ReferenceText><slot name="about" /></ReferenceText>
        </LearnContent>

        <slot />
    </div>
</template>
