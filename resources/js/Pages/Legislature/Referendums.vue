<script setup>
/**
 * Legislature/Referendums — FE-C9 (PHASE_C_DESIGN_frontend.md §B.9).
 *
 * Delegation FormCard (F-LEG-023 — the threshold field is READ-ONLY,
 * derived from the act type, never editable) → supermajority VoteTally →
 * queue ("queues to the next jurisdiction-wide ballot · WF-ELE-07") →
 * results with the per-row modify Btn — disabled with the CLK-19
 * citation for population-supermajority acts.
 */
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import FormCard from '@/Components/Surface/FormCard.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import Field from '@/Components/Ui/Field.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';
import StateStrip from '@/Components/Ui/StateStrip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import ThresholdMeter from '@/Components/Ui/ThresholdMeter.vue';
import VoteTally from '@/Components/Legislature/VoteTally.vue';
import VoteCastList from '@/Components/Legislature/VoteCastList.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });
const { t } = useI18n();

const props = defineProps({
    surface: { type: Object, required: true },
    legislature: { type: Object, required: true },
    machine: { type: Array, default: () => [] },
    pending: { type: Array, default: () => [] },
    queue: { type: Array, default: () => [] },
    results: { type: Array, default: () => [] },
    delegateForm: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
    urls: { type: Object, required: true },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

const formMeta = (id) => props.surface.forms.find((f) => f.id === id);
const bicameral = computed(() => props.legislature.mode === 'bicameral');

const ACT_TYPE_LABELS = {
    ordinary: t('c_legislature_pages_b.referendums.act_ordinary', 'Ordinary act — majority class'),
    setting_change: t('c_legislature_pages_b.referendums.act_setting_change', 'Setting-change act — majority class'),
    supermajority: t('c_legislature_pages_b.referendums.act_supermajority', 'Supermajority-class act'),
};

const THRESHOLD_LABELS = {
    majority: t('c_legislature_pages_b.referendums.threshold_majority', 'Majority of the population'),
    supermajority: t('c_legislature_pages_b.referendums.threshold_supermajority', 'Supermajority of the population (2/3)'),
};

/* ------------------------------------------------ delegate (F-LEG-023) -- */
const delegateForm = useForm({
    question: '',
    law_text: '',
    act_type: 'ordinary',
    targets_setting_key: '',
    proposed_value: '',
});

/* The threshold display is DERIVED from the act type — never editable. */
const derivedThreshold = computed(() => {
    const entry = props.delegateForm.actTypes.find((t) => t.value === delegateForm.act_type);
    return THRESHOLD_LABELS[entry?.threshold_derived ?? 'majority'];
});

function submitDelegation() {
    delegateForm
        .transform((data) => ({
            ...data,
            form_id: 'F-LEG-023',
            targets_setting_key: data.act_type === 'setting_change' ? data.targets_setting_key || null : null,
            proposed_value: data.act_type === 'setting_change' ? data.proposed_value || null : null,
        }))
        .post(props.urls.delegate, {
            preserveScroll: true,
            onSuccess: () => delegateForm.reset(),
        });
}

/* ------------------------------------------------------------- casting -- */
const casting = ref(null);
function cast(row, { value, explanation }) {
    casting.value = row.id;
    router.post(row.vote.cast_url, { value, explanation }, {
        preserveScroll: true,
        onFinish: () => {
            casting.value = null;
        },
    });
}

/* -------------------------------------------------- modify (F-LEG-034) -- */
const modifyTarget = ref(null);
const modifyForm = useForm({ text: '' });
function submitModify(row) {
    modifyForm
        .transform((data) => ({ ...data, form_id: 'F-LEG-034' }))
        .post(row.modify_url, {
            preserveScroll: true,
            onSuccess: () => {
                modifyForm.reset();
                modifyTarget.value = null;
            },
        });
}

const ORIGIN_LABELS = {
    delegation: t('c_legislature_pages_b.referendums.origin_delegation', 'Delegated by the chamber'),
    petition: t('c_legislature_pages_b.referendums.origin_petition', 'Citizen petition'),
};
</script>

<template>
    <PageScaffold :surface="surface" :title="t('c_legislature_pages_b.referendums.page_title', { name: legislature.name })">
        <template #intro>
            {{ t('c_legislature_pages_b.referendums.intro', 'The legislature can put any issue to the population by supermajority. The passage threshold is not chosen — it matches the kind of act: a question the legislature could pass by simple majority resolves by a majority of the population; one that would need a supermajority resolves by a population supermajority.') }}
        </template>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <!-- ==================================== delegation (F-LEG-023) === -->
        <FormCard
            v-if="can.delegate && formMeta('F-LEG-023')"
            :form="formMeta('F-LEG-023')"
            :inertia-form="delegateForm"
            :submit-label="t('c_legislature_pages_b.referendums.delegate_submit', 'Delegate to referendum')"
            @submit="submitDelegation"
        >
            <Field :label="t('c_legislature_pages_b.referendums.question_label', 'Question put to the population')" :error="delegateForm.errors.question" required>
                <template #control="{ id, invalid, describedBy }">
                    <textarea
                        :id="id"
                        v-model="delegateForm.question"
                        class="field-input"
                        rows="2"
                        :placeholder="t('c_legislature_pages_b.referendums.question_placeholder', { name: legislature.jurisdiction?.name ?? t('c_legislature_pages_b.referendums.this_jurisdiction', 'this jurisdiction') })"
                        :aria-invalid="invalid ? 'true' : undefined"
                        :aria-describedby="describedBy"
                    ></textarea>
                </template>
            </Field>
            <Field
                :label="t('c_legislature_pages_b.referendums.law_text_label', 'Law text')"
                :hint="t('c_legislature_pages_b.referendums.law_text_hint', 'The binding text the referendum enacts — not a summary.')"
                :error="delegateForm.errors.law_text"
                required
            >
                <template #control="{ id, invalid, describedBy }">
                    <textarea
                        :id="id"
                        v-model="delegateForm.law_text"
                        class="field-input"
                        rows="3"
                        :aria-invalid="invalid ? 'true' : undefined"
                        :aria-describedby="describedBy"
                    ></textarea>
                </template>
            </Field>
            <div class="grid-2">
                <Field :label="t('c_legislature_pages_b.referendums.act_type_label', 'Act type')" :error="delegateForm.errors.act_type">
                    <template #control="{ id }">
                        <select :id="id" v-model="delegateForm.act_type" class="select">
                            <option v-for="tp in props.delegateForm.actTypes" :key="tp.value" :value="tp.value">
                                {{ ACT_TYPE_LABELS[tp.value] ?? tp.value }}
                            </option>
                        </select>
                    </template>
                </Field>
                <div class="field">
                    <span class="field-label" id="ref-threshold-label">{{ t('c_legislature_pages_b.referendums.pop_threshold_label', 'Population threshold — fixed by act type') }}</span>
                    <p class="amendable" aria-labelledby="ref-threshold-label" style="margin-block: var(--space-2) 0">
                        <span class="amendable-value">{{ derivedThreshold }}</span>
                        <span class="amendable-meta">{{ t('c_legislature_pages_b.referendums.threshold_meta', 'derived from the act type — never editable · matches the legislative equivalent · Art. II §6') }}</span>
                    </p>
                </div>
            </div>
            <template v-if="delegateForm.act_type === 'setting_change'">
                <div class="grid-2">
                    <Field :label="t('c_legislature_pages_b.referendums.setting_key_label', 'Setting key')" :error="delegateForm.errors.targets_setting_key" required>
                        <template #control="{ id, invalid, describedBy }">
                            <input
                                :id="id"
                                v-model="delegateForm.targets_setting_key"
                                class="field-input"
                                type="text"
                                data-no-i18n
                                placeholder="e.g. election_interval_months"
                                :aria-invalid="invalid ? 'true' : undefined"
                                :aria-describedby="describedBy"
                            />
                        </template>
                    </Field>
                    <Field
                        :label="t('c_legislature_pages_b.referendums.proposed_value_label', 'Proposed value')"
                        :hint="t('c_legislature_pages_b.referendums.proposed_value_hint', 'Bounds-checked PRE-VOTE through the protected validator — out-of-range is rejected before any vote.')"
                        :error="delegateForm.errors.proposed_value"
                        required
                    >
                        <template #control="{ id, invalid, describedBy }">
                            <input
                                :id="id"
                                v-model="delegateForm.proposed_value"
                                class="field-input"
                                type="text"
                                data-no-i18n
                                :aria-invalid="invalid ? 'true' : undefined"
                                :aria-describedby="describedBy"
                            />
                        </template>
                    </Field>
                </div>
            </template>
            <p class="citation" style="margin-block-end: var(--space-2)">
                {{ t('c_legislature_pages_b.referendums.requires_supermajority', { supermajority: legislature.supermajority, serving: legislature.serving }) }}
            </p>
        </FormCard>
        <Card v-else as="section" :title="t('c_legislature_pages_b.referendums.delegate_card_title', 'Delegate a question')">
            <p class="gloss">
                {{ t('c_legislature_pages_b.referendums.delegate_gloss_before', 'Delegation is filed by a serving member (F-LEG-023, R-09) — the resolution itself needs a supermajority of all serving. Any resident reads this register; petitions reach the same ballot through') }} <a href="/civic/petitions">{{ t('c_legislature_pages_b.referendums.petition_path', 'the petition path') }}</a>.
            </p>
        </Card>

        <!-- ==================================== pending votes ============ -->
        <Card v-if="pending.length" as="section" :title="t('c_legislature_pages_b.referendums.pending_title', 'Open delegation & modification votes')">
            <div class="stack" style="gap: var(--space-3)">
                <Card v-for="row in pending" :key="row.id" inset>
                    <p style="margin-block-end: var(--space-1)">
                        <strong>{{ row.label }}</strong>
                        <span v-if="row.threshold_derived" class="citation"> · {{ THRESHOLD_LABELS[row.threshold_derived] }} {{ t('c_legislature_pages_b.referendums.on_the_ballot', 'on the ballot') }}</span>
                    </p>
                    <template v-if="row.vote">
                        <VoteTally
                            v-bind="row.vote.tally"
                            basis="Art. II §6"
                            :can-cast="can.delegate && row.vote.open && !row.vote.my_cast"
                            :casting="casting === row.id"
                            @cast="cast(row, $event)"
                        />
                        <details v-if="row.vote.casts.length" style="margin-block-start: var(--space-2)">
                            <summary class="cc-small" style="cursor: pointer">{{ t('c_legislature_pages_b.referendums.published_casts', { n: row.vote.casts.length }) }}</summary>
                            <VoteCastList :casts="row.vote.casts" :group-by-kind="bicameral" />
                        </details>
                    </template>
                </Card>
            </div>
        </Card>

        <!-- ==================================== queue ==================== -->
        <Card as="section" :title="t('c_legislature_pages_b.referendums.queue_title', 'Queued for the next jurisdiction-wide ballot')">
            <div v-if="queue.length" class="stack" style="gap: var(--space-3)">
                <Card v-for="item in queue" :key="item.id" inset>
                    <strong>{{ item.question }}</strong>
                    <div class="cluster" style="margin-block-start: var(--space-2)">
                        <StatusBadge :tone="item.status === 'scheduled' ? 'success' : 'info'" icon="clock">
                            {{ item.status === 'scheduled' ? t('c_legislature_pages_b.referendums.scheduled', 'Scheduled') : t('c_legislature_pages_b.referendums.queued_badge', 'Queued — next jurisdiction-wide ballot') }}
                        </StatusBadge>
                        <span class="citation">
                            {{ THRESHOLD_LABELS[item.threshold] }} · {{ ORIGIN_LABELS[item.origin] ?? item.origin }}
                            <template v-if="item.via.petition_href">
                                · <a :href="item.via.petition_href">{{ t('c_legislature_pages_b.referendums.petition_link', 'petition →') }}</a>
                            </template>
                        </span>
                        <a v-if="item.election" :href="item.election.href" class="citation">{{ item.election.label }} →</a>
                    </div>
                </Card>
            </div>
            <p v-else class="cc-small gloss">{{ t('c_legislature_pages_b.referendums.nothing_queued', 'Nothing queued — a delegated or petition-validated question rides the next jurisdiction-wide ballot.') }}</p>

            <StateStrip
                :states="machine"
                :current="queue[0]?.status ?? null"
                :aria-label="t('c_legislature_pages_b.referendums.state_machine_aria', 'Referendum question state machine')"
                style="margin-block-start: var(--space-3)"
            />
            <p class="citation" style="margin-block-start: var(--space-2)">
                {{ t('c_legislature_pages_b.referendums.queue_cite', 'Questions ride the next jurisdiction-wide ballot · WF-ELE-07 · ballots cast via Referendum vote · F-IND-008') }}
            </p>
        </Card>

        <!-- ==================================== results ================== -->
        <Card as="section" :title="t('c_legislature_pages_b.referendums.results_title', 'Resolved questions — results at the matching threshold')">
            <div v-if="results.length" class="stack" style="gap: var(--space-4)">
                <div v-for="row in results" :key="row.id">
                    <div class="cluster" style="justify-content: space-between">
                        <strong style="color: var(--gov-fg)">{{ row.title }}</strong>
                        <StatusBadge v-if="row.shielded" tone="warning" icon="lock">
                            {{ t('c_legislature_pages_b.referendums.shielded_badge', 'Population-supermajority act · shielded this term · CLK-19') }}
                        </StatusBadge>
                        <StatusBadge v-else-if="row.passed" tone="neutral" icon="check">
                            {{ row.lapsed ? t('c_legislature_pages_b.referendums.lapsed_badge', 'Ordinary law — protection lapsed (WF-LEG-18)') : t('c_legislature_pages_b.referendums.majority_badge', 'Majority act · same-term changes need a supermajority') }}
                        </StatusBadge>
                        <StatusBadge v-else tone="danger" icon="x">{{ t('c_legislature_pages_b.referendums.failed_badge', 'Failed at the population threshold') }}</StatusBadge>
                    </div>

                    <ThresholdMeter
                        :value="row.yes"
                        :max="row.eligible"
                        :threshold="null"
                        :label="t('c_legislature_pages_b.referendums.yes_votes_label', { title: row.title })"
                        style="margin-block-start: var(--space-2)"
                    >
                        {{ t('c_legislature_pages_b.referendums.yes_summary', { pct: row.yes_pct, yes: row.yes.toLocaleString(), eligible: row.eligible.toLocaleString() }) }}
                        <template #note>
                            {{ t('c_legislature_pages_b.referendums.threshold_note', { threshold: THRESHOLD_LABELS[row.threshold].toLowerCase() }) }}
                        </template>
                    </ThresholdMeter>

                    <div class="cluster" style="margin-block-start: var(--space-2)">
                        <a v-if="row.law" :href="row.law.href" class="citation" data-no-i18n>{{ row.law.act_number }} →</a>
                        <template v-if="row.law && can.modify">
                            <Btn
                                v-if="row.modifiable"
                                variant="secondary"
                                size="sm"
                                @click="modifyTarget = modifyTarget === row.id ? null : row.id"
                            >{{ t('c_legislature_pages_b.referendums.propose_modification', 'Propose modification') }}</Btn>
                            <Btn
                                v-else
                                variant="secondary"
                                size="sm"
                                disabled
                                :title="t('c_legislature_pages_b.referendums.blocked_note', 'Blocked this term · passed by population supermajority · CLK-19 · hardened')"
                            >{{ t('c_legislature_pages_b.referendums.propose_modification', 'Propose modification') }}</Btn>
                            <span v-if="!row.modifiable" class="citation">
                                {{ t('c_legislature_pages_b.referendums.blocked_note', 'Blocked this term · passed by population supermajority · CLK-19 · hardened') }}
                                <template v-if="row.shield_expires_with"> · {{ t('c_legislature_pages_b.referendums.shield_lapses', { label: row.shield_expires_with.election_label }) }}</template>
                            </span>
                            <span v-else class="citation">{{ t('c_legislature_pages_b.referendums.this_term_note', 'this term: chamber supermajority · F-LEG-034 · WF-LEG-19') }}</span>
                        </template>
                    </div>

                    <div v-if="modifyTarget === row.id" class="stack" style="gap: var(--space-2); margin-block-start: var(--space-2)">
                        <Field :label="t('c_legislature_pages_b.referendums.replacement_label', 'Replacement law text')" :error="modifyForm.errors.text ?? modifyForm.errors.constitution" required>
                            <template #control="{ id, invalid, describedBy }">
                                <textarea
                                    :id="id"
                                    v-model="modifyForm.text"
                                    class="field-input"
                                    rows="3"
                                    :aria-invalid="invalid ? 'true' : undefined"
                                    :aria-describedby="describedBy"
                                ></textarea>
                            </template>
                        </Field>
                        <Btn
                            variant="primary"
                            size="sm"
                            :disabled="modifyForm.processing || !modifyForm.text.trim()"
                            @click="submitModify(row)"
                        >{{ t('c_legislature_pages_b.referendums.file_modification', 'File modification vote (F-LEG-034)') }}</Btn>
                    </div>
                </div>
            </div>
            <p v-else class="cc-small gloss">{{ t('c_legislature_pages_b.referendums.no_resolved', 'No questions have resolved yet.') }}</p>

            <p class="citation" style="margin-block-start: var(--space-3)">
                <HardenedChip>{{ t('c_legislature_pages_b.referendums.shielded_chip', 'population-supermajority acts shielded from same-term modification · CLK-19') }}</HardenedChip>
                {{ ' ' }}
                {{ t('c_legislature_pages_b.referendums.convert_note', 'Art. II §6 — all referendum acts convert to ordinary law after the next general election certifies.') }}
            </p>
        </Card>

        <template #about>
            <p>
                {{ t('c_legislature_pages_b.referendums.about', 'The CLK-19 shield is a validator gate, not a timer: an F-LEG-034 filing against a population-supermajority act is rejected pre-vote with the citation, and the rejection itself lands on the audit chain. The shield releases when the next general election certifies — referendum acts then amend through the ordinary path.') }}
            </p>
        </template>
    </PageScaffold>
</template>
