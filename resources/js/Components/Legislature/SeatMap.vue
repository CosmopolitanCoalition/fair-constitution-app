<script setup>
/**
 * Legislature/SeatMap — the circular chamber (FE-C1;
 * PHASE_C_DESIGN_frontend.md §A.1). Port of chamberSvg() in
 * mockups/legislature/legislature-home.html (lines 183–223), generalized
 * for chamber size and bicameral seat kinds.
 *
 * Algorithm (byte-faithful to the mockup):
 *  - seniority order: occupied seats by days_served desc, ties by
 *    vote_share_norm desc (ledger #q2 normalized share); vacants appended
 *    last — "vacancies join at the junior-most position";
 *  - alternating placement: even positions take seniors from the front,
 *    odd take juniors from the back (lo++ / hi--);
 *  - rings: capacities 12, 20, 28, … (+8 per ring outward); radius
 *    78 + ring × 42.
 *
 * Deliberate deltas from the mockup:
 *  - DYNAMIC viewBox (the mockup hardcodes 280 for 9 seats — wrong for San
 *    Marino's 41): size = 2 × (78 + (rings−1)×42 + 17 + 8);
 *  - bicameral kinds: type_b seats get a var(--status-info) stroke + inner
 *    ring AND the kind in their aria-label (never color-only), plus a
 *    figcaption legend whenever any member carries type_b (Art. V §3).
 *
 * The adjacent roster table is the accessible data equivalent — page-level
 * contract, as in the mockup.
 */
import { computed } from 'vue';

const props = defineProps({
    /**
     * [{ id, seat_no, name, speaker: bool, vacant: bool,
     *    seat_kind: 'type_a'|'type_b'|null, days_served: int,
     *    vote_share_norm: number|null, district_label: string|null,
     *    note: string|null }]
     */
    members: { type: Array, required: true },
    /** Outline one member (roster row hover sync). */
    highlightId: { type: String, default: null },
    maxWidth: { type: String, default: '22rem' },
});

const SEAT_R = 17;
const RING_GAP = 8;

/* Seniority order; vacants join at the junior-most position. */
const orderedSeats = computed(() => {
    const occupied = props.members
        .filter((m) => !m.vacant)
        .slice()
        .sort(
            (a, b) =>
                (b.days_served || 0) - (a.days_served || 0) ||
                (b.vote_share_norm || 0) - (a.vote_share_norm || 0),
        );
    const vacants = props.members.filter((m) => m.vacant);
    return occupied.concat(vacants);
});

/* Alternate: even positions take seniors in order, odd take juniors from
   the end — the mockup's lo++ / hi-- walk, pinned. */
const placed = computed(() => {
    const seats = orderedSeats.value;
    const n = seats.length;
    const out = new Array(n);
    let lo = 0;
    let hi = n - 1;
    for (let i = 0; i < n; i++) out[i] = i % 2 === 0 ? seats[lo++] : seats[hi--];
    return out;
});

/* Ring capacities: 12 innermost, +8 per circle outward. */
const caps = computed(() => {
    const out = [];
    let cap = 12;
    let remaining = placed.value.length;
    while (remaining > 0) {
        out.push(Math.min(cap, remaining));
        remaining -= cap;
        cap += 8;
    }
    return out;
});

/* Dynamic viewBox: outermost ring radius + seat radius + breathing room. */
const size = computed(() => {
    const rings = Math.max(caps.value.length, 1);
    return 2 * (78 + (rings - 1) * 42 + SEAT_R + RING_GAP);
});
const c = computed(() => size.value / 2);

const bicameral = computed(() => props.members.some((m) => m.seat_kind === 'type_b'));
const servingCount = computed(() => props.members.filter((m) => !m.vacant).length);

const KIND_LABELS = { type_a: 'type A (population-apportioned)', type_b: 'type B (one per constituent)' };

