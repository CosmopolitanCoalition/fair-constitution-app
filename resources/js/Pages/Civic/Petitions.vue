<script setup>
/**
 * Civic/Petitions — FE-C10 (PHASE_C_DESIGN_frontend.md §B.12).
 *
 * Petition list scoped to the viewer's association chain (SignatureMeter
 * compact + the revocable Sign/Signed toggle — F-IND-010) + the F-IND-009
 * create FormCard with the LIVE threshold preview ("≈ {n} signatures at
 * {name}'s population" — recomputed per option from props, no request).
 * Create requires association — the engine enforces; the page explains
 * with the residency CTA, never a 403.
 */
import { computed, ref } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import FormCard from '@/Components/Surface/FormCard.vue';
import AdmChip from '@/Components/Ui/AdmChip.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import Field from '@/Components/Ui/Field.vue';
import StateStrip from '@/Components/Ui/StateStrip.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import SignatureMeter from '@/Components/Civic/SignatureMeter.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    petitions: { type: Array, default: () => [] },
    machine: { type: Array, default: () => [] },
    thresholdSetting: { type: Object, required: true },
    createForm: { type: Object, required: true },
    isAssociated: { type: Boolean, default: false },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

const formMeta = (id) => props.surface.forms.find((f) => f.id === id);

const STATE_TONES = {
    created: 'info',
    gathering: 'info',
    threshold_reached: 'success',
    signature_audit: 'warning',
    constitutional_review: 'warning',
    validated: 'success',
    on_ballot: 'success',
    adopted: 'success',
    rejected: 'danger',
    invalidated: 'danger',
};

const mySignatures = computed(() => props.petitions.filter((p) => p.signed_by_me).length);

/* --------------------------------------------- sign/revoke (F-IND-010) -- */
const signing = ref(null);
function toggleSignature(petition) {
    signing.value = petition.id;
    const options = {
        preserveScroll: true,
        onFinish: () => {
            signing.value = null;
        },
    };
    if (petition.signed_by_me) {
        router.delete(petition.sign_url, { data: { form_id: 'F-IND-010' }, ...options });
    } else {
        router.post(petition.sign_url, { form_id: 'F-IND-010' }, options);
    }
}

/* -------------------------------------------------- create (F-IND-009) -- */
const create = useForm({
    title: '',
    law_text: '',
    jurisdiction_id: props.createForm.scaleOptions[0]?.id ?? null,
});

/* LIVE threshold preview — per-option numbers from props, no request. */
const selectedScale = computed(
    () => props.createForm.scaleOptions.find((o) => o.id === create.jurisdiction_id) ?? null,
);

function submitCreate() {
    create
        .transform((data) => ({ ...data, form_id: 'F-IND-009', act_type: 'ordinary' }))
        .post('/civic/petitions', {
            preserveScroll: true,
            onSuccess: () => create.reset(),
        });
}
</script>

