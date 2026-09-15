<script setup>
/**
 * Operator/Identity — the identity page of the Phase 4 operator/* console suite
 * (PHASE_4_DESIGN_peerage.md §3.1; design contract mockups/v3/operator/identity.html).
 *
 * A pure READ surface over MeshConsoleController::identity(): the node's mesh
 * identity (server ID + Ed25519 PUBLIC key — the secret half never rides a prop),
 * the signed-in operator's account line, and the operator-plane device-key
 * registry (OperatorDevice, G-OP). Nulls on the node block mean the identity has
 * not been minted yet — this GET never mints, and the page says so honestly.
 *
 * Gating mirrors /operator/operations exactly: any authenticated user reaches the
 * shell; `authed: false` (no auth:operator session) renders the sign-in prompt and
 * no data. Settled console language: identity here is infrastructure — it grants
 * no vote, no seat, no constitutional say; "authority" attaches to places, never
 * to this box as a rank.
 */
import { computed, ref } from 'vue';
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
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import Stepper from '@/Components/Ui/Stepper.vue';
import { useAnnounce } from '@/composables/useAnnounce';

defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    authed: { type: Boolean, default: false },
    operator: { type: String, default: null },
    /** null until an operator is signed in; see the controller contract. */
    identity: { type: Object, default: null },
});

const { t } = useI18n();

const page = usePage();
const flash = computed(() => page.props.flash?.status ?? null);

/* ── defensive reads over the identity prop ─────────────────────────── */

const node = computed(() => props.identity?.node ?? null);
const account = computed(() => props.identity?.account ?? null);
const devices = computed(() => props.identity?.devices ?? []);

/** Unminted box: the node block exists but no keypair has ever been cut. */
const minted = computed(() => Boolean(node.value?.server_id));

const accountTone = computed(
    () =>
        ({ active: 'success', suspended: 'warning', closed: 'danger' })[account.value?.status] ??
        'neutral',
);
const accountStatusLabel = computed(() => {
    const s = account.value?.status;
    return s ? s.charAt(0).toUpperCase() + s.slice(1) : t('c_operator_pages.identity.status_unknown', 'Unknown');
});

const fmtDate = (iso) => (iso ? new Date(iso).toLocaleDateString() : '—');
const fmtDateTime = (iso) => (iso ? new Date(iso).toLocaleString() : '—');

/* ── the copy affordance (BallotReceipt pattern: secure-context + fallback) ── */

const { announce } = useAnnounce();
const copied = ref(null); // 'server_id' | 'public_key' | null
let copiedTimer = null;

function fallbackCopy(text) {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.insetBlockStart = '-100vh';
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    ta.remove();
}

async function copy(kind, text, label) {
    if (!text) return;
    try {
        if (navigator.clipboard?.writeText && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
        } else {
            fallbackCopy(text);
        }
    } catch {
        fallbackCopy(text);
    }
    announce(t('c_operator_pages.identity.copied_announce', { label }));
    copied.value = kind;
    if (copiedTimer) clearTimeout(copiedTimer);
    copiedTimer = setTimeout(() => (copied.value = null), 2000);
}

/* ── the device registry table ──────────────────────────────────────── */

const deviceColumns = [
    { key: 'label', label: t('c_operator_pages.identity.col_device', 'Device') },
    { key: 'fingerprint', label: t('c_operator_pages.identity.col_fingerprint', 'Key fingerprint'), mono: true },
    { key: 'state', label: t('c_operator_pages.identity.col_state', 'State') },
    { key: 'enrolled_at', label: t('c_operator_pages.identity.col_enrolled', 'Enrolled') },
];

/* ── the G-ID explainer (design-contract copy; static, no props) ────── */

const forwardedChecks = [
    {
        check: t('c_operator_pages.identity.check_attestation', 'Attestation'),
        detail: t('c_operator_pages.identity.check_attestation_detail', 'The issuer’s pinned key verifies the snapshot — fails closed on expiry, revocation, or mutation.'),
    },
    {
        check: t('c_operator_pages.identity.check_action', 'Action signature'),
        detail: t('c_operator_pages.identity.check_action_detail', 'The device signed THIS exact write (form + payload + subject) — non-repudiation.'),
    },
    {
        check: t('c_operator_pages.identity.check_subject', 'Subject'),
        detail: t('c_operator_pages.identity.check_subject_detail', 'The attested user resolves locally before the engine authorizes.'),
    },
];
const checkSteps = forwardedChecks.map((c, i) => ({
    label: c.check,
    state: i === 0 ? 'active' : 'pending',
}));
</script>

