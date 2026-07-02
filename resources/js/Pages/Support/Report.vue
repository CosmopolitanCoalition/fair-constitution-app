<script setup>
/**
 * Support/Report — the /support/report intake (mockups-v3-wiring Phase 1).
 *
 * Anyone can SEE the form (public read); filing requires a login (the POST
 * is auth-gated — reports are attributed). Category + body + a hidden `ref`
 * (the page the reporter came from, via ?ref=). The intake ROUTES a
 * request; it removes nothing — conduct/legal reports feed the
 * constitutional carve-out machinery (the judicial F-SOC-003 path).
 *
 * Deliberately simple — restyled in a later phase.
 */
import { computed } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import Field from '@/Components/Ui/Field.vue';

/* Phase-1 pilot surface: rides the v3 player chrome (it is also the tour's
   final stop and the Learn drawer's "Report an issue" target). */
defineOptions({ layout: AppShellV2 });

const props = defineProps({
    categories: { type: Array, default: () => [] },
    // NB: the `ref` page prop can't be a declared Vue prop (`ref` is a reserved
    // vnode attribute) — it is read from the Inertia page store below instead.
    submitted: { type: Boolean, default: false },
});

const page = usePage();
const isGuest = computed(() => !page.props.auth?.user);
const flashStatus = computed(() => page.props.flash?.status ?? null);

const form = useForm({
    category: props.categories[0]?.id ?? 'bug',
    body: '',
    ref: page.props.ref ?? '',
});

const needsReviewNote = computed(() => ['conduct', 'legal'].includes(form.category));

function submit() {
    form.post('/support/report', {
        preserveScroll: true,
        onSuccess: () => form.reset('body'),
    });
}
</script>

<template>
    <PageScaffold title="Report a problem">
        <template #intro>
            Report a problem — a bug, a question, or something that needs review. You get a
            reference number back so you can follow up.
        </template>

        <Banner v-if="flashStatus || submitted" tone="info" role="status">
            {{ flashStatus ?? 'Report filed.' }}
        </Banner>

        <Banner v-if="isGuest" tone="info">
            You need to be signed in to file a report —
            <Link href="/login">log in</Link> and come back to this page.
        </Banner>

        <Card as="section" title="File a report">
            <form class="stack" @submit.prevent="submit">
                <Field label="What kind of report is this?" :error="form.errors.category" required>
                    <template #control="{ id, invalid, describedBy }">
                        <select
                            :id="id"
                            v-model="form.category"
                            class="select"
                            :disabled="isGuest"
                            :aria-invalid="invalid ? 'true' : undefined"
                            :aria-describedby="describedBy"
                        >
                            <option v-for="option in categories" :key="option.id" :value="option.id">
                                {{ option.label }}
                            </option>
                        </select>
                    </template>
                </Field>

                <Field
                    label="What happened?"
                    hint="Plain words are fine. Include what you expected and what you saw instead."
                    :error="form.errors.body"
                    required
                >
                    <template #control="{ id, invalid, describedBy }">
                        <textarea
                            :id="id"
                            v-model="form.body"
                            class="field-input"
                            rows="6"
                            maxlength="5000"
                            :disabled="isGuest"
                            :aria-invalid="invalid ? 'true' : undefined"
                            :aria-describedby="describedBy"
                        ></textarea>
                    </template>
                </Field>

                <p v-if="needsReviewNote" class="gloss">
                    Conduct and legal reports are routed for review. Filing here does not remove
                    anything — content removal follows the constitutional carve-outs (the F-SOC-003
                    machinery), never this form.
                </p>

                <p v-if="form.ref" class="citation">Filed from: {{ form.ref }}</p>

                <div class="cluster">
                    <Btn
                        type="submit"
                        variant="primary"
                        :disabled="isGuest || form.processing || !form.body.trim()"
                    >File report</Btn>
                </div>
            </form>
        </Card>

        <template #about>
            <p>
                Reports land in the operator's support inbox and are routed by category. This
                intake never edits or removes content itself.
            </p>
        </template>
    </PageScaffold>
</template>
