<script setup>
/** TEMPORARY placeholder — see EconomyStub.vue. @lane-06 replaces this. */
import EconomyStub from './EconomyStub.vue'

const props = defineProps({
  currency: { default: null },
  account: { default: null },
  transactions: { default: null },
  receipts: { default: null },
})
</script>

<template>
  <EconomyStub
    title="My wallet"
    blurb="Your balance, your recent movements and your stipend receipts. Private — like a ballot, only you can read it."
    :data="props"
  />
</template>
