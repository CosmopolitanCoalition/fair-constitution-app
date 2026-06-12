<script setup>
/**
 * Electoral/PhaseBanner — the shared election-phase banner four mockups
 * hand-roll (open-ballot, candidacy-registration, candidate-profile,
 * election-detail). PHASE_B_DESIGN_frontend.md §A.7: one component keeps
 * the frozen `election: approval | ranked | certifying` vocabulary in one
 * file.
 *
 * Behavior per context (mockup copy verbatim, jurisdiction names
 * genericized — production banners are race-agnostic chrome):
 *  - approval  → renders NOTHING, except context=registration which renders
 *    the info "Registration is open now" banner instead;
 *  - ranked / certifying → tone=warning role=status (ambient state, not an
 *    interruption — every mockup uses role="status").
 */
import { computed } from 'vue';
import Banner from '@/Components/Ui/Banner.vue';
import CitationLine from '@/Components/Ui/CitationLine.vue';

const props = defineProps({
    /** Frozen vocabulary. */
    phase: {
        type: String,
        required: true,
        validator: (v) => ['approval', 'ranked', 'certifying'].includes(v),
    },
    context: {
        type: String,
        required: true,
        validator: (v) => ['open-ballot', 'registration', 'profile'].includes(v),
    },
    /** Profile-context copy branch (null → no finalist sentence). */
    isFinalist: { type: Boolean, default: null },
    /** { rankedBallot, results, openBallot } hrefs. */
    links: { type: Object, default: () => ({}) },
});

const visible = computed(() => props.phase !== 'approval' || props.context === 'registration');
</script>

<template>
    <!-- registration · approval — the info banner (CLK-18 window open) -->
    <Banner
        v-if="visible && context === 'registration' && phase === 'approval'"
        tone="info"
        icon="clock"
        title="Registration is open now."
    >
        The approval phase is live — validated candidates appear on the open ballot
        immediately.
        <CitationLine text="CLK-18 · open since prior certification" />
    </Banner>

    <!-- registration · ranked/certifying — window closed (CLK-18) -->
    <Banner
        v-else-if="visible && context === 'registration'"
        tone="warning"
        role="status"
        icon="clock"
        title="The finalist cutoff has passed for the current race."
    >
        <template v-if="phase === 'ranked'">The ranked window is open; </template>
        <template v-else>Tabulation and certification are under way; </template>
        registration reopens for the next cycle the moment results certify.
        <CitationLine text="CLK-18 · closes at finalist cutoff · reopens at certification" />
    </Banner>

    <!-- open-ballot · ranked/certifying — finalists locked (CLK-21) -->
    <Banner
        v-else-if="visible && context === 'open-ballot'"
        tone="warning"
        role="status"
        icon="clock"
        title="The approval phase has closed — finalists are locked."
    >
        <template v-if="phase === 'ranked'">
            The ranked window is open:
            <a v-if="links.rankedBallot" :href="links.rankedBallot">rank your ballot now</a>
            <template v-else>rank your ballot now</template>.
        </template>
        <template v-else>
            Tabulation is under way:
            <a v-if="links.results" :href="links.results">watch the count</a>
            <template v-else>watch the count</template>.
        </template>
        Standings below are the frozen cutoff.
        <CitationLine text="CLK-21 · finalist cutoff" />
    </Banner>

    <!-- profile · ranked/certifying — standing frozen (CLK-21) -->
    <Banner
        v-else-if="visible && context === 'profile'"
        tone="warning"
        role="status"
        icon="clock"
        title="The approval phase has closed — this standing is frozen at the finalist cutoff."
    >
        <template v-if="isFinalist === true">This candidate is a finalist on the ranked ballot. </template>
        <template v-else-if="isFinalist === false">This candidate did not reach the finalist line and remains write-in eligible. </template>
        <CitationLine text="CLK-21 · finalist cutoff · Art. II §2" />
    </Banner>
</template>
