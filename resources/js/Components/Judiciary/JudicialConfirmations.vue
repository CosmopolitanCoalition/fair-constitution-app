<script setup>
import { Link } from '@inertiajs/vue3';
import Card from '@/Components/Ui/Card.vue';
import HistoryPager from '@/Components/Ui/HistoryPager.vue';
import SelectionIdentity from '@/Components/Ui/SelectionIdentity.vue';
import ConsentVoteCard from '@/Components/Legislature/ConsentVoteCard.vue';

defineProps({
    judiciary: { type: Object, required: true },
    nominations: { type: Array, default: () => [] },
    pages: { type: Object, default: () => ({}) },
    context: { type: Object, default: () => ({ preview: true }) },
});
const labels = { nominated: 'Awaiting confirmation', consented: 'Confirmed', rejected: 'Not confirmed', withdrawn: 'Withdrawn' };
</script>

<template>
    <Card id="judicial-confirmations" as="section" title="Judicial nominations and confirmation">
        <p v-if="context.preview" class="confirmation-context">Role preview: members of this court’s source legislature review nominees and vote on confirmation.</p>
        <p v-else class="confirmation-context">Participating as {{ context.actor_name }}<span v-if="context.is_speaker">, Speaker of the source legislature</span>.</p>
        <p v-if="context.reason" role="status">{{ context.reason }}</p>
        <Link v-if="context.legislature_href" :href="context.legislature_href">Open the source legislature →</Link>
        <p v-if="!nominations.length" role="status">No judicial nominations have been recorded for this court.</p>
        <article v-for="nomination in nominations" :key="nomination.id" class="judicial-nomination">
            <header>
                <h3>Seat {{ nomination.seat_number ?? 'record' }} · {{ labels[nomination.status] ?? nomination.status }}</h3>
                <SelectionIdentity :person="nomination.nominee" />
                <p>Nominated by {{ nomination.nominated_by }}</p>
            </header>
            <details v-if="nomination.dossier">
                <summary>Read the nomination statement</summary>
                <p class="nomination-statement">{{ nomination.dossier }}</p>
            </details>
            <p v-if="nomination.term">Term: {{ nomination.term.starts }} to {{ nomination.term.ends }}</p>
            <ConsentVoteCard v-if="nomination.consent" :consent="nomination.consent" :can-cast="nomination.consent.can_cast" />
            <p v-if="nomination.consent_notice" role="status">{{ nomination.consent_notice }}</p>
        </article>
        <HistoryPager :pages="pages" :first="pages.first || `/judiciaries/${judiciary.id}`"
            :only="['nominations', 'confirmationPages', 'confirmationContext']" cursor-key="confirmations_cursor" label="Judicial nomination pages" />
    </Card>
</template>

<style scoped>
.confirmation-context { margin-block: .75rem; }
.judicial-nomination { border-block-start: 1px solid var(--border, #344054); margin-block-start: 1.25rem; padding-block-start: 1.25rem; }
.judicial-nomination h3 { font-size: 1rem; margin-block-end: .5rem; }
.judicial-nomination summary { min-block-size: 44px; cursor: pointer; }
.nomination-statement { white-space: pre-wrap; overflow-wrap: anywhere; }
</style>
