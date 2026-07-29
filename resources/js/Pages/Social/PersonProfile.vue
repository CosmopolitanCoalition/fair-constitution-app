<script setup>
/**
 * Social/PersonProfile — THE public person profile, the ?who= page
 * (contract mockups/v3/assets/js/profile-v2.js, other-view; v3.2 ruling 0a
 * "one person = one profile").
 *
 * One person, any role: if they stand in a race a Candidacy tab appears
 * (the absorbed electoral/candidate-profile contract — statement, approval
 * meter + finalist line, stage strip, endorsement web, manage/withdraw
 * when self); if they hold a seat an Office tab appears. There is no
 * separate kind of profile for officials.
 *
 * Rails (held server-side; mirrored here): pseudonymity end-to-end — the
 * server never ships a legal name; achievements arrive as a LIST (PI-6 —
 * nothing here counts, ranks or percentages a person); follows are private
 * notes, never records.
 */
import { computed, nextTick, ref } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import CitationLine from '@/Components/Ui/CitationLine.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import Field from '@/Components/Ui/Field.vue';
import LogRow from '@/Components/Ui/LogRow.vue';
import OrgChip from '@/Components/Ui/OrgChip.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StateStrip from '@/Components/Ui/StateStrip.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import TagChip from '@/Components/Ui/TagChip.vue';
import ThresholdMeter from '@/Components/Ui/ThresholdMeter.vue';
import Icon from '@/Components/Ui/Icon.vue';
import { achievementTitle } from '@/lib/achievementTitle';

defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    tab: { type: String, default: 'overview' },
    tabs: { type: Array, default: () => ['overview', 'record'] },
    isSelf: { type: Boolean, default: false },
    canMessage: { type: Boolean, default: false },
    editable: { type: Object, default: null },
    person: { type: Object, required: true },
    follow: { type: Object, default: () => ({ canFollow: false, isFollowing: false }) },
    candidacies: { type: Array, default: () => [] },
    candidacyPanel: { type: Object, default: null },
    offices: { type: Array, default: () => [] },
    record: { type: Object, default: () => ({ actions: [], associations: [], endorsementsGiven: [] }) },
    achievements: { type: Array, default: null },
});

const { t } = useI18n({ useScope: 'global' });
const page = usePage();
const flash = computed(() => page.props.flash?.status ?? null);
const errors = computed(() => page.props.errors ?? {});
const signedIn = computed(() => !!page.props.auth?.user);

const TAB_LABELS = {
    overview: 'Overview',
    record: 'Record',
    candidacy: 'Candidacy',
    office: 'Office',
    achievements: 'Achievements',
};

/* ─────────────────────────────────────────────────────────────── tabs */

const validKeys = computed(() => props.tabs);
const activeTab = ref(validKeys.value.includes(props.tab) ? props.tab : 'overview');
const tablistEl = ref(null);

