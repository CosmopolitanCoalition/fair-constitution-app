<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import { csrfFetch } from '@/lib/csrf';

const props = defineProps({ scopes: Array, keys: Array, secrets: Array, pending: Array });
const { t } = useI18n();
const busy = ref(false), error = ref(''), notice = ref('');
const keyForm = ref({ name: '', days: '', scopes: [] });
const secretForm = ref({ name: '', provider: '', value: '' });
const newKey = ref('');
const revealing = ref({}); // id -> { password, value }

async function call(path, method, data) {
    const response = await csrfFetch(`/connections${path}`, {
        method, headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: data ? JSON.stringify(data) : undefined,
    });
    if (!response.ok) throw new Error((await response.json().catch(() => ({}))).message || t('c_personal.failed'));
    return response.status === 204 ? null : response.json();
}
async function run(action) {
    busy.value = true; error.value = ''; notice.value = '';
    try { await action(); } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
const refresh = () => router.reload({ only: ['keys', 'secrets', 'pending'] });

const createKey = () => run(async () => {
    const f = keyForm.value;
    const out = await call('/keys', 'POST', { name: f.name, scopes: f.scopes, days: f.days ? Number(f.days) : null });
    newKey.value = out.key;
    keyForm.value = { name: '', days: '', scopes: [] };
    refresh();
});
const revoke = id => run(async () => { await call(`/keys/${id}`, 'DELETE'); refresh(); });
const copyKey = () => run(async () => { await navigator.clipboard.writeText(newKey.value); notice.value = t('c_personal.copied'); });
const saveSecret = () => run(async () => {
    await call('/secrets', 'POST', secretForm.value);
    secretForm.value = { name: '', provider: '', value: '' };
    notice.value = t('c_personal.saved');
    refresh();
});
const reveal = id => run(async () => {
    const state = revealing.value[id] || {};
    const out = await call(`/secrets/${id}/reveal`, 'POST', { password: state.password });
    revealing.value = { ...revealing.value, [id]: { value: out.value } };
});
const hide = id => { const { [id]: _drop, ...rest } = revealing.value; revealing.value = rest; };
const removeSecret = id => run(async () => { await call(`/secrets/${id}`, 'DELETE'); hide(id); refresh(); });
const decide = (id, verb) => run(async () => { await call(`/drafts/${id}/${verb}`, 'POST'); refresh(); });
</script>

<template>
    <PageScaffold>
        <template #intro>{{ t('c_personal.intro') }}</template>

        <section class="app-setting-card" aria-labelledby="keys-title">
            <h2 id="keys-title">{{ t('c_personal.keys_title') }}</h2>
            <p>{{ t('c_personal.keys_body') }}</p>
            <div v-if="newKey" role="status" class="app-setting-card">
                <strong>{{ t('c_personal.new_key_title') }}</strong>
                <p>{{ t('c_personal.new_key_body') }}</p>
                <code>{{ newKey }}</code>
                <button class="btn btn--secondary" @click="copyKey">{{ t('c_personal.copy') }}</button>
            </div>
            <form @submit.prevent="createKey">
                <label>{{ t('c_personal.key_name') }} <input v-model="keyForm.name" required maxlength="80" :placeholder="t('c_personal.key_name_hint')"></label>
                <label>{{ t('c_personal.key_days') }} <input v-model="keyForm.days" type="number" min="1" max="730"></label>
                <fieldset>
                    <legend>{{ t('c_personal.scopes') }}</legend>
                    <label v-for="s in scopes" :key="s"><input type="checkbox" :value="s" v-model="keyForm.scopes"> {{ s }}</label>
                </fieldset>
                <button class="btn btn--primary" :disabled="busy || !keyForm.scopes.length">{{ t('c_personal.create_key') }}</button>
            </form>
            <p v-if="!keys.length" class="muted">{{ t('c_personal.no_keys') }}</p>
            <ul>
                <li v-for="k in keys" :key="k.id">
                    <strong>{{ k.name }}</strong> <code>{{ k.key_prefix }}…</code> {{ k.scopes.join(', ') }}
                    <span class="muted"> {{ t('c_personal.last_used') }}: {{ k.last_used_at || t('c_personal.never_used') }}</span>
                    <span v-if="k.expires_at" class="muted"> {{ t('c_personal.expires') }}: {{ k.expires_at }}</span>
                    <span v-if="k.revoked_at"> {{ t('c_personal.revoked') }}</span>
                    <button v-else class="btn btn--secondary" :disabled="busy" @click="revoke(k.id)">{{ t('c_personal.revoke') }}</button>
                </li>
            </ul>
        </section>

        <section class="app-setting-card" aria-labelledby="secrets-title">
            <h2 id="secrets-title">{{ t('c_personal.secrets_title') }}</h2>
            <p>{{ t('c_personal.secrets_body') }}</p>
            <form @submit.prevent="saveSecret">
                <label>{{ t('c_personal.secret_name') }} <input v-model="secretForm.name" required maxlength="80"></label>
                <label>{{ t('c_personal.secret_provider') }} <input v-model="secretForm.provider" required maxlength="40"></label>
                <label>{{ t('c_personal.secret_value') }} <input v-model="secretForm.value" type="password" required autocomplete="off"></label>
                <button class="btn btn--primary" :disabled="busy">{{ t('c_personal.save_secret') }}</button>
            </form>
            <p v-if="!secrets.length" class="muted">{{ t('c_personal.no_secrets') }}</p>
            <ul>
                <li v-for="s in secrets" :key="s.id">
                    <strong>{{ s.name }}</strong> {{ s.provider }} <code>{{ s.hint }}</code>
                    <template v-if="revealing[s.id]?.value !== undefined">
                        <code>{{ revealing[s.id].value }}</code>
                        <button class="btn btn--secondary" @click="hide(s.id)">{{ t('c_personal.hide') }}</button>
                    </template>
                    <template v-else>
                        <input type="password" autocomplete="current-password" :aria-label="t('c_personal.confirm_password')" :placeholder="t('c_personal.confirm_password')"
                               :value="revealing[s.id]?.password || ''" @input="revealing = { ...revealing, [s.id]: { password: $event.target.value } }">
                        <button class="btn btn--secondary" :disabled="busy" @click="reveal(s.id)">{{ t('c_personal.reveal') }}</button>
                    </template>
                    <button class="btn btn--secondary" :disabled="busy" @click="removeSecret(s.id)">{{ t('c_personal.delete') }}</button>
                </li>
            </ul>
        </section>

        <section class="app-setting-card" aria-labelledby="drafts-title">
            <h2 id="drafts-title">{{ t('c_personal.drafts_title') }}</h2>
            <p>{{ t('c_personal.drafts_body') }}</p>
            <p v-if="!pending.length" class="muted">{{ t('c_personal.no_drafts') }}</p>
            <article v-for="d in pending" :key="d.id">
                <h3>{{ d.subject }}</h3>
                <p>{{ t('c_personal.to') }}: {{ JSON.parse(d.to_addresses).join(', ') }}</p>
                <pre>{{ d.body }}</pre>
                <button class="btn btn--primary" :disabled="busy" @click="decide(d.id, 'approve')">{{ t('c_personal.approve') }}</button>
                <button class="btn btn--secondary" :disabled="busy" @click="decide(d.id, 'reject')">{{ t('c_personal.reject') }}</button>
            </article>
        </section>

        <p v-if="notice" role="status">{{ notice }}</p>
        <p v-if="error" role="alert" class="field-error">{{ error }}</p>
    </PageScaffold>
</template>
