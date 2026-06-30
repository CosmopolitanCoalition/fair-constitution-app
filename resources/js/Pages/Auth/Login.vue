<script setup>
/**
 * Auth/Login — standalone session login (no AppLayout, pre-shell surface).
 * Email + password + remember; the server throttles at 5 attempts/minute
 * per email+IP and surfaces the lockout message on the email field.
 */
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Banner from '@/Components/Ui/Banner.vue';

// Standalone (pre-shell) page — opt out of the AppShell default layout.
defineOptions({ layout: null });
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import CheckboxField from '@/Components/Ui/CheckboxField.vue';
import Field from '@/Components/Ui/Field.vue';

const status = computed(() => usePage().props.flash?.status ?? null);

// Carried from an invite (/i/{token}) or a shared deep link — where the visitor lands after login.
const props = defineProps({
    intendedUrl: { type: String, default: null },
    invitePreview: { type: Object, default: null },
});
const continuationLabel = computed(() => props.invitePreview?.label || (props.intendedUrl ? 'where you were headed' : null));
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
    <Head title="Log in" />

    <main id="main" class="login-page">
        <div class="stack">
            <header>
                <span class="eyebrow">Welcome back</span>
                <h1>Log in</h1>
                <p class="page-intro">
                    Sign in to your Individual record. Your rights ride with your residency, not
                    with this session.
                </p>
            </header>

            <Banner v-if="status" tone="info">{{ status }}</Banner>

            <Banner v-if="continuationLabel" tone="info" title="You were invited" style="margin-block-end: var(--space-2)">
                <template v-if="inviterName">{{ inviterName }} invited you to <strong>{{ continuationLabel }}</strong>. </template>
                <template v-else>You’ll continue to <strong>{{ continuationLabel }}</strong>. </template>
                Log in and you’ll land there.
            </Banner>

            <Card as="section" aria-labelledby="login-h">
                <template #title>
                    <h2 id="login-h">Account sign-in</h2>
                </template>

                <form novalidate @submit.prevent="submit">
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
                                autofocus
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
                                autocomplete="current-password"
                                required
                                :aria-invalid="invalid ? 'true' : undefined"
                                :aria-describedby="describedBy"
                            />
                        </template>
                    </Field>

                    <div class="field">
                        <CheckboxField v-model="form.remember" name="remember">
                            Stay signed in on this device
                        </CheckboxField>
                    </div>

                    <div class="cluster">
                        <Btn type="submit" variant="primary" :disabled="form.processing">
                            {{ form.processing ? 'Signing in…' : 'Log in' }}
                        </Btn>
                        <span class="cc-small">
                            New here?
                            <Link href="/register">Create an account</Link>
                        </span>
                    </div>
                </form>
            </Card>
        </div>
    </main>
</template>

<style scoped>
.login-page {
    min-height: 100vh;
    max-inline-size: 30rem;
    margin-inline: auto;
    padding: var(--space-7) var(--space-4);
}
</style>
