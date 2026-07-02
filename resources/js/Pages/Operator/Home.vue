<script setup>
/**
 * Operator/Home — the operator plane's front door (mockups-v3-wiring Phase 4;
 * design contract mockups/v3/operator/operator-home.html; PHASE_4_DESIGN_peerage.md §3.1).
 *
 * A pure READ surface over MeshConsoleController::home() — the readiness
 * rollup (MeshGateService::evaluate), the four named roles as chips
 * (MeshGateService::roles), the enabled-channel manifest, the peers-&-sync
 * line, and the authority count the settled way: "this node holds the home
 * copy of N places" — authority attaches to a PLACE, never to a node as rank.
 * The G3c read-write petition ladder is deliberately absent (design flag 1 —
 * the legacy /federation page keeps it).
 *
 * Gating mirrors /operator/operations exactly: the shell renders for any
 * authenticated user; the operator data block (`readiness`) arrives only for
 * an auth:operator session — a citizen sees the sign-in prompt.
 */
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import Icon from '@/Components/Ui/Icon.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

/* v3 player chrome (MASTER_PLAN Phase 2 idiom). */
defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    /** True only for an auth:operator session (the /operator/operations gate). */
    authed: { type: Boolean, default: false },
    /** The signed-in operator's username (null for citizens). */
    operator: { type: String, default: null },
    /** MeshConsoleController::readiness() — null unless an operator is signed in. */
    readiness: { type: Object, default: null },
});

const page = usePage();
const flash = computed(() => page.props.flash?.status ?? null);

const gates = computed(() => props.readiness?.gates ?? []);
const attention = computed(() => gates.value.filter((g) => g?.status !== 'pass'));
const rollup = computed(() => {
    if (gates.value.some((g) => g?.status === 'fail')) return 'red';
    if (gates.value.some((g) => g?.status === 'warn')) return 'amber';
    return gates.value.length ? 'green' : 'amber';
});

const peers = computed(() => props.readiness?.peers ?? null);
const channels = computed(() => props.readiness?.channels ?? []);
const roles = computed(() => props.readiness?.roles ?? []);
const authority = computed(() => props.readiness?.authority ?? null);
const homeCopies = computed(() => authority.value?.home_copies ?? 0);

/** state → plain pill (the operator-console simplification, fixtures-operator.js). */
const STATE_PILL = {
    established: { pill: 'live', label: 'Active' },
    partial: { pill: 'vote', label: 'Partly on' },
    requested: { pill: 'wait', label: 'Waiting for approval' },
    qualifiable: { pill: 'wait', label: 'Ready to turn on' },
    'needs-config': { pill: 'info', label: 'Needs setup' },
    lapsed: { pill: 'closed', label: 'Stopped' },
};
const statePill = (state) => STATE_PILL[state] ?? STATE_PILL['needs-config'];

/** Defensive date text — heartbeat comes as a raw DB timestamp, sync as ISO. */
const fmtWhen = (value) => {
    if (!value) return null;
    const d = new Date(String(value).replace(' ', 'T'));
    return Number.isNaN(d.getTime())
        ? String(value)
        : d.toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
};

const lastSyncText = computed(() => {
    const s = peers.value?.last_sync;
    if (!s) return 'no sync yet';
    const when = fmtWhen(s.created_at);
    return `${s.result} · ${s.direction} · seq ${s.seq}${when ? ` · ${when}` : ''}`;
});

/**
 * The 8 surface doors (the mockup's entry grid). DNS rides the Operations
 * console for now; Moderation is a designed placeholder (Phase I service).
 */
const doors = [
    {
        href: '/setup', icon: 'sliders', title: 'Set up your node',
        note: 'Claim an operator account, name the instance, pick a role, and go — the first-run wizard.',
    },
    {
        href: '/operator/console', icon: 'landmark', title: 'The operator console',
        note: 'Your roles at a glance, the health line, and everything advanced behind one toggle.',
    },
    {
        href: '/operator/roles', icon: 'users', title: 'Roles & channels',
        note: 'The four named roles over the nine capability channels, and how a channel goes live.',
    },
    {
        href: '/operator/mesh', icon: 'globe', title: 'Mesh & federation',
        note: 'Join a cluster, your peers, sync between nodes, transports, and becoming a full peer.',
    },
    {
        href: '/operator/operations', icon: 'globe', title: 'DNS & certificates',
        note: 'The Identity Broker: DNS-before-cert, per-name + wildcard backup, DDNS, providers, the budget.',
        hint: 'Lives in the Operations console for now.',
    },
    {
        href: '/operator/identity', icon: 'lock', title: 'Identity',
        note: "Your devices and mesh identity; how a player's standing travels with them across nodes.",
    },
    {
        href: null, icon: 'shield', title: 'Moderation & the legal floor',
        note: 'The legitimacy flip (operator relay → judicial), the four carve-outs, and the legal floor.',
    },
    {
        href: '/operator/versioning', icon: 'refresh-cw', title: 'Versions & upgrades',
        note: 'Peer version tracking and the dual-meter upgrade agreement; the game-in-progress freeze.',
    },
];
</script>

