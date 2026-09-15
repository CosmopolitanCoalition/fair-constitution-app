<script setup>
/**
 * Legislature/EmergencyPowers — FE-C9 (PHASE_C_DESIGN_frontend.md §B.10).
 *
 * Invoke FormCard (F-LEG-024 — cause is a CLOSED two-option enum, the
 * form renders no third option; duration carries the pre-vote engine
 * validation) → supermajority VoteTally → per-active-power card with the
 * CLK-03 countdown ("auto-expires — no action required; nothing rolls
 * over silently"), renewal window gating (F-LEG-025), judicial-review
 * panel (F-JDG-007 — Planned · Phase E) → hard-rails card → expired
 * register. The rails render even when nothing is active — that is the
 * point.
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
import RadioGroup from '@/Components/Ui/RadioGroup.vue';
import Stat from '@/Components/Ui/Stat.vue';
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
    active: { type: Array, default: () => [] },
    expired: { type: Array, default: () => [] },
    invokeForm: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
    urls: { type: Object, required: true },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

const formMeta = (id) => props.surface.forms.find((f) => f.id === id);
const bicameral = computed(() => props.legislature.mode === 'bicameral');

/* The closed cause enum — exactly two options, by construction (Art. II §7). */
const CAUSE_LABELS = {
    natural_disaster: t('c_legislature_pages.emergency_powers.cause_natural_disaster', 'Natural disaster'),
    actual_invasion: t('c_legislature_pages.emergency_powers.cause_actual_invasion', 'Actual invasion'),
};
const causeOptions = computed(() =>
    props.invokeForm.causes.map((cause) => ({ value: cause, label: CAUSE_LABELS[cause] ?? cause })),
);

/* -------------------------------------------------- invoke (F-LEG-024) -- */
const invoke = useForm({
    cause: 'natural_disaster',
    label: '',
    duration_days: 30,
    area_jurisdiction_id: props.invokeForm.areaOptions[0]?.id ?? null,
    methods: '',
});

/* Pre-vote engine validation mirrors inline; the ENGINE is the boundary —
   a direct POST above the ceiling gets the verbatim 422. */
const durationError = computed(() => {
    const n = Number(invoke.duration_days);
    if (Number.isFinite(n) && (n < 1 || n > props.invokeForm.maxDays)) {
        return t('c_legislature_pages.emergency_powers.duration_error', { max: props.invokeForm.maxDays });
    }
    /* The engine's verbatim rejection (duration is its only numeric gate). */
    return invoke.errors.duration_days ?? constitutionError.value ?? null;
});

function submitInvoke() {
    invoke
        .transform((data) => ({ ...data, form_id: 'F-LEG-024', duration_days: Number(data.duration_days) }))
        .post(props.urls.invoke, {
            preserveScroll: true,
            onSuccess: () => invoke.reset(),
        });
}

