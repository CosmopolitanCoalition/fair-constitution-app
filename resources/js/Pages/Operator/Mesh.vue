<script setup>import { useLocaleFormat } from '@/composables/useLocaleFormat';
const localeFmt = useLocaleFormat();
/**
 * Operator/Mesh — mockups-v3-wiring Phase 4 (design contract:
 * mockups/v3/operator/mesh.html; dispositions: PHASE_4_DESIGN_peerage.md).
 *
 * READ-ONLY WRAPPER over GET /operator/mesh (MeshConsoleController::mesh) —
 * peers table, our advertised transports, the peerage gates, and the Full
 * Faith & Credit sync ledger, rendered exactly as the services report them.
 *
 * Gating mirrors /operator/operations: any signed-in citizen reaches the
 * shell, but the mesh data block arrives ONLY for an operator session
 * (`authed`) — a citizen sees the sign-in prompt and `mesh: null`.
 *
 * Settled language (design §3.4): "authority" attaches to a JURISDICTION —
 * where a place's home copy lives — never to a node as a rank; "become a
 * peer" is one process (cert + clients); role elevation is the separate,
 * trust-gated ladder on /operator/roles. The G3c read-write petition ladder
 * is NOT presented here (design flag 1 — the legacy /federation page keeps
 * it until retirement is settled).
 */
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import HostNav from '@/Components/Operator/HostNav.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import Icon from '@/Components/Ui/Icon.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, default: null },
    authed: { type: Boolean, default: false },
    operator: { type: String, default: null },
    /** null for a citizen session — see the controller's gate. */
    mesh: { type: Object, default: null },
});

const { t } = useI18n();

const page = usePage();
const flash = computed(() => page.props.flash?.status ?? null);

/* ------------------------------------------------- defensive prop reads */
const peers = computed(() => props.mesh?.peers ?? []);
const transports = computed(() => props.mesh?.transports ?? []);
const selfUrl = computed(() => props.mesh?.self_url ?? null);
const gates = computed(() => props.mesh?.gates ?? []);
const sync = computed(() => props.mesh?.sync ?? []);

/* ------------------------------------------------------- at a glance */
const trustedCount = computed(() => peers.value.filter((p) => p?.trusted).length);
const lastSync = computed(() => sync.value[0] ?? null);

/** Gate rollup → the mockup's health dot (fail=red, warn=amber, else green). */
const rollup = computed(() => {
    const statuses = gates.value.map((g) => g?.status);
    if (statuses.includes('fail')) return 'red';
    if (statuses.includes('warn')) return 'amber';
    return 'green';
});

/* ----------------------------------------------------- display helpers */
/** Peer trust lifecycle (ESM-20) → badge tone/icon/plain label. */
const PEER_STATUS = {
    discovered: { tone: 'neutral', icon: 'search', label: t('c_operator_pages.mesh.peer_discovered', 'Discovered') },
    handshake: { tone: 'info', icon: 'users', label: t('c_operator_pages.mesh.peer_handshake', 'Handshake') },
    trust_established: { tone: 'success', icon: 'check', label: t('c_operator_pages.mesh.peer_trust', 'Trust established') },
    syncing: { tone: 'info', icon: 'refresh-cw', label: t('c_operator_pages.mesh.peer_syncing', 'Syncing') },
    conflict_resolution: { tone: 'warning', icon: 'alert-triangle', label: t('c_operator_pages.mesh.peer_conflict', 'Resolving a conflict') },
    border_settled: { tone: 'neutral', icon: 'scale', label: t('c_operator_pages.mesh.peer_border', 'Border settled') },
    merged: { tone: 'neutral', icon: 'check', label: t('c_operator_pages.mesh.peer_merged', 'Merged') },
    departed: { tone: 'neutral', icon: 'x', label: t('c_operator_pages.mesh.peer_departed', 'Departed') },
};
const peerStatus = (status) =>
    PEER_STATUS[status] ?? { tone: 'neutral', icon: 'info', label: String(status ?? '—').replaceAll('_', ' ') };

