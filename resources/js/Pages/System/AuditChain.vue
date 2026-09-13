<script setup>
/**
 * System/AuditChain — read-only viewer over the hash-chained audit_log
 * (WF-SYS-04; mockups/system/audit-chain.html), latest-first.
 *
 * Anyone authenticated can read the chain — it is the shared public record
 * of the instance. Full-chain verification recomputes every link, so it is
 * operator-triggered (POST), never run per-request; the result is flashed.
 * Rejections are part of the chain: append-only means the rejection itself
 * is appended.
 */
import { computed, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import FormChip from '@/Components/Ui/FormChip.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';
import LogRow from '@/Components/Ui/LogRow.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    /** Bounded history page or one exact receipt, with published metadata only. */
    entries: { type: Object, required: true },
    /** { head_seq, genesis }; a sequence number is not an entry count. */
    chain: { type: Object, required: true },
    canVerify: { type: Boolean, default: false },
});

const page = usePage();
const flash = computed(() => page.props.flash?.status ?? null);
const errors = computed(() => page.props.errors ?? {});
const busy = ref(false);
const loadError = ref('');
const failedUrl = ref('');
const lookup = ref(props.entries.selection.seq ?? '');
const selection = computed(() => props.entries.selection);
watch(() => props.entries.selection.seq, seq => { lookup.value = seq ?? ''; });
const receiptUrl = seq => `${props.entries.latest_url}${props.entries.latest_url.includes('?') ? '&' : '?'}seq=${encodeURIComponent(seq)}`;

function visit(url) {
    if (busy.value) return;
    let completed = false;
    let cancelled = false;
    const failed = () => { loadError.value = 'The history could not be loaded. Try again or return to the latest entries.'; };
    router.get(url, {}, {
        only: ['entries', 'chain'], preserveState: true, preserveScroll: true,
        onStart: () => { busy.value = true; loadError.value = ''; failedUrl.value = url; },
        onSuccess: () => { completed = true; },
        onCancel: () => { cancelled = true; },
        onFinish: () => { busy.value = false; if (!completed && !cancelled) failed(); },
        onError: failed,
    });
}

/* The chain stores UTC; this systems surface shows it as UTC, explicitly. */
const utcFormatter = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'medium',
    timeZone: 'UTC',
});

function formatUtc(iso) {
    if (!iso) return '—';
    try {
        return `${utcFormatter.format(new Date(iso))} UTC`;
    } catch {
        return iso;
    }
}

const shortHash = (hash) => (hash ? `${hash.slice(0, 12)}…` : null);
const isFormRef = (ref) => typeof ref === 'string' && ref.startsWith('F-');

const verifyForm = useForm({});

function verify() {
    verifyForm.post('/system/audit-chain/verify', { preserveScroll: true });
}

/* Operator-only: re-ground a detected chain break by a signed, recorded reason
   (the UI twin of `audit:reconcile`). The chain is tamper-EVIDENT, never
   rewritten — the acknowledgement is what verifyChain then treats as grounded. */
const reconcileForm = useForm({ reason: '' });
function reconcile() {
    reconcileForm.post('/system/audit-chain/reconcile', {
        preserveScroll: true,
        onSuccess: () => reconcileForm.reset('reason'),
    });
}
</script>

