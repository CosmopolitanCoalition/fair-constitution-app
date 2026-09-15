<script setup>
/**
 * Civic/Journey — one guided arc, redesigned 2026-09-10 (operator: "a series
 * of buttons with no actual content ... an overhaul to flesh these out and
 * unify the design").
 *
 * Every step is a real card: what happens in the world, your part in it, and
 * a link to the page where it happens (config/cga/journeys.php carries the
 * content; the controller normalises legacy string steps to {label}). The
 * done toggle is a small control on the card, not the card itself. Progress
 * is durable (journey_progress, POST/DELETE /journeys/{id}/steps); finishing
 * the arc appends the medal to the achievements ledger and freezes the steps.
 * Soft-gate rule: a medal never changes a vote, a seat, or what you may do.
 */
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import FormChip from '@/Components/Ui/FormChip.vue';
import Icon from '@/Components/Ui/Icon.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { useAnnounce } from '@/composables/useAnnounce';
import { JOURNEYS_BY_ID } from '@/registry/journeys.js';

defineOptions({ layout: AppShellV2 });
const { t } = useI18n();

const props = defineProps({
    surface: { type: Object, required: true },
    /** From config/cga/journeys.php: {id, title, steps: [{label, what, you, href, form}], status, cls}. */
    journey: { type: Object, required: true },
    /** The viewer's durable progress: {stepsDone: int[], completedAt}. */
    progress: { type: Object, default: () => ({ stepsDone: [], completedAt: null }) },
    /** The earned medal, when the arc is complete: {id, title, earned_at}. */
    achievement: { type: Object, default: null },
    /** "Understand it first": {track: string|null, href: string}. */
    learn: { type: Object, default: () => ({ track: null, href: '/learn' }) },
});

const { announce } = useAnnounce();
const page = usePage();
const signedIn = computed(() => !!page.props.auth?.user);

const learnLabel = computed(() => {
    const track = props.learn?.track;
    if (!track) return t('c_civic.journey.learn_library', 'the Learn library');
    const words = track.replace(/_/g, ' ');
    return words.charAt(0).toUpperCase() + words.slice(1);
});

/* Client display data (class label, flagship, your-part, earn copy) by id.
   Journey display strings resolve through the c_journeys catalog, keyed by
   journey id; the registry value stays the fallback. */
const display = computed(() => JOURNEYS_BY_ID[props.journey.id] ?? null);
const clsLabel = computed(() => {
    const clsId = display.value?.cls ?? props.journey.cls;
    return t('c_journeys.class.' + clsId, display.value?.clsLabel ?? props.journey.cls);
});
const yourPart = computed(() => {
    const d = display.value;
    if (d?.yourPart) return t('c_journeys.' + props.journey.id + '.your_part', d.yourPart);
    return (d?.rooms || []).length
        ? t('c_journeys.generic.arc_rooms', 'follow the arc below — watch from the gallery, or take the floor where you’re a resident')
        : t('c_journeys.generic.arc', 'follow the arc below');
});
const earnLine = computed(
    () => (display.value?.earn
        ? t('c_journeys.' + props.journey.id + '.earn', display.value.earn)
        : t('c_civic.journey.earn_default', 'the places this journey touches will greet you as someone who knows the ropes')),
);

/* Steps arrive as objects; a legacy string step still renders as a label. */
const steps = computed(() =>
    (props.journey.steps ?? []).map((s) => (typeof s === 'string' ? { label: s, what: null, you: null, href: null, form: null } : s)),
);

const live = computed(() => props.journey.status === 'live');
const stepsDone = computed(() => props.progress?.stepsDone ?? []);
const doneCount = computed(() => stepsDone.value.length);
const total = computed(() => steps.value.length);
const complete = computed(() => props.progress?.completedAt != null);
const pct = computed(() => (total.value ? Math.round((doneCount.value / total.value) * 100) : 0));
const nextIndex = computed(() => steps.value.findIndex((_, i) => !isDone(i)));

const isDone = (index) => stepsDone.value.includes(index);

function toggleStep(index) {
    if (!live.value || !signedIn.value) return;
    if (complete.value && isDone(index)) return; // frozen after completion (server rejects too)
    const marking = !isDone(index);
    const options = {
        preserveScroll: true,
        onSuccess: () => announce(marking ? t('c_civic.journey.marked_done', 'Step marked done — saved to your progress.') : t('c_civic.journey.marked_not_done', 'Step marked not done.')),
    };
    if (marking) {
        router.post(`/journeys/${props.journey.id}/steps`, { step: index }, options);
    } else {
        router.delete(`/journeys/${props.journey.id}/steps`, { data: { step: index }, ...options });
    }
}
</script>

