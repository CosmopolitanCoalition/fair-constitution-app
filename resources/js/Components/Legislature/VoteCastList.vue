<script setup>
/**
 * Legislature/VoteCastList — published member positions (FE-C1;
 * PHASE_C_DESIGN_frontend.md §A.7). Member votes are PUBLIC — the exact
 * opposite of ballots (Art. II §2): every cast renders with the member's
 * name, value, and any explanation published with the vote.
 *
 * Grammar: absent counts the same as a no (peg quorum — title on the
 * badge); the Speaker's tie-breaking cast carries the F-SPK-004 record
 * (mockups/legislature/session-console.html "4–4 → Speaker broke the
 * tie (F-SPK-004)").
 *
 * Used by: BillDetail (decided floor votes), CommitteeDetail, Oversight
 * (removal votes), SpeakerTools (tie-break record).
 */
import { computed } from 'vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import TagChip from '@/Components/Ui/TagChip.vue';

const props = defineProps({
    /**
     * [{ member_name, seat_kind: 'type_a'|'type_b'|null,
     *    value: 'yes'|'no'|'abstain'|'absent', explanation: string|null,
     *    speaker_tiebreak: bool }]
     */
    casts: { type: Array, required: true },
    /** Bicameral surfaces group rows under their kind headings. */
    groupByKind: { type: Boolean, default: false },
});

const VALUE_BADGES = {
    yes: { tone: 'success', icon: 'check', text: 'Yes', title: null },
    no: { tone: 'danger', icon: 'x', text: 'No', title: null },
    abstain: { tone: 'neutral', icon: null, text: 'Abstain', title: null },
    absent: {
        tone: 'warning',
        icon: 'alert-triangle',
        text: 'Absent',
        title: 'Counts the same as a no — peg quorum (Art. II §2)',
    },
};

const KIND_HEADINGS = { type_a: 'Type A · population-apportioned', type_b: 'Type B · one per constituent' };
const KIND_CHIPS = { type_a: 'type A', type_b: 'type B' };

const groups = computed(() => {
    if (!props.groupByKind) return [{ heading: null, rows: props.casts }];
    const byKind = new Map();
    for (const cast of props.casts) {
        const key = cast.seat_kind ?? 'type_a';
        if (!byKind.has(key)) byKind.set(key, []);
        byKind.get(key).push(cast);
    }
    return [...byKind.entries()].map(([kind, rows]) => ({
        heading: KIND_HEADINGS[kind] ?? kind,
        rows,
    }));
});
</script>

<template>
    <div class="stack" style="gap: var(--space-3)">
        <section v-for="group in groups" :key="group.heading ?? 'all'">
            <h3 v-if="group.heading" style="font-size: var(--text-base)">{{ group.heading }}</h3>
            <div class="stack" style="gap: var(--space-1)">
                <div v-for="cast in group.rows" :key="cast.member_name" class="roster-row">
                    <span>
                        <strong style="color: var(--gov-fg)">{{ cast.member_name }}</strong>
                        <template v-if="!groupByKind && cast.seat_kind">
                            {{ ' ' }}
                            <TagChip>{{ KIND_CHIPS[cast.seat_kind] ?? cast.seat_kind }}</TagChip>
                        </template>
                        <template v-if="cast.speaker_tiebreak">
                            {{ ' ' }}
                            <StatusBadge tone="warning" icon="landmark">Speaker · tie-breaking vote · F-SPK-004</StatusBadge>
                        </template>
                    </span>
                    <span class="cluster" style="gap: var(--space-2)">
                        <details v-if="cast.explanation" style="display: inline-block">
                            <summary class="citation" style="cursor: pointer">Explanation</summary>
                            <p class="cc-small" style="margin-block: var(--space-1) 0">
                                {{ cast.explanation }}
                                <span class="citation">published with the vote · Art. II §2</span>
                            </p>
                        </details>
                        <StatusBadge
                            :tone="VALUE_BADGES[cast.value].tone"
                            :icon="VALUE_BADGES[cast.value].icon ?? undefined"
                            :title="VALUE_BADGES[cast.value].title ?? undefined"
                        >{{ VALUE_BADGES[cast.value].text }}</StatusBadge>
                    </span>
                </div>
            </div>
        </section>
    </div>
</template>
