<script setup>
/**
 * Civic/MyRecord — the user's own slice of the audit chain (civic/my-record
 * contract, EXPLORE_civic_electoral.md §2; mockups/civic/my-record.html).
 *
 * The record IS the chain, filtered to actor_user_id = me — never a parallel
 * copy. It can never contain ballot content or raw ping locations because
 * those are structurally never written (commitments and day-counts only).
 * Dates are stored UTC and shown in the user's timezone. The personal-
 * settings panel files F-IND-002 through the constitutional engine, so the
 * profile mutation and its chain entry commit together.
 */
import { computed } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import FormCard from '@/Components/Surface/FormCard.vue';
import AdmChip from '@/Components/Ui/AdmChip.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import Field from '@/Components/Ui/Field.vue';
import FormChip from '@/Components/Ui/FormChip.vue';
import HardenedChip from '@/Components/Ui/HardenedChip.vue';
import LogRow from '@/Components/Ui/LogRow.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

const props = defineProps({
    surface: { type: Object, required: true },
    /** Laravel paginator over the user's own audit_log slice. */
    entries: { type: Object, required: true },
    associations: { type: Array, default: () => [] },
    stats: { type: Object, required: true },
    profile: { type: Object, required: true },
    localeOptions: { type: Array, default: () => [] },
    languageOptions: { type: Array, default: () => [] },
});

const page = usePage();
const flash = computed(() => page.props.flash?.status ?? null);
const errors = computed(() => page.props.errors ?? {});
const roles = computed(() => page.props.auth?.roles ?? []);

/* Mirrors AppShell — display labels only, never a gate. */
const ROLE_LABELS = {
    'R-00': 'Visitor',
    'R-01': 'Individual',
    'R-02': 'Resident',
    'R-03': 'Jurisdictionally Associated',
    'R-04': 'Voter',
    'R-05': 'Petitioner',
};

/* ─────────────────────────── dates: stored UTC, shown in the user's tz */

const browserTimezone = (() => {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
    } catch {
        return 'UTC';
    }
})();

const displayTimezone = computed(() => props.profile.timezone || browserTimezone);

const entryFormatter = computed(() => {
    try {
        return new Intl.DateTimeFormat(undefined, {
            dateStyle: 'medium',
            timeStyle: 'short',
            timeZone: displayTimezone.value,
        });
    } catch {
        return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' });
    }
});

function formatWhen(iso) {
    if (!iso) return '—';
    try {
        return entryFormatter.value.format(new Date(iso));
    } catch {
        return iso;
    }
}

function formatDate(iso) {
    if (!iso) return '—';
    try {
        return new Intl.DateTimeFormat(undefined, {
            dateStyle: 'medium',
            timeZone: displayTimezone.value,
        }).format(new Date(iso));
    } catch {
        return iso;
    }
}

const shortHash = (hash) => (hash ? `${hash.slice(0, 12)}…` : null);
const isFormRef = (ref) => typeof ref === 'string' && ref.startsWith('F-');

/* ───────────────────────────────── F-IND-002 — personal settings panel */

const LANGUAGE_LABELS = {
    en: 'English (en)',
    es: 'Español (es)',
    ar: 'العربية (ar)',
    'zh-Hans': '中文 — 简体 (zh-Hans)',
    hi: 'हिन्दी (hi)',
};

const timezones =
    typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('timeZone') : null;

const settingsForm = useForm({
    display_name: props.profile.display_name ?? '',
    locale: props.profile.locale ?? 'en',
    timezone: props.profile.timezone ?? browserTimezone,
    languages: [...(props.profile.languages ?? [])],
});

function submitSettings() {
    settingsForm.post('/civic/record/profile', { preserveScroll: true });
}

const formMeta = (id) => props.surface.forms.find((f) => f.id === id);

/* ──────────────────────────────────────────── associations DataTable */

const associationColumns = [
    { key: 'jurisdiction', label: 'Jurisdiction' },
    { key: 'days_confirmed', label: 'Days confirmed', align: 'end', mono: true },
    { key: 'confirmed_at', label: 'Confirmed on' },
];

