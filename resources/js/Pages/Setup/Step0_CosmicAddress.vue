<script setup>
import { computed, ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import AppShellV2 from '@/Layouts/AppShellV2.vue'
import SetupStepper from '@/Components/SetupStepper.vue'
import CosmicAddressPicker from '@/Components/CosmicAddressPicker.vue'
import ImportBackupPanel from '@/Components/Setup/ImportBackupPanel.vue'
import { csrfFetch } from '@/lib/csrf'

// Setup wizard: minimal chrome (header + footer, no sidebar), wide canvas.
defineOptions({
    // ShellV2 (operator, 2026-08-04). Setup ran on the v1 shell with
    // `chrome: 'minimal'`, which is why it had NO bottom command bar and the
    // OLD dev controls: CmdBar and the Dev* panels are ShellV2 components, so
    // a v1 setup page could never receive either. Menus that cannot work yet
    // are locked by MenuNav while instance.setupComplete is false.
    layout: (h, page) => h(AppShellV2, { variant: 'wide' }, () => page),
})

const { t } = useI18n()

const props = defineProps({
    step: { type: Number, required: true },
    settings: { type: Object, required: true },
})

const instanceName = ref(props.settings.instance_name || '')
const cosmicAddressId = ref(props.settings.cosmic_address_id || null)
const cosmicPath = ref([])
const timeMode = ref(props.settings.time_mode || 'real')
const timeScaleSeconds = ref(props.settings.time_scale_seconds_per_year || 31536000)

const submitting = ref(false)
const submitError = ref(null)

const TIME_SCALE_PRESETS = [
    { seconds: 1, label: t('c_setup.step0_cosmic_address.preset_1_second', '1 second') },
    { seconds: 60, label: t('c_setup.step0_cosmic_address.preset_1_minute', '1 minute') },
    { seconds: 3600, label: t('c_setup.step0_cosmic_address.preset_1_hour', '1 hour') },
    { seconds: 86400, label: t('c_setup.step0_cosmic_address.preset_1_day', '1 day') },
    { seconds: 604800, label: t('c_setup.step0_cosmic_address.preset_1_week', '1 week') },
    { seconds: 2592000, label: t('c_setup.step0_cosmic_address.preset_30_days', '30 days') },
    { seconds: 31536000, label: t('c_setup.step0_cosmic_address.preset_1_year', '1 year (real-time)') },
]

const electionCycleText = computed(() => {
    const seconds = timeScaleSeconds.value * 5
    if (seconds < 60) return t('c_setup.step0_cosmic_address.cycle_seconds', { n: seconds })
    if (seconds < 3600) return t('c_setup.step0_cosmic_address.cycle_minutes', { n: Math.round(seconds / 60) })
    if (seconds < 86400) return t('c_setup.step0_cosmic_address.cycle_hours', { n: Math.round(seconds / 3600) })
    if (seconds < 604800) return t('c_setup.step0_cosmic_address.cycle_days', { n: Math.round(seconds / 86400) })
    return t('c_setup.step0_cosmic_address.cycle_years', { n: (seconds / 31536000).toFixed(1) })
})

function onPathChange(path) {
    cosmicPath.value = path
}

const pathBreadcrumb = computed(() => {
    if (!cosmicPath.value.length) return t('c_setup.step0_cosmic_address.breadcrumb_root', 'Multiverse ▸ ...')
    return t('c_setup.step0_cosmic_address.breadcrumb', { path: cosmicPath.value.map(p => p.selectedLabel).join(' ▸ ') })
})

async function onSubmit() {
    submitting.value = true
    submitError.value = null
    try {
        const res = await csrfFetch('/api/setup/cosmic-address', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                instance_name: instanceName.value.trim(),
                cosmic_address_id: cosmicAddressId.value,
                time_mode: timeMode.value,
                time_scale_seconds_per_year: timeMode.value === 'accelerated' ? timeScaleSeconds.value : null,
            }),
        })
        const data = await res.json()
        if (!res.ok) {
            submitError.value = data.error || data.message || t('c_setup.step0_cosmic_address.err_submit', 'Submission failed')
            return
        }
        router.visit(data.next || '/setup/step/1')
    } catch (e) {
        submitError.value = e.message || t('c_setup.step0_cosmic_address.err_network', 'Network error')
    } finally {
        submitting.value = false
    }
}

const canSubmit = computed(() =>
    !!instanceName.value.trim() && !!cosmicAddressId.value
)
</script>

