<script setup>
/**
 * Elections/CandidacyRegistration — FE-B3 (PHASE_B_DESIGN_frontend.md
 * §B.2; mockups/electoral/candidacy-registration.html).
 *
 * F-IND-011 FormCard (R-03 — residency is the ONLY requirement): office
 * select limited to races whose footprint contains one of the viewer's
 * active associations, optional platform statement + position-tag chips,
 * and the one attestation that may exist. The result card replaces the
 * mockup's scenario toggle — production has one truth (`myCandidacy`).
 *
 * Edge states: registration window closed (CLK-18) → submit disabled +
 * warning PhaseBanner (the engine independently 422s — UI disabling is
 * UX, never the boundary); already registered → result card only;
 * R-01/R-02 viewer → "establish residency" card.
 */
import { computed } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import FormCard from '@/Components/Surface/FormCard.vue';
import PhaseBanner from '@/Components/Electoral/PhaseBanner.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import CheckboxField from '@/Components/Ui/CheckboxField.vue';
import ChipToggle from '@/Components/Ui/ChipToggle.vue';
import CitationLine from '@/Components/Ui/CitationLine.vue';
import Field from '@/Components/Ui/Field.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';
import StateStrip from '@/Components/Ui/StateStrip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    election: { type: Object, required: true },
    phase: { type: String, required: true },
    registrationOpen: { type: Boolean, default: false },
    offices: { type: Array, default: () => [] },
    tagVocabulary: { type: Array, default: () => [] },
    machine: { type: Array, default: () => [] },
    viewerAssociated: { type: Boolean, default: false },
    myCandidacy: { type: Object, default: null },
});

const page = usePage();
const flash = computed(() => page.props.flash?.status ?? null);
const errors = computed(() => page.props.errors ?? {});

const formMeta = (id) => props.surface.forms.find((f) => f.id === id);

const form = useForm({
    race_id: props.offices.length === 1 ? props.offices[0].race_id : '',
    platform_statement: '',
    position_tags: [],
    residency_attested: false,
});

function toggleTag(tag) {
    form.position_tags = form.position_tags.includes(tag)
        ? form.position_tags.filter((t) => t !== tag)
        : [...form.position_tags, tag];
}

function submit() {
    form.post(`/elections/${props.election.id}/candidacy`, { preserveScroll: true });
}

/* Result-card state machine: myCandidacy drives everything post-submit. */
const inPool = computed(() =>
    ['validated', 'in_pool', 'finalist'].includes(props.myCandidacy?.status),
);
const machineCurrent = computed(() => {
    const status = props.myCandidacy?.status ?? null;
    if (status === null) return null;
    return props.machine.includes(status) ? status : null;
});
</script>

