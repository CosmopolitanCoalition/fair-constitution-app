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
    subject: '',
    body: '',
    ref: page.props.ref ?? '',
});

const selected = computed(() => props.categories.find((c) => c.id === form.category) ?? null);
const routesTo = computed(() => selected.value?.routesTo ?? null);
/* Abuse rides the moderation & legal floor — off the tech-support queue. */
const isAbuse = computed(() => selected.value?.target === 'moderation');

function submit() {
    form.post('/support/report', {
        preserveScroll: true,
        onSuccess: () => form.reset('subject', 'body'),
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

                <p v-if="routesTo" class="citation">Goes to: {{ routesTo }}</p>

                <Field
                    label="A one-line summary (optional)"
                    hint="A short subject helps triage — the details go below."
                    :error="form.errors.subject"
                >
                    <template #control="{ id, invalid, describedBy }">
                        <input
                            :id="id"
                            v-model="form.subject"
                            type="text"
                            class="field-input"
                            maxlength="160"
                            :disabled="isGuest"
                            :aria-invalid="invalid ? 'true' : undefined"
                            :aria-describedby="describedBy"
                        />
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

                <p v-if="isAbuse" class="gloss">
                    Abuse and illegal-content reports go to the moderation &amp; legal team, not the
                    support queue. Filing here removes nothing — content removal follows the
                    constitutional carve-outs (the F-SOC-003 machinery), never this form.
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

        <p v-if="!isGuest">
            <Link href="/support/tickets">See the reports you’ve filed →</Link>
        </p>

        <template #about>
            <p>
                Every report is one of six subjects, and each routes to one place — the operators,
                translation support, moderation &amp; the legal floor, or the product backlog. The
                intake routes a request; it never edits or removes content itself.
            </p>
        </template>
    </PageScaffold>
</template>
