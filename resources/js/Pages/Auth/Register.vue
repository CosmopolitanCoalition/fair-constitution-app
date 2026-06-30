<script setup>
/**
 * Auth/Register — civic/onboarding.html step 1 (account), WF-CIV-01.
 *
 * Standalone page (no AppLayout — pre-shell onboarding, like the mockup).
 * Submits F-IND-001 Individual Registration; the server routes the filing
 * through the ConstitutionalEngine so the registration is audit-chained.
 * Rights are NOT gated on anything here — voting/candidacy unlock later,
 * automatically, on residency verification (Art. I).
 */
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Banner from '@/Components/Ui/Banner.vue';

// Standalone (pre-shell) page — opt out of the AppShell default layout.
defineOptions({ layout: null });
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import CheckboxField from '@/Components/Ui/CheckboxField.vue';
import Field from '@/Components/Ui/Field.vue';
import FormChip from '@/Components/Ui/FormChip.vue';
import StateStrip from '@/Components/Ui/StateStrip.vue';

// Carried from an invite (/i/{token}) or a shared deep link — where the visitor lands after signup.
const props = defineProps({
    intendedUrl: { type: String, default: null },
    invitePreview: { type: Object, default: null },
});
const continuationLabel = computed(() => props.invitePreview?.label || (props.intendedUrl ? 'where you were headed' : null));
const inviterName = computed(() => props.invitePreview?.inviter || null);

// Mockup onboarding contract: the languages multiselect offers these five;
// the production list covers every supported locale (chrome i18n WI).
const LANGUAGES = [
    { value: 'en', label: 'English (en)' },
    { value: 'es', label: 'Español (es)' },
    { value: 'ar', label: 'العربية (ar)' },
    { value: 'zh-Hans', label: '中文 — 简体 (zh-Hans)' },
    { value: 'hi', label: 'हिन्दी (hi)' },
];

// ESM-01 Individual lifecycle (account surface covers "Registered").
const INDIVIDUAL_STATES = [
    'Registered',
    'Identity-Verified',
    'Residency-Declared',
    'Resident (R-02)',
    'Jurisdictionally Associated (R-03)',
];

// Full IANA list when the runtime offers it; otherwise the select degrades
// to a free-text input (server validates with timezone:all either way).
const timezones = typeof Intl.supportedValuesOf === 'function'
    ? Intl.supportedValuesOf('timeZone')
    : null;

const guessedTimezone = (() => {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
    } catch {
        return 'UTC';
    }
})();

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    languages: ['en'],
    timezone: guessedTimezone,
    terms: false,
});

