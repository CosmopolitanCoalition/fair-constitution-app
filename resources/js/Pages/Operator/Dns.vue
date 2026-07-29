<script setup>
/**
 * Operator/Dns — "DNS & certificates" (design contract:
 * mockups/v3/operator/dns.html). READ surface over the mesh-cert-broker
 * machinery: write-only credentials, the failover trust lists, the signed
 * broker routing table, received grants, installed certs, and the broker's
 * own issuance ledger where this box brokers.
 *
 * The mockup's budget rails, DDNS re-point, wildcard grants and
 * non-Cloudflare providers have NO backend — they render labeled exactly
 * as the mockup labels them (future/stub), never simulated
 * (docs/plans/launch/DNS_BROKER_FUTURES.md holds the design notes).
 * Credential set/forget posts to the EXISTING /federation/broker
 * endpoints — one write path, two doors.
 */
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, default: null },
    authed: { type: Boolean, default: false },
    operator: { type: String, default: null },
    /** null for a citizen session — see the controller's gate. */
    dns: { type: Object, default: null },
});

const credDomain = ref('');
const credZone = ref('');
const credToken = ref('');
const busy = ref(false);

function setCredential() {
    if (!credDomain.value || !credZone.value || !credToken.value) return;
    busy.value = true;
    router.post('/federation/broker/credentials', {
        domain: credDomain.value,
        zone_id: credZone.value,
        cloudflare_token: credToken.value,
    }, {
        preserveScroll: true,
        onFinish: () => { busy.value = false; credToken.value = ''; },
    });
}

function forgetCredential(domain) {
    busy.value = true;
    router.post('/federation/broker/credentials/forget', { domain }, {
        preserveScroll: true,
        onFinish: () => { busy.value = false; },
    });
}

const fmtEpoch = (n) => (n ? new Date(n * 1000).toLocaleDateString() : '—');
</script>

