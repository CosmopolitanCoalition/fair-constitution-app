<script setup>
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import { parseTs, wapi } from '@/lib/workspaceApi';

const { t, locale } = useI18n();
const busy = ref(false), error = ref('');
const contacts = ref([]), query = ref('');
const open = ref(null); // { contact, notes, followups }
const form = ref({ name: '', phone: '', email: '', organization: '' });
const note = ref({ body: '', channel: 'note' });
const followup = ref({ what: '', due: '' });

async function run(action) {
    busy.value = true; error.value = '';
    try { await action(); } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
const load = async () => { contacts.value = (await wapi(`/contacts?q=${encodeURIComponent(query.value)}`)).data; };
onMounted(() => run(load));
const search = () => run(load);
const add = () => run(async () => {
    const f = form.value;
    await wapi('/contacts', 'POST', { name: f.name, phone: f.phone || null, email: f.email || null, organization: f.organization || null });
    form.value = { name: '', phone: '', email: '', organization: '' };
    await load();
});
const show = id => run(async () => {
    const out = await wapi(`/contacts/${id}`);
    open.value = { contact: out.data, notes: out.notes, followups: out.followups };
});
const addNote = () => run(async () => { await wapi(`/contacts/${open.value.contact.id}/notes`, 'POST', note.value); note.value = { body: '', channel: 'note' }; await show(open.value.contact.id); });
const addFollowup = () => run(async () => {
    await wapi(`/contacts/${open.value.contact.id}/followups`, 'POST', { what: followup.value.what, due_at: followup.value.due ? new Date(`${followup.value.due}T09:00`).toISOString() : null });
    followup.value = { what: '', due: '' };
    await show(open.value.contact.id);
});
const done = id => run(async () => { await wapi(`/followups/${id}/complete`, 'POST'); await show(open.value.contact.id); });
const stamp = v => parseTs(v).toLocaleString(locale.value, { dateStyle: 'medium', timeStyle: 'short' });
</script>

<template>
    <PageScaffold>
        <template #intro>{{ t('c_personal.crm_intro') }}</template>

        <section class="app-setting-card" aria-labelledby="find-contact">
            <h2 id="find-contact">{{ t('c_personal.crm_find') }}</h2>
            <form @submit.prevent="search">
                <label>{{ t('c_personal.crm_search') }} <input v-model="query" type="search" maxlength="120"></label>
                <button class="btn btn--secondary" :disabled="busy">{{ t('c_personal.crm_search_go') }}</button>
            </form>
            <p v-if="!contacts.length" class="muted">{{ t('c_personal.crm_none') }}</p>
            <ul class="ws-list">
                <li v-for="c in contacts" :key="c.id">
                    <button class="btn btn--secondary" :disabled="busy" @click="show(c.id)">{{ c.name }}</button>
                    <span class="muted"> {{ [c.organization, c.phone, c.email].filter(Boolean).join(' · ') }}</span>
                    <span v-if="c.contact_class !== 'standard'"> · {{ t('c_personal.crm_class_' + c.contact_class) }}</span>
                </li>
            </ul>
        </section>

        <section v-if="open" class="app-setting-card" aria-labelledby="one-contact">
            <h2 id="one-contact">{{ open.contact.name }}</h2>
            <p class="muted">{{ [open.contact.organization, open.contact.phone, open.contact.email].filter(Boolean).join(' · ') }}</p>
            <h3>{{ t('c_personal.crm_followups') }}</h3>
            <p v-if="!open.followups.length" class="muted">{{ t('c_personal.crm_followups_none') }}</p>
            <ul class="ws-list">
                <li v-for="f in open.followups" :key="f.id">
                    {{ f.what }}<span v-if="f.due_at" class="muted"> · {{ stamp(f.due_at) }}</span>
                    <button class="btn btn--secondary" :disabled="busy" @click="done(f.id)">{{ t('c_personal.crm_done') }}</button>
                </li>
            </ul>
            <form @submit.prevent="addFollowup">
                <label>{{ t('c_personal.crm_followup_what') }} <input v-model="followup.what" required maxlength="240"></label>
                <label>{{ t('c_personal.tasks_due') }} <input v-model="followup.due" type="date"></label>
                <button class="btn btn--secondary" :disabled="busy">{{ t('c_personal.crm_followup_add') }}</button>
            </form>
            <h3>{{ t('c_personal.crm_notes') }}</h3>
            <p v-if="!open.notes.length" class="muted">{{ t('c_personal.crm_notes_none') }}</p>
            <ul class="ws-list">
                <li v-for="n in open.notes" :key="n.id"><span class="muted">{{ stamp(n.created_at) }} · {{ t('c_personal.crm_channel_' + n.channel) }}</span><br>{{ n.body }}</li>
            </ul>
            <form @submit.prevent="addNote">
                <label>{{ t('c_personal.crm_note') }} <textarea v-model="note.body" required rows="3" maxlength="10000"></textarea></label>
                <label>{{ t('c_personal.crm_channel') }}
                    <select v-model="note.channel"><option v-for="c in ['note', 'call', 'text', 'email', 'meeting']" :key="c" :value="c">{{ t('c_personal.crm_channel_' + c) }}</option></select>
                </label>
                <button class="btn btn--secondary" :disabled="busy">{{ t('c_personal.crm_note_add') }}</button>
            </form>
        </section>

        <section class="app-setting-card" aria-labelledby="new-contact">
            <h2 id="new-contact">{{ t('c_personal.crm_add') }}</h2>
            <form @submit.prevent="add">
                <label>{{ t('c_personal.crm_name') }} <input v-model="form.name" required maxlength="160"></label>
                <label>{{ t('c_personal.crm_phone') }} <input v-model="form.phone" type="tel" maxlength="40"></label>
                <label>{{ t('c_personal.crm_email') }} <input v-model="form.email" type="email" maxlength="255"></label>
                <label>{{ t('c_personal.crm_org') }} <input v-model="form.organization" maxlength="160"></label>
                <button class="btn btn--primary" :disabled="busy">{{ t('c_personal.crm_save') }}</button>
            </form>
        </section>

        <p v-if="error" role="alert" class="field-error">{{ error }}</p>
    </PageScaffold>
</template>

<style scoped>
.ws-list { list-style: none; padding: 0; }
.ws-list li { margin-block: 0.5rem; overflow-wrap: anywhere; }
</style>
