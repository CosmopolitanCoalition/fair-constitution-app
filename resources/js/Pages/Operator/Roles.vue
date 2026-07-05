<script setup>
/**
 * Operator/Roles — mockups-v3-wiring Phase 4 (PHASE_4_DESIGN_peerage.md §3.1).
 * Design contract: mockups/v3/operator/roles.html.
 *
 * The qualify → request → approve → join board over the REAL endpoints
 * (POST /operator/roles/{qualify,request,approve,revoke} — thin wrappers over
 * CapabilityProber / CapabilityService / MeshRoleGrantService, verb-for-verb
 * with the `mesh:role` CLI). The page renders the services' truth — role and
 * channel states come from MeshGateService.roles()/channels(); the pending
 * list carries LIVE meter reads from PeerUpgradeAgreementService. A
 * ConstitutionalViolation's message (with its citation) is flashed back
 * verbatim and shown honestly.
 *
 * Settled language (design §3.4): "authority" attaches to a JURISDICTION —
 * a home-copy fact about a place — never to a node as a rank; "become a peer"
 * is one process, and this ladder is only the separate, trust-gated role
 * elevation. The G3c read-write petition ladder is NOT presented here
 * (design flag 1 — the legacy /federation page keeps it).
 *
 * Gating mirrors /operator/operations: any signed-in citizen can reach the
 * shell, but `roles` is built only for an auth:operator session — everyone
 * else sees the operator sign-in prompt.
 */
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import Field from '@/Components/Ui/Field.vue';
import Icon from '@/Components/Ui/Icon.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

/* v3 player chrome (MASTER_PLAN Phase 2+). */
defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    authed: { type: Boolean, default: false },
    operator: { type: String, default: null },
    /** null for a non-operator session — { scope, named, channels, pending }. */
    roles: { type: Object, default: null },
    /** The last qualify probe, flashed by POST /operator/roles/qualify. */
    probe: { type: Object, default: null },
    /** Founding node — every role self-asserts (no dual-meter, no scope). */
    founding: { type: Boolean, default: false },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
const rolesError = computed(() => page.props.errors?.roles ?? null);

/* ------------------------------------------------ defensive prop reads -- */
const named = computed(() => props.roles?.named ?? []);
const channels = computed(() => props.roles?.channels ?? []);
const pending = computed(() => props.roles?.pending ?? []);
const scope = computed(() => props.roles?.scope ?? null);

const byCap = computed(() =>
    Object.fromEntries(channels.value.map((c) => [c.capability, c])),
);

const activeCount = computed(
    () => channels.value.filter((c) => c.state === 'established').length,
);
const governedCount = computed(
    () => channels.value.filter((c) => c.kind === 'governed').length,
);

/* ----------------------------------------------- state → plain language -- */
/* Channel states (MeshGateService::STATE_*) → the mockup's STATE_PILL map. */
const STATE_PILL = {
    established: { tone: 'success', label: 'Active' },
    qualifiable: { tone: 'warning', label: 'Ready to turn on' },
    'needs-config': { tone: 'info', label: 'Needs setup' },
    requested: { tone: 'warning', label: 'Waiting for approval' },
    lapsed: { tone: 'neutral', label: 'Stopped' },
};
const statePill = (state) => STATE_PILL[state] ?? STATE_PILL.qualifiable;

/* Named-role roll-ups (MeshGateService::ROLE_*). */
const ROLE_PILL = {
    established: { tone: 'success', label: 'Active — every channel on' },
    partial: { tone: 'info', label: 'Partly on' },
    requested: { tone: 'warning', label: 'Waiting for approval' },
    qualifiable: { tone: 'warning', label: 'Ready to turn on' },
    'needs-config': { tone: 'info', label: 'Needs setup' },
};
const rolePill = (state) => ROLE_PILL[state] ?? ROLE_PILL['needs-config'];

/* A role is self-asserted when every channel it groups is. */
const roleSelfAsserted = (role) =>
    (role.channels ?? []).every((c) => byCap.value[c]?.kind === 'self-asserted');

const chipKind = (cap) =>
    byCap.value[cap]?.kind === 'self-asserted' ? 'self' : 'governed';