/**
 * The relation chip records how we stand to each peer — a description,
 * never a rank (design §2: "mirror" survives only as a description).
 */
const RELATION_GLOSS = {
    sovereign: t('c_operator_pages.mesh.relation_sovereign', 'a peer with its own home copies — we sync both ways'),
    host: t('c_operator_pages.mesh.relation_host', 'the box we joined under'),
    mirror: t('c_operator_pages.mesh.relation_mirror', 'holds no place’s home copy here — still a full peer for reads and forwarded writes'),
};
const relationGloss = (relation) => RELATION_GLOSS[relation] ?? '';

const GATE_TONE = { pass: 'success', warn: 'warning', fail: 'danger' };
const GATE_ICON = { pass: 'check', warn: 'alert-triangle', fail: 'x' };

/** Sync-ledger result → plain badge (the record never forks). */
const SYNC_RESULT = {
    applied: { tone: 'success', icon: 'check', label: t('c_operator_pages.mesh.sync_applied', 'Applied') },
    conflict_authoritative_wins: { tone: 'warning', icon: 'alert-triangle', label: t('c_operator_pages.mesh.sync_conflict', 'Authority disputed → resolved') },
    rejected_tamper: { tone: 'danger', icon: 'x', label: t('c_operator_pages.mesh.sync_tamper', 'Rejected — tampered') },
    rejected_non_authoritative: { tone: 'danger', icon: 'x', label: t('c_operator_pages.mesh.sync_non_auth', 'Rejected — not the home copy') },
};
const syncResult = (result) =>
    SYNC_RESULT[result] ?? { tone: 'neutral', icon: 'info', label: String(result ?? '—').replaceAll('_', ' ') };

const fmtWhen = (iso) => (iso ? localeFmt.dateTime(new Date(iso)) : '—');
const shortId = (uuid) => (uuid ? String(uuid).slice(0, 8) : '—');

/** How far behind our copy of a peer's record is, when both seqs are known. */
function seqLag(p) {
    if (p?.peer_head_seq == null || p?.last_synced_seq == null) return null;
    return Math.max(0, p.peer_head_seq - p.last_synced_seq);
}

function versionOf(p) {
    const cv = p?.constitutional_version ?? null;
    const rel = p?.app_release ?? null;
    if (cv === null && rel === null) return '—';
    return [cv ?? '?', rel ?? '?'].join(' · ');
}

function syncRange(row) {
    if (row?.from_seq == null && row?.to_seq == null) return '—';
    return `#${row?.from_seq ?? '?'} → #${row?.to_seq ?? '?'}`;
}

const peerColumns = [
    { key: 'name', label: t('c_operator_pages.mesh.col_peer', 'Peer') },
    { key: 'url', label: t('c_operator_pages.mesh.col_url', 'URL') },
    { key: 'status', label: t('c_operator_pages.mesh.col_status', 'Status') },
    { key: 'relation', label: t('c_operator_pages.mesh.col_relation', 'Relation') },
    { key: 'version', label: t('c_operator_pages.mesh.col_version', 'Version') },
    { key: 'last_heartbeat_at', label: t('c_operator_pages.mesh.col_heartbeat', 'Heartbeat') },
    { key: 'seq', label: t('c_operator_pages.mesh.col_sync_seq', 'Sync seq') },
];

const syncColumns = [
    { key: 'seq', label: t('c_operator_pages.mesh.col_entry', 'Entry'), mono: true },
    { key: 'direction', label: t('c_operator_pages.mesh.col_direction', 'Direction') },
    { key: 'peer_id', label: t('c_operator_pages.mesh.col_peer', 'Peer') },
    { key: 'result', label: t('c_operator_pages.mesh.col_result', 'Result') },
    { key: 'range', label: t('c_operator_pages.mesh.col_range', 'Range'), mono: true },
    { key: 'created_at', label: t('c_operator_pages.mesh.col_at', 'At') },
];
</script>

