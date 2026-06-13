<script setup>
/**
 * Executive/DepartmentDetail — FE-D3 (PHASE_D_DESIGN_frontend.md §B.3;
 * surface executive/department-detail) ← the BoG-consent EXIT surface.
 *
 * Composes, top to bottom:
 *   • header badges (Operating / workers / "{g} governors + {w} worker-elected")
 *   • ESM-17 StateStrip — `removal_requested` splices in live when a removal opens
 *   • charter & oversight card (charter + Act chip; oversight executive +
 *     "full and equal investigative power"; oversees-CGC links + the
 *     perpetual-public-domain note)
 *   • BoardStrip FULL — the two-clock roster (governors 10-yr CLK-09 beside
 *     worker seats on the legislative-term CLK-10; chair joint-elected)
 *   • nomination dossier FormCard (F-EXE-001) + per-nomination cards with the
 *     Stepper and, once the consent vote opens, the chamber VoteTally
 *     (threshold_class MAJORITY) + VoteCastList — the chamber vote rendered
 *     on the executive surface
 *   • removal FormCard (F-EXE-003) + live removal VoteTally — MAJORITY,
 *     deliberately NOT supermajority (the gloss states the contrast)
 *   • reporting summary → DepartmentReporting link
 *
 * CONSTITUTIONAL POSTURE — pure renderer: composition_valid, the consent
 * VoteTally numbers, and the seat terms are all engine snapshots off the
 * boards / chamber_votes / board_seats rows. Casting happens in the
 * legislature (the Phase C /votes/{vote}/cast endpoint); this page renders
 * the same row, it never originates a vote.
 */
import { computed } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import FormCard from '@/Components/Surface/FormCard.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Card from '@/Components/Ui/Card.vue';
import Field from '@/Components/Ui/Field.vue';
import FormChip from '@/Components/Ui/FormChip.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';
import StateStrip from '@/Components/Ui/StateStrip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import Stepper from '@/Components/Ui/Stepper.vue';
import TagChip from '@/Components/Ui/TagChip.vue';
import BoardStrip from '@/Components/Organizations/BoardStrip.vue';
import VoteTally from '@/Components/Legislature/VoteTally.vue';
import VoteCastList from '@/Components/Legislature/VoteCastList.vue';