<template>
    <div class="max-w-4xl mx-auto px-6 py-8 w-full">
        <Head :title="t('c_setup.step0_cosmic_address.welcome', 'Welcome to the Cosmopolitan Coalition')" />
            <SetupStepper :current="0" :completed="settings.setup_step_completed" :steps="settings.ladder" />

            <header class="mt-8 mb-6">
                <h1 class="text-3xl font-bold text-white mb-2">
                    {{ t('c_setup.step0_cosmic_address.welcome', 'Welcome to the Cosmopolitan Coalition') }}
                </h1>
                <p class="text-gray-300 leading-relaxed" v-html="t('c_setup.step0_cosmic_address.intro', 'This is the Cosmopolitan Governance App — a federated, open-source platform implementing <em>A Fair Constitution</em>. It models every institution the Template defines (residency, elections, legislatures, executives, judiciaries, organizations) as interactive, automatable software, from neighborhood scale up to planetary scale and beyond.')"></p>
                <p class="text-gray-400 text-sm mt-3">
                    {{ t('c_setup.step0_cosmic_address.set_address', 'Let\'s set your cosmic address — the anchor your instance will federate under.') }}
                </p>
            </header>

            <!-- Single restore entry point for the whole setup flow.
                 Step 0 is the only place this panel lives — uploading a
                 bundle here lands the operator on whatever step the
                 bundle's setup_step_completed dictates, so there's no
                 reason to expose it again later in the wizard. -->
            <ImportBackupPanel :title="t('c_setup.step0_cosmic_address.restore_title', 'Or restore from a backup')" :disabled="submitting" />

            <section class="bg-gray-900 border border-gray-800 rounded-lg p-6 space-y-6">
                <div>
                    <label for="instance-name" class="block text-sm font-semibold text-gray-200 mb-2">
                        {{ t('c_setup.step0_cosmic_address.instance_name', 'Instance Name') }}
                    </label>
                    <input
                        id="instance-name"
                        v-model="instanceName"
                        type="text"
                        :placeholder="t('c_setup.step0_cosmic_address.instance_name_placeholder', 'e.g. Earth Prime, Midgard, Starfall')"
                        class="w-full bg-gray-950 border border-gray-700 rounded-md px-3 py-2 text-gray-100 focus:border-blue-500 focus:outline-none"
                    />
                    <p class="text-xs text-gray-400 mt-1">
                        {{ t('c_setup.step0_cosmic_address.instance_name_help', 'A friendly label for this install — shown in nav and federation directories.') }}
                    </p>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-200 mb-2">
                        {{ t('c_setup.step0_cosmic_address.cosmic_address', 'Cosmic Address') }}
                    </label>
                    <CosmicAddressPicker
                        v-model="cosmicAddressId"
                        @path-change="onPathChange"
                    />
                    <p class="text-xs text-gray-400 mt-2 truncate" :title="pathBreadcrumb">
                        {{ pathBreadcrumb }}
                    </p>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-200 mb-2">
                        {{ t('c_setup.step0_cosmic_address.time_mode', 'Time Mode') }}
                    </label>
                    <div class="flex gap-3">
                        <label
                            :class="[
                                'flex-1 border rounded-md p-3 cursor-pointer',
                                timeMode === 'real' ? 'border-blue-500 bg-blue-900/30' : 'border-gray-700 bg-gray-950',
                            ]"
                        >
                            <input type="radio" value="real" v-model="timeMode" class="sr-only" />
                            <div class="font-medium text-gray-100">{{ t('c_setup.step0_cosmic_address.real_time', 'Real-time') }}</div>
                            <p class="text-xs text-gray-400 mt-1">{{ t('c_setup.step0_cosmic_address.real_time_help', 'Elections on wall-clock — 5-year default cycle.') }}</p>
                        </label>
                        <label
                            :class="[
                                'flex-1 border rounded-md p-3 cursor-pointer',
                                timeMode === 'accelerated' ? 'border-blue-500 bg-blue-900/30' : 'border-gray-700 bg-gray-950',
                            ]"
                        >
                            <input type="radio" value="accelerated" v-model="timeMode" class="sr-only" />
                            <div class="font-medium text-gray-100">{{ t('c_setup.step0_cosmic_address.accelerated', 'Accelerated') }}</div>
                            <p class="text-xs text-gray-400 mt-1">{{ t('c_setup.step0_cosmic_address.accelerated_help', 'Compressed simulation — pick seconds per simulated year.') }}</p>
                        </label>
                    </div>

                    <div v-if="timeMode === 'accelerated'" class="mt-3 bg-gray-950 border border-gray-800 rounded-md p-3">
                        <label class="block text-xs text-gray-400 mb-1">
                            {{ t('c_setup.step0_cosmic_address.seconds_per_year', 'Seconds per simulated year') }}
                        </label>
                        <div class="flex items-center gap-2">
                            <select
                                v-model.number="timeScaleSeconds"
                                :aria-label="t('c_setup.step0_cosmic_address.seconds_per_year', 'Seconds per simulated year')"
                                class="bg-gray-900 border border-gray-700 rounded-md px-3 py-2 text-sm text-gray-100"
                            >
                                <option v-for="p in TIME_SCALE_PRESETS" :key="p.seconds" :value="p.seconds">
                                    {{ p.label }}
                                </option>
                            </select>
                            <span class="text-xs text-gray-400">
                                {{ t('c_setup.step0_cosmic_address.cycle_label', { cycle: electionCycleText }) }}
                            </span>
                        </div>
                    </div>
                </div>

                <div v-if="submitError" class="text-sm text-red-400 bg-red-900/30 border border-red-800 rounded p-2">
                    {{ submitError }}
                </div>

                <div class="flex justify-end pt-2 border-t border-gray-800">
                    <button
                        type="button"
                        :disabled="!canSubmit || submitting"
                        @click="onSubmit"
                        class="bg-blue-600 hover:bg-blue-500 disabled:bg-gray-700 disabled:cursor-not-allowed text-white px-5 py-2 rounded-md font-semibold transition-colors"
                    >
                        {{ submitting ? t('c_setup.step0_cosmic_address.saving', 'Saving…') : t('c_setup.step0_cosmic_address.continue', 'Continue →') }}
                    </button>
                </div>
            </section>
    </div>
</template>
