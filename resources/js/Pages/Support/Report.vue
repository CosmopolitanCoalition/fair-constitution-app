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
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import Field from '@/Components/Ui/Field.vue';

/* Phase-1 pilot surface: rides the v3 player chrome (it is also the tour's
   final stop and the Learn drawer's "Report an issue" target). */
defineOptions({ layout: AppShellV2 });

const { t } = useI18n();

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
    <PageScaffold :title="t('c_front.report.page_title', 'Report a problem')">
        <template #intro>
            {{ t('c_front.report.intro', 'Report a problem — a bug, a question, or something that needs review. You get a reference number back so you can follow up.') }}
        </template>

        <Banner v-if="flashStatus || submitted" tone="info" role="status">
            {{ flashStatus ?? t('c_front.report.filed', 'Report filed.') }}
        </Banner>

        <Banner v-if="isGuest" tone="info">
            {{ t('c_front.report.guest_before', 'You need to be signed in to file a report —') }}
            <Link href="/login" class="prose-link">{{ t('c_front.report.log_in', 'log in') }}</Link> {{ t('c_front.report.guest_after', 'and come back to this page.') }}
        </Banner>

        <Card as="section" :title="t('c_front.report.file_report', 'File a report')">
            <form class="stack" @submit.prevent="submit">
                <Field :label="t('c_front.report.category_label', 'What kind of report is this?')" :error="form.errors.category" required>
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

                <p v-if="routesTo" class="citation">{{ t('c_front.report.goes_to', { target: routesTo }) }}</p>

                <Field
                    :label="t('c_front.report.subject_label', 'A one-line summary (optional)')"
                    :hint="t('c_front.report.subject_hint', 'A short subject helps triage — the details go below.')"
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
                    :label="t('c_front.report.body_label', 'What happened?')"
                    :hint="t('c_front.report.body_hint', 'Plain words are fine. Include what you expected and what you saw instead.')"
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
                    {{ t('c_front.report.abuse_note', 'Abuse and illegal-content reports go to the moderation & legal team, not the support queue. Filing here removes nothing — content removal follows the constitutional carve-outs (the F-SOC-003 machinery), never this form.') }}
                </p>

                <p v-if="form.ref" class="citation">{{ t('c_front.report.filed_from', { ref: form.ref }) }}</p>

                <div class="cluster">
                    <Btn
                        type="submit"
                        variant="primary"
                        :disabled="isGuest || form.processing || !form.body.trim()"
                    >{{ t('c_front.report.submit', 'File report') }}</Btn>
                </div>
            </form>
        </Card>

        <p v-if="!isGuest">
            <Link href="/support/tickets">{{ t('c_front.report.see_filed', 'See the reports you’ve filed →') }}</Link>
        </p>

        <template #about>
            <p>
                {{ t('c_front.report.about', 'Every report is one of six subjects, and each routes to one place — the operators, translation support, moderation & the legal floor, or the product backlog. The intake routes a request; it never edits or removes content itself.') }}
            </p>
        </template>
    </PageScaffold>
</template>
