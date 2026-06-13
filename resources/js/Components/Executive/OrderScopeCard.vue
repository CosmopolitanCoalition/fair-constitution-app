<script setup>
/**
 * Executive/OrderScopeCard — executive order scope-citation card (FE-D1;
 * PHASE_D_DESIGN_frontend.md §A.6). One .log-row per executive_orders
 * row; .log-row--rejected for rejections — append-only means the
 * rejection itself is appended.
 *
 * Rejected orders render the engine's `rejection_citation` VERBATIM
 * (executive-actions.html line 208 grammar: "Rejected pre-issuance:
 * {citation}") plus the load-bearing public-record chip
 * ("on the public record · #{seq}" → /system/public-records?seq=…) —
 * the order never took effect; the rejected attempt is on the record.
 *
 * Judicial review is a Phase E filing — `review` renders as a
 * planned-flag chip (deferral #1), consistent with the F-JDG-007 stub.
 */
import { computed } from 'vue';
import StateStrip from '@/Components/Ui/StateStrip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

const props = defineProps({
    /**
     * executive_orders row:
     * { id_display:'EO-2031-14', title, department:{name}|null,
     *   issued_at_display, status:'drafted'|'scope_validated'|'issued'|
     *   'rejected_pre_issuance'|'reviewed'|'struck',
     *   enabling:{ type:'law'|'emergency_power', label, href },
     *   rejection_citation: string|null ← engine verbatim,
     *   note, body?, public_record:{ seq, href }|null, review:{status}|null }
     */
    order: { type: Object, required: true },
    /** Adds the order body + the order-lifecycle StateStrip. */
    detailed: { type: Boolean, default: false },
    /** PHP-owned order machine (detailed mode); pages feed it as a prop. */
    machine: { type: Array, default: () => [] },
});

const rejected = computed(() => props.order.status === 'rejected_pre_issuance');

const STATUS_BADGES = {
    drafted: ['neutral', 'file-text', 'Drafted'],
    scope_validated: ['info', 'check', 'Scope validated'],
    issued: ['success', 'check', 'Issued'],
    rejected_pre_issuance: ['danger', 'x', 'Rejected pre-issuance'],
    reviewed: ['info', 'scale', 'Judicially reviewed'],
    under_review: ['info', 'scale', 'Under judicial review'],
    struck: ['danger', 'x', 'Struck'],
    revoked: ['neutral', 'minus', 'Revoked'],
};
const badge = computed(() => {
    const [tone, icon, text] = STATUS_BADGES[props.order.status] ?? ['neutral', null, props.order.status];
    return { tone, icon, text };
});
</script>

<template>
    <div class="log-row" :class="{ 'log-row--rejected': rejected }">
        <span class="log-seq" data-no-i18n>{{ order.id_display }}</span>

        <div style="flex: 1 1 16rem; min-inline-size: 0">
            <strong>{{ order.title }}</strong>
            <span class="cc-small" style="display: block; color: var(--gov-fg-muted)">
                <template v-if="order.department">{{ order.department.name }} · </template>{{ order.issued_at_display }}
            </span>

            <!-- enabling-basis chip — the cited instrument -->
            <span class="cluster" style="gap: var(--space-2); margin-block-start: var(--space-1)">
                <a v-if="order.enabling" class="form-chip" :href="order.enabling.href">
                    <span class="form-id" data-no-i18n>{{ order.enabling.type === 'emergency_power' ? 'EMERGENCY POWER' : 'ENABLING ACT' }}</span>
                    {{ order.enabling.label }}
                </a>
                <span v-if="order.enabling?.type === 'emergency_power'" class="citation">
                    emergency methods widen the delegated scope only within the declared area and duration · Art. II §7 · CLK-03
                </span>
            </span>

            <!-- rejection: the engine citation VERBATIM + the record chip -->
            <template v-if="rejected">
                <span class="citation" style="display: block; margin-block-start: var(--space-1)">
                    Rejected pre-issuance: {{ order.rejection_citation }}
                </span>
                <span class="cc-small" style="display: block">
                    The order never took effect; the rejected attempt is on the public record.
                    <a v-if="order.public_record" :href="order.public_record.href" class="tag-chip" data-no-i18n>
                        on the public record · #{{ order.public_record.seq }}
                    </a>
                </span>
            </template>
            <span v-else-if="order.note" class="citation" style="display: block">{{ order.note }}</span>

            <template v-if="detailed">
                <p v-if="order.body" class="cc-small" style="margin-block: var(--space-2) 0; white-space: pre-wrap">{{ order.body }}</p>
                <div v-if="machine.length" style="margin-block-start: var(--space-2)">
                    <StateStrip :states="machine" :current="order.status" />
                </div>
            </template>
        </div>

        <span class="cluster" style="gap: var(--space-2)">
            <StatusBadge :tone="badge.tone" :icon="badge.icon">{{ badge.text }}</StatusBadge>
            <span v-if="order.status === 'issued'" class="tag-chip">judicially reviewable · Art. IV §5</span>
            <span v-if="order.review" class="planned-flag" data-no-i18n>
                judicial review · filing arrives with the judiciary · Phase E
            </span>
        </span>
    </div>
</template>
