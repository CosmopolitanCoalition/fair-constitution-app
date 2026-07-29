<script setup>
/**
 * ShellV2/LearnFlyout — the Learn drawer body (ported from mockups/v3
 * shell-v2.js hydrateLearnDrawer, app-shaped; K-2 payload wired 2026-07-28,
 * V3 synthesis Wave 1 slices L1/L2/L4). The constitutional "why" and deep
 * references live HERE, never in the plain player chrome:
 *
 *   • the authored per-screen education (registry/education.js, GENERATED
 *     from docs/plans/education/K2_CONTENT_*.md): the learn sentence, the
 *     how-to steps (do / detail / cite) in the mockups' sop idiom, and the
 *     "why" callout — all resolved through i18n (c_education.json), with
 *     cite tokens LITERAL in every language;
 *   • "where this fits" — the workflow context this screen takes part in
 *     (registry/flows.js, the 80 flow walkthroughs inverted per screen);
 *   • the surface's machinery — the constitutional forms in play and the
 *     citation (from the injected SurfaceMeta, config/cga/surfaces.php);
 *   • Report an issue → /support/report?ref=<surface id> (the Phase-1 intake);
 *   • Full lessons — Planned · Phase 7 (learn ships static then).
 *
 * Pages without an authored entry fall back to the module-level line
 * (LEARN_BY_MODULE), so the drawer is never empty on any screen.
 */
import { computed, inject } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Icon from '@/Components/Ui/Icon.vue';
import { LEARN_BY_MODULE } from '@/registry/surfaces.js';
import { EDUCATION_BY_SURFACE } from '@/registry/education.js';
import { FLOWS_BY_SURFACE } from '@/registry/flows.js';

const { t } = useI18n();
const page = usePage();
const surface = inject('cga:surface', computed(() => page.props.surface ?? null));

/* The authored K-2 payload for this surface, when it exists. */
const education = computed(() => {
    const id = surface.value?.id;
    return (id && EDUCATION_BY_SURFACE[id]) || null;
});

/* Flow participations ("where this fits"), primary first (minStep sort is
   baked into the generated registry). */
const flows = computed(() => {
    const id = surface.value?.id;
    return (id && FLOWS_BY_SURFACE[id]) || [];
});
const primaryFlow = computed(() => flows.value[0] ?? null);
const moreFlows = computed(() => flows.value.slice(1));

/* This screen's step position inside one flow: "3" or "2–4". */
function stepLabel(flow) {
    const ns = flow.steps.map((s) => s.n);
    const lo = Math.min(...ns), hi = Math.max(...ns);
    return lo === hi ? String(lo) : `${lo}–${hi}`;
}
const prevOf = (flow) => flow.steps[0]?.prev ?? null;
const nextOf = (flow) => flow.steps[flow.steps.length - 1]?.next ?? null;

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
        <!-- The learn sentence (authored) — or the module fallback line. -->
        <p v-if="education" class="ld-learn">{{ t(education.learn) }}</p>
        <p v-else class="gloss">{{ about }}</p>

        <!-- How to use this page — the mockups' sop idiom, verbatim classes. -->
        <section v-if="education && education.steps.length" class="sop">
            <span class="eyebrow"><Icon name="list-checks" size="sm" /> How to use this page</span>
            <ol class="sop-steps">
                <li v-for="(step, i) in education.steps" :key="i">
                    <span class="sop-do">{{ t(step.do) }}</span>
                    <span class="sop-detail">{{ t(step.detail) }}</span>
                    <span v-if="step.cite" class="citation">{{ step.cite }}</span>
                </li>
            </ol>
        </section>

        <!-- The constitutional why — the half that is never in the chrome. -->
        <aside v-if="education && education.why" class="ld-why">
            <Icon name="scale" size="sm" />
            <p><strong>The why:</strong> {{ t(education.why) }}</p>
        </aside>

        <!-- Where this fits — the flow(s) this screen takes part in. -->
        <section v-if="primaryFlow" class="ld-flow">
            <span class="ld-context-h"><Icon name="git-branch" size="sm" /> Where this fits</span>
            <div class="ld-flow-row">
                <span class="ld-flow-name">
                    {{ primaryFlow.wfName }}
                    <span class="ld-flow-fam">· {{ primaryFlow.familyLabel }}</span>
                </span>
                <span class="ld-flow-pos">Step {{ stepLabel(primaryFlow) }} of {{ primaryFlow.total }} · {{ primaryFlow.wf }}</span>
                <span v-if="prevOf(primaryFlow)" class="ld-flow-adj">← before this: {{ prevOf(primaryFlow) }}</span>
                <span v-if="nextOf(primaryFlow)" class="ld-flow-adj">→ after this: {{ nextOf(primaryFlow) }}</span>
            </div>
            <details v-if="moreFlows.length" class="ld-flow-more">
                <summary>Also part of {{ moreFlows.length }} other process{{ moreFlows.length === 1 ? '' : 'es' }}</summary>
                <div v-for="f in moreFlows" :key="f.wf" class="ld-flow-row">
                    <span class="ld-flow-name">{{ f.wfName }} <span class="ld-flow-fam">· {{ f.familyLabel }}</span></span>
                    <span class="ld-flow-pos">Step {{ stepLabel(f) }} of {{ f.total }} · {{ f.wf }}</span>
                </div>
            </details>
        </section>

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

<style scoped>
/* Scoped on purpose: these blocks belong to the Learn drawer alone, so their
   styling travels with the component instead of widening the shared shell
   CSS (components-v2.css already carries the global .sop/.ld-* idiom). */
.ld-learn {
    color: var(--gov-fg);
    margin: 0;
}
.ld-why {
    display: flex;
    gap: var(--space-2);
    align-items: flex-start;
    background: var(--gov-surface);
    border: 1px solid var(--gov-border);
    border-inline-start: 3px solid var(--cc-gold-300);
    border-radius: var(--radius-md);
    padding: var(--space-3);
    color: var(--gov-fg-muted);
    font-size: var(--text-sm);
}
.ld-why .icon { color: var(--cc-gold-300); flex-shrink: 0; margin-block-start: 2px; }
.ld-why p { margin: 0; }
.ld-why strong { color: var(--gov-fg); }
.ld-flow {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    border-block-start: 1px solid var(--gov-border);
    padding-block-start: var(--space-3);
}
.ld-flow-row { display: flex; flex-direction: column; gap: 2px; }
.ld-flow-name { color: var(--gov-fg); font-weight: var(--weight-semibold); font-size: var(--text-sm); }
.ld-flow-fam { color: var(--gov-fg-subtle); font-weight: normal; }
.ld-flow-pos { font-family: var(--font-mono); font-size: var(--text-xs); color: var(--gov-fg-muted); }
.ld-flow-adj { font-size: var(--text-xs); color: var(--gov-fg-subtle); }
.ld-flow-more summary {
    cursor: pointer;
    font-size: var(--text-xs);
    color: var(--gov-link);
    width: fit-content;
}
.ld-flow-more .ld-flow-row { margin-block-start: var(--space-2); }
</style>
