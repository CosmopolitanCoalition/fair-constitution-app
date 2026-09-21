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
import { computed, ref as vueRef } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import Field from '@/Components/Ui/Field.vue';
import { clearReportDraft, githubReport, saveReportDraft, takeReportDraft } from '@/lib/supportReport';

/* Phase-1 pilot surface: rides the v3 player chrome (it is also the tour's
   final stop and the Learn drawer's "Report an issue" target). */
defineOptions({ layout: AppShellV2 });

const { t } = useI18n();

const props = defineProps({
    categories: { type: Array, default: () => [] },
    // NB: the `ref` page prop can't be a declared Vue prop (`ref` is a reserved
    // vnode attribute) — it is read from the Inertia page store below instead.
    submitted: { type: Boolean, default: false },
    githubRepository: { type: String, default: null },
});

const page = usePage();
const isGuest = computed(() => !page.props.auth?.user);
const flashStatus = computed(() => page.props.flash?.status ?? null);
const draftError = vueRef('');

const form = useForm({
    category: props.categories[0]?.id ?? 'bug',
    subject: '',
    body: '',
    ref: page.props.ref ?? '',
});
// Store only when the reporter explicitly leaves for sign-in/GitHub. The draft
// is tab-local, expires after 30 minutes and is removed on restoration or filing.
try {
    const draft = takeReportDraft(sessionStorage, form.ref, props.categories.map(c => c.id));
    if (draft) Object.assign(form, draft);
} catch { /* A browser may disable storage; editing still works. */ }

const selected = computed(() => props.categories.find((c) => c.id === form.category) ?? null);
const routesTo = computed(() => selected.value?.routesTo ?? null);
/* Abuse rides the moderation & legal floor — off the tech-support queue. */
const isAbuse = computed(() => selected.value?.target === 'moderation');
const github = computed(() => githubReport(props.githubRepository, form, window.location.origin, selected.value?.label || 'Report'));

function keepDraft() {
    try { return saveReportDraft(sessionStorage, form); } catch { return false; }
}

function signIn() {
    if (!keepDraft() && (form.subject || form.body)) {
        draftError.value = t('c_report.storage_unavailable');
        return;
    }
    const to = `/support/report?ref=${encodeURIComponent(form.ref)}`;
    window.location.assign(`/continue?mode=login&to=${encodeURIComponent(to)}`);
}

function openGithub() {
    if (!github.value || !form.body.trim()) return;
    keepDraft();
    window.location.assign(github.value.url);
}

function submit() {
    if (isGuest.value) { signIn(); return; }
    form.post('/support/report', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('subject', 'body');
            try { clearReportDraft(sessionStorage); } catch { /* Optional browser storage. */ }
        },
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
            {{ t('c_report.guest_edit') }}
        </Banner>

        <Card as="section" :title="t('c_front.report.file_report', 'File a report')">
            <form class="stack" @submit.prevent="submit">
                <Field :label="t('c_front.report.category_label', 'What kind of report is this?')" :error="form.errors.category" required>
                    <template #control="{ id, invalid, describedBy }">
                        <select
                            :id="id"
                            v-model="form.category"
                            class="select"
                            :disabled="form.processing"
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
                            :disabled="form.processing"
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
                            :disabled="form.processing"
                            :aria-invalid="invalid ? 'true' : undefined"
                            :aria-describedby="describedBy"
                        ></textarea>
                    </template>
                </Field>

                <p v-if="isAbuse" class="gloss">
                    {{ t('c_front.report.abuse_note', 'Abuse and illegal-content reports go to the moderation & legal team, not the support queue. Filing here removes nothing — content removal follows the constitutional carve-outs (the F-SOC-003 machinery), never this form.') }}
                </p>

                <p v-if="form.ref" class="citation">{{ t('c_front.report.filed_from', { ref: form.ref }) }}</p>

                <div v-if="github" class="stack">
                    <p>{{ t('c_report.github_notice') }}</p>
                    <template v-if="github.needsCopy">
                        <Field :label="t('c_report.long_report')" :hint="t('c_report.long_report_hint')">
                            <template #control="{ id, describedBy }">
                                <textarea :id="id" class="field-input" rows="6" readonly :value="github.text" :aria-describedby="describedBy" @focus="$event.target.select()"></textarea>
                            </template>
                        </Field>
                    </template>
                    <Btn variant="primary" :disabled="form.processing || !form.body.trim()" @click="openGithub">
                        {{ t('c_report.open_github') }}
                    </Btn>
                </div>

                <p v-if="draftError" role="alert" class="field-error">{{ draftError }}</p>

                <div class="cluster">
                    <Btn
                        type="submit"
                        :variant="github ? 'secondary' : 'primary'"
                        :disabled="form.processing || (!isGuest && !form.body.trim())"
                    >{{ isGuest ? t('c_report.sign_in_private') : (github ? t('c_report.file_private') : t('c_front.report.submit', 'File report')) }}</Btn>
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
