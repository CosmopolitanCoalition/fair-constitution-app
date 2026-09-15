<script setup>
/**
 * Judiciary/AdvocateConsole — FE-E4 (PHASE_E_DESIGN_frontend.md §B.5;
 * surface judiciary/advocate-console).
 *
 * The per-viewer advocate dashboard:
 *  · Registration card — F-IND-015 (R-21 bar entry) when unregistered, else
 *    the "Registered advocate" StatusBadge + grant date + practice scope.
 *  · "Your cases" — one bounded page of cases filed via F-ADV-001, each
 *    carrying the engine state badge + the per-state NEXT-ACTION line.
 *  · "New filing" composer — a filing-type discriminator (F-ADV-001..004); the
 *    case picker loads on demand for existing-case filings. F-ADV-001 shows
 *    a client field instead. Every
 *    submission POSTs through the engine — F-ADV-001 to /judiciaries/{j}/cases,
 *    the hearing filings to /cases/{c}/filings — stage-gated SERVER-side (the
 *    attach-window). A 422 renders the engine citation verbatim; the UI never
 *    decides the gate.
 *  · "Recent filings" — the append-only docket as a LogRow list.
 *  · "Your four instruments" — the F-ADV-001..004 FormCard reference grid.
 *
 * Public-read: the four-instrument explainer + the registration form render for
 * any associated resident; the case list + filings are the viewer's own record.
 * Every threshold/state/panel value is a server snapshot — this page renders
 * rows and opens form doors; it computes nothing.
 */
import { computed, reactive, ref, watch } from 'vue';
import { Link, router, useForm, usePage, useRemember } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import FormCard from '@/Components/Surface/FormCard.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Card from '@/Components/Ui/Card.vue';
import Field from '@/Components/Ui/Field.vue';
import FormChip from '@/Components/Ui/FormChip.vue';
import LogRow from '@/Components/Ui/LogRow.vue';
import HistoryPager from '@/Components/Ui/HistoryPager.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });
const { t } = useI18n();

const props = defineProps({
    surface: { type: Object, required: true },
    /** §B.5 advocate block; null = unregistered viewer (registration card renders). */
    advocate: { type: Object, default: null },
    /** One bounded page of the viewer's cases, in title or docket order. */
    myCases: { type: Array, default: () => [] },
    case_pages: { type: Object, default: () => ({ query: '', by: 'title', previous: null, next: null, first: '/judiciary/advocate' }) },
    /** The viewer's own docketed filings (append-only), newest first. */
    filings: { type: Array, default: () => [] },
    filing_pages: { type: Object, default: () => ({ previous: null, next: null, first: '/judiciary/advocate' }) },
    composer: { type: Object, default: () => ({ types: [] }) },
    composer_cases: { type: Array, default: () => [] },
    composer_case_pages: { type: Object, default: () => ({ query: '', by: 'title', loaded: false, previous: null, next: null, first: '/judiciary/advocate' }) },
    /** Unregistered viewer: the judiciary the F-IND-015 form registers with. */
    registerTargetId: { type: String, default: null },
    /** { judiciary:{met,court_name,jurisdiction,type,status,operating}, residency:{met,name} }
        or null for an already-registered viewer — the prerequisites checklist. */
    prerequisites: { type: Object, default: null },
    /** Courts the viewer may register with (excludes ones already joined). When
        more than one, the form shows a jurisdiction-of-practice selector. */
    practiceOptions: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({ register: false, file: false, isRegistered: false }) },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
/* The engine 422: ConstitutionalViolation surfaces as errors.constitution
   carrying "{message} ({citation})" — the verbatim rejection. */
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

const surfaceForm = (id) => props.surface.forms.find((f) => f.id === id) ?? null;

const registrationForm = surfaceForm('F-IND-015');
const instrumentIds = ['F-ADV-001', 'F-ADV-002', 'F-ADV-003', 'F-ADV-004'];

function fmtDate(iso) {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleDateString();
    } catch {
        return iso;
    }
}

