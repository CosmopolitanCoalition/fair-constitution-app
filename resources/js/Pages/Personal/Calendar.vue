<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import { isoDate, parseTs, wapi, weekStart } from '@/lib/workspaceApi';

const { t, locale } = useI18n();
const busy = ref(false), error = ref(''), notice = ref('');
const monday = ref(weekStart(new Date()));
const events = ref([]);
const rules = ref([]);
const form = ref({ title: '', date: isoDate(new Date()), start: '09:00', end: '10:00', location: '' });
const rule = ref({ weekday: 1, starts_at: '09:00', ends_at: '17:00' });
const days = computed(() => Array.from({ length: 7 }, (_, i) => { const d = new Date(monday.value); d.setDate(d.getDate() + i); return d; }));
const dayEvents = d => events.value.filter(e => e.status !== 'cancelled' && isoDate(parseTs(e.starts_at)) === isoDate(d));
const when = e => parseTs(e.starts_at).toLocaleTimeString(locale.value, { hour: '2-digit', minute: '2-digit' });
const dayName = d => d.toLocaleDateString(locale.value, { weekday: 'long' });
const dayLabel = d => d.toLocaleDateString(locale.value, { month: 'short', day: 'numeric' });
const weekdayName = n => new Date(2024, 0, 7 + Number(n)).toLocaleDateString(locale.value, { weekday: 'long' });

async function run(action) {
    busy.value = true; error.value = ''; notice.value = '';
    try { await action(); } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
async function load() {
    const end = new Date(monday.value); end.setDate(end.getDate() + 7);
    events.value = (await wapi(`/events?from=${monday.value.toISOString()}&to=${end.toISOString()}`)).data;
    rules.value = (await wapi('/availability')).data;
}
onMounted(() => run(load));
const shift = n => { const d = new Date(monday.value); d.setDate(d.getDate() + n * 7); monday.value = d; run(load); };
const today = () => { monday.value = weekStart(new Date()); run(load); };
const add = () => run(async () => {
    const f = form.value;
    const start = new Date(`${f.date}T${f.start}`), end = new Date(`${f.date}T${f.end}`);
    await wapi('/events', 'POST', { title: f.title, location: f.location || null, starts_at: start.toISOString(), ends_at: end.toISOString() });
    form.value = { ...f, title: '', location: '' };
    notice.value = t('c_personal.ws_saved');
    await load();
});
const cancel = id => run(async () => { await wapi(`/events/${id}/cancel`, 'POST'); await load(); });
const saveRules = next => run(async () => { rules.value = (await wapi('/availability', 'PUT', { rules: next.map(r => ({ weekday: r.weekday, starts_at: r.starts_at.slice(0, 5), ends_at: r.ends_at.slice(0, 5) })) })).data; });
const addRule = () => saveRules([...rules.value, { ...rule.value, weekday: Number(rule.value.weekday) }]);
const dropRule = id => saveRules(rules.value.filter(r => r.id !== id));
</script>

<template>
    <PageScaffold>
        <template #intro>{{ t('c_personal.cal_intro') }}</template>

        <section class="app-setting-card" aria-labelledby="week-title">
            <h2 id="week-title">{{ t('c_personal.cal_week', { date: dayLabel(days[0]) }) }}</h2>
            <div class="app-setting-actions">
                <button class="btn btn--secondary" :disabled="busy" @click="shift(-1)">{{ t('c_personal.ws_prev') }}</button>
                <button class="btn btn--secondary" :disabled="busy" @click="today">{{ t('c_personal.ws_today') }}</button>
                <button class="btn btn--secondary" :disabled="busy" @click="shift(1)">{{ t('c_personal.ws_next') }}</button>
            </div>
            <div class="ws-week">
                <div v-for="d in days" :key="isoDate(d)" class="ws-day">
                    <h3>{{ dayName(d) }} <small>{{ dayLabel(d) }}</small></h3>
                    <p v-if="!dayEvents(d).length" class="muted">{{ t('c_personal.cal_free') }}</p>
                    <ul>
                        <li v-for="e in dayEvents(d)" :key="e.id">
                            <strong>{{ when(e) }}</strong> {{ e.title }}
                            <span v-if="e.location" class="muted"> · {{ e.location }}</span>
                            <button class="btn btn--secondary" :disabled="busy" @click="cancel(e.id)">{{ t('c_personal.cal_cancel') }}</button>
                        </li>
                    </ul>
                </div>
            </div>
        </section>

        <section class="app-setting-card" aria-labelledby="add-title">
            <h2 id="add-title">{{ t('c_personal.cal_add') }}</h2>
            <form @submit.prevent="add">
                <label>{{ t('c_personal.cal_title') }} <input v-model="form.title" required maxlength="200"></label>
                <label>{{ t('c_personal.cal_date') }} <input v-model="form.date" type="date" required></label>
                <label>{{ t('c_personal.cal_start') }} <input v-model="form.start" type="time" required></label>
                <label>{{ t('c_personal.cal_end') }} <input v-model="form.end" type="time" required></label>
                <label>{{ t('c_personal.cal_location') }} <input v-model="form.location" maxlength="200"></label>
                <button class="btn btn--primary" :disabled="busy">{{ t('c_personal.cal_book') }}</button>
            </form>
        </section>

        <section class="app-setting-card" aria-labelledby="avail-title">
            <h2 id="avail-title">{{ t('c_personal.cal_avail') }}</h2>
            <p>{{ t('c_personal.cal_avail_body') }}</p>
            <p v-if="!rules.length" class="muted">{{ t('c_personal.cal_avail_none') }}</p>
            <ul>
                <li v-for="r in rules" :key="r.id">
                    {{ weekdayName(r.weekday) }} {{ r.starts_at.slice(0, 5) }}–{{ r.ends_at.slice(0, 5) }}
                    <button class="btn btn--secondary" :disabled="busy" @click="dropRule(r.id)">{{ t('c_personal.ws_remove') }}</button>
                </li>
            </ul>
            <form @submit.prevent="addRule">
                <label>{{ t('c_personal.cal_day') }}
                    <select v-model="rule.weekday"><option v-for="n in 7" :key="n" :value="n % 7">{{ weekdayName(n % 7) }}</option></select>
                </label>
                <label>{{ t('c_personal.cal_start') }} <input v-model="rule.starts_at" type="time" required></label>
                <label>{{ t('c_personal.cal_end') }} <input v-model="rule.ends_at" type="time" required></label>
                <button class="btn btn--secondary" :disabled="busy">{{ t('c_personal.cal_avail_add') }}</button>
            </form>
        </section>

        <p v-if="notice" role="status">{{ notice }}</p>
        <p v-if="error" role="alert" class="field-error">{{ error }}</p>
    </PageScaffold>
</template>

<style scoped>
.ws-week { display: grid; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); gap: 0.75rem; }
.ws-day { border: 1px solid var(--line, currentColor); border-radius: 0.5rem; padding: 0.5rem 0.75rem; min-width: 0; }
.ws-day ul { list-style: none; padding: 0; margin: 0; }
.ws-day li { margin-block: 0.4rem; overflow-wrap: anywhere; }
</style>
