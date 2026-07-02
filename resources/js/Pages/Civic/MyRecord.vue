<script setup>
/**
 * Civic/MyRecord — the ONE person page (mockups-v3-wiring Phase 2; design
 * contract mockups/v3 assets/js/profile-v2.js, self view).
 *
 * One person, every role, one tabbed profile: Overview · Record · Candidacy
 * (only while standing) · Representatives · Achievements (designed empty
 * state) · Wallet (planned) · Settings. A candidacy is never a separate
 * identity and a record is never a separate page — they are tabs of this
 * same profile.
 *
 * The record IS the chain, filtered to actor_user_id = me — never a parallel
 * copy. It can never contain ballot content or raw ping locations because
 * those are structurally never written (commitments and day-counts only).
 * Dates are stored UTC and shown in the user's timezone. The Settings tab
 * files F-IND-002 through the constitutional engine, so the profile mutation
 * and its chain entry commit together.
 */
import { computed, nextTick, ref } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
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
import Icon from '@/Components/Ui/Icon.vue';
import LogRow from '@/Components/Ui/LogRow.vue';
import PlannedBanner from '@/Components/Ui/PlannedBanner.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    /** Laravel paginator over the user's own audit_log slice. */
    entries: { type: Object, required: true },
    associations: { type: Array, default: () => [] },
    stats: { type: Object, required: true },
    profile: { type: Object, required: true },
    localeOptions: { type: Array, default: () => [] },
    languageOptions: { type: Array, default: () => [] },
    /** RepresentativesResolver rows — most-local jurisdiction first. */
    representatives: { type: Array, default: () => [] },
    /** The user's candidacies; the Candidacy tab renders only when > 0. */
    candidacies: { type: Array, default: () => [] },
    /** Server-validated ?tab= (invalid values already fell back). */
    tab: { type: String, default: 'overview' },
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

/* ──────────────────────────────────────────────────────────── the tabs */

const tabs = computed(() => {
    const list = [
        { key: 'overview', label: 'Overview', icon: 'user' },
        { key: 'record', label: 'Record', icon: 'file-text' },
    ];
    /* A candidacy is a TAB of this same profile — only while one exists. */
    if (props.candidacies.length) {
        list.push({ key: 'candidacy', label: 'Candidacy', icon: 'vote' });
    }
    list.push(
        { key: 'representatives', label: 'Representatives', icon: 'landmark' },
        /* Always shown for yourself — the empty state is the invitation. */
        { key: 'achievements', label: 'Achievements', icon: 'award' },
        { key: 'wallet', label: 'Wallet', icon: 'lock' },
        { key: 'settings', label: 'Settings', icon: 'sliders' },
    );
    return list;
});

const validKeys = computed(() => tabs.value.map((t) => t.key));

/* ?tab=candidacy with nothing standing falls back to overview. */
const activeTab = ref(validKeys.value.includes(props.tab) ? props.tab : 'overview');

const tablistEl = ref(null);

function selectTab(key, focus = false) {
    activeTab.value = key;
    /* Keep the URL shareable without a server round-trip (SIMPLE — the
       server still validates ?tab= on real navigations). Preserve Inertia's
       history state so back/forward stays intact. */
    try {
        const url = new URL(window.location.href);
        if (key === 'overview') url.searchParams.delete('tab');
        else url.searchParams.set('tab', key);
        window.history.replaceState(window.history.state, '', url.toString());
    } catch {
        /* URL/History unavailable — tab state is local anyway. */
    }
    if (focus) {
        nextTick(() => tablistEl.value?.querySelector(`#ptab-${key}`)?.focus());
    }
}

/* Roving tabindex + arrow keys (profile-v2.js wire(), Vue-idiomatic). */
function onTabKeydown(event) {
    if (!['ArrowRight', 'ArrowLeft', 'Home', 'End'].includes(event.key)) return;
    event.preventDefault();
    const keys = validKeys.value;
    let index = keys.indexOf(activeTab.value);
    if (event.key === 'ArrowRight') index = (index + 1) % keys.length;
    else if (event.key === 'ArrowLeft') index = (index + keys.length - 1) % keys.length;
    else if (event.key === 'Home') index = 0;
    else index = keys.length - 1;
    selectTab(keys[index], true);
}

/* ───────────────────────────────────────────────────────────── the head */

const initials = computed(() => {
    const name = props.profile.display_name || '';
    return (
        name
            .split(/\s+/)
            .filter(Boolean)
            .map((word) => word.charAt(0))
            .slice(0, 2)
            .join('')
            .toUpperCase() || '?'
    );
});

