<script setup>
/**
 * Judiciary/ConstitutionalChallenge — FE-E5 (PHASE_E_DESIGN_frontend.md §B.4;
 * surface judiciary/constitutional-challenge) — THE Phase E exit-criterion
 * surface. The page IS the Art4Section5Tracker: it hydrates the component
 * with the live constitutional_challenges record (finding → remedy → both
 * CLK-11/CLK-12 windows → the three Art. IV §5 paths) and opens the
 * F-IND-016 filing door.
 *
 * CONSTITUTIONAL POSTURE — the tracker is a pure renderer: every threshold,
 * the override `required` count, the window due-dates, and the `applied`
 * boolean arrive as ENGINE SNAPSHOTS on the `challenge` prop (the controller
 * reads them off the rows; nothing is computed here). The page only opens
 * doors: the F-IND-016 POST goes through ConstitutionalEngine::file via the
 * controller, and an un-associated filer is rejected by the engine (422 →
 * errors.constitution, the citation verbatim) — never a page 403. Findings,
 * remedies, and every member's override position are PUBLIC RECORD
 * (Art. II §2 · Art. V §2), so the tracker renders for any reader.
 */
import { computed } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import FormCard from '@/Components/Surface/FormCard.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Field from '@/Components/Ui/Field.vue';
import Art4Section5Tracker from '@/Components/Judiciary/Art4Section5Tracker.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    /** { id, name } | null — the resolved (deepest associated) judiciary. */
    judiciary: { type: Object, default: null },
    /** Art4Section5Tracker `challenge` prop (server-shaped) | null → empty state. */
    challenge: { type: Object, default: null },
    /** constitutional_challenge ESM states (config/cga/state_machines.php). */
    machine: { type: Array, default: () => [] },
    /** F-IND-016 filing options: { lawOptions, scaleOptions, bases }. */
    fileForm: { type: Object, default: () => ({ lawOptions: [], scaleOptions: [], bases: [] }) },
    isAssociated: { type: Boolean, default: false },
    can: { type: Object, default: () => ({}) },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
/* The engine 422: a ConstitutionalViolation surfaces as errors.constitution
   carrying "{message} ({citation})" — the verbatim rejection. */
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

/* The F-IND-016 SurfaceMeta record (the canonical filing instrument). */
const fileFormMeta = computed(() => props.surface.forms.find((f) => f.id === 'F-IND-016') ?? null);

/* ----------------------------------------------------- F-IND-016 file --- */
const filing = useForm({
    challenged_law_id: '',
    jurisdiction_id: '',
    claimed_basis: '',
    claim_text: '',
    constitutional_citation: '',
});

function submitFiling() {
    filing.post('/constitutional-challenges', {
        preserveScroll: true,
        onSuccess: () => filing.reset('claim_text', 'constitutional_citation'),
    });
}
</script>

