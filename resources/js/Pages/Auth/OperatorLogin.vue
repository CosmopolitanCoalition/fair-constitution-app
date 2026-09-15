<script setup>
/**
 * Auth/OperatorLogin (Phase G, G3c) — session login for the OPERATOR plane
 * (auth:operator guard), separate from the citizen account. Username + password;
 * the server throttles 5/minute and surfaces the lockout on the username field.
 * Operator status is infrastructure — it confers no governance standing.
 */
import { Head, Link, useForm } from '@inertiajs/vue3';
import { provide, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';

// Standalone (pre-shell) page — opt out of the AppShell default layout.
defineOptions({ layout: null });
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import Field from '@/Components/Ui/Field.vue';
import CmdBar from '@/Components/ShellV2/CmdBar.vue';

// LE-5: no shell here, so the page provides its own Learn context and mounts
// the Learn-only command bar. The surface id resolves the authored guidance
// (registry/education.js, key auth/operator-login).
provide('cga:surface', ref({ id: 'auth/operator-login', module: 'system' }));
provide('cga:learn-target', '#learn-content-' + useId());

const { t } = useI18n();

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
    <Head :title="t('c_front.operator_login.head_title', 'Operator sign-in')" />

    <main id="main" class="login-page">
        <div class="stack">
            <header>
                <span class="eyebrow">{{ t('c_front.operator_login.eyebrow', 'Infrastructure') }}</span>
                <h1>{{ t('c_front.operator_login.title', 'Operator sign-in') }}</h1>
                <p class="page-intro">
                    {{ t('c_front.operator_login.intro', 'The operator console runs this instance and its place in the mesh — a separate login from your citizen account. Operator status confers no governance standing.') }}
                </p>
            </header>

            <Card as="section" aria-labelledby="op-login-h">
                <template #title>
                    <h2 id="op-login-h">{{ t('c_front.operator_login.account', 'Operator account') }}</h2>
                </template>

                <form novalidate @submit.prevent="submit">
                    <Field :label="t('c_front.operator_login.field_username', 'Username')" :error="form.errors.username" required>
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

                    <Field :label="t('c_front.operator_login.field_password', 'Password')" :error="form.errors.password" required>
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
                            {{ form.processing ? t('c_front.operator_login.signing_in', 'Signing in…') : t('c_front.operator_login.sign_in_btn', 'Sign in as operator') }}
                        </Btn>
                        <span class="cc-small">
                            <Link href="/operator/federation">{{ t('c_front.operator_login.back_link', 'Back to the federation console') }}</Link>
                        </span>
                    </div>
                </form>
            </Card>
        </div>
    </main>

    <!-- LE-5: Learn drawer reachable on the bare operator sign-in. -->
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