/* Associations arrive ordered adm_level ASC — the most local is last. */
const mostLocal = computed(() =>
    props.associations.length ? props.associations[props.associations.length - 1] : null,
);

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
const isFormRef = (ref2) => typeof ref2 === 'string' && ref2.startsWith('F-');

/* Pagination lives inside the Record tab — keep ?tab=record on its links. */
function pageHref(url) {
    if (!url) return url;
    try {
        const u = new URL(url, window.location.origin);
        u.searchParams.set('tab', 'record');
        return u.pathname + u.search;
    } catch {
        return url;
    }
}

/* ──────────────────────────────────────────────────── candidacy display */

const CANDIDACY_LABELS = {
    registered: 'Registered',
    validated: 'Validated',
    in_pool: 'In the approval pool',
    finalist: 'Finalist — on the ranked ballot',
    non_finalist: 'Non-finalist · write-in eligible',
    elected: 'Elected',
    defeated: 'Not elected',
    withdrawn: 'Withdrawn',
    rejected: 'Rejected',
};

const CANDIDACY_TONES = {
    registered: 'info',
    validated: 'info',
    in_pool: 'info',
    finalist: 'success',
    non_finalist: 'warning',
    elected: 'success',
    defeated: 'neutral',
    withdrawn: 'neutral',
    rejected: 'danger',
};

/* ────────────────────────────────────────────── representatives display */

const SEAT_TYPE_LABELS = { a: 'Type A · constituent', b: 'Type B · at-large' };