<template>
    <PageScaffold :surface="surface" title="Constitutional challenge tracker">
        <template #intro>
            Any inhabitant can challenge a law that unjustly impedes their rights. When the court
            finds a contradiction, three resolution paths open: the legislature amends, the
            legislature overrides by supermajority within the veto window, or — if the window closes
            with neither — the judiciary edits the law directly. A finding, a recommended fix, a
            reasonable timeframe to act, and an override window — all on a public clock.
        </template>

        <!-- engine 422: the rejection citation, verbatim -->
        <Banner v-if="constitutionError" tone="emergency" role="alert" title="The challenge was not filed.">
            {{ constitutionError }}
        </Banner>
        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>

        <!-- ============================================ THE TRACKER ====== -->
        <!-- The whole surface is the Art. IV §5 pipeline. `fileForm` is left
             null so the tracker renders the record + explainer; the wired
             F-IND-016 composer lives below (the tracker's internal FormCard
             does not forward submit — the page owns the POST). -->
        <Art4Section5Tracker :challenge="challenge" :machine="machine" :file-form="null" />

        <!-- ============================================ F-IND-016 ======== -->
        <!-- The filing door — always available (file the first challenge, or
             another). R-03 is the engine boundary; an un-associated viewer
             sees the residency CTA instead of a dead form, never a 403. -->
        <FormCard
            v-if="fileFormMeta && isAssociated"
            :form="fileFormMeta"
            :inertia-form="filing"
            :disabled="!can.fileChallenge"
            submit-label="File the challenge"
            processing-label="Filing…"
            @submit="submitFiling"
        >
            <p class="gloss" style="margin-block-end: var(--space-3)">
                Any inhabitant may file; no standing gatekeeper beyond jurisdictional association —
                the right is absolute and fee-free (Art. IV §5.1 · Art. I).
            </p>

            <Field label="Law challenged" :error="filing.errors.challenged_law_id">
                <template #control="{ id, invalid, describedBy }">
                    <select
                        :id="id"
                        v-model="filing.challenged_law_id"
                        class="select"
                        :aria-invalid="invalid ? 'true' : undefined"
                        :aria-describedby="describedBy"
                    >
                        <option value="">— select an in-force or amended law —</option>
                        <option v-for="law in fileForm.lawOptions" :key="law.id" :value="law.id">
                            {{ law.label }}
                        </option>
                    </select>
                </template>
            </Field>

            <Field
                label="Jurisdiction you file in"
                hint="The law's binding jurisdiction or a descendant under it that you inhabit."
                :error="filing.errors.jurisdiction_id"
            >
                <template #control="{ id, describedBy }">
                    <select :id="id" v-model="filing.jurisdiction_id" class="select" :aria-describedby="describedBy">
                        <option value="">— the law's binding jurisdiction (default) —</option>
                        <option v-for="scale in fileForm.scaleOptions" :key="scale.id" :value="scale.id">
                            {{ scale.name }}
                        </option>
                    </select>
                </template>
            </Field>

            <Field label="Asserted contradiction" :error="filing.errors.claimed_basis">
                <template #control="{ id, invalid, describedBy }">
                    <select
                        :id="id"
                        v-model="filing.claimed_basis"
                        class="select"
                        :aria-invalid="invalid ? 'true' : undefined"
                        :aria-describedby="describedBy"
                    >
                        <option value="">— select a basis —</option>
                        <option v-for="basis in fileForm.bases" :key="basis.value" :value="basis.value">
                            {{ basis.label }}
                        </option>
                    </select>
                </template>
            </Field>

            <Field
                label="How the law impedes your rights"
                hint="The court reads this; there is no merits test at filing — the right to be heard is absolute."
                :error="filing.errors.claim_text"
            >
                <template #control="{ id, invalid, describedBy }">
                    <textarea
                        :id="id"
                        v-model="filing.claim_text"
                        class="field-input"
                        rows="4"
                        :aria-invalid="invalid ? 'true' : undefined"
                        :aria-describedby="describedBy"
                    />
                </template>
            </Field>

            <Field
                label="Constitutional citation (optional)"
                hint="The Article/Section the law contradicts, if you can name it."
                :error="filing.errors.constitutional_citation"
            >
                <template #control="{ id, describedBy }">
                    <input
                        :id="id"
                        v-model="filing.constitutional_citation"
                        class="field-input"
                        :aria-describedby="describedBy"
                    />
                </template>
            </Field>

            <p class="citation">
                no fee, no eligibility ground, no standing gatekeeper — the engine enforces the
                absolute right and parks the filing at <span class="mono" data-no-i18n>filed</span>
                when no court is yet seated · F-IND-016 · Art. IV §5.1 · Art. I
            </p>
        </FormCard>

        <!-- un-associated viewer: explain, never 403 (public-read posture) -->
        <Banner
            v-else-if="fileFormMeta && !isAssociated"
            tone="info"
            role="status"
            title="Confirm a residency to file a challenge"
        >
            Reading is open to everyone — findings, remedies, and every member's override position
            are public record (Art. II §2). Filing a challenge needs an active residency association
            with the jurisdiction whose law you challenge (R-03).
            <a href="/civic/residency">Confirm residency →</a>
        </Banner>

        <template #about>
            <p>
                Workflows: <span class="mono" data-no-i18n>WF-JUD-05</span> — constitutional
                challenge &amp; law remedy; hearings run on the WF-JUD-03 machinery; Path A
                re-enters the bill flow (WF-LEG-06); executives enforce the outcome (WF-EXE-07).
            </p>
            <p>
                The challenge is its own durable entity, distinct from the case it is heard in: the
                CLK-11/CLK-12 windows run for weeks-to-months after the hearing closes, gated on
                legislature action. <span v-if="judiciary">Resolved court: {{ judiciary.name }}.</span>
            </p>
        </template>
    </PageScaffold>
</template>
