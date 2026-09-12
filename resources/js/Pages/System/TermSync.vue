<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import Stat from '@/Components/Ui/Stat.vue';

defineOptions({ layout: AppShellV2 });
const props = defineProps({
    surface: { type: Object, required: true },
    selectedPlace: { type: Object, default: null },
    legislature: { type: Object, default: null },
    legislatureChoices: { type: Object, required: true },
    lockstepTerms: { type: Object, required: true },
    civilTerms: { type: Object, required: true },
    refusals: { type: Object, required: true },
    appointmentYears: { type: Object, default: null },
});
const { t, locale } = useI18n();
const text = (key, values = {}) => t('c_term_sync.' + key, values);
const dateOf = (value) => value ? new Date(value.length === 10 ? value + 'T00:00:00' : value).toLocaleDateString(locale.value === 'en-XA' ? 'en' : locale.value) : text('not_recorded');
const columns = computed(() => [
    { key: 'kind', label: text('role') },
    { key: 'starts_on', label: text('started') },
    { key: 'ends_on', label: text('expires') },
    { key: 'record', label: text('source') },
]);
const termSections = computed(() => [
    { key: 'elected', title: text('elected_terms'), page: props.lockstepTerms, detail: props.legislature ? text('elected_scope') : text('place_elected_scope') },
    { key: 'appointed', title: text('appointed_terms'), page: props.civilTerms, detail: text('appointed_scope') },
]);
const kindLabel = (kind) => text('role_' + kind);
</script>