/* --------------------------------------------------- registration ------ */
const regForm = useForm({ judiciary_id: props.registerTargetId ?? '', qualifications_note: '' });
function submitRegistration() {
    if (!props.registerTargetId) return;
    /* FormCard's onMounted already wires regForm.transform() to inject the
       canonical form_id (F-IND-015); judiciary_id rides as a form field. */
    regForm.post('/advocate/registration', {
        preserveScroll: true,
        onSuccess: () => regForm.reset('qualifications_note'),
    });
}

/* ------------------------------------------------------- composer ------ */
const draftKey = `advocate-filing:${page.props.auth?.user?.id ?? 'guest'}:${props.advocate?.id ?? 'unregistered'}`;
const composerType = useRemember(ref(props.composer.types[0]?.id ?? 'F-ADV-001'), `${draftKey}:type`);
const isNewCase = computed(() => composerType.value === 'F-ADV-001');
const selectedCase = useRemember(ref(null), `${draftKey}:case`);
const filingBusy = ref(false);
const filingForm = useForm(draftKey, {
    case_id: '',
    client: '',
    title: '',
    body: '',
});
const selectedCaseLabel = computed(() => selectedCase.value?.id === filingForm.case_id ? selectedCase.value : { id: filingForm.case_id, title: t('c_institutions.advocate_console.selected_case_fallback', 'Selected case'), docket_no: '' });
const directories = reactive({
    roster: { query: props.case_pages.query ?? '', by: props.case_pages.by ?? 'title', busy: false, error: '' },
    composer: { query: props.composer_case_pages.query ?? '', by: props.composer_case_pages.by ?? 'title', busy: false, error: '' },
});
watch(() => props.case_pages, value => { directories.roster.query = value.query ?? ''; directories.roster.by = value.by ?? 'title'; });
watch(() => props.composer_case_pages, value => { directories.composer.query = value.query ?? ''; directories.composer.by = value.by ?? 'title'; });
watch(() => props.composer_cases, cases => {
    const current = cases.find(item => item.id === filingForm.case_id);
    if (current) selectedCase.value = { ...current };
}, { immediate: true });
function browseCases(kind, url = null) {
    const state = directories[kind];
    if (state.busy || !props.can.file) return;
    const prefix = kind === 'roster' ? 'case_' : 'compose_case_';
    // A partial visit updates one list, but its URL is the whole page's saved
    // location. Preserve the other case list and filing history on refresh/back.
    const current = new URL(page.url ?? '/judiciary/advocate', 'http://localhost');
    const target = url ? new URL(url, current) : null;
    for (const part of ['q', 'by', 'cursor']) {
        current.searchParams.delete(prefix + part);
        if (target?.searchParams.has(prefix + part)) current.searchParams.set(prefix + part, target.searchParams.get(prefix + part));
    }
    if (!target) {
        current.searchParams.set(prefix + 'q', state.query.trim());
        current.searchParams.set(prefix + 'by', state.by);
    }
    const query = current.searchParams.toString();
    router.get('/judiciary/advocate' + (query ? '?' + query : ''), {}, {
        only: kind === 'roster' ? ['myCases', 'case_pages'] : ['composer_cases', 'composer_case_pages'],
        preserveState: true, preserveScroll: true,
        onStart: () => { state.busy = true; state.error = ''; },
        onFinish: () => { state.busy = false; },
        onError: errors => { state.error = Object.values(errors)[0] || t('c_institutions.advocate_console.cases_load_error', 'Cases could not be loaded. Try again.'); },
    });
}
watch(isNewCase, value => { if (!value && !props.composer_case_pages.loaded) browseCases('composer'); }, { immediate: true });
function chooseCase(item) {
    filingForm.case_id = item.id;
    selectedCase.value = { ...item };
    filingForm.clearErrors('case_id');
}
function clearCase() { filingForm.case_id = ''; selectedCase.value = null; }

