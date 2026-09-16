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
import { useI18n } from 'vue-i18n';
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

const { t } = useI18n();

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
            {{ t('c_system.coverage.intro', 'Built live from the routes the app serves and the surfaces it registers. The three checks below must be green; anything red is real drift between the nav registry and the running app — fix the registry or the route, never this page.') }}
        </template>

        <p class="citation">
            {{ t('c_system.coverage.companion', 'Companion instrument:') }} <Link href="/coverage-ops">{{ t('c_system.coverage.matrix_link', 'the coverage matrix') }}</Link> {{ t('c_system.coverage.companion_after', '— every surface and every registry row, row by row.') }}
        </p>

        <!-- ───────────────────────────────────────────── the verdict ── -->
        <Banner
            v-if="!drift.ok"
            tone="emergency"
            :title="t('c_system.coverage.drift_title', 'Drift detected — the registry and the app disagree')"
        >
            {{ t('c_system.coverage.drift_body', '{dead} dead nav link(s), {tour} dead tour stop(s), {nav} unresolved surface nav(s). Details below.', { named: { dead: drift.deadNavLinks.length, tour: drift.deadTourStops.length, nav: drift.navUnresolved.length } }) }}
        </Banner>
        <Banner v-else tone="info" :title="t('c_system.coverage.all_clear_title', 'All clear')">
            {{ t('c_system.coverage.all_clear_body', 'Every wired registry href and tour stop resolves to a route the app serves, and every surface nav names a real menu id.') }}
        </Banner>

        <!-- ─────────────────────────────────────────────── the stats ── -->
        <div class="grid-2" style="grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr))">
            <Card><Stat :value="c.routes" :label="t('c_system.coverage.stat_routes', 'static GET routes the app serves')" /></Card>
            <Card><Stat :value="c.surfaces" :label="t('c_system.coverage.stat_surfaces', 'registered surfaces (SurfaceMeta)')" /></Card>
            <Card><Stat :value="`${c.navResolved} / ${c.surfacesWithNav}`" :label="t('c_system.coverage.stat_navs_resolve', 'surface navs that resolve to a menu id')" /></Card>
            <Card><Stat :value="c.navWired" :label="t('c_system.coverage.stat_wired', 'registry rows wired to a route')" /></Card>
            <Card><Stat :value="c.navPlanned" :label="t('c_system.coverage.stat_planned', 'registry rows still Planned (no route)')" /></Card>
            <Card><Stat :value="`${c.tourStops} / 117`" :label="t('c_system.coverage.stat_tour', 'guided-tour stops (toward the contract)')" /></Card>
        </div>

        <!-- ─────────────────────────────────── 1 · dead nav links ── -->
        <Card as="section">
            <template #title>
                <h2>
                    {{ t('c_system.coverage.dead_nav_title', 'Dead nav links') }}
                    <StatusBadge
                        :tone="drift.deadNavLinks.length ? 'danger' : 'success'"
                        :icon="drift.deadNavLinks.length ? 'alert-triangle' : 'check'"
                    >
                        {{ drift.deadNavLinks.length ? t('c_system.coverage.n_dead', '{n} dead', { named: { n: drift.deadNavLinks.length } }) : t('c_system.coverage.all_resolve', 'all resolve') }}
                    </StatusBadge>
                </h2>
            </template>
            <p class="cc-small">{{ t('c_system.coverage.dead_nav_help', 'A registry row wired to a route the app does not serve — a dead link in the menu.') }}</p>
            <ul v-if="drift.deadNavLinks.length" class="stack" style="gap: var(--space-1)">
                <li v-for="d in drift.deadNavLinks" :key="d.id" class="mono">
                    <Icon name="alert-triangle" size="sm" /> {{ d.id }} → <strong>{{ d.href }}</strong> <span class="citation">({{ d.section }})</span>
                </li>
            </ul>
            <p v-else class="citation"><Icon name="check" size="sm" /> {{ t('c_system.coverage.dead_nav_ok', 'Every wired href matches a served route.') }}</p>
        </Card>

        <!-- ─────────────────────────────────── 2 · dead tour stops ── -->
        <Card as="section">
            <template #title>
                <h2>
                    {{ t('c_system.coverage.dead_tour_title', 'Dead tour stops') }}
                    <StatusBadge
                        :tone="drift.deadTourStops.length ? 'danger' : 'success'"
                        :icon="drift.deadTourStops.length ? 'alert-triangle' : 'check'"
                    >
                        {{ drift.deadTourStops.length ? t('c_system.coverage.n_dead', '{n} dead', { named: { n: drift.deadTourStops.length } }) : t('c_system.coverage.all_resolve', 'all resolve') }}
                    </StatusBadge>
                </h2>
            </template>
            <p class="cc-small">{{ t('c_system.coverage.dead_tour_help', 'A guided-tour stop pointing at a route the app does not serve — the tour would land nowhere.') }}</p>
            <ul v-if="drift.deadTourStops.length" class="stack" style="gap: var(--space-1)">
                <li v-for="d in drift.deadTourStops" :key="d.href" class="mono">
                    <Icon name="alert-triangle" size="sm" /> {{ d.title }} → <strong>{{ d.href }}</strong>
                </li>
            </ul>
            <p v-else class="citation"><Icon name="check" size="sm" /> {{ t('c_system.coverage.tour_ok', 'All {n} stops land on a served route.', { named: { n: c.tourStops } }) }}</p>
        </Card>

        <!-- ─────────────────────────── 3 · surface nav cross-check ── -->
        <Card as="section">
            <template #title>
                <h2>
                    {{ t('c_system.coverage.crosscheck_title', 'Surface nav cross-check') }}
                    <StatusBadge
                        :tone="drift.navUnresolved.length ? 'danger' : 'success'"
                        :icon="drift.navUnresolved.length ? 'alert-triangle' : 'check'"
                    >
                        {{ drift.navUnresolved.length ? t('c_system.coverage.n_unresolved', '{n} unresolved', { named: { n: drift.navUnresolved.length } }) : t('c_system.coverage.all_resolve', 'all resolve') }}
                    </StatusBadge>
                </h2>
            </template>
            <p class="cc-small">
                {{ t('c_system.coverage.crosscheck_help', 'Every registered surface\'s active-nav id must name a row in the menu registry (SurfaceMeta::ids() ↔ the JS registry); if it doesn\'t, the surface highlights nothing.') }}
            </p>
            <ul v-if="drift.navUnresolved.length" class="stack" style="gap: var(--space-1)">
                <li v-for="d in drift.navUnresolved" :key="d.id" class="mono">
                    <Icon name="alert-triangle" size="sm" /> {{ d.id }} <span class="citation" data-no-i18n>nav=</span><strong>{{ d.nav }}</strong> <span class="citation">{{ t('c_system.coverage.no_menu_id', '— no menu id answers it') }}</span>
                </li>
            </ul>
            <p v-else class="citation"><Icon name="check" size="sm" /> {{ t('c_system.coverage.crosscheck_ok', 'Every surface nav resolves to a menu id.') }}</p>

            <!-- known drift, deferred to the owning lane — recorded, not counted -->
            <div v-if="drift.navAllowlisted.length" style="margin-block-start: var(--space-3)">
                <p class="cc-small">
                    <strong>{{ t('c_system.coverage.known_drift', 'Known drift — deferred ({n}).', { named: { n: drift.navAllowlisted.length } }) }}</strong>
                    {{ t('c_system.coverage.known_drift_note', 'Recorded, not counted against the verdict; the owning lane resolves it.') }}
                </p>
                <ul class="stack" style="gap: var(--space-1)">
                    <li v-for="d in drift.navAllowlisted" :key="d.id" class="mono citation" data-no-i18n>
                        {{ d.id }} nav=<strong>{{ d.nav }}</strong> — {{ d.note }}
                    </li>
                </ul>
            </div>
        </Card>

        <!-- ─────────────────────────── tour runway (informational) ── -->
        <Card as="section" :title="t('c_system.coverage.runway_title', 'Tour runway — reachable surfaces not yet a stop')">
            <p class="cc-small">
                {{ t('c_system.coverage.runway_help', 'Player-reachable, wired surfaces (no role gate, no sandbox) that no tour stop covers yet — the runway toward the 117-stop contract. Informational, not drift.') }}
            </p>
            <p v-if="!drift.tourGap.length" class="citation"><Icon name="check" size="sm" /> {{ t('c_system.coverage.runway_ok', 'Every reachable surface is on the tour.') }}</p>
            <ul v-else class="cluster" style="gap: var(--space-2); flex-wrap: wrap">
                <li v-for="g in drift.tourGap" :key="g.id" class="mono citation">{{ g.href }}</li>
            </ul>
        </Card>
    </PageScaffold>
</template>
