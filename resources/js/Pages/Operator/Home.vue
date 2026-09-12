<script setup>
/**
 * Operator/Home — canonical host overview, consolidated from the former console (design contract:
 * mockups/v3/operator/console.html; PHASE_4_DESIGN_peerage.md §3.1).
 *
 * READ-ONLY BY DESIGN — a pure render of MeshConsoleController@home, which
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
import HostNav from '@/Components/Operator/HostNav.vue';
import { HOST_PAGES } from '@/Components/Operator/hostNavigation.js';
import { useI18n } from 'vue-i18n';

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
const { t } = useI18n();
const text = (key, fallback) => t('c_host.' + key, fallback);
const tasks = [
    { key: 'roles', title: 'Manage capabilities', note: 'Choose the services this host provides and review capability requests.' },
    { key: 'mesh', title: 'Connect with other hosts', note: 'Check peers and synchronization, then open connection and access controls.' },
    { key: 'operations', title: 'Manage host settings', note: 'Review resources and services, DNS, devices, upgrades, and moderation.' },
];
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
            green: 'All readiness checks pass.',
            amber: gates.value.length ? 'Some readiness checks need attention.' : 'Readiness has not been reported.',
            red: 'A readiness check failed. Review the details below.',
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
    <PageScaffold :surface="surface" :title="text('overview_title', 'Host overview')">
        <template #intro>
            {{ text('intro', 'Check this host’s readiness, manage its services, and connect it with other hosts.') }}
        </template>
        <HostNav current="overview" />

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
            <p class="citation">{{ text('signed_in', 'Signed in as') }} <span data-no-i18n>{{ operator }}</span></p>
            <div class="host-tasks">
                <Link v-for="task in tasks" :key="task.key" :href="HOST_PAGES[task.key].href" class="host-task">
                    <strong>{{ text('task.' + task.key + '.title', task.title) }}</strong>
                    <span>{{ text('task.' + task.key + '.note', task.note) }}</span>
                </Link>
            </div>

            <!-- ========================== tier 1 — the health line ======= -->
            <Card as="section">
                <template #title><h2>{{ text('readiness', 'Host readiness') }}</h2></template>
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
                    {{ rollupNote }}
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

            <Card as="section" :title="text('capability_summary', 'Capability status')">
                <ul class="host-capabilities">
                    <li v-for="role in roles" :key="role.role">
                        <strong>{{ role.label }}</strong>
                        <span class="pill" :class="`pill--${pillOf(role.state).pill}`">{{ pillOf(role.state).label }}</span>
                    </li>
                </ul>
                <Link href="/operator/roles">{{ text('manage_capabilities', 'Manage capabilities and requests') }}</Link>
            </Card>

            <!-- ========================== tier 2 — Advanced =============== -->
            <Card as="section">
                <template #title><h2>Advanced</h2></template>

                <AboutSurface :summary-label="text('technical_details', 'Technical details and approval rules')">
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

<style scoped>
.host-tasks { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 15rem), 1fr)); gap: var(--space-3); }
.host-task { display: flex; flex-direction: column; gap: var(--space-2); padding: var(--space-4); border: 1px solid var(--gov-border); border-radius: var(--radius-sm); color: var(--gov-fg); text-decoration: none; }
.host-task:hover { background: var(--gov-surface-2); }
.host-task:focus-visible { outline: 2px solid var(--gov-primary); outline-offset: 2px; }
.host-task span { color: var(--gov-fg-subtle); font-size: var(--text-sm); }
.host-capabilities { list-style: none; padding: 0; display: flex; flex-wrap: wrap; gap: var(--space-4); }
.host-capabilities li { display: flex; flex-wrap: wrap; gap: var(--space-2); align-items: center; }
</style>
