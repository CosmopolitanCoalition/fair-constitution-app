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
 *   • BoardStrip FULL — the two-clock roster (appointed CLK-09 terms beside
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
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
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

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });

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
const { t } = useI18n();
const text = (key, fallback) => t('c_references.department.' + key, fallback);
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

const KIND_LABELS = computed(() => ({
    chief_executive: text('kind_chief_executive', 'Chief Executive'),
    treasury: text('kind_treasury', 'Treasury'),
    defense: text('kind_defense', 'Defense'),
    state: text('kind_state', 'State'),
    justice: text('kind_justice', 'Justice'),
    other: text('kind_other', 'Custom'),
}));
const kindLabel = computed(() => KIND_LABELS.value[props.department.kind] ?? props.department.kind);

/* Header counts — engine seat figures (owner side vs worker-elected). */
const governorCount = computed(() => (props.board?.owner_seats ?? 0));
const workerSeatCount = computed(() => (props.board?.worker_seats ?? 0));

/* ESM-17 display status badge. */
const STATUS_TONES = computed(() => ({
    operating: ['success', 'check', text('status_operating', 'Operating')],
    reporting: ['info', 'bar-chart', text('status_reporting', 'Reporting')],
    chartered: ['info', 'file-text', text('status_chartered', 'Chartered')],
    oversight_assigned: ['info', 'shield', text('status_oversight_assigned', 'Oversight assigned')],
    governors_nominated: ['info', 'clock', text('status_governors_nominated', 'Governors nominated')],
    consented: ['info', 'check', text('status_consented', 'Consented')],
    removal_requested: ['warning', 'alert-triangle', text('status_removal_requested', 'Removal requested')],
    rechartered: ['neutral', 'refresh-cw', text('status_rechartered', 'Re-chartered')],
    dissolved: ['neutral', 'minus', text('status_dissolved', 'Dissolved')],
}));
const statusBadge = computed(() => {
    const [tone, icon, txt] = STATUS_TONES.value[props.department.status] ?? ['neutral', null, props.department.status];
    return { tone, icon, text: txt };
});

/* Per-nomination card status badge. */
const NOM_TONES = computed(() => ({
    nominated: ['info', 'clock', text('nom_nominated', 'Nominated · consent pending')],
    consented: ['success', 'check', text('nom_consented', 'Consented')],
    seated: ['success', 'check', text('nom_seated', 'Seated')],
    rejected: ['danger', 'x', text('nom_rejected', 'Consent failed · nomination reopened')],
    ended: ['neutral', 'minus', text('nom_ended', 'Ended')],
}));
function nomBadge(status) {
    const [tone, icon, txt] = NOM_TONES.value[status] ?? ['neutral', null, status];
    return { tone, icon, text: txt };
}