function submit() {
    form.post('/register', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head title="Create your account" />

    <main id="main" class="register-page">
        <div class="stack">
            <Banner v-if="continuationLabel" tone="info" title="You were invited" style="margin-block-end: var(--space-2)">
                <template v-if="inviterName">{{ inviterName }} invited you to <strong>{{ continuationLabel }}</strong>. </template>
                <template v-else>You’ll continue to <strong>{{ continuationLabel }}</strong>. </template>
                Create your account and you’ll land there right after.
            </Banner>

            <header>
                <span class="eyebrow">Civic onboarding · step 1 of 3</span>
                <h1>Create your account</h1>
                <p class="page-intro">
                    Anyone can register — being a person is the only requirement. Your rights are
                    inherent; this account simply gives them a record to attach to. Voting and
                    candidacy unlock later, automatically, when your residency is verified.
                </p>
                <p class="citation">Registration is open to any person — rights are inherent · Art. I</p>
            </header>

            <Card as="section" aria-labelledby="reg-h">
                <template #title>
                    <h2 id="reg-h">Individual registration <FormChip form-id="F-IND-001" /></h2>
                </template>
                <p class="cc-small">
                    Create an account and identity record in the system.
                    <span class="citation" style="display:block">
                        available to R-01 Individual · creates the Individual record · Art. I (inherent rights)
                    </span>
                </p>

                <form novalidate @submit.prevent="submit">
                    <Field label="Full name" :error="form.errors.name" hint="Use the name you want on your public civic record." required>
                        <template #control="{ id, invalid, describedBy }">
                            <input
                                :id="id"
                                v-model="form.name"
                                class="field-input"
                                type="text"
                                name="name"
                                autocomplete="name"
                                required
                                :aria-invalid="invalid ? 'true' : undefined"
                                :aria-describedby="describedBy"
                            />
                        </template>
                    </Field>

                    <Field label="Email" :error="form.errors.email" required>
                        <template #control="{ id, invalid, describedBy }">
                            <input
                                :id="id"
                                v-model="form.email"
                                class="field-input"
                                type="email"
                                name="email"
                                autocomplete="email"
                                required
                                :aria-invalid="invalid ? 'true' : undefined"
                                :aria-describedby="describedBy"
                            />
                        </template>
                    </Field>

                    <Field label="Password" :error="form.errors.password" required>
                        <template #control="{ id, invalid, describedBy }">
                            <input
                                :id="id"
                                v-model="form.password"
                                class="field-input"
                                type="password"
                                name="password"
                                autocomplete="new-password"
                                required
                                :aria-invalid="invalid ? 'true' : undefined"
                                :aria-describedby="describedBy"
                            />
                        </template>
                    </Field>

                    <Field label="Confirm password" :error="form.errors.password_confirmation" required>
                        <template #control="{ id, invalid, describedBy }">
                            <input
                                :id="id"
                                v-model="form.password_confirmation"
                                class="field-input"
                                type="password"
                                name="password_confirmation"
                                autocomplete="new-password"
                                required
                                :aria-invalid="invalid ? 'true' : undefined"
                                :aria-describedby="describedBy"
                            />
                        </template>
                    </Field>

                    <Field
                        label="Languages"
                        :error="form.errors.languages"
                        hint="Records are translated per your selection."
                    >
                        <template #control="{ id, invalid, describedBy }">
                            <select
                                :id="id"
                                v-model="form.languages"
                                class="select"
                                name="languages"
                                multiple
                                size="5"
                                :aria-invalid="invalid ? 'true' : undefined"
                                :aria-describedby="describedBy"
                            >
                                <option v-for="lang in LANGUAGES" :key="lang.value" :value="lang.value">
                                    {{ lang.label }}
                                </option>
                            </select>
                        </template>
                    </Field>

                    <Field
                        label="Timezone"
                        :error="form.errors.timezone"
                        hint="Dates are shown in your timezone · stored as UTC."
                    >
                        <template #control="{ id, invalid, describedBy }">
                            <select
                                v-if="timezones"
                                :id="id"
                                v-model="form.timezone"
                                class="select"
                                name="timezone"
                                :aria-invalid="invalid ? 'true' : undefined"
                                :aria-describedby="describedBy"
                            >
                                <option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option>
                            </select>
                            <input
                                v-else
                                :id="id"
                                v-model="form.timezone"
                                class="field-input"
                                type="text"
                                name="timezone"
                                :aria-invalid="invalid ? 'true' : undefined"
                                :aria-describedby="describedBy"
                            />
                        </template>
                    </Field>

                    <div class="field" :class="{ 'field--invalid': form.errors.terms }">
                        <CheckboxField v-model="form.terms" name="terms">
                            I understand my account record is mine, my location pings stay encrypted,
                            and my civic actions become part of an append-only public record.
                        </CheckboxField>
                        <span v-if="form.errors.terms" class="field-error">{{ form.errors.terms }}</span>
                    </div>

                    <div class="cluster">
                        <Btn type="submit" variant="primary" :disabled="form.processing">
                            {{ form.processing ? 'Creating account…' : 'Create account' }}
                        </Btn>
                        <span class="cc-small">
                            Already have an account?
                            <Link href="/login">Log in</Link>
                        </span>
                    </div>
                </form>

                <Banner v-if="Object.keys(form.errors).length" tone="warning" title="Check the form" style="margin-block-start: var(--space-4)">
                    Some fields need attention before your Individual record can be created.
                </Banner>
            </Card>

            <Card as="section" aria-labelledby="state-h">
                <template #title>
                    <h2 id="state-h">Where you are in the Individual lifecycle</h2>
                </template>
                <StateStrip :states="INDIVIDUAL_STATES" current="Registered" />
                <p class="gloss" style="margin-block-start: var(--space-2)">
                    Association exists simultaneously at every nesting level (local → Earth); voting
                    and candidacy unlock at R-03 with no other requirements.
                </p>
                <p class="citation">Art. I · Art. V §1</p>
            </Card>
        </div>
    </main>
</template>

<style scoped>
.register-page {
    min-height: 100vh;
    max-inline-size: 46rem;
    margin-inline: auto;
    padding: var(--space-7) var(--space-4);
}
</style>
