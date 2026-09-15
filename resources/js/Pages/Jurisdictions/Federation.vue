<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import HostNav from '@/Components/Operator/HostNav.vue';
import SyncProgress from '@/Components/Federation/SyncProgress.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });

const { t } = useI18n();

const props = defineProps({
    instance: { type: Object, required: true },
    mirror: { type: Object, default: () => ({ is_mirror: false }) },
    roots: { type: Array, default: () => [] },
    peers: { type: Array, default: () => [] },
    sync: { type: Array, default: () => [] },
    checkpoints: { type: Array, default: () => [] },
    claims: { type: Array, default: () => [] },
    host: { type: Object, default: () => ({ authed: false }) },
    mesh: { type: Object, default: () => ({ gates: [], transports: [], self_url: null, probe: null }) },
    roles: { type: Object, default: () => ({ channels: [], scope: null, pending: [] }) },
});

const page = usePage();
const flash = computed(() => page.props.flash?.status ?? null);

// G3b/G3c — "Join a cluster": a stepped wizard that adopts this instance as a
// read-only mirror and composes the adoption negotiation (what to pull, the
// relationship, instance options). co_member is ADVISORY — read-write is never
// granted by joining; it is a separate governed request (Art. V §7).
const joinForm = useForm({
    host_url: '',
    join_key: '',
    requested_relation: 'mirror',
    requested_scope_jurisdiction_id: '',
    note: '',
    geodata_posture: 'already_have',
});
const joinStep = ref(1);
const joinScopeMode = ref('whole'); // 'whole' (whole corpus) | 'subtree'
const syncPanel = ref(null); // SyncProgress — kicked to poll the moment a join is accepted

const join = () => {
    joinForm
        .transform((data) => ({
            ...data,
            requested_scope_jurisdiction_id:
                joinScopeMode.value === 'subtree' && data.requested_scope_jurisdiction_id
                    ? data.requested_scope_jurisdiction_id
                    : null,
        }))
        .post('/federation/cluster/join', {
            preserveScroll: true,
            onSuccess: () => syncPanel.value?.start(),
        });
};

const leave = () => {
    if (window.confirm(t('c_jurisdictions.federation.confirm_leave', 'Leave the cluster? This instance will stop being a read-only mirror.'))) {
        router.post('/federation/cluster/leave', {}, { preserveScroll: true });
    }
};

// G3c — mirror-side: petition the host for read-write authority over a subtree.
// Operator-grade; it only composes + sends the request (the host's government
// decides, Art. V §7). It grants nothing locally.
const rwForm = useForm({ root_jurisdiction_id: props.roots?.[0]?.id ?? '', note: '' });
const requestRw = () => rwForm.post('/federation/cluster/request-read-write', { preserveScroll: true });

// G3c — host adoption console (operator-gated). The minted key is a ONE-SHOT
// flash, gone on reload — it is shown only once.
const mintedKey = computed(() => page.props.flash?.minted_key ?? null);
const mintForm = useForm({ max_uses: 1, expires_in_days: null });
const mint = () => mintForm.post('/federation/host/keys', { preserveScroll: true });
const revokeKey = (handle) => {
    if (window.confirm(t('c_jurisdictions.federation.confirm_revoke_key', { handle }))) {
        router.post('/federation/host/keys/revoke', { handle }, { preserveScroll: true });
    }
};
const approveReq = (id) => router.post(`/federation/host/requests/${id}/approve`, {}, { preserveScroll: true });
const rejectReq = (id) => router.post(`/federation/host/requests/${id}/reject`, {}, { preserveScroll: true });
const denyRw = (id) => router.post(`/federation/host/rw/${id}/deny`, {}, { preserveScroll: true });

// G8b — the operator's two-way mesh setup + verification gates. discover/handshake/probe
// are the GUI front doors to federation:peer:discover/:handshake + mesh:doctor; the gates
// checklist is the operator's "run the tests, get the greens." The probe result is read
// from props.mesh.probe (the controller flashes it back into the prop).
const discoverForm = useForm({ url: '' });
const discover = () => discoverForm.post('/federation/mesh/discover', { preserveScroll: true });
const handshakeForm = useForm({ peer: '' });
const handshake = () => handshakeForm.post('/federation/mesh/handshake', { preserveScroll: true });
const probeForm = useForm({ target: '' });
const probe = () => probeForm.post('/federation/mesh/probe', { preserveScroll: true });
const recheckGates = () => router.reload({ only: ['mesh'], preserveScroll: true });
const meshProbe = computed(() => props.mesh?.probe ?? null);
const gateClass = (status) => ({ pass: 'bg-emerald-100 text-emerald-800', warn: 'bg-amber-100 text-amber-800', fail: 'bg-rose-100 text-rose-700' }[status] || 'bg-slate-100 text-slate-700');
const gateMark = (status) => ({ pass: '✓', warn: '⚠', fail: '✗' }[status] || '•');

const statusClass = (status) => ({
    trust_established: 'bg-emerald-100 text-emerald-800',
    syncing: 'bg-sky-100 text-sky-800',
    discovered: 'bg-slate-100 text-slate-700',
    handshake: 'bg-amber-100 text-amber-800',
    conflict_resolution: 'bg-amber-100 text-amber-800',
    departed: 'bg-rose-100 text-rose-700',
}[status] || 'bg-slate-100 text-slate-700');

const resultClass = (result) => ({
    applied: 'bg-emerald-100 text-emerald-800',
    conflict_authoritative_wins: 'bg-amber-100 text-amber-800',
    rejected_tamper: 'bg-rose-100 text-rose-700',
    rejected_non_authoritative: 'bg-rose-100 text-rose-700',
}[result] || 'bg-slate-100 text-slate-700');

const shortId = (id) => (id ? String(id).slice(0, 8) : '—');

// Mesh Roles ★14/★15 — the Role Board. A box's role = the SET of channels it has established; each is
// qualified → requested → approved → joined independently. Controls sit behind the operator guard.
const channelStateClass = (s) => ({
    established: 'bg-emerald-100 text-emerald-800',
    qualifiable: 'bg-sky-100 text-sky-800',
    requested: 'bg-amber-100 text-amber-800',
    'needs-config': 'bg-slate-100 text-slate-600',
    lapsed: 'bg-rose-100 text-rose-700',
}[s] || 'bg-slate-100 text-slate-600');

const establishChannel = (capability) => router.post('/federation/roles/establish', { capability }, { preserveScroll: true });
const requestChannel = (capability) =>
    router.post('/federation/roles/request', { capability, scope_jurisdiction_id: props.roles.scope }, { preserveScroll: true });
const approveRole = (id) => router.post('/federation/roles/approve', { proposal_id: id }, { preserveScroll: true });
const revokeChannel = (capability) => {
    if (window.confirm(t('c_jurisdictions.federation.confirm_drop_channel', { capability }))) {
        router.post('/federation/roles/revoke', { capability }, { preserveScroll: true });
    }
};

// Transport switcher (★15) — the operator's composable JOIN channels (no consent gate).
const transportForm = useForm({ transport: 'tailnet', address: '', priority: 100 });
const registerTransport = () => transportForm.post('/federation/transports/register', { preserveScroll: true });
const disableTransport = (transport) => router.post('/federation/transports/disable', { transport }, { preserveScroll: true });