<template>
    <PageScaffold :surface="surface" title="Run a node">
        <template #intro>
            Run a node and your computer becomes part of the world — holding the record,
            naming peers, hosting the live rooms. This is the infrastructure the citizen
            game runs on, and it is deliberately separate from the constitutional game itself.
        </template>
        <template #about>
            <p>
                The operator plane's front door: is this box ready to meet the mesh, which
                of the four named roles it runs, and one door to every operator surface.
                "Become a peer" is one process — a node that can get a certificate and take
                clients is a full, equal peer; role elevation is the separate, trust-gated
                ladder on Roles &amp; channels.
            </p>
        </template>

        <Banner v-if="flash" tone="info">{{ flash }}</Banner>

        <!-- The plane wall — the ground rule, shown to every viewer. -->
        <div class="plane-wall">
            <Icon name="shield" size="sm" />
            <div>
                <strong>Off the constitutional plane.</strong>
                Running a node is infrastructure, not a citizen privilege — it buys you no
                extra vote, no seat, no say in any constitutional act. Operator vocabulary
                is "capability", never "role", so it can never collide with the citizen
                role system.
                <br />
                <span class="citation">
                    Operators are an overlapping de-facto board, answerable to the in-game
                    government — the moment a legislature seats itself, it supersedes the
                    operator board automatically. Authority ≠ leadership.
                </span>
            </div>
        </div>

        <!-- Not an operator → the sign-in gate (the /operator/operations mechanism). -->
        <Card v-if="!authed" as="section" title="Operator sign-in required">
            <p>
                The readiness rollup and the mesh controls are shown only to a signed-in
                operator. You don't need anything here to play — the game lives on the
                civic pages.
            </p>
            <div class="cluster" style="margin-block-start: var(--space-3)">
                <Btn as="a" href="/operator/login" variant="primary">
                    Sign in as an operator
                    <Icon name="arrow-right" size="sm" />
                </Btn>
            </div>
        </Card>

        <template v-else>
            <!-- ─────────────────────────────── Readiness rollup + gate chips -->
            <div class="health-line">
                <span class="health-dot" :class="`health-dot--${rollup}`" aria-hidden="true"></span>
                <strong>Peers &amp; sync at a glance</strong>
                <span class="citation">
                    {{ peers?.trusted ?? 0 }} trusted of {{ peers?.total ?? 0 }}
                    peer{{ (peers?.total ?? 0) === 1 ? '' : 's' }}
                    · last sync {{ lastSyncText }}
                    <template v-if="fmtWhen(peers?.last_heartbeat_at)">
                        · last heartbeat {{ fmtWhen(peers?.last_heartbeat_at) }}
                    </template>
                </span>
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

            <!-- ──────────────────────────────────────────── The gate checklist -->
            <Banner v-if="attention.length === 0" tone="info" icon="check" title="Ready to meet the mesh">
                Every gate passes — this box can federate.
            </Banner>
            <Card v-else as="section" title="Between you and ready">
                <p class="gloss">
                    Each line says what to do next. A red gate blocks federation outright;
                    an amber one just narrows what this box can offer.
                </p>
                <ul class="stack" style="gap: var(--space-2); list-style: none; margin: 0; padding: 0">
                    <li v-for="g in attention" :key="g.key" class="cluster" style="align-items: baseline">
                        <StatusBadge :tone="g.status === 'fail' ? 'danger' : 'warning'" icon="alert-triangle">
                            {{ g.status === 'fail' ? 'Blocked' : 'Heads-up' }}
                        </StatusBadge>
                        <span>
                            <strong>{{ g.label }}</strong>
                            <span class="gloss"> — {{ g.detail }}</span>
                        </span>
                    </li>
                </ul>
            </Card>

            <!-- ─────────────────────── Authority the settled way: places, not rank -->
            <Card as="section" title="Places on this node">
                <p>
                    This node holds the home copy of
                    <strong>{{ homeCopies }}</strong> place{{ homeCopies === 1 ? '' : 's' }}.
                    Authority is a fact about where a place's home copy lives — never a
                    rank of node: every peer holds the same record, and each place's home
                    copy decides its writes.
                </p>
                <div class="cluster" style="gap: var(--space-6); margin-block-start: var(--space-3)">
                    <Stat :value="homeCopies" label="home copies here" accent />
                    <Stat :value="authority?.peer_held ?? 0" label="home copies with peers" />
                    <Stat :value="authority?.total ?? 0" label="places in the record" />
                </div>
                <p class="citation">Full faith &amp; credit — one record, many holders · Art. V</p>
            </Card>

            <!-- ───────────────────────────────────── The enabled-channel manifest -->
            <Card as="section" title="Live channels on this box">
                <p class="gloss">
                    The capability channels this box currently runs — a box's "role" is
                    just this set. A governed channel went through the dual-meter consent;
                    a self-asserted one is turned on by you alone.
                </p>
                <p v-if="channels.length === 0" class="gloss">
                    None yet — a fresh box starts quiet. Record Keeper is the recommended
                    first turn-on: both of its channels are self-asserted.
                </p>
                <div v-else class="cluster" style="flex-wrap: wrap; gap: var(--space-2)">
                    <span v-for="c in channels" :key="c.capability" class="form-chip">
                        <span class="form-id">{{ c.capability }}</span>
                        <span class="pill" :class="c.kind === 'governed' ? 'pill--vote' : 'pill--info'">
                            {{ c.kind === 'governed' ? 'governed' : 'self-asserted' }}
                        </span>
                    </span>
                </div>
            </Card>

            <!-- ───────────────────────────────────────── The 4 named-role chips -->
            <section aria-labelledby="roles-h" class="stack" style="gap: var(--space-2)">
                <h2 id="roles-h">Your roles</h2>
                <p class="gloss">
                    A box's "role" is just the set of capability channels it runs — four
                    friendly names group them. The full cards live on
                    <Link href="/operator/roles">Roles &amp; channels</Link>; turn them on
                    from <Link href="/operator/console">the console</Link>.
                </p>
                <div class="cluster" style="gap: var(--space-2); flex-wrap: wrap">
                    <Link v-for="r in roles" :key="r.role" class="form-chip" href="/operator/roles">
                        {{ r.label }}
                        <span class="pill" :class="`pill--${statePill(r.state).pill}`">
                            {{ statePill(r.state).label }}
                        </span>
                        <span v-if="r.recommended && r.state !== 'established'" class="pill pill--planned">
                            Recommended first
                        </span>
                    </Link>
                </div>
            </section>

            <!-- ──────────────────────────────────────────── The 8 surface doors -->
            <section aria-labelledby="go-h" class="stack" style="gap: var(--space-2)">
                <h2 id="go-h">Operator surfaces</h2>
                <div class="role-grid">
                    <template v-for="door in doors" :key="door.title">
                        <Link v-if="door.href" class="role-card" :href="door.href">
                            <Icon :name="door.icon" />
                            <span class="role-name">{{ door.title }}</span>
                            <span>{{ door.note }}</span>
                            <span v-if="door.hint" class="gloss">{{ door.hint }}</span>
                            <span class="enter-as">
                                Open
                                <Icon name="arrow-right" size="sm" />
                            </span>
                        </Link>
                        <div v-else class="role-card role-card--planned">
                            <Icon :name="door.icon" />
                            <span class="role-name">{{ door.title }}</span>
                            <span>{{ door.note }}</span>
                            <span class="pill pill--planned">Planned · Phase I</span>
                        </div>
                    </template>
                </div>
            </section>

            <!-- ───────────────────────────────────────────── Low-key legacy doors -->
            <p class="gloss">
                The older consoles stay reachable while the campaign proves parity:
                <Link href="/operator/operations">Operations (legacy console)</Link> ·
                <Link href="/federation">Federation (legacy)</Link>.
                Signed in as <strong>{{ operator }}</strong>.
            </p>
        </template>
    </PageScaffold>
</template>
