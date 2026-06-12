<script setup>
/**
 * Electoral/StvRound — one counting round: heading + tally bars (key rounds
 * only) + the expandable transfer breakdown. PHASE_B_DESIGN_frontend.md
 * §A.4; markup byte-derived from roundBlock()/transferHtml()/bar() in
 * mockups/electoral/results.html (lines 151–195).
 *
 * Accepts BOTH candidate-reference shapes:
 *  - production (§C contract): { candidacy_id, name, write_in } objects in
 *    tallies/transfer pairs;
 *  - mockup/fixture STV_DATA: plain name strings, write-ins marked by a
 *    " (write-in)" suffix (normalized here, exactly like the mockup's
 *    nameLink()).
 *
 * Tally-less rounds (the collapsed middle) render heading + breakdown only
 * — same component; the PARENT decides placement (inline vs inside the
 * "Rounds a–b" <details>).
 */
import { computed } from 'vue';
import Icon from '@/Components/Ui/Icon.vue';
import StvBar from '@/Components/Electoral/StvBar.vue';

const props = defineProps({
    /** One `display[]` entry — exact shape in the design §C contract. */
    round: { type: Object, required: true },
    quota: { type: Number, required: true },
    scale: { type: Number, required: true },
    /** name → round (badges + tooltips). */
    electedRound: { type: Object, default: () => ({}) },
    /** (candidacy_id, name) => href|null. */
    profileHref: { type: Function, default: null },
    /** Transfer <details> open state. */
    defaultOpen: { type: Boolean, default: false },
});

/* Normalize a candidate reference (string | {candidacy_id,name,write_in}). */
function cand(ref) {
    if (typeof ref === 'string') {
        return {
            id: ref,
            name: ref.replace(' (write-in)', ''),
            writeIn: ref.indexOf('write-in') >= 0,
            raw: ref,
        };
    }
    return { id: ref.candidacy_id, name: ref.name, writeIn: !!ref.write_in, raw: ref.name };
}

function electedInRound(c) {
    return props.electedRound[c.raw] ?? props.electedRound[c.name] ?? null;
}

function href(c) {
    return props.profileHref ? props.profileHref(c.id, c.name) : null;
}

const tallies = computed(() =>
    (props.round.tallies ?? []).map(([ref, votes]) => {
        const c = cand(ref);
        const electedSoFar = props.round.electedSoFar ?? [];
        const isElected =
            electedSoFar.includes(c.raw) ||
            electedSoFar.includes(c.id) ||
            votes >= props.quota;
        const round = electedInRound(c);
        return {
            ...c,
            votes,
            elected: isElected,
            badge: isElected && round ? `r${round}` : null,
        };
    }),
);

const transfer = computed(() => {
    const tr = props.round.transfer;
    if (!tr || !tr.to || !tr.to.length) return null;
    const from = cand(tr.from);
    const totalMoved = tr.to.reduce((acc, [, votes]) => acc + votes, 0) + (tr.exhausted || 0);
    const max = Math.max(...tr.to.map(([, votes]) => votes));
    return {
        from,
        kind: tr.kind,
        totalMoved,
        exhausted: tr.exhausted || 0,
        rows: tr.to.map(([ref, votes]) => ({ ...cand(ref), votes, max })),
    };
});

const quotaTitle = computed(() => `Droop quota ${props.quota.toLocaleString()}`);
</script>

<template>
    <div>
        <h3>Round {{ round.n }} <span class="citation stv-action">{{ round.action }}</span></h3>

        <div v-if="round.tallies" class="stv-round">
            <StvBar
                v-for="row in tallies"
                :key="row.id"
                :name="row.name"
                :votes="row.votes"
                :quota="quota"
                :scale="scale"
                :elected="row.elected"
                :write-in="row.writeIn"
                :href="href(row)"
                :badge="row.badge"
                :quota-title="quotaTitle"
            />
        </div>

        <details
            v-if="transfer"
            class="about-surface"
            :open="defaultOpen"
            style="margin-block-start: var(--space-2)"
        >
            <summary>
                <Icon name="chevron-right" size="sm" />
                Where {{ transfer.from.name }}&rsquo;s votes went ·
                {{ transfer.totalMoved.toLocaleString() }} votes
                {{ transfer.kind === 'surplus' ? '(surplus, fractional Gregory values)' : '(elimination, at current value)' }}
            </summary>
            <div class="about-surface-body">
                <StvBar
                    v-for="row in transfer.rows"
                    :key="row.id"
                    :name="row.name"
                    :votes="row.votes"
                    :scale="row.max"
                    transfer-fill
                    arrow
                    :write-in="row.writeIn"
                    :href="href(row)"
                />
                <div v-if="transfer.exhausted" class="stv-cand">
                    <span class="stv-cand-name" style="color: var(--gov-fg-subtle)">→ exhausted (no further preference)</span>
                    <span class="stv-track" aria-hidden="true"></span>
                    <span class="stv-votes">{{ transfer.exhausted.toLocaleString() }}</span>
                </div>
            </div>
        </details>
    </div>
</template>
