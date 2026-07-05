<script setup>
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import SetupStepper from '@/Components/SetupStepper.vue'
import { csrfFetch } from '@/lib/csrf'

// Setup wizard: minimal chrome (header + footer, no sidebar), wide canvas.
defineOptions({
    layout: (h, page) => h(AppShell, { chrome: 'minimal', variant: 'wide' }, () => page),
})

const props = defineProps({
    step: { type: Number, required: true },
    settings: { type: Object, required: true },
    // Current constitutional defaults — comes from the planet row's
    // constitutional_settings (post-Map-Data) or pending_constitutional_defaults
    // (pre-Map-Data stash) or Fair Constitution Template defaults (fresh wizard).
    // Lets the form display the actual saved state when the operator revisits
    // Step 1 instead of always re-showing 5/9 template defaults.
    constants: {
        type: Object,
        default: () => ({}),
    },
})

// Pull from props.constants with the legacy hardcoded values as final fallback.
const c = props.constants ?? {}

// ── Legislature sizing ──────────────────────────────────────────────
const minSeats   = ref(c.legislature_min_seats   ?? 5)
const maxSeats   = ref(c.legislature_max_seats   ?? 9)
const sizingLaw  = ref(c.legislature_sizing_law  ?? 'cube_root')

// ── Elections ──────────────────────────────────────────────────────
const electionInterval       = ref(c.election_interval_months  ?? 60)
const votingMethod           = ref(c.voting_method             ?? 'stv_droop')
const specialElectionMinDays = ref(c.special_election_min_days ?? 90)
const specialElectionMaxDays = ref(c.special_election_max_days ?? 180)

// ── Governance ─────────────────────────────────────────────────────
const supermajorityN         = ref(c.supermajority_numerator     ?? 2)
const supermajorityD         = ref(c.supermajority_denominator   ?? 3)
const maxDaysBetweenMeetings = ref(c.max_days_between_meetings   ?? 90)
const emergencyPowersMaxDays = ref(c.emergency_powers_max_days   ?? 90)

// ── Appointments & Judiciary ───────────────────────────────────────
const civilAppointmentYears      = ref(c.civil_appointment_years        ?? 10)
const judicialAppointmentYears   = ref(c.judicial_appointment_years     ?? 10)
const judiciaryMinJudgesPerRace  = ref(c.judiciary_min_judges_per_race  ?? 5)
const judiciaryIsElected         = ref(c.judiciary_is_elected           ?? false)

// ── Organizations & Workers ────────────────────────────────────────
const workerRepMinEmployees      = ref(c.worker_rep_min_employees       ?? 100)
const workerRepParityEmployees   = ref(c.worker_rep_parity_employees    ?? 2000)

// ── Residency & Initiative ─────────────────────────────────────────
const residencyConfirmationDays       = ref(c.residency_confirmation_days        ?? 30)
const initiativePetitionThresholdPct  = ref(c.initiative_petition_threshold_pct  ?? 5.00)

// ── Economy ─────────────────────────────────────────────────────────
// The starting economy for the world — these cascade to child
// jurisdictions just like the constitutional defaults above.
const currencyName       = ref(c.currency_name       ?? 'Civic Value Unit')
const currencyCode       = ref(c.currency_code       ?? 'CVU')
const currencySymbol     = ref(c.currency_symbol     ?? 'ç')
const civicStipendFloor  = ref(c.civic_stipend_floor ?? 50)
const stipendBumpCap     = ref(c.stipend_bump_cap    ?? 20)
const payNodeOperator    = ref(c.pay_node_operator   ?? 8)
const paySocialModerator = ref(c.pay_social_moderator ?? 5)
const payOfficeHolder    = ref(c.pay_office_holder   ?? 12)
const stipendInterval    = ref(c.stipend_interval    ?? 'monthly')

