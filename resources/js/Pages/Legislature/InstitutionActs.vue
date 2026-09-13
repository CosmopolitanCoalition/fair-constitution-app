<script setup>
import { computed, reactive, ref } from 'vue';
import { Link, router, usePage, useRemember } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import LegislatureWorkspaceNav from '@/Components/Legislature/LegislatureWorkspaceNav.vue';
import ConsentVoteCard from '@/Components/Legislature/ConsentVoteCard.vue';
import HistoryPager from '@/Components/Ui/HistoryPager.vue';
defineOptions({ layout: AppShellV2 });
const props = defineProps({ surface: Object, workspace: Object, legislature: Object, context: Object, filingUrl: String,
    initialAction: String, proposals: Object, processes: Object, constituents: Object });
const action = ref(props.initialAction);
const values = useRemember(reactive({}), 'institution-act-draft:' + props.legislature.id);
const busy = ref(false);
const error = ref('');
const page = usePage();
const selected = computed(() => props.context.actions.find(item => item.key === action.value));
const input = (key, label, type = 'text', options = null, hint = '') => ({ key, label, type, options, hint });
const fields = computed(() => ({
    'delegate-executive': [input('delegated_scope', 'Powers delegated to the executive', 'textarea'), input('member_count', 'Executive committee seats', 'number'), input('interested', 'I am interested in serving on this committee', 'checkbox')],
    'elect-executive': [input('target_type', 'Elected executive structure', 'select', [{ value: 'committee', label: 'Executive committee' }, { value: 'individual', label: 'Individual executive with advisers' }]), input('member_count', 'Committee seats (for a committee executive)', 'number'), input('charter_text', 'Charter of the elected executive', 'textarea')],
    'create-department': [input('name', 'Department name'), input('kind', 'Department function', 'select', [{value:'chief_executive',label:'Chief executive'}, {value:'treasury',label:'Treasury'}, {value:'defense',label:'Defense'}, {value:'state',label:'State'}, {value:'justice',label:'Justice'}, {value:'other',label:'Other'}]), input('function_text', 'Responsibilities', 'textarea'), input('powers_text', 'Powers (optional)', 'textarea'), input('owner_seats', 'Governor seats', 'number'), input('reporting_interval_months', 'Report interval in months (optional)', 'number')],
    'create-court': [input('court_name', 'Court name'), input('function_text', 'Court responsibilities and jurisdiction', 'textarea'), input('judges_per_constituent', 'Judges nominated by each constituent', 'number', null, 'Used when this jurisdiction has constituent legislatures.'), input('committee_judge_count', 'Judges selected through a legislative committee', 'number', null, 'Used when there are no constituent legislatures. The court’s configured minimum is ' + (props.context.court?.minimumJudges ?? 'shown by the filing check') + '.')],
    'elect-court': [input('judge_count', 'Elected judges', 'number'), input('charter_text', 'Charter of the elected court', 'textarea')],
    'create-cgc': [input('name', 'Corporation name'), input('goods_services', 'Goods and services (optional)', 'textarea'), input('charter', 'Public charter', 'textarea'), input('owner_seats', 'Governor seats', 'number')],
}[action.value] ?? []));
function submit() {
    if (!props.context.canFile || !selected.value?.ready || busy.value) return;
    const payload = { action: action.value };
    for (const field of fields.value) {
        const value = values[action.value + ':' + field.key];
        if (value !== undefined && value !== '') payload[field.key] = value;
    }
    send(props.filingUrl, payload);
}
function send(url, payload) {
    if (busy.value) return;
    router.post(url, payload, { preserveScroll: true,
        onStart: () => { busy.value = true; error.value = ''; },
        onError: errors => { error.value = Object.values(errors).flat().join(' ') || 'The filing could not be completed. Your draft is retained.'; },
        onFinish: () => { busy.value = false; },
    });
}
function openConsent(process) { if (process.canOpen) send(process.open_url, {}); }
</script>

