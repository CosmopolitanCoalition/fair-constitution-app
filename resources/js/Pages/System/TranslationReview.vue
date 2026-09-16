<script setup>import { useLocaleFormat } from '@/composables/useLocaleFormat';
const localeFmt = useLocaleFormat();
/**
 * System/TranslationReview — the human half of translation.
 *
 * Built to mockups/v3/translation/language.html, using the classes the app
 * already ships for it (resources/css/cga/components-v2.css:876-915 —
 * .lesson-row, .review-row, .tprog, .contributor-row).
 *
 * A person never starts from a blank box. They see the English source, the
 * machine's first draft beside it, and answer: accept it, or suggest better
 * wording. Enough verifications move a string to community-verified. Three
 * readers, not one — no single person's opinion settles what a constitution
 * says in their language.
 *
 * The 38 constitutional terms get their own list because they constrain
 * everything else: settle "quorum" once and every string carrying it is
 * consistent; leave it unsettled and every string guesses separately.
 */
import { ref, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import Icon from '@/Components/Ui/Icon.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import DataTable from '@/Components/Ui/DataTable.vue';

defineOptions({ layout: AppShellV2 });

const { t } = useI18n();

const props = defineProps({
    surface: { type: Object, required: true },
    language: { type: Object, required: true },
    modality: { type: String, default: 'ui' },
    modalities: { type: Array, default: () => [] },
    states: { type: Array, default: () => [] },
    row: { type: Object, required: true },
    queue: { type: Array, default: () => [] },
    counts: { type: Object, default: () => ({}) },
    terms: { type: Array, default: () => [] },
    contributors: { type: Array, default: () => [] },
    quorum: { type: Number, default: 3 },
    canVerify: { type: Boolean, default: false },
});

/* Per-row wording buffers. A row only becomes an edit when someone types, so
   the common case — the machine got it right — stays a single click. */
const drafts = ref({});
const busy = ref(null);
const suggesting = ref({});

const rtl = computed(() => props.language.dir === 'rtl');
const byState = computed(() => Object.fromEntries(props.states.map((s) => [s.id, s])));
const current = computed(() => props.modalities.find((m) => m.id === props.modality) ?? {});

const rowKey = (it) => `${it.namespace}:${it.key}`;
const draftFor = (it) => drafts.value[rowKey(it)] ?? it.machine ?? '';
const cellOf = (id) => props.row.cells?.[id] ?? { state: 'none', pct: 0, verifiers: 0 };

const settledTerms = computed(() => props.terms.filter((tm) => tm.settled).length);
/* Distinct from "not settled": a term can have a machine's wording and still be
   unsettled. This counts the ones with no wording at all. */
const unwordedTerms = computed(() => props.terms.filter((tm) => !tm.rendering).length);

/* Term-list column set. Labels resolve at setup scope, so the useI18n t is not
   shadowed by the DataTable row slots (those bind the row as `term`). */
const termColumns = computed(() => [
    { key: 'term', label: t('c_system.translation_review.col_term', 'Term (English)'), mono: true },
    { key: 'rendering', label: t('c_system.translation_review.col_in', 'In {name}', { named: { name: props.language.name } }) },
    { key: 'state', label: t('c_system.translation_review.col_state', 'State') },
    { key: 'context', label: t('c_system.translation_review.col_context', 'What it means here') },
    ...(props.canVerify ? [{ key: 'act', label: t('c_system.translation_review.col_act', 'Is this right?') }] : []),
]);

function href(m) {
    return `/system/translations/review/${props.language.code}?m=${m}`;
}

/* A term rides the same endpoint, the same table and the same quorum as a
   string — settling a term IS settling a translation, and the more consequential
   one, since every string carrying the term inherits the choice. */
function submitTerm(term, verdict) {
    if (!props.canVerify) return;
    busy.value = `${term.namespace}:${term.term}`;
    router.post(
        `/system/translations/review/${props.language.code}`,
        {
            namespace: term.namespace,
            key: term.term,
            source_hash: term.source_hash,
            verdict,
            machine: term.rendering,
            text: null,
        },
        { preserveScroll: true, preserveState: false, onFinish: () => { busy.value = null; } },
    );
}

function submit(item, verdict) {
    if (!props.canVerify) return;
    busy.value = rowKey(item);
    router.post(
        `/system/translations/review/${props.language.code}`,
        {
            namespace: item.namespace,
            key: item.key,
            source_hash: item.source_hash,
            verdict,
            machine: item.machine,
            text: verdict === 'edited' ? draftFor(item) : null,
        },
        {
            preserveScroll: true,
            preserveState: false,
            onFinish: () => { busy.value = null; },
        },
    );
}
</script>

<template>
    <PageScaffold :surface="surface" :title="language.endonym">
        <template #intro>
            <span class="citation">
                {{ language.name }} · {{ language.code
                }}<template v-if="rtl"> · {{ t('c_system.translation_review.rtl', 'right-to-left') }}</template> · {{ t('c_system.translation_review.pct_overall', '{pct}% overall', { named: { pct: row.overall } }) }}
            </span>
            <br />
            {{ t('c_system.translation_review.intro_a', 'The machine writes the first round. You correct it. Enough verifications move a string to') }}
            <strong>{{ t('c_system.translation_review.community_verified', 'community-verified') }}</strong>
            {{ t('c_system.translation_review.intro_b', '— {quorum} readers, not one, so no single person\'s reading settles what the constitution says in this language.', { named: { quorum } }) }}
        </template>

        <!-- who may verify, and why it is not a privilege -->
        <div class="route-note">
            <Icon name="users" size="sm" />
            <div v-if="canVerify">
                <strong>{{ t('c_system.translation_review.can_verify_strong', 'You can verify {name}.', { named: { name: language.name } }) }}</strong>
                {{ t('c_system.translation_review.can_verify_body', 'Verification is gated to the people who actually read a language — not a central team, and not machine translation grading itself.') }}
            </div>
            <div v-else>
                <strong>{{ t('c_system.translation_review.reading_strong', 'You are reading this queue, not ruling on it.') }}</strong>
                {{ t('c_system.translation_review.reading_body', 'Verification is gated to the people who read {name} — someone who cannot read a language cannot usefully confirm it. This takes nothing from you: name {name} among your languages on your record and you can start immediately.', { named: { name: language.name } }) }}
            </div>
        </div>

        <!-- ── THE SIX KINDS ────────────────────────────────────────────────
             Six rows, not one percentage. A language at 99% on interface
             strings with no dubbed video is not "99% translated", and one
             number would say it was. -->
        <Card :title="t('c_system.translation_review.by_content_type', 'By content type')" :eyebrow="t('c_system.translation_review.six_kinds_eyebrow', 'six kinds of content')">
            <p class="gloss">
                {{ t('c_system.translation_review.pick_a', 'Pick a content type to review it. Interface and page copy cover the screens; audio and captions cover the guide videos.') }}
                <strong>{{ row.overall }}%</strong> {{ t('c_system.translation_review.pick_b', 'overall is the mean of all six — the empty ones count, which is the point of splitting them.') }}
            </p>
            <div class="lesson-list">
                <component
                    :is="m.measurable ? Link : 'div'"
                    v-for="m in modalities" :key="m.id"
                    class="lesson-row" :class="{ 'lesson--done': m.id === modality }"
                    :href="m.measurable ? href(m.id) : undefined"
                    :aria-current="m.id === modality ? 'true' : undefined"
                >
                    <span class="lesson-title">
                        <Icon :name="m.icon" size="sm" /> {{ m.label }}
                    </span>
                    <StatusBadge :tone="byState[cellOf(m.id).state]?.tone ?? 'neutral'">
                        {{ byState[cellOf(m.id).state]?.label ?? cellOf(m.id).state }}
                    </StatusBadge>
                    <span class="lesson-meta mbar">
                        <span class="tprog"><i :style="{ inlineSize: `${cellOf(m.id).pct}%` }" /></span>
                    </span>
                    <span class="lesson-meta" data-no-i18n>
                        {{ cellOf(m.id).pct }}% · {{ cellOf(m.id).verifiers }} verifiers
                    </span>
                </component>
            </div>
            <p v-for="m in modalities.filter((x) => !x.measurable)" :key="`why-${m.id}`" class="citation">
                <strong>{{ m.label }}</strong> — {{ m.why }}
            </p>
        </Card>

        <!-- ── THE TERMS ────────────────────────────────────────────────────
             Above the strings, because they constrain the strings. Verified
             the same way and by the same quorum — settling a term is the same
             act as settling a string, and carries more weight. -->
        <Card :title="t('c_system.translation_review.terms_title', 'Constitutional terms — {settled} of {total} settled', { named: { settled: settledTerms, total: terms.length } })">
            <p class="gloss">
                {{ t('c_system.translation_review.terms_gloss_a', 'These words carry legal weight and must read the same everywhere. Settle them first: every string containing one inherits the choice, and re-wording one later unsettles every verdict that was given against the old wording.') }}
                <strong>{{ t('c_system.translation_review.terms_settled_means', 'Settled means {quorum} readers agreed', { named: { quorum } }) }}</strong> {{ t('c_system.translation_review.terms_gloss_b', '— a rendering a machine proposed is a draft here too.') }}
            </p>
            <DataTable
                :columns="termColumns"
                :rows="terms"
                row-key="term"
                :caption="t('c_system.translation_review.terms_caption', 'Constitutional vocabulary for this language')"
            >
                <template #cell-rendering="{ row: term }">
                    <span v-if="term.rendering" :dir="language.dir" :lang="language.code">{{ term.rendering }}</span>
                    <em v-else class="gloss">{{ t('c_system.translation_review.no_wording_yet', 'no wording yet') }}</em>
                </template>
                <template #cell-state="{ row: term }">
                    <StatusBadge :tone="byState[term.state]?.tone ?? 'neutral'">
                        {{ byState[term.state]?.label ?? term.state }}
                    </StatusBadge>
                    <span class="citation" data-no-i18n> {{ term.verifiers }}/{{ term.needed }}</span>
                    <StatusBadge v-if="term.my_verdict" tone="info">{{ t('c_system.translation_review.you_said', 'you said: {verdict}', { named: { verdict: term.my_verdict } }) }}</StatusBadge>
                </template>
                <template #cell-act="{ row: term }">
                    <div class="cluster term-act">
                        <button
                            type="button" class="btn btn--secondary btn--sm"
                            :disabled="!term.rendering || busy === `${term.namespace}:${term.term}`"
                            @click="submitTerm(term, 'approved')"
                        >
                            <Icon name="check" size="sm" /> {{ t('c_system.translation_review.btn_right', 'Right') }}
                        </button>
                        <button
                            type="button" class="btn btn--ghost btn--sm"
                            :disabled="!term.rendering || busy === `${term.namespace}:${term.term}`"
                            @click="submitTerm(term, 'rejected')"
                        >
                            <Icon name="x" size="sm" /> {{ t('c_system.translation_review.btn_wrong', 'Wrong') }}
                        </button>
                    </div>
                </template>
            </DataTable>
            <p v-if="unwordedTerms" class="citation">
                {{ t('c_system.translation_review.unworded', '{n} have no {name} wording at all, so every string using them was translated with nothing holding it consistent.', { named: { n: unwordedTerms, name: language.name } }) }}
            </p>
        </Card>

        <!-- ── THE REVIEW QUEUE ─────────────────────────────────────────────
             English source and machine draft side by side, always. -->
        <Card>
            <template #title>
                <h2>{{ t('c_system.translation_review.review_queue', 'Review queue — {label}', { named: { label: current.label ?? modality } }) }}</h2>
                <StatusBadge tone="info">
                    {{ t('c_system.translation_review.reviewing', 'You are reviewing {name}', { named: { name: language.name } }) }}
                </StatusBadge>
            </template>

            <p class="gloss">
                {{ t('c_system.translation_review.queue_gloss_a', 'Each string shows the English source and the machine\'s first draft. Accept a good one, or suggest a better wording. Enough verifications move it to') }}
                <strong>{{ t('c_system.translation_review.community_verified', 'community-verified') }}</strong>{{ t('c_system.translation_review.queue_gloss_b', ', then it publishes.') }}
                <template v-if="counts.quarantined">
                    {{ t('c_system.translation_review.queue_quarantined', 'Strings the machine itself refused to ship — a placeholder went missing, a legal citation changed, the meaning came back too short — are first.') }}
                </template>
            </p>

            <p v-if="!current.measurable" class="citation">
                {{ current.why }} {{ t('c_system.translation_review.nothing_here', 'There is nothing here to review yet.') }}
            </p>
            <p v-else-if="!queue.length" class="gloss">
                {{ t('c_system.translation_review.nothing_waiting', 'Nothing waiting in {label}. Every string here has enough agreement.', { named: { label: current.label } }) }}
            </p>

            <div v-for="item in queue" :key="rowKey(item)" class="review-row">
                <span class="rkey">
                    <span data-no-i18n>{{ item.namespace }}.{{ item.key }}</span>
                    ·
                    <StatusBadge :tone="byState[item.state]?.tone ?? 'neutral'">
                        {{ byState[item.state]?.label ?? item.state }}
                    </StatusBadge>
                    <StatusBadge v-if="item.quarantine_reason" tone="warning">
                        {{ t('c_system.translation_review.machine_refused', 'machine refused: {reason}', { named: { reason: item.quarantine_reason } }) }}
                    </StatusBadge>
                    <StatusBadge v-if="item.my_verdict" tone="info">
                        {{ t('c_system.translation_review.you_said', 'you said: {verdict}', { named: { verdict: item.my_verdict } }) }}
                    </StatusBadge>
                </span>

                <div class="review-src">
                    <span class="rlbl">{{ t('c_system.translation_review.src_label', 'Source (English)') }}</span>{{ item.english }}
                </div>

                <div class="review-draft">
                    <span class="rlbl">{{ t('c_system.translation_review.draft_label', 'Draft ({name})', { named: { name: language.name } }) }}</span>
                    <span v-if="!suggesting[rowKey(item)]" :dir="language.dir" :lang="language.code">
                        <template v-if="item.machine">{{ item.machine }}</template>
                        <em v-else class="gloss">{{ t('c_system.translation_review.no_draft', 'no draft — this string falls back to English') }}</em>
                    </span>
                    <textarea
                        v-else
                        class="review-input"
                        :value="draftFor(item)"
                        :dir="language.dir"
                        :lang="language.code"
                        rows="2"
                        :aria-label="t('c_system.translation_review.wording_aria', '{name} wording for {key}', { named: { name: language.name, key: item.key } })"
                        @input="drafts[rowKey(item)] = $event.target.value"
                    />
                </div>

                <div class="review-cta">
                    <div class="cluster">
                        <template v-if="canVerify">
                            <button
                                v-if="!suggesting[rowKey(item)]"
                                type="button" class="btn btn--secondary btn--sm"
                                :disabled="busy === rowKey(item) || !item.machine"
                                @click="submit(item, 'approved')"
                            >
                                <Icon name="check" size="sm" /> {{ t('c_system.translation_review.accept_draft', 'Accept draft') }}
                            </button>
                            <button
                                type="button" class="btn btn--ghost btn--sm"
                                :disabled="busy === rowKey(item)"
                                @click="suggesting[rowKey(item)]
                                    ? submit(item, 'edited')
                                    : (suggesting[rowKey(item)] = true)"
                            >
                                <Icon name="message-square" size="sm" />
                                {{ suggesting[rowKey(item)] ? t('c_system.translation_review.save_wording', 'Save my wording') : t('c_system.translation_review.suggest_change', 'Suggest a change') }}
                            </button>
                            <button
                                v-if="suggesting[rowKey(item)]"
                                type="button" class="btn btn--ghost btn--sm"
                                @click="suggesting[rowKey(item)] = false"
                            >
                                {{ t('c_system.translation_review.cancel', 'Cancel') }}
                            </button>
                            <button
                                v-else
                                type="button" class="btn btn--ghost btn--sm"
                                :disabled="busy === rowKey(item) || !item.machine"
                                @click="submit(item, 'rejected')"
                            >
                                <Icon name="x" size="sm" /> {{ t('c_system.translation_review.this_wrong', 'This is wrong') }}
                            </button>
                        </template>
                        <span class="citation" data-no-i18n>
                            {{ item.verifiers }} of {{ item.needed }} verifications
                        </span>
                    </div>
                </div>
            </div>
        </Card>

        <Card :title="t('c_system.translation_review.contributors_title', 'Contributors')">
            <p v-if="!contributors.length" class="gloss">
                {{ t('c_system.translation_review.nobody_verified', 'Nobody has verified {name} yet. Every string in this language is still exactly what a machine wrote.', { named: { name: language.name } }) }}
            </p>
            <div v-for="c in contributors" :key="c.handle" class="contributor-row">
                <span class="avatar" aria-hidden="true">{{ c.handle.slice(0, 2).toUpperCase() }}</span>
                <span class="contributor-name">{{ c.handle }}</span>
                <span class="cverified" data-no-i18n>{{ localeFmt.number(c.verified) }} verified</span>
            </div>
        </Card>
    </PageScaffold>
</template>

<style scoped>
.mbar { flex: 1 1 8rem; min-inline-size: 6rem; }
.review-cta { grid-column: 1 / -1; }
.review-input {
    inline-size: 100%;
    padding: var(--space-2, 0.5rem);
    border-radius: var(--radius-sm, 6px);
    border: 1px solid var(--gov-border-strong, rgba(255, 255, 255, 0.24));
    background: var(--gov-surface-2, rgba(255, 255, 255, 0.04));
    color: inherit;
    font: inherit;
    resize: vertical;
}
.contributor-name { flex: 1 1 8rem; min-inline-size: 0; color: var(--gov-fg); }
.term-act { gap: var(--space-1, 0.25rem); flex-wrap: nowrap; }
</style>
