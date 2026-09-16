<script setup>
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Card from '@/Components/Ui/Card.vue';
import HistoryPager from '@/Components/Ui/HistoryPager.vue';
import SelectionIdentity from '@/Components/Ui/SelectionIdentity.vue';
import ConsentVoteCard from '@/Components/Legislature/ConsentVoteCard.vue';

const { t } = useI18n();

defineProps({
    judiciary: { type: Object, required: true },
    nominations: { type: Array, default: () => [] },
    pages: { type: Object, default: () => ({}) },
    context: { type: Object, default: () => ({ preview: true }) },
});
const statusLabel = (status) =>
    ({
        nominated: t('c_institution_components.judicial_confirmations.status_nominated', 'Awaiting confirmation'),
        consented: t('c_institution_components.judicial_confirmations.status_consented', 'Confirmed'),
        rejected: t('c_institution_components.judicial_confirmations.status_rejected', 'Not confirmed'),
        withdrawn: t('c_institution_components.judicial_confirmations.status_withdrawn', 'Withdrawn'),
    })[status] ?? status;
</script>

<template>
    <Card id="judicial-confirmations" as="section" :title="t('c_institution_components.judicial_confirmations.title', 'Judicial nominations and confirmation')">
        <p v-if="context.preview" class="confirmation-context">{{ t('c_institution_components.judicial_confirmations.role_preview', 'Role preview: members of this court’s source legislature review nominees and vote on confirmation.') }}</p>
        <p v-else class="confirmation-context">{{ t('c_institution_components.judicial_confirmations.participating_as', 'Participating as {name}', { named: { name: context.actor_name } }) }}<span v-if="context.is_speaker">{{ t('c_institution_components.judicial_confirmations.speaker_suffix', ', Speaker of the source legislature') }}</span>.</p>
        <p v-if="context.reason" role="status">{{ context.reason }}</p>
        <Link v-if="context.legislature_href" :href="context.legislature_href">{{ t('c_institution_components.judicial_confirmations.open_source_legislature', 'Open the source legislature →') }}</Link>
        <p v-if="!nominations.length" role="status">{{ t('c_institution_components.judicial_confirmations.none_recorded', 'No judicial nominations have been recorded for this court.') }}</p>
        <article v-for="nomination in nominations" :key="nomination.id" class="judicial-nomination">
            <header>
                <h3>{{ t('c_institution_components.judicial_confirmations.seat_label', 'Seat {n}', { named: { n: nomination.seat_number ?? t('c_institution_components.judicial_confirmations.seat_record', 'record') } }) }} · {{ statusLabel(nomination.status) }}</h3>
                <SelectionIdentity :person="nomination.nominee" />
                <p>{{ t('c_institution_components.judicial_confirmations.nominated_by', 'Nominated by {who}', { named: { who: nomination.nominated_by } }) }}</p>
            </header>
            <details v-if="nomination.dossier">
                <summary>{{ t('c_institution_components.judicial_confirmations.read_statement', 'Read the nomination statement') }}</summary>
                <p class="nomination-statement">{{ nomination.dossier }}</p>
            </details>
            <p v-if="nomination.term">{{ t('c_institution_components.judicial_confirmations.term', 'Term: {starts} to {ends}', { named: { starts: nomination.term.starts, ends: nomination.term.ends } }) }}</p>
            <ConsentVoteCard v-if="nomination.consent" :consent="nomination.consent" :can-cast="nomination.consent.can_cast" />
            <p v-if="nomination.consent_notice" role="status">{{ nomination.consent_notice }}</p>
        </article>
        <HistoryPager :pages="pages" :first="pages.first || `/judiciaries/${judiciary.id}`"
            :only="['nominations', 'confirmationPages', 'confirmationContext']" cursor-key="confirmations_cursor" :label="t('c_institution_components.judicial_confirmations.pager_label', 'Judicial nomination pages')" />
    </Card>
</template>

<style scoped>
.confirmation-context { margin-block: .75rem; }
.judicial-nomination { border-block-start: 1px solid var(--border, #344054); margin-block-start: 1.25rem; padding-block-start: 1.25rem; }
.judicial-nomination h3 { font-size: 1rem; margin-block-end: .5rem; }
.judicial-nomination summary { min-block-size: 44px; cursor: pointer; }
.nomination-statement { white-space: pre-wrap; overflow-wrap: anywhere; }
</style>