<template>
    <PageScaffold :surface="surface">
        <template #intro>{{ text('intro') }}</template>
        <Card v-if="!selectedPlace" as="section" :title="text('choose_place')">
            <p>{{ text('choose_help') }}</p>
            <Link class="term-link" href="/jurisdictions">{{ text('browse_world') }}</Link>
        </Card>
        <template v-else>
            <nav class="term-nav" :aria-label="text('place_navigation')">
                <strong>{{ selectedPlace.name }}</strong>
                <Link :href="`/jurisdictions/${selectedPlace.slug}`">{{ text('place_home') }}</Link>
                <Link :href="`/jurisdictions?parent=${encodeURIComponent(selectedPlace.slug)}`">{{ text('places_inside') }}</Link>
                <Link href="/jurisdictions">{{ text('browse_world') }}</Link>
            </nav>

            <Card as="section" :title="text('legislatures_here')">
                <p v-if="!legislatureChoices.rows.length">{{ text('no_legislature') }}</p>
                <nav v-else class="term-nav" :aria-label="text('choose_legislature')">
                    <Link :href="`/system/term-sync?jurisdiction=${encodeURIComponent(selectedPlace.slug)}`" :aria-current="!legislature ? 'page' : undefined">{{ text('all_place_terms') }}</Link>
                    <Link v-for="choice in legislatureChoices.rows" :key="choice.id" :href="choice.href" :aria-current="legislature?.id === choice.id ? 'page' : undefined">
                        {{ text('legislature_term', { number: choice.term_number }) }} · {{ text('status_' + choice.status) }}
                    </Link>
                </nav>
                <nav v-if="legislatureChoices.previous || legislatureChoices.next" class="term-nav" :aria-label="text('legislature_pages')">
                    <Link v-if="legislatureChoices.previous" :href="legislatureChoices.previous">{{ text('previous') }}</Link>
                    <Link v-if="legislatureChoices.next" :href="legislatureChoices.next">{{ text('next') }}</Link>
                </nav>
            </Card>

            <Card v-if="legislature" as="section" :title="text('election_clock')">
                <div class="term-stats">
                    <Stat :value="dateOf(legislature.term.starts_on)" :label="text('term_start')" />
                    <Stat :value="dateOf(legislature.term.ends_on)" :label="text('term_end')" />
                    <Stat :value="dateOf(legislature.next_election.clock_due_at)" :label="text('scheduled_election')" accent />
                    <Stat :value="legislature.interval_months" :label="text('configured_months')" />
                </div>
                <p class="cc-small">{{ text('clock_help') }}</p>
                <nav class="term-nav" :aria-label="text('legislature_navigation')">
                    <Link :href="legislature.chamber_href">{{ text('chamber') }}</Link>
                    <Link :href="legislature.maps_href">{{ text('maps') }}</Link>
                    <Link :href="legislature.settings_href">{{ text('settings') }}</Link>
                    <Link v-if="legislature.next_election.election_id" :href="`/elections/${legislature.next_election.election_id}`">{{ text('open_election') }}</Link>
                </nav>
            </Card>
            <p v-else class="cc-small">{{ text('select_clock') }}</p>

            <Card v-for="section in termSections" :key="section.key" as="section" :title="section.title">
                <p class="cc-small">{{ section.detail }}</p>
                <p v-if="section.key === 'appointed' && appointmentYears" class="cc-small">
                    {{ text('appointment_settings', appointmentYears) }}
                </p>
                <DataTable v-if="section.page.rows.length" :columns="columns" :rows="section.page.rows" :caption="section.title">
                    <template #cell-kind="{ row }">{{ kindLabel(row.kind) }}</template>
                    <template #cell-starts_on="{ row }">{{ dateOf(row.starts_on) }}</template>
                    <template #cell-ends_on="{ row }">{{ dateOf(row.ends_on) }}</template>
                    <template #cell-record="{ row }">
                        <Link v-if="row.election_href" :href="row.election_href">{{ text('source_election') }}</Link>
                        <Link v-else-if="row.legislature_href" :href="row.legislature_href">{{ text('legislature_record') }}</Link>
                        <span v-else>{{ text('appointment_record') }}</span>
                    </template>
                </DataTable>
                <p v-else>{{ text('no_terms') }}</p>
                <nav v-if="section.page.previous || section.page.next" class="term-nav" :aria-label="text('record_pages', { section: section.title })">
                    <Link v-if="section.page.previous" :href="section.page.previous" preserve-scroll>{{ text('previous') }}</Link>
                    <Link v-if="section.page.next" :href="section.page.next" preserve-scroll>{{ text('next') }}</Link>
                </nav>
            </Card>

            <Card as="section" :title="text('recorded_refusals')">
                <p class="cc-small">{{ text('refusal_scope') }}</p>
                <ol v-if="refusals.rows.length" class="refusal-list">
                    <li v-for="refusal in refusals.rows" :key="refusal.audit_seq">
                        <p>{{ refusal.attempt }}</p>
                        <span>{{ refusal.citation }} · {{ dateOf(refusal.at) }}</span>
                        <Link :href="`/system/audit-chain?seq=${refusal.audit_seq}`">{{ text('audit_entry', { number: refusal.audit_seq }) }}</Link>
                    </li>
                </ol>
                <p v-else>{{ text('no_refusals') }}</p>
                <nav v-if="refusals.previous || refusals.next" class="term-nav" :aria-label="text('refusal_pages')">
                    <Link v-if="refusals.previous" :href="refusals.previous" preserve-scroll>{{ text('previous') }}</Link>
                    <Link v-if="refusals.next" :href="refusals.next" preserve-scroll>{{ text('next') }}</Link>
                </nav>
            </Card>
        </template>
        <template #about><p>{{ text('about') }}</p></template>
    </PageScaffold>
</template>

<style scoped>
.term-nav { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2) var(--space-4); }
.term-nav a, .term-link, .refusal-list a { display: inline-flex; align-items: center; min-height: 44px; }
.term-nav a[aria-current="page"] { font-weight: 700; text-decoration-thickness: 3px; }
.term-nav a:focus-visible, .term-link:focus-visible, .refusal-list a:focus-visible { outline: 2px solid var(--gov-primary); outline-offset: 3px; }
.term-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 12rem), 1fr)); gap: var(--space-4); }
.refusal-list { padding-inline-start: var(--space-5); }
.refusal-list li + li { border-block-start: 1px solid var(--gov-border); margin-block-start: var(--space-3); }
.refusal-list p { overflow-wrap: anywhere; }
.refusal-list a { margin-inline-start: var(--space-3); }
</style>
