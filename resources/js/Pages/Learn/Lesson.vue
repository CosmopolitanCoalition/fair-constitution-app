<script setup>
/**
 * Learn/Lesson — a module's lesson + comprehension check (contract
 * mockups/v3/learn/lesson.html; K-2, ruling A5).
 *
 * ⚠ THE DELIBERATE BREAK FROM THE MOCKUP: lesson.html ships the answer
 * index to the browser (`lesson.check.answer`) — that shortcut is pinned
 * OUT (EducationAnswerKeySecrecyTest). Here the browser holds prompts and
 * choices only; grading is a POST, a wrong answer gets the explain text,
 * and the correct choice is never revealed by a fail. Retakes unlimited.
 */
import { computed, reactive } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Card from '@/Components/Ui/Card.vue';
import Icon from '@/Components/Ui/Icon.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    track: { type: Object, required: true },
    modules: { type: Array, default: () => [] },
    module: { type: Object, required: true },
    questions: { type: Array, default: () => [] },
    required: { type: Boolean, default: false },
    quiz: { type: Object, default: null },
    auth: { type: Object, default: () => ({}) },
});

const { t } = useI18n({ useScope: 'global' });

const chosen = reactive({});
const signedIn = computed(() => Boolean(props.auth?.user));
const result = computed(() =>
    props.quiz && props.quiz.module_key === props.module.key ? props.quiz : null);

const allAnswered = computed(() =>
    props.questions.length > 0 && props.questions.every((q) => chosen[q.key]));

const submit = () => {
    router.post(`/learn/${props.track.key}/${props.module.key}/check`, {
        answers: { ...chosen },
    }, { preserveScroll: true });
};

const next = computed(() => {
    const i = props.modules.findIndex((m) => m.key === props.module.key);
    return i >= 0 ? props.modules[i + 1] ?? null : null;
});
</script>

<template>
    <PageScaffold :surface="surface" :title="t(module.title)">
        <template #intro>
            <Link href="/learn">{{ t('c_education.learn.ui.back_to_learn') }}</Link>
            · <span class="citation">{{ t(track.title) }}</span>
        </template>

        <Banner v-if="required" tone="warn">{{ t('c_education.learn.ui.required_banner') }}</Banner>
        <StatusBadge v-if="module.completed" tone="success">{{ t('c_education.learn.ui.completed') }}</StatusBadge>

        <section aria-labelledby="check-h" class="stack">
            <h2 id="check-h">{{ t('c_education.learn.ui.check_heading') }}</h2>
            <p class="gloss">{{ t('c_education.learn.ui.check_intro') }}</p>

            <Banner v-if="result" :tone="result.passed ? 'success' : 'warn'">
                {{ result.passed ? t('c_education.learn.ui.passed') : t('c_education.learn.ui.failed') }}
                ({{ t('c_education.learn.ui.score') }}: {{ result.score_pct }}%)
            </Banner>

            <Card v-for="q in questions" :key="q.key">
                <p><strong>{{ t(q.prompt) }}</strong></p>
                <div class="stack" role="radiogroup" :aria-label="t(q.prompt)">
                    <label v-for="(choiceKey, opt) in q.choices" :key="opt" class="lesson-row">
                        <input
                            v-model="chosen[q.key]"
                            type="radio"
                            :name="`q-${q.key}`"
                            :value="opt"
                        >
                        {{ t(choiceKey) }}
                    </label>
                </div>
                <!-- Explain-on-fail: teaching prose for a missed question.
                     The correct choice is never marked — that would be the
                     answer key by another door. -->
                <Banner v-if="result && result.explain[q.key]" tone="info">
                    {{ t(result.explain[q.key]) }}
                </Banner>
            </Card>

            <p v-if="!signedIn" class="gloss">{{ t('c_education.learn.ui.sign_in_to_complete') }}</p>
            <p v-else>
                <button class="btn btn--primary" :disabled="!allAnswered" @click="submit">
                    {{ result && !result.passed ? t('c_education.learn.ui.retake') : t('c_education.learn.ui.submit_answers') }}
                </button>
            </p>
        </section>

        <section v-if="next" aria-labelledby="next-h" class="stack">
            <h2 id="next-h">Up next</h2>
            <p>
                <Link :href="`/learn/${track.key}/${next.key}`" class="btn">
                    {{ t(next.title) }} <Icon name="arrow-right" size="sm" />
                </Link>
            </p>
        </section>
    </PageScaffold>
</template>