<template>
    <PageScaffold :surface="surface" :title="`Stand for office — ${election.jurisdiction_name}`">
        <template #intro>
            If you live somewhere, you can run for office there — that's the only requirement.
            Sign-ups stay open the whole time between elections: they open the moment the last
            election is certified and close only when the final ballot locks (the finalist
            cutoff).
        </template>
        <template #about>
            <p>
                WF-CIV-05 candidacy lifecycle. Entity machine ESM-06:
                {{ machine.join(' → ') }} (rejected / withdrawn / non-finalist are terminal
                public-record branches). The board's only check is residency (F-ELB-002).
            </p>
        </template>

        <p class="cluster" style="gap: var(--space-3)">
            <HardenedChip />
            <CitationLine text="Right to stand — Art. I · no fees, no signatures, no vetting" />
            <CitationLine text="Registration window = approval phase · CLK-18" />
        </p>

        <Banner v-if="flash" tone="info">{{ flash }}</Banner>
        <Banner v-if="errors.constitution" tone="warning" title="Filing rejected by the constitutional engine">
            {{ errors.constitution }} — the rejection itself is on the audit chain (append-only).
        </Banner>

        <PhaseBanner :phase="phase" context="registration" />

        <!-- ───────────── viewer has no associations (R-01/R-02) ───────── -->
        <Card v-if="!viewerAssociated && !myCandidacy" as="section" title="Establish residency to stand for office">
            <p>
                Voting and candidacy unlock together the moment residency verifies — no other
                requirement exists, by constitutional design.
            </p>
            <div class="cluster" style="margin-block-start: var(--space-3)">
                <Btn :as="Link" href="/civic/residency" variant="primary" icon="map-pin">
                    Declare residency
                </Btn>
            </div>
            <p class="citation" style="margin-block-start: var(--space-2)">
                Residency verified → all associations → rights unlocked · Art. I; Art. V §1
            </p>
        </Card>

        <!-- ─────────────────────── result card (myCandidacy non-null) ── -->
        <Card v-else-if="myCandidacy" as="section" title="Your candidacy in this election">
            <StateStrip :states="machine" :current="machineCurrent" />

            <div style="margin-block-start: var(--space-3)">
                <Banner v-if="myCandidacy.status === 'registered'" tone="info" title="Submitted — awaiting board validation.">
                    The board's validation (F-ELB-002) checks residency and nothing else — it is
                    the only check that may exist.
                    <CitationLine text="Art. I · residency is the only check" />
                </Banner>

                <Banner v-else-if="inPool" tone="info" title="Validated — you are in the approval pool.">
                    <span class="cluster" style="gap: var(--space-2); margin-block: var(--space-2)">
                        <StatusBadge tone="success" icon="check">In approval pool · R-06</StatusBadge>
                        <StatusBadge tone="neutral">{{ myCandidacy.office_label }}</StatusBadge>
                    </span>
                    <span class="cluster" style="gap: var(--space-2)">
                        <Btn :as="Link" :href="`/elections/${election.id}/open-ballot`" variant="secondary" size="sm">
                            See your standing on the open ballot
                        </Btn>
                        <Btn :as="Link" :href="`/candidates/${myCandidacy.id}`" variant="secondary" size="sm">
                            Manage your public profile — F-CAN-001
                        </Btn>
                    </span>
                </Banner>

                <Banner v-else-if="myCandidacy.status === 'rejected'" tone="emergency" title="Registration rejected — no residency association found.">
                    The only permissible ground for rejection is the absence of a residency
                    association in the selected jurisdiction — anything else would violate
                    Art. I. If you believe this is wrong, correct your residency declaration or
                    challenge the decision in court
                    <span class="planned-flag">Planned · Phase E</span>.
                    <CitationLine :text="`Recorded ground: ${myCandidacy.rejection_reason ?? 'no_residency_association'} · Art. I`" />
                </Banner>

                <Banner v-else-if="myCandidacy.status === 'withdrawn'" tone="warning" title="Candidacy withdrawn.">
                    Withdrawal is a permanent public record.
                    <CitationLine text="F-CAN-003 · terminal ESM-06 state" />
                </Banner>

                <Banner v-else tone="info" :title="`Candidacy status: ${myCandidacy.status}`">
                    <Btn :as="Link" :href="`/candidates/${myCandidacy.id}`" variant="secondary" size="sm">
                        Open your public profile
                    </Btn>
                </Banner>
            </div>
        </Card>

        <!-- ─────────────────────────────── F-IND-011 form + next steps ── -->
        <div v-else class="grid-2">
            <FormCard
                :form="formMeta('F-IND-011')"
                :inertia-form="form"
                :submit-label="registrationOpen ? 'Register candidacy — F-IND-011' : 'Registration window is closed'"
                processing-label="Filing F-IND-011…"
                :disabled="!registrationOpen || !offices.length"
                @submit="submit"
            >
                <Field
                    label="Office (election race)"
                    hint="Only jurisdictions you are associated with are listed — candidacy follows residency, nothing else."
                    :error="form.errors.race_id"
                    required
                >
                    <template #control="{ id, invalid, describedBy }">
                        <select
                            :id="id"
                            v-model="form.race_id"
                            class="field-input"
                            :aria-invalid="invalid ? 'true' : undefined"
                            :aria-describedby="describedBy"
                        >
                            <option value="" disabled>— select a race —</option>
                            <option v-for="office in offices" :key="office.race_id" :value="office.race_id">
                                {{ office.label }} · {{ office.seats }} seats
                            </option>
                        </select>
                    </template>
                </Field>
                <p v-if="!offices.length" class="gloss">
                    No race footprint of this election contains one of your associations — this
                    election may simply not be yours to stand in.
                </p>

                <Field
                    label="Platform statement (optional)"
                    hint="Public, self-managed, editable any time via F-CAN-001."
                    :error="form.errors.platform_statement"
                >
                    <template #control="{ id, invalid, describedBy }">
                        <textarea
                            :id="id"
                            v-model="form.platform_statement"
                            class="field-input"
                            rows="4"
                            :aria-invalid="invalid ? 'true' : undefined"
                            :aria-describedby="describedBy"
                        ></textarea>
                    </template>
                </Field>

                <div class="field">
                    <span class="field-label">Position tags (optional)</span>
                    <span class="cluster" style="gap: var(--space-1)">
                        <ChipToggle
                            v-for="tag in tagVocabulary"
                            :key="tag"
                            :pressed="form.position_tags.includes(tag)"
                            @update:pressed="toggleTag(tag)"
                        >{{ tag }}</ChipToggle>
                    </span>
                    <span v-if="form.errors.position_tags" class="field-error">{{ form.errors.position_tags }}</span>
                </div>

                <div class="field" :class="{ 'field--invalid': form.errors.residency_attested }">
                    <CheckboxField v-model="form.residency_attested" name="residency_attested">
                        I attest that I reside in the selected jurisdiction.
                        <strong>Nothing else is asked of me.</strong>
                    </CheckboxField>
                    <span v-if="form.errors.residency_attested" class="field-error">
                        {{ form.errors.residency_attested }}
                    </span>
                </div>

                <template #actions>
                    <span v-if="!registrationOpen" class="citation">
                        closes at the finalist cutoff · reopens at certification · CLK-18
                    </span>
                </template>
            </FormCard>

            <Card as="section" title="What happens next">
                <StateStrip :states="machine" :current="null" />
                <ol style="margin-block-start: var(--space-3); padding-inline-start: var(--space-5)">
                    <li>
                        The election board validates your residency association —
                        <strong>the only check that may exist</strong> (F-ELB-002 · Art. I).
                    </li>
                    <li>
                        You enter the approval pool: every associated resident can approve you,
                        revocably, until the finalist cutoff (WF-CIV-08 · CLK-18).
                    </li>
                    <li>
                        The top X by approvals advance to the ranked ballot; everyone else
                        remains write-in eligible — the right to stand is never lost (CLK-21).
                    </li>
                </ol>
                <p class="citation" style="margin-block-start: var(--space-2)">
                    finalists X = finalist_multiplier × seats · pre-published with the
                    scheduling order · CLK-21
                </p>
            </Card>
        </div>
    </PageScaffold>
</template>
