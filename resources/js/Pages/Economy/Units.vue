<script setup>
/** TEMPORARY placeholder — see EconomyStub.vue. @lane-06 replaces this. */
import EconomyStub from './EconomyStub.vue'

const props = defineProps({
  currency: { default: null },
  levers: { default: null },
  supply: { default: null },
  issuance_rate_bps: { default: null },
  inflation_target_bps: { default: null },
})
</script>

<template>
  <EconomyStub
    title="Units & money"
    blurb="The currency and its monetary levers. Every lever moves only by act of a legislature — never an admin knob."
    :data="props"
  />
</template>
