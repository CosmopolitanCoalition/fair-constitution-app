<script setup>
import { computed, ref, useId, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import VoteTally from './VoteTally.vue';

const props = defineProps({
    consent: { type: Object, required: true },
    canCast: { type: Boolean, default: false },
});
const busy = ref(false);
const error = ref('');
const submitted = ref(false);
const tieExplanation = ref('');
const tieExplanationId = useId();
const allowed = computed(() => props.canCast && Boolean(props.consent.cast_url)
    && !props.consent.my_cast && !submitted.value && props.consent.tally?.status === 'open');
const canBreakTie = computed(() => Boolean(props.consent.can_tiebreak && props.consent.tiebreak_url)
    && !props.consent.my_cast && !submitted.value && props.consent.tally?.status === 'closed' && props.consent.tally?.outcome === 'tied');
watch(() => props.consent.cast_url, () => { error.value = ''; submitted.value = false; });
function cast({ value, explanation }) {
    if (!allowed.value || busy.value) return;
    send(props.consent.cast_url, { value, explanation });
}
function breakTie(value) {
    if (!canBreakTie.value || busy.value || !['yes', 'no'].includes(value)) return;
    send(props.consent.tiebreak_url, { value, explanation: tieExplanation.value.trim() || null });
}
function send(url, payload) {
    router.post(url, payload, {
        preserveScroll: true,
        onStart: () => { busy.value = true; error.value = ''; },
        onError: errors => { error.value = Object.values(errors)[0] || 'Your vote could not be submitted. Please try again.'; },
        onSuccess: () => { submitted.value = true; },
        onFinish: () => { busy.value = false; },
    });
}
</script>

<template>
    <div :aria-busy="busy">
        <VoteTally v-if="consent.tally" v-bind="consent.tally" :can-cast="allowed" :casting="busy" @cast="cast" />
        <form v-if="canBreakTie" class="consent-tiebreak" @submit.prevent>
            <h4>Speaker’s tie-breaking vote</h4>
            <label :for="tieExplanationId">Explanation (optional, published with your vote)</label>
            <textarea :id="tieExplanationId" v-model="tieExplanation" rows="2" :disabled="busy" />
            <div><button type="button" :disabled="busy" @click="breakTie('yes')">Break tie: yes</button>
                <button type="button" :disabled="busy" @click="breakTie('no')">Break tie: no</button></div>
        </form>
        <p v-if="busy" role="status">Submitting your vote…</p>
        <p v-if="error" role="alert">{{ error }}</p>
        <p v-if="consent.my_cast || submitted" role="status">Your vote has been recorded.</p>
        <p v-if="consent.read_only_reason">{{ consent.read_only_reason }}</p>
    </div>
</template>

<style scoped>
.consent-tiebreak { display: grid; gap: .6rem; margin-block: 1rem; }
.consent-tiebreak div { display: flex; flex-wrap: wrap; gap: .75rem; }
.consent-tiebreak button { min-block-size: 44px; font: inherit; }
.consent-tiebreak textarea { max-inline-size: 100%; font: inherit; }
</style>
