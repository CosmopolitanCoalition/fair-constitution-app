<script setup>
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
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import Icon from '@/Components/Ui/Icon.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import DataTable from '@/Components/Ui/DataTable.vue';

defineOptions({ layout: AppShellV2 });

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

const settledTerms = computed(() => props.terms.filter((t) => t.settled).length);
/* Distinct from "not settled": a term can have a machine's wording and still be
   unsettled. This counts the ones with no wording at all. */
const unwordedTerms = computed(() => props.terms.filter((t) => !t.rendering).length);

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
                }}<template v-if="rtl"> · right-to-left</template> · {{ row.overall }}% overall
            </span>
            <br />
            The machine writes the first round. You correct it. Enough verifications move a string
            to <strong>community-verified</strong> — {{ quorum }} readers, not one, so no single
            person's reading settles what the constitution says in this language.
        </template>

        <!-- who may verify, and why it is not a privilege -->
        <div class="route-note">
            <Icon name="users" size="sm" />
            <div v-if="canVerify">
                <strong>You can verify {{ language.name }}.</strong>
                Verification is gated to the people who actually read a language — not a central
                team, and not machine translation grading itself.
            </div>
            <div v-else>
                <strong>You are reading this queue, not ruling on it.</strong>
                Verification is gated to the people who read {{ language.name }} — someone who cannot
                read a language cannot usefully confirm it. This takes nothing from you: name
                {{ language.name }} among your languages on your record and you can start immediately.
            </div>
        </div>

        <!-- ── THE SIX KINDS ────────────────────────────────────────────────
             Six rows, not one percentage. A language at 99% on interface
             strings with no dubbed video is not "99% translated", and one
             number would say it was. -->
        <Card title="By content type" eyebrow="six kinds of content">
            <p class="gloss">
                Pick a content type to review it. Interface and page copy cover the screens;
                audio and captions cover the guide videos.
                <strong>{{ row.overall }}%</strong> overall is the mean of all six — the empty ones
                count, which is the point of splitting them.
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
        <Card :title="`Constitutional terms — ${settledTerms} of ${terms.length} settled`">
            <p class="gloss">
                These words carry legal weight and must read the same everywhere. Settle them first:
                every string containing one inherits the choice, and re-wording one later unsettles
                every verdict that was given against the old wording.
                <strong>Settled means {{ quorum }} readers agreed</strong> — a rendering a machine
                proposed is a draft here too.
            </p>
            <DataTable
                :columns="[
                    { key: 'term', label: 'Term (English)', mono: true },
                    { key: 'rendering', label: `In ${language.name}` },
                    { key: 'state', label: 'State' },
                    { key: 'context', label: 'What it means here' },
                    ...(canVerify ? [{ key: 'act', label: 'Is this right?' }] : []),
                ]"
                :rows="terms"
                row-key="term"
                caption="Constitutional vocabulary for this language"
            >
                <template #cell-rendering="{ row: t }">
                    <span v-if="t.rendering" :dir="language.dir" :lang="language.code">{{ t.rendering }}</span>
                    <em v-else class="gloss">no wording yet</em>
                </template>
                <template #cell-state="{ row: t }">
                    <StatusBadge :tone="byState[t.state]?.tone ?? 'neutral'">
                        {{ byState[t.state]?.label ?? t.state }}
                    </StatusBadge>
                    <span class="citation" data-no-i18n> {{ t.verifiers }}/{{ t.needed }}</span>
                    <StatusBadge v-if="t.my_verdict" tone="info">you said: {{ t.my_verdict }}</StatusBadge>
                </template>
                <template #cell-act="{ row: t }">
                    <div class="cluster term-act">
                        <button
                            type="button" class="btn btn--secondary btn--sm"
                            :disabled="!t.rendering || busy === `${t.namespace}:${t.term}`"
                            @click="submitTerm(t, 'approved')"
                        >
                            <Icon name="check" size="sm" /> Right
                        </button>
                        <button
                            type="button" class="btn btn--ghost btn--sm"
                            :disabled="!t.rendering || busy === `${t.namespace}:${t.term}`"
                            @click="submitTerm(t, 'rejected')"
                        >
                            <Icon name="x" size="sm" /> Wrong
                        </button>
                    </div>
                </template>
            </DataTable>
            <p v-if="unwordedTerms" class="citation">
                {{ unwordedTerms }} have no {{ language.name }} wording at all, so every string using
                them was translated with nothing holding it consistent.
            </p>
        </Card>

        <!-- ── THE REVIEW QUEUE ─────────────────────────────────────────────
             English source and machine draft side by side, always. -->
        <Card>
            <template #title>
                <h2>Review queue — {{ current.label ?? modality }}</h2>
                <StatusBadge tone="info">
                    You are reviewing {{ language.name }}
                </StatusBadge>
            </template>

            <p class="gloss">
                Each string shows the English source and the machine's first draft. Accept a good
                one, or suggest a better wording. Enough verifications move it to
                <strong>community-verified</strong>, then it publishes.
                <template v-if="counts.quarantined">
                    Strings the machine itself refused to ship — a placeholder went missing, a legal
                    citation changed, the meaning came back too short — are first.
                </template>
            </p>

            <p v-if="!current.measurable" class="citation">
                {{ current.why }} There is nothing here to review yet.
            </p>
            <p v-else-if="!queue.length" class="gloss">
                Nothing waiting in {{ current.label }}. Every string here has enough agreement.
            </p>

            <div v-for="item in queue" :key="rowKey(item)" class="review-row">
                <span class="rkey">
                    <span data-no-i18n>{{ item.namespace }}.{{ item.key }}</span>
                    ·
                    <StatusBadge :tone="byState[item.state]?.tone ?? 'neutral'">
                        {{ byState[item.state]?.label ?? item.state }}
                    </StatusBadge>
                    <StatusBadge v-if="item.quarantine_reason" tone="warning">
                        machine refused: {{ item.quarantine_reason }}
                    </StatusBadge>
                    <StatusBadge v-if="item.my_verdict" tone="info">
                        you said: {{ item.my_verdict }}
                    </StatusBadge>
                </span>

                <div class="review-src">
                    <span class="rlbl">Source (English)</span>{{ item.english }}
                </div>

                <div class="review-draft">
                    <span class="rlbl">Draft ({{ language.name }})</span>
                    <span v-if="!suggesting[rowKey(item)]" :dir="language.dir" :lang="language.code">
                        <template v-if="item.machine">{{ item.machine }}</template>
                        <em v-else class="gloss">no draft — this string falls back to English</em>
                    </span>
                    <textarea
                        v-else
                        class="review-input"
                        :value="draftFor(item)"
                        :dir="language.dir"
                        :lang="language.code"
                        rows="2"
                        :aria-label="`${language.name} wording for ${item.key}`"
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
                                <Icon name="check" size="sm" /> Accept draft
                            </button>
                            <button
                                type="button" class="btn btn--ghost btn--sm"
                                :disabled="busy === rowKey(item)"
                                @click="suggesting[rowKey(item)]
                                    ? submit(item, 'edited')
                                    : (suggesting[rowKey(item)] = true)"
                            >
                                <Icon name="message-square" size="sm" />
                                {{ suggesting[rowKey(item)] ? 'Save my wording' : 'Suggest a change' }}
                            </button>
                            <button
                                v-if="suggesting[rowKey(item)]"
                                type="button" class="btn btn--ghost btn--sm"
                                @click="suggesting[rowKey(item)] = false"
                            >
                                Cancel
                            </button>
                            <button
                                v-else
                                type="button" class="btn btn--ghost btn--sm"
                                :disabled="busy === rowKey(item) || !item.machine"
                                @click="submit(item, 'rejected')"
                            >
                                <Icon name="x" size="sm" /> This is wrong
                            </button>
                        </template>
                        <span class="citation" data-no-i18n>
                            {{ item.verifiers }} of {{ item.needed }} verifications
                        </span>
                    </div>
                </div>
            </div>
        </Card>

        <Card title="Contributors">
            <p v-if="!contributors.length" class="gloss">
                Nobody has verified {{ language.name }} yet. Every string in this language is still
                exactly what a machine wrote.
            </p>
            <div v-for="c in contributors" :key="c.handle" class="contributor-row">
                <span class="avatar" aria-hidden="true">{{ c.handle.slice(0, 2).toUpperCase() }}</span>
                <span class="contributor-name">{{ c.handle }}</span>
                <span class="cverified" data-no-i18n>{{ c.verified.toLocaleString() }} verified</span>
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
