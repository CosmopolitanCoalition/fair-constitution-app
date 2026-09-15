<script setup>import { useLocaleFormat } from '@/composables/useLocaleFormat';
const localeFmt = useLocaleFormat();
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
    return iso ? localeFmt.dateTime(new Date(iso)) : '—';
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

const tieBreakColumns = computed(() => [
    { key: 'context', label: t('c_legislature_workspace.speaker_tools.col_vote', 'Vote'), mono: true },
    { key: 'tally', label: t('c_legislature_workspace.speaker_tools.col_tied_at', 'Tied at'), mono: true },
    { key: 'cast', label: t('c_legislature_workspace.speaker_tools.col_speaker_cast', 'Speaker cast') },
    { key: 'outcome', label: t('c_legislature_workspace.speaker_tools.col_outcome', 'Outcome'), mono: true },
    { key: 'at', label: t('c_legislature_workspace.speaker_tools.col_when', 'When') },
]);
const tieBreakRows = computed(() =>
    props.tieBreaks.map((tb) => ({ ...tb, at: fmt(tb.at) })),
);

const priorityColumns = computed(() => [
    { key: 'who', label: t('c_legislature_workspace.speaker_tools.col_member', 'Member') },
    { key: 'text', label: t('c_legislature_workspace.speaker_tools.col_priority', 'Priority') },
    { key: 'session_no', label: t('c_legislature_workspace.speaker_tools.col_session', 'Session'), mono: true },
    { key: 'agenda_status', label: t('c_legislature_workspace.speaker_tools.col_status', 'Status') },
]);
</script>

