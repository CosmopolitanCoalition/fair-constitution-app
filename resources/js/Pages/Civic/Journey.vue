<script setup>
/**
 * Civic/Journey — one guided arc (mockups-v3-wiring Phase 3c; design
 * contract mockups/v3/journeys/journey.html, adapted to real data).
 *
 * The arc renders each step as a card with a durable done-toggle backed by
 * journey_progress (POST/DELETE /journeys/{id}/steps) — no longer the
 * mockups' localStorage. Completing every step is a LEDGER EVENT: the medal
 * appends to the append-only achievements table (sealed to the audit chain)
 * and the steps freeze — done is done. The mockup's live-world strip and
 * rooms section are deliberately SKIPPED until the Live Civic Room lands
 * (Phase 6). Soft-gate rule: a medal never changes a vote, a seat, or what
 * you are allowed to do.
 */
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import Icon from '@/Components/Ui/Icon.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { useAnnounce } from '@/composables/useAnnounce';
import { JOURNEYS_BY_ID, yourPartFor } from '@/registry/journeys.js';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    /** From config/cga/journeys.php: {id, title, steps, status, cls}. */
    journey: { type: Object, required: true },
    /** The viewer's durable progress: {stepsDone: int[], completedAt}. */
    progress: { type: Object, default: () => ({ stepsDone: [], completedAt: null }) },
    /** The earned medal, when the arc is complete: {id, title, earned_at}. */
    achievement: { type: Object, default: null },
});

const { announce } = useAnnounce();

/* Client display data (clsLabel, flagship, rooms, your-part) by id. */
const display = computed(() => JOURNEYS_BY_ID[props.journey.id] ?? null);
const clsLabel = computed(() => display.value?.clsLabel ?? props.journey.cls);
const yourPart = computed(() => yourPartFor(display.value));
const roomNames = computed(() => display.value?.rooms ?? []);
const earnLine = computed(
    () => display.value?.earn ?? 'the places this journey touches will greet you as someone who knows the ropes',
);

const live = computed(() => props.journey.status === 'live');
const stepsDone = computed(() => props.progress?.stepsDone ?? []);
const doneCount = computed(() => stepsDone.value.length);
const total = computed(() => props.journey.steps.length);
const complete = computed(() => props.progress?.completedAt != null);
const pct = computed(() => (total.value ? Math.round((doneCount.value / total.value) * 100) : 0));

const isDone = (index) => stepsDone.value.includes(index);

function toggleStep(index) {
    if (!live.value) return;
    /* After completion the steps are frozen — done is done (the server
       rejects the DELETE too; this just avoids a doomed request). */
    if (complete.value && isDone(index)) return;

    const marking = !isDone(index);
    const options = {
        preserveScroll: true,
        onSuccess: () =>
            announce(marking ? 'Step marked done — saved to your progress.' : 'Step marked not done.'),
    };

    if (marking) {
        router.post(`/journeys/${props.journey.id}/steps`, { step: index }, options);
    } else {
        router.delete(`/journeys/${props.journey.id}/steps`, { data: { step: index }, ...options });
    }
}
</script>