/* The prober's one-line qualification detail for a channel row. */
const qualifyDetail = (channel) =>
    (channel.gates ?? []).find((g) => g.key === `${channel.capability}.qualify`)?.detail ?? null;

const shortId = (id) => (id ? `${String(id).slice(0, 8)}…` : '—');
const fmtWhen = (iso) => (iso ? new Date(iso).toLocaleString() : '—');

/* --------------------------------------- the lifecycle form (useForm) ---- */
const act = useForm({
    capability: 'mirror',
    scope: '',
});
const actPayload = (data) => ({
    capability: data.capability,
    // Blank = the server's default scope (the root jurisdiction).
    scope: data.scope.trim() !== '' ? data.scope.trim() : null,
});
const submitQualify = () =>
    act.transform(actPayload).post('/operator/roles/qualify', { preserveScroll: true });
const submitRequest = () =>
    act.transform(actPayload).post('/operator/roles/request', { preserveScroll: true });

const actIsGoverned = computed(
    () => byCap.value[act.capability]?.kind === 'governed',
);

/* ------------------------------------------------- per-row actions ------- */
const busy = ref(null);
function post(url, data, key) {
    busy.value = key;
    router.post(url, data, {
        preserveScroll: true,
        onFinish: () => {
            busy.value = null;
        },
    });
}
const qualifyRow = (cap) =>
    post('/operator/roles/qualify', { capability: cap, scope: null }, `${cap}:qualify`);
const requestRow = (cap) =>
    post('/operator/roles/request', { capability: cap, scope: null }, `${cap}:request`);
const revokeRow = (cap) =>
    post('/operator/roles/revoke', { capability: cap }, `${cap}:revoke`);
const approveRow = (id) =>
    post('/operator/roles/approve', { proposal_id: id }, `approve:${id}`);

/* --------------------------------------------------- table columns ------- */
const CHANNEL_COLUMNS = [
    { key: 'capability', label: 'Channel' },
    { key: 'kind', label: 'Consent' },
    { key: 'what', label: 'What it does' },
    { key: 'state', label: 'On this node' },
    { key: 'actions', label: 'Actions' },
];
const PENDING_COLUMNS = [
    { key: 'capability', label: 'Channel' },
    { key: 'requested_by_server_id', label: 'Requested by', mono: true },
    { key: 'consent_leg', label: 'Consent leg' },
    { key: 'meters', label: 'Meters' },
    { key: 'created_at', label: 'Opened' },
    { key: 'actions', label: 'Actions' },
];

/* The lifecycle explainer (mirrors the mockup's §3 strip word-for-word). */
const LIFECYCLE = [
    { node: 'qualify', words: 'the node proves it can run the channel (reachable, on the right version, and has the storage and data the channel needs)' },
    { node: 'request', words: 'the operator asks for the capability over a named peer or jurisdiction' },
    { node: 'approve', words: 'the dual-meter consents — operator board, or the seated government' },
    { node: 'join', words: 'the channel turns on; it now shows as Active' },
];

/* The three consent meters (mockup §4 — explainer copy; live reads ride the pending rows). */
const METERS = [
    {
        id: 'A', name: 'the operator board', super: false,
        who: 'the active operators (neutral)',
        threshold: '1 operator → just you · 2 → unanimity · 3+ → two-thirds',
        applies: 'the bootstrap path, before a government is seated',
    },
    {
        id: 'B', name: 'the seated government', super: true,
        who: 'the constituent legislatures, by supermajority (a multi-jurisdiction vote)',
        threshold: 'supermajority of constituent jurisdictions',
        applies: 'the moment a legislature is seated — it SUPERSEDES Meter A',
    },
    {
        id: 'C', name: 'co-affected peers', super: false,
        who: 'every peer whose subtree the channel would touch',
        threshold: 'unanimity (any one peer can refuse)',
        applies: 'only channels that act under a peer’s zone — broker.dns, authority.grant',
    },
];
</script>