const activeHint = computed(
    () => props.composer.types.find((ct) => ct.id === composerType.value)?.hint ?? '',
);

function submitFiling() {
    if (!props.can.file || filingBusy.value) return;
    if (!isNewCase.value && !filingForm.case_id) {
        filingForm.setError('case_id', t('c_institutions.advocate_console.choose_case', 'Choose the case this filing belongs to.'));
        return;
    }
    const options = {
        preserveScroll: true,
        onStart: () => { filingBusy.value = true; filingForm.clearErrors(); },
        onFinish: () => { filingBusy.value = false; },
        onError: errors => filingForm.setError(errors),
    };

    if (isNewCase.value) {
        /* F-ADV-001 — a new case on behalf of a client (a different endpoint:
           it OPENS a case, it does not append to one). */
        router.post(
            `/judiciaries/${props.advocate.judiciary.id}/cases`,
            {
                form_id: 'F-ADV-001',
                judiciary_id: props.advocate.judiciary.id,
                title: filingForm.title,
                statement_of_claim: filingForm.body,
                client: filingForm.client,
            },
            {
                ...options,
                onSuccess: () => filingForm.reset('title', 'body', 'client'),
            },
        );
        return;
    }

    /* F-ADV-002/003/004 — append to an existing case under the attach-window. */
    router.post(
        `/cases/${filingForm.case_id}/filings`,
        {
            form_id: composerType.value,
            title: filingForm.title,
            body: filingForm.body,
        },
        {
            ...options,
            onSuccess: () => filingForm.reset('title', 'body'),
        },
    );
}
</script>

