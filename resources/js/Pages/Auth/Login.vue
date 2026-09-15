<script setup>
/**
 * Auth/Login — standalone session login (no AppLayout, pre-shell surface).
 * Email + password + remember; the server throttles at 5 attempts/minute
 * per email+IP and surfaces the lockout message on the email field.
 */
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, provide, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import Banner from '@/Components/Ui/Banner.vue';

// Standalone (pre-shell) page — opt out of the AppShell default layout.
defineOptions({ layout: null });
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import CheckboxField from '@/Components/Ui/CheckboxField.vue';
import Field from '@/Components/Ui/Field.vue';
import CmdBar from '@/Components/ShellV2/CmdBar.vue';

// LE-5: this page carries no shell, so it provides its own Learn context and
// mounts the Learn-only command bar. The surface id resolves the authored
// guidance (registry/education.js, key auth/login); the learn target mirrors
// the shell's teleport anchor so the drawer body renders the same way.
provide('cga:surface', ref({ id: 'auth/login', module: 'civic' }));
provide('cga:learn-target', '#learn-content-' + useId());

const { t } = useI18n();

const status = computed(() => usePage().props.flash?.status ?? null);

// Carried from an invite (/i/{token}) or a shared deep link — where the visitor lands after login.
const props = defineProps({
    intendedUrl: { type: String, default: null },
    invitePreview: { type: Object, default: null },
});
const continuationLabel = computed(() => props.invitePreview?.label || (props.intendedUrl ? t('c_front.login.headed', 'where you were headed') : null));
const inviterName = computed(() => props.invitePreview?.inviter || null);

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

function submit() {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head :title="t('c_front.login.head_title', 'Log in')" />

    <main id="main" class="login-page">
        <div class="stack">
            <header>
                <span class="eyebrow">{{ t('c_front.login.eyebrow', 'Welcome back') }}</span>
                <h1>{{ t('c_front.login.title', 'Log in') }}</h1>
                <p class="page-intro">
                    {{ t('c_front.login.intro', 'Sign in to your Individual record. Your rights ride with your residency, not with this session.') }}
                </p>
            </header>

            <Banner v-if="status" tone="info">{{ status }}</Banner>

            <Banner v-if="continuationLabel" tone="info" :title="t('c_front.login.invited_title', 'You were invited')" style="margin-block-end: var(--space-2)">
                <template v-if="inviterName">{{ t('c_front.login.invited_prefix', { inviter: inviterName }) }} <strong>{{ continuationLabel }}</strong>. </template>
                <template v-else>{{ t('c_front.login.continue_to', 'You’ll continue to') }} <strong>{{ continuationLabel }}</strong>. </template>
                {{ t('c_front.login.land_there', 'Log in and you’ll land there.') }}
            </Banner>

            <Card as="section" aria-labelledby="login-h">
                <template #title>
                    <h2 id="login-h">{{ t('c_front.login.account_signin', 'Account sign-in') }}</h2>
                </template>

                <form novalidate @submit.prevent="submit">
                    <Field :label="t('c_front.login.field_email', 'Email')" :error="form.errors.email" required>
                        <template #control="{ id, invalid, describedBy }">
                            <input
                                :id="id"
                                v-model="form.email"
                                class="field-input"
                                type="email"
                                name="email"
                                autocomplete="email"
                                required
                                autofocus
                                :aria-invalid="invalid ? 'true' : undefined"
                                :aria-describedby="describedBy"
                            />
                        </template>
                    </Field>

                    <Field :label="t('c_front.login.field_password', 'Password')" :error="form.errors.password" required>
                        <template #control="{ id, invalid, describedBy }">
                            <input
                                :id="id"
                                v-model="form.password"
                                class="field-input"
                                type="password"
                                name="password"
                                autocomplete="current-password"
                                required
                                :aria-invalid="invalid ? 'true' : undefined"
                                :aria-describedby="describedBy"
                            />
                        </template>
                    </Field>

                    <div class="field">
                        <CheckboxField v-model="form.remember" name="remember">
                            {{ t('c_front.login.stay_signed_in', 'Stay signed in on this device') }}
                        </CheckboxField>
                    </div>

                    <div class="cluster">
                        <Btn type="submit" variant="primary" :disabled="form.processing">
                            {{ form.processing ? t('c_front.login.signing_in', 'Signing in…') : t('c_front.login.log_in_btn', 'Log in') }}
                        </Btn>
                        <span class="cc-small">
                            {{ t('c_front.login.new_here', 'New here?') }}
                            <Link href="/register">{{ t('c_front.login.create_account', 'Create an account') }}</Link>
                        </span>
                    </div>
                </form>
            </Card>
        </div>
    </main>

    <!-- LE-5: Learn drawer reachable before signing in (same component and
         Learn target the legacy shell provides, without a shell). -->
    <CmdBar learn-only />
</template>

<style scoped>
.login-page {
    min-height: 100vh;
    max-inline-size: 30rem;
    margin-inline: auto;
    padding: var(--space-7) var(--space-4);
}
</style>