const associationRows = computed(() =>
    props.associations.map((assoc) => ({
        ...assoc,
        confirmed_at: formatDate(assoc.confirmed_at),
        days_confirmed: assoc.days_confirmed ?? '—',
    })),
);
</script>

<template>
    <PageScaffold :surface="surface">
        <template #intro>
            Your public civic record — every accepted (and rejected) filing you made, straight from
            the hash-chained audit log. It travels with any future candidacy. Ballot choices and raw
            ping locations are never on it: they are structurally never written to the chain.
        </template>
        <template #about>
            <p>
                Read-only slice of the append-only audit chain (WF-SYS-03/04) filtered to your own
                filings, plus the F-IND-002 personal-settings panel — profile changes are filed
                through the constitutional engine so the mutation and its chain entry commit
                together.
            </p>
        </template>

        <Banner v-if="flash" tone="info">{{ flash }}</Banner>
        <Banner v-if="errors.constitution" tone="warning" title="Filing rejected by the constitutional engine">
            {{ errors.constitution }} — the rejection itself is on the audit chain (append-only).
        </Banner>

        <!-- ────────────────────────────────────────────────────── Stats -->
        <Card as="section">
            <div class="cluster" style="gap: var(--space-6)">
                <Stat :value="stats.record_entries" label="record entries" accent />
                <Stat :value="stats.associations" label="associations" />
                <Stat :value="stats.qualifying_days" label="qualifying days" />
                <Stat :value="stats.ballots_cast" label="ballots cast" />
            </div>
            <p class="citation" style="margin-block-start: var(--space-3)">
                Participation is public; ballot choices are secret · Art. II §2
            </p>
        </Card>

        <!-- ─────────────────────────────────────────────── Roles held -->
        <Card as="section" title="Roles held">
            <div class="cluster" style="gap: var(--space-3)">
                <StatusBadge v-for="role in roles" :key="role" tone="info" icon="user">
                    {{ role }} · {{ ROLE_LABELS[role] ?? role }}
                </StatusBadge>
                <HardenedChip />
            </div>
            <p style="margin-block-start: var(--space-3)">
                Roles are derived from residency facts at read time — never stored, never granted.
                The chain is R-01 Individual → R-02 Resident → R-03 Jurisdictionally Associated →
                R-04 Voter, and jurisdictional association is the <strong>only</strong> gate on
                voting and candidacy.
            </p>
            <p class="citation">Rights derive from residency alone · Art. I; Art. V §1</p>
        </Card>

        <!-- ──────────────────────────────────────────── Record entries -->
        <Card as="section" title="Record entries">
            <p class="cc-small">
                Append-only · hash-chained · shown in your timezone
                (<code>{{ displayTimezone }}</code>) · stored as UTC.
            </p>

            <p v-if="entries.data.length === 0" class="gloss">
                No entries yet — your first filing (account creation) appears here as soon as the
                chain records it.
            </p>

            <div v-else>
                <LogRow
                    v-for="entry in entries.data"
                    :key="entry.seq"
                    :seq="entry.seq"
                    :hash="shortHash(entry.hash)"
                    :rejected="entry.rejected"
                >
                    <code class="cc-small">{{ formatWhen(entry.occurred_at) }}</code>
                    <span>{{ entry.module }} · {{ entry.event }}</span>
                    <FormChip v-if="isFormRef(entry.ref)" :form-id="entry.ref" />
                    <span v-else-if="entry.ref" class="form-chip"><span class="form-id">{{ entry.ref }}</span></span>
                    <StatusBadge v-if="entry.rejected" tone="danger" icon="x">rejected</StatusBadge>
                    <span v-if="entry.rejected && entry.blocked_reason" class="cc-small">
                        {{ entry.blocked_reason }}
                    </span>
                </LogRow>

                <div class="cluster" style="margin-block-start: var(--space-3); align-items: baseline">
                    <Btn
                        v-if="entries.prev_page_url"
                        :as="Link"
                        :href="entries.prev_page_url"
                        preserve-scroll
                        variant="secondary"
                        size="sm"
                    >
                        Newer
                    </Btn>
                    <Btn
                        v-if="entries.next_page_url"
                        :as="Link"
                        :href="entries.next_page_url"
                        preserve-scroll
                        variant="secondary"
                        size="sm"
                    >
                        Older
                    </Btn>
                    <span class="cc-small">
                        Page {{ entries.current_page }} of {{ entries.last_page }} ·
                        {{ entries.total }} entr{{ entries.total === 1 ? 'y' : 'ies' }}
                    </span>
                </div>
            </div>

            <p class="citation" style="margin-block-start: var(--space-3)">
                Never contains ballot content or raw locations — they are never written · Art. II · WF-SYS-04
            </p>
        </Card>

        <!-- ─────────────────────────────────────────────── Associations -->
        <Card as="section" title="Jurisdictional associations">
            <p v-if="associations.length === 0" class="gloss">
                None yet — associations at every nesting level appear here the moment residency
                verifies. <Link href="/civic/residency">Declare residency</Link> to begin.
            </p>
            <DataTable
                v-else
                :columns="associationColumns"
                :rows="associationRows"
                row-key="id"
                caption="Active jurisdictional associations at every nesting level"
            >
                <template #cell-jurisdiction="{ row }">
                    <AdmChip :level="row.adm_level" :label="row.name" />
                </template>
            </DataTable>
            <p class="citation" style="margin-block-start: var(--space-2)">
                One verified residency → associations at every enclosing level · Art. I
            </p>
        </Card>

        <!-- ──────────────────────────── F-IND-002 — personal settings -->
        <FormCard
            :form="formMeta('F-IND-002')"
            :inertia-form="settingsForm"
            submit-label="Save settings"
            processing-label="Filing F-IND-002…"
            @submit="submitSettings"
        >
            <p class="cc-small" style="margin-block-end: var(--space-3)">
                Self-managed, no civic weight — only the fields that actually changed are filed,
                and only those ever appear on the chain.
            </p>

            <Field label="Display name" :error="settingsForm.errors.display_name">
                <template #control="{ id, invalid, describedBy }">
                    <input
                        :id="id"
                        v-model="settingsForm.display_name"
                        class="field-input"
                        type="text"
                        name="display_name"
                        autocomplete="name"
                        :aria-invalid="invalid ? 'true' : undefined"
                        :aria-describedby="describedBy"
                    />
                </template>
            </Field>

            <Field label="Interface language" :error="settingsForm.errors.locale">
                <template #control="{ id, invalid, describedBy }">
                    <select
                        :id="id"
                        v-model="settingsForm.locale"
                        class="select"
                        name="locale"
                        :aria-invalid="invalid ? 'true' : undefined"
                        :aria-describedby="describedBy"
                    >
                        <option v-for="loc in localeOptions" :key="loc" :value="loc">
                            {{ LANGUAGE_LABELS[loc] ?? loc }}
                        </option>
                    </select>
                </template>
            </Field>

            <Field
                label="Timezone"
                hint="Dates are shown in your timezone · stored as UTC."
                :error="settingsForm.errors.timezone"
            >
                <template #control="{ id, invalid, describedBy }">
                    <select
                        v-if="timezones"
                        :id="id"
                        v-model="settingsForm.timezone"
                        class="select"
                        name="timezone"
                        :aria-invalid="invalid ? 'true' : undefined"
                        :aria-describedby="describedBy"
                    >
                        <option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option>
                    </select>
                    <input
                        v-else
                        :id="id"
                        v-model="settingsForm.timezone"
                        class="field-input"
                        type="text"
                        name="timezone"
                        :aria-invalid="invalid ? 'true' : undefined"
                        :aria-describedby="describedBy"
                    />
                </template>
            </Field>

            <Field
                label="Languages"
                hint="Records are translated per your selection."
                :error="settingsForm.errors.languages"
            >
                <template #control="{ id, invalid, describedBy }">
                    <select
                        :id="id"
                        v-model="settingsForm.languages"
                        class="select"
                        name="languages"
                        multiple
                        size="5"
                        :aria-invalid="invalid ? 'true' : undefined"
                        :aria-describedby="describedBy"
                    >
                        <option v-for="lang in languageOptions" :key="lang" :value="lang">
                            {{ LANGUAGE_LABELS[lang] ?? lang }}
                        </option>
                    </select>
                </template>
            </Field>
        </FormCard>
    </PageScaffold>
</template>
