<script setup>
import { onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import { parseTs, wapi } from '@/lib/workspaceApi';

const { t, locale } = useI18n();
const busy = ref(false), error = ref('');
const tasks = ref([]), projects = ref([]);
const filter = ref({ status: 'open', project_id: '' });
const form = ref({ title: '', due: '', priority: 3, project_id: '' });
const newProject = ref('');

async function run(action) {
    busy.value = true; error.value = '';
    try { await action(); } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
async function load() {
    const q = new URLSearchParams({ status: filter.value.status });
    if (filter.value.project_id) q.set('project_id', filter.value.project_id);
    tasks.value = (await wapi(`/tasks?${q}`)).data;
    projects.value = (await wapi('/projects')).data;
}
onMounted(() => run(load));
watch(filter, () => run(load), { deep: true });
const add = () => run(async () => {
    const f = form.value;
    await wapi('/tasks', 'POST', { title: f.title, priority: Number(f.priority), due_at: f.due ? new Date(`${f.due}T17:00`).toISOString() : null, project_id: f.project_id || null });
    form.value = { ...f, title: '', due: '' };
    await load();
});
const toggle = task => run(async () => { await wapi(`/tasks/${task.id}`, 'PATCH', { status: task.status === 'done' ? 'open' : 'done' }); await load(); });
const remove = id => run(async () => { await wapi(`/tasks/${id}`, 'DELETE'); await load(); });
const addProject = () => run(async () => { await wapi('/projects', 'POST', { name: newProject.value }); newProject.value = ''; await load(); });
const due = task => task.due_at ? parseTs(task.due_at).toLocaleDateString(locale.value, { month: 'short', day: 'numeric' }) : '';
const overdue = task => task.status === 'open' && task.due_at && parseTs(task.due_at) < new Date();
const projectName = id => projects.value.find(p => p.id === id)?.name || '';
</script>

<template>
    <PageScaffold>
        <template #intro>{{ t('c_personal.tasks_intro') }}</template>

        <section class="app-setting-card" aria-labelledby="add-task">
            <h2 id="add-task">{{ t('c_personal.tasks_add') }}</h2>
            <form @submit.prevent="add">
                <label>{{ t('c_personal.tasks_title') }} <input v-model="form.title" required maxlength="240"></label>
                <label>{{ t('c_personal.tasks_due') }} <input v-model="form.due" type="date"></label>
                <label>{{ t('c_personal.tasks_priority') }}
                    <select v-model="form.priority"><option v-for="n in 5" :key="n" :value="n">{{ n }}</option></select>
                </label>
                <label>{{ t('c_personal.tasks_project') }}
                    <select v-model="form.project_id"><option value="">{{ t('c_personal.tasks_no_project') }}</option><option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option></select>
                </label>
                <button class="btn btn--primary" :disabled="busy">{{ t('c_personal.tasks_save') }}</button>
            </form>
        </section>

        <section class="app-setting-card" aria-labelledby="list-task">
            <h2 id="list-task">{{ t('c_personal.tasks_list') }}</h2>
            <div class="app-setting-actions">
                <label>{{ t('c_personal.tasks_show') }}
                    <select v-model="filter.status"><option value="open">{{ t('c_personal.tasks_open') }}</option><option value="done">{{ t('c_personal.tasks_done') }}</option></select>
                </label>
                <label>{{ t('c_personal.tasks_project') }}
                    <select v-model="filter.project_id"><option value="">{{ t('c_personal.tasks_all') }}</option><option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option></select>
                </label>
            </div>
            <p v-if="!tasks.length" class="muted">{{ t('c_personal.tasks_none') }}</p>
            <ul class="ws-list">
                <li v-for="task in tasks" :key="task.id">
                    <label><input type="checkbox" :checked="task.status === 'done'" :disabled="busy" @change="toggle(task)"> {{ task.title }}</label>
                    <span v-if="task.project_id" class="muted"> · {{ projectName(task.project_id) }}</span>
                    <span v-if="task.due_at" :class="{ 'field-error': overdue(task) }"> · {{ t('c_personal.tasks_due_on', { date: due(task) }) }}</span>
                    <button class="btn btn--secondary" :disabled="busy" @click="remove(task.id)">{{ t('c_personal.ws_remove') }}</button>
                </li>
            </ul>
        </section>

        <section class="app-setting-card" aria-labelledby="proj-task">
            <h2 id="proj-task">{{ t('c_personal.tasks_projects') }}</h2>
            <form @submit.prevent="addProject">
                <label>{{ t('c_personal.tasks_project_name') }} <input v-model="newProject" required maxlength="120"></label>
                <button class="btn btn--secondary" :disabled="busy">{{ t('c_personal.tasks_project_add') }}</button>
            </form>
        </section>

        <p v-if="error" role="alert" class="field-error">{{ error }}</p>
    </PageScaffold>
</template>

<style scoped>
.ws-list { list-style: none; padding: 0; }
.ws-list li { margin-block: 0.5rem; overflow-wrap: anywhere; }
</style>
