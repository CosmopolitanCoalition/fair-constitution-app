<script setup>
import { onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import { csrfFetch } from '@/lib/csrf';
import { parseTs, wapi } from '@/lib/workspaceApi';

const { t, locale } = useI18n();
const busy = ref(false), error = ref(''), notice = ref('');
const counts = ref({ data: [], total: { unread: 0, flagged: 0, due: 0 } });
const messages = ref([]), drafts = ref([]);
const view = ref({ unread: false, flagged: false, account: '' });
const opened = ref(null);
const draft = ref({ to: '', subject: '', body: '' });

async function run(action) {
    busy.value = true; error.value = ''; notice.value = '';
    try { await action(); } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
async function load() {
    const q = new URLSearchParams();
    if (view.value.unread) q.set('unread', '1');
    if (view.value.flagged) q.set('flagged', '1');
    if (view.value.account) q.set('account', view.value.account);
    counts.value = await wapi('/mail/counts');
    messages.value = (await wapi(`/mail/messages?${q}`)).data;
    drafts.value = (await wapi('/mail/drafts')).data;
}
onMounted(() => run(load));
watch(view, () => run(load), { deep: true });
const read = m => run(async () => { opened.value = (await wapi(`/mail/messages/${m.id}`)).data; if (!m.is_read) await wapi(`/mail/messages/${m.id}`, 'PATCH', { is_read: true }); await load(); });
const flag = m => run(async () => { await wapi(`/mail/messages/${m.id}`, 'PATCH', { is_flagged: !m.is_flagged }); await load(); });
const stamp = v => parseTs(v).toLocaleString(locale.value, { dateStyle: 'medium', timeStyle: 'short' });
const addresses = d => (typeof d.to_addresses === 'string' ? JSON.parse(d.to_addresses) : d.to_addresses).join(', ');

const save = () => run(async () => {
    const to = draft.value.to.split(/[\s,;]+/).filter(Boolean);
    await wapi('/mail/drafts', 'POST', { to_addresses: to, subject: draft.value.subject, body: draft.value.body });
    draft.value = { to: '', subject: '', body: '' };
    notice.value = t('c_personal.ws_saved');
    await load();
});
const ask = d => run(async () => { await wapi(`/mail/drafts/${d.id}/request-send`, 'POST'); await load(); });
async function decide(d, verb) {
    const response = await csrfFetch(`/connections/drafts/${d.id}/${verb}`, { method: 'POST', headers: { Accept: 'application/json' } });
    if (!response.ok) throw new Error((await response.json().catch(() => ({}))).message || t('c_personal.failed'));
    await load();
}
</script>

<template>
    <PageScaffold>
        <template #intro>{{ t('c_personal.mail_intro') }}</template>

        <section class="app-setting-card" aria-labelledby="mail-counts">
            <h2 id="mail-counts">{{ t('c_personal.mail_counts') }}</h2>
            <p>{{ t('c_personal.mail_totals', { unread: counts.total.unread, flagged: counts.total.flagged, due: counts.total.due }) }}</p>
            <ul class="ws-list">
                <li v-for="a in counts.data" :key="a.account"><strong>{{ a.account }}</strong> · {{ t('c_personal.mail_totals', { unread: a.unread, flagged: a.flagged, due: a.due }) }}</li>
            </ul>
        </section>

        <section class="app-setting-card" aria-labelledby="mail-list">
            <h2 id="mail-list">{{ t('c_personal.mail_messages') }}</h2>
            <div class="app-setting-actions">
                <label><input type="checkbox" v-model="view.unread"> {{ t('c_personal.mail_only_unread') }}</label>
                <label><input type="checkbox" v-model="view.flagged"> {{ t('c_personal.mail_only_flagged') }}</label>
                <label>{{ t('c_personal.mail_account') }}
                    <select v-model="view.account"><option value="">{{ t('c_personal.tasks_all') }}</option><option v-for="a in counts.data" :key="a.account" :value="a.account">{{ a.account }}</option></select>
                </label>
            </div>
            <p v-if="!messages.length" class="muted">{{ t('c_personal.mail_none') }}</p>
            <ul class="ws-list">
                <li v-for="m in messages" :key="m.id">
                    <button class="btn btn--secondary" :disabled="busy" @click="read(m)"><strong v-if="!m.is_read">{{ m.subject || t('c_personal.mail_no_subject') }}</strong><template v-else>{{ m.subject || t('c_personal.mail_no_subject') }}</template></button>
                    <span class="muted"> {{ m.from_name || m.from_email }} · {{ stamp(m.received_at) }} · {{ m.account }}</span>
                    <button class="btn btn--secondary" :disabled="busy" @click="flag(m)">{{ m.is_flagged ? t('c_personal.mail_unflag') : t('c_personal.mail_flag') }}</button>
                    <p class="muted">{{ m.preview }}</p>
                </li>
            </ul>
            <article v-if="opened" class="app-setting-card" aria-live="polite">
                <h3>{{ opened.subject }}</h3>
                <p class="muted">{{ opened.from_name }} {{ opened.from_email }} · {{ stamp(opened.received_at) }}</p>
                <pre class="ws-body">{{ opened.body || opened.preview }}</pre>
            </article>
        </section>

        <section class="app-setting-card" aria-labelledby="mail-drafts">
            <h2 id="mail-drafts">{{ t('c_personal.mail_drafts') }}</h2>
            <p>{{ t('c_personal.mail_drafts_body') }}</p>
            <p v-if="!drafts.length" class="muted">{{ t('c_personal.mail_drafts_none') }}</p>
            <article v-for="d in drafts" :key="d.id" class="ws-draft">
                <h3>{{ d.subject }} <small>({{ t('c_personal.mail_status_' + d.status) }})</small></h3>
                <p class="muted">{{ t('c_personal.to') }}: {{ addresses(d) }}</p>
                <pre class="ws-body">{{ d.body }}</pre>
                <button v-if="d.status === 'draft'" class="btn btn--secondary" :disabled="busy" @click="ask(d)">{{ t('c_personal.mail_ask') }}</button>
                <template v-if="d.status === 'pending'">
                    <button class="btn btn--primary" :disabled="busy" @click="run(() => decide(d, 'approve'))">{{ t('c_personal.approve') }}</button>
                    <button class="btn btn--secondary" :disabled="busy" @click="run(() => decide(d, 'reject'))">{{ t('c_personal.reject') }}</button>
                </template>
            </article>
            <form @submit.prevent="save">
                <label>{{ t('c_personal.to') }} <input v-model="draft.to" required :placeholder="t('c_personal.mail_to_hint')"></label>
                <label>{{ t('c_personal.mail_subject') }} <input v-model="draft.subject" required maxlength="400"></label>
                <label>{{ t('c_personal.mail_body') }} <textarea v-model="draft.body" required rows="6"></textarea></label>
                <button class="btn btn--secondary" :disabled="busy">{{ t('c_personal.mail_save_draft') }}</button>
            </form>
        </section>

        <p v-if="notice" role="status">{{ notice }}</p>
        <p v-if="error" role="alert" class="field-error">{{ error }}</p>
    </PageScaffold>
</template>

<style scoped>
.ws-list { list-style: none; padding: 0; }
.ws-list li { margin-block: 0.5rem; overflow-wrap: anywhere; }
.ws-body { white-space: pre-wrap; overflow-wrap: anywhere; }
.ws-draft { margin-block: 1rem; }
</style>
