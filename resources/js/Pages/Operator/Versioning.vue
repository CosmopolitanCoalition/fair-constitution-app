<script setup>import { useLocaleFormat } from '@/composables/useLocaleFormat';
const localeFmt = useLocaleFormat();
/**
 * Operator/Versioning — versions & upgrades on the operator plane
 * (mockups-v3-wiring Phase 4, PHASE_4_DESIGN_peerage.md §3.1; design contract
 * mockups/v3/operator/versioning.html).
 *
 * A READ-ONLY view over MeshConsoleController::versioning(), which itself only
 * renders PeerUpgradeAgreementService truth — nothing here re-implements a
 * meter, moves a version, or edits a rule. Gating mirrors /operator/operations
 * exactly: anyone reaches the shell; only a signed-in operator gets the data
 * block (`authed:false` → the sign-in prompt, `versioning:null`).
 *
 * Console language (settled slate): "authority" attaches to a JURISDICTION —
 * a place's home copy — never to a node as a rank. The G3c read-write petition
 * ladder is not presented here (design flag 1; the legacy page keeps it).
 */
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
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
    versioning: { type: Object, default: null },
});

const { t } = useI18n();

const page = usePage();
const flash = computed(() => page.props.flash?.status ?? null);

/* ------------------------------------------------ defensive prop reads */
const ours = computed(() => props.versioning?.ours ?? null);
const peers = computed(() => props.versioning?.peers ?? []);
const proposals = computed(() => props.versioning?.proposals ?? []);

/* --------------------------------------------------------- peer table */
const peerColumns = [
    { key: 'name', label: t('c_operator_pages.versioning.col_peer', 'Peer') },
    { key: 'status', label: t('c_operator_pages.versioning.col_status', 'Status') },
    { key: 'constitutional_version', label: t('c_operator_pages.versioning.col_const_version', 'Constitutional version'), mono: true },
    { key: 'app_release', label: t('c_operator_pages.versioning.col_app_release', 'App release'), mono: true },
    { key: 'version_match', label: t('c_operator_pages.versioning.col_match', 'Match') },
];

const statusTone = (s) =>
    ['trust_established', 'syncing', 'border_settled'].includes(s)
        ? 'success'
        : s === 'conflict_resolution'
          ? 'warning'
          : 'neutral';

/* ---------------------------------------------------------- proposals */
const KIND_LABELS = {
    constitutional_bump: t('c_operator_pages.versioning.kind_constitutional', 'Constitutional version'),
    schema_bump: t('c_operator_pages.versioning.kind_schema', 'Schema'),
    app_release: t('c_operator_pages.versioning.kind_app_release', 'App release'),
    role_grant: t('c_operator_pages.versioning.kind_role_grant', 'Role grant'),
};
const kindLabel = (kind) => KIND_LABELS[kind] ?? kind;

/* Only the constitutional bump and the role grant take consent meters —
   schema/app-release bumps move mechanically (the backend contract). */
const takesMeters = (p) => ['constitutional_bump', 'role_grant'].includes(p.kind);

const fromTo = (p) => {
    if (p.kind === 'constitutional_bump') {
        return { from: p.from_constitutional_version, to: p.to_constitutional_version };
    }
    if (p.kind === 'schema_bump') {
        return { from: p.from_schema_version, to: p.to_schema_version };
    }
    if (p.kind === 'app_release') {
        return { from: p.from_app_release, to: p.to_app_release };
    }
    return null;
};

const METERS = [
    { key: 'a', label: t('c_operator_pages.versioning.meter_a', 'A · operator board') },
    { key: 'b', label: t('c_operator_pages.versioning.meter_b', 'B · seated government') },
    { key: 'c', label: t('c_operator_pages.versioning.meter_c', 'C · co-affected peers') },
];

/* One pill per meter: not needed (doesn't apply) / passed / waiting. */
const meterPills = (p) =>
    METERS.map((m) => {
        const meter = p.meters?.[m.key];
        if (!meter?.applies) {
            return { ...m, tone: 'neutral', icon: null, text: t('c_operator_pages.versioning.meter_not_needed', 'not needed') };
        }
        return meter.passed === true
            ? { ...m, tone: 'success', icon: 'check', text: t('c_operator_pages.versioning.meter_passed', 'passed') }
            : { ...m, tone: 'warning', icon: 'clock', text: t('c_operator_pages.versioning.meter_waiting', 'waiting') };
    });