/* --------------------------------------------------- renew (F-LEG-025) -- */
const renewTarget = ref(null);
const renewForm = useForm({ extension_days: 30 });
function submitRenew(power) {
    renewForm
        .transform((data) => ({ ...data, form_id: 'F-LEG-025', extension_days: Number(data.extension_days) }))
        .post(power.renew_url, {
            preserveScroll: true,
            onSuccess: () => {
                renewForm.reset();
                renewTarget.value = null;
            },
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

function expiresDate(iso) {
    return iso ? new Date(iso).toLocaleDateString() : '—';
}
</script>

<template>
    <PageScaffold :surface="surface" :title="t('c_legislature_pages.emergency_powers.title', { name: legislature.name })">
        <template #intro>
            {{ t('c_legislature_pages.emergency_powers.intro', 'Emergency powers exist for exactly two causes — natural disaster and actual invasion. They require a supermajority, run at most 90 days, are judicially reviewable at any time, auto-expire, and can never disrupt elections, sessions, or courts. Any active power is the first order of business at every session.') }}
        </template>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <!-- ==================================== active dashboard ========= -->
        <template v-if="active.length">
            <Card v-for="power in active" :key="power.id" as="section" :title="t('c_legislature_pages.emergency_powers.active_title', { label: power.label })">
                <StateStrip :states="machine" :current="power.status" :aria-label="t('c_legislature_pages.emergency_powers.machine_aria', 'Emergency powers state machine')" />

                <div class="cluster" style="gap: var(--space-6); margin-block-start: var(--space-3)">
                    <Stat :value="power.day" :label="t('c_legislature_pages.emergency_powers.stat_day', { max: power.max_days })" accent />
                    <Stat
                        :value="Math.max(0, power.max_days - power.day)"
                        :label="t('c_legislature_pages.emergency_powers.stat_expiry', { date: expiresDate(power.expires_at) })"
                    />
                </div>

                <ThresholdMeter
                    :value="power.day"
                    :max="power.max_days"
                    :threshold="power.max_days"
                    :label="t('c_legislature_pages.emergency_powers.meter_label', 'CLK-03 countdown')"
                    style="margin-block-start: var(--space-3)"
                >
                    {{ t('c_legislature_pages.emergency_powers.meter_text', { day: power.day, max: power.max_days }) }}
                    <template #note>{{ t('c_legislature_pages.emergency_powers.meter_note', 'hard ceiling: 90 days · CLK-03 · Art. II §7') }}</template>
                </ThresholdMeter>

                <p class="cc-small" style="margin-block-start: var(--space-3)">
                    {{ t('c_legislature_pages.emergency_powers.label_cause', 'cause:') }} <strong>{{ CAUSE_LABELS[power.cause] ?? power.cause }}</strong>
                    {{ t('c_legislature_pages.emergency_powers.label_area', '· area:') }}
                    <a v-if="power.area.geom_href" :href="power.area.geom_href">{{ power.area.label }}</a>
                    <template v-else>{{ power.area.label }}</template>
                    {{ t('c_legislature_pages.emergency_powers.label_methods', '· methods:') }} {{ power.methods }}
                </p>

                <template v-if="power.invoke_vote">
                    <h3 style="font-size: var(--text-base); margin-block-start: var(--space-3)">{{ t('c_legislature_pages.emergency_powers.invoke_vote_record', 'Invocation vote record (F-LEG-024)') }}</h3>
                    <VoteTally v-bind="power.invoke_vote" basis="Art. II §7" />
                </template>

                <!-- renewal panel (F-LEG-025) -->
                <Card inset style="margin-block-start: var(--space-3)">
                    <h3 style="font-size: var(--text-base)">
                        {{ t('c_legislature_pages.emergency_powers.renewal', 'Renewal') }}
                        <StatusBadge :tone="power.renewal_window.open_now ? 'warning' : 'neutral'" icon="clock">
                            {{ power.renewal_window.open_now
                                ? t('c_legislature_pages.emergency_powers.renewal_open', 'Renewal window open')
                                : t('c_legislature_pages.emergency_powers.renewal_opens', { day: power.renewal_window.opens_day, at: power.renewal_window.opens_at }) }}
                        </StatusBadge>
                    </h3>
                    <p class="citation">
                        {{ t('c_legislature_pages.emergency_powers.renewal_window', { day: power.renewal_window.opens_day, max: invokeForm.maxDays }) }}
                    </p>
                    <ul v-if="power.renewals.length" class="cc-small" style="margin-block: var(--space-2)">
                        <li v-for="(renewal, ri) in power.renewals" :key="ri">
                            {{ t('c_legislature_pages.emergency_powers.renewal_line', { days: renewal.extension_days, summary: renewal.vote_summary }) }}
                        </li>
                    </ul>
                    <template v-if="can.renew">
                        <Btn
                            variant="secondary"
                            size="sm"
                            :disabled="!power.renewal_window.open_now"
                            :title="power.renewal_window.open_now ? undefined : t('c_legislature_pages.emergency_powers.renewal_early', { at: power.renewal_window.opens_at })"
                            @click="renewTarget = renewTarget === power.id ? null : power.id"
                        >{{ t('c_legislature_pages.emergency_powers.propose_renewal', 'Propose renewal (F-LEG-025)') }}</Btn>
                        <div v-if="renewTarget === power.id" class="stack" style="gap: var(--space-2); margin-block-start: var(--space-2)">
                            <Field
                                :label="t('c_legislature_pages.emergency_powers.extension_label', 'Extension (days)')"
                                :hint="t('c_legislature_pages.emergency_powers.extension_hint', { max: invokeForm.maxDays })"
                                :error="renewForm.errors.extension_days"
                                required
                            >
                                <template #control="{ id, invalid, describedBy }">
                                    <input
                                        :id="id"
                                        v-model="renewForm.extension_days"
                                        class="field-input"
                                        type="number"
                                        min="1"
                                        :max="invokeForm.maxDays"
                                        :aria-invalid="invalid ? 'true' : undefined"
                                        :aria-describedby="describedBy"
                                    />
                                </template>
                            </Field>
                            <Btn variant="primary" size="sm" :disabled="renewForm.processing" @click="submitRenew(power)">
                                {{ t('c_legislature_pages.emergency_powers.put_renewal', 'Put renewal to a vote') }}
                            </Btn>
                        </div>
                    </template>
                </Card>

                <!-- judicial review panel (F-JDG-007) -->
                <Card inset style="margin-block-start: var(--space-3)">
                    <h3 style="font-size: var(--text-base)">{{ t('c_legislature_pages.emergency_powers.judicial_review', 'Judicial review (F-JDG-007)') }}</h3>
                    <div class="cluster">
                        <StatusBadge :tone="power.judicial_review === 'pending' ? 'warning' : 'neutral'" icon="scale">
                            {{ power.judicial_review === 'pending' ? t('c_legislature_pages.emergency_powers.review_pending', 'Review pending') : t('c_legislature_pages.emergency_powers.review_none', 'No review in progress') }}
                        </StatusBadge>
                        <span class="citation">
                            {{ t('c_legislature_pages.emergency_powers.review_cite', 'available at any time, by any inhabitant · filing arrives with the judiciary (Planned · Phase E) · Art. II §7 · WF-JUD-06') }}
                        </span>
                    </div>
                </Card>
            </Card>
        </template>
        <Card v-else as="section" :title="t('c_legislature_pages.emergency_powers.dashboard_title', 'Active power dashboard')">
            <div class="cluster">
                <StatusBadge tone="success" icon="check">{{ t('c_legislature_pages.emergency_powers.no_active', 'No active emergency powers') }}</StatusBadge>
                <span class="citation">
                    {{ active.length === 0 && expired.length === 0
                        ? t('c_legislature_pages.emergency_powers.never_invoked', { name: legislature.jurisdiction?.name ?? t('c_legislature_pages.emergency_powers.this_jurisdiction_lower', 'this jurisdiction') })
                        : t('c_legislature_pages.emergency_powers.none_in_force', { name: legislature.jurisdiction?.name ?? t('c_legislature_pages.emergency_powers.this_jurisdiction_upper', 'This jurisdiction') }) }}
                </span>
            </div>
        </Card>

        <!-- ==================================== pending votes ============ -->
        <Card v-if="pending.length" as="section" :title="t('c_legislature_pages.emergency_powers.pending_title', 'Open invocation & renewal votes')">
            <div class="stack" style="gap: var(--space-3)">
                <Card v-for="row in pending" :key="row.id" inset>
                    <p style="margin-block-end: var(--space-1)">
                        <strong>{{ row.label }}</strong>
                        <span class="citation"> · {{ row.summary }}</span>
                    </p>
                    <template v-if="row.vote">
                        <VoteTally
                            v-bind="row.vote.tally"
                            basis="Art. II §7"
                            :can-cast="can.invoke && row.vote.open && !row.vote.my_cast"
                            :casting="casting === row.id"
                            @cast="cast(row, $event)"
                        />
                        <details v-if="row.vote.casts.length" style="margin-block-start: var(--space-2)">
                            <summary class="cc-small" style="cursor: pointer">{{ t('c_legislature_pages.emergency_powers.published_casts', { count: row.vote.casts.length }) }}</summary>
                            <VoteCastList :casts="row.vote.casts" :group-by-kind="bicameral" />
                        </details>
                    </template>
                </Card>
            </div>
        </Card>

        <!-- ==================================== invoke (F-LEG-024) ======= -->
        <FormCard
            v-if="can.invoke && formMeta('F-LEG-024')"
            :form="formMeta('F-LEG-024')"
            :inertia-form="invoke"
            :submit-label="t('c_legislature_pages.emergency_powers.put_invocation', 'Put invocation to a vote')"
            @submit="submitInvoke"
        >
            <div class="grid-2">
                <RadioGroup
                    v-model="invoke.cause"
                    :label="t('c_legislature_pages.emergency_powers.cause_label', 'Cause — the only two the engine accepts')"
                    :options="causeOptions"
                />
                <Field
                    :label="t('c_legislature_pages.emergency_powers.duration_label', 'Duration (days)')"
                    :hint="t('c_legislature_pages.emergency_powers.duration_hint', { max: invokeForm.maxDays })"
                    :error="durationError"
                    required
                >
                    <template #control="{ id, invalid, describedBy }">
                        <input
                            :id="id"
                            v-model="invoke.duration_days"
                            class="field-input"
                            type="number"
                            min="1"
                            :max="invokeForm.maxDays"
                            :aria-invalid="invalid ? 'true' : undefined"
                            :aria-describedby="describedBy"
                        />
                    </template>
                </Field>
            </div>
            <p class="gloss" style="margin-block-start: calc(-1 * var(--space-2)); margin-block-end: var(--space-3)">
                {{ t('c_legislature_pages.emergency_powers.no_other_cause', 'No other cause exists. Economic, political, or public-order rationales are rejected pre-vote.') }}
            </p>
            <Field :label="t('c_legislature_pages.emergency_powers.name_label', 'Name the emergency')" :error="invoke.errors.label" required>
                <template #control="{ id, invalid, describedBy }">
                    <input
                        :id="id"
                        v-model="invoke.label"
                        class="field-input"
                        type="text"
                        :placeholder="t('c_legislature_pages.emergency_powers.name_placeholder', 'e.g. River Ausa flooding')"
                        :aria-invalid="invalid ? 'true' : undefined"
                        :aria-describedby="describedBy"
                    />
                </template>
            </Field>
            <div class="grid-2">
                <Field
                    :label="t('c_legislature_pages.emergency_powers.area_label', 'Area')"
                    :hint="t('c_legislature_pages.emergency_powers.area_hint', 'Engine validation: area ≤ this legislature\'s authority — own or descendant jurisdictions only.')"
                    :error="invoke.errors.area_jurisdiction_id"
                >
                    <template #control="{ id }">
                        <select :id="id" v-model="invoke.area_jurisdiction_id" class="select">
                            <option v-for="area in invokeForm.areaOptions" :key="area.id" :value="area.id">
                                {{ area.name }}
                            </option>
                        </select>
                    </template>
                </Field>
                <Field
                    :label="t('c_legislature_pages.emergency_powers.methods_label', 'Methods')"
                    :hint="t('c_legislature_pages.emergency_powers.methods_hint', 'Engine validation: methods must remain within constitutional order — rights and civic processes are untouchable.')"
                    :error="invoke.errors.methods"
                    required
                >
                    <template #control="{ id, invalid, describedBy }">
                        <textarea
                            :id="id"
                            v-model="invoke.methods"
                            class="field-input"
                            rows="2"
                            :placeholder="t('c_legislature_pages.emergency_powers.methods_placeholder', 'e.g. evacuation orders, shelter requisition, debris-clearance contracting')"
                            :aria-invalid="invalid ? 'true' : undefined"
                            :aria-describedby="describedBy"
                        ></textarea>
                    </template>
                </Field>
            </div>
            <p class="citation" style="margin-block-end: var(--space-2)">
                {{ t('c_legislature_pages.emergency_powers.requires_super', { super: legislature.supermajority, serving: legislature.serving }) }}
            </p>
        </FormCard>
        <Card v-else as="section" :title="t('c_legislature_pages.emergency_powers.invoke_title', 'Invoke emergency powers')">
            <p class="gloss">
                {{ t('c_legislature_pages.emergency_powers.invoke_gloss', 'Invocation is filed by a serving member (F-LEG-024, R-09) and adopted only by supermajority of all serving. Every declaration, renewal, and expiry publishes to the public record — citizens read this dashboard at all times.') }}
            </p>
        </Card>

        <!-- ==================================== hard rails =============== -->
        <Card as="section" :title="t('c_legislature_pages.emergency_powers.rails_title', 'The hard rails')">
            <ul style="margin-block-end: 0">
                <li>{{ t('c_legislature_pages.emergency_powers.rail_civic_label', 'Civic-process protection:') }} <strong>{{ t('c_legislature_pages.emergency_powers.rail_civic', 'elections, sessions, courts, and every civic process cannot be disrupted — enforced in code.') }}</strong></li>
                <li>{{ t('c_legislature_pages.emergency_powers.rail_expiry', 'Auto-expiry at the declared duration — no action required, no extension without a fresh supermajority.') }}</li>
                <li>{{ t('c_legislature_pages.emergency_powers.rail_first_order', 'First order of business: an active power leads the agenda of every session until it ends (AgendaStrip slot 1 —') }} <a :href="`/legislatures/${legislature.id}/session`">{{ t('c_legislature_pages.emergency_powers.session_console', 'session console') }}</a>).</li>
                <li>{{ t('c_legislature_pages.emergency_powers.rail_reviewable', 'Judicially reviewable at any time (WF-JUD-06).') }}</li>
            </ul>
            <div class="cluster" style="margin-block-start: var(--space-3)">
                <HardenedChip>{{ t('c_legislature_pages.emergency_powers.rails_chip', 'cannot disrupt elections, sessions, courts, or any civic process — enforced in code · Art. II §7') }}</HardenedChip>
                <span class="citation">{{ t('c_legislature_pages.emergency_powers.rails_cite', 'CLK-03 — the 90-day ceiling is itself the amendable setting\'s constitutional maximum') }}</span>
            </div>
        </Card>

        <!-- ==================================== expired register ========= -->
        <Card v-if="expired.length" as="section" :title="t('c_legislature_pages.emergency_powers.expired_title', 'Expired register')">
            <p class="gloss">{{ t('c_legislature_pages.emergency_powers.expired_gloss', 'Auto-expiry publishes a full audit record — nothing rolls over silently.') }}</p>
            <div class="stack" style="gap: var(--space-2)">
                <div v-for="row in expired" :key="row.id" class="cluster" style="justify-content: space-between">
                    <span><strong>{{ row.label }}</strong> <span class="citation">· {{ row.status }} {{ row.expired_at }}</span></span>
                    <a v-if="row.record_href" :href="row.record_href" class="citation">{{ t('c_legislature_pages.emergency_powers.sealed_audit', 'sealed audit record →') }}</a>
                </div>
            </div>
        </Card>

        <template #about>
            <p>
                {{ t('c_legislature_pages.emergency_powers.about', 'All validation runs PRE-VOTE: the closed cause enum, the ≤ 90-day ceiling, the area-of-authority check, and the methods requirement reject before any vote is taken — the rejection rows are the operator-visible record. The power row and its CLK-03 countdown exist only on supermajority adoption.') }}
            </p>
        </template>
    </PageScaffold>
</template>
