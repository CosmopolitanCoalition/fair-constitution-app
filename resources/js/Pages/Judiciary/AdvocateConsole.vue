<script setup>
/**
 * Judiciary/AdvocateConsole — FE-E4 (PHASE_E_DESIGN_frontend.md §B.5;
 * surface judiciary/advocate-console).
 *
 * The per-viewer advocate dashboard:
 *  · Registration card — F-IND-015 (R-21 bar entry) when unregistered, else
 *    the "Registered advocate" StatusBadge + grant date + practice scope.
 *  · "Your active cases" — one card--inset per case filed via F-ADV-001, each
 *    carrying the engine state badge + the per-state NEXT-ACTION line.
 *  · "New filing" composer — a filing-type discriminator (F-ADV-001..004); the
 *    case select hides for F-ADV-001 (which shows a client field instead). Every
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
import { computed, ref } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import FormCard from '@/Components/Surface/FormCard.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Card from '@/Components/Ui/Card.vue';
import Field from '@/Components/Ui/Field.vue';
import FormChip from '@/Components/Ui/FormChip.vue';
import LogRow from '@/Components/Ui/LogRow.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

const props = defineProps({
    surface: { type: Object, required: true },
    /** §B.5 advocate block; null = unregistered viewer (registration card renders). */
    advocate: { type: Object, default: null },
    /** Cases the viewer filed on behalf of a client (F-ADV-001), newest first. */
    myCases: { type: Array, default: () => [] },
    /** The viewer's own docketed filings (append-only), newest first. */
    filings: { type: Array, default: () => [] },
    /** { types:[{id,label,hint}], casesForClient:[{id,title,label}] }. */
    composer: { type: Object, default: () => ({ types: [], casesForClient: [] }) },
    /** Unregistered viewer: the judiciary the F-IND-015 form registers with. */
    registerTargetId: { type: String, default: null },
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
const composerType = ref(props.composer.types[0]?.id ?? 'F-ADV-001');
const isNewCase = computed(() => composerType.value === 'F-ADV-001');

const filingForm = useForm({
    case_id: props.composer.casesForClient[0]?.id ?? '',
    client: '',
    title: '',
    body: '',
});

const activeHint = computed(
    () => props.composer.types.find((t) => t.id === composerType.value)?.hint ?? '',
);

function submitFiling() {
    if (!props.can.file) return;

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
                preserveScroll: true,
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
            preserveScroll: true,
            onSuccess: () => filingForm.reset('title', 'body'),
        },
    );
}
</script>

