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
import { computed } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import FormChip from '@/Components/Ui/FormChip.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';
import LogRow from '@/Components/Ui/LogRow.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

const props = defineProps({
    surface: { type: Object, required: true },
    /** Laravel paginator over audit_log, latest-first. */
    entries: { type: Object, required: true },
    /** { head_seq, count, genesis } */
    chain: { type: Object, required: true },
    canVerify: { type: Boolean, default: false },
});

const page = usePage();
const flash = computed(() => page.props.flash?.status ?? null);
const errors = computed(() => page.props.errors ?? {});

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
</script>

<template>
    <PageScaffold :surface="surface">
        <template #intro>
            The append-only, hash-chained record of every state transition this instance accepted —
            and every one it rejected. Nothing is ever updated or removed; tampering with any link
            breaks every hash after it.
        </template>
        <template #about>
            <p>
                WF-SYS-04 — the constitutional engine intercepts every transition pre-commit;
                accepted ones are appended here, invalid ones are rejected with their citation and
                the rejection is appended too. Ballot and residency identities are never written in
                clear — commitments and day-counts only.
            </p>
        </template>

        <Banner v-if="flash" tone="info">{{ flash }}</Banner>
        <Banner v-if="errors.chain" tone="warning" title="Chain verification failed">
            {{ errors.chain }}
        </Banner>

        <!-- ─────────────────────────────────────────────── Chain head -->
        <Card as="section">
            <div class="cluster" style="gap: var(--space-6)">
                <Stat :value="`#${chain.head_seq}`" label="chain head" accent />
                <Stat :value="chain.count" label="entries" />
            </div>
            <p class="cc-small" style="margin-block-start: var(--space-3)">
                Genesis prev-hash: <code>{{ shortHash(chain.genesis) }}</code> ·
                every link satisfies
                <code>hash(n) = H(hash(n−1) ∥ payload(n))</code>.
            </p>
            <div class="cluster" style="margin-block-start: var(--space-3); align-items: baseline">
                <template v-if="canVerify">
                    <Btn variant="primary" icon="refresh-cw" :disabled="verifyForm.processing" @click="verify">
                        {{ verifyForm.processing ? 'Recomputing every link…' : 'Verify the full chain' }}
                    </Btn>
                    <span class="cc-small">Recomputes all {{ chain.count }} link hashes against the head.</span>
                </template>
                <p v-else class="gloss" style="margin: 0">
                    Full-chain verification recomputes every link and is operator-triggered; the
                    result is recorded when it runs.
                </p>
            </div>
        </Card>

        <!-- ─────────────────────────────────────── How the chain works -->
        <Card as="section" title="How the chain works">
            <p>
                Each entry's hash covers the previous entry's hash plus its own payload, so the
                whole history is a single tamper-evident chain anchored at the genesis hash.
                <strong>Rejections are appended too</strong> — an unconstitutional filing leaves a
                permanent record of having been refused, with its citation. UPDATE and DELETE on
                the table raise at the database level.
                <HardenedChip />
            </p>
            <p class="citation" style="margin-block-start: var(--space-2)">
                Invalid transitions rejected pre-commit; complete tamper-evident history · Art. VII · CGA §6.2, §6.4
            </p>
        </Card>

        <!-- ───────────────────────────────────────────── Latest entries -->
        <Card as="section" title="Latest entries">
            <p class="cc-small">Latest first · stored as UTC, shown as UTC.</p>

            <p v-if="entries.data.length === 0" class="gloss">The chain is empty — no filings yet.</p>

            <div v-else>
                <LogRow
                    v-for="entry in entries.data"
                    :key="entry.seq"
                    :seq="entry.seq"
                    :hash="shortHash(entry.hash)"
                    :rejected="entry.rejected"
                >
                    <code class="cc-small">{{ formatUtc(entry.occurred_at) }}</code>
                    <span>{{ entry.module }} · {{ entry.event }}</span>
                    <FormChip v-if="isFormRef(entry.ref)" :form-id="entry.ref" />
                    <span v-else-if="entry.ref" class="form-chip"><span class="form-id">{{ entry.ref }}</span></span>
                    <StatusBadge v-if="entry.rejected" tone="danger" icon="x">rejected</StatusBadge>
                    <span v-if="entry.rejected && entry.blocked_reason" class="cc-small">
                        {{ entry.blocked_reason }}
                    </span>
                </LogRow>

                <div class="cluster" style="margin-block-start: var(--space-3); align-items: baseline">
                    <Btn
                        v-if="entries.prev_page_url"
                        :as="Link"
                        :href="entries.prev_page_url"
                        preserve-scroll
                        variant="secondary"
                        size="sm"
                    >
                        Newer
                    </Btn>
                    <Btn
                        v-if="entries.next_page_url"
                        :as="Link"
                        :href="entries.next_page_url"
                        preserve-scroll
                        variant="secondary"
                        size="sm"
                    >
                        Older
                    </Btn>
                    <span class="cc-small">
                        Page {{ entries.current_page }} of {{ entries.last_page }} ·
                        {{ entries.total }} entr{{ entries.total === 1 ? 'y' : 'ies' }}
                    </span>
                </div>
            </div>
        </Card>
    </PageScaffold>
</template>
