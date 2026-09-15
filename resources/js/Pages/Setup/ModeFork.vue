<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import AppShellV2 from '@/Layouts/AppShellV2.vue'
import { csrfHeaders } from '@/lib/csrf'

const { t } = useI18n()

// Setup wizard: minimal chrome (header + footer, no sidebar), wide canvas.
defineOptions({
    // ShellV2 (operator, 2026-08-04). Setup ran on the v1 shell with
    // `chrome: 'minimal'`, which is why it had NO bottom command bar and the
    // OLD dev controls: CmdBar and the Dev* panels are ShellV2 components, so
    // a v1 setup page could never receive either. Menus that cannot work yet
    // are locked by MenuNav while instance.setupComplete is false.
    layout: (h, page) => h(AppShellV2, { variant: 'wide' }, () => page),
})

defineProps({
    settings: { type: Object, required: true },
})

const submitting = ref(false)
const submitError = ref(null)

async function choose(mode) {
    if (submitting.value) return
    submitting.value = true
    submitError.value = null
    try {
        const res = await fetch('/api/setup/mode', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                ...csrfHeaders(),
            },
            body: JSON.stringify({ setup_mode: mode }),
        })
        const data = await res.json()
        if (!res.ok) {
            submitError.value = data.error || data.message || t('c_setup.mode_fork.err_set_mode', 'Could not set the mode.')
            return
        }
        router.visit(data.next || '/setup')
    } catch (e) {
        submitError.value = e.message || t('c_setup.mode_fork.err_network', 'Network error')
    } finally {
        submitting.value = false
    }
}
</script>

<template>
    <div class="max-w-4xl mx-auto px-6 py-12 w-full">
        <h1 class="text-2xl font-semibold text-white mb-2">{{ t('c_setup.mode_fork.heading', 'How should this node join the game?') }}</h1>
        <p class="text-gray-400 mb-8" v-html="t('c_setup.mode_fork.intro', 'Your operator account is set up. Every node in a mesh plays the same game — so the only question is whether this node <em>starts</em> a world or <em>joins</em> one that already exists.')"></p>

        <div v-if="submitError" class="mb-6 text-sm text-red-400">{{ submitError }}</div>

        <div class="grid md:grid-cols-2 gap-5">
            <button type="button" :disabled="submitting" @click="choose('solo')"
                class="text-left bg-gray-900 border border-gray-800 hover:border-emerald-600 rounded-xl p-6
                       disabled:opacity-50 transition-colors">
                <div class="text-emerald-400 text-xs font-semibold tracking-wide uppercase mb-2">{{ t('c_setup.mode_fork.solo_eyebrow', 'Start a new world') }}</div>
                <h2 class="text-white text-lg font-semibold mb-2">{{ t('c_setup.mode_fork.solo_title', 'Solo') }}</h2>
                <p class="text-gray-400 text-sm" v-html="t('c_setup.mode_fork.solo_body', 'Build the game here — author the constitution, load the map, draw districts, seat the institutions. <strong>You become the canonical game</strong>, and other nodes federate to you. Recommended for the first node in a federation.')"></p>
            </button>

            <button type="button" :disabled="submitting" @click="choose('join')"
                class="text-left bg-gray-900 border border-gray-800 hover:border-sky-600 rounded-xl p-6
                       disabled:opacity-50 transition-colors">
                <div class="text-sky-400 text-xs font-semibold tracking-wide uppercase mb-2">{{ t('c_setup.mode_fork.join_eyebrow', 'Join an existing mesh') }}</div>
                <h2 class="text-white text-lg font-semibold mb-2">{{ t('c_setup.mode_fork.join_title', 'Join') }}</h2>
                <p class="text-gray-400 text-sm" v-html="t('c_setup.mode_fork.join_body', 'Connect to a host already in the federation. <strong>Everything syncs in</strong> — the map foundation, the constitution, and every institution — and this node becomes a read-only mirror. No institution-building; you\'re playing the same game as the rest of the mesh.')"></p>
            </button>
        </div>

        <p class="text-gray-600 text-xs mt-8" v-html="t('c_setup.mode_fork.footer', 'This choice is one-way for the life of the instance. Tearing down and starting over is <code class=&quot;text-gray-400&quot;>docker compose down -v</code>.')"></p>
    </div>
</template>
