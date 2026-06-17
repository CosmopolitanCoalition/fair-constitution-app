<script setup>
/**
 * Auth/OperatorLogin (Phase G, G3c) — session login for the OPERATOR plane
 * (auth:operator guard), separate from the citizen account. Username + password;
 * the server throttles 5/minute and surfaces the lockout on the username field.
 * Operator status is infrastructure — it confers no governance standing.
 */
import { Head, Link, useForm } from '@inertiajs/vue3';

// Standalone (pre-shell) page — opt out of the AppShell default layout.
defineOptions({ layout: null });
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import Field from '@/Components/Ui/Field.vue';

const form = useForm({
    username: '',
    password: '',
});

function submit() {
    form.post('/operator/login', {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head title="Operator sign-in" />

    <main id="main" class="login-page">
        <div class="stack">
            <header>
                <span class="eyebrow">Infrastructure</span>
                <h1>Operator sign-in</h1>
                <p class="page-intro">
                    The operator console runs this instance and its place in the mesh — a separate
                    login from your citizen account. Operator status confers no governance standing.
                </p>
            </header>

            <Card as="section" aria-labelledby="op-login-h">
                <template #title>
                    <h2 id="op-login-h">Operator account</h2>
                </template>

                <form novalidate @submit.prevent="submit">
                    <Field label="Username" :error="form.errors.username" required>
                        <template #control="{ id, invalid, describedBy }">
                            <input
                                :id="id"
                                v-model="form.username"
                                class="field-input"
                                type="text"
                                name="username"
                                autocomplete="username"
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

                    <div class="cluster">
                        <Btn type="submit" variant="primary" :disabled="form.processing">
                            {{ form.processing ? 'Signing in…' : 'Sign in as operator' }}
                        </Btn>
                        <span class="cc-small">
                            <Link href="/federation">Back to the federation console</Link>
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