const STIPEND_INTERVALS = [
    { id: 'monthly',   label: 'Monthly' },
    { id: 'quarterly', label: 'Quarterly' },
    { id: 'per_cycle', label: 'Per cycle' },
]

// ── Game Mode (WORLD property) ──────────────────────────────────────
// Seeded from the world settings, not the per-jurisdiction constants.
// Selecting a mode saves immediately (own endpoint), independent of the
// constitutional-defaults submit below.
const gameMode       = ref(props.settings.game_mode ?? 'production')
const gameModeSaving = ref(false)
const gameModeError  = ref(null)

const GAME_MODES = [
    {
        id: 'production',
        label: 'Production',
        blurb: 'The world operates strictly within its constitutional constraints. Every role, qualification, and gate is enforced as written.',
    },
    {
        id: 'sandbox',
        label: 'Sandbox / Dev',
        blurb: 'No constitutional hardening — a dev toolbox can assume any role and manufacture qualifications. For demoing, testing, and building the world before it goes live.',
    },
]

async function selectGameMode(mode) {
    if (mode === gameMode.value || gameModeSaving.value) return
    const previous = gameMode.value
    gameMode.value = mode // optimistic
    gameModeSaving.value = true
    gameModeError.value = null
    try {
        const res = await csrfFetch('/api/setup/game-mode', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ game_mode: mode }),
        })
        const data = await res.json()
        if (!res.ok) {
            gameMode.value = previous // revert
            gameModeError.value = data.error || data.message || 'Could not save game mode.'
            return
        }
        gameMode.value = data.settings?.game_mode ?? mode
    } catch (e) {
        gameMode.value = previous // revert
        gameModeError.value = e.message || 'Network error saving game mode.'
    } finally {
        gameModeSaving.value = false
    }
}

const submitting = ref(false)
const submitError = ref(null)

const SIZING_LAWS = [
    { id: 'cube_root', label: 'Cube-Root Law — round(population^(1/3))', enabled: true },
]

const VOTING_METHODS = [
    { id: 'stv_droop', label: 'STV with Droop Quota', enabled: true },
]

const supermajorityRatio = computed(() => {
    if (!supermajorityD.value) return '0%'
    return ((supermajorityN.value / supermajorityD.value) * 100).toFixed(1) + '%'
})

const supermajorityValid = computed(() =>
    supermajorityD.value >= 2 && (supermajorityN.value / supermajorityD.value) > 0.5
)

const seatsValid = computed(() => minSeats.value >= 1 && maxSeats.value >= minSeats.value)

const specialElectionValid = computed(() =>
    specialElectionMinDays.value >= 1 && specialElectionMaxDays.value >= specialElectionMinDays.value
)

const workerThresholdsValid = computed(() =>
    workerRepMinEmployees.value >= 1 && workerRepParityEmployees.value >= workerRepMinEmployees.value
)

const acceleratedHint = computed(() => {
    if (props.settings.time_mode !== 'accelerated') return null
    const secondsPerYear = props.settings.time_scale_seconds_per_year ?? 31536000
    const totalSeconds = secondsPerYear * (electionInterval.value / 12)
    if (totalSeconds < 60) return `≈ ${totalSeconds.toFixed(0)}s of wall-clock time`
    if (totalSeconds < 3600) return `≈ ${(totalSeconds / 60).toFixed(1)} min`
    if (totalSeconds < 86400) return `≈ ${(totalSeconds / 3600).toFixed(1)} hours`
    if (totalSeconds < 31536000) return `≈ ${(totalSeconds / 86400).toFixed(1)} days`
    return `≈ ${(totalSeconds / 31536000).toFixed(1)} years`
})

const canSubmit = computed(() =>
    supermajorityValid.value &&
    seatsValid.value &&
    specialElectionValid.value &&
    workerThresholdsValid.value &&
    electionInterval.value > 0 &&
    maxDaysBetweenMeetings.value > 0 &&
    emergencyPowersMaxDays.value > 0 &&
    civilAppointmentYears.value > 0 &&
    judicialAppointmentYears.value > 0 &&
    judiciaryMinJudgesPerRace.value >= 1 &&
    residencyConfirmationDays.value > 0 &&
    initiativePetitionThresholdPct.value > 0
)

