<script setup>
/**
 * Operator/Console — mockups-v3-wiring Phase 4 (design contract:
 * mockups/v3/operator/console.html; PHASE_4_DESIGN_peerage.md §3.1).
 *
 * READ-ONLY BY DESIGN — a pure render of MeshConsoleController@console, which
 * itself only wraps MeshGateService (gates / roles / channels) and
 * PeerUpgradeAgreementService (the three consent meters). Nothing here
 * re-computes a meter, a probe, or an authority rule; the CTAs navigate to
 * /operator/roles where the lifecycle actions live.
 *
 * Gating mirrors /operator/operations exactly: the page shell is reachable by
 * any signed-in user, but the console data block arrives ONLY for an
 * authenticated operator — a citizen sees `authed: false`, a null `console`
 * prop, and the operator sign-in prompt.
 *
 * Settled language (design §3.4, binding): "authority" attaches to a
 * JURISDICTION — never to a node as a rank; "become a peer" is one process
 * (a cert + clients); role elevation is the separate trust-gated ladder. The
 * G3c read-write petition ladder is NOT presented here (design flag 1 — the
 * legacy /federation page keeps it).
 */
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import AboutSurface from '@/Components/Surface/AboutSurface.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import Icon from '@/Components/Ui/Icon.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

/* Phase-4 restyle: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    authed: { type: Boolean, default: false },
    operator: { type: String, default: null },
    /** Operator-only data block (null for citizens): health + roles + channels + meters. */
    console: { type: Object, default: null },
});

const page = usePage();
const flash = computed(() => page.props.flash?.status ?? null);

/* Alias the `console` prop so the template never collides with the global. */
const data = computed(() => props.console ?? null);

/* ------------------------------------------------------------------ health */
const gates = computed(() => data.value?.health?.gates ?? []);

/** The mockup's rollup dot: red = a hard blocker, amber = a gate wants attention. */
const rollup = computed(() => {
    if (gates.value.some((g) => g.status === 'fail')) return 'red';
    if (gates.value.some((g) => g.status === 'warn')) return 'amber';
    return gates.value.length > 0 ? 'green' : 'amber';
});

const rollupNote = computed(
    () =>
        ({
            green: 'green — every readiness check passes.',
            amber: 'amber — a couple of gates want attention (nothing is blocked).',
            red: 'red — a hard blocker; federation cannot flow until it clears.',
        })[rollup.value],
);

/** Gates that want attention, with their one-line details. */
const attention = computed(() => gates.value.filter((g) => g.status !== 'pass'));

/* ---------------------------------------------------- roles + channel grid */
const roles = computed(() => data.value?.roles ?? []);
const channels = computed(() => data.value?.channels ?? []);

/* state → plain pill (the mockup's STATE_PILL, + the roles() `partial` rollup). */
const STATE_PILL = {
    established: { pill: 'live', label: 'Active' },
    partial: { pill: 'vote', label: 'Partly active' },
    qualifiable: { pill: 'wait', label: 'Ready to turn on' },
    'needs-config': { pill: 'info', label: 'Needs setup' },
    requested: { pill: 'wait', label: 'Waiting for approval' },
    lapsed: { pill: 'closed', label: 'Stopped' },
};
const pillOf = (state) => STATE_PILL[state] ?? STATE_PILL['needs-config'];

/* A role is one-click when EVERY channel it groups is self-asserted (the
   channel grid is the source of truth for consent kind — never re-derived). */
const kindByCap = computed(() =>
    Object.fromEntries(channels.value.map((c) => [c.capability, c.kind])),
);
const isSelfAsserted = (role) =>
    (role.channels ?? []).length > 0 &&
    (role.channels ?? []).every((cap) => kindByCap.value[cap] === 'self-asserted');

const ctaLabel = (role) => {
    if (role.state === 'established') return 'Manage';
    return isSelfAsserted(role) ? 'Establish' : 'Request';
};

/** The first non-pass gate detail for a channel — the plain "why not yet" line. */
const channelHint = (ch) => {
    if (ch.state === 'established') return null;
    const gate = (ch.gates ?? []).find((g) => g.status !== 'pass');
    return gate?.detail ?? null;
};

const channelColumns = [
    { key: 'capability', label: 'Channel', mono: true },
    { key: 'kind', label: 'Consent' },
    { key: 'what', label: 'What it does' },
    { key: 'state', label: 'State' },
];

/* ------------------------------------------------------------- the meters */
const meters = computed(() => data.value?.meters ?? null);

const meterCards = computed(() => {
    const m = meters.value;
    if (!m) return [];
    return [
        { id: 'A', ...(m.a ?? {}), count: m.a?.active_operators ?? null },
        { id: 'B', ...(m.b ?? {}), count: null },
        { id: 'C', ...(m.c ?? {}), count: m.c?.co_affected_peers ?? null },
    ];
});

