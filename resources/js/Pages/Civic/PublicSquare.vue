<script setup>
/**
 * Civic/PublicSquare — Phase K-1 (the civic record plane, public square).
 *
 * Open resident discourse scoped to the viewer's association chain. Posting (F-SOC-001) is
 * residency-only (Art. I) — the page never 403s an un-associated viewer; it reads (public) and
 * shows the residency CTA instead of the form. There is NO removal control: the square is
 * uncensorable; the only removals are the four office-gated carve-outs, each logged.
 */
import { computed } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import FormCard from '@/Components/Surface/FormCard.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import Field from '@/Components/Ui/Field.vue';
import Stat from '@/Components/Ui/Stat.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    threads: { type: Array, default: () => [] },
    jurisdictions: { type: Array, default: () => [] },
    isAssociated: { type: Boolean, default: false },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);
const formMeta = (id) => props.surface.forms?.find((f) => f.id === id);

const create = useForm({
    jurisdiction_id: props.jurisdictions[0]?.id ?? null,
    title: '',
    body: '',
});

function submitCreate() {
    create
        .transform((d) => ({ ...d, form_id: 'F-SOC-001' }))
        .post('/civic/square', { preserveScroll: true, onSuccess: () => create.reset('title', 'body') });
}
</script>

<template>
    <PageScaffold :surface="surface">
        <template #intro>
            The public square is open to every resident of the jurisdiction. Anyone associated may
            post; no one — operator, legislator, or judge — may remove a post on viewpoint. The only
            removals are four narrow, logged carve-outs (Art. I).
        </template>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <div class="cluster" style="gap: var(--space-6)">
            <Stat :value="threads.length" label="threads in your association chain" />
            <Stat value="Art. I" label="uncensorable" accent />
        </div>

        <Card as="section" title="Recent threads">
            <p class="citation" style="margin-block-end: var(--space-3)">scoped to your association chain</p>
            <div v-if="threads.length" class="stack" style="gap: var(--space-3)">
                <Card v-for="thread in threads" :key="thread.id" inset>
                    <strong>{{ thread.title }}</strong>
                    <p class="cc-small gloss" style="margin-block-start: var(--space-1)">opened by {{ thread.author_display }}</p>
                    <div class="stack" style="gap: var(--space-2); margin-block-start: var(--space-2)">
                        <div v-for="post in thread.posts" :key="post.id">
                            <p>{{ post.body }}</p>
                            <p class="citation">{{ post.author_display }} · {{ post.at }}</p>
                        </div>
                    </div>
                </Card>
            </div>
            <p v-else class="cc-small gloss">No threads yet — any associated resident can open one.</p>
        </Card>

        <FormCard
            v-if="isAssociated && formMeta('F-SOC-001')"
            :form="formMeta('F-SOC-001')"
            :inertia-form="create"
            submit-label="Post to the square"
            @submit="submitCreate"
        >
            <Field label="Jurisdiction" :error="create.errors.jurisdiction_id">
                <template #control="{ id }">
                    <select :id="id" v-model="create.jurisdiction_id" class="select">
                        <option v-for="j in jurisdictions" :key="j.id" :value="j.id">{{ j.name }}</option>
                    </select>
                </template>
            </Field>
            <Field label="Title" :error="create.errors.title" required>
                <template #control="{ id, invalid, describedBy }">
                    <input :id="id" v-model="create.title" class="field-input" type="text"
                        placeholder="What is this about?" :aria-invalid="invalid ? 'true' : undefined" :aria-describedby="describedBy" />
                </template>
            </Field>
            <Field label="Your post" :error="create.errors.body" required>
                <template #control="{ id, invalid, describedBy }">
                    <textarea :id="id" v-model="create.body" class="field-input" rows="4"
                        :aria-invalid="invalid ? 'true' : undefined" :aria-describedby="describedBy"></textarea>
                </template>
            </Field>
        </FormCard>
        <Card v-else as="section" title="Posting (F-SOC-001)">
            <p class="gloss">
                Posting in the square requires an active jurisdictional association (R-03) — the same
                gate as voting, and the only one (Art. I).
            </p>
            <Btn as="a" href="/civic/residency" variant="primary" size="sm">Declare residency →</Btn>
        </Card>

        <template #about>
            <p>
                Reactions, follows, and per-user blocks stay local to your device and never federate.
                A post can be removed only under a logged carve-out (a judicial order, or protecting
                another's rights) — every such removal is itself a public, appealable record.
            </p>
        </template>
    </PageScaffold>
</template>