const legLabel = (leg) =>
    leg === 'seated' ? t('c_operator_pages.versioning.leg_seated', 'the seated government') : t('c_operator_pages.versioning.leg_board', 'the operator board (no government seated yet)');

/* ------------------------------------------------------------ helpers */
const shortId = (id) => (id ? String(id).slice(0, 8) : '—');
const fmtDate = (iso) => (iso ? localeFmt.date(new Date(iso)) : '—');
</script>

<template>
    <PageScaffold :surface="surface">
        <HostNav current="versioning" />
        <template #intro>
            {{ t('c_operator_pages.versioning.intro', 'How a version changes hands across the mesh — what this node runs, what every peer advertises, and the open proposals waiting on consent. Every constitutional change is screened by the hardened filter first, then agreed by the people who actually hold the power.') }}
        </template>

        <Banner v-if="flash" tone="info" role="status">{{ flash }}</Banner>

        <!-- Not an operator → the sign-in gate (the exact /operator/operations mechanism). -->
        <Card v-if="!authed" as="section" :title="t('c_operator_pages.versioning.signin_title', 'Operator sign-in required')">
            <p class="gloss">
                {{ t('c_operator_pages.versioning.signin_body', 'Versions and upgrade proposals are shown only to a signed-in operator. Nothing is decided here without consent — a version change clears the meters, never a console.') }}
            </p>
            <Btn as="a" href="/operator/login" variant="primary" size="sm">
                {{ t('c_operator_pages.versioning.signin_btn', 'Sign in as an operator →') }}
            </Btn>
        </Card>

        <template v-else-if="versioning">
            <!-- Plane wall -->
            <div class="plane-wall">
                <Icon name="shield" size="sm" />
                <div>
                    <strong>{{ t('c_operator_pages.versioning.plane_off_strong', 'Off the constitutional plane.') }}</strong>
                    {{ t('c_operator_pages.versioning.plane_off_body', 'Upgrading the software is an operator-plane capability, not a citizen privilege. The constitutional rules themselves are never edited from here — a version change is proposed, screened, and only then agreed by the people who actually hold the power.') }}
                </div>
            </div>

            <!-- Our three versions -->
            <Card as="section" :title="t('c_operator_pages.versioning.node_runs_title', 'This node runs')">
                <div class="cluster" style="gap: var(--space-6)">
                    <Stat :value="ours?.constitutional_version ?? '—'" :label="t('c_operator_pages.versioning.stat_const_version', 'constitutional version')" accent />
                    <Stat :value="ours?.app_release ?? '—'" :label="t('c_operator_pages.versioning.stat_app_release', 'app release')" />
                    <Stat :value="ours?.schema_version ?? '—'" :label="t('c_operator_pages.versioning.stat_schema_version', 'schema version')" />
                </div>
                <div class="cluster" style="gap: var(--space-2); margin-block-start: var(--space-3)">
                    <StatusBadge v-if="ours?.pinned" tone="warning" icon="lock">
                        {{ t('c_operator_pages.versioning.pinned', 'pinned') }}{{ ours?.version_pinned_at ? ` ${fmtDate(ours.version_pinned_at)}` : '' }}
                    </StatusBadge>
                    <StatusBadge v-else tone="neutral">{{ t('c_operator_pages.versioning.derived', 'derived from the rules in force') }}</StatusBadge>
                    <span class="citation">
                        {{ t('c_operator_pages.versioning.const_version_cite', 'the constitutional version — a fingerprint of the hardened rules in force') }}
                    </span>
                </div>
                <p class="cc-small gloss" style="margin-block-start: var(--space-3)">
                    {{ t('c_operator_pages.versioning.three_kinds', 'Three kinds of version move independently. Only the constitutional one touches the hardened surface — it alone passes the filter and the meters below; app releases and schema bumps move mechanically.') }}
                </p>
            </Card>

            <!-- The game-in-progress freeze -->
            <Card as="section" :title="t('c_operator_pages.versioning.freeze_title', 'The game-in-progress freeze')">
                <div class="cluster" style="gap: var(--space-2)">
                    <Icon name="lock" size="sm" />
                    <strong>{{ t('c_operator_pages.versioning.freeze_strong', 'A running election or session pins its version.') }}</strong>
                </div>
                <p class="cc-small" style="margin-block-start: var(--space-2)">
                    {{ t('c_operator_pages.versioning.freeze_body', 'When play begins, the contest captures the constitutional version in force at that moment. Any agreed upgrade lands for the next contest — never the one mid-play — so no one can change the rules under the ballots already cast.') }}
                </p>
                <p class="advanced-note" style="margin-block-start: var(--space-2)">
                    {{ t('c_operator_pages.versioning.freeze_note', 'Pinning is a pure function of the contest record; there is no manual override to thaw a running game.') }}
                </p>
            </Card>

            <!-- Peer versions -->
            <Card as="section" :title="t('c_operator_pages.versioning.peer_versions_title', 'Peer versions')">
                <p class="cc-small gloss" style="margin-block-end: var(--space-3)">
                    {{ t('c_operator_pages.versioning.peer_versions_body', 'Every peer advertises its constitutional version. A peer on a different version cannot be synced into — the mesh refuses fail-closed until both sides have agreed an upgrade.') }}
                </p>
                <DataTable
                    v-if="peers.length"
                    :columns="peerColumns"
                    :rows="peers"
                    row-key="server_id"
                    :caption="t('c_operator_pages.versioning.peer_caption', 'Peer constitutional versions and app releases')"
                >
                    <template #cell-name="{ row }">
                        {{ row.name || shortId(row.server_id) }}
                    </template>
                    <template #cell-status="{ row }">
                        <StatusBadge :tone="statusTone(row.status)">
                            {{ (row.status ?? t('c_operator_pages.versioning.status_unknown', 'unknown')).replaceAll('_', ' ') }}
                        </StatusBadge>
                    </template>
                    <template #cell-constitutional_version="{ value }">
                        <span v-if="value">{{ value }}</span>
                        <span v-else class="gloss">{{ t('c_operator_pages.versioning.not_advertised', 'not yet advertised') }}</span>
                    </template>
                    <template #cell-app_release="{ value }">{{ value ?? '—' }}</template>
                    <template #cell-version_match="{ row }">
                        <StatusBadge v-if="row.version_match === true" tone="success" icon="check">
                            {{ t('c_operator_pages.versioning.match', 'match') }}
                        </StatusBadge>
                        <StatusBadge v-else-if="row.version_match === false" tone="danger" icon="alert-triangle">
                            {{ t('c_operator_pages.versioning.differs', 'differs — sync holds') }}
                        </StatusBadge>
                        <StatusBadge v-else tone="neutral">{{ t('c_operator_pages.versioning.match_unknown', 'unknown') }}</StatusBadge>
                    </template>
                </DataTable>
                <p v-else class="cc-small gloss">
                    {{ t('c_operator_pages.versioning.no_peers', 'No peers yet — this box stands alone. Versions start to matter the moment a second box joins the mesh.') }}
                </p>
                <Banner
                    v-if="peers.length"
                    tone="info"
                    :title="t('c_operator_pages.versioning.failclosed_title', 'Fail-closed, not best-effort.')"
                    style="margin-block-start: var(--space-3)"
                >
                    {{ t('c_operator_pages.versioning.failclosed_body', 'A divergent version stops the sync rather than silently merging mismatched rules. The other side agrees the upgrade — through the same meters below — before its tail flows again.') }}
                </Banner>
            </Card>

            <!-- Open proposals -->
            <Card as="section" :title="t('c_operator_pages.versioning.proposals_title', 'Open proposals')">
                <p class="cc-small gloss" style="margin-block-end: var(--space-3)">
                    {{ t('c_operator_pages.versioning.proposals_body', "A proposal is screened first, agreed second. Each meter that applies must pass; a meter that doesn't apply to the proposal's kind or scope simply isn't needed.") }}
                </p>
                <Banner tone="warning" :title="t('c_operator_pages.versioning.screened_title', 'Screened first, and ungateable.')" role="status">
                    {{ t('c_operator_pages.versioning.screened_body', 'Every proposal passes the hardened admissibility filter before any consent is counted. One that would lower proportionality, or drop the supermajority floor below majority-plus-one, is refused outright — no meter, no operator, and no amount of reach can wave it through.') }}
                </Banner>

                <div v-if="proposals.length" class="stack" style="gap: var(--space-3); margin-block-start: var(--space-3)">
                    <Card v-for="p in proposals" :key="p.id" inset>
                        <div class="cluster" style="justify-content: space-between; gap: var(--space-2)">
                            <strong>
                                {{ kindLabel(p.kind) }}<template v-if="p.capability">
                                    · <span class="form-id">{{ p.capability }}</span></template>
                            </strong>
                            <span class="citation">{{ t('c_operator_pages.versioning.opened', { date: fmtDate(p.created_at) }) }}</span>
                        </div>

                        <p v-if="fromTo(p)" class="cluster" style="gap: var(--space-2); margin-block-start: var(--space-2)">
                            <code class="form-id">{{ fromTo(p).from ?? '—' }}</code>
                            <Icon name="arrow-right" size="sm" />
                            <code class="form-id">{{ fromTo(p).to ?? '—' }}</code>
                        </p>

                        <p class="cc-small gloss" style="margin-block-start: var(--space-2)">
                            {{ t('c_operator_pages.versioning.proposed_by', 'proposed by node') }} <span class="form-id">{{ shortId(p.proposed_by_server_id) }}</span>
                            {{ t('c_operator_pages.versioning.for_place', '· for the place') }} <span class="form-id">{{ shortId(p.affected_root_jurisdiction_id) }}</span>
                            {{ t('c_operator_pages.versioning.consent_falls', '· consent falls to') }} {{ legLabel(p.consent_leg) }}
                        </p>

                        <div v-if="takesMeters(p)" class="cluster" style="gap: var(--space-2); margin-block-start: var(--space-2)">
                            <StatusBadge
                                v-for="m in meterPills(p)"
                                :key="m.key"
                                :tone="m.tone"
                                :icon="m.icon"
                            >
                                {{ m.label }} — {{ m.text }}
                            </StatusBadge>
                        </div>
                        <div v-else class="cluster" style="margin-block-start: var(--space-2)">
                            <StatusBadge tone="neutral">{{ t('c_operator_pages.versioning.no_meters', 'no meters — this kind moves mechanically') }}</StatusBadge>
                        </div>
                    </Card>
                </div>
                <p v-else class="cc-small gloss" style="margin-block-start: var(--space-3)">
                    {{ t('c_operator_pages.versioning.no_proposals', 'No open proposals — the mesh agrees on the version in force.') }}
                </p>

                <p class="citation" style="margin-block-start: var(--space-3)">
                    {{ t('c_operator_pages.versioning.meters_cite', 'A and B are two legs of one seat — the operator board attests only while no government is seated; the moment one seats, its supermajority supersedes the board. C is every trust-established peer holding the home copy of a co-affected place (unanimous; it passes on its own when no such peer exists).') }}
                </p>
            </Card>

            <!-- Closing plane note -->
            <div class="plane-wall">
                <Icon name="shield" size="sm" />
                <div>
                    <strong>{{ t('c_operator_pages.versioning.answerable_strong', 'Answerable to the government.') }}</strong>
                    {{ t('c_operator_pages.versioning.answerable_body', 'Operators run the upgrade; they never author the rule. The filter is hardened, the freeze is mechanical, and the seated government has the final say.') }}
                </div>
            </div>
        </template>

        <template #about>
            <p>
                {{ t('c_operator_pages.versioning.about', 'This console renders the versioning truth held by the upgrade-agreement service — it never re-implements a meter or moves a version itself. Proposals are driven through the mesh services; a regressive constitutional bump can never pass the hardened filter, no matter who consents.') }}
            </p>
        </template>
    </PageScaffold>
</template>
