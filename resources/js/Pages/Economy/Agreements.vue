<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Card from '@/Components/Ui/Card.vue';
import WorkTradeNav from '@/Components/Economy/WorkTradeNav.vue';

defineOptions({ layout: AppShellV2 });
const props = defineProps({
    agreements: { type: Array, default: () => [] },
    pagination: { type: Object, default: () => ({}) },
});
const groups = computed(() => [
    { key: 'org', title: 'Work & sales', hint: 'Agreements with organizations you work with or belong to.', rows: props.agreements.filter(a => a.family === 'org') },
    { key: 'resident', title: 'Between people', hint: 'Agreements you offered or were invited to sign.', rows: props.agreements.filter(a => a.family === 'resident') },
]);
const kinds = { labor_recurring: 'Ongoing work', labor_single: 'One-off work', commercial: 'Sale', other: 'Other agreement' };
const status = { draft: 'Draft', offered: 'Awaiting signatures', active: 'Active', ended: 'Ended', voided: 'Voided' };
</script>

<template>
    <PageScaffold title="My agreements">
        <template #intro>Review terms, signatures and proposed changes in the agreements available to you.</template>
        <WorkTradeNav active="agreements" />
        <div class="agreement-actions">
            <Link href="/economy/resident-agreements?new=1" class="agreement-primary">Offer an agreement between people</Link>
            <Link href="/economy/joint-ledgers">Manage shared funds</Link>
        </div>
        <p class="agreement-note">Agreement terms are private. Work and sale agreements appear when the corresponding transaction is recorded.</p>

        <Card v-for="group in groups" :key="group.key" as="section" :title="group.title">
            <p class="agreement-note">{{ group.hint }}</p>
            <p v-if="!group.rows.length">No agreements in this section.</p>
            <ul v-else class="agreement-list">
                <li v-for="agreement in group.rows" :key="agreement.id">
                    <div class="agreement-row">
                        <div>
                            <span class="agreement-kind">{{ agreement.family === 'org' ? (kinds[agreement.kind] ?? 'Agreement') : 'Personal agreement' }}</span>
                            <h3><Link :href="agreement.href">{{ agreement.family === 'org' ? `${agreement.org_name} — ${agreement.counterparty}` : agreement.title }}</Link></h3>
                        </div>
                        <span>{{ status[agreement.status] ?? agreement.status }}</span>
                    </div>
                    <p v-if="agreement.family === 'org'" class="agreement-note">
                        {{ agreement.org_name }}: {{ agreement.signed_by_org ? 'signed' : 'awaiting signature' }} ·
                        {{ agreement.counterparty }}: {{ agreement.signed_by_counterparty ? 'signed' : 'awaiting signature' }}
                    </p>
                    <p v-else class="agreement-note">
                        <span v-for="(signer, index) in agreement.signers" :key="index">
                            <template v-if="index"> · </template>{{ signer.name }}{{ signer.is_me ? ' (you)' : '' }}: {{ signer.signed ? 'signed' : 'awaiting signature' }}
                        </span>
                    </p>
                </li>
            </ul>
            <nav v-if="pagination[group.key]?.previous || pagination[group.key]?.next" class="agreement-pager" :aria-label="`${group.title} pages`">
                <Link v-if="pagination[group.key].previous" :href="pagination[group.key].previous" preserve-scroll>Newer agreements</Link>
                <Link v-if="pagination[group.key].next" :href="pagination[group.key].next" preserve-scroll>Older agreements</Link>
            </nav>
        </Card>
    </PageScaffold>
</template>

<style scoped>
.agreement-actions, .agreement-row, .agreement-pager { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem 1rem; }
.agreement-actions a, .agreement-pager a { display: inline-flex; align-items: center; min-block-size: 44px; padding: .5rem .75rem; }
.agreement-primary { border: 1px solid var(--gov-accent); border-radius: .4rem; font-weight: 600; }
.agreement-note, .agreement-kind { color: var(--gov-fg-muted); font-size: .875rem; }
.agreement-list { padding: 0; margin: 0; list-style: none; }
.agreement-list li { padding-block: 1rem; border-block-start: 1px solid var(--gov-border); }
.agreement-row { justify-content: space-between; align-items: baseline; }
.agreement-row h3 { margin: .3rem 0; font-size: 1rem; }
.agreement-row h3 a { display: inline-block; padding-block: .4rem; }
.agreement-pager { justify-content: space-between; margin-block-start: .75rem; }
a:focus-visible { outline: 3px solid var(--gov-accent); outline-offset: 3px; }
</style>
