<script setup>
/**
 * Legislature/Oversight — FE-C8 (PHASE_C_DESIGN_frontend.md §B.8).
 *
 * Misconduct intake (any resident — audited non-form action, registry
 * gap) → investigation docket → removal proceeding with the F-LEG-022
 * supermajority VoteTally ("needs ceil(serving × 2/3) of serving —
 * vacancies stay in the denominator") → F-LEG-036 vacancy declaration
 * closing the loop into the Phase B /vacancies/{id} countback page.
 * This page replaces Phase B's `vacancy:declare` dev command.
 */
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import FormCard from '@/Components/Surface/FormCard.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import Field from '@/Components/Ui/Field.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';
import RadioGroup from '@/Components/Ui/RadioGroup.vue';
import StateStrip from '@/Components/Ui/StateStrip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import VoteTally from '@/Components/Legislature/VoteTally.vue';
import ConsentVoteCard from '@/Components/Legislature/ConsentVoteCard.vue';
import VoteCastList from '@/Components/Legislature/VoteCastList.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });
const { t } = useI18n();

const props = defineProps({
    surface: { type: Object, required: true },
    legislature: { type: Object, required: true },
    adminOffice: { type: Object, default: null },
    investigations: { type: Array, default: () => [] },
    proceedings: { type: Array, default: () => [] },
    vacancies: { type: Array, default: () => [] },
    vacancyMachine: { type: Array, default: () => [] },
    members: { type: Array, default: () => [] },
    viewerMemberId: { type: String, default: null },
    can: { type: Object, default: () => ({}) },
    urls: { type: Object, required: true },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

/* A1 gallery (§10 A1): oversight is a government proceeding, public to
 * watch. A spectator sees the same read-only page — every action control is
 * can.*-gated and resolves false for them — with a "you are watching" note. */
const isGallery = computed(() => props.can?.isGallery ?? false);

const formMeta = (id) => props.surface.forms.find((f) => f.id === id);

const bicameral = computed(() => props.legislature.mode === 'bicameral');

const INVESTIGATION_TONES = {
    intake: 'info',
    investigating: 'warning',
    referred: 'danger',
    closed_no_finding: 'neutral',
};

/* ------------------------------------------------ office (F-LEG-013) -- */
const officeForm = useForm({ nominees_text: '' });
function submitOffice() {
    officeForm
        .transform((data) => ({
            form_id: 'F-LEG-013',
            nominees: data.nominees_text.split('\n').map((s) => s.trim()).filter(Boolean),
        }))
        .post(props.urls.createOffice, { preserveScroll: true });
}

/* ------------------------------------------------ intake (non-form) --- */
const intakeForm = useForm({ subject_member_id: '', summary: '' });
function submitIntake() {
    intakeForm.post(props.urls.intake, {
        preserveScroll: true,
        onSuccess: () => intakeForm.reset(),
    });
}

/* ------------------------------------------ investigation actions ----- */
const advancing = ref(null);
function advance(investigation) {
    advancing.value = investigation.id;
    router.post(investigation.urls.refer, { action: 'investigate' }, {
        preserveScroll: true,
        onFinish: () => {
            advancing.value = null;
        },
    });
}

const referTarget = ref(null);
const referForm = useForm({ findings: '', refer: true, kind: 'impeachment', action: 'findings' });
function submitRefer(investigation) {
    referForm.post(investigation.urls.refer, {
        preserveScroll: true,
        onSuccess: () => {
            referForm.reset();
            referTarget.value = null;
        },
    });
}

/* ------------------------------------------ proceedings (F-SPK-007) --- */
const proceedingForm = useForm({ kind: 'impeachment', subject_member_id: '' });
function submitProceeding() {
    proceedingForm.post(props.urls.proceedings, {
        preserveScroll: true,
        onSuccess: () => proceedingForm.reset(),
    });
}

const designateForms = ref({}); // proceeding id → member id
const designating = ref(null);
function designate(proceeding) {
    designating.value = proceeding.id;
    router.post(proceeding.urls.lifecycle, {
        action: 'designate',
        proceeding_id: proceeding.id,
        presider_member_id: designateForms.value[proceeding.id],
    }, {
        preserveScroll: true,
        onFinish: () => {
            designating.value = null;
        },
    });
}

const openingVote = ref(null);
function openVote(proceeding) {
    openingVote.value = proceeding.id;
    router.post(proceeding.urls.lifecycle, { action: 'open_vote', proceeding_id: proceeding.id }, {
        preserveScroll: true,
        onFinish: () => {
            openingVote.value = null;
        },
    });
}

/* ------------------------------------------------- casts (F-LEG-022) -- */
const casting = ref(null);
function castRemoval(proceeding, { value, explanation }) {
    casting.value = proceeding.id;
    router.post(proceeding.urls.lifecycle, {
        action: 'cast',
        proceeding_id: proceeding.id,
        value,
        explanation,
    }, {
        preserveScroll: true,
        onFinish: () => {
            casting.value = null;
        },
    });
}

/* -------------------------------------------------- vacancy (F-LEG-036) */
const vacancyForm = useForm({ member_id: '', reason: 'resigned' });
function submitVacancy() {
    vacancyForm.post(props.urls.vacancies, {
        preserveScroll: true,
        onSuccess: () => vacancyForm.reset(),
    });
}

const REASON_OPTIONS = [
    { value: 'resigned', label: t('c_legislature_pages_b.oversight.reason_resigned', 'Resigned') },
    { value: 'deceased', label: t('c_legislature_pages_b.oversight.reason_deceased', 'Deceased') },
    { value: 'removed', label: t('c_legislature_pages_b.oversight.reason_removed', 'Removed') },
    { value: 'relocation', label: t('c_legislature_pages_b.oversight.reason_relocation', 'Relocation') },
    { value: 'other', label: t('c_legislature_pages_b.oversight.reason_other', 'Other') },
];

const PROCEEDING_MACHINE = ['opened', 'presiding_designated', 'voted', 'closed'];
</script>

<template>
    <PageScaffold :surface="surface" :title="t('c_legislature_pages_b.oversight.page_title', { name: legislature.name })">
        <template #intro>
            {{ t('c_legislature_pages_b.oversight.intro', 'The independent administrative office enforces parliamentary procedure and the ethics code. It takes misconduct complaints, investigates, and refers findings to removal proceedings — and it declares vacancies when seats fall empty.') }}
        </template>

        <Banner v-if="isGallery" tone="neutral" role="status">
            {{ t('c_legislature_pages_b.oversight.gallery', 'You are watching. Oversight is public — it\'s government (Art. II §2). Complaints, investigations, and removal votes are run by the chamber and its administrative office; findings publish to the public record.') }}
        </Banner>
        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <!-- ================================== I-ADM ==================== -->
        <Card as="section" :title="t('c_legislature_pages_b.oversight.adm_title', 'Administrative office (I-ADM)')">
            <template v-if="adminOffice">
                <p class="cc-small">
                    <StatusBadge :tone="adminOffice.status === 'staffed' ? 'success' : 'info'">{{ adminOffice.status }}</StatusBadge>
                    {{ ' ' }}
                    <HardenedChip>{{ t('c_legislature_pages_b.oversight.adm_hardened', 'independent · neutral record-keeper · Art. II §2') }}</HardenedChip>
                </p>

                <p v-if="adminOffice.staff?.length" class="cc-small">
                    {{ t('c_legislature_pages_b.oversight.staff_label', 'Staff:') }}
                    <template v-for="(person, pi) in adminOffice.staff" :key="pi">
                        <template v-if="pi > 0"> · </template>
                        {{ person.name }} <span class="citation">{{ t('c_legislature_pages_b.oversight.staff_term', { ends: person.term_ends }) }}</span>
                    </template>
                </p>

                <!-- creation act vote (pending office) -->
                <template v-if="adminOffice.pending">
                    <h3 style="font-size: var(--text-base)">{{ t('c_legislature_pages_b.oversight.creation_vote_heading', 'Creation act vote — majority of all serving') }}</h3>
                    <ConsentVoteCard :consent="adminOffice.pending" :can-cast="can.vote" />
                </template>

                <!-- staffing consents -->
                <template v-if="adminOffice.consents?.length">
                    <h3 style="font-size: var(--text-base); margin-block-start: var(--space-3)">
                        {{ t('c_legislature_pages_b.oversight.staffing_consents_heading', 'Staffing consents (appointment pipeline · majority)') }}
                    </h3>
                    <div class="stack" style="gap: var(--space-3)">
                        <Card v-for="consent in adminOffice.consents" :key="consent.cast_url" inset>
                            <p style="margin-block-end: var(--space-1)"><strong>{{ consent.nominee }}</strong> {{ t('c_legislature_pages_b.oversight.consent_vote_suffix', '— consent vote') }}</p>
                            <ConsentVoteCard :consent="consent" :can-cast="can.vote" />
                        </Card>
                    </div>
                </template>
            </template>

            <template v-else>
                <p class="gloss">
                    {{ t('c_legislature_pages_b.oversight.no_office', 'No administrative office exists — intake cannot docket until the chamber creates one by majority act.') }}
                </p>
                <FormCard
                    v-if="can.createOffice"
                    :form="{ id: 'F-LEG-013', name: t('c_legislature_pages_b.oversight.office_form_name', 'Administrative Office Creation Act'), availableTo: ['R-09'], citation: t('c_legislature_pages_b.oversight.office_form_citation', 'Art. II §2 — ordinary majority of all serving') }"
                    :inertia-form="officeForm"
                    :submit-label="t('c_legislature_pages_b.oversight.file_creation', 'File creation act')"
                    @submit="submitOffice"
                >
                    <Field
                        :label="t('c_legislature_pages_b.oversight.nominee_label', 'Staff nominee user IDs (one per line, optional)')"
                        :hint="t('c_legislature_pages_b.oversight.nominee_hint', 'Nominees follow the appointment-consent pipeline — one majority consent vote each; 10-year civil appointments (CLK-09).')"
                        :error="officeForm.errors.nominees ?? officeForm.errors.constitution"
                    >
                        <template #control="{ id, invalid, describedBy }">
                            <textarea
                                :id="id"
                                v-model="officeForm.nominees_text"
                                class="field-input"
                                rows="2"
                                data-no-i18n
                                :aria-invalid="invalid ? 'true' : undefined"
                                :aria-describedby="describedBy"
                            ></textarea>
                        </template>
                    </Field>
                </FormCard>
            </template>
        </Card>

        <!-- ================================== intake + docket ========== -->
        <div class="grid-2">
            <section class="card" aria-labelledby="intake-h">
                <h2 id="intake-h">{{ t('c_legislature_pages_b.oversight.intake_heading', 'Misconduct intake') }}</h2>
                <p class="gloss">
                    {{ t('c_legislature_pages_b.oversight.intake_gloss', 'From any resident, any member, or the chamber\'s own motion. No catalog form exists for intake (flagged registry gap) — the complaint is an audited non-form action; the docket is public.') }}
                </p>
                <template v-if="can.intake">
                    <Field :label="t('c_legislature_pages_b.oversight.subject_label', 'Subject (serving member)')" :error="intakeForm.errors.subject_member_id" required>
                        <template #control="{ id, invalid, describedBy }">
                            <select
                                :id="id"
                                v-model="intakeForm.subject_member_id"
                                class="select"
                                :aria-invalid="invalid ? 'true' : undefined"
                                :aria-describedby="describedBy"
                            >
                                <option value="" disabled>{{ t('c_legislature_pages_b.oversight.choose', '— choose —') }}</option>
                                <option v-for="member in members" :key="member.id" :value="member.id">
                                    {{ member.name }}{{ member.is_speaker ? t('c_legislature_pages_b.oversight.speaker_suffix', ' (Speaker)') : '' }}
                                </option>
                            </select>
                        </template>
                    </Field>
                    <Field :label="t('c_legislature_pages_b.oversight.complaint_summary', 'Complaint summary')" :error="intakeForm.errors.summary ?? intakeForm.errors.constitution" required>
                        <template #control="{ id, invalid, describedBy }">
                            <textarea
                                :id="id"
                                v-model="intakeForm.summary"
                                class="field-input"
                                rows="3"
                                :aria-invalid="invalid ? 'true' : undefined"
                                :aria-describedby="describedBy"
                            ></textarea>
                        </template>
                    </Field>
                    <Btn
                        variant="primary"
                        size="sm"
                        :disabled="intakeForm.processing || !intakeForm.subject_member_id || !intakeForm.summary.trim()"
                        @click="submitIntake"
                    >{{ t('c_legislature_pages_b.oversight.docket_complaint', 'Docket complaint') }}</Btn>
                </template>
                <p v-else class="citation">
                    {{ t('c_legislature_pages_b.oversight.intake_requires', 'Intake requires a live administrative office (F-LEG-013) and an authenticated account.') }}
                </p>
            </section>

            <section class="card" aria-labelledby="docket-h">
                <h2 id="docket-h">{{ t('c_legislature_pages_b.oversight.docket_heading', 'Investigations docket') }}</h2>
                <div v-if="investigations.length" class="stack" style="gap: var(--space-2)">
                    <Card v-for="inv in investigations" :key="inv.id" inset>
                        <p style="margin-block-end: var(--space-1)">
                            <strong data-no-i18n>{{ inv.code }}</strong> — {{ inv.subject }}
                            {{ ' ' }}
                            <StatusBadge :tone="INVESTIGATION_TONES[inv.state] ?? 'neutral'">{{ inv.state }}</StatusBadge>
                        </p>
                        <p class="cc-small">{{ inv.re }}</p>
                        <p v-if="inv.findings_record_href" class="citation">
                            <a :href="inv.findings_record_href">{{ t('c_legislature_pages_b.oversight.findings_link', 'findings — sealed public record →') }}</a>
                        </p>
                        <div v-if="can.refer" class="cluster" style="margin-block-start: var(--space-1)">
                            <Btn
                                v-if="inv.state === 'intake'"
                                variant="secondary"
                                size="sm"
                                :disabled="advancing === inv.id"
                                @click="advance(inv)"
                            >{{ t('c_legislature_pages_b.oversight.begin_investigating', 'Begin investigating') }}</Btn>
                            <Btn
                                v-if="inv.state === 'investigating'"
                                variant="secondary"
                                size="sm"
                                @click="referTarget = referTarget === inv.id ? null : inv.id"
                            >{{ t('c_legislature_pages_b.oversight.publish_findings_open', 'Publish findings…') }}</Btn>
                        </div>
                        <div v-if="referTarget === inv.id" class="stack" style="gap: var(--space-2); margin-block-start: var(--space-2)">
                            <Field :label="t('c_legislature_pages_b.oversight.findings_label', 'Findings (published to the public record)')" :error="referForm.errors.findings ?? referForm.errors.constitution" required>
                                <template #control="{ id, invalid, describedBy }">
                                    <textarea
                                        :id="id"
                                        v-model="referForm.findings"
                                        class="field-input"
                                        rows="3"
                                        :aria-invalid="invalid ? 'true' : undefined"
                                        :aria-describedby="describedBy"
                                    ></textarea>
                                </template>
                            </Field>
                            <RadioGroup
                                v-model="referForm.refer"
                                :label="t('c_legislature_pages_b.oversight.disposition', 'Disposition')"
                                :options="[
                                    { value: true, label: t('c_legislature_pages_b.oversight.refer_option', 'Refer to a removal proceeding') },
                                    { value: false, label: t('c_legislature_pages_b.oversight.close_option', 'Close with no finding') },
                                ]"
                            />
                            <Field v-if="referForm.refer" :label="t('c_legislature_pages_b.oversight.proceeding_kind', 'Proceeding kind')">
                                <template #control="{ id }">
                                    <select :id="id" v-model="referForm.kind" class="select">
                                        <option value="impeachment">{{ t('c_legislature_pages_b.oversight.opt_impeachment', 'Impeachment') }}</option>
                                        <option value="censure">{{ t('c_legislature_pages_b.oversight.opt_censure', 'Censure') }}</option>
                                        <option value="expulsion">{{ t('c_legislature_pages_b.oversight.opt_expulsion', 'Expulsion') }}</option>
                                    </select>
                                </template>
                            </Field>
                            <Btn
                                variant="primary"
                                size="sm"
                                :disabled="referForm.processing || !referForm.findings.trim()"
                                @click="submitRefer(inv)"
                            >{{ t('c_legislature_pages_b.oversight.publish_findings', 'Publish findings') }}</Btn>
                        </div>
                    </Card>
                </div>
                <p v-else class="cc-small gloss">{{ t('c_legislature_pages_b.oversight.docket_empty', 'The docket is empty.') }}</p>
            </section>
        </div>

        <!-- ================================== removal ================== -->
        <Card as="section" :title="t('c_legislature_pages_b.oversight.removal_title', 'Removal proceedings')">
            <p class="gloss">
                {{ t('c_legislature_pages_b.oversight.removal_gloss', 'Removal parity: legislators, executives, and judges are removed by the same standard — a supermajority of all serving members. The Speaker presides, never over their own case (Art. II §3 · removal.presider, hardened).') }}
            </p>

            <FormCard
                v-if="can.openProceeding && formMeta('F-LEG-022')"
                :form="{ id: 'F-SPK-007', name: t('c_legislature_pages_b.oversight.spk_form_name', 'Impeachment/Censure/Expulsion Presiding'), availableTo: ['R-10'], citation: t('c_legislature_pages_b.oversight.spk_form_citation', 'Art. II §3 — own-case presiding blocked in code') }"
                :inertia-form="proceedingForm"
                :submit-label="t('c_legislature_pages_b.oversight.open_proceeding', 'Open proceeding')"
                @submit="submitProceeding"
            >
                <Field :label="t('c_legislature_pages_b.oversight.kind_label', 'Kind')" required>
                    <template #control="{ id }">
                        <select :id="id" v-model="proceedingForm.kind" class="select">
                            <option value="impeachment">{{ t('c_legislature_pages_b.oversight.opt_impeachment', 'Impeachment') }}</option>
                            <option value="censure">{{ t('c_legislature_pages_b.oversight.opt_censure', 'Censure') }}</option>
                            <option value="expulsion">{{ t('c_legislature_pages_b.oversight.opt_expulsion', 'Expulsion') }}</option>
                        </select>
                    </template>
                </Field>
                <Field :label="t('c_legislature_pages_b.oversight.subject_label', 'Subject (serving member)')" :error="proceedingForm.errors.subject_member_id ?? proceedingForm.errors.constitution" required>
                    <template #control="{ id, invalid, describedBy }">
                        <select
                            :id="id"
                            v-model="proceedingForm.subject_member_id"
                            class="select"
                            :aria-invalid="invalid ? 'true' : undefined"
                            :aria-describedby="describedBy"
                        >
                            <option value="" disabled>{{ t('c_legislature_pages_b.oversight.choose', '— choose —') }}</option>
                            <option v-for="member in members" :key="member.id" :value="member.id">
                                {{ member.name }}{{ member.is_speaker ? t('c_legislature_pages_b.oversight.speaker_owncase_suffix', ' (Speaker — own case requires a designated presider)') : '' }}
                            </option>
                        </select>
                    </template>
                </Field>
            </FormCard>

            <div class="stack" style="gap: var(--space-3); margin-block-start: var(--space-3)">
                <Card v-for="proceeding in proceedings" :key="proceeding.id" inset>
                    <p style="margin-block-end: var(--space-1)">
                        <strong>{{ proceeding.kind }}</strong> — {{ proceeding.subject }}
                        {{ ' ' }}
                        <StatusBadge :tone="proceeding.outcome === 'retained' ? 'neutral' : proceeding.outcome ? 'danger' : 'info'">
                            {{ proceeding.outcome ?? proceeding.status }}
                        </StatusBadge>
                    </p>
                    <StateStrip :states="PROCEEDING_MACHINE" :current="proceeding.status" :aria-label="t('c_legislature_pages_b.oversight.proceeding_state_aria', 'Proceeding state machine')" />
                    <p class="citation" style="margin-block: var(--space-1)">
                        {{ t('c_legislature_pages_b.oversight.presiding_label', 'Presiding:') }} {{ proceeding.presided_by ?? t('c_legislature_pages_b.oversight.awaiting_designation', '— awaiting designation (the chamber designates; the engine blocks the subject)') }}
                    </p>

                    <!-- designate (own-case / presider-less path) -->
                    <div v-if="proceeding.status === 'opened' && can.designate" class="cluster">
                        <select v-model="designateForms[proceeding.id]" class="select" :aria-label="t('c_legislature_pages_b.oversight.designate_presider_aria', 'Designate presider')">
                            <option :value="undefined" disabled>{{ t('c_legislature_pages_b.oversight.designate_option', '— designate a presider —') }}</option>
                            <option
                                v-for="member in members.filter((m) => m.id !== proceeding.subject_member_id)"
                                :key="member.id"
                                :value="member.id"
                            >{{ member.name }}</option>
                        </select>
                        <Btn
                            variant="secondary"
                            size="sm"
                            :disabled="!designateForms[proceeding.id] || designating === proceeding.id"
                            @click="designate(proceeding)"
                        >{{ t('c_legislature_pages_b.oversight.designate_btn', 'Designate (F-SPK-007)') }}</Btn>
                    </div>

                    <!-- open the F-LEG-022 vote -->
                    <div v-if="proceeding.status === 'presiding_designated'" class="cluster" style="margin-block-start: var(--space-1)">
                        <Btn
                            v-if="can.vote"
                            variant="primary"
                            size="sm"
                            :disabled="openingVote === proceeding.id"
                            @click="openVote(proceeding)"
                        >{{ t('c_legislature_pages_b.oversight.open_removal_vote', 'Open removal vote (F-LEG-022)') }}</Btn>
                        <span class="citation">{{ t('c_legislature_pages_b.oversight.supermajority_note', 'supermajority of all serving · Art. VII') }}</span>
                    </div>

                    <!-- the vote -->
                    <template v-if="proceeding.vote">
                        <VoteTally
                            v-bind="proceeding.vote.tally"
                            basis="Art. VII"
                            :can-cast="can.vote && proceeding.vote.open && !proceeding.vote.my_cast"
                            :casting="casting === proceeding.id"
                            @cast="castRemoval(proceeding, $event)"
                        />
                        <p class="gloss">
                            {{ t('c_legislature_pages_b.oversight.needs_supermajority', 'Needs the supermajority of ALL serving — vacancies stay in the denominator; the Speaker presides and cannot cast (a supermajority tie is arithmetically impossible).') }}
                        </p>
                        <details v-if="proceeding.vote.casts.length" style="margin-block-start: var(--space-2)">
                            <summary class="cc-small" style="cursor: pointer">{{ t('c_legislature_pages_b.oversight.published_casts', { n: proceeding.vote.casts.length }) }}</summary>
                            <VoteCastList :casts="proceeding.vote.casts" :group-by-kind="bicameral" />
                        </details>
                    </template>

                    <!-- removal → vacancy chip (the closed loop) -->
                    <p v-if="proceeding.vacancy" class="cc-small" style="margin-block-start: var(--space-2)">
                        <StatusBadge tone="danger" icon="alert-triangle">{{ t('c_legislature_pages_b.oversight.seat_vacated', 'seat vacated') }}</StatusBadge>
                        {{ ' ' }}
                        {{ t('c_legislature_pages_b.oversight.system_filed', 'F-LEG-036 system-filed →') }}
                        <a :href="proceeding.vacancy.href">{{ t('c_legislature_pages_b.oversight.vacancy_countback_link', { status: proceeding.vacancy.status }) }}</a>
                    </p>
                </Card>
            </div>
            <p v-if="!proceedings.length" class="cc-small gloss">{{ t('c_legislature_pages_b.oversight.no_proceedings', 'No proceedings on record.') }}</p>
        </Card>

        <!-- ================================== vacancies ================ -->
        <Card as="section" :title="t('c_legislature_pages_b.oversight.vacancies_title', 'Vacancies')">
            <div v-if="vacancies.length" class="stack" style="gap: var(--space-3)">
                <Card v-for="vacancy in vacancies" :key="vacancy.id" inset>
                    <p style="margin-block-end: var(--space-1)">
                        <strong>{{ t('c_legislature_pages_b.oversight.seat_label', { seat: vacancy.seat ?? '—' }) }}</strong> — {{ vacancy.member }}
                        <span v-if="vacancy.declared_via" class="citation" data-no-i18n>· declared via {{ vacancy.declared_via }}</span>
                    </p>
                    <StateStrip :states="vacancyMachine" :current="vacancy.status" :aria-label="t('c_legislature_pages_b.oversight.vacancy_state_aria', 'Vacancy state machine')" />
                    <p class="cc-small" style="margin-block-start: var(--space-1)">
                        <a :href="vacancy.countback_href">{{ t('c_legislature_pages_b.oversight.countback_record', 'Countback record →') }}</a>
                        <template v-if="vacancy.special">
                            · {{ t('c_legislature_pages_b.oversight.special_election', { when: vacancy.special.scheduled_for, status: vacancy.special.status }) }}
                        </template>
                    </p>
                </Card>
            </div>
            <p v-else class="cc-small gloss">{{ t('c_legislature_pages_b.oversight.no_vacancies', 'No vacancies on record.') }}</p>

            <FormCard
                v-if="can.declareVacancy && formMeta('F-LEG-036')"
                :form="formMeta('F-LEG-036')"
                :inertia-form="vacancyForm"
                :submit-label="t('c_legislature_pages_b.oversight.declare_vacancy', 'Declare vacancy')"
                @submit="submitVacancy"
            >
                <p class="citation" style="margin-block-end: var(--space-2)">
                    {{ t('c_legislature_pages_b.oversight.declarer_rule', 'catalog alias: F-LEG-030 · workflows catalog (renumbering drift). Declarer rule (hardened): the Speaker or system may declare any current seat; a plain member only their own — declaration is never a weapon · Art. II §5.') }}
                </p>
                <Field :label="t('c_legislature_pages_b.oversight.member_label', 'Member')" :error="vacancyForm.errors.member_id ?? vacancyForm.errors.constitution" required>
                    <template #control="{ id, invalid, describedBy }">
                        <select
                            :id="id"
                            v-model="vacancyForm.member_id"
                            class="select"
                            :aria-invalid="invalid ? 'true' : undefined"
                            :aria-describedby="describedBy"
                        >
                            <option value="" disabled>{{ t('c_legislature_pages_b.oversight.choose', '— choose —') }}</option>
                            <option v-for="member in members" :key="member.id" :value="member.id">
                                {{ member.name }}{{ member.id === viewerMemberId ? t('c_legislature_pages_b.oversight.you_suffix', ' (you)') : '' }}
                            </option>
                        </select>
                    </template>
                </Field>
                <RadioGroup v-model="vacancyForm.reason" :label="t('c_legislature_pages_b.oversight.reason_label', 'Reason')" :options="REASON_OPTIONS" />
            </FormCard>
        </Card>

        <template #about>
            <p>
                {{ t('c_legislature_pages_b.oversight.about', 'A removal or expulsion system-files F-LEG-036 in the same transaction as the closing cast — the vacancy record, the countback, and the certify-or-special-election branch are the Phase B machinery, unchanged. This page is where the loop closes.') }}
            </p>
        </template>
    </PageScaffold>
</template>
