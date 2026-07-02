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
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import FormCard from '@/Components/Surface/FormCard.vue';
import AdmChip from '@/Components/Ui/AdmChip.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Card from '@/Components/Ui/Card.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';
import StateStrip from '@/Components/Ui/StateStrip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    /** PHP-owned account-side slice of ESM-01 Individual. */
    machine: { type: Array, default: () => [] },
    identity: { type: Object, required: true },
    declaredJurisdiction: { type: Object, default: null },
});

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
    <PageScaffold :surface="surface">
        <template #intro>
            Where your jurisdiction supports it, you can link a government ID (formally: identity
            verification) to your account. It helps keep elections honest — it is
            <strong>never</strong> required. Voting and candidacy come from living somewhere,
            nothing else. You can
            <Link href="/civic/residency">skip straight to saying where you live</Link>.
        </template>
        <template #about>
            <p>
                WF-CIV-01 identity step, Phase A scope: the manual attestation-request stub only.
                Per-jurisdiction external ID bridges (encrypted yes/no document match, nothing
                stored) arrive with federation in Phase F; an officer recording the verified flag
                is later-phase machinery.
            </p>
        </template>

        <!-- THE banner — the page's most important element. -->
        <Banner tone="info" title="Verification is never a rights requirement.">
            Voting and candidacy depend on jurisdictional residency alone — no identity check,
            document, course, or fee can ever be added between you and your rights. Skipping this
            page is always allowed and changes nothing.
            <span class="citation" style="display: block; margin-block-start: var(--space-1)">
                Art. I · hardened <HardenedChip><span class="visually-hidden">hardened</span></HardenedChip>
            </span>
        </Banner>

        <Banner v-if="flash" tone="info">{{ flash }}</Banner>
        <Banner v-if="errors.constitution" tone="warning" title="Filing rejected by the constitutional engine">
            {{ errors.constitution }} — the rejection itself is on the audit chain (append-only).
        </Banner>

        <!-- ──────────────────────────────────────────── Current status -->
        <Card as="section" title="Where you are">
            <StateStrip :states="machine" :current="identity.status" />
            <div class="cluster" style="margin-block-start: var(--space-3); gap: var(--space-3)">
                <StatusBadge v-if="isVerified" tone="success" icon="check">
                    Identity verified
                    <template v-if="identity.verified_via"> · via {{ identity.verified_via }}</template>
                    <template v-if="identity.verified_at"> · {{ formatDate(identity.verified_at) }}</template>
                </StatusBadge>
                <StatusBadge v-else-if="isPending" tone="warning" icon="clock">
                    Attestation requested {{ formatDate(identity.attestation_requested_at) }} — pending
                </StatusBadge>
                <StatusBadge v-else tone="neutral" icon="user">Not verified — and that is fine</StatusBadge>
            </div>
            <p class="citation" style="margin-block-start: var(--space-3)">
                Identity verification strengthens election integrity — never a voting requirement · Art. I; Art. II §2
            </p>
        </Card>

        <!-- ──────────────────────── F-IND-004 — manual attestation path -->
        <FormCard
            v-if="!isVerified"
            :form="formMeta('F-IND-004')"
            :inertia-form="requestForm"
            :submit-label="isPending ? 'Request again' : 'Request attestation appointment'"
            processing-label="Filing F-IND-004…"
            @submit="submitRequest"
        >
            <p style="margin-block-end: var(--space-3)">
                The manual path: request an attestation appointment with
                <template v-if="declaredJurisdiction">
                    the administrative office of
                    <AdmChip :level="declaredJurisdiction.adm_level" :label="declaredJurisdiction.name" />.
                </template>
                <template v-else>
                    your jurisdiction's administrative office — you have not declared residency
                    yet, so the request is recorded unscoped;
                    <Link href="/civic/residency">declare residency</Link> to direct it.
                </template>
                An officer records only the verified flag — no document data is ever accepted,
                transmitted, or stored by this filing.
            </p>
            <p v-if="isPending" class="gloss" style="margin-block-end: var(--space-3)">
                A request from {{ formatDate(identity.attestation_requested_at) }} is already on
                your record — requesting again simply appends a fresh entry.
            </p>
        </FormCard>

        <!-- ───────────────────────────────── External bridge — honest -->
        <Card as="section" title="External ID bridge">
            <p class="gloss">
                Some jurisdictions will support an external identity bridge — an encrypted yes/no
                match against an existing ID system, with the document number never stored. That
                machinery ships with federation in <strong>Phase F</strong>; today the manual
                attestation path above is the only one.
            </p>
        </Card>
    </PageScaffold>
</template>
