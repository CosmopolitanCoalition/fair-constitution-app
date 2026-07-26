<script setup>
/** TEMPORARY placeholder — see EconomyStub.vue. @lane-06 replaces this. */
import EconomyStub from './EconomyStub.vue'

const props = defineProps({
  currency: { default: null },
  accounts: { default: null },
  ledger: { default: null },
  issuance: { default: null },
  budgets: { default: null },
  revenue: { default: null },
  totals: { default: null },
})
</script>

<template>
  <EconomyStub
    title="Public finance"
    blurb="The public ledger, treasury accounts, issuance, budgets and revenue. Public by construction."
    :data="props"
  />
</template>
