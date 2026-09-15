<script setup>
/**
 * Learn/MaterialEdit — revise one existing training module (LE-2, F-EDU-002).
 *
 * Pre-filled from the module's current row; action defaults to revise. Same
 * rails as the manager: reading is open, filing is R-23's (engine-enforced),
 * structure only, no answer key, prose stays in the K-2 source.
 */
import { reactive, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';

defineOptions({ layout: AppShellV2 });

const { t } = useI18n();

const props = defineProps({
    surface: { type: Object, required: true },
    can: { type: Object, default: () => ({ publish: false }) },
    tracks: { type: Array, default: () => [] },
    surfaces: { type: Array, default: () => [] },
    module: { type: Object, required: true },
});

const form = reactive({
    module_key: props.module.module_key || '',
    title: props.module.title || '',
    track_key: props.module.track_key || '',
    surface_id: props.module.surface_id || '',
    minutes: props.module.minutes ?? null,
    status: props.module.status || 'draft',
    action: 'revise',
    ip_register_entry_id: '',
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
        onError: (errors) => { error.value = Object.values(errors)[0] || t('c_front.material_edit.error_generic', 'The revision could not be filed. Please retry.'); },
        onSuccess: () => { notice.value = t('c_front.material_edit.notice_filed', 'Revision filed on the public record.'); },
    });
}
</script>

<template>
    <PageScaffold :surface="surface" :title="t('c_front.material_edit.page_title', 'Edit a training module')">
        <template #intro>{{ t('c_front.material_edit.intro', 'Revise this module\'s structure. Lesson prose is authored in the K-2 source, not on this form.') }}</template>

        <p class="meta">{{ t('c_front.material_edit.current_revision', { n: module.revision_number }) }} <Link href="/learn/manage">{{ t('c_front.material_edit.back_all', 'Back to all modules') }}</Link></p>

        <p v-if="!can.publish" role="status" class="preview">
            {{ t('c_front.material_edit.preview', 'Read-only preview. Revising training material requires the authoring body\'s agent role (R-23).') }}
        </p>

        <form v-if="can.publish" class="publish-form" :aria-busy="busy" @submit.prevent="submit">
            <h2>{{ t('c_front.material_edit.form_h', 'Revise or re-publish this module') }}</h2>
            <p class="gloss">{{ t('c_front.material_edit.gloss', 'This form writes the module row only. The answer key never rides it.') }}</p>

            <label for="edit-action">{{ t('c_front.material_edit.action_label', 'Action') }}</label>
            <select id="edit-action" v-model="form.action">
                <option value="revise">{{ t('c_front.material_edit.opt_revise', 'Revise (increment revision)') }}</option>
                <option value="publish">{{ t('c_front.material_edit.opt_publish', 'Publish (reset to revision 1)') }}</option>
            </select>

            <label for="edit-module-key">{{ t('c_front.material_edit.module_key_label', 'Module key') }}</label>
            <input id="edit-module-key" v-model="form.module_key" maxlength="64" required />

            <label for="edit-title">{{ t('c_front.material_edit.title_label', 'Title') }}</label>
            <input id="edit-title" v-model="form.title" maxlength="160" required />

            <label for="edit-track">{{ t('c_front.material_edit.track_label', 'Track') }}</label>
            <select id="edit-track" v-model="form.track_key" required>
                <option value="" disabled>{{ t('c_front.material_edit.select_track', 'Select a track') }}</option>
                <option v-for="track in tracks" :key="track.key" :value="track.key">{{ track.title }} ({{ track.status }})</option>
            </select>

            <label for="edit-surface">{{ t('c_front.material_edit.surface_label', 'Surface') }}</label>
            <select id="edit-surface" v-model="form.surface_id" required>
                <option value="" disabled>{{ t('c_front.material_edit.select_surface', 'Select a registered surface') }}</option>
                <option v-for="s in surfaces" :key="s.id" :value="s.id">{{ s.title }} ({{ s.id }})</option>
            </select>

            <label for="edit-minutes">{{ t('c_front.material_edit.minutes_label', 'Minutes (optional)') }}</label>
            <input id="edit-minutes" v-model="form.minutes" type="number" min="0" max="65535" />

            <label for="edit-status">{{ t('c_front.material_edit.status_label', 'Status') }}</label>
            <select id="edit-status" v-model="form.status">
                <option value="draft">{{ t('c_front.material_edit.status_draft', 'Draft (not shown to learners; gate not armed)') }}</option>
                <option value="live">{{ t('c_front.material_edit.status_live', 'Live (shown to learners; arms the role\'s training gate)') }}</option>
            </select>

            <label for="edit-ip">{{ t('c_front.material_edit.ip_label', 'IP dedication reference (optional)') }}</label>
            <input id="edit-ip" v-model="form.ip_register_entry_id" maxlength="64" />

            <button type="submit" :disabled="busy || !form.module_key.trim() || !form.title.trim() || !form.track_key || !form.surface_id">
                {{ form.action === 'revise' ? t('c_front.material_edit.submit_revise', 'File revision') : t('c_front.material_edit.submit_republish', 'Re-publish module') }}
            </button>
            <p v-if="busy" role="status">{{ t('c_front.material_edit.filing', 'Filing revision…') }}</p>
            <p v-if="error" role="alert">{{ error }}</p>
            <p v-if="notice" role="status">{{ notice }}</p>
        </form>
    </PageScaffold>
</template>

<style scoped>
.publish-form { display: grid; gap: .65rem; max-inline-size: 42rem; }
.preview { border: 1px solid var(--border, #344054); padding: .75rem; border-radius: .5rem; }
button, input, select { min-block-size: 44px; font: inherit; }
input, select { inline-size: 100%; max-inline-size: 42rem; }
button { inline-size: fit-content; }
.meta { font-size: .9rem; }
.gloss { opacity: .8; }
</style>
