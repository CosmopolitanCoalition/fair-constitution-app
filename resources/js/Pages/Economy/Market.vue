<script setup>
/** TEMPORARY placeholder — see EconomyStub.vue. @lane-06 replaces this. */
import EconomyStub from './EconomyStub.vue'

const props = defineProps({
  currency: { default: null },
  offers: { default: null },
  work: { default: null },
  assistance: { default: null },
})
</script>

<template>
  <EconomyStub
    title="The open market"
    blurb="Offers and requests: things and services for sale, work being sought, and neighbours asking for help."
    :data="props"
  />
</template>
