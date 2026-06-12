<script setup>
/**
 * Elections/CandidateProfile — FE-B3 (PHASE_B_DESIGN_frontend.md §B.3;
 * mockups/electoral/candidate-profile.html).
 *
 * Sections: PhaseBanner (profile context, isFinalist branch) · approval
 * standing Card (Stat ×3 + ThresholdMeter vs the finalist line +
 * StateStrip) · endorsements (org chips, individual public/private split,
 * the expandable public web) + endorsement requests (owner) · public
 * record (LogRow actions; votes/statements arrive Phase C) · manage card
 * (owner: F-CAN-001 statement, F-CAN-003 withdraw with post-cutoff
 * disabled-with-citation, F-CAN-002 request form).
 *
 * Deferred per §E: the stylized endorsement-graph SVG (Phase D org module)
 * — the chips + public-web list carry all the information.
 */
import { computed, ref } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import FormCard from '@/Components/Surface/FormCard.vue';
import PhaseBanner from '@/Components/Electoral/PhaseBanner.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import CitationLine from '@/Components/Ui/CitationLine.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import Field from '@/Components/Ui/Field.vue';
import LogRow from '@/Components/Ui/LogRow.vue';
import OrgChip from '@/Components/Ui/OrgChip.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StateStrip from '@/Components/Ui/StateStrip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import TagChip from '@/Components/Ui/TagChip.vue';
import ThresholdMeter from '@/Components/Ui/ThresholdMeter.vue';

const props = defineProps({
    surface: { type: Object, required: true },
    candidacy: { type: Object, required: true },
    standing: { type: Object, default: null },
    machine: { type: Array, default: () => [] },
    currentState: { type: String, default: null },
    endorsements: { type: Object, default: () => ({ orgs: [], individual: { total: 0, public: 0, private: 0 }, publicWeb: [] }) },
    requests: { type: Array, default: () => [] },
    publicRecord: { type: Object, default: () => ({ votes: [], actions: [], statements: [] }) },
    isOwner: { type: Boolean, default: false },
    can: { type: Object, default: () => ({ withdraw: false }) },
    organizations: { type: Array, default: () => [] },
});

const page = usePage();
const flash = computed(() => page.props.flash?.status ?? null);
const errors = computed(() => page.props.errors ?? {});

const formMeta = (id) => props.surface.forms.find((f) => f.id === id);

const race = computed(() => props.candidacy.race);
const phase = computed(() => race.value?.phase ?? 'approval');
const noEndorsements = computed(
    () => props.endorsements.orgs.length === 0 && props.endorsements.individual.total === 0,
);

const fmtDate = (iso) => (iso ? new Date(iso).toLocaleDateString() : '—');

const requestColumns = [
    { key: 'org_name', label: 'Organization' },
    { key: 'requested_at', label: 'Requested' },
    { key: 'status', label: 'Status' },
];

/* ───────────────────────────────────── F-CAN-001 — manage statement */

const profileForm = useForm({
    platform_statement: props.candidacy.statement ?? '',
});
function submitProfile() {
    profileForm.patch(`/candidates/${props.candidacy.id}`, { preserveScroll: true });
}

/* ──────────────────────────── F-CAN-003 — withdraw (ballot lock UX) */

const withdrawForm = useForm({});
const withdrawConfirming = ref(false);
function submitWithdraw() {
    withdrawForm.post(`/candidates/${props.candidacy.id}/withdraw`, {
        preserveScroll: true,
        onSuccess: () => (withdrawConfirming.value = false),
    });
}

/* ─────────────────────────────── F-CAN-002 — endorsement request */

const requestForm = useForm({ organization_id: '', message: '' });
function submitRequest() {
    requestForm.post(`/candidates/${props.candidacy.id}/endorsement-requests`, {
        preserveScroll: true,
        onSuccess: () => requestForm.reset(),
    });
}
</script>

