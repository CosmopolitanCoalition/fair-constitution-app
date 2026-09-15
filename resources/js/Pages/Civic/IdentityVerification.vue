<script setup>
/**
 * Civic/IdentityVerification — minimal Phase A surface
 * (civic/identity-verification contract, EXPLORE_civic_electoral.md §2;
 * mockups/civic/identity-verification.html).
 *
 * Only the manual attestation-request path ships in Phase A (F-IND-004
 * stub through the engine). No external ID bridge (Phase F), no officer
 * console, no document data ever accepted or stored. The page's most
 * important element is the banner: verification is NEVER a rights
 * requirement (Art. I) — skipping is always allowed.
 */
import { computed } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import FormCard from '@/Components/Surface/FormCard.vue';
import AdmChip from '@/Components/Ui/AdmChip.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Card from '@/Components/Ui/Card.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';
import StateStrip from '@/Components/Ui/StateStrip.vue';
import Stepper from '@/Components/Ui/Stepper.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });
const { t } = useI18n();

const props = defineProps({
    surface: { type: Object, required: true },
    /** PHP-owned ESM-01 Individual onboarding arc (4 states). */
    machine: { type: Array, default: () => [] },
    /** The viewer's current node on that arc, derived server-side. */
    journeyStatus: { type: String, default: 'registered' },
    identity: { type: Object, required: true },
    declaredJurisdiction: { type: Object, default: null },
});

/* Plain labels for the onboarding strip — the raw tokens are machine grammar
   ("jurisdictionally_associated"); the player chrome speaks plainly (S8). */
const ONBOARDING_LABELS = computed(() => ({
    registered: t('c_civic.identity_verification.label_registered', 'Registered'),
    identity_verified: t('c_civic.identity_verification.label_identity_verified', 'ID linked'),
    residency_declared: t('c_civic.identity_verification.label_residency_declared', 'Residency declared'),
    jurisdictionally_associated: t('c_civic.identity_verification.label_jurisdictionally_associated', 'Represented'),
}));

/* The onboarding stepper context — this is step 2 of the 3-step arrival arc,
   and it is the OPTIONAL one. Steps 1 and 3 are always reachable regardless. */
const onboardingSteps = computed(() => [
    { label: t('c_civic.identity_verification.step_account', '1 · Account'), icon: 'check', state: 'done' },
    { label: t('c_civic.identity_verification.step_link_id', '2 · Link an ID (optional)'), state: 'active' },
    {
        label: t('c_civic.identity_verification.step_live', '3 · Say where you live'),
        state: props.journeyStatus === 'jurisdictionally_associated' ? 'done' : 'pending',
    },
]);

const page = usePage();
const flash = computed(() => page.props.flash?.status ?? null);
const errors = computed(() => page.props.errors ?? {});

const isVerified = computed(() => props.identity.status === 'identity_verified');
const isPending = computed(
    () => !isVerified.value && props.identity.attestation_requested_at !== null,
);

function formatDate(iso) {
    if (!iso) return '—';
    try {
        return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(iso));
    } catch {
        return iso;
    }
}

const requestForm = useForm({});

function submitRequest() {
    requestForm.post('/civic/identity/request', { preserveScroll: true });
}

const formMeta = (id) => props.surface.forms.find((f) => f.id === id);
</script>

