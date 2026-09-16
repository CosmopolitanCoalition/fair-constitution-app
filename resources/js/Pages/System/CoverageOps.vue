<script setup>
/**
 * System/CoverageOps — the dense build-team drift matrix (design contract:
 * mockups/v3/shared/coverage-ops.html).
 *
 * The row-by-row sibling of /coverage: every registered surface, every registry
 * nav row, and every tour stop, each with its resolution status against the
 * app's ground truth (routes served + surfaces registered, both props). Same
 * verdict, more detail — this is where you find the offending row.
 *
 * Public read (build-team info only), reachable from "For the build team".
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import Banner from '@/Components/Ui/Banner.vue';
import { PLAYER_NAV, SITEMAP, TOUR } from '@/registry/surfaces.js';
import { flattenRegistryNav, computeCoverageDrift, NAV_ALLOWLIST } from '@/registry/coverage.js';

defineOptions({ layout: AppShellV2 });

const { t } = useI18n();

const props = defineProps({
    surface: { type: Object, required: true },
    routes: { type: Array, default: () => [] },
    surfaces: { type: Array, default: () => [] },
});

const navRows = computed(() => flattenRegistryNav(PLAYER_NAV, SITEMAP));
const drift = computed(() =>
    computeCoverageDrift({ surfaces: props.surfaces, routes: props.routes, nav: navRows.value, tour: TOUR }),
);

const routeSet = computed(() => new Set(props.routes));
const registryIds = computed(() => new Set(navRows.value.map((n) => n.id)));

const staticPath = (href) => (!href || String(href).startsWith('tour:') ? null : String(href).split('?')[0]);
const isConcrete = (p) => !!p && !p.includes('{');

/* Registry nav rows with a resolution verdict. */
const navMatrix = computed(() =>
    navRows.value.map((n) => {
        const p = staticPath(n.href);
        let status = 'planned';
        if (n.href && String(n.href).startsWith('tour:')) status = 'special';
        else if (p && isConcrete(p)) status = routeSet.value.has(p) ? 'served' : 'dead';
        return { id: n.id, section: n.section, href: n.href, roles: n.roles || null, status };
    }),
);

/* Surfaces with a nav-resolution verdict. */
const surfaceMatrix = computed(() =>
    props.surfaces.map((s) => ({
        ...s,
        navStatus:
            s.nav == null || s.nav === '' ? 'none'
            : registryIds.value.has(s.nav) ? 'resolves'
            : NAV_ALLOWLIST[s.nav] ? 'allowlisted'
            : 'unresolved',
    })),
);

/* Tour stops with a route verdict. */
const tourMatrix = computed(() =>
    TOUR.map((t, i) => {
        const p = staticPath(t.href);
        return { i: i + 1, act: t.act, title: t.title, href: t.href, served: isConcrete(p) && routeSet.value.has(p) };
    }),
);

const tone = (s) =>
    ({ served: 'success', resolves: 'success', dead: 'danger', unresolved: 'danger', allowlisted: 'info', planned: 'neutral', special: 'info', none: 'neutral' })[s] || 'neutral';
</script>