<template>
    <PageScaffold :surface="surface" :title="`Candidate — ${candidacy.name}`">
        <template #intro>
            Public campaign profile. The statement and tags are the candidate's own words —
            self-managed, never vetted; the standing is the daily approval aggregate.
        </template>
        <template #about>
            <p>
                WF-CIV-05 / WF-CIV-08. Entity machine ESM-06: {{ machine.join(' → ') }}.
                Individual approvals are secret — only aggregates appear here.
            </p>
        </template>

        <Banner v-if="flash" tone="info">{{ flash }}</Banner>
        <Banner v-if="errors.constitution" tone="warning" title="Filing rejected by the constitutional engine">
            {{ errors.constitution }} — the rejection itself is on the audit chain (append-only).
        </Banner>

        <PhaseBanner
            :phase="phase"
            context="profile"
            :is-finalist="standing ? standing.isFinalist : null"
        />

        <!-- ─────────────────────────────────────── identity + standing -->
        <Card as="section">
            <template #title>
                <h2>
                    {{ candidacy.name }}
                    <StatusBadge v-if="candidacy.incumbent" tone="neutral">incumbent</StatusBadge>
                    <StatusBadge v-if="candidacy.withdrawn" tone="danger">withdrawn — recorded on the public record</StatusBadge>
                </h2>
            </template>
            <p v-if="candidacy.statement">{{ candidacy.statement }}</p>
            <p v-else class="gloss">No platform statement yet.</p>
            <p class="cluster" style="gap: var(--space-1)">
                <TagChip v-for="tag in candidacy.position_tags" :key="tag">{{ tag }}</TagChip>
            </p>
            <p v-if="race" class="citation">
                {{ race.label }} · {{ race.seats }} seats · top {{ race.finalist_count }} advance · CLK-21
            </p>

            <StateStrip :states="machine" :current="currentState" style="margin-block-start: var(--space-3)" />
        </Card>

        <!-- ─────────────────────────────────────────── approval standing -->
        <Card as="section" title="Approval standing">
            <template v-if="standing">
                <div class="cluster" style="gap: var(--space-6)">
                    <Stat :value="`#${standing.rank} of ${standing.of}`" label="full-race rank" />
                    <Stat :value="standing.approvals.toLocaleString()" label="approvals — aggregate · updated daily" accent />
                    <Stat :value="race?.finalist_count ?? '—'" label="finalist places (X)" />
                </div>
                <div style="margin-block-start: var(--space-3)">
                    <ThresholdMeter
                        :value="standing.approvals"
                        :max="Math.max(standing.topApprovals, standing.lineApprovals, standing.approvals, 1)"
                        :threshold="standing.lineApprovals"
                        label="Approvals relative to the finalist line"
                    >
                        <template v-if="standing.isFinalist">
                            rank #{{ standing.rank }} — inside the top {{ race?.finalist_count }} (finalist track)
                        </template>
                        <template v-else>
                            below the finalist line · write-in eligible
                        </template>
                        <template #note>
                            gold tick = finalist line · top {{ race?.finalist_count }} of {{ standing.of }} · CLK-21
                        </template>
                    </ThresholdMeter>
                </div>
                <p class="citation" style="margin-block-start: var(--space-2)">
                    {{ standing.frozen ? 'frozen at the finalist cutoff' : `aggregate as of ${standing.asOf} · updated daily` }}
                    · individual approvals are secret · Art. II §2
                </p>
            </template>
            <template v-else>
                <p class="gloss">
                    <template v-if="!race">
                        Awaiting board validation — the race binding (and with it the standing)
                        appears once F-ELB-002 validates residency.
                    </template>
                    <template v-else>
                        No standings aggregate exists for this race yet — see the count on the
                        open ballot once the daily rollup runs.
                    </template>
                </p>
                <Btn
                    v-if="race"
                    :as="Link"
                    :href="`/elections/${race.election_id}/open-ballot?race=${race.id}`"
                    variant="secondary"
                    size="sm"
                >See the race standings</Btn>
            </template>
        </Card>

        <!-- ───────────────────────────── endorsements + requests -->
        <div class="grid-2">
            <Card as="section" title="Endorsements">
                <p class="cluster" style="gap: var(--space-1)">
                    <OrgChip v-for="org in endorsements.orgs" :key="org.id" :name="org.name" :org-type="org.type" />
                    <TagChip v-if="endorsements.individual.total">
                        {{ endorsements.individual.total }} individual endorsements ·
                        {{ endorsements.individual.public }} public / {{ endorsements.individual.private }} private
                    </TagChip>
                    <!-- zero-endorsement candidacies are first-class -->
                    <TagChip v-if="noEndorsements">no organizational endorsements — running unendorsed is first-class</TagChip>
                </p>
                <details v-if="endorsements.publicWeb.length" class="about-surface" style="margin-block-start: var(--space-3)">
                    <summary>Public endorsement web — {{ endorsements.publicWeb.length }} public individual endorsers</summary>
                    <div class="about-surface-body">
                        <ul style="margin: 0; padding-inline-start: var(--space-5)">
                            <li v-for="endorser in endorsements.publicWeb" :key="endorser.user_id">
                                {{ endorser.name }}
                                <StatusBadge v-if="endorser.alsoCandidate" tone="neutral">also a candidate</StatusBadge>
                                <template v-if="endorser.endorses.length">
                                    — also endorses:
                                    <template v-for="(target, i) in endorser.endorses" :key="target.candidacy_id">
                                        <template v-if="i > 0">, </template>
                                        <Link :href="`/candidates/${target.candidacy_id}`">{{ target.name }}</Link>
                                    </template>
                                </template>
                            </li>
                        </ul>
                        <p class="citation">
                            individual endorsers disclose by choice · org endorsements are always
                            public · polymorphic endorsements table
                        </p>
                    </div>
                </details>
                <p class="citation" style="margin-block-start: var(--space-2)">
                    Endorsements inform — they never gate. Art. I; Art. II §2.
                </p>
            </Card>

            <Card as="section" title="Endorsement requests">
                <template v-if="isOwner">
                    <DataTable
                        v-if="requests.length"
                        :columns="requestColumns"
                        :rows="requests"
                        caption="Endorsement requests filed by this candidacy"
                    >
                        <template #cell-requested_at="{ value }">{{ fmtDate(value) }}</template>
                        <template #cell-status="{ value }">
                            <StatusBadge :tone="value === 'granted' ? 'success' : value === 'declined' ? 'danger' : 'info'">
                                {{ value }}{{ value === 'granted' ? ' · grants R-07' : '' }}
                            </StatusBadge>
                        </template>
                    </DataTable>
                    <p v-else class="gloss">No requests yet.</p>

                    <form novalidate style="margin-block-start: var(--space-3)" @submit.prevent="submitRequest">
                        <Field
                            label="Ask an organization for its endorsement — F-CAN-002"
                            hint="The org's agent decides via F-ORG-002; a grant is public and grants R-07."
                            :error="requestForm.errors.organization_id || errors.constitution"
                        >
                            <template #control="{ id, invalid, describedBy }">
                                <select
                                    :id="id"
                                    v-model="requestForm.organization_id"
                                    class="field-input"
                                    :aria-invalid="invalid ? 'true' : undefined"
                                    :aria-describedby="describedBy"
                                >
                                    <option value="" disabled>— select an organization —</option>
                                    <option v-for="org in organizations" :key="org.id" :value="org.id">{{ org.name }}</option>
                                </select>
                            </template>
                        </Field>
                        <Btn type="submit" variant="secondary" size="sm" :disabled="requestForm.processing || !requestForm.organization_id">
                            {{ requestForm.processing ? 'Filing F-CAN-002…' : 'Request endorsement' }}
                        </Btn>
                        <p v-if="!organizations.length" class="gloss" style="margin-block-start: var(--space-2)">
                            No active organizations registered on this instance yet.
                        </p>
                    </form>
                </template>
                <p v-else class="gloss">
                    Requests are managed by the candidate. Granted endorsements appear publicly in
                    the endorsements card.
                </p>
            </Card>
        </div>

        <!-- ────────────────────────────────────────────── public record -->
        <Card as="section" title="Public record">
            <h3>Votes in office</h3>
            <p class="gloss">
                <template v-if="candidacy.incumbent">Voting records arrive with the legislature pipeline (Phase C).</template>
                <template v-else>None — not an incumbent. A first-time candidate's record begins at registration.</template>
            </p>
            <h3 style="margin-block-start: var(--space-3)">Civic actions</h3>
            <template v-if="publicRecord.actions.length">
                <LogRow v-for="action in publicRecord.actions" :key="action.seq" :seq="action.seq">
                    {{ action.label }}
                    <span class="citation">{{ fmtDate(action.date) }}</span>
                </LogRow>
            </template>
            <p v-else class="gloss">
                No public record entries yet — participation appears here as it happens.
            </p>
            <p class="citation" style="margin-block-start: var(--space-2)">
                Participation is public; ballot choices are secret · Art. II §2
            </p>
        </Card>

        <!-- ──────────────────────────────────────── manage card (owner) -->
        <template v-if="isOwner">
            <FormCard
                :form="formMeta('F-CAN-001')"
                :inertia-form="profileForm"
                submit-label="Save statement — F-CAN-001"
                processing-label="Filing F-CAN-001…"
                @submit="submitProfile"
            >
                <Field
                    label="Platform statement"
                    hint="Public and self-managed; every save appends to your public record."
                    :error="profileForm.errors.platform_statement"
                >
                    <template #control="{ id, invalid, describedBy }">
                        <textarea
                            :id="id"
                            v-model="profileForm.platform_statement"
                            class="field-input"
                            rows="5"
                            :aria-invalid="invalid ? 'true' : undefined"
                            :aria-describedby="describedBy"
                        ></textarea>
                    </template>
                </Field>
            </FormCard>

            <Card as="section" title="Withdraw candidacy — F-CAN-003">
                <p class="cc-small">
                    Withdrawal is a permanent public record (terminal state). It is only possible
                    <strong>before</strong> the finalist cutoff — once the ballot is published, it
                    never changes.
                </p>
                <template v-if="candidacy.withdrawn">
                    <StatusBadge tone="danger">Withdrawn — recorded on the public record</StatusBadge>
                </template>
                <template v-else-if="!can.withdraw">
                    <Btn variant="danger" disabled title="ballot locked at the finalist cutoff — withdrawal closed · CLK-21">
                        Withdraw candidacy
                    </Btn>
                    <CitationLine text="ballot locked at the finalist cutoff — withdrawal closed · CLK-21" />
                </template>
                <template v-else>
                    <div class="cluster">
                        <Btn v-if="!withdrawConfirming" variant="danger" icon="alert-triangle" @click="withdrawConfirming = true">
                            Withdraw candidacy
                        </Btn>
                        <template v-else>
                            <span><strong>This cannot be undone.</strong> Withdrawal is a permanent public record.</span>
                            <Btn variant="danger" :disabled="withdrawForm.processing" @click="submitWithdraw">
                                {{ withdrawForm.processing ? 'Filing F-CAN-003…' : 'Confirm withdrawal — F-CAN-003' }}
                            </Btn>
                            <Btn variant="ghost" @click="withdrawConfirming = false">Keep standing</Btn>
                        </template>
                    </div>
                </template>
            </Card>
        </template>
    </PageScaffold>
</template>