<template>
    <PageScaffold :surface="surface" :title="t('c_operator_pages.mesh.page_title', 'Peers and sync')">
        <HostNav current="mesh" />
        <p><Link href="/operator/federation">{{ t('c_operator_pages.mesh.open_controls', 'Open connection, invitation, and access controls') }}</Link></p>
        <template #intro>
            {{ t('c_operator_pages.mesh.intro', 'How your node finds its peers, carries their records, and survives a hostile network — the federation layer the citizen game rides on.') }}
        </template>

        <Banner v-if="flash" tone="info">{{ flash }}</Banner>

        <!-- The operator plane is capability, not power -->
        <div class="plane-wall">
            <span><Icon name="shield" size="sm" /></span>
            <div>
                <strong style="color: var(--gov-fg)">{{ t('c_operator_pages.mesh.plane_off_strong', 'Off the constitutional plane.') }}</strong>
                {{ t('c_operator_pages.mesh.plane_off_body', 'Joining, syncing and naming peers are capabilities — they buy no vote, no seat, and no say in any constitutional act.') }}
            </div>
        </div>

        <!-- ==================== operator gate (the /operator/operations mechanism) -->
        <Card v-if="!authed" as="section" :title="t('c_operator_pages.mesh.signin_title', 'Operator sign-in required')">
            <p>
                {{ t('c_operator_pages.mesh.signin_body', 'Peers, transports and the sync ledger are shown only to a signed-in operator of this box. Citizens play the game; operators keep the box on the mesh.') }}
            </p>
            <p>
                <Btn as="a" href="/operator/login" variant="primary" icon="arrow-right">
                    {{ t('c_operator_pages.mesh.signin_btn', 'Sign in as an operator') }}
                </Btn>
            </p>
        </Card>

        <template v-else>
            <!-- ============================================== at a glance ===== -->
            <div class="health-line">
                <span class="health-dot" :class="`health-dot--${rollup}`" aria-hidden="true"></span>
                <strong style="color: var(--gov-fg)">{{ t('c_operator_pages.mesh.at_a_glance', 'Peers & sync at a glance') }}</strong>
                <span class="citation">
                    {{ t('c_operator_pages.mesh.glance_trusted', { n: trustedCount }) }} · {{ t('c_operator_pages.mesh.glance_known', { n: peers.length }) }} · {{ t('c_operator_pages.mesh.glance_last_sync', { when: lastSync ? fmtWhen(lastSync.created_at) : t('c_operator_pages.mesh.never', 'never') }) }}
                </span>
            </div>

            <div class="cluster" style="gap: var(--space-6)">
                <Stat :value="peers.length" :label="t('c_operator_pages.mesh.stat_met', 'peers this box has met')" accent />
                <Stat :value="trustedCount" :label="t('c_operator_pages.mesh.stat_trust', 'trust established')" />
                <Stat :value="sync.length" :label="t('c_operator_pages.mesh.stat_ledger', 'recent ledger entries')" />
            </div>

            <!-- ================================================ your peers ===== -->
            <Card as="section">
                <template #title>
                    <h2>{{ t('c_operator_pages.mesh.your_peers', 'Your peers') }} <span class="citation">{{ t('c_operator_pages.mesh.n_known', { n: peers.length }) }}</span></h2>
                </template>
                <p class="cc-small">
                    {{ t('c_operator_pages.mesh.peers_body', 'Every box this node has handshaked with, and where each one sits in the trust lifecycle. The relation chip records how you stand to each peer — it describes data placement, never a rank.') }}
                </p>

                <p v-if="peers.length === 0" class="gloss">
                    {{ t('c_operator_pages.mesh.no_peers_before', 'No peers yet — this box stands alone. Joining a cluster starts with a one-shot join-key minted by a host operator; the join wizard is on') }}
                    <Link href="/operator/federation">{{ t('c_operator_pages.mesh.connections_link', 'Connections and access') }}</Link>{{ t('c_operator_pages.mesh.no_peers_after', ". Joining never moves authority: which box holds a place's home copy stays exactly where it was.") }}
                </p>

                <DataTable
                    v-else
                    :columns="peerColumns"
                    :rows="peers"
                    row-key="server_id"
                    :caption="t('c_operator_pages.mesh.peers_caption', 'Peers and their trust lifecycle')"
                >
                    <template #cell-name="{ row }">
                        <span style="color: var(--gov-fg-strong)">{{ row.name ?? t('c_operator_pages.mesh.unnamed_peer', 'Unnamed peer') }}</span>
                        <span class="citation" style="display: block" data-no-i18n>{{ shortId(row.server_id) }}</span>
                    </template>
                    <template #cell-url="{ row }">
                        <span class="advanced-note" data-no-i18n>{{ row.url ?? '—' }}</span>
                    </template>
                    <template #cell-status="{ row }">
                        <StatusBadge :tone="peerStatus(row.status).tone" :icon="peerStatus(row.status).icon">
                            {{ peerStatus(row.status).label }}
                        </StatusBadge>
                    </template>
                    <template #cell-relation="{ row }">
                        <span class="relation-chip" :title="relationGloss(row.relation)" data-no-i18n>
                            {{ row.relation ?? 'sovereign' }}
                        </span>
                    </template>
                    <template #cell-version="{ row }">
                        <span class="advanced-note" data-no-i18n>{{ versionOf(row) }}</span>
                    </template>
                    <template #cell-last_heartbeat_at="{ row }">
                        {{ fmtWhen(row.last_heartbeat_at) }}
                    </template>
                    <template #cell-seq="{ row }">
                        <span class="advanced-note" data-no-i18n>
                            {{ row.last_synced_seq ?? '—' }}<template v-if="row.peer_head_seq != null"> / {{ row.peer_head_seq }}</template>
                        </span>
                        <span v-if="seqLag(row) !== null" class="citation" style="display: block">
                            {{ seqLag(row) === 0 ? t('c_operator_pages.mesh.caught_up', 'caught up') : t('c_operator_pages.mesh.behind', { n: seqLag(row) }) }}
                        </span>
                    </template>
                </DataTable>
                <span class="citation">{{ t('c_operator_pages.mesh.trust_lifecycle', 'Trust lifecycle: discovered · handshake · trust established · syncing · border settled') }}</span>
            </Card>

            <!-- ====================================== becoming a full peer ===== -->
            <Card as="section" :title="t('c_operator_pages.mesh.becoming_title', 'Becoming a full peer')">
                <p class="cc-small">
                    {{ t('c_operator_pages.mesh.becoming_before', "There is one path to peerage, and every node walks it: get a certificate, take clients, and hold the whole public record. Once you're in, your box is a full, equal peer — it serves players like any other node. Role elevation is the separate, trust-gated ladder on") }}
                    <Link href="/operator/roles">{{ t('c_operator_pages.mesh.roles_link', 'Roles & channels') }}</Link>{{ t('c_operator_pages.mesh.becoming_after', '.') }}
                </p>
                <div class="grid-2">
                    <Card inset>
                        <strong style="color: var(--gov-fg)">{{ t('c_operator_pages.mesh.differ_title', 'What makes nodes differ') }}</strong>
                        <p class="cc-small">
                            {{ t('c_operator_pages.mesh.differ_body', "Only two things. Services a box's hardware can't host — a small box might skip voice or the live rooms. And the trust-gated naming channels, which reach under other peers' zones and so need those peers' consent.") }}
                        </p>
                    </Card>
                    <Card inset>
                        <strong style="color: var(--gov-fg)">{{ t('c_operator_pages.mesh.authority_title', 'What “authority” means') }}</strong>
                        <p class="cc-small">
                            {{ t('c_operator_pages.mesh.authority_before', "For every jurisdiction, one box's record is the authoritative one — a bookkeeping fact about where the home copy lives, never a rank. A player can act on") }} <strong>{{ t('c_operator_pages.mesh.any', 'any') }}</strong> {{ t('c_operator_pages.mesh.authority_after', "node: the action is verified — signed by their own device, carrying an attestation from their home server — and forwarded to the place's home copy.") }}
                        </p>
                    </Card>
                </div>

                <!-- The authority-flip explainer (design contract: 3 verified steps) -->
                <Card inset>
                    <strong style="color: var(--gov-fg)">{{ t('c_operator_pages.mesh.moving_title', "Moving a jurisdiction's home copy") }}</strong>
                    <p class="cc-small">
                        {{ t('c_operator_pages.mesh.moving_body', 'When a home box retires (or its government chooses a new host), authority moves in three verified steps:') }}
                    </p>
                    <ol class="cc-small" style="margin-block-end: var(--space-2)">
                        <li>
                            <strong>{{ t('c_operator_pages.mesh.step_export_strong', 'Sealed export') }}</strong> {{ t('c_operator_pages.mesh.step_export_body', "— the departing box seals a signed export of the jurisdiction's records.") }}
                        </li>
                        <li>
                            <strong>{{ t('c_operator_pages.mesh.step_flip_strong', 'Verified flip') }}</strong> {{ t('c_operator_pages.mesh.step_flip_body', '— the receiving peer verifies it against the audit chain, and the mesh records the new home.') }}
                        </li>
                        <li>
                            <strong>{{ t('c_operator_pages.mesh.step_repeer_strong', 'Re-peer') }}</strong> {{ t('c_operator_pages.mesh.step_repeer_body', '— every other node re-points at the new home copy on its next sync.') }}
                        </li>
                    </ol>
                    <p class="gloss">
                        {{ t('c_operator_pages.mesh.moving_note', 'Nothing is lost and nothing pauses: reads continue everywhere throughout, and forwarded writes queue until the flip lands.') }}
                    </p>
                </Card>
                <span class="citation">{{ t('c_operator_pages.mesh.peerage_cite', 'One process to peerage — authority attaches to a place, never a node') }}</span>
            </Card>

            <!-- ==================================== Full Faith & Credit ======== -->
            <Card as="section" :title="t('c_operator_pages.mesh.ffc_title', 'Full Faith & Credit — the sync ledger')">
                <p class="cc-small">
                    {{ t('c_operator_pages.mesh.ffc_body', "When peers carry one another's records, the sync ledger is the receipt. Each row is one push or pull, with its result and the record range it covered. An “authority disputed → resolved” result means the dispute resolved in favour of the place's home copy — the constitutional record never forks.") }}
                </p>

                <p v-if="sync.length === 0" class="gloss">
                    {{ t('c_operator_pages.mesh.no_sync', 'No sync entries yet — the ledger starts writing the moment a peer carries records to or from this box.') }}
                </p>

                <DataTable
                    v-else
                    :columns="syncColumns"
                    :rows="sync"
                    row-key="seq"
                    :caption="t('c_operator_pages.mesh.sync_caption', 'The last 25 sync ledger entries')"
                >
                    <template #cell-seq="{ row }">
                        <span class="advanced-note" data-no-i18n>#{{ row.seq }}</span>
                    </template>
                    <template #cell-direction="{ row }">
                        <StatusBadge :tone="row.direction === 'outbound' ? 'info' : 'neutral'" icon="arrow-right">
                            {{ row.direction === 'outbound' ? t('c_operator_pages.mesh.outbound', 'Outbound') : t('c_operator_pages.mesh.inbound', 'Inbound') }}
                        </StatusBadge>
                    </template>
                    <template #cell-peer_id="{ row }">
                        <span class="advanced-note" data-no-i18n>{{ shortId(row.peer_id) }}</span>
                    </template>
                    <template #cell-result="{ row }">
                        <StatusBadge :tone="syncResult(row.result).tone" :icon="syncResult(row.result).icon">
                            {{ syncResult(row.result).label }}
                        </StatusBadge>
                    </template>
                    <template #cell-range="{ row }">
                        <span class="advanced-note" data-no-i18n>{{ syncRange(row) }}</span>
                    </template>
                    <template #cell-created_at="{ row }">
                        {{ fmtWhen(row.created_at) }}
                    </template>
                </DataTable>

                <div class="lr-note">
                    <Icon name="lock" size="sm" />
                    <span>
                        {{ t('c_operator_pages.mesh.ledger_lr_before', 'The sync ledger is') }} <strong>{{ t('c_operator_pages.mesh.append_only_public', 'append-only and public') }}</strong> {{ t('c_operator_pages.mesh.ledger_lr_after', '— like every record on the constitutional plane, it can be read by anyone and never rewritten.') }}
                    </span>
                </div>
            </Card>

            <!-- ============================================== transports ======= -->
            <Card as="section" :title="t('c_operator_pages.mesh.transports_title', 'Transports')">
                <p class="cc-small">
                    {{ t('c_operator_pages.mesh.transports_before', 'The same signed bytes ride any advertised channel, tried in priority order — so the mesh survives even when the open internet does not. These are the endpoints') }} <em>{{ t('c_operator_pages.mesh.this_box', 'this box') }}</em> {{ t('c_operator_pages.mesh.transports_after', 'advertises to its peers.') }}
                </p>
                <p v-if="transports.length === 0" class="gloss">
                    {{ t('c_operator_pages.mesh.no_transports', 'No transports advertised yet — peers can still reach this box at its federation URL below, but registering transports gives the mesh fallback rungs when that URL fails.') }}
                </p>
                <div v-else class="cluster" style="gap: var(--space-2)">
                    <span
                        v-for="t in transports"
                        :key="`${t.transport}:${t.url}`"
                        class="pill pill--info"
                        data-no-i18n
                    >
                        <code class="advanced-note">{{ t.transport }}</code>&nbsp;{{ t.url }}
                    </span>
                </div>
                <p v-if="selfUrl" class="cc-small">
                    {{ t('c_operator_pages.mesh.federation_url', 'Federation URL — how peers address this box:') }}
                    <span class="advanced-note" data-no-i18n>{{ selfUrl }}</span>
                </p>
            </Card>

            <!-- ================================================== gates ======== -->
            <Card as="section" :title="t('c_operator_pages.mesh.gates_title', 'Peerage gates')">
                <p class="cc-small">
                    {{ t('c_operator_pages.mesh.gates_body', 'What this box still needs before (or to remain) a full peer — a certificate, an identity, a reachable URL. Each gate is probed live; nothing here is a rank, only readiness.') }}
                </p>
                <ul style="list-style: none; padding: 0; display: grid; gap: var(--space-2)">
                    <li v-for="g in gates" :key="g.key" class="cluster" style="gap: var(--space-2)">
                        <StatusBadge :tone="GATE_TONE[g.status] ?? 'neutral'" :icon="GATE_ICON[g.status] ?? 'info'">
                            {{ g.label }}
                        </StatusBadge>
                        <span class="citation">{{ g.detail }}</span>
                    </li>
                    <li v-if="gates.length === 0" class="gloss">{{ t('c_operator_pages.mesh.no_gates', 'No gates reported.') }}</li>
                </ul>
                <p class="gloss">{{ t('c_operator_pages.mesh.signed_in_as', { operator }) }}</p>
            </Card>
        </template>

        <template #about>
            <p>
                {{ t('c_operator_pages.mesh.about', "This console reads the mesh as it is — peers from the handshake registry, our advertised transports, the live peerage gates, and the last 25 entries of the append-only sync ledger. Every join, sync and departure it shows was recorded by the federation services; the page changes nothing. Cluster join is infrastructure, not a constitutional act: it grants no vote, no seat, and no authority over any place's record.") }}
            </p>
        </template>
    </PageScaffold>
</template>