/* Removal outcome badge. */
const REMOVAL_TONES = computed(() => ({
    pending: ['warning', 'clock', text('removal_pending', 'Removal vote open')],
    removed: ['danger', 'x', text('removal_removed', 'Removed by majority')],
    retained: ['success', 'check', text('removal_retained', 'Retained')],
}));
function removalBadge(outcome) {
    const [tone, icon, txt] = REMOVAL_TONES.value[outcome] ?? ['neutral', null, outcome];
    return { tone, icon, text: txt };
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
            {{ text('intro', 'Manage this department’s board, review nominations, and follow its reports. The roster shows each member’s current term dates.') }}
        </template>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <!-- =============================================== header ======== -->
        <Card as="section" :title="department.name">
            <p class="cluster" style="gap: var(--space-2)">
                <TagChip data-no-i18n>{{ kindLabel }}</TagChip>
                <StatusBadge :tone="statusBadge.tone" :icon="statusBadge.icon">{{ statusBadge.text }}</StatusBadge>
                <StatusBadge tone="info" icon="users">{{ t('c_references.department.workers', { count: department.worker_count }) }}</StatusBadge>
                <StatusBadge v-if="board" tone="neutral" icon="landmark">
                    {{ t('c_references.department.gov_worker_line', { gov: governorCount, worker: workerSeatCount }) }}
                </StatusBadge>
            </p>
            <p v-if="machine.length" style="margin-block-start: var(--space-3)">
                <StateStrip :states="machine" :current="department.status" />
            </p>
            <p class="cluster" style="gap: var(--space-2); margin-block-start: var(--space-2)">
                <Link :href="department.executive.href">{{ department.executive.name }} →</Link>
                <Link :href="`${department.executive.href}/departments`">{{ text('all_departments', 'All departments →') }}</Link>
            </p>
        </Card>

        <!-- ====================================== charter & oversight ==== -->
        <Card as="section" :title="text('charter_oversight', 'Charter & oversight')">
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
                {{ text('overseen_by', 'Overseen by') }} <Link :href="department.executive.href">{{ department.executive.name }}</Link>.
            </p>
            <p class="citation">
                {{ text('investigative_power', 'full and equal investigative power · Art. III §4 ·') }}
                <Link :href="`${department.executive.href}/actions`">{{ text('executive_actions', 'executive actions →') }}</Link>
            </p>
            <hr style="border: 0; border-block-start: 1px solid var(--gov-border); margin-block: var(--space-3)" />

            <!-- oversees CGCs -->
            <template v-if="department.oversees_cgcs.length">
                <p>
                    {{ text('oversees', 'Oversees:') }}
                    <template v-for="(cgc, i) in department.oversees_cgcs" :key="cgc.name">
                        <template v-if="i > 0"> · </template><Link :href="cgc.href">{{ cgc.name }}</Link>
                    </template>
                </p>
                <p class="citation">{{ text('cgc_public_domain', 'CGC intellectual property is perpetually public domain · Art. III §5') }}</p>
            </template>
            <p v-else class="gloss">
                {{ text('no_cgcs', 'No Common Good Corporations overseen by this department.') }}
            </p>
        </Card>

        <!-- ============================================ board roster ===== -->
        <Card as="section" :title="text('board_of_governors', 'Board of Governors')">
            <template v-if="board">
                <BoardStrip
                    :seats="board.seats"
                    :composition-valid="board.compositionValid"
                    :required-worker-seats="board.requiredWorkerSeats"
                />
                <p class="citation" style="margin-block-start: var(--space-2)">
                    {{ text('terms', 'Governors serve civil appointments. Worker-elected members serve until the legislative term ends. The entire board elects its chair.') }}
                </p>
            </template>
            <Banner v-else tone="info" role="status" :title="text('board_not_formed_title', 'No board constituted yet.')">
                {{ text('board_not_formed', 'The department charter creates the board and its governor seats. Governors take their seats after nomination and legislative consent.') }}
            </Banner>
        </Card>

        <!-- ====================================== nomination pipeline ==== -->
        <Card as="section" :title="text('nomination_pipeline', 'Nomination pipeline')">
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
                        <p class="eyebrow">{{ text('consent_vote_eyebrow', 'Consent vote · majority of all serving') }}</p>
                        <VoteTally v-bind="nom.consent_vote.tally" basis="Art. III §4 · peg-quorum majority" />
                        <details v-if="nom.consent_vote.casts.length" style="margin-block-start: var(--space-2)">
                            <summary class="citation" style="cursor: pointer">{{ text('published_positions', 'Published positions →') }}</summary>
                            <div style="margin-block-start: var(--space-2)">
                                <VoteCastList :casts="nom.consent_vote.casts" />
                            </div>
                        </details>
                    </div>

                    <!-- seated term dates from the governing appointment -->
                    <p v-if="nom.term" class="citation" style="margin-block-start: var(--space-2)" data-no-i18n>
                        {{ text('term', 'Term') }} {{ fmtDate(nom.term.starts_on) }} → {{ fmtDate(nom.term.ends_on) }}
                    </p>
                    <p v-if="nom.status === 'rejected'" class="citation" style="margin-block-start: var(--space-2)">
                        {{ text('nomination_reopened', 'Consent failed. The seat is open for a new nomination.') }}
                    </p>
                </div>
            </template>
            <p v-else class="gloss">
                {{ text('no_nominations', 'No nominations on record. A seated member of the overseeing executive opens one below.') }}
            </p>
        </Card>

        <!-- =========================== F-EXE-001 dossier form =========== -->
        <FormCard
            v-if="can.nominate"
            :form="surface.forms.find((f) => f.id === 'F-EXE-001')"
            :inertia-form="nomination"
            :submit-label="text('nominate_submit', 'File nomination dossier')"
            :processing-label="text('filing', 'Filing…')"
            @submit="submitNomination"
        >
            <Field
                :label="text('nominee_label', 'Nominee')"
                :hint="text('nominee_hint', 'The nominee\'s user id. Eligibility is active jurisdiction association only — neutrality is a duty of office, not an eligibility test (Art. I).')"
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
                :label="text('dossier_label', 'Dossier')"
                :hint="text('dossier_hint', 'Credentials and the neutrality attestation — published at nomination.')"
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
                {{ text('nominate_cite', 'Opens a consent vote in the legislature, requiring a majority of all serving members · Art. III §4.') }}
            </p>
        </FormCard>

        <!-- ============================================ removals ========= -->
        <Card as="section" :title="text('removal_requests', 'Removal requests')">
            <p class="gloss" style="margin-block-end: var(--space-3)">
                {{ text('removal_intro_before', 'Removing a governor requires an') }} <strong>{{ text('removal_intro_strong', 'ordinary majority of all serving members') }}</strong>{{ text('removal_intro_after', '. Requests and their voting records appear below.') }}
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
                        <p class="eyebrow">{{ text('removal_vote_eyebrow', 'Removal vote · majority of all serving') }}</p>
                        <VoteTally v-bind="rem.vote.tally" basis="Art. III §4 · ordinary majority of all serving members" />
                        <details v-if="rem.vote.casts.length" style="margin-block-start: var(--space-2)">
                            <summary class="citation" style="cursor: pointer">{{ text('published_positions', 'Published positions →') }}</summary>
                            <div style="margin-block-start: var(--space-2)">
                                <VoteCastList :casts="rem.vote.casts" />
                            </div>
                        </details>
                    </div>
                </div>
            </template>
            <p v-else class="gloss">{{ text('no_removals', 'No removal requests on record.') }}</p>
        </Card>

        <!-- =========================== F-EXE-003 removal form =========== -->
        <FormCard
            v-if="can.requestRemoval"
            :form="surface.forms.find((f) => f.id === 'F-EXE-003')"
            :inertia-form="removal"
            :submit-label="text('removal_submit', 'Request removal')"
            :processing-label="text('filing', 'Filing…')"
            :disabled="!removableSeats.length"
            @submit="submitRemoval"
        >
            <Field
                :label="text('board_member_label', 'Board member')"
                :hint="text('board_member_hint', 'Removal runs against a currently seated board member.')"
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
                        <option value="" disabled>{{ text('select_seat', 'Select a seated member…') }}</option>
                        <option v-for="seat in removableSeats" :key="seat.id" :value="seat.id">
                            {{ seat.holder?.name ?? text('seat_fallback', 'Seat') }} — {{ seat.seat_class.replaceAll('_', ' ') }}
                        </option>
                    </select>
                </template>
            </Field>

            <Field
                :label="text('grounds_label', 'Grounds')"
                :hint="text('grounds_hint', 'A good-faith competence/ethics finding — published at filing.')"
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
                {{ text('removal_cite', 'Opens a chamber vote requiring an ordinary majority of all serving members · Art. III §4.') }}
            </p>
            <Banner
                v-if="!removableSeats.length"
                tone="info"
                role="status"
                :title="text('no_seated_title', 'No seated members to remove.')"
                style="margin-block-start: var(--space-2)"
            >
                {{ text('no_seated_body', 'There is no currently seated board member to file a removal against.') }}
            </Banner>
        </FormCard>

        <!-- ============================================ reporting ======== -->
        <Card v-if="reporting" as="section" :title="text('reporting_title', 'Reporting')">
            <p v-if="reporting.last_filed">
                {{ t('c_references.department.last_filed', { kind: reporting.last_filed.kind, date: fmtDate(reporting.last_filed.at) }) }}
                <Link v-if="reporting.last_filed.record_href" :href="reporting.last_filed.record_href">{{ text('on_public_record', 'on the public record →') }}</Link>
            </p>
            <p v-if="reporting.next_due" class="cc-small">
                {{ t('c_references.department.next_due', { date: fmtDate(reporting.next_due.on) }) }}
                <StatusBadge
                    :tone="reporting.next_due.status === 'overdue' ? 'danger' : reporting.next_due.status === 'due_soon' ? 'warning' : 'info'"
                    style="margin-inline-start: var(--space-2)"
                >{{ reporting.next_due.status }}</StatusBadge>
            </p>
            <p v-else-if="!hasReportingInterval" class="gloss">
                {{ text('no_reporting_interval', 'This charter sets no reporting interval — reporting cadence is charter data, not a clock.') }}
            </p>
            <p class="cluster" style="margin-block-start: var(--space-2)">
                <Link :href="reporting.reporting_href">{{ text('reports_register', 'Rules & reports register →') }}</Link>
            </p>
        </Card>

        <template #about>
            <p>
                {{ text('board_explained', 'Workers gain board representation as the workforce grows, under the same rules used by other organizations. The board’s membership must match that scale. Appointed governors and worker-elected members follow their respective term schedules.') }}
                <HardenedChip>{{ text('term_schedules', 'Appointed and worker-elected members have different term schedules') }}</HardenedChip>
            </p>
        </template>
    </PageScaffold>
</template>
