<script setup>
/** TEMPORARY placeholder — see EconomyStub.vue. @lane-06 replaces this. */
import EconomyStub from './EconomyStub.vue'

const props = defineProps({
  currency: { default: null },
  supply: { default: null },
  ledger: { default: null },
  counts: { default: null },
  stipend: { default: null },
})
</script>

<template>
  <EconomyStub
    title="The economy"
    blurb="Currency, money supply, and the state of the public ledger."
    :data="props"
  />
</template>