<template>
    <PageScaffold :surface="surface" :title="t('c_legislature_workspace.speaker_tools.title', { name: legislature.name }, 'Speaker tools — {name}')">
        <LegislatureWorkspaceNav :workspace="workspace" active="speaker" />

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <Banner v-if="preview" tone="info" role="status" :title="t('c_legislature_workspace.speaker_tools.explore_title', 'Explore the Speaker’s role')">
            {{ t('c_legislature_workspace.speaker_tools.explore_body', 'Follow this office through the public chamber, live room and session records. Official actions use the current officeholder’s account.') }}
        </Banner>
        <Banner v-else-if="readOnly" tone="info" role="status" :title="t('c_legislature_workspace.speaker_tools.read_only_title', 'Read-only view')">
            {{ text('speaker_read_only') }}
        </Banner>

        <!-- ================================== neutrality =============== -->
        <Card as="section" :title="t('c_legislature_workspace.speaker_tools.neutral_chair', 'Neutral chair')">
            <p v-if="preview && workspace.hasSpeaker" class="cc-small">
                {{ t('c_legislature_workspace.speaker_tools.find_speaker', 'Find the current Speaker in the') }} <Link :href="workspace.chamber">{{ t('c_legislature_workspace.speaker_tools.chamber_roster', 'chamber roster') }}</Link>.
            </p>
            <p v-else class="cc-small">
                {{ t('c_legislature_workspace.speaker_tools.speaker_label', 'Speaker:') }} <strong>{{ speaker.name }}</strong>
                <StatusBadge v-if="speaker.is_viewer" tone="warning" icon="landmark">{{ t('c_legislature_workspace.speaker_tools.you', 'you') }}</StatusBadge>
            </p>
            <p>
                <HardenedChip>{{ t('c_legislature_workspace.speaker_tools.neutral_chip', 'politically neutral · votes only to break ties · Art. II §3') }}</HardenedChip>
            </p>
            <p class="gloss">
                {{ t('c_legislature_workspace.speaker_tools.neutral_gloss', 'The Speaker stays in every quorum and threshold denominator. On yes/no business a Speaker cast is rejected pre-commit unless the vote stands tied — and a tie-break never manufactures a supermajority (Art. VII).') }}
            </p>
        </Card>

        <section class="card" aria-labelledby="speaker-work-h">
            <h2 id="speaker-work-h">{{ t('c_legislature_workspace.speaker_tools.follow_work', 'Follow the Speaker’s work') }}</h2>
            <div class="stack">
                <Link :href="workspace.rooms">{{ t('c_legislature_workspace.speaker_tools.link_chamber', 'Enter the live chamber — recognize speakers and follow the speaking queue') }}</Link>
                <Link :href="urls.session">{{ t('c_legislature_workspace.speaker_tools.link_session', 'Open the session workspace — attendance, quorum, agenda and minutes') }}</Link>
                <Link :href="workspace.sessions">{{ t('c_legislature_workspace.speaker_tools.link_records', 'Browse session records — past agendas, votes and public statements') }}</Link>
                <Link :href="urls.committees">{{ t('c_legislature_workspace.speaker_tools.link_committees', 'Follow committee work — hearings, evidence and reports') }}</Link>
                <Link :href="urls.oversight">{{ t('c_legislature_workspace.speaker_tools.link_oversight', 'Open oversight — removal proceedings and presiding responsibilities') }}</Link>
            </div>
        </section>

        <div v-if="!preview" class="grid-2">
            <!-- ============================== tie-break record ========= -->
            <section class="card" aria-labelledby="tiebreak-h">
                <h2 id="tiebreak-h">
                    {{ t('c_legislature_workspace.speaker_tools.tiebreak_record', 'Tie-break record') }}
                    <StatusBadge tone="neutral">{{ t('c_legislature_workspace.speaker_tools.this_term', { n: tieBreaks.length }, '{n} this term') }}</StatusBadge>
                </h2>
                <p class="gloss">
                    {{ t('c_legislature_workspace.speaker_tools.tiebreak_gloss', 'The only Speaker votes on record — each cast via F-SPK-004 on a vote that closed tied, recomputed against the unchanged peg threshold.') }}
                </p>
                <DataTable
                    v-if="tieBreakRows.length"
                    :columns="tieBreakColumns"
                    :rows="tieBreakRows"
                    row-key="vote_id"
                    :caption="t('c_legislature_workspace.speaker_tools.tiebreak_caption', 'Speaker tie-breaking votes')"
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
                <p v-else class="cc-small gloss">{{ t('c_legislature_workspace.speaker_tools.no_tie', 'No tie has needed breaking this term.') }}</p>
            </section>

            <!-- ============================== presiding ================ -->
            <section class="card" aria-labelledby="presiding-h">
                <h2 id="presiding-h">{{ t('c_legislature_workspace.speaker_tools.removal_presiding', 'Removal presiding (F-SPK-007)') }}</h2>
                <p class="gloss">
                    {{ t('c_legislature_workspace.speaker_tools.presiding_gloss', 'The Speaker presides over every removal proceeding except their own case, where the chamber designates a substitute.') }}
                </p>
                <div v-if="pendingProceedings.length" class="stack" style="gap: var(--space-2)">
                    <Card v-for="proceeding in pendingProceedings" :key="proceeding.id" inset>
                        <p style="margin-block-end: var(--space-1)">
                            <strong>{{ proceeding.kind }}</strong> — {{ proceeding.subject }}
                            {{ ' ' }}
                            <StatusBadge tone="info">{{ proceeding.status }}</StatusBadge>
                        </p>
                        <Banner v-if="proceeding.presiding_blocked && speaker.is_viewer" tone="warning" role="status">
                            {{ t('c_legislature_workspace.speaker_tools.subject_blocked', 'You are the subject — the engine blocks you from presiding; the chamber designates a substitute · Art. II §3 (removal.presider, hardened).') }}
                        </Banner>
                        <p class="cc-small">
                            <a :href="urls.oversight">{{ t('c_legislature_workspace.speaker_tools.preside_oversight', 'Preside on the oversight page →') }}</a>
                        </p>
                    </Card>
                </div>
                <p v-else class="cc-small gloss">{{ t('c_legislature_workspace.speaker_tools.no_proceedings', 'No removal proceedings are pending.') }}</p>
            </section>
        </div>

        <!-- ================================== priorities queue ========= -->
        <Card v-if="!preview" as="section" :title="t('c_legislature_workspace.speaker_tools.priorities_title', 'Member priorities queue (F-SPK-006)')">
            <p class="gloss">
                {{ t('c_legislature_workspace.speaker_tools.priorities_gloss', 'Members hand the Speaker their priorities; facilitation appends each to the next session\'s unlocked agenda tail. The filing itself is the priorities log — slots 1–2 (emergency powers, constitutional matters) stay locked (Art. II §2).') }}
            </p>

            <DataTable
                v-if="priorities.length"
                :columns="priorityColumns"
                :rows="priorities"
                row-key="id"
                :caption="t('c_legislature_workspace.speaker_tools.priorities_caption', 'Facilitated member priorities')"
            />
            <p v-else class="cc-small gloss">{{ t('c_legislature_workspace.speaker_tools.no_priorities', 'No priorities facilitated yet.') }}</p>
            <nav v-if="priorityPages?.older || priorityPages?.newer" class="cluster" :aria-label="text('priority_pages')">
                <Link v-if="priorityPages.older" :href="priorityPages.older">{{ text('older') }}</Link>
                <Link v-if="priorityPages.newer" :href="priorityPages.newer">{{ text('newer') }}</Link>
            </nav>

            <template v-if="!readOnly">
                <FormCard
                    v-if="can.facilitate && formMeta('F-SPK-006')"
                    :form="formMeta('F-SPK-006')"
                    :inertia-form="priorityForm"
                    :submit-label="t('c_legislature_workspace.speaker_tools.add_agenda', 'Add to next agenda')"
                    @submit="submitPriority"
                >
                    <p class="cc-small" style="margin-block-end: var(--space-2)">
                        {{ t('c_legislature_workspace.speaker_tools.target_session', { n: prioritySession.session_no }, 'Target: session {n}') }}
                        <StatusBadge tone="info">{{ prioritySession.status }}</StatusBadge>
                    </p>
                    <Field :label="t('c_legislature_workspace.speaker_tools.field_member', 'Member')" :error="priorityForm.errors.member_id" required>
                        <template #control="{ id, invalid, describedBy }">
                            <select
                                :id="id"
                                v-model="priorityForm.member_id"
                                class="select"
                                :aria-invalid="invalid ? 'true' : undefined"
                                :aria-describedby="describedBy"
                            >
                                <option value="" disabled>{{ t('c_legislature_workspace.speaker_tools.choose_member', '— choose a member —') }}</option>
                                <option v-for="member in members" :key="member.id" :value="member.id">
                                    {{ member.name }}
                                </option>
                            </select>
                        </template>
                    </Field>
                    <Field
                        :label="t('c_legislature_workspace.speaker_tools.field_priority', 'Priority')"
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
                    {{ t('c_legislature_workspace.speaker_tools.facilitation_none', 'Facilitation targets an upcoming session — none is scheduled; call one on the') }}
                    <a :href="urls.session">{{ t('c_legislature_workspace.speaker_tools.session_console_link', 'session console') }}</a> (F-SPK-001).
                </p>
            </template>
        </Card>

        <template #about>
            <p>
                {{ t('c_legislature_workspace.speaker_tools.about', 'This page never duplicates a console: F-SPK-001/002/003/008/009 run on the session console, F-SPK-005 on the committees page, F-SPK-007 on oversight. The tie-break record and priorities queue live here because they are records of the office itself.') }}
            </p>
        </template>
    </PageScaffold>
</template>
