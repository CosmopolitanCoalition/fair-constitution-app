<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import FormCard from '@/Components/Surface/FormCard.vue';
import AdmChip from '@/Components/Ui/AdmChip.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import Field from '@/Components/Ui/Field.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import TagChip from '@/Components/Ui/TagChip.vue';
import ReferenceText from '@/Components/Ui/ReferenceText.vue';

defineOptions({ layout: AppShellV2 });
const props = defineProps({
    surface: { type: Object, required: true },
    directory: { type: Object, required: true },
    filters: { type: Object, required: true },
    createForm: { type: Object, required: true },
    isAssociated: { type: Boolean, default: false },
});
const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);
const formMeta = (id) => props.surface.forms.find((f) => f.id === id);
const titleize = (s) => s === 'common_good_corp' ? 'Common Good Corporation' : s ? String(s).replaceAll('_', ' ') : '—';
const fmt = (n) => Number(n ?? 0).toLocaleString();
const search = ref({ ...props.filters.selected });
const loading = ref(false);
const registrationOpen = ref(false);
watch(() => props.filters.selected, (value) => { search.value = { ...value }; });
function visit(url, data = {}) {
    router.get(url, data, {
        only: ['directory', 'filters', 'jurisdictionContext'], preserveState: true,
        onStart: () => { loading.value = true; },
        onFinish: () => { loading.value = false; },
    });
}
function applyFilters() {
    visit('/organizations', Object.fromEntries(Object.entries(search.value).filter(([,value]) => value !== '')));
}
function clearFilters() { search.value = {q:'',type:'',structure:'',jurisdiction:''}; applyFilters(); }
const hasFilters = computed(() => Object.values(props.filters.selected).some(Boolean));
const registerForm = useForm({
    type: props.createForm.types[0] ?? 'business',
    structure: props.createForm.structures[0]?.value ?? null,
    name: '', jurisdiction_id: props.createForm.jurisdictionOptions[0]?.id ?? null, purpose: '',
});
watch(() => registerForm.type, type => {
    registerForm.structure = type === 'informal' ? null : registerForm.structure ?? props.createForm.structures[0]?.value ?? null;
});
const selectedStructureGloss = computed(() => props.createForm.structures.find(s => s.value === registerForm.structure)?.rule_gloss ?? null);
function submitRegistration() { registerForm.post('/organizations', {preserveScroll: true}); }
const columns = [
    {key:'name',label:'Organization'}, {key:'type',label:'Type / structure'},
    {key:'workers',label:'Workers',mono:true,align:'right'},
    {key:'board',label:'Worker representation'},
    {key:'endorsement_count',label:'Endorsements',mono:true,align:'right'},
];
</script>

