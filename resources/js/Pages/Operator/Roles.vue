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
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import HostNav from '@/Components/Operator/HostNav.vue';
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

const { t } = useI18n();

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
    established: { tone: 'success', label: t('c_operator_pages.roles.state_active', 'Active') },
    qualifiable: { tone: 'warning', label: t('c_operator_pages.roles.state_ready', 'Ready to turn on') },
    'needs-config': { tone: 'info', label: t('c_operator_pages.roles.state_needs_setup', 'Needs setup') },
    requested: { tone: 'warning', label: t('c_operator_pages.roles.state_waiting', 'Waiting for approval') },
    lapsed: { tone: 'neutral', label: t('c_operator_pages.roles.state_stopped', 'Stopped') },
};
const statePill = (state) => STATE_PILL[state] ?? STATE_PILL.qualifiable;

/* Named-role roll-ups (MeshGateService::ROLE_*). */
const ROLE_PILL = {
    established: { tone: 'success', label: t('c_operator_pages.roles.role_active', 'Active — every channel on') },
    partial: { tone: 'info', label: t('c_operator_pages.roles.role_partial', 'Partly on') },
    requested: { tone: 'warning', label: t('c_operator_pages.roles.state_waiting', 'Waiting for approval') },
    qualifiable: { tone: 'warning', label: t('c_operator_pages.roles.state_ready', 'Ready to turn on') },
    'needs-config': { tone: 'info', label: t('c_operator_pages.roles.state_needs_setup', 'Needs setup') },
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
    { key: 'capability', label: t('c_operator_pages.roles.col_channel', 'Channel') },
    { key: 'kind', label: t('c_operator_pages.roles.col_consent', 'Consent') },
    { key: 'what', label: t('c_operator_pages.roles.col_what', 'What it does') },
    { key: 'state', label: t('c_operator_pages.roles.col_on_node', 'On this node') },
    { key: 'actions', label: t('c_operator_pages.roles.col_actions', 'Actions') },
];
const PENDING_COLUMNS = [
    { key: 'capability', label: t('c_operator_pages.roles.col_channel', 'Channel') },
    { key: 'requested_by_server_id', label: t('c_operator_pages.roles.col_requested_by', 'Requested by'), mono: true },
    { key: 'consent_leg', label: t('c_operator_pages.roles.col_consent_leg', 'Consent leg') },
    { key: 'meters', label: t('c_operator_pages.roles.col_meters', 'Meters') },
    { key: 'created_at', label: t('c_operator_pages.roles.col_opened', 'Opened') },
    { key: 'actions', label: t('c_operator_pages.roles.col_actions', 'Actions') },
];

/* The lifecycle explainer (mirrors the mockup's §3 strip word-for-word). */
const LIFECYCLE = [
    { node: 'qualify', words: t('c_operator_pages.roles.life_qualify', 'the node proves it can run the channel (reachable, on the right version, and has the storage and data the channel needs)') },
    { node: 'request', words: t('c_operator_pages.roles.life_request', 'the operator asks for the capability over a named peer or jurisdiction') },
    { node: 'approve', words: t('c_operator_pages.roles.life_approve', 'the dual-meter consents — operator board, or the seated government') },
    { node: 'join', words: t('c_operator_pages.roles.life_join', 'the channel turns on; it now shows as Active') },
];