function selectTab(key, focus = false) {
    activeTab.value = key;
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

/* ─────────────────────────────────────────────────────── follow / message */

const followBusy = ref(false);

function toggleFollow() {
    if (followBusy.value) return;
    followBusy.value = true;
    const opts = { preserveScroll: true, onFinish: () => (followBusy.value = false) };
    if (props.follow.isFollowing) router.delete(`/people/${props.person.id}/follow`, opts);
    else router.post(`/people/${props.person.id}/follow`, {}, opts);
}

const messageBusy = ref(false);

/* Open (or reopen) a direct message — a 2-person private room. The server
 * finds an existing DM or makes one, then lands us in it. */
function messagePerson() {
    if (messageBusy.value) return;
    messageBusy.value = true;
    router.post(`/people/${props.person.id}/message`, {}, {
        onFinish: () => (messageBusy.value = false),
    });
}

/* ─────────────────────────────────────────── self-edit door (F-IND-002) */

const editing = ref(false);
const profileForm = useForm({
    display_name: props.editable?.display_name ?? '',
    handle: props.editable?.handle ?? '',
    bio: props.editable?.bio ?? '',
    visibility: props.editable?.visibility ?? 'public',
});

function saveProfile() {
    profileForm.post('/people/profile', {
        preserveScroll: true,
        onSuccess: () => (editing.value = false),
    });
}

/* ───────────────────────────────── candidacy panel (the absorbed page) */

const cand = computed(() => props.candidacyPanel?.candidacy ?? null);
const standing = computed(() => props.candidacyPanel?.standing ?? null);
const race = computed(() => cand.value?.race ?? null);
const isOwner = computed(() => !!props.candidacyPanel?.isOwner);
const noEndorsements = computed(() => {
    const e = props.candidacyPanel?.endorsements;
    return e ? e.orgs.length === 0 && e.individual.total === 0 : true;
});

const fmtDate = (iso) => (iso ? new Date(iso).toLocaleDateString() : '—');

const requestColumns = [
    { key: 'org_name', label: 'Organization' },
    { key: 'requested_at', label: 'Requested' },
    { key: 'status', label: 'Status' },
];

/* Focus another of the person's candidacies — a real visit (server rebuilds the panel). */
function focusCandidacy(id) {
    router.get('/people', { who: props.person.id, tab: 'candidacy', candidacy: id }, { preserveScroll: true });
}

/* F-CAN-001 — manage statement (existing endpoint; the tab is a view). */
const statementForm = useForm({ platform_statement: cand.value?.statement ?? '' });
function submitStatement() {
    statementForm.patch(`/candidates/${cand.value.id}`, { preserveScroll: true });
}

/* F-CAN-003 — withdraw (ballot lock UX; server enforces CLK-21). */
const withdrawForm = useForm({});
const withdrawConfirming = ref(false);
function submitWithdraw() {
    withdrawForm.post(`/candidates/${cand.value.id}/withdraw`, {
        preserveScroll: true,
        onSuccess: () => (withdrawConfirming.value = false),
    });
}

/* F-CAN-002 — endorsement request. */
const requestForm = useForm({ organization_id: '', message: '' });
function submitRequest() {
    requestForm.post(`/candidates/${cand.value.id}/endorsement-requests`, {
        preserveScroll: true,
        onSuccess: () => requestForm.reset(),
    });
}
</script>

<template>
    <PageScaffold :surface="surface" :title="person.display">
        <template #intro>
            <template v-if="isSelf">
                Your public profile — exactly what everyone else sees. Settings, wallet and the
                rest of your private half live at <Link href="/civic/record">My record</Link>.
            </template>
            <template v-else>
                One person, shown the same way everyone is. If they hold an office, their office
                record is just another tab — there is no separate kind of profile for officials.
            </template>
        </template>

        <Banner v-if="flash" tone="info">{{ flash }}</Banner>
        <Banner v-if="errors.constitution" tone="warning" title="Filing rejected by the constitutional engine">
            {{ errors.constitution }} — the rejection itself is on the audit chain (append-only).
        </Banner>

        <!-- ─────────────────────────────────────────────────── head card -->
        <div class="card profile-head">
            <span class="profile-avatar" aria-hidden="true">{{ person.initials }}</span>
            <div style="flex: 1; min-width: 0">
                <h2 style="margin: 0">{{ person.display }}</h2>
                <p class="gloss" style="margin-block: var(--space-1) 0">
                    <template v-if="person.office">{{ person.office }}</template>
                    <template v-else-if="person.handle">@{{ person.handle }}</template>
                    <template v-else-if="person.home">Resident of {{ person.home.name }}</template>
                    <template v-else>A public profile</template>
                </p>
                <p v-if="person.bio" style="margin-block: var(--space-2) 0">{{ person.bio }}</p>
                <div class="profile-stats" style="margin-block-start: var(--space-2)">
                    <span v-if="person.home" class="citation">
                        <Icon name="map-pin" /> {{ person.home.name }}
                    </span>
                    <template v-if="person.followCounts">
                        <span class="citation">{{ person.followCounts.followers }} followers</span>
                        <span class="citation">{{ person.followCounts.following }} following</span>
                    </template>
                    <span v-if="offices.length" class="citation">Serves every resident equally</span>
                </div>
            </div>
            <div v-if="!isSelf" class="cluster" style="align-items: flex-start">
                <Btn
                    v-if="follow.canFollow"
                    :variant="follow.isFollowing ? 'secondary' : 'primary'"
                    size="sm"
                    :disabled="followBusy"
                    @click="toggleFollow"
                >{{ follow.isFollowing ? 'Following ✓' : 'Follow' }}</Btn>
                <Btn v-else-if="!signedIn" :as="Link" href="/login" variant="primary" size="sm">Sign in to follow</Btn>
                <Btn
                    v-if="canMessage"
                    variant="secondary"
                    size="sm"
                    :disabled="messageBusy"
                    @click="messagePerson"
                >Message</Btn>
                <Btn v-else-if="!signedIn" :as="Link" href="/login" variant="secondary" size="sm">Sign in to message</Btn>
            </div>
            <div v-else class="cluster" style="align-items: flex-start">
                <Btn variant="secondary" size="sm" @click="editing = !editing">
                    {{ editing ? 'Close editor' : 'Edit profile' }}
                </Btn>
            </div>
        </div>

        <!-- ───────────────────────────────── self-edit door (F-IND-002 §②) -->
        <Card v-if="isSelf && editing" as="section" title="Edit your public profile">
            <p class="gloss" style="margin-block-start: 0">
                Your display name, handle and bio file through the constitutional engine
                (F-IND-002). The public chain records only <em>that</em> your profile changed,
                never the values — a handle you later change can’t be linked back (Art. I).
            </p>
            <Field label="Display name" :error="profileForm.errors.display_name">
                <template #control="{ id, invalid, describedBy }">
                    <input
                        :id="id"
                        v-model="profileForm.display_name"
                        class="field-input"
                        type="text"
                        maxlength="255"
                        placeholder="A pseudonym is a first-class civic identity"
                        :aria-invalid="invalid ? 'true' : undefined"
                        :aria-describedby="describedBy"
                    />
                </template>
            </Field>
            <Field label="Handle" :error="profileForm.errors.handle" hint="3–64 chars: a–z, 0–9, hyphen or underscore. Your @address.">
                <template #control="{ id, invalid, describedBy }">
                    <input
                        :id="id"
                        v-model="profileForm.handle"
                        class="field-input"
                        type="text"
                        maxlength="64"
                        placeholder="e.g. jordan-r"
                        :aria-invalid="invalid ? 'true' : undefined"
                        :aria-describedby="describedBy"
                    />
                </template>
            </Field>
            <Field label="Bio" :error="profileForm.errors.bio">
                <template #control="{ id, invalid, describedBy }">
                    <textarea
                        :id="id"
                        v-model="profileForm.bio"
                        class="field-input"
                        rows="3"
                        maxlength="2000"
                        placeholder="A line about your civic life (optional)"
                        :aria-invalid="invalid ? 'true' : undefined"
                        :aria-describedby="describedBy"
                    ></textarea>
                </template>
            </Field>
            <Field label="Visibility" :error="profileForm.errors.visibility" hint="A preference — it never gates a right (Art. I).">
                <template #control="{ id, invalid, describedBy }">
                    <select
                        :id="id"
                        v-model="profileForm.visibility"
                        class="select"
                        :aria-invalid="invalid ? 'true' : undefined"
                        :aria-describedby="describedBy"
                    >
                        <option value="public">Public — anyone can see your bio, handle and counts</option>
                        <option value="jurisdiction">Your jurisdiction only</option>
                        <option value="private">Private — hidden from the public view</option>
                    </select>
                </template>
            </Field>
            <div class="cluster" style="margin-block-start: var(--space-3)">
                <Btn variant="primary" size="sm" :disabled="profileForm.processing" @click="saveProfile">
                    {{ profileForm.processing ? 'Filing F-IND-002…' : 'Save profile' }}
                </Btn>
                <Btn variant="ghost" size="sm" @click="editing = false">Cancel</Btn>
            </div>
        </Card>
        <p v-if="isSelf && person.visibility && person.visibility !== 'public'" class="citation">
            Your profile visibility is “{{ person.visibility }}” — bio, follow counts and
            achievements are hidden from this public view until you choose to show them.
            Visibility is a preference; it never gates a right (Art. I).
        </p>

        <!-- ──────────────────────────────────────────────────────── tabs -->
        <div ref="tablistEl" class="profile-tabs" role="tablist" aria-label="Profile sections">
            <button
                v-for="key in tabs"
                :id="`ptab-${key}`"
                :key="key"
                role="tab"
                :aria-selected="activeTab === key ? 'true' : 'false'"
                :aria-controls="`ppanel-${key}`"
                :tabindex="activeTab === key ? 0 : -1"
                :class="['profile-tab', { 'profile-tab--active': activeTab === key }]"
                type="button"
                @click="selectTab(key)"
                @keydown="onTabKeydown"
            >{{ TAB_LABELS[key] ?? key }}</button>
        </div>

        <!-- ───────────────────────────────────────────────────── overview -->
        <section
            v-show="activeTab === 'overview'"
            id="ppanel-overview"
            role="tabpanel"
            aria-labelledby="ptab-overview"
        >
            <Banner tone="info">
                <template v-if="isSelf">
                    This is your <strong>public</strong> profile. Everything on it is what any
                    resident — or any visitor — can already see.
                </template>
                <template v-else>
                    This is {{ person.display }}'s public profile — following them, messaging
                    them, or reading their public record never depends on paying or joining
                    anything.
                </template>
            </Banner>

            <Card as="section" title="At a glance">
                <ul style="margin: 0; padding-inline-start: var(--space-5)">
                    <li v-if="person.home">Resident of {{ person.home.name }} — residency is the only civic prerequisite there is (Art. I).</li>
                    <li v-if="candidacies.length">
                        Standing in an election —
                        <a href="#ppanel-candidacy" @click.prevent="selectTab('candidacy', true)">see the Candidacy tab</a>.
                    </li>
                    <li v-if="offices.length">
                        Holds public office —
                        <a href="#ppanel-office" @click.prevent="selectTab('office', true)">see the Office tab</a>.
                    </li>
                    <li>
                        The public record —
                        <a href="#ppanel-record" @click.prevent="selectTab('record', true)">the Record tab</a> —
                        is the audited civic history: it shows <em>that</em> a person participated, never <em>how</em> they voted.
                    </li>
                </ul>
                <p v-if="!person.bio && !person.handle" class="citation" style="margin-block-start: var(--space-2)">
                    No public bio — a pseudonymous profile is a first-class way to live a civic life · Art. I
                </p>
            </Card>
        </section>

        <!-- ─────────────────────────────────────────────────────── record -->
        <section
            v-show="activeTab === 'record'"
            id="ppanel-record"
            role="tabpanel"
            aria-labelledby="ptab-record"
        >
            <Card as="section" title="Public record">
                <h3>Confirmed residencies</h3>
                <p v-if="record.associations.length" class="cluster" style="gap: var(--space-1)">
                    <TagChip v-for="a in record.associations" :key="a.id">{{ a.name }}</TagChip>
                </p>
                <p v-else-if="isSelf" class="gloss">No confirmed residency yet.</p>
                <p v-else class="gloss">
                    Not shown — a person's named home chain appears when they choose a public
                    profile. Where they act publicly (a candidacy, an office), that place is on
                    those tabs.
                </p>

                <h3 style="margin-block-start: var(--space-3)">Civic actions</h3>
                <template v-if="record.actions.length">
                    <LogRow v-for="action in record.actions" :key="action.seq" :seq="action.seq">
                        {{ action.label }}
                        <span class="citation">{{ fmtDate(action.date) }}</span>
                    </LogRow>
                </template>
                <p v-else class="gloss">No public record entries yet — participation appears here as it happens.</p>

                <h3 style="margin-block-start: var(--space-3)">Endorsements given — public by their choice</h3>
                <template v-if="record.endorsementsGiven.length">
                    <p class="cluster" style="gap: var(--space-1)">
                        <Link
                            v-for="en in record.endorsementsGiven"
                            :key="en.candidacy_id"
                            :href="`/people?who=${en.user_id}&tab=candidacy&candidacy=${en.candidacy_id}`"
                        >{{ en.name }}</Link>
                    </p>
                </template>
                <p v-else class="gloss">No public endorsements given.</p>

                <p class="citation" style="margin-block-start: var(--space-2)">
                    Participation is public; ballot choices are secret. The record can only ever be
                    added to — never quietly edited · Art. II §2
                </p>
            </Card>
        </section>

        <!-- ──────────────────────────────── candidacy (the absorbed page) -->
        <section
            v-if="tabs.includes('candidacy')"
            v-show="activeTab === 'candidacy'"
            id="ppanel-candidacy"
            role="tabpanel"
            aria-labelledby="ptab-candidacy"
        >
            <Banner v-if="cand" tone="info">
                {{ person.display }} is standing for election
                <template v-if="race"> — {{ race.label }} · {{ race.seats }} seats</template>.
                A candidacy is not a separate identity: it is this same profile, carried onto the ballot.
            </Banner>

            <!-- more than one candidacy: pick which race to inspect -->
            <p v-if="candidacies.length > 1" class="cluster" style="gap: var(--space-1)">
                <Btn
                    v-for="c in candidacies"
                    :key="c.id"
                    :variant="c.id === candidacyPanel?.focus ? 'primary' : 'ghost'"
                    size="sm"
                    @click="focusCandidacy(c.id)"
                >{{ c.race_label }} · {{ c.status }}</Btn>
            </p>

            <template v-if="cand">
                <Card as="section">
                    <template #title>
                        <h2>
                            Platform statement
                            <StatusBadge v-if="cand.incumbent" tone="neutral">incumbent</StatusBadge>
                            <StatusBadge v-if="cand.withdrawn" tone="danger">withdrawn — recorded on the public record</StatusBadge>
                        </h2>
                    </template>
                    <p v-if="cand.statement">{{ cand.statement }}</p>
                    <p v-else class="gloss">No platform statement published yet.</p>
                    <p class="cluster" style="gap: var(--space-1)">
                        <TagChip v-for="tag in cand.position_tags" :key="tag">{{ tag }}</TagChip>
                    </p>
                    <p v-if="race" class="citation">
                        {{ race.label }} · {{ race.seats }} seats · top {{ race.finalist_count }} advance · CLK-21
                    </p>
                    <CitationLine text="Shown on the open ballot and the ranked ballot. Every edit is appended to the public record." />
                    <StateStrip :states="candidacyPanel.machine" :current="candidacyPanel.currentState" style="margin-block-start: var(--space-3)" />
                </Card>

                <Card as="section" title="Approval standing">
                    <template v-if="standing">
                        <div class="cluster" style="gap: var(--space-6)">
                            <Stat :value="`#${standing.rank} of ${standing.of}`" label="full-race rank" />
                            <Stat :value="standing.approvals.toLocaleString()" label="approvals — aggregate · updated daily" accent />
                            <Stat :value="race?.finalist_count ?? '—'" label="finalist places (X)" />
                        </div>
                        <div style="margin-block-start: var(--space-3)">
                            <ThresholdMeter
                                :value="standing.approvals"
                                :max="Math.max(standing.topApprovals, standing.lineApprovals, standing.approvals, 1)"
                                :threshold="standing.lineApprovals"
                                label="Approvals relative to the finalist line"
                            >
                                <template v-if="standing.isFinalist">
                                    rank #{{ standing.rank }} — inside the top {{ race?.finalist_count }} (finalist track)
                                </template>
                                <template v-else>
                                    below the finalist line · write-in eligible
                                </template>
                                <template #note>
                                    gold tick = finalist line · top {{ race?.finalist_count }} of {{ standing.of }} · CLK-21
                                </template>
                            </ThresholdMeter>
                        </div>
                        <p class="citation" style="margin-block-start: var(--space-2)">
                            {{ standing.frozen ? 'frozen at the finalist cutoff' : `aggregate as of ${standing.asOf} · updated daily` }}
                            · individual approvals are secret · Art. II §2
                        </p>
                    </template>
                    <template v-else>
                        <p class="gloss">
                            <template v-if="!race">
                                Awaiting board validation — the race binding (and with it the standing)
                                appears once F-ELB-002 validates residency.
                            </template>
                            <template v-else>
                                No standings aggregate exists for this race yet — see the count on the
                                open ballot once the daily rollup runs.
                            </template>
                        </p>
                        <Btn
                            v-if="race"
                            :as="Link"
                            :href="`/elections/${race.election_id}/open-ballot?race=${race.id}`"
                            variant="secondary"
                            size="sm"
                        >See the race standings</Btn>
                    </template>
                </Card>

                <div class="grid-2">
                    <Card as="section" title="Endorsements">
                        <p class="cluster" style="gap: var(--space-1)">
                            <OrgChip v-for="org in candidacyPanel.endorsements.orgs" :key="org.id" :name="org.name" :org-type="org.type" />
                            <TagChip v-if="candidacyPanel.endorsements.individual.total">
                                {{ candidacyPanel.endorsements.individual.total }} individual endorsements ·
                                {{ candidacyPanel.endorsements.individual.public }} public / {{ candidacyPanel.endorsements.individual.private }} private
                            </TagChip>
                            <TagChip v-if="noEndorsements">no organizational endorsements — running unendorsed is first-class</TagChip>
                        </p>
                        <details v-if="candidacyPanel.endorsements.publicWeb.length" class="about-surface" style="margin-block-start: var(--space-3)">
                            <summary>Public endorsement web — {{ candidacyPanel.endorsements.publicWeb.length }} public individual endorsers</summary>
                            <div class="about-surface-body">
                                <ul style="margin: 0; padding-inline-start: var(--space-5)">
                                    <li v-for="endorser in candidacyPanel.endorsements.publicWeb" :key="endorser.user_id">
                                        <Link :href="`/people?who=${endorser.user_id}`">{{ endorser.name }}</Link>
                                        <StatusBadge v-if="endorser.alsoCandidate" tone="neutral">also a candidate</StatusBadge>
                                        <template v-if="endorser.endorses.length">
                                            — also endorses:
                                            <template v-for="(target, i) in endorser.endorses" :key="target.candidacy_id">
                                                <template v-if="i > 0">, </template>
                                                <Link :href="`/people?who=${target.user_id}&tab=candidacy&candidacy=${target.candidacy_id}`">{{ target.name }}</Link>
                                            </template>
                                        </template>
                                    </li>
                                </ul>
                                <p class="citation">
                                    individual endorsers disclose by choice · org endorsements are always
                                    public · polymorphic endorsements table
                                </p>
                            </div>
                        </details>
                        <p class="citation" style="margin-block-start: var(--space-2)">
                            Endorsements inform — they never gate. Art. I; Art. II §2.
                        </p>
                    </Card>

                    <Card as="section" title="The record rides along">
                        <p>
                            Every candidacy carries the person's full public record with it —
                            auto-attached, never editable by the candidate. It is the
                            <a href="#ppanel-record" @click.prevent="selectTab('record', true)">Record tab</a>
                            of this same profile.
                        </p>
                        <template v-if="isOwner">
                            <h3 style="margin-block-start: var(--space-3)">Endorsement requests <span class="citation">visible only to you</span></h3>
                            <DataTable
                                v-if="candidacyPanel.requests.length"
                                :columns="requestColumns"
                                :rows="candidacyPanel.requests"
                                caption="Endorsement requests filed by this candidacy"
                            >
                                <template #cell-requested_at="{ value }">{{ fmtDate(value) }}</template>
                                <template #cell-status="{ value }">
                                    <StatusBadge :tone="value === 'granted' ? 'success' : value === 'declined' ? 'danger' : 'info'">
                                        {{ value }}{{ value === 'granted' ? ' · grants R-07' : '' }}
                                    </StatusBadge>
                                </template>
                            </DataTable>
                            <p v-else class="gloss">No requests yet.</p>

                            <form novalidate style="margin-block-start: var(--space-3)" @submit.prevent="submitRequest">
                                <Field
                                    label="Ask an organization for its endorsement — F-CAN-002"
                                    hint="The org's agent decides via F-ORG-002; a grant is public and grants R-07."
                                    :error="requestForm.errors.organization_id || errors.constitution"
                                >
                                    <template #control="{ id, invalid, describedBy }">
                                        <select
                                            :id="id"
                                            v-model="requestForm.organization_id"
                                            class="field-input"
                                            :aria-invalid="invalid ? 'true' : undefined"
                                            :aria-describedby="describedBy"
                                        >
                                            <option value="" disabled>— select an organization —</option>
                                            <option v-for="org in candidacyPanel.organizations" :key="org.id" :value="org.id">{{ org.name }}</option>
                                        </select>
                                    </template>
                                </Field>
                                <Btn type="submit" variant="secondary" size="sm" :disabled="requestForm.processing || !requestForm.organization_id">
                                    {{ requestForm.processing ? 'Filing F-CAN-002…' : 'Request endorsement' }}
                                </Btn>
                            </form>
                        </template>
                        <p v-else class="citation" style="margin-block-start: var(--space-2)">
                            Statement edits, endorsement requests, and withdrawal appear on this tab
                            only when {{ person.display }} opens their own profile — every change
                            lands on the public record.
                        </p>
                    </Card>
                </div>

                <!-- ──────────────────────────── manage (self only, F-CAN-001/003) -->
                <template v-if="isOwner">
                    <Card as="section" title="Manage this candidacy — visible only to you">
                        <form novalidate @submit.prevent="submitStatement">
                            <Field
                                label="Platform statement — F-CAN-001"
                                hint="Public and self-managed; every save appends to your public record."
                                :error="statementForm.errors.platform_statement"
                            >
                                <template #control="{ id, invalid, describedBy }">
                                    <textarea
                                        :id="id"
                                        v-model="statementForm.platform_statement"
                                        class="field-input"
                                        rows="5"
                                        :aria-invalid="invalid ? 'true' : undefined"
                                        :aria-describedby="describedBy"
                                    ></textarea>
                                </template>
                            </Field>
                            <Btn type="submit" variant="secondary" size="sm" :disabled="statementForm.processing">
                                {{ statementForm.processing ? 'Filing F-CAN-001…' : 'Save statement — F-CAN-001' }}
                            </Btn>
                        </form>

                        <hr style="margin-block: var(--space-4)" />

                        <h3>Withdraw candidacy — F-CAN-003</h3>
                        <p class="cc-small">
                            Withdrawal is allowed until the ballot lock at the finalist cutoff. It is
                            recorded permanently on the public record.
                        </p>
                        <template v-if="cand.withdrawn">
                            <StatusBadge tone="danger">Withdrawn — recorded on the public record</StatusBadge>
                        </template>
                        <template v-else-if="!candidacyPanel.can.withdraw">
                            <Btn variant="danger" disabled title="ballot locked at the finalist cutoff — withdrawal closed · CLK-21">
                                Withdraw candidacy
                            </Btn>
                            <CitationLine text="ballot locked at the finalist cutoff — withdrawal closed · CLK-21" />
                        </template>
                        <template v-else>
                            <div class="cluster">
                                <Btn v-if="!withdrawConfirming" variant="danger" icon="alert-triangle" @click="withdrawConfirming = true">
                                    Withdraw candidacy
                                </Btn>
                                <template v-else>
                                    <span><strong>Withdraw from this race? This cannot be undone.</strong></span>
                                    <Btn variant="danger" :disabled="withdrawForm.processing" @click="submitWithdraw">
                                        {{ withdrawForm.processing ? 'Filing F-CAN-003…' : 'Yes, withdraw — F-CAN-003' }}
                                    </Btn>
                                    <Btn variant="ghost" @click="withdrawConfirming = false">Keep running</Btn>
                                </template>
                            </div>
                        </template>
                    </Card>
                </template>
            </template>
        </section>

        <!-- ─────────────────────────────────────────────────────── office -->
        <section
            v-if="tabs.includes('office')"
            v-show="activeTab === 'office'"
            id="ppanel-office"
            role="tabpanel"
            aria-labelledby="ptab-office"
        >
            <Card v-for="(office, i) in offices" :key="i" as="section">
                <template #title>
                    <h2>
                        {{ office.title }}
                        <StatusBadge v-if="office.is_speaker" tone="success">Speaker</StatusBadge>
                        <StatusBadge tone="neutral">{{ office.status }}</StatusBadge>
                    </h2>
                </template>
                <p class="gloss">
                    Elected by every resident of {{ office.jurisdiction }} through STV — this seat
                    answers to all of them equally, not to a party or a donor.
                </p>
                <p class="citation">
                    <template v-if="office.since">seated {{ fmtDate(office.since) }}</template>
                    <template v-if="office.until"> · term ends {{ fmtDate(office.until) }}</template>
                </p>
                <Btn v-if="office.href" :as="Link" :href="office.href" variant="secondary" size="sm">
                    Open the {{ office.kind === 'legislature' ? 'chamber' : office.kind }} page
                </Btn>
            </Card>
            <p class="citation">
                Every act taken in office is public and uneditable — it lives on the
                <a href="#ppanel-record" @click.prevent="selectTab('record', true)">Record tab</a>
                and in the institution's own records · Art. II
            </p>
        </section>

        <!-- ───────────────────────────────────────────────── achievements -->
        <section
            v-if="tabs.includes('achievements')"
            v-show="activeTab === 'achievements'"
            id="ppanel-achievements"
            role="tabpanel"
            aria-labelledby="ptab-achievements"
        >
            <Card as="section" title="Achievements">
                <template v-if="achievements && achievements.length">
                    <div class="role-grid">
                        <div v-for="medal in achievements" :key="medal.id" class="role-card">
                            <Icon name="award" />
                            <div>
                                <strong>{{ achievementTitle(medal.title, t) }}</strong>
                                <p class="citation" style="margin: 0">Earned {{ fmtDate(medal.earned_at) }}</p>
                            </div>
                        </div>
                    </div>
                </template>
                <p v-else class="gloss">
                    <template v-if="isSelf">
                        Nothing earned yet — <Link href="/journeys">a guided journey</Link> is the
                        natural first one.
                    </template>
                    <template v-else>Nothing shown here yet.</template>
                </p>
                <p style="margin-block-start: var(--space-3)">
                    <Btn :as="Link" href="/achievements" variant="ghost" size="sm">Browse the full catalog</Btn>
                </p>
                <p class="citation">
                    A list, never a score — nothing here grants any vote, seat, role or
                    eligibility, ever · CI-1 · PI-6
                </p>
            </Card>
        </section>
    </PageScaffold>
</template>