<template>
    <PageScaffold :surface="surface" :title="t('c_civic.identity_verification.page_title', 'Link a government ID (optional)')">
        <Stepper :steps="onboardingSteps" />

        <template #intro>
            {{ t('c_civic.identity_verification.intro_before', 'Where your jurisdiction supports it, you can link a government ID (formally: identity verification) to your account. It helps keep elections honest — it is') }}
            <strong>{{ t('c_civic.identity_verification.intro_never', 'never') }}</strong>
            {{ t('c_civic.identity_verification.intro_after', 'required. Voting and candidacy come from living somewhere, nothing else. You can') }}
            <Link href="/civic/residency">{{ t('c_civic.identity_verification.intro_link', 'skip straight to saying where you live') }}</Link>.
        </template>
        <template #about>
            <p>
                {{ t('c_civic.identity_verification.about', 'WF-CIV-01 identity step, Phase A scope: the manual attestation-request stub only. Per-jurisdiction external ID bridges (encrypted yes/no document match, nothing stored) arrive with federation in Phase F; an officer recording the verified flag is later-phase machinery.') }}
            </p>
        </template>

        <!-- THE banner — the page's most important element. -->
        <Banner tone="info" :title="t('c_civic.identity_verification.banner_title', 'Verification is never a rights requirement.')">
            {{ t('c_civic.identity_verification.banner_body', 'Voting and candidacy depend on jurisdictional residency alone — no identity check, document, course, or fee can ever be added between you and your rights. Skipping this page is always allowed and changes nothing.') }}
            <span class="citation" style="display: block; margin-block-start: var(--space-1)">
                {{ t('c_civic.identity_verification.hardened_cite', 'Art. I · hardened') }} <HardenedChip><span class="visually-hidden">{{ t('c_civic.identity_verification.hardened', 'hardened') }}</span></HardenedChip>
            </span>
        </Banner>

        <Banner v-if="flash" tone="info">{{ flash }}</Banner>
        <Banner v-if="errors.constitution" tone="warning" :title="t('c_civic.identity_verification.rejected_title', 'Filing rejected by the constitutional engine')">
            {{ errors.constitution }} {{ t('c_civic.identity_verification.rejected_after', '— the rejection itself is on the audit chain (append-only).') }}
        </Banner>

        <!-- ──────────────────────────────────────────── Current status -->
        <Card as="section" :title="t('c_civic.identity_verification.where_you_are', 'Where you are')">
            <StateStrip :states="machine" :current="journeyStatus" :labels="ONBOARDING_LABELS" />
            <div class="cluster" style="margin-block-start: var(--space-3); gap: var(--space-3)">
                <StatusBadge v-if="isVerified" tone="success" icon="check">
                    {{ t('c_civic.identity_verification.identity_verified', 'Identity verified') }}
                    <template v-if="identity.verified_via"> {{ t('c_civic.identity_verification.via', { via: identity.verified_via }) }}</template>
                    <template v-if="identity.verified_at"> · {{ formatDate(identity.verified_at) }}</template>
                </StatusBadge>
                <StatusBadge v-else-if="isPending" tone="warning" icon="clock">
                    {{ t('c_civic.identity_verification.attestation_pending', { when: formatDate(identity.attestation_requested_at) }) }}
                </StatusBadge>
                <StatusBadge v-else tone="neutral" icon="user">{{ t('c_civic.identity_verification.not_verified', 'Not verified — and that is fine') }}</StatusBadge>
            </div>
            <p class="citation" style="margin-block-start: var(--space-3)">
                {{ t('c_civic.identity_verification.status_cite', 'Identity verification strengthens election integrity — never a voting requirement · Art. I; Art. II §2') }}
            </p>
        </Card>

        <!-- ──────────────────────── F-IND-004 — manual attestation path -->
        <FormCard
            v-if="!isVerified"
            :form="formMeta('F-IND-004')"
            :inertia-form="requestForm"
            :submit-label="isPending ? t('c_civic.identity_verification.request_again', 'Request again') : t('c_civic.identity_verification.request_appointment', 'Request attestation appointment')"
            :processing-label="t('c_civic.identity_verification.filing', 'Filing F-IND-004…')"
            @submit="submitRequest"
        >
            <p style="margin-block-end: var(--space-3)">
                {{ t('c_civic.identity_verification.manual_path', 'The manual path: request an attestation appointment with') }}
                <template v-if="declaredJurisdiction">
                    {{ t('c_civic.identity_verification.office_of', 'the administrative office of') }}
                    <AdmChip :level="declaredJurisdiction.adm_level" :label="declaredJurisdiction.name" />.
                </template>
                <template v-else>
                    {{ t('c_civic.identity_verification.office_unscoped', "your jurisdiction's administrative office — you have not declared residency yet, so the request is recorded unscoped;") }}
                    <Link href="/civic/residency">{{ t('c_civic.identity_verification.declare_link', 'declare residency') }}</Link> {{ t('c_civic.identity_verification.to_direct', 'to direct it.') }}
                </template>
                {{ t('c_civic.identity_verification.officer_records', 'An officer records only the verified flag — no document data is ever accepted, transmitted, or stored by this filing.') }}
            </p>
            <p v-if="isPending" class="gloss" style="margin-block-end: var(--space-3)">
                {{ t('c_civic.identity_verification.pending_note', { when: formatDate(identity.attestation_requested_at) }) }}
            </p>
        </FormCard>

        <!-- ───────────────────────────────── External bridge — honest -->
        <Card as="section" :title="t('c_civic.identity_verification.auto_check_title', 'Automatic ID check')">
            <p class="gloss">
                {{ t('c_civic.identity_verification.auto_before', "One day, where a jurisdiction supports it, you'll be able to link an existing government ID by an encrypted yes/no match — the document number never stored, never transmitted by us.") }}
                <strong>{{ t('c_civic.identity_verification.auto_none_yet', 'No jurisdiction on this world can do that yet') }}</strong>{{ t('c_civic.identity_verification.auto_bridge', ': the bridge is built with federation in') }}
                <strong>{{ t('c_civic.identity_verification.auto_phase_f', 'Phase F') }}</strong>{{ t('c_civic.identity_verification.auto_until', '. Until then the in-person attestation request above is the only path, and it too is entirely optional.') }}
            </p>
        </Card>
    </PageScaffold>
</template>