async function onSubmit() {
    submitting.value = true
    submitError.value = null
    try {
        const res = await csrfFetch('/api/setup/constants', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                legislature_min_seats: minSeats.value,
                legislature_max_seats: maxSeats.value,
                legislature_sizing_law: sizingLaw.value,
                election_interval_months: electionInterval.value,
                voting_method: votingMethod.value,
                special_election_min_days: specialElectionMinDays.value,
                special_election_max_days: specialElectionMaxDays.value,
                supermajority_numerator: supermajorityN.value,
                supermajority_denominator: supermajorityD.value,
                max_days_between_meetings: maxDaysBetweenMeetings.value,
                emergency_powers_max_days: emergencyPowersMaxDays.value,
                civil_appointment_years: civilAppointmentYears.value,
                judicial_appointment_years: judicialAppointmentYears.value,
                judiciary_min_judges_per_race: judiciaryMinJudgesPerRace.value,
                judiciary_is_elected: judiciaryIsElected.value,
                worker_rep_min_employees: workerRepMinEmployees.value,
                worker_rep_parity_employees: workerRepParityEmployees.value,
                residency_confirmation_days: residencyConfirmationDays.value,
                initiative_petition_threshold_pct: initiativePetitionThresholdPct.value,
                // Economy defaults — backend now requires these.
                currency_name: currencyName.value,
                currency_code: currencyCode.value,
                currency_symbol: currencySymbol.value,
                civic_stipend_floor: civicStipendFloor.value,
                stipend_bump_cap: stipendBumpCap.value,
                pay_node_operator: payNodeOperator.value,
                pay_social_moderator: paySocialModerator.value,
                pay_office_holder: payOfficeHolder.value,
                stipend_interval: stipendInterval.value,
            }),
        })
        const data = await res.json()
        if (!res.ok) {
            submitError.value = data.error || data.message || 'Submission failed'
            return
        }
        router.visit(data.next || '/setup/step/2')
    } catch (e) {
        submitError.value = e.message || 'Network error'
    } finally {
        submitting.value = false
    }
}
</script>

