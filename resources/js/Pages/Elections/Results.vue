<script setup>
/**
 * Elections/Results — FE-B6 (PHASE_B_DESIGN_frontend.md §B.6 + §C).
 *
 * Renders StvRoundPresenter's §C contract: key rounds (1–3, every elect
 * round, the final) inline with full tallies; mid rounds collapsed into
 * one "Rounds a–b" disclosure (heading + transfer breakdown only — same
 * StvRound component, parent decides placement). The CSV button streams
 * the full-precision count record. `tabulation.status === 'running'`
 * renders the instant-count banner and polls by partial reload.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import CitationLine from '@/Components/Ui/CitationLine.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import FormChip from '@/Components/Ui/FormChip.vue';
import PersonaChip from '@/Components/Ui/PersonaChip.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import TagChip from '@/Components/Ui/TagChip.vue';
import StvRound from '@/Components/Electoral/StvRound.vue';

const props = defineProps({
    surface: { type: Object, required: true },
    election: { type: Object, required: true },
    race: { type: Object, required: true },
    races: { type: Array, default: () => [] },
    tabulation: { type: Object, required: true },
    stv: { type: Object, default: null },
    auditStv: { type: Object, default: null },
    audits: { type: Array, default: () => [] },
    observers: { type: Array, default: () => [] },
    certification: { type: Object, default: null },
    can: { type: Object, default: () => ({ certify: false, recount: false }) },
    csvHref: { type: String, required: true },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

function fmt(iso) {
    return iso ? new Date(iso).toLocaleString() : '—';
}

/* -------------------------------------------------- tabulating poll ---- */

let pollTimer = null;
onMounted(() => {
    pollTimer = setInterval(() => {
        if (props.tabulation.status === 'running') {
            router.reload({
                only: ['tabulation', 'stv', 'auditStv', 'election', 'certification', 'can', 'audits'],
            });
        }
    }, 5000);
});
onBeforeUnmount(() => clearInterval(pollTimer));

/* -------------------------------------------------- rounds split (§C) -- */

function splitRounds(stv) {
    if (!stv) return null;
    const key = stv.display.filter((r) => r.tallies);
    const mid = stv.display.filter((r) => !r.tallies);
    return {
        opening: key.slice(0, -1),
        final: key[key.length - 1] ?? null,
        mid,
        midLabel: mid.length
            ? `Rounds ${mid[0].n}–${mid[mid.length - 1].n} — expand any round for its vote transfers`
            : null,
        electedRound: Object.fromEntries(stv.elected.map((e) => [e.name, e.round])),
    };
}

const main = computed(() => splitRounds(props.stv));
const rerun = computed(() => splitRounds(props.auditStv));

const profileHref = (id) => (id ? `/candidates/${id}` : null);

/* Write-ins that took part in the count (footnote, §B.6). */
const writeIns = computed(() => {
    if (!props.stv) return [];
    const names = new Set();
    const scan = (ref) => {
        if (ref && typeof ref === 'object' && ref.write_in) names.add(ref.name);
    };
    props.stv.display.forEach((round) => {
        (round.tallies ?? []).forEach(([ref]) => scan(ref));
        (round.transfer?.to ?? []).forEach(([ref]) => scan(ref));
        if (round.transfer) scan(round.transfer.from);
    });
    props.stv.elected.forEach((e) => e.write_in && names.add(e.name));
    return [...names];
});

/* -------------------------------------------------- recount (cause) ---- */

const recountOpen = ref(false);
const recountCause = ref('');
const posting = ref(false);

function certify() {
    posting.value = true;
    router.post(`/elections/${props.election.id}/certify`, {}, {
        preserveScroll: true,
        onFinish: () => (posting.value = false),
    });
}

function orderRecount() {
    posting.value = true;
    router.post(`/elections/${props.election.id}/recount`, { cause: recountCause.value }, {
        preserveScroll: true,
        onSuccess: () => {
            recountOpen.value = false;
            recountCause.value = '';
        },
        onFinish: () => (posting.value = false),
    });
}