/* The three consent meters (mockup §4 — explainer copy; live reads ride the pending rows). */
const METERS = [
    {
        id: 'A', name: t('c_operator_pages.roles.meter_a_name', 'the operator board'), super: false,
        who: t('c_operator_pages.roles.meter_a_who', 'the active operators (neutral)'),
        threshold: t('c_operator_pages.roles.meter_a_threshold', '1 operator → just you · 2 → unanimity · 3+ → two-thirds'),
        applies: t('c_operator_pages.roles.meter_a_applies', 'the bootstrap path, before a government is seated'),
    },
    {
        id: 'B', name: t('c_operator_pages.roles.meter_b_name', 'the seated government'), super: true,
        who: t('c_operator_pages.roles.meter_b_who', 'the constituent legislatures, by supermajority (a multi-jurisdiction vote)'),
        threshold: t('c_operator_pages.roles.meter_b_threshold', 'supermajority of constituent jurisdictions'),
        applies: t('c_operator_pages.roles.meter_b_applies', 'the moment a legislature is seated — it SUPERSEDES Meter A'),
    },
    {
        id: 'C', name: t('c_operator_pages.roles.meter_c_name', 'co-affected peers'), super: false,
        who: t('c_operator_pages.roles.meter_c_who', 'every peer whose subtree the channel would touch'),
        threshold: t('c_operator_pages.roles.meter_c_threshold', 'unanimity (any one peer can refuse)'),
        applies: t('c_operator_pages.roles.meter_c_applies', 'only channels that act under a peer’s zone — broker.dns, authority.grant'),
    },
];
</script>