function repInitials(name) {
    return (
        (name || '')
            .split(/\s+/)
            .filter(Boolean)
            .map((word) => word.charAt(0))
            .slice(0, 2)
            .join('')
            .toUpperCase() || '?'
    );
}

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
    <PageScaffold :surface="surface" title="My profile">
        <template #intro>
            Everything about your civic life in one place — who you are, your record, the people
            who represent you, and (when you stand) your candidacy. Ballot choices and raw ping
            locations are never here: they are structurally never written to the chain.
        </template>
        <template #about>
            <p>
                One person, one profile (profile-v2 contract). The Record tab is a read-only slice
                of the append-only audit chain (WF-SYS-03/04) filtered to your own filings; the
                Settings tab files F-IND-002 through the constitutional engine so the mutation and
                its chain entry commit together. A candidacy is a tab of this same profile — never
                a separate identity.
            </p>
        </template>

        <Banner v-if="flash" tone="info">{{ flash }}</Banner>
        <Banner v-if="errors.constitution" tone="warning" title="Filing rejected by the constitutional engine">
            {{ errors.constitution }} — the rejection itself is on the audit chain (append-only).
        </Banner>

        <!-- ──────────────────────────────────────────────────── the head -->
        <div class="card profile-head">
            <span class="profile-avatar" aria-hidden="true">{{ initials }}</span>
            <div class="stack" style="gap: var(--space-1); flex: 1 1 16rem">
                <div class="cluster" style="align-items: baseline; gap: var(--space-2)">
                    <h2 style="margin: 0">{{ profile.display_name || 'Your profile' }}</h2>
                    <span class="gloss">
                        {{ mostLocal ? `Resident of ${mostLocal.name}` : 'No verified residency yet' }}
                    </span>
                </div>
                <div class="profile-stats" style="margin-block-start: var(--space-2)">
                    <span class="profile-stat">
                        <Icon name="landmark" size="sm" />
                        <b>{{ stats.associations }}</b>
                        association{{ stats.associations === 1 ? '' : 's' }}
                    </span>
                    <span class="profile-stat">
                        <Icon name="file-text" size="sm" />
                        <b>{{ stats.record_entries }}</b>
                        record entr{{ stats.record_entries === 1 ? 'y' : 'ies' }}
                    </span>
                </div>
            </div>
        </div>

        <!-- ──────────────────────────────────────────────────── tab bar -->
        <div ref="tablistEl" class="profile-tabs" role="tablist" aria-label="Profile sections">
            <button
                v-for="t in tabs"
                :id="`ptab-${t.key}`"
                :key="t.key"
                type="button"
                role="tab"
                class="profile-tab"
                :class="{ 'is-active': activeTab === t.key }"
                :aria-selected="activeTab === t.key ? 'true' : 'false'"
                :aria-controls="`ppanel-${t.key}`"
                :tabindex="activeTab === t.key ? 0 : -1"
                @click="selectTab(t.key)"
                @keydown="onTabKeydown"
            >
                <Icon :name="t.icon" size="sm" />
                <span>{{ t.label }}</span>
            </button>
        </div>

        <!-- ═══════════════════════════════════════════════ TAB: overview -->
        <div
            v-show="activeTab === 'overview'"
            id="ppanel-overview"
            role="tabpanel"
            aria-labelledby="ptab-overview"
            class="stack"
        >
            <Banner tone="info">
                <strong>Right now</strong> — {{ stats.ballots_cast }} ballot{{
                    stats.ballots_cast === 1 ? '' : 's'
                }}
                cast on your record. Your ballot only ever shows that voting is open — never how
                you voted.
            </Banner>

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

            <Card as="section" title="Roles held">
                <div class="cluster" style="gap: var(--space-3)">
                    <StatusBadge v-for="role in roles" :key="role" tone="info" icon="user">
                        {{ role }} · {{ ROLE_LABELS[role] ?? role }}
                    </StatusBadge>
                    <HardenedChip />
                </div>
                <p style="margin-block-start: var(--space-3)">
                    Roles are derived from residency facts at read time — never stored, never
                    granted. The chain is R-01 Individual → R-02 Resident → R-03 Jurisdictionally
                    Associated → R-04 Voter, and jurisdictional association is the
                    <strong>only</strong> gate on voting and candidacy.
                </p>
                <p class="citation">Rights derive from residency alone · Art. I; Art. V §1</p>
            </Card>
        </div>

        <!-- ═════════════════════════════════════════════════ TAB: record -->
        <div
            v-show="activeTab === 'record'"
            id="ppanel-record"
            role="tabpanel"
            aria-labelledby="ptab-record"
            class="stack"
        >
            <Card as="section" title="Record entries">
                <p class="gloss">The record IS the chain — it cannot be quietly edited.</p>
                <p class="cc-small">
                    Append-only · hash-chained · shown in your timezone
                    (<code>{{ displayTimezone }}</code>) · stored as UTC.
                </p>

                <p v-if="entries.data.length === 0" class="gloss">
                    No entries yet — your first filing (account creation) appears here as soon as
                    the chain records it.
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
                            :href="pageHref(entries.prev_page_url)"
                            preserve-scroll
                            variant="secondary"
                            size="sm"
                        >
                            Newer
                        </Btn>
                        <Btn
                            v-if="entries.next_page_url"
                            :as="Link"
                            :href="pageHref(entries.next_page_url)"
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
        </div>

        <!-- ══════════════════════════════ TAB: candidacy (when standing) -->
        <div
            v-if="candidacies.length"
            v-show="activeTab === 'candidacy'"
            id="ppanel-candidacy"
            role="tabpanel"
            aria-labelledby="ptab-candidacy"
            class="stack"
        >
            <p class="gloss">
                A candidacy is not a separate identity: it is this same profile, carried onto the
                ballot.
            </p>

            <Card v-for="candidacy in candidacies" :key="candidacy.id" as="section">
                <div class="cluster" style="justify-content: space-between; align-items: baseline">
                    <h3 style="margin: 0">{{ candidacy.race_label }}</h3>
                    <StatusBadge :tone="CANDIDACY_TONES[candidacy.status] ?? 'info'">
                        {{ CANDIDACY_LABELS[candidacy.status] ?? candidacy.status }}
                    </StatusBadge>
                </div>

                <p v-if="candidacy.platform_statement" style="margin-block-start: var(--space-3)">
                    {{ candidacy.platform_statement }}
                </p>
                <p v-else class="gloss" style="margin-block-start: var(--space-3)">
                    No platform statement published yet.
                </p>

                <div
                    v-if="candidacy.position_tags.length"
                    class="cluster"
                    style="gap: var(--space-2); margin-block-start: var(--space-2)"
                >
                    <span v-for="tag in candidacy.position_tags" :key="tag" class="tag-chip">{{ tag }}</span>
                </div>

                <div class="cluster" style="margin-block-start: var(--space-3)">
                    <Btn :as="Link" href="/elections" variant="secondary" size="sm">
                        <Icon name="vote" size="sm" /> Open the election
                    </Btn>
                </div>
                <p class="citation" style="margin-block-start: var(--space-2)">
                    The record rides along — it is the Record tab of this same profile · Art. II
                </p>
            </Card>
        </div>

        <!-- ══════════════════════════════════════ TAB: representatives -->
        <div
            v-show="activeTab === 'representatives'"
            id="ppanel-representatives"
            role="tabpanel"
            aria-labelledby="ptab-representatives"
            class="stack"
        >
            <Card as="section" title="Your representatives">
                <p class="gloss">
                    Seats are elected in multi-winner rounds, so several people represent you at
                    once — every one of them answers to you, including the ones you didn't rank.
                </p>

                <p v-if="representatives.length === 0" class="gloss">
                    No representatives seated yet — they appear here the moment a legislature is
                    seated for a place you live.
                </p>

                <div v-else class="role-grid" style="margin-block-start: var(--space-3)">
                    <div v-for="rep in representatives" :key="rep.member_id" class="role-card">
                        <span class="profile-avatar profile-avatar--sm" aria-hidden="true">
                            {{ repInitials(rep.name) }}
                        </span>
                        <span class="role-name">{{ rep.name ?? '—' }}</span>
                        <span class="cc-small">
                            Seat {{ rep.seat_no ?? '—' }} ·
                            {{ SEAT_TYPE_LABELS[rep.seat_type] ?? rep.seat_type }}
                        </span>
                        <StatusBadge v-if="rep.is_speaker" tone="info" icon="landmark">Speaker</StatusBadge>
                        <AdmChip
                            v-if="rep.jurisdiction.adm_level !== null"
                            :level="rep.jurisdiction.adm_level"
                            :label="rep.jurisdiction.name"
                        />
                        <span v-else class="cc-small">{{ rep.jurisdiction.name ?? '—' }}</span>
                        <span v-if="rep.term_ends_on" class="cc-small">
                            Term ends {{ formatDate(rep.term_ends_on) }}
                        </span>
                    </div>
                </div>

                <p class="citation" style="margin-block-start: var(--space-3)">
                    Multi-winner seats answer to every resident · Art. II §2
                </p>
            </Card>
        </div>

        <!-- ═══════════════════════════════════════ TAB: achievements -->
        <div
            v-show="activeTab === 'achievements'"
            id="ppanel-achievements"
            role="tabpanel"
            aria-labelledby="ptab-achievements"
            class="stack"
        >
            <Card as="section">
                <div class="cluster" style="justify-content: space-between; align-items: flex-start">
                    <h3 style="margin: 0"><Icon name="award" size="sm" /> Achievements</h3>
                    <StatusBadge tone="neutral" icon="clock">Planned · Phase 3</StatusBadge>
                </div>
                <p class="gloss" style="margin-block-start: var(--space-3)">
                    Nothing here yet — finish your first journey to earn one.
                </p>
                <p class="cc-small">
                    Earned records of the journeys you complete and your civic firsts. None ever
                    changes a vote, a seat, or what you are allowed to do.
                </p>
            </Card>
        </div>

        <!-- ═════════════════════════════════════════════ TAB: wallet -->
        <div
            v-show="activeTab === 'wallet'"
            id="ppanel-wallet"
            role="tabpanel"
            aria-labelledby="ptab-wallet"
            class="stack"
        >
            <PlannedBanner extra="The wallet arrives with the civic economy (Phase 8). Nothing here is live yet, and no real money is anywhere." />
            <Card as="section">
                <div class="cluster" style="justify-content: space-between; align-items: flex-start">
                    <h3 style="margin: 0"><Icon name="lock" size="sm" /> Wallet</h3>
                    <StatusBadge tone="warning" icon="clock">Planned · Phase 8</StatusBadge>
                </div>
                <p class="never-federated" style="margin-block-start: var(--space-3)">
                    <Icon name="lock" size="sm" />
                    <span>Private — like a ballot, only you can read it.</span>
                </p>
            </Card>
        </div>

        <!-- ═══════════════════════════════════════════ TAB: settings -->
        <div
            v-show="activeTab === 'settings'"
            id="ppanel-settings"
            role="tabpanel"
            aria-labelledby="ptab-settings"
            class="stack"
        >
            <FormCard
                :form="formMeta('F-IND-002')"
                :inertia-form="settingsForm"
                submit-label="Save settings"
                processing-label="Filing F-IND-002…"
                @submit="submitSettings"
            >
                <p class="cc-small" style="margin-block-end: var(--space-3)">
                    Self-managed, no civic weight — only the fields that actually changed are
                    filed, and only those ever appear on the chain.
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
        </div>
    </PageScaffold>
</template>
