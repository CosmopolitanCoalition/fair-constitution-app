<script setup>
/**
 * Legislature/SpeakerTools — FE-C7 (PHASE_C_DESIGN_frontend.md §B.7).
 *
 * Office records and the member-priorities queue, within the selected
 * legislature workspace. Session/committee/oversight controls retain
 * their own destinations; form references remain in the shared disclosure.
 *
 * R-10 sees the live variant; R-09 the read-only "what the Speaker can
 * do" variant — actions hidden, and the engine rejects them regardless.
 */
import { computed } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import LegislatureWorkspaceNav from '@/Components/Legislature/LegislatureWorkspaceNav.vue';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import FormCard from '@/Components/Surface/FormCard.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import Field from '@/Components/Ui/Field.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    workspace: { type: Object, required: true },
    legislature: { type: Object, required: true },
    speaker: { type: Object, required: true },
    readOnly: { type: Boolean, default: true },
    preview: { type: Boolean, default: false },
    tieBreaks: { type: Array, default: () => [] },
    priorities: { type: Array, default: () => [] },
    priorityPages: { type: Object, default: null },
    prioritySession: { type: Object, default: null },
    members: { type: Array, default: () => [] },
    pendingProceedings: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
    urls: { type: Object, required: true },
});

const page = usePage();
const { t } = useI18n();
const text = key => t('c_legislature_workspace.' + key);
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

const formMeta = (id) => props.surface.forms.find((f) => f.id === id);

function fmt(iso) {
    return iso ? new Date(iso).toLocaleString() : '—';
}

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
        <LegislatureWorkspaceNav :workspace="workspace" active="speaker" />

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <Banner v-if="preview" tone="info" role="status" title="Explore the Speaker’s role">
            Follow this office through the public chamber, live room and session records. Official actions use the current officeholder’s account.
        </Banner>
        <Banner v-else-if="readOnly" tone="info" role="status" title="Read-only view">
            {{ text('speaker_read_only') }}
        </Banner>

        <!-- ================================== neutrality =============== -->
        <Card as="section" title="Neutral chair">
            <p v-if="preview && workspace.hasSpeaker" class="cc-small">
                Find the current Speaker in the <Link :href="workspace.chamber">chamber roster</Link>.
            </p>
            <p v-else class="cc-small">
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

        <section class="card" aria-labelledby="speaker-work-h">
            <h2 id="speaker-work-h">Follow the Speaker’s work</h2>
            <div class="stack">
                <Link :href="workspace.rooms">Enter the live chamber — recognize speakers and follow the speaking queue</Link>
                <Link :href="urls.session">Open the session workspace — attendance, quorum, agenda and minutes</Link>
                <Link :href="workspace.sessions">Browse session records — past agendas, votes and public statements</Link>
                <Link :href="urls.committees">Follow committee work — hearings, evidence and reports</Link>
                <Link :href="urls.oversight">Open oversight — removal proceedings and presiding responsibilities</Link>
            </div>
        </section>

        <div v-if="!preview" class="grid-2">
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
                    <template #cell-context="{ row }">
                        <Link v-if="row.vote_href" :href="row.vote_href">{{ row.context }}</Link>
                        <span v-else>{{ row.context }}</span>
                        <p v-if="row.explanation" class="gloss" data-no-i18n>{{ row.explanation }}</p>
                    </template>
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
        <Card v-if="!preview" as="section" title="Member priorities queue (F-SPK-006)">
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
            <nav v-if="priorityPages?.older || priorityPages?.newer" class="cluster" :aria-label="text('priority_pages')">
                <Link v-if="priorityPages.older" :href="priorityPages.older">{{ text('older') }}</Link>
                <Link v-if="priorityPages.newer" :href="priorityPages.newer">{{ text('newer') }}</Link>
            </nav>

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