<template>
    <PageScaffold :surface="surface" title="Organizations">
        <template #intro>Find an organization, review its work, or start one. Browse the world or narrow the list to a place.</template>
        <Banner v-if="flashStatus" tone="info" role="status"><ReferenceText>{{ flashStatus }}</ReferenceText></Banner>
        <Banner v-if="constitutionError" tone="emergency" role="alert"><ReferenceText>{{ constitutionError }}</ReferenceText></Banner>
        <Card as="section" title="Find an organization">
            <form class="directory-filters" @submit.prevent="applyFilters">
                <label>Name begins with<input v-model="search.q" type="search" maxlength="120" placeholder="e.g. Anne Arundel" class="field-input" /></label>
                <label>Type<select v-model="search.type" class="select"><option value="">All types</option><option v-for="type in filters.types" :key="type" :value="type">{{ titleize(type) }}</option></select></label>
                <label>Structure<select v-model="search.structure" class="select"><option value="">All structures</option><option v-for="type in filters.structures" :key="type" :value="type">{{ titleize(type) }}</option></select></label>
                <label>Place<select v-model="search.jurisdiction" class="select"><option value="">Worldwide</option><option v-for="place in filters.jurisdictions" :key="place.id" :value="place.id">{{ place.name }}</option></select></label>
                <Btn type="submit" :disabled="loading">Search</Btn>
                <Btn v-if="hasFilters" variant="ghost" :disabled="loading" @click="clearFilters">Clear filters</Btn>
            </form>
            <p class="cc-small">Search by the start of a name. <Link href="/jurisdictions">Browse places around the world</Link> to explore organizations elsewhere.</p>
            <div :aria-busy="loading" class="directory-results">
                <p role="status" aria-live="polite">{{ loading ? 'Loading organizations…' : directory.organizations.length + ' organizations on this page' }}</p>
                <DataTable v-if="directory.organizations.length" :columns="columns" :rows="directory.organizations" row-key="id" caption="Organizations on this page">
                    <template #cell-name="{row}">
                        <Link :href="row.href"><strong>{{ row.name }}</strong></Link>
                        <StatusBadge v-if="row.monopoly_pending" tone="warning">Acquisition under review</StatusBadge>
                        <span v-if="row.jurisdiction" class="directory-place"><AdmChip :level="row.jurisdiction.adm_level" :label="row.jurisdiction.name" /></span>
                    </template>
                    <template #cell-type="{row}"><TagChip>{{ titleize(row.type) }}</TagChip><span v-if="row.structure" class="directory-place">{{ titleize(row.structure) }}</span></template>
                    <template #cell-workers="{row}">{{ fmt(row.workers) }}</template>
                    <template #cell-board="{row}">
                        <Link v-if="row.board" :href="'/organizations/co-determination?org=' + row.id">{{ fmt(row.board.worker_seats) }} worker seats required</Link>
                        <span v-else>No board recorded</span>
                    </template>
                    <template #cell-endorsement_count="{row}">{{ fmt(row.endorsement_count) }}</template>
                </DataTable>
                <Banner v-else tone="info" role="status" title="No organizations match this search.">Try another name prefix or clear a filter.</Banner>
                <nav class="directory-pages" aria-label="Organization directory pages">
                    <Link v-if="directory.previous" :href="directory.previous" :only="['directory','filters','jurisdictionContext']" rel="prev">Previous organizations</Link>
                    <Link v-if="directory.next" :href="directory.next" :only="['directory','filters','jurisdictionContext']" rel="next">Next organizations</Link>
                </nav>
            </div>
        </Card>
        <details v-if="isAssociated" class="registration-disclosure" @toggle="registrationOpen = $event.target.open">
            <summary>Start an organization or club</summary>
            <p>Choose informal for a club. Other organization types can specify their ownership structure.</p>
        <FormCard
            v-if="isAssociated && registrationOpen"
            :form="formMeta('F-IND-012')"
            :inertia-form="registerForm"
            submit-label="Register organization"
            processing-label="Registering…"
            @submit="submitRegistration"
        >
            <Field label="Name" :error="registerForm.errors.name" required>
                <template #control="{ id, describedBy, invalid }">
                    <input :id="id" v-model="registerForm.name" class="field-input" :aria-invalid="invalid || undefined" :aria-describedby="describedBy" />
                </template>
            </Field>

            <Field label="Type" :error="registerForm.errors.type">
                <template #control="{ id }">
                    <select :id="id" v-model="registerForm.type" class="select">
                        <option v-for="type in createForm.types" :key="type" :value="type">{{ titleize(type) }}</option>
                    </select>
                </template>
            </Field>

            <Field v-if="registerForm.type !== 'informal'" label="Ownership structure" :error="registerForm.errors.structure" :hint="selectedStructureGloss">
                <template #control="{ id, describedBy }">
                    <select :id="id" v-model="registerForm.structure" class="select" :aria-describedby="describedBy">
                        <option v-for="s in createForm.structures" :key="s.value" :value="s.value">{{ s.label }}</option>
                    </select>
                </template>
            </Field>

            <Field label="Jurisdiction" :error="registerForm.errors.jurisdiction_id">
                <template #control="{ id }">
                    <select :id="id" v-model="registerForm.jurisdiction_id" class="select">
                        <option v-for="j in createForm.jurisdictionOptions" :key="j.id" :value="j.id">{{ j.name }}</option>
                    </select>
                </template>
            </Field>

            <Field label="Purpose" :error="registerForm.errors.purpose">
                <template #control="{ id }">
                    <textarea :id="id" v-model="registerForm.purpose" class="field-input" rows="2"></textarea>
                </template>
            </Field>

            <template #actions>
                <span class="citation">
                    Common Good Corporations are not self-registered — the legislature creates them by act
                    under the public-service creation process.
                </span>
            </template>
        </FormCard>

        </details>
        <Card v-if="!isAssociated" as="section" title="Registering an organization">
            <Banner tone="info" role="status" title="Confirm residency to register.">
                Registration is an absolute right of any associated resident (Art. I, Economic Freedom) —
                you just need an active residency association first.
            </Banner>
            <p class="cc-small" style="margin-block-start: var(--space-3)">
                <Link href="/civic">Confirm your residency →</Link>
            </p>
        </Card>

        <template #about><p>Any person or organization can endorse a candidate. Organization types do not confer special election privileges. Open an organization to see its board, finances and membership options.</p></template>
    </PageScaffold>
</template>

<style scoped>
.directory-filters { display: flex; flex-wrap: wrap; align-items: end; gap: 1rem; }
.directory-filters label { display: grid; gap: .4rem; flex: 1 1 12rem; min-inline-size: 0; }
.directory-filters input, .directory-filters select { inline-size: 100%; min-inline-size: 0; }
.directory-place { display: block; margin-block-start: .25rem; }
.directory-pages { display: flex; flex-wrap: wrap; gap: 1rem; margin-block-start: 1rem; }
.directory-pages a { display: inline-flex; align-items: center; min-block-size: 44px; padding: .5rem 1rem; border: 1px solid var(--gov-border); border-radius: .5rem; }
.registration-disclosure { border: 1px solid var(--gov-border); border-radius: .5rem; padding: 1rem; }
.registration-disclosure summary { cursor: pointer; font-weight: 600; min-block-size: 44px; }
.directory-pages a:focus-visible, .registration-disclosure summary:focus-visible { outline: 3px solid var(--gov-accent); outline-offset: 3px; }
</style>
