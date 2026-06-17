<script setup>
import { computed } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
    instance: { type: Object, required: true },
    mirror: { type: Object, default: () => ({ is_mirror: false }) },
    roots: { type: Array, default: () => [] },
    peers: { type: Array, default: () => [] },
    sync: { type: Array, default: () => [] },
    checkpoints: { type: Array, default: () => [] },
    claims: { type: Array, default: () => [] },
    host: { type: Object, default: () => ({ authed: false }) },
});

const page = usePage();
const flash = computed(() => page.props.flash?.status ?? null);

// G3b — "Join a cluster": adopt this instance as a read-only mirror.
const joinForm = useForm({ host_url: '', join_key: '' });

const join = () => joinForm.post('/federation/cluster/join', { preserveScroll: true });

const leave = () => {
    if (window.confirm('Leave the cluster? This instance will stop being a read-only mirror.')) {
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
    if (window.confirm(`Revoke invite key ${handle}? Mirrors that already joined are unaffected.`)) {
        router.post('/federation/host/keys/revoke', { handle }, { preserveScroll: true });
    }
};
const approveReq = (id) => router.post(`/federation/host/requests/${id}/approve`, {}, { preserveScroll: true });
const rejectReq = (id) => router.post(`/federation/host/requests/${id}/reject`, {}, { preserveScroll: true });
const denyRw = (id) => router.post(`/federation/host/rw/${id}/deny`, {}, { preserveScroll: true });

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
</script>

<template>
    <Head title="Federation" />

    <div class="mx-auto max-w-5xl space-y-6 p-6">
        <header>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Phase F · WF-JUR-06 · Art. V §2</p>
            <h1 class="text-2xl font-semibold text-slate-900">Federation</h1>
            <p class="mt-1 max-w-2xl text-sm text-slate-600">
                The peer mesh and the Full-Faith-&-Credit record. Peers authenticate by Ed25519 signature; every
                synced tail is verified against the peer's chain before it is applied, and a record for a jurisdiction
                this instance is authoritative for is never overwritten.
            </p>
        </header>

        <!-- This instance's identity -->
        <section class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-slate-900">This instance</h2>
            <dl class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Name</dt>
                    <dd class="text-slate-800">{{ instance.name }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Server ID</dt>
                    <dd class="font-mono text-slate-800">{{ shortId(instance.server_id) }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Key fingerprint</dt>
                    <dd class="font-mono text-xs text-slate-700">{{ instance.public_key_fp || '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Mesh</dt>
                    <dd>
                        <span :class="instance.enabled ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'"
                              class="rounded px-2 py-0.5 text-xs font-medium">
                            {{ instance.enabled ? 'enabled' : 'disabled' }}
                        </span>
                    </dd>
                </div>
            </dl>
        </section>

        <!-- G3b — Cluster membership: join as a read-only mirror, or leave -->
        <section class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-slate-900">Cluster membership</h2>

            <p v-if="flash" class="mt-2 rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                {{ flash }}
            </p>

            <!-- Already a read-only mirror -->
            <div v-if="mirror.is_mirror" class="mt-3 space-y-3">
                <div class="rounded border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-800">
                    This instance is a <strong>read-only mirror</strong> of host
                    <span class="font-mono">{{ shortId(mirror.host_server_id) }}</span>
                    <span v-if="mirror.membership_state"> (state: {{ mirror.membership_state }})</span>.
                    It is authoritative for nothing and accepts no constitutional filings.
                    <span v-if="mirror.adopted_at" class="block text-xs text-sky-600">
                        Adopted {{ new Date(mirror.adopted_at).toLocaleString() }}.
                    </span>
                </div>
                <button type="button" @click="leave"
                        class="rounded border border-rose-300 bg-white px-3 py-1.5 text-sm font-medium text-rose-700 hover:bg-rose-50">
                    Leave the cluster
                </button>

                <!-- G3c — operator-grade: petition the host for read-write authority.
                     Composes + sends the request only; the grant is the governed flow. -->
                <div v-if="host.authed" class="rounded border border-amber-200 bg-amber-50 px-3 py-3">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-amber-800">Request read-write authority</h3>
                    <p class="mt-1 max-w-2xl text-xs text-amber-700">
                        Read-write is <strong>not granted by joining</strong>. This sends a petition to the host; its
                        government must approve it by a supermajority of your jurisdiction's residents and a supermajority
                        of constituent jurisdictions (Art. V §7). Only then does authority flip and the sealed operational
                        bundle transfer. This instance stays authoritative for nothing until then.
                    </p>
                    <form class="mt-2 flex flex-wrap items-end gap-3" @submit.prevent="requestRw">
                        <label class="text-sm">
                            <span class="block text-xs font-semibold uppercase tracking-wide text-slate-500">Jurisdiction subtree</span>
                            <select v-model="rwForm.root_jurisdiction_id"
                                    class="mt-1 rounded border border-slate-300 px-2 py-1 text-sm">
                                <option v-for="j in roots" :key="j.id" :value="j.id">{{ j.name }}</option>
                            </select>
                        </label>
                        <label class="grow text-sm">
                            <span class="block text-xs font-semibold uppercase tracking-wide text-slate-500">Note <span class="font-normal normal-case text-slate-400">(optional)</span></span>
                            <input v-model="rwForm.note" type="text" maxlength="1000" placeholder="why we run a vetted node here"
                                   class="mt-1 w-full rounded border border-slate-300 px-2 py-1 text-sm" />
                        </label>
                        <button type="submit" :disabled="rwForm.processing || !rwForm.root_jurisdiction_id"
                                class="rounded bg-amber-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-amber-700 disabled:opacity-50">
                            {{ rwForm.processing ? 'Sending…' : 'Request read-write authority' }}
                        </button>
                    </form>
                    <p v-if="rwForm.errors.rw_request" class="mt-1 text-xs text-rose-600">{{ rwForm.errors.rw_request }}</p>
                    <p v-if="rwForm.errors.root_jurisdiction_id" class="mt-1 text-xs text-rose-600">{{ rwForm.errors.root_jurisdiction_id }}</p>
                </div>
            </div>

            <!-- Not a mirror — offer to join one -->
            <form v-else class="mt-3 space-y-3" @submit.prevent="join">
                <p class="max-w-2xl text-sm text-slate-600">
                    Adopt this instance into an existing cluster as a <strong>read-only mirror</strong> of its public
                    records. A mirror copies the host and is authoritative for nothing. With a join key it is admitted
                    at once; without one, a request is queued for the host operator to vouch.
                </p>

                <label class="block text-sm">
                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Host URL</span>
                    <input v-model="joinForm.host_url" type="url" required placeholder="https://host.example"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-1.5 text-sm focus:border-sky-400 focus:outline-none" />
                    <span v-if="joinForm.errors.host_url" class="mt-1 block text-xs text-rose-600">{{ joinForm.errors.host_url }}</span>
                </label>

                <label class="block text-sm">
                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Join key <span class="font-normal normal-case text-slate-400">(optional — leave blank to request a vouch)</span></span>
                    <input v-model="joinForm.join_key" type="text" placeholder="handle.secret"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-1.5 font-mono text-sm focus:border-sky-400 focus:outline-none" />
                </label>

                <button type="submit" :disabled="joinForm.processing"
                        class="rounded bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-700 disabled:opacity-50">
                    {{ joinForm.processing ? 'Joining…' : 'Join a cluster' }}
                </button>
            </form>
        </section>

        <!-- G3c — Host adoption console (operator-gated): mint/approve invite keys in the browser -->
        <section class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-slate-900">Host adoption console</h2>

            <!-- Not signed in as an operator -->
            <p v-if="!host.authed" class="mt-3 text-sm text-slate-600">
                Minting and approving invite keys is an operator action.
                <a href="/operator/login" class="font-medium text-sky-700 hover:underline">Sign in as an operator →</a>
            </p>

            <div v-else class="mt-3 space-y-5">
                <!-- The minted key, shown ONCE (one-shot flash) -->
                <div v-if="mintedKey" class="rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm">
                    <p class="font-semibold text-amber-900">Copy this invite key now — it is shown only once:</p>
                    <code class="mt-1 block break-all rounded bg-white px-2 py-1 font-mono text-xs text-slate-800">{{ mintedKey }}</code>
                </div>

                <!-- Mint -->
                <form class="flex flex-wrap items-end gap-3" @submit.prevent="mint">
                    <label class="text-sm">
                        <span class="block text-xs font-semibold uppercase tracking-wide text-slate-500">Max uses</span>
                        <input v-model.number="mintForm.max_uses" type="number" min="1" max="100"
                               class="mt-1 w-24 rounded border border-slate-300 px-2 py-1 text-sm" />
                    </label>
                    <label class="text-sm">
                        <span class="block text-xs font-semibold uppercase tracking-wide text-slate-500">Expires in (days)</span>
                        <input v-model.number="mintForm.expires_in_days" type="number" min="1" max="365" placeholder="never"
                               class="mt-1 w-28 rounded border border-slate-300 px-2 py-1 text-sm" />
                    </label>
                    <button type="submit" :disabled="mintForm.processing"
                            class="rounded bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-700 disabled:opacity-50">
                        {{ mintForm.processing ? 'Minting…' : 'Mint invite key' }}
                    </button>
                </form>

                <!-- Invite keys -->
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Invite keys</h3>
                    <p v-if="!host.keys || host.keys.length === 0" class="mt-1 text-sm text-slate-500">No keys minted yet.</p>
                    <table v-else class="mt-2 w-full text-left text-sm">
                        <thead class="text-xs uppercase tracking-wide text-slate-500">
                            <tr><th class="py-1">Handle</th><th>Uses</th><th>State</th><th>Expires</th><th></th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="k in host.keys" :key="k.handle" class="border-t border-slate-100">
                                <td class="py-1.5 font-mono text-slate-700">{{ k.handle }}</td>
                                <td class="text-slate-600">{{ k.uses }}/{{ k.max_uses }}</td>
                                <td>
                                    <span :class="k.live ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'"
                                          class="rounded px-2 py-0.5 text-xs font-medium">{{ k.revoked_at ? 'revoked' : (k.live ? 'live' : 'dead') }}</span>
                                </td>
                                <td class="text-slate-500">{{ k.expires_at ? new Date(k.expires_at).toLocaleString() : '—' }}</td>
                                <td class="text-right">
                                    <button v-if="k.live" type="button" @click="revokeKey(k.handle)"
                                            class="rounded border border-rose-300 px-2 py-0.5 text-xs font-medium text-rose-700 hover:bg-rose-50">Revoke</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pending adoption requests -->
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Pending adoption requests</h3>
                    <p v-if="!host.requests || host.requests.length === 0" class="mt-1 text-sm text-slate-500">No pending requests.</p>
                    <ul v-else class="mt-2 space-y-2 text-sm">
                        <li v-for="r in host.requests" :key="r.id" class="flex items-center justify-between border-t border-slate-100 pt-2">
                            <span class="font-mono text-slate-600">{{ r.applicant_server_id }}…</span>
                            <span class="flex gap-2">
                                <button type="button" @click="approveReq(r.id)"
                                        class="rounded bg-emerald-600 px-2 py-0.5 text-xs font-medium text-white hover:bg-emerald-700">Approve</button>
                                <button type="button" @click="rejectReq(r.id)"
                                        class="rounded border border-slate-300 px-2 py-0.5 text-xs font-medium text-slate-600 hover:bg-slate-50">Reject</button>
                            </span>
                        </li>
                    </ul>
                </div>

                <!-- Read-write petitions (G3c intake; the GRANT is the governed flow) -->
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Read-write petitions</h3>
                    <p v-if="!host.rw_requests || host.rw_requests.length === 0" class="mt-1 text-sm text-slate-500">No read-write petitions.</p>
                    <ul v-else class="mt-2 space-y-2 text-sm">
                        <li v-for="r in host.rw_requests" :key="r.id" class="flex items-center justify-between border-t border-slate-100 pt-2">
                            <span>
                                <span class="font-mono text-slate-600">{{ r.applicant_server_id }}…</span>
                                <span class="text-slate-500"> wants read-write over </span>
                                <span class="font-mono text-slate-600">{{ r.root_jurisdiction_id }}…</span>
                                <span v-if="r.note" class="block text-xs text-slate-400">{{ r.note }}</span>
                            </span>
                            <button type="button" @click="denyRw(r.id)"
                                    class="rounded border border-slate-300 px-2 py-0.5 text-xs font-medium text-slate-600 hover:bg-slate-50">Deny</button>
                        </li>
                    </ul>
                </div>

                <p class="text-xs text-slate-400">
                    Approving an adoption admits a <strong>read-only mirror</strong> (authoritative for nothing).
                    Read-write is a separate <strong>governed</strong> grant — decided by the jurisdiction's standing
                    government (Art. V §7) or, where there is none, the de-facto operator board — not a console click.
                </p>
            </div>
        </section>

        <!-- Peers -->
        <section class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-slate-900">Peers <span class="text-slate-400">({{ peers.length }})</span></h2>
            <p v-if="peers.length === 0" class="mt-2 text-sm text-slate-500">
                No peers yet — discover one with <code class="rounded bg-slate-100 px-1">federation:peer:discover &lt;url&gt;</code>.
            </p>
            <table v-else class="mt-3 w-full text-left text-sm">
                <thead class="text-xs uppercase tracking-wide text-slate-500">
                    <tr><th class="py-1">Name</th><th>Server</th><th>URL</th><th>Status</th><th>Last heartbeat</th></tr>
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
            <h2 class="text-sm font-semibold text-slate-900">Sync ledger <span class="text-slate-400">(append-only)</span></h2>
            <p v-if="sync.length === 0" class="mt-2 text-sm text-slate-500">No sync exchanges recorded yet.</p>
            <table v-else class="mt-3 w-full text-left text-sm">
                <thead class="text-xs uppercase tracking-wide text-slate-500">
                    <tr><th class="py-1">#</th><th>Direction</th><th>Result</th><th>Seqs</th><th>When</th></tr>
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
                <h2 class="text-sm font-semibold text-slate-900">Authority claims</h2>
                <p v-if="claims.length === 0" class="mt-2 text-sm text-slate-500">No flipped partitions.</p>
                <ul v-else class="mt-3 space-y-2 text-sm">
                    <li v-for="c in claims" :key="c.id" class="flex items-center justify-between border-t border-slate-100 pt-2">
                        <span class="font-mono text-slate-600">{{ shortId(c.jurisdiction_id) }}</span>
                        <span class="text-slate-700">→ {{ c.authority }}</span>
                        <span class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{{ c.resolution }}</span>
                    </li>
                </ul>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-5">
                <h2 class="text-sm font-semibold text-slate-900">Head checkpoints</h2>
                <p v-if="checkpoints.length === 0" class="mt-2 text-sm text-slate-500">No checkpoints published.</p>
                <ul v-else class="mt-3 space-y-2 text-sm">
                    <li v-for="c in checkpoints" :key="c.seq" class="flex items-center justify-between border-t border-slate-100 pt-2">
                        <span class="text-slate-600">audit seq {{ c.audit_seq }}</span>
                        <span class="font-mono text-xs text-slate-500">{{ c.head_hash }}</span>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