<template>
    <PageScaffold :surface="surface" :title="t('c_operator_pages.roles.page_title', 'Host capabilities')">
        <HostNav current="roles" />
        <template #intro>
            {{ t('c_operator_pages.roles.intro', 'A box’s “role” is nothing more than the set of capability channels it runs. Trust here is composable — you add the channels you can serve, one at a time. There are no tiers to climb and no rank to earn.') }}
        </template>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="rolesError" tone="emergency" :title="t('c_operator_pages.roles.refused', 'Refused')">{{ rolesError }}</Banner>

        <!-- Not an operator → the same sign-in gate as /operator/operations. -->
        <Card v-if="!authed" :title="t('c_operator_pages.roles.signin_title', 'Operator sign-in required')">
            <p>
                {{ t('c_operator_pages.roles.signin_body', 'The roles board is shown only to a signed-in operator. Operator accounts live on the operator plane — they have no link to citizen users and carry no citizen power.') }}
            </p>
            <p>
                <Btn as="a" href="/operator/login" variant="primary">{{ t('c_operator_pages.roles.signin_btn', 'Sign in as an operator') }}</Btn>
            </p>
        </Card>

        <template v-else-if="roles">
            <!-- Founding bootstrap: the operator may have jumped here from the setup
                 wizard — give them a plain way back so they aren't stranded in the
                 console's own menu (design flag: back-to-setup). -->
            <p v-if="founding" class="cluster" style="justify-content: flex-start">
                <Btn as="a" href="/setup" variant="secondary" size="sm">{{ t('c_operator_pages.roles.return_setup', '← Return to setup') }}</Btn>
            </p>

            <div class="plane-wall">
                <span><Icon name="shield" size="sm" /></span>
                <div>
                    <strong>{{ t('c_operator_pages.roles.plane_off_strong', 'Off the constitutional plane.') }}</strong>
                    {{ t('c_operator_pages.roles.plane_off_body', 'Running a node is infrastructure, not a citizen privilege — it buys you no extra vote, no seat, no say in any constitutional act. Operator accounts have no link to citizen users.') }}
                    <br />
                    <span class="citation">
                        {{ t('c_operator_pages.roles.signed_in_grant', { name: operator ?? 'operator' }) }}{{ scope ? t('c_operator_pages.roles.scope_default', { id: shortId(scope) }) : '' }}{{ t('c_operator_pages.roles.never_rank', ' — never to a node as a rank.') }}
                    </span>
                </div>
            </div>

            <!-- ==================== 1 · the four named roles ==================== -->
            <section aria-labelledby="roles-h" class="stack">
                <h2 id="roles-h">{{ t('c_operator_pages.roles.four_roles_title', 'The four named roles') }}</h2>
                <p class="page-intro">
                    {{ t('c_operator_pages.roles.four_roles_intro', 'Four friendly names group the nine channels — no new power, just a grouping. Each card shows its channel set, the duty you take on, and whether it turns on by itself or needs a government decision.') }}
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
                            <span v-if="r.recommended" class="pill pill--planned">{{ t('c_operator_pages.roles.recommended_first', 'Recommended first node') }}</span>
                            <span v-else-if="roleSelfAsserted(r)" class="pill pill--live">{{ t('c_operator_pages.roles.self_asserted', 'Self-asserted') }}</span>
                            <span v-else class="pill pill--info">{{ t('c_operator_pages.roles.governed', 'Governed') }}</span>
                        </div>

                        <p style="font-size: var(--text-sm)">{{ r.what }}</p>
                        <p class="orc-duty">{{ t('c_operator_pages.roles.your_duty', { duty: r.duty }) }}</p>

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
                                {{ t('c_operator_pages.roles.on_this_box', { label: rolePill(r.state).label }) }}
                            </StatusBadge>
                        </p>

                        <span class="citation">
                            {{
                                roleSelfAsserted(r)
                                    ? t('c_operator_pages.roles.self_gloss', 'Self-asserted — every channel goes live on one click; no gate.')
                                    : t('c_operator_pages.roles.governed_gloss', 'Governed — requested, then dual-meter approval (operator board, or the seated government).')
                            }}
                        </span>
                        <span v-if="r.petition" class="gloss">
                            {{ t('c_operator_pages.roles.petition_gloss', 'Read–write authority is a fact about a place — where its home copy lives. It moves between consenting nodes per jurisdiction; it is not a rank this box petitions for.') }}
                        </span>
                    </div>
                </div>

                <p class="advanced-note">
                    <span class="channel-chip channel-chip--self">{{ t('c_operator_pages.roles.chip_self', 'self') }}</span> {{ t('c_operator_pages.roles.legend_self', 'a self-asserted channel ·') }}
                    <span class="channel-chip channel-chip--governed">{{ t('c_operator_pages.roles.chip_governed', 'governed') }}</span> {{ t('c_operator_pages.roles.legend_governed', 'a governed channel') }}
                </p>
            </section>

            <!-- ==================== 2 · the nine channels ==================== -->
            <section aria-labelledby="chan-h" class="stack">
                <h2 id="chan-h">{{ t('c_operator_pages.roles.nine_channels_title', 'The nine channels') }}</h2>
                <p class="page-intro">
                    {{ t('c_operator_pages.roles.nine_intro_1', 'The whole closed vocabulary. Three channels are') }} <strong>{{ t('c_operator_pages.roles.self_asserted_word', 'self-asserted') }}</strong> —
                    <code>mesh.member</code>, <code>mirror</code>, and <code>etl</code> {{ t('c_operator_pages.roles.nine_intro_2', '— and need no gate at all: they only ever describe what your own box does. The other six are') }}
                    <strong>{{ t('c_operator_pages.roles.governed_word', 'governed') }}</strong>{{ t('c_operator_pages.roles.nine_intro_3', ', because each one grants a duty over others, or hangs a name under a peer’s zone.') }}
                </p>

                <div v-if="founding" class="plane-wall" style="margin-bottom: var(--space-4)">
                    <span><Icon name="info" size="sm" /></span>
                    <span>
                        <strong>{{ t('c_operator_pages.roles.founding_strong', 'You are the founding operator.') }}</strong> {{ t('c_operator_pages.roles.founding_body', 'There is no mesh to answer to and no government seated yet, so every role is yours to switch on directly — governed channels included. Once your world is founded and a government seats, governed channels return to the dual-meter consent path for any later change.') }}
                    </span>
                </div>

                <div class="cluster" style="gap: var(--space-6)">
                    <Stat :value="`${activeCount} / ${channels.length}`" :label="t('c_operator_pages.roles.stat_active', 'channels active on this box')" />
                    <Stat :value="founding ? 0 : governedCount" :label="founding ? t('c_operator_pages.roles.stat_awaiting_founding', 'awaiting consent (none — you are founding)') : t('c_operator_pages.roles.stat_governed', 'governed channels (dual-meter)')" />
                    <Stat :value="pending.length" :label="t('c_operator_pages.roles.stat_open_requests', 'open role-grant requests')" :accent="pending.length > 0" />
                </div>

                <DataTable
                    :columns="CHANNEL_COLUMNS"
                    :rows="channels"
                    row-key="capability"
                    :caption="t('c_operator_pages.roles.channels_caption', 'The nine capability channels, their consent kind, live state on this node, and actions')"
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
                            :title="t('c_operator_pages.roles.meter_c_title', 'acts under a peer’s zone — co-affected peers must consent')"
                        >{{ t('c_operator_pages.roles.meter_c_chip', 'Meter C') }}</span>
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
                                >{{ busy === `${row.capability}:revoke` ? t('c_operator_pages.roles.dropping', 'Dropping…') : t('c_operator_pages.roles.drop', 'Drop') }}</Btn>
                            </template>
                            <template v-else-if="row.state === 'requested'">
                                <span class="gloss">{{ t('c_operator_pages.roles.waiting_dual_meter', 'Waiting on the dual-meter — see the pending list below.') }}</span>
                            </template>
                            <template v-else-if="founding">
                                <!-- Founding node: every role is yours to switch on directly. -->
                                <Btn
                                    variant="primary"
                                    size="sm"
                                    :disabled="busy !== null"
                                    @click="requestRow(row.capability)"
                                >{{ busy === `${row.capability}:request` ? t('c_operator_pages.roles.turning_on', 'Turning on…') : t('c_operator_pages.roles.turn_on', 'Turn on') }}</Btn>
                            </template>
                            <template v-else>
                                <Btn
                                    variant="ghost"
                                    size="sm"
                                    :disabled="busy !== null"
                                    @click="qualifyRow(row.capability)"
                                >{{ busy === `${row.capability}:qualify` ? t('c_operator_pages.roles.probing', 'Probing…') : t('c_operator_pages.roles.qualify', 'Qualify') }}</Btn>
                                <Btn
                                    :variant="row.kind === 'self-asserted' ? 'primary' : 'secondary'"
                                    size="sm"
                                    :disabled="busy !== null"
                                    @click="requestRow(row.capability)"
                                >{{
                                    busy === `${row.capability}:request`
                                        ? t('c_operator_pages.roles.sending', 'Sending…')
                                        : row.kind === 'self-asserted' ? t('c_operator_pages.roles.turn_on', 'Turn on') : t('c_operator_pages.roles.request', 'Request')
                                }}</Btn>
                            </template>
                        </div>
                    </template>
                </DataTable>

                <div class="plane-wall">
                    <span><Icon name="info" size="sm" /></span>
                    <div>
                        <strong>{{ t('c_operator_pages.roles.self_vs_gov_strong', 'Self-asserted vs governed.') }}</strong>
                        {{ t('c_operator_pages.roles.self_vs_gov_body', 'A self-asserted channel touches nobody but you, so it is yours to switch on. A governed channel either grants a duty over other people or writes a name under a peer’s zone — so it is requested, never claimed, and approved by the dual-meter below.') }}
                    </div>
                </div>
            </section>

            <!-- ==================== 3 · how a channel goes live ==================== -->
            <section aria-labelledby="life-h" class="stack">
                <h2 id="life-h">{{ t('c_operator_pages.roles.lifecycle_title', 'How a channel goes live') }}</h2>
                <p class="page-intro">
                    {{ t('c_operator_pages.roles.lifecycle_intro', 'A governed channel walks four plain steps. A self-asserted channel skips straight to Active — there is no request and no approval to wait on.') }}
                </p>

                <Card>
                    <div class="fsm">
                        <template v-for="(s, i) in LIFECYCLE" :key="s.node">
                            <span v-if="i" class="fsm-arrow" aria-hidden="true"><Icon name="arrow-right" size="sm" /></span>
                            <span class="fsm-node">{{ s.node }}</span>
                        </template>
                        <span class="fsm-arrow" aria-hidden="true"><Icon name="arrow-right" size="sm" /></span>
                        <span class="pill pill--live">{{ t('c_operator_pages.roles.active_pill', 'Active') }}</span>
                    </div>

                    <ol class="stack" style="font-size: var(--text-sm); padding-inline-start: var(--space-4)">
                        <li v-for="s in LIFECYCLE" :key="s.node">
                            <span class="advanced-note">{{ s.node }}</span> — {{ s.words }}
                        </li>
                    </ol>

                    <p class="advanced-note">
                        {{ t('c_operator_pages.roles.self_asserted_channels', 'Self-asserted (mesh.member · mirror · etl):') }}
                        <span class="fsm-node">{{ t('c_operator_pages.roles.establish', 'establish') }}</span>
                        <Icon name="arrow-right" size="sm" />
                        <span class="pill pill--live">{{ t('c_operator_pages.roles.active_pill', 'Active') }}</span>
                        {{ t('c_operator_pages.roles.one_click_no_gate', '— one click, no gate.') }}
                    </p>
                </Card>

                <Card :title="t('c_operator_pages.roles.run_step_title', 'Run a step from here')" as="section">
                    <p class="gloss">
                        {{ t('c_operator_pages.roles.run_step_body', 'Qualify probes whether this box can host the channel — it changes nothing. Request turns a self-asserted channel on, or opens a governed request for the dual-meter to decide.') }}
                    </p>

                    <form class="stack" @submit.prevent="submitRequest">
                        <Field :label="t('c_operator_pages.roles.field_channel', 'Channel')" :error="act.errors.capability">
                            <template #control="{ id, invalid, describedBy }">
                                <select
                                    :id="id"
                                    v-model="act.capability"
                                    class="select"
                                    :aria-invalid="invalid ? 'true' : undefined"
                                    :aria-describedby="describedBy"
                                >
                                    <optgroup :label="t('c_operator_pages.roles.optgroup_self', 'Self-asserted — one click, no gate')">
                                        <option
                                            v-for="c in channels.filter((x) => x.kind === 'self-asserted')"
                                            :key="c.capability"
                                            :value="c.capability"
                                        >{{ c.capability }} — {{ c.label }}</option>
                                    </optgroup>
                                    <optgroup :label="t('c_operator_pages.roles.optgroup_governed', 'Governed — dual-meter approval')">
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
                            :label="t('c_operator_pages.roles.field_scope', 'Scope (optional jurisdiction id)')"
                            :error="act.errors.scope"
                            :hint="t('c_operator_pages.roles.field_scope_hint', 'Leave blank for the root jurisdiction. A grant acts over a place — never over the mesh as a rank.')"
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
                                {{ act.processing ? t('c_operator_pages.roles.working', 'Working…') : t('c_operator_pages.roles.qualify_probe', 'Qualify — probe this box') }}
                            </Btn>
                            <Btn variant="primary" type="submit" :disabled="act.processing">
                                {{
                                    act.processing
                                        ? t('c_operator_pages.roles.working', 'Working…')
                                        : actIsGoverned ? t('c_operator_pages.roles.request_channel', 'Request the channel') : t('c_operator_pages.roles.turn_channel_on', 'Turn the channel on')
                                }}
                            </Btn>
                        </div>
                    </form>

                    <Card v-if="probe" inset>
                        <p style="margin: 0">
                            <StatusBadge :tone="probe.ok ? 'success' : 'danger'">
                                {{ probe.ok ? t('c_operator_pages.roles.qualified', 'Qualified') : t('c_operator_pages.roles.not_qualified', 'Not qualified') }}
                            </StatusBadge>
                            <span class="channel-chip" style="margin-inline-start: var(--space-2)">{{ probe.capability }}</span>
                        </p>
                        <p style="font-size: var(--text-sm)">{{ probe.detail }}</p>
                        <p v-if="probe.affects_peer_subtree" class="gloss">
                            {{ t('c_operator_pages.roles.probe_meter_c', 'This channel acts under a peer’s zone — co-affected peers must also consent (Meter C).') }}
                        </p>
                    </Card>
                </Card>
            </section>

            <!-- ==================== 4 · approvals + the three meters ==================== -->
            <section aria-labelledby="pending-h" class="stack">
                <h2 id="pending-h">{{ t('c_operator_pages.roles.waiting_title', 'Waiting for approval') }}</h2>
                <p class="page-intro">
                    {{ t('c_operator_pages.roles.waiting_intro', 'A governed channel is approved by a dual-meter — operators while no government is seated, the seated government the moment one exists — plus a third meter for the few channels that reach under a peer’s zone. The meters below are live reads; nothing here re-counts a vote.') }}
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
                            <span v-if="m.super" class="pill pill--live">{{ t('c_operator_pages.roles.supersedes_a', 'Supersedes A') }}</span>
                        </div>
                        <p style="font-size: var(--text-sm); margin: 0"><strong>{{ t('c_operator_pages.roles.who', 'Who:') }}</strong> {{ m.who }}</p>
                        <p style="font-size: var(--text-sm); margin: 0"><strong>{{ t('c_operator_pages.roles.threshold', 'Threshold:') }}</strong> {{ m.threshold }}</p>
                        <p style="font-size: var(--text-sm); margin: 0"><strong>{{ t('c_operator_pages.roles.applies', 'Applies:') }}</strong> {{ m.applies }}</p>
                    </div>
                </div>

                <p v-if="pending.length === 0" class="gloss">
                    {{ t('c_operator_pages.roles.no_pending', 'No open role-grant requests. A governed request you or a peer opens will appear here with its live meter state.') }}
                </p>

                <DataTable
                    v-else
                    :columns="PENDING_COLUMNS"
                    :rows="pending"
                    row-key="id"
                    :caption="t('c_operator_pages.roles.pending_caption', 'Open role-grant requests with live consent-meter reads')"
                >
                    <template #cell-capability="{ row }">
                        <span class="channel-chip channel-chip--governed">{{ row.capability }}</span>
                        <div class="gloss">{{ t('c_operator_pages.roles.over_place', { id: shortId(row.scope_jurisdiction_id) }) }}</div>
                    </template>

                    <template #cell-requested_by_server_id="{ value }">
                        {{ shortId(value) }}
                    </template>

                    <template #cell-consent_leg="{ row }">
                        <StatusBadge :tone="row.consent_leg === 'seated' ? 'info' : 'neutral'">
                            {{ row.consent_leg === 'seated' ? t('c_operator_pages.roles.leg_seated', 'seated government (Meter B)') : t('c_operator_pages.roles.leg_operator', 'operator board (Meter A)') }}
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
                        >{{ busy === `approve:${row.id}` ? t('c_operator_pages.roles.attesting', 'Attesting…') : t('c_operator_pages.roles.attest_approve', 'Attest & approve') }}</Btn>
                        <div v-if="row.consent_leg === 'seated'" class="gloss">
                            {{ t('c_operator_pages.roles.seated_gloss', 'A seated government approves through its own vote — this button can only attest Meter A, and the grant will refuse with its citation until that vote passes.') }}
                        </div>
                    </template>
                </DataTable>

                <p class="citation">
                    {{ t('c_operator_pages.roles.approval_cite', 'Approval mints a verified grant — dual-meter consent (Art. VII admissibility; seated-government supersession), never a self-claim. Dropping one of your own channels is always unilateral.') }}
                </p>
            </section>
        </template>
    </PageScaffold>
</template>