<template>
    <PageScaffold :surface="surface" :title="t('c_journeys.' + journey.id + '.title', journey.title)">
        <template #intro>
            {{ t('c_civic.journey.intro', 'Follow the real thing as it moves through the world. Each step tells you what happens, what your part is, and where to go. Mark a step when you have done it.') }}
        </template>

        <div class="cluster" style="justify-content: space-between">
            <div class="cluster">
                <StatusBadge v-if="display?.flagship" tone="info" icon="award">{{ t('c_civic.journey.flagship', 'Flagship') }}</StatusBadge>
                <span class="eyebrow">{{ clsLabel }}</span>
            </div>
            <StatusBadge v-if="complete" tone="success" icon="award">{{ t('c_civic.journey.complete', 'Journey complete') }}</StatusBadge>
            <StatusBadge v-else-if="live" tone="info">{{ t('c_civic.journey.steps_done', { done: doneCount, total }) }}</StatusBadge>
            <StatusBadge v-else tone="neutral" icon="clock">{{ t('c_civic.journey.coming_soon', 'Coming soon') }}</StatusBadge>
        </div>

        <Banner v-if="!live" tone="info">
            {{ t('c_civic.journey.not_live', 'This journey is not live in this world yet. Its steps are shown for reading and cannot be marked.') }}
        </Banner>
        <p v-if="!signedIn" class="gloss">
            {{ t('c_civic.journey.read_without_account', 'You can read every step without an account.') }}
            <Link :href="`/continue?to=${encodeURIComponent('/journeys/' + journey.id)}`">{{ t('c_civic.journey.sign_in_save', 'Sign in to save your progress.') }}</Link>
        </p>

        <p style="margin: 0">
            <strong>{{ t('c_civic.journey.your_part_label', 'Your part:') }}</strong> {{ yourPart }}.
            <span class="gloss">
                {{ t('c_civic.journey.new_to_this', 'New to this?') }}
                <Link :href="learn.href">{{ t('c_civic.journey.read_lesson', 'Read the short lesson') }}<template v-if="learn.track">{{ t('c_civic.journey.lesson_colon', { label: learnLabel }) }}</template></Link>
                {{ t('c_civic.journey.reading_never_gates', 'first. Reading never gates anything.') }}
            </span>
        </p>

        <!-- ───────────────────────────────────────────────── the arc -->
        <Card as="section">
            <div class="meter" role="meter" aria-valuemin="0" :aria-valuemax="total" :aria-valuenow="doneCount" :aria-label="t('c_civic.journey.progress_aria', { done: doneCount, total })">
                <span class="meter-fill" :class="{ 'meter-fill--met': complete }" :style="{ 'inline-size': `${pct}%` }"></span>
            </div>

            <ol class="journey">
                <li
                    v-for="(step, index) in steps"
                    :key="index"
                    class="journey-step"
                    :class="{ 'journey-step--done': isDone(index), 'journey-step--next': live && !complete && index === nextIndex }"
                >
                    <span class="journey-n" aria-hidden="true">
                        <Icon v-if="isDone(index)" name="check" size="sm" />
                        <template v-else>{{ index + 1 }}</template>
                    </span>
                    <div class="journey-body">
                        <h3 class="journey-title">{{ t('c_journeys.' + journey.id + '.step.' + index + '.label', step.label) }}</h3>
                        <p v-if="step.what" class="journey-what">{{ t('c_journeys.' + journey.id + '.step.' + index + '.what', step.what) }}</p>
                        <p v-if="step.you" class="journey-you"><strong>{{ t('c_civic.journey.your_part_label', 'Your part:') }}</strong> {{ t('c_journeys.' + journey.id + '.step.' + index + '.you', step.you) }}</p>
                        <div class="cluster journey-actions">
                            <Btn v-if="step.href" :as="Link" :href="step.href" variant="secondary" size="sm" icon="arrow-right">{{ t('c_civic.journey.go_there', 'Go there') }}</Btn>
                            <FormChip v-if="step.form" :form-id="step.form" />
                            <template v-if="live && signedIn">
                                <Btn v-if="!isDone(index)" variant="ghost" size="sm" @click="toggleStep(index)">{{ t('c_civic.journey.mark_done', 'Mark done') }}</Btn>
                                <Btn v-else-if="!complete" variant="ghost" size="sm" icon="check" @click="toggleStep(index)">{{ t('c_civic.journey.done_undo', 'Done · undo') }}</Btn>
                                <span v-else class="citation"><Icon name="check" size="sm" /> {{ t('c_civic.journey.done', 'Done') }}</span>
                            </template>
                        </div>
                    </div>
                </li>
            </ol>

            <p class="gloss" style="margin-block-start: var(--space-3)">
                <template v-if="complete">
                    {{ t('c_civic.journey.on_profile', 'This journey is on your profile:') }}
                    <Link href="/civic/record?tab=achievements">{{ t('c_civic.journey.see_achievements', 'see your achievements') }}</Link>{{ t('c_civic.journey.steps_frozen', '. Its steps are frozen.') }}
                </template>
                <template v-else>
                    {{ t('c_civic.journey.progress_yours', 'Your progress is yours alone. It is saved for you and does not change what the world does.') }}
                </template>
            </p>
        </Card>

        <!-- ───────────────────────────────── what completing this earns -->
        <Card as="section" :title="t('c_civic.journey.earns_title', 'What finishing this earns')">
            <ul class="earn">
                <li><strong>{{ t('c_civic.journey.medal_label', 'A medal on your profile.') }}</strong> {{ t('c_civic.journey.medal_joins', { title: t('c_journeys.' + journey.id + '.title', journey.title) }) }} <Link href="/civic/record?tab=achievements">{{ t('c_civic.journey.your_achievements', 'your achievements') }}</Link>.</li>
                <li><strong>{{ t('c_civic.journey.head_start', 'A head start.') }}</strong> {{ earnLine }}.</li>
                <li><strong>{{ t('c_civic.journey.stipend_bonus', 'A stipend bonus') }}</strong> {{ t('c_civic.journey.when_economy', 'when the economy pays it.') }} <StatusBadge tone="neutral" icon="clock">{{ t('c_civic.journey.coming', 'Coming') }}</StatusBadge></li>
            </ul>
            <p class="citation">{{ t('c_civic.journey.medal_never', 'A medal never changes a vote, a seat, or what you are allowed to do.') }}</p>
        </Card>

        <div class="cluster" style="justify-content: space-between">
            <Link href="/journeys"><Icon name="arrow-right" size="sm" /> {{ t('c_civic.journey.all_journeys', 'All journeys') }}</Link>
            <span v-if="achievement" class="cc-small"><Icon name="award" size="sm" /> {{ t('c_civic.journey.earned', { date: achievement.earned_at?.slice(0, 10) }) }}</span>
        </div>
    </PageScaffold>