<template>
    <PageScaffold :surface="surface">
        <template #intro>
            Anyone who lives here can draft a law and put it to a vote. Reach the signature
            threshold, pass an independent check and a constitutionality review, and your
            proposal goes on the next jurisdiction-wide ballot.
        </template>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <div class="cluster" style="gap: var(--space-6)">
            <Stat :value="petitions.length" label="petitions in your association chain" />
            <Stat :value="`${thresholdSetting.pct}%`" label="signature threshold · CLK-17" accent />
            <Stat :value="mySignatures" label="your live signatures" />
        </div>
        <p class="amendable" style="margin-block-start: calc(-1 * var(--space-3))">
            <span class="amendable-value">{{ thresholdSetting.pct }}%</span> of jurisdiction population
            <span class="amendable-meta" data-no-i18n>{{ thresholdSetting.key }} · amendable by legislative act · {{ thresholdSetting.clock }} · Art. II §6</span>
        </p>

        <!-- ==================================== list ===================== -->
        <Card as="section" title="Open petitions">
            <p class="citation" style="margin-block-end: var(--space-3)">scoped to your association chain</p>

            <div v-if="petitions.length" class="stack" style="gap: var(--space-3)">
                <Card v-for="petition in petitions" :key="petition.id" inset>
                    <div class="cluster" style="justify-content: space-between">
                        <span>
                            <Link :href="petition.href"><strong>{{ petition.title }}</strong></Link>
                            {{ ' ' }}
                            <AdmChip :level="petition.jurisdiction.adm_level ?? 0" :label="petition.jurisdiction.name ?? ''" />
                            {{ ' ' }}
                            <StatusBadge :tone="STATE_TONES[petition.state] ?? 'neutral'">{{ petition.state }}</StatusBadge>
                        </span>
                        <Btn
                            :variant="petition.signed_by_me ? 'secondary' : 'primary'"
                            size="sm"
                            :aria-pressed="petition.signed_by_me ? 'true' : 'false'"
                            :disabled="!petition.signable || signing === petition.id"
                            :title="petition.signable ? undefined : 'audited count frozen at the threshold check'"
                            @click="toggleSignature(petition)"
                        >{{ petition.signed_by_me ? 'Signed' : 'Sign' }}</Btn>
                    </div>
                    <SignatureMeter
                        :signatures="petition.signatures"
                        :threshold="petition.threshold_count"
                        :pct="petition.pct"
                        compact
                        style="margin-block-start: var(--space-2)"
                    />
                    <p class="citation" style="margin-block-start: var(--space-1)">
                        scale: {{ petition.scale_label }} · scope: {{ petition.scope_label }}
                    </p>
                </Card>
            </div>
            <p v-else class="cc-small gloss">
                No open petitions in your association chain — any associated resident can create one.
            </p>

            <p class="cc-small" style="margin-block-start: var(--space-3)">
                The Sign toggles use Petition signature
                <span class="form-chip"><span class="form-id" data-no-i18n>F-IND-010</span></span>
                — revocable while the petition gathers; the audited count freezes at the threshold check.
                <span class="citation" style="display: block">available to R-03 Jurisdictionally Associated · Art. II §6</span>
            </p>
        </Card>

        <!-- ==================================== create (F-IND-009) ======= -->
        <FormCard
            v-if="isAssociated && formMeta('F-IND-009')"
            :form="formMeta('F-IND-009')"
            :inertia-form="create"
            submit-label="Register petition"
            @submit="submitCreate"
        >
            <Field label="Title" :error="create.errors.title" required>
                <template #control="{ id, invalid, describedBy }">
                    <input
                        :id="id"
                        v-model="create.title"
                        class="field-input"
                        type="text"
                        placeholder="Short, neutral title for the proposed law"
                        :aria-invalid="invalid ? 'true' : undefined"
                        :aria-describedby="describedBy"
                    />
                </template>
            </Field>
            <Field
                label="Law text"
                hint="Write the binding text itself, not a summary — this is what voters ratify. It is reviewed for constitutionality before ballot placement."
                :error="create.errors.law_text"
                required
            >
                <template #control="{ id, invalid, describedBy }">
                    <textarea
                        :id="id"
                        v-model="create.law_text"
                        class="field-input"
                        rows="4"
                        :aria-invalid="invalid ? 'true' : undefined"
                        :aria-describedby="describedBy"
                    ></textarea>
                </template>
            </Field>
            <Field
                label="Scale — which jurisdiction adopts it"
                :hint="selectedScale
                    ? `≈ ${selectedScale.threshold_preview.toLocaleString()} signatures at ${selectedScale.name}'s civic population of ${selectedScale.population.toLocaleString()} (${selectedScale.threshold_pct}% · CLK-17 — snapshot taken at creation)`
                    : 'Threshold preview updates with your selection.'"
                :error="create.errors.jurisdiction_id"
            >
                <template #control="{ id }">
                    <select :id="id" v-model="create.jurisdiction_id" class="select">
                        <option v-for="option in createForm.scaleOptions" :key="option.id" :value="option.id">
                            {{ option.name }} — civic pop {{ option.population.toLocaleString() }}
                        </option>
                    </select>
                </template>
            </Field>
            <p class="gloss" style="margin-block-end: var(--space-3)">
                Scale and scope travel with the law — the same fields a bill carries. The petition
                enters at <em>Created</em> and signature gathering opens immediately.
            </p>
        </FormCard>
        <Card v-else as="section" title="Petition creation (F-IND-009)">
            <p class="gloss">
                Creating a petition requires an active jurisdictional association (R-03) — the same
                gate as voting and candidacy, and the only one (Art. I).
            </p>
            <Btn as="a" href="/civic/residency" variant="primary" size="sm">Declare residency →</Btn>
        </Card>

        <!-- ==================================== lifecycle ================ -->
        <Card as="section" title="Petition lifecycle">
            <StateStrip :states="machine" aria-label="Petition state machine" />
            <p class="gloss" style="margin-block-start: var(--space-2)">
                Two kill-paths: a failed signature audit, or an unconstitutional finding.
            </p>
            <p class="citation">
                Audit by the election board (F-ELB-005) · review by the judiciary (F-JDG-008 · Planned · Phase E) · Art. II §6
            </p>
        </Card>

        <template #about>
            <p>
                Signing (F-IND-010) is available to any R-03 associated resident of the petition's
                jurisdiction — no petitioner role needed to sign. Thresholds are snapshots: the
                civic-population basis and the resolved percentage freeze at creation (CLK-17).
            </p>
        </template>
    </PageScaffold>
</template>
