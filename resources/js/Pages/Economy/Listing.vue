<script setup>
/** TEMPORARY placeholder — see EconomyStub.vue. @lane-06 replaces this. */
import EconomyStub from './EconomyStub.vue'

const props = defineProps({
  currency: { default: null },
  listing: { default: null },
  orders: { default: null },
  can_order: { default: null },
})
</script>

<template>
  <EconomyStub
    title="A listing"
    blurb="One offer on the open market."
    :data="props"
  />
</template>
