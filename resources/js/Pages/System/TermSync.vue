<script setup>
/**
 * System/TermSync — FE-C10 (PHASE_C_DESIGN_frontend.md §B.16, WF-SYS-01).
 *
 * READ-ONLY BY DESIGN — zero actions; the page's whole point is that
 * there is NO skip/delay/reschedule API. Per-legislature single-clock
 * cards render the ARMED CLK-01 timer's REAL due_at from clock_timers ·
 * lockstep table (San Marino + Montegiardino are the live fixtures) ·
 * decoupled 10-year civil clocks (CLK-09) · the engine-refusals card
 * with the four refusal rules verbatim + any real recorded rejections.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';
import LogRow from '@/Components/Ui/LogRow.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    legislatures: { type: Array, default: () => [] },
    lockstepRoles: { type: Array, default: () => [] },
    civilTerms: { type: Array, default: () => [] },
    refusals: { type: Array, default: () => [] },
});

const KIND_LABELS = {
    legislature_seat: 'Legislature seat',
    executive_seat: 'Executive seat',
    judicial_seat: 'Judicial seat',
    election_board_member: 'Election board member',
    board_governor: 'Board governor',
    admin_staff: 'Administrative staff',
};

function dateOf(iso) {
    return iso ? new Date(iso).toLocaleDateString() : '—';
}

function daysUntil(iso) {
    if (!iso) return null;
    return Math.max(0, Math.ceil((new Date(iso) - Date.now()) / 86400000));
}

// ── The inline-SVG lockstep timeline. Rendered from the live term registry:
// the elected branches share ONE window and end together (CLK-10 structural),
// drawn as three bars with a common-expiry marker; the appointed offices run a
// separate, longer 10-year clock, drawn as a dashed contrast bar. A "today"
// marker sits at the current date. Null (→ honest empty-state) until a real
// term window is seated — no synthetic dates.
const SVG = { W: 800, padL: 96, padR: 24, topPad: 30, laneH: 24, laneGap: 14, bottomPad: 28 };

const lockstepTimeline = computed(() => {
    const ref = props.legislatures.find((l) => l.term?.starts_on && l.term?.ends_on);
    if (!ref) return null;

    const start = new Date(ref.term.starts_on).getTime();
    const end = new Date(ref.term.ends_on).getTime();
    if (!(end > start)) return null;

    const years = props.civilTerms[0]?.years ?? 10;
    const appointedEnd = start + years * 365.25 * 86400000;
    const now = Date.now();
    const winStart = start;
    const winEnd = Math.max(end, appointedEnd, now);
    const span = winEnd - winStart || 1;

    const innerW = SVG.W - SVG.padL - SVG.padR;
    const x = (t) => SVG.padL + ((t - winStart) / span) * innerW;
    const laneY = (i) => SVG.topPad + i * (SVG.laneH + SVG.laneGap);

    const elected = ['Legislative', 'Executive', 'Judicial'].map((label, i) => ({
        label,
        y: laneY(i),
        x: x(start),
        w: Math.max(2, x(end) - x(start)),
    }));

    return {
        width: SVG.W,
        height: laneY(4) + SVG.bottomPad - SVG.laneGap,
        laneH: SVG.laneH,
        elected,
        appointed: {
            label: `Appointed · ${years}-yr`,
            y: laneY(3),
            x: x(start),
            w: Math.max(2, x(appointedEnd) - x(start)),
        },
        electedEndX: x(end),
        appointedEndX: x(appointedEnd),
        todayX: now >= winStart && now <= winEnd ? x(now) : null,
        labels: {
            start: new Date(start).getFullYear(),
            electedEnd: new Date(end).getFullYear(),
            appointedEnd: new Date(appointedEnd).getFullYear(),
        },
    };
});

const lockstepColumns = [
    { key: 'kind', label: 'Elected role' },
    { key: 'count', label: 'Serving terms' },
    { key: 'ends_on', label: 'Common expiry' },
];

const REFUSAL_RULES = [
    'Skip, delay, or reschedule a derived election — there is no API for it; the trigger fires from CLK-01 regardless of who holds office.',
    'Extend any elected term past the common expiry, including under active emergency powers — elections cannot be disrupted (Art. II §7).',
    'Let executive or judicial terms drift from the legislative term when an office converts to elected (Art. III §3; Art. IV §3).',
    'Vacancies never reset the clock — countback and special-election winners serve the remainder of the term (Art. II §5 · CLK-04).',
];
</script>

<template>
    <PageScaffold :surface="surface">
        <template #intro>
            Every elected term — legislature, executive, judiciary — ends on the same day, and one
            clock schedules every election. There is no calendar to manipulate: the next general
            election exists from the moment the last one certifies.
        </template>

        <p>
            <HardenedChip>no election can be skipped or delayed by officials</HardenedChip>
        </p>

        <!-- ==================================== single-clock cards ======= -->
        <Card
            v-for="row in legislatures"
            :key="row.id"
            as="section"
            :title="`${row.name} — one clock, every trigger`"
        >
            <div class="cluster" style="gap: var(--space-6)">
                <Stat
                    :value="daysUntil(row.next_election.clock_due_at) ?? '—'"
                    label="days to the next general election"
                    accent
                />
                <Stat
                    :value="dateOf(row.next_election.clock_due_at)"
                    label="next general election · the armed CLK-01 timer's real due_at"
                />
                <Stat :value="row.interval_months" label="months per term (election_interval_months)" />
            </div>

            <p class="cc-small" style="margin-block-start: var(--space-3)">
                Term {{ row.term.starts_on ?? '—' }} → {{ row.term.ends_on ?? '—' }}
                ({{ row.mode }}) — the legislative term defines
                <span data-no-i18n>election_interval_months</span> · CLK-01; elected executive and
                judicial terms equal it · CLK-10 structural — the next election exists from the
                moment the prior certifies.
                <template v-if="row.next_election.election_id">
                    {{ ' ' }}
                    <Link :href="`/elections/${row.next_election.election_id}`">open successor election ({{ row.next_election.election_status }}) →</Link>
                </template>
            </p>
            <p class="citation">
                <Link :href="row.chamber_href">{{ row.jurisdiction }} chamber →</Link>
                · a setting change applies from the next cycle, never the running one — dependent clocks re-derive · Art. II §2
            </p>
        </Card>
        <Card v-if="!legislatures.length" as="section" title="One clock, every trigger">
            <p class="cc-small gloss">
                No active legislature yet — jurisdictions activate at critical population (CLK-06);
                each starts its own clock at first certification.
            </p>
        </Card>

        <!-- ==================================== lockstep timeline ======== -->
        <Card as="section" title="The lockstep — at a glance">
            <p class="cc-small">
                Every elected branch shares one term window and ends on the same day; appointed
                offices run a separate, longer 10-year clock. Rendered from the live term registry.
            </p>
            <figure
                v-if="lockstepTimeline"
                class="timeline"
                role="img"
                aria-label="Lockstep timeline: the legislative, executive, and judicial terms share one window and end on a common expiry date; the appointed 10-year clock runs longer and decoupled; a marker shows today."
            >
                <svg
                    :viewBox="`0 0 ${lockstepTimeline.width} ${lockstepTimeline.height}`"
                    width="100%"
                    preserveAspectRatio="xMidYMid meet"
                >
                    <!-- three elected branch bars, ending together -->
                    <g v-for="bar in lockstepTimeline.elected" :key="bar.label">
                        <text :x="8" :y="bar.y + 16" class="tl-label" data-no-i18n>{{ bar.label }}</text>
                        <rect :x="bar.x" :y="bar.y" :width="bar.w" :height="lockstepTimeline.laneH" rx="4" class="tl-elected" />
                    </g>
                    <!-- dashed 10-year appointed contrast bar -->
                    <text :x="8" :y="lockstepTimeline.appointed.y + 16" class="tl-label" data-no-i18n>{{ lockstepTimeline.appointed.label }}</text>
                    <rect
                        :x="lockstepTimeline.appointed.x"
                        :y="lockstepTimeline.appointed.y"
                        :width="lockstepTimeline.appointed.w"
                        :height="lockstepTimeline.laneH"
                        rx="4"
                        class="tl-appointed"
                    />
                    <!-- common-expiry marker (the elected bars all stop here) -->
                    <line
                        :x1="lockstepTimeline.electedEndX"
                        :y1="lockstepTimeline.elected[0].y - 6"
                        :x2="lockstepTimeline.electedEndX"
                        :y2="lockstepTimeline.appointed.y - 6"
                        class="tl-expiry"
                    />
                    <!-- today marker -->
                    <template v-if="lockstepTimeline.todayX !== null">
                        <line :x1="lockstepTimeline.todayX" y1="18" :x2="lockstepTimeline.todayX" :y2="lockstepTimeline.height - 20" class="tl-today" />
                        <text :x="lockstepTimeline.todayX" y="12" class="tl-today-label" text-anchor="middle">today</text>
                    </template>
                    <!-- year axis -->
                    <text :x="lockstepTimeline.elected[0].x" :y="lockstepTimeline.height - 6" class="tl-axis" data-no-i18n>{{ lockstepTimeline.labels.start }}</text>
                    <text :x="lockstepTimeline.electedEndX" :y="lockstepTimeline.height - 6" class="tl-axis" text-anchor="middle" data-no-i18n>{{ lockstepTimeline.labels.electedEnd }} · common expiry</text>
                    <text :x="lockstepTimeline.appointedEndX" :y="lockstepTimeline.height - 6" class="tl-axis" text-anchor="end" data-no-i18n>{{ lockstepTimeline.labels.appointedEnd }}</text>
                </svg>
            </figure>
            <p v-else class="cc-small gloss">
                The timeline renders once a legislature's term window is seated — no active elected
                term is on record yet.
            </p>
            <p class="tl-emergency">
                <StatusBadge tone="info" icon="clock">Under emergency powers the lockstep is unaffected</StatusBadge>
                <span class="cc-small"> — no elected term can be extended, even in an emergency (Art. II §7).</span>
            </p>
        </Card>

        <!-- ==================================== lockstep table =========== -->
        <Card as="section" title="The lockstep — every term-bearing elected role">
            <DataTable
                v-if="lockstepRoles.length"
                :columns="lockstepColumns"
                :rows="lockstepRoles"
                caption="Lockstep terms by role"
            >
                <template #cell-kind="{ row }">{{ KIND_LABELS[row.kind] ?? row.kind }}</template>
                <template #cell-ends_on="{ row }">
                    <span data-no-i18n>{{ row.ends_on }}</span>
                    {{ ' ' }}
                    <StatusBadge tone="info" icon="clock">anchored to its legislature's clock</StatusBadge>
                </template>
            </DataTable>
            <p v-else class="cc-small gloss">No active lockstep terms on record.</p>
            <p class="citation" style="margin-block-start: var(--space-2)">
                Lockstep expiry · Art. III §3; Art. IV §3 — replacement terms (countback/special) INHERIT the original expiry, never a fresh term.
            </p>
        </Card>

        <div class="grid-2">
            <!-- ================================== decoupled =============== -->
            <section class="card" aria-labelledby="civil-h">
                <h2 id="civil-h">Appointed officers — deliberately decoupled</h2>
                <div v-if="civilTerms.length" class="stack" style="gap: var(--space-2); margin-block-start: var(--space-2)">
                    <p v-for="(term, ti) in civilTerms" :key="ti" class="cc-small">
                        <strong>{{ KIND_LABELS[term.kind] ?? term.kind }}</strong> — {{ term.count }} serving ·
                        {{ term.years }}-year appointment clocks
                        <span class="citation" data-no-i18n>· {{ term.clock }}</span>
                    </p>
                </div>
                <p v-else class="cc-small gloss" style="margin-block-start: var(--space-2)">No active civil appointments yet.</p>
                <p class="citation" style="margin-block-start: var(--space-2)">
                    appointment terms are deliberately decoupled from election cycles · Art. II §9; Art. IV §1 · CLK-09
                </p>
            </section>

            <!-- ================================== staggered note ========== -->
            <section class="card" aria-labelledby="stagger-h">
                <h2 id="stagger-h">Staggered activation</h2>
                <p class="cc-small" style="margin-block-start: var(--space-2)">
                    Jurisdictions activate at their own thresholds as populations reach critical
                    mass (CLK-06), so clocks start staggered. Lockstep harmonization toward a
                    shared election day is an encompassing-level end-state that arrives as
                    participation grows.
                </p>
                <p class="citation" style="margin-block-start: var(--space-2)">
                    Vacancies fit inside the lockstep; they never move it — countback then special election within 90–180 days · CLK-04
                </p>
            </section>
        </div>

        <!-- ==================================== refusals ================= -->
        <Card as="section" title="What the engine refuses to do">
            <p>
                <HardenedChip>no skip / delay / reschedule API exists — refusal is structural, not policy</HardenedChip>
            </p>
            <ul style="margin-block: var(--space-2)">
                <li v-for="(rule, ri) in REFUSAL_RULES" :key="ri">{{ rule }}</li>
            </ul>
            <template v-if="refusals.length">
                <h3 style="font-size: var(--text-base)">Recorded rejections</h3>
                <div class="stack" style="gap: var(--space-1)">
                    <LogRow v-for="refusal in refusals" :key="refusal.audit_seq" :seq="refusal.audit_seq" rejected>
                        <div style="flex: 1 1 18rem; min-inline-size: 0">
                            <strong style="color: var(--gov-fg)">{{ refusal.attempt }}</strong>
                            <span class="citation" style="display: block" data-no-i18n>{{ refusal.citation }} · {{ refusal.at }}</span>
                        </div>
                        <Link class="citation" :href="`/system/audit-chain?seq=${refusal.audit_seq}`">on the chain →</Link>
                    </LogRow>
                </div>
            </template>
            <p v-else class="cc-small gloss">
                No violation has ever been attempted on this instance — attempts would be rejected
                pre-commit and recorded here verbatim.
            </p>
            <p class="citation" style="margin-block-start: var(--space-2)">
                Attempted violations are rejected pre-commit and recorded ·
                <Link href="/system/audit-chain">audit chain</Link> · WF-SYS-04
            </p>
        </Card>

        <template #about>
            <p>
                Zero actions live here by design — every number renders from the terms registry and
                the armed CLK-01 timers; the lockstep is what moves every race at once (WF-SYS-01 →
                WF-ELE-01/08/09).
            </p>
        </template>
    </PageScaffold>
</template>

<style scoped>
.timeline {
    margin: var(--space-3) 0;
    overflow-x: auto;
}

.timeline svg {
    display: block;
    max-width: 46rem;
}

.tl-elected {
    fill: var(--gov-primary);
    opacity: 0.85;
}

.tl-appointed {
    fill: none;
    stroke: var(--gov-fg-subtle);
    stroke-width: 1.5;
    stroke-dasharray: 5 4;
}

.tl-label {
    fill: var(--gov-fg-muted);
    font-size: 12px;
}

.tl-axis {
    fill: var(--gov-fg-subtle);
    font-size: 11px;
}

.tl-expiry {
    stroke: var(--gov-border-strong);
    stroke-width: 1;
}

.tl-today {
    stroke: var(--danger);
    stroke-width: 1.5;
    stroke-dasharray: 3 3;
}

.tl-today-label {
    fill: var(--danger);
    font-size: 11px;
}

.tl-emergency {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    flex-wrap: wrap;
    margin-block-start: var(--space-3);
}
</style>