<template>
    <PageScaffold :surface="surface" :title="'Institution acts — ' + legislature.name">
        <LegislatureWorkspaceNav :workspace="workspace" active="institutions" />
        <p v-if="page.props.flash?.status" role="status">{{ page.props.flash.status }}</p>
        <section class="act-compose" aria-labelledby="act-compose-heading" :aria-busy="busy">
            <div><h2 id="act-compose-heading">Propose an institution act</h2><p>{{ context.role }} · {{ legislature.name }}</p></div>
            <p v-if="!context.canFile" class="preview">Explore the forms below. Filing requires a current seat in this legislature; previewing a role does not create one.</p>
            <form @submit.prevent="submit">
                <label for="institution-action">Action</label>
                <select id="institution-action" v-model="action" :disabled="busy"><option v-for="item in context.actions" :key="item.key" :value="item.key">{{ item.name }}</option></select>
                <p v-if="selected?.reason" role="status">{{ selected.reason }}</p>
                <div v-for="field in fields" :key="action + field.key" class="act-field">
                    <label :for="'act-' + field.key">{{ field.label }}</label>
                    <textarea v-if="field.type === 'textarea'" :id="'act-' + field.key" v-model="values[action + ':' + field.key]" rows="4" :disabled="busy" :aria-describedby="field.hint ? 'act-hint-' + field.key : undefined" />
                    <select v-else-if="field.type === 'select'" :id="'act-' + field.key" v-model="values[action + ':' + field.key]" :disabled="busy"><option value="">Choose…</option><option v-for="option in field.options" :key="option.value" :value="option.value">{{ option.label }}</option></select>
                    <input v-else :id="'act-' + field.key" v-model="values[action + ':' + field.key]" :type="field.type" :min="field.type === 'number' ? 1 : undefined" :step="field.type === 'number' ? 1 : undefined" :disabled="busy" :aria-describedby="field.hint ? 'act-hint-' + field.key : undefined" />
                    <p v-if="field.hint" :id="'act-hint-' + field.key" class="hint">{{ field.hint }}</p>
                </div>
                <p v-if="['create-department', 'create-cgc'].includes(action)">Governor nominations follow creation in the institution’s appointments workspace.</p>
                <p v-if="['create-department', 'create-cgc'].includes(action) && ['delegated', 'elected'].includes(context.executive?.status)"><Link :href="context.executive.href">View the overseeing executive for this jurisdiction</Link></p>
                <p v-if="action === 'create-cgc' && !['delegated', 'elected'].includes(context.executive?.status)">This charter will have no overseeing executive assigned. Governor nominations need an assigned executive.</p>
                <button type="submit" :disabled="busy || !context.canFile || !selected?.ready">{{ busy ? 'Filing proposal…' : 'File proposal for a public vote' }}</button>
                <p v-if="error" role="alert">{{ error }}</p>
            </form>
        </section>
        <section aria-labelledby="institution-proposals"><h2 id="institution-proposals">Proposals and decisions</h2>
            <p v-if="!proposals?.records.length">No institution proposals on this page.</p>
            <article v-for="proposal in proposals?.records" :key="proposal.id" class="act-record">
                <h3>{{ proposal.name }}</h3><p>{{ proposal.action }} · {{ proposal.status }}<span v-if="proposal.filed_at"> · {{ new Date(proposal.filed_at).toLocaleDateString() }}</span></p>
                <details><summary>Read the proposed act</summary><dl><template v-for="detail in proposal.details" :key="detail.label"><dt>{{ detail.label }}</dt><dd>{{ detail.value }}</dd></template></dl></details>
                <ConsentVoteCard v-if="proposal.vote" :consent="proposal.vote" :can-cast="proposal.vote.can_cast" />
                <Link v-if="proposal.result_href" :href="proposal.result_href">Open resulting institution</Link>
            </article>
            <HistoryPager v-if="proposals" :pages="proposals.pagination" :first="proposals.pagination.first" :only="['proposals']" cursor-key="acts_cursor" label="Institution proposals" />
        </section>
        <section aria-labelledby="institution-consents"><h2 id="institution-consents">Constituent consent</h2>
            <p v-if="constituents"><Link :href="filingUrl">All conversion processes</Link></p>
            <p v-if="!processes?.records.length">No executive or court conversion processes for this legislature on this page.</p>
            <article v-for="process in processes?.records" :key="process.id" class="act-record">
                <h3>{{ process.name }}</h3><p>{{ process.status }} · {{ process.yes }} of {{ process.required }} required jurisdictions agree ({{ process.total }} total).</p>
                <Link :href="process.href">Browse constituent decisions</Link>
                <p v-if="process.local_result">This jurisdiction: {{ process.local_result }}</p>
                <button v-if="process.canOpen" type="button" :disabled="busy" @click="openConsent(process)">Open this legislature’s consent vote</button>
                <ConsentVoteCard v-if="process.vote" :consent="process.vote" :can-cast="process.vote.can_cast" />
            </article>
            <HistoryPager v-if="processes" :pages="processes.pagination" :first="processes.pagination.first" :only="['processes']" cursor-key="processes_cursor" label="Conversion processes" />
        </section>
        <section v-if="constituents" aria-labelledby="constituent-list"><h2 id="constituent-list">{{ constituents.name }}</h2>
            <ul><li v-for="place in constituents.records" :key="place.id"><Link v-if="place.href" :href="place.href">{{ place.name }}</Link><span v-else>{{ place.name }}</span> · {{ place.result }}</li></ul>
            <HistoryPager :pages="constituents.pagination" :first="constituents.pagination.first" :only="['constituents']" cursor-key="consents_cursor" label="Constituent jurisdictions" />
        </section>
        <template #about><p>Create the institution by a public act, then fill its offices through the dedicated appointment or election process. The act’s vote meter shows the governing threshold. Executive and court conversions also require constituent consent where the jurisdiction has constituents.</p><p>Legislators cast public votes; the Speaker’s separate control appears only for an eligible tie. A preview never grants an office or bypasses a filing check.</p></template>
    </PageScaffold>
</template>

<style scoped>
.act-compose,.act-record { padding: 1.2rem; border: 1px solid var(--gov-border); border-radius: var(--radius-md); margin-block: 1rem; }
form,.act-field { display:grid; gap:.5rem; } form { max-width:54rem; gap:1rem; }
input,select,textarea,button { font:inherit; max-width:100%; } input,select,textarea { padding:.6rem; border:1px solid var(--gov-border); border-radius:.35rem; background:var(--gov-surface); color:inherit; }
input[type=checkbox] { justify-self:start; inline-size:1.3rem; block-size:1.3rem; }
button,summary,a { min-height:44px; } button { padding:.6rem 1rem; cursor:pointer; } button:disabled { cursor:default; opacity:.6; }
summary { cursor:pointer; padding-block:.6rem; } dd { white-space:pre-wrap; overflow-wrap:anywhere; margin:.3rem 0 1rem; } dt { font-weight:600; }
.hint,.preview { color:var(--gov-fg-muted); } :is(input,select,textarea,button,summary,a):focus-visible { outline:3px solid var(--gov-accent); outline-offset:3px; }
section { margin-block:1.8rem; min-width:0; }
</style>
