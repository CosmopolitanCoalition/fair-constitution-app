<script setup>
/**
 * Learn/MaterialManager — the training-material shelf (LE-2, F-EDU-002).
 *
 * Reading is open to everyone (the read-everywhere rule): the module list
 * renders for any viewer. Filing is R-23's — can.publish gates the form; a
 * non-holder sees a read-only preview. The engine is the real gate.
 *
 * This form writes module STRUCTURE only. Lesson prose is authored in the
 * K-2 source, never here, and the answer key never touches this surface.
 */
import { reactive, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';

defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    can: { type: Object, default: () => ({ publish: false }) },
    tracks: { type: Array, default: () => [] },
    surfaces: { type: Array, default: () => [] },
    modules: { type: Object, default: () => ({ rows: [], pages: {} }) },
});

const form = reactive({
    module_key: '', title: '', track_key: '', surface_id: '',
    minutes: null, status: 'draft', action: 'publish', ip_register_entry_id: '',
});
const busy = ref(false), error = ref(''), notice = ref('');

function submit() {
    if (busy.value || !props.can.publish) return;
    if (!form.module_key.trim() || !form.title.trim() || !form.track_key || !form.surface_id) return;
    const data = {
        module_key: form.module_key.trim(), title: form.title.trim(), action: form.action,
        track_key: form.track_key, surface_id: form.surface_id,
        minutes: form.minutes === null || form.minutes === '' ? null : Number(form.minutes),
        status: form.status,
        ip_register_entry_id: form.ip_register_entry_id.trim() || null,
    };
    router.post('/learn/manage', data, {
        preserveScroll: true,
        onStart: () => { busy.value = true; error.value = ''; notice.value = ''; },
        onFinish: () => { busy.value = false; },
        onError: (errors) => { error.value = Object.values(errors)[0] || 'The publication could not be filed. Please retry.'; },
        onSuccess: () => { notice.value = 'Publication filed on the public record.'; form.module_key = ''; form.title = ''; },
    });
}
</script>

<template>
    <PageScaffold :surface="surface" title="Manage training material">
        <template #intro>Publish and revise training modules. Lesson prose is authored in the K-2 source, not on this form.</template>

        <p v-if="!can.publish" role="status" class="preview">
            Read-only preview. Publishing training material requires the authoring body's agent role (R-23).
        </p>

        <form v-if="can.publish" class="publish-form" :aria-busy="busy" @submit.prevent="submit">
            <h2>Publish or revise a module</h2>
            <p class="gloss">Lesson prose lives in the K-2 source (docs/plans/education/K2_CONTENT_*.md). This form writes the module row only. The answer key never rides it.</p>

            <label for="material-action">Action</label>
            <select id="material-action" v-model="form.action">
                <option value="publish">Publish (first edition)</option>
                <option value="revise">Revise (existing module)</option>
            </select>

            <label for="material-module-key">Module key</label>
            <input id="material-module-key" v-model="form.module_key" maxlength="64" required />

            <label for="material-title">Title</label>
            <input id="material-title" v-model="form.title" maxlength="160" required />

            <label for="material-track">Track</label>
            <select id="material-track" v-model="form.track_key" required>
                <option value="" disabled>Select a track</option>
                <option v-for="track in tracks" :key="track.key" :value="track.key">{{ track.title }} ({{ track.status }})</option>
            </select>

            <label for="material-surface">Surface</label>
            <select id="material-surface" v-model="form.surface_id" required>
                <option value="" disabled>Select a registered surface</option>
                <option v-for="s in surfaces" :key="s.id" :value="s.id">{{ s.title }} ({{ s.id }})</option>
            </select>

            <label for="material-minutes">Minutes (optional)</label>
            <input id="material-minutes" v-model="form.minutes" type="number" min="0" max="65535" />

            <label for="material-status">Status</label>
            <select id="material-status" v-model="form.status">
                <option value="draft">Draft (not shown to learners; gate not armed)</option>
                <option value="live">Live (shown to learners; arms the role's training gate)</option>
            </select>

            <label for="material-ip">IP dedication reference (optional)</label>
            <input id="material-ip" v-model="form.ip_register_entry_id" maxlength="64" />

            <button type="submit" :disabled="busy || !form.module_key.trim() || !form.title.trim() || !form.track_key || !form.surface_id">
                {{ form.action === 'revise' ? 'File revision' : 'Publish module' }}
            </button>
            <p v-if="busy" role="status">Filing publication…</p>
            <p v-if="error" role="alert">{{ error }}</p>
            <p v-if="notice" role="status">{{ notice }}</p>
        </form>

        <section class="module-list" aria-labelledby="modules-h">
            <h2 id="modules-h">Published and draft modules</h2>
            <p v-if="!modules.rows.length" class="gloss">No modules are published yet.</p>
            <article v-for="m in modules.rows" :key="m.module_key + m.track_key" class="module-row">
                <h3>
                    <Link :href="m.edit_href">{{ m.title }}</Link>
                    <span class="status">{{ m.status }}</span>
                </h3>
                <p class="meta">
                    {{ m.track_title }} · <code>{{ m.module_key }}</code> · revision {{ m.revision_number }}
                    <span v-if="m.published_by"> · published by {{ m.published_by }}</span>
                    <span v-if="m.published_at"> · {{ m.published_at }}</span>
                </p>
            </article>
            <Link v-if="modules.pages && modules.pages.next" :href="modules.pages.next" class="pager-next">Next modules</Link>
        </section>
    </PageScaffold>
</template>

<style scoped>
.publish-form { display: grid; gap: .65rem; max-inline-size: 42rem; }
.preview { border: 1px solid var(--border, #344054); padding: .75rem; border-radius: .5rem; }
button, input, select { min-block-size: 44px; font: inherit; }
input, select { inline-size: 100%; max-inline-size: 42rem; }
button { inline-size: fit-content; }
.module-row { border-block-start: 1px solid var(--border, #344054); padding-block: .65rem; }
.module-row h3 { display: flex; gap: .75rem; align-items: baseline; }
.status { font-size: .8rem; text-transform: uppercase; }
.meta { font-size: .9rem; }
.gloss { opacity: .8; }
</style>