<template>
    <PageScaffold :surface="surface">
        <HostNav current="identity" />
        <template #intro>
            {{ t('c_operator_pages.identity.intro', "The identity layer that lets a server know who you are across the mesh, and lets a citizen’s public standing travel with them onto a server that isn’t home. Two separate identities live here — yours as an operator, and the attested standing of a citizen passing through. They never touch.") }}
        </template>

        <Banner v-if="flash" tone="info">{{ flash }}</Banner>

        <!-- Not an operator → the sign-in gate (the /operator/operations mechanism), no data. -->
        <Card v-if="!authed" :title="t('c_operator_pages.identity.signin_title', 'Operator sign-in required')">
            <p>
                {{ t('c_operator_pages.identity.signin_body', 'The identity plane is shown only to a signed-in operator. Nothing here carries constitutional power — it is how this box proves which box is talking.') }}
            </p>
            <div class="cluster">
                <Btn as="a" href="/operator/login" variant="primary" icon="lock">
                    {{ t('c_operator_pages.identity.signin_btn', 'Sign in as an operator') }}
                </Btn>
            </div>
        </Card>

        <template v-else>
            <div class="plane-wall">
                <Icon name="shield" size="sm" />
                <div>
                    <strong>{{ t('c_operator_pages.identity.wall_strong', 'The plane wall holds here too.') }}</strong> {{ t('c_operator_pages.identity.wall_body', 'Operator identity is wholly separate from citizen identity — an operator account has no link to any citizen user.') }}<br />
                    <span class="citation">{{ t('c_operator_pages.identity.wall_cite', 'capability, not role') }}</span>
                </div>
            </div>

            <!-- ═══════════ Section 1 — the node + your operator identity ═══════════ -->
            <section aria-labelledby="me-h" class="stack">
                <h2 id="me-h">{{ t('c_operator_pages.identity.your_identity', 'Your operator identity') }}</h2>
                <p class="page-intro">
                    {{ t('c_operator_pages.identity.your_identity_intro', 'Who this box is on the mesh, and who you are on this box. This is infrastructure identity — it grants no vote, no seat, no constitutional say.') }}
                </p>

                <Banner v-if="!minted" tone="info" :title="t('c_operator_pages.identity.no_mesh_id_title', 'No mesh identity minted yet')">
                    {{ t('c_operator_pages.identity.no_mesh_id_body', 'This box has not cut its Ed25519 signing keypair. It mints the first time federation needs it — reading this page never mints one.') }}
                </Banner>

                <Card :title="node?.instance_name || t('c_operator_pages.identity.this_node', 'This node')" :eyebrow="t('c_operator_pages.identity.this_node', 'This node')">
                    <div class="grid-2">
                        <div class="stack" style="gap: var(--space-2)">
                            <div>
                                <span class="citation">{{ t('c_operator_pages.identity.server_id', 'Server ID') }}</span><br />
                                <code v-if="node?.server_id" data-no-i18n>{{ node.server_id }}</code>
                                <span v-else class="gloss">{{ t('c_operator_pages.identity.not_minted', 'not minted yet') }}</span>
                            </div>
                            <div class="cluster" style="gap: var(--space-2)">
                                <Btn
                                    v-if="node?.server_id"
                                    size="sm"
                                    variant="ghost"
                                    icon="copy"
                                    @click="copy('server_id', node.server_id, t('c_operator_pages.identity.server_id', 'Server ID'))"
                                >
                                    {{ copied === 'server_id' ? t('c_operator_pages.identity.copied', 'Copied ✓') : t('c_operator_pages.identity.copy_server_id', 'Copy server ID') }}
                                </Btn>
                            </div>
                            <div>
                                <span class="citation">{{ t('c_operator_pages.identity.federation', 'Federation') }}</span><br />
                                <StatusBadge
                                    :tone="node?.federation_enabled ? 'success' : 'neutral'"
                                    :icon="node?.federation_enabled ? 'check' : null"
                                >
                                    {{ node?.federation_enabled ? t('c_operator_pages.identity.on', 'On') : t('c_operator_pages.identity.off', 'Off') }}
                                </StatusBadge>
                            </div>
                        </div>
                        <div class="stack" style="gap: var(--space-2)">
                            <div>
                                <span class="citation">{{ t('c_operator_pages.identity.public_key_label', 'Public key (Ed25519 — the public half only)') }}</span><br />
                                <code
                                    v-if="node?.public_key"
                                    data-no-i18n
                                    style="overflow-wrap: anywhere"
                                    >{{ node.public_key }}</code
                                >
                                <span v-else class="gloss">{{ t('c_operator_pages.identity.not_minted', 'not minted yet') }}</span>
                            </div>
                            <div class="cluster" style="gap: var(--space-2)">
                                <Btn
                                    v-if="node?.public_key"
                                    size="sm"
                                    variant="ghost"
                                    icon="copy"
                                    @click="copy('public_key', node.public_key, t('c_operator_pages.identity.public_key', 'Public key'))"
                                >
                                    {{ copied === 'public_key' ? t('c_operator_pages.identity.copied', 'Copied ✓') : t('c_operator_pages.identity.copy_public_key', 'Copy public key') }}
                                </Btn>
                            </div>
                            <div v-if="node?.public_key_fp">
                                <span class="citation">{{ t('c_operator_pages.identity.fingerprint', 'Fingerprint') }}</span><br />
                                <code data-no-i18n>{{ node.public_key_fp }}</code>
                            </div>
                            <p v-if="node?.key_generated_at" class="citation">
                                {{ t('c_operator_pages.identity.key_generated', { date: fmtDate(node.key_generated_at) }) }}
                            </p>
                        </div>
                    </div>
                    <p class="advanced-note">
                        {{ t('c_operator_pages.identity.secret_note', 'The secret half of the signing key never leaves this box and never rides this page. Peers pin the public key; the node signs as itself.') }}
                    </p>
                </Card>

                <Card :eyebrow="t('c_operator_pages.identity.your_account', 'Your account')" :title="account?.username || operator || t('c_operator_pages.identity.operator', 'Operator')">
                    <div class="grid-2">
                        <div class="stack" style="gap: var(--space-2)">
                            <div>
                                <span class="citation">{{ t('c_operator_pages.identity.username', 'Username') }}</span><br />
                                <strong>{{ account?.username ?? operator ?? '—' }}</strong>
                            </div>
                            <div>
                                <span class="citation">{{ t('c_operator_pages.identity.mesh_operator_id', 'Mesh operator ID') }}</span><br />
                                <code v-if="account?.mesh_operator_id" data-no-i18n>{{
                                    account.mesh_operator_id
                                }}</code>
                                <span v-else class="gloss"
                                    >{{ t('c_operator_pages.identity.not_linked', 'not linked to a mesh identity yet') }}</span
                                >
                            </div>
                        </div>
                        <div class="stack" style="gap: var(--space-2)">
                            <div>
                                <span class="citation">{{ t('c_operator_pages.identity.status', 'Status') }}</span><br />
                                <StatusBadge
                                    :tone="accountTone"
                                    :icon="accountTone === 'success' ? 'check' : null"
                                >
                                    {{ accountStatusLabel }}
                                </StatusBadge>
                                <span class="citation">
                                    {{ t('c_operator_pages.identity.last_login', { when: fmtDateTime(account?.last_login_at) }) }}</span
                                >
                            </div>
                            <div>
                                <span class="citation">{{ t('c_operator_pages.identity.account_created', 'Account created') }}</span><br />
                                {{ fmtDate(account?.created_at) }}
                            </div>
                        </div>
                    </div>
                </Card>

                <h3>{{ t('c_operator_pages.identity.devices', 'Devices') }}</h3>
                <p class="page-intro">
                    {{ t('c_operator_pages.identity.devices_intro', 'Each device enrols its own key. Possession of a device key — never a password — is what other servers recognise.') }}
                </p>

                <DataTable
                    v-if="devices.length > 0"
                    :columns="deviceColumns"
                    :rows="devices"
                    row-key="id"
                    :caption="t('c_operator_pages.identity.devices_caption', 'The enrolled operator device keys on this box')"
                >
                    <template #cell-label="{ row }">
                        {{ row.label || t('c_operator_pages.identity.unnamed_device', 'Unnamed device') }}
                    </template>
                    <template #cell-state="{ row }">
                        <StatusBadge v-if="row.active" tone="success" icon="check">{{ t('c_operator_pages.identity.device_active', 'Active') }}</StatusBadge>
                        <StatusBadge v-else tone="neutral">
                            {{ t('c_operator_pages.identity.device_revoked', { date: fmtDate(row.revoked_at) }) }}
                        </StatusBadge>
                    </template>
                    <template #cell-enrolled_at="{ value }">
                        {{ fmtDate(value) }}
                    </template>
                </DataTable>

                <Card v-else inset>
                    <p>
                        <strong>{{ t('c_operator_pages.identity.no_devices_strong', 'No devices enrolled yet.') }}</strong> {{ t('c_operator_pages.identity.no_devices_body', 'This operator account signs in with its local password only. Enrolling a device publishes its Ed25519 public key, so other boxes can recognise you by key possession.') }}
                    </p>
                </Card>

                <div class="lr-note">
                    <Icon name="lock" size="sm" />
                    <div>
                        {{ t('c_operator_pages.identity.lr_password', 'Your local password signs you in on this box only — other boxes recognise you by device-key possession, never by a password.') }}
                    </div>
                </div>
                <p class="advanced-note">
                    {{ t('c_operator_pages.identity.enrol_note', 'Enrolment publishes a device’s public key; linking proves possession of an already-enrolled key on a new box. No secret ever leaves the device.') }}
                </p>
            </section>

            <!-- ═══════════ Section 2 — citizen standing on the move ═══════════ -->
            <section aria-labelledby="travel-h" class="stack">
                <h2 id="travel-h">{{ t('c_operator_pages.identity.travel_title', 'Citizen standing on the move') }}</h2>
                <p class="page-intro">
                    {{ t('c_operator_pages.identity.travel_intro_before', "A person on a journey can act through a server that isn’t their home, because their public standing travels as a signed, short-lived") }}
                    <strong>{{ t('c_operator_pages.identity.attestation', 'attestation') }}</strong>{{ t('c_operator_pages.identity.travel_intro_after', '. This attestation is what lets a visiting player act here.') }}
                </p>

                <div class="plane-wall">
                    <Icon name="info" size="sm" />
                    <div>
                        <strong>{{ t('c_operator_pages.identity.att_strong', 'An attestation carries only public standing.') }}</strong> {{ t('c_operator_pages.identity.att_before', 'Role codes, the device key, the issuer, a TTL, and a signature — and') }} <em>{{ t('c_operator_pages.identity.nothing_else', 'nothing else') }}</em>{{ t('c_operator_pages.identity.att_after', '. Never credentials, never locations, never ballots. Roles are still derived live, never stored.') }}<br />
                        <span class="citation"
                            >{{ t('c_operator_pages.identity.att_cite', 'voting and candidacy are absolute rights, derived from residency, never persisted') }}</span
                        >
                    </div>
                </div>

                <div class="grid-2">
                    <Card>
                        <h3 style="margin: 0">{{ t('c_operator_pages.identity.what_att_title', 'What an attestation is') }}</h3>
                        <p style="font-size: var(--text-sm)">
                            {{ t('c_operator_pages.identity.what_att_body', 'A short-lived (≤ 24h), revocable, instance-signed snapshot of a person’s derived standing, bound to a device key. Only the HOME authority attests. It carries only public standing — never credentials, locations, or ballots.') }}
                        </p>
                        <div class="fsm">
                            <span class="fsm-node">{{ t('c_operator_pages.identity.fsm_home_attests', 'home authority attests') }}</span>
                            <span class="fsm-arrow" aria-hidden="true"
                                ><Icon name="arrow-right" size="sm"
                            /></span>
                            <span class="fsm-node">{{ t('c_operator_pages.identity.fsm_device_signs', 'device signs the write') }}</span>
                            <span class="fsm-arrow" aria-hidden="true"
                                ><Icon name="arrow-right" size="sm"
                            /></span>
                            <span class="fsm-node">{{ t('c_operator_pages.identity.fsm_nonhome_verifies', 'non-home engine verifies') }}</span>
                            <span class="fsm-arrow" aria-hidden="true"
                                ><Icon name="arrow-right" size="sm"
                            /></span>
                            <span class="fsm-node">{{ t('c_operator_pages.identity.fsm_ttl', '≤ 24h TTL · expiry sweep') }}</span>
                        </div>
                        <span class="citation"
                            >{{ t('c_operator_pages.identity.what_att_cite', 'Only the home authority attests · ≤ 24h · revocable · instance-signed') }}</span
                        >
                    </Card>
                    <Card>
                        <h3 style="margin: 0">{{ t('c_operator_pages.identity.enrol_title', 'Device enrolment — no escrow') }}</h3>
                        <p style="font-size: var(--text-sm)">
                            {{ t('c_operator_pages.identity.enrol_body', 'A device enrols its Ed25519 PUBLIC key — the secret never leaves the device (no escrow).') }}
                        </p>
                        <div class="lr-note">
                            <Icon name="lock" size="sm" />
                            <div>
                                {{ t('c_operator_pages.identity.enrol_lr_before', 'The Ed25519') }} <strong>{{ t('c_operator_pages.identity.public', 'public') }}</strong> {{ t('c_operator_pages.identity.enrol_lr_after', 'key is published; the secret half never leaves the device. There is nowhere for a private key to be escrowed.') }}
                            </div>
                        </div>
                    </Card>
                </div>

                <h3>{{ t('c_operator_pages.identity.forwarded_title', 'The forwarded write — three checks') }}</h3>
                <p class="page-intro">
                    {{ t('c_operator_pages.identity.forwarded_intro', 'When a forwarded write arrives, it must clear all three checks before the engine will authorize it. Any failure is fail-closed.') }}
                </p>
                <Stepper :steps="checkSteps" :aria-label="t('c_operator_pages.identity.forwarded_aria', 'The three forwarded-write checks')" />
                <ul class="stack" style="font-size: var(--text-sm); padding-inline-start: var(--space-4); gap: var(--space-2)">
                    <li v-for="c in forwardedChecks" :key="c.check">
                        <strong>{{ c.check }}.</strong> {{ c.detail }}
                    </li>
                </ul>
                <p class="advanced-note">
                    {{ t('c_operator_pages.identity.subject_note', 'Subject: the attested user resolves locally before authorization — citizenship is read live, never trusted from the wire alone.') }}
                </p>

                <div class="grid-2">
                    <Card>
                        <h3 style="margin: 0">{{ t('c_operator_pages.identity.sweep_title', 'The expiry sweep') }}</h3>
                        <p style="font-size: var(--text-sm)">
                            {{ t('c_operator_pages.identity.sweep_body', 'A housekeeping job prunes lapsed attestations (already fail-closed on expiry; the sweep just bounds the table, soft-deleting for forensics).') }}
                        </p>
                        <div class="fsm">
                            <span class="fsm-node">{{ t('c_operator_pages.identity.fsm_expired', 'expired') }}</span>
                            <span class="fsm-arrow" aria-hidden="true"
                                ><Icon name="arrow-right" size="sm"
                            /></span>
                            <span class="fsm-node">{{ t('c_operator_pages.identity.fsm_failclosed', 'already fail-closed') }}</span>
                            <span class="fsm-arrow" aria-hidden="true"
                                ><Icon name="arrow-right" size="sm"
                            /></span>
                            <span class="fsm-node">{{ t('c_operator_pages.identity.fsm_softdeleted', 'soft-deleted for forensics') }}</span>
                        </div>
                    </Card>
                    <Card>
                        <h3 style="margin: 0">{{ t('c_operator_pages.identity.keystone_title', 'The keystone') }}</h3>
                        <p style="font-size: var(--text-sm)">
                            {{ t('c_operator_pages.identity.keystone_body', 'The forwarded write is the keystone: a person passing through a server that isn’t home can still act, because their standing travels as a signed, short-lived attestation and their own device signs the action itself.') }}
                        </p>
                        <span class="citation"
                            >{{ t('c_operator_pages.identity.keystone_cite', 'standing travels; the right itself stays derived, never stored') }}</span
                        >
                    </Card>
                </div>
            </section>

            <div class="plane-wall">
                <Icon name="shield" size="sm" />
                <div>
                    <strong>{{ t('c_operator_pages.identity.two_id_strong', 'Two identities, one wall.') }}</strong> {{ t('c_operator_pages.identity.two_id_body', 'Your operator account proves which box is talking; a citizen’s attestation proves what they may do. Neither can be read as the other — and neither buys a constitutional privilege.') }}<br />
                    <span class="citation">{{ t('c_operator_pages.identity.two_id_cite', 'off the constitutional plane') }}</span>
                </div>
            </div>
        </template>
    </PageScaffold>
</template>
