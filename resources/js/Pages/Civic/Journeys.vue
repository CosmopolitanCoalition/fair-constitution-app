<script setup>
/**
 * Civic/Journeys — the journeys directory (mockups-v3-wiring Phase 3c;
 * design contract mockups/v3 index.html #journeys-h + fixtures-v2.js).
 *
 * Every guided arc, grouped by interaction class (§7 honest map), each card
 * carrying the viewer's own durable progress (server-side journey_progress,
 * no longer the mockups' localStorage). Planned journeys show honestly as
 * planned — no CTA, never a locked door. Soft-gate rule everywhere: a
 * journey nudges, it never blocks, and a medal grants nothing.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Icon from '@/Components/Ui/Icon.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { CLASSES, JOURNEYS_BY_ID } from '@/registry/journeys.js';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    /** config/cga/journeys.php merged with the viewer's progress. */
    journeys: { type: Array, default: () => [] },
});

/* Group by interaction class, in the §7 display order; display data
   (clsLabel, flagship) rides in from the client registry by id. */
const groups = computed(() =>
    CLASSES.map((cls) => ({
        ...cls,
        journeys: props.journeys
            .filter((j) => j.cls === cls.id)
            .map((j) => ({ ...j, display: JOURNEYS_BY_ID[j.id] ?? null })),
    })).filter((group) => group.journeys.length > 0),
);
</script>

<template>
    <PageScaffold :surface="surface">
        <template #intro>
            Learn by doing — each journey walks you through one real process, step by step.
            Finishing one goes on your profile.
        </template>
        <template #about>
            <p>
                A journey is a guided arc over the real institutions — you mark off each step as
                you take it, and completing the whole arc earns a medal on
                <Link href="/civic/record?tab=achievements">your profile</Link>. A medal never
                changes a vote, a seat, or what you are allowed to do.
            </p>
        </template>

        <section v-for="group in groups" :key="group.id" :aria-labelledby="`jcls-${group.id}`">
            <h2 :id="`jcls-${group.id}`">{{ group.label }}</h2>

            <div class="role-grid">
                <div v-for="j in group.journeys" :key="j.id" class="card stack" style="gap: var(--space-2)">
                    <div class="cluster" style="justify-content: space-between; align-items: baseline">
                        <h3 style="margin: 0">{{ j.title }}</h3>
                        <StatusBadge v-if="j.display?.flagship" tone="info" icon="award">Flagship</StatusBadge>
                    </div>

                    <!-- steps-done meter — n of N -->
                    <div class="cluster" style="justify-content: space-between; align-items: baseline">
                        <span v-if="j.completed" class="cc-small">
                            <Icon name="award" size="sm" /> Journey complete
                        </span>
                        <span v-else class="cc-small">{{ j.steps_done }} of {{ j.steps_total }} steps</span>
                        <StatusBadge v-if="j.status === 'planned'" tone="neutral" icon="clock">
                            Planned
                        </StatusBadge>
                    </div>
                    <div
                        class="meter"
                        role="meter"
                        aria-valuemin="0"
                        :aria-valuemax="j.steps_total"
                        :aria-valuenow="j.steps_done"
                        :aria-label="`Your progress — ${j.steps_done} of ${j.steps_total} steps done`"
                    >
                        <span
                            class="meter-fill"
                            :class="{ 'meter-fill--met': j.completed }"
                            :style="{ 'inline-size': `${Math.round((j.steps_done / j.steps_total) * 100)}%` }"
                        ></span>
                    </div>

                    <!-- planned journeys carry no CTA — honest, never a locked door -->
                    <p v-if="j.status === 'planned'" class="gloss" style="margin: 0">
                        Not live in this world yet — it arrives with a later phase.
                    </p>
                    <div v-else class="cluster">
                        <Btn :as="Link" :href="`/journeys/${j.id}`" variant="secondary" size="sm">
                            {{ j.steps_done > 0 ? 'Continue' : 'Start' }}
                            <Icon name="arrow-right" size="sm" />
                        </Btn>
                        <span v-if="j.completed" class="cc-small">On your profile.</span>
                    </div>
                </div>
            </div>
        </section>
    </PageScaffold>
</template>
