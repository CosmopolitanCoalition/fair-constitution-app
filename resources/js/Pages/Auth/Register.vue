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
import { computed, provide, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { ALL_LOCALES } from '@/i18n/index.js';
import Banner from '@/Components/Ui/Banner.vue';

// Standalone (pre-shell) page — opt out of the AppShell default layout.
defineOptions({ layout: null });
import CmdBar from '@/Components/ShellV2/CmdBar.vue';

// LE-5: no shell here, so the page provides its own Learn context and mounts
// the Learn-only command bar. The surface id resolves the authored guidance
// (registry/education.js, key auth/register).
provide('cga:surface', ref({ id: 'auth/register', module: 'civic' }));
provide('cga:learn-target', '#learn-content-' + useId());
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
const { t } = useI18n();

const continuationLabel = computed(() => props.invitePreview?.label || (props.intendedUrl ? t('c_front.register.headed', 'where you were headed') : null));
const inviterName = computed(() => props.invitePreview?.inviter || null);

// The languages multiselect offers every locale in THE registry
// (locales.generated.js, via i18n/index.js), no longer a hand-copied five that
// had drifted from it. Each option is the endonym plus its code, so a language
// reads in its own script.
const LANGUAGES = ALL_LOCALES.map((l) => ({ value: l.code, label: `${l.endonym} (${l.code})` }));

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
    <Head :title="t('c_front.register.head_title', 'Create your account')" />

    <main id="main" class="register-page">
        <div class="stack">
            <Banner v-if="continuationLabel" tone="info" :title="t('c_front.register.invited_title', 'You were invited')" style="margin-block-end: var(--space-2)">
                <template v-if="inviterName">{{ t('c_front.register.invited_prefix', { inviter: inviterName }) }} <strong>{{ continuationLabel }}</strong> {{ t('c_front.register.land_after', '— you’ll land there right after.') }}</template>
                <template v-else>{{ t('c_front.register.continue_to', 'You’ll continue to') }} <strong>{{ continuationLabel }}</strong> {{ t('c_front.register.land_after', '— you’ll land there right after.') }}</template>
            </Banner>

            <header>
                <span class="eyebrow">{{ t('c_front.register.eyebrow', 'Civic onboarding · step 1 of 3') }}</span>
                <h1>{{ t('c_front.register.title', 'Create your account') }}</h1>
                <p class="page-intro">
                    {{ t('c_front.register.intro', 'Anyone can register — being a person is the only requirement. Your rights are inherent; this account simply gives them a record to attach to. Voting and candidacy unlock later, automatically, when your residency is verified.') }}
                </p>
                <p class="citation">{{ t('c_front.register.intro_cite', 'Registration is open to any person — rights are inherent · Art. I') }}</p>
            </header>

            <Card as="section" aria-labelledby="reg-h">
                <template #title>
                    <h2 id="reg-h">{{ t('c_front.register.reg_h', 'Individual registration') }} <FormChip form-id="F-IND-001" /></h2>
                </template>
                <p class="cc-small">
                    {{ t('c_front.register.reg_desc', 'Create an account and identity record in the system.') }}
                    <span class="citation" style="display:block">
                        {{ t('c_front.register.reg_cite', 'available to R-01 Individual · creates the Individual record · Art. I (inherent rights)') }}
                    </span>
                </p>

                <form novalidate @submit.prevent="submit">
                    <!-- Invite arrival (v3 civic/join.html): the name is asked ONCE, here — the
                         landing page deliberately carries no name input, so when an invite is in
                         flight this field speaks the mockup's friendlier voice. -->
                    <Field
                        :label="invitePreview ? t('c_front.register.name_label_invite', 'What should people call you?') : t('c_front.register.name_label', 'Full name')"
                        :error="form.errors.name"
                        :hint="invitePreview ? t('c_front.register.name_hint_invite', 'Any name you like — you can change it later.') : t('c_front.register.name_hint', 'Use the name you want on your public civic record.')"
                        required
                    >
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

                    <Field :label="t('c_front.register.field_email', 'Email')" :error="form.errors.email" required>
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

                    <Field :label="t('c_front.register.field_password', 'Password')" :error="form.errors.password" required>
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

                    <Field :label="t('c_front.register.field_confirm', 'Confirm password')" :error="form.errors.password_confirmation" required>
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
                        :label="t('c_front.register.lang_label', 'Languages')"
                        :error="form.errors.languages"
                        :hint="t('c_front.register.lang_hint', 'Records are translated per your selection.')"
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
                        :label="t('c_front.register.tz_label', 'Timezone')"
                        :error="form.errors.timezone"
                        :hint="t('c_front.register.tz_hint', 'Dates are shown in your timezone · stored as UTC.')"
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
                            {{ t('c_front.register.terms', 'I understand my account record is mine, my location pings stay encrypted, and my civic actions become part of an append-only public record.') }}
                        </CheckboxField>
                        <span v-if="form.errors.terms" class="field-error">{{ form.errors.terms }}</span>
                    </div>

                    <div class="cluster">
                        <Btn type="submit" variant="primary" :disabled="form.processing">
                            {{ form.processing ? t('c_front.register.creating', 'Creating account…') : t('c_front.register.create_btn', 'Create account') }}
                        </Btn>
                        <span class="cc-small">
                            {{ t('c_front.register.have_account', 'Already have an account?') }}
                            <Link href="/login" class="prose-link">{{ t('c_front.register.log_in', 'Log in') }}</Link>
                        </span>
                    </div>
                </form>

                <Banner v-if="Object.keys(form.errors).length" tone="warning" :title="t('c_front.register.errors_title', 'Check the form')" style="margin-block-start: var(--space-4)">
                    {{ t('c_front.register.errors_body', 'Some fields need attention before your Individual record can be created.') }}
                </Banner>
            </Card>

            <Card as="section" aria-labelledby="state-h">
                <template #title>
                    <h2 id="state-h">{{ t('c_front.register.lifecycle_h', 'Where you are in the Individual lifecycle') }}</h2>
                </template>
                <StateStrip :states="INDIVIDUAL_STATES" current="Registered" />
                <p class="gloss" style="margin-block-start: var(--space-2)">
                    {{ t('c_front.register.lifecycle_gloss', 'Association exists simultaneously at every nesting level (local → Earth); voting and candidacy unlock at R-03 with no other requirements.') }}
                </p>
                <p class="citation" data-no-i18n>Art. I · Art. V §1</p>
            </Card>
        </div>
    </main>

    <!-- LE-5: Learn drawer reachable during onboarding, before any session. -->
    <CmdBar learn-only />
</template>

<style scoped>
.register-page {
    min-height: 100vh;
    max-inline-size: 46rem;
    margin-inline: auto;
    padding: var(--space-7) var(--space-4);
}
</style>