const consentLegNote = computed(() => {
    const leg = meters.value?.consent_leg ?? null;
    if (leg === 'seated')
        return 'A government is seated for this scope — Meter B holds consent, and the operator board can no longer attest on its behalf.';
    if (leg === 'operator')
        return 'No government is seated for this scope yet — consent runs through Meter A, the operator board.';
    return null;
});

/* open proposals — live counts, straight off peer_upgrade_proposals. */
const KIND_LABEL = {
    constitutional_bump: 'constitution bump',
    schema_bump: 'schema bump',
    app_release: 'app release',
    role_grant: 'role grant',
};
const openTotal = computed(() => meters.value?.open_proposals?.total ?? 0);
const openKinds = computed(() =>
    Object.entries(meters.value?.open_proposals?.by_kind ?? {}).map(([kind, n]) => ({
        kind,
        n,
        label: `${KIND_LABEL[kind] ?? kind.replaceAll('_', ' ')}${n === 1 ? '' : 's'}`,
    })),
);

const scope = computed(() => data.value?.scope ?? null);
</script>

<template>
    <PageScaffold :surface="surface">
        <template #intro>
            One screen for the box you run: are your peers reachable, is sync flowing, and
            which capabilities are live. The friendly view is the default — every channel
            slug and every meter lives one tap away under Advanced.
        </template>

        <!-- ============================== the plane wall (top) =========== -->
        <div class="plane-wall">
            <Icon name="shield" size="sm" />
            <div>
                <strong style="color: var(--gov-fg)">Off the constitutional plane.</strong>
                Running a node is infrastructure, not a citizen privilege. It buys you no
                extra vote, no seat, no say in any constitutional act.
                <br />
                <span class="citation">Mesh state is a public record</span>
            </div>
        </div>

        <Banner v-if="flash" tone="info">{{ flash }}</Banner>

        <!-- ============================== citizen → sign-in gate ========= -->
        <Card v-if="!authed" as="section">
            <template #title><h2>Operator sign-in required</h2></template>
            <p class="cc-small">
                The mesh console is shown only to a signed-in operator. Operator accounts
                live on their own plane — they are not citizen users, and signing in here
                grants no citizen power.
            </p>
            <Btn as="a" href="/operator/login" variant="primary" icon="arrow-right">
                Sign in as an operator
            </Btn>
        </Card>

        <template v-else>
            <p class="citation">
                Signed in as <span data-no-i18n>{{ operator }}</span> — this console is
                read-only; the role lifecycle actions live on the roles board.
            </p>

            <!-- ========================== tier 1 — the health line ======= -->
            <Card as="section">
                <template #title><h2>Peers &amp; sync at a glance</h2></template>
                <div class="health-line">
                    <span class="health-dot" :class="`health-dot--${rollup}`" aria-hidden="true"></span>
                    <strong style="color: var(--gov-fg)">Node readiness</strong>
                    <span class="citation">{{ gates.length }} readiness checks</span>
                    <span style="flex-basis: 100%"></span>
                    <span
                        v-for="g in gates"
                        :key="g.key"
                        class="gate-chip"
                        :class="`gate-chip--${g.status}`"
                        :title="g.detail"
                    >
                        <Icon :name="g.status === 'pass' ? 'check' : 'alert-triangle'" size="sm" />
                        {{ g.label }}
                    </span>
                </div>
                <p class="gloss">
                    The dot is the rollup: {{ rollupNote }} Each chip is one of the readiness
                    checks behind it — the same checks the <span data-no-i18n>mesh:gates</span>
                    command prints.
                </p>
                <ul v-if="attention.length" style="margin: 0">
                    <li v-for="g in attention" :key="g.key" class="cc-small">
                        <StatusBadge :tone="g.status === 'fail' ? 'danger' : 'warning'" icon="alert-triangle">
                            {{ g.status === 'fail' ? 'Blocked' : 'To do' }}
                        </StatusBadge>
                        {{ g.label }} — <span data-no-i18n>{{ g.detail }}</span>
                    </li>
                </ul>
            </Card>

            <!-- ========================== tier 1 — the named roles ======== -->
            <Card as="section">
                <template #title><h2>Your roles</h2></template>
                <p class="page-intro">
                    Four friendly names group the capability channels — a role is just the
                    set of channels it runs, and it adds no new power. Self-asserted roles
                    turn on with one click; governed roles are requested, then approved by
                    the dual-meter. Becoming a peer is one process for every node: get a
                    certificate, take clients.
                </p>
                <div class="op-role-grid">
                    <div
                        v-for="r in roles"
                        :key="r.role"
                        class="op-role-card"
                        :class="{ 'op-role--recommended': r.recommended }"
                    >
                        <div class="cluster" style="justify-content: space-between; align-items: center">
                            <span class="orc-title">{{ r.label }}</span>
                            <span class="pill" :class="`pill--${pillOf(r.state).pill}`">
                                {{ pillOf(r.state).label }}
                            </span>
                        </div>
                        <span v-if="r.recommended" class="pill pill--planned" style="align-self: flex-start">
                            Recommended first node
                        </span>
                        <p style="font-size: var(--text-sm)">{{ r.what }}</p>
                        <p v-if="r.duty" class="orc-duty">{{ r.duty }}</p>
                        <div class="orc-channels">
                            <span
                                v-for="cap in r.channels ?? []"
                                :key="cap"
                                class="channel-chip"
                                :class="kindByCap[cap] === 'self-asserted' ? 'channel-chip--self' : 'channel-chip--governed'"
                                :title="pillOf(r.channel_states?.[cap]).label"
                                data-no-i18n
                            >{{ cap }}</span>
                        </div>
                        <Btn
                            :as="Link"
                            href="/operator/roles"
                            variant="primary"
                            size="sm"
                            :icon="isSelfAsserted(r) ? 'check' : 'arrow-right'"
                            style="align-self: flex-start"
                        >
                            {{ ctaLabel(r) }}
                        </Btn>
                        <span class="citation">
                            {{
                                isSelfAsserted(r)
                                    ? 'self-asserted — one click, no approval needed'
                                    : 'governed — requested, then dual-meter approval'
                            }}
                        </span>
                    </div>
                </div>
            </Card>

            <!-- ========================== tier 2 — Advanced =============== -->
            <Card as="section">
                <template #title><h2>Advanced</h2></template>
                <p class="gloss">Everything raw, behind one toggle. Closed by default.</p>

                <AboutSurface summary-label="Advanced — every channel slug, the three meters, and the live counts">
                    <div class="stack" style="margin-block-start: var(--space-4)">
                        <!-- the full channel grid -->
                        <section aria-labelledby="op-console-channels-h">
                            <h3 id="op-console-channels-h">The nine capability channels</h3>
                            <p class="gloss">
                                A box's "role" is just the set of channels it runs. Self-asserted
                                channels turn on with one click; governed channels are requested,
                                then approved by the dual-meter.
                            </p>
                            <DataTable
                                :columns="channelColumns"
                                :rows="channels"
                                row-key="capability"
                                caption="The capability channels"
                            >
                                <template #cell-capability="{ row }">
                                    <span
                                        class="channel-chip"
                                        :class="row.kind === 'self-asserted' ? 'channel-chip--self' : 'channel-chip--governed'"
                                        data-no-i18n
                                    >{{ row.capability }}</span>
                                </template>
                                <template #cell-kind="{ row }">
                                    <StatusBadge v-if="row.kind === 'self-asserted'" tone="success" icon="check">
                                        self-asserted
                                    </StatusBadge>
                                    <StatusBadge v-else tone="warning" icon="shield">governed</StatusBadge>
                                    <span v-if="row.affects_peer_subtree" class="relation-chip" title="Acts under a peer's own zone — every co-affected peer must consent">
                                        Meter C
                                    </span>
                                </template>
                                <template #cell-what="{ row }">
                                    {{ row.what }}
                                    <span v-if="row.label && row.label !== row.capability" class="citation" style="display: block">
                                        {{ row.label }}
                                    </span>
                                </template>
                                <template #cell-state="{ row }">
                                    <span class="pill" :class="`pill--${pillOf(row.state).pill}`">
                                        {{ pillOf(row.state).label }}
                                    </span>
                                    <span
                                        v-if="channelHint(row)"
                                        class="citation"
                                        style="display: block"
                                        data-no-i18n
                                    >{{ channelHint(row) }}</span>
                                </template>
                            </DataTable>
                        </section>

                        <!-- the dual-meter consent -->
                        <section aria-labelledby="op-console-meters-h">
                            <h3 id="op-console-meters-h">The dual-meter consent</h3>
                            <p class="gloss">
                                Governed channels need approval. Meter A runs the bootstrap path;
                                the moment a legislature seats itself, Meter B supersedes it
                                automatically. Meter C only attaches to channels that act under a
                                peer's own zone.
                            </p>
                            <p v-if="consentLegNote" class="cc-small">{{ consentLegNote }}</p>
                            <p v-if="scope" class="citation" data-no-i18n>
                                Scope: the root jurisdiction · {{ scope }}
                            </p>
                            <p v-else class="citation">
                                No root jurisdiction yet — the meters attach to a scope once the
                                world is seeded.
                            </p>
                            <div class="meter-abc">
                                <div
                                    v-for="m in meterCards"
                                    :key="m.id"
                                    class="meter-card"
                                    :class="{ 'meter-card--super': m.id === 'B' }"
                                >
                                    <div class="cluster" style="align-items: center; gap: var(--space-2)">
                                        <span class="mc-id" data-no-i18n>{{ m.id }}</span>
                                        <strong style="color: var(--gov-fg)">{{ m.label }}</strong>
                                        <span v-if="m.id === 'B'" class="pill pill--live">Supersedes A</span>
                                    </div>
                                    <p style="font-size: var(--text-sm)">{{ m.explain }}</p>
                                    <p class="cc-small">
                                        <StatusBadge v-if="m.applies" tone="success" icon="check">
                                            Applies now
                                        </StatusBadge>
                                        <StatusBadge v-else tone="neutral">Not in play</StatusBadge>
                                    </p>
                                    <p v-if="m.id === 'A' && m.count !== null" class="cc-small">
                                        <strong data-no-i18n>{{ m.count }}</strong>
                                        active operator{{ m.count === 1 ? '' : 's' }} on the board.
                                    </p>
                                    <p v-if="m.id === 'C'" class="cc-small">
                                        <template v-if="(m.count ?? 0) > 0">
                                            <strong data-no-i18n>{{ m.count }}</strong>
                                            co-affected peer{{ m.count === 1 ? '' : 's' }} must consent
                                            (unanimity).
                                        </template>
                                        <template v-else>
                                            No co-affected peers — Meter C auto-passes.
                                        </template>
                                    </p>
                                </div>
                            </div>
                        </section>

                        <!-- open proposals — live counts -->
                        <section aria-labelledby="op-console-proposals-h">
                            <h3 id="op-console-proposals-h">Open proposals</h3>
                            <div class="cluster" style="gap: var(--space-6)">
                                <Stat :value="openTotal" label="open proposals" accent />
                                <Stat v-for="k in openKinds" :key="k.kind" :value="k.n" :label="k.label" />
                            </div>
                            <p class="gloss">
                                <template v-if="openTotal === 0">
                                    Nothing is waiting on a meter right now.
                                </template>
                                <template v-else>
                                    Each open proposal shows its meters, kind by kind, on
                                    <Link href="/operator/versioning">Versioning</Link>; role-grant
                                    approvals live on <Link href="/operator/roles">Roles</Link>.
                                </template>
                            </p>
                        </section>

                        <!-- peers, sync & transports — one pointer, tables live on Mesh -->
                        <section aria-labelledby="op-console-mesh-h">
                            <h3 id="op-console-mesh-h">Peers, sync &amp; transports</h3>
                            <p class="gloss">
                                The full tables — every peer, the sync ledger, and the transport
                                ladder — live in one place:
                                <Link href="/operator/mesh">Mesh &amp; federation</Link>.
                            </p>
                        </section>

                        <!-- CLI hints -->
                        <section aria-labelledby="op-console-cli-h">
                            <h3 id="op-console-cli-h">CLI hints</h3>
                            <p class="gloss">
                                Everything on this console is also a command. These are the
                                operator-plane verbs the console wraps.
                            </p>
                            <div class="cluster" style="flex-wrap: wrap; gap: var(--space-2)">
                                <code class="channel-chip" data-no-i18n>mesh:gates</code>
                                <code class="channel-chip" data-no-i18n>mesh:doctor [target]</code>
                                <code class="channel-chip" data-no-i18n>mesh:role [list|qualify|request|approve|revoke] &lt;capability&gt;</code>
                                <code class="channel-chip" data-no-i18n>federation:sync:push</code>
                            </div>
                        </section>
                    </div>
                </AboutSurface>
            </Card>
        </template>

        <!-- ============================== the plane wall (bottom) ======== -->
        <div class="plane-wall">
            <Icon name="info" size="sm" />
            <div>
                <strong style="color: var(--gov-fg)">Answerable to the government.</strong>
                Below a seated legislature the operator board (neutral + logged) relays
                rights-protection. The moment a legislature seats itself, the seated
                government supersedes the operator board — automatically, as a pure
                function of facts.
            </div>
        </div>

        <template #about>
            <p>
                This console is the read surface over the node's mesh services — the same
                gates the <span data-no-i18n>mesh:gates</span> command prints, the same
                role and channel states the roles board acts on, and the same three
                consent meters that govern every capability grant and upgrade. Authority
                here always means a fact about a place — which node holds a
                jurisdiction's home copy — never a rank of node.
            </p>
        </template>
    </PageScaffold>
</template>