<template>
    <PageScaffold :surface="surface">
        <template #intro>
            Browse recorded actions and open the receipt for a specific entry.
        </template>
        <template #about>
            <h2>How the chain works</h2>
            <p>
                Each entry's hash covers the previous entry's hash plus its own payload, making
                changes detectable. Rejected filings are recorded too, with the reason for refusal.
                Existing entries cannot be edited or deleted. <HardenedChip />
            </p>
            <p>
                This viewer shows published metadata. It does not expose the underlying payload
                or participant identities. Sequence numbers can have gaps, so the latest sequence
                number is not a count of entries.
            </p>
            <p>
                The chain begins with the genesis previous hash <code>{{ chain.genesis }}</code>.
                Each link follows <code>hash(n) = H(hash(n−1) ∥ payload(n))</code>.
                Full verification recalculates the links; browsing a receipt does not verify it.
            </p>
        </template>

        <Banner v-if="flash" tone="info">{{ flash }}</Banner>
        <Banner v-if="errors.chain" tone="warning" title="Chain verification failed">
            {{ errors.chain }}
        </Banner>

        <!-- ─────────────────────────────────────────────── Chain head -->
        <Card as="section">
            <div class="cluster" style="gap: var(--space-6)">
                <Stat :value="chain.head_seq === null ? 'No entries yet' : `#${chain.head_seq}`" label="Latest sequence" accent />
            </div>
            <div class="cluster" style="margin-block-start: var(--space-3); align-items: baseline">
                <template v-if="canVerify">
                    <Btn variant="primary" icon="refresh-cw" :disabled="verifyForm.processing" @click="verify">
                        {{ verifyForm.processing ? 'Recomputing every link…' : 'Verify the full chain' }}
                    </Btn>
                    <span class="cc-small">Recomputes every link. This may take time on a large instance.</span>
                </template>
                <p v-else class="gloss" style="margin: 0">
                    Full-chain verification recomputes every link and is operator-triggered; the
                    result is recorded when it runs.
                </p>
            </div>

            <!-- Operator-only: re-ground a detected break by a recorded reason. -->
            <div v-if="canVerify" style="margin-block-start: var(--space-4)">
                <label class="field-label" for="reconcile-reason" style="margin-block-end: var(--space-1)">
                    Reconcile a chain break
                </label>
                <div class="cluster" style="align-items: baseline">
                    <input
                        id="reconcile-reason"
                        v-model="reconcileForm.reason"
                        class="field-input"
                        style="inline-size: min(32rem, 100%)"
                        placeholder="Why is this break grounded? (recorded on the chain)"
                    />
                    <Btn
                        variant="secondary"
                        icon="shield"
                        :disabled="reconcileForm.processing || !reconcileForm.reason.trim()"
                        @click="reconcile"
                    >
                        {{ reconcileForm.processing ? 'Signing…' : 'Reconcile break' }}
                    </Btn>
                </div>
                <p v-if="errors.reason" class="cc-small" style="color: var(--color-danger)">{{ errors.reason }}</p>
                <p class="cc-small" style="margin-block-start: var(--space-1)">
                    Tamper-EVIDENT, never rewritten: a de-facto operator signs an acknowledgement with a
                    reason, recorded on the chain, and verification then treats the break as grounded.
                </p>
            </div>
        </Card>

        <!-- ───────────────────────────────────────────── Latest entries -->
        <Card as="section" :title="selection.status === 'found' ? `Receipt #${selection.seq}` : 'Audit history'">
            <form class="cluster" @submit.prevent="visit(receiptUrl(lookup))">
                <label for="audit-sequence">Entry number</label>
                <input id="audit-sequence" v-model="lookup" class="field-input" type="text" inputmode="numeric" pattern="[1-9][0-9]{0,18}" required style="inline-size: 14rem" />
                <button type="submit" :disabled="busy">Open receipt</button>
            </form>
            <nav class="cluster" aria-label="Audit history navigation" :aria-busy="busy" style="margin-block: var(--space-3)">
                <button v-if="entries.pages.previous" type="button" :disabled="busy" @click="visit(entries.pages.previous)">Newer entries</button>
                <button v-if="entries.pages.next" type="button" :disabled="busy" @click="visit(entries.pages.next)">Older entries</button>
                <button type="button" :disabled="busy" @click="visit(entries.latest_url)">Browse latest history</button>
                <span role="status">{{ busy ? 'Loading history…' : '' }}</span>
            </nav>
            <div v-if="loadError" role="alert">
                {{ loadError }} <button type="button" :disabled="busy" @click="visit(failedUrl)">Try again</button>
            </div>
            <p v-if="selection.status === 'invalid'" role="alert">This entry number is invalid. Use a positive whole number or browse the latest history.</p>
            <p v-else-if="selection.status === 'invalid_cursor'" role="alert">This history page link is invalid. Browse the latest history to continue.</p>
            <p v-else-if="selection.status === 'missing'" role="status">Entry #{{ selection.seq }} was not found on this instance. Sequence numbers can have gaps.</p>
            <p v-else-if="entries.data.length === 0" class="gloss">No entries on this page. Browse the latest history to check for new records.</p>
            <p class="cc-small">{{ selection.status === 'history' ? 'Newest first · ' : '' }}Times are shown in UTC.</p>

            <div v-if="entries.data.length">
                <LogRow
                    v-for="entry in entries.data"
                    :key="entry.seq"
                    :seq="entry.seq"
                    :hash="shortHash(entry.hash)"
                    :rejected="entry.rejected"
                >
                    <code class="cc-small">{{ formatUtc(entry.occurred_at) }}</code>
                    <span>{{ entry.module }} · {{ entry.event }}</span>
                    <a v-if="selection.status === 'history'" :href="receiptUrl(entry.seq)" :aria-disabled="busy" @click.prevent="visit(receiptUrl(entry.seq))">Open receipt #{{ entry.seq }}</a>
                    <FormChip v-if="isFormRef(entry.ref)" :form-id="entry.ref" />
                    <span v-else-if="entry.ref" class="form-chip"><span class="form-id">{{ entry.ref }}</span></span>
                    <StatusBadge v-if="entry.rejected" tone="danger" icon="x">rejected</StatusBadge>
                    <span v-if="entry.rejected && entry.blocked_reason" class="cc-small">
                        {{ entry.blocked_reason }}
                    </span>
                </LogRow>

                <dl v-if="selection.status === 'found'" class="receipt-hashes">
                    <dt>Entry hash</dt><dd><code>{{ entries.data[0].hash }}</code></dd>
                    <dt>Previous entry hash</dt><dd><code>{{ entries.data[0].prev_hash }}</code></dd>
                </dl>
            </div>
        </Card>
    </PageScaffold>
</template>

<style scoped>
.receipt-hashes dd { margin-inline-start: 0; margin-block-end: 1rem; overflow-wrap: anywhere; }
</style>
