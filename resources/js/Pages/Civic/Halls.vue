<script setup>
/**
 * Civic/Halls — Phase K-1 (the civic record plane, halls of governance).
 *
 * Deliberation tied to live governance objects. Posting (F-SOC-001) and filing your own post as
 * testimony (F-SOC-002 → the append-only public_records seal, Art. II §2) are residency-only and
 * engine-routed. Halls are append-only; a thread sealed as testimony shows a sealed badge.
 */
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import FormCard from '@/Components/Surface/FormCard.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import Field from '@/Components/Ui/Field.vue';
import Stat from '@/Components/Ui/Stat.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';

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
        .post('/civic/halls', { preserveScroll: true, onSuccess: () => create.reset('title', 'body') });
}

const filing = ref(null);
function fileTestimony(thread, post) {
    filing.value = post.id;
    router.post('/civic/halls/testimony', {
        form_id: 'F-SOC-002',
        jurisdiction_id: thread.jurisdiction_id,
        thread_id: thread.id,
        post_id: post.id,
    }, { preserveScroll: true, onFinish: () => { filing.value = null; } });
}
</script>

<template>
    <PageScaffold :surface="surface">
        <template #intro>
            The halls are where residents deliberate on bills, referendums, petitions, and
            committees. Filing your own post as <em>testimony</em> seals it into the append-only
            public record (Art. II §2) — the post stays in the conversation; the civic act lands
            immutably on the chain.
        </template>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <div class="cluster" style="gap: var(--space-6)">
            <Stat :value="threads.length" label="hall threads in your chain" />
            <Stat value="Art. II §2" label="append-only record" accent />
        </div>

        <Card as="section" title="Deliberation">
            <div v-if="threads.length" class="stack" style="gap: var(--space-3)">
                <Card v-for="thread in threads" :key="thread.id" inset>
                    <span>
                        <strong>{{ thread.title }}</strong>
                        {{ ' ' }}
                        <StatusBadge v-if="thread.sealed" tone="success">sealed as testimony</StatusBadge>
                    </span>
                    <p class="cc-small gloss" style="margin-block-start: var(--space-1)">opened by {{ thread.author_display }}</p>
                    <div class="stack" style="gap: var(--space-2); margin-block-start: var(--space-2)">
                        <div v-for="post in thread.posts" :key="post.id">
                            <p>{{ post.body }}</p>
                            <p class="citation">
                                {{ post.author_display }} · {{ post.at }}
                                <Btn v-if="post.mine" variant="secondary" size="sm" :disabled="filing === post.id"
                                    style="margin-inline-start: var(--space-2)" @click="fileTestimony(thread, post)">
                                    File as testimony
                                </Btn>
                            </p>
                        </div>
                    </div>
                </Card>
            </div>
            <p v-else class="cc-small gloss">No hall threads yet in your association chain.</p>
            <p class="cc-small" style="margin-block-start: var(--space-3)">
                Filing testimony uses
                <span class="form-chip"><span class="form-id" data-no-i18n>F-SOC-002</span></span>
                — it seals YOUR post into the append-only register; you can only file your own.
            </p>
        </Card>

        <FormCard
            v-if="isAssociated && formMeta('F-SOC-001')"
            :form="formMeta('F-SOC-001')"
            :inertia-form="create"
            submit-label="Post to the halls"
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
                        :aria-invalid="invalid ? 'true' : undefined" :aria-describedby="describedBy" />
                </template>
            </Field>
            <Field label="Your statement" :error="create.errors.body" required>
                <template #control="{ id, invalid, describedBy }">
                    <textarea :id="id" v-model="create.body" class="field-input" rows="4"
                        :aria-invalid="invalid ? 'true' : undefined" :aria-describedby="describedBy"></textarea>
                </template>
            </Field>
        </FormCard>
        <Card v-else as="section" title="Posting (F-SOC-001)">
            <p class="gloss">Deliberating in the halls requires an active jurisdictional association (R-03) — Art. I.</p>
            <Btn as="a" href="/civic/residency" variant="primary" size="sm">Declare residency →</Btn>
        </Card>

        <template #about>
            <p>
                Testimony is your own statement entered into the record — you can seal only your own
                posts, only in the halls. A sealed record is immutable and appealable; corrections
                append a new record rather than rewriting the old one.
            </p>
        </template>
    </PageScaffold>
</template>