<template>
    <PageScaffold :surface="surface">
        <template #intro>
            A box’s “role” is nothing more than the set of capability channels it runs.
            Trust here is composable — you add the channels you can serve, one at a time.
            There are no tiers to climb and no rank to earn.
        </template>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="rolesError" tone="emergency" title="Refused">{{ rolesError }}</Banner>

        <!-- Not an operator → the same sign-in gate as /operator/operations. -->
        <Card v-if="!authed" title="Operator sign-in required">
            <p>
                The roles board is shown only to a signed-in operator. Operator accounts live on
                the operator plane — they have no link to citizen users and carry no citizen power.
            </p>
            <p>
                <Btn as="a" href="/operator/login" variant="primary">Sign in as an operator</Btn>
            </p>
        </Card>

        <template v-else-if="roles">
            <div class="plane-wall">
                <span><Icon name="shield" size="sm" /></span>
                <div>
                    <strong>Off the constitutional plane.</strong>
                    Running a node is infrastructure, not a citizen privilege — it buys you no extra
                    vote, no seat, no say in any constitutional act. Operator accounts have no link
                    to citizen users.
                    <br />
                    <span class="citation">
                        Signed in as {{ operator ?? 'operator' }} · a grant always attaches to a
                        place{{ scope ? ` — default scope: the root jurisdiction (${shortId(scope)})` : '' }} —
                        never to a node as a rank.
                    </span>
                </div>
            </div>

            <!-- ==================== 1 · the four named roles ==================== -->
            <section aria-labelledby="roles-h" class="stack">
                <h2 id="roles-h">The four named roles</h2>
                <p class="page-intro">
                    Four friendly names group the nine channels — no new power, just a grouping.
                    Each card shows its channel set, the duty you take on, and whether it turns on
                    by itself or needs a government decision.
                </p>

                <div class="op-role-grid">
                    <div
                        v-for="r in named"
                        :key="r.role"
                        class="op-role-card"
                        :class="{ 'op-role--recommended': r.recommended }"
                    >
                        <div class="cluster" style="justify-content: space-between; align-items: flex-start">
                            <span class="orc-title">{{ r.label }}</span>
                            <span v-if="r.recommended" class="pill pill--planned">Recommended first node</span>
                            <span v-else-if="roleSelfAsserted(r)" class="pill pill--live">Self-asserted</span>
                            <span v-else class="pill pill--info">Governed</span>
                        </div>

                        <p style="font-size: var(--text-sm)">{{ r.what }}</p>
                        <p class="orc-duty">Your duty: {{ r.duty }}</p>

                        <div class="orc-channels">
                            <span
                                v-for="c in r.channels"
                                :key="c"
                                class="channel-chip"
                                :class="`channel-chip--${chipKind(c)}`"
                                :title="statePill(r.channel_states?.[c]).label"
                            >{{ c }}</span>
                        </div>

                        <p style="margin: 0">
                            <StatusBadge :tone="rolePill(r.state).tone">
                                On this box: {{ rolePill(r.state).label }}
                            </StatusBadge>
                        </p>

                        <span class="citation">
                            {{
                                roleSelfAsserted(r)
                                    ? 'Self-asserted — every channel goes live on one click; no gate.'
                                    : 'Governed — requested, then dual-meter approval (operator board, or the seated government).'
                            }}
                        </span>
                        <span v-if="r.petition" class="gloss">
                            Read–write authority is a fact about a place — where its home copy lives.
                            It moves between consenting nodes per jurisdiction; it is not a rank this
                            box petitions for.
                        </span>
                    </div>
                </div>

                <p class="advanced-note">
                    <span class="channel-chip channel-chip--self">self</span> a self-asserted channel ·
                    <span class="channel-chip channel-chip--governed">governed</span> a governed channel
                </p>
            </section>

            <!-- ==================== 2 · the nine channels ==================== -->
            <section aria-labelledby="chan-h" class="stack">
                <h2 id="chan-h">The nine channels</h2>
                <p class="page-intro">
                    The whole closed vocabulary. Three channels are <strong>self-asserted</strong> —
                    <code>mesh.member</code>, <code>mirror</code>, and <code>etl</code> — and need no
                    gate at all: they only ever describe what your own box does. The other six are
                    <strong>governed</strong>, because each one grants a duty over others, or hangs a
                    name under a peer’s zone.
                </p>

                <div v-if="founding" class="plane-wall" style="margin-bottom: var(--space-4)">
                    <span><Icon name="info" size="sm" /></span>
                    <span>
                        <strong>You are the founding operator.</strong> There is no mesh to answer to and
                        no government seated yet, so every role is yours to switch on directly — governed
                        channels included. Once your world is founded and a government seats, governed
                        channels return to the dual-meter consent path for any later change.
                    </span>
                </div>

                <div class="cluster" style="gap: var(--space-6)">
                    <Stat :value="`${activeCount} / ${channels.length}`" label="channels active on this box" />
                    <Stat :value="founding ? 0 : governedCount" :label="founding ? 'awaiting consent (none — you are founding)' : 'governed channels (dual-meter)'" />
                    <Stat :value="pending.length" label="open role-grant requests" :accent="pending.length > 0" />
                </div>

                <DataTable
                    :columns="CHANNEL_COLUMNS"
                    :rows="channels"
                    row-key="capability"
                    caption="The nine capability channels, their consent kind, live state on this node, and actions"
                >
                    <template #cell-capability="{ row }">
                        <span class="channel-chip" :class="`channel-chip--${row.kind === 'self-asserted' ? 'self' : 'governed'}`">
                            {{ row.capability }}
                        </span>
                        <div class="gloss">{{ row.label }}</div>
                    </template>

                    <template #cell-kind="{ row }">
                        <StatusBadge :tone="row.kind === 'self-asserted' ? 'success' : 'warning'">
                            {{ row.kind }}
                        </StatusBadge>
                    </template>

                    <template #cell-what="{ row }">
                        <span style="font-size: var(--text-sm)">{{ row.what }}</span>
                        <span
                            v-if="row.affects_peer_subtree"
                            class="relation-chip"
                            title="acts under a peer’s zone — co-affected peers must consent"
                        >Meter C</span>
                    </template>

                    <template #cell-state="{ row }">
                        <StatusBadge :tone="statePill(row.state).tone">{{ statePill(row.state).label }}</StatusBadge>
                        <div v-if="row.state !== 'established' && qualifyDetail(row)" class="gloss">
                            {{ qualifyDetail(row) }}
                        </div>
                    </template>

                    <template #cell-actions="{ row }">
                        <div class="cluster" style="gap: var(--space-2)">
                            <template v-if="row.state === 'established'">
                                <Btn
                                    variant="danger"
                                    size="sm"
                                    :disabled="busy !== null"
                                    @click="revokeRow(row.capability)"
                                >{{ busy === `${row.capability}:revoke` ? 'Dropping…' : 'Drop' }}</Btn>
                            </template>
                            <template v-else-if="row.state === 'requested'">
                                <span class="gloss">Waiting on the dual-meter — see the pending list below.</span>
                            </template>
                            <template v-else-if="founding">
                                <!-- Founding node: every role is yours to switch on directly. -->
                                <Btn
                                    variant="primary"
                                    size="sm"
                                    :disabled="busy !== null"
                                    @click="requestRow(row.capability)"
                                >{{ busy === `${row.capability}:request` ? 'Turning on…' : 'Turn on' }}</Btn>
                            </template>
                            <template v-else>
                                <Btn
                                    variant="ghost"
                                    size="sm"
                                    :disabled="busy !== null"
                                    @click="qualifyRow(row.capability)"
                                >{{ busy === `${row.capability}:qualify` ? 'Probing…' : 'Qualify' }}</Btn>
                                <Btn
                                    :variant="row.kind === 'self-asserted' ? 'primary' : 'secondary'"
                                    size="sm"
                                    :disabled="busy !== null"
                                    @click="requestRow(row.capability)"
                                >{{
                                    busy === `${row.capability}:request`
                                        ? 'Sending…'
                                        : row.kind === 'self-asserted' ? 'Turn on' : 'Request'
                                }}</Btn>
                            </template>
                        </div>
                    </template>
                </DataTable>

                <div class="plane-wall">
                    <span><Icon name="info" size="sm" /></span>
                    <div>
                        <strong>Self-asserted vs governed.</strong>
                        A self-asserted channel touches nobody but you, so it is yours to switch on.
                        A governed channel either grants a duty over other people or writes a name
                        under a peer’s zone — so it is requested, never claimed, and approved by the
                        dual-meter below.
                    </div>
                </div>
            </section>

            <!-- ==================== 3 · how a channel goes live ==================== -->
            <section aria-labelledby="life-h" class="stack">
                <h2 id="life-h">How a channel goes live</h2>
                <p class="page-intro">
                    A governed channel walks four plain steps. A self-asserted channel skips straight
                    to Active — there is no request and no approval to wait on.
                </p>

                <Card>
                    <div class="fsm">
                        <template v-for="(s, i) in LIFECYCLE" :key="s.node">
                            <span v-if="i" class="fsm-arrow" aria-hidden="true"><Icon name="arrow-right" size="sm" /></span>
                            <span class="fsm-node">{{ s.node }}</span>
                        </template>
                        <span class="fsm-arrow" aria-hidden="true"><Icon name="arrow-right" size="sm" /></span>
                        <span class="pill pill--live">Active</span>
                    </div>

                    <ol class="stack" style="font-size: var(--text-sm); padding-inline-start: var(--space-4)">
                        <li v-for="s in LIFECYCLE" :key="s.node">
                            <span class="advanced-note">{{ s.node }}</span> — {{ s.words }}
                        </li>
                    </ol>

                    <p class="advanced-note">
                        Self-asserted (mesh.member · mirror · etl):
                        <span class="fsm-node">establish</span>
                        <Icon name="arrow-right" size="sm" />
                        <span class="pill pill--live">Active</span>
                        — one click, no gate.
                    </p>
                </Card>

                <Card title="Run a step from here" as="section">
                    <p class="gloss">
                        Qualify probes whether this box can host the channel — it changes nothing.
                        Request turns a self-asserted channel on, or opens a governed request for the
                        dual-meter to decide.
                    </p>

                    <form class="stack" @submit.prevent="submitRequest">
                        <Field label="Channel" :error="act.errors.capability">
                            <template #control="{ id, invalid, describedBy }">
                                <select
                                    :id="id"
                                    v-model="act.capability"
                                    class="select"
                                    :aria-invalid="invalid ? 'true' : undefined"
                                    :aria-describedby="describedBy"
                                >
                                    <optgroup label="Self-asserted — one click, no gate">
                                        <option
                                            v-for="c in channels.filter((x) => x.kind === 'self-asserted')"
                                            :key="c.capability"
                                            :value="c.capability"
                                        >{{ c.capability }} — {{ c.label }}</option>
                                    </optgroup>
                                    <optgroup label="Governed — dual-meter approval">
                                        <option
                                            v-for="c in channels.filter((x) => x.kind === 'governed')"
                                            :key="c.capability"
                                            :value="c.capability"
                                        >{{ c.capability }} — {{ c.label }}</option>
                                    </optgroup>
                                </select>
                            </template>
                        </Field>

                        <Field
                            label="Scope (optional jurisdiction id)"
                            :error="act.errors.scope"
                            hint="Leave blank for the root jurisdiction. A grant acts over a place — never over the mesh as a rank."
                        >
                            <template #control="{ id, invalid, describedBy }">
                                <input
                                    :id="id"
                                    v-model="act.scope"
                                    class="field-input"
                                    type="text"
                                    spellcheck="false"
                                    :aria-invalid="invalid ? 'true' : undefined"
                                    :aria-describedby="describedBy"
                                />
                            </template>
                        </Field>

                        <div class="cluster" style="gap: var(--space-2)">
                            <Btn variant="secondary" :disabled="act.processing" @click="submitQualify">
                                {{ act.processing ? 'Working…' : 'Qualify — probe this box' }}
                            </Btn>
                            <Btn variant="primary" type="submit" :disabled="act.processing">
                                {{
                                    act.processing
                                        ? 'Working…'
                                        : actIsGoverned ? 'Request the channel' : 'Turn the channel on'
                                }}
                            </Btn>
                        </div>
                    </form>

                    <Card v-if="probe" inset>
                        <p style="margin: 0">
                            <StatusBadge :tone="probe.ok ? 'success' : 'danger'">
                                {{ probe.ok ? 'Qualified' : 'Not qualified' }}
                            </StatusBadge>
                            <span class="channel-chip" style="margin-inline-start: var(--space-2)">{{ probe.capability }}</span>
                        </p>
                        <p style="font-size: var(--text-sm)">{{ probe.detail }}</p>
                        <p v-if="probe.affects_peer_subtree" class="gloss">
                            This channel acts under a peer’s zone — co-affected peers must also
                            consent (Meter C).
                        </p>
                    </Card>
                </Card>
            </section>

            <!-- ==================== 4 · approvals + the three meters ==================== -->
            <section aria-labelledby="pending-h" class="stack">
                <h2 id="pending-h">Waiting for approval</h2>
                <p class="page-intro">
                    A governed channel is approved by a dual-meter — operators while no government is
                    seated, the seated government the moment one exists — plus a third meter for the
                    few channels that reach under a peer’s zone. The meters below are live reads;
                    nothing here re-counts a vote.
                </p>

                <div class="meter-abc" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr)); gap: var(--space-4)">
                    <div
                        v-for="m in METERS"
                        :key="m.id"
                        class="meter-card"
                        :class="{ 'meter-card--super': m.super }"
                    >
                        <div class="cluster" style="justify-content: space-between; align-items: flex-start">
                            <span class="cluster" style="gap: var(--space-2)">
                                <span class="mc-id">{{ m.id }}</span>
                                <strong>{{ m.name }}</strong>
                            </span>
                            <span v-if="m.super" class="pill pill--live">Supersedes A</span>
                        </div>
                        <p style="font-size: var(--text-sm); margin: 0"><strong>Who:</strong> {{ m.who }}</p>
                        <p style="font-size: var(--text-sm); margin: 0"><strong>Threshold:</strong> {{ m.threshold }}</p>
                        <p style="font-size: var(--text-sm); margin: 0"><strong>Applies:</strong> {{ m.applies }}</p>
                    </div>
                </div>

                <p v-if="pending.length === 0" class="gloss">
                    No open role-grant requests. A governed request you or a peer opens will appear
                    here with its live meter state.
                </p>

                <DataTable
                    v-else
                    :columns="PENDING_COLUMNS"
                    :rows="pending"
                    row-key="id"
                    caption="Open role-grant requests with live consent-meter reads"
                >
                    <template #cell-capability="{ row }">
                        <span class="channel-chip channel-chip--governed">{{ row.capability }}</span>
                        <div class="gloss">over place {{ shortId(row.scope_jurisdiction_id) }}</div>
                    </template>

                    <template #cell-requested_by_server_id="{ value }">
                        {{ shortId(value) }}
                    </template>

                    <template #cell-consent_leg="{ row }">
                        <StatusBadge :tone="row.consent_leg === 'seated' ? 'info' : 'neutral'">
                            {{ row.consent_leg === 'seated' ? 'seated government (Meter B)' : 'operator board (Meter A)' }}
                        </StatusBadge>
                    </template>

                    <template #cell-meters="{ row }">
                        <div class="cluster" style="gap: var(--space-1)">
                            <StatusBadge :tone="row.meter_a ? 'success' : 'neutral'">A {{ row.meter_a ? '✓' : '—' }}</StatusBadge>
                            <StatusBadge :tone="row.meter_b ? 'success' : 'neutral'">B {{ row.meter_b ? '✓' : '—' }}</StatusBadge>
                            <StatusBadge :tone="row.meter_c ? 'success' : 'neutral'">C {{ row.meter_c ? '✓' : '—' }}</StatusBadge>
                        </div>
                    </template>

                    <template #cell-created_at="{ value }">
                        {{ fmtWhen(value) }}
                    </template>

                    <template #cell-actions="{ row }">
                        <Btn
                            variant="primary"
                            size="sm"
                            :disabled="busy !== null"
                            @click="approveRow(row.id)"
                        >{{ busy === `approve:${row.id}` ? 'Attesting…' : 'Attest & approve' }}</Btn>
                        <div v-if="row.consent_leg === 'seated'" class="gloss">
                            A seated government approves through its own vote — this button can only
                            attest Meter A, and the grant will refuse with its citation until that
                            vote passes.
                        </div>
                    </template>
                </DataTable>

                <p class="citation">
                    Approval mints a verified grant — dual-meter consent (Art. VII admissibility;
                    seated-government supersession), never a self-claim. Dropping one of your own
                    channels is always unilateral.
                </p>
            </section>
        </template>
    </PageScaffold>
</template>
