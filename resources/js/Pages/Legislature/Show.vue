<script setup>
/**
 * Legislature/Show — the legislature overview (mockups-v3-wiring Phase 3e).
 *
 * The former 5.2k-line monolith hosting BOTH this overview AND the full
 * district mapper was split: the ENTIRE lm-split Leaflet machine moved
 * VERBATIM to Legislature/Districts.vue (route
 * /legislatures/{legislature}/districts); this page is what remains — who
 * this legislature is (seats, term, status), who serves in it, which
 * district maps it has, and the doors to everything else. Every pre-split
 * mapper deep link (?scope= / ?map= / ?setup= / ?compare=) is forwarded by
 * the controller to the districts surface, so nothing bookmarked breaks.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import LegislatureWorkspaceNav from '@/Components/Legislature/LegislatureWorkspaceNav.vue';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

/* Phase 3e monolith retirement: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, default: null },
    workspace: { type: Object, required: true },
    /** { id, slug, name, status, term_number, term_starts_on, term_ends_on,
     *    type_a_seats, type_b_seats, serving, seated, speaker_name,
     *    chamber_seated } */
    legislature: { type: Object, required: true },
    /** { total, active: { id, name, status, district_count } | null } */
    maps: { type: Object, default: () => ({ total: 0, active: null }) },
    /** The district-mapper URL (/legislatures/{slug}/districts). */
    districtsHref: { type: String, required: true },
});
const { t } = useI18n();
const text = key => t('c_legislature_workspace.' + key);

const statusTone = computed(() => ({
    active: 'success',
    forming: 'info',
    dissolved: 'neutral',
}[props.legislature.status] ?? 'neutral'));

const totalSeats = computed(
    () => (props.legislature.type_a_seats ?? 0) + (props.legislature.type_b_seats ?? 0),
);

function fmtDate(iso) {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleDateString();
    } catch {
        return iso;
    }
}

function fmtNum(n) {
    return typeof n === 'number' ? n.toLocaleString() : (n ?? '—');
}
</script>

<template>
    <PageScaffold :surface="surface" :title="`${legislature.name} — legislature`">
        <LegislatureWorkspaceNav :workspace="workspace" active="overview" />

        <!-- ===================================== districts & maps ====== -->
        <Card as="section" :title="text('maps')">
            <p>{{ text('map_intro') }}</p>
            <div class="cluster" style="gap: var(--space-5); align-items: flex-start; margin-block: var(--space-3)">
                <Stat :value="fmtNum(maps.total)" :label="maps.total === 1 ? 'district map' : 'district maps'" />
                <Stat
                    v-if="maps.active"
                    :value="fmtNum(maps.active.district_count)"
                    :label="`districts on “${maps.active.name}” (${maps.active.status})`"
                    accent
                />
                <Stat v-else value="—" label="no active map yet" />
            </div>
            <Btn :as="Link" :href="districtsHref" variant="primary" icon="map">
                {{ text('open_maps') }}
            </Btn>
        </Card>

        <!-- =========================================== the chamber ====== -->
        <Card as="section" title="Seats &amp; term">
            <div class="cluster" style="gap: var(--space-3); margin-block-end: var(--space-3)">
                <StatusBadge :tone="statusTone">{{ legislature.status }}</StatusBadge>
                <span v-if="legislature.term_number" class="cc-small">
                    Term {{ legislature.term_number }}
                    <template v-if="legislature.term_starts_on">
                        · {{ fmtDate(legislature.term_starts_on) }} →
                        {{ fmtDate(legislature.term_ends_on) }}
                    </template>
                </span>
                <span v-if="legislature.speaker_name" class="cc-small">
                    Speaker: {{ legislature.speaker_name }}
                </span>
            </div>
            <div class="cluster" style="gap: var(--space-5); align-items: flex-start">
                <Stat :value="fmtNum(totalSeats)" label="seats" />
                <Stat :value="fmtNum(legislature.type_a_seats)" :label="text('type_a')" />
                <Stat
                    v-if="legislature.type_b_seats > 0"
                    :value="fmtNum(legislature.type_b_seats)"
                    :label="text('type_b')"
                />
                <Stat :value="fmtNum(legislature.serving)" label="serving now" accent />
            </div>
        </Card>

    </PageScaffold>
</template>