<template>
    <PageScaffold :surface="surface">
        <template #intro>
            {{ t('c_system.coverage_ops.intro', 'Live proof of coverage, row by row: every surface, every registry nav row, and every tour stop against the routes the app serves. Each build stage turns its scope green before it commits — that green is what "done" means.') }}
        </template>

        <p class="citation"><Link href="/coverage">{{ t('c_system.coverage_ops.back_link', '← back to the coverage dashboard') }}</Link></p>

        <Banner v-if="!drift.ok" tone="emergency" :title="t('c_system.coverage_ops.drift_title', 'Drift detected')">
            {{ t('c_system.coverage_ops.drift_body', '{dead} dead nav link(s) · {tour} dead tour stop(s) · {nav} unresolved surface nav(s).', { named: { dead: drift.deadNavLinks.length, tour: drift.deadTourStops.length, nav: drift.navUnresolved.length } }) }}
        </Banner>
        <Banner v-else tone="info" :title="t('c_system.coverage_ops.all_resolve_title', 'All rows resolve')">
            {{ t('c_system.coverage_ops.all_resolve_body', 'Every registry row, tour stop, and surface nav resolves against the running app.') }}
        </Banner>

        <!-- ─────────────────────────────── registry nav rows ── -->
        <Card as="section" :title="t('c_system.coverage_ops.nav_rows_title', 'Registry nav rows → routes')">
            <!-- W-0338: these tables scroll sideways at narrow widths; the wrapper is a named, focusable region. -->
            <div class="table-wrap" tabindex="0" role="region" :aria-label="t('c_system.coverage_ops.nav_rows_region', 'Registry nav rows (scrollable)')">
                <table class="table">
                    <thead><tr><th>{{ t('c_system.coverage_ops.col_id', 'ID') }}</th><th>{{ t('c_system.coverage_ops.col_section', 'Section') }}</th><th>{{ t('c_system.coverage_ops.col_href', 'Href') }}</th><th>{{ t('c_system.coverage_ops.col_roles', 'Roles') }}</th><th>{{ t('c_system.coverage_ops.col_status', 'Status') }}</th></tr></thead>
                    <tbody>
                        <tr v-for="r in navMatrix" :key="r.section + ':' + r.id">
                            <td class="mono">{{ r.id }}</td>
                            <td class="mono">{{ r.section }}</td>
                            <td class="mono">{{ r.href ?? '—' }}</td>
                            <td class="mono">{{ r.roles ? r.roles.join('/') : '—' }}</td>
                            <td><StatusBadge :tone="tone(r.status)">{{ r.status }}</StatusBadge></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>

        <!-- ─────────────────────────────── surfaces → nav ── -->
        <Card as="section" :title="t('c_system.coverage_ops.surfaces_title', 'Registered surfaces → menu id (SurfaceMeta::ids())')">
            <div class="table-wrap" tabindex="0" role="region" :aria-label="t('c_system.coverage_ops.surfaces_region', 'Registered surfaces (scrollable)')">
                <table class="table">
                    <thead><tr><th>{{ t('c_system.coverage_ops.col_surface_id', 'Surface id') }}</th><th>{{ t('c_system.coverage_ops.col_module', 'Module') }}</th><th>{{ t('c_system.coverage_ops.col_nav', 'Nav') }}</th><th>{{ t('c_system.coverage_ops.col_status', 'Status') }}</th></tr></thead>
                    <tbody>
                        <tr v-for="s in surfaceMatrix" :key="s.id">
                            <td class="mono">{{ s.id }}</td>
                            <td class="mono">{{ s.module ?? '—' }}</td>
                            <td class="mono">{{ s.nav ?? '—' }}</td>
                            <td><StatusBadge :tone="tone(s.navStatus)">{{ s.navStatus }}</StatusBadge></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>

        <!-- ─────────────────────────────── tour stops → routes ── -->
        <Card as="section" :title="t('c_system.coverage_ops.tour_title', 'Tour stops → routes')">
            <div class="table-wrap" tabindex="0" role="region" :aria-label="t('c_system.coverage_ops.tour_region', 'Tour stops (scrollable)')">
                <table class="table">
                    <thead><tr><th>{{ t('c_system.coverage_ops.col_hash', '#') }}</th><th>{{ t('c_system.coverage_ops.col_act', 'Act') }}</th><th>{{ t('c_system.coverage_ops.col_stop', 'Stop') }}</th><th>{{ t('c_system.coverage_ops.col_href', 'Href') }}</th><th>{{ t('c_system.coverage_ops.col_status', 'Status') }}</th></tr></thead>
                    <tbody>
                        <tr v-for="t in tourMatrix" :key="t.i">
                            <td class="mono">{{ t.i }}</td>
                            <td>{{ t.act }}</td>
                            <td>{{ t.title }}</td>
                            <td class="mono">{{ t.href }}</td>
                            <td><StatusBadge :tone="t.served ? 'success' : 'danger'">{{ t.served ? 'served' : 'dead' }}</StatusBadge></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>
    </PageScaffold>
</template>
