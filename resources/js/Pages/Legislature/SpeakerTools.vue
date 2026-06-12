<script setup>
/**
 * Legislature/SpeakerTools — FE-C7 (PHASE_C_DESIGN_frontend.md §B.7).
 *
 * The Speaker's launchpad, not a duplicate console: the 9 F-SPK cards
 * rendered from the registry (name first, ID second), each linking to
 * the surface where the live control lives. Neutrality card (hardened),
 * tie-break record (F-SPK-004), member-priorities queue (F-SPK-006),
 * and the presiding card with the own-case block surfaced.
 *
 * R-10 sees the live variant; R-09 the read-only "what the Speaker can
 * do" variant — actions hidden, and the engine rejects them regardless.
 */
import { computed } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import FormCard from '@/Components/Surface/FormCard.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import Field from '@/Components/Ui/Field.vue';
import FormChip from '@/Components/Ui/FormChip.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

const props = defineProps({
    surface: { type: Object, required: true },
    legislature: { type: Object, required: true },
    speaker: { type: Object, required: true },
    readOnly: { type: Boolean, default: true },
    forms: { type: Array, default: () => [] },
    tieBreaks: { type: Array, default: () => [] },
    priorities: { type: Array, default: () => [] },
    prioritySession: { type: Object, default: null },
    members: { type: Array, default: () => [] },
    pendingProceedings: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
    urls: { type: Object, required: true },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

const formMeta = (id) => props.surface.forms.find((f) => f.id === id);

function fmt(iso) {
    return iso ? new Date(iso).toLocaleString() : '—';
}

const SURFACE_LABELS = {
    session: 'Session console',
    committees: 'Committees',
    oversight: 'Oversight',
    speaker: 'this page — the priorities queue below',
};

/* ----------------------------------------------- priorities (F-SPK-006) */
const priorityForm = useForm({ session_id: '', member_id: '', text: '' });

function submitPriority() {
    priorityForm.session_id = props.prioritySession?.id ?? '';
    priorityForm.post(props.urls.priorities, {
        preserveScroll: true,
        onSuccess: () => priorityForm.reset(),
    });
}

const tieBreakColumns = [
    { key: 'context', label: 'Vote', mono: true },
    { key: 'tally', label: 'Tied at', mono: true },
    { key: 'cast', label: 'Speaker cast' },
    { key: 'outcome', label: 'Outcome', mono: true },
    { key: 'at', label: 'When' },
];
const tieBreakRows = computed(() =>
    props.tieBreaks.map((tb) => ({ ...tb, at: fmt(tb.at) })),
);

const priorityColumns = [
    { key: 'who', label: 'Member' },
    { key: 'text', label: 'Priority' },
    { key: 'session_no', label: 'Session', mono: true },
    { key: 'agenda_status', label: 'Status' },
];
</script>

<template>
    <PageScaffold :surface="surface" :title="`Speaker tools — ${legislature.name}`">
        <template #intro>
            The Speaker facilitates and stays politically neutral: they remain a serving member in
            every denominator, vote only to break ties, and preside over removal proceedings —
            never their own. This page is the launchpad for the nine Speaker forms; each control
            lives on its working surface.
        </template>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <Banner v-if="readOnly" tone="info" role="status" title="Read-only view">
            You hold a seat in this chamber (R-09) but are not its Speaker — this is the "what the
            Speaker can do" view. Actions are hidden here, and the engine rejects them regardless
            (the role gate is the handler's, never the page's).
        </Banner>

        <!-- ================================== neutrality =============== -->
        <Card as="section" title="Neutral chair">
            <p class="cc-small">
                Speaker: <strong>{{ speaker.name }}</strong>
                <StatusBadge v-if="speaker.is_viewer" tone="warning" icon="landmark">you</StatusBadge>
            </p>
            <p>
                <HardenedChip>politically neutral · votes only to break ties · Art. II §3</HardenedChip>
            </p>
            <p class="gloss">
                The Speaker stays in every quorum and threshold denominator. On yes/no business a
                Speaker cast is rejected pre-commit unless the vote stands tied — and a tie-break
                never manufactures a supermajority (Art. VII).
            </p>
        </Card>

        <!-- ================================== the 9 F-SPK cards ======== -->
        <Card as="section" title="The Speaker's nine forms">
            <div class="grid-2">
                <Card v-for="form in forms" :key="form.id" inset>
                    <p style="margin-block-end: var(--space-1)">
                        <strong>{{ form.name }}</strong>
                        {{ ' ' }}
                        <FormChip :form-id="form.id" :alias="form.alias" />
                    </p>
                    <p class="citation" style="margin-block-end: var(--space-1)">
                        available to {{ (form.availableTo?.length ? form.availableTo : ['R-10']).join(', ') }}
                        <template v-if="form.citation"> · {{ form.citation }}</template>
                    </p>
                    <p class="cc-small">
                        <a v-if="form.surface_href" :href="form.surface_href">
                            Go to {{ SURFACE_LABELS[form.surface] ?? form.surface }} →
                        </a>
                        <span v-else class="gloss">{{ SURFACE_LABELS[form.surface] }}</span>
                    </p>
                </Card>
            </div>
        </Card>

        <div class="grid-2">
            <!-- ============================== tie-break record ========= -->
            <section class="card" aria-labelledby="tiebreak-h">
                <h2 id="tiebreak-h">
                    Tie-break record
                    <StatusBadge tone="neutral">{{ tieBreaks.length }} this term</StatusBadge>
                </h2>
                <p class="gloss">
                    The only Speaker votes on record — each cast via F-SPK-004 on a vote that
                    closed tied, recomputed against the unchanged peg threshold.
                </p>
                <DataTable
                    v-if="tieBreakRows.length"
                    :columns="tieBreakColumns"
                    :rows="tieBreakRows"
                    row-key="vote_id"
                    caption="Speaker tie-breaking votes"
                >
                    <template #cell-cast="{ row }">
                        <StatusBadge tone="warning" icon="landmark">{{ row.cast }} · F-SPK-004</StatusBadge>
                    </template>
                </DataTable>
                <p v-else class="cc-small gloss">No tie has needed breaking this term.</p>
            </section>

            <!-- ============================== presiding ================ -->
            <section class="card" aria-labelledby="presiding-h">
                <h2 id="presiding-h">Removal presiding (F-SPK-007)</h2>
                <p class="gloss">
                    The Speaker presides over every removal proceeding except their own case,
                    where the chamber designates a substitute.
                </p>
                <div v-if="pendingProceedings.length" class="stack" style="gap: var(--space-2)">
                    <Card v-for="proceeding in pendingProceedings" :key="proceeding.id" inset>
                        <p style="margin-block-end: var(--space-1)">
                            <strong>{{ proceeding.kind }}</strong> — {{ proceeding.subject }}
                            {{ ' ' }}
                            <StatusBadge tone="info">{{ proceeding.status }}</StatusBadge>
                        </p>
                        <Banner v-if="proceeding.presiding_blocked && speaker.is_viewer" tone="warning" role="status">
                            You are the subject — the engine blocks you from presiding; the chamber
                            designates a substitute · Art. II §3 (removal.presider, hardened).
                        </Banner>
                        <p class="cc-small">
                            <a :href="urls.oversight">Preside on the oversight page →</a>
                        </p>
                    </Card>
                </div>
                <p v-else class="cc-small gloss">No removal proceedings are pending.</p>
            </section>
        </div>

        <!-- ================================== priorities queue ========= -->
        <Card as="section" title="Member priorities queue (F-SPK-006)">
            <p class="gloss">
                Members hand the Speaker their priorities; facilitation appends each to the next
                session's unlocked agenda tail. The filing itself is the priorities log — slots
                1–2 (emergency powers, constitutional matters) stay locked (Art. II §2).
            </p>

            <DataTable
                v-if="priorities.length"
                :columns="priorityColumns"
                :rows="priorities"
                row-key="id"
                caption="Facilitated member priorities"
            />
            <p v-else class="cc-small gloss">No priorities facilitated yet.</p>

            <template v-if="!readOnly">
                <FormCard
                    v-if="can.facilitate && formMeta('F-SPK-006')"
                    :form="formMeta('F-SPK-006')"
                    :inertia-form="priorityForm"
                    submit-label="Add to next agenda"
                    @submit="submitPriority"
                >
                    <p class="cc-small" style="margin-block-end: var(--space-2)">
                        Target: session {{ prioritySession.session_no }}
                        <StatusBadge tone="info">{{ prioritySession.status }}</StatusBadge>
                    </p>
                    <Field label="Member" :error="priorityForm.errors.member_id" required>
                        <template #control="{ id, invalid, describedBy }">
                            <select
                                :id="id"
                                v-model="priorityForm.member_id"
                                class="select"
                                :aria-invalid="invalid ? 'true' : undefined"
                                :aria-describedby="describedBy"
                            >
                                <option value="" disabled>— choose a member —</option>
                                <option v-for="member in members" :key="member.id" :value="member.id">
                                    {{ member.name }}
                                </option>
                            </select>
                        </template>
                    </Field>
                    <Field
                        label="Priority"
                        :error="priorityForm.errors.text ?? priorityForm.errors.constitution"
                        required
                    >
                        <template #control="{ id, invalid, describedBy }">
                            <textarea
                                :id="id"
                                v-model="priorityForm.text"
                                class="field-input"
                                rows="2"
                                :aria-invalid="invalid ? 'true' : undefined"
                                :aria-describedby="describedBy"
                            ></textarea>
                        </template>
                    </Field>
                </FormCard>
                <p v-else class="citation">
                    Facilitation targets an upcoming session — none is scheduled; call one on the
                    <a :href="urls.session">session console</a> (F-SPK-001).
                </p>
            </template>
        </Card>

        <template #about>
            <p>
                This page never duplicates a console: F-SPK-001/002/003/008/009 run on the
                session console, F-SPK-005 on the committees page, F-SPK-007 on oversight. The
                tie-break record and priorities queue live here because they are records of the
                office itself.
            </p>
        </template>
    </PageScaffold>
</template>
