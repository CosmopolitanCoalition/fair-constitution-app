<script setup>
import { Head } from '@inertiajs/vue3';

defineProps({
    instance: { type: Object, required: true },
    peers: { type: Array, default: () => [] },
    sync: { type: Array, default: () => [] },
    checkpoints: { type: Array, default: () => [] },
    claims: { type: Array, default: () => [] },
});

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