</template>

<style scoped>
.journey {
    list-style: none;
    margin: var(--space-4) 0 0;
    padding: 0;
    display: grid;
    gap: var(--space-3);
}

.journey-step {
    display: grid;
    grid-template-columns: 2.25rem 1fr;
    gap: var(--space-3);
    align-items: start;
    padding: var(--space-3);
    border: 1px solid var(--gov-border, #d6d9de);
    border-radius: var(--radius-md, 8px);
    background: var(--gov-surface-2, #eef0f3);
}

.journey-step--next {
    border-color: var(--gov-accent, #7c5cff);
}

.journey-step--done {
    opacity: 0.72;
}

.journey-n {
    display: inline-grid;
    place-items: center;
    inline-size: 2.25rem;
    block-size: 2.25rem;
    border-radius: 999px;
    border: 1px solid var(--gov-border, #d6d9de);
    font-variant-numeric: tabular-nums;
    font-weight: 600;
}

.journey-step--done .journey-n {
    background: var(--gov-good-s, #e6f4ea);
    color: var(--gov-good, #1e7d3a);
}

.journey-title {
    margin: 0;
    font-size: 1.05rem;
    line-height: 1.3;
}

.journey-what {
    margin: var(--space-1) 0 0;
}

.journey-you {
    margin: var(--space-1) 0 0;
    color: var(--gov-fg-muted, inherit);
}

.journey-actions {
    margin-block-start: var(--space-2);
    align-items: center;
}

.earn {
    margin: 0;
    padding-inline-start: 1.2rem;
    display: grid;
    gap: var(--space-1);
}
</style>
