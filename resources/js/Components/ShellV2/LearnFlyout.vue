<script setup>
/**
 * ShellV2/LearnFlyout — the Learn drawer body (ported from mockups/v3
 * shell-v2.js hydrateLearnDrawer, app-shaped). The constitutional "why" and
 * deep references live HERE, never in the plain player chrome:
 *
 *   • a plain-language "what this screen is about" line (registry text,
 *     keyed by surface id then module);
 *   • the surface's machinery — the constitutional forms in play and the
 *     citation (from the injected SurfaceMeta, config/cga/surfaces.php);
 *   • Report an issue → /support/report?ref=<surface id> (the Phase-1 intake);
 *   • Full lessons — Planned · Phase 7 (learn ships static then).
 */
import { computed, inject } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Icon from '@/Components/Ui/Icon.vue';
import { LEARN_BY_MODULE, LEARN_BY_SURFACE } from '@/registry/surfaces.js';

const page = usePage();
const surface = inject('cga:surface', computed(() => page.props.surface ?? null));

/* Pages without a SurfaceMeta entry (e.g. the support intake) fall back to
   the URL's first segment, mapped onto the registry's module vocabulary. */
const URL_MODULE = {
    civic: 'civic', elections: 'electoral', legislature: 'legislature',
    executive: 'executive', judiciary: 'judiciary', organizations: 'organizations',
    jurisdictions: 'jurisdictions', legislatures: 'jurisdictions', system: 'system',
    federation: 'federation', operator: 'operator', support: 'support',
};
const about = computed(() => {
    const s = surface.value;
    const urlModule = URL_MODULE[String(page.url ?? '/').split('?')[0].split('/')[1] ?? ''];
    return (
        (s?.id && LEARN_BY_SURFACE[s.id]) ||
        (s?.module && LEARN_BY_MODULE[s.module]) ||
        (urlModule && LEARN_BY_MODULE[urlModule]) ||
        'A quick guide to this screen.'
    );
});

const forms = computed(() => surface.value?.forms ?? []);
const citation = computed(() => surface.value?.citation ?? null);

const reportHref = computed(() => {
    const ref = surface.value?.id || String(page.url ?? '/').split('?')[0];
    return '/support/report?ref=' + encodeURIComponent(ref);
});
</script>

<template>
    <div class="ld-body">
        <p class="gloss">{{ about }}</p>

        <div v-if="forms.length || citation" class="ld-context">
            <span class="ld-context-h"><Icon name="scale" size="sm" /> The machinery behind this screen</span>
            <p v-if="forms.length" class="cluster" style="gap: var(--space-1)">
                <span v-for="f in forms" :key="f.id ?? f" class="form-chip">{{ f.name ?? f.id ?? f }}</span>
            </p>
            <p v-if="citation" class="citation">{{ citation }}</p>
        </div>

        <div class="cluster" style="gap: var(--space-1)">
            <span class="form-chip" aria-disabled="true" style="opacity: 0.65">
                <Icon name="graduation-cap" size="sm" /> Full lessons
                <span class="planned-flag">Planned · Phase 7</span>
            </span>
            <Link class="form-chip form-chip--report" :href="reportHref">
                <Icon name="flag" size="sm" /> Report an issue
            </Link>
        </div>
    </div>
</template>