<template>
    <PageScaffold :surface="surface">
        <template #intro>
            Your filings, motions, evidence, and briefs — everything you submit lands on the public
            docket of the case it belongs to. Representation is a constitutional right of your clients;
            registration keeps the bar of advocates zealous and competent.
        </template>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <!-- ====================================== registration status ==== -->
        <Card v-if="can.isRegistered" as="section" title="Registration status">
            <div class="cluster" style="margin-block-end: var(--space-3)">
                <StatusBadge tone="success" icon="check">Registered advocate</StatusBadge>
                <span class="citation">
                    granted {{ fmtDate(advocate.granted_at) }} · {{ advocate.judiciary.name }}
                </span>
            </div>
            <p>
                Registered for <strong>{{ advocate.persona.name }}</strong> — practice rights cover
                {{ advocate.practice_scope }}.
            </p>
            <p style="margin-block-start: var(--space-3)">
                <Link :href="advocate.judiciary.href">Your judiciary →</Link>
            </p>
        </Card>

        <template v-else>
            <FormCard
                v-if="registrationForm && registerTargetId"
                :form="registrationForm"
                :inertia-form="regForm"
                submit-label="Register as an advocate"
                @submit="submitRegistration"
            >
                <p class="gloss" style="margin-block-end: var(--space-3)">
                    Representation is a constitutional right of your clients; registration keeps the
                    bar of advocates zealous and competent. Registration is open to any associated
                    resident — association with the court's jurisdiction is the only eligibility check.
                </p>
                <Field
                    label="Qualifications note (optional)"
                    hint="Recorded with your registration; the bar's competence is a property of the bar, never a gate on your client's right."
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
            </FormCard>

            <Card v-else as="section" title="Registration status">
                <Banner tone="info" role="status" title="No judiciary in your association chain yet.">
                    Advocate registration is open to any associated resident, but a court must exist
                    in your jurisdiction first — courts form when a legislature creates one (F-LEG-017).
                </Banner>
            </Card>
        </template>

        <!-- ======================================== your active cases ==== -->
        <Card as="section" title="Your active cases">
            <p class="gloss">Cases you filed on behalf of clients (via F-ADV-001).</p>

            <div v-if="myCases.length" class="stack" style="gap: var(--space-3); margin-block-start: var(--space-3)">
                <Card v-for="c in myCases" :key="c.id" inset>
                    <div class="cluster" style="justify-content: space-between">
                        <div>
                            <strong style="color: var(--gov-fg)">{{ c.title }}</strong>
                            <span class="citation" style="display: block">
                                {{ c.docket_no }} · {{ c.kind }} · {{ c.court }} · panel: {{ c.panel }}
                            </span>
                        </div>
                        <StatusBadge :tone="c.state_tone" icon="file-text">{{ c.state }}</StatusBadge>
                    </div>
                    <p style="font-size: var(--text-sm); margin-block-start: var(--space-2)">
                        {{ c.next_action }}
                    </p>
                    <p style="margin-block-start: var(--space-2)">
                        <Link :href="c.href">Open case →</Link>
                    </p>
                </Card>
            </div>
            <p v-else class="gloss" style="margin-block-start: var(--space-3)">
                Cases you file on behalf of clients appear here.
            </p>
        </Card>

        <!-- ============================================= new filing ====== -->
        <Card as="section" title="New filing">
            <form class="stack" style="gap: var(--space-3)" novalidate @submit.prevent="submitFiling">
                <div class="grid-2">
                    <Field label="Filing type" :error="filingForm.errors.form_id">
                        <template #control="{ id }">
                            <select :id="id" v-model="composerType" class="select">
                                <option v-for="t in composer.types" :key="t.id" :value="t.id">
                                    {{ t.label }}
                                </option>
                            </select>
                        </template>
                    </Field>

                    <Field
                        v-if="!isNewCase"
                        label="Case"
                        :hint="activeHint"
                        :error="filingForm.errors.case_id"
                    >
                        <template #control="{ id, describedBy }">
                            <select
                                :id="id"
                                v-model="filingForm.case_id"
                                class="select"
                                :aria-describedby="describedBy"
                            >
                                <option v-for="c in composer.casesForClient" :key="c.id" :value="c.id">
                                    {{ c.label }}
                                </option>
                            </select>
                        </template>
                    </Field>
                </div>

                <Field
                    v-if="isNewCase"
                    label="Client"
                    hint="Your client retains you; the retainer is recorded with the filing."
                    :error="filingForm.errors.client"
                >
                    <template #control="{ id, describedBy }">
                        <input
                            :id="id"
                            v-model="filingForm.client"
                            class="field-input"
                            type="text"
                            placeholder="Who you are filing for"
                            :aria-describedby="describedBy"
                        />
                    </template>
                </Field>

                <Field label="Title" :error="filingForm.errors.title">
                    <template #control="{ id }">
                        <input
                            :id="id"
                            v-model="filingForm.title"
                            class="field-input"
                            type="text"
                            placeholder="A short label for this filing"
                        />
                    </template>
                </Field>

                <Field label="Summary" :error="filingForm.errors.body">
                    <template #control="{ id }">
                        <textarea
                            :id="id"
                            v-model="filingForm.body"
                            class="field-input"
                            rows="3"
                            placeholder="What this filing asks the court to do"
                        />
                    </template>
                </Field>

                <div class="cluster">
                    <button type="submit" class="btn btn--primary" :disabled="!can.file">
                        Submit to the docket
                    </button>
                    <span v-if="!can.file" class="gloss">
                        Register as an advocate (F-IND-015) to file on behalf of a client.
                    </span>
                </div>
            </form>
        </Card>

        <!-- =========================================== recent filings ==== -->
        <Card as="section" title="Recent filings">
            <div v-if="filings.length" class="stack" style="gap: 0; margin-block-start: var(--space-2)">
                <LogRow v-for="g in filings" :key="g.seq" :seq="g.seq">
                    <FormChip :form-id="g.form" />
                    <span style="flex: 1 1 12rem">
                        {{ g.text }}
                        <span v-if="g.case" class="citation">{{ g.case.title }}</span>
                    </span>
                    <span class="citation">{{ fmtDate(g.when) }}</span>
                    <StatusBadge tone="success" icon="check">Accepted · docketed</StatusBadge>
                </LogRow>
            </div>
            <p v-else class="gloss" style="margin-block-start: var(--space-2)">
                Your docketed filings appear here — the docket is append-only; nothing is ever sealed
                retroactively (Art. IV §4).
            </p>
        </Card>

        <!-- ========================================= four instruments ==== -->
        <Card as="section" title="Your four instruments">
            <div class="grid-2" style="margin-block-start: var(--space-2)">
                <Card v-for="fid in instrumentIds" :key="fid" inset>
                    <template v-if="surfaceForm(fid)">
                        <div class="cluster" style="justify-content: space-between; align-items: baseline">
                            <strong style="color: var(--gov-fg)">{{ surfaceForm(fid).name }}</strong>
                            <FormChip :form-id="fid" :alias="surfaceForm(fid).alias" />
                        </div>
                        <span class="citation" style="display: block; margin-block-start: var(--space-1)">
                            available to {{ (surfaceForm(fid).availableTo ?? []).join(', ') }}
                            <template v-if="surfaceForm(fid).citation"> · {{ surfaceForm(fid).citation }}</template>
                        </span>
                    </template>
                </Card>
            </div>
        </Card>

        <template #about>
            <p>
                <strong>Workflows:</strong> registration is WF-CIV-07; every filing feeds the
                WF-JUD-03 case lifecycle.
            </p>
            <p>
                <strong>Entity state machine:</strong> Case — filings attach at specific states
                (motions before and during hearing, evidence on the open docket, briefs until
                deliberation); the case detail page plays the full sequence. The attach-window is
                enforced by the engine, not this page.
            </p>
        </template>
    </PageScaffold>
</template>