<template>
    <PageScaffold :surface="surface">
        <template #intro>
            {{ t('c_institutions.advocate_console.intro', 'Your filings, motions, evidence, and briefs — everything you submit lands on the public docket of the case it belongs to. Representation is a constitutional right of your clients; registration keeps the bar of advocates zealous and competent.') }}
        </template>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <!-- ====================================== registration status ==== -->
        <Card v-if="can.isRegistered" as="section" :title="t('c_institutions.advocate_console.registration_status', 'Registration status')">
            <div class="cluster" style="margin-block-end: var(--space-3)">
                <StatusBadge tone="success" icon="check">{{ t('c_institutions.advocate_console.registered_advocate', 'Registered advocate') }}</StatusBadge>
                <span class="citation">
                    {{ t('c_institutions.advocate_console.granted_line', { date: fmtDate(advocate.granted_at), name: advocate.judiciary.name }) }}
                </span>
            </div>
            <p>
                {{ t('c_institutions.advocate_console.reg_for', 'Registered for') }} <strong>{{ advocate.persona.name }}</strong> {{ t('c_institutions.advocate_console.reg_scope', { scope: advocate.practice_scope }) }}
            </p>
            <p style="margin-block-start: var(--space-3)">
                <Link :href="advocate.judiciary.href">{{ t('c_institutions.advocate_console.your_judiciary', 'Your judiciary →') }}</Link>
            </p>
        </Card>

        <template v-else>
            <!-- Prerequisites checklist — what registration needs, and whether
                 you meet it. Two rows, each with an honest met / not-yet badge. -->
            <Card v-if="prerequisites" as="section" :title="t('c_institutions.advocate_console.before_register', 'Before you register')">
                <ul class="stack" style="gap: var(--space-2); list-style: none; padding: 0">
                    <li class="cluster" style="gap: var(--space-2)">
                        <StatusBadge
                            :tone="prerequisites.residency.met ? 'success' : 'warning'"
                            :icon="prerequisites.residency.met ? 'check' : 'clock'"
                        >
                            {{ prerequisites.residency.met ? t('c_institutions.advocate_console.met', 'Met') : t('c_institutions.advocate_console.not_yet', 'Not yet') }}
                        </StatusBadge>
                        <span>
                            <template v-if="prerequisites.residency.met">
                                {{ t('c_institutions.advocate_console.residency_met_before', 'You live in') }} <strong>{{ prerequisites.residency.name }}</strong> {{ t('c_institutions.advocate_console.residency_met_after', '— association is the only eligibility the bar checks.') }}
                            </template>
                            <template v-else>
                                {{ t('c_institutions.advocate_console.residency_not_met', 'You need a confirmed residency first — say where you live, and the courts of that place open to you.') }}
                            </template>
                        </span>
                    </li>
                    <li class="cluster" style="gap: var(--space-2)">
                        <StatusBadge
                            :tone="prerequisites.judiciary.met ? 'success' : 'warning'"
                            :icon="prerequisites.judiciary.met ? 'check' : 'clock'"
                        >
                            {{ prerequisites.judiciary.met ? t('c_institutions.advocate_console.met', 'Met') : t('c_institutions.advocate_console.not_yet', 'Not yet') }}
                        </StatusBadge>
                        <span>
                            <template v-if="prerequisites.judiciary.met">
                                <strong>{{ prerequisites.judiciary.court_name }}</strong> {{ t('c_institutions.advocate_console.jud_operating', { jurisdiction: prerequisites.judiciary.jurisdiction, type: prerequisites.judiciary.type }) }}
                            </template>
                            <template v-else-if="prerequisites.judiciary.court_name">
                                <strong>{{ prerequisites.judiciary.court_name }}</strong> {{ t('c_institutions.advocate_console.jud_exists', { jurisdiction: prerequisites.judiciary.jurisdiction, status: prerequisites.judiciary.status }) }}
                            </template>
                            <template v-else>
                                {{ t('c_institutions.advocate_console.jud_none', 'No court has formed in your jurisdiction yet — one appears when a legislature creates it (F-LEG-017).') }}
                            </template>
                        </span>
                    </li>
                </ul>
            </Card>

            <FormCard
                v-if="registrationForm && registerTargetId"
                :form="registrationForm"
                :inertia-form="regForm"
                :submit-label="t('c_institutions.advocate_console.register_submit', 'Register as an advocate')"
                @submit="submitRegistration"
            >
                <p class="gloss" style="margin-block-end: var(--space-3)">
                    {{ t('c_institutions.advocate_console.register_gloss', "Representation is a constitutional right of your clients; registration keeps the bar of advocates zealous and competent. Registration is open to any associated resident — association with the court's jurisdiction is the only eligibility check.") }}
                </p>

                <!-- Jurisdiction of practice — only when more than one court is
                     open to you. One court needs no choice; the target is set. -->
                <Field
                    v-if="practiceOptions.length > 1"
                    :label="t('c_institutions.advocate_console.court_of_practice_label', 'Court of practice')"
                    :hint="t('c_institutions.advocate_console.court_of_practice_hint', 'You live in more than one jurisdiction — choose whose court to join. You can register with the others later.')"
                >
                    <template #control="{ id, describedBy }">
                        <select
                            :id="id"
                            v-model="regForm.judiciary_id"
                            class="field-input"
                            :aria-describedby="describedBy"
                        >
                            <option
                                v-for="opt in practiceOptions"
                                :key="opt.id"
                                :value="opt.id"
                                :disabled="!opt.operating"
                            >
                                {{ opt.court_name }} · {{ opt.jurisdiction }}{{ opt.operating ? '' : t('c_institutions.advocate_console.not_seated_suffix', ' (not yet seated)') }}
                            </option>
                        </select>
                    </template>
                </Field>
                <Field
                    :label="t('c_institutions.advocate_console.qualifications_label', 'Qualifications note (optional)')"
                    :hint="t('c_institutions.advocate_console.qualifications_hint', 'Recorded with your registration; the bar\'s competence is a property of the bar, never a gate on your client\'s right.')"
                    :error="regForm.errors.qualifications_note"
                >
                    <template #control="{ id, describedBy }">
                        <textarea
                            :id="id"
                            v-model="regForm.qualifications_note"
                            class="field-input"
                            rows="2"
                            :aria-describedby="describedBy"
                        />
                    </template>
                </Field>
                <p class="cc-small" style="margin-block-start: var(--space-2)">
                    {{ t('c_institutions.advocate_console.self_rep_note', 'Representing yourself never requires registration — this role exists so others can be competently represented, never as a gate on your own right to be heard.') }}
                </p>
            </FormCard>

            <Card v-else as="section" :title="t('c_institutions.advocate_console.registration_status', 'Registration status')">
                <Banner tone="info" role="status" :title="t('c_institutions.advocate_console.no_judiciary_title', 'No judiciary in your association chain yet.')">
                    {{ t('c_institutions.advocate_console.no_judiciary_body', 'Advocate registration is open to any associated resident, but a court must exist in your jurisdiction first — courts form when a legislature creates one (F-LEG-017).') }}
                </Banner>
            </Card>
        </template>

        <!-- ================================================= your cases ==== -->
        <Card as="section" :title="t('c_institutions.advocate_console.your_cases_title', 'Your cases')">
            <p class="gloss">{{ t('c_institutions.advocate_console.your_cases_gloss', 'Cases you filed on behalf of clients, including completed cases. Browse by title or docket number.') }}</p>
            <form v-if="can.file" class="case-search" @submit.prevent="browseCases('roster')">
                <label>{{ t('c_institutions.advocate_console.search_by', 'Search by') }}<select v-model="directories.roster.by"><option value="title">{{ t('c_institutions.advocate_console.opt_title', 'Title') }}</option><option value="docket">{{ t('c_institutions.advocate_console.opt_docket', 'Docket number') }}</option></select></label>
                <label>{{ t('c_institutions.advocate_console.starts_with', 'Starts with') }}<input v-model="directories.roster.query" type="search" maxlength="160" /></label>
                <button type="submit" :disabled="directories.roster.busy">{{ t('c_institutions.advocate_console.search_cases', 'Search cases') }}</button>
                <button v-if="case_pages.query" type="button" :disabled="directories.roster.busy" @click="directories.roster.query = ''; browseCases('roster')">{{ t('c_institutions.advocate_console.all_my_cases', 'All my cases') }}</button>
            </form>
            <p v-if="directories.roster.error" role="alert">{{ directories.roster.error }}</p>
            <p role="status">{{ directories.roster.busy ? t('c_institutions.advocate_console.loading_cases', 'Loading cases…') : '' }}</p>

            <div v-if="myCases.length" class="stack" :aria-busy="directories.roster.busy" style="gap: var(--space-3); margin-block-start: var(--space-3)">
                <Card v-for="c in myCases" :key="c.id" inset>
                    <div class="cluster" style="justify-content: space-between">
                        <div>
                            <strong style="color: var(--gov-fg)">{{ c.title }}</strong>
                            <span class="citation" style="display: block">
                                {{ c.docket_no }} · {{ c.kind }} · {{ c.court }} · {{ t('c_institutions.advocate_console.panel_label', 'panel:') }} {{ c.panel }}
                            </span>
                        </div>
                        <StatusBadge :tone="c.state_tone" icon="file-text">{{ c.state }}</StatusBadge>
                    </div>
                    <p style="font-size: var(--text-sm); margin-block-start: var(--space-2)">
                        {{ c.next_action }}
                    </p>
                    <p style="margin-block-start: var(--space-2)">
                        <Link :href="c.href">{{ t('c_institutions.advocate_console.open_case', 'Open case →') }}</Link>
                    </p>
                </Card>
            </div>
            <p v-else class="gloss" style="margin-block-start: var(--space-3)">
                {{ case_pages.query ? t('c_institutions.advocate_console.no_cases_match', 'No cases match this beginning. Try another title or docket number.') : t('c_institutions.advocate_console.no_cases', 'Cases you file on behalf of clients appear here.') }}
            </p>
            <nav class="case-pages" :aria-label="t('c_institutions.advocate_console.case_pages_aria', 'Your case pages')">
                <button v-if="case_pages.previous" :disabled="directories.roster.busy" @click="browseCases('roster', case_pages.previous)">{{ t('c_institutions.advocate_console.previous_cases', 'Previous cases') }}</button>
                <button v-if="case_pages.next" :disabled="directories.roster.busy" @click="browseCases('roster', case_pages.next)">{{ t('c_institutions.advocate_console.more_cases', 'More cases') }}</button>
                <button v-if="case_pages.previous || directories.roster.error" :disabled="directories.roster.busy" @click="browseCases('roster', case_pages.first)">{{ t('c_institutions.advocate_console.first_page', 'First page') }}</button>
            </nav>
        </Card>

        <!-- ============================================= new filing ====== -->
        <Card as="section" :title="t('c_institutions.advocate_console.new_filing_title', 'New filing')">
            <form class="stack" style="gap: var(--space-3)" novalidate @submit.prevent="submitFiling">
                <div class="grid-2">
                    <Field :label="t('c_institutions.advocate_console.filing_type_label', 'Filing type')" :error="filingForm.errors.form_id">
                        <template #control="{ id }">
                            <select :id="id" v-model="composerType" class="select">
                                <option v-for="t in composer.types" :key="t.id" :value="t.id">
                                    {{ t.label }}
                                </option>
                            </select>
                        </template>
                    </Field>
                </div>

                <fieldset v-if="!isNewCase" class="case-picker">
                    <legend>{{ t('c_institutions.advocate_console.case_for_filing', 'Case for this filing') }}</legend>
                    <p class="gloss">{{ activeHint }}</p>
                    <div v-if="filingForm.case_id" class="selected-case">
                        <strong>{{ t('c_institutions.advocate_console.selected_label', 'Selected:') }} {{ selectedCaseLabel.title }}</strong>
                        <span>{{ selectedCaseLabel.docket_no }}</span>
                        <Link :href="`/cases/${filingForm.case_id}`">{{ t('c_institutions.advocate_console.open_selected_case', 'Open selected case') }}</Link>
                        <small>{{ t('c_institutions.advocate_console.case_reference', 'Case reference:') }} {{ filingForm.case_id }}</small>
                        <button type="button" @click="clearCase">{{ t('c_institutions.advocate_console.change_case', 'Change case') }}</button>
                    </div>
                    <p v-if="filingForm.errors.case_id" role="alert">{{ filingForm.errors.case_id }}</p>
                    <div class="case-search">
                        <label>{{ t('c_institutions.advocate_console.search_by', 'Search by') }}<select v-model="directories.composer.by"><option value="title">{{ t('c_institutions.advocate_console.opt_title', 'Title') }}</option><option value="docket">{{ t('c_institutions.advocate_console.opt_docket', 'Docket number') }}</option></select></label>
                        <label>{{ t('c_institutions.advocate_console.starts_with', 'Starts with') }}<input v-model="directories.composer.query" type="search" maxlength="160" @keydown.enter.prevent="browseCases('composer')" /></label>
                        <button type="button" :disabled="directories.composer.busy || !can.file" @click="browseCases('composer')">{{ t('c_institutions.advocate_console.find_a_case', 'Find a case') }}</button>
                        <button v-if="composer_case_pages.query" type="button" :disabled="directories.composer.busy" @click="directories.composer.query = ''; browseCases('composer')">{{ t('c_institutions.advocate_console.all_my_cases', 'All my cases') }}</button>
                    </div>
                    <p v-if="directories.composer.error" role="alert">{{ directories.composer.error }}</p>
                    <div :aria-busy="directories.composer.busy">
                        <p role="status">{{ directories.composer.busy ? t('c_institutions.advocate_console.loading_filing_cases', 'Loading cases for this filing…') : (!composer_case_pages.loaded ? t('c_institutions.advocate_console.find_case_prompt', 'Find a case to select it for this filing.') : (!composer_cases.length ? t('c_institutions.advocate_console.no_matching_page', 'No matching cases on this page. Try another title or docket number.') : t('c_institutions.advocate_console.choose_case', 'Choose the case this filing belongs to.'))) }}</p>
                        <ul class="case-options">
                            <li v-for="item in composer_cases" :key="item.id">
                                <div><strong>{{ item.title }}</strong><span>{{ item.docket_no }} · {{ item.state }}</span><Link :href="item.href">{{ t('c_institutions.advocate_console.open_case_short', 'Open case') }}</Link><small>{{ t('c_institutions.advocate_console.case_reference', 'Case reference:') }} {{ item.id }}</small></div>
                                <button type="button" :disabled="filingForm.case_id === item.id || directories.composer.busy" :aria-label="t('c_institutions.advocate_console.select_case_aria', { title: item.title, docket: item.docket_no, id: item.id })" @click="chooseCase(item)">{{ t('c_institutions.advocate_console.select_case', 'Select case') }}</button>
                            </li>
                        </ul>
                    </div>
                    <nav class="case-pages" :aria-label="t('c_institutions.advocate_console.filing_choices_aria', 'Filing case choices')">
                        <button v-if="composer_case_pages.previous" type="button" :disabled="directories.composer.busy" @click="browseCases('composer', composer_case_pages.previous)">{{ t('c_institutions.advocate_console.previous_choices', 'Previous choices') }}</button>
                        <button v-if="composer_case_pages.next" type="button" :disabled="directories.composer.busy" @click="browseCases('composer', composer_case_pages.next)">{{ t('c_institutions.advocate_console.more_choices', 'More choices') }}</button>
                        <button v-if="composer_case_pages.previous || directories.composer.error" type="button" :disabled="directories.composer.busy" @click="browseCases('composer', composer_case_pages.first)">{{ t('c_institutions.advocate_console.first_page', 'First page') }}</button>
                    </nav>
                </fieldset>

                <Field
                    v-if="isNewCase"
                    :label="t('c_institutions.advocate_console.client_label', 'Client')"
                    :hint="t('c_institutions.advocate_console.client_hint', 'Your client retains you; the retainer is recorded with the filing.')"
                    :error="filingForm.errors.client"
                >
                    <template #control="{ id, describedBy }">
                        <input
                            :id="id"
                            v-model="filingForm.client"
                            class="field-input"
                            type="text"
                            :placeholder="t('c_institutions.advocate_console.client_placeholder', 'Who you are filing for')"
                            :aria-describedby="describedBy"
                        />
                    </template>
                </Field>

                <Field :label="t('c_institutions.advocate_console.title_label', 'Title')" :error="filingForm.errors.title">
                    <template #control="{ id }">
                        <input
                            :id="id"
                            v-model="filingForm.title"
                            class="field-input"
                            type="text"
                            :placeholder="t('c_institutions.advocate_console.title_placeholder', 'A short label for this filing')"
                        />
                    </template>
                </Field>

                <Field :label="t('c_institutions.advocate_console.summary_label', 'Summary')" :error="filingForm.errors.body">
                    <template #control="{ id }">
                        <textarea
                            :id="id"
                            v-model="filingForm.body"
                            class="field-input"
                            rows="3"
                            :placeholder="t('c_institutions.advocate_console.summary_placeholder', 'What this filing asks the court to do')"
                        />
                    </template>
                </Field>

                <div class="cluster">
                    <button type="submit" class="btn btn--primary" :disabled="!can.file || filingBusy">
                        {{ filingBusy ? t('c_institutions.advocate_console.submitting', 'Submitting…') : t('c_institutions.advocate_console.submit_docket', 'Submit to the docket') }}
                    </button>
                    <span v-if="!can.file" class="gloss">
                        {{ t('c_institutions.advocate_console.register_to_file', 'Register as an advocate (F-IND-015) to file on behalf of a client.') }}
                    </span>
                </div>
            </form>
        </Card>

        <!-- =========================================== recent filings ==== -->
        <Card as="section" :title="t('c_institutions.advocate_console.filing_history_title', 'Filing history')">
            <div v-if="filings.length" class="stack" style="gap: 0; margin-block-start: var(--space-2)">
                <LogRow v-for="g in filings" :key="g.seq" :seq="g.seq">
                    <FormChip :form-id="g.form" />
                    <span style="flex: 1 1 12rem">
                        {{ g.text }}
                        <Link v-if="g.case" :href="g.case.href" class="citation">{{ g.case.title }}</Link>
                    </span>
                    <span class="citation">{{ fmtDate(g.when) }}</span>
                    <StatusBadge tone="success" icon="check">{{ t('c_institutions.advocate_console.accepted_docketed', 'Accepted · docketed') }}</StatusBadge>
                </LogRow>
            </div>
            <p v-else class="gloss" style="margin-block-start: var(--space-2)">
                {{ t('c_institutions.advocate_console.no_docketed', 'No docketed filings on this page.') }}
            </p>
            <HistoryPager :pages="filing_pages" :only="['filings', 'filing_pages']" :first="filing_pages.first" cursor-key="filings_cursor" :label="t('c_institutions.advocate_console.filing_history_pager', 'Advocate filing history pages')" />
        </Card>

        <!-- ========================================= four instruments ==== -->
        <Card as="section" :title="t('c_institutions.advocate_console.four_instruments_title', 'Your four instruments')">
            <div class="grid-2" style="margin-block-start: var(--space-2)">
                <Card v-for="fid in instrumentIds" :key="fid" inset>
                    <template v-if="surfaceForm(fid)">
                        <div class="cluster" style="justify-content: space-between; align-items: baseline">
                            <strong style="color: var(--gov-fg)">{{ surfaceForm(fid).name }}</strong>
                            <FormChip :form-id="fid" :alias="surfaceForm(fid).alias" />
                        </div>
                        <span class="citation" style="display: block; margin-block-start: var(--space-1)">
                            {{ t('c_institutions.advocate_console.available_to', 'available to') }} {{ (surfaceForm(fid).availableTo ?? []).join(', ') }}
                            <template v-if="surfaceForm(fid).citation"> · {{ surfaceForm(fid).citation }}</template>
                        </span>
                    </template>
                </Card>
            </div>
        </Card>

        <template #about>
            <p>
                <strong>{{ t('c_institutions.advocate_console.about_workflows_label', 'Workflows:') }}</strong> {{ t('c_institutions.advocate_console.about_workflows', 'registration is WF-CIV-07; every filing feeds the WF-JUD-03 case lifecycle.') }}
            </p>
            <p>
                <strong>{{ t('c_institutions.advocate_console.about_esm_label', 'Entity state machine:') }}</strong> {{ t('c_institutions.advocate_console.about_esm', 'Case — filings attach at specific states (motions before and during hearing, evidence on the open docket, briefs until deliberation); the case detail page plays the full sequence. The attach-window is enforced by the engine, not this page.') }}
            </p>
        </template>
    </PageScaffold>
</template>

<style scoped>
.case-search, .case-pages { display: flex; flex-wrap: wrap; gap: .75rem; align-items: end; margin-block: 1rem; }
.case-search label, .selected-case, .case-options li > div { display: grid; gap: .3rem; min-inline-size: 0; }
.case-search input { max-inline-size: 100%; }
.case-picker { min-inline-size: 0; }
.case-options { list-style: none; padding: 0; }
.case-options li { display: flex; justify-content: space-between; align-items: center; gap: 1rem; padding-block: .75rem; border-block-end: 1px solid var(--gov-border); }
.selected-case, .case-options { overflow-wrap: anywhere; }
.selected-case { padding: .75rem; background: var(--gov-surface); }
.selected-case button { justify-self: start; }
</style>