const props = defineProps({
    surface: { type: Object, required: true },
    /** §B.3 department block. */
    department: { type: Object, required: true },
    /** ESM-17 (department_board) — PHP-owned. */
    machine: { type: Array, default: () => [] },
    /** { compositionValid, requiredWorkerSeats, owner_seats, worker_seats, seats:[BoardStrip rows], chair } | null */
    board: { type: Object, default: null },
    /** [{ id, nominee, status, consent_vote:{tally, casts}|null, term, stepper }] */
    nominations: { type: Array, default: () => [] },
    /** [{ id, subject, grounds_published, vote:{tally, casts}|null, outcome }] */
    removals: { type: Array, default: () => [] },
    reporting: { type: Object, default: null },
    can: { type: Object, default: () => ({ nominate: false, requestRemoval: false }) },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

const KIND_LABELS = {
    chief_executive: 'Chief Executive',
    treasury: 'Treasury',
    defense: 'Defense',
    state: 'State',
    justice: 'Justice',
    other: 'Custom',
};
const kindLabel = computed(() => KIND_LABELS[props.department.kind] ?? props.department.kind);

/* Header counts — engine seat figures (owner side vs worker-elected). */
const governorCount = computed(() => (props.board?.owner_seats ?? 0));
const workerSeatCount = computed(() => (props.board?.worker_seats ?? 0));

/* ESM-17 display status badge. */
const STATUS_TONES = {
    operating: ['success', 'check', 'Operating'],
    reporting: ['info', 'bar-chart', 'Reporting'],
    chartered: ['info', 'file-text', 'Chartered'],
    oversight_assigned: ['info', 'shield', 'Oversight assigned'],
    governors_nominated: ['info', 'clock', 'Governors nominated'],
    consented: ['info', 'check', 'Consented'],
    removal_requested: ['warning', 'alert-triangle', 'Removal requested'],
    rechartered: ['neutral', 'refresh-cw', 'Re-chartered'],
    dissolved: ['neutral', 'minus', 'Dissolved'],
};
const statusBadge = computed(() => {
    const [tone, icon, text] = STATUS_TONES[props.department.status] ?? ['neutral', null, props.department.status];
    return { tone, icon, text };
});

/* Per-nomination card status badge. */
const NOM_TONES = {
    nominated: ['info', 'clock', 'Nominated · consent pending'],
    consented: ['success', 'check', 'Consented'],
    seated: ['success', 'check', 'Seated'],
    rejected: ['danger', 'x', 'Consent failed · renominate (WF-EXE-05)'],
    ended: ['neutral', 'minus', 'Ended'],
};
function nomBadge(status) {
    const [tone, icon, text] = NOM_TONES[status] ?? ['neutral', null, status];
    return { tone, icon, text };
}

/* Removal outcome badge. */
const REMOVAL_TONES = {
    pending: ['warning', 'clock', 'Removal vote open'],
    removed: ['danger', 'x', 'Removed by majority'],
    retained: ['success', 'check', 'Retained'],
};
function removalBadge(outcome) {
    const [tone, icon, text] = REMOVAL_TONES[outcome] ?? ['neutral', null, outcome];
    return { tone, icon, text };
}

/* ---------------------------------------------------- F-EXE-001 nominate -- */
const nomination = useForm({
    nominee_user_id: '',
    dossier: '',
});
function submitNomination() {
    nomination.post(`/departments/${props.department.id}/nominations`, {
        preserveScroll: true,
        onSuccess: () => nomination.reset(),
    });
}

/* ----------------------------------------------------- F-EXE-003 removal -- */
const removal = useForm({
    board_seat_id: '',
    grounds: '',
});
function submitRemoval() {
    removal.post(`/departments/${props.department.id}/removal-requests`, {
        preserveScroll: true,
        onSuccess: () => removal.reset(),
    });
}

/* Seats eligible for a removal request: currently SEATED governor/worker. */
const removableSeats = computed(() =>
    (props.board?.seats ?? []).filter((s) => s.status === 'seated'),
);

const hasReportingInterval = computed(() => props.department.charter?.reporting_interval_months != null);

function fmtDate(value) {
    if (!value) return '—';
    try {
        return new Date(value).toLocaleDateString();
    } catch {
        return value;
    }
}
</script>

<template>
    <PageScaffold :surface="surface" :title="department.name">
        <template #intro>
            A chartered department, its Board of Governors, and the consent pipeline that seats it.
            The governors hold 10-year civil terms (CLK-09); the worker-elected seats run on the
            legislative-term clock (CLK-10) — the two clocks sit side by side in the roster below.
        </template>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <!-- =============================================== header ======== -->
        <Card as="section" :title="department.name">
            <p class="cluster" style="gap: var(--space-2)">
                <TagChip data-no-i18n>{{ kindLabel }}</TagChip>
                <StatusBadge :tone="statusBadge.tone" :icon="statusBadge.icon">{{ statusBadge.text }}</StatusBadge>
                <StatusBadge tone="info" icon="users">{{ department.worker_count }} workers</StatusBadge>
                <StatusBadge v-if="board" tone="neutral" icon="landmark">
                    {{ governorCount }} governors + {{ workerSeatCount }} worker-elected
                </StatusBadge>
            </p>
            <p v-if="machine.length" style="margin-block-start: var(--space-3)">
                <StateStrip :states="machine" :current="department.status" />
            </p>
            <p class="cluster" style="gap: var(--space-2); margin-block-start: var(--space-2)">
                <Link :href="department.executive.href">{{ department.executive.name }} →</Link>
                <Link :href="`${department.executive.href}/departments`">All departments →</Link>
            </p>
        </Card>

        <!-- ====================================== charter & oversight ==== -->
        <Card as="section" title="Charter & oversight">
            <!-- charter -->
            <p>
                <FormChip form-id="F-LEG-016" name="Department Creation Act" />
                <Link
                    v-if="department.charter.act"
                    :href="department.charter.act.href"
                    class="tag-chip"
                    data-no-i18n
                    style="margin-inline-start: var(--space-2)"
                >{{ department.charter.act.act_number }}</Link>
            </p>
            <p v-if="department.charter.text_summary" class="cc-small" style="white-space: pre-line">
                {{ department.charter.text_summary }}
            </p>
            <hr style="border: 0; border-block-start: 1px solid var(--gov-border); margin-block: var(--space-3)" />

            <!-- oversight -->
            <p>
                Overseen by <Link :href="department.executive.href">{{ department.executive.name }}</Link>.
            </p>
            <p class="citation">
                full and equal investigative power · Art. III §4 ·
                <Link :href="`${department.executive.href}/actions`">executive actions →</Link>
            </p>
            <hr style="border: 0; border-block-start: 1px solid var(--gov-border); margin-block: var(--space-3)" />

            <!-- oversees CGCs -->
            <template v-if="department.oversees_cgcs.length">
                <p>
                    Oversees:
                    <template v-for="(cgc, i) in department.oversees_cgcs" :key="cgc.name">
                        <template v-if="i > 0"> · </template><Link :href="cgc.href">{{ cgc.name }}</Link>
                    </template>
                </p>
                <p class="citation">CGC intellectual property is perpetually public domain · Art. III §5</p>
            </template>
            <p v-else class="gloss">
                No Common Good Corporations overseen by this department.
            </p>
        </Card>

        <!-- ============================================ board roster ===== -->
        <Card as="section" title="Board of Governors">
            <template v-if="board">
                <BoardStrip
                    :seats="board.seats"
                    :composition-valid="board.compositionValid"
                    :required-worker-seats="board.requiredWorkerSeats"
                />
                <p class="citation" style="margin-block-start: var(--space-2)">
                    Governors: 10-year civil appointments · CLK-09. Worker-elected seats end with the
                    legislative term · CLK-10. Chair: joint-elected by the entire board · Art. III §6.
                </p>
            </template>
            <Banner v-else tone="info" role="status" title="No board constituted yet.">
                The board and its governor seats are created when the department is chartered
                (F-LEG-016); governors are nominated (F-EXE-001) and consented (F-LEG-020) onto them.
            </Banner>
        </Card>

        <!-- ====================================== nomination pipeline ==== -->
        <Card as="section" title="Nomination pipeline">
            <template v-if="nominations.length">
                <div
                    v-for="nom in nominations"
                    :key="nom.id"
                    class="card card--inset"
                    style="margin-block-end: var(--space-3)"
                >
                    <p style="margin-block-end: var(--space-2)">
                        <strong style="color: var(--gov-fg)">{{ nom.nominee.name }}</strong>
                        <StatusBadge
                            :tone="nomBadge(nom.status).tone"
                            :icon="nomBadge(nom.status).icon"
                            style="margin-inline-start: var(--space-2)"
                        >{{ nomBadge(nom.status).text }}</StatusBadge>
                    </p>
                    <Stepper :steps="nom.stepper" />

                    <!-- the chamber consent vote, rendered HERE on the executive surface -->
                    <div v-if="nom.consent_vote" style="margin-block-start: var(--space-3)">
                        <p class="eyebrow">Consent vote · F-LEG-020 · majority of all serving</p>
                        <VoteTally v-bind="nom.consent_vote.tally" basis="Art. III §4 · peg-quorum majority" />
                        <details v-if="nom.consent_vote.casts.length" style="margin-block-start: var(--space-2)">
                            <summary class="citation" style="cursor: pointer">Published positions →</summary>
                            <div style="margin-block-start: var(--space-2)">
                                <VoteCastList :casts="nom.consent_vote.casts" />
                            </div>
                        </details>
                    </div>

                    <!-- seated term dates (10-yr CLK-09) -->
                    <p v-if="nom.term" class="citation" style="margin-block-start: var(--space-2)" data-no-i18n>
                        term {{ fmtDate(nom.term.starts_on) }} → {{ fmtDate(nom.term.ends_on) }} · CLK-09 (10 years)
                    </p>
                    <p v-if="nom.status === 'rejected'" class="citation" style="margin-block-start: var(--space-2)">
                        Consent failed — the seat reopens for renomination (WF-EXE-05).
                    </p>
                </div>
            </template>
            <p v-else class="gloss">
                No nominations on record. A seated member of the overseeing executive opens one below.
            </p>
        </Card>

        <!-- =========================== F-EXE-001 dossier form =========== -->
        <FormCard
            v-if="can.nominate"
            :form="surface.forms.find((f) => f.id === 'F-EXE-001')"
            :inertia-form="nomination"
            submit-label="File nomination dossier"
            processing-label="Filing…"
            @submit="submitNomination"
        >
            <Field
                label="Nominee"
                hint="The nominee's user id. Eligibility is active jurisdiction association only — neutrality is a duty of office, not an eligibility test (Art. I)."
                :error="nomination.errors.nominee_user_id"
                required
            >
                <template #control="{ id, invalid, describedBy }">
                    <input
                        :id="id"
                        v-model="nomination.nominee_user_id"
                        class="field-input"
                        type="text"
                        :aria-invalid="invalid ? 'true' : undefined"
                        :aria-describedby="describedBy"
                    />
                </template>
            </Field>

            <Field
                label="Dossier"
                hint="Credentials and the neutrality attestation — published at nomination."
                :error="nomination.errors.dossier"
            >
                <template #control="{ id, invalid, describedBy }">
                    <textarea
                        :id="id"
                        v-model="nomination.dossier"
                        class="field-input"
                        rows="4"
                        maxlength="20000"
                        :aria-invalid="invalid ? 'true' : undefined"
                        :aria-describedby="describedBy"
                    ></textarea>
                </template>
            </Field>

            <p class="citation" style="margin-block-start: var(--space-2)">
                opens the F-LEG-020 consent vote in the legislature · majority of all serving · Art. III §4.
            </p>
        </FormCard>

        <!-- ============================================ removals ========= -->
        <Card as="section" title="Removal requests">
            <p class="gloss" style="margin-block-end: var(--space-3)">
                Governor removal is an <strong>ordinary majority of all serving</strong> — hiring and
                firing (owner ruling #14). This is deliberately <strong>not</strong> the supermajority
                machinery used to remove elected officeholders.
            </p>

            <template v-if="removals.length">
                <div
                    v-for="rem in removals"
                    :key="rem.id"
                    class="card card--inset"
                    style="margin-block-end: var(--space-3)"
                >
                    <p style="margin-block-end: var(--space-2)">
                        <strong style="color: var(--gov-fg)">{{ rem.subject.name }}</strong>
                        <StatusBadge
                            :tone="removalBadge(rem.outcome).tone"
                            :icon="removalBadge(rem.outcome).icon"
                            style="margin-inline-start: var(--space-2)"
                        >{{ removalBadge(rem.outcome).text }}</StatusBadge>
                    </p>
                    <p class="cc-small" style="white-space: pre-line">{{ rem.grounds_published }}</p>
                    <div v-if="rem.vote" style="margin-block-start: var(--space-3)">
                        <p class="eyebrow">Removal vote · majority of all serving</p>
                        <VoteTally v-bind="rem.vote.tally" basis="Art. III §4 · ordinary majority · owner ruling #14" />
                        <details v-if="rem.vote.casts.length" style="margin-block-start: var(--space-2)">
                            <summary class="citation" style="cursor: pointer">Published positions →</summary>
                            <div style="margin-block-start: var(--space-2)">
                                <VoteCastList :casts="rem.vote.casts" />
                            </div>
                        </details>
                    </div>
                </div>
            </template>
            <p v-else class="gloss">No removal requests on record.</p>
        </Card>

        <!-- =========================== F-EXE-003 removal form =========== -->
        <FormCard
            v-if="can.requestRemoval"
            :form="surface.forms.find((f) => f.id === 'F-EXE-003')"
            :inertia-form="removal"
            submit-label="Request removal"
            processing-label="Filing…"
            :disabled="!removableSeats.length"
            @submit="submitRemoval"
        >
            <Field
                label="Board member"
                hint="Removal runs against a currently seated board member."
                :error="removal.errors.board_seat_id"
                required
            >
                <template #control="{ id, invalid, describedBy }">
                    <select
                        :id="id"
                        v-model="removal.board_seat_id"
                        class="select"
                        :aria-invalid="invalid ? 'true' : undefined"
                        :aria-describedby="describedBy"
                    >
                        <option value="" disabled>Select a seated member…</option>
                        <option v-for="seat in removableSeats" :key="seat.id" :value="seat.id">
                            {{ seat.holder?.name ?? 'Seat' }} — {{ seat.seat_class.replaceAll('_', ' ') }}
                        </option>
                    </select>
                </template>
            </Field>

            <Field
                label="Grounds"
                hint="A good-faith competence/ethics finding — published at filing."
                :error="removal.errors.grounds"
                required
            >
                <template #control="{ id, invalid, describedBy }">
                    <textarea
                        :id="id"
                        v-model="removal.grounds"
                        class="field-input"
                        rows="4"
                        maxlength="20000"
                        :aria-invalid="invalid ? 'true' : undefined"
                        :aria-describedby="describedBy"
                    ></textarea>
                </template>
            </Field>

            <p class="citation" style="margin-block-start: var(--space-2)">
                opens an ordinary-majority chamber vote (NOT supermajority) · owner ruling #14 · Art. III §4.
            </p>
            <Banner
                v-if="!removableSeats.length"
                tone="info"
                role="status"
                title="No seated members to remove."
                style="margin-block-start: var(--space-2)"
            >
                There is no currently seated board member to file a removal against.
            </Banner>
        </FormCard>

        <!-- ============================================ reporting ======== -->
        <Card v-if="reporting" as="section" title="Reporting">
            <p v-if="reporting.last_filed">
                Last filed: {{ reporting.last_filed.kind }} report on {{ fmtDate(reporting.last_filed.at) }}.
                <Link v-if="reporting.last_filed.record_href" :href="reporting.last_filed.record_href">on the public record →</Link>
            </p>
            <p v-if="reporting.next_due" class="cc-small">
                Next due {{ fmtDate(reporting.next_due.on) }}
                <StatusBadge
                    :tone="reporting.next_due.status === 'overdue' ? 'danger' : reporting.next_due.status === 'due_soon' ? 'warning' : 'info'"
                    style="margin-inline-start: var(--space-2)"
                >{{ reporting.next_due.status }}</StatusBadge>
            </p>
            <p v-else-if="!hasReportingInterval" class="gloss">
                This charter sets no reporting interval — reporting cadence is charter data, not a clock.
            </p>
            <p class="cluster" style="margin-block-start: var(--space-2)">
                <Link :href="reporting.reporting_href">Rules &amp; reports register →</Link>
            </p>
        </Card>

        <template #about>
            <p>
                A department's board is the same co-determination engine as any organization
                (Art. III §6): worker seats arrive through the uniform scale and the board is valid
                only while its composition matches that scale. The governors' 10-year terms run
                independently of the worker seats' legislative lockstep.
                <HardenedChip>two clocks · CLK-09 governors · CLK-10 worker seats</HardenedChip>
            </p>
        </template>
    </PageScaffold>
</template>