<template>
    <div class="max-w-4xl mx-auto px-6 py-8 w-full">
            <SetupStepper :current="1" :completed="settings.setup_step_completed" />

            <header class="mt-8 mb-6">
                <h1 class="text-3xl font-bold text-white mb-2">
                    Constitution &amp; Economy Defaults
                </h1>
                <p class="text-gray-400 text-sm max-w-3xl">
                    You are founding the constitution and its economy now — the values below are the
                    Fair Constitution Template's suggested defaults (the "defaults of defaults"),
                    shown as reference. You can depart from them here.
                    After setup, any further amendments must go through valid legislative acts.
                </p>
            </header>

            <!-- Set once, inherited everywhere. -->
            <div class="flex items-start gap-3 bg-blue-950/40 border border-blue-900 rounded-lg p-4 mb-6">
                <div class="text-blue-300 mt-0.5">ℹ</div>
                <p class="text-sm text-gray-300">
                    <span class="font-semibold text-gray-100">Set once, inherited everywhere.</span>
                    Every constitutional and economic default here seeds each jurisdiction's own
                    amendable settings and cascades to new child jurisdictions as they come online —
                    so they don't reinvent the wheel. Each jurisdiction can still amend its own
                    values locally once its legitimacy gate activates.
                    The <span class="font-semibold text-gray-100">game mode</span> below is a
                    world-wide property, not per-jurisdiction.
                </p>
            </div>

            <!-- ─────────── Game Mode (WORLD property) ─────────── -->
            <section class="bg-gray-900 border border-gray-800 rounded-lg p-6 mb-5">
                <h2 class="text-lg font-semibold text-white mb-1">Game Mode</h2>
                <p class="text-sm text-gray-400 mb-4">
                    A world-wide setting. Choose how strictly this world enforces its constitution.
                    Saved immediately when you pick.
                </p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <button
                        v-for="mode in GAME_MODES"
                        :key="mode.id"
                        type="button"
                        :disabled="gameModeSaving"
                        @click="selectGameMode(mode.id)"
                        :class="[
                            'text-left rounded-lg border p-4 transition-colors disabled:opacity-60 disabled:cursor-not-allowed',
                            gameMode === mode.id
                                ? 'border-blue-500 bg-blue-950/40 ring-1 ring-blue-500'
                                : 'border-gray-700 bg-gray-950 hover:border-gray-600',
                        ]"
                    >
                        <div class="flex items-center justify-between mb-1">
                            <span class="font-semibold text-white">{{ mode.label }}</span>
                            <span
                                v-if="gameMode === mode.id"
                                class="text-xs font-semibold text-blue-300 bg-blue-900/60 rounded px-2 py-0.5"
                            >
                                Selected
                            </span>
                        </div>
                        <p class="text-xs text-gray-400 leading-relaxed">{{ mode.blurb }}</p>
                    </button>
                </div>
                <p v-if="gameModeError" class="text-xs text-red-400 mt-3">{{ gameModeError }}</p>
                <p v-else-if="gameModeSaving" class="text-xs text-gray-500 mt-3">Saving…</p>
            </section>

            <!-- ─────────── Legislature ─────────── -->
            <section class="bg-gray-900 border border-gray-800 rounded-lg p-6 mb-5">
                <h2 class="text-lg font-semibold text-white mb-4">Legislature</h2>
                <div class="space-y-5">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-200 mb-1">
                                Minimum seats per legislature
                            </label>
                            <input
                                v-model.number="minSeats"
                                type="number"
                                min="1"
                                class="w-full bg-gray-950 border border-gray-700 rounded-md px-3 py-2 text-gray-100 focus:border-blue-500 focus:outline-none"
                            />
                            <p class="text-xs text-gray-500 mt-1">
                                Default of defaults: <span class="text-gray-300">5</span> · Art. II §2
                            </p>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-200 mb-1">
                                Maximum seats per legislature
                            </label>
                            <input
                                v-model.number="maxSeats"
                                type="number"
                                min="1"
                                class="w-full bg-gray-950 border border-gray-700 rounded-md px-3 py-2 text-gray-100 focus:border-blue-500 focus:outline-none"
                            />
                            <p class="text-xs text-gray-500 mt-1">
                                Default of defaults: <span class="text-gray-300">9</span> (before mandatory subdivision) · Art. II §2
                            </p>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-200 mb-1">
                            Legislature Sizing Law
                        </label>
                        <select
                            v-model="sizingLaw"
                            class="w-full bg-gray-950 border border-gray-700 rounded-md px-3 py-2 text-gray-100"
                        >
                            <option v-for="law in SIZING_LAWS" :key="law.id" :value="law.id" :disabled="!law.enabled">
                                {{ law.label }}
                            </option>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">
                            Total legislature size is computed from population, then clamped to
                            <code class="text-gray-400">[min, max]</code>, then partitioned into districts of size
                            <code class="text-gray-400">[min_seats, max_seats]</code>.
                        </p>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-200 mb-1">
                            Max Days Between Meetings
                        </label>
                        <input
                            v-model.number="maxDaysBetweenMeetings"
                            type="number"
                            min="1"
                            class="w-full bg-gray-950 border border-gray-700 rounded-md px-3 py-2 text-gray-100 focus:border-blue-500 focus:outline-none"
                        />
                        <p class="text-xs text-gray-500 mt-1">
                            Default of defaults: <span class="text-gray-300">90</span> days · Art. II §2
                        </p>
                    </div>
                </div>
            </section>

            <!-- ─────────── Elections ─────────── -->
            <section class="bg-gray-900 border border-gray-800 rounded-lg p-6 mb-5">
                <h2 class="text-lg font-semibold text-white mb-4">Elections</h2>
                <div class="space-y-5">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-200 mb-1">
                                Election Interval (months)
                            </label>
                            <input
                                v-model.number="electionInterval"
                                type="number"
                                min="1"
                                max="1200"
                                class="w-full bg-gray-950 border border-gray-700 rounded-md px-3 py-2 text-gray-100 focus:border-blue-500 focus:outline-none"
                            />
                            <p class="text-xs text-gray-500 mt-1">
                                Default of defaults: <span class="text-gray-300">60</span> months (5 years) · Art. II §2
                                <span v-if="acceleratedHint" class="block">{{ acceleratedHint }}</span>
                            </p>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-200 mb-1">
                                Voting Method
                            </label>
                            <select
                                v-model="votingMethod"
                                class="w-full bg-gray-950 border border-gray-700 rounded-md px-3 py-2 text-gray-100"
                            >
                                <option v-for="m in VOTING_METHODS" :key="m.id" :value="m.id" :disabled="!m.enabled">
                                    {{ m.label }}
                                </option>
                            </select>
                            <p class="text-xs text-gray-500 mt-1">
                                Default of defaults: <span class="text-gray-300">STV Droop</span> · Art. II §2 ·
                                currently the only implemented algorithm.
                            </p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-200 mb-1">
                                Special Election — Min Days After Vacancy
                            </label>
                            <input
                                v-model.number="specialElectionMinDays"
                                type="number"
                                min="1"
                                class="w-full bg-gray-950 border border-gray-700 rounded-md px-3 py-2 text-gray-100 focus:border-blue-500 focus:outline-none"
                            />
                            <p class="text-xs text-gray-500 mt-1">
                                Default of defaults: <span class="text-gray-300">90</span> days · Art. II §5
                            </p>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-200 mb-1">
                                Special Election — Max Days After Vacancy
                            </label>
                            <input
                                v-model.number="specialElectionMaxDays"
                                type="number"
                                min="1"
                                class="w-full bg-gray-950 border border-gray-700 rounded-md px-3 py-2 text-gray-100 focus:border-blue-500 focus:outline-none"
                            />
                            <p
                                :class="[
                                    'text-xs mt-1',
                                    specialElectionValid ? 'text-gray-500' : 'text-red-400',
                                ]"
                            >
                                Default of defaults: <span class="text-gray-300">180</span> days · Art. II §5 ·
                                must be ≥ min.
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ─────────── Governance Thresholds ─────────── -->
            <section class="bg-gray-900 border border-gray-800 rounded-lg p-6 mb-5">
                <h2 class="text-lg font-semibold text-white mb-4">Governance Thresholds</h2>
                <div class="space-y-5">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-200 mb-1">
                                Supermajority
                            </label>
                            <div class="flex items-center gap-2">
                                <input
                                    v-model.number="supermajorityN"
                                    type="number"
                                    min="1"
                                    class="w-16 bg-gray-950 border border-gray-700 rounded-md px-2 py-2 text-gray-100 text-center"
                                />
                                <span class="text-gray-500">/</span>
                                <input
                                    v-model.number="supermajorityD"
                                    type="number"
                                    min="2"
                                    class="w-16 bg-gray-950 border border-gray-700 rounded-md px-2 py-2 text-gray-100 text-center"
                                />
                                <span class="text-xs text-gray-400 ml-2">= {{ supermajorityRatio }}</span>
                            </div>
                            <p
                                :class="[
                                    'text-xs mt-1',
                                    supermajorityValid ? 'text-gray-500' : 'text-red-400',
                                ]"
                            >
                                Default of defaults: <span class="text-gray-300">2/3</span> · Art. VII ·
                                must exceed 1/2 (simple majority).
                            </p>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-200 mb-1">
                                Emergency Powers Max Duration (days)
                            </label>
                            <input
                                v-model.number="emergencyPowersMaxDays"
                                type="number"
                                min="1"
                                class="w-full bg-gray-950 border border-gray-700 rounded-md px-3 py-2 text-gray-100 focus:border-blue-500 focus:outline-none"
                            />
                            <p class="text-xs text-gray-500 mt-1">
                                Default of defaults: <span class="text-gray-300">90</span> days · Art. II §7
                            </p>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-200 mb-1">
                            Citizen Initiative Petition Threshold (% of population)
                        </label>
                        <input
                            v-model.number="initiativePetitionThresholdPct"
                            type="number"
                            min="0.01"
                            max="100"
                            step="0.01"
                            class="w-full bg-gray-950 border border-gray-700 rounded-md px-3 py-2 text-gray-100 focus:border-blue-500 focus:outline-none"
                        />
                        <p class="text-xs text-gray-500 mt-1">
                            Default of defaults: <span class="text-gray-300">5.00%</span> · Art. II §6
                        </p>
                    </div>
                </div>
            </section>

            <!-- ─────────── Appointments & Judiciary ─────────── -->
            <section class="bg-gray-900 border border-gray-800 rounded-lg p-6 mb-5">
                <h2 class="text-lg font-semibold text-white mb-4">Appointments & Judiciary</h2>
                <div class="space-y-5">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-200 mb-1">
                                Civil Appointment Term (years)
                            </label>
                            <input
                                v-model.number="civilAppointmentYears"
                                type="number"
                                min="1"
                                class="w-full bg-gray-950 border border-gray-700 rounded-md px-3 py-2 text-gray-100 focus:border-blue-500 focus:outline-none"
                            />
                            <p class="text-xs text-gray-500 mt-1">
                                Default of defaults: <span class="text-gray-300">10</span> years · Art. II §9
                            </p>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-200 mb-1">
                                Judicial Appointment Term (years)
                            </label>
                            <input
                                v-model.number="judicialAppointmentYears"
                                type="number"
                                min="1"
                                class="w-full bg-gray-950 border border-gray-700 rounded-md px-3 py-2 text-gray-100 focus:border-blue-500 focus:outline-none"
                            />
                            <p class="text-xs text-gray-500 mt-1">
                                Default of defaults: <span class="text-gray-300">10</span> years · Art. IV §4
                            </p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-200 mb-1">
                                Minimum Judges per Race
                            </label>
                            <input
                                v-model.number="judiciaryMinJudgesPerRace"
                                type="number"
                                min="1"
                                class="w-full bg-gray-950 border border-gray-700 rounded-md px-3 py-2 text-gray-100 focus:border-blue-500 focus:outline-none"
                            />
                            <p class="text-xs text-gray-500 mt-1">
                                Default of defaults: <span class="text-gray-300">5</span> · Art. IV §4
                            </p>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-200 mb-1">
                                Judiciary Selection Method
                            </label>
                            <div class="flex items-center gap-3 mt-2">
                                <label class="flex items-center gap-2 text-sm text-gray-200">
                                    <input
                                        type="radio"
                                        :value="false"
                                        v-model="judiciaryIsElected"
                                        class="text-blue-500 focus:ring-blue-500"
                                    />
                                    Appointed
                                </label>
                                <label class="flex items-center gap-2 text-sm text-gray-200">
                                    <input
                                        type="radio"
                                        :value="true"
                                        v-model="judiciaryIsElected"
                                        class="text-blue-500 focus:ring-blue-500"
                                    />
                                    Elected
                                </label>
                            </div>
                            <p class="text-xs text-gray-500 mt-1">
                                Default of defaults: <span class="text-gray-300">Appointed</span> · Art. IV §1
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ─────────── Organizations & Workers ─────────── -->
            <section class="bg-gray-900 border border-gray-800 rounded-lg p-6 mb-5">
                <h2 class="text-lg font-semibold text-white mb-4">Organizations & Workers</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-200 mb-1">
                            Worker Rep — First Seat Threshold (employees)
                        </label>
                        <input
                            v-model.number="workerRepMinEmployees"
                            type="number"
                            min="1"
                            class="w-full bg-gray-950 border border-gray-700 rounded-md px-3 py-2 text-gray-100 focus:border-blue-500 focus:outline-none"
                        />
                        <p class="text-xs text-gray-500 mt-1">
                            Default of defaults: <span class="text-gray-300">100</span> · Art. III §6
                        </p>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-200 mb-1">
                            Worker : Shareholder Parity (employees)
                        </label>
                        <input
                            v-model.number="workerRepParityEmployees"
                            type="number"
                            min="1"
                            class="w-full bg-gray-950 border border-gray-700 rounded-md px-3 py-2 text-gray-100 focus:border-blue-500 focus:outline-none"
                        />
                        <p
                            :class="[
                                'text-xs mt-1',
                                workerThresholdsValid ? 'text-gray-500' : 'text-red-400',
                            ]"
                        >
                            Default of defaults: <span class="text-gray-300">2000</span> · Art. III §6 ·
                            must be ≥ first-seat threshold.
                        </p>
                    </div>
                </div>
            </section>

            <!-- ─────────── Residency ─────────── -->
            <section class="bg-gray-900 border border-gray-800 rounded-lg p-6 mb-5">
                <h2 class="text-lg font-semibold text-white mb-4">Residency</h2>
                <div>
                    <label class="block text-sm font-semibold text-gray-200 mb-1">
                        Residency Confirmation Window (days)
                    </label>
                    <input
                        v-model.number="residencyConfirmationDays"
                        type="number"
                        min="1"
                        class="w-full bg-gray-950 border border-gray-700 rounded-md px-3 py-2 text-gray-100 focus:border-blue-500 focus:outline-none"
                    />
                    <p class="text-xs text-gray-500 mt-1">
                        Default of defaults: <span class="text-gray-300">30</span> days of qualifying GPS pings
                        before residency is confirmed and voting/candidacy rights unlock.
                    </p>
                </div>
            </section>

            <!-- ─────────── Economy ─────────── -->
            <section class="bg-gray-900 border border-gray-800 rounded-lg p-6 mb-5">
                <h2 class="text-lg font-semibold text-white mb-1">Economy defaults</h2>
                <p class="text-sm text-gray-400 mb-4">
                    The starting economy for your world — these cascade to child jurisdictions just
                    like the constitutional ones.
                </p>
                <div class="space-y-5">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-200 mb-1">
                                Currency name
                            </label>
                            <input
                                v-model="currencyName"
                                type="text"
                                class="w-full bg-gray-950 border border-gray-700 rounded-md px-3 py-2 text-gray-100 focus:border-blue-500 focus:outline-none"
                            />
                            <p class="text-xs text-gray-500 mt-1">The abstract unit of account.</p>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-200 mb-1">
                                Currency symbol
                            </label>
                            <input
                                v-model="currencySymbol"
                                type="text"
                                class="w-full bg-gray-950 border border-gray-700 rounded-md px-3 py-2 text-gray-100 focus:border-blue-500 focus:outline-none"
                            />
                            <p class="text-xs text-gray-500 mt-1">Shown on wallets and the exchange.</p>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-200 mb-1">
                                Currency code
                            </label>
                            <input
                                v-model="currencyCode"
                                type="text"
                                class="w-full bg-gray-950 border border-gray-700 rounded-md px-3 py-2 text-gray-100 focus:border-blue-500 focus:outline-none"
                            />
                            <p class="text-xs text-gray-500 mt-1">Short ticker, e.g. CVU.</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-200 mb-1">
                                Civic stipend — residency floor
                            </label>
                            <input
                                v-model.number="civicStipendFloor"
                                type="number"
                                min="0"
                                class="w-full bg-gray-950 border border-gray-700 rounded-md px-3 py-2 text-gray-100 focus:border-blue-500 focus:outline-none"
                            />
                            <p class="text-xs text-gray-500 mt-1">
                                Everyone with active residency receives this. Default: <span class="text-gray-300">50</span>
                            </p>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-200 mb-1">
                                Stipend bump cap (max stacked)
                            </label>
                            <input
                                v-model.number="stipendBumpCap"
                                type="number"
                                min="0"
                                class="w-full bg-gray-950 border border-gray-700 rounded-md px-3 py-2 text-gray-100 focus:border-blue-500 focus:outline-none"
                            />
                            <p class="text-xs text-gray-500 mt-1">
                                The most the role differentials can add. Default: <span class="text-gray-300">20</span>
                            </p>
                        </div>
                    </div>

                    <div>
                        <p class="text-sm font-semibold text-gray-200 mb-2">Per-role pay</p>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-semibold text-gray-200 mb-1">
                                    Node operators
                                </label>
                                <input
                                    v-model.number="payNodeOperator"
                                    type="number"
                                    min="0"
                                    class="w-full bg-gray-950 border border-gray-700 rounded-md px-3 py-2 text-gray-100 focus:border-blue-500 focus:outline-none"
                                />
                                <p class="text-xs text-gray-500 mt-1">
                                    Civic-duty pay for the people running nodes. Default: <span class="text-gray-300">8</span>
                                </p>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-200 mb-1">
                                    Social moderators
                                </label>
                                <input
                                    v-model.number="paySocialModerator"
                                    type="number"
                                    min="0"
                                    class="w-full bg-gray-950 border border-gray-700 rounded-md px-3 py-2 text-gray-100 focus:border-blue-500 focus:outline-none"
                                />
                                <p class="text-xs text-gray-500 mt-1">
                                    Civic-duty pay for moderators. Default: <span class="text-gray-300">5</span>
                                </p>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-200 mb-1">
                                    Civic office-holders
                                </label>
                                <input
                                    v-model.number="payOfficeHolder"
                                    type="number"
                                    min="0"
                                    class="w-full bg-gray-950 border border-gray-700 rounded-md px-3 py-2 text-gray-100 focus:border-blue-500 focus:outline-none"
                                />
                                <p class="text-xs text-gray-500 mt-1">
                                    Civic-duty pay for elected &amp; appointed officers. Default: <span class="text-gray-300">12</span>
                                </p>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-200 mb-1">
                            Stipend interval
                        </label>
                        <select
                            v-model="stipendInterval"
                            class="w-full bg-gray-950 border border-gray-700 rounded-md px-3 py-2 text-gray-100"
                        >
                            <option v-for="i in STIPEND_INTERVALS" :key="i.id" :value="i.id">
                                {{ i.label }}
                            </option>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">
                            How often the economic clock pays out.
                        </p>
                    </div>
                </div>
            </section>

            <!-- ─────────── Submit ─────────── -->
            <section class="bg-gray-900 border border-gray-800 rounded-lg p-6">
                <div v-if="submitError" class="text-sm text-red-400 bg-red-900/30 border border-red-800 rounded p-2 mb-3">
                    {{ submitError }}
                </div>
                <div class="flex justify-between pt-2">
                    <a href="/setup/step/0" class="text-gray-400 hover:text-gray-200 text-sm px-2 py-2">
                        ← Back
                    </a>
                    <button
                        type="button"
                        :disabled="!canSubmit || submitting"
                        @click="onSubmit"
                        class="bg-blue-600 hover:bg-blue-500 disabled:bg-gray-700 disabled:cursor-not-allowed text-white px-5 py-2 rounded-md font-semibold transition-colors"
                    >
                        {{ submitting ? 'Saving…' : 'Continue →' }}
                    </button>
                </div>
            </section>
    </div>
</template>