<template>
    <PageScaffold :surface="surface" title="DNS & certificates">
        <template #intro>
            Real names and real certificates for mesh nodes: an authority grants, a broker
            writes the record and proves the name, and the node serves on a real cert. The
            token that writes DNS is sealed on the broker box — write-only, encrypted, never
            shown to anyone again.
        </template>

        <Card v-if="!authed" as="section" title="Operator sign-in required">
            <p>
                Credentials, grants and the issuance ledger are shown only to a signed-in
                operator of this box.
            </p>
            <p>
                <Btn as="a" href="/operator/login" variant="primary" icon="arrow-right">
                    Sign in as an operator
                </Btn>
            </p>
        </Card>

        <template v-else>
            <Card as="section" title="How a name becomes a certificate">
                <ol class="flow-steps">
                    <li>A name is chosen — the grant names exactly one host under a brokered domain.</li>
                    <li>The A-record is written FIRST — DNS is identity, and a cert never points at nothing.</li>
                    <li>The ACME proof runs — Let's Encrypt issues against the live record.</li>
                    <li>The node serves on a real certificate — renewals repeat the same path.</li>
                </ol>
            </Card>

            <Card as="section" title="Domains & credentials">
                <Banner v-if="(dns?.credentials ?? []).length === 0" tone="info" role="status">
                    <strong>No domain credentials on this box.</strong> A box only needs one
                    when it brokers a domain — most nodes never do.
                </Banner>
                <DataTable
                    v-else
                    :columns="[
                        { key: 'domain', label: 'Domain' },
                        { key: 'zone_id', label: 'Zone' },
                        { key: 'token', label: 'Write token' },
                        { key: 'source', label: 'Source' },
                        { key: 'actions', label: '' },
                    ]"
                    :rows="dns?.credentials ?? []"
                    row-key="domain"
                >
                    <template #cell-zone_id="{ row }"><span data-no-i18n>{{ row.zone_id }}</span></template>
                    <template #cell-token="{ row }">
                        <StatusBadge v-if="row.configured" tone="success" icon="lock">set — write-only, never shown</StatusBadge>
                        <StatusBadge v-else tone="neutral">not set</StatusBadge>
                    </template>
                    <template #cell-source="{ row }">
                        <StatusBadge :tone="row.source === 'local' ? 'info' : 'neutral'">{{ row.source }}</StatusBadge>
                    </template>
                    <template #cell-actions="{ row }">
                        <Btn variant="ghost" size="sm" :disabled="busy" @click="forgetCredential(row.domain)">Forget</Btn>
                    </template>
                </DataTable>

                <h4>Drop a credential</h4>
                <p>
                    The token is encrypted at rest and never read back to any screen. Setting
                    it makes this box able to broker the domain.
                </p>
                <div class="cluster">
                    <input v-model="credDomain" type="text" placeholder="domain (example.org)" data-no-i18n />
                    <input v-model="credZone" type="text" placeholder="zone id" data-no-i18n />
                    <input v-model="credToken" type="password" placeholder="API token (write-only)" data-no-i18n />
                    <Btn :disabled="busy || !credDomain || !credZone || !credToken" @click="setCredential">Set</Btn>
                </div>
            </Card>

            <Card as="section" title="The broker routing table">
                <p>
                    Signed attestations — which broker may act under which domain, per the
                    domain's authority. Gossiped across the mesh; a mint can never outrun it.
                </p>
                <Banner v-if="(dns?.authorizations ?? []).length === 0" tone="info" role="status">
                    <strong>No live broker authorizations known to this box.</strong>
                </Banner>
                <DataTable
                    v-else
                    :columns="[
                        { key: 'domain', label: 'Domain' },
                        { key: 'broker_server_id', label: 'Broker' },
                        { key: 'authority_server_id', label: 'Authority' },
                    ]"
                    :rows="dns?.authorizations ?? []"
                    row-key="domain"
                >
                    <template #cell-broker_server_id="{ row }"><span data-no-i18n>{{ row.broker_server_id.slice(0, 8) }}</span></template>
                    <template #cell-authority_server_id="{ row }"><span data-no-i18n>{{ row.authority_server_id.slice(0, 8) }}</span></template>
                </DataTable>
            </Card>

            <Card as="section" title="Certificates on this box">
                <div class="cluster">
                    <Stat label="Received grants" :value="(dns?.received_grants ?? []).length" />
                    <Stat label="Installed certs" :value="(dns?.installed_certs ?? []).length" />
                </div>
                <DataTable
                    v-if="(dns?.installed_certs ?? []).length"
                    :columns="[
                        { key: 'fqdn', label: 'Name' },
                        { key: 'not_after', label: 'Expires' },
                        { key: 'state', label: 'State' },
                    ]"
                    :rows="dns?.installed_certs ?? []"
                    row-key="fqdn"
                >
                    <template #cell-fqdn="{ row }"><span data-no-i18n>{{ row.fqdn }}</span></template>
                    <template #cell-not_after="{ row }"><span data-no-i18n>{{ new Date(row.not_after).toLocaleDateString() }} ({{ row.days_left }}d)</span></template>
                    <template #cell-state="{ row }">
                        <StatusBadge v-if="row.expired" tone="danger">Expired</StatusBadge>
                        <StatusBadge v-else-if="row.expiring" tone="warning">Expiring soon</StatusBadge>
                        <StatusBadge v-else tone="success" icon="check">Valid</StatusBadge>
                    </template>
                </DataTable>
            </Card>

            <Card v-if="(dns?.issuances ?? []).length" as="section" title="The issuance ledger (this box brokers)">
                <DataTable
                    :columns="[
                        { key: 'fqdn', label: 'Name issued' },
                        { key: 'peer_server_id', label: 'For peer' },
                        { key: 'issued_at', label: 'Issued' },
                        { key: 'not_after', label: 'Expires' },
                    ]"
                    :rows="dns?.issuances ?? []"
                    row-key="fqdn"
                >
                    <template #cell-fqdn="{ row }"><span data-no-i18n>{{ row.fqdn }}</span></template>
                    <template #cell-peer_server_id="{ row }"><span data-no-i18n>{{ row.peer_server_id.slice(0, 8) }}</span></template>
                    <template #cell-issued_at="{ row }"><span data-no-i18n>{{ fmtEpoch(row.issued_at) }}</span></template>
                    <template #cell-not_after="{ row }"><span data-no-i18n>{{ fmtEpoch(row.not_after) }}</span></template>
                </DataTable>
                <p class="citation">Append-only — every certificate this broker ever issued.</p>
            </Card>

            <div class="grid-2">
                <Card as="section" title="Per-name certificates">
                    <p>
                        <StatusBadge tone="info">Default · ungated</StatusBadge>
                        One grant, one host name — the only ungated path, tried first, budget-cheap.
                    </p>
                </Card>
                <Card as="section" title="Wildcard backup">
                    <p>
                        <StatusBadge tone="neutral">Future — not yet implemented</StatusBadge>
                        A distinct grant kind touching the protected trust core: authority-minted,
                        per-domain approval, optionally a higher consent bar. Designed, not built.
                    </p>
                </Card>
            </div>

            <div class="grid-2">
                <Card as="section" title="Dynamic DNS — moving nodes">
                    <p>
                        <StatusBadge tone="neutral">Future — not yet implemented</StatusBadge>
                        A moving node re-points its own A-record by signed update — same cert, no
                        new issuance, no budget spent. Designed, not built.
                    </p>
                </Card>
                <Card as="section" title="The budget rails">
                    <p>
                        <StatusBadge tone="neutral">Future — not yet enforced</StatusBadge>
                        Per-domain and per-name weekly issuance ceilings with pre-flight refusal,
                        so one flapping host can never exhaust a domain's Let's Encrypt budget.
                        The ledger above already records what a rail would count.
                    </p>
                </Card>
            </div>

            <Card as="section" title="DNS providers">
                <DataTable
                    :columns="[{ key: 'provider', label: 'Provider' }, { key: 'status', label: 'Status' }]"
                    :rows="[
                        { provider: 'Cloudflare', status: 'live' },
                        { provider: 'Route53', status: 'stub' },
                        { provider: 'DigitalOcean', status: 'stub' },
                        { provider: 'Manual', status: 'stub' },
                    ]"
                    row-key="provider"
                >
                    <template #cell-status="{ row }">
                        <StatusBadge :tone="row.status === 'live' ? 'success' : 'neutral'">
                            {{ row.status === 'live' ? 'Live' : 'Stub — not yet implemented' }}
                        </StatusBadge>
                    </template>
                </DataTable>
            </Card>
        </template>

        <template #about>
            <p>
                A separate box, a sealed token: the broker is its own sealed service, the
                write token never leaves it, and minting grants runs through the domain's
                authority. Granting and requesting certificates run over the mesh —
                <span data-no-i18n>mesh:cert-grant</span> /
                <span data-no-i18n>mesh:request-cert</span> — and the peer plumbing lives on
                <a href="/operator/mesh">the server mesh</a>.
            </p>
        </template>
    </PageScaffold>
</template>