// Broker credentials — the operator drops the Cloudflare token for a domain into this box's local,
// encrypted store. Write-only: the token field is never pre-filled and the value never comes back.
const brokerCredForm = useForm({ domain: '', zone_id: '', cloudflare_token: '' });
const setBrokerCred = () =>
    brokerCredForm.post('/federation/broker/credentials', { preserveScroll: true, onSuccess: () => brokerCredForm.reset() });
const forgetBrokerCred = (domain) => {
    if (window.confirm(t('c_jurisdictions.federation.confirm_forget_cred', { domain }))) {
        router.post('/federation/broker/credentials/forget', { domain }, { preserveScroll: true });
    }
};
</script>

<template>
    <Head :title="t('c_jurisdictions.federation.head_title', 'Host connections and access')" />

    <div class="mx-auto max-w-5xl space-y-6 p-6">
        <header>
            <h1 class="text-2xl font-semibold" style="color: var(--gov-fg-strong)">{{ t('c_jurisdictions.federation.page_title', 'Host connections and access') }}</h1>
            <p class="mt-1 max-w-2xl text-sm" style="color: var(--gov-fg-muted)">
                {{ t('c_jurisdictions.federation.header_body', 'Connect this host to the network, manage invitations and access requests, and verify peer connections. For the current peer list and synchronization record, open Peers and sync.') }}
            </p>
        </header>
        <HostNav current="federation" />

        <!-- This instance's identity -->
        <section class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-slate-900">{{ t('c_jurisdictions.federation.this_instance', 'This instance') }}</h2>
            <dl class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">{{ t('c_jurisdictions.federation.dt_name', 'Name') }}</dt>
                    <dd class="text-slate-800">{{ instance.name }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">{{ t('c_jurisdictions.federation.dt_server_id', 'Server ID') }}</dt>
                    <dd class="font-mono text-slate-800">{{ shortId(instance.server_id) }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">{{ t('c_jurisdictions.federation.dt_fingerprint', 'Key fingerprint') }}</dt>
                    <dd class="font-mono text-xs text-slate-700">{{ instance.public_key_fp || '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">{{ t('c_jurisdictions.federation.dt_mesh', 'Mesh') }}</dt>
                    <dd>
                        <span :class="instance.enabled ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'"
                              class="rounded px-2 py-0.5 text-xs font-medium">
                            {{ instance.enabled ? t('c_jurisdictions.federation.mesh_enabled', 'enabled') : t('c_jurisdictions.federation.mesh_disabled', 'disabled') }}
                        </span>
                    </dd>
                </div>
            </dl>
        </section>

        <!-- Mesh Roles ★14 — the Role Board: a box's role = the SET of channels it has established -->
        <section class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-slate-900">{{ t('c_jurisdictions.federation.role_board_title', 'Role board — capability channels') }}</h2>
            <p class="mt-1 max-w-2xl text-xs text-slate-600">
                {{ t('c_jurisdictions.federation.role_board_body', 'A box\'s role is the set of channels it has established — each qualified, requested, approved, and joined independently. Self-asserted channels are the operator\'s own infra choice; governed channels route through the mesh\'s dual-meter consent (the operator board, or a seated government).') }}
            </p>

            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <div v-for="c in roles.channels" :key="c.capability" class="rounded border border-slate-200 p-3">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-sm font-semibold text-slate-800">{{ c.label }}</span>
                        <span :class="channelStateClass(c.state)" class="rounded px-2 py-0.5 text-xs font-medium">{{ c.state }}</span>
                    </div>
                    <p class="mt-1 font-mono text-[11px] text-slate-600">{{ c.capability }}</p>
                    <p class="mt-1 text-xs text-slate-600">{{ c.what }}</p>
                    <p class="mt-1 text-[11px] uppercase tracking-wide text-slate-600">
                        {{ c.kind }}<span v-if="c.affects_peer_subtree"> {{ t('c_jurisdictions.federation.co_affected', '· co-affected peers consent') }}</span>
                    </p>

                    <ul class="mt-2 space-y-1">
                        <li v-for="g in c.gates" :key="g.key" class="flex items-start gap-1.5 text-xs">
                            <span :class="gateClass(g.status)"
                                  class="mt-0.5 inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full text-[10px] font-bold">
                                {{ gateMark(g.status) }}
                            </span>
                            <span class="text-slate-600">{{ g.label }} <span class="text-slate-600">— {{ g.detail }}</span></span>
                        </li>
                    </ul>

                    <div v-if="host.authed" class="mt-2 flex flex-wrap gap-2">
                        <button v-if="c.kind === 'self-asserted' && c.state !== 'established'" type="button"
                                @click="establishChannel(c.capability)"
                                class="rounded bg-slate-800 px-2 py-1 text-xs font-medium text-white hover:bg-slate-700">{{ t('c_jurisdictions.federation.btn_establish', 'Establish') }}</button>
                        <button v-if="c.kind === 'governed' && c.state === 'qualifiable'" type="button"
                                @click="requestChannel(c.capability)"
                                class="rounded bg-sky-700 px-2 py-1 text-xs font-medium text-white hover:bg-sky-600">{{ t('c_jurisdictions.federation.btn_request', 'Request') }}</button>
                        <span v-if="c.kind === 'governed' && c.state === 'needs-config'" class="text-[11px] italic text-slate-600">
                            {{ t('c_jurisdictions.federation.needs_config', 'drop the required token/key to qualify') }}
                        </span>
                        <span v-if="c.state === 'requested'" class="text-[11px] italic text-amber-600">{{ t('c_jurisdictions.federation.awaiting_consent', 'awaiting consent (see Pending requests)') }}</span>
                        <button v-if="c.state === 'established'" type="button"
                                @click="revokeChannel(c.capability)"
                                class="rounded border border-rose-300 px-2 py-1 text-xs font-medium text-rose-700 hover:bg-rose-50">{{ t('c_jurisdictions.federation.btn_drop', 'Drop') }}</button>
                    </div>
                </div>
            </div>
        </section>

        <!-- Mesh Roles ★14 — Pending role requests + their live dual-meter consent state (operator-only) -->
        <section v-if="host.authed && roles.pending.length" class="rounded-lg border border-amber-200 bg-amber-50 p-5">
            <h2 class="text-sm font-semibold text-slate-900">{{ t('c_jurisdictions.federation.pending_title', 'Pending role requests') }}</h2>
            <p class="mt-1 max-w-2xl text-xs text-slate-600">
                {{ t('c_jurisdictions.federation.pending_body_1', 'Each request\'s live consent state. ') }}<strong>{{ t('c_jurisdictions.federation.pending_approve', 'Approve') }}</strong>{{ t('c_jurisdictions.federation.pending_body_2', ' runs the bootstrap operator-board attestation (Meter A) then ratifies; a seated government approves through its supermajority vote (Meter B). Meter C is the co-affected peers\' unanimity for a channel that acts under a peer\'s subtree.') }}
            </p>
            <ul class="mt-3 space-y-2">
                <li v-for="r in roles.pending" :key="r.id" class="rounded border border-amber-200 bg-white p-3 text-sm">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="font-mono text-slate-800">{{ r.capability }}</span>
                        <span class="text-xs text-slate-500">{{ t('c_jurisdictions.federation.pending_scope', { scope: r.scope, by: r.requested_by, leg: r.consent_leg }) }}</span>
                    </div>
                    <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                        <span :class="r.meter_a ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'" class="rounded px-1.5 py-0.5 font-medium">{{ t('c_jurisdictions.federation.meter_a', 'Meter A') }} {{ r.meter_a ? '✓' : '·' }}</span>
                        <span :class="r.meter_b ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'" class="rounded px-1.5 py-0.5 font-medium">{{ t('c_jurisdictions.federation.meter_b', 'Meter B') }} {{ r.meter_b ? '✓' : '·' }}</span>
                        <span :class="r.meter_c ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'" class="rounded px-1.5 py-0.5 font-medium">{{ t('c_jurisdictions.federation.meter_c', 'Meter C') }} {{ r.meter_c ? '✓' : '·' }}</span>
                        <button type="button" @click="approveRole(r.id)"
                                class="ml-auto rounded bg-emerald-700 px-2 py-1 font-medium text-white hover:bg-emerald-600">{{ t('c_jurisdictions.federation.approve_ratify', 'Approve + ratify') }}</button>
                    </div>
                </li>
            </ul>
        </section>

        <!-- Mesh Roles — Broker credentials (operator-only): drop the Cloudflare token for a domain locally -->
        <section v-if="host.authed" class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-slate-900">{{ t('c_jurisdictions.federation.broker_title', 'Broker credentials') }}</h2>
            <p class="mt-1 max-w-2xl text-xs text-slate-600">
                {{ t('c_jurisdictions.federation.broker_body_1', 'To run the DNS/TLS broker role, drop a Cloudflare DNS-edit token for each domain you\'ll broker. The token is stored ') }}<strong>{{ t('c_jurisdictions.federation.broker_body_strong', 'encrypted on this box only') }}</strong>{{ t('c_jurisdictions.federation.broker_body_2', ' — it never federates, never appears in any response, and can\'t be read back here. After this (plus ') }}<code class="font-mono">lego</code>{{ t('c_jurisdictions.federation.broker_body_3', ' on PATH), this box qualifies for ') }}<span class="font-mono">broker.dns</span> /
                <span class="font-mono">broker.tls</span>.
            </p>

            <div v-if="roles.broker_credentials && roles.broker_credentials.length" class="mt-3 space-y-1">
                <div v-for="b in roles.broker_credentials" :key="b.domain"
                     class="flex items-center justify-between rounded border border-slate-200 px-3 py-1.5 text-sm">
                    <span><span class="font-mono text-slate-800">{{ b.domain }}</span>
                        <span class="ml-1 text-xs text-slate-500">{{ t('c_jurisdictions.federation.broker_zone', { id: b.zone_id }) }}</span>
                        <span class="ml-1 rounded bg-emerald-100 px-1.5 py-0.5 text-[11px] font-medium text-emerald-800">{{ t('c_jurisdictions.federation.broker_configured', 'configured') }}</span>
                    </span>
                    <button type="button" @click="forgetBrokerCred(b.domain)" class="text-xs text-rose-600 hover:text-rose-800">{{ t('c_jurisdictions.federation.broker_remove', 'Remove') }}</button>
                </div>
            </div>

            <form @submit.prevent="setBrokerCred" class="mt-3 grid gap-2 sm:grid-cols-2">
                <input v-model="brokerCredForm.domain" type="text" :aria-label="t('c_jurisdictions.federation.broker_domain_aria', 'Broker domain')" :placeholder="t('c_jurisdictions.federation.broker_domain_ph', 'domain (e.g. worldofstatecraft.org)')"
                       class="rounded border border-slate-300 px-2 py-1 text-sm" />
                <input v-model="brokerCredForm.zone_id" type="text" :aria-label="t('c_jurisdictions.federation.broker_zone_aria', 'Cloudflare zone id')" :placeholder="t('c_jurisdictions.federation.broker_zone_ph', 'Cloudflare zone id')"
                       class="rounded border border-slate-300 px-2 py-1 text-sm" />
                <input v-model="brokerCredForm.cloudflare_token" type="password" autocomplete="off" :aria-label="t('c_jurisdictions.federation.broker_token_aria', 'Cloudflare DNS-edit token')"
                       :placeholder="t('c_jurisdictions.federation.broker_token_ph', 'Cloudflare DNS-edit token (stored encrypted; never shown again)')"
                       class="rounded border border-slate-300 px-2 py-1 text-sm sm:col-span-2" />
                <div class="sm:col-span-2">
                    <button type="submit" :disabled="brokerCredForm.processing"
                            class="rounded bg-slate-800 px-3 py-1 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-50">
                        {{ t('c_jurisdictions.federation.broker_store', 'Store credential') }}
                    </button>
                    <span v-if="brokerCredForm.errors.domain || brokerCredForm.errors.cloudflare_token" class="ml-2 text-xs text-rose-600">
                        {{ brokerCredForm.errors.domain || brokerCredForm.errors.cloudflare_token }}
                    </span>
                </div>
            </form>
        </section>

        <!-- G8b — Two-way mesh: setup + verification gates (the operator's "run the gates") -->
        <section class="rounded-lg border border-slate-200 bg-white p-5">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold text-slate-900">{{ t('c_jurisdictions.federation.mesh_title', 'Two-way mesh — setup & gates') }}</h2>
                <button type="button" @click="recheckGates"
                        class="rounded border border-slate-300 bg-white px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50">
                    {{ t('c_jurisdictions.federation.mesh_recheck', 'Re-check') }}
                </button>
            </div>
            <p class="mt-1 max-w-2xl text-xs text-slate-600">
                {{ t('c_jurisdictions.federation.mesh_checklist_body', 'The operator\'s readiness checklist — green is ready, amber a step not yet done, red a hard blocker. Pair with a peer probe (below) to prove the two-way datapath before the rig\'s certification gates.') }}
            </p>

            <!-- gates checklist -->
            <ul class="mt-3 space-y-1.5">
                <li v-for="g in mesh.gates" :key="g.key" class="flex items-start gap-2 text-sm">
                    <span :class="gateClass(g.status)"
                          class="mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-xs font-bold">
                        {{ gateMark(g.status) }}
                    </span>
                    <span class="text-slate-800">
                        {{ g.label }}
                        <span class="block text-xs text-slate-500">{{ g.detail }}</span>
                    </span>
                </li>
            </ul>

            <div class="mt-3 text-xs text-slate-600">
                <span class="font-semibold uppercase tracking-wide text-slate-500">{{ t('c_jurisdictions.federation.advertised', 'Advertised:') }}</span>
                <span v-if="mesh.transports.length" class="ml-1 font-mono">{{ mesh.transports.map((t) => t.transport).join(', ') }}</span>
                <span v-else class="ml-1 italic">{{ t('c_jurisdictions.federation.advertised_none', 'none — run transport:register') }}</span>
            </div>

            <!-- operator actions -->
            <div v-if="host.authed" class="mt-4 space-y-4 border-t border-slate-100 pt-4">
                <div class="grid gap-3 sm:grid-cols-2">
                    <form @submit.prevent="discover" class="space-y-1">
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('c_jurisdictions.federation.lbl_discover', 'Discover a peer (URL)') }}</label>
                        <div class="flex gap-2">
                            <input v-model="discoverForm.url" type="text" :aria-label="t('c_jurisdictions.federation.aria_discover', 'Peer URL to discover')" placeholder="http://[200:…]:8080"
                                   class="w-full rounded border border-slate-300 px-2 py-1 text-sm" />
                            <button type="submit" :disabled="discoverForm.processing"
                                    class="rounded bg-slate-800 px-3 py-1 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-50">
                                {{ t('c_jurisdictions.federation.btn_discover', 'Discover') }}
                            </button>
                        </div>
                    </form>
                    <form @submit.prevent="handshake" class="space-y-1">
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('c_jurisdictions.federation.lbl_handshake', 'Handshake (server_id or URL)') }}</label>
                        <div class="flex gap-2">
                            <input v-model="handshakeForm.peer" type="text" :aria-label="t('c_jurisdictions.federation.aria_handshake', 'Peer server id or URL to handshake')" placeholder="server_id or url"
                                   class="w-full rounded border border-slate-300 px-2 py-1 text-sm" />
                            <button type="submit" :disabled="handshakeForm.processing"
                                    class="rounded bg-slate-800 px-3 py-1 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-50">
                                {{ t('c_jurisdictions.federation.btn_handshake', 'Handshake') }}
                            </button>
                        </div>
                    </form>
                </div>

                <form @submit.prevent="probe" class="space-y-1">
                    <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('c_jurisdictions.federation.lbl_probe', 'Probe a peer over every transport (mesh:doctor)') }}</label>
                    <div class="flex gap-2">
                        <input v-model="probeForm.target" type="text" :aria-label="t('c_jurisdictions.federation.aria_probe', 'Peer server id or URL to probe')" placeholder="server_id or url"
                               class="w-full rounded border border-slate-300 px-2 py-1 text-sm" />
                        <button type="submit" :disabled="probeForm.processing"
                                class="rounded bg-sky-700 px-3 py-1 text-sm font-medium text-white hover:bg-sky-600 disabled:opacity-50">
                            {{ t('c_jurisdictions.federation.btn_probe', 'Probe') }}
                        </button>
                    </div>
                </form>

                <div v-if="meshProbe" class="rounded border border-slate-200 bg-slate-50 p-3 text-sm">
                    <p class="text-xs font-semibold text-slate-700">
                        {{ t('c_jurisdictions.federation.probe_reached', { reached: meshProbe.reached, total: meshProbe.total, target: meshProbe.target }) }}
                    </p>
                    <ul class="mt-1 space-y-1">
                        <li v-for="(r, i) in meshProbe.rungs" :key="i" class="flex flex-wrap items-center gap-2 font-mono text-xs">
                            <span :class="r.reachable ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-700'"
                                  class="rounded px-1.5 py-0.5 font-bold">{{ r.reachable ? t('c_jurisdictions.federation.probe_ok', 'OK') : '✗' }}</span>
                            <span class="text-slate-700">[{{ r.transport }}] {{ r.url }}</span>
                            <span class="text-slate-500">—
                                <template v-if="r.error">{{ r.error }}</template>
                                <template v-else-if="r.reachable">{{ (r.latency_ms ?? '?') }}ms · {{ r.version === '' ? t('c_jurisdictions.federation.probe_no_version', 'no version') : (r.version_match ? t('c_jurisdictions.federation.probe_version_match', 'version match') : t('c_jurisdictions.federation.probe_version_mismatch', 'version MISMATCH')) }}</template>
                                <template v-else>HTTP {{ r.http_status ?? '?' }}</template>
                            </span>
                        </li>
                    </ul>
                </div>

                <!-- Transport switcher (Mesh Roles ★15) — register / drop a JOIN channel (operator infra choice, no consent gate) -->
                <div class="border-t border-slate-100 pt-3">
                    <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('c_jurisdictions.federation.lbl_transports', 'Transports (switch method)') }}</label>
                    <div v-if="mesh.transports.length" class="mt-1 flex flex-wrap gap-2">
                        <span v-for="tp in mesh.transports" :key="tp.transport"
                              class="inline-flex items-center gap-1 rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-700">
                            <span class="font-mono">{{ tp.transport }}</span>
                            <button type="button" @click="disableTransport(tp.transport)" class="text-rose-600 hover:text-rose-800" :title="t('c_jurisdictions.federation.transport_stop', 'Stop advertising')">×</button>
                        </span>
                    </div>
                    <form @submit.prevent="registerTransport" class="mt-2 flex flex-wrap items-end gap-2">
                        <select v-model="transportForm.transport" :aria-label="t('c_jurisdictions.federation.aria_transport_type', 'Transport type')" class="rounded border border-slate-300 px-2 py-1 text-sm">
                            <option value="https">https</option>
                            <option value="tailnet">tailnet</option>
                            <option value="onion">onion</option>
                            <option value="yggdrasil">yggdrasil</option>
                            <option value="sneakernet">sneakernet</option>
                        </select>
                        <input v-model="transportForm.address" type="text" :aria-label="t('c_jurisdictions.federation.aria_transport_addr', 'Transport address')" placeholder="address / url"
                               class="w-56 rounded border border-slate-300 px-2 py-1 text-sm" />
                        <button type="submit" :disabled="transportForm.processing"
                                class="rounded bg-slate-800 px-3 py-1 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-50">{{ t('c_jurisdictions.federation.btn_advertise', 'Advertise') }}</button>
                    </form>
                </div>
            </div>
            <p v-else class="mt-4 border-t border-slate-100 pt-3 text-xs text-slate-500">
                <a href="/operator/login" class="text-sky-700 underline">{{ t('c_jurisdictions.federation.signin_operator', 'Sign in as operator') }}</a>{{ t('c_jurisdictions.federation.mesh_terminal_1', ' to discover, handshake, and probe peers — or use the terminal: ') }}<code class="font-mono">php artisan mesh:gates</code>{{ t('c_jurisdictions.federation.mesh_terminal_2', ', ') }}<code class="font-mono">mesh:doctor &lt;peer&gt;</code>.
            </p>
        </section>

        <!-- G3b — Cluster membership: join as a read-only mirror, or leave -->
        <section class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-slate-900">{{ t('c_jurisdictions.federation.cluster_title', 'Cluster membership') }}</h2>

            <p v-if="flash" class="mt-2 rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                {{ flash }}
            </p>

            <!-- G3b — live seed/drain progress (polls the sync-progress endpoint; hides itself when idle) -->
            <SyncProgress ref="syncPanel" class="mt-3" />

            <!-- Already a read-only mirror -->
            <div v-if="mirror.is_mirror" class="mt-3 space-y-3">
                <div class="rounded border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-800">
                    {{ t('c_jurisdictions.federation.mirror_1', 'This instance is a ') }}<strong>{{ t('c_jurisdictions.federation.mirror_ro', 'read-only mirror') }}</strong>{{ t('c_jurisdictions.federation.mirror_2', ' of host ') }}
                    <span class="font-mono">{{ shortId(mirror.host_server_id) }}</span>
                    <span v-if="mirror.membership_state">{{ t('c_jurisdictions.federation.mirror_state', { state: mirror.membership_state }) }}</span>{{ t('c_jurisdictions.federation.mirror_3', '. It is authoritative for nothing and accepts no constitutional filings.') }}
                    <span v-if="mirror.adopted_at" class="block text-xs text-sky-600">
                        {{ t('c_jurisdictions.federation.mirror_adopted', { when: new Date(mirror.adopted_at).toLocaleString() }) }}
                    </span>
                </div>
                <button type="button" @click="leave"
                        class="rounded border border-rose-300 bg-white px-3 py-1.5 text-sm font-medium text-rose-700 hover:bg-rose-50">
                    {{ t('c_jurisdictions.federation.btn_leave', 'Leave the cluster') }}
                </button>

                <!-- G3c — operator-grade: petition the host for read-write authority.
                     Composes + sends the request only; the grant is the governed flow. -->
                <div v-if="host.authed" class="rounded border border-amber-200 bg-amber-50 px-3 py-3">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-amber-800">{{ t('c_jurisdictions.federation.rw_title', 'Request read-write authority') }}</h3>
                    <p class="mt-1 max-w-2xl text-xs text-amber-700">
                        {{ t('c_jurisdictions.federation.rw_body_1', 'Read-write is ') }}<strong>{{ t('c_jurisdictions.federation.rw_notgranted', 'not granted by joining') }}</strong>{{ t('c_jurisdictions.federation.rw_body_2', '. This sends a petition to the host; its government must approve it by a supermajority of your jurisdiction\'s residents and a supermajority of constituent jurisdictions (Art. V §7). Only then does authority flip and the sealed operational bundle transfer. This instance stays authoritative for nothing until then.') }}
                    </p>
                    <form class="mt-2 flex flex-wrap items-end gap-3" @submit.prevent="requestRw">
                        <label class="text-sm">
                            <span class="block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('c_jurisdictions.federation.lbl_subtree', 'Jurisdiction subtree') }}</span>
                            <select v-model="rwForm.root_jurisdiction_id"
                                    class="mt-1 rounded border border-slate-300 px-2 py-1 text-sm">
                                <option v-for="j in roots" :key="j.id" :value="j.id">{{ j.name }}</option>
                            </select>
                        </label>
                        <label class="grow text-sm">
                            <span class="block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('c_jurisdictions.federation.lbl_note', 'Note') }} <span class="font-normal normal-case text-slate-600">{{ t('c_jurisdictions.federation.optional', '(optional)') }}</span></span>
                            <input v-model="rwForm.note" type="text" maxlength="1000" :placeholder="t('c_jurisdictions.federation.rw_note_ph', 'why we run a vetted node here')"
                                   class="mt-1 w-full rounded border border-slate-300 px-2 py-1 text-sm" />
                        </label>
                        <button type="submit" :disabled="rwForm.processing || !rwForm.root_jurisdiction_id"
                                class="rounded bg-amber-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-amber-700 disabled:opacity-50">
                            {{ rwForm.processing ? t('c_jurisdictions.federation.rw_sending', 'Sending…') : t('c_jurisdictions.federation.rw_submit', 'Request read-write authority') }}
                        </button>
                    </form>
                    <p v-if="rwForm.errors.rw_request" class="mt-1 text-xs text-rose-600">{{ rwForm.errors.rw_request }}</p>
                    <p v-if="rwForm.errors.root_jurisdiction_id" class="mt-1 text-xs text-rose-600">{{ rwForm.errors.root_jurisdiction_id }}</p>
                </div>
            </div>

            <!-- Not a mirror — the stepped join wizard (G3c) -->
            <div v-else class="mt-3">
                <!-- Step indicator -->
                <ol class="mb-4 flex flex-wrap gap-2 text-xs">
                    <li v-for="(label, i) in [t('c_jurisdictions.federation.step_host_key', 'Host & key'), t('c_jurisdictions.federation.step_what_pull', 'What to pull'), t('c_jurisdictions.federation.step_relationship', 'Relationship'), t('c_jurisdictions.federation.step_review', 'Review')]" :key="i"
                        :class="joinStep === i + 1 ? 'bg-sky-700 text-white' : (joinStep > i + 1 ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600')"
                        class="rounded-full px-3 py-1 font-medium">
                        {{ i + 1 }}. {{ label }}
                    </li>
                </ol>

                <!-- Step 1 — host & credential -->
                <div v-if="joinStep === 1" class="space-y-3">
                    <p class="max-w-2xl text-sm text-slate-600">
                        {{ t('c_jurisdictions.federation.step1_body_1', 'Adopt this instance into an existing cluster as a ') }}<strong>{{ t('c_jurisdictions.federation.mirror_ro', 'read-only mirror') }}</strong>{{ t('c_jurisdictions.federation.step1_body_2', ' of its public records — authoritative for nothing. With a join key it is admitted at once; without one, a request is queued for the host operator to vouch.') }}
                    </p>
                    <label class="block text-sm">
                        <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('c_jurisdictions.federation.lbl_host_url', 'Host URL') }}</span>
                        <input v-model="joinForm.host_url" type="url" required placeholder="https://host.example"
                               class="mt-1 w-full rounded border border-slate-300 px-3 py-1.5 text-sm focus:border-sky-400 focus:outline-none" />
                        <span v-if="joinForm.errors.host_url" class="mt-1 block text-xs text-rose-600">{{ joinForm.errors.host_url }}</span>
                    </label>
                    <label class="block text-sm">
                        <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('c_jurisdictions.federation.lbl_join_key', 'Join key') }} <span class="font-normal normal-case text-slate-600">{{ t('c_jurisdictions.federation.join_key_opt', '(optional — leave blank to request a vouch)') }}</span></span>
                        <input v-model="joinForm.join_key" type="text" placeholder="handle.secret"
                               class="mt-1 w-full rounded border border-slate-300 px-3 py-1.5 font-mono text-sm focus:border-sky-400 focus:outline-none" />
                    </label>
                </div>

                <!-- Step 2 — what to pull -->
                <div v-else-if="joinStep === 2" class="space-y-4">
                    <div class="rounded border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                        <label class="flex items-center gap-2 text-slate-700">
                            <input type="checkbox" checked disabled class="rounded" />
                            <span>{{ t('c_jurisdictions.federation.step2_pub_1', 'The host\'s ') }}<strong>{{ t('c_jurisdictions.federation.step2_pub_records', 'public records') }}</strong>{{ t('c_jurisdictions.federation.step2_pub_2', ' — always pulled (this is what mirroring means).') }}</span>
                        </label>
                    </div>

                    <fieldset class="text-sm">
                        <legend class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('c_jurisdictions.federation.lbl_scope', 'Scope') }}</legend>
                        <label class="mt-1 flex items-center gap-2 text-slate-700">
                            <input type="radio" value="whole" v-model="joinScopeMode" /> {{ t('c_jurisdictions.federation.scope_whole', 'Whole corpus') }}
                        </label>
                        <label class="mt-1 flex items-center gap-2 text-slate-700">
                            <input type="radio" value="subtree" v-model="joinScopeMode" /> {{ t('c_jurisdictions.federation.scope_subtree', 'A specific jurisdiction subtree') }}
                        </label>
                        <input v-if="joinScopeMode === 'subtree'" v-model="joinForm.requested_scope_jurisdiction_id"
                               type="text" :aria-label="t('c_jurisdictions.federation.aria_subtree_uuid', 'Jurisdiction subtree UUID')" :placeholder="t('c_jurisdictions.federation.subtree_uuid_ph', 'jurisdiction UUID')"
                               class="mt-1 w-full rounded border border-slate-300 px-3 py-1.5 font-mono text-xs focus:border-sky-400 focus:outline-none" />
                    </fieldset>

                    <fieldset class="text-sm">
                        <legend class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('c_jurisdictions.federation.lbl_geodata', 'Geodata') }}</legend>
                        <label class="mt-1 flex items-center gap-2 text-slate-700">
                            <input type="radio" value="already_have" v-model="joinForm.geodata_posture" /> {{ t('c_jurisdictions.federation.geo_have', 'I already have the map archive') }}
                        </label>
                        <label class="mt-1 flex items-center gap-2 text-slate-700">
                            <input type="radio" value="pull_from_origin" v-model="joinForm.geodata_posture" />
                            {{ t('c_jurisdictions.federation.geo_pull', 'Pull geodata from the origin') }} <span class="text-xs text-slate-600">{{ t('c_jurisdictions.federation.geo_pull_note', '(CC BY 4.0; rasters delivered in Phase H)') }}</span>
                        </label>
                        <label class="mt-1 flex items-center gap-2 text-slate-700">
                            <input type="radio" value="skip" v-model="joinForm.geodata_posture" /> {{ t('c_jurisdictions.federation.geo_skip', 'Skip — text-only mirror') }}
                        </label>
                    </fieldset>
                </div>

                <!-- Step 3 — relationship & instance options -->
                <div v-else-if="joinStep === 3" class="space-y-3">
                    <fieldset class="text-sm">
                        <legend class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('c_jurisdictions.federation.lbl_relationship', 'Relationship') }}</legend>
                        <label class="mt-1 flex items-center gap-2 text-slate-700">
                            <input type="radio" value="mirror" v-model="joinForm.requested_relation" /> {{ t('c_jurisdictions.federation.rel_mirror', 'Read-only mirror') }}
                        </label>
                        <label class="mt-1 flex items-center gap-2 text-amber-800">
                            <input type="radio" value="co_member" v-model="joinForm.requested_relation" />
                            {{ t('c_jurisdictions.federation.rel_co_1', 'Read-only mirror, ') }}<em>{{ t('c_jurisdictions.federation.rel_co_and', 'and') }}</em>{{ t('c_jurisdictions.federation.rel_co_2', ' I intend to request read-write') }}
                        </label>
                    </fieldset>

                    <div v-if="joinForm.requested_relation === 'co_member'"
                         class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        {{ t('c_jurisdictions.federation.comember_1', 'Read-write is ') }}<strong>{{ t('c_jurisdictions.federation.rw_notgranted', 'not granted by joining') }}</strong>{{ t('c_jurisdictions.federation.comember_2', '. After you are a mirror, you submit a read-write request; the host\'s government must approve it by a supermajority of your jurisdiction\'s residents and a supermajority of constituent jurisdictions (Art. V §7). Only then does authority flip and the sealed operational bundle transfer.') }}
                    </div>

                    <label class="block text-sm">
                        <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('c_jurisdictions.federation.lbl_note_host', 'Note to the host operator') }} <span class="font-normal normal-case text-slate-600">{{ t('c_jurisdictions.federation.optional', '(optional)') }}</span></span>
                        <input v-model="joinForm.note" type="text" maxlength="1000" :placeholder="t('c_jurisdictions.federation.note_mirror_ph', 'why we want to mirror')"
                               class="mt-1 w-full rounded border border-slate-300 px-3 py-1.5 text-sm focus:border-sky-400 focus:outline-none" />
                    </label>
                </div>

                <!-- Step 4 — review & submit -->
                <div v-else class="space-y-2 text-sm text-slate-700">
                    <p>{{ t('c_jurisdictions.federation.review_1', 'You will become a ') }}<strong>{{ t('c_jurisdictions.federation.mirror_ro', 'read-only mirror') }}</strong>{{ t('c_jurisdictions.federation.review_2', ' of ') }}<span class="font-mono">{{ joinForm.host_url || '—' }}</span>{{ t('c_jurisdictions.federation.review_3', ', pulling its public records') }}<span v-if="joinScopeMode === 'subtree'">{{ t('c_jurisdictions.federation.review_scoped', ' (scoped to ') }}<span class="font-mono">{{ joinForm.requested_scope_jurisdiction_id || '—' }}</span>)</span>.</p>
                    <p>{{ t('c_jurisdictions.federation.review_geodata', 'Geodata:') }} <strong>{{ { already_have: t('c_jurisdictions.federation.geo_lbl_have', 'already have the archive'), pull_from_origin: t('c_jurisdictions.federation.geo_lbl_pull', 'pull from the origin'), skip: t('c_jurisdictions.federation.geo_lbl_skip', 'skip (text-only)') }[joinForm.geodata_posture] }}</strong>.</p>
                    <p>{{ t('c_jurisdictions.federation.review_admission', 'Admission:') }} <strong>{{ joinForm.join_key ? t('c_jurisdictions.federation.admission_onestep', 'one-step (join key supplied)') : t('c_jurisdictions.federation.admission_vouch', 'request a vouch') }}</strong>.</p>
                    <p>{{ t('c_jurisdictions.federation.review_you', 'You ') }}<strong>{{ joinForm.requested_relation === 'co_member' ? t('c_jurisdictions.federation.will', 'will') : t('c_jurisdictions.federation.will_not', 'will not') }}</strong>{{ t('c_jurisdictions.federation.review_pursue', ' pursue read-write afterward.') }}</p>
                    <span v-if="joinForm.errors.host_url" class="block text-xs text-rose-600">{{ joinForm.errors.host_url }}</span>
                    <span v-if="joinForm.errors.requested_scope_jurisdiction_id" class="block text-xs text-rose-600">{{ joinForm.errors.requested_scope_jurisdiction_id }}</span>
                </div>

                <!-- Wizard nav -->
                <div class="mt-4 flex items-center gap-2">
                    <button v-if="joinStep > 1" type="button" @click="joinStep--"
                            class="rounded border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-600 hover:bg-slate-50">{{ t('c_jurisdictions.federation.btn_back', 'Back') }}</button>
                    <button v-if="joinStep < 4" type="button" @click="joinStep++" :disabled="joinStep === 1 && !joinForm.host_url"
                            class="rounded bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-700 disabled:opacity-50">{{ t('c_jurisdictions.federation.btn_next', 'Next') }}</button>
                    <button v-else type="button" @click="join" :disabled="joinForm.processing"
                            class="rounded bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-700 disabled:opacity-50">
                        {{ joinForm.processing ? t('c_jurisdictions.federation.joining', 'Joining…') : t('c_jurisdictions.federation.btn_join', 'Join a cluster') }}
                    </button>
                </div>
            </div>
        </section>

        <!-- G3c — Host adoption console (operator-gated): mint/approve invite keys in the browser -->
        <section class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-slate-900">{{ t('c_jurisdictions.federation.console_title', 'Host adoption console') }}</h2>

            <!-- Not signed in as an operator -->
            <p v-if="!host.authed" class="mt-3 text-sm text-slate-600">
                {{ t('c_jurisdictions.federation.console_signin_1', 'Minting and approving invite keys is an operator action.') }}
                <a href="/operator/login" class="font-medium text-sky-700 underline">{{ t('c_jurisdictions.federation.console_signin_link', 'Sign in as an operator →') }}</a>
            </p>

            <div v-else class="mt-3 space-y-5">
                <!-- The minted key, shown ONCE (one-shot flash) -->
                <div v-if="mintedKey" class="rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm">
                    <p class="font-semibold text-amber-900">{{ t('c_jurisdictions.federation.minted_copy', 'Copy this invite key now — it is shown only once:') }}</p>
                    <code class="mt-1 block break-all rounded bg-white px-2 py-1 font-mono text-xs text-slate-800">{{ mintedKey }}</code>
                </div>

                <!-- Mint -->
                <form class="flex flex-wrap items-end gap-3" @submit.prevent="mint">
                    <label class="text-sm">
                        <span class="block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('c_jurisdictions.federation.lbl_max_uses', 'Max uses') }}</span>
                        <input v-model.number="mintForm.max_uses" type="number" min="1" max="100"
                               class="mt-1 w-24 rounded border border-slate-300 px-2 py-1 text-sm" />
                    </label>
                    <label class="text-sm">
                        <span class="block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('c_jurisdictions.federation.lbl_expires', 'Expires in (days)') }}</span>
                        <input v-model.number="mintForm.expires_in_days" type="number" min="1" max="365" :placeholder="t('c_jurisdictions.federation.expires_never', 'never')"
                               class="mt-1 w-28 rounded border border-slate-300 px-2 py-1 text-sm" />
                    </label>
                    <button type="submit" :disabled="mintForm.processing"
                            class="rounded bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-700 disabled:opacity-50">
                        {{ mintForm.processing ? t('c_jurisdictions.federation.minting', 'Minting…') : t('c_jurisdictions.federation.btn_mint', 'Mint invite key') }}
                    </button>
                </form>

                <!-- Invite keys -->
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('c_jurisdictions.federation.invite_keys_title', 'Invite keys') }}</h3>
                    <p v-if="!host.keys || host.keys.length === 0" class="mt-1 text-sm text-slate-500">{{ t('c_jurisdictions.federation.no_keys', 'No keys minted yet.') }}</p>
                    <table v-else class="mt-2 w-full text-left text-sm">
                        <thead class="text-xs uppercase tracking-wide text-slate-500">
                            <tr><th class="py-1">{{ t('c_jurisdictions.federation.th_handle', 'Handle') }}</th><th>{{ t('c_jurisdictions.federation.th_uses', 'Uses') }}</th><th>{{ t('c_jurisdictions.federation.th_state', 'State') }}</th><th>{{ t('c_jurisdictions.federation.th_expires', 'Expires') }}</th><th></th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="k in host.keys" :key="k.handle" class="border-t border-slate-100">
                                <td class="py-1.5 font-mono text-slate-700">{{ k.handle }}</td>
                                <td class="text-slate-600">{{ k.uses }}/{{ k.max_uses }}</td>
                                <td>
                                    <span :class="k.live ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'"
                                          class="rounded px-2 py-0.5 text-xs font-medium">{{ k.revoked_at ? t('c_jurisdictions.federation.key_revoked', 'revoked') : (k.live ? t('c_jurisdictions.federation.key_live', 'live') : t('c_jurisdictions.federation.key_dead', 'dead')) }}</span>
                                </td>
                                <td class="text-slate-500">{{ k.expires_at ? new Date(k.expires_at).toLocaleString() : '—' }}</td>
                                <td class="text-right">
                                    <button v-if="k.live" type="button" @click="revokeKey(k.handle)"
                                            class="rounded border border-rose-300 px-2 py-0.5 text-xs font-medium text-rose-700 hover:bg-rose-50">{{ t('c_jurisdictions.federation.btn_revoke', 'Revoke') }}</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pending adoption requests -->
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('c_jurisdictions.federation.adopt_pending_title', 'Pending adoption requests') }}</h3>
                    <p v-if="!host.requests || host.requests.length === 0" class="mt-1 text-sm text-slate-500">{{ t('c_jurisdictions.federation.no_pending', 'No pending requests.') }}</p>
                    <ul v-else class="mt-2 space-y-2 text-sm">
                        <li v-for="r in host.requests" :key="r.id" class="flex items-start justify-between gap-3 border-t border-slate-100 pt-2">
                            <span class="min-w-0">
                                <span class="text-slate-700">{{ r.applicant_name || t('c_jurisdictions.federation.unnamed', 'Unnamed applicant') }}</span>
                                <span class="font-mono text-slate-600"> · {{ r.applicant_server_id }}…</span>
                                <span v-if="r.requested_relation === 'co_member'"
                                      class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-800">{{ t('c_jurisdictions.federation.intends_rw', 'intends read-write') }}</span>
                                <span v-else class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-xs font-medium text-slate-600">{{ t('c_jurisdictions.federation.tag_mirror', 'mirror') }}</span>
                                <span v-if="r.requested_scope" class="block text-xs text-slate-500">{{ t('c_jurisdictions.federation.scope_label', 'scope:') }} <span class="font-mono">{{ r.requested_scope }}…</span></span>
                                <span v-if="r.note" class="block text-xs text-slate-600">{{ r.note }}</span>
                                <span v-if="r.requested_relation === 'co_member'" class="block text-xs text-amber-700">
                                    {{ t('c_jurisdictions.federation.approve_note', 'Approving admits a read-only mirror only — read-write is the jurisdiction\'s government\'s call (Art. V §7).') }}
                                </span>
                            </span>
                            <span class="flex shrink-0 gap-2">
                                <button type="button" @click="approveReq(r.id)"
                                        class="rounded bg-emerald-600 px-2 py-0.5 text-xs font-medium text-white hover:bg-emerald-700">{{ t('c_jurisdictions.federation.btn_approve', 'Approve') }}</button>
                                <button type="button" @click="rejectReq(r.id)"
                                        class="rounded border border-slate-300 px-2 py-0.5 text-xs font-medium text-slate-600 hover:bg-slate-50">{{ t('c_jurisdictions.federation.btn_reject', 'Reject') }}</button>
                            </span>
                        </li>
                    </ul>
                </div>

                <!-- Read-write petitions (G3c intake; the GRANT is the governed flow) -->
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('c_jurisdictions.federation.rw_petitions_title', 'Read-write petitions') }}</h3>
                    <p v-if="!host.rw_requests || host.rw_requests.length === 0" class="mt-1 text-sm text-slate-500">{{ t('c_jurisdictions.federation.no_rw_petitions', 'No read-write petitions.') }}</p>
                    <ul v-else class="mt-2 space-y-2 text-sm">
                        <li v-for="r in host.rw_requests" :key="r.id" class="flex items-center justify-between border-t border-slate-100 pt-2">
                            <span>
                                <span class="font-mono text-slate-600">{{ r.applicant_server_id }}…</span>
                                <span class="text-slate-500">{{ t('c_jurisdictions.federation.wants_rw_over', ' wants read-write over ') }}</span>
                                <span class="font-mono text-slate-600">{{ r.root_jurisdiction_id }}…</span>
                                <span v-if="r.note" class="block text-xs text-slate-600">{{ r.note }}</span>
                            </span>
                            <button type="button" @click="denyRw(r.id)"
                                    class="rounded border border-slate-300 px-2 py-0.5 text-xs font-medium text-slate-600 hover:bg-slate-50">{{ t('c_jurisdictions.federation.btn_deny', 'Deny') }}</button>
                        </li>
                    </ul>
                </div>

                <p class="text-xs text-slate-600">
                    {{ t('c_jurisdictions.federation.console_foot_1', 'Approving an adoption admits a ') }}<strong>{{ t('c_jurisdictions.federation.mirror_ro', 'read-only mirror') }}</strong>{{ t('c_jurisdictions.federation.console_foot_2', ' (authoritative for nothing). Read-write is a separate ') }}<strong>{{ t('c_jurisdictions.federation.console_foot_governed', 'governed') }}</strong>{{ t('c_jurisdictions.federation.console_foot_3', ' grant — decided by the jurisdiction\'s standing government (Art. V §7) or, where there is none, the de-facto operator board — not a console click.') }}
                </p>
            </div>
        </section>

        <!-- Peers -->
        <section class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-slate-900">{{ t('c_jurisdictions.federation.peers_title', 'Peers') }} <span class="text-slate-600">({{ peers.length }})</span></h2>
            <p v-if="peers.length === 0" class="mt-2 text-sm text-slate-500">
                {{ t('c_jurisdictions.federation.no_peers', 'No peers yet — discover one with ') }}<code class="rounded bg-slate-100 px-1 text-slate-700">federation:peer:discover &lt;url&gt;</code>.
            </p>
            <table v-else class="mt-3 w-full text-left text-sm">
                <thead class="text-xs uppercase tracking-wide text-slate-500">
                    <tr><th class="py-1">{{ t('c_jurisdictions.federation.peer_name', 'Name') }}</th><th>{{ t('c_jurisdictions.federation.peer_server', 'Server') }}</th><th>{{ t('c_jurisdictions.federation.peer_url', 'URL') }}</th><th>{{ t('c_jurisdictions.federation.peer_status', 'Status') }}</th><th>{{ t('c_jurisdictions.federation.peer_heartbeat', 'Last heartbeat') }}</th></tr>
                </thead>
                <tbody>
                    <tr v-for="p in peers" :key="p.id" class="border-t border-slate-100">
                        <td class="py-1.5 text-slate-800">{{ p.name || '—' }}</td>
                        <td class="font-mono text-slate-600">{{ shortId(p.server_id) }}</td>
                        <td class="text-slate-600">{{ p.url }}</td>
                        <td><span :class="statusClass(p.status)" class="rounded px-2 py-0.5 text-xs font-medium">{{ p.status }}</span></td>
                        <td class="text-slate-500">{{ p.last_heartbeat_at ? new Date(p.last_heartbeat_at).toLocaleString() : '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </section>

        <!-- Sync ledger -->
        <section class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-slate-900">{{ t('c_jurisdictions.federation.sync_title', 'Sync ledger') }} <span class="text-slate-600">{{ t('c_jurisdictions.federation.sync_appendonly', '(append-only)') }}</span></h2>
            <p v-if="sync.length === 0" class="mt-2 text-sm text-slate-500">{{ t('c_jurisdictions.federation.no_sync', 'No sync exchanges recorded yet.') }}</p>
            <table v-else class="mt-3 w-full text-left text-sm">
                <thead class="text-xs uppercase tracking-wide text-slate-500">
                    <tr><th class="py-1">#</th><th>{{ t('c_jurisdictions.federation.sync_direction', 'Direction') }}</th><th>{{ t('c_jurisdictions.federation.sync_result', 'Result') }}</th><th>{{ t('c_jurisdictions.federation.sync_seqs', 'Seqs') }}</th><th>{{ t('c_jurisdictions.federation.sync_when', 'When') }}</th></tr>
                </thead>
                <tbody>
                    <tr v-for="s in sync" :key="s.seq" class="border-t border-slate-100">
                        <td class="py-1.5 font-mono text-slate-500">{{ s.seq }}</td>
                        <td class="text-slate-700">{{ s.direction }}</td>
                        <td><span :class="resultClass(s.result)" class="rounded px-2 py-0.5 text-xs font-medium">{{ s.result }}</span></td>
                        <td class="font-mono text-slate-500">{{ s.from_seq ?? '—' }} → {{ s.to_seq ?? '—' }}</td>
                        <td class="text-slate-500">{{ s.created_at ? new Date(s.created_at).toLocaleString() : '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </section>

        <!-- Authority claims + checkpoints -->
        <div class="grid gap-6 md:grid-cols-2">
            <section class="rounded-lg border border-slate-200 bg-white p-5">
                <h2 class="text-sm font-semibold text-slate-900">{{ t('c_jurisdictions.federation.claims_title', 'Authority claims') }}</h2>
                <p v-if="claims.length === 0" class="mt-2 text-sm text-slate-500">{{ t('c_jurisdictions.federation.no_claims', 'No flipped partitions.') }}</p>
                <ul v-else class="mt-3 space-y-2 text-sm">
                    <li v-for="c in claims" :key="c.id" class="flex items-center justify-between border-t border-slate-100 pt-2">
                        <span class="font-mono text-slate-600">{{ shortId(c.jurisdiction_id) }}</span>
                        <span class="text-slate-700">→ {{ c.authority }}</span>
                        <span class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{{ c.resolution }}</span>
                    </li>
                </ul>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-5">
                <h2 class="text-sm font-semibold text-slate-900">{{ t('c_jurisdictions.federation.checkpoints_title', 'Head checkpoints') }}</h2>
                <p v-if="checkpoints.length === 0" class="mt-2 text-sm text-slate-500">{{ t('c_jurisdictions.federation.no_checkpoints', 'No checkpoints published.') }}</p>
                <ul v-else class="mt-3 space-y-2 text-sm">
                    <li v-for="c in checkpoints" :key="c.seq" class="flex items-center justify-between border-t border-slate-100 pt-2">
                        <span class="text-slate-600">{{ t('c_jurisdictions.federation.audit_seq', { seq: c.audit_seq }) }}</span>
                        <span class="font-mono text-xs text-slate-500">{{ c.head_hash }}</span>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>

<style scoped>
/* W-0336: placeholder text at slate-500 (4.7:1 on the white fields); the browser
   default grey-400 measured 2.6:1 in the host sweep of 2026-09-14. */
input::placeholder,
textarea::placeholder { color: #64748b; opacity: 1; }
/* The fields on this console are light (white ground); the shell's muted field colour
   measured 2.6:1 on them. Typed text reads at slate-900. */
input, textarea, select { color: #0f172b; }
</style>