function switchRace(event) {
    router.get(`/elections/${props.election.id}/results`, { race: event.target.value });
}

const phaseBadge = computed(() => ({
    tabulating: { tone: 'warning', icon: 'clock', label: 'Tabulating' },
    certified: { tone: 'success', icon: 'check', label: 'Certified' },
    recount: { tone: 'danger', icon: 'refresh-cw', label: 'Recount — audit re-run' },
}[props.election.certSubStep] ?? { tone: 'warning', icon: 'clock', label: 'Certifying' }));
</script>

<template>
    <PageScaffold :surface="surface" :title="`Results — ${race.label}`">
        <template #intro>
            Round-by-round PR-STV with the Droop quota. Surpluses transfer at fractional value
            (Gregory method); every one of the {{ race.seats }}
            {{ race.seats === 1 ? 'seat' : 'seats' }} fills in this single count. Write-ins are
            tabulated identically to finalists. The full record below is public and auditable.
        </template>

        <p class="citation">
            STV with Droop quota · fractional (Gregory) surplus transfers · hardened · Art. II §2
        </p>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <div v-if="races.length > 1" class="field" style="max-inline-size: 32rem">
            <label class="field-label" for="race-picker">Race</label>
            <select id="race-picker" class="select" :value="race.id" @change="switchRace">
                <option v-for="r in races" :key="r.id" :value="r.id">{{ r.label }}</option>
            </select>
        </div>

        <!-- ===================================== tabulating poll state === -->
        <Banner
            v-if="tabulation.status === 'running'"
            tone="info"
            icon="clock"
            title="Tabulating — instant count in progress."
        >
            The ranked window has closed and the protected counting engine is re-running every
            ballot. This page refreshes itself until the record lands.
            <CitationLine text="VoteCountingService · hardened · Art. II §2" />
        </Banner>

        <Banner
            v-else-if="tabulation.status === 'none'"
            tone="warning"
            role="status"
            title="No count record exists for this race yet."
        >
            Tabulation dispatches automatically when the ranked window closes — no official can
            start, stop, or skip it.
        </Banner>

        <template v-if="stv">
            <div class="cluster" style="gap: var(--space-6)">
                <Stat :value="stv.total.toLocaleString()" label="valid ballots" />
                <Stat
                    :value="stv.quota.toLocaleString()"
                    label="Droop quota = floor(votes ÷ (seats+1)) + 1"
                    accent
                />
                <Stat :value="stv.seats" label="seats — all filled in one count" />
                <Stat :value="stv.rounds" label="counting rounds" />
            </div>

            <!-- ===================================== elected ============= -->
            <Card as="section" :title="`Elected — ${stv.elected.length} of ${stv.seats} seats`">
                <div class="cluster">
                    <span v-for="winner in stv.elected" :key="winner.candidacy_id" class="cluster" style="gap: var(--space-1)">
                        <PersonaChip :name="winner.name" />
                        <Link :href="profileHref(winner.candidacy_id)" class="citation">profile</Link>
                        <StatusBadge tone="success" icon="check">elected · round {{ winner.round }}</StatusBadge>
                        <span class="persona-roles">seat {{ winner.seat_no }}</span>
                        <TagChip v-if="winner.write_in">write-in</TagChip>
                    </span>
                </div>
                <p class="citation" style="margin-block-start: var(--space-3)">
                    <template v-if="certification">
                        Certified {{ fmt(certification.certified_at) }} · F-ELB-004 · Election Results
                        Certification · Art. II §2
                    </template>
                    <template v-else>
                        Certification pending · F-ELB-004 · Election Results Certification ·
                        available to R-08 · Art. II §2
                    </template>
                </p>
            </Card>

            <!-- ===================================== the count =========== -->
            <Card as="section" title="The count, round by round">
                <p class="gloss">
                    Gold tick = the Droop quota. Reaching it elects a candidate; their surplus
                    transfers onward at fractional value so no vote is wasted.
                </p>
                <span class="visually-hidden">Droop quota {{ stv.quota.toLocaleString() }}</span>

                <StvRound
                    v-for="round in main.opening"
                    :key="round.n"
                    :round="round"
                    :quota="stv.quota"
                    :scale="stv.scale"
                    :elected-round="main.electedRound"
                    :profile-href="profileHref"
                />

                <details v-if="main.mid.length" class="about-surface" style="margin-block: var(--space-4)">
                    <summary>{{ main.midLabel }}</summary>
                    <div class="about-surface-body">
                        <StvRound
                            v-for="round in main.mid"
                            :key="round.n"
                            :round="round"
                            :quota="stv.quota"
                            :scale="stv.scale"
                            :elected-round="main.electedRound"
                            :profile-href="profileHref"
                        />
                    </div>
                </details>

                <StvRound
                    v-if="main.final"
                    :round="main.final"
                    :quota="stv.quota"
                    :scale="stv.scale"
                    :elected-round="main.electedRound"
                    :profile-href="profileHref"
                    default-open
                />

                <p v-if="writeIns.length" class="cc-small" style="margin-block-start: var(--space-3)">
                    <TagChip>write-in</TagChip>
                    {{ writeIns.join(', ') }} entered as
                    {{ writeIns.length === 1 ? 'a write-in and was' : 'write-ins and were' }}
                    tabulated identically — transfers flow onward like any other.
                    <CitationLine text="Write-in of any validated candidate always allowed · Art. II §2" />
                </p>

                <p style="margin-block-start: var(--space-3)">
                    <Btn as="a" :href="csvHref" variant="secondary" size="sm" icon="file-text">
                        Download full count record (CSV)
                    </Btn>
                    <span class="citation"> full precision · streamed from tabulation_rounds</span>
                </p>
            </Card>

            <!-- ===================================== audit re-run ======== -->
            <Card v-if="auditStv" as="section" title="Audit re-run (recount)">
                <div class="cluster" style="margin-block-end: var(--space-3)">
                    <StatusBadge tone="danger" icon="refresh-cw">kind: audit_rerun</StatusBadge>
                    <template v-for="audit in audits" :key="audit.id">
                        <StatusBadge
                            v-if="audit.outcome"
                            :tone="audit.outcome === 'reaffirmed' ? 'success' : 'warning'"
                            :icon="audit.outcome === 'reaffirmed' ? 'check' : 'alert-triangle'"
                        >outcome: {{ audit.outcome }}</StatusBadge>
                        <StatusBadge v-else tone="warning" icon="clock">re-run in progress</StatusBadge>
                        <span class="citation">cause: {{ audit.cause }} · ordered {{ fmt(audit.ordered_at) }}</span>
                    </template>
                </div>
                <p class="gloss">
                    A recount is an audit re-run of the stored ballots through the same protected
                    engine — there is no hand count. Identical inputs reproduce an identical
                    record hash.
                </p>
                <details class="about-surface">
                    <summary>Re-run record — {{ auditStv.rounds }} rounds</summary>
                    <div class="about-surface-body">
                        <span class="visually-hidden">Droop quota {{ auditStv.quota.toLocaleString() }}</span>
                        <StvRound
                            v-for="round in [...rerun.opening, ...rerun.mid, ...(rerun.final ? [rerun.final] : [])]"
                            :key="round.n"
                            :round="round"
                            :quota="auditStv.quota"
                            :scale="auditStv.scale"
                            :elected-round="rerun.electedRound"
                            :profile-href="profileHref"
                        />
                        <p class="citation" data-no-i18n>record hash {{ auditStv.tabulation.record_hash }}</p>
                    </div>
                </details>
            </Card>
        </template>

        <!-- ===================================== certification =========== -->
        <Card as="section" title="Certification &amp; chain of custody">
            <p>
                The count ran under a public chain of custody. Observation and audit standing
                belongs to the endorsing organizations and to the candidates themselves; any
                voter can verify their own ballot by receipt hash.
            </p>
            <p class="citation">All factions can observe and audit · Art. II §2 · as implemented — observer standing transfers to endorsing organizations and candidates</p>

            <DataTable
                :columns="[
                    { key: 'name', label: 'Observer' },
                    { key: 'standing', label: 'Standing', mono: true },
                    { key: 'attestation', label: 'Attestation' },
                ]"
                :rows="observers"
                caption="Observers of record"
            >
                <template #cell-name="{ row }">
                    <Link v-if="row.href" :href="row.href">{{ row.name }}</Link>
                    <template v-else>{{ row.name }}</template>
                </template>
                <template #cell-attestation="{ row }">
                    <StatusBadge v-if="row.attested" tone="success" icon="check">attested</StatusBadge>
                    <StatusBadge v-else tone="neutral" icon="clock">observing</StatusBadge>
                </template>
            </DataTable>

            <div class="cluster" style="margin-block-start: var(--space-3)">
                <FormChip form-id="F-ELB-004" name="Election results certification" />
                <FormChip form-id="F-ELB-006" name="Recount/audit order" />
                <span class="gloss">
                    The count runs in-system, so a "recount" is an audit review: tabulation
                    re-runs and the chain of custody is re-verified — there is no hand-count.
                </span>
                <StatusBadge :tone="phaseBadge.tone" :icon="phaseBadge.icon">{{ phaseBadge.label }}</StatusBadge>
            </div>

            <p v-if="certification" class="citation" style="margin-block-start: var(--space-2)" data-no-i18n>
                certified by {{ certification.by }} · count record hash {{ certification.count_record_hash }}
            </p>

            <div v-if="can.certify || can.recount" class="cluster" style="margin-block-start: var(--space-3)">
                <Btn v-if="can.certify" variant="primary" size="sm" :disabled="posting" @click="certify">
                    Certify results — F-ELB-004
                </Btn>
                <Btn
                    v-if="can.recount && !recountOpen"
                    variant="danger"
                    size="sm"
                    @click="recountOpen = true"
                >Order recount — F-ELB-006</Btn>
            </div>
            <div v-if="recountOpen" class="field" style="margin-block-start: var(--space-2)">
                <label class="field-label" for="recount-cause">Cause for the audit re-run (required)</label>
                <textarea id="recount-cause" v-model="recountCause" class="field-input" rows="2"></textarea>
                <span class="field-hint">The engine rejects an order without a stated cause.</span>
                <div class="cluster" style="margin-block-start: var(--space-2)">
                    <Btn variant="danger" size="sm" :disabled="posting || !recountCause.trim()" @click="orderRecount">
                        Confirm recount order
                    </Btn>
                    <Btn variant="ghost" size="sm" @click="recountOpen = false; recountCause = ''">Cancel</Btn>
                </div>
            </div>
        </Card>

        <!-- ============== RCV single-winner variant (Phase D races only) == -->
        <Card v-if="race.seat_kind === 'single' && stv" as="section" title="Single-winner variant — individual executive (RCV)">
            <p class="cc-small">
                Single-winner ranked-choice voting applies <strong>only</strong> to the
                individual executive office model. The top 4 runners-up become the executive's
                advisors and alternates automatically — derived by sequential exclusion.
            </p>
            <p class="citation">Single-winner RCV only for the individual executive · top-4 runners-up as advisors · Art. III §3</p>
        </Card>

        <template #about>
            <p>
                <strong>Entity state machine:</strong> Election — Tabulating → Certified
                (→ Recount). The countback engine (WF-ELE-03) re-runs these same ballots when a
                vacancy opens.
            </p>
        </template>
    </PageScaffold>
</template>
