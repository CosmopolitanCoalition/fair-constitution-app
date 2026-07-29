<script setup>
/**
 * System/Coverage — the registry-vs-app coverage dashboard (design contract:
 * mockups/v3/shared/coverage.html).
 *
 * "For the build team." The app's ground truth (the routes it serves + every
 * registered surface) arrives as props; the JS nav registry is imported here;
 * registry/coverage.js cross-checks the two. The headline is GREEN only when
 * every wired registry href and every tour stop resolves to a real route AND
 * every surface `nav` names a real menu id. It goes RED the moment they drift
 * — an instrument that cannot fail measures nothing.
 *
 * Public read (build-team info only — surface names + route paths, no user
 * data), so it is reachable at review time and from the "For the build team"
 * menu.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Icon from '@/Components/Ui/Icon.vue';
import { PLAYER_NAV, SITEMAP, TOUR } from '@/registry/surfaces.js';
import { flattenRegistryNav, computeCoverageDrift } from '@/registry/coverage.js';

defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    routes: { type: Array, default: () => [] },
    surfaces: { type: Array, default: () => [] },
});

const drift = computed(() =>
    computeCoverageDrift({
        surfaces: props.surfaces,
        routes: props.routes,
        nav: flattenRegistryNav(PLAYER_NAV, SITEMAP),
        tour: TOUR,
    }),
);
const c = computed(() => drift.value.counts);
</script>

<template>
    <PageScaffold :surface="surface">
        <template #intro>
            Built live from the routes the app serves and the surfaces it registers. The three
            checks below must be green; anything red is real drift between the nav registry and the
            running app — fix the registry or the route, never this page.
        </template>

        <p class="citation">
            Companion instrument: <Link href="/coverage-ops">the coverage matrix</Link> — every
            surface and every registry row, row by row.
        </p>

        <!-- ───────────────────────────────────────────── the verdict ── -->
        <Banner
            v-if="!drift.ok"
            tone="emergency"
            title="Drift detected — the registry and the app disagree"
        >
            {{ drift.deadNavLinks.length }} dead nav link(s),
            {{ drift.deadTourStops.length }} dead tour stop(s),
            {{ drift.navUnresolved.length }} unresolved surface nav(s). Details below.
        </Banner>
        <Banner v-else tone="info" title="All clear">
            Every wired registry href and tour stop resolves to a route the app serves, and every
            surface nav names a real menu id.
        </Banner>

        <!-- ─────────────────────────────────────────────── the stats ── -->
        <div class="grid-2" style="grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr))">
            <Card><Stat :value="c.routes" label="static GET routes the app serves" /></Card>
            <Card><Stat :value="c.surfaces" label="registered surfaces (SurfaceMeta)" /></Card>
            <Card><Stat :value="`${c.navResolved} / ${c.surfacesWithNav}`" label="surface navs that resolve to a menu id" /></Card>
            <Card><Stat :value="c.navWired" label="registry rows wired to a route" /></Card>
            <Card><Stat :value="c.navPlanned" label="registry rows still Planned (no route)" /></Card>
            <Card><Stat :value="`${c.tourStops} / 117`" label="guided-tour stops (toward the contract)" /></Card>
        </div>

        <!-- ─────────────────────────────────── 1 · dead nav links ── -->
        <Card as="section">
            <template #title>
                <h2>
                    Dead nav links
                    <StatusBadge
                        :tone="drift.deadNavLinks.length ? 'danger' : 'success'"
                        :icon="drift.deadNavLinks.length ? 'alert-triangle' : 'check'"
                    >
                        {{ drift.deadNavLinks.length ? `${drift.deadNavLinks.length} dead` : 'all resolve' }}
                    </StatusBadge>
                </h2>
            </template>
            <p class="cc-small">A registry row wired to a route the app does not serve — a dead link in the menu.</p>
            <ul v-if="drift.deadNavLinks.length" class="stack" style="gap: var(--space-1)">
                <li v-for="d in drift.deadNavLinks" :key="d.id" class="mono">
                    <Icon name="alert-triangle" size="sm" /> {{ d.id }} → <strong>{{ d.href }}</strong> <span class="citation">({{ d.section }})</span>
                </li>
            </ul>
            <p v-else class="citation"><Icon name="check" size="sm" /> Every wired href matches a served route.</p>
        </Card>

        <!-- ─────────────────────────────────── 2 · dead tour stops ── -->
        <Card as="section">
            <template #title>
                <h2>
                    Dead tour stops
                    <StatusBadge
                        :tone="drift.deadTourStops.length ? 'danger' : 'success'"
                        :icon="drift.deadTourStops.length ? 'alert-triangle' : 'check'"
                    >
                        {{ drift.deadTourStops.length ? `${drift.deadTourStops.length} dead` : 'all resolve' }}
                    </StatusBadge>
                </h2>
            </template>
            <p class="cc-small">A guided-tour stop pointing at a route the app does not serve — the tour would land nowhere.</p>
            <ul v-if="drift.deadTourStops.length" class="stack" style="gap: var(--space-1)">
                <li v-for="d in drift.deadTourStops" :key="d.href" class="mono">
                    <Icon name="alert-triangle" size="sm" /> {{ d.title }} → <strong>{{ d.href }}</strong>
                </li>
            </ul>
            <p v-else class="citation"><Icon name="check" size="sm" /> All {{ c.tourStops }} stops land on a served route.</p>
        </Card>

        <!-- ─────────────────────────── 3 · surface nav cross-check ── -->
        <Card as="section">
            <template #title>
                <h2>
                    Surface nav cross-check
                    <StatusBadge
                        :tone="drift.navUnresolved.length ? 'danger' : 'success'"
                        :icon="drift.navUnresolved.length ? 'alert-triangle' : 'check'"
                    >
                        {{ drift.navUnresolved.length ? `${drift.navUnresolved.length} unresolved` : 'all resolve' }}
                    </StatusBadge>
                </h2>
            </template>
            <p class="cc-small">
                Every registered surface's active-nav id must name a row in the menu registry
                (SurfaceMeta::ids() ↔ the JS registry); if it doesn't, the surface highlights nothing.
            </p>
            <ul v-if="drift.navUnresolved.length" class="stack" style="gap: var(--space-1)">
                <li v-for="d in drift.navUnresolved" :key="d.id" class="mono">
                    <Icon name="alert-triangle" size="sm" /> {{ d.id }} <span class="citation">nav=</span><strong>{{ d.nav }}</strong> <span class="citation">— no menu id answers it</span>
                </li>
            </ul>
            <p v-else class="citation"><Icon name="check" size="sm" /> Every surface nav resolves to a menu id.</p>

            <!-- known drift, deferred to the owning lane — recorded, not counted -->
            <div v-if="drift.navAllowlisted.length" style="margin-block-start: var(--space-3)">
                <p class="cc-small">
                    <strong>Known drift — deferred ({{ drift.navAllowlisted.length }}).</strong>
                    Recorded, not counted against the verdict; the owning lane resolves it.
                </p>
                <ul class="stack" style="gap: var(--space-1)">
                    <li v-for="d in drift.navAllowlisted" :key="d.id" class="mono citation">
                        {{ d.id }} nav=<strong>{{ d.nav }}</strong> — {{ d.note }}
                    </li>
                </ul>
            </div>
        </Card>

        <!-- ─────────────────────────── tour runway (informational) ── -->
        <Card as="section" title="Tour runway — reachable surfaces not yet a stop">
            <p class="cc-small">
                Player-reachable, wired surfaces (no role gate, no sandbox) that no tour stop covers
                yet — the runway toward the 117-stop contract. Informational, not drift.
            </p>
            <p v-if="!drift.tourGap.length" class="citation"><Icon name="check" size="sm" /> Every reachable surface is on the tour.</p>
            <ul v-else class="cluster" style="gap: var(--space-2); flex-wrap: wrap">
                <li v-for="g in drift.tourGap" :key="g.id" class="mono citation">{{ g.href }}</li>
            </ul>
        </Card>
    </PageScaffold>
</template>