function seatLabel(m) {
    if (m.vacant) {
        return `Seat ${m.seat_no} — vacant (countback running); joins at the junior-most position`;
    }
    let label =
        `Seat ${m.seat_no} — ${m.name}${m.speaker ? ' (Speaker)' : ''}` +
        ` · ${m.days_served || 0} days served · share ${(m.vote_share_norm || 0).toFixed(2)}`;
    if (bicameral.value && m.seat_kind) label += ` · ${KIND_LABELS[m.seat_kind] ?? m.seat_kind}`;
    if (m.district_label) label += ` · ${m.district_label}`;
    return label;
}

/* Flat render list: ring/position → x/y, styling per the mockup. */
const dots = computed(() => {
    const out = [];
    let idx = 0;
    caps.value.forEach((ringCount, ring) => {
        const r = 78 + ring * 42;
        for (let k = 0; k < ringCount; k++, idx++) {
            const m = placed.value[idx];
            const ang = -Math.PI / 2 + (2 * Math.PI * k) / ringCount;
            const x = c.value + r * Math.cos(ang);
            const y = c.value + r * Math.sin(ang);
            out.push({
                m,
                x: x.toFixed(1),
                y: y.toFixed(1),
                textY: (y + 4).toFixed(1),
                fill: m.vacant
                    ? 'var(--gov-surface-2)'
                    : 'color-mix(in oklch, var(--gov-primary) 45%, var(--gov-bg))',
                stroke: m.speaker
                    ? 'var(--cc-gold-400)'
                    : m.seat_kind === 'type_b'
                      ? 'var(--status-info)'
                      : 'var(--gov-border-strong)',
                dash: m.vacant ? '4 3' : null,
                textFill: m.vacant ? 'var(--gov-fg-subtle)' : 'var(--gov-fg)',
                label: seatLabel(m),
                highlighted: props.highlightId !== null && m.id === props.highlightId,
            });
        }
    });
    return out;
});
</script>

<template>
    <figure :style="{ 'max-inline-size': maxWidth, 'margin-block': '0', 'margin-inline': 'auto' }">
        <svg
            :viewBox="`0 0 ${size} ${size}`"
            role="img"
            :aria-label="`Circular chamber seat map — ${members.length} seats, ${servingCount} serving`"
            xmlns="http://www.w3.org/2000/svg"
        >
            <!-- The dashed "floor" circle at the center — no head of the room. -->
            <circle :cx="c" :cy="c" r="22" fill="none" stroke="var(--gov-border)" stroke-dasharray="3 4" />
            <text :x="c" :y="c + 4" text-anchor="middle" font-size="9" fill="var(--gov-fg-subtle)">floor</text>

            <g v-for="dot in dots" :key="dot.m.id ?? `seat-${dot.m.seat_no}`" role="img" :aria-label="dot.label">
                <title>{{ dot.label }}</title>
                <!-- Highlight halo (roster hover sync). -->
                <circle
                    v-if="dot.highlighted"
                    :cx="dot.x"
                    :cy="dot.y"
                    r="21"
                    fill="none"
                    stroke="var(--cc-gold-400)"
                    stroke-width="2"
                    stroke-dasharray="2 2"
                />
                <circle
                    :cx="dot.x"
                    :cy="dot.y"
                    r="17"
                    :fill="dot.fill"
                    :stroke="dot.stroke"
                    stroke-width="2"
                    :stroke-dasharray="dot.dash ?? undefined"
                />
                <!-- Inner ring: the type_b non-color-only companion is the
                     aria-label; this ring is the at-a-glance affordance. -->
                <circle
                    v-if="dot.m.seat_kind === 'type_b'"
                    :cx="dot.x"
                    :cy="dot.y"
                    r="12"
                    fill="none"
                    stroke="var(--status-info)"
                    stroke-width="1.5"
                />
                <text
                    :x="dot.x"
                    :y="dot.textY"
                    text-anchor="middle"
                    font-size="11"
                    font-weight="600"
                    :fill="dot.textFill"
                >{{ dot.m.seat_no }}</text>
            </g>
        </svg>
        <figcaption v-if="bicameral" class="gloss">
            Gold ring = Speaker · dashed = vacant · blue ring = type B (one per constituent) · Art. V §3
        </figcaption>
    </figure>
</template>