<template>
    <PageScaffold :surface="surface" :title="journey.title">
        <template #intro>
            A journey is something you learn by doing it. Follow the real thing as it moves
            through the world, mark off the steps you take, and finish it to earn a place on
            your profile.
        </template>

        <!-- cls eyebrow + status, under the scaffold header -->
        <div class="cluster" style="justify-content: space-between">
            <div class="cluster">
                <StatusBadge v-if="display?.flagship" tone="info" icon="award">Flagship</StatusBadge>
                <span class="eyebrow">{{ clsLabel }}</span>
            </div>
            <StatusBadge v-if="live" tone="success" icon="check">Live in this world</StatusBadge>
            <StatusBadge v-else tone="neutral" icon="clock">Planned — coming soon</StatusBadge>
        </div>

        <Banner v-if="!live" tone="info">
            This journey is not live in this world yet — its arc is shown for reading, and
            steps cannot be marked until it arrives.
        </Banner>

        <p class="gloss" style="margin: 0">
            <strong style="color: var(--gov-fg)">Your part:</strong> {{ yourPart }}.
        </p>

        <!-- ─────────────────────────────────────────────────────── the arc -->
        <Card as="section">
            <span class="eyebrow">The arc</span>

            <!-- your progress — the durable rail -->
            <div class="card card--inset" style="margin-block-start: var(--space-3)">
                <div class="cluster" style="justify-content: space-between; align-items: baseline">
                    <span class="eyebrow"><Icon name="user" size="sm" /> Your progress</span>
                    <StatusBadge v-if="complete" tone="success" icon="award">Journey complete</StatusBadge>
                    <StatusBadge v-else tone="info">{{ doneCount }} of {{ total }} steps</StatusBadge>
                </div>
                <div
                    class="meter"
                    role="meter"
                    aria-valuemin="0"
                    :aria-valuemax="total"
                    :aria-valuenow="doneCount"
                    :aria-label="`Your progress — ${doneCount} of ${total} steps done`"
                >
                    <span
                        class="meter-fill"
                        :class="{ 'meter-fill--met': complete }"
                        :style="{ 'inline-size': `${pct}%` }"
                    ></span>
                </div>
                <p class="gloss" style="margin-block-start: var(--space-1)">
                    <template v-if="complete">
                        This journey is on your profile —
                        <Link href="/civic/record?tab=achievements">see your achievements</Link>.
                        Its steps are frozen: done is done.
                    </template>
                    <template v-else>
                        Mark off each step as you do it. Your progress is yours — it's saved for
                        you, separate from where the world is.
                    </template>
                </p>
            </div>

            <ol class="flow-steps" style="margin-block-start: var(--space-3)">
                <li v-for="(step, index) in journey.steps" :key="index" class="flow-step">
                    <div class="flow-step-head">
                        <span class="flow-step-n">{{ index + 1 }}</span>
                        <span class="flow-action" style="color: var(--gov-fg)">{{ step }}</span>
                        <Icon v-if="isDone(index)" name="check" size="sm" />
                    </div>
                    <div v-if="live" class="cluster" style="margin-block-start: var(--space-1)">
                        <Btn
                            v-if="!isDone(index)"
                            variant="ghost"
                            size="sm"
                            @click="toggleStep(index)"
                        >
                            Mark this step done
                        </Btn>
                        <Btn
                            v-else-if="!complete"
                            variant="secondary"
                            size="sm"
                            :pressed="true"
                            icon="check"
                            @click="toggleStep(index)"
                        >
                            Done — tap to undo
                        </Btn>
                        <Btn v-else variant="secondary" size="sm" :disabled="true" icon="check">
                            Done
                        </Btn>
                    </div>
                </li>
            </ol>
        </Card>

        <!-- rooms — deliberately skipped until the Live Civic Room lands -->
        <p class="gloss">
            <Icon name="landmark" size="sm" />
            <template v-if="roomNames.length">
                This journey passes through the {{ roomNames.join(' and the ').toLowerCase() }} —
                the live room arrives in Phase 6.
            </template>
            <template v-else>
                The live rooms this journey touches arrive in Phase 6.
            </template>
        </p>

        <!-- ───────────────────────────── what completing this earns -->
        <Card as="section">
            <h2><Icon name="award" size="sm" /> What completing this earns</h2>
            <ul>
                <li>
                    <strong>A medal on your profile</strong> — “{{ journey.title }}” joins
                    <Link href="/civic/record?tab=achievements">your achievements</Link>.
                </li>
                <li>
                    <strong>A one-time stipend bonus</strong> — paid into your wallet when the
                    economy goes live
                    <StatusBadge tone="neutral" icon="clock">Planned · Phase 8</StatusBadge>
                </li>
                <li>
                    <strong>A head start</strong> — {{ earnLine }}. A friendly nudge, never a
                    locked door.
                </li>
            </ul>
            <p class="citation">
                A medal never changes a vote, a seat, or what you are allowed to do.
            </p>
        </Card>

        <div class="cluster" style="justify-content: space-between">
            <Link href="/journeys"><Icon name="arrow-right" size="sm" /> All journeys</Link>
            <span v-if="achievement" class="cc-small">
                <Icon name="award" size="sm" /> Earned {{ achievement.earned_at?.slice(0, 10) }}
            </span>
        </div>
    </PageScaffold>
</template>
