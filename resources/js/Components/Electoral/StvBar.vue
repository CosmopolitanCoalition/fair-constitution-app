<script setup>
/**
 * Electoral/StvBar — one candidate bar of an STV tally (.stv-cand family).
 * PHASE_B_DESIGN_frontend.md §A.4. The unit StvRound composes; also used
 * standalone by the RankedBallot live aggregate, the VacancyCountback
 * re-run panel, and the Results RCV variant.
 *
 * The .stv-track is aria-hidden — the NUMBER is the accessible datum; the
 * one-per-list visually-hidden "Droop quota {quota}" sibling is
 * parent-rendered, not per bar.
 *
 * Deviations from the §A.4 prop table (both required for mockup-faithful
 * DOM): `quota` is nullable — transfer-breakdown rows in the mockup carry
 * no quota mark; `arrow` prefixes "→ " on transfer rows (the mockup bakes
 * the arrow into the name cell).
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import TagChip from '@/Components/Ui/TagChip.vue';

const props = defineProps({
    name: { type: String, required: true },
    /** null → renders '—' (struck countback member). */
    votes: { type: Number, default: null },
    /** null → no quota mark (transfer-breakdown rows). */
    quota: { type: Number, default: null },
    /** Bar max; convention quota × 1.35 (mockup SCALE). */
    scale: { type: Number, required: true },
    /** → .stv-cand--elected + .stv-fill--elected. */
    elected: { type: Boolean, default: false },
    /** → .stv-cand--eliminated (line-through). */
    eliminated: { type: Boolean, default: false },
    /** → .stv-fill--transfer (gold; breakdown rows). */
    transferFill: { type: Boolean, default: false },
    /** Appends TagChip 'write-in'. */
    writeIn: { type: Boolean, default: false },
    /** Candidate profile link (ellipsis rule .stv-cand-name a). */
    href: { type: String, default: null },
    /** e.g. 'r16' elected-round chip (StatusBadge success). */
    badge: { type: String, default: null },
    /** Countback chips: 'removed from the count' | 'reaches quota' | … */
    chips: { type: Array, default: () => [] },
    /** e.g. 'Droop quota 41,239'. */
    quotaTitle: { type: String, default: null },
    /** "→ " name prefix (transfer-breakdown rows). */
    arrow: { type: Boolean, default: false },
});

const fillPct = computed(() => {
    if (props.votes === null || props.scale <= 0) return null;
    const pct = (props.votes / props.scale) * 100;
    /* Mockup transfer rows floor at 2% so tiny Gregory values stay visible. */
    return Math.min(100, props.transferFill ? Math.max(2, pct) : pct);
});
const quotaPct = computed(() =>
    props.quota === null || props.scale <= 0
        ? null
        : Math.min(100, (props.quota / props.scale) * 100),
);

const displayVotes = computed(() =>
    props.votes === null ? '—' : Math.round(props.votes).toLocaleString(),
);
/* Display rounds; the exact (≤ 3dp Gregory) value rides in the title. */
const votesTitle = computed(() =>
    props.votes !== null && props.votes !== Math.round(props.votes)
        ? String(props.votes)
        : null,
);

const linkTitle = computed(() => {
    const electedRound = props.elected && props.badge ? props.badge.match(/^r(\d+)$/) : null;
    const tip = electedRound
        ? `elected in round ${electedRound[1]}`
        : props.votes !== null
          ? `${Math.round(props.votes).toLocaleString()} votes`
          : null;
    return `${props.name} — open public profile${tip ? ` · ${tip}` : ''}`;
});
</script>

<template>
    <div
        class="stv-cand"
        :class="{ 'stv-cand--elected': elected, 'stv-cand--eliminated': eliminated }"
    >
        <span class="stv-cand-name">
            <template v-if="arrow">→ </template>
            <Link v-if="href" :href="href" :title="linkTitle">{{ name }}</Link>
            <template v-else>{{ name }}</template>
            {{ ' ' }}
            <TagChip v-if="writeIn">write-in</TagChip>
            <StatusBadge
                v-if="badge"
                tone="success"
                icon="check"
                style="margin-inline-start: var(--space-1)"
            >{{ badge }}</StatusBadge>
            <TagChip v-for="chip in chips" :key="chip">{{ chip }}</TagChip>
        </span>
        <span class="stv-track" aria-hidden="true">
            <span
                v-if="fillPct !== null"
                class="stv-fill"
                :class="{ 'stv-fill--elected': elected, 'stv-fill--transfer': transferFill }"
                :style="{ 'inline-size': `${fillPct.toFixed(1)}%` }"
            ></span>
            <span
                v-if="quotaPct !== null"
                class="stv-quota-mark"
                :style="{ 'inset-inline-start': `${quotaPct.toFixed(1)}%` }"
                :title="quotaTitle || undefined"
            ></span>
        </span>
        <span class="stv-votes" :title="votesTitle || undefined">{{ displayVotes }}</span>
    </div>
</template>
